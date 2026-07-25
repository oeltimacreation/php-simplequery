<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Closure;

final class BenchmarkOperation
{
    /** @var non-empty-string */
    private string $name;

    /** @var Closure(): mixed */
    private Closure $operation;

    private function __construct()
    {
    }

    /** @param array{name: non-empty-string, operation: Closure(): mixed} $definition */
    public static function from(array $definition): self
    {
        $benchmark = new self();
        $benchmark->name = $definition['name'];
        $benchmark->operation = $definition['operation'];

        return $benchmark;
    }

    /** @return non-empty-string */
    public function name(): string
    {
        return $this->name;
    }

    public function execute(): mixed
    {
        return ($this->operation)();
    }
}
