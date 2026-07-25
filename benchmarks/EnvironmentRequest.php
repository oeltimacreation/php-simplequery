<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use PDO;

final class EnvironmentRequest
{
    private string $packageRoot;

    private ?PDO $pdo;

    private ?string $target;

    private function __construct()
    {
    }

    /** @param array{package_root: string, pdo?: PDO|null, target?: string|null} $context */
    public static function from(array $context): self
    {
        $request = new self();
        $request->packageRoot = $context['package_root'];
        $request->pdo = $context['pdo'] ?? null;
        $request->target = $context['target'] ?? null;

        return $request;
    }

    public function packageRoot(): string
    {
        return $this->packageRoot;
    }

    public function pdo(): ?PDO
    {
        return $this->pdo;
    }

    public function target(): ?string
    {
        return $this->target;
    }
}
