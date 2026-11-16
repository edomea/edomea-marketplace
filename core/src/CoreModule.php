<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Access\AccessException;
use Emp\Core\Access\AccessService;
use Emp\Core\Access\Role;
use Emp\Core\Access\User;
use Emp\Core\Http\AdminGuard;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ManifestException;
use Emp\Core\Module\ModuleInterface;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Plugin\PackageException;

/**
 * Il Core registrato come modulo: API di sistema, servizio Accesso e gestione plugin.
 *
 * Sistema
 *   GET  /api/v1/salute                        stato del servizio (pubblico)
 *
 * Accesso (vedi docs/accesso-sicurezza.md)
 *   GET  /api/v1/accesso/stato                 setup completato? utente della sessione
 *   POST /api/v1/accesso/setup                 primo setup del Tecnico (HTTPS + codice di setup, una volta)
 *   POST /api/v1/accesso/login                 accesso con nome utente e password
 *   POST /api/v1/accesso/logout                uscita
 *   POST /api/v1/accesso/password              cambio della propria password
 *   POST /api/v1/accesso/recupero              recupero del Tecnico con Recovery Key (solo HTTPS)
 *
 * Utenti (Tecnico → amministratori, amministratori → operatori)
 *   GET  /api/v1/utenti
 *   POST /api/v1/utenti                        crea con password temporanea
 *   POST /api/v1/utenti/{id}/password          password temporanea
 *   POST /api/v1/utenti/{id}/revoca            revoca degli accessi (chiude le sessioni)
 *   POST /api/v1/utenti/{id}/disattiva
 *   POST /api/v1/utenti/{id}/attiva
 *
 * Plugin (solo Tecnico, solo HTTPS)
 *   GET  /api/v1/plugin
 *   POST /api/v1/plugin/{id}/installa
 *   POST /api/v1/plugin/{id}/disinstalla
 */
final class CoreModule implements ModuleInterface
{
    public const COOKIE = 'emp_sessione';

