<?php

declare(strict_types=1);

namespace Emp\Core\Backup;

use Emp\Core\Access\AccessException;
use Emp\Core\Access\Role;
use Emp\Core\Access\User;
use Emp\Core\CoreModule;
use Emp\Core\Http\AdminGuard;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Kernel;

/**
 * API della pagina Backup (solo Amministratore Tecnico, solo HTTPS).
 *
 *   GET  /api/v1/backup                        stato: impostazioni, destinazione, backup, operazione in corso
 *   POST /api/v1/backup/aggiorna               rilegge lo stato (anche l'elenco remoto)
 *   POST /api/v1/backup/esegui                 backup completo adesso
 *   POST /api/v1/backup/impostazioni           {ora, notturno, conserva_locali, conserva_remoti}
 *   POST /api/v1/backup/destinazione           {tipo: gdrive|sftp|cartella, password, chiave?, destinazione?, password_sftp?, percorso?}
 *   POST /api/v1/backup/destinazione/google    {indirizzo}: secondo passo del collegamento a Google Drive
 *   POST /api/v1/backup/scollega               {password}
 *   POST /api/v1/backup/chiave                 {password}: chiave dei backup (nella risposta, una volta)
 *   POST /api/v1/backup/scarica                {nome, origine: locale|remoto, password}
 *   POST /api/v1/backup/ripristina             {nome, origine, password, conferma: "RIPRISTINA"}
 *   GET  /api/v1/backup/richiesta/{id}         esito di una richiesta ({pronta: false} finché è in corso)
 *   GET  /api/v1/backup/file/{token}           scarica il file preparato (Nginx, X-Accel-Redirect)
 *
 * Pagina Sistema (aggiornamenti dei pacchetti di Ubuntu, stesso servizio di EMP Server):
 *   GET  /api/v1/sistema                       versione di Ubuntu, aggiornamenti disponibili, riavvio necessario
 *   POST /api/v1/sistema/verifica              aggiorna l'elenco degli aggiornamenti disponibili
 *   POST /api/v1/sistema/aggiorna              {password}: backup di EMP, poi installazione degli aggiornamenti
 *   POST /api/v1/sistema/riavvia               {password, conferma: "RIAVVIA"}: riavvio del server tra 1 minuto
 *   (l'esito delle richieste si legge da /api/v1/backup/richiesta/{id})
 *
 * Le operazioni sono eseguite da EMP Server (servizio emp-backup-web): le POST restituiscono
 * l'identificativo della richiesta, la pagina ne legge l'esito. Password, chiavi e token non
 * vanno mai nel registro di tracciabilità.
 */
final class BackupRoutes
{
    private const NAME = '/^emp-backup-[a-z0-9.-]{1,160}\.tar$/';

