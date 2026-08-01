<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use FilesystemIterator;
use Oeltima\SimpleQuery\Tools\Quality\DuplicationGate;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DuplicationGateTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }
        $this->roots = [];
    }

    public function testCleanTreePassesTheGate(): void
    {
        self::assertSame([], (new DuplicationGate())->check($this->cleanRoot()));
    }

    public function testMissingGoldenFixtureIsReported(): void
    {
        $root = $this->cleanRoot();
        unlink($root . '/tests/Fixtures/Compiler/mariadb.json');

        $this->assertGateReports('Missing or invalid golden fixture', $root);
    }

    public function testDuplicateGoldenCaseIdIsReported(): void
    {
        $root = $this->cleanRoot();
        $this->appendCase($root, 'mariadb', 'mariadb-select', 'SELECT * FROM `second` WHERE `id` = ?');

        $this->assertGateReports('Golden fixture case id "mariadb-select" is duplicated', $root);
    }

    public function testRepeatedGoldenSqlIsReported(): void
    {
        $root = $this->cleanRoot();
        $this->appendCase($root, 'mysql', 'mysql-copy', 'SELECT * FROM `mariadb` WHERE `id` = ?');

        $this->assertGateReports('Golden SQL is repeated', $root);
    }

    public function testDuplicateTestMethodNameIsReported(): void
    {
        $root = $this->cleanRoot();
        file_put_contents(
            $root . '/tests/Unit/OtherTest.php',
            '<?php class OtherTest { public function testWorks(): void {} }',
        );

        $this->assertGateReports('Test method "testWorks" is defined in more than one file', $root);
    }

    public function testReaddedAddHavingDispatchIsReported(): void
    {
        $root = $this->cleanRoot();
        file_put_contents(
            $root . '/src/QueryBuilder.php',
            '<?php class QueryBuilder { private function addHaving(): void {} }',
        );

        $this->assertGateReports('QueryBuilder::addHaving() was re-added', $root);
    }

    public function testExtraStatementDoubleIsReported(): void
    {
        $root = $this->cleanRoot();
        file_put_contents($root . '/tests/Unit/ThrowingStatement.php', '<?php class ThrowingStatement {}');

        $this->assertGateReports('Unexpected PDOStatement test doubles beyond', $root);
    }

    public function testInlineWriteGoldenAssertionsAreReported(): void
    {
        $root = $this->cleanRoot();
        file_put_contents(
            $root . '/tests/Compiler/SqliteCompilerTest.php',
            '<?php class SqliteCompilerTest { public function compile(): void { new CompiledWriteQuery(); } }',
        );

        $this->assertGateReports('re-adds inline write golden assertions', $root);
    }

    public function testFuncNumArgsGrowthIsReported(): void
    {
        $root = $this->cleanRoot();
        $body = '<?php class BuildsConditions { private function addCondition(): void {} }' . PHP_EOL;
        for ($i = 0; $i < 13; $i++) {
            $body .= '<?php func_num_args(); ?>' . PHP_EOL;
        }
        file_put_contents($root . '/src/Internal/BuildsConditions.php', $body);

        $this->assertGateReports('func_num_args() dispatch grew to 13 sites', $root);
    }

    public function testMagicStringGrowthIsReported(): void
    {
        $root = $this->cleanRoot();
        file_put_contents(
            $root . '/src/QueryBuilder.php',
            '<?php class QueryBuilder { private function x(): void { ' . str_repeat("'INNER'", 8) . '; } }',
        );

        $this->assertGateReports('QueryBuilder magic-string literal sites grew to 8', $root);
    }

    private function assertGateReports(string $expected, string $root): void
    {
        self::assertStringContainsString($expected, implode("\n", (new DuplicationGate())->check($root)));
    }

    private function cleanRoot(): string
    {
        $root = sys_get_temp_dir() . '/simplequery-duplication-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/tests/Fixtures/Compiler', 0777, true));
        self::assertTrue(mkdir($root . '/tests/Unit', 0777, true));
        self::assertTrue(mkdir($root . '/tests/Compiler', 0777, true));
        self::assertTrue(mkdir($root . '/src/Internal', 0777, true));
        $this->roots[] = $root;

        foreach (['mariadb', 'mysql', 'sqlite'] as $driver) {
            file_put_contents(
                $root . '/tests/Fixtures/Compiler/' . $driver . '.json',
                json_encode([
                    'schema_version' => 1,
                    'driver' => $driver,
                    'cases' => [[
                        'id' => $driver . '-select',
                        'sql' => 'SELECT * FROM `' . $driver . '` WHERE `id` = ?',
                        'bindings' => [1],
                    ]],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        }
        file_put_contents($root . '/tests/Compiler/MariaDbCompilerTest.php', '<?php class MariaDbCompilerTest {}');
        file_put_contents($root . '/tests/Compiler/MySqlCompilerTest.php', '<?php class MySqlCompilerTest {}');
        file_put_contents($root . '/tests/Compiler/SqliteCompilerTest.php', '<?php class SqliteCompilerTest {}');
        file_put_contents(
            $root . '/tests/Unit/DemoTest.php',
            '<?php class DemoTest { public function testWorks(): void {} }',
        );
        file_put_contents(
            $root . '/src/QueryBuilder.php',
            '<?php class QueryBuilder { public function where(): void {} }',
        );
        file_put_contents(
            $root . '/src/Internal/BuildsConditions.php',
            '<?php class BuildsConditions { private function addCondition(): void {} }',
        );

        return $root;
    }

    private function appendCase(string $root, string $driver, string $id, string $sql): void
    {
        $path = $root . '/tests/Fixtures/Compiler/' . $driver . '.json';
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        $cases = $fixture['cases'] ?? [];
        self::assertIsArray($cases);
        $cases[] = ['id' => $id, 'sql' => $sql, 'bindings' => []];
        $fixture['cases'] = $cases;
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
