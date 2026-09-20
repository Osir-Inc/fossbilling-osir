<?php

declare(strict_types=1);

namespace Osir\FossBilling\Dns;

/**
 * What OSIR reports about a domain's DNS: whether the zone exists and whether the domain actually
 * points at OSIR's nameservers.
 *
 * The nameservers are read from this answer and never hardcoded: OSIR's set is configurable, and
 * is ns1/ns3 rather than ns1..ns4.
 */
final class ZoneStatus
{
    /**
     * @param list<string> $nameservers    the domain's nameservers at the registry
     * @param list<string> $ourNameservers OSIR's own nameservers
     */
    private function __construct(
        public readonly bool $zoneExists,
        public readonly bool $usesOurNameservers,
        public readonly array $nameservers,
        public readonly array $ourNameservers,
    ) {}

    /**
     * These endpoints publish no response schema, so the answer may be the bare object or OSIR's
     * {success, data} envelope; both are read here.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        $nested = $data['data'] ?? null;
        if (!array_key_exists('zoneExists', $data) && is_array($nested)) {
            $data = $nested;
        }

        return new self(
            self::bool($data, 'zoneExists'),
            self::bool($data, 'usesOurNameservers'),
            self::hosts($data['nameservers'] ?? null),
            self::hosts($data['ourNameservers'] ?? null),
        );
    }

    /** @return array<string, bool|list<string>> */
    public function toArray(): array
    {
        return [
            'zone_exists' => $this->zoneExists,
            'uses_our_nameservers' => $this->usesOurNameservers,
            'nameservers' => $this->nameservers,
            'our_nameservers' => $this->ourNameservers,
        ];
    }

    /**
     * Tolerant on purpose: a `1` or `"true"` from an endpoint with no published schema must not
     * turn into a red "your records do not resolve" banner on a healthy zone.
     *
     * @param array<array-key, mixed> $data
     */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** @return list<string> */
    private static function hosts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $hosts = [];
        foreach ($value as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = rtrim(trim($host), '.');
            }
        }

        return $hosts;
    }
}
