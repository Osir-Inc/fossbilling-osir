<?php

declare(strict_types=1);

namespace Osir\FossBilling\Support;

/**
 * Removes secrets and personal data from anything that may end up in a log.
 *
 * Two layers: key-based masking for structured data (any key whose normalised name is
 * sensitive is replaced wholesale), and pattern-based masking for free text (OSIR API keys
 * and bearer tokens are recognised wherever they appear).
 */
final class Redactor
{
    public const string MASK = '[redacted]';

    /** Normalised (lower-case, no separators) key names whose values are never logged. */
    private const array SENSITIVE_KEYS = [
        // credentials
        'apikey', 'xapikey', 'authorization', 'token', 'accesstoken', 'refreshtoken', 'password',
        'secret', 'clientsecret',
        // domain transfer secrets
        'authcode', 'authinfo', 'eppcode', 'epp', 'transfercode',
        // contact personal data
        'email', 'phone', 'fax', 'street', 'street1', 'street2', 'street3', 'address', 'address1',
        'address2', 'address3', 'postalcode', 'zip', 'firstname', 'lastname', 'name', 'organization',
        'company', 'city', 'state', 'birthday', 'documentnr', 'documentnumber', 'companynumber',
        'registrant', 'admin', 'tech', 'billing', 'contacts',
    ];

    private const array TEXT_PATTERNS = [
        '/osir_(?:live|test)_[A-Za-z0-9_\-]+/' => 'osir_***',
        '/(?i:bearer)\s+[A-Za-z0-9._~+\/\-]+=*/' => 'Bearer ***',
        // Contact data echoed in upstream error texts.
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
        '/\+\d{1,3}\.\d{4,14}/' => '[phone]',
        '/\+\d{7,15}\b/' => '[phone]',
    ];

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['_truncated' => true];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = self::redactArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = self::redactText($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function redactText(string $text): string
    {
        return (string) preg_replace(array_keys(self::TEXT_PATTERNS), array_values(self::TEXT_PATTERNS), $text);
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        return in_array($normalised, self::SENSITIVE_KEYS, true);
    }
}
