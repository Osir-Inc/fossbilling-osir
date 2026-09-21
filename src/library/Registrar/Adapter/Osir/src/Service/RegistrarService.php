<?php

declare(strict_types=1);

namespace Osir\FossBilling\Service;

use Osir\FossBilling\Config\Settings;
use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Domain\Nameservers;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Http\ApiClient;
use Osir\FossBilling\Http\ApiRequest;
use Osir\FossBilling\Http\ApiResponse;
use Osir\FossBilling\Mapping\Availability;
use Osir\FossBilling\Mapping\AvailabilityState;
use Osir\FossBilling\Mapping\ContactData;
use Osir\FossBilling\Mapping\ContactMapper;
use Osir\FossBilling\Mapping\DomainInfo;
use Osir\FossBilling\Mapping\DomainInfoParser;
use Osir\FossBilling\Support\SafeLogger;

/**
 * Registrar use cases against the OSIR API. Knows nothing about FOSSBilling's classes; the
 * adapter translates in and out.
 *
 * Money-safety rules implemented here:
 *   - premium names are refused (FOSSBilling has no premium pricing, so the reseller would sell
 *     at the standard price and be charged the premium cost);
 *   - an optional per-year cost cap is checked against OSIR's quote, failing closed on any doubt;
 *   - register/renew/transfer carry an Idempotency-Key and re-check the registry state, so a
 *     retry never registers, renews or transfers twice ({@see RenewalPolicy} for renewals);
 *   - "already done" is proven by OSIR's idempotency store (a replayed success for this order's
 *     key); a domain that another order, or the reseller by hand, put in the account is refused
 *     for an administrator instead of being attached to this order.
 */
final class RegistrarService
{
    private const int MAX_YEARS = 10;
    /** How many idempotency keys to try when OSIR keeps replaying a stored 5xx for earlier ones. */
    private const int MAX_KEY_ATTEMPTS = 5;
    /** Clock skew tolerated between FOSSBilling's order time and the registry's creation date. */
    private const int ORDER_CLOCK_SLACK_SECONDS = 3600;

    private const string P_AVAILABLE = '/v2/domains/{domain}/available';
    private const string P_QUOTE = '/v2/domains/{domain}/quote';
    private const string P_RENEWAL_QUOTE = '/v2/domains/{domain}/renewal-quote';
    private const string P_TRANSFER_QUOTE = '/v2/transfer/{domain}/quote';
    private const string P_INFO = '/v2/domains/{domain}/info';
    private const string P_RENEW = '/v2/domains/{domain}/renew';
    private const string P_NAMESERVERS = '/v2/domains/{domain}/nameservers';
    private const string P_CONTACTS = '/v2/domains/{domain}/contacts';
    private const string P_AUTHCODE = '/v2/domains/{domain}/authcode';
    private const string P_LOCK = '/v2/domains/{domain}/lock';
    private const string P_UNLOCK = '/v2/domains/{domain}/unlock';
    private const string P_PRIVACY_ON = '/v2/domains/{domain}/privacy/enable';
    private const string P_PRIVACY_OFF = '/v2/domains/{domain}/privacy/disable';
    private const string P_TRANSFER_STATUS = '/v2/transfer/{domain}/status';
    private const string P_REGISTER = '/v2/domains/register';
    private const string P_TRANSFER = '/v2/transfer/initiate';
    private const string P_BALANCE = '/v1/payment/balance';

    public function __construct(
        private readonly ApiClient $api,
        private readonly Settings $settings,
        private readonly SafeLogger $log,
    ) {}

    // ------------------------------------------------------------------ queries

