<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

enum SortDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';

    public static function fromString(string $direction): self
    {
        return match (strtoupper($direction)) {
            'ASC' => self::Asc,
            'DESC' => self::Desc,
            default => throw new Exception\InvalidQueryException(
                sprintf('Unsupported sort direction: %s.', $direction),
            ),
        };
    }
}
