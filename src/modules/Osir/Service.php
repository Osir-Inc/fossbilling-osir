<?php

declare(strict_types=1);

/*
 * Copyright OSIR. SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Osir;

use Box\Mod\Client\Entity\Client as ClientEntity;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;
use Osir\FossBilling\Dns\DnsRecord;
use Osir\FossBilling\Dns\DnsService;
use Osir\FossBilling\Dns\RecordType;
use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\OsirException;
use Osir\FossBilling\Import\CatalogTld;
use Osir\FossBilling\Import\ImportService;
use Osir\FossBilling\Import\PriceRule;
use Osir\FossBilling\Import\RemoteDomain;
use Osir\FossBilling\Import\TldCost;
use Osir\FossBilling\Mapping\ContactData;
use Osir\FossBilling\Service\IdempotencyKeys;
use Osir\FossBilling\Service\OrderRef;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * "Import from OSIR": step 1 creates or re-prices FOSSBilling TLDs from OSIR's catalog with the
 * administrator's markup; step 2 turns domains already held in the OSIR account into active
 * FOSSBilling orders WITHOUT registering or charging anything.
 *
 * All OSIR traffic goes through the registrar adapter (same API key, TLS rules and logging).
 * Prices written to FOSSBilling are always recomputed here from OSIR's cost and the markup; the
 * browser only chooses which TLDs and which markup.
 *
 * @phpstan-type Prices array{register: string, renew: string, transfer: string}
 * @phpstan-type Existing array{id: int, registrar: string, same_registrar: bool, active: bool, price: Prices}
 * @phpstan-type TldRow array{tld: string, display?: string, cost?: Prices, price?: Prices, estimated?: bool, fossbilling?: Existing|null, planned?: 'create'|'update'|'skip', status?: string, message?: string}
 * @phpstan-type DomainResult array{domain: string, status: 'imported'|'skipped'|'failed', message?: string, order_id?: int}
 */
class Service implements InjectionAwareInterface
{
    /** Per API call, so each browser request stays well inside PHP and proxy time limits. */
    public const int MAX_TLDS_PER_CALL = 10;
    public const int MAX_DOMAINS_PER_CALL = 10;
    private const int CATALOG_TTL = 600;
    /** Preview and import within this window use the same OSIR quote, so what was shown is what is saved. */
    private const int COST_TTL = 3600;
    private const int DOMAINS_TTL = 300;
    /** Short: the DNS tab is per client and must show a change they just made. */
    private const int DNS_TTL = 20;

    protected ?\Pimple\Container $di = null;

    /** @var array<int, ImportService> one adapter per registrar and request */
    private array $importServices = [];

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /** @return array<string, array<string, string>> */
    public function getModulePermissions(): array
    {
        return [
            'import' => [
                'type' => 'bool',
                'display_name' => __trans('Use the OSIR import'),
                'description' => __trans('Allows the staff member to load the OSIR catalog and domain list. Importing also needs the "Manage TLDs" (TLDs) or order and domain management (domains) permissions.'),
            ],
        ];
    }

    public function install(): void {}

    public function uninstall(): void {}

    /**
     * Makes the adapter's classes loadable. The Api and Controller need them before any adapter
     * instance exists (FOSSBilling only loads an adapter when it builds one).
     */
    public function loadLibrary(): void
    {
        if (class_exists(PriceRule::class)) {
            return;
        }
        $file = PATH_LIBRARY . '/Registrar/Adapter/Osir/autoload.php';
        if (!is_file($file)) {
            throw new InformationException('The OSIR registrar adapter is not installed (library/Registrar/Adapter/Osir is missing).');
        }
        require_once $file;
    }

    // ------------------------------------------------------------------ registrars

    /** @return list<array{id: int, name: string, test_mode: bool}> */
    public function registrars(): array
    {
        $rows = $this->db()->getAll("SELECT id, name, test_mode FROM tld_registrar WHERE registrar = 'Osir' ORDER BY id");

        return array_values(array_map(static fn(array $r): array => [
            'id' => self::int($r['id'] ?? null),
            'name' => self::str($r['name'] ?? null),
            'test_mode' => (bool) ($r['test_mode'] ?? false),
        ], self::rows($rows)));
    }

    // ------------------------------------------------------------------ step 1: TLDs