    public static function register(Kernel $kernel, string $moduleId): void
    {
        $r = $kernel->router;

        $r->add('GET', '/api/v1/backup', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel): Response {
            $bridge = $kernel->backup();
            if ($bridge === null || !$bridge->isAvailable()) {
                return Response::json(['disponibile' => false, 'messaggio' => BackupBridge::unavailableMessage()]);
            }

            return Response::json(['disponibile' => true] + $bridge->overview());
        }));

        $r->add('POST', '/api/v1/backup/aggiorna', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static fn (User $user): Response => self::enqueue($kernel, $user, 'aggiorna')));

        $r->add('POST', '/api/v1/backup/esegui', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static fn (User $user): Response => self::enqueue($kernel, $user, 'backup', [], 'Backup richiesto dalla pagina')));

        $r->add('POST', '/api/v1/backup/impostazioni', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $in = $q->json();
            $time = is_string($in['ora'] ?? null) ? $in['ora'] : '';
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                throw new BackupException('Orario non valido (HH:MM, es. 02:30)');
            }
            $local = self::int($in['conserva_locali'] ?? null, 90, 'Backup da conservare su questa macchina: da 1 a 90');
            $remote = self::int($in['conserva_remoti'] ?? null, 365, 'Backup da conservare sulla destinazione remota: da 1 a 365');
            $params = ['ora' => $time, 'notturno' => ($in['notturno'] ?? false) === true ? 'si' : 'no',
                'conserva_locali' => (string) $local, 'conserva_remoti' => (string) $remote];

            return self::enqueue($kernel, $user, 'impostazioni', $params, json_encode($params, JSON_UNESCAPED_SLASHES) ?: null);
        }));

        $r->add('POST', '/api/v1/backup/destinazione', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'backup.destinazione');
            $key = strtoupper(trim($q->input('chiave')));
            if ($key !== '' && !preg_match('/^EMP-BK(-[A-Z2-7]{6}){5}$/', $key)) {
                throw new BackupException('Chiave dei backup non valida (formato EMP-BK-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX)');
            }
            $withKey = $key !== '' ? ['chiave' => $key] : [];
            $summary = static fn (string $what): string => (string) json_encode(['destinazione' => $what, 'chiave_esistente' => $key !== ''], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return match ($q->input('tipo')) {
                'gdrive' => self::enqueue($kernel, $user, 'gdrive_avvia', $withKey, $summary('Google Drive')),
                'sftp' => self::enqueue($kernel, $user, 'sftp', [
                    'destinazione' => self::sftpTarget($q->input('destinazione')),
                    'password_b64' => base64_encode($q->input('password_sftp')),
                ] + $withKey, $summary('SFTP ' . $q->input('destinazione'))),
                'cartella' => self::enqueue($kernel, $user, 'cartella', ['percorso' => self::folder($q->input('percorso'))] + $withKey, $summary('Cartella ' . $q->input('percorso'))),
                default => throw new BackupException('Scegliere la destinazione: Google Drive, SFTP o cartella'),
            };
        }));

        $r->add('POST', '/api/v1/backup/destinazione/google', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $url = trim($q->input('indirizzo'));
            if (!preg_match('#^http://(127\.0\.0\.1|localhost):53682/\?#', $url)) {
                throw new BackupException('Incollare l\'indirizzo completo che inizia con http://127.0.0.1:53682/');
            }

            return self::enqueue($kernel, $user, 'gdrive_completa', ['indirizzo' => $url]);
        }));

        $r->add('POST', '/api/v1/backup/scollega', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'backup.scollega');

            return self::enqueue($kernel, $user, 'scollega');
        }));

        $r->add('POST', '/api/v1/backup/chiave', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'backup.chiave');

            return self::enqueue($kernel, $user, 'chiave', [], 'Chiave dei backup mostrata');
        }));

        $r->add('POST', '/api/v1/backup/scarica', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            [$name, $origin] = self::backupRef($q);
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'backup.scarica');

            return self::enqueue($kernel, $user, 'scarica', ['nome' => $name, 'origine' => $origin], $origin . ' ' . $name);
        }));

        $r->add('POST', '/api/v1/backup/ripristina', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            [$name, $origin] = self::backupRef($q);
            if ($q->input('conferma') !== 'RIPRISTINA') {
                throw new BackupException('Per confermare scrivere RIPRISTINA');
            }
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'backup.ripristina');

            return self::enqueue($kernel, $user, 'ripristina', ['nome' => $name, 'origine' => $origin], $origin . ' ' . $name);
        }));

        // ---- pagina Sistema -------------------------------------------------------------
        $r->add('GET', '/api/v1/sistema', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel): Response {
            $bridge = $kernel->backup();
            if ($bridge === null || !$bridge->isAvailable()) {
                return Response::json(['disponibile' => false, 'messaggio' => BackupBridge::unavailableMessage()]);
            }
            $o = $bridge->overview();
            $job = $o['lavoro'];
            $isSystemJob = is_array($job) && str_starts_with((string) ($job['azione'] ?? ''), 'sistema_');

            return Response::json(['disponibile' => true, 'sistema' => $o['stato']['sistema'] ?? null,
                'lavoro' => $isSystemJob ? $job : null, 'in_coda' => $o['in_coda']]);
        }));

        $r->add('POST', '/api/v1/sistema/verifica', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static fn (User $user): Response => self::enqueue($kernel, $user, 'sistema_verifica')));

        $r->add('POST', '/api/v1/sistema/aggiorna', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'sistema.aggiorna');

            return self::enqueue($kernel, $user, 'sistema_aggiorna', [], 'Aggiornamenti di Ubuntu richiesti dalla pagina');
        }));

        $r->add('POST', '/api/v1/sistema/riavvia', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            if ($q->input('conferma') !== 'RIAVVIA') {
                throw new BackupException('Per confermare scrivere RIAVVIA');
            }
            $kernel->access()->confirmPassword($user, $q->input('password'), $q->remoteAddr, 'sistema.riavvia');

            return self::enqueue($kernel, $user, 'sistema_riavvia', [], 'Riavvio del server richiesto dalla pagina');
        }));

        $r->add('GET', '/api/v1/backup/richiesta/{id}', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $bridge = self::bridge($kernel);
            $out = $bridge->takeResponse($q->param('id'), $user->username);
            if ($out === null) {
                return Response::json(['pronta' => false]);
            }

            return Response::json(['pronta' => true, 'ok' => $out['ok'] === true, 'messaggio' => (string) ($out['messaggio'] ?? ''),
                'dati' => is_array($out['dati'] ?? null) ? $out['dati'] : []]);
        }));

        $r->add('GET', '/api/v1/backup/file/{token}', $moduleId, static fn (Request $q): Response => self::guarded($kernel, $q, static function (User $user) use ($kernel, $q): Response {
            $file = self::bridge($kernel)->download($q->param('token'), $user->username);
            if ($file === null) {
                return Response::error(404, 'Download scaduto o non disponibile: prepararlo di nuovo dalla pagina Backup');
            }
            $kernel->audit()->record($user->username, 'emp-core', 'backup.scaricato', 'backup', $file['nome']);

            // Nginx consegna il file (location interna /_emp_scaricamenti/), il Core solo l'autorizzazione.
            return new Response(200, '', [
                'X-Accel-Redirect' => '/_emp_scaricamenti/' . $file['token'] . '.tar',
                'Content-Type' => 'application/x-tar',
                'Content-Disposition' => 'attachment; filename="' . $file['nome'] . '"',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }));
    }

    /** @param \Closure(User): Response $action */
    private static function guarded(Kernel $kernel, Request $q, \Closure $action): Response
    {
        $blocked = AdminGuard::check($q);
        if ($blocked !== null) {
            return $blocked;
        }
        try {
            return $action(CoreModule::requireUser($kernel->access(), $q, false, Role::TECNICO));
        } catch (AccessException $e) {
            return Response::error($e->status, $e->getMessage());
        } catch (BackupException $e) {
            return Response::error(409, $e->getMessage());
        }
    }

    private static function bridge(Kernel $kernel): BackupBridge
    {
        $bridge = $kernel->backup();
        if ($bridge === null || !$bridge->isAvailable()) {
            throw new BackupException(BackupBridge::unavailableMessage());
        }

        return $bridge;
    }

    /** @param array<string, string> $params */
    private static function enqueue(Kernel $kernel, User $user, string $action, array $params = [], ?string $summary = null): Response
    {
        $id = self::bridge($kernel)->enqueue($action, $user->username, $params);
        if ($action !== 'aggiorna' && $action !== 'gdrive_completa') {
            $system = str_starts_with($action, 'sistema_');
            $kernel->audit()->record($user->username, 'emp-core', $system ? 'sistema.' . substr($action, 8) : 'backup.' . $action,
                $system ? 'sistema' : 'backup', $system ? 'ubuntu' : 'emp', 0, null, $summary);
        }

        return Response::json(['ok' => true, 'richiesta' => $id], 202);
    }

    /** @return array{0: string, 1: string} */
    private static function backupRef(Request $q): array
    {
        $name = $q->input('nome');
        $origin = $q->input('origine');
        if (!preg_match(self::NAME, $name) || str_contains($name, '..')) {
            throw new BackupException('Backup non valido');
        }
        if ($origin !== 'locale' && $origin !== 'remoto') {
            throw new BackupException('Origine del backup non valida');
        }

        return [$name, $origin];
    }

    private static function int(mixed $value, int $max, string $error): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new BackupException($error);
        }

        return $value;
    }

    private static function sftpTarget(string $target): string
    {
        $target = trim($target);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}@[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?$/', $target)) {
            throw new BackupException('Server SFTP non valido: scrivere utente@server oppure utente@server:porta');
        }

        return $target;
    }

    private static function folder(string $path): string
    {
        $path = rtrim(trim($path), '/');
        if (!preg_match('#^/(mnt|media|srv)(/[A-Za-z0-9._-]{1,64}){1,6}$#', $path) || str_contains($path, '/.')) {
            throw new BackupException('Cartella non valida: deve stare sotto /mnt, /media o /srv (disco USB o NAS montato)');
        }

        return $path;
    }
}
