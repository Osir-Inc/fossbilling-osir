<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

/**
 * The registry state of a domain as reported by OSIR's info endpoint.
 * Deliberately excludes the transfer auth code: FOSSBilling serialises the details object into
 * its database, and the auth code must never be persisted there.
 */
final class DomainInfo
{
    /**
     * @param list<string> $statuses    raw EPP statuses (e.g. "clientTransferProhibited")
     * @param list<string> $nameservers lower-case host names in registry order
     */
    public function __construct(
        public readonly string $domain,
        public readonly DomainStatus $status,
        public readonly array $statuses,
        public readonly ?int $createdAt,
        public readonly ?int $expiresAt,
        public readonly array $nameservers,
        public readonly bool $locked,
        public readonly bool $privacy,
        public readonly bool $inRedemption = false,
        public readonly bool $inAutoRenewGrace = false,
    ) {}

    public static function pendingTransfer(string $domain): self
    {
        return new self($domain, DomainStatus::PendingTransfer, [], null, null, [], false, false);
    }
}
