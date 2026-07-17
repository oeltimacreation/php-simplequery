<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

return static function (): void {
    $db = CompilerConnection::for(Driver::Sqlite);
    $compiled = $db->table('widgets')->where('enabled', true)->compile();

    CompiledQueryAssertions::assertMatches(
        $compiled,
        'SELECT * FROM "widgets" WHERE "enabled" = ?',
        [1],
        [ParameterType::Integer],
    );
};
