<?php

declare(strict_types=1);

/*
 * OSIR adapter diagnostics for FOSSBilling. Read-only: never registers, renews or changes anything.
 *
 *   php library/Registrar/Adapter/Osir/bin/osir-doctor.php [--registrar=<id>] [--domain=<name>]
 *
 * Run it from the FOSSBilling root as the web server user (it reads config.php and the database).
 */

// This file lives inside FOSSBilling's web root. It must do nothing when requested over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 5);
if (!is_file($root . '/load.php') || !is_file($root . '/di.php')) {
    fwrite(STDERR, "FOSSBilling not found at $root. Keep this script in library/Registrar/Adapter/Osir/bin/.\n");
    exit(2);
}

$options = getopt('', ['registrar:', 'domain:', 'help']);
if ($options === false || isset($options['help'])) {
    echo "Usage: php osir-doctor.php [--registrar=<id>] [--domain=<name>]\n";
    exit(0);
}

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "FOSSBilling is not installed at $root (config.php missing).\n");
    exit(2);
}

// FOSSBilling's bootstrap may print an HTML error page (or an installer redirect) and exit 0. A
// diagnostic tool must fail loudly instead: swallow that output, and turn any exit before the
// script finished into status 2.
$run = new stdClass();
$run->finished = false;
ob_start();
register_shutdown_function(static function () use ($run): void {
    if ($run->finished === true) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, "osir-doctor: FOSSBilling did not start (bootstrap aborted). Check config.php and the FOSSBilling logs.\n");
    exit(2);
});

require_once $root . '/load.php';
require_once dirname(__DIR__) . '/autoload.php';

set_exception_handler(static function (Throwable $e): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, 'osir-doctor: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(2);
});

$di = include $root . '/di.php';
if (!$di instanceof Pimple\Container) {
    throw new RuntimeException('Unexpected FOSSBilling bootstrap (di.php did not return a container).');
}
$translate = $di['translate'];
if (is_callable($translate)) {
    $translate();
}
$db = $di['db'];
$modService = $di['mod_service'];
if (!$db instanceof Box_Database || !is_callable($modService)) {
    throw new RuntimeException('Unexpected FOSSBilling services (db / mod_service).');
}
$domainService = $modService('servicedomain');
if (!$domainService instanceof Box\Mod\Servicedomain\Service) {
    throw new RuntimeException('FOSSBilling domain module not available.');
}
ob_end_clean(); // bootstrap is done; from here on everything printed is ours

// RedBean models expose their columns through __get(); read them as plain strings.
$field = static function (Model_TldRegistrar $model, string $name): string {
    $value = $model->__get($name);

    return is_scalar($value) ? (string) $value : '';
};

$registrarFilter = isset($options['registrar']) && is_string($options['registrar']) ? $options['registrar'] : null;
$found = $db->find('TldRegistrar', 'registrar = :r', [':r' => 'Osir']);
$models = [];
foreach (is_iterable($found) ? $found : [] as $model) {
    if ($model instanceof Model_TldRegistrar && ($registrarFilter === null || $field($model, 'id') === $registrarFilter)) {
        $models[] = $model;
    }
}
if ($models === []) {
    fwrite(STDERR, "No OSIR registrar is installed (Domain Management → Registrars).\n");
    $run->finished = true;
    exit(2);
}

$domain = null;
if (isset($options['domain']) && is_string($options['domain'])) {
    try {
        $domain = Osir\FossBilling\Domain\DomainName::fromString($options['domain']);
    } catch (Osir\FossBilling\Exception\ValidationException $e) {
        fwrite(STDERR, 'Invalid domain: ' . $e->getMessage() . "\n");
        $run->finished = true;
        exit(2);
    }
}

$marks = ['ok' => "\033[32m✔\033[0m", 'warn' => "\033[33m!\033[0m", 'fail' => "\033[31m✘\033[0m"];
$failed = false;
foreach ($models as $model) {
    printf("\nRegistrar #%s \"%s\"%s\n", $field($model, 'id'), $field($model, 'name'), $field($model, 'test_mode') === '1' ? ' [Test Mode]' : '');
    try {
        $adapter = $domainService->registrarGetRegistrarAdapter($model);
        if (!$adapter instanceof Registrar_Adapter_Osir) {
            throw new RuntimeException('Registrar is not the OSIR adapter.');
        }
        $results = $adapter->diagnostics()->run($domain);
    } catch (Throwable $e) {
        echo '  ' . $marks['fail'] . ' could not start: ' . $e->getMessage() . "\n";
        $failed = true;

        continue;
    }
    foreach ($results as $r) {
        printf("  %s %-30s %s\n", $marks[$r['status']], $r['check'], $r['detail']);
        $failed = $failed || $r['status'] === 'fail';
    }
}
echo "\n";
$run->finished = true;
exit($failed ? 1 : 0);
