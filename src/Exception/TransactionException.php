<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Exception;

use Oeltima\SimpleQuery\Driver;
use Throwable;

class TransactionException extends SimpleQueryException
{
    public function __construct(
        string $message,
        public readonly string $operation = 'transaction',
        public readonly int $managedDepth = 0,
        public readonly ?Driver $driver = null,
        public readonly ?string $connectionLabel = null,
        public readonly ?Throwable $callbackFailure = null,
        public readonly ?Throwable $controlFailure = null,
        public readonly ?Throwable $recoveryFailure = null,
        public readonly bool $connectionUnusable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            0,
            $previous ?? $recoveryFailure ?? $controlFailure ?? $callbackFailure,
        );
    }
}