    public function checkAvailability(DomainName $domain): Availability
    {
        $response = $this->api->send(ApiRequest::get(ApiRequest::path(self::P_AVAILABLE, $domain))->unwrapped());
        $availability = Availability::fromResponse($response->data, $response->requestId);

        if ($availability->state === AvailabilityState::Available && $availability->premium && $this->allowsPremiumWithinCap()) {
            // The customer pays before the name is registered, so a premium name that registration
            // would refuse must not reach the cart: they would be charged for an order that can
            // only be cancelled by an administrator.
            $cap = $this->settings->maxYearlyCostCents;
            assert($cap !== null);
            $price = $availability->totalPriceCents;
            if ($price === null || $price > $cap) {
                $this->log->info(sprintf('%s not offered: premium name priced at %s, above the cost limit of %d cents per year.', $domain, $price === null ? 'an unknown amount' : $price . ' cents', $cap));

                throw new RuleException(':domain is a premium domain priced above the limit set by the administrator.', [':domain' => $domain->unicode()]);
            }
        }

        return $availability;
    }

    /**
     * Current registry state. A domain being transferred in is reported as pendingTransfer
     * instead of "not found", so FOSSBilling's sync does not treat it as an error.
     */
    public function details(DomainName $domain): DomainInfo
    {
        $info = $this->infoOrNull($domain);
        if ($info !== null) {
            return $info;
        }
        if ($this->hasPendingIncomingTransfer($domain)) {
            return DomainInfo::pendingTransfer($domain->ascii());
        }

        throw new RuleException(':domain is not registered in this OSIR account.', [':domain' => $domain->unicode()]);
    }

    public function authCode(DomainName $domain): string
    {
        $data = $this->api->send(ApiRequest::get(ApiRequest::path(self::P_AUTHCODE, $domain)))->data;
        $code = $data['authCode'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new RuleException('The transfer code for :domain is not available right now. Please try again later.', [':domain' => $domain->unicode()]);
        }

        return $code;
    }

    /**
     * Whether a transfer to OSIR can be attempted. OSIR has no eligibility endpoint, so this checks
     * what can be checked cheaply: the TLD is supported, the name is registered, and it is not
     * already in this account. Registry-side conditions (60-day lock, clientTransferProhibited,
     * wrong auth code) are only reported when the transfer is initiated.
     */
    public function canTransfer(DomainName $domain): bool
    {
        try {
            $this->api->send(ApiRequest::get(ApiRequest::path(self::P_TRANSFER_QUOTE, $domain), ['years' => 1])->unwrapped());
        } catch (ApiException $e) {
            if ($e->getKind() === ApiErrorKind::NotFound) {
                throw new RuleException('Transfers of :tld domains are not supported.', [':tld' => '.' . $domain->tld()]);
            }

            throw $e;
        }

        $availability = $this->checkAvailability($domain);
        if ($availability->state === AvailabilityState::Available) {
            throw new RuleException(':domain is not registered, so it cannot be transferred. It can be registered instead.', [':domain' => $domain->unicode()]);
        }
        if ($availability->state === AvailabilityState::Unknown) {
            throw new RuleException('Could not verify :domain right now. Please try again in a few minutes.', [':domain' => $domain->unicode()]);
        }
        $info = $this->infoOrNull($domain);
        if ($info !== null && !$info->status->isGone()) {
            throw new RuleException(':domain is already managed by this registrar.', [':domain' => $domain->unicode()]);
        }

        return true;
    }

    /** Account balance in USD cents (diagnostics). */
    public function balanceCents(): int
    {
        $data = $this->api->send(ApiRequest::get(self::P_BALANCE))->data;
        $balance = $data['balance'] ?? null;
        if (!is_int($balance) && !is_float($balance) && !(is_string($balance) && is_numeric($balance))) {
            throw new ApiException(ApiErrorKind::Protocol, 200, null, 'Balance response lacks "balance".', 'n/a');
        }

        return (int) round(((float) $balance) * 100);
    }

    // ------------------------------------------------------------------ money operations
    //
    // "Did this order already do it?" is answered by OSIR's idempotency store, not by guessing from
    // registry state: a keyed request whose answer is a REPLAYED success proves that this very order
    // did it. Registry-state heuristics are only a fallback for the rare cases where the store
    // cannot answer (stored 5xx, oversized stored body).

