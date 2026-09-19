<?php

declare(strict_types=1);

/**
 * OSIR domain registrar adapter for FOSSBilling.
 *
 * Copyright OSIR Inc. Licensed under the Apache License, Version 2.0.
 * Documentation: https://github.com/osir/fossbilling-registrar
 *
 * This class is deliberately thin: it translates FOSSBilling's Registrar_Domain objects into the
 * adapter's validated types, calls {@see Osir\FossBilling\Service\RegistrarService}, and converts
 * every internal failure into a Registrar_Exception whose text is safe to show to end clients.
 * Upstream error details (which can include the reseller's balance) go to the log only.
 */

use Osir\FossBilling\Config\Settings;
use Osir\FossBilling\Config\SettingsResolver;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\ConfigurationException;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Exception\TransportException;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Http\ApiClient;
use Osir\FossBilling\Http\RetryPolicy;
use Osir\FossBilling\Import\ImportService;
use Osir\FossBilling\Mapping\AvailabilityState;
use Osir\FossBilling\Mapping\DomainStatus;
use Osir\FossBilling\Mapping\FossBillingMapper as Map;
use Osir\FossBilling\Service\Diagnostics;
use Osir\FossBilling\Service\RegistrarService;
use Osir\FossBilling\Support\SafeLogger;

// Requested directly over HTTP (e.g. nginx without FOSSBilling's rewrite rules), the parent class is
// missing: stop quietly instead of a fatal error that could disclose the file system path.
if (!class_exists(Registrar_AdapterAbstract::class)) {
    return;
}

require_once __DIR__ . '/Osir/autoload.php';

class Registrar_Adapter_Osir extends Registrar_AdapterAbstract
{
    /** A registry expiry this far past FOSSBilling's means a renewal happened (about half a year). */
    private const int EXPIRY_JUMP_SECONDS = 180 * 86400;

    /** Settings fields that hold credentials; dropped from memory once the service is built. */
    private const array SECRET_FIELDS = ['api_key', 'api_key_test'];

    /** @var array<array-key, mixed> */
    private array $options;

    private ?RegistrarService $service = null;
    private ?Settings $settings = null;
    private ?SafeLogger $safeLog = null;

    /**
     * FOSSBilling constructs the adapter before calling enableTestMode(), so nothing that depends
     * on the mode (key selection, environment) may be resolved here; see {@see self::service()}.
     *
     * @param array<array-key, mixed>|mixed $options stored registrar settings
     */
    public function __construct(#[\SensitiveParameter] $options)
    {
        $this->options = is_array($options) ? $options : [];
    }

    /**
     * @return array{label: string, form: array<string, array{0: string, 1: array<string, mixed>}>}
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'Registers and manages domains through OSIR (osir.com). Create API keys in the OSIR panel under API keys. '
                . 'Test Mode uses the OSIR sandbox (OTE) with an osir_test_ key. For better security, keep the keys out of the database: '
                . 'add define() lines at the top of config.php instead (see the adapter documentation).',
            'form' => [
                'api_key' => ['password', [
                    'label' => 'Live API key',
                    'description' => 'Starts with `osir_live_`. Leave empty if `OSIR_REGISTRAR_API_KEY` is defined in config.php.',
                    'required' => false,
                    'secret' => true,
                ]],
                'api_key_test' => ['password', [
                    'label' => 'Sandbox API key',
                    'description' => 'Starts with `osir_test_`. Used only while Test Mode is on. Leave empty if `OSIR_REGISTRAR_API_KEY_TEST` is defined in config.php.',
                    'required' => false,
                    'secret' => true,
                ]],
                'max_yearly_cost' => ['text', [
                    'label' => 'Maximum cost per year (USD)',
                    'description' => 'Optional safety limit. Registrations, renewals and transfers whose OSIR cost per year (including fees) is higher are refused. Leave empty for no limit.',
                    'required' => false,
                ]],
                'initialize_dns_zone' => ['radio', [
                    'label' => 'Create DNS zone at OSIR',
                    'description' => 'Create a DNS zone at OSIR for newly registered domains. Choose Yes only if your domains use OSIR nameservers.',
                    'multiOptions' => ['0' => 'No', '1' => 'Yes'],
                    'default' => '0',
                ]],
                'debug_logging' => ['radio', [
                    'label' => 'Debug logging',
                    'description' => 'Log every OSIR request (method, path, status, duration). Request and response bodies are never logged.',
                    'multiOptions' => ['0' => 'No', '1' => 'Yes'],
                    'default' => '0',
                ]],
            ],
        ];
    }

    /**
     * Masked in FOSSBilling's API and admin UI (FOSSBilling >= 0.8.7 also honours 'secret' => true).
     *
     * @return string[]
     */
    public static function getSecretFields(): array
    {
        return self::SECRET_FIELDS;
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $name = Map::domainName($domain);
            $availability = $this->service()->checkAvailability($name);

            return match ($availability->state) {
                AvailabilityState::Unknown => throw new RuleException('Could not check the availability of :domain right now. Please try again in a few minutes.', [':domain' => $name->unicode()]),
                AvailabilityState::Available => $availability->premium
                    ? throw new RuleException(':domain is a premium domain. Premium domains cannot be registered through this registrar.', [':domain' => $name->unicode()])
                    : true,
                AvailabilityState::Registered => false,
            };
        });
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        return $this->guard(fn(): bool => $this->service()->canTransfer(Map::domainName($domain)));
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->updateNameservers(Map::domainName($domain), Map::nameservers($domain));

