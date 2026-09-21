<?php

declare(strict_types=1);

/*
 * End-to-end suite: real FOSSBilling 0.8.7 + the OSIR adapter + the TLS mock of the OSIR API.
 *
 * Drives FOSSBilling through its admin API exactly as an administrator (or its cron) would, then
 * asserts on what the adapter actually sent to "OSIR" (the mock's request log and state) and on
 * what FOSSBilling stored. Run with:  make e2e   (or: docker compose --profile e2e run --rm e2e)
 */

const FB = 'http://fossbilling';
const MOCK = 'http://mock-osir-app:8080';
const LIVE_KEY = 'osir_live_E2eLiveKeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
/** A sandbox key as 1.0.x could store it; 1.1 never uses it and must keep it masked. */
const OLD_SANDBOX_KEY = 'osir_test_E2eOldSandboxKeyAAAAAAAAAAAAAAAAAAAAAA';
const CONFIG_PHP = '/fb/config.php';

$token = trim((string) file_get_contents('/run/admin-api-token'));
$suffix = substr(bin2hex(random_bytes(3)), 0, 5); // unique names per run
$failures = 0;
$passes = 0;

// ------------------------------------------------------------------ tiny test harness

function check(bool $ok, string $what, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        ++$passes;
        echo "  \033[32m✔\033[0m $what\n";
    } else {
        ++$failures;
        echo "  \033[31m✘ $what\033[0m" . ($detail !== '' ? "\n      $detail" : '') . "\n";
    }
}

/** @var array<string, bool> outcome of each scenario, so dependants can be skipped instead of cascading */
$outcomes = [];

function scenario(string $id, string $title, callable $body, array $requires = []): void
{
    global $failures, $outcomes;
    $only = array_filter(explode(',', (string) getenv('E2E_ONLY')));
    if ($only !== [] && !in_array($id, $only, true)) {
        return; // E2E_ONLY=1,2,15 runs just those (list prerequisites too)
    }
    echo "\n\033[1m$id. $title\033[0m\n";
    foreach ($requires as $dep) {
        if (!($outcomes[$dep] ?? false)) {
            echo "  \033[33m- skipped: prerequisite scenario $dep failed\033[0m\n";
            $outcomes[$id] = false;

            return;
        }
    }
    $before = $failures;
    try {
        $body();
    } catch (Throwable $e) {
        check(false, 'scenario crashed', $e::class . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    }
    $outcomes[$id] = $failures === $before;
}

function http(string $method, string $url, ?array $form = null, ?string $basicAuth = null, ?array $json = null): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        $headers[] = 'Content-Type: application/json';
    } elseif ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null, 'raw' => is_string($raw) ? $raw : ''];
}

/** FOSSBilling admin API call. Returns [result, errorMessage|null]. */
function fb(string $endpoint, array $data = []): array
{
    global $token;
    $r = http('POST', FB . '/api/admin/' . $endpoint, $data, 'admin:' . $token);
    if ($r['json'] === null) {
        return [null, 'non-JSON response (HTTP ' . $r['status'] . '): ' . substr($r['raw'], 0, 300)];
    }
    $error = $r['json']['error'] ?? null;

    return [$r['json']['result'] ?? null, is_array($error) ? (string) ($error['message'] ?? 'error') : null];
}

function fbOk(string $endpoint, array $data = []): mixed
{
    [$result, $error] = fb($endpoint, $data);
    if ($error !== null) {
        throw new RuntimeException("$endpoint failed: $error");
    }

    return $result;
}

function mock(string $what, ?array $json = null): array
{
    $r = $json === null ? http('GET', MOCK . '/__mock/' . $what) : http('POST', MOCK . '/__mock/' . $what, json: $json);

    return $r['json'] ?? [];
}

/** Requests the mock received since $since (index). */
function requestsSince(int $since): array
{
    return array_slice(mock('requests')['requests'] ?? [], $since);
}

function requestCount(): int
{
    return count(mock('requests')['requests'] ?? []);
}

function only(array $requests, string $method, string $pathRegex): array
{
    return array_values(array_filter($requests, static fn(array $r): bool => $r['method'] === $method && preg_match($pathRegex, $r['path']) === 1));
}

function setConstant(string $name, ?string $value): void
{
    $c = file_get_contents(CONFIG_PHP);
    $line = '/^defined\("' . $name . '"\) \|\| define\("' . $name . '", "[^"]*"\);\n/m';
    $c = preg_replace($line, '', $c);
    if ($value !== null) {
        $c = preg_replace('/^<\?php\n/', "<?php\ndefined(\"$name\") || define(\"$name\", \"$value\");\n", $c, 1);
    }
    file_put_contents(CONFIG_PHP, $c);
    // FOSSBilling's PHP revalidates OPcache every 2 s (opcache.revalidate_freq); wait it out.
    sleep(3);
}

/** Reads one cookie out of a curl cookie jar. */
function cookieValue(string $jar, string $name): ?string
{
    foreach (explode("\n", (string) @file_get_contents($jar)) as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) === 7 && $parts[5] === $name) {
            return $parts[6];
        }
    }

    return null;
}

/**
 * Calls the CLIENT API as a signed-in client (session cookie), which is how the DNS tab is used.
 * Each caller keeps its own cookie jar, so one client's session can never be reused for another.
 *
 * @return array{0: mixed, 1: string|null}
 */
function clientApi(string $jar, string $endpoint, array $params = []): array
{
    $ch = curl_init(FB . '/api/client/' . $endpoint);
    // A session-authenticated client call carries the CSRF token, exactly as the browser does.
    $headers = ['Content-Type: application/json'];
    $csrf = cookieValue($jar, 'fossbilling_csrf');
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-TOKEN: ' . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [null, 'non-JSON response: ' . substr($raw, 0, 200)];
    }
    $error = $json['error'] ?? null;

    return [$json['result'] ?? null, is_array($error) ? (string) ($error['message'] ?? 'error') : null];
}

/**
 * Signs a client in and returns their cookie jar.
 *
 * @param-out string|null $error
 */
