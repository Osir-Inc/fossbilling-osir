<?php

declare(strict_types=1);

/*
 * Stateful mock of the OSIR REST API — development and end-to-end tests only.
 *
 * It reproduces the parts of the API contract the adapter relies on, including the edge cases the
 * adapter must handle:
 *   - mixed response shapes (EPPApiResponse envelope, unwrapped DTOs, ownership-helper errors,
 *     empty 401 bodies, the rate limiter's shape);
 *   - availability answering available:false with an explanation when the check could not complete;
 *   - Idempotency-Key semantics: per (customer, endpoint) scope, narrow fingerprint, replay of
 *     stored 2xx/5xx with `Idempotent-Replay: true`, 409 REQUEST_IN_PROGRESS, 4xx releases the key;
 *   - dates as zone-less ISO-8601 in UTC; money in integer cents.
 *
 * Magic domain labels drive scenarios:
 *   taken*   registered by someone else          premium*  premium name (4999.00 USD)
 *   outage*  availability check errors           mine*     already in the caller's account
 *   foreign* registered by ANOTHER OSIR customer (ownership endpoints answer 403 "not the owner")
 *
 * Control endpoints (reachable only inside the compose network):
 *   POST /__mock/reset            wipe state
 *   GET  /__mock/state            full state (domains, balance, idempotency records)
 *   GET  /__mock/requests         every request received (API key replaced by a verdict)
 *   POST /__mock/faults           {"<METHOD> <path-regex>": MODE | {"mode": MODE, "times": N}}
 *                                  MODE: "502-after-commit" (work done, answer lost — also on replays),
 *                                        "timeout-after-commit", "500" (fresh error, nothing stored),
 *                                        "500-stored" (5xx recorded against the Idempotency-Key, no work done)
 *   POST /__mock/set-status       {"domain": "x.com", "status": "transferredOut"}
 *   POST /__mock/auto-renew       {"domain": "x.com"}  the registry auto-renewed at expiry: expiry +1 year,
 *                                  domain enters OSIR's auto-renew grace period (unpaid)
 *   POST /__mock/seed-domain      {"domain": "x.com", "days_left": 120, "status": "active"} a domain already in
 *                                  the e2e account (for the import tools)
 *
 * TLD catalog / quotes for the import tools: .com/.net/.org quote at their catalog price, .io quotes
 * above its catalog price (registry price differs), .shop has a first-year promotion, and one catalog
 * entry has no price (must be left out).
 */

const KEYS = [
    'osir_live_E2eLiveKeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' => 'cust-e2e',
    'osir_test_E2eTestKeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' => 'cust-e2e',
];
const STATE_FILE = '/state/db.json';
const LOG_FILE = '/state/requests.jsonl';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode($uri['path'] ?? '/');
parse_str($uri['query'] ?? '', $query);
$rawBody = file_get_contents('php://input') ?: '';
$body = json_decode($rawBody, true);
$body = is_array($body) ? $body : [];
$headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);

// ---------------------------------------------------------------- state helpers

function withState(callable $fn): mixed
{
    $fh = fopen(STATE_FILE, 'c+');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $state = $raw ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        $state = ['domains' => [], 'balance_cents' => 100000, 'idempotency' => [], 'transfers' => [], 'faults' => [], 'charges' => []];
    }
    $result = $fn($state);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $result;
}

