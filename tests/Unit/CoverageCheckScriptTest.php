<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CoverageCheckScriptTest extends TestCase
{
    private string $report;

    #[\Override]
    protected function setUp(): void
    {
        $report = tempnam(sys_get_temp_dir(), 'simplequery-coverage-');
        self::assertIsString($report);
        $this->report = $report;
        file_put_contents($this->report, <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project>
    <metrics statements="20" coveredstatements="20" conditionals="0" coveredconditionals="0"/>
    <file name="/project/src/Internal/Compiler/TestCompiler.php">
      <metrics statements="10" coveredstatements="10" conditionals="0" coveredconditionals="0"/>
    </file>
  </project>
</coverage>
XML);
    }

    #[\Override]
    protected function tearDown(): void
    {
        unlink($this->report);
    }

    public function testLineOnlyCoverageAcceptsAReportWithoutBranchMetrics(): void
    {
        [$status, $output] = $this->runChecker('--line=90', '--compiler-line=95');

        self::assertSame(0, $status, $output);
        self::assertStringNotContainsString('branch', strtolower($output));
    }

    public function testConfiguredBranchCoverageFailsWhenMetricsAreAbsent(): void
    {
        [$status, $output] = $this->runChecker(
            '--line=90',
            '--branch=80',
            '--compiler-line=95',
            '--compiler-branch=90',
        );

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('Overall branch coverage is required but not reported.', $output);
        self::assertStringContainsString('Compiler branch coverage is required but not reported.', $output);
    }

    /** @return array{int, string} */
    private function runChecker(string ...$options): array
    {
        $command = array_merge([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/scripts/check-coverage.php',
            $this->report,
        ], $options);
        $output = [];
        $status = 0;
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }
}
