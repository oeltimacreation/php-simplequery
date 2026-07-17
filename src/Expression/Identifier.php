<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Expression;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;

final readonly class Identifier
{
    /**
     * @param non-empty-list<string> $segments
     */
    private function __construct(
        public array $segments,
        public bool $wildcard = false,
        public ?string $alias = null,
    ) {
        foreach ($this->segments as $segment) {
            if ($this->wildcard && $this->segments === ['*']) {
                continue;
            }
            self::validateSegment($segment);
        }

        if ($this->wildcard && $this->alias !== null) {
            throw new InvalidQueryException('A wildcard cannot have an alias.');
        }

        if ($this->alias !== null) {
            self::validateAlias($this->alias);
        }
    }

    public static function of(string $qualifiedName): self
    {
        if ($qualifiedName === '' || str_contains($qualifiedName, '*')) {
            throw new InvalidQueryException('Use Identifier::wildcard() for wildcard identifiers.');
        }

        /** @var non-empty-list<string> $segments */
        $segments = explode('.', $qualifiedName);

        return new self($segments);
    }

    public static function fromSegments(string $segment, string ...$segments): self
    {
        $allSegments = [$segment];
        foreach ($segments as $additionalSegment) {
            $allSegments[] = $additionalSegment;
        }

        return new self($allSegments);
    }

    public static function wildcard(?string $qualifier = null): self
    {
        if ($qualifier === null) {
            return new self(['*'], true);
        }

        if ($qualifier === '' || str_contains($qualifier, '*')) {
            throw new InvalidQueryException('Wildcard qualifier must be a non-empty qualified identifier.');
        }

        /** @var non-empty-list<string> $segments */
        $segments = explode('.', $qualifier);

        return new self($segments, true);
    }

    public function as(string $alias): self
    {
        return new self($this->segments, $this->wildcard, $alias);
    }

    public function withoutAlias(): self
    {
        return new self($this->segments, $this->wildcard);
    }

    private static function validateSegment(string $segment): void
    {
        if ($segment === '' || str_contains($segment, "\0")) {
            throw new InvalidQueryException('Identifier segments must be non-empty and contain no NUL bytes.');
        }

        if ($segment === '*') {
            throw new InvalidQueryException('Use Identifier::wildcard() for wildcard identifiers.');
        }
    }

    private static function validateAlias(string $alias): void
    {
        if ($alias === '' || str_contains($alias, "\0") || str_contains($alias, '.')) {
            throw new InvalidQueryException('An alias must be one non-empty identifier segment.');
        }
    }
}