function clientLogin(string $suffix, ?string &$error = null): string
{
    $jar = tempnam(sys_get_temp_dir(), 'e2e-client-');

    // A browser loads a page first, which is what issues the CSRF cookie the API then requires.
    $ch = curl_init(FB . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    curl_exec($ch);
    curl_close($ch);

    $ch = curl_init(FB . '/api/guest/client/login');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => json_encode(['email' => "fossbilling-e2e+$suffix@osir.com", 'password' => 'E2e-Passw0rd!' . $suffix]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    $json = json_decode($raw, true);
    $err = is_array($json) ? ($json['error'] ?? null) : null;
    $error = is_array($err) ? (string) ($err['message'] ?? 'error') : (is_array($json) ? null : 'non-JSON login response: ' . substr($raw, 0, 200));

    return $jar;
}

function createClient(string $suffix): int
{
    return (int) fbOk('client/create', [
        // FOSSBilling checks the domain's MX record, so the reserved example.* domains are refused.
        // The dev container has no MTA, so nothing is ever sent.
        'email' => "fossbilling-e2e+$suffix@osir.com", 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'password' => 'E2e-Passw0rd!' . $suffix,
        'company' => 'Analytical Ltd', 'address_1' => '1 Engine Street', 'city' => 'London', 'postcode' => 'N1 9GU',
        'country' => 'GB', 'phone_cc' => '44', 'phone' => '2079460000',
    ]);
}

/**
 * Creates the order, then activates it as a separate call. (FOSSBilling's `activate` flag on
 * order/create swallows activation errors and only logs them, which would hide adapter failures.)
 *
 * @return array{0: int|null, 1: string|null} order id, error from creation or activation
 */
function orderDomain(int $clientId, array $config): array
{
    // A 1-year billing period, as checkout orders have: FOSSBilling then gives the order an expiry date.
    [$orderId, $error] = fb('order/create', ['client_id' => $clientId, 'product_id' => 1, 'config' => $config, 'period' => '1Y', 'invoice_option' => 'no-invoice']);
    if ($error !== null) {
        return [null, $error];
    }
    [, $activationError] = fb('order/activate', ['id' => (int) $orderId]);

    return [(int) $orderId, $activationError];
}

/** Creates an order without activating it (a second, pending order for the same name, …). */
function createOrder(int $clientId, array $config): int
{
    return (int) fbOk('order/create', ['client_id' => $clientId, 'product_id' => 1, 'config' => $config, 'period' => '1Y', 'invoice_option' => 'no-invoice']);
}

/** @return list<array<string, mixed>> */
function charges(string $what): array
{
    return array_values(array_filter(mock('state')['charges'], static fn(array $c): bool => $c['what'] === $what));
}

function serviceOf(int $orderId): array
{
    return fbOk('order/service', ['id' => $orderId]);
}

// ------------------------------------------------------------------ suite

echo "OSIR adapter — FOSSBilling end-to-end suite (run $suffix)\n";
mock('reset', []);
$registrarId = 0;
$clientId = 0;

scenario('1', 'Install and configure the registrar; secrets are masked', function () use (&$registrarId): void {
    // FOSSBilling 0.8.7 keeps listing installed registrars as "available", and its list has no
    // adapter-code field, so an existing install is recognised by the adapter's label.
    $find = static function (): int {
        foreach (fbOk('servicedomain/registrar_get_list')['list'] as $r) {
            if (str_starts_with((string) $r['label'], 'Registers and manages domains through OSIR')) {
                return (int) $r['id'];
            }
        }

        return 0;
    };
    $registrarId = $find();
    if ($registrarId === 0) {
        fbOk('servicedomain/registrar_install', ['code' => 'Osir']);
        $registrarId = $find();
    }
    check($registrarId > 0, 'Osir registrar is installed');
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'title' => 'OSIR', 'test_mode' => 0, 'config' => [
        'api_key' => LIVE_KEY, 'max_yearly_cost' => '', 'initialize_dns_zone' => '0', 'debug_logging' => '1',
    ]]);
    $r = fbOk('servicedomain/registrar_get', ['id' => $registrarId]);
    check(array_key_exists('api_key', $r['config']) && $r['config']['api_key'] === null && ($r['config']['api_key_set'] ?? false) === true, 'live key is stored but never returned by the admin API');
    check(!str_contains(json_encode($r), 'E2eLiveKey'), 'no key material anywhere in the registrar API response');

    $tld = fbOk('servicedomain/tld_get', ['tld' => '.com']);
    fbOk('servicedomain/tld_update', ['tld' => '.com', 'tld_registrar_id' => $registrarId, 'price_registration' => $tld['price_registration'] ?: 15, 'price_renew' => $tld['price_renew'] ?: 15, 'price_transfer' => $tld['price_transfer'] ?: 15, 'active' => 1, 'allow_register' => 1, 'allow_transfer' => 1]);
    fbOk('system/update_params', ['nameserver_1' => 'ns1.e2e-dns.test', 'nameserver_2' => 'ns2.e2e-dns.test']);
    check((int) fbOk('servicedomain/tld_get', ['tld' => '.com'])['registrar']['id'] === $registrarId, '.com is served by OSIR');
});

$domain = "e2e-alpha-$suffix";
$orderId = 0;

scenario('2', 'Register a domain end to end', function () use (&$clientId, &$orderId, $domain, $suffix): void {
    $clientId = createClient($suffix);
    $mark = requestCount();
    [$result, $error] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $domain, 'register_tld' => '.com', 'register_years' => 1]);
    check($error === null, 'order created and activated', (string) $error);
    $orderId = (int) $result;

    $reqs = requestsSince($mark);
    $register = only($reqs, 'POST', '#^/v2/domains/register$#');
    check(count($register) === 1, 'exactly one registration call');
    $body = $register[0]['body'] ?? [];
    check(($body['domain'] ?? '') === "$domain.com" && ($body['period'] ?? 0) === 1, 'domain and period sent');
    check(($body['environment'] ?? null) === 'prod', 'environment is explicit (prod)');
    check(($body['autoRenew'] ?? null) === false, 'autoRenew=false (FOSSBilling owns renewals)');
    check(($body['initializeDnsZone'] ?? null) === false, 'no DNS zone unless configured');
    check(($body['nameservers'] ?? []) === ['ns1.e2e-dns.test', 'ns2.e2e-dns.test'], 'default nameservers sent');
    check(($body['registrant']['phone'] ?? '') === '+44.2079460000' && ($body['registrant']['country'] ?? '') === 'GB', 'registrant normalised (+CC.NNNN, ISO country)');
    check(preg_match("#^fb:[0-9a-f]{12}:live:o$orderId:register:$domain\\.com:1y$#", (string) $register[0]['idempotency_key']) === 1, 'idempotency key bound to environment and order', (string) $register[0]['idempotency_key']);
    check($register[0]['key'] === 'valid' && $register[0]['authorization_header_present'] === false, 'live key sent in X-API-Key only');
    check(str_starts_with((string) $register[0]['user_agent'], 'OSIR-FOSSBilling/'), 'identifying User-Agent');

    $state = mock('state');
    check(isset($state['domains']["$domain.com"]), 'domain exists at OSIR');
    $svc = serviceOf($orderId);
    $expected = gmdate('Y-m-d', $state['domains']["$domain.com"]['expires']);
    check(str_starts_with((string) ($svc['expires_at'] ?? ''), $expected), 'FOSSBilling stored the registry expiry (UTC)', ($svc['expires_at'] ?? 'null') . " vs $expected");
}, ['1']);

