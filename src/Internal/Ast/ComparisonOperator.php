<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;

/** @internal */
enum ComparisonOperator: string
{
    case Equal = '=';
    case NotEqual = '!=';
    case AlternateNotEqual = '<>';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case Like = 'LIKE';
    case NotLike = 'NOT LIKE';

    public static function normalize(string $operator): self
    {
        return match (strtoupper(trim($operator))) {
            '=' => self::Equal,
            '!=' => self::NotEqual,
            '<>' => self::AlternateNotEqual,
            '<' => self::LessThan,
            '<=' => self::LessThanOrEqual,
            '>' => self::GreaterThan,
            '>=' => self::GreaterThanOrEqual,
            'LIKE' => self::Like,
            'NOT LIKE' => self::NotLike,
            default => throw new InvalidQueryException(sprintf('Unsupported comparison operator: %s.', $operator)),
        };
    }

    public function supportsNull(): bool
    {
        return $this === self::Equal || $this === self::NotEqual || $this === self::AlternateNotEqual;
    }

    public function isEquality(): bool
    {
        return $this === self::Equal;
    }
}
