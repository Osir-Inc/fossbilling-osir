<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

/**
 * What OSIR charges this account per year for a TLD, in USD cents, fees included.
 *
 * The registration cost comes from OSIR's own quote (live registry price for a standard name,
 * plus the ICANN fee and OSIR's fee). OSIR has no renewal or transfer quote for a name that is
 * not in the account, so those two are the catalog price plus the same fees. They are raised,
 * and {@see $estimated} is set so the administrator can check them, when the registry charges
 * more than the catalog says or when a component is dearer than the registration (OSIR's fee
 * can grow with the price). Estimates only ever err on the high side.
 */
final class TldCost
{
    public function __construct(
        public readonly CatalogTld $catalog,
        public readonly int $registerCents,
        public readonly int $renewCents,
        public readonly int $transferCents,
        /** ICANN fee + OSIR fee (+ trustee fee) per year. */
        public readonly int $feesCents,
        /** The registry's live price differs from OSIR's catalog price. */
        public readonly bool $estimated,
    ) {}
}