    /** @return list<array<string, mixed>> */
    public function catalog(int $registrarId, bool $refresh = false): array
    {
        $registrar = $this->registrar($registrarId);
        $existing = $this->existingTlds();

        $rows = [];
        foreach ($this->cachedCatalog($registrar, $refresh) as $tld) {
            $rows[] = [
                'tld' => $tld->tld,
                'display' => self::displayTld($tld->tld),
                'type' => $tld->type,
                'min_years' => $tld->minYears,
                'max_years' => $tld->maxYears,
                'has_premium' => $tld->hasPremium,
                'has_restrictions' => $tld->hasRestrictions,
                'list_price' => self::prices($tld->registerCents, $tld->renewCents, $tld->transferCents),
                'fossbilling' => $this->existingRow($existing[$tld->tld] ?? null, $registrarId),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $tlds
     *
     * @return list<TldRow>
     */
    public function previewTlds(int $registrarId, array $tlds, PriceRule $rule): array
    {
        return $this->eachTld($registrarId, $tlds, $rule, static function (array $row): array {
            $row['status'] = $row['planned'] ?? 'skip';

            return $row;
        });
    }

    /**
     * @param list<string> $tlds
     *
     * @return list<TldRow>
     */
    public function importTlds(int $registrarId, array $tlds, PriceRule $rule, bool $updateExisting): array
    {
        $domains = $this->domainService();

        return $this->eachTld($registrarId, $tlds, $rule, function (array $row, TldCost $cost) use ($domains, $registrarId, $updateExisting): array {
            $price = $row['price'] ?? throw new \LogicException('price missing');
            $prices = ['price_registration' => $price['register'], 'price_renew' => $price['renew'], 'price_transfer' => $price['transfer']];
            $planned = $row['planned'] ?? 'skip';
            if ($planned === 'create' && $domains->tldFindOneByTld($row['tld']) instanceof \Model_Tld) {
                // Created meanwhile (another tab): never create a second row.
                $row['status'] = 'skipped';
                $row['message'] = __trans('Created meanwhile by another import; left unchanged.');

                return $row;
            }
            if ($planned === 'create') {
                $domains->tldCreate($prices + [
                    'tld' => $row['tld'],
                    'tld_registrar_id' => $registrarId,
                    'min_years' => $cost->catalog->minYears,
                    'allow_register' => true,
                    'allow_transfer' => true,
                    'require_transfer_code' => true,
                    'active' => true,
                ]);
                $row['status'] = 'created';
                $this->log(\Box_Log::INFO, sprintf('OSIR import: created TLD %s (%s / %s / %s)', $row['tld'], $price['register'], $price['renew'], $price['transfer']));
            } elseif ($planned === 'update' && $updateExisting) {
                $model = $domains->tldFindOneByTld($row['tld']);
                if (!$model instanceof \Model_Tld) {
                    throw new InformationException('TLD :tld disappeared while importing.', [':tld' => $row['tld']]);
                }
                $domains->tldUpdate($model, $prices);
                $row['status'] = 'updated';
                $this->log(\Box_Log::INFO, sprintf('OSIR import: re-priced TLD %s (%s / %s / %s)', $row['tld'], $price['register'], $price['renew'], $price['transfer']));
            } else {
                $row['status'] = 'skipped';
                $row['message'] ??= __trans('Already in FOSSBilling; prices left unchanged.');
            }

            return $row;
        });
    }

    // ------------------------------------------------------------------ step 2: domains

    /** @return list<array<string, mixed>> */
    public function remoteDomains(int $registrarId, bool $refresh = false): array
    {
        $registrar = $this->registrar($registrarId);
        $this->assertLive($registrar);
        $existingServices = $this->existingServices();
        $tlds = $this->existingTlds();

        $rows = [];
        foreach ($this->cachedDomains($registrar, $refresh) as $domain) {
            $existing = $this->findService($existingServices, $domain->name);
            [, $tld] = self::split($domain->name);
            $tldRow = $tlds[$tld] ?? null;
            $reason = match (true) {
                $existing !== null => __trans('Already in FOSSBilling'),
                !$domain->importable() => __trans((string) $domain->notImportableReason()),
                $tldRow === null => __trans('Import the TLD first (step 1)'),
                self::int($tldRow['tld_registrar_id'] ?? null) !== $registrarId => __trans('The TLD uses another registrar'),
                default => null,
            };
            $rows[] = [
                'domain' => $domain->name->ascii(),
                'display' => $domain->name->unicode(),
                'status' => $domain->status->value,
                'expires_at' => $domain->expiresAt !== null ? date('Y-m-d', $domain->expiresAt) : null,
                'importable' => $reason === null,
                'reason' => $reason,
                'order_id' => $existing,
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $domains
     *
     * @return list<DomainResult>
     */
    public function importDomains(int $registrarId, int $clientId, array $domains): array
    {
        $registrar = $this->registrar($registrarId);
        $this->assertLive($registrar);
        if (count($domains) > self::MAX_DOMAINS_PER_CALL) {
            throw new InformationException('Import at most :n domains per request.', [':n' => (string) self::MAX_DOMAINS_PER_CALL]);
        }
        $client = $this->em()->getRepository(ClientEntity::class)->find($clientId);
        if (!$client instanceof ClientEntity) {
            throw new InformationException('Client not found');
        }
        $product = $this->productService()->getMainDomainProduct();
        if (!$product instanceof Product) {
            throw new InformationException('FOSSBilling has no domain product. Create one under Products first.');
        }
        $remote = $this->cachedDomains($registrar, false);
        $import = $this->importService($registrar);

        $results = [];
        foreach ($domains as $input) {
            try {
                $name = DomainName::fromString($input);
            } catch (OsirException) {
                $results[] = ['domain' => mb_substr($input, 0, 80), 'status' => 'failed', 'message' => __trans('Invalid domain name')];

                continue;
            }
            $results[] = $this->importDomain($name, $remote[$name->ascii()] ?? null, $registrarId, $client, $product, $import);
        }

        return $results;
    }

    // ------------------------------------------------------------------ TLD internals

    /**
     * @param list<string>                                  $tlds
     * @param \Closure(TldRow, TldCost): TldRow $then
     *
     * @return list<TldRow>
     */
    private function eachTld(int $registrarId, array $tlds, PriceRule $rule, \Closure $then): array
    {
        if (count($tlds) > self::MAX_TLDS_PER_CALL) {
            throw new InformationException('Process at most :n TLDs per request.', [':n' => (string) self::MAX_TLDS_PER_CALL]);
        }
        $registrar = $this->registrar($registrarId);
        $this->assertUsd();
        $catalog = $this->cachedCatalog($registrar, false);
        $existing = $this->existingTlds();
        $domains = $this->domainService();

        $results = [];
        foreach ($tlds as $input) {
            $row = ['tld' => mb_substr($input, 0, 70)];

            try {
                $tld = $domains->normalizeTld($input);
                $row['tld'] = $tld;
                $row['display'] = self::displayTld($tld);
                $entry = $catalog[$tld] ?? throw new InformationException('OSIR does not offer :tld.', [':tld' => $tld]);
                $cost = $this->cachedCost($registrar, $entry);
                $row['cost'] = self::prices($cost->registerCents, $cost->renewCents, $cost->transferCents);
                $row['price'] = self::prices($rule->sellingCents($cost->registerCents), $rule->sellingCents($cost->renewCents), $rule->sellingCents($cost->transferCents));
                $row['estimated'] = $cost->estimated;
                $current = $this->existingRow($existing[$tld] ?? null, $registrarId);
                $row['fossbilling'] = $current;
                if ($current === null) {
                    $row['planned'] = 'create';
                } elseif ($current['same_registrar']) {
                    $row['planned'] = 'update';
                } else {
                    $row['planned'] = 'skip';
                    $row['message'] = __trans('Assigned to another registrar in FOSSBilling; left unchanged.');
                }
                $row = $then($row, $cost);
            } catch (InformationException|OsirException $e) {
                $row['status'] = 'failed';
                $row['message'] = $e->getMessage();
            } catch (\Throwable $e) {
                // One bad TLD (a database constraint, a race with another tab) must not abort the rest.
                $this->log(\Box_Log::ERR, sprintf('OSIR import of TLD %s failed: %s', $row['tld'], $e->getMessage()));
                $row['status'] = 'failed';
                $row['message'] = __trans('Unexpected error; see the FOSSBilling log.');
            }
            $results[] = $row;
        }

        return $results;
    }

    /** @return array<string, array<array-key, mixed>> keyed by lower-case TLD */
    private function existingTlds(): array
    {
        $rows = $this->db()->getAll('SELECT t.id, t.tld, t.tld_registrar_id, t.price_registration, t.price_renew, t.price_transfer, t.active, r.name AS registrar_name FROM tld t LEFT JOIN tld_registrar r ON r.id = t.tld_registrar_id');
        $domains = $this->domainService();
        $out = [];
        foreach (self::rows($rows) as $r) {
            try {
                // Keyed the way FOSSBilling normalises TLDs, so a legacy "com" row matches ".com".
                $out[$domains->normalizeTld(self::str($r['tld'] ?? null))] = $r;
            } catch (InformationException) {
                continue;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed>|null $row
     *
     * @return Existing|null
     */
    private function existingRow(?array $row, int $registrarId): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => self::int($row['id'] ?? null),
            'registrar' => self::str($row['registrar_name'] ?? null),
            'same_registrar' => self::int($row['tld_registrar_id'] ?? null) === $registrarId,
            'active' => (bool) ($row['active'] ?? false),
            'price' => [
                'register' => self::decimal($row['price_registration'] ?? null),
                'renew' => self::decimal($row['price_renew'] ?? null),
                'transfer' => self::decimal($row['price_transfer'] ?? null),
            ],
        ];
    }

    // ------------------------------------------------------------------ domain internals

    /**
     * @return DomainResult
     */
    private function importDomain(DomainName $name, ?RemoteDomain $domain, int $registrarId, ClientEntity $client, Product $product, ImportService $import): array
    {
        $ascii = $name->ascii();
        if ($domain === null) {
            return ['domain' => $ascii, 'status' => 'skipped', 'message' => __trans('Not in the OSIR account (reload the list).')];
        }
        if (!$domain->importable() || $domain->expiresAt === null) {
            return ['domain' => $ascii, 'status' => 'skipped', 'message' => __trans((string) $domain->notImportableReason())];
        }
        [$sld, $tld] = self::split($name);
        $domains = $this->domainService();
        $tldModel = $domains->tldFindOneByTld($tld);
        if (!$tldModel instanceof \Model_Tld || self::int($tldModel->tld_registrar_id) !== $registrarId) {
            return ['domain' => $ascii, 'status' => 'skipped', 'message' => __trans('Import the :tld TLD for this registrar first (step 1).', [':tld' => $tld])];
        }
        // Renewals must be at least the TLD's minimum renewal period, or OSIR refuses them.
        $years = ($this->cachedCatalog($this->registrar($registrarId), false)[$tld] ?? null)->minRenewYears ?? 1;

        // Serialises concurrent imports of the same name (two tabs, a double click), so the
        // "already in FOSSBilling" check below cannot race with another import of this name.
        $lock = 'osir_import_' . substr(hash('sha256', $ascii), 0, 40);
        if (self::int($this->db()->getCell('SELECT GET_LOCK(:l, 10)', [':l' => $lock])) !== 1) {
            return ['domain' => $ascii, 'status' => 'failed', 'message' => __trans('Another import of this domain is running.')];
        }

        $orders = $this->orderService();
        $orderId = null;
        $service = null;
        $warnings = [];

        try {
            if ($this->findService($this->existingServices($sld), $name) !== null) {
                return ['domain' => $ascii, 'status' => 'skipped', 'message' => __trans('Already in FOSSBilling.')];
            }

            $contact = null;

            try {
                $contact = $import->registrant($name);
            } catch (OsirException $e) {
                $warnings[] = __trans('Registrant contact not read from OSIR (:reason); the client\'s details were used.', [':reason' => $e->getMessage()]);
            }

            // 1. The service row, with no pending action: FOSSBilling must never provision it. It
            //    is written first, so an interrupted import leaves at most an orphan service row
            //    (harmless), not a pending "register" order.
            $bean = $this->db()->dispense('ServiceDomain');
            if (!$bean instanceof \Model_ServiceDomain) {
                throw new \LogicException('Unexpected ServiceDomain model.');
            }
            $service = $bean;
            $service->client_id = $client->getId();
            $service->tld_registrar_id = $registrarId;
            $service->sld = $sld;
            $service->tld = $tld;
            $service->period = $years;
            $service->privacy = $domain->privacy;
            $service->action = null;
            $service->ns1 = $domain->nameservers[0] ?? null;
            $service->ns2 = $domain->nameservers[1] ?? null;
            $service->ns3 = $domain->nameservers[2] ?? null;
            $service->ns4 = $domain->nameservers[3] ?? null;
            $this->fillContact($service, $contact, $client);
            $service->registered_at = $domain->createdAt !== null ? date('Y-m-d H:i:s', $domain->createdAt) : null;
            $service->expires_at = date('Y-m-d H:i:s', $domain->expiresAt);
            $service->created_at = date('Y-m-d H:i:s');
            $service->updated_at = date('Y-m-d H:i:s');
            $this->db()->store($service);

            // 2. A normal FOSSBilling domain order ("register", the renewal period, renewal price),
            //    so renewal invoices and the adapter's renewal flow work as for any other domain.
            //    FOSSBilling creates it as pending; it is made active right below. Were that step
            //    ever interrupted, activating the pending order would still not register anything:
            //    FOSSBilling first checks availability, and the name is taken.
            $orderId = self::int($orders->createOrder($client, $product, [
                'config' => ['action' => 'register', 'register_sld' => $sld, 'register_tld' => $tld, 'register_years' => $years],
                'period' => $years . 'Y',
                'price' => sprintf('%.2f', (float) self::decimal($tldModel->price_renew) * $years * $this->clientRate($client)),
                'skip_validation' => true,
                'invoice_option' => 'no-invoice',
                'activate' => false,
                // No order "notes": FOSSBilling shows them to the client, and partners sell under
                // their own brand. The admin-only status history below records the import.
            ]));

            // 3. Link and activate in one flush.
            $order = $orders->getOrderRepository()->find($orderId);
            if (!$order instanceof Order) {
                throw new InformationException('Order :id not found', [':id' => (string) $orderId]);
            }
            $order->setServiceId(self::int($service->id));
            $order->setStatus(Order::STATUS_ACTIVE);
            $order->setActivatedAt(new \DateTime());
            $order->setExpiresAt((new \DateTime())->setTimestamp($domain->expiresAt));
            $order->setUpdatedAt(new \DateTime());
            $this->em()->persist($order);
            $this->em()->flush();
            $orders->saveStatusChange($order, 'Imported from OSIR (already registered there; nothing registered or charged)');
            $this->log(\Box_Log::INFO, sprintf('OSIR import: domain %s imported as order #%d', $ascii, $orderId));
        } catch (\Throwable $e) {
            $this->undo($orderId, $service);
            $this->log(\Box_Log::ERR, sprintf('OSIR import of %s failed: %s', $ascii, $e->getMessage()));

            return ['domain' => $ascii, 'status' => 'failed', 'message' => $e instanceof InformationException || $e instanceof OsirException ? $e->getMessage() : __trans('Unexpected error; see the FOSSBilling log.')];
        } finally {
            $this->db()->getCell('SELECT RELEASE_LOCK(:l)', [':l' => $lock]);
        }

        // Pull lock/privacy/nameservers/expiry through the adapter's normal sync. A failure here
        // leaves a correct order; the next cron sync retries.
        try {
            $domains->synchronizeDomain($service);
        } catch (\Throwable $e) {
            $warnings[] = __trans('First sync failed (:reason); the cron sync will retry.', [':reason' => $e->getMessage()]);
        }

        return ['domain' => $ascii, 'status' => 'imported', 'order_id' => $orderId] + ($warnings === [] ? [] : ['message' => implode(' ', $warnings)]);
    }

    /**
     * Domain services FOSSBilling already has (optionally only for one second-level label),
     * keyed "sld|tld" with the newest order id (0 when there is no order).
     *
     * @return array<string, int>
     */
    private function existingServices(?string $sld = null): array
    {
        $sql = 'SELECT sd.sld, sd.tld, MAX(co.id) AS order_id FROM service_domain sd LEFT JOIN client_order co ON co.service_id = sd.id AND co.service_type = :type';
        $params = [':type' => 'domain'];
        if ($sld !== null) {
            $sql .= ' WHERE sd.sld IN (:a, :u)';
            $params[':a'] = $sld;
            $params[':u'] = self::unicodeLabel($sld);
        }
        $out = [];
        foreach (self::rows($this->db()->getAll($sql . ' GROUP BY sd.id, sd.sld, sd.tld', $params)) as $r) {
            $key = mb_strtolower(self::str($r['sld'] ?? null)) . '|' . mb_strtolower(self::str($r['tld'] ?? null));
            $out[$key] = max($out[$key] ?? 0, self::int($r['order_id'] ?? null));
        }

        return $out;
    }

    /**
     * The order id FOSSBilling has for this name (0: a service without order), or null. Matches
     * the ASCII and the Unicode spelling, since a normal order may store an IDN either way.
     *
     * @param array<string, int> $services
     */
    private function findService(array $services, DomainName $name): ?int
    {
        [$sld, $tld] = self::split($name);
        foreach ([$sld . '|' . $tld, mb_strtolower(self::unicodeLabel($sld)) . '|' . $tld, mb_strtolower(self::unicodeLabel($sld)) . '|' . mb_strtolower(self::displayTld($tld))] as $key) {
            if (isset($services[$key])) {
                return $services[$key];
            }
        }

        return null;
    }

    /** Removes what a failed import created, so a retry starts clean. */
    private function undo(?int $orderId, ?\Model_ServiceDomain $service): void
    {
        try {
            if ($service !== null && $service->id !== null && $service->id !== 0) {
                $this->db()->trash($service);
            }
            if ($orderId !== null) {
                $orders = $this->orderService();
                $order = $orders->getOrderRepository()->find($orderId);
                if ($order instanceof Order) {
                    $orders->rmOrder($order);
                }
            }
        } catch (\Throwable $e) {
            $this->log(\Box_Log::ERR, sprintf('OSIR import clean-up failed for order #%d: %s', (int) $orderId, $e->getMessage()));
        }
    }

    private function fillContact(\Model_ServiceDomain $service, ?ContactData $contact, ClientEntity $client): void
    {
        // The OSIR registrant is what the registry has, so it is the truth for this domain; the
        // client's own details are only a fallback when OSIR has no usable registrant.
        if ($contact !== null && $contact->email !== null && ($contact->firstName !== null || $contact->organization !== null)) {
            $values = [$contact->firstName, $contact->lastName, $contact->email, $contact->organization, $contact->street1, $contact->street2,
                $contact->city, $contact->state, $contact->postalCode, $contact->country, $contact->phoneCountryCode, $contact->phone];
        } else {
            $values = [$client->getFirstName(), $client->getLastName(), $client->getEmail(), $client->getCompany(), $client->getAddress1(), $client->getAddress2(),
                $client->getCity(), $client->getState(), $client->getPostcode(), $client->getCountry(), $client->getPhoneCc(), $client->getPhone()];
        }
        [$service->contact_first_name, $service->contact_last_name, $service->contact_email, $service->contact_company,
            $service->contact_address1, $service->contact_address2, $service->contact_city, $service->contact_state,
            $service->contact_postcode, $service->contact_country, $service->contact_phone_cc, $service->contact_phone] = $values;
    }

    // ------------------------------------------------------------------ client DNS

    /**
     * The DNS tab's data for one domain order: whether OSIR serves the zone, and every record.
     *
     * @return array{domain: string, status: array<string, bool|list<string>>, records: list<array<string, bool|int|string|null>>}
     */
    public function dnsOverview(\Model_ClientOrder $order): array
    {
        [$domain, $dns, $registrar] = $this->dnsFor($order);

        // Briefly cached: the page makes two upstream calls, and every client of the installation
        // shares one OSIR API key with registrations and renewals. Writes drop the entry, so a
        // client never sees their own change missing.
        $overview = $this->cached($this->dnsCacheKey($registrar, $order), self::DNS_TTL, fn(): array => $this->guardOsir(function () use ($domain, $dns): array {
            $records = [];
            foreach ($dns->records($domain) as $record) {
                $row = $record->toArray();
                // Whether OSIR serves this record, decided here rather than in the template: the
                // template only has the Unicode domain, which never matches an IDN record name.
                $row['locked'] = $record->type === RecordType::SOA || ($record->type === RecordType::NS && $record->isApex($domain));
                $records[] = $row;
            }

            return [
                'domain' => $domain->unicode(),
                'status' => $dns->status($domain)->toArray(),
                'records' => $records,
            ];
        }));

        if (!is_array($overview) || !isset($overview['domain'], $overview['status'], $overview['records'])) {
            throw new InformationException('The DNS records of this domain could not be read right now. Please try again in a few minutes.');
        }
        /** @var array{domain: string, status: array<string, bool|list<string>>, records: list<array<string, bool|int|string|null>>} $overview */

        return $overview;
    }

    /** @param array<string, mixed> $input */
    public function dnsAdd(\Model_ClientOrder $order, array $input): void
    {
        [$domain, $dns, $registrar] = $this->dnsFor($order);

        $this->guardOsir(function () use ($domain, $dns, $registrar, $order, $input): void {
            $dns->create($domain, DnsRecord::draft($domain, $input), $this->dnsKey($registrar, $order, $domain));
        });
        $this->cache()->delete($this->dnsCacheKey($registrar, $order));
        $this->log(\Box_Log::INFO, sprintf('Client added a DNS record to %s (order #%d).', $domain->ascii(), self::int($order->id)));
    }

    /** @param array<string, mixed> $input */
    public function dnsEdit(\Model_ClientOrder $order, string $recordId, array $input): void
    {
        [$domain, $dns, $registrar] = $this->dnsFor($order);

        $this->guardOsir(function () use ($domain, $dns, $recordId, $input): void {
            $dns->update($domain, $recordId, DnsRecord::draft($domain, $input));
        });
        $this->cache()->delete($this->dnsCacheKey($registrar, $order));
        $this->log(\Box_Log::INFO, sprintf('Client updated a DNS record of %s (order #%d).', $domain->ascii(), self::int($order->id)));
    }

    public function dnsRemove(\Model_ClientOrder $order, string $recordId): void
    {
        [$domain, $dns, $registrar] = $this->dnsFor($order);

        $this->guardOsir(function () use ($domain, $dns, $recordId): void {
            $dns->delete($domain, $recordId);
        });
        $this->cache()->delete($this->dnsCacheKey($registrar, $order));
        $this->log(\Box_Log::INFO, sprintf('Client deleted a DNS record of %s (order #%d).', $domain->ascii(), self::int($order->id)));
    }

    /**
     * Resolves the order to the domain it holds and an OSIR DNS client for it.
     *
     * The caller has already proven the order belongs to the signed-in client (the client API
     * uses FOSSBilling's own findForClientById). Here the order must additionally be an active
     * domain service registered through an OSIR registrar: the domain name is taken from that
     * service row, never from the request, and OSIR re-checks ownership on every call.
     *
     * @return array{0: DomainName, 1: DnsService, 2: \Model_TldRegistrar}
     */
    private function dnsFor(\Model_ClientOrder $order): array
    {
        $service = $this->orderService()->getOrderService($order);
        if (!$service instanceof \Model_ServiceDomain || $order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
            throw new InformationException('DNS is available for active domain orders only.');
        }

        $registrar = $this->registrar(self::int($service->tld_registrar_id));

        $adapter = $this->domainService()->registrarGetRegistrarAdapter($registrar);
        if ((bool) $registrar->test_mode || !$adapter instanceof \Registrar_Adapter_Osir) {
            throw $this->dnsUnavailable(sprintf('DNS for order #%d: the OSIR registrar is in Test Mode or its adapter is missing.', self::int($order->id)));
        }

        try {
            $dns = $adapter->dnsService();
        } catch (OsirException $e) {
            // Configuration messages name settings and files; they belong in the log, not in front
            // of a client who can do nothing about them.
            throw $this->dnsUnavailable(sprintf('DNS for order #%d is unavailable: %s', self::int($order->id), $e->getMessage()));
        }

        return [DomainName::fromParts(self::str($service->sld), self::str($service->tld)), $dns, $registrar];
    }

    /**
     * Key for the one DNS call that is not naturally repeatable (adding a record): it makes the
     * adapter's own retry of THIS submission safe. A fresh nonce each time is deliberate — OSIR
     * remembers a key for 30 days, so a reused key would replay the first answer and a re-added
     * record would never appear.
     */
    private function dnsKey(\Model_TldRegistrar $registrar, \Model_ClientOrder $order, DomainName $domain): ?string
    {
        $adapter = $this->domainService()->registrarGetRegistrarAdapter($registrar);
        if (!$adapter instanceof \Registrar_Adapter_Osir) {
            return null;
        }

        $scope = $adapter->keyScope();

        return IdempotencyKeys::dnsRecord(
            $scope['installation'],
            $scope['environment'],
            new OrderRef((string) self::int($order->id), null),
            $domain,
            bin2hex(random_bytes(6)),
        );
    }

    /** One OSIR call's worth of records, cached per order and registrar configuration. */
    private function dnsCacheKey(\Model_TldRegistrar $registrar, \Model_ClientOrder $order): string
    {
        return 'osir_dns_' . self::fingerprint($registrar) . '_o' . self::int($order->id);
    }

    /** Logs the real reason and shows the client one they can act on. */
    private function dnsUnavailable(string $reason): InformationException
    {
        $this->log(\Box_Log::ERR, $reason);

        return new InformationException('DNS management is not available for this domain right now. Please contact support.');
    }

    /**
     * Turns the adapter's exceptions into FOSSBilling messages. Their text is written for end
     * users and carries no secrets or raw upstream payloads.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function guardOsir(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (OsirException $e) {
            throw new InformationException($e->getTemplate(), $e->getVariables());
        }
    }

    // ------------------------------------------------------------------ registrar and OSIR access

    private function registrar(int $id): \Model_TldRegistrar
    {
        $model = $this->db()->findOne('TldRegistrar', 'id = :id AND registrar = :r', [':id' => $id, ':r' => 'Osir']);
        if (!$model instanceof \Model_TldRegistrar) {
            throw new InformationException('OSIR registrar not found. Install it under Domain Registration → Registrars first.');
        }

        return $model;
    }

    private function assertLive(\Model_TldRegistrar $registrar): void
    {
        if ((bool) $registrar->test_mode) {
            // OSIR has no test environment; the adapter refuses to work while Test Mode is on.
            throw new InformationException('OSIR has no test environment. Turn off Test Mode for this registrar to use the import.');
        }
    }

    private function importService(\Model_TldRegistrar $registrar): ImportService
    {
        $id = self::int($registrar->id);
        if (isset($this->importServices[$id])) {
            return $this->importServices[$id];
        }
        $adapter = $this->domainService()->registrarGetRegistrarAdapter($registrar);
        if (!$adapter instanceof \Registrar_Adapter_Osir) {
            throw new InformationException('The OSIR registrar adapter is not installed.');
        }

        try {
            return $this->importServices[$id] = $adapter->importService();
        } catch (OsirException $e) {
            throw new InformationException($e->getTemplate(), $e->getVariables());
        }
    }

    /** @return array<string, CatalogTld> */
    private function cachedCatalog(\Model_TldRegistrar $registrar, bool $refresh): array
    {
        $key = 'osir_import_catalog_' . self::fingerprint($registrar);
        if ($refresh) {
            $this->cache()->delete($key);
        }
        $load = fn(): array => $this->importService($registrar)->catalog();
        $value = $this->cached($key, self::CATALOG_TTL, $load);
        if (!is_array($value) || !(reset($value) instanceof CatalogTld)) {
            $value = $this->cached($key, self::CATALOG_TTL, $load, true);
        }
        if (!is_array($value)) {
            throw new \LogicException('Unexpected cached catalog.');
        }

        /** @var array<string, CatalogTld> $value */
        return $value;
    }

    private function cachedCost(\Model_TldRegistrar $registrar, CatalogTld $tld): TldCost
    {
        $key = 'osir_import_cost_' . self::fingerprint($registrar) . '_' . substr(hash('sha256', $tld->tld), 0, 32);
        $load = fn(): TldCost => $this->importService($registrar)->cost($tld);
        $value = $this->cached($key, self::COST_TTL, $load);
        if (!$value instanceof TldCost) {
            // Written by an older version of this module (incompatible class): reload.
            $value = $this->cached($key, self::COST_TTL, $load, true);
        }
        if (!$value instanceof TldCost) {
            throw new \LogicException('Unexpected cached cost.');
        }

        return $value;
    }

    /** @return array<string, RemoteDomain> */
    private function cachedDomains(\Model_TldRegistrar $registrar, bool $refresh): array
    {
        $key = 'osir_import_domains_' . self::fingerprint($registrar);
        if ($refresh) {
            $this->cache()->delete($key);
        }
        $load = fn(): array => $this->importService($registrar)->domains();
        $value = $this->cached($key, self::DOMAINS_TTL, $load);
        if (!is_array($value) || ($value !== [] && !(reset($value) instanceof RemoteDomain))) {
            $value = $this->cached($key, self::DOMAINS_TTL, $load, true);
        }
        if (!is_array($value)) {
            throw new \LogicException('Unexpected cached domain list.');
        }

        /** @var array<string, RemoteDomain> $value */
        return $value;
    }

    /** @param \Closure(): mixed $load */
    private function cached(string $key, int $ttl, \Closure $load, bool $reload = false): mixed
    {
        if ($reload) {
            $this->cache()->delete($key);
        }

        try {
            return $this->cache()->get($key, static function (ItemInterface $item) use ($ttl, $load): mixed {
                $item->expiresAfter($ttl);

                return $load();
            });
        } catch (OsirException $e) {
            throw new InformationException($e->getTemplate(), $e->getVariables());
        }
    }

    // ------------------------------------------------------------------ FOSSBilling services, typed

    private function container(): \Pimple\Container
    {
        return $this->di ?? throw new \LogicException('The dependency injection container has not been set.');
    }

    private function db(): \Box_Database
    {
        $db = $this->container()['db'];

        return $db instanceof \Box_Database ? $db : throw new \LogicException('Unexpected db service.');
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->container()['em'];

        return $em instanceof EntityManagerInterface ? $em : throw new \LogicException('Unexpected em service.');
    }

    private function cache(): CacheInterface
    {
        $cache = $this->container()['cache'];

        return $cache instanceof CacheInterface ? $cache : throw new \LogicException('Unexpected cache service.');
    }

    private function modService(string $name): object
    {
        $factory = $this->container()['mod_service'];
        $service = is_callable($factory) ? $factory($name) : null;

        return is_object($service) ? $service : throw new \LogicException('Unexpected mod_service.');
    }

    private function domainService(): \Box\Mod\Servicedomain\Service
    {
        $s = $this->modService('servicedomain');

        return $s instanceof \Box\Mod\Servicedomain\Service ? $s : throw new \LogicException('Unexpected servicedomain service.');
    }

    private function orderService(): \Box\Mod\Order\Service
    {
        $s = $this->modService('order');

        return $s instanceof \Box\Mod\Order\Service ? $s : throw new \LogicException('Unexpected order service.');
    }

    private function productService(): \Box\Mod\Product\Service
    {
        $s = $this->modService('product');

        return $s instanceof \Box\Mod\Product\Service ? $s : throw new \LogicException('Unexpected product service.');
    }

    /**
     * OSIR bills in USD and TLD prices are stored in FOSSBilling's default currency, so importing
     * prices into a non-USD installation would silently mis-price every TLD.
     */
    private function assertUsd(): void
    {
        $default = $this->currencyService()->getCurrencyRepository()->findDefault();
        if ($default === null || strtoupper($default->getCode()) !== 'USD') {
            throw new InformationException('TLD import needs USD as FOSSBilling\'s default currency (OSIR prices are in USD).');
        }
    }

    /** Conversion rate from the default currency to the client's (1.0 for the default). */
    private function clientRate(ClientEntity $client): float
    {
        $code = $client->getCurrency();
        if ($code === null || $code === '') {
            return 1.0;
        }
        $rate = $this->currencyService()->getCurrencyRepository()->getRateByCode($code);

        return $rate !== null && $rate > 0 ? $rate : throw new InformationException('Currency rate for :code is not configured.', [':code' => $code]);
    }

    private function currencyService(): \Box\Mod\Currency\Service
    {
        $s = $this->modService('currency');

        return $s instanceof \Box\Mod\Currency\Service ? $s : throw new \LogicException('Unexpected currency service.');
    }

    private function log(int $priority, string $message): void
    {
        $logger = $this->container()['logger'];
        if ($logger instanceof \Box_Log) {
            $logger->log($message, $priority);
        }
    }

    // ------------------------------------------------------------------ small helpers

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A FOSSBilling DECIMAL column as "12.34". */
    private static function decimal(mixed $value): string
    {
        return sprintf('%.2f', is_numeric($value) ? (float) $value : 0.0);
    }

    /** @return Prices decimal strings in USD */
    private static function prices(int $register, int $renew, int $transfer): array
    {
        return ['register' => self::money($register), 'renew' => self::money($renew), 'transfer' => self::money($transfer)];
    }

    private static function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** @return array{0: string, 1: string} FOSSBilling's split: "example", ".co.uk" */
    private static function split(DomainName $name): array
    {
        $parts = explode('.', $name->ascii(), 2);

        return [$parts[0], '.' . ($parts[1] ?? '')];
    }

    /**
     * Cache namespace for one registrar AND its configuration (key, mode), so changing the API key
     * or switching Test Mode never serves data fetched with the previous settings.
     */
    private static function fingerprint(\Model_TldRegistrar $registrar): string
    {
        return self::int($registrar->id) . '_' . substr(hash('sha256', self::str($registrar->config) . '|' . self::str($registrar->test_mode)), 0, 16);
    }

    private static function unicodeLabel(string $label): string
    {
        $unicode = idn_to_utf8($label, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return is_string($unicode) && $unicode !== '' ? $unicode : $label;
    }

    private static function displayTld(string $tld): string
    {
        $unicode = idn_to_utf8(ltrim($tld, '.'), IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return is_string($unicode) && $unicode !== '' ? '.' . $unicode : $tld;
    }
}
