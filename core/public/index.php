<?php

declare(strict_types=1);

/*
 * Punto d'ingresso HTTP di EMP Core. Il web server (Nginx) punta qui
 * con la cartella public/ come radice; tutto il resto è fuori dal web.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Emp\Core\Config;
use Emp\Core\Http\Response;

try {
    $kernel = Config::load()->kernel();
} catch (\Throwable $e) {
    error_log('[EMP] ' . $e->getMessage());
    Response::error(503, 'Servizio non disponibile: configurazione assente o non valida')->send();

    return;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$kernel->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), is_string($path) ? $path : '/')->send();
