<?php

declare(strict_types=1);

namespace Emp\Core\Http;

use Emp\Core\Access\AccessException;
use Emp\Core\Access\User;
use Emp\Core\CoreModule;
use Emp\Core\Kernel;

/**
 * Aiuti per le API dei plugin, uguali per tutti:
 *
 *   $r->add('GET', '/api/v1/studio/prodotti', $id, static fn (Request $q): Response => Api::run(static function () use ($kernel, $q) {
 *       $user = Api::user($kernel, $q, 'studio.vedi');
 *       ...
 *   }));
 *
 * - Api::user: sessione valida, password non temporanea, permesso del ruolo (amministratori: tutti i permessi).
 * - Api::run: errori come JSON con il codice giusto (401/403 accesso, 404 non trovato, 409 conflitto, 422 dati non validi).
 *   Un plugin lancia ApiException($messaggio, $codice) per gli errori previsti.
 */
final class Api
{
    public static function user(Kernel $kernel, Request $q, string $permission): User
    {
        $user = CoreModule::requireUser($kernel->access(), $q);
        if (!$kernel->jobRoles()->allows($user, $permission)) {
            throw new AccessException('Non hai il permesso per questa funzione (' . $permission . '): chiedilo all\'amministratore', 403);
        }

        return $user;
    }

    /** @param \Closure(): Response $action */
    public static function run(\Closure $action): Response
    {
        try {
            return $action();
        } catch (AccessException $e) {
            return Response::error($e->status, $e->getMessage());
        } catch (ApiException $e) {
            return Response::error($e->status, $e->getMessage());
        }
    }
}