            return true;
        });
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->updateContact(Map::domainName($domain), Map::contact($domain));

            return true;
        });
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->transfer(
                Map::domainName($domain),
                is_string($epp = $domain->getEpp()) ? $epp : '',
                Map::years($domain),
                Map::contact($domain),
                Map::orderRef($this->_order),
            );

            return true;
        });
    }

    /**
     * A domain that is no longer in this OSIR account (transferred away, deleted, never registered)
     * is returned with FOSSBilling's own data unchanged and an error in the log, instead of an
     * exception: FOSSBilling's expiry batch sync only records success when EVERY domain synced, so a
     * single gone domain would otherwise make it re-sync all domains on every cron tick (web or CLI
     * cron alike), burning OSIR's rate limit. The price is that a manual "Sync" of such a domain
     * reports success; the log says why.
     */
    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        return $this->guard(function () use ($domain): Registrar_Domain {
            $name = Map::domainName($domain);
            try {
                $info = $this->service()->details($name);
            } catch (RuleException $e) {
                return $this->unchangedBecauseGone($domain, $name->ascii(), $e->getMessage());
            }
            if ($info->status->isGone()) {
                return $this->unchangedBecauseGone(
                    $domain,
                    $name->ascii(),
                    $info->status === DomainStatus::TransferredOut ? 'transferred to another registrar' : 'no longer registered',
                );
            }

            // While a renewal of this order is unresolved (FOSSBilling marked it failed_renew), do not
            // copy a registry expiry that has jumped ahead: that jump may be our own renewal whose answer
            // was lost. Keeping FOSSBilling's date lets the retry recognise the renewal as already
            // applied instead of renewing — and charging — a second time. Normal syncs resume once the
            // order is active again.
            $order = Map::orderRef($this->_order);
            $known = Map::expiry($domain);
            $keepExpiry = $order !== null && $order->hasUnresolvedRenewal()
                && $known !== null && $info->expiresAt !== null
                && $info->expiresAt - $known > self::EXPIRY_JUMP_SECONDS;
            if ($keepExpiry) {
                $this->log()->warning(sprintf('%s: renewal of order #%s is unresolved; not syncing the registry expiry (%s) until the renewal is retried.', $name, $order->id, gmdate('Y-m-d', (int) $info->expiresAt)));
            }

            return Map::applyInfo($domain, $info, $keepExpiry);
        });
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return $this->guard(fn(): string => $this->service()->authCode(Map::domainName($domain)));
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->register(
                Map::domainName($domain),
                Map::years($domain),
                Map::nameservers($domain),
                Map::contact($domain),
                Map::orderRef($this->_order),
            );

            return true;
        });
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->renew(
                Map::domainName($domain),
                Map::years($domain),
                Map::expiry($domain),
                Map::orderRef($this->_order),
            );

            return true;
        });
    }

    /**
     * OSIR does not let API keys delete domains (deletion is an OSIR staff action, because it is
     * irreversible). FOSSBilling calls this when an order is cancelled; refusing would make orders
     * impossible to cancel, so the domain is left registered and the administrator is told in the log.
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $name = Map::domainName($domain);
            $this->log()->warning(sprintf(
                'FOSSBilling asked to delete %s (order cancelled). OSIR does not delete domains through the API: '
                . 'the domain stays registered until it expires. Contact OSIR support if it must be deleted within the grace period.',
                $name,
            ));

            return true;
        });
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->setPrivacy(Map::domainName($domain), true);

            return true;
        });
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->setPrivacy(Map::domainName($domain), false);

            return true;
        });
    }

    public function lock(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->setTransferLock(Map::domainName($domain), true);

            return true;
        });
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        return $this->guard(function () use ($domain): bool {
            $this->service()->setTransferLock(Map::domainName($domain), false);

            return true;
        });
    }

    /**
     * Builds the service on first use. Overridable so tests can inject a mock HTTP client through
     * {@see Registrar_AdapterAbstract::getHttpClient()}.
     *
     * @throws ConfigurationException
     */
    protected function service(): RegistrarService
    {
        if ($this->service === null) {
            $settings = SettingsResolver::fromRuntime()->resolve($this->options, (bool) $this->_testMode, self::installationSeed());
            // The key now lives only inside Settings (as a Secret); drop the raw copies.
            foreach (self::SECRET_FIELDS as $field) {
                unset($this->options[$field]);
            }
            $this->safeLog = new SafeLogger($this->getLog(), $settings->debug);
            $api = new ApiClient($this->getHttpClient(), $settings, $this->safeLog, RetryPolicy::forCurrentSapi());
            $this->service = new RegistrarService($api, $settings, $this->safeLog);
            $this->settings = $settings;
        }

        return $this->service;
    }

    /**
     * Read-only health checks, used by bin/osir-doctor.php.
     *
     * @throws ConfigurationException when the adapter cannot even be configured (the doctor reports it)
     */
    public function diagnostics(): Diagnostics
    {
        $service = $this->service();
        assert($this->settings !== null);

        return new Diagnostics($service, $this->settings);
    }

    /**
     * Read-only catalog, pricing and domain-listing queries for the "Import from OSIR" admin tools
     * (the companion FOSSBilling module in modules/Osir).
     *
     * @throws ConfigurationException when the adapter cannot be configured
     */
    public function importService(): ImportService
    {
        $this->service();
        assert($this->settings !== null && $this->safeLog !== null);

        return new ImportService(
            new ApiClient($this->getHttpClient(), $this->settings, $this->safeLog, RetryPolicy::forCurrentSapi()),
            $this->safeLog,
        );
    }

    private function unchangedBecauseGone(Registrar_Domain $domain, string $name, string $why): Registrar_Domain
    {
        $this->log()->error(sprintf('Sync of %s skipped: %s. FOSSBilling keeps its own data for this domain.', $name, $why));
        // FOSSBilling serialises this object into its database; never let a transfer code ride along.
        $domain->setEpp(null);

        return $domain;
    }

    private function log(): SafeLogger
    {
        return $this->safeLog ??= new SafeLogger($this->getLog());
    }

    /**
     * Runs one adapter operation and converts every failure into a Registrar_Exception.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     *
     * @throws Registrar_Exception
     */
    private function guard(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (ValidationException|RuleException $e) {
            // Written for end users by design; no upstream text inside.
            throw new Registrar_Exception($e->getTemplate(), $e->getVariables());
        } catch (ConfigurationException $e) {
            // Details for administrators only: availability checks run for anonymous visitors too.
            $this->log()->error('Configuration problem: ' . $e->getMessage());

            throw new Registrar_Exception('The domain registrar is not configured correctly. Administrators: see the system log for details.');
        } catch (TransportException $e) {
            $this->log()->error(sprintf('OSIR unreachable (ref %s): %s', $e->getRequestId(), $e->getMessage()));

            throw new Registrar_Exception(
                $e->isOutcomeUnknown()
                    ? 'The domain registrar did not answer in time. The request may still be processed; it is safe to retry in a few minutes. (Reference: :ref)'
                    : 'The domain registrar could not be reached. Please try again in a few minutes. (Reference: :ref)',
                [':ref' => $e->getRequestId()],
            );
        } catch (ApiException $e) {
            $this->log()->error(sprintf(
                'OSIR error %s (HTTP %d%s, ref %s): %s',
                $e->getKind()->value,
                $e->getHttpStatus(),
                $e->getErrorCode() !== null ? ', ' . $e->getErrorCode() : '',
                $e->getRequestId(),
                $e->getUpstreamMessage() ?? 'no message',
            ));

            throw new Registrar_Exception(self::publicMessage($e->getKind()) . ' (Reference: :ref)', [':ref' => $e->getRequestId()]);
        } catch (Throwable $e) {
            // Never leak internals (paths, SQL, stack traces) to clients.
            $this->log()->error(sprintf('Unexpected %s in OSIR adapter: %s', $e::class, $e->getMessage()));

            throw new Registrar_Exception('The domain registrar reported an unexpected error. Please contact support.');
        }
    }

    /** Client-safe wording per error kind. Account-level problems are deliberately vague for clients. */
    private static function publicMessage(ApiErrorKind $kind): string
    {
        return match ($kind) {
            ApiErrorKind::Authentication, ApiErrorKind::Permission, ApiErrorKind::AccountNotVerified,
            ApiErrorKind::InsufficientFunds, ApiErrorKind::SpendLimit => 'The domain registrar could not process this request right now. Please contact support.',
            ApiErrorKind::NotFound => 'The domain was not found at the registrar.',
            ApiErrorKind::Conflict => 'The registrar refused the request because of the domain\'s current state (for example a pending transfer, or an expired domain).',
            ApiErrorKind::InProgress => 'This request is still being processed by the registrar. Please check again in a few minutes.',
            ApiErrorKind::RateLimited => 'The domain registrar is busy. Please try again in a minute.',
            ApiErrorKind::InvalidAuthCode => 'The transfer code was rejected by the registry. Please check it and try again.',
            ApiErrorKind::Rejected => 'The domain registrar rejected the request.',
            ApiErrorKind::Server, ApiErrorKind::Protocol => 'The domain registrar reported a temporary error. Please try again later.',
        };
    }

    /**
     * Identifies this FOSSBilling installation in idempotency keys and contact ids. It must be the
     * same in web requests and cron: FOSSBilling's INSTANCE_ID (config info.instance_id) is, whereas
     * SYSTEM_URL is not (its scheme follows the request unless force_https is set).
     */
    private static function installationSeed(): string
    {
        $instance = defined('INSTANCE_ID') ? constant('INSTANCE_ID') : null;
        // Ignore FOSSBilling's defaults: 'Unknown' and the config-sample placeholder of X's.
        if (is_string($instance) && $instance !== '' && $instance !== 'Unknown' && preg_match('/^[X-]+$/', $instance) !== 1) {
            return 'instance:' . $instance;
        }
        $root = defined('PATH_ROOT') ? constant('PATH_ROOT') : null;

        return 'path:' . (is_string($root) && $root !== '' ? $root : __DIR__);
    }
}