    /**
     * @param OrderRef|null $order the FOSSBilling order; without it the call carries no Idempotency-Key
     *
     * @return bool true if registered now, false if an earlier attempt of the same order already did
     */
    public function register(DomainName $domain, int $years, Nameservers $nameservers, ContactData $contact, ?OrderRef $order): bool
    {
        self::assertYears($years);
        $registrant = ContactMapper::toRegistrant($contact, $this->contactExternalId($domain));

        $availability = $this->checkAvailability($domain);
        if ($availability->state === AvailabilityState::Unknown) {
            throw $this->unconfirmedAvailability($domain);
        }
        if ($availability->state === AvailabilityState::Registered && $order === null) {
            throw $this->notAvailable($domain);
        }
        if ($availability->state === AvailabilityState::Available) {
            $this->assertWithinCostCap([[ApiRequest::get(ApiRequest::path(self::P_QUOTE, $domain), ['years' => $years])->unwrapped(), 'totalFees', null]], $years, $domain, $availability->premium);
        }
        // Registered + order: the keyed request below is sent anyway. If this order registered the
        // name earlier (lost response), OSIR replays that success; otherwise OSIR refuses without charging.

        $body = [
            'domain' => $domain->ascii(),
            'period' => $years,
            'nameservers' => $nameservers->toArray(),
            'registrant' => $registrant,
            // FOSSBilling owns the renewal cycle; never let OSIR renew (and charge) on its own.
            'autoRenew' => false,
            'initializeDnsZone' => $this->settings->initializeDnsZone,
            'environment' => $this->settings->environment->apiValue(),
        ];

        try {
            $response = $this->sendMoneyCall(
                fn(int $attempt): ApiRequest => ApiRequest::post(
                    self::P_REGISTER,
                    $body,
                    $order !== null ? IdempotencyKeys::register($this->settings->installationId, $this->settings->environment, $order, $domain, $years, $attempt) : null,
                ),
                $order !== null,
                fn(): bool => $this->createdSinceOrder($domain, $order),
            );
        } catch (ApiException $e) {
            $message = strtolower((string) $e->getUpstreamMessage());
            if (str_contains($message, 'error checking availability')) {
                throw $this->unconfirmedAvailability($domain, $e);
            }
            if ($e->getErrorCode() === 'IDEMPOTENT_REPLAY_UNAVAILABLE' && $this->createdSinceOrder($domain, $order)) {
                // OSIR recorded a success for this key but could not store its body.
                $this->log->warning(sprintf('%s: OSIR confirmed an earlier registration for this order without its details.', $domain));

                return false;
            }
            if ($e->getKind() === ApiErrorKind::Conflict || ($e->getKind() === ApiErrorKind::Rejected && str_contains($message, 'already exists'))) {
                throw $this->alreadyTaken($domain, $e);
            }

            throw $e;
        }
        if ($response === null) {
            return false;
        }

        $this->log->info(sprintf('Registered %s for %d year(s)%s (ref %s)', $domain, $years, $response->replayed ? ' [replay of an earlier attempt of this order]' : '', $response->requestId));

        return !$response->replayed;
    }

