<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use JsonException;
use Closure;
use PDO;
use RuntimeException;

final class Harness
{
    /** @return array<string, mixed> */
    public static function measure(MeasurementRequest $request): array
    {
        $digests = self::operationDigests($request);
        $expectedDigest = self::assertDigestParity($digests);
        self::warmUp($request, $expectedDigest);
        $samples = self::sample($request, $expectedDigest);
        $summaries = self::summarize($samples, $digests);

        return [
            'correctness' => [
                'accepted' => true,
                'digest_algorithm' => 'sha256-json',
                'common_digest' => $expectedDigest,
            ],
            'measurement' => [
                'setup_timed' => false,
                'warmup_iterations' => $request->warmups(),
                'sample_iterations' => $request->iterations(),
                'sample_order' => $request->sampleOrder(),
                'operations' => $summaries,
            ],
        ];
    }

    /** @return array<non-empty-string, string> */
    private static function operationDigests(MeasurementRequest $request): array
    {
        $digests = [];
        foreach ($request->operations() as $name => $operation) {
            $digests[$name] = self::digest($operation());
        }

        return $digests;
    }

    /** @param array<non-empty-string, string> $digests */
    private static function assertDigestParity(array $digests): string
    {
        $expectedDigest = reset($digests);
        if (!is_string($expectedDigest)) {
            throw new RuntimeException('Benchmark operations produced no correctness digest.');
        }
        foreach ($digests as $name => $digest) {
            if (!hash_equals($expectedDigest, $digest)) {
                throw new RuntimeException(sprintf(
                    'Correctness parity failed for operation %s.',
                    $name,
                ));
            }
        }

        return $expectedDigest;
    }

    private static function warmUp(MeasurementRequest $request, string $expectedDigest): void
    {
        for ($warmup = 0; $warmup < $request->warmups(); ++$warmup) {
            foreach ($request->operations() as $name => $operation) {
                self::assertOperationDigest($name, $operation, $expectedDigest, 'Warm-up correctness');
            }
        }
    }

    /** @return array<non-empty-string, non-empty-list<array{elapsed_ms: float, allocated_after_bytes: int}>> */
    private static function sample(MeasurementRequest $request, string $expectedDigest): array
    {
        /** @var array<non-empty-string, non-empty-list<array{elapsed_ms: float, allocated_after_bytes: int}>> $samples */
        $samples = [];
        foreach ($request->operations() as $name => $_operation) {
            $samples[$name] = [];
        }
        for ($iteration = 0; $iteration < $request->iterations(); ++$iteration) {
            $operations = $iteration % 2 === 0
                ? $request->operations()
                : array_reverse($request->operations());
            foreach ($operations as $name => $operation) {
                $samples[$name][] = self::timeOperation($name, $operation, $expectedDigest);
            }
        }

        /** @var array<non-empty-string, non-empty-list<array{elapsed_ms: float, allocated_after_bytes: int}>> $samples */
        return $samples;
    }

    private static function assertOperationDigest(
        string $name,
        Closure $operation,
        string $expectedDigest,
        string $phase,
    ): void {
        self::assertResultDigest($operation(), $name, $expectedDigest, $phase);
    }

    /** @return array{elapsed_ms: float, allocated_after_bytes: int} */
    private static function timeOperation(
        string $name,
        Closure $operation,
        string $expectedDigest,
    ): array {
        $started = hrtime(true);
        $result = $operation();
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $allocatedAfter = memory_get_usage(true);
        self::assertResultDigest($result, $name, $expectedDigest, 'Timed correctness');

        return ['elapsed_ms' => $elapsed, 'allocated_after_bytes' => $allocatedAfter];
    }

    private static function assertResultDigest(
        mixed $result,
        string $name,
        string $expectedDigest,
        string $phase,
    ): void {
        if (!hash_equals($expectedDigest, self::digest($result))) {
            throw new RuntimeException(sprintf('%s failed for operation %s.', $phase, $name));
        }
    }

    /**
     * @param array<non-empty-string, non-empty-list<array{elapsed_ms: float, allocated_after_bytes: int}>> $samples
     * @param array<non-empty-string, string> $digests
     * @return array<non-empty-string, array<string, mixed>>
     */
    private static function summarize(array $samples, array $digests): array
    {
        $summaries = [];
        foreach ($samples as $name => $rawSamples) {
            $summaries[$name] = self::summarizeOperation($rawSamples, $digests[$name]);
        }

        return $summaries;
    }

    /**
     * @param non-empty-list<array{elapsed_ms: float, allocated_after_bytes: int}> $rawSamples
     * @return array<string, mixed>
     */
    private static function summarizeOperation(array $rawSamples, string $digest): array
    {
        $timings = [];
        $allocations = [];
        foreach ($rawSamples as $sample) {
            $timings[] = $sample['elapsed_ms'];
            $allocations[] = $sample['allocated_after_bytes'];
        }
        $sorted = $timings;
        sort($sorted);
        $firstAllocation = $allocations[0];
        $lastAllocation = $allocations[count($allocations) - 1];
        $peakAllocation = max($allocations);

        return [
            'samples_ms' => array_map(static fn (float $sample): float => round($sample, 6), $timings),
            'minimum_ms' => round($sorted[0], 6),
            'median_ms' => round($sorted[intdiv(count($sorted), 2)], 6),
            'maximum_ms' => round($sorted[count($sorted) - 1], 6),
            'allocated_after_sample_bytes' => $allocations,
            'retained_growth_bytes' => max(0, $lastAllocation - $firstAllocation),
            'retained_peak_above_first_bytes' => max(0, $peakAllocation - $firstAllocation),
            'correctness_digest' => $digest,
        ];
    }

