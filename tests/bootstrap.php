<?php

declare(strict_types=1);

/*
 * Unit-test bootstrap. Loads the plugin's dev dependencies and FOSSBilling's REAL library classes
 * (Registrar_*, FOSSBilling\*, Box_*) from the pinned release in build/ — tests run against the
 * genuine Registrar_Domain / Registrar_Exception code, not hand-written stubs.
 * Fetch the release first: dev/scripts/fetch-fossbilling.sh
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$envVersion = getenv('FOSSBILLING_VERSION');
$fossbillingVersion = is_string($envVersion) && $envVersion !== '' ? $envVersion : '0.8.7';
$library = $root . '/build/fossbilling-' . $fossbillingVersion . '/library';
if (!is_file($library . '/Registrar/AdapterAbstract.php')) {
    fwrite(STDERR, "FOSSBilling {$fossbillingVersion} not found in build/. Run dev/scripts/fetch-fossbilling.sh {$fossbillingVersion}\n");
    exit(1);
}

define('PATH_ROOT', dirname($library));
define('PATH_LIBRARY', $library);
define('PATH_CONFIG', __DIR__ . '/fixtures/fossbilling-config.php');
define('DEBUG', false);
define('BIND_TO', '0');

// Loader for the FOSSBilling classes the adapter touches, all taken from the pinned release:
//   Registrar_Domain_Contact -> library/Registrar/Domain/Contact.php   (PSR-0 style)
//   FOSSBilling\Exception    -> library/FOSSBilling/Exception.php
//   Box\Mod\Order\Entity\Order -> modules/Order/Entity/Order.php   (referenced by Model_ClientOrder constants)
//   RedBeanPHP\SimpleModel   -> vendor/gabordemooij/redbean/RedBeanPHP/SimpleModel.php (Model_* base class)
// FOSSBilling's full vendor autoloader is deliberately NOT used: it would pull a second copy of
// Symfony and friends next to the plugin's own dev dependencies.
$fossbillingRoot = dirname($library);
spl_autoload_register(static function (string $class) use ($library, $fossbillingRoot): void {
    if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) !== 1) {
        return;
    }
    $file = match (true) {
        str_starts_with($class, 'FOSSBilling\\') => $library . '/' . str_replace('\\', '/', $class) . '.php',
        str_starts_with($class, 'Box\\Mod\\') => $fossbillingRoot . '/modules/' . str_replace('\\', '/', substr($class, 8)) . '.php',
        str_starts_with($class, 'RedBeanPHP\\') => $fossbillingRoot . '/vendor/gabordemooij/redbean/' . str_replace('\\', '/', $class) . '.php',
        default => $library . '/' . str_replace('_', '/', $class) . '.php',
    };
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/library/Registrar/Adapter/Osir.php';
