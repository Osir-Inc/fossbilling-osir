<?php

declare(strict_types=1);

/*
 * PSR-4 autoloader for the OSIR adapter's classes (namespace Osir\FossBilling\ -> ./src/).
 *
 * The adapter ships without Composer dependencies: FOSSBilling already provides everything it
 * needs (Symfony HttpClient, intl). This file only registers a loader and is safe to include
 * more than once or to request directly over HTTP (it has no other side effects).
 */
if (!defined('OSIR_FOSSBILLING_AUTOLOADER')) {
    define('OSIR_FOSSBILLING_AUTOLOADER', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Osir\\FossBilling\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
