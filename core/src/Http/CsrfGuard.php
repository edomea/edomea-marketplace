<?php

declare(strict_types=1);

namespace Emp\Core\Http;

/**
 * Protezione dalle richieste inviate da altri siti con il cookie di sessione
 * dell'utente (CSRF). Ogni richiesta che modifica dati deve:
 * - portare l'intestazione X-EMP-Richiesta: 1 (un altro sito non può aggiungerla
 *   senza un permesso CORS, che il Core non concede);
 * - se il browser invia Origin, provenire dallo stesso indirizzo del Core.
 * Il cookie è inoltre SameSite=Strict.
 */
final class CsrfGuard
{
    public static function check(Request $request): ?Response
    {
        if ($request->method === 'GET') {
            return null;
        }
        if ($request->header(LocalAdminGuard::HEADER) !== '1') {
            return Response::error(403, 'Richiesta non consentita');
        }
        $origin = $request->header('origin');
        if ($origin !== null) {
            $host = strtolower((string) $request->header('host'));
            $originHost = strtolower((string) preg_replace('#^https?://#i', '', $origin));
            if ($host === '' || $originHost !== $host) {
                return Response::error(403, 'Origine non consentita');
            }
        }

        return null;
    }
}
