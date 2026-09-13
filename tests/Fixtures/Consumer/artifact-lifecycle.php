<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

require __DIR__ . '/vendor/autoload.php';

$check = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Installed consumer lifecycle failed.');
    }
};
foreach (Driver::cases() as $driver) {
    $quote = $driver === Driver::Sqlite ? '"' : '`';
    CompiledQueryAssertions::assertMatches(
        CompilerConnection::for($driver)->table('items')->where('id', 1)->compile(),
        'SELECT * FROM ' . $quote . 'items' . $quote . ' WHERE ' . $quote . 'id' . $quote . ' = ?',
        [1],
    );
}
$check(!class_exists('Oeltima\\SimpleQuery\\Tests\\Unit\\ConfigurableStatement'));
$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)')->execute();
$check($db->table('items')->insertGetId(['value' => 'first']) === '1');
$check($db->table('items')->insert(['value' => 'second']) === 1);
$check(($db->table('items')->firstAssociative()['value'] ?? null) === 'first');
$cursor = $db->table('items')->orderBy('id')->iterateAssociative();
foreach ($cursor as $row) {
    $check($row['value'] === 'first');
    break;
}
$cursor->close();
$check($cursor->isClosed());
$db->transaction(static function (Connection $tx): void {
    $tx->transaction(static fn (Connection $nested): int => $nested->table('items')->where('id', 1)
        ->update(['value' => 'updated']));
});
$original = new RuntimeException('Synthetic rollback.');
try {
    $db->transaction(static function (Connection $tx) use ($original): void {
        $tx->table('items')->delete();
        throw $original;
    });
} catch (RuntimeException $failure) {
    $check($failure === $original);
}
$check($db->table('items')->count() === 2);
try {
    $db->table('missing_table')->where('value', 'synthetic-private')->get();
    throw new RuntimeException('Expected database failure.');
} catch (QueryExecutionException $failure) {
    $check($failure->getPrevious() instanceof PDOException);
    $check(!str_contains($failure->getMessage(), 'synthetic-private'));
}
$db->close();
echo "Installed no-dev consumer passed.\n";