scenario('3', 'Checkout safety: premium, taken and unknown availability are refused', function () use (&$clientId, $suffix): void {
    $mark = requestCount();
    [, $e1] = orderDomain($clientId, ['action' => 'register', 'register_sld' => "premium-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    check($e1 !== null && str_contains($e1, 'premium'), 'premium name refused at order time', (string) $e1);
    [, $e2] = orderDomain($clientId, ['action' => 'register', 'register_sld' => "taken-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    check($e2 !== null && str_contains($e2, 'already registered'), 'registered name refused at order time', (string) $e2);
    [, $e3] = orderDomain($clientId, ['action' => 'register', 'register_sld' => "outage-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    check($e3 !== null && str_contains($e3, 'Could not check'), 'failed availability check is not treated as "registered" or "available"', (string) $e3);
    check(only(requestsSince($mark), 'POST', '#/register$#') === [], 'no registration was attempted');
}, ['2']);

scenario('4', 'Lost response on registration: retry with the same key, charged once', function () use (&$clientId, $suffix): void {
    $name = "e2e-retry-$suffix.com";
    mock('faults', ['POST ^/v2/domains/register$' => '502-after-commit']);
    $mark = requestCount();
    [$result, $error] = orderDomain($clientId, ['action' => 'register', 'register_sld' => "e2e-retry-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    check($error === null, 'activation succeeded despite the lost response', (string) $error);
    $calls = only(requestsSince($mark), 'POST', '#^/v2/domains/register$#');
    check(count($calls) === 2 && $calls[0]['idempotency_key'] === $calls[1]['idempotency_key'], 'retried once with the SAME idempotency key');
    $charges = array_filter(mock('state')['charges'], static fn(array $c): bool => $c['what'] === "register $name");
    check(count($charges) === 1, 'charged exactly once');
}, ['2']);

scenario('5', 'Nameservers, contacts, lock, privacy', function () use (&$orderId, $domain): void {
    $mark = requestCount();
    fbOk('servicedomain/update_nameservers', ['order_id' => $orderId, 'ns1' => 'NS1.Other-DNS.test', 'ns2' => 'ns2.other-dns.test']);
    check(mock('state')['domains']["$domain.com"]['ns'] === ['ns1.other-dns.test', 'ns2.other-dns.test'], 'nameservers updated and normalised');
    fbOk('servicedomain/update_nameservers', ['order_id' => $orderId, 'ns1' => 'ns1.other-dns.test', 'ns2' => 'ns2.other-dns.test']);
    check(count(only(requestsSince($mark), 'PUT', '#/nameservers$#')) === 1, 'unchanged set is not re-sent (registries reject rem+add of the same host)');

    fbOk('servicedomain/update_contacts', ['order_id' => $orderId, 'contact' => [
        'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.org', 'company' => '', 'address1' => '2 Compiler Road',
        'address2' => '', 'country' => 'US', 'city' => 'Arlington', 'state' => 'VA', 'postcode' => '22201', 'phone_cc' => '1', 'phone' => '(555) 555-0100',
    ]]);
    $contacts = mock('state')['domains']["$domain.com"]['contacts'];
    check(array_keys($contacts) === ['registrant', 'admin', 'tech', 'billing'] && $contacts['registrant']['phone'] === '+1.5555550100', 'contacts sent for all roles, phone normalised');

    fbOk('servicedomain/lock', ['order_id' => $orderId]);
    check(mock('state')['domains']["$domain.com"]['locked'] === true, 'locked');
    fbOk('servicedomain/unlock', ['order_id' => $orderId]);
    check(mock('state')['domains']["$domain.com"]['locked'] === false, 'unlocked');
    fbOk('servicedomain/disable_privacy_protection', ['order_id' => $orderId]);
    check(mock('state')['domains']["$domain.com"]['privacy'] === false, 'privacy disabled');
    fbOk('servicedomain/enable_privacy_protection', ['order_id' => $orderId]);
    check(mock('state')['domains']["$domain.com"]['privacy'] === true, 'privacy enabled');
}, ['2']);

scenario('6', 'Transfer code is delivered but never persisted or logged', function () use (&$orderId, $domain): void {
    $code = fbOk('servicedomain/get_transfer_code', ['order_id' => $orderId]);
    check($code === mock('state')['domains']["$domain.com"]['authCode'], 'auth code returned to the admin');
    fbOk('servicedomain/sync', ['order_id' => $orderId]);
    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    $details = (string) $pdo->query('SELECT details FROM service_domain WHERE sld = ' . $pdo->quote($domain))->fetchColumn();
    check($details !== '' && !str_contains($details, (string) $code), 'serialized domain details in FOSSBilling DB do not contain the auth code');
}, ['2']);

scenario('7', 'Renewal: charged once, even when the response is lost', function () use (&$orderId, $domain): void {
    $before = mock('state')['domains']["$domain.com"]['expires'];
    mock('faults', ['POST ^/v2/domains/[^/]+/renew$' => '502-after-commit']);
    $mark = requestCount();
    [, $error] = fb('order/renew', ['id' => $orderId]);
    check($error === null, 'renewal succeeded despite the lost response', (string) $error);
    $calls = only(requestsSince($mark), 'POST', '#/renew$#');
    check(count($calls) === 2 && $calls[0]['idempotency_key'] === $calls[1]['idempotency_key'], 'retried with the SAME key');
    check(preg_match('#:live:o\d+:renew:' . preg_quote("$domain.com", '#') . ':1y-exp\d{8}$#', (string) $calls[0]['idempotency_key']) === 1, 'renew key encodes FOSSBilling\'s known expiry', (string) $calls[0]['idempotency_key']);
    $after = mock('state')['domains']["$domain.com"]['expires'];
    check($after - $before === 365 * 86400, 'renewed exactly one year (not two)');
    $svc = serviceOf($orderId);
    check(str_starts_with((string) $svc['expires_at'], gmdate('Y-m-d', $after)), 'FOSSBilling synced the new expiry');
}, ['2']);

scenario('7b', 'Late renewal after the registry auto-renewed: the grace is paid, not skipped', function () use (&$clientId, $suffix): void {
    $name = "e2e-late-$suffix";
    [$oid, $error] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $name, 'register_tld' => '.com', 'register_years' => 1]);
    check($error === null, 'registered', (string) $error);
    $before = mock('state')['domains']["$name.com"]['expires'];
    mock('auto-renew', ['domain' => "$name.com"]); // expiry passes unpaid; the registry renews on its own

    [, $renewError] = fb('order/renew', ['id' => (int) $oid]);
    check($renewError === null, 'renewal accepted', (string) $renewError);
    $state = mock('state');
    $graceCharges = array_filter($state['charges'], static fn(array $c): bool => $c['what'] === "grace $name.com");
    $extraRenewals = array_filter($state['charges'], static fn(array $c): bool => $c['what'] === "renew $name.com");
    check(count($graceCharges) === 1, 'the auto-renew grace was paid exactly once');
    check($extraRenewals === [], 'no second registry renewal on top of the auto-renew');
    check(($state['domains']["$name.com"]['grace'] ?? true) === false, 'domain left the grace period (no parking, no deletion)');
    check($state['domains']["$name.com"]['expires'] === $before + 365 * 86400, 'expiry is exactly one year later');
}, ['2']);

scenario('8', 'Transfers: happy path, pending sync, wrong auth code', function () use (&$clientId, $suffix): void {
    $name = "taken-beta-$suffix"; // registered elsewhere, as a transfer-in must be
    [$oid, $error] = orderDomain($clientId, ['action' => 'transfer', 'transfer_sld' => $name, 'transfer_tld' => '.com', 'transfer_code' => 'Good#Code1']);
    check($error === null, 'transfer order activated', (string) $error);
    $t = mock('state')['transfers']["$name.com"] ?? null;
    check($t !== null && $t['authCode'] === 'Good#Code1', 'transfer initiated with the auth code');
    [, $syncErr] = fb('servicedomain/sync', ['order_id' => (int) $oid]);
    check($syncErr === null, 'sync during a pending transfer does not fail', (string) $syncErr);

    [, $bad] = orderDomain($clientId, ['action' => 'transfer', 'transfer_sld' => "taken-gamma-$suffix", 'transfer_tld' => '.com', 'transfer_code' => 'wrong-code']);
    check($bad !== null && str_contains($bad, 'transfer code') && !str_contains($bad, 'credentials'), 'wrong auth code reported as such (not as bad API key)', (string) $bad);
}, ['2']);

scenario('9', 'Test Mode is refused: OSIR has no test environment, and nothing is sent', function () use (&$registrarId, &$orderId, &$clientId, $suffix): void {
    // A sandbox key stored by 1.0.x (its form field is gone) stays masked by the admin API.
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['api_key_test' => OLD_SANDBOX_KEY]]);
    $r = fbOk('servicedomain/registrar_get', ['id' => $registrarId]);
    check(array_key_exists('api_key_test', $r['config']) && $r['config']['api_key_test'] === null && ($r['config']['api_key_test_set'] ?? false) === true, 'a sandbox key stored by 1.0.x stays masked');
    check(!str_contains(json_encode($r), 'E2eOldSandbox'), 'no trace of it in the registrar API response');

    $pending = createOrder($clientId, ['action' => 'register', 'register_sld' => "e2e-ote-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'test_mode' => 1]);
    try {
        $mark = requestCount();
        [, $activate] = fb('order/activate', ['id' => $pending]);
        [, $renew] = fb('order/renew', ['id' => (int) $orderId]);
        [, $lock] = fb('servicedomain/lock', ['order_id' => $orderId]);
        $refused = static fn(?string $e): bool => $e !== null && str_contains($e, 'not configured correctly');
        check($refused($activate) && $refused($renew) && $refused($lock), 'registration, renewal and lock are refused with the generic message', json_encode([$activate, $renew, $lock]));
        check(requestsSince($mark) === [], 'no request reached OSIR (Test Mode never becomes live operations)');
        $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
        $logged = (int) $pdo->query("SELECT COUNT(*) FROM activity_system WHERE message LIKE '%OSIR has no test environment%'")->fetchColumn();
        $files = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/fb/data/log', FilesystemIterator::SKIP_DOTS)) as $file) {
            $files .= (string) file_get_contents((string) $file);
        }
        check($logged > 0 || str_contains($files, 'OSIR has no test environment'), 'the administrator log says to turn Test Mode off');
    } finally {
        fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'test_mode' => 0]);
    }
    fb('order/delete', ['id' => $pending]);
}, ['2']);

scenario('10', 'Wrong API key: generic message, key not echoed', function () use (&$registrarId, &$orderId): void {
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['api_key' => 'osir_live_WrongKeyBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB']]);
    [, $error] = fb('servicedomain/lock', ['order_id' => $orderId]);
    check($error !== null && str_contains($error, 'Reference:') && !str_contains($error, 'WrongKey'), 'rejected with a reference, key not echoed', (string) $error);
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['api_key' => LIVE_KEY]]);
}, ['2']);

