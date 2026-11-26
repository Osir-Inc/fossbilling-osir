<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Import\ImportService;
use Osir\FossBilling\Import\TldPriceList;
use Osir\FossBilling\Support\SafeLogger;
use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The price list ClientFence's "TLDs and prices" page reads ({@see \Registrar_TldPrices}).
 */
final class TldPriceListTest extends TestCase
{
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

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function quote(array $overrides = []): array
    {
        return array_merge([
            'domain' => 'x.com', 'registrationYears' => 1, 'totalCost' => 1039, 'standardTotalCost' => 1039,
            'totalFees' => 1089, 'premium' => false, 'promoApplied' => false, 'valid' => true, 'currency' => 'USD',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $extensions */
    private static function catalog(ScriptedHttpClient $http, array $extensions): ScriptedHttpClient
    {
        return $http->json(200, ['totalExtensions' => count($extensions), 'extensions' => $extensions]);
    }

    private static function list(ScriptedHttpClient $http, ?CapturingLogger $logger = null): TldPriceList
    {
        $logger ??= new CapturingLogger();
        $log = new SafeLogger($logger, true);

        return new TldPriceList(new ImportService(Fixtures::apiClient($http, logger: $logger), $log, static fn(): string => 'probe1'), $log);
    }

    public function testPricesEveryTldInTheCatalogWhenNoneAreNamed(): void
    {
        $http = new ScriptedHttpClient();
        self::catalog($http, [self::entry(), self::entry(['extension' => '.net'])]);
        $http->json(200, self::quote())->json(200, self::quote(['totalCost' => 2000, 'standardTotalCost' => 2000, 'totalFees' => 2050]));

        $answer = self::list($http)->slice([]);

        self::assertSame('USD', $answer['currency']);
        self::assertSame(['.com', '.net'], array_keys($answer['prices']));
        // Cost in whole units, fees included: 1039 + 50 cents.
        self::assertSame(['register' => 10.89, 'renew' => 10.89, 'transfer' => 10.89], $answer['prices']['.com']);
        self::assertSame(20.5, $answer['prices']['.net']['register']);
        self::assertSame([], $answer['remaining']);
    }

    public function testAnsweringOneSliceAtATimeAndAskingForTheRestBack(): void
    {
        $extensions = [];
        for ($i = 0; $i < TldPriceList::SLICE + 3; ++$i) {
            $extensions[] = self::entry(['extension' => '.t' . $i]);
        }
        $http = new ScriptedHttpClient();
        self::catalog($http, $extensions);
        for ($i = 0; $i < TldPriceList::SLICE; ++$i) {
            $http->json(200, self::quote());
        }

        $answer = self::list($http)->slice([]);

        self::assertCount(TldPriceList::SLICE, $answer['prices']);
        self::assertCount(3, $answer['remaining'], 'what is left comes back to be asked for again');
        // The catalog is sorted as text, so .t10 comes before .t2 — what matters is that the
        // slice and what is left together make up the whole list, with nothing priced twice.
        self::assertSame([], array_intersect(array_keys($answer['prices']), $answer['remaining']));
        self::assertCount(TldPriceList::SLICE + 3, array_merge(array_keys($answer['prices']), $answer['remaining']));
    }

    public function testNamedTldsAreNormalisedAndOnesOsirDoesNotSellAreDropped(): void
    {
        $http = new ScriptedHttpClient();
        self::catalog($http, [self::entry(['extension' => '.com']), self::entry(['extension' => '.xn--p1ai'])]);
        $http->json(200, self::quote())->json(200, self::quote());

        $answer = self::list($http)->slice(['COM', '.рф', 'nosuchtld', '.com', '../etc/passwd']);

        self::assertSame(['.com', '.xn--p1ai'], array_keys($answer['prices']), 'case, missing dot, IDN and duplicates');
        self::assertSame([], $answer['remaining'], 'a TLD OSIR does not sell is not asked for again');
    }

    public function testATldOsirWillNotQuoteIsLeftOutRatherThanPricedAtNothing(): void
    {
        $http = new ScriptedHttpClient();
        self::catalog($http, [self::entry(['extension' => '.bar']), self::entry()]);
        // OSIR answers the quote for .bar with valid:false (a registry change); .com is fine.
        $http->json(200, self::quote(['valid' => false, 'totalCost' => 0, 'totalFees' => 0]))->json(200, self::quote());

        $logger = new CapturingLogger();
        $answer = self::list($http, $logger)->slice([]);

        self::assertSame(['.com'], array_keys($answer['prices']), 'no price is better than a wrong price');
        self::assertStringContainsString('.bar', $logger->all());
        self::assertStringContainsString('no usable price', $logger->all());
    }

    public function testTheCatalogIsFetchedOncePerRequest(): void
    {
        $http = new ScriptedHttpClient();
        self::catalog($http, [self::entry(), self::entry(['extension' => '.net'])]);
        $http->json(200, self::quote())->json(200, self::quote());

        $list = self::list($http);
        $list->slice(['.com']);
        $list->slice(['.net']);

        $catalogCalls = 0;
        foreach ($http->requests as $request) {
            if (str_contains($request->url, '/catalog/domains')) {
                ++$catalogCalls;
            }
        }
        self::assertSame(1, $catalogCalls, 'ClientFence asks slice after slice within one request');
    }
}
