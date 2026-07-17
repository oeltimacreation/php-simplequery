<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Consumer;

use PHPUnit\Framework\TestCase;

final class ExternalCompilerConsumerTest extends TestCase
{
    public function testPublicCompilerToolkitWorksWithoutInternalImports(): void
    {
        $fixture = require dirname(__DIR__) . '/Fixtures/Consumer/compiler-smoke.php';
        self::assertIsCallable($fixture);
        $fixture();
        self::addToAssertionCount(1);
    }
}
