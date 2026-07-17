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
        $copy = new self();
        foreach ($this->terms as $term) {
            $predicate = $term->predicate;
            if ($predicate instanceof GroupPredicate) {
                $predicate = new GroupPredicate($predicate->conditions->copy());
            }
            $copy->add(new ConditionTerm($predicate, $term->or, $term->negated));
        }

        return $copy;
    }
}
