<?php

declare(strict_types=1);

namespace Emp\Core\Mail;

/**
 * Client SMTP minimo, solo PHP (nessuna libreria esterna): STARTTLS o SSL/TLS diretto con
 * certificato del server verificato, AUTH PLAIN/LOGIN, un messaggio di testo UTF-8 per invio.
 * Il trasporto si può sostituire nei test (connettore che restituisce uno stream).
 */
final class SmtpClient
{
    public const SECURITY = ['starttls', 'ssl', 'nessuna'];

    /** @var resource */
    private $stream;
    /** @var list<string> dialogo SMTP (senza credenziali), per capire un errore */
    private array $log = [];

    /**
     * @param \Closure(string, int, string, int): resource|null $connector per i test
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security,
        private readonly int $timeout = 15,
        private readonly ?\Closure $connector = null,
    ) {
    }

    /**
     * @param list<string> $to
     */
    public function send(string $user, string $password, string $from, string $fromName, array $to, string $subject, string $body, ?string $replyTo = null): void
    {
        try {
            $this->open();
            $this->expect(220);
            $helo = $this->ehlo();
            if ($this->security === 'starttls') {
                if (!str_contains(strtoupper($helo), 'STARTTLS')) {
                    throw new MailException('Il server non offre STARTTLS: scegliere "SSL/TLS" oppure un altro server');
                }
                $this->command('STARTTLS', 220);
                $this->enableTls();
                $helo = $this->ehlo();
            }
            if ($user !== '') {
                $methods = strtoupper($helo);
                if (str_contains($methods, 'PLAIN')) {
                    $this->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $password), 235, true);
                } else {
                    $this->command('AUTH LOGIN', 334);
                    $this->command(base64_encode($user), 334, true);
                    $this->command(base64_encode($password), 235, true);
                }
            }
            $this->command('MAIL FROM:<' . $from . '>', 250);
            foreach ($to as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command('DATA', 354);
            $this->write(self::message($from, $fromName, $to, $subject, $body, $replyTo) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', [221, 250], false, true);
        } finally {
            if (is_resource($this->stream ?? null)) {
                fclose($this->stream);
            }
        }
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    /** Messaggio RFC 5322 di solo testo, UTF-8, con righe puntate protette (dot-stuffing). */
    public static function message(string $from, string $fromName, array $to, string $subject, string $body, ?string $replyTo = null): string
    {
        $enc = static fn (string $s): string => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? $enc($fromName) . ' ' : '') . '<' . $from . '>',
            'To: ' . implode(', ', array_map(static fn (string $a): string => '<' . $a . '>', $to)),
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr(strrchr($from, '@') ?: '@emp.local', 1)) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo !== null && $replyTo !== '') {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        $text = str_replace(["\r\n", "\r"], "\n", $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
    }

    private function open(): void
    {
        $remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        if ($this->connector !== null) {
            $stream = ($this->connector)($this->host, $this->port, $this->security, $this->timeout);
        } else {
            $context = stream_context_create(['ssl' => self::tlsOptions($this->host)]);
            $stream = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            if ($stream === false) {
                throw new MailException(sprintf('Connessione a %s:%d non riuscita: %s', $this->host, $this->port, $errstr !== '' ? $errstr : 'errore ' . $errno));
            }
        }
        if (!is_resource($stream)) {
            throw new MailException('Connessione al server SMTP non riuscita');
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;
    }

    /** @return array<string, mixed> */
    private static function tlsOptions(string $host): array
    {
        return ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true];
    }

    private function enableTls(): void
    {
        if ($this->connector !== null) {
            return; // nei test il trasporto è già "sicuro"
        }
        stream_context_set_option($this->stream, ['ssl' => self::tlsOptions($this->host)]);
        $ok = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if ($ok !== true) {
            throw new MailException('STARTTLS non riuscito: certificato del server non valido o TLS non supportato');
        }
    }

    private function ehlo(): string
    {
        $name = gethostname() ?: 'emp.local';

        return $this->command('EHLO ' . (preg_match('/^[A-Za-z0-9.-]+$/', $name) ? $name : 'emp.local'), 250);
    }

    /** @param int|list<int> $codes */
    private function command(string $line, int|array $codes, bool $secret = false, bool $lenient = false): string
    {
        $this->write($line, $secret);
        try {
            return $this->expect($codes);
        } catch (MailException $e) {
            if ($lenient) {
                return '';
            }
            throw $e;
        }
    }

    private function write(string $data, bool $secret = false): void
    {
        $this->log[] = 'C: ' . ($secret ? '(dati di accesso nascosti)' : (strlen($data) > 200 ? substr($data, 0, 200) . '…' : $data));
        if (@fwrite($this->stream, $data . "\r\n") === false) {
            throw new MailException('Connessione SMTP interrotta');
        }
    }

    /** @param int|list<int> $codes */
    private function expect(int|array $codes): string
    {
        $codes = (array) $codes;
        $text = '';
        do {
            $line = fgets($this->stream, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);
                throw new MailException($meta['timed_out'] ? 'Il server SMTP non risponde (tempo scaduto)' : 'Connessione SMTP chiusa dal server');
            }
            $this->log[] = 'S: ' . rtrim($line);
            $text .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $codes, true)) {
            $msg = trim(substr($line, 4));
            throw new MailException(match (true) {
                $code === 535 => 'Utente o password SMTP non accettati dal server (' . $msg . ')',
                $code === 530 => 'Il server richiede l\'autenticazione o una connessione cifrata (' . $msg . ')',
                $code >= 550 && $code < 560 => 'Indirizzo rifiutato dal server (' . $msg . ')',
                default => sprintf('Risposta SMTP inattesa %d: %s', $code, $msg),
            });
        }

        return $text;
    }
}
