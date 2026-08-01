<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use PDO;
use RuntimeException;

/**
 * Multiprocess SQLite write-lock contention and busy-timeout resilience probe.
 *
 * The probe spawns real PHP subprocesses against a shared file-backed SQLite
 * database and verifies three deterministic outcomes:
 *
 * - a zero busy-timeout writer surfaces SQLITE_BUSY while another writer holds
 *   the write lock (`busy_surfaced`);
 * - a writer with a busy timeout blocks and completes its transaction after
 *   the lock holder commits (`busy_timeout_resilient`);
 * - several concurrent writers each commit their disjoint id ranges with no
 *   lost or duplicated rows (`stress_complete`).
 */
final class SqliteContentionProbe
{
    private const STRESS_WORKERS = 4;

    private const STRESS_ROWS_PER_WORKER = 100;

    public function __construct(
        private readonly ?string $workerScript = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $path = $this->fixturePath();
        $parent = $this->connect($path, 5000);
        $parent->exec('PRAGMA journal_mode = WAL');
        $parent->exec('CREATE TABLE sq_contention (id INTEGER PRIMARY KEY, worker INTEGER NOT NULL)');

        $busySurfaced = $this->probeBusySurfaced($parent, $path);
        $resilient = $this->probeBusyTimeoutResilience($parent, $path);
        $parent->exec('DELETE FROM sq_contention WHERE worker = 99');
        $stress = $this->probeConcurrentWriters($parent, $path);

        $count = $this->fetchCount($parent, 'SELECT COUNT(*) FROM sq_contention');
        $distinct = $this->fetchCount($parent, 'SELECT COUNT(DISTINCT id) FROM sq_contention');
        $stressComplete = $stress['inserted_total'] === $count
            && $count === self::STRESS_WORKERS * self::STRESS_ROWS_PER_WORKER
            && $distinct === self::STRESS_WORKERS * self::STRESS_ROWS_PER_WORKER;

        $this->require($busySurfaced, 'contended writer must surface SQLITE_BUSY');
        $this->require($resilient['completed'], 'busy-timeout writer must complete after the lock is released');
        $this->require($stressComplete, 'concurrent writers must commit every disjoint id exactly once');

        unset($parent);
        $this->cleanup($path);

        return [
            'php_version' => PHP_VERSION,
            'busy_surfaced' => $busySurfaced,
            'contended_sqlstate' => $this->contendedSqlState ?? null,
            'contended_driver_code' => $this->contendedDriverCode ?? null,
            'busy_timeout_resilient' => $resilient['completed'],
            'busy_timeout_wait_ms' => $resilient['wait_ms'],
            'resilient_inserted' => $resilient['inserted'],
            'stress_complete' => $stressComplete,
            'stress_workers' => self::STRESS_WORKERS,
            'stress_rows_per_worker' => self::STRESS_ROWS_PER_WORKER,
            'stress_rows' => $count,
            'stress_distinct_ids' => $distinct,
        ];
    }

    private function probeBusySurfaced(PDO $parent, string $path): bool
    {
        $parent->exec('BEGIN IMMEDIATE');
        try {
            $result = $this->runWorker([$path, 'contended', '0']);
            $decoded = $this->decode($result['output']);
        } finally {
            $parent->exec('ROLLBACK');
        }

        $this->contendedSqlState = isset($decoded['sqlstate']) && is_string($decoded['sqlstate'])
            ? $decoded['sqlstate']
            : null;
        $driverCode = $decoded['driver_code'] ?? null;
        $this->contendedDriverCode = is_int($driverCode) || is_string($driverCode) ? (string) $driverCode : null;

        return $result['status'] === 0 && ($decoded['busy_observed'] ?? false) === true;
    }

