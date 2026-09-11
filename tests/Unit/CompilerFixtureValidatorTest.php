<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Tools\Quality\CompilerFixtureValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompilerFixtureValidatorTest extends TestCase
{
    #[DataProvider('invalidFixtures')]
    public function testBrokenFixtureRelationshipsAreRejected(string $fault): void
    {
        $fixtures = [];
        $features = [];
        $ids = [];
        foreach (['mariadb', 'mysql', 'sqlite'] as $driver) {
            $id = $driver . '-select';
            $ids[] = $id;
            $fixtures[$driver] = [
                'schema_version' => 1, 'driver' => $driver,
                'cases' => [['id' => $id, 'sql' => 'SELECT ?', 'bindings' => [1], 'types' => ['integer']]],
            ];
            $features[$driver] = [$id];
        }
        $manifest = ['schema_version' => 2, 'features' => ['select' => $features]];
        $validator = new CompilerFixtureValidator();
        $validator->validate($fixtures, $ids, $manifest);
        switch ($fault) {
            case 'missing types':
                unset($fixtures['sqlite']['cases'][0]['types']);
                break;
            case 'duplicate id':
                $fixtures['sqlite']['cases'][] = $fixtures['sqlite']['cases'][0];
                break;
            case 'unknown id':
                $fixtures['sqlite']['cases'][0]['id'] = 'sqlite-unknown';
                break;
            case 'missing dialect':
                unset($manifest['features']['select']['mysql']);
                break;
            case 'orphan feature':
                $manifest['features']['select']['sqlite'] = ['sqlite-orphan'];
                break;
            case 'wrong dialect':
                $manifest['features']['select']['sqlite'] = ['mysql-select'];
                break;
            case 'unknown rejection':
                $manifest['features']['select']['sqlite'] = ['unsupported:sqlite-unknown'];
                break;
            case 'malformed schema':
                $fixtures['sqlite']['schema_version'] = '1';
                break;
            case 'cardinality':
                $fixtures['sqlite']['cases'][0]['types'] = [];
                break;
            case 'unknown type':
                $fixtures['sqlite']['cases'][0]['types'] = ['automatic'];
                break;
            case 'keyed bindings':
                $fixtures['sqlite']['cases'][0]['bindings'] = ['named' => 1];
                break;
            case 'missing executable fixture':
                $ids[] = 'sqlite-unrepresented';
                break;
            default:
                throw new RuntimeException('Unknown test fault.');
        }
        $this->expectException(RuntimeException::class);
        $validator->validate($fixtures, $ids, $manifest);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFixtures(): iterable
    {
        foreach (
            [
            'missing types', 'duplicate id', 'unknown id', 'missing dialect', 'orphan feature',
            'wrong dialect', 'unknown rejection', 'malformed schema', 'cardinality', 'unknown type',
            'keyed bindings', 'missing executable fixture',
            ] as $fault
        ) {
            yield $fault => [$fault];
        }
    }
}
