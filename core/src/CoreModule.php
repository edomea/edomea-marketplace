<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Http\LocalAdminGuard;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ManifestException;
use Emp\Core\Module\ModuleInterface;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Plugin\PackageException;

/**
 * Il Core registrato come modulo: API di sistema e gestione dei plugin.
 *
 * Rotte:
 *   GET  /api/v1/salute                     stato del servizio (pubblica)
 *   GET  /api/v1/plugin                     elenco dei plugin (solo dal Server)
 *   POST /api/v1/plugin/{id}/installa       installa o aggiorna (solo dal Server)
 *   POST /api/v1/plugin/{id}/disinstalla    rimuove dal registro (solo dal Server)
 *
 * La pagina web che usa queste API è public/amministrazione/plugin.html.
 */
final class CoreModule implements ModuleInterface
{
    /** Esecutore registrato nella tracciabilità finché manca il servizio Accesso. */
    public const LOCAL_ACTOR = 'amministrazione-locale';

    public function register(Kernel $kernel, Manifest $manifest): void
    {
        $kernel->router->add('GET', '/api/v1/salute', $manifest->id, static fn (): Response => Response::json([
            'stato' => 'ok',
            'core' => (string) $manifest->version,
            'ora' => $kernel->clock->now()->format(\DateTimeInterface::ATOM),
        ]));

        $kernel->router->add('GET', '/api/v1/plugin', $manifest->id, static function (Request $r) use ($kernel): Response {
            return LocalAdminGuard::check($r) ?? Response::json($kernel->plugins()->overview());
        });

        $kernel->router->add('POST', '/api/v1/plugin/{id}/installa', $manifest->id, static function (Request $r) use ($kernel): Response {
            return LocalAdminGuard::check($r) ?? self::run(static fn (): array => [
                'ok' => true,
                'operazioni' => $kernel->plugins()->install($r->param('id'), self::LOCAL_ACTOR),
            ]);
        });

        $kernel->router->add('POST', '/api/v1/plugin/{id}/disinstalla', $manifest->id, static function (Request $r) use ($kernel): Response {
            return LocalAdminGuard::check($r) ?? self::run(static function () use ($kernel, $r): array {
                $kernel->plugins()->uninstall($r->param('id'), self::LOCAL_ACTOR);

                return ['ok' => true, 'operazioni' => ['Plugin rimosso dal registro. I dati non sono stati cancellati.']];
            });
        });
    }

    /** @param \Closure(): array<string, mixed> $action */
    private static function run(\Closure $action): Response
    {
        try {
            return Response::json($action());
        } catch (ResolutionException | PackageException | ManifestException $e) {
            // Errori previsti (dipendenze, pacchetti, versioni): messaggio per l'operatore.
            return Response::error(409, $e->getMessage());
        }
    }
}
