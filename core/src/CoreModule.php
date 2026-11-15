<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Http\Response;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ModuleInterface;

/**
 * Il Core registrato come modulo: espone le API di sistema.
 */
final class CoreModule implements ModuleInterface
{
    public function register(Kernel $kernel, Manifest $manifest): void
    {
        $kernel->router->add('GET', '/api/v1/salute', $manifest->id, static fn (): Response => Response::json([
            'stato' => 'ok',
            'core' => (string) $manifest->version,
            'ora' => $kernel->clock->now()->format(\DateTimeInterface::ATOM),
        ]));
        // L'elenco dei moduli installati sarà esposto via API solo dopo il servizio
        // Accesso (permesso core.moduli.gestisci); oggi è disponibile da riga di comando.
    }
}
