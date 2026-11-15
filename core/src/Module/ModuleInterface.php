<?php

declare(strict_types=1);

namespace Emp\Core\Module;

use Emp\Core\Kernel;

/**
 * Contratto che ogni modulo (Core compreso) implementa nella classe dichiarata
 * nel manifest. register() è chiamato a ogni avvio, nell'ordine delle dipendenze:
 * il modulo registra qui rotte API, servizi e permessi. Non deve scrivere dati.
 */
interface ModuleInterface
{
    public function register(Kernel $kernel, Manifest $manifest): void;
}