scenario('11', 'Redirects are never followed (key cannot leak to another host)', function () use (&$orderId): void {
    setConstant('OSIR_REGISTRAR_API_URL', 'https://redirect.osir.test');
    try {
        $mark = requestCount();
        [, $error] = fb('servicedomain/lock', ['order_id' => $orderId]);
    } finally {
        setConstant('OSIR_REGISTRAR_API_URL', 'https://api.osir.test');
    }
    check($error !== null && str_contains($error, 'temporary error'), 'redirect answered with an error, not followed', (string) $error);
    check(requestsSince($mark) === [], 'the redirect target never received the request');
}, ['2']);

scenario('12', 'TLS is always verified', function () use (&$orderId): void {
    setConstant('OSIR_REGISTRAR_CA_FILE', null); // system CA store does not trust the dev CA
    try {
        $mark = requestCount();
        [, $error] = fb('servicedomain/lock', ['order_id' => $orderId]);
    } finally {
        setConstant('OSIR_REGISTRAR_CA_FILE', '/certs/ca.pem');
    }
    check($error !== null && str_contains($error, 'could not be reached'), 'untrusted certificate refused', (string) $error);
    check(requestsSince($mark) === [], 'nothing reached the API');
}, ['2']);


scenario('15', 'Lost renewal answer, then a sync, then a retry: renewed and charged once', function () use (&$clientId, $suffix): void {
    $name = "e2e-sync-$suffix";
    [$oid, $error] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $name, 'register_tld' => '.com', 'register_years' => 1]);
    check($error === null, 'registered', (string) $error);
    $before = mock('state')['domains']["$name.com"]['expires'];

    mock('faults', ['POST ^/v2/domains/[^/]+/renew$' => ['mode' => '502-after-commit', 'times' => 10]]);
    $mark = requestCount();
    [, $lost] = fb('order/renew', ['id' => (int) $oid]);
    mock('faults', []);
    check($lost !== null, 'first renewal reported as failed (answer lost)', 'no error');
    check(count(charges("renew $name.com")) === 1, 'OSIR renewed once');

    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    $state = static fn(): string => json_encode($pdo->query('SELECT co.status, co.period, co.expires_at AS order_expires, sd.expires_at AS service_expires FROM client_order co JOIN service_domain sd ON sd.id = co.service_id WHERE co.id = ' . (int) $oid)->fetch(PDO::FETCH_ASSOC));
    $afterFailure = $state();
    // FOSSBilling's cron runs its batch expiry sync every few minutes; here it runs right between the lost
    // answer and the retry. It does not tell the adapter which order it syncs, so it copies OSIR's new
    // expiry. The retry must still reuse the first key (anchored to the order's own expiry).
    $pdo->exec("UPDATE setting SET value = '2000-01-01 00:00:00' WHERE param = 'servicedomain_last_sync'");
    fbOk('servicedomain/batch_sync_expiration_dates');
    fbOk('servicedomain/sync', ['order_id' => (int) $oid]); // FOSSBilling now holds OSIR's new expiry
    $afterSync = $state();
    [, $retry] = fb('order/renew', ['id' => (int) $oid]);
    check($retry === null, 'retry after the sync succeeds', (string) $retry);
    $keys = array_values(array_unique(array_column(only(requestsSince($mark), 'POST', '#/renew$#'), 'idempotency_key')));
    check(count(charges("renew $name.com")) === 1, 'still charged exactly once', 'keys ' . json_encode($keys) . ' after failure ' . $afterFailure . ' after sync ' . $afterSync);
    check(mock('state')['domains']["$name.com"]['expires'] === $before + 365 * 86400, 'renewed exactly one year');
    fbOk('servicedomain/sync', ['order_id' => (int) $oid]); // order active again: normal sync
    $svc = serviceOf((int) $oid);
    check(str_starts_with((string) $svc['expires_at'], gmdate('Y-m-d', mock('state')['domains']["$name.com"]['expires'])), 'FOSSBilling expiry matches OSIR once the renewal is resolved', (string) $svc['expires_at']);
}, ['2']);

