<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use PDO;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class ControlScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::PDO_CONTROL_10,
            ScenarioName::PDO_CONTROL_100,
            ScenarioName::PDO_CONTROL_1000,
            ScenarioName::PDO_CONTROL_5000 => $this->pdoControl($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function pdoControl(ScenarioRequest $request): array
    {
        $rows = $request->name->dimension() ?? throw new \LogicException('Missing row dimension.');
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE benchmark_rows (id INTEGER PRIMARY KEY, value_text TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO benchmark_rows (id, value_text) VALUES (?, ?)');
        $generation = 0;

        $operation = static function () use ($pdo, $insert, $rows, &$generation): array {
            ++$generation;
            $base = $generation * $rows;
            $pdo->beginTransaction();
            for ($row = 1; $row <= $rows; ++$row) {
                $insert->execute([$base + $row, 'value-' . $row]);
            }
            $pdo->commit();
            $statement = $pdo->prepare(
                'SELECT id, value_text FROM benchmark_rows WHERE id > ? AND id <= ? ORDER BY id',
            );
            $statement->execute([$base, $base + $rows]);
            $result = $statement->fetchAll();

            return [
                'row_count' => count($result),
                'first_value' => $result[0]['value_text'] ?? null,
                'last_value' => $result[$rows - 1]['value_text'] ?? null,
            ];
        };

        return ['operations' => ['pdo' => $operation], 'pdo' => $pdo, 'dimensions' => ['rows' => $rows]];
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
