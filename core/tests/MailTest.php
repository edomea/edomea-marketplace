<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Mail\MailException;
use Emp\Core\Mail\MailService;
use Emp\Core\Mail\SmtpClient;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/** Email SMTP: dialogo con un server finto (coppia di socket), configurazione e prova. */
final class MailTest extends TestCase
{
    /** @var list<resource> lato "server" delle connessioni finte */
    private array $servers = [];

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** Connettore con le risposte del server già pronte (il client le legge una alla volta). */
    private function fakeServer(string ...$replies): \Closure
    {
        return function () use ($replies) {
            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($server, implode('', array_map(static fn (string $r): string => $r . "\r\n", $replies)));
            $this->servers[] = $server;

            return $client;
        };
    }

    private function received(): string
    {
        $server = end($this->servers);
        stream_set_blocking($server, false);

        return (string) stream_get_contents($server);
    }

    private function service(\Closure $connector): MailService
    {
        $modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $modules . '/emp-core');
        $pdo = new \PDO('sqlite::memory:');
        $installer = new Installer($pdo, new Clock(), 'emp_');
        $installer->install((new Resolver())->resolve((new Registry($modules))->discover()), 'installer');

        return new MailService($pdo, 'emp_core_', new Clock(), $connector);
    }

    public function testSmtpDialogue(): void
    {
        $ok = ['220 smtp.test ESMTP', '250-smtp.test', '250-STARTTLS', '250 AUTH PLAIN LOGIN', '220 go', '250-smtp.test', '250 AUTH PLAIN LOGIN',
            '235 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'];
        $client = new SmtpClient('smtp.test', 587, 'starttls', 5, $this->fakeServer(...$ok));
        $client->send('negozio@test.it', 'segreta', 'negozio@test.it', 'Edomea Città', ['cliente@test.it'], 'Prova è', "Riga 1\n.riga col punto");
        $sent = $this->received();
        self::assertStringContainsString("STARTTLS\r\n", $sent);
        self::assertStringContainsString('AUTH PLAIN ' . base64_encode("\0negozio@test.it\0segreta"), $sent);
        self::assertStringContainsString("RCPT TO:<cliente@test.it>\r\n", $sent);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Prova è') . '?=', $sent);
        self::assertStringContainsString(base64_encode("Riga 1\n.riga col punto"), str_replace("\r\n", '', $sent));
        // La password non finisce mai nel dialogo mostrato al Tecnico.
        self::assertStringNotContainsString(base64_encode("\0negozio@test.it\0segreta"), implode("\n", $client->log()));

        // Password sbagliata: messaggio chiaro.
        $bad = new SmtpClient('smtp.test', 465, 'ssl', 5, $this->fakeServer('220 hi', '250 AUTH LOGIN', '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '535 5.7.8 Authentication failed'));
        try {
            $bad->send('u@test.it', 'x', 'u@test.it', '', ['a@test.it'], 'S', 'B');
            self::fail('Attesa MailException');
        } catch (MailException $e) {
            self::assertStringContainsString('Utente o password SMTP non accettati', $e->getMessage());
        }
    }

    public function testConfigurationAndTest(): void
    {
        $replies = ['220 hi', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'];
        $mail = $this->service($this->fakeServer(...$replies));
        self::assertFalse($mail->config()['attiva']);
        try {
            $mail->send(['a@test.it'], 'S', 'B');
            self::fail('Attesa MailException');
        } catch (MailException $e) {
            self::assertStringContainsString('non attivo', $e->getMessage());
        }
        foreach ([['host' => 'smtp test'], ['porta' => 0], ['sicurezza' => 'boh'], ['mittente' => 'non-email'], ['nome_mittente' => '<x>']] as $bad) {
            try {
                $mail->save('tecnico', $bad + ['host' => 'smtp.test', 'porta' => 25, 'sicurezza' => 'nessuna', 'mittente' => 'n@test.it']);
                self::fail('Attesa MailException per ' . json_encode($bad));
            } catch (MailException) {
            }
        }
        $mail->save('tecnico', ['attiva' => true, 'host' => 'SMTP.Test', 'porta' => 25, 'sicurezza' => 'nessuna', 'utente' => '', 'mittente' => 'Negozio@Test.it', 'nome_mittente' => 'Negozio']);
        $c = $mail->config();
        self::assertSame(['smtp.test', 25, 'negozio@test.it', false], [$c['host'], $c['porta'], $c['mittente'], $c['password_impostata']]);
        $mail->test('tecnico', 'prova@test.it');
        self::assertSame('ok', $mail->config()['ultimo_test']['esito']);
        $mail->remove('tecnico');
        self::assertSame('', $mail->config()['host']);
    }

    public function testPasswordKeptWhenEmpty(): void
    {
        $mail = $this->service($this->fakeServer('220 hi'));
        $base = ['attiva' => true, 'host' => 'smtp.test', 'porta' => 587, 'sicurezza' => 'starttls', 'utente' => 'u@test.it', 'mittente' => 'u@test.it'];
        $mail->save('tecnico', $base + ['password' => 'prima']);
        self::assertTrue($mail->config()['password_impostata']);
        $mail->save('tecnico', $base + ['password' => '']);
        self::assertTrue($mail->config()['password_impostata']);
        self::assertArrayNotHasKey('password', $mail->config());
    }
}
