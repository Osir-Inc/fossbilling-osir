<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Mapping\DomainInfoParser;
use Osir\FossBilling\Mapping\DomainStatus;
use Osir\FossBilling\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainInfoParserTest extends TestCase
{
    private const int T1 = 1821348672; // 2027-09-19T10:11:12Z
    private const int T0 = 1821312000; // 2027-09-19T00:00:00Z

    /** @return iterable<string, array{string, ?int}> */
    public static function dates(): iterable
    {
        yield 'zone-less is UTC' => ['2027-09-19T10:11:12', self::T1];
        yield 'fractional seconds' => ['2027-09-19T10:11:12.123456', self::T1];
        yield 'Z' => ['2027-09-19T10:11:12Z', self::T1];
        yield 'offset' => ['2027-09-19T12:11:12+02:00', self::T1];
        yield 'date only' => ['2027-09-19', self::T0];
        yield 'no rollover (Feb 30)' => ['2027-02-30T00:00:00', null];
        yield 'bad hour' => ['2027-09-19T25:00:00', null];
        yield 'garbage' => ['next tuesday', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('dates')]
    public function testParsesDatesStrictlyAsUtc(string $input, ?int $expected): void
    {
        self::assertSame($expected, DomainInfoParser::timestamp($input));
    }

    public function testIsIndependentOfThePhpDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14
        try {
            self::assertSame(self::T1, DomainInfoParser::timestamp('2027-09-19T10:11:12'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testUnknownStatusIsMappedToUnknownAndFlagsAreRead(): void
    {
        $info = DomainInfoParser::parse(Fixtures::info(['status' => 'somethingNew', 'inAutoRenewGracePeriod' => true, 'inRedemptionPeriod' => false]), 'r');
        self::assertSame(DomainStatus::Unknown, $info->status);
        self::assertTrue($info->inAutoRenewGrace);
        self::assertFalse($info->inRedemption);
    }
}
