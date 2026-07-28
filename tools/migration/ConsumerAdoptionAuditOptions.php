<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

final readonly class ConsumerAdoptionAuditOptions
{
    public function __construct(
        public ConsumerPathPolicy $paths = ConsumerPathPolicy::Redacted,
        public ConsumerTimestampPolicy $timestamp = ConsumerTimestampPolicy::Current,
    ) {
    }

    public function includesPaths(): bool
    {
        return $this->paths === ConsumerPathPolicy::Included;
    }

    public function isDeterministic(): bool
    {
        return $this->timestamp === ConsumerTimestampPolicy::Deterministic;
    }
}
