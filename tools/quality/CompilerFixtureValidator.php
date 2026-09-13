<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RuntimeException;

final class CompilerFixtureValidator
{
    /**
     * @param array<string, mixed> $fixtures
     * @param list<string> $executableIds
     * @param array<string, mixed> $manifest
     * @param list<string> $rejectionIds
     */
    public function validate(array $fixtures, array $executableIds, array $manifest, array $rejectionIds = []): void
    {
        $ids = [];
        foreach (['mariadb', 'mysql', 'sqlite'] as $driver) {
            $fixture = $fixtures[$driver] ?? null;
            if (
                !is_array($fixture) || ($fixture['schema_version'] ?? null) !== 1
                || ($fixture['driver'] ?? null) !== $driver || !is_array($fixture['cases'] ?? null)
            ) {
                throw new RuntimeException('Missing or malformed compiler dialect fixture: ' . $driver);
            }
            if (!array_is_list($fixture['cases']) || $fixture['cases'] === []) {
                throw new RuntimeException('Compiler cases must be a non-empty list: ' . $driver);
            }
            foreach ($fixture['cases'] as $case) {
                $id = $this->caseId($case, $driver);
                if (isset($ids[$id]) || !in_array($id, $executableIds, true)) {
                    throw new RuntimeException('Duplicate or unknown executable compiler case: ' . $id);
                }
                $ids[$id] = $driver;
            }
        }
        if (count($ids) !== count($executableIds)) {
            throw new RuntimeException('An executable compiler case has no golden fixture.');
        }
        $features = $manifest['features'] ?? null;
        if (($manifest['schema_version'] ?? null) !== 2 || !is_array($features) || $features === []) {
            throw new RuntimeException('Malformed compiler feature manifest.');
        }
        $referenced = [];
        foreach ($features as $feature => $dialects) {
            if (!is_string($feature) || $feature === '' || !is_array($dialects)) {
                throw new RuntimeException('Malformed compiler feature.');
            }
            foreach (['mariadb', 'mysql', 'sqlite'] as $driver) {
                $references = $dialects[$driver] ?? null;
                if (!is_array($references) || !array_is_list($references) || $references === []) {
                    throw new RuntimeException('Missing feature dialect: ' . $feature . '/' . $driver);
                }
                foreach ($references as $reference) {
                    if (!is_string($reference)) {
                        throw new RuntimeException('Feature references must be strings.');
                    }
                    if (str_starts_with($reference, 'unsupported:')) {
                        $rejection = substr($reference, strlen('unsupported:'));
                        if (str_starts_with($rejection, $driver . '-') && in_array($rejection, $rejectionIds, true)) {
                            continue;
                        }
                    } elseif (($ids[$reference] ?? null) === $driver) {
                        $referenced[$reference] = true;
                        continue;
                    }
                    throw new RuntimeException('Unknown or wrong-dialect feature reference: ' . $reference);
                }
            }
        }
        foreach (array_keys($ids) as $id) {
            if (!isset($referenced[$id])) {
                throw new RuntimeException('Compiler case is not referenced by any feature: ' . $id);
            }
        }
    }

    private function caseId(mixed $case, string $driver): string
    {
        if (
            !is_array($case) || !is_string($case['id'] ?? null) || !str_starts_with($case['id'], $driver . '-')
            || !is_string($case['sql'] ?? null) || trim($case['sql']) === ''
        ) {
            throw new RuntimeException('Malformed compiler case identity or SQL.');
        }
        $values = $case['bindings'] ?? null;
        $types = $case['types'] ?? null;
        if (
            !is_array($values) || !array_is_list($values) || !is_array($types) || !array_is_list($types)
            || count($values) !== count($types)
        ) {
            throw new RuntimeException('Compiler bindings and types must be matching ordered lists.');
        }
        foreach ($types as $type) {
            if (!in_array($type, ['null', 'integer', 'string', 'binary', 'lob'], true)) {
                throw new RuntimeException('Unknown compiler binding type.');
            }
        }

        return $case['id'];
    }
}