    /**
     * @param int|null $knownExpiry the expiry date FOSSBilling has for the domain (Unix time); used to
     *                              compare with the registry, NOT as the retry anchor (a sync overwrites it)
     *
     * @return bool true if renewed (or the auto-renew grace paid) now, false if an earlier attempt already did
     */
    public function renew(DomainName $domain, int $years, ?int $knownExpiry, ?OrderRef $order): bool
    {
        self::assertYears($years);
        if ($order !== null && $order->expiresAt === null) {
            // The retry key is anchored to the ORDER's expiry. Without it the only other anchor is the
            // domain's expiry, which a sync can move between a lost answer and the retry (FOSSBilling's
            // cron batch sync does not tell the adapter which order it syncs, so the sync freeze cannot
            // help there) — and a moved anchor means a second, charged renewal. Refuse instead.
            throw new RuleException(
                'Order #:order has no expiry date in FOSSBilling, so a repeated renewal could not be recognised safely. Set the order\'s billing period and expiry date, then renew again.',
                [':order' => $order->id],
            );
        }
        $decision = RenewalPolicy::decide($this->info($domain), $knownExpiry, $years, $domain->unicode());
        if ($decision === RenewalDecision::AlreadyApplied) {
            $this->log->warning(sprintf('%s: OSIR expiry is already %d year(s) past FOSSBilling\'s; treating the renewal as already applied.', $domain, $years));

            return false;
        }
        if ($knownExpiry === null) {
            throw new \LogicException('RenewalPolicy must refuse renewals without a known expiry.');
        }

        $env = $this->settings->environment->apiValue();
        $quotes = [[ApiRequest::get(ApiRequest::path(self::P_RENEWAL_QUOTE, $domain), ['years' => $years, 'environment' => $env]), 'totalWithRestore', 'renewalYears']];
        if ($decision === RenewalDecision::PayAutoRenewGrace) {
            // OSIR prices the grace payment on the registration basis; cap against the higher of both.
            $quotes[] = [ApiRequest::get(ApiRequest::path(self::P_QUOTE, $domain), ['years' => 1])->unwrapped(), 'totalFees', null];
        }
        $this->assertWithinCostCap($quotes, $years, $domain);

        // The retry anchor is the ORDER's expiry: FOSSBilling moves it only after a successful renew
        // action, whereas the domain's expiry is overwritten by every sync. A retry after a sync thus
        // still reuses the key and gets the earlier attempt replayed instead of a second renewal.
        $anchor = $order?->expiresAt;

        $response = $this->sendMoneyCall(
            fn(int $attempt): ApiRequest => ApiRequest::post(
                ApiRequest::path(self::P_RENEW, $domain),
                ['period' => $years, 'environment' => $env],
                $order !== null && $anchor !== null ? IdempotencyKeys::renew($this->settings->installationId, $this->settings->environment, $order, $domain, $years, $anchor, $attempt) : null,
            ),
            $order !== null,
            $decision === RenewalDecision::PayAutoRenewGrace
                ? fn(): bool => !$this->info($domain)->inAutoRenewGrace
                : fn(): bool => RenewalPolicy::decide($this->info($domain), $knownExpiry, $years, $domain->unicode()) === RenewalDecision::AlreadyApplied,
        );
        if ($response === null) {
            return false;
        }

        $this->log->info(sprintf(
            '%s %s for %d year(s)%s (ref %s)',
            $decision === RenewalDecision::PayAutoRenewGrace ? 'Paid the registry auto-renewal of' : 'Renewed',
            $domain,
            $years,
            $response->replayed ? ' [replay of an earlier attempt of this order]' : '',
            $response->requestId,
        ));

        return !$response->replayed;
    }

    /**
     * @return bool true if a transfer was initiated now, false if an earlier attempt of the same order already did
     */
    public function transfer(DomainName $domain, string $authCode, int $years, ContactData $contact, ?OrderRef $order): bool
    {
        self::assertYears($years);
        $authCode = self::assertAuthCode($authCode);
        $registrant = ContactMapper::toRegistrant($contact, $this->contactExternalId($domain));
        $this->assertWithinCostCap([[ApiRequest::get(ApiRequest::path(self::P_TRANSFER_QUOTE, $domain), ['years' => $years])->unwrapped(), 'totalFees', 'transferYears']], $years, $domain);

        $body = [
            'domain' => $domain->ascii(),
            'authCode' => $authCode,
            'period' => $years,
            'registrant' => $registrant,
            'environment' => $this->settings->environment->apiValue(),
        ];

        try {
            $response = $this->sendMoneyCall(
                fn(int $attempt): ApiRequest => ApiRequest::post(
                    self::P_TRANSFER,
                    $body,
                    $order !== null ? IdempotencyKeys::transfer($this->settings->installationId, $this->settings->environment, $order, $domain, $attempt) : null,
                )->unwrapped(),
                $order !== null,
                fn(): bool => $this->hasPendingIncomingTransfer($domain),
            );
        } catch (ApiException $e) {
            if ($e->getKind() === ApiErrorKind::InvalidAuthCode) {
                throw new ValidationException('The transfer code for :domain was rejected by the registry. Please check it and try again.', [':domain' => $domain->unicode()], 0, $e);
            }
            if ($e->getErrorCode() === 'TRANSFER_EXISTS') {
                // Not this order's (a replay would have answered): started elsewhere. Never adopt it silently.
                throw new RuleException('A transfer of :domain is already pending in this registrar account. An administrator must link it to the order manually.', [':domain' => $domain->unicode()], 0, $e);
            }
            if ($e->getKind() === ApiErrorKind::Rejected && str_contains(strtolower((string) $e->getUpstreamMessage()), 'already')) {
                throw new RuleException(':domain is already in this registrar account. An administrator must link it to the order manually.', [':domain' => $domain->unicode()], 0, $e);
            }

            throw $e;
        }
        if ($response === null) {
            return false;
        }

        $this->log->info(sprintf('Transfer of %s initiated%s (ref %s)', $domain, $response->replayed ? ' [replay of an earlier attempt of this order]' : '', $response->requestId));

        return !$response->replayed;
    }