scenario('15b', 'An order without an expiry date is never renewed (its retry could not be recognised)', function () use (&$clientId, $suffix): void {
    [$oid] = orderDomain($clientId, ['action' => 'register', 'register_sld' => "e2e-noexp-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    $pdo->exec('UPDATE client_order SET expires_at = NULL, period = NULL WHERE id = ' . (int) $oid);
    $mark = requestCount();
    [, $error] = fb('order/renew', ['id' => (int) $oid]);
    check($error !== null && str_contains($error, 'no expiry date'), 'refused with an explanation', (string) $error);
    check(only(requestsSince($mark), 'POST', '#/renew$#') === [] && charges("renew e2e-noexp-$suffix.com") === [], 'nothing sent, nothing charged');
}, ['2']);

scenario('16', 'Two orders for the same name: the second is not attached to the first one\'s domain', function () use (&$clientId, $suffix): void {
    $name = "e2e-twin-$suffix";
    $first = createOrder($clientId, ['action' => 'register', 'register_sld' => $name, 'register_tld' => '.com', 'register_years' => 1]);
    $second = createOrder($clientId, ['action' => 'register', 'register_sld' => $name, 'register_tld' => '.com', 'register_years' => 1]);
    [, $e1] = fb('order/activate', ['id' => $first]);
    [, $e2] = fb('order/activate', ['id' => $second]);
    check($e1 === null, 'first order registers', (string) $e1);
    // FOSSBilling's own availability check (at service creation) or the adapter's idempotency proof refuses it.
    check($e2 !== null && (str_contains($e2, 'already registered') || str_contains($e2, 'was not registered by this order')), 'second order refused', (string) $e2);
    check(count(charges("register $name.com")) === 1, 'charged once');
}, ['2']);

scenario('17', 'OSIR stored a 5xx for the key: the next attempt rotates the key after a re-check', function () use (&$clientId, $suffix): void {
    $name = "e2e-stored-$suffix";
    mock('faults', ['POST ^/v2/domains/register$' => '500-stored']);
    $mark = requestCount();
    [$oid, $first] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $name, 'register_tld' => '.com', 'register_years' => 1]);
    check($first !== null, 'first activation fails with the backend error', 'no error');
    [, $second] = fb('order/activate', ['id' => (int) $oid]);
    check($second === null, 'activating again succeeds', (string) $second);
    $keys = array_map(static fn(array $r): string => (string) $r['idempotency_key'], only(requestsSince($mark), 'POST', '#^/v2/domains/register$#'));
    check(count($keys) === 3 && $keys[0] === $keys[1] && str_ends_with($keys[2], ':a2'), 'stored 5xx replayed once, then key :a2', implode(', ', $keys));
    check(count(charges("register $name.com")) === 1, 'charged once');
}, ['2']);

scenario('18', 'Cost limit: a price above the configured maximum is refused before anything is charged', function () use (&$registrarId, &$clientId, $suffix): void {
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['max_yearly_cost' => '5']]);
    try {
        $mark = requestCount();
        [, $error] = orderDomain($clientId, ['action' => 'register', 'register_sld' => "e2e-capped-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    } finally {
        fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['max_yearly_cost' => '']]);
    }
    check($error !== null && str_contains($error, 'above the limit'), 'refused with the limit message', (string) $error);
    check(only(requestsSince($mark), 'POST', '#/register$#') === [], 'no registration attempted');
}, ['2']);

scenario('19', 'Transfer of a domain held by another OSIR customer', function () use (&$clientId, $suffix): void {
    [, $error] = orderDomain($clientId, ['action' => 'transfer', 'transfer_sld' => "foreign-$suffix", 'transfer_tld' => '.com', 'transfer_code' => 'Good#Code2']);
    check($error === null, 'accepted (OSIR\'s "not the owner" 403 means "not in this account")', (string) $error);
    check(isset(mock('state')['transfers']["foreign-$suffix.com"]), 'transfer initiated');
}, ['2']);

scenario('20', 'Batch expiry sync records success even when a domain has left the account', function () use ($suffix): void {
    mock('set-status', ['domain' => "e2e-sync-$suffix.com", 'status' => 'transferredOut']);
    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    $pdo->exec("UPDATE setting SET value = '2000-01-01 00:00:00' WHERE param = 'servicedomain_last_sync'");
    [$ran, $error] = fb('servicedomain/batch_sync_expiration_dates');
    $last = (string) $pdo->query("SELECT value FROM setting WHERE param = 'servicedomain_last_sync'")->fetchColumn();
    check($error === null && $ran === true, 'batch sync ran', (string) $error);
    check(str_starts_with($last, date('Y-m-d')), 'FOSSBilling recorded the batch as successful (no endless re-sync)', $last);
}, ['15']);

