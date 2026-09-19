<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

/**
 * OSIR's summary status of a domain (the `status` field of the info endpoint).
 * Anything OSIR adds later maps to {@see self::Unknown}, so a `match` over this enum stays exhaustive.
 */
enum DomainStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    /** The registry auto-renewed at expiry; OSIR is waiting for payment (it parks the domain meanwhile). */
    case AutoRenewGracePeriod = 'autoRenewGracePeriod';
    case RedemptionPeriod = 'redemptionPeriod';
    case PendingDelete = 'pendingDelete';
    case PendingTransfer = 'pendingTransfer';
    case PendingCreate = 'pendingCreate';
    case Deleted = 'deleted';
    case TransferredOut = 'transferredOut';
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }

    /** No longer a working registration under this account. */
    public function isGone(): bool
    {
        return $this === self::Deleted || $this === self::TransferredOut;
    }
}
