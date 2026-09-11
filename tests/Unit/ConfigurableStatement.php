<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Configurable PDOStatement test double.
 *
 * A single double expresses the fetch-result, fetch/close failure, and
 * false-close outcomes that previously required seven near-identical
 * subclasses. Configure it with the named static helpers before preparing a
 * statement and reset it in setUp() so static state cannot leak between tests.
 */
final class ConfigurableStatement extends PDOStatement
{
    public const DEFAULT_FETCH_FAILURE = 'Controlled statement fetch failure.';

    public const DEFAULT_CLOSE_FAILURE = 'Controlled statement close failure.';

    /** @var mixed */
    public static mixed $row = false;

    /** When true, fetch() returns $row exactly once and then false. */
    public static bool $rowOnce = false;

    public static bool $fetchThrows = false;

    public static bool $closeThrows = false;

    public static bool $closeReturnsFalse = false;

    public static bool $closed = false;

    public static int $closeCalls = 0;

    public static int $rowCountCalls = 0;

    public static bool $bindReturnsFalse = false;

    public static bool $executeReturnsFalse = false;

    public static int $executeCalls = 0;

    public static ?int $rowCountThrowsOnCall = null;

    protected function __construct()
    {
    }

    public static function reset(): void
    {
        self::$row = false;
        self::$rowOnce = false;
        self::$fetchThrows = false;
        self::$closeThrows = false;
        self::$closeReturnsFalse = false;
        self::$closed = false;
        self::$closeCalls = 0;
        self::$rowCountCalls = 0;
        self::$bindReturnsFalse = false;
        self::$executeReturnsFalse = false;
        self::$executeCalls = 0;
        self::$rowCountThrowsOnCall = null;
    }

    public static function returns(mixed $row, bool $once = false): void
    {
        self::$row = $row;
        self::$rowOnce = $once;
    }

    public static function fetchThrows(): void
    {
        self::$fetchThrows = true;
    }

    public static function closeThrows(): void
    {
        self::$closeThrows = true;
    }

    public static function closeReturnsFalse(): void
    {
        self::$closeReturnsFalse = true;
    }

    #[\Override]
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        if (self::$fetchThrows) {
            throw new PDOException(self::DEFAULT_FETCH_FAILURE);
        }

        $row = self::$row;
        if (self::$rowOnce) {
            self::$row = false;
        }

        return $row;
    }

    #[\Override]
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return self::$bindReturnsFalse ? false : parent::bindValue($param, $value, $type);
    }

    /** @param array<array-key, mixed>|null $params */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        ++self::$executeCalls;

        return self::$executeReturnsFalse ? false : parent::execute($params);
    }

    #[\Override]
    public function rowCount(): int
    {
        ++self::$rowCountCalls;
        if (self::$rowCountThrowsOnCall === self::$rowCountCalls) {
            throw new PDOException('Controlled affected-row failure.');
        }

        return parent::rowCount();
    }

    #[\Override]
    public function closeCursor(): bool
    {
        self::$closed = true;
        ++self::$closeCalls;

        if (self::$closeThrows) {
            throw new PDOException(self::DEFAULT_CLOSE_FAILURE);
        }
        if (self::$closeReturnsFalse) {
            return false;
        }

        return parent::closeCursor();
    }
}
