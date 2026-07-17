<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Observability;

interface QueryObserver
{
    public function queryExecuted(QueryExecution $execution): void;
}
