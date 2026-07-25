<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

interface ScenarioFactory
{
    public function prepare(ScenarioRequest $request): ?PreparedScenario;
}
