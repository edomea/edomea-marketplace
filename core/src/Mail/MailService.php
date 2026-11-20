<?php

declare(strict_types=1);

namespace Emp\Core\Mail;

use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;

/**
 * Posta in uscita di EMP (SMTP), configurata dal Tecnico nella pagina Email.
 * Per i plugin: $kernel->mailer()->send(['cliente@example.com'], 'Oggetto', "Testo").
 * EMP funziona anche senza email: se non è configurata, send() segnala l'errore e basta.
 *
 * La password SMTP resta nel database (che va nei backup cifrati) e non esce mai dalle API.
 */
final class MailService
{
    private const PREFIX = 'smtp_';
    private const FIELDS = ['host', 'porta', 'sicurezza', 'utente', 'password', 'mittente', 'nome_mittente', 'rispondi_a', 'attiva', 'ultimo_test'];

    private readonly string $settings;
    private readonly AuditLog $audit;

    /** @param \Closure|null $connector trasporto alternativo (test) */
    public function __construct(private readonly \PDO $pdo, string $tablePrefix, private readonly Clock $clock, private readonly ?\Closure $connector = null)
    {
        $this->settings = $tablePrefix . 'settings';
        $this->audit = new AuditLog($pdo, $tablePrefix . 'audit', $clock);
    }

    /** @return array<string, mixed> configurazione senza la password */
    public function config(): array
    {
        $v = $this->values();
        $last = json_decode($v['ultimo_test'] ?? '', true);

        return [
            'attiva' => ($v['attiva'] ?? 'no') === 'si',
            'host' => $v['host'] ?? '',
            'porta' => (int) ($v['porta'] ?? 587),
            'sicurezza' => $v['sicurezza'] ?? 'starttls',
            'utente' => $v['utente'] ?? '',
            'password_impostata' => ($v['password'] ?? '') !== '',
            'mittente' => $v['mittente'] ?? '',
            'nome_mittente' => $v['nome_mittente'] ?? '',
            'rispondi_a' => $v['rispondi_a'] ?? '',
            'ultimo_test' => is_array($last) ? $last : null,
        ];
    }

