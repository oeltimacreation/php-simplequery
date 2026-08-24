<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Tools\Quality\ReleaseConsistencyChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReleaseConsistencyCheckerTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/simplequery-release-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/docs/plans', 0777, true);
        mkdir($root . '/docs/maintainers', 0777, true);
        mkdir($root . '/.codescene', 0777, true);
        mkdir($root . '/.github/workflows', 0777, true);
        $this->root = $root;

        file_put_contents($root . '/composer.json', json_encode([
            'scripts' => [
                'check' => 'php scripts/check.php',
                'test' => 'phpunit',
            ],
        ], JSON_PRETTY_PRINT));

        file_put_contents($root . '/docs/plans/0.6.md', '# 0.6 Plan');
        file_put_contents($root . '/docs/plans/README.md', '[Active Plan](0.6.md)');
        file_put_contents($root . '/docs/README.md', '[Active Plan](plans/0.6.md)');
        file_put_contents($root . '/docs/maintainers/README.md', '[Active Plan](../plans/0.6.md)');

        file_put_contents($root . '/SECURITY.md', "| 0.5.x | ✅ Current release line |\n");
        file_put_contents($root . '/SUPPORT.md', "`0.5.0` is the current minor release.\nPin `~0.5.0`.\n");

        file_put_contents(
            $root . '/.github/workflows/ci.yml',
            "git worktree add --detach \"\${baseline_dir}\" v0.5.0\n",
        );
        file_put_contents($root . '/docs/maintainers/benchmarking.md', "Comparing against v0.5.0.\n");

        file_put_contents($root . '/README.md', "Run `composer check` and `composer install`.\n");
        file_put_contents($root . '/.codescene/code-health-rules.json', json_encode([
            'rule_sets' => [
                ['matching_content_path' => 'README.md'],
            ],
        ], JSON_PRETTY_PRINT));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
        }

        rmdir($this->root);
    }

    public function testValidRepositoryPassesReleaseConsistency(): void
    {
        $checker = new ReleaseConsistencyChecker();
        self::assertSame([], $checker->check($this->root));
    }

    public function testMultipleActivePlansAreRejected(): void
    {
        file_put_contents($this->root . '/docs/plans/0.5.md', '# Old Plan');

        $checker = new ReleaseConsistencyChecker();
        $errors = $checker->check($this->root);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Multiple active plan files found in docs/plans', $errors[0]);
    }

    public function testSupportPolicyMismatchIsReported(): void
    {
        file_put_contents($this->root . '/SUPPORT.md', "`0.3.0` is the current minor release.\nPin `~0.2.0`.\n");

        $checker = new ReleaseConsistencyChecker();
        $errors = $checker->check($this->root);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('SUPPORT.md current minor release does not match SECURITY.md', $errors[0]);
    }

    public function testUnmaintainedComposerCommandInActiveDocIsReported(): void
    {
        file_put_contents($this->root . '/README.md', "Run `composer unknown-command` to build.\n");

        $checker = new ReleaseConsistencyChecker();
        $errors = $checker->check($this->root);

        self::assertCount(1, $errors);
        self::assertStringContainsString(
            'README.md references unmaintained composer command "unknown-command".',
            $errors[0],
        );
    }

    public function testNonExistentCodeScenePathIsReported(): void
    {
        file_put_contents($this->root . '/.codescene/code-health-rules.json', json_encode([
            'rule_sets' => [
                ['matching_content_path' => 'non/existent/path.php'],
            ],
        ], JSON_PRETTY_PRINT));

        $checker = new ReleaseConsistencyChecker();
        $errors = $checker->check($this->root);

        self::assertCount(1, $errors);
        self::assertStringContainsString(
            '.codescene/code-health-rules.json configures non-existent path: non/existent/path.php.',
            $errors[0],
        );
    }
}
