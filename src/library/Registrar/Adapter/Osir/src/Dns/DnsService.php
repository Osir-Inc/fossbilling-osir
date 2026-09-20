<?php

declare(strict_types=1);

namespace Osir\FossBilling\Dns;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Http\ApiClient;
use Osir\FossBilling\Http\ApiRequest;
use Osir\FossBilling\Http\ApiResponse;
use Osir\FossBilling\Support\SafeLogger;

/**
 * DNS record use cases against OSIR, used by the client area (modules/Osir).
 *
 * Knows nothing about FOSSBilling: the module resolves which domain the signed-in client may
 * touch and passes it in. OSIR checks ownership again on every call and answers 403 for a domain
 * the API key's customer does not hold, so a mistake here cannot reach another reseller's zone.
 *
 * What this class is responsible for:
 *   - refusing writes to the zone apex (SOA always, NS at the apex), which OSIR serves;
 *   - validating records locally, so bad input never becomes a 400;
 *   - carrying an Idempotency-Key on creation, so a retry after a timeout cannot add the record
 *     twice (OSIR fingerprints the key by domain + name + type + content).
 * No zone bootstrap is needed: creating a record creates the zone with OSIR's defaults.
 */
final class DnsService
{
    private const string P_RECORDS = '/v2/dns/domains/{domain}/records';
    private const string P_RECORD = '/v2/dns/domains/{domain}/records/{recordId}';
    private const string P_STATUS = '/v2/dns/domains/{domain}/status';

    public function __construct(
        private readonly ApiClient $api,
        private readonly SafeLogger $log,
    ) {}

