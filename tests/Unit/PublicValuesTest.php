<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\SortDirection;
use Oeltima\SimpleQuery\Tests\Fixtures\TestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublicValuesTest extends TestCase
{
    public function testDriversDeclareTheirBroadPdoDriver(): void
    {
        self::assertSame('mysql', Driver::MariaDb->pdoDriver());
        self::assertSame('mysql', Driver::MySql->pdoDriver());
        self::assertSame('sqlite', Driver::Sqlite->pdoDriver());
    }

    public function testSortDirectionAcceptsOnlyTheClosedSet(): void
    {
        self::assertSame(SortDirection::Asc, SortDirection::fromString('asc'));
        self::assertSame(SortDirection::Desc, SortDirection::fromString('DESC'));

        $this->expectException(InvalidQueryException::class);
        SortDirection::fromString('sideways');
    }

    #[DataProvider('automaticBindings')]
    public function testAutomaticBindingsBecomeConcrete(
        mixed $input,
        mixed $expectedValue,
        ParameterType $expectedType,
    ): void {
        $binding = Binding::fromValue($input);

        self::assertSame($expectedValue, $binding->value);
        self::assertSame($expectedType, $binding->type);
    }

    /** @return iterable<string, array{mixed, mixed, ParameterType}> */
    public static function automaticBindings(): iterable
    {
        yield 'null' => [null, null, ParameterType::Null];
        yield 'true' => [true, 1, ParameterType::Integer];
        yield 'false' => [false, 0, ParameterType::Integer];
        yield 'integer' => [42, 42, ParameterType::Integer];
        yield 'float' => [1.25, '1.25', ParameterType::String];
        yield 'float preserving zero' => [1.0, '1.0', ParameterType::String];
        yield 'string' => ['42', '42', ParameterType::String];
        yield 'backed enum' => [TestStatus::Active, 'active', ParameterType::String];
    }

    public function testExplicitBindingsValidateTheirValueDomain(): void
    {
        self::assertSame(ParameterType::Binary, (new Binding("\x00", ParameterType::Binary))->type);

        $stream = fopen('php://memory', 'r');
        self::assertIsResource($stream);
        self::assertSame(ParameterType::Lob, (new Binding($stream, ParameterType::Lob))->type);
        fclose($stream);

        $this->expectException(InvalidQueryException::class);
        new Binding('1', ParameterType::Integer);
    }

    public function testUnsupportedAndNonFiniteAutomaticValuesAreRejected(): void
    {
        try {
            Binding::fromValue(new \stdClass());
            self::fail('Object binding should fail.');
        } catch (InvalidQueryException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidQueryException::class);
        Binding::fromValue(INF);
    }

    public function testIdentifiersAreExplicitImmutableValues(): void
    {
        $qualified = Identifier::of('schema.users');
        $aliased = $qualified->as('Users');

        self::assertSame(['schema', 'users'], $qualified->segments);
        self::assertNull($qualified->alias);
        self::assertSame('Users', $aliased->alias);
        self::assertSame(['column.with.dot'], Identifier::fromSegments('column.with.dot')->segments);
        self::assertTrue(Identifier::wildcard('users')->wildcard);
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidIdentifiersAreRejected(callable $factory): void
    {
        $this->expectException(InvalidQueryException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [static fn () => Identifier::of('')];
        yield 'empty segment' => [static fn () => Identifier::of('users..id')];
        yield 'nul' => [static fn () => Identifier::of("users.\0id")];
        yield 'wildcard through normal factory' => [static fn () => Identifier::of('users.*')];
        yield 'qualified alias' => [static fn () => Identifier::of('users')->as('schema.alias')];
        yield 'wildcard alias' => [static fn () => Identifier::wildcard()->as('all')];
    }

    public function testRawExpressionsAndCompiledQueriesRequireOrderedConcreteBindings(): void
    {
        $raw = new RawExpression('COALESCE(?, ?)', [null, TestStatus::Active]);
        self::assertSame([ParameterType::Null, ParameterType::String], array_map(
            static fn (Binding $binding): ParameterType => $binding->type,
            $raw->bindings,
        ));

        $compiled = new CompiledQuery('SELECT ?', [new Binding(1, ParameterType::Integer)]);
        self::assertSame('SELECT ?', $compiled->sql);

        try {
            (new ReflectionClass(RawExpression::class))->newInstanceArgs(['SELECT ?', ['named' => 1]]);
            self::fail('Associative raw bindings should fail.');
        } catch (InvalidQueryException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidQueryException::class);
        new CompiledQuery('SELECT ?', [new Binding(1)]);
    }

    public function testCompiledQueryRejectsNonBindingMembersAtTheRuntimeBoundary(): void
    {
        $this->expectException(InvalidQueryException::class);
        (new ReflectionClass(CompiledQuery::class))->newInstanceArgs(['SELECT ?', ['not-a-binding']]);
    }

    public function testCompiledQueryPreservesMemberValidationBeforeConcreteTypeValidation(): void
    {
        try {
            (new ReflectionClass(CompiledQuery::class))->newInstanceArgs([
                'SELECT ?, ?',
                [new Binding(1), 'not-a-binding'],
            ]);
            self::fail('A non-binding member should fail before an earlier automatic binding.');
        } catch (InvalidQueryException $exception) {
            self::assertSame('Every compiled binding must be a Binding.', $exception->getMessage());
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidQueryExecutions')]
    public function testQueryExecutionValidatesConsumerConstructedValues(array $arguments): void
    {
        $this->expectException(InvalidQueryException::class);
        (new ReflectionClass(QueryExecution::class))->newInstanceArgs($arguments);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function invalidQueryExecutions(): iterable
    {
        $valid = ['SELECT ?', [ParameterType::Integer], 0.1, true, null, Driver::Sqlite, null, 0];

        yield 'parameter types must be a list' => [[
            'SELECT ?', ['first' => ParameterType::Integer], 0.1, true, null, Driver::Sqlite, null, 0,
        ]];
        yield 'parameter type members are validated' => [[
            'SELECT ?', ['integer'], 0.1, true, null, Driver::Sqlite, null, 0,
        ]];
        yield 'SQL is not empty' => [[
            ' ', [ParameterType::Integer], 0.1, true, null, Driver::Sqlite, null, 0,
        ]];
        yield 'duration is non-negative' => [[
            'SELECT ?', [ParameterType::Integer], -0.1, true, null, Driver::Sqlite, null, 0,
        ]];
        yield 'duration is finite' => [[
            'SELECT ?', [ParameterType::Integer], INF, true, null, Driver::Sqlite, null, 0,
        ]];
        yield 'affected rows are non-negative' => [[
            'SELECT ?', [ParameterType::Integer], 0.1, true, -1, Driver::Sqlite, null, 0,
        ]];
        $valid[7] = -1;
        yield 'transaction depth is non-negative' => [$valid];
    }

    public function testConnectionOptionsValidateProfileAndDriverApplicability(): void
    {
        (new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 0, label: 'test'))->validateFor(Driver::Sqlite);
        self::addToAssertionCount(1);

        foreach (
            [
                static fn () => new ConnectionOptions(sqliteBusyTimeoutMilliseconds: -1),
                static fn () => new ConnectionOptions(label: ''),
                static fn () => new ConnectionOptions(label: "bad\nlabel"),
                static fn () => (new ConnectionOptions(bufferedQueries: true))->validateFor(Driver::Sqlite),
                static fn () => (new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 1))->validateFor(Driver::MySql),
                static fn () => (new ConnectionOptions(foundRows: true))->validateFor(Driver::MariaDb),
                static fn () => (new ConnectionOptions(persistent: true))->validateFor(Driver::MySql),
            ] as $invalid
        ) {
            try {
                $invalid();
                self::fail('Invalid connection option should fail.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
