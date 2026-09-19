<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exercises Registrar_Adapter_Osir exactly as FOSSBilling does, with FOSSBilling's real
 * Registrar_Domain / Registrar_Domain_Contact / Registrar_Exception classes.
 */
final class AdapterTest extends TestCase
{
    private ScriptedHttpClient $http;
    private CapturingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new ScriptedHttpClient();
        $this->logger = new CapturingLogger();
    }

    /** @param array<string, mixed> $config */
    private function adapter(array $config = ['api_key' => Fixtures::LIVE_KEY, 'api_key_test' => Fixtures::TEST_KEY], bool $testMode = false, bool $cron = false): \Registrar_Adapter_Osir
    {
        $http = $this->http->client;
        $adapter = new class ($config, $http, $cron) extends \Registrar_Adapter_Osir {
            /** @param array<string, mixed> $config */
            public function __construct(array $config, private readonly HttpClientInterface $mock, private readonly bool $cron)
            {
                parent::__construct($config);
            }

            public function getHttpClient(): HttpClientInterface
            {
                return $this->mock;
            }

            protected function isCronContext(): bool
            {
                return $this->cron;
            }
        };
        $adapter->setLog($this->boxLog());
        if ($testMode) {
            $adapter->enableTestMode();
        }

        return $adapter;
    }

    private function boxLog(): \Box_Log
    {
        $logger = $this->logger;
        $writer = new class ('capture', $logger) extends \Box_LogDb {
            public function __construct(string $service, private readonly CapturingLogger $sink)
            {
                parent::__construct($service);
            }

            /** @param array<array-key, mixed> $event */
            public function write(array $event, string $channel = 'application'): void
            {
                $message = $event['message'] ?? '';
                $this->sink->info(is_string($message) ? $message : '');
            }
        };
        $log = new \Box_Log();
        $log->addWriter($writer);

        return $log;
    }

    private static function domain(): \Registrar_Domain
    {
        // FOSSBilling's setters are untyped, so no fluent chaining (keeps static analysis exact).
        $contact = new \Registrar_Domain_Contact();
        $contact->setFirstName('Ada');
        $contact->setLastName('Lovelace');
        $contact->setEmail('ada@example.org');
        $contact->setTelCc('44');
        $contact->setTel('2079460000');
        $contact->setAddress1('1 Engine Street');
        $contact->setCity('London');
        $contact->setZip('N1 9GU');
        $contact->setCountry('GB');
        $contact->setCompany('');

        $d = new \Registrar_Domain();
        $d->setSld('example');
        $d->setTld('.com');
        $d->setRegistrationPeriod(1);
        $d->setNs1('ns1.example.net');
        $d->setNs2('ns2.example.net');
        $d->setNs3('');
        $d->setNs4(null);
        $d->setContactRegistrar($contact);
        $d->setEpp('incoming-transfer-code');

        return $d;
    }

    public function testConfigDeclaresSecretKeyFields(): void
    {
        $config = \Registrar_Adapter_Osir::getConfig();
        self::assertTrue($config['form']['api_key'][1]['secret']);
        self::assertTrue($config['form']['api_key_test'][1]['secret']);
        self::assertSame('password', $config['form']['api_key'][0]);
        self::assertSame(['api_key', 'api_key_test'], \Registrar_Adapter_Osir::getSecretFields());
        self::assertArrayNotHasKey('api_url', $config['form'], 'the API URL must not be admin-configurable');
    }

    public function testConstructionNeverThrowsAndNeverCallsOut(): void
    {
        self::assertInstanceOf(\Registrar_AdapterAbstract::class, new \Registrar_Adapter_Osir([]));
        self::assertInstanceOf(\Registrar_AdapterAbstract::class, new \Registrar_Adapter_Osir(null));
        self::assertCount(0, $this->http->requests);
    }

    public function testMissingKeyIsExplainedInTheLogOnly(): void
    {
        try {
            $this->adapter([])->isDomainAvailable(self::domain());
            self::fail('Expected Registrar_Exception');
        } catch (\Registrar_Exception $e) {
            // Availability checks run for anonymous visitors: no configuration details for them.
            self::assertStringContainsString('not configured correctly', $e->getMessage());
            self::assertStringNotContainsString('osir_live', $e->getMessage());
        }
        self::assertStringContainsString('No live OSIR API key', $this->logger->all());
    }

    public function testAvailability(): void
    {
        $this->http->json(200, ['available' => true, 'premium' => false])->json(200, ['available' => false, 'reason' => 'In use']);
        $adapter = $this->adapter();
        self::assertTrue($adapter->isDomainAvailable(self::domain()));
        self::assertFalse($adapter->isDomainAvailable(self::domain()));
    }

    public function testPremiumIsRefusedAtCheckout(): void
    {
        $this->http->json(200, ['available' => true, 'premium' => true]);
        $this->expectException(\Registrar_Exception::class);
        $this->expectExceptionMessage('premium');
        $this->adapter()->isDomainAvailable(self::domain());
    }

    public function testTestModeUsesSandboxKeyAndOte(): void
    {
        $this->http->envelope(200, ['locked' => true]);
        $this->adapter(testMode: true)->lock(self::domain());
        self::assertSame(Fixtures::TEST_KEY, $this->http->last()->headers['x-api-key']);
        self::assertSame(['environment' => 'ote1'], $this->http->last()->query());
    }

    public function testRegisterUsesOrderIdForIdempotency(): void
    {
        $this->http->json(200, ['available' => true, 'premium' => false])->envelope(201, ['status' => 'COMPLETED']);
        $adapter = $this->adapter();
        // Built the way FOSSBilling's RedBean layer builds it: a model wrapping a dispensed bean.
        $bean = new \RedBeanPHP\OODBBean();
        $bean->initializeForDispense('client_order');
        $bean->setProperty('id', 314);
        $bean->setProperty('created_at', date('Y-m-d H:i:s'));
        $order = new \Model_ClientOrder();
        $order->loadBean($bean);
        $adapter->setOrder($order);

        self::assertTrue($adapter->registerDomain(self::domain()));
        self::assertMatchesRegularExpression('/^fb:[0-9a-f]{12}:live:o314:register:example\\.com:1y$/', $this->http->last()->headers['idempotency-key']);
    }

    public function testDetailsUpdatesDomainAndNeverCarriesAnAuthCode(): void
    {
        $this->http->envelope(200, Fixtures::info(['nameservers' => ['ns1.example.net', 'ns2.example.net', 'ns3.example.net']]));
        $domain = self::domain();
        $contactBefore = $domain->getContactRegistrar();

        $result = $this->adapter()->getDomainDetails($domain);

        self::assertSame(gmmktime(10, 11, 12, 9, 19, 2027), $result->getExpirationTime());
        self::assertSame(gmmktime(10, 11, 12, 9, 19, 2025), $result->getRegistrationTime());
        self::assertSame('ns3.example.net', $result->getNs3());
        self::assertNull($result->getNs4());
        self::assertTrue($result->getLocked());
        self::assertTrue($result->getPrivacyEnabled());
        self::assertNull($result->getEpp(), 'FOSSBilling serialises this object; no auth code may ride along');
        self::assertSame($contactBefore, $result->getContactRegistrar(), 'FOSSBilling\'s contact is authoritative');
        self::assertStringNotContainsString('incoming-transfer-code', serialize($result));
    }

    /**
     * FOSSBilling's batch expiry sync only records success when EVERY domain synced; one gone
     * domain would make it re-sync all domains on every cron run (web or CLI).
     */
    public function testGoneDomainKeepsFossbillingDataAndLogsAnError(): void
    {
        $this->http->envelope(200, Fixtures::info(['status' => 'transferredOut']))
            ->json(404, ['error' => 'nf', 'status' => 404])->json(404, ['errorCode' => 'TRANSFER_NOT_FOUND']);
        $adapter = $this->adapter();

        $domain = self::domain();
        self::assertSame($domain, $adapter->getDomainDetails($domain));
        self::assertNull($domain->getEpp(), 'no transfer code in what FOSSBilling serialises');
        self::assertStringContainsString('transferred to another registrar', $this->logger->all());

        $unknown = self::domain();
        self::assertSame($unknown, $adapter->getDomainDetails($unknown));
        self::assertStringContainsString('not registered in this OSIR account', $this->logger->all());
    }

    /** Regression (orders without an expiry): no sync may hide a lost renewal. */
    public function testSyncDoesNotCopyTheExpiryWhileARenewalIsUnresolved(): void
    {
        $this->http->envelope(200, Fixtures::info(['expiryDate' => '2028-09-19T00:00:00']))
            ->envelope(200, Fixtures::info(['expiryDate' => '2028-09-19T00:00:00']));
        $known = gmmktime(0, 0, 0, 9, 19, 2027);

        $adapter = $this->adapter();
        $adapter->setOrder(self::order('failed_renew'));
        $domain = self::domain();
        $domain->setExpirationTime($known);
        self::assertSame($known, $adapter->getDomainDetails($domain)->getExpirationTime());
        self::assertStringContainsString('renewal of order #314 is unresolved', $this->logger->all());

        $active = $this->adapter();
        $active->setOrder(self::order('active'));
        $domain = self::domain();
        $domain->setExpirationTime($known);
        self::assertSame(gmmktime(0, 0, 0, 9, 19, 2028), $active->getDomainDetails($domain)->getExpirationTime());
    }

    private static function order(string $status): \Model_ClientOrder
    {
        $bean = new \RedBeanPHP\OODBBean();
        $bean->initializeForDispense('client_order');
        $bean->setProperty('id', 314);
        $bean->setProperty('status', $status);
        $order = new \Model_ClientOrder();
        $order->loadBean($bean);

        return $order;
    }

    public function testAccountProblemsAreNotRevealedToClients(): void
    {
        $this->http->json(200, ['available' => true, 'premium' => false])
            ->json(402, ['success' => false, 'error' => 'Insufficient funds. Required: 1250 cents, Available: 50 cents (customer: fa4c4448)', 'errorCode' => 'INSUFFICIENT_FUNDS']);
        try {
            $this->adapter()->registerDomain(self::domain());
            self::fail('Expected Registrar_Exception');
        } catch (\Registrar_Exception $e) {
            self::assertStringNotContainsString('cents', $e->getMessage());
            self::assertStringNotContainsString('fa4c4448', $e->getMessage());
            self::assertMatchesRegularExpression('/Reference: [0-9a-f-]{36}/', $e->getMessage());
        }
        // ...but the administrator log has the details.
        self::assertStringContainsString('insufficient_funds', $this->logger->all());
        self::assertStringContainsString('Available: 50 cents', $this->logger->all());
    }

    public function testUnexpectedErrorsDoNotLeakInternals(): void
    {
        $this->http->respond(static fn(): never => throw new \RuntimeException('/var/www/secret/path SQLSTATE[42S02]'));
        try {
            $this->adapter()->lock(self::domain());
            self::fail('Expected Registrar_Exception');
        } catch (\Registrar_Exception $e) {
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
            self::assertStringNotContainsString('/var/www', $e->getMessage());
        }
    }

    public function testDeleteNeverCallsOsirAndWarnsAdmin(): void
    {
        self::assertTrue($this->adapter()->deleteDomain(self::domain()));
        self::assertCount(0, $this->http->requests);
        self::assertStringContainsString('does not delete domains through the API', $this->logger->all());
    }

    public function testRenewWithoutExpiryAsksForSync(): void
    {
        $this->http->envelope(200, Fixtures::info());
        $this->expectException(\Registrar_Exception::class);
        $this->expectExceptionMessage('Synchronise the domain first');
        $this->adapter()->renewDomain(self::domain());
    }

    public function testInvalidDomainFromFossbillingIsRejectedLocally(): void
    {
        $d = self::domain();
        $d->setSld('../../admin');
        $this->expectException(\Registrar_Exception::class);
        try {
            $this->adapter()->getEpp($d);
        } finally {
            self::assertCount(0, $this->http->requests);
        }
    }

    public function testRawKeyIsDroppedFromTheAdapterOnceResolved(): void
    {
        $this->http->envelope(200, ['locked' => true]);
        $adapter = $this->adapter();
        $adapter->lock(self::domain());
        // Inspect the adapter's own state (a full print_r would also walk into the test's mock
        // HTTP client, which records request headers for assertions).
        $options = (new \ReflectionProperty(\Registrar_Adapter_Osir::class, 'options'))->getValue($adapter);
        self::assertIsArray($options);
        self::assertArrayNotHasKey('api_key', $options);
        self::assertArrayNotHasKey('api_key_test', $options);
        self::assertStringNotContainsString('AbCdEfGhIjKl', print_r($options, true));
    }

    public function testKeyNeverAppearsInLogs(): void
    {
        $this->http->json(401, null);
        try {
            $this->adapter(['api_key' => Fixtures::LIVE_KEY, 'debug_logging' => '1'])->lock(self::domain());
        } catch (\Registrar_Exception) {
        }
        self::assertNotSame('', $this->logger->all());
        self::assertStringNotContainsString('AbCdEfGhIjKl', $this->logger->all());
    }
}
