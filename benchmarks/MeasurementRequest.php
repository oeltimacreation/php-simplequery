<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class MeasurementRequest
{
    /** @var non-empty-list<BenchmarkOperation> */
    private array $operations;

    private int $warmups;

    private int $iterations;

    private function __construct()
    {
    }

    /**
     * @param array<non-empty-string, \Closure(): mixed> $operations
     * @param array{warmups: int, iterations: int} $sampling
     */
    public static function from(array $operations, array $sampling): self
    {
        self::validate($operations, $sampling);
        $request = new self();
        $benchmarkOperations = [];
        foreach ($operations as $name => $operation) {
            $benchmarkOperations[] = BenchmarkOperation::from(['name' => $name, 'operation' => $operation]);
        }
        $request->operations = $benchmarkOperations;
        $request->warmups = $sampling['warmups'];
        $request->iterations = $sampling['iterations'];

        return $request;
    }

    /** @return non-empty-list<BenchmarkOperation> */
    public function operations(): array
    {
        return $this->operations;
    }

    public function warmups(): int
    {
        return $this->warmups;
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    public function sampleOrder(): string
    {
        return count($this->operations) > 1 ? 'alternating-forward-reverse' : 'single-operation';
    }

    /**
     * @param array<non-empty-string, \Closure(): mixed> $operations
     * @param array{warmups: int, iterations: int} $sampling
     * @phpstan-assert non-empty-array<non-empty-string, \Closure(): mixed> $operations
     */
    private static function validate(array $operations, array $sampling): void
    {
        if ($operations === []) {
            throw new RuntimeException('Benchmarks require operations, a warm-up, and an odd sample count.');
        }
        if ($sampling['warmups'] < 1) {
            throw new RuntimeException('Benchmarks require operations, a warm-up, and an odd sample count.');
        }
        if ($sampling['iterations'] < 1 || $sampling['iterations'] % 2 === 0) {
            throw new RuntimeException('Benchmarks require operations, a warm-up, and an odd sample count.');
        }
    }
}
