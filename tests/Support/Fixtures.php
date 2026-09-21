<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Support;

use Osir\FossBilling\Config\Environment;
use Osir\FossBilling\Config\Secret;
use Osir\FossBilling\Config\Settings;
use Osir\FossBilling\Http\ApiClient;
use Osir\FossBilling\Http\RetryPolicy;
use Osir\FossBilling\Mapping\ContactData;
use Osir\FossBilling\Service\OrderRef;
use Osir\FossBilling\Service\RegistrarService;
use Osir\FossBilling\Support\SafeLogger;

final class Fixtures
{
    public const string LIVE_KEY = 'osir_live_AbCdEfGhIjKlMnOpQrStUvWxYz012345';
    /** A key of the old sandbox kind: never accepted, used to test refusals and log redaction. */
    public const string TEST_KEY = 'osir_test_AbCdEfGhIjKlMnOpQrStUvWxYz012345';
    public const int NOW = 1789812000; // 2026-09-19T10:00:00Z

    public static function settings(Environment $env = Environment::Live, ?int $maxYearlyCostCents = null, bool $dnsZone = false, bool $allowCheaperPremium = false): Settings
    {
        return new Settings(
            environment: $env,
            apiKey: new Secret(self::LIVE_KEY),
            baseUrl: 'https://be.osir.com',
            caFile: null,
            installationId: 'abc123def456',
            maxYearlyCostCents: $maxYearlyCostCents,
            initializeDnsZone: $dnsZone,
            allowCheaperPremium: $allowCheaperPremium,
            debug: true,
            source: 'settings',
        );
    }

    /**
     * @param list<float>              $sleeps receives every requested sleep instead of sleeping
     * @param (\Closure(): float)|null $clock  fake monotonic clock; defaults to one that only advances by the sleeps
     */
    public static function apiClient(ScriptedHttpClient $http, ?Settings $settings = null, ?CapturingLogger $logger = null, ?RetryPolicy $retry = null, array &$sleeps = [], ?\Closure $clock = null): ApiClient
    {
        $now = 0.0;

        return new ApiClient(
            $http->client,
            $settings ?? self::settings(),
            new SafeLogger($logger ?? new CapturingLogger(), true),
            $retry ?? new RetryPolicy(3, 90.0, 0.5),
            static function (float $s) use (&$sleeps, &$now): void {
                $sleeps[] = $s;
                $now += $s;
            },
            $clock ?? static function () use (&$now): float {
                return $now;
            },
        );
    }

    public static function order(string $id = '42', ?int $createdAt = self::NOW - 600, ?int $expiresAt = self::NOW + 30 * 86400, ?int $priceMinorUnits = null, ?string $currency = 'USD'): OrderRef
    {
        return new OrderRef($id, $createdAt, $expiresAt, null, $priceMinorUnits, $currency);
    }

    public static function service(ScriptedHttpClient $http, ?Settings $settings = null, ?CapturingLogger $logger = null): RegistrarService
    {
        $settings ??= self::settings();
        $logger ??= new CapturingLogger();

        return new RegistrarService(self::apiClient($http, $settings, $logger), $settings, new SafeLogger($logger, true));
    }

    /** @param array<string, string|null> $overrides */
    public static function contact(array $overrides = []): ContactData
    {
        $v = array_merge([
            'firstName' => 'Ada', 'lastName' => 'Lovelace', 'organization' => 'Analytical Ltd', 'email' => 'ada@example.org',
            'phoneCountryCode' => '44', 'phone' => '20 7946 0000', 'street1' => '1 Engine Street', 'street2' => null,
            'city' => 'London', 'state' => null, 'postalCode' => 'N1 9GU', 'country' => 'gb',
        ], $overrides);

        return new ContactData(
            $v['firstName'],
            $v['lastName'],
            $v['organization'],
            $v['email'],
            $v['phoneCountryCode'],
            $v['phone'],
            $v['street1'],
            $v['street2'],
            $v['city'],
            $v['state'],
            $v['postalCode'],
            $v['country'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed> OSIR info payload
     */
    public static function info(array $overrides = []): array
    {
        /** @var array<string, mixed> */
        return array_merge([
            'domain' => 'example.com',
            'status' => 'active',
            'statuses' => ['ok'],
            'expiryDate' => '2027-09-19T10:11:12',
            'creationDate' => '2025-09-19T10:11:12',
            'nameservers' => ['ns1.example.net', 'ns2.example.net'],
            'locked' => true,
            'expired' => false,
            'privacy' => true,
            'registrar' => 'osir',
        ], $overrides);
    }
}
