<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Exception;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Internal\PdoErrorEvidence;
use PDOException;

final class QueryExecutionException extends SimpleQueryException
{
    public function __construct(
        string $message,
        public readonly ?string $sqlState,
        public readonly int|string|null $driverCode,
        public readonly string $sql,
        public readonly Driver $driver,
        public readonly ?string $connectionLabel,
        ?PDOException $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromPdo(
        PDOException $exception,
        string $sql,
        Driver $driver,
        ?string $connectionLabel,
    ): self {
        $evidence = PdoErrorEvidence::from($exception);

        return new self(
            self::safeMessage($evidence->sqlState),
            $evidence->sqlState,
            $evidence->driverCode,
            $sql,
            $driver,
            $connectionLabel,
            $exception,
        );
    }

    public static function invalidResult(
        string $message,
        string $sql,
        Driver $driver,
        ?string $connectionLabel,
    ): self {
        return new self($message, null, null, $sql, $driver, $connectionLabel);
    }

    private static function safeMessage(?string $sqlState): string
    {
        return $sqlState === null
            ? 'Database statement execution failed.'
            : sprintf('Database statement execution failed (SQLSTATE %s).', $sqlState);
    }
}
