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

    public function containsStructuredExists(): bool
    {
        foreach ($this->terms as $term) {
            $predicate = $term->predicate;
            if (
                $predicate instanceof ExistsPredicate
                || ($predicate instanceof GroupPredicate && $predicate->conditions->containsStructuredExists())
                || ($predicate instanceof InPredicate && $predicate->values instanceof SubquerySnapshot
                    && $predicate->values->containsStructuredExists)
            ) {
                return true;
            }
        }

        return false;
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
