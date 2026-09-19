<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Mapping\DomainStatus;

/** A domain held in the OSIR account, as listed by OSIR. */
final class RemoteDomain
{
    /** @param list<string> $nameservers lower-case host names in registry order */
    public function __construct(
        public readonly DomainName $name,
        public readonly DomainStatus $status,
        public readonly ?int $createdAt,
        public readonly ?int $expiresAt,
        public readonly array $nameservers,
        public readonly bool $privacy,
    ) {}

    /**
     * Only a working registration with a known expiry date becomes a FOSSBilling order:
     * FOSSBilling's renewal cycle is driven by that date. An expired domain, or one on its way
     * out (redemption, pending delete), would become an "active" order for a name that is not.
     *
     * A domain in the auto-renew grace period is refused too: the registry has already moved its
     * expiry a year ahead, so FOSSBilling would not invoice the renewal for months while OSIR
     * deletes the unpaid domain after its grace window. Pay it at OSIR first, then import it.
     */
    public function importable(): bool
    {
        return $this->expiresAt !== null && $this->status === DomainStatus::Active;
    }

    /** Why {@see importable()} is false, for the administrator; null when it is importable. */
    public function notImportableReason(): ?string
    {
        return match (true) {
            $this->importable() => null,
            $this->status === DomainStatus::AutoRenewGracePeriod => 'Its renewal is unpaid (auto-renew grace period). Renew it at OSIR first, then import it.',
            $this->expiresAt === null => 'OSIR reports no expiry date for it.',
            default => sprintf('Not importable while it is %s.', $this->status->value),
        };
    }
}
