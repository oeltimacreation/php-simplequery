<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

/**
 * A single versioned golden fixture case.
 *
 * Carries the per-driver case identity and expected SQL so the duplication gate
 * never exchanges string-keyed arrays between its scanning steps.
 *
 * @internal
 */
final readonly class GoldenCase
{
    public function __construct(
        public string $id,
        public string $sql,
        public string $location,
    ) {
    }
}
