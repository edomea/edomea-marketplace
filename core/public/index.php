<?php

declare(strict_types=1);

/*
 * Punto d'ingresso HTTP di EMP Core. Il web server (Nginx) punta qui
 * con la cartella public/ come radice; tutto il resto è fuori dal web.
 * Le pagine statiche (public/amministrazione/) le serve il web server.
 */

// Server di sviluppo PHP: i file statici li serve direttamente.
if (PHP_SAPI === 'cli-server') {
    $static = realpath(__DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if ($static !== false && is_file($static) && $static !== __FILE__ && str_starts_with($static, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Emp\Core\Config;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;

try {
    $kernel = Config::load()->kernel();
} catch (\Throwable $e) {
    error_log('[EMP] ' . $e->getMessage());
    Response::error(503, 'Servizio non disponibile: configurazione assente o non valida')->send();

    return;
}

$kernel->handle(Request::fromGlobals())->send();
