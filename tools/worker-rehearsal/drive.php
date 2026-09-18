<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeReport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array<string, false|string> $options */
$options = getopt('', ['base-url:', 'mode:', 'soak-seconds:', 'idle-seconds:', 'output:']);
$baseUrl = $options['base-url'] ?? null;
$mode = $options['mode'] ?? 'worker';
$soakSeconds = filter_var($options['soak-seconds'] ?? '0', FILTER_VALIDATE_INT);
$idleSeconds = filter_var($options['idle-seconds'] ?? '1.5', FILTER_VALIDATE_FLOAT);
$output = $options['output'] ?? null;
if (
    !is_string($baseUrl) || $baseUrl === ''
    || !is_string($mode) || !in_array($mode, ['worker', 'classic'], true)
    || !is_int($soakSeconds) || $soakSeconds < 0
    || !is_float($idleSeconds) || $idleSeconds <= 0
    || ($output !== null && !is_string($output))
) {
    fwrite(
        STDERR,
        "Usage: drive.php --base-url=URL --mode=<worker|classic>"
        . " [--soak-seconds=N] [--idle-seconds=S] [--output=PATH]\n",
    );
    exit(2);
}

/**
 * @return array{status: int, body: array<string, mixed>, elapsed_ms: float}
 */
$request = static function (string $path) use ($baseUrl): array {
    $handle = curl_init($baseUrl . $path);
    if ($handle === false) {
        throw new RuntimeException('Could not create an HTTP client.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $started = hrtime(true);
    $body = curl_exec($handle);
    $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if (!is_string($body)) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    $decoded = json_decode($body, true);

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'elapsed_ms' => $elapsedMilliseconds,
    ];
};

/**
 * @param array<string, mixed> $body
 */
$counter = static function (array $body, string $name): int {
    $counters = $body['counters'] ?? null;
    if (!is_array($counters)) {
        return -1;
    }
    $value = $counters[$name] ?? null;

    return is_int($value) ? $value : -1;
};

/**
 * @param array<string, mixed> $body
 */
$reason = static function (array $body, string $name): int {
    $reasons = $body['reasons'] ?? null;
    if (!is_array($reasons)) {
        return -1;
    }
    $value = $reasons[$name] ?? null;

    return is_int($value) ? $value : -1;
};

/** @param list<float> $samples */
$percentile = static function (array $samples, float $quantile): float {
    if ($samples === []) {
        return 0.0;
    }
    sort($samples);
    $index = (int) floor($quantile * (count($samples) - 1));

    return $samples[$index];
};

$boot = $request('/health');
$report = new ProbeReport('worker-rehearsal-' . $mode, 'frankenphp', [
    'base_url' => $baseUrl,
    'mode' => $mode,
    'idle_threshold_seconds' => $idleSeconds,
    'soak_seconds' => $soakSeconds,
    'php_version' => $boot['body']['php_version'] ?? null,
    'sapi' => $boot['body']['sapi'] ?? null,
    'zts' => $boot['body']['zts'] ?? null,
    'pid' => $boot['body']['pid'] ?? null,
    'server_version' => $boot['body']['server_version'] ?? null,
    'client_version' => $boot['body']['client_version'] ?? null,
]);

/**
 * @param array<string, mixed> $details
 */
$require = static function (string $name, bool $condition, array $details = []) use ($report): void {
    if ($name === '') {
        throw new InvalidArgumentException('Rehearsal assertion name cannot be empty.');
    }
    if (!$condition) {
        throw new RuntimeException('Worker rehearsal assertion failed: ' . $name);
    }
    $report->passed($name, $details);
};

try {
    $require('health_boot', $boot['status'] === 200, ['status' => $boot['status']]);
    if ($mode === 'worker') {
        $require('worker_zts', ($boot['body']['zts'] ?? null) === true);
        $require('worker_single_pid', is_int($boot['body']['pid'] ?? null));
    }
    $initializeValue = $boot['body']['initialize_limit'] ?? null;
    $reportLimitValue = $boot['body']['report_limit'] ?? null;
    $require(
        'statement_limits_declared',
        is_numeric($initializeValue) && is_numeric($reportLimitValue),
    );
    if (!is_numeric($initializeValue) || !is_numeric($reportLimitValue)) {
        throw new RuntimeException('Missing statement limit values.');
    }

    // Cache-only requests must not create a connection.
    $createdBeforeCache = $counter($boot['body'], 'created');
    $cache = $request('/cache');
    $healthAfterCache = $request('/health');
    $require(
        'cache_only_no_connection',
        $cache['status'] === 200 && $counter($healthAfterCache['body'], 'created') === $createdBeforeCache,
    );

    // Warm reuse keeps one session; classic mode gets a fresh owner per request.
    $firstHealth = $request('/health');
    $secondHealth = $request('/health');
    if ($mode === 'worker') {
        $require(
            'worker_session_pinning',
            ($firstHealth['body']['session_id'] ?? null) === ($secondHealth['body']['session_id'] ?? null)
                && ($firstHealth['body']['pid'] ?? null) === ($secondHealth['body']['pid'] ?? null),
        );
        $require(
            'worker_connection_reuse',
            $counter($secondHealth['body'], 'created') === $counter($firstHealth['body'], 'created'),
        );
    } else {
        $require(
            'classic_fresh_session_per_request',
            ($firstHealth['body']['session_id'] ?? null) !== ($secondHealth['body']['session_id'] ?? null),
        );
        $require('classic_created_per_request', $counter($secondHealth['body'], 'created') === 1);
    }

    $write = $request('/write');
    $require('write_insert', $write['status'] === 200 && is_string($write['body']['id'] ?? null));
    $read = $request('/read');
    $rows = $read['body']['rows'] ?? null;
    $require('read_rows', $read['status'] === 200 && is_int($rows) && $rows > 0, ['rows' => $rows]);

    $transaction = $request('/transaction');
    $require(
        'transaction_commit',
        $transaction['status'] === 200
            && is_int($transaction['body']['rows'] ?? null)
            && $transaction['body']['rows'] > $rows,
    );

    $reported = $request('/report');
    $raisedLimit = $reported['body']['raised_limit'] ?? null;
    $require(
        'report_success',
        $reported['status'] === 200
            && is_numeric($raisedLimit)
            && abs((float) $raisedLimit - (float) $reportLimitValue) < 0.001,
    );
    $healthAfterReport = $request('/health');
    $restoredLimit = $healthAfterReport['body']['statement_limit'] ?? null;
    $require(
        'report_restored',
        is_numeric($restoredLimit) && abs((float) $restoredLimit - (float) $initializeValue) < 0.001,
    );

    $failed = $request('/fail');
    $require(
        'query_failure_status',
        $failed['status'] === 503 && ($failed['body']['error'] ?? null) === 'query_failed',
    );
    $recovered = $request('/read');
    $require('query_failure_recovery', $recovered['status'] === 200);
    if ($mode === 'worker') {
        $healthAfterFailure = $request('/health');
        $require('query_failure_evicted', $counter($healthAfterFailure['body'], 'evicted') >= 1);
        $require(
            'query_failure_no_cleanup_failure',
            $counter($healthAfterFailure['body'], 'cleanup_failed') === 0,
        );
    }

    $broken = $request('/construction-failure');
    $require(
        'construction_failure_status',
        $broken['status'] === 503 && ($broken['body']['operation'] ?? null) === 'connect',
    );
    $restored = $request('/read');
    $require('construction_recovery', $restored['status'] === 200);
    if ($mode === 'worker') {
        $healthAfterConstruction = $request('/health');
        $require(
            'construction_failure_counted',
            $counter($healthAfterConstruction['body'], 'failed_construction') >= 1,
        );
    }

    if ($mode === 'worker') {
        // Proactive replacement: the local idle boundary retires the handle
        // before an expired socket can fail a statement.
        $beforeIdle = $request('/health');
        $idleReplacements = $counter($beforeIdle['body'], 'replaced_idle');
        usleep((int) ceil(($idleSeconds + 0.75) * 1_000_000));
        $afterIdle = $request('/read');
        $healthAfterIdle = $request('/health');
        $require('proactive_idle_success', $afterIdle['status'] === 200);
        $require(
            'proactive_idle_replaced',
            $counter($healthAfterIdle['body'], 'replaced_idle') > $idleReplacements,
        );
        $require(
            'proactive_idle_no_failed_statement',
            $reason($healthAfterIdle['body'], 'query_failed') === $reason($beforeIdle['body'], 'query_failed'),
        );

        // Abrupt loss independent of the idle policy: one failed statement,
        // explicit eviction, successful replacement for the next unit.
        $kill = $request('/admin-kill');
        $require('abrupt_kill_session', $kill['status'] === 200 && is_int($kill['body']['session_id'] ?? null));
        $dead = $request('/read');
        $require('abrupt_loss_failure', $dead['status'] === 503);
        $recoveredAfterKill = $request('/read');
        $require('abrupt_loss_recovery', $recoveredAfterKill['status'] === 200);
        $healthAfterKill = $request('/health');
        $require('abrupt_loss_evicted', $counter($healthAfterKill['body'], 'evicted') >= 2);
    }

    if ($soakSeconds > 0) {
        $weights = array_merge(
            array_fill(0, 50, '/read'),
            array_fill(0, 30, '/cache'),
            array_fill(0, 15, '/write'),
            array_fill(0, 3, '/transaction'),
            array_fill(0, 2, '/report'),
        );
        $deadline = microtime(true) + $soakSeconds;
        $iteration = 0;
        $requestErrors = 0;
        $idleCycles = 0;
        $failureCycles = 0;
        /** @var array<string, list<float>> $latencies */
        $latencies = [];
        /** @var list<int> $memorySamples */
        $memorySamples = [];
        /** @var list<array<string, mixed>> $errorDetails */
        $errorDetails = [];

        while (microtime(true) < $deadline) {
            $path = $weights[$iteration % count($weights)];
            $response = $request($path);
            $latencies[$path][] = $response['elapsed_ms'];
            if ($response['status'] !== 200) {
                ++$requestErrors;
                if (count($errorDetails) < 10) {
                    $errorDetails[] = [
                        'iteration' => $iteration,
                        'endpoint' => $path,
                        'status' => $response['status'],
                        'error' => $response['body']['error'] ?? null,
                        'driver_code' => $response['body']['driver_code'] ?? null,
                    ];
                }
            }
            if ($iteration % 25 === 24) {
                sleep((int) ceil($idleSeconds + 0.5));
                ++$idleCycles;
                $request('/read');
            }
            if ($iteration % 100 === 99) {
                $request('/fail');
                $request('/read');
                ++$failureCycles;
            }
            if ($iteration % 20 === 0) {
                $sample = $request('/health');
                $memory = $sample['body']['memory_bytes'] ?? null;
                if (is_int($memory)) {
                    $memorySamples[] = $memory;
                }
            }
            ++$iteration;
        }

        $final = $request('/health');
        $growth = $memorySamples === [] ? 0 : max($memorySamples) - min($memorySamples);
        $report->observed('soak_summary', [
            'iterations' => $iteration,
            'request_errors' => $requestErrors,
            'idle_cycles' => $idleCycles,
            'failure_cycles' => $failureCycles,
            'memory_growth_bytes' => $growth,
            'connection_creations' => $counter($final['body'], 'created'),
            'memory_samples' => count($memorySamples),
            'final_counters' => $final['body']['counters'] ?? null,
            'error_details' => $errorDetails,
        ]);
        $require('soak_no_request_errors', $requestErrors === 0, ['errors' => $requestErrors]);
        $require('soak_no_cleanup_failures', $counter($final['body'], 'cleanup_failed') === 0);
        $require('soak_memory_budget', $growth <= 32 * 1024 * 1024, ['growth_bytes' => $growth]);
        if ($mode === 'worker') {
            $require(
                'soak_bounded_connections',
                $counter($final['body'], 'created') <= $idleCycles + $failureCycles + 5,
                ['created' => $counter($final['body'], 'created')],
            );
        }
        foreach (['/read', '/write', '/transaction'] as $path) {
            $p95 = $percentile($latencies[$path] ?? [], 0.95);
            $require(
                'latency_p95_' . trim($path, '/'),
                $p95 <= 250.0,
                ['p95_ms' => round($p95, 1), 'samples' => count($latencies[$path] ?? [])],
            );
        }
        $cacheP95 = $percentile($latencies['/cache'] ?? [], 0.95);
        $require(
            'latency_p95_cache',
            $cacheP95 <= 25.0,
            ['p95_ms' => round($cacheP95, 1), 'samples' => count($latencies['/cache'] ?? [])],
        );
    }
} catch (Throwable $failure) {
    $report->failed('rehearsal_driver', [
        'exception_class' => $failure::class,
        'message' => $failure->getMessage(),
    ]);
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, "Could not write worker rehearsal report.\n");
    exit(2);
}
exit($report->hasFailures() ? 1 : 0);
