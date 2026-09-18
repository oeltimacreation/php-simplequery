<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Examples\WorkerLifecycle;

/** Application model that resolves its owner on every call. */
final class JobModel
{
    public function __construct(private readonly RequestScope $scope)
    {
    }

    public function create(string $name): string
    {
        return $this->scope->connection('primary')->table('jobs')->insertGetId(['name' => $name]);
    }

    public function count(): int
    {
        return $this->scope->connection('primary')->table('jobs')->count();
    }
}
