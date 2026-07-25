<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Expression\RawExpression;
use stdClass;

use function PHPStan\Testing\assertType;

return static function (Connection $database): void {
    $builder = $database
        ->table('users')
        ->where(static function ($group): void {
            assertType(ConditionGroup::class, $group);
            $group->where('active', true);
        })
        ->having(static function ($group): void {
            assertType(ConditionGroup::class, $group);
            $group->where('score', '>', 0);
        })
        ->join('teams', static function ($join): void {
            assertType(JoinClause::class, $join);
            $join->on('teams.id', '=', 'users.team_id');
        });

    assertType(Cursor::class . '<' . stdClass::class . '>', $builder->iterate());
    assertType('Traversable<int, stdClass>', $builder->iterate()->getIterator());
    assertType(Cursor::class . '<array<string, mixed>>', $builder->iterateAssociative());
    assertType('Traversable<int, array<string, mixed>>', $builder->iterateAssociative()->getIterator());

    assertType(Cursor::class . '<' . stdClass::class . '>', $database->query('SELECT 1')->iterate());
    assertType(
        Cursor::class . '<array<string, mixed>>',
        $database->query('SELECT 1 AS value')->iterateAssociative(),
    );

    $bindings = [1, 'active'];
    $database->query('SELECT ? WHERE ? = ?', $bindings);
    new RawExpression('COALESCE(?, ?)', $bindings);
    new CompiledQuery('SELECT ?', [new Binding(1, ParameterType::Integer)]);
    new QueryExecution('SELECT ?', [ParameterType::Integer], 0.1, true, null, Driver::Sqlite, null, 0);
};
