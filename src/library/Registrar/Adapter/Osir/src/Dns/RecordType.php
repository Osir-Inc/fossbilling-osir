<?php

declare(strict_types=1);

namespace Osir\FossBilling\Dns;

use Osir\FossBilling\Exception\ValidationException;

/**
 * The record types OSIR's DNS accepts.
 *
 * SOA and apex NS are served by OSIR and must not be rewritten from a client area; a zone whose
 * apex is edited by hand stops resolving. {@see DnsService::assertClientEditable()} enforces that.
 */
enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case NS = 'NS';
    case PTR = 'PTR';
    case SOA = 'SOA';
    case SRV = 'SRV';
    case TXT = 'TXT';
    case CAA = 'CAA';
    case NAPTR = 'NAPTR';

    /** @throws ValidationException */
    public static function fromInput(string $value): self
    {
        $type = self::tryFrom(strtoupper(trim($value)));
        if ($type === null) {
            throw new ValidationException('":type" is not a DNS record type.', [':type' => substr($value, 0, 20)]);
        }

        return $type;
    }

    /** Whether the type carries a priority (sent by OSIR, shown and edited in the form). */
    public function usesPriority(): bool
    {
        return $this === self::MX || $this === self::SRV || $this === self::NAPTR;
    }

    /** SRV carries weight and port besides the priority. */
    public function usesServiceFields(): bool
    {
        return $this === self::SRV;
    }

    /** Types a client may never write, whatever the record name. */
    public function isZoneApexOnly(): bool
    {
        return $this === self::SOA;
    }
}
