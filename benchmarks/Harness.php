<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Closure;
use JsonException;
use PDO;
use RuntimeException;

final class Harness
{
    /**
     * @param array<non-empty-string, Closure(): mixed> $operations
     * @return array<string, mixed>
     */
    public static function measure(array $operations, int $warmups, int $iterations): array
    {
        self::validateMeasurementArguments($operations, $warmups, $iterations);
        $digests = self::operationDigests($operations);
        $expectedDigest = self::assertDigestParity($digests);
        self::warmUp($operations, $warmups, $expectedDigest);
        $samples = self::sample($operations, $iterations, $expectedDigest);
        $summaries = self::summarize($samples, $digests);

        return [
            'correctness' => [
                'accepted' => true,
                'digest_algorithm' => 'sha256-json',
                'common_digest' => $expectedDigest,
            ],
            'measurement' => [
                'setup_timed' => false,
                'warmup_iterations' => $warmups,
                'sample_iterations' => $iterations,
                'sample_order' => count($operations) > 1 ? 'alternating-forward-reverse' : 'single-operation',
                'operations' => $summaries,
            ],
        ];
    }

    /** @param array<non-empty-string, Closure(): mixed> $operations */
    private static function validateMeasurementArguments(array $operations, int $warmups, int $iterations): void
    {
        if ($operations === []) {
            throw new RuntimeException('Benchmarks require operations, a warm-up, and an odd sample count.');
        }
        if ($warmups < 1) {
            throw new RuntimeException('Benchmarks require operations, a warm-up, and an odd sample count.');
        }
        if ($iterations < 1 || $iterations % 2 === 0) {
            throw new RuntimeException('Benchmarks require operations, a warm-up, and an odd sample count.');
        }
    }

    /**
     * @param array<non-empty-string, Closure(): mixed> $operations
     * @return array<non-empty-string, string>
     */
    private static function operationDigests(array $operations): array
    {
        $digests = [];
        foreach ($operations as $name => $operation) {
            $digests[$name] = self::digest(self::execute($operation));
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
            if ($digest !== $expectedDigest) {
                throw new RuntimeException(sprintf('Correctness parity failed for operation %s.', $name));
            }
        }

        return $expectedDigest;
    }

    /** @param array<non-empty-string, Closure(): mixed> $operations */
    private static function warmUp(array $operations, int $warmups, string $expectedDigest): void
    {
        for ($warmup = 0; $warmup < $warmups; ++$warmup) {
            foreach ($operations as $name => $operation) {
                self::assertOperationDigest($operation, $expectedDigest, 'Warm-up', $name);
            }
        }
    }

    /**
     * @param array<non-empty-string, Closure(): mixed> $operations
     * @return array<non-empty-string, non-empty-list<float>>
     */
    private static function sample(array $operations, int $iterations, string $expectedDigest): array
    {
        /** @var array<non-empty-string, list<float>> $samples */
        $samples = array_fill_keys(array_keys($operations), []);
        $names = array_keys($operations);
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $orderedNames = $iteration % 2 === 0 ? $names : array_reverse($names);
            foreach ($orderedNames as $name) {
                $samples[$name][] = self::timeOperation($operations[$name], $expectedDigest, $name);
            }
        }

        /** @var array<non-empty-string, non-empty-list<float>> $samples */
        return $samples;
    }

    private static function assertOperationDigest(
        Closure $operation,
        string $expectedDigest,
        string $phase,
        string $name,
    ): void {
        self::assertResultDigest(self::execute($operation), $expectedDigest, $phase, $name);
    }

    private static function timeOperation(Closure $operation, string $expectedDigest, string $name): float
    {
        $started = hrtime(true);
        $result = self::execute($operation);
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        self::assertResultDigest($result, $expectedDigest, 'Timed', $name);

        return $elapsed;
    }

    private static function assertResultDigest(
        mixed $result,
        string $expectedDigest,
        string $phase,
        string $name,
    ): void {
        if (self::digest($result) !== $expectedDigest) {
            throw new RuntimeException(sprintf('%s correctness failed for operation %s.', $phase, $name));
        }
    }

    /**
     * @param array<non-empty-string, non-empty-list<float>> $samples
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
     * @param non-empty-list<float> $rawSamples
     * @return array<string, mixed>
     */
    private static function summarizeOperation(array $rawSamples, string $digest): array
    {
        $sorted = $rawSamples;
        sort($sorted);

        return [
            'samples_ms' => array_map(static fn (float $sample): float => round($sample, 6), $rawSamples),
            'minimum_ms' => round($sorted[0], 6),
            'median_ms' => round($sorted[intdiv(count($sorted), 2)], 6),
            'maximum_ms' => round($sorted[count($sorted) - 1], 6),
            'correctness_digest' => $digest,
        ];
    }

    /** @return array<string, mixed> */
    public static function environment(string $packageRoot, ?PDO $pdo = null, ?string $target = null): array
    {
        $xdebugMode = ini_get('xdebug.mode');
        $pcovEnabled = ini_get('pcov.enabled');
        $instrumentationDisabled = (!extension_loaded('xdebug') || $xdebugMode === '' || $xdebugMode === 'off')
            && (!extension_loaded('pcov') || $pcovEnabled === '' || $pcovEnabled === '0');

        return [
            'source' => self::sourceMetadata($packageRoot),
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
            'pdo' => self::pdoMetadata($pdo, $target),
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

    private static function execute(Closure $operation): mixed
    {
        return $operation();
    }

    /** @return array<string, mixed> */
    private static function sourceMetadata(string $packageRoot): array
    {
        $commit = self::git($packageRoot, ['rev-parse', 'HEAD']);
        $status = self::git($packageRoot, ['status', '--porcelain']);

        return [
            'root_basename' => basename($packageRoot),
            'commit' => $commit,
            'dirty' => $status === null ? null : $status !== '',
        ];
    }

    /** @param list<string> $arguments */
    private static function git(string $packageRoot, array $arguments): ?string
    {
        $parts = ['git', '-C', escapeshellarg($packageRoot)];
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        $output = [];
        $status = 0;
        exec(implode(' ', $parts) . ' 2>/dev/null', $output, $status);

        return $status === 0 ? trim(implode("\n", $output)) : null;
    }

    /** @return array<string, mixed> */
    private static function pdoMetadata(?PDO $pdo, ?string $target): array
    {
        $metadata = [
            'available_drivers' => PDO::getAvailableDrivers(),
            'target' => $target,
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
