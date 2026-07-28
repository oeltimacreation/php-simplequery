<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compatibility;

use Oeltima\SimpleQuery\Tools\Quality\PublicApiManifest;
use PHPUnit\Framework\TestCase;

final class PublicApiManifestTest extends TestCase
{
    public function testReviewedManifestMatchesTheRuntimePublicSurface(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = file_get_contents($root . '/tests/Fixtures/Contracts/public-api.json');
        self::assertIsString($expected);

        self::assertSame($expected, (new PublicApiManifest())->encode($root));
    }

    public function testManifestMarksInternalSeamsAndExcludesInternalNamespace(): void
    {
        $types = (new PublicApiManifest())->build(dirname(__DIR__, 2))['types'];
        $encoded = json_encode($types, JSON_THROW_ON_ERROR);

        foreach ($types as $type) {
            self::assertIsString($type['name'] ?? null);
            self::assertStringStartsNotWith('Oeltima\\SimpleQuery\\Internal\\', $type['name']);
        }
        self::assertStringContainsString('snapshotForCompilation', $encoded);
        self::assertStringContainsString('template-covariant TRow', $encoded);

        $queryBuilder = $this->typeByName($types, 'Oeltima\\SimpleQuery\\QueryBuilder');
        $snapshot = $this->methodByName($queryBuilder, 'snapshotForCompilation');
        self::assertSame('internal', $snapshot['compatibility'] ?? null);
        self::assertSame('self', $this->methodByName($queryBuilder, 'select')['return_type'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $types
     * @return array<string, mixed>
     */
    private function typeByName(array $types, string $name): array
    {
        foreach ($types as $type) {
            if (($type['name'] ?? null) === $name) {
                return $type;
            }
        }

        self::fail(sprintf('Type %s was not found in the public API manifest.', $name));
    }

    /**
     * @param array<string, mixed> $type
     * @return array<string, mixed>
     */
    private function methodByName(array $type, string $name): array
    {
        $methods = $type['methods'] ?? null;
        self::assertIsArray($methods);
        foreach ($methods as $method) {
            self::assertIsArray($method);
            if (($method['name'] ?? null) === $name) {
                return $method;
            }
        }

        self::fail(sprintf('Method %s was not found in the public API manifest.', $name));
    }
}
