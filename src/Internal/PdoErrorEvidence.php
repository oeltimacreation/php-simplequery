<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use PDOException;

/**
 * Extracts the driver evidence PDO exposes on a failure without assigning
 * portable semantics. Missing, empty, or malformed `errorInfo` entries fall
 * back to an integer driver code or the SQLSTATE string reported by the
 * exception, and never invent a value that the driver did not report.
 *
 * @internal
 */
final readonly class PdoErrorEvidence
{
    private function __construct(
        public ?string $sqlState,
        public int|string|null $driverCode,
    ) {
    }

    public static function from(PDOException $failure): self
    {
        $errorInfo = $failure->errorInfo;
        $code = $failure->getCode();

        return new self(
            self::sqlState($errorInfo, $code),
            self::driverCode($errorInfo, $code),
        );
    }

    /** @param array<mixed>|null $errorInfo */
    private static function sqlState(?array $errorInfo, int|string $code): ?string
    {
        $value = $errorInfo[0] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** @param array<mixed>|null $errorInfo */
    private static function driverCode(?array $errorInfo, int|string $code): int|string|null
    {
        $value = $errorInfo[1] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_int($code) && $code !== 0 ? $code : null;
    }
}
