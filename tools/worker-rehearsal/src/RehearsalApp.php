<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\WorkerRehearsal;

use Closure;
use InvalidArgumentException;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use PDO;
use Throwable;

/**
 * Synthetic worker-mode application used to rehearse the library lifecycle
 * contract under FrankenPHP. It is rehearsal tooling, not a library API.
 */
final class RehearsalApp
{
    private readonly RehearsalHolder $holder;

    private readonly float $bootedAt;

    private ?PDO $admin = null;

    public function __construct(private readonly RehearsalConfig $config)
    {
        $this->bootedAt = microtime(true);
        $this->holder = new RehearsalHolder(
            factory: function (RehearsalHolder $holder): Connection {
                if ($holder->shouldFailFactory()) {
                    try {
                        return Connection::connect(
                            $this->config->driver,
                            $this->config->dsn,
                            'rehearsal_missing_user',
                            'invalid-rehearsal-password',
                            $this->config->pdoOptions,
                        );
                    } catch (ConnectionException $failure) {
                        $holder->increment('failed_construction');

                        throw $failure;
                    }
                }

                return Connection::connect(
                    $this->config->driver,
                    $this->config->dsn,
                    $this->config->username,
                    $this->config->password,
                    $this->config->pdoOptions,
                    new ConnectionOptions(label: 'worker-rehearsal'),
                );
            },
            initialize: function (Connection $connection): void {
                $connection->query($this->config->initializeSql)->execute();
            },
            idleThresholdSeconds: $this->config->idleThresholdSeconds,
        );
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    public function handle(string $path): array
    {
        try {
            return ['status' => 200, 'payload' => $this->dispatch($path)];
        } catch (QueryExecutionException $failure) {
            $this->holder->evict('query_failed');

            return ['status' => 503, 'payload' => [
                'error' => 'query_failed',
                'sql_state' => $failure->sqlState,
                'driver_code' => $failure->driverCode,
            ]];
        } catch (TransactionException $failure) {
            if ($failure->connectionUnusable) {
                $this->holder->evict('transaction_unusable');
            }

            return ['status' => 503, 'payload' => [
                'error' => 'transaction_failed',
                'connection_unusable' => $failure->connectionUnusable,
            ]];
        } catch (ConnectionException $failure) {
            return ['status' => 503, 'payload' => [
                'error' => 'connection_failed',
                'operation' => $failure->operation,
                'sql_state' => $failure->sqlState,
                'driver_code' => $failure->driverCode,
            ]];
        } catch (InvalidArgumentException) {
            return ['status' => 400, 'payload' => ['error' => 'invalid_request']];
        } catch (Throwable $failure) {
            return ['status' => 500, 'payload' => ['error' => 'internal', 'class' => $failure::class]];
        }
    }

    public function shutdown(): void
    {
        $this->holder->shutdown();
        $this->admin = null;
    }

    /** @return array<string, mixed> */
    private function dispatch(string $path): array
    {
        return match ($path) {
            '/cache' => ['ok' => true, 'database' => false],
            '/health' => $this->health(),
            '/read' => $this->read(),
            '/write' => $this->write(),
            '/transaction' => $this->transaction(),
            '/report' => $this->report(),
            '/fail' => $this->missingTable(),
            '/break' => $this->toggleFactory(true),
            '/recover' => $this->toggleFactory(false),
            '/construction-failure' => $this->constructionFailure(),
            '/admin-kill' => $this->adminKill(),
            '/counters' => [
                'ok' => true,
                'counters' => $this->holder->counters(),
                'reasons' => $this->holder->reasons(),
                'timing' => $this->holder->timing(),
            ],
            '/db-sessions' => $this->databaseSessions(),
            default => throw new InvalidArgumentException('Unknown rehearsal route.'),
        };
    }

    /** @return array<string, mixed> */
    private function health(): array
    {
        return $this->withConnection(function (Connection $connection): array {
            $row = $connection->query('SELECT CONNECTION_ID() AS id')->firstAssociative();
            $sessionId = $row['id'] ?? null;

            return [
                'ok' => true,
                'pid' => getmypid(),
                'sapi' => PHP_SAPI,
                'php_version' => PHP_VERSION,
                'zts' => ZEND_THREAD_SAFE,
                'uptime_seconds' => round(microtime(true) - $this->bootedAt, 3),
                'memory_bytes' => memory_get_usage(true),
                'peak_bytes' => memory_get_peak_usage(true),
                'session_id' => is_int($sessionId) || is_string($sessionId) ? (int) $sessionId : null,
                'reusable' => $this->holder->current()?->isReusable() ?? false,
                'server_version' => $connection->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
                'client_version' => $connection->pdo()->getAttribute(PDO::ATTR_CLIENT_VERSION),
                'initialize_limit' => $this->config->initializeValue,
                'report_limit' => $this->config->reportLimitValue,
                'statement_limit' => $this->statementLimit($connection),
                'counters' => $this->holder->counters(),
                'reasons' => $this->holder->reasons(),
                'timing' => $this->holder->timing(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return $this->withConnection(
            fn (Connection $connection): array => [
                'ok' => true,
                'rows' => $connection->table($this->config->table)->count(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function write(): array
    {
        return $this->withConnection(
            fn (Connection $connection): array => [
                'ok' => true,
                'id' => $connection->table($this->config->table)
                    ->insertGetId(['marker' => bin2hex(random_bytes(4))]),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function transaction(): array
    {
        return $this->withConnection(function (Connection $connection): array {
            $rows = $connection->transaction(function (Connection $transaction): int {
                $transaction->table($this->config->table)->insert(['marker' => 'transaction-a']);
                $transaction->table($this->config->table)->insert(['marker' => 'transaction-b']);

                return $transaction->table($this->config->table)->count();
            });

            return ['ok' => true, 'rows' => $rows];
        });
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $connection = $this->holder->acquire();
        $primary = null;
        try {
            $connection->query($this->config->reportLimitSql)->execute();
            $raised = $this->statementLimit($connection);
            // Evaluate SLEEP once; sleeping per table row would exceed even the
            // raised limit as the synthetic table grows.
            $row = $connection->query('SELECT SLEEP(0.3) AS slept')->firstAssociative();

            return ['ok' => true, 'raised_limit' => $raised, 'slept' => $row['slept'] ?? null];
        } catch (Throwable $failure) {
            $primary = $failure;

            throw $failure;
        } finally {
            try {
                $connection->query($this->config->initializeSql)->execute();
                $this->holder->increment('report_restores');
            } catch (Throwable $restoreFailure) {
                $this->holder->evict('session_restore_failed');
                $this->holder->increment('report_restore_failures');
                if ($primary === null) {
                    throw $restoreFailure;
                }
            }
            $this->holder->release($primary);
        }
    }

    /** @return array<string, mixed> */
    private function missingTable(): array
    {
        return $this->withConnection(
            static fn (Connection $connection): array => [
                'rows' => $connection->table('simplequery_missing_table')->count(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function constructionFailure(): array
    {
        // Force the next acquisition through the factory even when a healthy
        // handle is cached.
        $this->holder->evict('construction_rehearsal');
        $this->holder->setFailFactory(true);
        try {
            return $this->withConnection(
                fn (Connection $connection): array => [
                    'rows' => $connection->table($this->config->table)->count(),
                ],
            );
        } finally {
            $this->holder->setFailFactory(false);
        }
    }

    /** @return array<string, mixed> */
    private function toggleFactory(bool $fail): array
    {
        $this->holder->setFailFactory($fail);

        return ['ok' => true, 'fail_factory' => $fail];
    }

    /** @return array<string, mixed> */
    private function adminKill(): array
    {
        $connection = $this->holder->current();
        if ($connection === null) {
            return ['ok' => false, 'reason' => 'no_connection'];
        }
        $row = $connection->query('SELECT CONNECTION_ID() AS id')->firstAssociative();
        $sessionId = $row['id'] ?? null;
        if (!is_int($sessionId) && !is_string($sessionId)) {
            return ['ok' => false, 'reason' => 'no_session'];
        }
        $id = (int) $sessionId;
        $this->admin()->exec('KILL CONNECTION ' . $id);
        $this->holder->increment('killed_sessions');

        return ['ok' => true, 'session_id' => $id];
    }

    /** @return array<string, mixed> */
    private function databaseSessions(): array
    {
        $statement = $this->admin()->query("SHOW STATUS LIKE 'Threads_connected'");
        $row = $statement === false ? null : $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'ok' => true,
            'threads_connected' => is_array($row) ? ($row['Value'] ?? null) : null,
        ];
    }

    /**
     * @param Closure(Connection): array<string, mixed> $work
     * @return array<string, mixed>
     */
    private function withConnection(Closure $work): array
    {
        $connection = $this->holder->acquire();
        $primary = null;
        try {
            return $work($connection);
        } catch (Throwable $failure) {
            $primary = $failure;

            throw $failure;
        } finally {
            $this->holder->release($primary);
        }
    }

    private function statementLimit(Connection $connection): float
    {
        $row = $connection
            ->query('SELECT @@SESSION.' . $this->config->limitVariable . ' AS value')
            ->firstAssociative();
        $value = $row['value'] ?? null;
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Missing statement limit value.');
        }

        return (float) $value;
    }

    private function admin(): PDO
    {
        return $this->admin ??= new PDO(
            $this->config->adminDsn,
            $this->config->adminUsername,
            $this->config->adminPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
