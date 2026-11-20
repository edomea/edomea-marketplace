<?php

declare(strict_types=1);

namespace Emp\Core\Backup;

/**
 * Collegamento tra la pagina Backup del Core e EMP Server.
 *
 * Il Core gira come utente "emp", senza permessi sulla macchina e senza poter eseguire
 * comandi: per i backup deposita una richiesta in <dir>/richieste/ (scritta prima in
 * <dir>/preparazione/ e poi spostata, così il servizio la trova sempre completa).
 * Il servizio emp-backup-web di EMP Server (root) la esegue e scrive:
 *   risposte/<id>.json   esito della richiesta, letto e cancellato qui;
 *   stato.json           impostazioni, destinazione remota, elenco dei backup;
 *   lavoro.json          avanzamento dell'ultima operazione lunga (backup, ripristino…);
 *   scaricamenti.json    file da scaricare preparati (serviti da Nginx per un'ora).
 * Vedi server/emp-backup-web e docs/ambiente-server-linux.md.
 */
final class BackupBridge
{
    /** Azioni accettate dal servizio, con i soli campi previsti. */
    public const ACTIONS = [
        'aggiorna' => [],
        'backup' => [],
        'impostazioni' => ['ora', 'notturno', 'conserva_locali', 'conserva_remoti'],
        'gdrive_avvia' => ['chiave', 'mantieni_chiave', 'client_id', 'client_secret_b64'],
        'gdrive_completa' => ['indirizzo'],
        'sftp' => ['destinazione', 'password_b64', 'chiave'],
        'cartella' => ['percorso', 'chiave'],
        'scollega' => [],
        'chiave' => [],
        'scarica' => ['nome', 'origine'],
        'ripristina' => ['nome', 'origine'],
        // Pagina Sistema: aggiornamenti dei pacchetti di Ubuntu.
        'sistema_verifica' => [],
        'sistema_aggiorna' => [],
        'sistema_riavvia' => [],
    ];

    public function __construct(private readonly string $dir)
    {
    }

    /** True su EMP Server con il servizio dei backup installato. */
    public function isAvailable(): bool
    {
        return is_dir($this->dir . '/preparazione') && is_writable($this->dir . '/preparazione')
            && is_dir($this->dir . '/richieste') && is_writable($this->dir . '/richieste')
            && is_dir($this->dir . '/risposte');
    }

    /** @return array{stato: array<string, mixed>|null, lavoro: array<string, mixed>|null, in_coda: int} */
    public function overview(): array
    {
        $queue = 0;
        foreach (@scandir($this->dir . '/richieste') ?: [] as $name) {
            if (str_ends_with($name, '.req')) {
                $queue++;
            }
        }

        return [
            'stato' => $this->readJson('stato.json'),
            'lavoro' => $this->readJson('lavoro.json'),
            'in_coda' => $queue,
        ];
    }

    /**
     * Nuova richiesta per il servizio; restituisce il suo identificativo.
     *
     * @param array<string, string> $params
     */
    public function enqueue(string $action, string $username, array $params = []): string
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new BackupException('Operazione non prevista');
        }
        if (!$this->isAvailable()) {
            throw new BackupException(self::unavailableMessage());
        }
        $lines = ['azione=' . $action, 'utente=' . $username, 'creato=' . time()];
        foreach ($params as $key => $value) {
            if (!in_array($key, self::ACTIONS[$action], true)) {
                throw new BackupException('Campo non previsto: ' . $key);
            }
            if (strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new BackupException('Valore non valido: ' . $key);
            }
            $lines[] = $key . '=' . $value;
        }
        $id = bin2hex(random_bytes(16));
        $tmp = $this->dir . '/preparazione/.' . $id;
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new BackupException('Richiesta non registrata: cartella dei backup non scrivibile');
        }
        fwrite($fh, implode("\n", $lines) . "\n");
        fclose($fh);
        if (!@rename($tmp, $this->dir . '/richieste/' . $id . '.req')) {
            @unlink($tmp);
            throw new BackupException('Richiesta non registrata');
        }

        return $id;
    }

    /**
     * Esito di una richiesta dell'utente: null finché non è pronto. Viene consegnato
     * una sola volta (può contenere la chiave dei backup) e poi cancellato.
     *
     * @return array<string, mixed>|null
     */
    public function takeResponse(string $id, string $username): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            return null;
        }
        $file = $this->dir . '/risposte/' . $id . '.json';
        if (is_link($file) || !is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || ($data['utente'] ?? null) !== $username) {
            return null;
        }
        @unlink($file);

        return $data;
    }

    /**
     * File da scaricare preparato dal servizio per l'utente, se non scaduto.
     *
     * @return array{token: string, nome: string, byte: int}|null
     */
    public function download(string $token, string $username): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        foreach ($this->readJson('scaricamenti.json') ?? [] as $d) {
            if (is_array($d) && ($d['token'] ?? null) === $token && ($d['utente'] ?? null) === $username
                && (int) ($d['scade'] ?? 0) > time() && is_string($d['nome'] ?? null)
                && preg_match('/^emp-backup-[a-z0-9.-]{1,160}\.tar$/', $d['nome'])) {
                return ['token' => $token, 'nome' => $d['nome'], 'byte' => (int) ($d['byte'] ?? 0)];
            }
        }

        return null;
    }

    public static function unavailableMessage(): string
    {
        return 'Gestione dei backup non disponibile: serve EMP Server 0.6.0-alpha.8 o successivo (aggiornare EMP Server)';
    }

    /** @return array<mixed>|null */
    private function readJson(string $name): ?array
    {
        $file = $this->dir . '/' . $name;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