    /**
     * @param array{package_root: string, pdo?: PDO|null, target?: string|null} $request
     * @return array<string, mixed>
     */
    public static function environment(array $request): array
    {
        $xdebugMode = ini_get('xdebug.mode');
        $pcovEnabled = ini_get('pcov.enabled');
        $instrumentationDisabled = (!extension_loaded('xdebug') || $xdebugMode === '' || $xdebugMode === 'off')
            && (!extension_loaded('pcov') || $pcovEnabled === '' || $pcovEnabled === '0');

        return [
            'source' => self::sourceMetadata($request),
            'os' => [
                'family' => PHP_OS_FAMILY,
                'name' => php_uname('s'),
                'release' => php_uname('r'),
                'architecture' => php_uname('m'),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'opcache_cli_enabled' => ini_get('opcache.enable_cli') === '1',
                'jit' => ini_get('opcache.jit'),
                'xdebug_loaded' => extension_loaded('xdebug'),
                'xdebug_mode' => $xdebugMode === false ? null : $xdebugMode,
                'pcov_loaded' => extension_loaded('pcov'),
                'pcov_enabled' => $pcovEnabled === false ? null : $pcovEnabled,
                'timing_instrumentation_disabled' => $instrumentationDisabled,
            ],
            'pdo' => self::pdoMetadata($request),
        ];
    }

    /** @return array{php_peak_allocated_bytes: int, process_max_rss_bytes: int|null, rss_source: string} */
    public static function memory(): array
    {
        $usage = getrusage();
        $rss = is_array($usage) && isset($usage['ru_maxrss']) && is_int($usage['ru_maxrss'])
            ? $usage['ru_maxrss']
            : null;
        if ($rss !== null && PHP_OS_FAMILY !== 'Darwin') {
            $rss *= 1024;
        }

        return [
            'php_peak_allocated_bytes' => memory_get_peak_usage(true),
            'process_max_rss_bytes' => $rss,
            'rss_source' => 'getrusage.ru_maxrss',
        ];
    }

    /** @return array{allocated_bytes: int, open_file_descriptors: int|null} */
    public static function resourceSnapshot(): array
    {
        $descriptors = glob('/proc/self/fd/*');

        return [
            'allocated_bytes' => memory_get_usage(true),
            'open_file_descriptors' => is_array($descriptors) ? count($descriptors) : null,
        ];
    }

    /** @param array<string, mixed> $environment */
    public static function assertTimingInstrumentationDisabled(array $environment): void
    {
        $php = $environment['php'] ?? null;
        if (!is_array($php) || ($php['timing_instrumentation_disabled'] ?? false) !== true) {
            throw new RuntimeException('Timing refused because debugger or coverage instrumentation is active.');
        }
    }

    public static function digest(mixed $value): string
    {
        try {
            return hash('sha256', json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException('Benchmark result is not JSON-serializable.', 0, $exception);
        }
    }

    /**
     * @param array{package_root: string, pdo?: PDO|null, target?: string|null} $request
     * @return array<string, mixed>
     */
    private static function sourceMetadata(array $request): array
    {
        $commit = self::git($request, ['rev-parse', 'HEAD']);
        $status = self::git($request, ['status', '--porcelain']);

        return [
            'root_basename' => basename($request['package_root']),
            'commit' => $commit,
            'dirty' => $status === null ? null : $status !== '',
        ];
    }

    /**
     * @param array{package_root: string, pdo?: PDO|null, target?: string|null} $request
     * @param list<string> $arguments
     */
    private static function git(array $request, array $arguments): ?string
    {
        $parts = ['git', '-C', escapeshellarg($request['package_root'])];
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        $output = [];
        $status = 0;
        exec(implode(' ', $parts) . ' 2>/dev/null', $output, $status);

        return $status === 0 ? trim(implode("\n", $output)) : null;
    }

    /**
     * @param array{package_root: string, pdo?: PDO|null, target?: string|null} $request
     * @return array<string, mixed>
     */
    private static function pdoMetadata(array $request): array
    {
        $pdo = $request['pdo'] ?? null;
        $metadata = [
            'available_drivers' => PDO::getAvailableDrivers(),
            'target' => $request['target'] ?? null,
            'driver' => null,
            'client_version' => null,
            'server_version' => null,
            'connection_status' => null,
            'emulated_prepares' => null,
            'buffered_queries' => null,
            'persistent' => null,
            'stringify_fetches' => null,
        ];
        if ($pdo === null) {
            return $metadata;
        }

        foreach (
            [
            'driver' => PDO::ATTR_DRIVER_NAME,
            'client_version' => PDO::ATTR_CLIENT_VERSION,
            'server_version' => PDO::ATTR_SERVER_VERSION,
            'connection_status' => PDO::ATTR_CONNECTION_STATUS,
            'emulated_prepares' => PDO::ATTR_EMULATE_PREPARES,
            'persistent' => PDO::ATTR_PERSISTENT,
            'stringify_fetches' => PDO::ATTR_STRINGIFY_FETCHES,
            ] as $name => $attribute
        ) {
            try {
                $metadata[$name] = $pdo->getAttribute($attribute);
            } catch (\Throwable) {
                $metadata[$name] = null;
            }
        }
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            try {
                $metadata['buffered_queries'] = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            } catch (\Throwable) {
                $metadata['buffered_queries'] = null;
            }
        }

        return $metadata;
    }
}