scenario('21', 'Import tool, step 1: TLDs priced from OSIR\'s quote plus markup; nothing charged', function () use (&$registrarId): void {
    [, $error] = fb('extension/activate', ['type' => 'mod', 'id' => 'osir']);
    check($error === null || str_contains($error, 'already'), 'OSIR import module activates', (string) $error);
    // TLDs created by a previous run of this suite would otherwise be "already in FOSSBilling".
    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    $pdo->exec("DELETE FROM tld WHERE tld IN ('.io', '.shop', '.net')");
    $mark = requestCount();
    $chargesBefore = count(mock('state')['charges']);

    $catalog = array_column(fbOk('osir/tld_catalog', ['registrar_id' => $registrarId, 'refresh' => 1]), null, 'tld');
    check(isset($catalog['.io'], $catalog['.shop'], $catalog['.xn--p1ai']) && !isset($catalog['.broken']), 'catalog loaded; the entry without a price is left out', implode(',', array_keys($catalog)));
    check(($catalog['.com']['fossbilling']['same_registrar'] ?? false) === true, '.com is recognised as already on this registrar');

    $markup = ['markup_percent' => '20', 'markup_fixed' => '0', 'rounding' => '99'];
    $preview = array_column(fbOk('osir/tld_preview', ['registrar_id' => $registrarId, 'tlds' => ['.io', '.shop', '.net']] + $markup), null, 'tld');
    check(($preview['.shop']['cost']['register'] ?? null) === '30.50' && ($preview['.shop']['price']['register'] ?? null) === '36.99', 'promotion ignored: cost 30.00 + 0.50 fees, +20 %, up to .99', json_encode($preview['.shop'] ?? null));
    check(($preview['.io']['estimated'] ?? false) === true && ($preview['.io']['cost']['renew'] ?? null) === '50.50', 'registry price above the catalog raises renewal too, and is flagged', json_encode($preview['.io'] ?? null));
    check(($preview['.net']['cost']['renew'] ?? null) === '12.50', 'renewal cost = catalog renewal + the same fees');
    [, $notYet] = fb('servicedomain/tld_get', ['tld' => '.io']);
    check($notYet !== null, 'preview writes nothing');

    [, $tooMany] = fb('osir/tld_import', ['registrar_id' => $registrarId, 'tlds' => ['.a1', '.a2', '.a3', '.a4', '.a5', '.a6', '.a7', '.a8', '.a9', '.b1', '.b2']] + $markup);
    check($tooMany !== null, 'more than 10 TLDs per call is refused');
    [, $badMarkup] = fb('osir/tld_import', ['registrar_id' => $registrarId, 'tlds' => ['.io'], 'markup_percent' => '-5', 'markup_fixed' => '0', 'rounding' => 'none']);
    check($badMarkup !== null, 'a negative markup is refused');

    $comBefore = fbOk('servicedomain/tld_get', ['tld' => '.com']);
    $result = array_column(fbOk('osir/tld_import', ['registrar_id' => $registrarId, 'tlds' => ['.io', '.shop', '.net', '.com'], 'update_existing' => 0] + $markup), null, 'tld');
    check(array_map(static fn(array $r) => $r['status'], $result) === ['.io' => 'created', '.shop' => 'created', '.net' => 'created', '.com' => 'skipped'], 'three created, the existing one left alone', json_encode(array_map(static fn(array $r) => [$r['status'], $r['message'] ?? null], $result)));
    $shop = fbOk('servicedomain/tld_get', ['tld' => '.shop']);
    check((float) $shop['price_registration'] === 36.99 && (int) $shop['registrar']['id'] === $registrarId && (bool) $shop['active'], '.shop saved with the previewed price on the OSIR registrar', json_encode($shop));
    check(fbOk('servicedomain/tld_get', ['tld' => '.com'])['price_registration'] === $comBefore['price_registration'], 'existing price unchanged without "re-price"');
    check(only(requestsSince($mark), 'POST', '#.#') === [] && count(mock('state')['charges']) === $chargesBefore, 'read-only at OSIR: no POST, nothing charged');
}, ['1']);

scenario('22', 'Import tool, step 2: existing OSIR domains become active orders; nothing registered or charged', function () use (&$registrarId, $suffix, $domain): void {
    mock('seed-domain', ['domain' => "e2e-imp-$suffix.shop", 'days_left' => 200]);
    mock('seed-domain', ['domain' => "e2e-imp2-$suffix.io", 'days_left' => 300]);
    mock('seed-domain', ['domain' => "e2e-grace-$suffix.io", 'days_left' => 345, 'status' => 'autoRenewGracePeriod']);
    mock('seed-domain', ['domain' => "e2e-red-$suffix.shop", 'status' => 'redemptionPeriod']);
    mock('seed-domain', ['domain' => "e2e-notld-$suffix.dev"]);
    $mark = requestCount();
    $chargesBefore = count(mock('state')['charges']);

    $list = array_column(fbOk('osir/domain_list', ['registrar_id' => $registrarId, 'refresh' => 1]), null, 'domain');
    check(($list["e2e-imp-$suffix.shop"]['importable'] ?? false) === true && ($list["e2e-imp2-$suffix.io"]['importable'] ?? false) === true, 'active domains are importable');
    check(($list["e2e-grace-$suffix.io"]['importable'] ?? true) === false && str_contains((string) ($list["e2e-grace-$suffix.io"]['reason'] ?? ''), 'unpaid'), 'a domain in the auto-renew grace period is refused (its expiry is already a year ahead)', json_encode($list["e2e-grace-$suffix.io"] ?? null));
    check(($list["e2e-red-$suffix.shop"]['importable'] ?? true) === false, 'a domain in redemption is not importable');
    check(str_contains((string) ($list["e2e-notld-$suffix.dev"]['reason'] ?? ''), 'TLD'), 'a TLD missing in FOSSBilling is explained');
    check(($list["$domain.com"]['importable'] ?? true) === false && ($list["$domain.com"]['order_id'] ?? 0) > 0, 'a domain FOSSBilling already has is linked to its order, not offered again');

    $client = createClient("imp$suffix");
    [, $wrongClient] = fb('osir/domain_import', ['registrar_id' => $registrarId, 'client_id' => 999999, 'domains' => ["e2e-imp-$suffix.shop"]]);
    check($wrongClient !== null, 'unknown client is refused');

    $result = array_column(fbOk('osir/domain_import', ['registrar_id' => $registrarId, 'client_id' => $client, 'domains' => ["e2e-imp-$suffix.shop", "e2e-imp2-$suffix.io", "e2e-red-$suffix.shop", "e2e-grace-$suffix.io", '../etc.com']]), null, 'domain');
    check(($result["e2e-imp-$suffix.shop"]['status'] ?? null) === 'imported' && ($result["e2e-imp2-$suffix.io"]['status'] ?? null) === 'imported', 'two imported', json_encode($result));
    check(($result["e2e-red-$suffix.shop"]['status'] ?? null) === 'skipped' && ($result["e2e-grace-$suffix.io"]['status'] ?? null) === 'skipped', 'redemption and grace domains skipped, even when requested directly');
    check(count($result) === 5 && in_array('failed', array_column($result, 'status'), true), 'an invalid name fails on its own without stopping the batch');

    $orderId = (int) $result["e2e-imp-$suffix.shop"]['order_id'];
    $order = fbOk('order/get', ['id' => $orderId]);
    $expected = gmdate('Y-m-d', (int) mock('state')['domains']["e2e-imp-$suffix.shop"]['expires']);
    check($order['status'] === 'active' && (int) $order['client_id'] === $client, 'order is active for the chosen client', $order['status']);
    check(str_starts_with((string) $order['expires_at'], $expected), 'order expiry = OSIR expiry', $order['expires_at'] . ' vs ' . $expected);
    check((float) $order['price'] === (float) fbOk('servicedomain/tld_get', ['tld' => '.shop'])['price_renew'] && $order['period'] === '1Y', 'order renews yearly at the TLD renewal price');
    $service = serviceOf($orderId);
    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    $action = $pdo->query('SELECT action FROM service_domain WHERE sld = ' . $pdo->quote("e2e-imp-$suffix") . " AND tld = '.shop'")->fetchColumn();
    check($action === null, 'service has no pending register/transfer action (re-activation cannot register it)', var_export($action, true));
    check(($service['synced_at'] ?? null) !== null && (int) ($service['locked'] ?? 0) === 1, 'first sync ran through the adapter (lock state from OSIR)');
    check(($service['ns1'] ?? null) === 'ns1.seed.test' && ($service['contact']['email'] ?? null) === 'grace@example.org', 'nameservers and registrant contact come from OSIR', json_encode([$service['ns1'] ?? null, $service['contact'] ?? null]));

    $again = fbOk('osir/domain_import', ['registrar_id' => $registrarId, 'client_id' => $client, 'domains' => ["e2e-imp-$suffix.shop"]]);
    check(($again[0]['status'] ?? null) === 'skipped', 'importing again is refused (no duplicate order)');

    $posts = only(requestsSince($mark), 'POST', '#.#');
    check($posts === [] && count(mock('state')['charges']) === $chargesBefore, 'nothing registered, renewed, transferred or charged at OSIR', json_encode($posts));
}, ['21', '2']);

