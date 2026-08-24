<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\MeasurementRequest;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Harness.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$targetName = $arguments[1] ?? null;
if (!is_string($targetName) || !in_array($targetName, ['mariadb', 'mysql', 'proxysql', 'maxscale'], true)) {
    throw new RuntimeException('Engine benchmark target must be mariadb, mysql, proxysql, or maxscale.');
}
$target = ProbeTarget::named($targetName);
$driver = $target->engine === 'mysql' ? Driver::MySql : Driver::MariaDb;
$pdo = $target->connect();
$emulated = (bool) ($target->options[PDO::ATTR_EMULATE_PREPARES] ?? false);
$buffered = (bool) ($target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true);
$connection = Connection::fromPdo(
    $pdo,
    $driver,
    new ConnectionOptions(
        emulatePrepares: $emulated,
        bufferedQueries: $buffered,
        foundRows: false,
        persistent: false,
        label: 'benchmark-' . $targetName,
    ),
);
$table = 'simplequery_benchmark_rows';
$pdo->exec('DROP TABLE IF EXISTS ' . $table);
$pdo->exec(
    'CREATE TABLE ' . $table . ' ('
    . 'id INTEGER NOT NULL PRIMARY KEY, category VARCHAR(32) NOT NULL, payload VARCHAR(128) NOT NULL) ENGINE=InnoDB',
);
$insert = $pdo->prepare('INSERT INTO ' . $table . ' (id, category, payload) VALUES (?, ?, ?)');
$pdo->beginTransaction();
for ($id = 1; $id <= 2_000; ++$id) {
    $insert->execute([$id, 'category-' . ($id % 10), str_repeat((string) ($id % 10), 96)]);
}
$pdo->commit();

$summarize = static function (iterable $rows): array {
    $count = 0;
    $idSum = 0;
    $payloadBytes = 0;
    $firstId = null;
    $lastId = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('The engine benchmark returned a non-array row.');
        }
        $id = $row['id'] ?? null;
        $payload = $row['payload'] ?? null;
        if (!is_int($id) || !is_string($payload)) {
            throw new RuntimeException('The engine benchmark returned an invalid row shape.');
        }
        ++$count;
        $idSum += $id;
        $payloadBytes += strlen($payload);
        $firstId ??= $id;
        $lastId = $id;
    }

    return [
        'rows' => $count,
        'id_sum' => $idSum,
        'payload_bytes' => $payloadBytes,
        'first_id' => $firstId,
        'last_id' => $lastId,
    ];
};
$prepareDirect = static function () use ($pdo, $table): PDOStatement {
    $statement = $pdo->prepare(
        'SELECT id, category, payload FROM ' . $table . ' WHERE id >= ? ORDER BY id LIMIT 1000',
    );
    $statement->execute([501]);

    return $statement;
};
$directFull = static function () use ($prepareDirect, $summarize): array {
    $statement = $prepareDirect();
    $result = $summarize($statement->fetchAll(PDO::FETCH_ASSOC));
    $statement->closeCursor();

    return $result;
};
$simpleQueryFull = static function () use ($connection, $summarize, $table): array {
    return $summarize(
        $connection->table($table)->where('id', '>=', 501)->orderBy('id')->limit(1000)->getAssociative(),
    );
};
$directCursor = static function () use ($prepareDirect, $summarize): array {
    $statement = $prepareDirect();
    $rows = static function () use ($statement): iterable {
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    };
    $result = $summarize($rows());
    $statement->closeCursor();

    return $result;
};
$simpleQueryCursor = static function () use ($connection, $summarize, $table): array {
    return $summarize(
        $connection->table($table)->where('id', '>=', 501)->orderBy('id')->limit(1000)->iterateAssociative(),
    );
};

try {
    $environment = Harness::environment([
        'package_root' => dirname(__DIR__),
        'pdo' => $pdo,
        'target' => $targetName,
    ]);
    Harness::assertTimingInstrumentationDisabled($environment);
    $measurement = Harness::measure(MeasurementRequest::from(
        [
            'simplequery_full_result' => $simpleQueryFull,
            'pdo_full_result' => $directFull,
            'simplequery_streaming_cursor' => $simpleQueryCursor,
            'pdo_streaming_cursor' => $directCursor,
        ],
        ['warmups' => 2, 'iterations' => 7],
    ));
    $report = [
        'schema_version' => 2,
        'benchmark' => 'direct-proxy-result-memory-comparison',
        'collected_at' => gmdate(DATE_ATOM),
        'target' => $targetName,
        'engine' => $target->engine,
        'dimensions' => [
            'fixture_rows' => 2_000,
            'result_rows' => 1_000,
            'columns' => 3,
            'row_payload_bytes' => 96,
        ],
        'environment' => $environment,
        'correctness' => $measurement['correctness'],
        'measurement' => $measurement['measurement'],
        'memory' => Harness::memory(),
    ];
} finally {
    $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    $connection->close();
}

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
