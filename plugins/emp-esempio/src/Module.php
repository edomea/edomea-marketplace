<?php

declare(strict_types=1);

namespace Emp\Plugin\Esempio;

use Emp\Core\Http\Response;
use Emp\Core\Kernel;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ModuleInterface;

final class Module implements ModuleInterface
{
    public function register(Kernel $kernel, Manifest $manifest): void
    {
        $kernel->router->add('GET', '/api/v1/esempio', $manifest->id, static fn (): Response => Response::json([
            'modulo' => $manifest->id,
            'versione' => (string) $manifest->version,
        ]));
    }
}
