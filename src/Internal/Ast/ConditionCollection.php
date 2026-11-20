<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final class ConditionCollection
{
    /** @var list<ConditionTerm> */
    private array $terms = [];

    public function add(ConditionTerm $term): void
    {
        $this->terms[] = $term;
    }

    /** @return list<ConditionTerm> */
    public function terms(): array
    {
        return $this->terms;
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    public function copy(): self
    {
        // Only grouped terms contain mutable collections.
        $terms = $this->terms;
        foreach ($terms as $index => $term) {
            if ($term->predicate instanceof GroupPredicate) {
                $terms[$index] = new ConditionTerm(
                    new GroupPredicate($term->predicate->conditions->copy()),
                    $term->or,
                    $term->negated,
                );
            }
        }

        $copy = new self();
        $copy->terms = $terms;

        return $copy;
    }
}
