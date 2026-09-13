<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RuntimeException;

final class CompilerFixtureValidator
{
    private const DRIVERS = ['mariadb', 'mysql', 'sqlite'];

    /** @var list<string> */
    private array $executableIds = [];

    /** @var list<string> */
    private array $rejectionIds = [];

    /** @var array<string, string> */
    private array $ids = [];

    /**
     * @param array<string, mixed> $fixtures
     * @param list<string> $executableIds
     * @param array<string, mixed> $manifest
     * @param list<string> $rejectionIds
     */
    public function validate(array $fixtures, array $executableIds, array $manifest, array $rejectionIds = []): void
    {
        $this->executableIds = $executableIds;
        $this->rejectionIds = $rejectionIds;
        $this->ids = $this->collectFixtureCases($fixtures);
        $this->assertExecutableCasesMatch();
        $this->assertEveryCaseReferenced($this->validateFeatureManifest($manifest));
    }

    /** @param array<string, mixed> $fixtures
     * @return array<string, string>
     */
    private function collectFixtureCases(array $fixtures): array
    {
        $ids = [];
        foreach (self::DRIVERS as $driver) {
            $cases = $this->dialectCases($driver, $fixtures[$driver] ?? null);
            $ids = array_merge($ids, $this->registerCases($cases, $driver));
        }

        return $ids;
    }

    /** @return list<mixed> */
    private function dialectCases(string $driver, mixed $fixture): array
    {
        if (!is_array($fixture)) {
            $this->rejectDialect($driver);
        }
        if (($fixture['schema_version'] ?? null) !== 1) {
            $this->rejectDialect($driver);
        }
        if (($fixture['driver'] ?? null) !== $driver) {
            $this->rejectDialect($driver);
        }
        $cases = $fixture['cases'] ?? null;
        if (!is_array($cases) || !array_is_list($cases)) {
            $this->rejectCases($driver);
        }
        if ($cases === []) {
            $this->rejectCases($driver);
        }

        return $cases;
    }

    private function rejectDialect(string $driver): never
    {
        throw new RuntimeException('Missing or malformed compiler dialect fixture: ' . $driver);
    }

    private function rejectCases(string $driver): never
    {
        throw new RuntimeException('Compiler cases must be a non-empty list: ' . $driver);
    }

    /** @param list<mixed> $cases
     * @return array<string, string>
     */
    private function registerCases(array $cases, string $driver): array
    {
        $registered = [];
        foreach ($cases as $case) {
            $id = $this->caseId($case, $driver);
            if (isset($registered[$id])) {
                $this->rejectExecutableCase($id);
            }
            if (!in_array($id, $this->executableIds, true)) {
                $this->rejectExecutableCase($id);
            }
            $registered[$id] = $driver;
        }

        return $registered;
    }

    private function rejectExecutableCase(string $id): never
    {
        throw new RuntimeException('Duplicate or unknown executable compiler case: ' . $id);
    }

    private function assertExecutableCasesMatch(): void
    {
        if (count($this->ids) !== count($this->executableIds)) {
            throw new RuntimeException('An executable compiler case has no golden fixture.');
        }
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, true>
     */
    private function validateFeatureManifest(array $manifest): array
    {
        $referenced = [];
        foreach ($this->manifestFeatures($manifest) as $feature => $dialects) {
            if (!is_string($feature) || $feature === '') {
                throw new RuntimeException('Malformed compiler feature.');
            }
            if (!is_array($dialects)) {
                throw new RuntimeException('Malformed compiler feature.');
            }
            $referenced += $this->featureReferences($feature, $dialects);
        }

        return $referenced;
    }

    /** @param array<string, mixed> $manifest
     * @return array<mixed>
     */
    private function manifestFeatures(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== 2) {
            throw new RuntimeException('Malformed compiler feature manifest.');
        }
        $features = $manifest['features'] ?? null;
        if (!is_array($features) || $features === []) {
            throw new RuntimeException('Malformed compiler feature manifest.');
        }

        return $features;
    }

