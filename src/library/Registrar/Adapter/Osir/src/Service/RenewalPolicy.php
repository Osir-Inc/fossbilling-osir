<?php

declare(strict_types=1);

namespace Osir\FossBilling\Service;

use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Mapping\DomainInfo;
use Osir\FossBilling\Mapping\DomainStatus;

/**
 * Decides what a FOSSBilling renewal must do at OSIR. Kept apart from the HTTP plumbing because
 * it is the riskiest logic in the adapter: a wrong answer either charges a customer twice or
 * lets a paid-for domain lapse.
 *
 * OSIR behaviour it relies on:
 *   - `GET /info` reads the registry live. When the registry reports autoRenewPeriod (it renewed
 *     the domain itself at expiry) and nobody has paid yet, OSIR flags the domain and answers
 *     status=autoRenewGracePeriod. In that state `POST /renew` only charges — always exactly one
 *     year — and OSIR has its own duplicate-payment guard. Unpaid, OSIR parks the domain and
 *     deletes it after 45 days.
 *   - Otherwise `POST /renew` renews at the registry.
 *
 * Rules, in order:
 *   1. redemption / pendingDelete     → refuse (a restore is needed, done by OSIR support)
 *   2. deleted / transferred out      → refuse
 *   3. FOSSBilling has no expiry date → refuse (cannot tell a lost-response retry from a new renewal)
 *   4. auto-renew grace period        → pay it (1 year only; a longer period is refused up front)
 *   5. OSIR expiry already ≈ FOSSBilling expiry + N years → already applied (lost-response retry)
 *   6. otherwise                      → renew
 * Rule 4 comes before rule 5 on purpose: in the grace period OSIR's expiry is ALREADY a year
 * ahead because of the registry's own auto-renewal, and must not be mistaken for our renewal.
 */
final class RenewalPolicy
{
    /**
     * Slack when matching expiry dates (registry vs FOSSBilling day rounding, leap days, renewals
     * a few weeks early). Not related to OSIR's 45-day grace period.
     */
    private const int EXPIRY_MATCH_SLACK_DAYS = 45;
    private const int DAY = 86400;

    /**
     * @throws RuleException when the renewal must not be attempted
     */
    public static function decide(DomainInfo $info, ?int $knownExpiry, int $years, string $displayName): RenewalDecision
    {
        if ($info->inRedemption || $info->status === DomainStatus::PendingDelete) {
            throw new RuleException(':domain has expired and is in the redemption period. It must be restored through OSIR support.', [':domain' => $displayName]);
        }
        if ($info->status->isGone()) {
            throw new RuleException(':domain is no longer registered in this account.', [':domain' => $displayName]);
        }
        if ($knownExpiry === null) {
            throw new RuleException('The expiry date of :domain is unknown in FOSSBilling. Synchronise the domain first, then renew.', [':domain' => $displayName]);
        }
        if ($info->inAutoRenewGrace) {
            if ($years !== 1) {
                throw new RuleException(
                    'The registry has already renewed :domain for one year, which must be paid first. Renew it for 1 year now; a longer extension can follow.',
                    [':domain' => $displayName],
                );
            }

            return RenewalDecision::PayAutoRenewGrace;
        }
        if ($info->expiresAt !== null && $info->expiresAt - $knownExpiry >= ($years * 365 - self::EXPIRY_MATCH_SLACK_DAYS) * self::DAY) {
            return RenewalDecision::AlreadyApplied;
        }

        return RenewalDecision::Renew;
    }
}
