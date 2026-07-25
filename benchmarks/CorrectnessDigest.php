<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

final class CorrectnessDigest
{
    private string $value;

    private function __construct()
    {
    }

    public static function fromResult(mixed $result): self
    {
        $digest = new self();
        $digest->value = Harness::digest($result);

        return $digest;
    }

    public function matches(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
