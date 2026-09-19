<?php

declare(strict_types=1);

namespace Osir\FossBilling\Config;

/**
 * Immutable, fully validated runtime configuration of one adapter instance.
 * Build it with {@see SettingsResolver}; nothing else constructs it outside tests.
 */
final class Settings
{
    public const string DEFAULT_BASE_URL = 'https://be.osir.com';

    /**
     * @param string          $baseUrl                 scheme + host (+ port), no path, always https
     * @param string|null     $caFile                  custom CA bundle (development/staging only)
     * @param string          $installationId          short stable identifier of this FOSSBilling install
     * @param int|null        $maxYearlyCostCents      refuse register/renew/transfer above this cost per year (USD cents)
     * @param bool            $initializeDnsZone       ask OSIR to create a DNS zone for new registrations
     * @param bool            $debug                   write request metadata (never bodies) to the log
     * @param string          $source                  where the API key came from, for diagnostics: "server" (constant/env) or "settings" (database)
     */
    public function __construct(
        public readonly Environment $environment,
        public readonly Secret $apiKey,
        public readonly string $baseUrl,
        public readonly ?string $caFile,
        public readonly string $installationId,
        public readonly ?int $maxYearlyCostCents,
        public readonly bool $initializeDnsZone,
        public readonly bool $debug,
        public readonly string $source,
    ) {}
}