scenario('13', 'Cancelling an order never deletes the domain at OSIR', function () use (&$orderId, $domain): void {
    $mark = requestCount();
    [, $error] = fb('order/cancel', ['id' => $orderId, 'reason' => 'e2e']);
    check($error === null, 'order cancelled', (string) $error);
    check(requestsSince($mark) === [], 'no API call on cancel');
    check(isset(mock('state')['domains']["$domain.com"]), 'domain still registered');
}, ['2']);

scenario('14', 'Log hygiene: no API key, no auth code in FOSSBilling logs', function () use ($domain): void {
    $haystack = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/fb/data/log', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $haystack .= (string) file_get_contents((string) $file);
    }
    $pdo = new PDO('mysql:host=db;dbname=fossbilling', 'fossbilling', getenv('OSIRFB_DB_PASSWORD') ?: '');
    foreach ($pdo->query('SELECT message FROM activity_system') as $row) {
        $haystack .= $row['message'] . "\n";
    }
    check(str_contains($haystack, '[OSIR]'), 'adapter log lines are present (debug on)');
    check(!str_contains($haystack, 'E2eLiveKey') && !str_contains($haystack, 'E2eOldSandbox'), 'no API key in any log (live or old sandbox)');
    $code = mock('state')['domains']["$domain.com"]['authCode'];
    check(!str_contains($haystack, $code), 'no auth code in any log');
    check(!str_contains($haystack, 'fossbilling-e2e+') && !str_contains($haystack, 'grace@example.org'), 'no contact e-mail in any log');
}, ['2', '6']);

scenario('23', 'Client DNS tab: only your own domain, and never the zone apex', function () use ($suffix): void {
    // Its own client and domain: the shared order of scenario 2 is cancelled by scenario 13.
    $dnsSuffix = $suffix . 'd';
    $client = createClient($dnsSuffix);
    $name = "e2e-dns-$suffix";
    [$order, $orderError] = orderDomain($client, ['action' => 'register', 'register_sld' => $name, 'register_tld' => '.com', 'register_years' => 1]);
    check($orderError === null, 'a domain is registered for the DNS client', (string) $orderError);
    $order = (int) $order;

    $jar = clientLogin($dnsSuffix, $loginError);
    check($loginError === null, 'the client signs in to their own area', (string) $loginError);

    [$overview, $error] = clientApi($jar, 'osir/dns_records', ['order_id' => $order]);
    check($error === null && is_array($overview), 'client reads the records of their own domain', (string) $error);
    check(($overview['domain'] ?? '') === "$name.com", 'the domain comes from the order, not the request');
    check(($overview['status']['uses_our_nameservers'] ?? true) === false, 'warns that the domain is not on OSIR nameservers');
    check(array_filter($overview['records'] ?? [], static fn(array $r): bool => $r['type'] === 'SOA') !== [], 'the zone apex is shown');

    $mark = requestCount();
    [, $error] = clientApi($jar, 'osir/dns_record_create', ['order_id' => $order, 'name' => 'www', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => 600]);
    check($error === null, 'a record is added', (string) $error);
    $created = only(requestsSince($mark), 'POST', '#^/v2/dns/domains/[^/]+/records$#');
    check(count($created) === 1, 'exactly one DNS write');
    check(preg_match("#^fb:[0-9a-f]{12}:live:o$order:dns-add:$name\\.com:[0-9a-f]{12}$#", (string) ($created[0]['idempotency_key'] ?? '')) === 1, 'the create carries an idempotency key bound to the order and record', (string) ($created[0]['idempotency_key'] ?? ''));

    [$after] = clientApi($jar, 'osir/dns_records', ['order_id' => $order]);
    $www = array_values(array_filter($after['records'] ?? [], static fn(array $r): bool => $r['name'] === "www.$name.com"));
    check(count($www) === 1 && $www[0]['content'] === '203.0.113.7', 'the record is there afterwards');

    // The apex belongs to OSIR: refused locally, so nothing is sent at all.
    $mark = requestCount();
    [, $soa] = clientApi($jar, 'osir/dns_record_create', ['order_id' => $order, 'name' => '@', 'type' => 'SOA', 'content' => 'ns1.osir.test. a.b. 1 2 3 4 5']);
    [, $ns] = clientApi($jar, 'osir/dns_record_create', ['order_id' => $order, 'name' => '@', 'type' => 'NS', 'content' => 'ns1.attacker.test']);
    [, $ttl] = clientApi($jar, 'osir/dns_record_create', ['order_id' => $order, 'name' => 'x', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => 5]);
    check($soa !== null && $ns !== null && $ttl !== null, 'SOA, apex NS and an impossible TTL are all refused');
    check(requestsSince($mark) === [], 'nothing was sent to OSIR for a refused record');

    // Another client's domain: refused before OSIR is contacted.
    $otherClient = createClient($suffix . 'e');
    [$otherOrder, $otherError] = orderDomain($otherClient, ['action' => 'register', 'register_sld' => "e2e-dns2-$suffix", 'register_tld' => '.com', 'register_years' => 1]);
    check($otherError === null, "a second client's domain is registered", (string) $otherError);
    $mark = requestCount();
    [, $denied] = clientApi($jar, 'osir/dns_records', ['order_id' => (int) $otherOrder]);
    check($denied === 'Order not found', "another client's domain is refused", (string) $denied);
    check(requestsSince($mark) === [], 'nothing was sent to OSIR for a foreign order');

    // The apex is OSIR's: its record ids are in the page, so they must be refused server-side.
    $apexNs = array_values(array_filter($after['records'] ?? [], static fn(array $r): bool => $r['type'] === 'NS' && $r['name'] === "$name.com"));
    $soa = array_values(array_filter($after['records'] ?? [], static fn(array $r): bool => $r['type'] === 'SOA'));
    check($apexNs !== [] && $soa !== [], 'the apex records are listed with ids');
    check(($soa[0]['locked'] ?? false) === true && ($apexNs[0]['locked'] ?? false) === true, 'the apex rows are marked as managed by OSIR');
    [, $soaDelete] = clientApi($jar, 'osir/dns_record_delete', ['order_id' => $order, 'record_id' => (string) ($soa[0]['id'] ?? '')]);
    check($soaDelete !== null, 'deleting the SOA record by id is refused', 'it was deleted');
    [, $nsOverwrite] = clientApi($jar, 'osir/dns_record_update', ['order_id' => $order, 'record_id' => (string) ($apexNs[0]['id'] ?? ''), 'name' => 'www', 'type' => 'A', 'content' => '203.0.113.9']);
    check($nsOverwrite !== null, 'overwriting an apex nameserver record by id is refused', 'it was overwritten');
    [$stillThere] = clientApi($jar, 'osir/dns_records', ['order_id' => $order]);
    check(count(array_filter($stillThere['records'] ?? [], static fn(array $r): bool => $r['type'] === 'NS' && $r['name'] === "$name.com")) === count($apexNs), 'the apex nameservers are untouched');

    [, $error] = clientApi($jar, 'osir/dns_record_delete', ['order_id' => $order, 'record_id' => (string) ($www[0]['id'] ?? '')]);
    check($error === null, 'the record is deleted', (string) $error);
    [$final] = clientApi($jar, 'osir/dns_records', ['order_id' => $order]);
    check(array_filter($final['records'] ?? [], static fn(array $r): bool => $r['name'] === "www.$name.com") === [], 'it is gone afterwards');

    // Re-adding what was just deleted must really re-add it: a reused idempotency key would make
    // OSIR replay the first success for 30 days while the record never came back.
    [, $error] = clientApi($jar, 'osir/dns_record_create', ['order_id' => $order, 'name' => 'www', 'type' => 'A', 'content' => '203.0.113.7', 'ttl' => 600]);
    check($error === null, 'the same record can be added again', (string) $error);
    [$again] = clientApi($jar, 'osir/dns_records', ['order_id' => $order]);
    check(array_filter($again['records'] ?? [], static fn(array $r): bool => $r['name'] === "www.$name.com") !== [], 'the re-added record is really there');

    @unlink($jar);
}, ['21']);