    /** @return array{completed: bool, inserted: int, wait_ms: float} */
    private function probeBusyTimeoutResilience(PDO $parent, string $path): array
    {
        $readyFile = $path . '.ready';
        $parent->exec('BEGIN IMMEDIATE');
        $process = $this->startWorker([$path, 'resilient', '2000', '50', $readyFile]);
        $started = hrtime(true);
        $ready = false;
        for ($attempt = 0; $attempt < 200 && !$ready; ++$attempt) {
            $ready = is_file($readyFile);
            if (!$ready) {
                usleep(25_000);
            }
        }
        usleep(250_000);
        $parent->exec('COMMIT');
        $result = $this->finishWorker($process);
        $waitMs = round((hrtime(true) - $started) / 1_000_000, 3);
        if (is_file($readyFile)) {
            unlink($readyFile);
        }

        $decoded = $this->decode($result['output']);
        $completed = $result['status'] === 0 && $this->intField($decoded, 'inserted') === 50;

        return [
            'completed' => $completed,
            'inserted' => $this->intField($decoded, 'inserted'),
            'wait_ms' => $waitMs,
        ];
    }

    /** @return array{inserted_total: int, workers: int} */
    private function probeConcurrentWriters(PDO $parent, string $path): array
    {
        $processes = [];
        for ($worker = 0; $worker < self::STRESS_WORKERS; ++$worker) {
            $arguments = [$path, 'stress', (string) $worker, (string) self::STRESS_ROWS_PER_WORKER];
            $processes[] = $this->startWorker($arguments);
        }

        $inserted = 0;
        foreach ($processes as $process) {
            $result = $this->finishWorker($process);
            if ($result['status'] !== 0) {
                throw new RuntimeException('A concurrent SQLite stress writer failed.');
            }
            $decoded = $this->decode($result['output']);
            $inserted += $this->intField($decoded, 'inserted');
        }

        return ['inserted_total' => $inserted, 'workers' => self::STRESS_WORKERS];
    }

    private function fixturePath(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'simplequery-contention-');
        if ($file === false) {
            throw new RuntimeException('Unable to create a SQLite contention fixture path.');
        }
        $path = $file . '.sqlite';
        unlink($file);

        return $path;
    }

    private function connect(string $path, int $busyTimeoutMilliseconds): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeoutMilliseconds);

        return $pdo;
    }

    private function fetchCount(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof \PDOStatement) {
            throw new RuntimeException('SQLite contention probe query did not return a statement.');
        }
        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('SQLite contention probe count returned an invalid value.');
        }

        return (int) $value;
    }

    /**
     * @param list<string> $arguments
     * @return array{status: int, output: string}
     */
    private function runWorker(array $arguments): array
    {
        return $this->finishWorker($this->startWorker($arguments));
    }

    /**
     * @param list<string> $arguments
     * @return resource
     */
    private function startWorker(array $arguments)
    {
        $command = array_merge([PHP_BINARY, $this->workerPath()], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a SQLite contention worker.');
        }
        $this->processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];

        return $process;
    }

    /**
     * @param resource $process
     * @return array{status: int, output: string}
     */
    private function finishWorker($process): array
    {
        $entry = null;
        foreach ($this->processes as $index => $candidate) {
            if ($candidate['process'] === $process) {
                $entry = $candidate;
                unset($this->processes[$index]);
                break;
            }
        }
        if ($entry === null) {
            throw new RuntimeException('Unknown SQLite contention worker process.');
        }

        $stdout = stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $status = proc_close($entry['process']);
        if ($stderr !== '' && $stderr !== false) {
            fwrite(STDERR, $stderr);
        }

        return ['status' => $status === -1 ? 1 : $status, 'output' => is_string($stdout) ? trim($stdout) : ''];
    }

    private function workerPath(): string
    {
        return $this->workerScript
            ?? dirname(__DIR__) . '/sqlite-contention-worker.php';
    }

    /** @return array<array-key, mixed> */
    private function decode(string $output): array
    {
        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('SQLite contention worker emitted invalid JSON: %s', $output));
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private function intField(array $decoded, string $key): int
    {
        $value = $decoded[$key] ?? 0;
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException(sprintf('SQLite contention worker field "%s" is not an integer.', $key));
        }

        return (int) $value;
    }

    private function require(bool $condition, string $assertion): void
    {
        if (!$condition) {
            throw new RuntimeException(sprintf('SQLite contention probe assertion failed: %s.', $assertion));
        }
    }

    private function cleanup(string $path): void
    {
        foreach ([$path, $path . '-shm', $path . '-wal', $path . '.ready'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }

    private ?string $contendedSqlState = null;

    private ?string $contendedDriverCode = null;

    /** @var array<int, array{process: resource, stdout: resource, stderr: resource}> */
    private array $processes = [];
}
