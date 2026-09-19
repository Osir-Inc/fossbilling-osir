<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ValidationException;

/**
 * One entry of OSIR's public TLD catalog. The prices are OSIR's LIST prices in USD cents,
 * WITHOUT the ICANN fee and OSIR's own fee, and without live registry pricing: they are shown
 * for orientation only. What an operation really costs comes from {@see TldCost}.
 */
final class CatalogTld
{
    private function __construct(
        /** ASCII form with a leading dot, e.g. ".com", ".net.al", ".xn--p1ai". */
        public readonly string $tld,
        public readonly int $registerCents,
        public readonly int $renewCents,
        public readonly int $transferCents,
        public readonly int $minYears,
        public readonly int $maxYears,
        /** Shortest renewal OSIR accepts; imported orders renew for this many years. */
        public readonly int $minRenewYears,
        public readonly int $minCharacters,
        public readonly int $maxCharacters,
        public readonly bool $hasPremium,
        public readonly bool $hasRestrictions,
        public readonly ?string $type,
    ) {}

    /**
     * Returns null for an entry that cannot be imported safely (unknown shape, invalid TLD,
     * missing or zero price): such a TLD is left out rather than imported with a made-up price.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromApi(array $row): ?self
    {
        $tld = $row['extension'] ?? null;
        if (!is_string($tld) || !str_starts_with($tld, '.')) {
            return null;
        }
        try {
            $tld = '.' . DomainName::fromString('a' . $tld)->tld();
        } catch (ValidationException) {
            return null;
        }

        $register = self::positiveInt($row['registrationPrice'] ?? null);
        $renew = self::positiveInt($row['renewalPrice'] ?? null);
        // OSIR documents a transfer price of 0 as "same as registration".
        $transfer = self::positiveInt($row['transferPrice'] ?? null) ?? $register;
        if ($register === null || $renew === null || $transfer === null) {
            return null;
        }

        $minYears = self::positiveInt($row['minRegistrationPeriod'] ?? null) ?? 1;
        $maxYears = max($minYears, self::positiveInt($row['maxRegistrationPeriod'] ?? null) ?? 10);
        $minChars = self::positiveInt($row['minCharacters'] ?? null) ?? 1;
        $maxChars = min(63, max($minChars, self::positiveInt($row['maxCharacters'] ?? null) ?? 63));
        $type = $row['extensionType'] ?? null;

        return new self(
            tld: $tld,
            registerCents: $register,
            renewCents: $renew,
            transferCents: $transfer,
            minYears: $minYears,
            maxYears: $maxYears,
            minRenewYears: min(10, self::positiveInt($row['minRenewalPeriod'] ?? null) ?? 1),
            minCharacters: $minChars,
            maxCharacters: $maxChars,
            hasPremium: ($row['hasPremium'] ?? false) === true,
            hasRestrictions: ($row['hasRestrictions'] ?? false) === true,
            type: is_string($type) && preg_match('/^[A-Za-z]{1,20}$/', $type) === 1 ? $type : null,
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
