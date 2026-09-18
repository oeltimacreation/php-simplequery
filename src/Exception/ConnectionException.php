<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Exception;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Internal\PdoErrorEvidence;
use PDOException;
use Throwable;

final class ConnectionException extends SimpleQueryException
{
    public function __construct(
        string $message,
        public readonly string $operation = 'connection',
        public readonly ?string $sqlState = null,
        public readonly int|string|null $driverCode = null,
        public readonly ?Driver $driver = null,
        public readonly ?string $connectionLabel = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Reports a failed connection attempt with normalized driver evidence. The
     * raw PDO exception, driver message, and trace are deliberately not
     * retained, so DSNs, credentials, and host/path details cannot leak
     * through a chained exception.
     */
    public static function fromConstructionFailure(
        PDOException $failure,
        Driver $driver,
        ?string $connectionLabel = null,
    ): self {
        $evidence = PdoErrorEvidence::from($failure);

        return new self(
            'Could not establish the database connection.',
            operation: 'connect',
            sqlState: $evidence->sqlState,
            driverCode: $evidence->driverCode,
            driver: $driver,
            connectionLabel: $connectionLabel,
        );
    }

    public static function closed(Driver $driver, ?string $connectionLabel = null): self
    {
        return new self(
            'The database connection is closed.',
            operation: 'closed',
            driver: $driver,
            connectionLabel: $connectionLabel,
        );
    }

    public static function compilerOnly(Driver $driver, ?string $connectionLabel = null): self
    {
        return new self(
            'A compiler-only connection has no PDO instance.',
            operation: 'compiler_only',
            driver: $driver,
            connectionLabel: $connectionLabel,
        );
    }
}
