<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Exception\NumericOverflowException;
use Oeltima\SimpleQuery\Internal\AggregateResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class AggregateResultTest extends TestCase
{
    #[DataProvider('validCounts')]
    public function testCountNormalizesSupportedDriverValues(int|string $value, int $expected): void
    {
        self::assertSame($expected, AggregateResult::count($value));
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function validCounts(): iterable
    {
        yield 'integer zero' => [0, 0];
        yield 'decimal zero' => ['0', 0];
        yield 'leading zeros' => ['00012', 12];
        yield 'integer maximum' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'decimal maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX];
    }

    public function testCountRejectsOverflow(): void
    {
        $overflow = self::incrementDecimal((string) PHP_INT_MAX);

        $this->expectException(NumericOverflowException::class);
        AggregateResult::count($overflow);
    }

    #[DataProvider('invalidCounts')]
    public function testCountRejectsInvalidDriverValues(mixed $value): void
    {
        $this->expectException(UnexpectedValueException::class);
        AggregateResult::count($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCounts(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-1'];
        yield 'decimal fraction' => ['1.5'];
        yield 'float' => [1.0];
        yield 'null' => [null];
        yield 'false' => [false];
    }

    private static function incrementDecimal(string $value): string
    {
        $digits = str_split($value);
        for ($position = count($digits) - 1; $position >= 0; --$position) {
            if ($digits[$position] !== '9') {
                $digits[$position] = (string) ((int) $digits[$position] + 1);

                return implode('', $digits);
            }
            $digits[$position] = '0';
        }

        return '1' . implode('', $digits);
    }
}
