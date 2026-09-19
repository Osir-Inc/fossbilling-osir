<?php

declare(strict_types=1);

namespace Osir\FossBilling\Service;

enum RenewalDecision
{
    /** Renew at the registry. */
    case Renew;
    /** The registry already auto-renewed; OSIR only charges for it (OSIR's auto-renew grace period). */
    case PayAutoRenewGrace;
    /** OSIR's expiry is already where this renewal would put it: an earlier attempt succeeded. */
    case AlreadyApplied;
}
