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

// The version is whatever dev/scripts/fetch-fossbilling.sh put in build/ — the newest one when
// several are present. Pinning it here as well only produces confusing failures when the pin moves
// and a stale directory is still lying around, which is exactly how 1.3.2's build broke.
$envVersion = getenv('FOSSBILLING_VERSION');
if (is_string($envVersion) && $envVersion !== '') {
    $versions = [$envVersion];
} else {
    $dirs = glob($root . '/build/fossbilling-*', GLOB_ONLYDIR);
    $versions = array_map(
        static fn(string $dir): string => substr(basename($dir), strlen('fossbilling-')),
        $dirs === false ? [] : $dirs,
    );
    usort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
}
$fossbillingVersion = null;
foreach ($versions as $candidate) {
    if (is_file($root . '/build/fossbilling-' . $candidate . '/library/Registrar/AdapterAbstract.php')) {
        $fossbillingVersion = $candidate;

        break;
    }
}
if ($fossbillingVersion === null) {
    fwrite(STDERR, "No FOSSBilling release found in build/. Run dev/scripts/fetch-fossbilling.sh\n");
    exit(1);
}
$library = $root . '/build/fossbilling-' . $fossbillingVersion . '/library';

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
