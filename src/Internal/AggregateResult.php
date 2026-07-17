<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Oeltima\SimpleQuery\Exception\NumericOverflowException;
use UnexpectedValueException;

/** @internal */
final class AggregateResult
{
    public static function count(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new UnexpectedValueException('Count returned a negative value.');
            }

            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new UnexpectedValueException('Count did not return a non-negative decimal integer.');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;
        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new NumericOverflowException('Count is greater than PHP_INT_MAX.');
        }

        return (int) $normalized;
    }
}
