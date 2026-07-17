<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RepositoryFoundationTest extends TestCase
{
    public function testComposerDeclaresRuntimeAndAutoloadContract(): void
    {
        $composer = $this->readJson('composer.json');
        $requirements = $composer['require'] ?? null;
        $autoload = $composer['autoload'] ?? null;
        self::assertIsArray($requirements);
        self::assertIsArray($autoload);
        $psr4 = $autoload['psr-4'] ?? null;
        self::assertIsArray($psr4);

        self::assertSame('oeltimacreation/php-simplequery', $composer['name'] ?? null);
        self::assertSame('^8.2', $requirements['php'] ?? null);
        self::assertSame('*', $requirements['ext-pdo'] ?? null);
        self::assertSame('src/', $psr4['Oeltima\\SimpleQuery\\'] ?? null);
    }

    #[DataProvider('phpFiles')]
    public function testEveryPhpFileUsesStrictTypes(string $file): void
    {
        $contents = file_get_contents($file);

        self::assertIsString($contents);
        self::assertMatchesRegularExpression(
            '/\A<\?php\R\Rdeclare\(strict_types=1\);/',
            $contents,
            sprintf('%s must begin with the strict-types declaration.', $file),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (['src', 'tests', 'tools', 'scripts', 'benchmarks'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    yield $file->getPathname() => [$file->getPathname()];
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $relativePath): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
