<?php

declare(strict_types=1);

namespace Osir\FossBilling\Config;

/**
 * Which OSIR environment the adapter operates on.
 *
 * FOSSBilling's per-registrar "Test Mode" switch selects SANDBOX. OSIR chooses production vs
 * OTE per request, so every state-changing call carries {@see self::apiValue()} explicitly —
 * the adapter never relies on a server-side default.
 */
enum Environment: string
{
    case Live = 'live';
    case Sandbox = 'sandbox';

    /** Value of OSIR's `environment` body/query field. */
    public function apiValue(): string
    {
        return match ($this) {
            self::Live => 'prod',
            self::Sandbox => 'ote1',
        };
    }

    /** Required API key prefix, so a key cannot be used in the wrong mode by mistake. */
    public function keyPrefix(): string
    {
        return match ($this) {
            self::Live => 'osir_live_',
            self::Sandbox => 'osir_test_',
        };
    }
}
