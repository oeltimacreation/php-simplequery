<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Expression\RawExpression;
use stdClass;

use function PHPStan\Testing\assertType;

return static function (Connection $database): void {
    $normalizedEmail = $database->raw('LOWER(users.email)');
    $builder = $database
        ->table('users')
        ->where($normalizedEmail, '=', 'ada@example.test')
        ->orWhere($database->raw('COALESCE(users.email, ?)', ['']), 'grace@example.test')
        ->whereNot($database->raw('LOWER(users.status)'), '=', 'disabled')
        ->orWhereNot($database->raw('LOWER(users.status)'), 'archived')
        ->whereColumn('users.team_id', '=', 'teams.id')
        ->orWhereColumn(Identifier::of('users.owner_id'), '=', Identifier::of('users.id'))
        ->where(static function ($group) use ($database): void {
            assertType(ConditionGroup::class, $group);
            $group
                ->where('active', true)
                ->where($database->raw('LOWER(name)'), '=', 'ada')
                ->whereColumn('tenant_id', '=', 'owner_tenant_id');
        })
        ->having($database->raw('COUNT(*)'), '>', 1)
        ->orHaving(static function ($group) use ($database): void {
            assertType(ConditionGroup::class, $group);
            $group->where($database->raw('MAX(score)'), '>', 0);
        })
        ->join('teams', static function ($join) use ($database): void {
            assertType(JoinClause::class, $join);
            $join
                ->on($database->raw('teams.id + ?', [0]), '=', Identifier::of('users.team_id'))
                ->orOn('teams.owner_id', '=', $database->raw('users.owner_id + ?', [0]))
                ->onValue($database->raw('LENGTH(teams.name)'), '>', 2)
                ->orOnValue($database->raw('LENGTH(teams.code)'), '>', 1)
                ->where($database->raw('LOWER(teams.status)'), '=', 'active');
        })
        ->leftJoin('owners', $database->raw('owners.id + ?', [0]), '=', Identifier::of('users.owner_id'));

    assertType('Oeltima\\SimpleQuery\\QueryBuilder', $builder);

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

    $transactionResult = $database->transaction(
        static function ($transaction): string {
            assertType(Connection::class, $transaction);

            return $transaction->table('records')->insertGetId(['label' => 'synthetic']);
        },
    );
    assertType('string', $transactionResult);

    try {
        $database->query('SELECT * FROM missing_table')->get();
    } catch (QueryExecutionException $exception) {
        assertType('string|null', $exception->sqlState);
        assertType('int|string|null', $exception->driverCode);
        assertType(Driver::class, $exception->driver);
    }
};
