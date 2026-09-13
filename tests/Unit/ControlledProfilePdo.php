<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;

final class ControlledProfilePdo extends PDO
{
    public function __construct(private readonly string $intercept, private readonly string $behavior)
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        parent::exec('PRAGMA foreign_keys = ON');
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== $this->intercept) {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        return match ($this->behavior) {
            'query-false' => false,
            'query-throws' => throw new PDOException('Synthetic profile read failure.'),
            'fetch-false' => parent::query('SELECT 0 WHERE 0'),
            'integer-zero' => parent::query('SELECT 0'),
            'string-zero' => parent::query("SELECT '0'"),
            default => throw new \LogicException('Unknown controlled profile behavior.'),
        };
    }
}
