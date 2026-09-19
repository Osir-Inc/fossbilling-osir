<?php

declare(strict_types=1);

namespace Osir\FossBilling\Service;

use Osir\FossBilling\Config\Settings;
use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\OsirException;
use Osir\FossBilling\Mapping\AvailabilityState;
use Osir\FossBilling\Version;

/**
 * Read-only health checks for an installed adapter: configuration, TLS + authentication against
 * OSIR, account balance and, optionally, one domain. Never calls anything that changes state or
 * costs money, and never prints more of the API key than {@see \Osir\FossBilling\Config\Secret::hint()}.
 */
final class Diagnostics
{
    public function __construct(
        private readonly RegistrarService $service,
        private readonly Settings $settings,
    ) {}

    /** @return list<array{check: string, status: 'ok'|'warn'|'fail', detail: string}> */
    public function run(?DomainName $domain = null): array
    {
        $results = [];
        $results[] = self::result('Adapter version', 'ok', Version::PLUGIN);
        $fossbilling = class_exists(\FOSSBilling\Version::class) ? \FOSSBilling\Version::VERSION : null;
        $results[] = match (true) {
            $fossbilling === null => self::result('FOSSBilling version', 'warn', 'unknown'),
            version_compare($fossbilling, Version::MIN_FOSSBILLING, '<') => self::result('FOSSBilling version', 'fail', sprintf('%s — %s or newer is required (older versions show API keys in the admin panel)', $fossbilling, Version::MIN_FOSSBILLING)),
            default => self::result('FOSSBilling version', 'ok', $fossbilling),
        };
        $results[] = self::result('Environment', 'ok', sprintf('%s (OSIR environment "%s")', $this->settings->environment->value, $this->settings->environment->apiValue()));
        $results[] = self::result('API endpoint', $this->settings->baseUrl === Settings::DEFAULT_BASE_URL ? 'ok' : 'warn', $this->settings->baseUrl . ($this->settings->baseUrl === Settings::DEFAULT_BASE_URL ? '' : ' (server-level override)'));
        $results[] = $this->settings->source === 'server'
            ? self::result('API key', 'ok', $this->settings->apiKey->hint() . ', from config.php / environment')
            : self::result('API key', 'warn', $this->settings->apiKey->hint() . ', stored in the FOSSBilling database — prefer a define() in config.php');
        $results[] = self::result('Cost cap', $this->settings->maxYearlyCostCents === null ? 'warn' : 'ok', $this->settings->maxYearlyCostCents === null ? 'not set (no per-year price limit)' : sprintf('%.2f USD per year', $this->settings->maxYearlyCostCents / 100));
        if ($this->settings->debug) {
            $results[] = self::result('Debug logging', 'warn', 'on — turn it off when not troubleshooting');
        }

        try {
            $balance = $this->service->balanceCents();
            $results[] = self::result('Connection, TLS and API key', 'ok', 'authenticated');
            $results[] = self::result('Account balance', $balance > 0 ? 'ok' : 'warn', sprintf('%.2f USD', $balance / 100));
        } catch (OsirException $e) {
            $results[] = self::result('Connection, TLS and API key', 'fail', self::describe($e));

            return $results;
        }

        if ($domain !== null) {
            try {
                $availability = $this->service->checkAvailability($domain);
                $results[] = self::result('Availability of ' . $domain->unicode(), $availability->state === AvailabilityState::Unknown ? 'warn' : 'ok', strtolower($availability->state->name) . ($availability->premium ? ', premium' : ''));
                if ($availability->state === AvailabilityState::Registered) {
                    $info = $this->service->details($domain);
                    $results[] = self::result('In this account', 'ok', sprintf('status %s, expires %s', $info->status->value, $info->expiresAt !== null ? gmdate('Y-m-d', $info->expiresAt) : 'unknown'));
                }
            } catch (OsirException $e) {
                $results[] = self::result('Domain ' . $domain->unicode(), 'warn', self::describe($e));
            }
        }

        return $results;
    }

    /** @return array{check: string, status: 'ok'|'warn'|'fail', detail: string} */
    private static function result(string $check, string $status, string $detail): array
    {
        assert(in_array($status, ['ok', 'warn', 'fail'], true));

        /** @var 'ok'|'warn'|'fail' $status */
        return ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private static function describe(OsirException $e): string
    {
        if ($e instanceof ApiException) {
            return sprintf('%s (HTTP %d%s), reference %s', $e->getKind()->value, $e->getHttpStatus(), $e->getErrorCode() !== null ? ', ' . $e->getErrorCode() : '', $e->getRequestId());
        }

        return $e->getMessage();
    }
}
