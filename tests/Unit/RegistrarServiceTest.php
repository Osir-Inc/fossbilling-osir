<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Config\Environment;
use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Domain\Nameservers;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Mapping\AvailabilityState;
use Osir\FossBilling\Mapping\DomainStatus;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class RegistrarServiceTest extends TestCase
{
    private static function domain(): DomainName
    {
        return DomainName::fromString('example.com');
    }

    private static function ns(): Nameservers
    {
        return Nameservers::fromList(['ns1.example.net', 'ns2.example.net']);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function available(array $extra = []): array
    {
        /** @var array<string, mixed> */
        return array_merge(['domain' => 'example.com', 'available' => true, 'premium' => false, 'totalPrice' => 1250, 'message' => 'Domain is available'], $extra);
    }

    /** @return array<string, mixed> */
    private static function registered(): array
    {
        return ['domain' => 'example.com', 'available' => false, 'reason' => 'In use', 'message' => 'Domain is already registered'];
    }

    private static function ymd(string $date): int
    {
        $t = strtotime($date . 'T00:00:00Z');
        self::assertIsInt($t);

        return $t;
    }

    // ------------------------------------------------------------ availability

    public function testClassifiesAvailabilityOutcomes(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(200, self::registered())
            ->json(200, ['available' => false, 'message' => 'Domain is already registered'])
            ->json(200, ['available' => false, 'message' => 'Error checking availability: EPP timeout'])
            ->json(200, ['available' => false, 'message' => 'Extension not supported: .xyz'])
            ->json(200, ['available' => false, 'message' => 'Unexpected error occurred'])
            ->json(200, ['available' => false, 'message' => '']);
        $service = Fixtures::service($http);

        $states = [];
        for ($i = 0; $i < 7; ++$i) {
            $states[] = $service->checkAvailability(self::domain())->state;
        }
        self::assertSame([
            AvailabilityState::Available, AvailabilityState::Registered, AvailabilityState::Registered,
            AvailabilityState::Unknown, AvailabilityState::Unknown, AvailabilityState::Unknown, AvailabilityState::Unknown,
        ], $states);
        self::assertSame('/v2/domains/example.com/available', $http->requests[0]->path());
    }

    // ------------------------------------------------------------ register

    public function testRegisterSendsCompleteSafeRequest(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->envelope(201, ['domain' => 'example.com', 'status' => 'COMPLETED']);

        self::assertTrue(Fixtures::service($http)->register(self::domain(), 2, self::ns(), Fixtures::contact(), Fixtures::order('42')));

        $r = $http->last();
        self::assertSame('POST', $r->method);
        self::assertSame('/v2/domains/register', $r->path());
        self::assertSame('fb:abc123def456:live:o42:register:example.com:2y', $r->headers['idempotency-key']);
        self::assertNotNull($r->json);
        self::assertSame('example.com', $r->json['domain']);
        self::assertSame(2, $r->json['period']);
        self::assertSame(['ns1.example.net', 'ns2.example.net'], $r->json['nameservers']);
        self::assertFalse($r->json['autoRenew'], 'FOSSBilling owns renewals');
        self::assertFalse($r->json['initializeDnsZone']);
        self::assertSame('prod', $r->json['environment'], 'environment is always explicit');
        self::assertIsArray($r->json['registrant']);
        self::assertSame('+44.2079460000', $r->json['registrant']['phone']);
        self::assertSame('fb:abc123def456:live:example.com', $r->json['registrant']['externalId'], 'one OSIR contact per domain and environment');
    }

    public function testSandboxUsesOteAndSeparateKeys(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::available())->envelope(201, ['status' => 'COMPLETED']);
        Fixtures::service($http, Fixtures::settings(Environment::Sandbox))->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order('42'));
        $r = $http->last();
        self::assertNotNull($r->json);
        self::assertSame('ote1', $r->json['environment']);
        self::assertSame(Fixtures::TEST_KEY, $r->headers['x-api-key']);
        self::assertSame('fb:abc123def456:sandbox:o42:register:example.com:1y', $r->headers['idempotency-key'], 'a sandbox success must never be replayed for the live order');
    }

    public function testIncompleteContactStopsRegistrationBeforeAnyRequest(): void
    {
        $http = new ScriptedHttpClient();
        $this->expectException(ValidationException::class);
        try {
            Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(['street1' => '']), Fixtures::order());
        } finally {
            self::assertCount(0, $http->requests, 'an incomplete registrant never reaches the registry');
        }
    }

    public function testRefusesPremiumNames(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::available(['premium' => true]));
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('premium');
        try {
            Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
        } finally {
            self::assertCount(1, $http->requests, 'no register call');
        }
    }

    public function testRefusesWhenAvailabilityIsUnknown(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['available' => false, 'message' => 'Unexpected error occurred']);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('Could not confirm');
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    /** A lost response: the name now shows as registered, and OSIR's idempotency store proves it was this order. */
    public function testRecoversThroughTheIdempotencyStore(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::registered())
            ->envelope(201, ['status' => 'COMPLETED'], ['idempotent-replay' => 'true']);

        self::assertFalse(Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order('42')));
        self::assertSame('fb:abc123def456:live:o42:register:example.com:1y', $http->last()->headers['idempotency-key']);
    }

    /**
     * Regression: two orders for the same name. The second must be refused even
     * though the domain was created after that order — only a replay proves "this order did it".
     */
    public function testDomainRegisteredByAnotherOrderIsNotAdopted(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::registered())
            ->json(409, ['success' => false, 'error' => 'Domain is not available', 'errorCode' => 'REGISTRATION_FAILED'])
            ->envelope(200, Fixtures::info(['creationDate' => gmdate('Y-m-d\TH:i:s', Fixtures::NOW)]));
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('was not registered by this order');
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order('43'));
    }

    public function testRegisteredWithoutOrderIsRefusedWithoutAnyWrite(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::registered());
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('no longer available');
        try {
            Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), null);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testTakenByAnotherRegistrantIsRefused(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::registered())
            ->json(409, ['success' => false, 'error' => 'Domain is not available', 'errorCode' => 'REGISTRATION_FAILED'])
            ->json(404, ['error' => 'Domain not found: example.com', 'status' => 404]);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('no longer available');
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testHeldByAnotherOsirCustomerCountsAsNotInAccount(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::registered())
            ->json(409, ['success' => false, 'error' => 'Domain is not available', 'errorCode' => 'REGISTRATION_FAILED'])
            ->json(403, ['error' => 'Access denied: You are not the owner of this domain', 'status' => 403]);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('no longer available');
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testRegistryOutageConflictIsNotReportedAsTaken(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(409, ['success' => false, 'error' => 'Domain is not available: Error checking availability: timeout', 'errorCode' => 'REGISTRATION_FAILED']);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('Could not confirm');
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testSandboxRetryIsRecoveredThroughTheStore(): void
    {
        // In sandbox the availability pre-check is answered from production ("available"); the
        // keyed request is what finds this order's earlier OTE registration.
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->envelope(201, ['status' => 'COMPLETED'], ['idempotent-replay' => 'true']);
        self::assertFalse(Fixtures::service($http, Fixtures::settings(Environment::Sandbox))->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order()));
        self::assertStringContainsString(':sandbox:', $http->last()->headers['idempotency-key']);
    }

    public function testLocalDuplicateWithoutReplayIsNotAdopted(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(400, ['success' => false, 'error' => 'Domain already exists in local registry', 'errorCode' => 'REGISTRATION_FAILED'])
            ->envelope(200, Fixtures::info());
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('was not registered by this order');
        Fixtures::service($http, Fixtures::settings(Environment::Sandbox))->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testReplayUnavailableFallsBackToTheCreationDate(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(409, ['success' => false, 'error' => 'stored', 'errorCode' => 'IDEMPOTENT_REPLAY_UNAVAILABLE'])
            ->envelope(200, Fixtures::info(['creationDate' => gmdate('Y-m-d\TH:i:s', Fixtures::NOW)]));
        self::assertFalse(Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order()));
    }

    public function testReplayedStoredErrorRotatesTheKeyAfterRecheck(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(500, ['success' => false, 'error' => 'Internal server error', 'errorCode' => 'INTERNAL_ERROR'], ['idempotent-replay' => 'true'])
            ->json(404, ['error' => 'nf', 'status' => 404]) // re-check: not registered
            ->envelope(201, ['status' => 'COMPLETED']);

        self::assertTrue(Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order('42')));
        $keys = array_values(array_filter(array_map(static fn($r) => $r->headers['idempotency-key'] ?? null, $http->requests), static fn(?string $k): bool => $k !== null));
        self::assertSame(['fb:abc123def456:live:o42:register:example.com:1y', 'fb:abc123def456:live:o42:register:example.com:1y:a2'], $keys);
    }

    public function testReplayedStoredErrorAfterActualSuccessIsRecovered(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(500, ['success' => false, 'error' => 'Internal server error'], ['idempotent-replay' => 'true'])
            ->envelope(200, Fixtures::info(['creationDate' => gmdate('Y-m-d\TH:i:s', Fixtures::NOW)]));
        self::assertFalse(Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order()));
        self::assertCount(3, $http->requests, 'no second registration');
    }

    public function testRotationGivesUpWithASupportMessage(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::available());
        for ($i = 0; $i < 5; ++$i) {
            $http->json(500, ['success' => false, 'error' => 'stored'], ['idempotent-replay' => 'true'])
                ->json(404, ['error' => 'nf', 'status' => 404]);
        }
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('contact OSIR support');
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testFreshServerErrorIsNotRotated(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::available())->json(500, ['success' => false, 'error' => 'boom']);
        $this->expectException(ApiException::class);
        try {
            Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testCostCapBlocksExpensiveRegistration(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(200, ['domain' => 'example.com', 'totalFees' => 3100, 'premium' => false]);
        $this->expectException(RuleException::class);
        try {
            Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 1500))->register(self::domain(), 2, self::ns(), Fixtures::contact(), Fixtures::order());
        } finally {
            self::assertSame(['years' => '2'], $http->last()->query());
            self::assertCount(2, $http->requests, 'no register call');
        }
    }

    public function testCostCapRoundsMultiYearCostUp(): void
    {
        // 3001 cents over 2 years = 1500.5/year → rounds up to 1501 > cap 1500.
        $http = (new ScriptedHttpClient())->json(200, self::available())->json(200, ['totalFees' => 3001]);
        $this->expectException(RuleException::class);
        Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 1500))->register(self::domain(), 2, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testCostCapAllowsWithinLimit(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, self::available())
            ->json(200, ['domain' => 'example.com', 'totalFees' => 3000, 'premium' => false])
            ->envelope(201, ['status' => 'COMPLETED']);
        self::assertTrue(Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 1500))->register(self::domain(), 2, self::ns(), Fixtures::contact(), Fixtures::order()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function doubtfulQuotes(): iterable
    {
        yield 'missing total' => [['domain' => 'example.com']];
        yield 'zero total' => [['totalFees' => 0]];
        yield 'premium' => [['totalFees' => 900, 'premium' => true]];
        yield 'non-integer' => [['totalFees' => '900']];
    }

    /** @param array<string, mixed> $quote */
    #[\PHPUnit\Framework\Attributes\DataProvider('doubtfulQuotes')]
    public function testCostCapFailsClosedOnDoubtfulQuotes(array $quote): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::available())->json(200, $quote);
        $this->expectException(RuleException::class);
        Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 1500))->register(self::domain(), 1, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    public function testRegisterWithoutOrderSendsNoIdempotencyKey(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::available())->envelope(201, ['status' => 'COMPLETED']);
        Fixtures::service($http)->register(self::domain(), 1, self::ns(), Fixtures::contact(), null);
        self::assertArrayNotHasKey('idempotency-key', $http->last()->headers);
    }

    public function testRejectsInvalidPeriod(): void
    {
        $this->expectException(ValidationException::class);
        Fixtures::service(new ScriptedHttpClient())->register(self::domain(), 11, self::ns(), Fixtures::contact(), Fixtures::order());
    }

    // ------------------------------------------------------------ renew

    public function testRenewKeyIsAnchoredOnTheOrdersExpiry(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['expiryDate' => '2027-09-19T10:11:12']))
            ->envelope(200, ['status' => 'COMPLETED']);

        self::assertTrue(Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7', expiresAt: self::ymd('2027-09-20'))));

        $r = $http->last();
        self::assertSame('/v2/domains/example.com/renew', $r->path());
        self::assertSame(['period' => 1, 'environment' => 'prod'], $r->json);
        self::assertSame('fb:abc123def456:live:o7:renew:example.com:1y-exp20270920', $r->headers['idempotency-key']);
        self::assertSame(['environment' => 'prod'], $http->requests[0]->query(), 'info refreshes the registry expiry first');
    }

    /**
     * Regression: the renewal went through but its response was lost; then a sync
     * copied OSIR's new expiry into FOSSBilling's domain record. The retry must reuse the SAME key
     * (anchored on the order, which only moves after success) so OSIR replays instead of renewing again.
     */
    public function testRetryAfterASyncReusesTheKey(): void
    {
        $order = Fixtures::order('7', expiresAt: self::ymd('2026-10-01'));
        $first = (new ScriptedHttpClient())->envelope(200, Fixtures::info(['expiryDate' => '2026-10-01T00:00:00']))->transportError()->transportError()->transportError();
        try {
            Fixtures::service($first)->renew(self::domain(), 1, self::ymd('2026-10-01'), $order);
            self::fail('Expected the lost response to surface');
        } catch (\Osir\FossBilling\Exception\TransportException) {
        }

        // …sync: FOSSBilling's domain expiry is now OSIR's new one (2027-10-01)…
        $retry = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['expiryDate' => '2027-10-01T00:00:00']))
            ->envelope(200, ['status' => 'COMPLETED'], ['idempotent-replay' => 'true']);
        self::assertFalse(Fixtures::service($retry)->renew(self::domain(), 1, self::ymd('2027-10-01'), $order));
        self::assertSame($first->last()->headers['idempotency-key'], $retry->last()->headers['idempotency-key']);
    }

    public function testDoesNotRenewTwiceWhenAnEarlierRenewalWentThrough(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info(['expiryDate' => '2028-09-19T10:11:12']));
        self::assertFalse(Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7')));
        self::assertCount(1, $http->requests, 'no renew call');
    }

    /**
     * Regression: a client pays the renewal AFTER expiry. The registry has
     * auto-renewed, so OSIR's expiry is already a year ahead — but nothing was paid. The adapter
     * must pay OSIR's auto-renew grace, not mistake it for an applied renewal.
     */
    public function testLateRenewalPaysTheAutoRenewGrace(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['status' => 'autoRenewGracePeriod', 'inAutoRenewGracePeriod' => true, 'rgpStatus' => 'autoRenewPeriod', 'expiryDate' => '2027-09-01T00:00:00']))
            ->envelope(200, ['status' => 'COMPLETED']);

        self::assertTrue(Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2026-09-01'), Fixtures::order('7')));
        self::assertSame('/v2/domains/example.com/renew', $http->last()->path());
    }

    public function testGraceWithMultiYearIsRefusedBeforeCharging(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info(['status' => 'autoRenewGracePeriod', 'inAutoRenewGracePeriod' => true]));
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('Renew it for 1 year now');
        try {
            Fixtures::service($http)->renew(self::domain(), 2, self::ymd('2026-09-01'), Fixtures::order('7'));
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRenewsWhenOsirIsOnlySlightlyAhead(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['expiryDate' => '2027-09-21T10:11:12']))
            ->envelope(200, ['status' => 'COMPLETED']);
        self::assertTrue(Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7')));
    }

    public function testRefusesRenewalWithoutKnownExpiry(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info());
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('Synchronise');
        try {
            Fixtures::service($http)->renew(self::domain(), 1, null, Fixtures::order('7'));
        } finally {
            self::assertCount(1, $http->requests, 'only the info read');
        }
    }

    public function testRefusesRenewalInRedemption(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info(['status' => 'redemptionPeriod']));
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('redemption');
        Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2026-09-01'), Fixtures::order('7'));
    }

    public function testRenewCostCapUsesRenewalQuoteAndChecksItsPeriod(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info())
            ->envelope(200, ['domain' => 'example.com', 'renewalYears' => 1, 'totalWithRestore' => 5000, 'finalTotal' => 1]);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('above the limit');
        try {
            Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 2000))->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7'));
        } finally {
            self::assertSame('/v2/domains/example.com/renewal-quote', $http->last()->path());
        }
    }

    public function testRenewCostCapRejectsOsirErrorQuote(): void
    {
        // A quote that could not be computed: zero totals and a message.
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info())
            ->envelope(200, ['domain' => 'example.com', 'renewalYears' => 1, 'totalWithRestore' => 0, 'finalTotal' => 0, 'message' => 'Error generating quote']);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('Could not confirm the price');
        Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 2000))->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7'));
    }

    public function testGraceCostCapUsesTheHigherOfRenewalAndRegistrationPrice(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['status' => 'autoRenewGracePeriod', 'inAutoRenewGracePeriod' => true]))
            ->envelope(200, ['renewalYears' => 1, 'totalWithRestore' => 1500])
            ->json(200, ['totalFees' => 2500]);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('above the limit');
        Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 2000))->renew(self::domain(), 1, self::ymd('2026-09-01'), Fixtures::order('7'));
    }

    public function testGraceStoredErrorIsRecoveredWhenGraceCleared(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['status' => 'autoRenewGracePeriod', 'inAutoRenewGracePeriod' => true, 'expiryDate' => '2027-09-01T00:00:00']))
            ->json(500, ['success' => false, 'error' => 'x'], ['idempotent-replay' => 'true'])
            ->envelope(200, Fixtures::info(['expiryDate' => '2027-09-01T00:00:00']));
        self::assertFalse(Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2027-09-01'), Fixtures::order('7')));
        self::assertCount(3, $http->requests, 'no rotation into a real renewal');
    }

    public function testRecheckRefusalPropagatesInsteadOfRotating(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['expiryDate' => '2027-09-19T00:00:00']))
            ->json(500, ['success' => false, 'error' => 'x'], ['idempotent-replay' => 'true'])
            ->envelope(200, Fixtures::info(['status' => 'redemptionPeriod']));
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('redemption');
        try {
            Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7'));
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testRenewReplayedErrorIsRecoveredWhenExpiryMoved(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, Fixtures::info(['expiryDate' => '2027-09-19T00:00:00']))
            ->json(500, ['success' => false, 'error' => 'x'], ['idempotent-replay' => 'true'])
            ->envelope(200, Fixtures::info(['expiryDate' => '2028-09-19T00:00:00']));
        self::assertFalse(Fixtures::service($http)->renew(self::domain(), 1, self::ymd('2027-09-19'), Fixtures::order('7')));
    }

    // ------------------------------------------------------------ transfer

    public function testTransferInitiatesWithAuthCodeAndKey(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['domain' => 'example.com', 'success' => true, 'status' => 'PENDING']);

        self::assertTrue(Fixtures::service($http)->transfer(self::domain(), ' Ab#12-xY ', 1, Fixtures::contact(), Fixtures::order('9')));
        $r = $http->last();
        self::assertSame('/v2/transfer/initiate', $r->path());
        self::assertNotNull($r->json);
        self::assertSame('Ab#12-xY', $r->json['authCode']);
        self::assertSame('fb:abc123def456:live:o9:transfer:example.com', $r->headers['idempotency-key']);
    }

    public function testRetryOfThisOrdersTransferIsReplayed(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['domain' => 'example.com', 'status' => 'PENDING'], ['idempotent-replay' => 'true']);
        self::assertFalse(Fixtures::service($http)->transfer(self::domain(), 'code', 1, Fixtures::contact(), Fixtures::order('9')));
    }

    /** A pending transfer started elsewhere must not complete another order. */
    public function testTransferPendingFromElsewhereIsNotAdopted(): void
    {
        $http = (new ScriptedHttpClient())->json(409, ['success' => false, 'error' => 'exists', 'errorCode' => 'TRANSFER_EXISTS']);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('already pending');
        Fixtures::service($http)->transfer(self::domain(), 'code', 1, Fixtures::contact(), Fixtures::order('9'));
    }

    public function testDomainAlreadyInAccountIsNotSilentlyAttached(): void
    {
        $http = (new ScriptedHttpClient())->json(400, ['success' => false, 'error' => 'Domain already owned by you', 'errorCode' => 'TRANSFER_ERROR']);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('link it to the order manually');
        Fixtures::service($http)->transfer(self::domain(), 'code', 1, Fixtures::contact(), Fixtures::order('9'));
    }

    public function testInvalidAuthCodeIsAClientFacingValidationError(): void
    {
        $http = (new ScriptedHttpClient())->json(401, ['success' => false, 'error' => 'Invalid auth code', 'errorCode' => 'INVALID_AUTH_CODE']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('transfer code');
        Fixtures::service($http)->transfer(self::domain(), 'wrong', 1, Fixtures::contact(), Fixtures::order('9'));
    }

    public function testTransferCostCapChecksTheQuotedPeriod(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['totalFees' => 900, 'transferYears' => 2]);
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('Could not confirm the price');
        Fixtures::service($http, Fixtures::settings(maxYearlyCostCents: 2000))->transfer(self::domain(), 'code', 1, Fixtures::contact(), Fixtures::order('9'));
    }

    public function testAuthCodeIsValidatedLocally(): void
    {
        $this->expectException(ValidationException::class);
        Fixtures::service(new ScriptedHttpClient())->transfer(self::domain(), "abc\ndef", 1, Fixtures::contact(), Fixtures::order('9'));
    }

    public function testCanTransferChecks(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, ['domain' => 'example.com', 'totalFees' => 1500])
            ->json(200, self::registered())
            ->json(403, ['error' => 'Access denied: You are not the owner of this domain', 'status' => 403]);
        self::assertTrue(Fixtures::service($http)->canTransfer(self::domain()));

        $unsupported = (new ScriptedHttpClient())->json(404, ['success' => false, 'errorCode' => 'UNSUPPORTED_EXTENSION', 'error' => 'x']);
        $this->expectException(RuleException::class);
        Fixtures::service($unsupported)->canTransfer(self::domain());
    }

    // ------------------------------------------------------------ management

    public function testDetailsParsesInfoAsUtc(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info());
        $info = Fixtures::service($http)->details(self::domain());

        self::assertSame(DomainStatus::Active, $info->status);
        self::assertSame(gmmktime(10, 11, 12, 9, 19, 2027), $info->expiresAt);
        self::assertSame(['ns1.example.net', 'ns2.example.net'], $info->nameservers);
        self::assertTrue($info->locked);
    }

    public function testDetailsReportsPendingTransferInsteadOfNotFound(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(404, ['error' => 'nf', 'status' => 404])
            ->json(200, ['domain' => 'example.com', 'status' => 'PENDING', 'transferType' => 'GAINING']);
        self::assertSame(DomainStatus::PendingTransfer, Fixtures::service($http)->details(self::domain())->status);
    }

    public function testDetailsOfAnUnknownDomainIsARuleError(): void
    {
        $http = (new ScriptedHttpClient())->json(404, ['error' => 'nf', 'status' => 404])->json(404, ['errorCode' => 'TRANSFER_NOT_FOUND']);
        $this->expectException(RuleException::class);
        Fixtures::service($http)->details(self::domain());
    }

    public function testDetailsPropagatesOtherErrors(): void
    {
        $http = (new ScriptedHttpClient())->json(500, ['success' => false, 'error' => 'x']);
        try {
            Fixtures::service($http)->details(self::domain());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::Server, $e->getKind());
        }
    }

    public function testMalformedInfoIsAProtocolError(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['nameservers' => []]);
        $this->expectException(ApiException::class);
        Fixtures::service($http)->details(self::domain());
    }

    public function testUnchangedNameserversAreNotSent(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info(['nameservers' => ['NS2.example.net', 'ns1.example.net.']]));
        self::assertFalse(Fixtures::service($http)->updateNameservers(self::domain(), self::ns()));
        self::assertCount(1, $http->requests);
    }

    public function testChangedNameserversAreSentWithEnvironment(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, Fixtures::info())->envelope(200, ['success' => true]);
        self::assertTrue(Fixtures::service($http)->updateNameservers(self::domain(), Nameservers::fromList(['a.dns.example', 'b.dns.example'])));
        self::assertSame(['nameservers' => ['a.dns.example', 'b.dns.example'], 'replaceAll' => true, 'environment' => 'prod'], $http->last()->json);
        self::assertSame('PUT', $http->last()->method);
    }

    public function testContactUpdateSendsAllFourRolesWithoutExternalId(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['contactsUpdated' => 4]);
        Fixtures::service($http)->updateContact(self::domain(), Fixtures::contact());
        $json = $http->last()->json;
        self::assertNotNull($json);
        self::assertSame(['registrant', 'admin', 'tech', 'billing'], array_keys($json));
        self::assertIsArray($json['registrant']);
        self::assertArrayNotHasKey('externalId', $json['registrant']);
    }

    public function testAuthCodeAndMissingAuthCode(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['domain' => 'example.com', 'authCode' => 'Xy9#abc'])->envelope(200, ['domain' => 'example.com']);
        $service = Fixtures::service($http);
        self::assertSame('Xy9#abc', $service->authCode(self::domain()));
        $this->expectException(RuleException::class);
        $service->authCode(self::domain());
    }

    public function testLockAndPrivacySendEmptyJsonObjects(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['locked' => true])->envelope(200, ['locked' => false])->envelope(200, ['privacy' => false]);
        $service = Fixtures::service($http, Fixtures::settings(Environment::Sandbox));
        $service->setTransferLock(self::domain(), true);
        self::assertSame('/v2/domains/example.com/lock', $http->requests[0]->path());
        self::assertSame(['environment' => 'ote1'], $http->requests[0]->query());
        self::assertSame('{}', $http->requests[0]->options['body']);
        $service->setTransferLock(self::domain(), false);
        self::assertSame('/v2/domains/example.com/unlock', $http->requests[1]->path());
        $service->setPrivacy(self::domain(), false);
        self::assertSame('/v2/domains/example.com/privacy/disable', $http->requests[2]->path());
    }

    public function testBalanceConvertsMajorUnitsToCents(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['success' => true, 'data' => ['balance' => 12.34, 'currency' => 'USD'], 'error' => null]);
        self::assertSame(1234, Fixtures::service($http)->balanceCents());
    }
}
