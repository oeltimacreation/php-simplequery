<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RuntimeException;

final class CompilerFixtureValidator
{
    private const DRIVERS = ['mariadb', 'mysql', 'sqlite'];

    /**
     * @param array<string, mixed> $fixtures
     * @param list<string> $executableIds
     * @param array<string, mixed> $manifest
     * @param list<string> $rejectionIds
     */
    public function validate(array $fixtures, array $executableIds, array $manifest, array $rejectionIds = []): void
    {
        $ids = $this->collectFixtureCases($fixtures, $executableIds);
        $this->assertExecutableCasesMatch($ids, $executableIds);
        $referenced = $this->validateFeatureManifest($manifest, $ids, $rejectionIds);
        $this->assertEveryCaseReferenced($ids, $referenced);
    }

    /**
     * @param array<string, mixed> $fixtures
     * @param list<string> $executableIds
     * @return array<string, string>
     */
    private function collectFixtureCases(array $fixtures, array $executableIds): array
    {
        $ids = [];
        foreach (self::DRIVERS as $driver) {
            $cases = $this->dialectCases($driver, $fixtures[$driver] ?? null);
            $ids = array_merge($ids, $this->registerCases($cases, $driver, $executableIds));
        }

        return $ids;
    }

    /** @return list<mixed> */
    private function dialectCases(string $driver, mixed $fixture): array
    {
        if (
            !is_array($fixture) || ($fixture['schema_version'] ?? null) !== 1
            || ($fixture['driver'] ?? null) !== $driver
        ) {
            throw new RuntimeException('Missing or malformed compiler dialect fixture: ' . $driver);
        }
        $cases = $fixture['cases'] ?? null;
        if (!is_array($cases) || !array_is_list($cases) || $cases === []) {
            throw new RuntimeException('Compiler cases must be a non-empty list: ' . $driver);
        }

        return $cases;
    }

    /**
     * @param list<mixed> $cases
     * @param list<string> $executableIds
     * @return array<string, string>
     */
    private function registerCases(array $cases, string $driver, array $executableIds): array
    {
        $registered = [];
        foreach ($cases as $case) {
            $id = $this->caseId($case, $driver);
            if (isset($registered[$id]) || !in_array($id, $executableIds, true)) {
                throw new RuntimeException('Duplicate or unknown executable compiler case: ' . $id);
            }
            $registered[$id] = $driver;
        }

        return $registered;
    }

    /** @param array<string, string> $ids
     * @param list<string> $executableIds
     */
    private function assertExecutableCasesMatch(array $ids, array $executableIds): void
    {
        if (count($ids) !== count($executableIds)) {
            throw new RuntimeException('An executable compiler case has no golden fixture.');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, string> $ids
     * @param list<string> $rejectionIds
     * @return array<string, true>
     */
    private function validateFeatureManifest(array $manifest, array $ids, array $rejectionIds): array
    {
        $features = $manifest['features'] ?? null;
        if (($manifest['schema_version'] ?? null) !== 2 || !is_array($features) || $features === []) {
            throw new RuntimeException('Malformed compiler feature manifest.');
        }
        $referenced = [];
        foreach ($features as $feature => $dialects) {
            if (!is_string($feature) || $feature === '' || !is_array($dialects)) {
                throw new RuntimeException('Malformed compiler feature.');
            }
            $referenced += $this->featureReferences($feature, $dialects, $ids, $rejectionIds);
        }

        return $referenced;
    }

    /**
     * @param array<mixed> $dialects
     * @param array<string, string> $ids
     * @param list<string> $rejectionIds
     * @return array<string, true>
     */
    private function featureReferences(
        string $feature,
        array $dialects,
        array $ids,
        array $rejectionIds,
    ): array {
        $referenced = [];
        foreach (self::DRIVERS as $driver) {
            $referenced += $this->dialectReferences($feature, $driver, $dialects[$driver] ?? null, $ids, $rejectionIds);
        }

        return $referenced;
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $rejectionIds
     * @return array<string, true>
     */
    private function dialectReferences(
        string $feature,
        string $driver,
        mixed $references,
        array $ids,
        array $rejectionIds,
    ): array {
        if (!is_array($references) || !array_is_list($references) || $references === []) {
            throw new RuntimeException('Missing feature dialect: ' . $feature . '/' . $driver);
        }
        $referenced = [];
        foreach ($references as $reference) {
            $referencedId = $this->referenceId($reference, $driver, $ids, $rejectionIds);
            if ($referencedId !== null) {
                $referenced[$referencedId] = true;
            }
        }

        return $referenced;
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $rejectionIds
     */
    private function referenceId(
        mixed $reference,
        string $driver,
        array $ids,
        array $rejectionIds,
    ): ?string {
        if (!is_string($reference)) {
            throw new RuntimeException('Feature references must be strings.');
        }
        if (!str_starts_with($reference, 'unsupported:')) {
            if (($ids[$reference] ?? null) === $driver) {
                return $reference;
            }

            throw new RuntimeException('Unknown or wrong-dialect feature reference: ' . $reference);
        }
        $rejection = substr($reference, strlen('unsupported:'));
        if (str_starts_with($rejection, $driver . '-') && in_array($rejection, $rejectionIds, true)) {
            return null;
        }

        throw new RuntimeException('Unknown or wrong-dialect feature reference: ' . $reference);
    }

    /** @param array<string, string> $ids
     * @param array<string, true> $referenced
     */
    private function assertEveryCaseReferenced(array $ids, array $referenced): void
    {
        foreach (array_keys($ids) as $id) {
            if (!isset($referenced[$id])) {
                throw new RuntimeException('Compiler case is not referenced by any feature: ' . $id);
            }
        }
    }

    private function caseId(mixed $case, string $driver): string
    {
        if (!is_array($case)) {
            throw new RuntimeException('Malformed compiler case identity or SQL.');
        }
        $id = $case['id'] ?? null;
        $sql = $case['sql'] ?? null;
        if (!is_string($id) || !str_starts_with($id, $driver . '-')) {
            throw new RuntimeException('Malformed compiler case identity or SQL.');
        }
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Malformed compiler case identity or SQL.');
        }
        $this->assertBindingLists($case);

        return $id;
    }

    /** @param array<mixed> $case */
    private function assertBindingLists(array $case): void
    {
        $values = $case['bindings'] ?? null;
        $types = $case['types'] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('Compiler bindings and types must be matching ordered lists.');
        }
        if (!is_array($types) || !array_is_list($types) || count($values) !== count($types)) {
            throw new RuntimeException('Compiler bindings and types must be matching ordered lists.');
        }
        foreach ($types as $type) {
            if (!in_array($type, ['null', 'integer', 'string', 'binary', 'lob'], true)) {
                throw new RuntimeException('Unknown compiler binding type.');
            }
        }
    }
}
