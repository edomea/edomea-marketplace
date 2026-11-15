<?php

declare(strict_types=1);

/*
 * Autoload del Core senza Composer (a runtime EMP usa solo PHP).
 * Composer serve soltanto per i test di sviluppo.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Emp\\Core\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative)) {
        return;
    }
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