    // ------------------------------------------------------------------ management

    /** @return bool false when the registry already has exactly these nameservers (no call made) */
    public function updateNameservers(DomainName $domain, Nameservers $nameservers): bool
    {
        // OSIR replaces the whole set in one registry command (remove all + add all); some
        // registries reject removing and adding the same host, so an unchanged set is not sent.
        $current = $this->info($domain)->nameservers;
        $wanted = $nameservers->toArray();
        $sortedCurrent = $current;
        $sortedWanted = $wanted;
        sort($sortedCurrent);
        sort($sortedWanted);
        if ($sortedCurrent === $sortedWanted) {
            return false;
        }

        $this->api->send(ApiRequest::put(
            ApiRequest::path(self::P_NAMESERVERS, $domain),
            ['nameservers' => $wanted, 'replaceAll' => true, 'environment' => $this->settings->environment->apiValue()],
        ));
        $this->log->info(sprintf('Updated nameservers of %s', $domain));

        return true;
    }

    /**
     * Known OSIR limitation (documented for partners): OSIR stores the new contact, but pushes it
     * to the registry only for some TLDs, and it matches stored contacts by e-mail address.
     */
    public function updateContact(DomainName $domain, ContactData $contact): void
    {
        $payload = ContactMapper::toRegistrant($contact);
        $this->api->send(ApiRequest::put(
            ApiRequest::path(self::P_CONTACTS, $domain),
            ['registrant' => $payload, 'admin' => $payload, 'tech' => $payload, 'billing' => $payload],
        ));
        $this->log->warning(sprintf('Updated contacts of %s at OSIR. Registry WHOIS data may not change for every TLD; see the adapter documentation.', $domain));
    }

    public function setTransferLock(DomainName $domain, bool $locked): void
    {
        $this->api->send(ApiRequest::post(
            path: ApiRequest::path($locked ? self::P_LOCK : self::P_UNLOCK, $domain),
            body: [],
            query: ['environment' => $this->settings->environment->apiValue()],
            timeoutSeconds: 60.0,
        ));
        $this->log->info(sprintf('%s %s', $locked ? 'Locked' : 'Unlocked', $domain));
    }

    public function setPrivacy(DomainName $domain, bool $enabled): void
    {
        $this->api->send(ApiRequest::post(
            path: ApiRequest::path($enabled ? self::P_PRIVACY_ON : self::P_PRIVACY_OFF, $domain),
            body: [],
            timeoutSeconds: 60.0,
        ));
        $this->log->info(sprintf('Privacy %s for %s', $enabled ? 'enabled' : 'disabled', $domain));
    }

    // ------------------------------------------------------------------ internals

