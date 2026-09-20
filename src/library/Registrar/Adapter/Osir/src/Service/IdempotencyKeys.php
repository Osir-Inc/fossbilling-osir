<?php

declare(strict_types=1);

namespace Osir\FossBilling\Service;

use Osir\FossBilling\Config\Environment;
use Osir\FossBilling\Domain\DomainName;

/**
 * Builds the Idempotency-Key values for OSIR's money endpoints.
 *
 * OSIR scopes a key per (account, endpoint) and keeps the outcome of a keyed request for 30 days.
 * The keys therefore encode everything that makes an operation distinct:
 *   - the environment (always `live`: OSIR has no sandbox; kept so existing keys stay valid);
 *   - the order id, and for renewals the order's expiry date, so a retry of the SAME action
 *     collapses into one charge while the next renewal gets a new key;
 *   - an attempt number, bumped only when OSIR replays a stored 5xx for the previous key (the
 *     service re-checks the registry state before moving on, see RegistrarService::sendMoneyCall).
 * Keys are human-readable on purpose, so OSIR support can match them to FOSSBilling orders.
 */
final class IdempotencyKeys
{
    public static function register(string $installationId, Environment $env, OrderRef $order, DomainName $domain, int $years, int $attempt = 1): string
    {
        return self::build('register', $installationId, $env, $order, $domain, $years . 'y', $attempt);
    }

    /**
     * $anchor is the FOSSBilling ORDER's expiry date, which FOSSBilling moves only after a successful
     * renew action. (The domain's expiry is not usable: every sync overwrites it from the registry,
     * so a retry after a sync would get a new key and renew twice.)
     */
    public static function renew(string $installationId, Environment $env, OrderRef $order, DomainName $domain, int $years, int $anchor, int $attempt = 1): string
    {
        return self::build('renew', $installationId, $env, $order, $domain, $years . 'y-exp' . gmdate('Ymd', $anchor), $attempt);
    }

    public static function transfer(string $installationId, Environment $env, OrderRef $order, DomainName $domain, int $attempt = 1): string
    {
        return self::build('transfer', $installationId, $env, $order, $domain, '', $attempt);
    }

    /**
     * Adding a DNS record. Nothing is charged here, so the key exists only so that a retry after a
     * timeout cannot add the same record twice.
     *
     * $nonce is new for every submission, and deliberately not derived from the record: OSIR keeps
     * a keyed outcome for 30 days, so a key that depended only on (order, record) would replay the
     * first answer when a client deleted a record and added it back — the record would never
     * reappear and the client area would still report success.
     */
    public static function dnsRecord(string $installationId, Environment $env, OrderRef $order, DomainName $domain, string $nonce): string
    {
        return self::build('dns-add', $installationId, $env, $order, $domain, $nonce, 1);
    }

    private static function build(string $action, string $installationId, Environment $env, OrderRef $order, DomainName $domain, string $extra, int $attempt): string
    {
        $parts = ['fb', $installationId, $env->value, 'o' . $order->id, $action, $domain->ascii()];
        if ($extra !== '') {
            $parts[] = $extra;
        }
        if ($attempt > 1) {
            $parts[] = 'a' . $attempt;
        }
        $key = implode(':', $parts);

        // Stay well inside OSIR's 255-character limit even for the longest domain names.
        return strlen($key) <= 200 ? $key : substr($key, 0, 135) . ':' . hash('sha256', $key);
    }
}
