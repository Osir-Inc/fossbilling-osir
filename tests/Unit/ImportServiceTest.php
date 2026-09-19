<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Import\CatalogTld;
use Osir\FossBilling\Import\ImportService;
use Osir\FossBilling\Mapping\DomainStatus;
use Osir\FossBilling\Support\SafeLogger;
use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class ImportServiceTest extends TestCase
{
    private static function import(ScriptedHttpClient $http, ?CapturingLogger $logger = null): ImportService
    {
        $logger ??= new CapturingLogger();

        return new ImportService(Fixtures::apiClient($http, logger: $logger), new SafeLogger($logger, true), static fn(): string => 'abc123');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function entry(array $overrides = []): array
    {
        return array_merge([
            'extension' => '.com', 'registrationPrice' => 1039, 'renewalPrice' => 1039, 'transferPrice' => 1039,
            'minRegistrationPeriod' => 1, 'maxRegistrationPeriod' => 10, 'minCharacters' => 2, 'maxCharacters' => 63,
            'hasPremium' => false, 'hasRestrictions' => false, 'extensionType' => 'gTLD',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private static function tld(array $overrides = []): CatalogTld
    {
        $tld = CatalogTld::fromApi(self::entry($overrides));
        self::assertNotNull($tld);

        return $tld;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function quote(array $overrides = []): array
    {
        return array_merge([
            'domain' => 'osirpriceabc123.com', 'registrationYears' => 1, 'pricePerYear' => 1039, 'totalCost' => 1039,
            'standardTotalCost' => 1039, 'icannFee' => 20, 'registrarFee' => 30, 'totalFees' => 1089, 'premium' => false,
            'promoApplied' => false, 'valid' => true, 'currency' => 'USD',
        ], $overrides);
    }

    // ------------------------------------------------------------------ catalog

    public function testCatalogKeepsUsableEntriesSortedAndLeavesTheRestOut(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['totalExtensions' => 6, 'extensions' => [
            self::entry(['extension' => '.net', 'transferPrice' => 0]),
            self::entry(),
            self::entry(['extension' => '.free', 'registrationPrice' => 0]),
            self::entry(['extension' => 'org']),               // no leading dot
            self::entry(['extension' => '.bad/../x']),
            self::entry(['extension' => '.РФ']),               // IDN, normalised to punycode
            'garbage',
        ]]);
        $logger = new CapturingLogger();
        $catalog = self::import($http, $logger)->catalog();

        self::assertSame(['.com', '.net', '.xn--p1ai'], array_keys($catalog));
        self::assertSame(1039, $catalog['.net']->transferCents, 'transfer price 0 means "same as registration"');
        self::assertStringContainsString('4 entries', $logger->all());
        self::assertSame('GET', $http->requests[0]->method);
        self::assertStringEndsWith('/v1/public/catalog/domains', $http->requests[0]->url);
    }

    public function testCatalogWithoutExtensionsIsAProtocolError(): void
    {
        $this->expectException(ApiException::class);
        self::import((new ScriptedHttpClient())->json(200, ['totalExtensions' => 0]))->catalog();
    }

    public function testCatalogEntryBounds(): void
    {
        $tld = self::tld(['minRegistrationPeriod' => 2, 'maxRegistrationPeriod' => 1, 'maxCharacters' => 500, 'extensionType' => '<b>x</b>']);
        self::assertSame(2, $tld->minYears);
        self::assertSame(2, $tld->maxYears, 'max is never below min');
        self::assertSame(63, $tld->maxCharacters);
        self::assertNull($tld->type, 'an unexpected type string is dropped, never echoed');
        self::assertNull(CatalogTld::fromApi(self::entry(['renewalPrice' => '1039'])), 'prices must be integers');
    }

    // ------------------------------------------------------------------ cost

    public function testCostIsTheQuoteWithFeesAndTheCatalogForRenewAndTransfer(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote());
        $cost = self::import($http)->cost(self::tld(['renewalPrice' => 1200, 'transferPrice' => 900]));

        self::assertSame(1089, $cost->registerCents);
        self::assertSame(1250, $cost->renewCents);
        self::assertSame(950, $cost->transferCents);
        self::assertSame(50, $cost->feesCents);
        self::assertFalse($cost->estimated);
        self::assertStringContainsString('/v2/domains/osirpriceabc123.com/quote?years=1', $http->requests[0]->url);
    }

    public function testPromotionIsIgnoredInFavourOfTheStandardPrice(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote(['totalCost' => 99, 'pricePerYear' => 99, 'totalFees' => 149, 'standardTotalCost' => 1039, 'promoApplied' => true]));
        $cost = self::import($http)->cost(self::tld());

        self::assertSame(1089, $cost->registerCents, 'promo 0.99 must not become the permanent price');
    }

    public function testRegistryPriceAboveCatalogRaisesRenewAndTransferProportionally(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote(['totalCost' => 5000, 'standardTotalCost' => 5000, 'totalFees' => 5050]));
        $cost = self::import($http)->cost(self::tld(['registrationPrice' => 4000, 'renewalPrice' => 4000, 'transferPrice' => 3000]));

        self::assertSame(5050, $cost->registerCents);
        self::assertSame(5050, $cost->renewCents);
        self::assertSame(3800, $cost->transferCents);
        self::assertTrue($cost->estimated);
    }

    public function testRegistryPriceBelowCatalogIsFlaggedButRenewalIsNotLowered(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote(['totalCost' => 800, 'standardTotalCost' => 800, 'totalFees' => 850]));
        $cost = self::import($http)->cost(self::tld());

        self::assertSame(850, $cost->registerCents);
        self::assertSame(1089, $cost->renewCents);
        self::assertTrue($cost->estimated);
    }

    public function testMinimumPeriodIsQuotedAndDividedPerYear(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote(['registrationYears' => 2, 'totalCost' => 2001, 'standardTotalCost' => 2001, 'totalFees' => 2101]));
        $tld = self::tld(['minRegistrationPeriod' => 2, 'minRenewalPeriod' => 2, 'registrationPrice' => 1001, 'renewalPrice' => 1001, 'transferPrice' => 1001]);
        $cost = self::import($http)->cost($tld);

        self::assertStringContainsString('quote?years=2', $http->requests[0]->url);
        self::assertSame(1001 + 50, $cost->registerCents, 'per year, rounded up');
        self::assertSame(2, $tld->minRenewYears);
    }

    public function testDearerRenewalScalesTheFeeUpAndIsFlagged(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote(['totalCost' => 1000, 'standardTotalCost' => 1000, 'totalFees' => 1050]));
        $cost = self::import($http)->cost(self::tld(['registrationPrice' => 1000, 'renewalPrice' => 12000, 'transferPrice' => 1000]));

        self::assertSame(12000 + 600, $cost->renewCents, 'fee scaled with the price (OSIR\'s fee can be a percentage)');
        self::assertSame(1050, $cost->transferCents);
        self::assertTrue($cost->estimated);
    }

    public function testPromotionMarksTheCostEstimated(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote(['totalCost' => 99, 'totalFees' => 149, 'standardTotalCost' => 1039, 'promoApplied' => true]));
        self::assertTrue(self::import($http)->cost(self::tld())->estimated);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableQuotes(): iterable
    {
        yield 'not valid' => [['valid' => false]];
        yield 'premium probe' => [['premium' => true]];
        yield 'zero price' => [['totalCost' => 0, 'standardTotalCost' => 0, 'totalFees' => 50]];
        yield 'warning' => [['warning' => 'Pricing unavailable']];
        yield 'fees below price' => [['totalFees' => 1000]];
        yield 'price as string' => [['totalCost' => '1039']];
        yield 'multi-year' => [['registrationYears' => 2]];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableQuotes')]
    public function testUnusableQuoteIsRefusedNotGuessed(array $override): void
    {
        $this->expectException(RuleException::class);
        self::import((new ScriptedHttpClient())->json(200, self::quote($override)))->cost(self::tld());
    }

    public function testProbeNameRespectsTheTldLengthLimits(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::quote());
        self::import($http)->cost(self::tld(['extension' => '.io', 'maxCharacters' => 5]));
        self::assertStringContainsString('/v2/domains/osirp.io/quote', $http->requests[0]->url);

        $http = (new ScriptedHttpClient())->json(200, self::quote());
        self::import($http)->cost(self::tld(['extension' => '.io', 'minCharacters' => 30]));
        self::assertStringContainsString('/v2/domains/osirpriceabc123' . str_repeat('x', 15) . '.io/quote', $http->requests[0]->url);
    }

    // ------------------------------------------------------------------ domains

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function listed(string $domain, array $overrides = []): array
    {
        return array_merge([
            'id' => 'x', 'domain' => $domain, 'customerId' => 'c', 'creationDate' => '2024-11-25T10:00:00', 'expirationDate' => '2026-11-25T23:59:59',
            'statuses' => ['ok'], 'nameservers' => ['NS1.Example.NET.', 'ns2.example.net', 'ns1.example.net'], 'autoRenew' => false, 'privacy' => true, 'status' => 'active',
        ], $overrides);
    }

    public function testDomainsArePagedDedupedAndValidated(): void
    {
        $logger = new CapturingLogger();
        $http = (new ScriptedHttpClient())
            ->envelope(200, ['domains' => [self::listed('a.com'), self::listed('../evil'), self::listed('B.com')], 'totalPages' => 2, 'page' => 0])
            ->envelope(200, ['domains' => [self::listed('a.com', ['status' => 'expired']), self::listed('c.com', ['expirationDate' => 'soon'])], 'totalPages' => 2, 'page' => 1]);
        $domains = self::import($http, $logger)->domains();

        self::assertSame(['a.com', 'b.com', 'c.com'], array_keys($domains));
        self::assertSame(DomainStatus::Active, $domains['a.com']->status, 'first occurrence wins');
        self::assertSame(['ns1.example.net', 'ns2.example.net'], $domains['a.com']->nameservers);
        self::assertSame(1795651199, $domains['a.com']->expiresAt);
        self::assertTrue($domains['a.com']->importable());
        self::assertFalse($domains['c.com']->importable(), 'no parseable expiry → not importable');
        self::assertStringContainsString('skipped', $logger->all());
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('page=1', $http->requests[1]->url);
    }

    public function testOnlyActiveRegistrationsAreImportable(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['domains' => [
            self::listed('grace.com', ['status' => 'autoRenewGracePeriod']),
            self::listed('red.com', ['status' => 'redemptionPeriod']),
            self::listed('gone.com', ['status' => 'transferredOut']),
            self::listed('new.com', ['status' => 'somethingNew']),
        ], 'totalPages' => 1]);
        $domains = self::import($http)->domains();

        self::assertFalse($domains['grace.com']->importable(), 'grace: the registry expiry is already a year ahead');
        self::assertStringContainsString('Renew it at OSIR first', (string) $domains['grace.com']->notImportableReason());
        self::assertFalse($domains['red.com']->importable());
        self::assertFalse($domains['gone.com']->importable());
        self::assertFalse($domains['new.com']->importable());
    }

    public function testMalformedListingIsAProtocolError(): void
    {
        $this->expectException(ApiException::class);
        self::import((new ScriptedHttpClient())->envelope(200, ['domains' => []]))->domains();
    }

    public function testEmptyPageEndsTheListing(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['domains' => [], 'totalPages' => 5]);
        self::assertSame([], self::import($http)->domains());
        self::assertCount(1, $http->requests);
    }

    // ------------------------------------------------------------------ registrant

    public function testRegistrantIsMappedToFossBillingFields(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['domainName' => 'a.com', 'registrant' => [
            'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.org', 'phone' => '+355.691234567',
            'organization' => ' ', 'street1' => '1 Main St', 'city' => 'Tirana', 'postalCode' => '1001', 'country' => 'al',
        ]]);
        $c = self::import($http)->registrant(DomainName::fromString('a.com'));

        self::assertNotNull($c);
        self::assertSame(['355', '691234567'], [$c->phoneCountryCode, $c->phone]);
        self::assertSame('AL', $c->country);
        self::assertNull($c->organization, 'blank values become null');
        self::assertStringEndsWith('/v2/domains/a.com/contacts', $http->requests[0]->url);
    }

    public function testNoRegistrantIsNull(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['domainName' => 'a.com', 'registrant' => null]);
        self::assertNull(self::import($http)->registrant(DomainName::fromString('a.com')));
    }

    public function testPhoneSplitting(): void
    {
        self::assertSame(['1', '2025550100'], ImportService::splitPhone('+1.2025550100'));
        self::assertSame([null, '0691234567'], ImportService::splitPhone('0691234567'));
        self::assertSame([null, null], ImportService::splitPhone(null));
    }
}
