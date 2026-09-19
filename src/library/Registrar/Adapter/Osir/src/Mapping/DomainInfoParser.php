<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;

/**
 * Parses OSIR's info payload. Unknown fields are ignored; missing or mistyped required fields
 * fail loudly (Protocol error) instead of producing a half-filled record that would then
 * overwrite correct data in FOSSBilling.
 */
final class DomainInfoParser
{
    /** @param array<array-key, mixed> $data */
    public static function parse(array $data, string $requestId): DomainInfo
    {
        $domain = $data['domain'] ?? null;
        $status = $data['status'] ?? null;
        if (!is_string($domain) || $domain === '' || !is_string($status) || $status === '') {
            throw new ApiException(ApiErrorKind::Protocol, 200, null, 'Info response lacks domain/status.', $requestId);
        }

        $nameservers = [];
        foreach (is_array($data['nameservers'] ?? null) ? $data['nameservers'] : [] as $ns) {
            if (is_string($ns) && trim($ns) !== '') {
                $nameservers[] = strtolower(rtrim(trim($ns), '.'));
            }
        }

        $statuses = [];
        foreach (is_array($data['statuses'] ?? null) ? $data['statuses'] : [] as $s) {
            if (is_string($s)) {
                $statuses[] = $s;
            }
        }

        $parsedStatus = DomainStatus::fromApi($status);

        return new DomainInfo(
            domain: strtolower($domain),
            status: $parsedStatus,
            statuses: $statuses,
            createdAt: self::timestamp($data['creationDate'] ?? null),
            expiresAt: self::timestamp($data['expiryDate'] ?? null),
            nameservers: $nameservers,
            locked: ($data['locked'] ?? false) === true,
            privacy: ($data['privacy'] ?? false) === true,
            inRedemption: ($data['inRedemptionPeriod'] ?? false) === true || $parsedStatus === DomainStatus::RedemptionPeriod,
            inAutoRenewGrace: ($data['inAutoRenewGracePeriod'] ?? false) === true || $parsedStatus === DomainStatus::AutoRenewGracePeriod,
        );
    }

    /**
     * OSIR serialises dates as ISO-8601 local date-times WITHOUT a zone designator; the values are
     * UTC. The string is parsed field by field (no lenient rollover: 2027-02-30 is rejected, not
     * turned into 2027-03-02) and interpreted as UTC unless it carries its own offset.
     */
    public static function timestamp(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?)?(Z|[+-]\d{2}:?\d{2})?$/';
        if (preg_match($pattern, trim($value), $m) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $offset = ($m[7] ?? '') === '' ? 'UTC' : ($m[7] === 'Z' ? 'UTC' : $m[7]);
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new \DateTimeZone($offset),
        );

        return $date === false ? null : $date->getTimestamp();
    }
}
