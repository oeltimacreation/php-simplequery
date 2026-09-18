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
        $sqlState = self::sqlStateEntry($errorInfo);
        $driverCode = self::driverCodeEntry($errorInfo);
        $code = $failure->getCode();

        if ($sqlState === null && is_string($code) && $code !== '') {
            $sqlState = $code;
        }
        if ($driverCode === null && is_int($code) && $code !== 0) {
            $driverCode = $code;
        }

        return new self($sqlState, $driverCode);
    }

    /** @param array<mixed>|null $errorInfo */
    private static function sqlStateEntry(?array $errorInfo): ?string
    {
        $value = $errorInfo[0] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<mixed>|null $errorInfo */
    private static function driverCodeEntry(?array $errorInfo): int|string|null
    {
        $value = $errorInfo[1] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
