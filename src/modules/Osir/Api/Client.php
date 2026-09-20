<?php

declare(strict_types=1);

/*
 * Copyright OSIR. SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Osir\Api;

use Box\Mod\Client\Entity\Client as ClientEntity;
use FOSSBilling\InformationException;

/**
 * Client API for the DNS tab of a domain registered through OSIR.
 *
 * Ownership is decided here, once, in {@see self::order()}: the order must belong to the
 * signed-in client, be active, and hold a domain service. Every method takes the domain from
 * that order's service row, so a request cannot name a domain of its own. OSIR checks ownership
 * again and answers 403 for a domain outside the reseller's account.
 */
class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Zone state and every record of the domain.
     *
     * @param array{order_id: int|string} $data
     *
     * @return array{domain: string, status: array<string, bool|list<string>>, records: list<array<string, bool|int|string|null>>}
     */
    public function dns_records(array $data): array
    {
        return $this->osir()->dnsOverview($this->order($data));
    }

    /**
     * Adds a record.
     *
     * @param array{order_id: int|string, name: string, type: string, content: string, ttl?: int|string, priority?: int|string, weight?: int|string, port?: int|string} $data
     */
    public function dns_record_create(array $data): bool
    {
        $this->osir()->dnsAdd($this->order($data), $data);

        return true;
    }

    /**
     * Replaces a record. OSIR derives the record id from what the record says, so the id changes
     * whenever the record does; the browser re-reads the list after every write.
     *
     * @param array{order_id: int|string, record_id: string, name: string, type: string, content: string, ttl?: int|string, priority?: int|string, weight?: int|string, port?: int|string} $data
     */
    public function dns_record_update(array $data): bool
    {
        $this->osir()->dnsEdit($this->order($data), self::recordId($data), $data);

        return true;
    }

    /**
     * @param array{order_id: int|string, record_id: string} $data
     */
    public function dns_record_delete(array $data): bool
    {
        $this->osir()->dnsRemove($this->order($data), self::recordId($data));

        return true;
    }

    /**
     * The signed-in client's own order. Anything else — another client's order, a cancelled one,
     * a hosting order — is refused before OSIR is contacted.
     *
     * @param array<string, mixed> $data
     */
    private function order(array $data): \Model_ClientOrder
    {
        $id = $data['order_id'] ?? null;
        if (!is_int($id) && !(is_string($id) && preg_match('/^\d{1,10}$/', $id) === 1)) {
            throw new InformationException('Order ID is required');
        }

        $di = $this->getDi();
        if (!$di instanceof \Pimple\Container) {
            throw new \LogicException('The dependency injection container has not been set.');
        }
        $factory = $di['mod_service'];
        $orders = is_callable($factory) ? $factory('order') : null;
        if (!$orders instanceof \Box\Mod\Order\Service) {
            throw new \LogicException('Unexpected order service.');
        }

        $identity = $this->getIdentity();
        if (!$identity instanceof \Model_Client && !$identity instanceof ClientEntity) {
            // Only a signed-in client reaches this API; anything else has no orders of its own.
            throw new InformationException('Order not found');
        }

        $order = $orders->findForClientById($identity, (int) $id);
        if (!$order instanceof \Model_ClientOrder) {
            throw new InformationException('Order not found');
        }
        $orders->assertOrderUsable($order);

        return $order;
    }

    /** @param array<string, mixed> $data */
    private static function recordId(array $data): string
    {
        $id = $data['record_id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new InformationException('Record ID is required');
        }

        return trim($id);
    }

    private function osir(): \Box\Mod\Osir\Service
    {
        $service = $this->getService();
        if (!$service instanceof \Box\Mod\Osir\Service) {
            throw new \LogicException('Unexpected module service.');
        }
        $service->loadLibrary();

        return $service;
    }
}
