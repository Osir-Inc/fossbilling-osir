<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Mapping\DomainInfo;
use Osir\FossBilling\Mapping\DomainStatus;
use Osir\FossBilling\Service\RenewalDecision;
use Osir\FossBilling\Service\RenewalPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenewalPolicyTest extends TestCase
{
    private const int DAY = 86400;
    private const int KNOWN = 1820000000; // FOSSBilling's expiry on record

    private static function info(DomainStatus $status, ?int $expires, bool $grace = false, bool $redemption = false): DomainInfo
    {
        return new DomainInfo('example.com', $status, [], null, $expires, [], false, false, $redemption, $grace);
    }

    /** @return iterable<string, array{DomainInfo, int, RenewalDecision}> */
    public static function decisions(): iterable
    {
        yield 'same expiry → renew' => [self::info(DomainStatus::Active, self::KNOWN), 1, RenewalDecision::Renew];
        yield 'registry a few days ahead → renew' => [self::info(DomainStatus::Active, self::KNOWN + 3 * self::DAY), 1, RenewalDecision::Renew];
        yield 'registry behind FOSSBilling → renew' => [self::info(DomainStatus::Active, self::KNOWN - 30 * self::DAY), 1, RenewalDecision::Renew];
        yield 'one year ahead → already applied' => [self::info(DomainStatus::Active, self::KNOWN + 365 * self::DAY), 1, RenewalDecision::AlreadyApplied];
        yield 'boundary: 320 days ahead → already applied' => [self::info(DomainStatus::Active, self::KNOWN + 320 * self::DAY), 1, RenewalDecision::AlreadyApplied];
        yield 'boundary: 319 days ahead → renew' => [self::info(DomainStatus::Active, self::KNOWN + 319 * self::DAY), 1, RenewalDecision::Renew];
        yield '2 years: one year ahead → renew' => [self::info(DomainStatus::Active, self::KNOWN + 365 * self::DAY), 2, RenewalDecision::Renew];
        yield '2 years: two years ahead → already applied' => [self::info(DomainStatus::Active, self::KNOWN + 730 * self::DAY), 2, RenewalDecision::AlreadyApplied];
        yield 'grace, a year ahead → pay grace (NOT already applied)' => [self::info(DomainStatus::AutoRenewGracePeriod, self::KNOWN + 365 * self::DAY, grace: true), 1, RenewalDecision::PayAutoRenewGrace];
        yield 'grace flag with active status → pay grace' => [self::info(DomainStatus::Active, self::KNOWN + 365 * self::DAY, grace: true), 1, RenewalDecision::PayAutoRenewGrace];
        yield 'expired, no registry auto-renew → renew' => [self::info(DomainStatus::Expired, self::KNOWN), 1, RenewalDecision::Renew];
        yield 'unknown expiry at OSIR → renew' => [self::info(DomainStatus::Active, null), 1, RenewalDecision::Renew];
    }

    #[DataProvider('decisions')]
    public function testDecides(DomainInfo $info, int $years, RenewalDecision $expected): void
    {
        self::assertSame($expected, RenewalPolicy::decide($info, self::KNOWN, $years, 'example.com'));
    }

    /** @return iterable<string, array{DomainInfo, ?int, int, string}> */
    public static function refusals(): iterable
    {
        yield 'redemption' => [self::info(DomainStatus::RedemptionPeriod, null, redemption: true), self::KNOWN, 1, 'redemption'];
        yield 'redemption flag only' => [self::info(DomainStatus::Expired, null, redemption: true), self::KNOWN, 1, 'redemption'];
        yield 'pending delete' => [self::info(DomainStatus::PendingDelete, null), self::KNOWN, 1, 'redemption'];
        yield 'transferred out' => [self::info(DomainStatus::TransferredOut, null), self::KNOWN, 1, 'no longer registered'];
        yield 'deleted' => [self::info(DomainStatus::Deleted, null), self::KNOWN, 1, 'no longer registered'];
        yield 'no known expiry' => [self::info(DomainStatus::Active, self::KNOWN), null, 1, 'Synchronise'];
        yield 'grace, multi-year' => [self::info(DomainStatus::AutoRenewGracePeriod, self::KNOWN, grace: true), self::KNOWN, 3, 'Renew it for 1 year now'];
    }

    #[DataProvider('refusals')]
    public function testRefuses(DomainInfo $info, ?int $known, int $years, string $message): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage($message);
        RenewalPolicy::decide($info, $known, $years, 'example.com');
    }
}
