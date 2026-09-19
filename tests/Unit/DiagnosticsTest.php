<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Service\Diagnostics;
use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    /**
     * @param list<array{check: string, status: 'ok'|'warn'|'fail', detail: string}> $results
     *
     * @return array<string, array{check: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    private static function byCheck(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r['check']] = $r;
        }

        return $out;
    }

    public function testHealthyInstallWithDomainLookup(): void
    {
        $http = (new ScriptedHttpClient())
            ->json(200, ['success' => true, 'data' => ['balance' => 42.5, 'currency' => 'USD'], 'error' => null])
            ->json(200, ['available' => false, 'reason' => 'In use'])
            ->envelope(200, Fixtures::info());
        $settings = Fixtures::settings(maxYearlyCostCents: 2000);
        $results = self::byCheck((new Diagnostics(Fixtures::service($http, $settings), $settings))->run(DomainName::fromString('example.com')));

        self::assertSame('ok', $results['Connection, TLS and API key']['status']);
        self::assertSame('42.50 USD', $results['Account balance']['detail']);
        self::assertSame('registered', $results['Availability of example.com']['detail']);
        self::assertStringContainsString('expires 2027-09-19', $results['In this account']['detail']);
        self::assertSame('warn', $results['API key']['status'], 'a key stored in the database is flagged');
        self::assertStringNotContainsString('AbCdEfGhIjKl', json_encode($results, JSON_THROW_ON_ERROR), 'only the key prefix is shown');
        self::assertCount(3, $http->requests, 'read-only: balance, availability, info');
        foreach ($http->requests as $r) {
            self::assertSame('GET', $r->method);
        }
    }

    public function testRejectedKeyIsAFailureAndStopsFurtherChecks(): void
    {
        $http = (new ScriptedHttpClient())->json(401, null);
        $results = self::byCheck((new Diagnostics(Fixtures::service($http), Fixtures::settings()))->run(DomainName::fromString('example.com')));

        self::assertSame('fail', $results['Connection, TLS and API key']['status']);
        self::assertStringContainsString('authentication (HTTP 401)', $results['Connection, TLS and API key']['detail']);
        self::assertArrayNotHasKey('Availability of example.com', $results);
        self::assertCount(1, $http->requests);
    }

    public function testDoesNotLogTheKey(): void
    {
        $logger = new CapturingLogger();
        $http = (new ScriptedHttpClient())->json(200, ['success' => true, 'data' => ['balance' => 1], 'error' => null]);
        (new Diagnostics(Fixtures::service($http, logger: $logger), Fixtures::settings()))->run();
        self::assertStringNotContainsString('AbCdEfGhIjKl', $logger->all());
    }
}
