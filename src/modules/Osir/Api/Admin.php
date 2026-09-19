<?php

declare(strict_types=1);

/*
 * Copyright OSIR. SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Osir\Api;

use FOSSBilling\InformationException;
use Osir\FossBilling\Exception\OsirException;
use Osir\FossBilling\Import\PriceRule;

/**
 * Admin API behind Domain Registration → OSIR import. Reading needs the module's "import"
 * permission; writing additionally needs FOSSBilling's own permission for what is written
 * (TLDs, or orders and domains).
 *
 */
class Admin extends \FOSSBilling\Api\AbstractApi
{
    private function osir(): \Box\Mod\Osir\Service
    {
        $service = $this->getService();
        if (!$service instanceof \Box\Mod\Osir\Service) {
            throw new \LogicException('Unexpected module service.');
        }
        $service->loadLibrary();

        return $service;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{id: int, name: string, test_mode: bool}>
     */
    public function registrars(array $data = []): array
    {
        $this->checkPermissions('osir', 'import');

        return $this->osir()->registrars();
    }

    /**
     * OSIR's TLD catalog with list prices and the matching FOSSBilling TLD, if any.
     *
     * @param array{registrar_id: int, refresh?: bool} $data
     *
     * @return list<array<string, mixed>>
     */
    public function tld_catalog(array $data): array
    {
        $this->checkPermissions('osir', 'import');

        return $this->osir()->catalog(self::id($data, 'registrar_id'), self::flag($data, 'refresh'));
    }

    /**
     * Cost (from OSIR's quote) and selling price for up to 10 TLDs. Writes nothing.
     *
     * @param array{registrar_id: int, tlds: list<string>, markup_percent?: string, markup_fixed?: string, rounding?: string} $data
     *
     * @return list<array<string, mixed>>
     */
    public function tld_preview(array $data): array
    {
        $this->checkPermissions('osir', 'import');

        return $this->osir()->previewTlds(self::id($data, 'registrar_id'), self::names($data, 'tlds'), self::rule($data));
    }

    /**
     * Creates, or with update_existing re-prices, up to 10 TLDs on the OSIR registrar.
     *
     * @param array{registrar_id: int, tlds: list<string>, markup_percent?: string, markup_fixed?: string, rounding?: string, update_existing?: bool} $data
     *
     * @return list<array<string, mixed>>
     */
    public function tld_import(array $data): array
    {
        $this->checkPermissions('osir', 'import');
        $this->checkPermissions('servicedomain', 'manage_tlds');

        return $this->osir()->importTlds(self::id($data, 'registrar_id'), self::names($data, 'tlds'), self::rule($data), self::flag($data, 'update_existing'));
    }

    /**
     * Domains in the OSIR account and whether each can be imported.
     *
     * @param array{registrar_id: int, refresh?: bool} $data
     *
     * @return list<array<string, mixed>>
     */
    public function domain_list(array $data): array
    {
        $this->checkPermissions('osir', 'import');

        return $this->osir()->remoteDomains(self::id($data, 'registrar_id'), self::flag($data, 'refresh'));
    }

    /**
     * Turns up to 10 domains of the OSIR account into active orders of one client. Nothing is
     * registered or charged.
     *
     * @param array{registrar_id: int, client_id: int, domains: list<string>} $data
     *
     * @return list<array<string, mixed>>
     */
    public function domain_import(array $data): array
    {
        $this->checkPermissions('osir', 'import');
        $this->checkPermissions('order', 'manage');
        $this->checkPermissions('servicedomain', 'manage_domains');
        return $this->osir()->importDomains(self::id($data, 'registrar_id'), self::id($data, 'client_id'), self::names($data, 'domains'));
    }

    /** @param array<string, mixed> $data */
    private static function id(array $data, string $key): int
    {
        $id = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InformationException(':field is required.', [':field' => $key]);
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    private static function flag(array $data, string $key): bool
    {
        return \FOSSBilling\Tools::normalizeBoolean($data[$key] ?? false);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function names(array $data, string $key): array
    {
        $list = $data[$key] ?? null;
        if (!is_array($list) || $list === [] || !array_is_list($list)) {
            throw new InformationException('Select at least one entry.');
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item) || $item === '' || strlen($item) > 253) {
                throw new InformationException('Invalid entry in the selection.');
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $data */
    private static function rule(array $data): PriceRule
    {
        try {
            return PriceRule::fromInput($data['markup_percent'] ?? '', $data['markup_fixed'] ?? '', $data['rounding'] ?? 'none');
        } catch (OsirException $e) {
            throw new InformationException($e->getTemplate(), $e->getVariables());
        }
    }
}