    /**
     * Every record in the zone, as OSIR holds it.
     *
     * @return list<DnsRecord>
     */
    public function records(DomainName $domain): array
    {
        $response = $this->get(self::P_RECORDS, $domain);

        $records = [];
        foreach (self::rows($response->data, $domain) as $row) {
            $record = DnsRecord::fromApi($row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /** Whether the zone exists and the domain points at OSIR's nameservers. */
    public function status(DomainName $domain): ZoneStatus
    {
        return ZoneStatus::fromApi($this->get(self::P_STATUS, $domain)->data);
    }

    /**
     * A read, with OSIR's ownership refusal turned into something a client can act on: a 403 here
     * means the domain is not in the OSIR account this installation uses (it was transferred away,
     * or the order was imported without the domain).
     *
     * @throws RuleException
     */
    private function get(string $template, DomainName $domain): ApiResponse
    {
        try {
            return $this->api->send(ApiRequest::get(ApiRequest::path($template, $domain))->unwrapped());
        } catch (ApiException $e) {
            throw $this->ownershipOrOriginal($e, $domain);
        }
    }

    /** @return ApiException|RuleException */
    private function ownershipOrOriginal(ApiException $e, DomainName $domain): \RuntimeException
    {
        if ($e->getKind() === ApiErrorKind::Permission) {
            return new RuleException('OSIR does not manage DNS for :domain under this account.', [':domain' => $domain->unicode()], 0, $e);
        }

        return $e;
    }

    /**
     * Adds a record. $idempotencyKey covers ONE submission, so a retry after a timeout cannot add
     * the record twice; it must differ between submissions, or OSIR would replay the first answer
     * for up to 30 days and a re-added record would silently never appear.
     *
     * @throws ValidationException the record is not one a client may write
     */
    public function create(DomainName $domain, DnsRecord $record, ?string $idempotencyKey = null): void
    {
        $this->assertClientEditable($domain, $record);

        try {
            $this->api->send(ApiRequest::post(ApiRequest::path(self::P_RECORDS, $domain), $record->payload(), $idempotencyKey)->unwrapped());
        } catch (ApiException $e) {
            throw $this->ownershipOrOriginal($e, $domain);
        }
        $this->log->info(sprintf('Added a %s record to %s.', $record->type->value, $domain->ascii()));
    }

    /**
     * Replaces a record. No idempotency key: writing the same values twice is the same result.
     *
     * Both ends are checked: the record being written AND the record the id points at, because an
     * id on its own says nothing about what it addresses. Without that, an apex NS record could be
     * overwritten with an innocent-looking A record.
     *
     * @throws ValidationException the record is not one a client may write
     * @throws RuleException       the record no longer exists (its id changed under us)
     */
    public function update(DomainName $domain, string $recordId, DnsRecord $record): void
    {
        $this->assertClientEditable($domain, $record);
        $this->assertClientEditable($domain, $this->existing($domain, $recordId));

        $this->send('PUT', $domain, $recordId, $record->payload());
        $this->log->info(sprintf('Updated a %s record of %s.', $record->type->value, $domain->ascii()));
    }

    /**
     * @throws RuleException       the record no longer exists
     * @throws ValidationException the record is one OSIR serves (deleting it would break the zone)
     */
    public function delete(DomainName $domain, string $recordId): void
    {
        $existing = $this->existing($domain, $recordId);
        $this->assertClientEditable($domain, $existing);

        $this->send('DELETE', $domain, $recordId, null);
        $this->log->info(sprintf('Deleted a %s record of %s.', $existing->type->value, $domain->ascii()));
    }

    /**
     * The record an id points at. A record id is derived from what the record says, so it goes
     * stale as soon as anyone edits that record; a miss means "reload", not "not found".
     *
     * @throws RuleException
     */
    private function existing(DomainName $domain, string $recordId): DnsRecord
    {
        $path = str_replace('{recordId}', self::recordSegment($recordId), ApiRequest::path(self::P_RECORD, $domain));

        try {
            $response = $this->api->send(ApiRequest::get($path)->unwrapped());
        } catch (ApiException $e) {
            if ($e->getKind() === ApiErrorKind::NotFound) {
                throw self::stale($e);
            }

            throw $this->ownershipOrOriginal($e, $domain);
        }

        $record = DnsRecord::fromApi(self::object($response->data));
        if ($record === null) {
            throw new RuleException('That DNS record could not be read. Reload the page and try again.');
        }

        return $record;
    }

    /**
     * The zone apex belongs to OSIR. SOA is refused outright, and so is an NS record on the zone
     * itself: replacing it would take the domain off OSIR's nameservers from inside the client
     * area. NS records for sub-zones ("sub.example.com") are allowed.
     *
     * @throws ValidationException
     */
    private function assertClientEditable(DomainName $domain, DnsRecord $record): void
    {
        if ($record->type->isZoneApexOnly()) {
            throw new ValidationException('The SOA record of :domain is managed by OSIR and cannot be changed here.', [':domain' => $domain->unicode()]);
        }
        if ($record->type === RecordType::NS && $record->isApex($domain)) {
            throw new ValidationException('The nameservers of :domain are changed on the domain itself, not as a DNS record.', [':domain' => $domain->unicode()]);
        }
        if ($record->type === RecordType::CNAME && $record->isApex($domain)) {
            // A CNAME beside the apex SOA/NS is invalid (RFC 1912 2.4) and takes mail and web down.
            throw new ValidationException('A CNAME cannot be used on :domain itself. Use it on a host such as www, or add an A record.', [':domain' => $domain->unicode()]);
        }
    }

    /**
     * A write against one record id. The id comes from OSIR's own listing and is percent-encoded
     * into the path, so it cannot change which endpoint is called.
     *
     * @param array<string, mixed>|null $body
     *
     * @throws RuleException
     */
    private function send(string $method, DomainName $domain, string $recordId, ?array $body): void
    {
        $path = str_replace('{recordId}', self::recordSegment($recordId), ApiRequest::path(self::P_RECORD, $domain));
        $request = $method === 'PUT'
            ? ApiRequest::put($path, $body ?? [])
            : ApiRequest::delete($path);

        try {
            $this->api->send($request->unwrapped());
        } catch (ApiException $e) {
            if ($e->getKind() === ApiErrorKind::NotFound) {
                throw self::stale($e);
            }

            throw $this->ownershipOrOriginal($e, $domain);
        }
    }

    /** OSIR derives the id from name + type + content, so it goes stale whenever the record does. */
    private static function stale(ApiException $e): RuleException
    {
        return new RuleException('That DNS record no longer exists. Reload the page to see the current records.', [], 0, $e);
    }

    /**
     * Record ids come from OSIR's own listing and are opaque tokens. Only that shape is accepted,
     * so a crafted id can never become anything but one path segment.
     *
     * @throws ValidationException
     */
    private static function recordSegment(string $recordId): string
    {
        $recordId = trim($recordId);
        if (preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $recordId) !== 1) {
            throw new ValidationException('That DNS record could not be identified. Reload the page and try again.');
        }

        return rawurlencode($recordId);
    }

    /**
     * One record as OSIR returned it, with or without the {success, data} envelope.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function object(array $data): array
    {
        $nested = $data['data'] ?? null;
        if (!array_key_exists('type', $data) && is_array($nested)) {
            return $nested;
        }

        return $data;
    }

    /**
     * OSIR's DNS endpoints publish no response schema, so accept the shapes it may answer with:
     * a bare list, or a list under "records", "data" or "data.records".
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $data, DomainName $domain): array
    {
        $candidates = [$data];
        foreach (['records', 'data'] as $key) {
            $nested = $data[$key] ?? null;
            if (is_array($nested)) {
                $candidates[] = $nested;
                $deeper = $nested['records'] ?? null;
                if (is_array($deeper)) {
                    $candidates[] = $deeper;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (array_is_list($candidate)) {
                $rows = [];
                foreach ($candidate as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }

                if ($rows !== [] || $candidate === []) {
                    return $rows;
                }
            }
        }

        // An unreadable answer must never be shown as "this domain has no records": the client
        // would re-create records that already exist.
        throw new RuleException('The DNS records of :domain could not be read right now. Please try again in a few minutes.', [':domain' => $domain->unicode()]);
    }
}