    /** @param array<mixed> $dialects
     * @return array<string, true>
     */
    private function featureReferences(string $feature, array $dialects): array
    {
        $referenced = [];
        foreach (self::DRIVERS as $driver) {
            $references = $dialects[$driver] ?? null;
            if (!is_array($references) || !array_is_list($references)) {
                $this->rejectFeatureDialect($feature, $driver);
            }
            if ($references === []) {
                $this->rejectFeatureDialect($feature, $driver);
            }
            $this->addReferenced($referenced, $references, $driver);
        }

        return $referenced;
    }

    /** @param array<string, true> $referenced
     * @param list<mixed> $references
     */
    private function addReferenced(array &$referenced, array $references, string $driver): void
    {
        foreach ($references as $reference) {
            $referencedId = $this->referenceId($reference, $driver);
            if ($referencedId !== null) {
                $referenced[$referencedId] = true;
            }
        }
    }

    private function rejectFeatureDialect(string $feature, string $driver): never
    {
        throw new RuntimeException('Missing feature dialect: ' . $feature . '/' . $driver);
    }

    private function referenceId(mixed $reference, string $driver): ?string
    {
        if (!is_string($reference)) {
            throw new RuntimeException('Feature references must be strings.');
        }
        if (!str_starts_with($reference, 'unsupported:')) {
            return $this->executableReference($reference, $driver);
        }
        $rejection = substr($reference, strlen('unsupported:'));
        if (!str_starts_with($rejection, $driver . '-')) {
            $this->rejectReference($reference);
        }
        if (!in_array($rejection, $this->rejectionIds, true)) {
            $this->rejectReference($reference);
        }

        return null;
    }

    private function executableReference(string $reference, string $driver): string
    {
        if (($this->ids[$reference] ?? null) === $driver) {
            return $reference;
        }

        throw new RuntimeException('Unknown or wrong-dialect feature reference: ' . $reference);
    }

    private function rejectReference(string $reference): never
    {
        throw new RuntimeException('Unknown or wrong-dialect feature reference: ' . $reference);
    }

    /** @param array<string, true> $referenced */
    private function assertEveryCaseReferenced(array $referenced): void
    {
        foreach (array_keys($this->ids) as $id) {
            if (!isset($referenced[$id])) {
                throw new RuntimeException('Compiler case is not referenced by any feature: ' . $id);
            }
        }
    }

    private function caseId(mixed $case, string $driver): string
    {
        if (!is_array($case)) {
            $this->rejectCase();
        }
        $id = $case['id'] ?? null;
        $sql = $case['sql'] ?? null;
        if (!is_string($id) || !str_starts_with($id, $driver . '-')) {
            $this->rejectCase();
        }
        if (!is_string($sql) || trim($sql) === '') {
            $this->rejectCase();
        }
        $this->assertBindingLists($case);

        return $id;
    }

    private function rejectCase(): never
    {
        throw new RuntimeException('Malformed compiler case identity or SQL.');
    }

    /** @param array<mixed> $case */
    private function assertBindingLists(array $case): void
    {
        $values = $this->bindingList($case, 'bindings');
        $types = $this->bindingList($case, 'types');
        if (count($values) !== count($types)) {
            throw new RuntimeException('Compiler bindings and types must be matching ordered lists.');
        }
        $this->assertBindingTypes($types);
    }

    /** @param array<mixed> $case
     * @return list<mixed>
     */
    private function bindingList(array $case, string $key): array
    {
        $values = $case[$key] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('Compiler bindings and types must be matching ordered lists.');
        }

        return $values;
    }

    /** @param list<mixed> $types */
    private function assertBindingTypes(array $types): void
    {
        foreach ($types as $type) {
            if (!in_array($type, ['null', 'integer', 'string', 'binary', 'lob'], true)) {
                throw new RuntimeException('Unknown compiler binding type.');
            }
        }
    }
}
