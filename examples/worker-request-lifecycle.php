<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Examples\WorkerLifecycle\JobModel;
use Oeltima\SimpleQuery\Examples\WorkerLifecycle\RequestScope;
use Oeltima\SimpleQuery\Examples\WorkerLifecycle\WorkerDatabase;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/worker-lifecycle/WorkerDatabase.php';
require __DIR__ . '/worker-lifecycle/RequestScope.php';
require __DIR__ . '/worker-lifecycle/JobModel.php';

$check = static function (string $label, bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Worker request lifecycle example failed: ' . $label);
    }
};

// The application controls the monotonic clock so the example can exercise its
// idle policy deterministically; deployments use hrtime(true).
$now = 1_000.0;
$clock = static function () use (&$now): float {
    return $now;
};

$database = new WorkerDatabase(
    factory: static fn (): Connection => Connection::connect(Driver::Sqlite, 'sqlite::memory:'),
    initialize: static function (Connection $connection): void {
        $connection->query('PRAGMA busy_timeout = 100')->execute();
        $connection->query('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)')
            ->execute();
    },
    clock: $clock,
    idleThresholdSeconds: 60.0,
);

// A cache-only request performs no database work and opens no connection.
$cacheResult = $database->request(static fn (RequestScope $scope): string => 'from-cache');
$check('cache_only_result', $cacheResult === 'from-cache');
$check(
    'cache_only_opens_nothing',
    $database->counters()['created'] === 0 && $database->counters()['cache_only_units'] === 1,
);

// Warm reuse: the second request finds the same initialized session.
$database->request(static function (RequestScope $scope): void {
    $scope->connection()->table('jobs')->insert(['name' => 'first']);
});
$database->request(static function (RequestScope $scope) use ($check, $database): void {
    $check('warm_reuse_same_session', $scope->connection()->table('jobs')->count() === 1);
    $check('warm_reuse_no_new_connection', $database->counters()['created'] === 1);
});

// The idle policy retires an idle handle only at the next unit boundary.
$now += 61.0;
$database->request(static function (RequestScope $scope) use ($check): void {
    $check('idle_replacement_starts_fresh', $scope->connection()->table('jobs')->count() === 0);
});
$check('idle_replacement_counted', $database->counters()['replaced_idle'] === 1);

// Once a unit owns a connection, an elapsed threshold cannot replace it, and a
// second lazy model resolves the identical owner during a transaction.
$database->request(static function (RequestScope $scope) use ($check, $database, &$now): void {
    $primary = $scope->connection();
    $read = $scope->connection('read');
    $check('roles_have_separate_owners', $read !== $primary);

    $now += 3_600.0;
    $check('mid_unit_owner_pinned', $scope->connection() === $primary);
    $created = $database->counters()['created'];

    $scope->connection()->transaction(
        static function (Connection $transaction) use ($scope, $check, $database, $created): void {
            $first = new JobModel($scope);
            $second = new JobModel($scope);
            $first->create('transactional-a');
            $second->create('transactional-b');
            $check('two_models_share_transaction_owner', $transaction === $scope->connection());
            $check('no_replacement_inside_transaction', $database->counters()['created'] === $created);
        },
    );

    $check('transaction_writes_committed', (new JobModel($scope))->count() === 2);
});

// Cursor cleanup is explicit: a tracked, early-terminated stream that outlives
// its handler is closed at the boundary before the connection can be reused.
/** @var \Oeltima\SimpleQuery\Cursor<array<string, mixed>>|null $cursor */
$cursor = null;
/** @var \Traversable<int, array<string, mixed>>|null $cursorRows */
$cursorRows = null;
$database->request(static function (RequestScope $scope) use ($check, &$cursor, &$cursorRows): void {
    $cursor = $scope->cursor($scope->connection()->table('jobs')->orderBy('id'));
    $cursorRows = $cursor->getIterator();
    foreach ($cursorRows as $row) {
        break;
    }
    $check('partial_cursor_open_before_cleanup', !$cursor->isClosed());
});
$check('partial_cursor_closed_at_boundary', $cursor !== null && $cursor->isClosed());
$check('partial_cursor_boundary_counted', $database->counters()['cursors_closed'] === 1);
unset($cursorRows);

// Early returns, thrown Throwable, and handled "HTTP errors" all release the
// owner through the same finally path.
$database->request(static function (RequestScope $scope) use ($check): string {
    $scope->connection();
    $check('early_return_still_acquires', $scope->usedConnection());

    return 'early';
});
$thrown = new RuntimeException('Synthetic domain failure');
try {
    $database->request(static function (RequestScope $scope) use ($thrown): void {
        $scope->connection();

        throw $thrown;
    });
    $check('thrown_throwable_propagates', false);
} catch (RuntimeException $failure) {
    $check('thrown_throwable_identity', $failure === $thrown);
}
/** @var array{status: int, body: string} $handled */
$handled = $database->request(static function (RequestScope $scope): array {
    try {
        $scope->connection()->table('missing_jobs')->count();
    } catch (QueryExecutionException $failure) {
        $scope->evict($failure);

        return ['status' => 503, 'body' => 'database unavailable'];
    }

    return ['status' => 200, 'body' => 'ok'];
});
$check('handled_error_response', $handled['status'] === 503);
$check('handled_error_evicted', $database->counters()['evicted'] === 1);
$check('handled_error_no_replay', $database->reasons() === ['query_failed' => 1]);

