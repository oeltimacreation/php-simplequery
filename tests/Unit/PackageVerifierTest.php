<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Tools\Quality\PackageVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

#[RequiresPhpExtension('zip')]
final class PackageVerifierTest extends TestCase
{
    #[DataProvider('badPackages')]
    public function testForbiddenOrIncompletePackagesFail(string $fault): void
    {
        $path = sys_get_temp_dir() . '/simplequery-package-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        foreach (
            ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'SUPPORT.md',
            'src/Connection.php', 'src/Testing/CompilerConnection.php', 'src/Testing/CompiledQueryAssertions.php',
            'docs/guides/upgrading.md', 'docs/guides/getting-started.md', 'examples/README.md'] as $file
        ) {
            $zip->addFromString($file, '{}');
        }
        $zip->close();
        $verifier = new PackageVerifier();
        self::assertSame(12, $verifier->inspect($path)['files']);
        self::assertSame('{}', $verifier->composerJson($path));
        $zip->open($path);
        match ($fault) {
            'missing toolkit' => $zip->deleteName('src/Testing/CompilerConnection.php'),
            'unknown root' => $zip->addFromString('local-settings.ini', 'synthetic'),
            'cache' => $zip->addFromString('.phpstan.cache/fixture', 'synthetic'),
            'environment' => $zip->addFromString('src/.env.local', 'synthetic'),
            'traversal' => $zip->addEmptyDir('../escaped'),
            'oversized' => $zip->addFromString('docs/large.md', str_repeat('a', 786433)),
            'symlink' => $zip->setExternalAttributesName('README.md', ZipArchive::OPSYS_UNIX, 0120777 << 16),
            default => throw new RuntimeException('Unknown package fault.'),
        };
        $zip->close();
        try {
            $this->expectException(RuntimeException::class);
            $verifier->inspect($path);
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badPackages(): iterable
    {
        $faults = ['missing toolkit', 'unknown root', 'cache', 'environment', 'traversal', 'oversized', 'symlink'];
        foreach ($faults as $fault) {
            yield $fault => [$fault];
        }
    }
}
