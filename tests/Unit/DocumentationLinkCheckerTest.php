<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Tools\Quality\DocumentationLinkChecker;
use PHPUnit\Framework\TestCase;

final class DocumentationLinkCheckerTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/simplequery-doc-links-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/docs', 0777, true));
        self::assertTrue(mkdir($root . '/coverage'));
        $this->root = $root;
        file_put_contents($root . '/README.md', 'Repository documentation.');
        file_put_contents($root . '/docs/guide.md', '# Guide');
        file_put_contents($root . '/docs/index.md', '# Index');
        file_put_contents($root . '/coverage/generated.md', '[Missing](nope.md)');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['coverage/generated.md', 'docs/guide.md', 'docs/index.md', 'README.md'] as $file) {
            unlink($this->root . '/' . $file);
        }
        rmdir($this->root . '/coverage');
        rmdir($this->root . '/docs');
        rmdir($this->root);
    }

    public function testRelativeTargetsAreCheckedWhileExternalAndGeneratedTreesAreIgnored(): void
    {
        file_put_contents($this->root . '/README.md', '[Guide](docs/guide.md) [Web](https://example.test)');
        file_put_contents($this->root . '/docs/guide.md', '[Index](index.md#start)');
        file_put_contents($this->root . '/docs/index.md', '# Start');
        self::assertSame([], (new DocumentationLinkChecker())->check($this->root));
    }

    public function testMissingRelativeTargetReportsSourceAndTarget(): void
    {
        file_put_contents($this->root . '/docs/guide.md', '[Missing](../missing.md)');

        self::assertSame(
            ['docs/guide.md links to missing target ../missing.md.'],
            (new DocumentationLinkChecker())->check($this->root),
        );
    }
}
