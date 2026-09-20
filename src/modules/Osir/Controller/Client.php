<?php

declare(strict_types=1);

/*
 * Copyright OSIR. SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Osir\Controller;

/**
 * The client-area DNS page: /osir/dns/{order id}.
 *
 * The page itself only renders; everything it shows comes from the client API, which is where
 * the order's ownership is checked. An order id that is not the signed-in client's simply loads
 * a page whose first API call fails with "Order not found".
 */
class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/osir/dns/:order_id', 'get_dns', ['order_id' => '[0-9]+'], static::class);
    }

    public function get_dns(\Box_App $app, string $order_id): string
    {
        $di = $this->di ?? throw new \LogicException('The dependency injection container has not been set.');
        $di['is_client_logged'];

        $api = $di['api_client'];
        if (!$api instanceof \FOSSBilling\Api\Proxy) {
            throw new \LogicException('Unexpected api_client service.');
        }

        // Fetched server-side so the page renders with its records already in place, and so an
        // order that is not this client's is refused before anything is shown.
        $overview = $api->call('osir_dns_records', ['order_id' => (int) $order_id]);

        return $app->render('mod_osir_dns', [
            'order_id' => (int) $order_id,
            'overview' => $overview,
        ]);
    }
}