function respond(int $status, ?array $json, array $extraHeaders = []): never
{
    http_response_code($status);
    foreach ($extraHeaders as $k => $v) {
        header("$k: $v");
    }
    if ($json !== null) {
        header('Content-Type: application/json');
        echo json_encode($json, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function ok(mixed $data, int $status = 200): array
{
    return [$status, ['success' => true, 'data' => $data, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')]];
}

function fail(int $status, string $error, string $code): array
{
    return [$status, ['success' => false, 'error' => $error, 'errorCode' => $code, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')]];
}

function ownershipError(int $status, string $error): array
{
    return [$status, ['error' => $error, 'status' => $status]];
}

function ldt(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s', $ts);
}

function label(string $domain): string
{
    return explode('.', $domain)[0];
}

// ---------------------------------------------------------------- request log

$apiKey = $headers['x-api-key'] ?? null;
$customer = $apiKey !== null ? (KEYS[$apiKey] ?? null) : null;
$logEntry = [
    'ts' => microtime(true),
    'method' => $method,
    'path' => $path,
    'query' => $query,
    'body' => $body,
    'key' => $apiKey === null ? 'none' : ($customer !== null ? (str_starts_with($apiKey, 'osir_test_') ? 'valid-test' : 'valid-live') : 'invalid'),
    'idempotency_key' => $headers['idempotency-key'] ?? null,
    'request_id' => $headers['x-request-id'] ?? null,
    'user_agent' => $headers['user-agent'] ?? null,
    'authorization_header_present' => isset($headers['authorization']),
];

// ---------------------------------------------------------------- control plane

if (str_starts_with($path, '/__mock/')) {
    match ($path) {
        '/__mock/reset' => (function (): never {
            @unlink(STATE_FILE);
            @unlink(LOG_FILE);
            respond(200, ['reset' => true]);
        })(),
        '/__mock/state' => respond(200, withState(fn(array &$s) => $s)),
        '/__mock/requests' => respond(200, ['requests' => array_values(array_filter(array_map(
            static fn(string $l) => json_decode($l, true),
            is_file(LOG_FILE) ? file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [],
        )))]),
        '/__mock/faults' => respond(200, withState(function (array &$s) use ($body) {
            $s['faults'] = $body;

            return ['faults' => $s['faults']];
        })),
        '/__mock/set-status' => respond(200, withState(function (array &$s) use ($body) {
            $d = strtolower((string) ($body['domain'] ?? ''));
            if (!isset($s['domains'][$d])) {
                return ['error' => 'unknown domain'];
            }
            $s['domains'][$d]['status'] = (string) ($body['status'] ?? 'active');

            return ['domain' => $d, 'status' => $s['domains'][$d]['status']];
        })),
        '/__mock/auto-renew' => respond(200, withState(function (array &$s) use ($body) {
            $d = strtolower((string) ($body['domain'] ?? ''));
            if (!isset($s['domains'][$d])) {
                return ['error' => 'unknown domain'];
            }
            $s['domains'][$d]['expires'] += 365 * 86400;
            $s['domains'][$d]['grace'] = true;
            $s['domains'][$d]['status'] = 'autoRenewGracePeriod';

            return ['domain' => $d, 'expires' => $s['domains'][$d]['expires']];
        })),
        '/__mock/seed-domain' => respond(200, withState(function (array &$s) use ($body) {
            $d = strtolower((string) ($body['domain'] ?? ''));
            $now = time();
            $s['domains'][$d] = [
                'customer' => 'cust-e2e', 'created' => $now - 86400 * 400, 'expires' => $now + 86400 * (int) ($body['days_left'] ?? 120),
                'ns' => ['ns1.seed.test', 'ns2.seed.test'], 'locked' => true, 'privacy' => false, 'status' => (string) ($body['status'] ?? 'active'),
                'authCode' => 'Seed#Auth1', 'env' => 'PRODUCTION',
                'contacts' => ['registrant' => ['firstName' => 'Grace', 'lastName' => 'Hopper', 'email' => 'grace@example.org', 'phone' => '+1.2025550100', 'street1' => '1 Navy Way', 'city' => 'Arlington', 'postalCode' => '22201', 'country' => 'US', 'organization' => 'Seed Inc']],
            ];

            return ['domain' => $d, 'expires' => $s['domains'][$d]['expires']];
        })),
        default => respond(404, ['error' => 'unknown control endpoint']),
    };
}

file_put_contents(LOG_FILE, json_encode($logEntry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// ---------------------------------------------------------------- authentication (empty 401 like Quarkus)

if ($customer === null) {
    respond(401, null, ['WWW-Authenticate' => 'API-Key']);
}

// ---------------------------------------------------------------- routing

$env = static fn(array $src): string => in_array(strtolower((string) ($src['environment'] ?? 'prod')), ['ote1', 'ote-1'], true) ? 'OTE1' : 'PRODUCTION';

$routes = [
    ['GET', '#^/v2/domains/([^/]+)/available$#', 'available'],
    ['GET', '#^/v2/domains/([^/]+)/quote$#', 'quote'],
    ['GET', '#^/v2/domains/([^/]+)/renewal-quote$#', 'renewalQuote'],
    ['GET', '#^/v2/domains/([^/]+)/info$#', 'info'],
    ['GET', '#^/v2/domains/([^/]+)/authcode$#', 'authcode'],
    ['PUT', '#^/v2/domains/([^/]+)/nameservers$#', 'nameservers'],
    ['PUT', '#^/v2/domains/([^/]+)/contacts$#', 'contacts'],
    ['POST', '#^/v2/domains/([^/]+)/(lock|unlock)$#', 'lock'],
    ['POST', '#^/v2/domains/([^/]+)/privacy/(enable|disable)$#', 'privacy'],
    ['POST', '#^/v2/domains/register$#', 'register'],
    ['POST', '#^/v2/domains/([^/]+)/renew$#', 'renew'],
    ['GET', '#^/v2/transfer/([^/]+)/quote$#', 'transferQuote'],
    ['POST', '#^/v2/transfer/initiate$#', 'transfer'],
    ['GET', '#^/v2/transfer/([^/]+)/status$#', 'transferStatus'],
    ['GET', '#^/v1/payment/balance$#', 'balance'],
    ['GET', '#^/v1/public/catalog/domains$#', 'catalog'],
    ['GET', '#^/v2/domains$#', 'listDomains'],
    ['GET', '#^/v2/domains/([^/]+)/contacts$#', 'readContacts'],
];

$handler = null;
$params = [];
foreach ($routes as [$m, $re, $name]) {
    if ($m === $method && preg_match($re, $path, $mm) === 1) {
        $handler = $name;
        $params = array_slice($mm, 1);

        break;
    }
}
if ($handler === null) {
    respond(404, ['success' => false, 'error' => 'Resource not found', 'errorCode' => 'NOT_FOUND']);
}

// Fault injection for the next matching request(s): consumed once, or `times` times.
$fault = withState(function (array &$s) use ($method, $path) {
    foreach ($s['faults'] as $pattern => $spec) {
        [$fm, $fre] = explode(' ', $pattern, 2);
        if ($fm === $method && preg_match('#' . $fre . '#', $path) === 1) {
            $mode = is_array($spec) ? (string) $spec['mode'] : (string) $spec;
            $left = is_array($spec) ? (int) ($spec['times'] ?? 1) - 1 : 0;
            if ($left > 0) {
                $s['faults'][$pattern] = ['mode' => $mode, 'times' => $left];
            } else {
                unset($s['faults'][$pattern]);
            }

            return $mode;
        }
    }

    return null;
});
if ($fault === '500') {
    respond(500, ['success' => false, 'error' => 'Internal server error', 'errorCode' => 'INTERNAL_ERROR']);
}

// Idempotency handling for money endpoints.
$idemEndpoint = match ($handler) {
    'register' => 'domains.register',
    'renew' => 'domains.renew',
    'transfer' => 'transfer.initiate',
    default => null,
};
$idemKey = $idemEndpoint !== null ? trim((string) ($headers['idempotency-key'] ?? '')) : '';
if ($idemKey !== '') {
    $fingerprint = match ($handler) {
        'register' => strtolower((string) ($body['domain'] ?? '')) . ':' . (int) ($body['period'] ?? 1),
        'renew' => strtolower($params[0]) . ':' . (int) ($body['period'] ?? 1),
        default => strtolower((string) ($body['domain'] ?? '')),
    };
    $slot = $customer . '|' . $idemEndpoint . '|' . $idemKey;
    $claim = withState(function (array &$s) use ($slot, $fingerprint) {
        $rec = $s['idempotency'][$slot] ?? null;
        if ($rec === null) {
            $s['idempotency'][$slot] = ['state' => 'IN_PROGRESS', 'fingerprint' => $fingerprint];

            return ['new' => true];
        }

        return ['new' => false, 'rec' => $rec];
    });
    if (!$claim['new']) {
        $rec = $claim['rec'];
        if ($rec['fingerprint'] !== $fingerprint) {
            respond(422, ['success' => false, 'error' => 'Idempotency key reused with a different request', 'errorCode' => 'IDEMPOTENCY_KEY_REUSED', 'code' => 'IDEMPOTENCY_KEY_REUSED']);
        }
        if ($rec['state'] === 'IN_PROGRESS') {
            respond(409, ['success' => false, 'error' => 'Request in progress', 'errorCode' => 'REQUEST_IN_PROGRESS', 'code' => 'REQUEST_IN_PROGRESS', 'resolution' => 'Wait and retry with the SAME key']);
        }
        if ($fault === '502-after-commit') {
            respond(502, null);                     // the proxy loses the replay too
        }
        respond($rec['status'], $rec['body'], ['Idempotent-Replay' => 'true']);
    }
    if ($fault === '500-stored') {
        // The server failed and recorded the 500 against the key; no work was done.
        $stored = ['success' => false, 'error' => 'Internal server error', 'errorCode' => 'INTERNAL_ERROR'];
        withState(function (array &$s) use ($slot, $stored) {
            $s['idempotency'][$slot] = ['state' => 'COMPLETED', 'fingerprint' => $s['idempotency'][$slot]['fingerprint'], 'status' => 500, 'body' => $stored];
        });
        respond(500, $stored);
    }
}

[$status, $json] = withState(fn(array &$s) => $handler($s, $params, $body, $query, $customer, $env));

if ($idemKey !== '') {
    withState(function (array &$s) use ($slot, $status, $json) {
        if ($status >= 400 && $status < 500) {
            unset($s['idempotency'][$slot]);       // 4xx releases the key
        } else {
            $s['idempotency'][$slot]['state'] = 'COMPLETED';
            $s['idempotency'][$slot]['status'] = $status;
            $s['idempotency'][$slot]['body'] = $json;
        }
    });
}

if ($fault === '502-after-commit') {
    respond(502, null);                             // the proxy lost the answer; the work was done
}
if ($fault === 'timeout-after-commit') {
    sleep(35);                                      // longer than the adapter's idle timeout
}

respond($status, $json);

// ---------------------------------------------------------------- handlers

function available(array &$s, array $p): array
{
    $d = strtolower($p[0]);
    $l = label($d);
    if (str_starts_with($l, 'outage')) {
        return [200, ['domain' => $d, 'available' => false, 'message' => 'Error checking availability: EPP connection reset', 'reason' => null, 'premium' => false]];
    }
    $registered = isset($s['domains'][$d]) || str_starts_with($l, 'taken') || str_starts_with($l, 'mine') || str_starts_with($l, 'foreign');
    $premium = str_starts_with($l, 'premium');
    $price = $premium ? 499900 : 1000;

    return [200, [
        'domain' => $d, 'available' => !$registered,
        'message' => $registered ? 'Domain is already registered' : 'Domain is available',
        'reason' => $registered ? 'In use' : null,
        'price' => $price, 'icannFee' => 20, 'registrarFee' => 30, 'totalPrice' => $price + 50,
        'currency' => 'USD', 'premium' => $premium, 'checkedAt' => ldt(time()),
    ]];
}

function quote(array &$s, array $p, array $b, array $q): array
{
    $years = (int) ($q['years'] ?? 1);
    $premium = str_starts_with(label($p[0]), 'premium');
    $tld = substr($p[0], strrpos($p[0], '.') + 1);
    // Registry prices that differ from the catalog (.io) or carry a first-year promotion (.shop).
    $standard = $premium ? 499900 : (['io' => 5000, 'shop' => 3000][$tld] ?? 1000);
    $promo = $tld === 'shop' && $years === 1;          // first-year promotion: 0.99
    $per = $promo ? 99 : $standard;

    return [200, [
        'domain' => $p[0], 'registrationYears' => $years, 'pricePerYear' => $per, 'totalCost' => $per * $years,
        'standardTotalCost' => $standard * $years, 'promoApplied' => $promo, 'icannFee' => 20 * $years, 'registrarFee' => 30,
        'totalFees' => $per * $years + 20 * $years + 30, 'premium' => $premium, 'valid' => true, 'currency' => 'USD',
    ]];
}

function renewalQuote(array &$s, array $p, array $b, array $q): array
{
    $years = (int) ($q['years'] ?? 1);
    $total = 1000 * $years + 20 * $years + 30;

    return ok(['domain' => $p[0], 'renewalYears' => $years, 'renewalTotal' => 1000 * $years, 'icannFee' => 20 * $years, 'registrarFee' => 30, 'totalWithRestore' => $total, 'finalTotal' => $total, 'currency' => 'USD']);
}

function owned(array &$s, string $d, string $customer): ?array
{
    $rec = $s['domains'][$d] ?? null;
    if ($rec === null && str_starts_with(label($d), 'mine')) {
        $now = time();
        $rec = $s['domains'][$d] = ['customer' => $customer, 'created' => $now - 86400 * 200, 'expires' => $now + 86400 * 165, 'ns' => ['ns1.mine.test', 'ns2.mine.test'], 'locked' => true, 'privacy' => true, 'status' => 'active', 'authCode' => 'Mine#Auth9', 'env' => 'PRODUCTION', 'contacts' => null];
    }

    return $rec !== null && $rec['customer'] === $customer ? $rec : null;
}

function info(array &$s, array $p, array $b, array $q, string $c): array
{
    $d = strtolower($p[0]);
    if (str_starts_with(label($d), 'foreign')) {
        return ownershipError(403, 'Access denied: You are not the owner of this domain');
    }
    $rec = owned($s, $d, $c);
    if ($rec === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }

    return ok([
        'domain' => $d, 'status' => $rec['status'], 'statuses' => $rec['locked'] ? ['clientTransferProhibited'] : ['ok'],
        'expiryDate' => ldt($rec['expires']), 'creationDate' => ldt($rec['created']), 'nameservers' => $rec['ns'],
        'locked' => $rec['locked'], 'expired' => false, 'premium' => false, 'autoRenew' => false, 'privacy' => $rec['privacy'],
        'inAutoRenewGracePeriod' => ($rec['grace'] ?? false) === true, 'rgpStatus' => ($rec['grace'] ?? false) ? 'autoRenewPeriod' : null,
        'registrantEmail' => $rec['contacts']['registrant']['email'] ?? null, 'registrar' => 'osir',
    ]);
}

function authcode(array &$s, array $p, array $b, array $q, string $c): array
{
    $rec = owned($s, strtolower($p[0]), $c);

    return $rec === null ? ownershipError(404, 'Domain not found') : ok(['domain' => $p[0], 'authCode' => $rec['authCode']]);
}

function nameservers(array &$s, array $p, array $b, array $q, string $c, callable $env): array
{
    $d = strtolower($p[0]);
    if (owned($s, $d, $c) === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }
    $ns = $b['nameservers'] ?? null;
    if (!is_array($ns) || count($ns) < 1 || count($ns) > 13) {
        return [400, ['title' => 'Constraint Violation', 'status' => 400, 'violations' => [['field' => 'updateNameservers.request.nameservers', 'message' => 'size must be between 1 and 13']]]];
    }
    if (array_map('strtolower', $ns) === $s['domains'][$d]['ns']) {
        // Real registries reject removing and re-adding the same host in one update.
        return fail(400, 'Registry error 2306: parameter value policy error (host both removed and added)', 'NAMESERVER_UPDATE_FAILED');
    }
    $previous = $s['domains'][$d]['ns'];
    $s['domains'][$d]['ns'] = array_map('strtolower', $ns);

    return ok(['domain' => $d, 'success' => true, 'previousNameservers' => $previous, 'newNameservers' => $s['domains'][$d]['ns'], 'environment' => $env($b)]);
}

function contacts(array &$s, array $p, array $b, array $q, string $c): array
{
    $d = strtolower($p[0]);
    if (owned($s, $d, $c) === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }
    $s['domains'][$d]['contacts'] = $b;

    return ok(['domain' => $d, 'contactsUpdated' => count($b)]);
}

function lock(array &$s, array $p, array $b, array $q, string $c): array
{
    $d = strtolower($p[0]);
    if (owned($s, $d, $c) === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }
    $s['domains'][$d]['locked'] = $p[1] === 'lock';

    return ok(['domain' => $d, 'locked' => $s['domains'][$d]['locked'], 'message' => 'ok']);
}

function privacy(array &$s, array $p, array $b, array $q, string $c): array
{
    $d = strtolower($p[0]);
    if (owned($s, $d, $c) === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }
    $s['domains'][$d]['privacy'] = $p[1] === 'enable';

    return ok(['domain' => $d, 'privacy' => $s['domains'][$d]['privacy'], 'message' => 'ok']);
}

function charge(array &$s, int $cents, string $what): bool
{
    if ($s['balance_cents'] < $cents) {
        return false;
    }
    $s['balance_cents'] -= $cents;
    $s['charges'][] = ['what' => $what, 'cents' => $cents, 'at' => time()];

    return true;
}

function register(array &$s, array $p, array $b, array $q, string $c, callable $env): array
{
    $d = strtolower((string) ($b['domain'] ?? ''));
    $period = (int) ($b['period'] ?? 1);
    if ($d === '' || strlen($d) > 63 || !is_array($b['nameservers'] ?? null) || $b['nameservers'] === []) {
        return [400, ['title' => 'Constraint Violation', 'status' => 400, 'violations' => [['field' => 'registerDomain.request.domain', 'message' => 'invalid']]]];
    }
    [, $avail] = available($s, [$d]);
    if (!$avail['available']) {
        return fail(409, 'Domain is not available: ' . $avail['message'], 'REGISTRATION_FAILED');
    }
    $reg = $b['registrant'] ?? [];
    foreach (['firstName', 'lastName', 'email', 'phone', 'street1', 'city', 'country'] as $f) {
        if (!is_string($reg[$f] ?? null) || $reg[$f] === '') {
            $reg = null; // an incomplete registrant is not stored (the adapter must never send one)

            break;
        }
    }
    $cost = $avail['totalPrice'] * $period;
    if (!charge($s, $cost, "register $d")) {
        return fail(402, "Insufficient funds. Required: $cost cents, Available: {$s['balance_cents']} cents (customer: $c)", 'REGISTRATION_FAILED');
    }
    $now = time();
    $s['domains'][$d] = [
        'customer' => $c, 'created' => $now, 'expires' => $now + 365 * 86400 * $period, 'ns' => array_map('strtolower', $b['nameservers']),
        'locked' => false, 'privacy' => true, 'status' => 'active', 'authCode' => 'Auth#' . substr(md5($d), 0, 8), 'env' => $env($b),
        'contacts' => ['registrant' => $reg], 'autoRenew' => $b['autoRenew'] ?? true, 'initializeDnsZone' => $b['initializeDnsZone'] ?? true,
    ];

    return ok(['domain' => $d, 'success' => true, 'status' => 'COMPLETED', 'creationDate' => ldt($now), 'expirationDate' => ldt($s['domains'][$d]['expires']), 'totalCost' => $cost, 'environment' => $env($b), 'authCode' => null], 201);
}

function renew(array &$s, array $p, array $b, array $q, string $c, callable $env): array
{
    $d = strtolower($p[0]);
    if (owned($s, $d, $c) === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }
    $period = (int) ($b['period'] ?? 1);
    if (($s['domains'][$d]['grace'] ?? false) === true) {
        // OSIR's auto-renew grace: the registry already renewed; only charge, always exactly 1 year.
        if (!charge($s, 1050, "grace $d")) {
            return fail(402, 'Insufficient funds', 'INSUFFICIENT_FUNDS');
        }
        $s['domains'][$d]['grace'] = false;
        $s['domains'][$d]['status'] = 'active';

        return ok(['domain' => $d, 'success' => true, 'renewalPeriod' => 1, 'totalCost' => 1050, 'status' => 'COMPLETED', 'newExpirationDate' => ldt($s['domains'][$d]['expires'])]);
    }
    $cost = 1050 * $period;
    if (!charge($s, $cost, "renew $d")) {
        return fail(402, 'Insufficient funds', 'INSUFFICIENT_FUNDS');
    }
    $old = $s['domains'][$d]['expires'];
    $s['domains'][$d]['expires'] = $old + 365 * 86400 * $period;

    return ok(['domain' => $d, 'success' => true, 'oldExpirationDate' => ldt($old), 'newExpirationDate' => ldt($s['domains'][$d]['expires']), 'renewalPeriod' => $period, 'totalCost' => $cost, 'status' => 'COMPLETED', 'environment' => $env($b)]);
}

function transferQuote(array &$s, array $p, array $b, array $q): array
{
    $tld = substr($p[0], strpos($p[0], '.'));
    if (!in_array($tld, ['.com', '.net', '.org'], true)) {
        return fail(404, 'Unsupported extension ' . $tld, 'UNSUPPORTED_EXTENSION');
    }
    $years = (int) ($q['years'] ?? 1);

    return [200, ['domain' => $p[0], 'transferYears' => $years, 'totalCost' => 900 * $years, 'icannFee' => 20, 'serviceFee' => 30, 'totalFees' => 900 * $years + 50, 'requiresAuthCode' => true]];
}

function transfer(array &$s, array $p, array $b, array $q, string $c, callable $env): array
{
    $d = strtolower((string) ($b['domain'] ?? ''));
    if (($b['authCode'] ?? '') === 'wrong-code') {
        return fail(401, 'Invalid authorization code', 'INVALID_AUTH_CODE');
    }
    if (isset($s['transfers'][$d]) && $s['transfers'][$d]['customer'] === $c) {
        return fail(409, 'Transfer request already exists for ' . $d, 'TRANSFER_EXISTS');
    }
    if (!charge($s, 950, "transfer $d")) {
        return fail(402, 'Insufficient funds', 'INSUFFICIENT_FUNDS');
    }
    $s['transfers'][$d] = ['customer' => $c, 'status' => 'PENDING', 'initiated' => time(), 'env' => $env($b), 'authCode' => $b['authCode'] ?? null];

    return [200, ['domain' => $d, 'success' => true, 'message' => 'Transfer initiated successfully', 'status' => 'PENDING', 'transferInitiatedDate' => ldt(time()), 'expectedCompletionDate' => ldt(time() + 5 * 86400), 'totalCost' => 950]];
}

function transferStatus(array &$s, array $p, array $b, array $q, string $c): array
{
    $d = strtolower($p[0]);
    $t = $s['transfers'][$d] ?? null;
    if ($t === null || $t['customer'] !== $c || $t['status'] !== 'PENDING') {
        return fail(404, 'No pending transfer for ' . $d, 'TRANSFER_NOT_FOUND');
    }

    return [200, ['domain' => $d, 'status' => 'PENDING', 'transferType' => 'GAINING', 'transferInitiatedDate' => ldt($t['initiated']), 'expectedCompletionDate' => ldt($t['initiated'] + 5 * 86400)]];
}

function balance(array &$s, array $p, array $b, array $q, string $c): array
{
    return [200, ['success' => true, 'data' => ['balance' => $s['balance_cents'] / 100, 'currency' => 'USD', 'customerId' => $c], 'error' => null, 'timestamp' => gmdate('c')]];
}

function catalog(): array
{
    $e = static fn(string $ext, int $reg, int $renew, int $transfer, string $type = 'gTLD') => [
        'extension' => $ext, 'registrar' => 'MOCK', 'registrationPrice' => $reg, 'renewalPrice' => $renew, 'transferPrice' => $transfer,
        'restorePrice' => 4000, 'minCharacters' => 2, 'maxCharacters' => 63, 'minRegistrationPeriod' => 1, 'maxRegistrationPeriod' => 10,
        'hasPremium' => false, 'hasRestrictions' => false, 'extensionType' => $type,
    ];

    return [200, ['totalExtensions' => 7, 'extensions' => [
        $e('.com', 1000, 1000, 0),
        $e('.net', 1000, 1200, 1000),
        $e('.org', 1000, 1000, 1000),
        $e('.io', 4000, 4000, 4000, 'ccTLD'),
        $e('.shop', 3000, 3000, 3000),
        $e('.broken', 0, 0, 0),
        $e('.xn--p1ai', 1000, 1000, 1000, 'ccTLD'),
    ], 'promotions' => [], 'registrars' => ['MOCK']]];
}

function listDomains(array &$s, array $p, array $b, array $q, string $c): array
{
    $mine = array_filter($s['domains'], static fn(array $d) => $d['customer'] === $c);
    uasort($mine, static fn(array $a, array $b) => $a['expires'] <=> $b['expires']);
    $size = max(1, min(100, (int) ($q['size'] ?? 20)));
    $page = max(0, (int) ($q['page'] ?? 0));
    $items = [];
    foreach (array_slice($mine, $page * $size, $size, true) as $name => $d) {
        $items[] = ['id' => md5($name), 'domain' => $name, 'customerId' => $c, 'creationDate' => ldt($d['created']), 'expirationDate' => ldt($d['expires']),
            'statuses' => $d['locked'] ? ['clientTransferProhibited'] : ['ok'], 'nameservers' => $d['ns'], 'autoRenew' => false, 'privacy' => $d['privacy'], 'status' => $d['status']];
    }

    return ok(['domains' => $items, 'page' => $page, 'size' => $size, 'totalElements' => count($mine), 'totalPages' => (int) ceil(count($mine) / $size), 'empty' => $items === []]);
}

function readContacts(array &$s, array $p, array $b, array $q, string $c): array
{
    $d = strtolower($p[0]);
    $rec = owned($s, $d, $c);
    if ($rec === null) {
        return ownershipError(404, 'Domain not found: ' . $d);
    }

    return ok(['domainName' => $d, 'registrant' => $rec['contacts']['registrant'] ?? null, 'admin' => null, 'tech' => null, 'billing' => null]);
}
