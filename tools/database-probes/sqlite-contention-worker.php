<?php

declare(strict_types=1);

/*
 * Child worker for the SQLite lock-contention stress probe.
 *
 * Modes:
 * - contended <db> <timeout-ms>: begin an immediate transaction; with a zero
 *   busy timeout this must surface SQLITE_BUSY while a writer holds the lock.
 *   Exits 0 with busy_observed=true on the expected outcome.
 * - resilient <db> <timeout-ms> <rows> <ready-file>: signal readiness, then
 *   block on the immediate write lock until the parent commits; all rows must
 *   insert.
 * - stress <db> <worker-id> <rows>: insert a disjoint id range concurrently.
 *
 * Raw BEGIN IMMEDIATE/COMMIT are used because a WAL-mode deferred transaction
 * upgrade returns SQLITE_BUSY immediately instead of honoring busy_timeout.
 * Connection establishment and the stress write are retried with a short
 * back-off because the SQLite shared-memory recovery lock is not covered by
 * busy_timeout.
 *
 * Output is a single JSON object on stdout.
 */

$connectWithRetry = static function (string $dbPath, int $busyTimeoutMilliseconds): PDO {
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeoutMilliseconds);

            return $pdo;
        } catch (PDOException $exception) {
            if ($attempt === 5) {
                throw $exception;
            }
            usleep(100_000);
        }
    }

    throw new RuntimeException('Unreachable connection retry loop.');
};

$writeStressRange = static function (PDO $pdo, int $worker, int $rows): void {
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $statement = $pdo->prepare('INSERT INTO sq_contention (id, worker) VALUES (?, ?)');
            for ($index = 1; $index <= $rows; ++$index) {
                $statement->execute([$worker * $rows + $index, $worker]);
            }
            $pdo->exec('COMMIT');

            return;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? -1) !== 5) {
                throw $exception;
            }
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            if ($attempt === 5) {
                throw $exception;
            }
            usleep(150_000);
        }
    }

    throw new RuntimeException('Unreachable stress retry loop.');
};

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$dbPath = $arguments[0] ?? null;
$mode = $arguments[1] ?? null;
if (!is_string($dbPath) || !is_string($mode)) {
    fwrite(STDERR, "sqlite-contention-worker: db path and mode are required.\n");
    exit(2);
}

try {
    if ($mode === 'contended') {
        $pdo = $connectWithRetry($dbPath, (int) ($arguments[2] ?? '0'));
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            fwrite(STDOUT, json_encode(['mode' => $mode, 'busy_observed' => false]) . PHP_EOL);
            exit(3);
        } catch (PDOException $exception) {
            $busy = (int) ($exception->errorInfo[1] ?? -1) === 5;
            fwrite(STDOUT, json_encode([
                'mode' => $mode,
                'busy_observed' => $busy,
                'sqlstate' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
            ]) . PHP_EOL);
            exit($busy ? 0 : 4);
        }
    }

    if ($mode === 'resilient') {
        $timeout = (int) ($arguments[2] ?? '2000');
        $rows = (int) ($arguments[3] ?? '0');
        $readyFile = $arguments[4] ?? '';
        $pdo = $connectWithRetry($dbPath, $timeout);
        if ($readyFile !== '' && file_put_contents($readyFile, 'ready') === false) {
            fwrite(STDERR, "sqlite-contention-worker: could not write the readiness marker.\n");
            exit(2);
        }
        $pdo->exec('BEGIN IMMEDIATE');
        $statement = $pdo->prepare('INSERT INTO sq_contention (id, worker) VALUES (?, ?)');
        for ($index = 1; $index <= $rows; ++$index) {
            $statement->execute([$index, 99]);
        }
        $pdo->exec('COMMIT');
        fwrite(STDOUT, json_encode(['mode' => $mode, 'inserted' => $rows]) . PHP_EOL);
        exit(0);
    }

    if ($mode === 'stress') {
        $worker = (int) ($arguments[2] ?? '0');
        $rows = (int) ($arguments[3] ?? '0');
        usleep($worker * 75_000);
        $pdo = $connectWithRetry($dbPath, 5000);
        $writeStressRange($pdo, $worker, $rows);
        fwrite(STDOUT, json_encode(['mode' => $mode, 'worker' => $worker, 'inserted' => $rows]) . PHP_EOL);
        exit(0);
    }

    fwrite(STDERR, "sqlite-contention-worker: unknown mode.\n");
    exit(2);
} catch (PDOException $exception) {
    fwrite(STDERR, sprintf(
        "sqlite-contention-worker failed: %s (sqlstate %s, driver code %s).\n",
        $exception->getMessage(),
        $exception->errorInfo[0] ?? 'n/a',
        $exception->errorInfo[1] ?? 'n/a',
    ));
    exit(1);
}
