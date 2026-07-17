<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\BuildsConditions;

final class ConditionGroup
{
    use BuildsConditions;

    private readonly ConditionCollection $conditions;

    /** @internal */
    public function __construct(private readonly Connection $connection)
    {
        $this->conditions = new ConditionCollection();
    }

    /** @internal */
    public function snapshot(): ConditionCollection
    {
        return $this->conditions->copy();
    }

    /** @internal */
    public function isEmpty(): bool
    {
        return $this->conditions->isEmpty();
    }

    #[\Override]
    protected function conditionConnection(): Connection
    {
        return $this->connection;
    }

    #[\Override]
    protected function conditionCollection(): ConditionCollection
    {
        return $this->conditions;
    }
}
