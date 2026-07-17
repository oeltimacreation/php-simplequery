<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;

/** @internal */
final class InputNormalizer
{
    public static function identifier(string|Identifier $identifier): Identifier
    {
        return is_string($identifier) ? Identifier::of($identifier) : $identifier;
    }

    public static function projection(string|Identifier|RawExpression $expression): Identifier|RawExpression
    {
        if (!is_string($expression)) {
            return $expression;
        }

        if ($expression === '*') {
            return Identifier::wildcard();
        }

        if (str_ends_with($expression, '.*')) {
            return Identifier::wildcard(substr($expression, 0, -2));
        }

        return Identifier::of($expression);
    }

    public static function structuredExpression(
        string|Identifier|RawExpression $expression,
    ): Identifier|RawExpression {
        return is_string($expression) ? Identifier::of($expression) : $expression;
    }
}
