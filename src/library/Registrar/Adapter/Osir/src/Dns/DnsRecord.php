<?php

declare(strict_types=1);

namespace Osir\FossBilling\Dns;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ValidationException;

/**
 * One DNS record, either as OSIR returned it (with an id) or as the client area wants it written
 * (a draft, id null).
 *
 * OSIR derives `recordId` from name + type + content, so an edit changes the id. Nothing here
 * caches ids: the client area re-reads the list after every write.
 *
 * Validation happens in this class, before a request is sent, so a mistyped form comes back as a
 * message the client can act on rather than a 400 from the API.
 */
final class DnsRecord
{
    /** OSIR rejects a TTL of 0; a week is the longest that is still sensible in a client area. */
    public const int TTL_MIN = 60;
    public const int TTL_MAX = 604800;
    private const int CONTENT_MAX = 65535;
    private const int PORT_MAX = 65535;

    private function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly RecordType $type,
        public readonly string $content,
        public readonly ?int $ttl,
        public readonly ?int $priority,
        public readonly ?int $weight,
        public readonly ?int $port,
        public readonly bool $disabled,
    ) {}

    /**
     * A record the client wants written. `$name` is accepted either fully qualified
     * ("www.example.com") or relative to the zone ("www", "@"), and is always sent fully
     * qualified, which is the form OSIR returns.
     *
     * @param array<string, mixed> $input raw form values
     *
     * @throws ValidationException
     */
    public static function draft(DomainName $zone, array $input): self
    {
        $type = RecordType::fromInput(self::string($input, 'type'));
        $name = self::qualify($zone, self::string($input, 'name'));
        $content = trim(self::string($input, 'content'));

        if ($content === '') {
            throw new ValidationException('The record value cannot be empty.');
        }
        if (strlen($content) > self::CONTENT_MAX) {
            throw new ValidationException('The record value is too long.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $content) === 1) {
            throw new ValidationException('The record value contains characters that are not allowed.');
        }

        self::assertContentFits($type, $content);

        $ttl = self::optionalInt($input, 'ttl');
        if ($ttl !== null && ($ttl < self::TTL_MIN || $ttl > self::TTL_MAX)) {
            throw new ValidationException('The TTL must be between :min and :max seconds.', [':min' => (string) self::TTL_MIN, ':max' => (string) self::TTL_MAX]);
        }

        $priority = self::optionalInt($input, 'priority');
        $weight = self::optionalInt($input, 'weight');
        $port = self::optionalInt($input, 'port');

        foreach ([':priority' => $priority, ':weight' => $weight] as $label => $value) {
            if ($value !== null && ($value < 0 || $value > self::PORT_MAX)) {
                throw new ValidationException('The :field must be between 0 and 65535.', [':field' => ltrim($label, ':')]);
            }
        }

        if ($type->usesPriority() && $priority === null) {
            throw new ValidationException(':type records need a priority.', [':type' => $type->value]);
        }
        if ($type->usesServiceFields()) {
            if ($port === null || $port < 1 || $port > self::PORT_MAX) {
                throw new ValidationException('SRV records need a port between 1 and 65535.');
            }
            if ($weight === null) {
                throw new ValidationException('SRV records need a weight.');
            }
        } elseif ($port !== null || $weight !== null) {
            // Sending them for other types would be silently ignored; drop them instead.
            $port = null;
            $weight = null;
        }

        return new self(null, $name, $type, $content, $ttl, $type->usesPriority() ? $priority : null, $weight, $port, self::bool($input, 'disabled'));
    }

    /**
     * One record as OSIR returned it. Unknown or malformed entries are skipped by the caller, so
     * a single odd row never breaks the whole listing.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromApi(array $row): ?self
    {
        $name = self::string($row, 'name');
        $content = self::string($row, 'content');
        $type = RecordType::tryFrom(strtoupper(self::string($row, 'type')));
        if ($name === '' || $type === null) {
            return null;
        }

        $id = self::string($row, 'id');
        if ($id === '') {
            $id = self::string($row, 'recordId');
        }

        return new self(
            $id === '' ? null : $id,
            rtrim($name, '.'),
            $type,
            $content,
            self::optionalInt($row, 'ttl'),
            self::optionalInt($row, 'priority'),
            self::optionalInt($row, 'weight'),
            self::optionalInt($row, 'port'),
            self::bool($row, 'disabled'),
        );
    }

    /**
     * The JSON body for a create or update. Optional fields are omitted rather than sent as null,
     * so OSIR applies its own defaults (a TTL the client left blank, for instance).
     *
     * @return array<string, bool|int|string>
     */
    public function payload(): array
    {
        $body = [
            'name' => $this->name,
            'type' => $this->type->value,
            'content' => $this->content,
        ];
        foreach (['ttl' => $this->ttl, 'priority' => $this->priority, 'weight' => $this->weight, 'port' => $this->port] as $field => $value) {
            if ($value !== null) {
                $body[$field] = $value;
            }
        }
        if ($this->disabled) {
            $body['disabled'] = true;
        }

        return $body;
    }

    /** Whether this record sits at the zone apex ("example.com" itself). */
    public function isApex(DomainName $zone): bool
    {
        return strcasecmp($this->name, $zone->ascii()) === 0;
    }

    /**
     * Shape handed to the template. Ids are strings for the browser; nothing else is computed here.
     *
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'priority' => $this->priority,
            'weight' => $this->weight,
            'port' => $this->port,
            'disabled' => $this->disabled,
        ];
    }

    /**
     * The value has to match the type, or OSIR answers 400 and the client is left guessing. Only
     * the types with an unambiguous shape are checked; TXT, CAA, SRV and NAPTR carry free-form or
     * compound values and are left to OSIR.
     *
     * @throws ValidationException
     */
    private static function assertContentFits(RecordType $type, string $content): void
    {
        $ok = match ($type) {
            RecordType::A => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            RecordType::AAAA => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            RecordType::CNAME, RecordType::NS, RecordType::MX, RecordType::PTR => self::looksLikeHostName($content),
            default => true,
        };

        if ($ok) {
            return;
        }

        throw new ValidationException(match ($type) {
            RecordType::A => 'An A record needs an IPv4 address, for example 203.0.113.7.',
            RecordType::AAAA => 'An AAAA record needs an IPv6 address, for example 2001:db8::1.',
            default => 'A :type record needs a host name, for example mail.example.com.',
        }, [':type' => $type->value]);
    }

    private static function looksLikeHostName(string $content): bool
    {
        $host = rtrim($content, '.');
        // An IP address is a syntactically valid name, and a common mistake here: these types all
        // have to point at a host name, so refuse it.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return $host !== '' && strlen($host) <= 253 && preg_match('/^(?!-)[A-Za-z0-9_-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9_-]{1,63}(?<!-))*$/', $host) === 1 && str_contains($host, '.');
    }

    /**
     * "www" -> "www.example.com"; "@" or "" -> "example.com"; an already qualified name is kept.
     *
     * @throws ValidationException
     */
    private static function qualify(DomainName $zone, string $name): string
    {
        $name = rtrim(trim($name), '.');
        if ($name === '' || $name === '@') {
            return $zone->ascii();
        }

        $zoneAscii = $zone->ascii();
        $ascii = mb_strtolower($name, 'UTF-8');
        // Only the part the client typed may need IDN encoding, and only when it is not ASCII
        // already ("münchen" -> "xn--mnchen-3ya"); "_sip._tcp" and "*" must be left alone.
        if (preg_match('/^[\x21-\x7E]+$/', $ascii) !== 1) {
            $ascii = DomainName::toAscii($ascii);
        }
        if (strcasecmp($ascii, $zoneAscii) !== 0 && !str_ends_with($ascii, '.' . $zoneAscii)) {
            $ascii .= '.' . $zoneAscii;
        }

        self::assertValidRecordName($ascii);

        return $ascii;
    }

    /**
     * A record name is not a host name: service records ("_sip._tcp"), DMARC ("_dmarc"), ACME
     * challenges ("_acme-challenge") and wildcards ("*.example.com") are all valid and would all
     * fail a host name check. Labels are therefore checked here, letting a leading underscore and
     * a single leading "*" through.
     *
     * @throws ValidationException
     */
    private static function assertValidRecordName(string $ascii): void
    {
        if ($ascii === '' || strlen($ascii) > 253) {
            throw new ValidationException('The record name is too long.');
        }

        $labels = explode('.', $ascii);
        foreach ($labels as $index => $label) {
            if ($index === 0 && $label === '*') {
                continue;
            }
            if (preg_match('/^_?[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $label) !== 1) {
                throw new ValidationException('":name" is not a valid record name.', [':name' => substr($ascii, 0, 60)]);
            }
        }
    }

    /** @param array<array-key, mixed> $input */
    private static function string(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @throws ValidationException
     */
    private static function optionalInt(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ValidationException('":field" must be a whole number.', [':field' => $key]);
    }

    /** @param array<array-key, mixed> $input */
    private static function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