    /**
     * @param array<string, mixed> $in password vuota = resta quella salvata
     */
    public function save(string $actor, array $in): void
    {
        $host = strtolower(trim((string) ($in['host'] ?? '')));
        $port = (int) ($in['porta'] ?? 0);
        $security = (string) ($in['sicurezza'] ?? '');
        $user = trim((string) ($in['utente'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $from = strtolower(trim((string) ($in['mittente'] ?? '')));
        $fromName = trim((string) ($in['nome_mittente'] ?? ''));
        $replyTo = strtolower(trim((string) ($in['rispondi_a'] ?? '')));
        $active = ($in['attiva'] ?? false) === true;

        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)
            && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new MailException('Server SMTP non valido (es. smtp.gmail.com, smtps.aruba.it)');
        }
        if ($port < 1 || $port > 65535) {
            throw new MailException('Porta non valida (di solito 587 con STARTTLS, 465 con SSL/TLS)');
        }
        if (!in_array($security, SmtpClient::SECURITY, true)) {
            throw new MailException('Sicurezza non valida: starttls, ssl o nessuna');
        }
        if (mb_strlen($user) > 254 || preg_match('/[\x00-\x1F]/', $user) || strlen($password) > 512 || preg_match('/[\r\n\0]/', $password)) {
            throw new MailException('Utente o password SMTP non validi');
        }
        if (!self::validEmail($from)) {
            throw new MailException('Indirizzo del mittente non valido');
        }
        if ($replyTo !== '' && !self::validEmail($replyTo)) {
            throw new MailException('Indirizzo "Rispondi a" non valido');
        }
        if (mb_strlen($fromName) > 80 || preg_match('/[\x00-\x1F<>"]/u', $fromName)) {
            throw new MailException('Nome del mittente non valido (massimo 80 caratteri, senza < > ")');
        }
        $before = $this->config();
        $values = ['host' => $host, 'porta' => (string) $port, 'sicurezza' => $security, 'utente' => $user, 'mittente' => $from,
            'nome_mittente' => $fromName, 'rispondi_a' => $replyTo, 'attiva' => $active ? 'si' : 'no'];
        if ($password !== '') {
            $values['password'] = $password;
        }
        if ($user === '') {
            $values['password'] = '';
        }
        foreach ($values as $k => $v) {
            $this->put($k, $v, $actor);
        }
        unset($before['ultimo_test']);
        $after = $this->config();
        unset($after['ultimo_test']);
        $this->audit->record($actor, 'emp-core', 'email.configurazione', 'impostazione', 'smtp', 0,
            json_encode($before, JSON_UNESCAPED_UNICODE) ?: null,
            json_encode($after + ['password_cambiata' => $password !== ''], JSON_UNESCAPED_UNICODE) ?: null);
    }

    /** Disattiva l'invio e cancella i dati (password compresa). */
    public function remove(string $actor): void
    {
        $this->pdo->prepare("DELETE FROM {$this->settings} WHERE name LIKE ?")->execute([self::PREFIX . '%']);
        $this->audit->record($actor, 'emp-core', 'email.rimossa', 'impostazione', 'smtp', 0);
    }

    /**
     * Email di prova (anche con l'invio non ancora attivo). Il risultato resta nella pagina.
     *
     * @return list<string> dialogo SMTP senza credenziali
     */
    public function test(string $actor, string $to): array
    {
        $to = strtolower(trim($to));
        if (!self::validEmail($to)) {
            throw new MailException('Indirizzo per la prova non valido');
        }
        $client = $this->client();
        $result = ['quando' => $this->clock->nowForDatabase(), 'a' => $to, 'esito' => 'ok', 'messaggio' => 'Email di prova inviata'];
        try {
            $this->deliver($client, [$to], 'Prova email da EMP', "Questa è un'email di prova inviata da EMP (Edomea Marketplace).\n\n"
                . 'Se la ricevi, la configurazione SMTP funziona: EMP potrà inviare avvisi, documenti e comunicazioni ai clienti.');
        } catch (MailException $e) {
            $result['esito'] = 'errore';
            $result['messaggio'] = $e->getMessage();
        }
        $this->put('ultimo_test', (string) json_encode($result, JSON_UNESCAPED_UNICODE), $actor);
        $this->audit->record($actor, 'emp-core', 'email.prova', 'impostazione', 'smtp', 0, null, $result['esito'] . ' → ' . $to . ': ' . $result['messaggio']);
        if ($result['esito'] !== 'ok') {
            throw new MailException($result['messaggio']);
        }

        return $client->log();
    }

    /**
     * Invio per il Core e i plugin.
     *
     * @param list<string> $to
     */
    public function send(array $to, string $subject, string $body, ?string $replyTo = null): void
    {
        if (!$this->config()['attiva']) {
            throw new MailException('Invio email non attivo: configurarlo nella pagina Email di /techadmin');
        }
        $to = array_values(array_map(static fn ($a): string => strtolower(trim((string) $a)), $to));
        if ($to === [] || count($to) > 50) {
            throw new MailException('Da 1 a 50 destinatari');
        }
        foreach ($to as $a) {
            if (!self::validEmail($a)) {
                throw new MailException('Destinatario non valido: ' . $a);
            }
        }
        if (mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
            throw new MailException('Oggetto non valido');
        }
        $this->deliver($this->client(), $to, $subject, $body, $replyTo);
    }

    public static function validEmail(string $a): bool
    {
        return strlen($a) <= 254 && filter_var($a, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n<>]/', $a);
    }

    private function client(): SmtpClient
    {
        $c = $this->config();
        if ($c['host'] === '' || $c['mittente'] === '') {
            throw new MailException('Configurare prima server SMTP e mittente');
        }

        return new SmtpClient($c['host'], $c['porta'], $c['sicurezza'], 15, $this->connector);
    }

    /** @param list<string> $to */
    private function deliver(SmtpClient $client, array $to, string $subject, string $body, ?string $replyTo = null): void
    {
        $v = $this->values();
        $c = $this->config();
        $client->send($c['utente'], $v['password'] ?? '', $c['mittente'], $c['nome_mittente'], $to, $subject, $body,
            $replyTo ?? ($c['rispondi_a'] !== '' ? $c['rispondi_a'] : null));
    }

    /** @return array<string, string> */
    private function values(): array
    {
        $stmt = $this->pdo->prepare("SELECT name, value FROM {$this->settings} WHERE name LIKE ?");
        $stmt->execute([self::PREFIX . '%']);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = substr((string) $r['name'], strlen(self::PREFIX));
            if (in_array($k, self::FIELDS, true)) {
                $out[$k] = (string) $r['value'];
            }
        }

        return $out;
    }

    private function put(string $key, string $value, string $actor): void
    {
        $name = self::PREFIX . $key;
        $now = $this->clock->nowForDatabase();
        $exists = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->settings} WHERE name = ?");
        $exists->execute([$name]);
        if ((int) $exists->fetchColumn() > 0) {
            $this->pdo->prepare("UPDATE {$this->settings} SET value = ?, updated_at = ?, updated_by = ? WHERE name = ?")->execute([$value, $now, $actor, $name]);
        } else {
            $this->pdo->prepare("INSERT INTO {$this->settings} (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)")->execute([$name, $value, $now, $actor]);
        }
    }
}
