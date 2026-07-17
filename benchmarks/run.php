<?php

declare(strict_types=1);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "The PDO SQLite benchmark control requires pdo_sqlite.\n");
    exit(1);
}

$benchmarkQuery = static function (PDO $pdo, string $sql): PDOStatement {
    $statement = $pdo->query($sql);
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('The benchmark query did not return a statement.');
    }

    return $statement;
};

$iterations = 5;
$sizes = [10, 100, 1_000, 5_000];
$results = [];

foreach ($sizes as $size) {
    $samples = [];
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE benchmark_rows (id INTEGER PRIMARY KEY, value_text TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO benchmark_rows (id, value_text) VALUES (?, ?)');

        $started = hrtime(true);
        $pdo->beginTransaction();
        for ($row = 1; $row <= $size; ++$row) {
            $insert->execute([$row, 'value-' . $row]);
        }
        $pdo->commit();
        $rows = $benchmarkQuery($pdo, 'SELECT id, value_text FROM benchmark_rows ORDER BY id')->fetchAll();
        $elapsedNanoseconds = hrtime(true) - $started;

        if (count($rows) !== $size) {
            throw new RuntimeException('The benchmark correctness check failed.');
        }

        $samples[] = $elapsedNanoseconds / 1_000_000;
    }

    sort($samples);
    $results[] = [
        'size' => $size,
        'iterations' => $iterations,
        'median_ms' => round($samples[2], 3),
        'minimum_ms' => round($samples[0], 3),
        'maximum_ms' => round($samples[4], 3),
        'milliseconds_per_row_at_median' => round($samples[2] / $size, 6),
    ];
}

fwrite(
    STDOUT,
    json_encode(
        [
            'schema_version' => 1,
            'benchmark' => 'pdo_sqlite_harness_control',
            'purpose' => 'validate the harness and retain a PDO-only control for future SimpleQuery benchmarks',
            'php_version' => PHP_VERSION,
            'sqlite_version' => $benchmarkQuery(
                new PDO('sqlite::memory:'),
                'SELECT sqlite_version()',
            )->fetchColumn(),
            'results' => $results,
        ],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ) . PHP_EOL,
);