    public function register(Kernel $kernel, Manifest $manifest): void
    {
        $r = $kernel->router;
        $id = $manifest->id;
        $access = static fn () => $kernel->access();

        $r->add('GET', '/api/v1/salute', $id, static fn (): Response => Response::json([
            'stato' => 'ok',
            'core' => (string) $manifest->version,
            'ora' => $kernel->clock->now()->format(\DateTimeInterface::ATOM),
        ]));

        // ---- Accesso ----------------------------------------------------------------
        $r->add('GET', '/api/v1/accesso/stato', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $user = $access()->userForToken($q->cookie(self::COOKIE));

            return Response::json([
                'inizializzato' => $access()->isInitialized(),
                'utente' => $user?->toArray(),
                // Per le pagine: funzioni sensibili disponibili? serve il codice di setup?
                'protetto' => AdminGuard::check($q) === null,
                'codice_setup' => !AdminGuard::isLocal($q),
            ]);
        }));

        $r->add('POST', '/api/v1/accesso/setup', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(
            static fn (): Response => Response::json([
                'recovery_key' => $access()->setupTecnico(
                    $q->input('username'),
                    $q->input('nome'),
                    $q->input('password'),
                    $q->input('codice'),
                    !AdminGuard::isLocal($q),
                    $q->remoteAddr,
                ),
            ]),
        ));

        $r->add('POST', '/api/v1/accesso/login', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            [$token, $user] = $access()->login($q->input('username'), $q->input('password'), $q->remoteAddr);

            return Response::json(['utente' => $user->toArray()])->withHeader('Set-Cookie', self::cookie($token, $q, AccessService::SESSION_HOURS * 3600));
        }));

        $r->add('POST', '/api/v1/accesso/logout', $id, static function (Request $q) use ($access): Response {
            $access()->logout($q->cookie(self::COOKIE));

            return Response::json(['ok' => true])->withHeader('Set-Cookie', self::cookie('', $q, 0));
        });

        $r->add('POST', '/api/v1/accesso/password', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $user = self::requireUser($access(), $q, true);
            $access()->changeOwnPassword($user, $q->input('attuale'), $q->input('nuova'), $q->cookie(self::COOKIE));

            return Response::json(['ok' => true, 'messaggio' => 'Password aggiornata. Le altre sessioni sono state chiuse.']);
        }));

        $r->add('POST', '/api/v1/accesso/recupero', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(
            static fn (): Response => Response::json([
                'recovery_key' => $access()->recoverTecnico($q->input('username'), $q->input('recovery_key'), $q->input('nuova'), $q->remoteAddr),
            ]),
        ));

        // ---- Utenti -----------------------------------------------------------------
        $r->add('GET', '/api/v1/utenti', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $actor = self::requireUser($access(), $q);

            return Response::json([
                'gestibile' => Role::managedBy($actor->role),
                'utenti' => array_map(static fn (User $u): array => $u->toArray(), $access()->listUsers($actor)),
            ]);
        }));

        $r->add('POST', '/api/v1/utenti', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $actor = self::requireUser($access(), $q);
            $user = $access()->createUser($actor, $q->input('username'), $q->input('nome'), $q->input('ruolo'), $q->input('password'));

            return Response::json(['utente' => $user->toArray()], 201);
        }));

        $r->add('POST', '/api/v1/utenti/{id}/password', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $access()->setTemporaryPassword(self::requireUser($access(), $q), $q->param('id'), $q->input('password'));

            return Response::json(['ok' => true, 'messaggio' => 'Password temporanea impostata: andrà cambiata al primo accesso.']);
        }));

        $r->add('POST', '/api/v1/utenti/{id}/revoca', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $n = $access()->revokeAccess(self::requireUser($access(), $q), $q->param('id'));

            return Response::json(['ok' => true, 'messaggio' => "Accessi revocati: {$n} sessioni chiuse."]);
        }));

        foreach (['disattiva' => false, 'attiva' => true] as $action => $active) {
            $r->add('POST', "/api/v1/utenti/{id}/{$action}", $id, static fn (Request $q): Response => self::run(static function () use ($access, $q, $active): Response {
                $access()->setActive(self::requireUser($access(), $q), $q->param('id'), $active);

                return Response::json(['ok' => true, 'messaggio' => $active ? 'Utente riattivato.' : 'Utente disattivato e sessioni chiuse.']);
            }));
        }

        // ---- Plugin (funzione tecnica) ------------------------------------------------
        $r->add('GET', '/api/v1/plugin', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $access, $q): Response {
            self::requireUser($access(), $q, false, Role::TECNICO);

            return Response::json($kernel->plugins()->overview());
        }));

        $r->add('POST', '/api/v1/plugin/{id}/installa', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);

            return Response::json(['ok' => true, 'operazioni' => $kernel->plugins()->install($q->param('id'), $user->username)]);
        }));

        $r->add('POST', '/api/v1/plugin/{id}/disinstalla', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            $kernel->plugins()->uninstall($q->param('id'), $user->username);

            return Response::json(['ok' => true, 'operazioni' => ['Plugin rimosso dal registro. I dati non sono stati cancellati.']]);
        }));
    }

    /**
     * Utente della sessione. Con $allowPending l'utente che deve ancora cambiare
     * la password temporanea è accettato (solo per il cambio password).
     */
    public static function requireUser(AccessService $access, Request $q, bool $allowPending = false, ?string $role = null): User
    {
        $user = $access->userForToken($q->cookie(self::COOKIE));
        if ($user === null) {
            throw new AccessException('Accesso richiesto', 401);
        }
        if ($user->mustChangePassword && !$allowPending) {
            throw new AccessException('Cambiare la password temporanea prima di continuare', 403);
        }
        if ($role !== null && $user->role !== $role) {
            throw new AccessException('Funzione riservata: ' . Role::label($role), 403);
        }

        return $user;
    }

    private static function cookie(string $token, Request $q, int $maxAge): string
    {
        return self::COOKIE . '=' . $token . '; Path=/; Max-Age=' . $maxAge . '; HttpOnly; SameSite=Strict' . ($q->isSecure() ? '; Secure' : '');
    }

    /** @param \Closure(): Response $action */
    private static function run(\Closure $action): Response
    {
        try {
            return $action();
        } catch (AccessException $e) {
            return Response::error($e->status, $e->getMessage());
        } catch (ResolutionException | PackageException | ManifestException $e) {
            // Errori previsti nella gestione plugin: messaggio per l'operatore.
            return Response::error(409, $e->getMessage());
        }
    }
}
