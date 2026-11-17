<?php

declare(strict_types=1);

namespace Emp\Core\Http;

/**
 * Protezione delle funzioni sensibili: primo setup, recupero del Tecnico e
 * gestione dei plugin. Sono consentite solo se la password e le chiavi non
 * possono viaggiare in chiaro sulla rete del negozio:
 * - richiesta in HTTPS (EMP Server su Ubuntu: Nginx con certificato locale), oppure
 * - richiesta dal computer stesso: indirizzo di loopback e Host locale
 *   (console della VM, sviluppo); l'Host locale blocca il DNS rebinding.
 * Le richieste che modificano sono inoltre controllate da CsrfGuard
 * (intestazione X-EMP-Richiesta e Origin).
 */
final class AdminGuard
{
    public const HEADER = 'X-EMP-Richiesta';

    public static function check(Request $request): ?Response
    {
        if ($request->isSecure() || self::isLocal($request)) {
            return null;
        }

        return Response::error(403, 'Funzione disponibile solo in HTTPS: aprire EMP con l\'indirizzo https://');
    }

    /** Richiesta dal computer stesso (loopback e Host locale). */
    public static function isLocal(Request $request): bool
    {
        return in_array($request->remoteAddr, ['127.0.0.1', '::1'], true)
            && (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?$/i', (string) $request->header('host'));
    }
}
