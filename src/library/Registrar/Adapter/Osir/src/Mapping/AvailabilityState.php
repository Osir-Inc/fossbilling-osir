<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

enum AvailabilityState
{
    case Available;
    case Registered;
    /** The check itself failed (registry outage, unsupported TLD, …). Never treat as either answer. */
    case Unknown;
}