    /**
     * Sends a register/renew/transfer call. When OSIR answers with a REPLAYED 5xx — the stored
     * outcome of an earlier request with the same key, which it would repeat for 30 days — the
     * registry state is re-checked first: if the earlier attempt did go through, that is success;
     * otherwise the call is repeated under the next idempotency key. Rotation state is not stored:
     * each FOSSBilling retry walks the chain again from the first key.
     *
     * @param \Closure(int): ApiRequest $build       builds the request for a key attempt (1, 2, …)
     * @param \Closure(): bool          $alreadyDone whether the registry state shows the operation happened;
     *                                               any exception it throws propagates (fail closed)
     *
     * @return ApiResponse|null null when an earlier attempt turned out to have succeeded
     */
    private function sendMoneyCall(\Closure $build, bool $keyed, \Closure $alreadyDone): ?ApiResponse
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->api->send($build($attempt));
            } catch (ApiException $e) {
                if (!$keyed || !$e->isReplayed() || $e->getKind() !== ApiErrorKind::Server) {
                    throw $e;
                }
                if ($alreadyDone()) {
                    $this->log->warning(sprintf('OSIR replayed a stored error for key attempt %d, but the registry shows the operation succeeded (ref %s).', $attempt, $e->getRequestId()));

                    return null;
                }
                if ($attempt >= self::MAX_KEY_ATTEMPTS) {
                    throw new RuleException(
                        'OSIR keeps reporting an earlier failure for this order. Please contact OSIR support with reference :ref.',
                        [':ref' => $e->getRequestId()],
                        0,
                        $e,
                    );
                }
                $this->log->warning(sprintf('OSIR replayed a stored error for key attempt %d; retrying under a new idempotency key (ref %s).', $attempt, $e->getRequestId()));
            }
        }
    }

    private function info(DomainName $domain): DomainInfo
    {
        $response = $this->api->send(ApiRequest::get(ApiRequest::path(self::P_INFO, $domain), ['environment' => $this->settings->environment->apiValue()]));

        return DomainInfoParser::parse($response->data, $response->requestId);
    }

    /** The domain's info, or null when it is not in this account (unknown to OSIR, or held by another OSIR customer). */
    private function infoOrNull(DomainName $domain): ?DomainInfo
    {
        try {
            return $this->info($domain);
        } catch (ApiException $e) {
            if ($e->getKind() === ApiErrorKind::NotFound || self::isForeignOwnership($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * FALLBACK heuristic, used only when OSIR's idempotency store cannot answer (a stored 5xx, or a
     * success whose body was too large to store): the domain is in this account and was created no
     * earlier than the order. It cannot tell two orders for the same name apart, so it is never the
     * primary check.
     */
    private function createdSinceOrder(DomainName $domain, ?OrderRef $order): bool
    {
        if ($order === null || $order->createdAt === null) {
            return false;
        }
        $info = $this->infoOrNull($domain);

        return $info !== null
            && !$info->status->isGone()
            && $info->createdAt !== null
            && $info->createdAt >= $order->createdAt - self::ORDER_CLOCK_SLACK_SECONDS;
    }

    /** Explains why a registration was refused: taken by someone else, or already in this account. */
    private function alreadyTaken(DomainName $domain, \Throwable $previous): RuleException
    {
        $info = $this->infoOrNull($domain);
        if ($info !== null && !$info->status->isGone()) {
            return new RuleException(
                ':domain is already in this registrar account but was not registered by this order. An administrator must link it manually.',
                [':domain' => $domain->unicode()],
                0,
                $previous,
            );
        }

        return $this->notAvailable($domain, $previous);
    }

    private function notAvailable(DomainName $domain, ?\Throwable $previous = null): RuleException
    {
        return new RuleException(':domain is no longer available for registration.', [':domain' => $domain->unicode()], 0, $previous);
    }

    private function hasPendingIncomingTransfer(DomainName $domain): bool
    {
        try {
            $data = $this->api->send(ApiRequest::get(ApiRequest::path(self::P_TRANSFER_STATUS, $domain))->unwrapped())->data;
        } catch (ApiException $e) {
            if ($e->getKind() === ApiErrorKind::NotFound) {
                return false;
            }

            throw $e;
        }

        return ($data['transferType'] ?? null) === 'GAINING';
    }

    /** OSIR's ownership helper answers 403 "not the owner" for a domain held by another OSIR customer. */
    private static function isForeignOwnership(ApiException $e): bool
    {
        return $e->getKind() === ApiErrorKind::Permission && str_contains(strtolower((string) $e->getUpstreamMessage()), 'not the owner');
    }



    /**
     * Premium names are refused unless the administrator both turned them on AND set a cost limit.
     *
     * The limit is what makes it safe: FOSSBilling sells every name of a TLD at one price, so a
     * premium name is only sound business when its cost stays under a number the administrator
     * chose — set it at or below what you charge for the TLD. Without a limit there is nothing to
     * judge the price against, so the name is refused.
     */
    private function allowsPremiumWithinCap(): bool
    {
        return $this->settings->allowCheaperPremium && $this->settings->maxYearlyCostCents !== null;
    }

    /**
     * Refuses when OSIR's quote for this operation exceeds the configured cap. With several quotes
     * the highest total counts. Fails closed on any doubt — missing or non-positive total, premium
     * flag, period mismatch, or a warning (OSIR answers some failed quotes with HTTP 200 and zero).
     *
     * @param list<array{0: ApiRequest, 1: string, 2: string|null}> $quotes request, total field, period field
     */
    private function assertWithinCostCap(array $quotes, int $years, DomainName $domain, bool $premium = false): void
    {
        $cap = $this->settings->maxYearlyCostCents;
        if ($premium && !$this->allowsPremiumWithinCap()) {
            // Refused on the availability answer alone: no quote is needed, and none is fetched.
            throw new RuleException(':domain is a premium domain. Premium domains cannot be registered through this registrar.', [':domain' => $domain->unicode()]);
        }

        if ($cap === null) {
            return;
        }

        $perYear = 0;
        foreach ($quotes as [$request, $totalField, $yearsField]) {
            $quote = $this->api->send($request)->data;
            $total = $quote[$totalField] ?? null;
            $quotedYears = $yearsField === null ? $years : ($quote[$yearsField] ?? null);
            $isPremium = $premium || ($quote['premium'] ?? false) === true;
            $doubtful = !is_int($total)
                || $total <= 0
                || ($isPremium && !$this->allowsPremiumWithinCap())
                || !is_int($quotedYears) || $quotedYears < 1
                || ($yearsField !== null && $quotedYears !== $years)
                || (is_string($quote['warning'] ?? null) && trim($quote['warning']) !== '');
            if ($doubtful) {
                $this->log->warning(sprintf('%s refused: OSIR returned no usable price to check against the cost cap.', $domain));

                throw new RuleException('Could not confirm the price of :domain, so it was not processed. Please contact support.', [':domain' => $domain->unicode()]);
            }
            $perYear = max($perYear, intdiv($total + $quotedYears - 1, $quotedYears));
        }

        if ($perYear > $cap) {
            $this->log->warning(sprintf('%s refused: OSIR cost of %d cents per year exceeds the configured cap of %d cents per year.', $domain, $perYear, $cap));

            throw new RuleException('The current price of :domain is above the limit set by the administrator, so it was not processed. Please contact support.', [':domain' => $domain->unicode()]);
        }
    }

    private function unconfirmedAvailability(DomainName $domain, ?\Throwable $previous = null): RuleException
    {
        return new RuleException('Could not confirm that :domain is available. Please try again in a few minutes.', [':domain' => $domain->unicode()], 0, $previous);
    }

    /**
     * One OSIR contact per domain and environment (OSIR updates a contact with the same externalId
     * in place, so a per-customer id would let one domain's edit rewrite another domain's registrant).
     */
    private function contactExternalId(DomainName $domain): string
    {
        return 'fb:' . $this->settings->installationId . ':' . $this->settings->environment->value . ':' . $domain->ascii();
    }

    private static function assertYears(int $years): void
    {
        if ($years < 1 || $years > self::MAX_YEARS) {
            throw new ValidationException('The registration period must be between 1 and :max years.', [':max' => (string) self::MAX_YEARS]);
        }
    }

    private static function assertAuthCode(string $authCode): string
    {
        $authCode = trim($authCode);
        // EPP authInfo is 6-64 chars in practice (RFC 5731 allows longer); refuse control characters and absurd lengths.
        if ($authCode === '' || strlen($authCode) > 128 || preg_match('/[\x00-\x1F\x7F]/', $authCode) === 1) {
            throw new ValidationException('The transfer (EPP) code is missing or invalid.');
        }

        return $authCode;
    }
}
