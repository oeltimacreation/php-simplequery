<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "The migration benchmark requires pdo_sqlite.\n");
    exit(1);
}

$connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$connection->query('CREATE TABLE benchmark_teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)')->execute();
$connection->query(
    'CREATE TABLE benchmark_members ('
    . 'id INTEGER PRIMARY KEY, team_id INTEGER NOT NULL, name TEXT NOT NULL, active INTEGER NOT NULL, score INTEGER)',
)->execute();
$connection->table('benchmark_teams')->insertMany([
    ['id' => 1, 'name' => 'Alpha'],
    ['id' => 2, 'name' => 'Beta'],
    ['id' => 3, 'name' => 'Gamma'],
]);
$members = [];
for ($id = 1; $id <= 500; ++$id) {
    $members[] = [
        'id' => $id,
        'team_id' => (($id - 1) % 3) + 1,
        'name' => 'Member ' . $id,
        'active' => $id % 4 !== 0,
        'score' => $id % 100,
    ];
}
$connection->table('benchmark_members')->insertMany($members);

$nativeQuery = static function () use ($connection): array {
    return $connection
        ->table('benchmark_members', 'm')
        ->select(
            'm.id',
            Identifier::of('m.name')->as('member_name'),
            Identifier::of('t.name')->as('team_name'),
            'm.score',
        )
        ->join(Identifier::of('benchmark_teams')->as('t'), 't.id', '=', 'm.team_id')
        ->where('m.active', true)
        ->where('m.score', '>=', 50)
        ->orderBy('m.id')
        ->limit(100)
        ->getAssociative();
};

$pdoQuery = static function () use ($connection): array {
    $statement = $connection->pdo()->prepare(
        'SELECT m.id, m.name AS member_name, t.name AS team_name, m.score '
        . 'FROM benchmark_members m INNER JOIN benchmark_teams t ON t.id = m.team_id '
        . 'WHERE m.active = ? AND m.score >= ? ORDER BY m.id LIMIT 100',
    );
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('The direct PDO migration benchmark query could not be prepared.');
    }
    $statement->execute([1, 50]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

$nativeRows = $nativeQuery();
$pdoRows = $pdoQuery();
if ($nativeRows !== $pdoRows || count($nativeRows) !== 100) {
    throw new RuntimeException('The migration benchmark parity check failed.');
}

/**
 * @param Closure(): array<mixed> $operation
 * @return list<float>
 */
$measure = static function (Closure $operation, int $iterations): array {
    $samples = [];
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $started = hrtime(true);
        $operation();
        $samples[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($samples);

    return $samples;
};

$iterations = 9;
$nativeQuery();
$pdoQuery();
$nativeSamples = $measure($nativeQuery, $iterations);
$pdoSamples = $measure($pdoQuery, $iterations);
$middle = intdiv($iterations, 2);
$nativeMedian = $nativeSamples[$middle];
$pdoMedian = $pdoSamples[$middle];
$ratio = $pdoMedian > 0.0 ? $nativeMedian / $pdoMedian : null;
$sqliteVersion = $connection->query('SELECT sqlite_version() AS version')->firstAssociative()['version'] ?? null;
$connection->close();

fwrite(
    STDOUT,
    json_encode(
        [
            'schema_version' => 1,
            'benchmark' => 'representative_migration_list_query',
            'purpose' => 'compare native SimpleQuery migration shape with direct PDO while asserting result parity',
            'php_version' => PHP_VERSION,
            'sqlite_version' => $sqliteVersion,
            'fixture_rows' => 500,
            'result_rows' => count($nativeRows),
            'iterations' => $iterations,
            'result_parity' => true,
            'result_digest' => hash('sha256', serialize($nativeRows)),
            'simplequery_median_ms' => round($nativeMedian, 4),
            'pdo_median_ms' => round($pdoMedian, 4),
            'simplequery_to_pdo_ratio' => $ratio === null ? null : round($ratio, 3),
            'policy' => 'recorded baseline; correctness is gated, timing ratio is reviewed rather than hard-failed',
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL,
);
