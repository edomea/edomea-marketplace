<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Access\AccessException;
use Emp\Core\Access\AccessService;
use Emp\Core\Access\Role;
use Emp\Core\Access\User;
use Emp\Core\Backup\BackupRoutes;
use Emp\Core\Brand\BrandException;
use Emp\Core\Brand\BrandService;
use Emp\Core\Http\AdminGuard;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ManifestException;
use Emp\Core\Module\ModuleInterface;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Plugin\LocalCatalog;
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
 *   GET  /api/v1/accesso/profilo               il proprio profilo: account, sessioni aperte, ultimi accessi
 *   POST /api/v1/accesso/profilo               {password, nome, username}: modifica del proprio profilo
 *   POST /api/v1/accesso/sessioni/chiudi-altre {password}: chiude le altre sessioni del proprio account
 *
 * Utenti (Tecnico → amministratori, amministratori → operatori)
 *   GET  /api/v1/utenti
 *   POST /api/v1/utenti                        crea con password temporanea
 *   POST /api/v1/utenti/{id}/modifica          {nome, username}
 *   POST /api/v1/utenti/{id}/password          password temporanea
 *   POST /api/v1/utenti/{id}/revoca            revoca degli accessi (chiude le sessioni)
 *   POST /api/v1/utenti/{id}/disattiva
 *   POST /api/v1/utenti/{id}/attiva
 *
 * Plugin (solo Tecnico, solo HTTPS)
 *   GET  /api/v1/plugin
 *   POST /api/v1/plugin/{id}/installa
 *   POST /api/v1/plugin/{id}/disinstalla
 *   POST /api/v1/plugin/carica                 pacchetto ZIP nel corpo (application/zip) → catalogo locale;
 *                                              intestazione facoltativa X-EMP-Impronta: SHA-256 atteso
 *   POST /api/v1/plugin/{id}/rimuovi-pacchetto toglie il pacchetto dal catalogo locale
 *   GET  /api/v1/plugin/arrivi                 ZIP caricati via scp in /var/lib/emp/arrivi/plugin
 *   POST /api/v1/plugin/arrivi/aggiungi        {file}: ZIP arrivato → catalogo locale (stessi controlli), poi eliminato
 *   POST /api/v1/plugin/arrivi/elimina         {file}: elimina lo ZIP arrivato
 *
 * Sorgente GitHub facoltativa (solo Tecnico, solo HTTPS; vedi docs/architettura-moduli.md)
 *   GET  /api/v1/plugin/github                 impostazioni (attiva, repository)
 *   POST /api/v1/plugin/github                 {attiva, repository}
 *   GET  /api/v1/plugin/github/release         release pubblicate del repository
 *   POST /api/v1/plugin/github/importa         {tag}: plugin della release → catalogo locale
 *
 * Marchio white label (lettura pubblica: serve anche alle pagine di accesso)
 *   GET  /api/v1/marchio                       {nome, colore, logo: impronta|null}
 *   GET  /api/v1/marchio/logo                  immagine del logo
 *   POST /api/v1/marchio                       {nome, colore}  (solo Tecnico, solo HTTPS)
 *   POST /api/v1/marchio/logo                  immagine PNG/JPEG/WebP nel corpo, max 512 KB  (solo Tecnico, solo HTTPS)
 *   POST /api/v1/marchio/logo/rimuovi · POST /api/v1/marchio/predefinito  (solo Tecnico, solo HTTPS)
 *
 * Backup (solo Tecnico, solo HTTPS; eseguiti da EMP Server): vedi Backup\BackupRoutes
 *   GET  /api/v1/backup  ·  POST /api/v1/backup/{esegui,impostazioni,destinazione,chiave,scarica,ripristina,…}
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
            'server' => $kernel->serverVersion,
            // Avvio sicuro (sudo emp-server avvio-sicuro): le pagine lo segnalano accanto alla versione.
            'avvio_sicuro' => $kernel->bootControl()->safeMode,
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

        // ---- Marchio white label --------------------------------------------------------
        $r->add('GET', '/api/v1/marchio', $id, static fn (): Response => Response::json($kernel->brand()->get()));

        $r->add('GET', '/api/v1/marchio/logo', $id, static function () use ($kernel): Response {
            $logo = $kernel->brand()->logo();
            if ($logo === null) {
                return Response::error(404, 'Nessun logo');
            }

            return new Response(200, $logo[1], [
                'Content-Type' => $logo[0],
                'Cache-Control' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'",
            ]);
        });

        $brandAction = static fn (Request $q, \Closure $fn): Response => AdminGuard::check($q) ?? self::run(static function () use ($access, $q, $fn, $kernel): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            try {
                $fn($kernel->brand(), $user);
            } catch (BrandException $e) {
                return Response::error(422, $e->getMessage());
            }

            return Response::json(['ok' => true, 'marchio' => $kernel->brand()->get()]);
        });
        $r->add('POST', '/api/v1/marchio', $id, static fn (Request $q): Response => $brandAction($q,
            static fn (BrandService $b, User $u) => $b->update($u->username, $q->input('nome'), $q->input('colore'))));
        $r->add('POST', '/api/v1/marchio/logo', $id, static fn (Request $q): Response => $brandAction($q, static function (BrandService $b, User $u) use ($q): void {
            $type = strtolower(trim(explode(';', (string) $q->header('content-type'))[0]));
            if (!in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new BrandException('Inviare un\'immagine PNG, JPEG o WebP');
            }
            $b->setLogo($u->username, $q->body);
        }));
        $r->add('POST', '/api/v1/marchio/logo/rimuovi', $id, static fn (Request $q): Response => $brandAction($q,
            static fn (BrandService $b, User $u) => $b->removeLogo($u->username)));
        $r->add('POST', '/api/v1/marchio/predefinito', $id, static fn (Request $q): Response => $brandAction($q,
            static fn (BrandService $b, User $u) => $b->reset($u->username)));

        // ---- Profilo -----------------------------------------------------------------
        $r->add('GET', '/api/v1/accesso/profilo', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $user = self::requireUser($access(), $q, true);

            return Response::json(['profilo' => $access()->profile($user)]);
        }));

        $r->add('POST', '/api/v1/accesso/profilo', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $user = self::requireUser($access(), $q);
            $updated = $access()->updateOwnProfile($user, $q->input('password'), $q->input('nome'), $q->input('username'), $q->remoteAddr);

            return Response::json(['ok' => true, 'utente' => $updated->toArray(), 'messaggio' => 'Profilo aggiornato.']);
        }));

        $r->add('POST', '/api/v1/accesso/sessioni/chiudi-altre', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $user = self::requireUser($access(), $q);
            $n = $access()->closeOtherSessions($user, $q->input('password'), $q->cookie(self::COOKIE), $q->remoteAddr);

            return Response::json(['ok' => true, 'messaggio' => $n === 0 ? 'Nessun\'altra sessione aperta.' : "Sessioni chiuse: {$n}."]);
        }));

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

        $r->add('POST', '/api/v1/utenti/{id}/modifica', $id, static fn (Request $q): Response => self::run(static function () use ($access, $q): Response {
            $updated = $access()->updateUser(self::requireUser($access(), $q), $q->param('id'), $q->input('nome'), $q->input('username'));

            return Response::json(['ok' => true, 'utente' => $updated->toArray(), 'messaggio' => 'Dati di ' . $updated->username . ' aggiornati.']);
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

        // ---- Plugin: caricamento manuale del pacchetto --------------------------------
        $r->add('POST', '/api/v1/plugin/carica', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            $type = strtolower(trim(explode(';', (string) $q->header('content-type'))[0]));
            if (!in_array($type, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
                throw new PackageException('Inviare il pacchetto ZIP del plugin');
            }
            $plugins = $kernel->plugins();
            $local = $plugins->localCatalog();
            if ($local === null || !$local->isWritable()) {
                throw new PackageException('Catalogo locale non disponibile: rieseguire l\'installazione di EMP Server');
            }
            $tmp = $local->dir() . DIRECTORY_SEPARATOR . '.caricamento-' . bin2hex(random_bytes(6));
            try {
                self::receive($q, $tmp, LocalCatalog::MAX_UPLOAD_BYTES);
                $entry = $plugins->upload($tmp, 'Caricato a mano da ' . $user->username, $q->header('x-emp-impronta'));
            } finally {
                @unlink($tmp);
            }
            if ($entry['nuovo']) {
                $kernel->audit()->record($user->username, 'emp-core', 'plugin.pacchetto_caricato', 'plugin', $entry['id'], 0, null, self::summary($entry));
            }

            return Response::json(['ok' => true, 'pacchetto' => $entry, 'operazioni' => [$entry['nuovo']
                ? sprintf('Pacchetto %s %s aggiunto al catalogo locale (SHA-256 %s). Installarlo dall\'elenco.', $entry['id'], $entry['version'], $entry['sha256'])
                : sprintf('Pacchetto %s %s già presente nel catalogo locale.', $entry['id'], $entry['version'])]]);
        }));

        $r->add('POST', '/api/v1/plugin/{id}/rimuovi-pacchetto', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            if (!$kernel->plugins()->removePackage($q->param('id'))) {
                return Response::error(404, 'Pacchetto non presente nel catalogo locale');
            }
            $kernel->audit()->record($user->username, 'emp-core', 'plugin.pacchetto_rimosso', 'plugin', $q->param('id'));

            return Response::json(['ok' => true, 'operazioni' => ['Pacchetto tolto dal catalogo locale. Il plugin eventualmente installato resta installato.']]);
        }));

        // ---- Plugin: pacchetti arrivati via scp -----------------------------------------
        $arrivals = static function () use ($kernel) {
            return $kernel->arrivals() ?? throw new PackageException('Cartella degli arrivi non configurata: rieseguire l\'installazione di EMP Server');
        };

        $r->add('GET', '/api/v1/plugin/arrivi', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($arrivals, $access, $q): Response {
            self::requireUser($access(), $q, false, Role::TECNICO);
            $a = $arrivals();

            return Response::json(['cartella' => $a->dir(), 'file' => $a->list()]);
        }));

        $r->add('POST', '/api/v1/plugin/arrivi/aggiungi', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $arrivals, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            $a = $arrivals();
            $name = $q->input('file');
            $entry = $kernel->plugins()->upload($a->path($name), "Arrivato via scp ({$name})");
            $a->delete($name);
            if ($entry['nuovo']) {
                $kernel->audit()->record($user->username, 'emp-core', 'plugin.pacchetto_arrivato', 'plugin', $entry['id'], 0, null, self::summary($entry));
            }

            return Response::json(['ok' => true, 'pacchetto' => $entry, 'operazioni' => [$entry['nuovo']
                ? sprintf('Pacchetto %s %s aggiunto al catalogo locale (SHA-256 %s). Installarlo dall\'elenco.', $entry['id'], $entry['version'], $entry['sha256'])
                : sprintf('Pacchetto %s %s già presente nel catalogo locale.', $entry['id'], $entry['version'])]]);
        }));

        $r->add('POST', '/api/v1/plugin/arrivi/elimina', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $arrivals, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            $name = $q->input('file');
            $arrivals()->delete($name);
            $kernel->audit()->record($user->username, 'emp-core', 'plugin.arrivo_eliminato', 'file', $name);

            return Response::json(['ok' => true, 'operazioni' => ["{$name} eliminato dagli arrivi."]]);
        }));

        // ---- Plugin: sorgente GitHub facoltativa ---------------------------------------
        $github = static function () use ($kernel) {
            return $kernel->githubSource() ?? throw new PackageException('Catalogo locale non disponibile: rieseguire l\'installazione di EMP Server');
        };

        $r->add('GET', '/api/v1/plugin/github', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($github, $access, $q): Response {
            self::requireUser($access(), $q, false, Role::TECNICO);

            return Response::json($github()->settings() + ['curl' => \Emp\Core\Plugin\CurlHttpClient::isAvailable()]);
        }));

        $r->add('POST', '/api/v1/plugin/github', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $github, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            $source = $github();
            $before = $source->settings();
            $active = ($q->json()['attiva'] ?? false) === true;
            $repository = $q->input('repository') !== '' ? $q->input('repository') : $before['repository'];
            $after = $source->saveSettings($active, $repository, $user->username, $kernel->clock->now());
            $kernel->audit()->record(
                $user->username,
                'emp-core',
                'plugin.sorgente_github',
                'impostazione',
                'sorgente-github',
                0,
                json_encode(['attiva' => $before['attiva'], 'repository' => $before['repository']], JSON_UNESCAPED_SLASHES) ?: null,
                json_encode(['attiva' => $after['attiva'], 'repository' => $after['repository']], JSON_UNESCAPED_SLASHES) ?: null,
            );

            return Response::json(['ok' => true, 'impostazioni' => $after, 'operazioni' => [$active
                ? "Sorgente GitHub attiva: {$after['repository']} (solo release pubblicate)."
                : 'Sorgente GitHub disattivata.']]);
        }));

        $r->add('GET', '/api/v1/plugin/github/release', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($github, $access, $q): Response {
            self::requireUser($access(), $q, false, Role::TECNICO);

            return Response::json(['release' => $github()->releases()]);
        }));

        $r->add('POST', '/api/v1/plugin/github/importa', $id, static fn (Request $q): Response => AdminGuard::check($q) ?? self::run(static function () use ($kernel, $github, $access, $q): Response {
            $user = self::requireUser($access(), $q, false, Role::TECNICO);
            $imported = $github()->import($q->input('tag'));
            $log = [];
            foreach ($imported as $entry) {
                if ($entry['nuovo']) {
                    $kernel->audit()->record($user->username, 'emp-core', 'plugin.pacchetto_importato', 'plugin', $entry['id'], 0, null, self::summary($entry));
                }
                $log[] = sprintf('%s %s %s', $entry['id'], $entry['version'], $entry['nuovo'] ? 'aggiunto al catalogo locale' : 'già presente');
            }
            $log[] = 'Installare o aggiornare i plugin dall\'elenco.';

            return Response::json(['ok' => true, 'pacchetti' => $imported, 'operazioni' => $log]);
        }));

        // ---- Backup di EMP (pagina Backup di /techadmin) --------------------------------
        BackupRoutes::register($kernel, $id);
    }

    /** Copia il corpo della richiesta in un file, con un limite reale sui byte. */
    private static function receive(Request $q, string $dest, int $maxBytes): void
    {
        $in = $q->openBody();
        $out = @fopen($dest, 'xb');
        if ($out === false) {
            fclose($in);
            throw new PackageException('Impossibile ricevere il pacchetto');
        }
        try {
            $written = stream_copy_to_stream($in, $out, $maxBytes + 1);
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($written === false || $written === 0) {
            throw new PackageException('Nessun pacchetto ricevuto');
        }
        if ($written > $maxBytes) {
            throw new PackageException(sprintf('Pacchetto troppo grande (massimo %d MB)', intdiv($maxBytes, 1048576)));
        }
    }

    /** @param array<string, mixed> $entry */
    private static function summary(array $entry): ?string
    {
        return json_encode(['versione' => $entry['version'], 'sha256' => $entry['sha256'], 'origine' => $entry['origine']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
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
