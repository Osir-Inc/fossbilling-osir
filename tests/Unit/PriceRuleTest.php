<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Import\PriceRule;
use Osir\FossBilling\Import\Rounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceRuleTest extends TestCase
{
    /** @return iterable<string, array{Rounding, int, int}> */
    public static function roundings(): iterable
    {
        yield 'none keeps cents' => [Rounding::None, 1234, 1234];
        yield '.99 from below' => [Rounding::Cents99, 1210, 1299];
        yield '.99 exact stays' => [Rounding::Cents99, 1299, 1299];
        yield '.99 whole amount' => [Rounding::Cents99, 1200, 1299];
        yield 'whole up' => [Rounding::Whole, 1201, 1300];
        yield 'whole exact stays' => [Rounding::Whole, 1300, 1300];
    }

    #[DataProvider('roundings')]
    public function testRoundingOnlyGoesUp(Rounding $rounding, int $in, int $out): void
    {
        self::assertSame($out, $rounding->apply($in));
        self::assertGreaterThanOrEqual($in, $rounding->apply($in));
    }

    public function testPercentPlusFixedInIntegerCents(): void
    {
        // 10.89 + 20 % (2.178 → 2.18, rounded up) + 1.00 = 14.07
        self::assertSame(1407, PriceRule::fromInput('20', '1', 'none')->sellingCents(1089));
        // 12.5 % of 9.99 = 1.24875 → 1.25
        self::assertSame(1124, PriceRule::fromInput('12.5', '', 'none')->sellingCents(999));
        self::assertSame(1499, PriceRule::fromInput('20', '1.00', '99')->sellingCents(1089));
        self::assertSame(1400, PriceRule::fromInput('20', '0.50', 'whole')->sellingCents(1089)); // 13.57 → 14.00
    }

    public function testZeroMarkupSellsAtCostNeverBelow(): void
    {
        self::assertSame(1089, PriceRule::fromInput('0', '0', 'none')->sellingCents(1089));
        self::assertSame(1089, PriceRule::fromInput('', '', 'none')->sellingCents(1089));
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function badInput(): iterable
    {
        yield 'negative percent' => ['-5', '0', 'none'];
        yield 'three decimals' => ['1.234', '0', 'none'];
        yield 'exponent' => ['1e3', '0', 'none'];
        yield 'comma' => ['12,5', '0', 'none'];
        yield 'over 1000 %' => ['1000.01', '0', 'none'];
        yield 'fixed too large' => ['10', '1000.01', 'none'];
        yield 'array' => [['20'], '0', 'none'];
        yield 'unknown rounding' => ['10', '0', 'up'];
        yield 'rounding not a string' => ['10', '0', 99];
    }

    #[DataProvider('badInput')]
    public function testRejectsInsteadOfGuessing(mixed $percent, mixed $fixed, mixed $rounding): void
    {
        $this->expectException(ValidationException::class);
        PriceRule::fromInput($percent, $fixed, $rounding);
    }

    public function testDescribe(): void
    {
        self::assertSame('20 % + 1.50, rounded up to .99', PriceRule::fromInput('20', '1.5', '99')->describe());
        self::assertSame('12.5 %', PriceRule::fromInput('12.50', '0', 'none')->describe());
    }

    public function testCostMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PriceRule::fromInput('10', '0', 'none')->sellingCents(0);
    }
}
