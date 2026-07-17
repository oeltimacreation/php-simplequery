<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final class QueryState
{
    /** @var list<\Oeltima\SimpleQuery\Expression\Identifier|\Oeltima\SimpleQuery\Expression\RawExpression> */
    public array $projections = [];

    public bool $distinct = false;

    public ConditionCollection $where;

    /** @var list<JoinState> */
    public array $joins = [];

    /** @var list<\Oeltima\SimpleQuery\Expression\Identifier|\Oeltima\SimpleQuery\Expression\RawExpression> */
    public array $groups = [];

    public ConditionCollection $having;

    /** @var list<OrderClause> */
    public array $orders = [];

    public ?int $limit = null;

    public ?int $offset = null;

    public LockState $lock;

    public function __construct(public Source $source)
    {
        $this->where = new ConditionCollection();
        $this->having = new ConditionCollection();
        $this->lock = new LockState();
    }

    public function copy(): self
    {
        $copy = new self($this->source);
        $copy->projections = $this->projections;
        $copy->distinct = $this->distinct;
        $copy->where = $this->where->copy();
        $copy->joins = array_map(static fn (JoinState $join): JoinState => $join->copy(), $this->joins);
        $copy->groups = $this->groups;
        $copy->having = $this->having->copy();
        $copy->orders = $this->orders;
        $copy->limit = $this->limit;
        $copy->offset = $this->offset;
        $copy->lock = $this->lock->copy();

        return $copy;
    }
}