scenario('24', 'Premium names: refused by default, allowed only within the cost limit', function () use (&$registrarId, &$clientId, $suffix): void {
    $bargain = "bargain-$suffix";          // premium at OSIR, 0.86 USD to register and to renew
    $expensive = "premium-x-$suffix";      // premium at OSIR, 4999.00 USD
    $trap = "trap-$suffix";                // 0.86 USD to register, 4999.00 USD to renew, no premium flag

    // Default: refused before anything is quoted.
    $mark = requestCount();
    [, $off] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $bargain, 'register_tld' => '.com', 'register_years' => 1]);
    check($off !== null && str_contains($off, 'premium'), 'a cheap premium name is refused while the setting is off', (string) $off);
    check(only(requestsSince($mark), 'POST', '#/register$#') === [], 'nothing was registered');

    // Turned on but with no cost limit: still refused, because there is nothing to judge the price by.
    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['allow_cheaper_premium' => '1', 'max_yearly_cost' => '']]);
    [, $noCap] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $bargain, 'register_tld' => '.com', 'register_years' => 1]);
    check($noCap !== null && str_contains($noCap, 'premium'), 'without a cost limit a premium name is still refused', (string) $noCap);

    fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['allow_cheaper_premium' => '1', 'max_yearly_cost' => '2']]);
    try {
        $mark = requestCount();
        [$order, $error] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $bargain, 'register_tld' => '.com', 'register_years' => 1]);
        check($error === null, 'within the limit, the cheap premium name is registered', (string) $error);
        check(isset(mock('state')['domains']["$bargain.com"]), 'the domain exists at OSIR');

        // Above the limit: refused, and not even offered to the customer.
        [, $tooDear] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $expensive, 'register_tld' => '.com', 'register_years' => 1]);
        check($tooDear !== null && str_contains($tooDear, 'above the limit'), 'an expensive premium name is refused at checkout', (string) $tooDear);
        check(!isset(mock('state')['domains']["$expensive.com"]), 'and it was not registered');
        check(only(requestsSince($mark), 'POST', '#/register$#') !== [] && count(only(requestsSince($mark), 'POST', '#/register$#')) === 1, 'exactly one registration in this block');

        // The renewal trap: cheap to register, premium to renew, and OSIR does not flag it.
        [$trapOrder, $trapError] = orderDomain($clientId, ['action' => 'register', 'register_sld' => $trap, 'register_tld' => '.com', 'register_years' => 1]);
        check($trapError === null, 'a name that is cheap to register is registered', (string) $trapError);
        $mark = requestCount();
        [, $renewTrap] = fb('order/renew', ['id' => (int) $trapOrder]);
        check($renewTrap !== null && str_contains($renewTrap, 'above the limit'), 'its expensive renewal is refused by the cost limit', (string) $renewTrap);
        check(only(requestsSince($mark), 'POST', '#^/v2/domains/[^/]+/renew$#') === [], 'nothing was renewed');

        // And a genuinely cheap renewal still goes through.
        $mark = requestCount();
        [, $renewError] = fb('order/renew', ['id' => (int) $order]);
        check($renewError === null, 'the cheap premium name renews', (string) $renewError);
        check(count(only(requestsSince($mark), 'POST', '#^/v2/domains/[^/]+/renew$#')) === 1, 'exactly one renewal call');
    } finally {
        fbOk('servicedomain/registrar_update', ['id' => $registrarId, 'config' => ['allow_cheaper_premium' => '0', 'max_yearly_cost' => '']]);
    }
}, ['2']);

$total = $passes + $failures;
echo "\n" . ($failures === 0 ? "\033[32mALL $total CHECKS PASSED\033[0m" : "\033[31m$failures OF $total CHECKS FAILED\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
