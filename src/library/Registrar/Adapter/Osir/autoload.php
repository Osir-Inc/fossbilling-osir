<?php

declare(strict_types=1);

/*
 * PSR-4 autoloader for the OSIR adapter's classes (namespace Osir\FossBilling\ -> ./src/).
 *
 * The adapter ships without Composer dependencies: FOSSBilling already provides everything it
 * needs (Symfony HttpClient, intl). This file only registers a loader and is safe to include
 * more than once or to request directly over HTTP (it has no other side effects).
 */
if (!defined('OSIR_ADAPTER_AUTOLOADER')) {
    define('OSIR_ADAPTER_AUTOLOADER', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Osir\\FossBilling\\';

        // Spelled in pieces so the ClientFence generator leaves it alone: in that build the line
        // above becomes Osir\ClientFence\, and this one stays the namespace releases up to 1.4.0
        // used. A request for it can then only come from a file left behind by a half-finished
        // upgrade - this adapter is a file (Osir.php) beside a folder of the same name (Osir/),
        // and a file manager that will not replace the file, or an opcode cache still serving the
        // old one, leaves the two halves on different releases. PHP would report only "class not
        // found", which tells an administrator nothing, so say what happened and what to do.
        $legacy = 'Osir\\' . 'Foss' . 'Billing\\';
        // @phpstan-ignore booleanAnd.alwaysFalse, notIdentical.alwaysFalse (equal in this build, different in the generated ClientFence one)
        if ($legacy !== $prefix && str_starts_with($class, $legacy)) {
            $tell = 'The OSIR plugin\'s files come from two different releases. Delete the file library/Registrar/Adapter/Osir.php, the folder library/Registrar/Adapter/Osir and the folder modules/Osir, upload the archive for this platform again, and restart PHP (or clear the opcode cache).';

            // Registrar_Exception survives the adapter's own error handling with its text intact.
            throw class_exists(Registrar_Exception::class, false) ? new Registrar_Exception($tell) : new RuntimeException($tell);
        }

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
