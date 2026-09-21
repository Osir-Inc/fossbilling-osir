<?php

declare(strict_types=1);

namespace Osir\FossBilling\Service;

/**
 * The FOSSBilling order an operation belongs to.
 *
 *  - id:        makes idempotency keys unique per order;
 *  - expiresAt: the ORDER's expiry, which FOSSBilling moves only after a successful renew action —
 *               the stable anchor of renewal keys (the domain's own expiry changes with every sync);
 *  - createdAt: only for the fallback heuristic when OSIR's idempotency store cannot answer;
 *  - status:    FOSSBilling's order status ("failed_renew" means the last renewal attempt failed
 *               and its outcome at OSIR may be unknown);
 *  - price:     what FOSSBilling charges for this order, in the order's own currency. Only used to
 *               decide whether a premium name costs less than it sells for; null when unknown.
 */
final class OrderRef
{
    public function __construct(
        public readonly string $id,
        public readonly ?int $createdAt,
        public readonly ?int $expiresAt = null,
        public readonly ?string $status = null,
        public readonly ?int $priceMinorUnits = null,
        public readonly ?string $currency = null,
    ) {
        if (preg_match('/^[0-9]{1,20}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Order id must be numeric.');
        }
        if ($priceMinorUnits !== null && $priceMinorUnits < 0) {
            throw new \InvalidArgumentException('Order price cannot be negative.');
        }
    }

    /**
     * What this order charges, but only when it is in the currency OSIR quotes in (USD): comparing
     * against another currency would need an exchange rate the adapter does not have.
     */
    public function priceInUsdCents(): ?int
    {
        return $this->currency === 'USD' ? $this->priceMinorUnits : null;
    }

    /** The last renewal attempt failed; it may nevertheless have gone through at OSIR. */
    public function hasUnresolvedRenewal(): bool
    {
        return $this->status === 'failed_renew';
    }
}
