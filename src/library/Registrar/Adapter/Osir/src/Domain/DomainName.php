<?php

declare(strict_types=1);

namespace Osir\FossBilling\Domain;

use Osir\FossBilling\Exception\ValidationException;

/**
 * A validated, normalised domain name in its ASCII (A-label / punycode) form.
 *
 * Every domain that reaches the OSIR API goes through this class first. It is the single
 * choke point that guarantees a value is a syntactically valid hostname before it is
 * interpolated into a URL path, so a crafted "domain" such as `../admin`, `a/b`, `x?y=1`
 * or `evil.com#` can never alter the request target.
 */
final class DomainName
{
    private const int MAX_LENGTH = 253;
    private const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    private function __construct(private readonly string $ascii) {}

    /**
     * @throws ValidationException
     */
    public static function fromString(string $input): self
    {
        $name = trim($input);
        if ($name === '') {
            throw new ValidationException('Domain name is empty.');
        }

        // Only a single trailing root dot is tolerated ("example.com.").
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }

        $ascii = self::toAscii(mb_strtolower($name, 'UTF-8'));
        self::assertValidHostname($ascii, 2);

        // A TLD is never all-numeric; this also rules out bare IPv4 addresses.
        $labels = explode('.', $ascii);
        if (ctype_digit(end($labels))) {
            throw new ValidationException('Domain name has an invalid top-level domain.');
        }

        return new self($ascii);
    }

    /**
     * Builds a name from FOSSBilling's split representation (sld "example", tld ".com").
     *
     * @throws ValidationException
     */
    public static function fromParts(?string $sld, ?string $tld): self
    {
        $sld = trim((string) $sld);
        $tld = ltrim(trim((string) $tld), '.');
        if ($sld === '' || $tld === '') {
            throw new ValidationException('Domain name is incomplete.');
        }
        // Checked AFTER IDNA mapping: U+3002, U+FF0E and U+FF61 are mapped to "." by UTS #46.
        if (str_contains(self::toAscii(mb_strtolower($sld, 'UTF-8')), '.')) {
            throw new ValidationException('Domain label must not contain a dot.');
        }

        return self::fromString($sld . '.' . $tld);
    }

    public function ascii(): string
    {
        return $this->ascii;
    }

    /** Unicode form for display; falls back to ASCII if conversion is not possible. */
    public function unicode(): string
    {
        $info = [];
        $unicode = idn_to_utf8($this->ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $info);

        return is_string($unicode) && $unicode !== '' && self::idnaClean($info) ? $unicode : $this->ascii;
    }

    /** The TLD including every label after the first, e.g. "co.uk" for "example.co.uk". */
    public function tld(): string
    {
        return explode('.', $this->ascii, 2)[1];
    }

    /** Path-safe segment. The value is already restricted to [a-z0-9.-]; encoding is defence in depth. */
    public function pathSegment(): string
    {
        return rawurlencode($this->ascii);
    }

    public function __toString(): string
    {
        return $this->ascii;
    }

    /**
     * Validates an ASCII hostname (used for domains and nameservers alike).
     *
     * @throws ValidationException
     */
    public static function assertValidHostname(string $ascii, int $minLabels): void
    {
        if ($ascii === '' || strlen($ascii) > self::MAX_LENGTH) {
            throw new ValidationException('Host name length is invalid.');
        }

        $labels = explode('.', $ascii);
        if (count($labels) < $minLabels) {
            throw new ValidationException('Host name must contain at least :count labels.', [':count' => (string) $minLabels]);
        }

        foreach ($labels as $label) {
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                throw new ValidationException('Host name contains an invalid label.');
            }
            // Two hyphens at positions 3-4 are reserved for A-labels ("xn--"); anything else there is invalid.
            if (strlen($label) >= 4 && substr($label, 2, 2) === '--' && !str_starts_with($label, 'xn--')) {
                throw new ValidationException('Host name contains a reserved label.');
            }
            if (str_starts_with($label, 'xn--') && !self::isValidALabel($label)) {
                throw new ValidationException('Host name contains an invalid internationalised label.');
            }
        }
    }

    /**
     * Converts a (possibly Unicode) name to ASCII using UTS #46 non-transitional processing,
     * with STD3 rules (LDH only), bidi and CONTEXTJ checks, as IDNA2008 registries expect.
     *
     * @throws ValidationException
     */
    public static function toAscii(string $name): string
    {
        if (preg_match('/^[\x21-\x7e]+$/', $name) === 1) {
            // Pure printable ASCII: no IDNA mapping needed, validation happens in assertValidHostname().
            return $name;
        }

        $info = [];
        $options = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;
        $ascii = idn_to_ascii($name, $options, INTL_IDNA_VARIANT_UTS46, $info);
        if (!is_string($ascii) || $ascii === '' || !self::idnaClean($info)) {
            throw new ValidationException('Domain name contains characters that cannot be registered.');
        }

        return strtolower($ascii);
    }

    /** An A-label must round-trip through IDNA decoding, otherwise it is not a real punycode label. */
    private static function isValidALabel(string $label): bool
    {
        $info = [];
        $unicode = idn_to_utf8($label, IDNA_NONTRANSITIONAL_TO_UNICODE | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46, $info);

        return is_string($unicode) && $unicode !== '' && self::idnaClean($info);
    }

    /** idn_to_* report problems through the by-reference $info array ("errors" is a bitmask). */
    private static function idnaClean(mixed $info): bool
    {
        return is_array($info) && ($info['errors'] ?? 0) === 0;
    }
}
