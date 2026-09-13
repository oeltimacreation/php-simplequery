<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDO;
use PDOException;

final class ControlledTransactionPdo extends PDO
{
    /** @var list<string> */
    public array $controlCalls = [];

    public bool $failInspectionAfterRollback = false;

    public bool $failBegin = false;

    public bool $failBeginAfterDispatch = false;

    public bool $failCommit = false;

    public bool $failRollback = false;

    public bool $pretendCommitSuccess = false;

    public bool $pretendRollbackSuccess = false;

    public bool $failTransactionInspection = false;

    public ?string $failControlPrefix = null;

    public ?string $failControlAfterDispatchPrefix = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        parent::exec('PRAGMA foreign_keys = ON');
        parent::exec('PRAGMA busy_timeout = 5000');
    }

    #[\Override]
    public function beginTransaction(): bool
    {
        $this->controlCalls[] = 'begin';
        if ($this->failBegin) {
            throw new PDOException('Controlled begin failure.');
        }
        if ($this->failBeginAfterDispatch) {
            parent::beginTransaction();

            throw new PDOException('Controlled post-dispatch begin failure.');
        }

        return parent::beginTransaction();
    }

    #[\Override]
    public function commit(): bool
    {
        $this->controlCalls[] = 'commit';
        if ($this->failCommit) {
            throw new PDOException('Controlled commit failure.');
        }
        if ($this->pretendCommitSuccess) {
            return true;
        }

        return parent::commit();
    }

    #[\Override]
    public function rollBack(): bool
    {
        $this->controlCalls[] = 'rollback';
        if ($this->failRollback) {
            throw new PDOException('Controlled rollback failure.');
        }
        if ($this->pretendRollbackSuccess) {
            return true;
        }

        $result = parent::rollBack();
        $this->failTransactionInspection = $this->failInspectionAfterRollback;

        return $result;
    }

    #[\Override]
    public function inTransaction(): bool
    {
        if ($this->failTransactionInspection) {
            throw new PDOException('Controlled transaction-state inspection failure.');
        }

        return parent::inTransaction();
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $this->controlCalls[] = $statement;
        if ($this->failControlPrefix !== null && str_starts_with($statement, $this->failControlPrefix)) {
            throw new PDOException('Controlled transaction-control SQL failure.');
        }
        if (
            $this->failControlAfterDispatchPrefix !== null
            && str_starts_with($statement, $this->failControlAfterDispatchPrefix)
        ) {
            parent::exec($statement);

            throw new PDOException('Controlled post-dispatch transaction-control SQL failure.');
        }

        return parent::exec($statement);
    }
}
