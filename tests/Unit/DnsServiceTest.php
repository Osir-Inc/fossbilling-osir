<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Config\Environment;
use Osir\FossBilling\Dns\DnsRecord;
use Osir\FossBilling\Dns\DnsService;
use Osir\FossBilling\Dns\RecordType;
use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Service\IdempotencyKeys;
use Osir\FossBilling\Support\SafeLogger;
use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class DnsServiceTest extends TestCase
{
    private static function dns(ScriptedHttpClient $http, ?CapturingLogger $logger = null): DnsService
    {
        $logger ??= new CapturingLogger();

        return new DnsService(Fixtures::apiClient($http, logger: $logger), new SafeLogger($logger, true));
    }

    private static function domain(string $name = 'example.com'): DomainName
    {
        return DomainName::fromString($name);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge(['id' => 'rec-1', 'name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => 3600, 'disabled' => false], $overrides);
    }

    // ------------------------------------------------------------------ reading

    public function testRecordsReadsTheV2Endpoint(): void
    {
        $http = (new ScriptedHttpClient())->json(200, [self::row()]);

        $records = self::dns($http)->records(self::domain());

        self::assertCount(1, $records);
        self::assertSame('www.example.com', $records[0]->name);
        self::assertSame(RecordType::A, $records[0]->type);
        self::assertSame('rec-1', $records[0]->id);
        self::assertSame('GET', $http->requests[0]->method);
        self::assertStringEndsWith('/v2/dns/domains/example.com/records', $http->requests[0]->url);
    }

    /**
     * OSIR publishes no response schema for these endpoints, so the listing accepts a bare list
     * and the wrapped shapes alike.
     *
     * @param array<array-key, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('listShapes')]
    public function testRecordsAcceptsEveryShapeOsirMayAnswerWith(array $body): void
    {
        $http = (new ScriptedHttpClient())->json(200, $body);

        $records = self::dns($http)->records(self::domain());

        self::assertCount(1, $records);
        self::assertSame('203.0.113.7', $records[0]->content);
    }

    /** @return array<string, array{0: array<array-key, mixed>}> */
    public static function listShapes(): array
    {
        return [
            'bare list' => [[self::row()]],
            'records key' => [['records' => [self::row()]]],
            'envelope' => [['success' => true, 'data' => [self::row()]]],
            'envelope with records' => [['success' => true, 'data' => ['records' => [self::row()]]]],
        ];
    }

    public function testRecordsSkipsRowsItCannotRead(): void
    {
        $http = (new ScriptedHttpClient())->json(200, [self::row(['type' => 'WEIRD']), self::row(['name' => '']), self::row()]);

        self::assertCount(1, self::dns($http)->records(self::domain()));
    }

    public function testStatusReportsNameserverUse(): void
    {
        $http = (new ScriptedHttpClient())->json(200, [
            'domain' => 'example.com',
            'zoneExists' => true,
            'usesOurNameservers' => false,
            'nameservers' => ['ns1.elsewhere.net.', 'ns2.elsewhere.net'],
            'ourNameservers' => ['ns1.osir.com', 'ns3.osir.com'],
        ]);

        $status = self::dns($http)->status(self::domain());

        self::assertTrue($status->zoneExists);
        self::assertFalse($status->usesOurNameservers);
        self::assertSame(['ns1.elsewhere.net', 'ns2.elsewhere.net'], $status->nameservers);
        self::assertSame(['ns1.osir.com', 'ns3.osir.com'], $status->ourNameservers);
        self::assertStringEndsWith('/v2/dns/domains/example.com/status', $http->requests[0]->url);
    }

    public function testStatusReadsTheEnvelopeShapeToo(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['success' => true, 'data' => ['zoneExists' => true, 'usesOurNameservers' => true, 'nameservers' => ['ns1.osir.com'], 'ourNameservers' => ['ns1.osir.com']]]);

        $status = self::dns($http)->status(self::domain());

        self::assertTrue($status->zoneExists);
        self::assertTrue($status->usesOurNameservers);
        self::assertSame(['ns1.osir.com'], $status->ourNameservers);
    }

    public function testADomainOutsideTheAccountIsExplainedNotShownAsHttp403(): void
    {
        $http = (new ScriptedHttpClient())->json(403, ['error' => 'Domain not owned: example.com']);

        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('does not manage DNS for example.com');
        self::dns($http)->records(self::domain());
    }

    // ------------------------------------------------------------------ writing

    public function testCreateSendsTheRecordAndItsIdempotencyKey(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['id' => 'rec-9']);
        $record = DnsRecord::draft(self::domain(), ['name' => 'www', 'type' => 'a', 'content' => '203.0.113.7', 'ttl' => '600']);

        self::dns($http)->create(self::domain(), $record, 'fb:inst:live:o42:dns-add:example.com:abcdef');

        $request = $http->requests[0];
        self::assertSame('POST', $request->method);
        self::assertStringEndsWith('/v2/dns/domains/example.com/records', $request->url);
        self::assertSame(['name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => 600], $request->json);
        self::assertSame('fb:inst:live:o42:dns-add:example.com:abcdef', $request->headers['idempotency-key'] ?? null);
    }

    public function testUpdateAndDeleteReadTheRecordFirstThenAddressItById(): void
    {
        // Each write reads the record the id points at, so the apex cannot be hit through an id.
        $http = (new ScriptedHttpClient())
            ->json(200, self::row(['name' => 'old.example.com', 'type' => 'TXT', 'content' => 'v=spf1 -all']))
            ->json(200, [])
            ->json(200, self::row())
            ->json(200, []);
        $dns = self::dns($http);
        $record = DnsRecord::draft(self::domain(), ['name' => 'txt', 'type' => 'TXT', 'content' => 'v=spf1 -all']);

        $dns->update(self::domain(), 'rec-1', $record);
        $dns->delete(self::domain(), 'rec-1');

        self::assertSame(['GET', 'PUT', 'GET', 'DELETE'], array_map(static fn($r): string => $r->method, $http->requests));
        foreach ($http->requests as $request) {
            self::assertStringEndsWith('/v2/dns/domains/example.com/records/rec-1', $request->url);
        }
    }

    public function testTheApexCannotBeDeletedThroughItsRecordId(): void
    {
        // The id of the SOA row is in the page's own HTML; deleting it would stop the zone resolving.
        $http = (new ScriptedHttpClient())->json(200, self::row(['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.osir.com. a.b. 1 2 3 4 5']));

        try {
            self::dns($http)->delete(self::domain(), 'rec-soa');
            self::fail('Expected the SOA record to be protected');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(['GET'], array_map(static fn($r): string => $r->method, $http->requests), 'no DELETE may be sent');
    }

    public function testAnApexNameserverCannotBeOverwrittenWithAnInnocentRecord(): void
    {
        $http = (new ScriptedHttpClient())->json(200, self::row(['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.osir.com']));
        $record = DnsRecord::draft(self::domain(), ['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7']);

        try {
            self::dns($http)->update(self::domain(), 'rec-ns', $record);
            self::fail('Expected the apex nameserver record to be protected');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(['GET'], array_map(static fn($r): string => $r->method, $http->requests), 'no PUT may be sent');
    }

    public function testACnameOnTheDomainItselfIsRefused(): void
    {
        $http = new ScriptedHttpClient();

        $this->expectException(ValidationException::class);
        self::dns($http)->create(self::domain(), DnsRecord::draft(self::domain(), ['name' => '@', 'type' => 'CNAME', 'content' => 'elsewhere.example.net']));
    }

    public function testDeleteOfAStaleRecordAsksTheClientToReload(): void
    {
        $http = (new ScriptedHttpClient())->json(404, ['error' => 'Record not found']);

        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('no longer exists');
        self::dns($http)->delete(self::domain(), 'rec-1');
    }

    public function testAnswerInAShapeWeCannotReadIsNeverShownAsAnEmptyZone(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['success' => true, 'data' => ['items' => [self::row()]]]);

        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('could not be read');
        self::dns($http)->records(self::domain());
    }

    public function testTheZoneApexIsNotClientEditable(): void
    {
        $http = new ScriptedHttpClient();
        $dns = self::dns($http);

        foreach ([['type' => 'SOA', 'name' => '@', 'content' => 'ns1.osir.com. admin.osir.com. 1 2 3 4 5'], ['type' => 'NS', 'name' => '@', 'content' => 'ns1.attacker.test']] as $input) {
            try {
                $dns->create(self::domain(), DnsRecord::draft(self::domain(), $input));
                self::fail('Expected the apex record to be refused');
            } catch (ValidationException) {
                // expected
            }
        }

        self::assertSame([], $http->requests, 'nothing may be sent for a refused record');
    }

    public function testNsRecordsBelowTheApexAreAllowed(): void
    {
        $http = (new ScriptedHttpClient())->json(200, []);

        self::dns($http)->create(self::domain(), DnsRecord::draft(self::domain(), ['name' => 'sub', 'type' => 'NS', 'content' => 'ns1.elsewhere.net']));

        self::assertCount(1, $http->requests);
    }

    // ------------------------------------------------------------------ record validation

    public function testDraftQualifiesNamesAgainstTheZone(): void
    {
        $zone = self::domain();

        self::assertSame('example.com', DnsRecord::draft($zone, ['name' => '@', 'type' => 'A', 'content' => '203.0.113.7'])->name);
        self::assertSame('www.example.com', DnsRecord::draft($zone, ['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7'])->name);
        self::assertSame('www.example.com', DnsRecord::draft($zone, ['name' => 'www.example.com.', 'type' => 'A', 'content' => '203.0.113.7'])->name);
        self::assertSame('*.example.com', DnsRecord::draft($zone, ['name' => '*', 'type' => 'A', 'content' => '203.0.113.7'])->name);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRecords')]
    public function testDraftRefusesRecordsOsirWouldReject(array $input): void
    {
        $this->expectException(ValidationException::class);
        DnsRecord::draft(self::domain(), $input);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badRecords(): array
    {
        return [
            'unknown type' => [['name' => 'www', 'type' => 'XYZ', 'content' => 'x']],
            'empty value' => [['name' => 'www', 'type' => 'A', 'content' => '  ']],
            'ttl below the minimum' => [['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => '5']],
            'ttl above a week' => [['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => '999999']],
            'ttl not a number' => [['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => 'soon']],
            'mx without priority' => [['name' => '@', 'type' => 'MX', 'content' => 'mail.example.com']],
            'srv without port' => [['name' => '_sip._tcp', 'type' => 'SRV', 'content' => 'sip.example.com', 'priority' => '10', 'weight' => '0']],
            'newline in the value' => [['name' => 'www', 'type' => 'TXT', 'content' => "a\nb"]],
            'A record with a host name' => [['name' => 'www', 'type' => 'A', 'content' => 'example.net']],
            'A record with an IPv6 address' => [['name' => 'www', 'type' => 'A', 'content' => '2001:db8::1']],
            'AAAA record with an IPv4 address' => [['name' => 'www', 'type' => 'AAAA', 'content' => '203.0.113.7']],
            'CNAME pointing at an IP' => [['name' => 'www', 'type' => 'CNAME', 'content' => '203.0.113.7']],
            'MX with a single label' => [['name' => '@', 'type' => 'MX', 'content' => 'mail', 'priority' => '10']],
        ];
    }

    public function testValuesThatFitTheirTypeAreAccepted(): void
    {
        $zone = self::domain();

        foreach ([
            ['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7'],
            ['name' => 'www', 'type' => 'AAAA', 'content' => '2001:db8::1'],
            ['name' => 'www', 'type' => 'CNAME', 'content' => 'target.example.net.'],
            ['name' => '@', 'type' => 'MX', 'content' => 'mail.example.net', 'priority' => '10'],
            ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 include:example.net -all'],
            ['name' => '@', 'type' => 'CAA', 'content' => '0 issue "letsencrypt.org"'],
        ] as $input) {
            self::assertSame($input['content'], DnsRecord::draft($zone, $input)->content, 'the value is kept as typed');
        }
    }

    public function testServiceFieldsOnlyTravelWithSrv(): void
    {
        $zone = self::domain();

        $srv = DnsRecord::draft($zone, ['name' => '_sip._tcp', 'type' => 'SRV', 'content' => 'sip.example.com', 'priority' => '10', 'weight' => '5', 'port' => '5060']);
        self::assertSame(['name' => '_sip._tcp.example.com', 'type' => 'SRV', 'content' => 'sip.example.com', 'priority' => 10, 'weight' => 5, 'port' => 5060], $srv->payload());

        // Weight and port sent for an A record would be ignored by OSIR; they are dropped here.
        $a = DnsRecord::draft($zone, ['name' => 'www', 'type' => 'A', 'content' => '203.0.113.7', 'weight' => '5', 'port' => '80', 'priority' => '10']);
        self::assertSame(['name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.7'], $a->payload());
    }

    /**
     * A key that depended on the record would be reused when a client deletes a record and adds it
     * back: OSIR would replay the first answer for 30 days and the record would never reappear.
     */
    public function testEverySubmissionGetsItsOwnIdempotencyKey(): void
    {
        $keys = [];
        for ($i = 0; $i < 3; ++$i) {
            $keys[] = IdempotencyKeys::dnsRecord('abc123abc123', Environment::Live, Fixtures::order(), self::domain(), bin2hex(random_bytes(6)));
        }

        self::assertCount(3, array_unique($keys));
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('#^fb:abc123abc123:live:o42:dns-add:example\.com:[0-9a-f]{12}$#', $key);
        }
    }

    /**
     * Record ids are opaque tokens from OSIR's listing; anything else never becomes a request.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRecordIds')]
    public function testOnlyOsirsOwnRecordIdShapeIsAccepted(string $recordId): void
    {
        $http = new ScriptedHttpClient();

        try {
            self::dns($http)->delete(self::domain(), $recordId);
            self::fail('Expected the record id to be refused');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame([], $http->requests);
    }

    /** @return array<string, array{0: string}> */
    public static function badRecordIds(): array
    {
        return [
            'empty' => ['   '],
            'path traversal' => ['a/../../../v2/domains/other.com'],
            'encoded slash' => ['a%2Fb'],
            'query injection' => ['rec?customer=other'],
            'too long' => [str_repeat('a', 129)],
        ];
    }
}
