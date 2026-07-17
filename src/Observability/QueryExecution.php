<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Observability;

use Oeltima\SimpleQuery\Driver;

final readonly class QueryExecution
{
    /**
     * @param list<\Oeltima\SimpleQuery\ParameterType> $parameterTypes
     */
    public function __construct(
        public string $sql,
        public array $parameterTypes,
        public float $durationMilliseconds,
        public bool $successful,
        public ?int $affectedRows,
        public Driver $driver,
        public ?string $connectionLabel,
        public int $transactionDepth,
    ) {
    }
}
