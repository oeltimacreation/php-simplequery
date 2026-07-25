<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use PDO;

final readonly class PreparedScenario
{
    /**
     * @param array<non-empty-string, \Closure(): mixed> $operations
     * @param array<string, int> $dimensions
     */
    public function __construct(
        public array $operations,
        public ?PDO $pdo,
        public array $dimensions,
    ) {
    }
}
