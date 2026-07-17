<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = CompilerConnection::for(Driver::MySql);
$activeRoles = $db->table('roles')->select('user_id')->where('active', true);
$query = $db
    ->table('users', 'u')
    ->select('u.id', 'u.email', $db->raw('LOWER(u.email) AS normalized_email'))
    ->distinct()
    ->leftJoin('profiles', static function (JoinClause $join): void {
        $join->on('profiles.user_id', '=', 'u.id')->onValue('profiles.visible', '=', true);
    })
    ->where(static function (ConditionGroup $group): void {
        $group->where('u.status', 'active')->orWhereNull('u.deleted_at');
    })
    ->whereIn('u.id', $activeRoles)
    ->whereBetween('u.age', 18, 65)
    ->whereNotIn('u.kind', [])
    ->groupBy('u.id', 'u.email')
    ->having('u.id', '>', 0)
    ->orderBy('u.id', 'DESC')
    ->limit(20)
    ->offset(0);

$firstCompilation = $query->compile();
$clone = clone $query;
$clone->whereNotNull('u.email');
if (
    $query->compile()->sql !== $firstCompilation->sql
    || $clone->compile()->sql === $firstCompilation->sql
) {
    throw new RuntimeException('Compilation determinism or clone isolation failed.');
}

$lock = $db->table('jobs')->where('ready', true)->forUpdate()->skipLocked()->compile();
CompiledQueryAssertions::assertMatches(
    $lock,
    'SELECT * FROM `jobs` WHERE `ready` = ? FOR UPDATE SKIP LOCKED',
    [1],
);

$insert = CompiledWriteQuery::insertMany($db->table('users'), [
    ['email' => 'first@example.test', 'active' => true],
    ['email' => 'second@example.test', 'active' => false],
]);
CompiledQueryAssertions::assertMatches(
    $insert,
    'INSERT INTO `users` (`email`, `active`) VALUES (?, ?), (?, ?)',
    ['first@example.test', 1, 'second@example.test', 0],
);

CompiledWriteQuery::update($db->table('users')->where('id', 1), ['active' => false]);
CompiledWriteQuery::delete($db->table('users')->where('id', 2));

fwrite(STDOUT, "Query-building example passed.\n");
