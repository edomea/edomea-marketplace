<?php

declare(strict_types=1);

namespace Emp\Core\Http;

/**
 * Protezione provvisoria delle funzioni di amministrazione (gestione plugin).
 *
 * Finché il Core non ha il servizio Accesso (login, ruoli, MFA), queste
 * funzioni sono disponibili SOLO dal computer Server stesso:
 * - indirizzo del client di loopback (127.0.0.1 o ::1);
 * - intestazione Host locale (blocca il DNS rebinding);
 * - per le richieste che modificano: intestazione X-EMP-Richiesta e, se
 *   presente, Origin locale (blocca le richieste da altri siti nel browser).
 */
final class LocalAdminGuard
{
    public const HEADER = 'X-EMP-Richiesta';

    public static function check(Request $request): ?Response
    {
        if (!in_array($request->remoteAddr, ['127.0.0.1', '::1'], true)) {
            return Response::error(403, 'Gestione disponibile solo dal computer Server');
        }
        if (!self::isLocalHost((string) $request->header('host'))) {
            return Response::error(403, 'Host non consentito');
        }
        if ($request->method !== 'GET') {
            if ($request->header(self::HEADER) !== '1') {
                return Response::error(403, 'Richiesta non consentita');
            }
            $origin = $request->header('origin');
            if ($origin !== null && !self::isLocalOrigin($origin)) {
                return Response::error(403, 'Origine non consentita');
            }
        }

        return null;
    }

    private static function isLocalHost(string $host): bool
    {
        return (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?$/i', $host);
    }

    private static function isLocalOrigin(string $origin): bool
    {
        return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?$#i', $origin);
    }
}