// A request that keeps an untracked cursor alive past its handler leaves
// outstanding work; the boundary sees the unusable state and discards instead
// of reusing the connection.
/** @var Connection|null $abandoned */
$abandoned = null;
/** @var \Oeltima\SimpleQuery\Cursor<array<string, mixed>>|null $untracked */
$untracked = null;
/** @var \Traversable<int, array<string, mixed>>|null $untrackedRows */
$untrackedRows = null;
$database->request(
    static function (RequestScope $scope) use ($check, &$abandoned, &$untracked, &$untrackedRows): void {
        $abandoned = $scope->connection();
        $abandoned->table('jobs')->insert(['name' => 'leaked']);
        $untracked = $abandoned->table('jobs')->orderBy('id')->iterateAssociative();
        $untrackedRows = $untracked->getIterator();
        foreach ($untrackedRows as $row) {
            break;
        }
        $check('untracked_cursor_open_before_cleanup', !$untracked->isClosed());
    },
);
$check('untracked_cursor_owner_discarded', $abandoned !== null && !$abandoned->isReusable());
$check('untracked_cursor_discard_counted', $database->counters()['discarded_units'] >= 1);
$check('untracked_cursor_still_open', $untracked?->isClosed() === false);
unset($untrackedRows);
$check('untracked_cursor_cleaned_on_release', $untracked !== null && $untracked->isClosed());

// Session initialization is reapplied on every replacement, and temporary
// report settings are restored before the next ordinary request.
$readTimeout = static function (RequestScope $scope): int {
    $row = $scope->connection()->query('PRAGMA busy_timeout')->firstAssociative();
    $value = $row['timeout'] ?? null;
    if (!is_int($value) && !is_string($value)) {
        throw new RuntimeException('Missing SQLite busy timeout.');
    }

    return (int) $value;
};
$check('initialization_applied', $database->request($readTimeout) === 100);
$reported = $database->request(static function (RequestScope $scope): int {
    $value = $scope->withTemporarySessionChange(
        change: static function (Connection $connection): void {
            $connection->query('PRAGMA busy_timeout = 5000')->execute();
        },
        restore: static function (Connection $connection): void {
            $connection->query('PRAGMA busy_timeout = 100')->execute();
        },
        work: static function (Connection $connection): int {
            $row = $connection->query('PRAGMA busy_timeout')->firstAssociative();
            $setting = $row['timeout'] ?? null;
            if (!is_int($setting) && !is_string($setting)) {
                throw new RuntimeException('Missing SQLite busy timeout.');
            }

            return (int) $setting;
        },
    );
    if (!is_int($value)) {
        throw new RuntimeException('Unexpected report result.');
    }

    return $value;
});
$check('report_setting_returned', $reported === 5000);
$check('report_restore_verified', $database->request($readTimeout) === 100);

// Report failure with successful restoration keeps the original exception and
// leaves the session reusable.
$reportFailure = new RuntimeException('Synthetic report failure');
try {
    $database->request(static function (RequestScope $scope) use ($reportFailure): void {
        $scope->withTemporarySessionChange(
            change: static function (Connection $connection): void {
                $connection->query('PRAGMA busy_timeout = 5000')->execute();
            },
            restore: static function (Connection $connection): void {
                $connection->query('PRAGMA busy_timeout = 100')->execute();
            },
            work: static function () use ($reportFailure): void {
                throw $reportFailure;
            },
        );
    });
    $check('report_failure_propagates', false);
} catch (RuntimeException $failure) {
    $check('report_failure_identity', $failure === $reportFailure);
}
$check('report_failure_restored', $database->request($readTimeout) === 100);

// Report failure with failed restoration evicts the session and preserves the
// original failure.
try {
    $database->request(static function (RequestScope $scope) use ($reportFailure): void {
        $scope->withTemporarySessionChange(
            change: static function (Connection $connection): void {
                $connection->query('PRAGMA busy_timeout = 5000')->execute();
            },
            restore: static function (Connection $connection): void {
                $connection->discard();
                $connection->query('PRAGMA busy_timeout = 100')->execute();
            },
            work: static function () use ($reportFailure): void {
                throw $reportFailure;
            },
        );
    });
    $check('restore_failure_propagates', false);
} catch (RuntimeException $failure) {
    $check('restore_failure_preserves_original', $failure === $reportFailure);
}
$check('restore_failure_evicted', $database->reasons()['session_restore_failed'] === 1);
$check('restore_failure_reinitializes', $database->request($readTimeout) === 100);

// A replacement is a new session with fresh initialization; the unused role
// kept its owner, and shutdown opens nothing.
$check('read_role_survived', $database->retained('read'));
$database->shutdown();
$check('shutdown_retained_nothing', !$database->retained('primary') && !$database->retained('read'));
$shutdownRejected = false;
try {
    $database->request(static fn (RequestScope $scope): string => 'after-shutdown');
} catch (RuntimeException) {
    $shutdownRejected = true;
}
$check('shutdown_is_terminal', $shutdownRejected);

$timing = $database->acquisitionTiming();
$check('acquisition_cost_measured', $timing['acquisitions'] > 0 && $timing['microseconds'] > 0);

fwrite(
    STDOUT,
    'Worker request lifecycle example passed; counters: '
    . json_encode($database->counters(), JSON_THROW_ON_ERROR)
    . PHP_EOL,
);
