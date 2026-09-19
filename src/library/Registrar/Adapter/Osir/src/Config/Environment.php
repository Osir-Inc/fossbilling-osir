<?php

declare(strict_types=1);

namespace Osir\FossBilling\Config;

/**
 * Which OSIR environment the adapter operates on. OSIR offers no sandbox to partners, so there is
 * only the live one; FOSSBilling's per-registrar "Test Mode" is refused in {@see SettingsResolver}.
 *
 * Kept as a type (rather than a constant) because the environment is part of every idempotency key
 * (`…:live:…`) and of every state-changing request (`environment=prod`, sent explicitly so the adapter
 * never relies on a server-side default). Changing either would break the retry of an in-flight order.
 */
enum Environment: string
{
    case Live = 'live';

    /** Value of OSIR's `environment` body/query field. */
    public function apiValue(): string
    {
        return 'prod';
    }

    /** Required API key prefix. */
    public function keyPrefix(): string
    {
        return 'osir_live_';
    }
}
