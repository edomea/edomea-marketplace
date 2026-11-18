<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Backup\BackupBridge;
use Emp\Core\Backup\BackupException;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * Pagina Backup: richieste al servizio di EMP Server (emp-backup-web), solo per il Tecnico,
 * solo in HTTPS, con la conferma della password per chiave, download e ripristino.
 */
final class BackupTest extends TestCase
{
    private const PWD = 'Chiave-Sicura-2026!';
    private string $modules;
    private string $web;
    private Installer $installer;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $this->modules . '/emp-core');
        $this->web = Fixtures::tempDir();
        foreach (['preparazione', 'richieste', 'risposte'] as $d) {
            mkdir($this->web . '/' . $d, 0700);
        }
        $this->pdo = new \PDO('sqlite::memory:');
        $this->installer = new Installer($this->pdo, new Clock(), 'emp_');
        $this->installer->install((new Resolver())->resolve((new Registry($this->modules))->discover()), 'installer');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** @return list<string> */
    private function queued(): array
    {
        return array_values(array_filter(scandir($this->web . '/richieste') ?: [], static fn (string $f): bool => str_ends_with($f, '.req')));
    }

    /** @return array<string, string> */
    private function request(string $file): array
    {
        $out = [];
        foreach (explode("\n", trim((string) file_get_contents($this->web . '/richieste/' . $file))) as $line) {
            [$k, $v] = explode('=', $line, 2);
            $out[$k] = $v;
        }

        return $out;
    }

    private function respond(string $id, string $user, array $data): void
    {
        file_put_contents($this->web . "/risposte/{$id}.json", json_encode(['id' => $id, 'utente' => $user, 'ok' => true, 'messaggio' => 'Fatto.', 'dati' => $data]));
    }

    public function testBridgeWritesOnlyExpectedRequests(): void
    {
        $bridge = new BackupBridge($this->web);
        self::assertTrue($bridge->isAvailable());
        $id = $bridge->enqueue('scarica', 'tecnico', ['nome' => 'emp-backup-srv-20261118-020000.tar', 'origine' => 'locale']);
        self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $id));
        self::assertSame([$id . '.req'], $this->queued());
        $req = $this->request($id . '.req');
        self::assertSame(['scarica', 'tecnico', 'emp-backup-srv-20261118-020000.tar', 'locale'], [$req['azione'], $req['utente'], $req['nome'], $req['origine']]);
        self::assertSame([], array_values(array_diff(scandir($this->web . '/preparazione') ?: [], ['.', '..'])));

        foreach ([
            ['spegni', []],                                         // azione non prevista
            ['backup', ['nome' => 'x']],                            // campo non previsto
            ['scarica', ['nome' => "a\nazione=ripristina"]],        // a capo: niente righe in più
        ] as [$action, $params]) {
            try {
                $bridge->enqueue($action, 'tecnico', $params);
                self::assertTrue(false, 'richiesta accettata: ' . $action);
            } catch (BackupException) {
            }
        }
        self::assertSame(1, count($this->queued()));
    }

    public function testResponseDeliveredOnceAndOnlyToItsUser(): void
    {
        $bridge = new BackupBridge($this->web);
        $id = str_repeat('ab', 16);
        self::assertNull($bridge->takeResponse($id, 'tecnico'));
        $this->respond($id, 'tecnico', ['chiave' => 'EMP-BK-AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE']);
        self::assertNull($bridge->takeResponse($id, 'altro'));
        self::assertNull($bridge->takeResponse('../' . $id, 'tecnico'));
        self::assertSame('EMP-BK-AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE', $bridge->takeResponse($id, 'tecnico')['dati']['chiave']);
        self::assertNull($bridge->takeResponse($id, 'tecnico'));
    }

    public function testDownloadOnlyForItsUserUntilExpiry(): void
    {
        $bridge = new BackupBridge($this->web);
        $ok = str_repeat('1', 32);
        $old = str_repeat('2', 32);
        file_put_contents($this->web . '/scaricamenti.json', json_encode([
            ['token' => $ok, 'nome' => 'emp-backup-srv-20261118-020000.tar', 'byte' => 10, 'utente' => 'tecnico', 'scade' => time() + 600],
            ['token' => $old, 'nome' => 'emp-backup-srv-20261117-020000.tar', 'byte' => 10, 'utente' => 'tecnico', 'scade' => time() - 1],
        ]));
        self::assertSame('emp-backup-srv-20261118-020000.tar', $bridge->download($ok, 'tecnico')['nome']);
        self::assertNull($bridge->download($ok, 'altro'));
        self::assertNull($bridge->download($old, 'tecnico'));
        self::assertNull($bridge->download('x', 'tecnico'));
    }

    public function testHttpRoutes(): void
    {
        $kernel = new Kernel($this->modules, $this->installer, new Clock(), serverVersion: '0.6.0-alpha.8', backupWebDir: $this->web);
        $kernel->access()->setupTecnico('tecnico', 'Mario Tecnico', self::PWD);
        $h = ['host' => 'localhost', 'x-emp-richiesta' => '1'];
        $login = $kernel->handle(new Request('POST', '/api/v1/accesso/login', $h, '127.0.0.1', (string) json_encode(['username' => 'tecnico', 'password' => self::PWD])));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $login->headers['Set-Cookie'], $m);
        $auth = $h + ['cookie' => 'emp_sessione=' . $m[1]];
        $post = static fn (string $path, array $body = [], array $headers = []): Response => $kernel->handle(new Request('POST', '/api/v1/backup/' . $path, ($headers ?: $auth), '127.0.0.1', (string) json_encode($body)));
        $get = static fn (string $path, array $headers = []): Response => $kernel->handle(new Request('GET', '/api/v1/' . $path, ($headers ?: $auth), '127.0.0.1'));

        // Senza sessione: 401. Dalla rete del negozio senza HTTPS: 403.
        self::assertSame(401, $get('backup', $h)->status);
        $lan = ['host' => '192.168.1.10', 'x-emp-richiesta' => '1', 'cookie' => $auth['cookie']];
        self::assertSame(403, $kernel->handle(new Request('GET', '/api/v1/backup', $lan, '192.168.1.20'))->status);

        // Stato scritto dal servizio.
        file_put_contents($this->web . '/stato.json', json_encode(['impostazioni' => ['ora' => '02:30'], 'locali' => []]));
        $state = json_decode($get('backup')->body, true);
        self::assertTrue($state['disponibile']);
        self::assertSame('02:30', $state['stato']['impostazioni']['ora']);

        // Backup adesso: una richiesta in coda, registrata nella tracciabilità.
        $run = $post('esegui');
        self::assertSame(202, $run->status, $run->body);
        $id = json_decode($run->body, true)['richiesta'];
        self::assertSame([$id . '.req'], $this->queued());
        self::assertSame(['pronta' => false], json_decode($get('backup/richiesta/' . $id)->body, true));
        $this->respond($id, 'tecnico', []);
        self::assertSame(['pronta' => true, 'ok' => true, 'messaggio' => 'Fatto.', 'dati' => []], json_decode($get('backup/richiesta/' . $id)->body, true));

        // Impostazioni controllate già nel Core.
        self::assertSame(409, $post('impostazioni', ['ora' => '25:00', 'notturno' => true, 'conserva_locali' => 14, 'conserva_remoti' => 30])->status);
        self::assertSame(409, $post('impostazioni', ['ora' => '02:30', 'notturno' => true, 'conserva_locali' => 0, 'conserva_remoti' => 30])->status);
        $set = $post('impostazioni', ['ora' => '03:15', 'notturno' => false, 'conserva_locali' => 20, 'conserva_remoti' => 60]);
        self::assertSame(202, $set->status, $set->body);
        $req = $this->request(json_decode($set->body, true)['richiesta'] . '.req');
        self::assertSame(['03:15', 'no', '20', '60'], [$req['ora'], $req['notturno'], $req['conserva_locali'], $req['conserva_remoti']]);

        // Chiave, download, ripristino e destinazione: solo con la password.
        self::assertSame(403, $post('chiave', ['password' => 'sbagliata'])->status);
        self::assertSame(202, $post('chiave', ['password' => self::PWD])->status);
        self::assertSame(409, $post('scarica', ['nome' => '../../etc/passwd', 'origine' => 'locale', 'password' => self::PWD])->status);
        self::assertSame(403, $post('scarica', ['nome' => 'emp-backup-srv-20261118-020000.tar', 'origine' => 'locale', 'password' => 'x'])->status);
        self::assertSame(202, $post('scarica', ['nome' => 'emp-backup-srv-20261118-020000.tar', 'origine' => 'remoto', 'password' => self::PWD])->status);
        self::assertSame(409, $post('ripristina', ['nome' => 'emp-backup-srv-20261118-020000.tar', 'origine' => 'locale', 'password' => self::PWD])->status);
        $restore = $post('ripristina', ['nome' => 'emp-backup-srv-20261118-020000.tar', 'origine' => 'locale', 'password' => self::PWD, 'conferma' => 'RIPRISTINA']);
        self::assertSame(202, $restore->status, $restore->body);
        self::assertSame(409, $post('destinazione', ['tipo' => 'cartella', 'percorso' => '/etc', 'password' => self::PWD])->status);
        self::assertSame(409, $post('destinazione', ['tipo' => 'gdrive', 'chiave' => 'sbagliata', 'password' => self::PWD])->status);
        self::assertSame(403, $post('destinazione', ['tipo' => 'gdrive', 'password' => 'x'])->status);
        $sftp = $post('destinazione', ['tipo' => 'sftp', 'destinazione' => 'backup@nas.local:2222', 'password_sftp' => 'segreta', 'password' => self::PWD]);
        self::assertSame(202, $sftp->status, $sftp->body);
        $req = $this->request(json_decode($sftp->body, true)['richiesta'] . '.req');
        self::assertSame(['sftp', 'backup@nas.local:2222', 'segreta'], [$req['azione'], $req['destinazione'], base64_decode($req['password_b64'])]);
        self::assertSame(409, $post('destinazione/google', ['indirizzo' => 'https://evil.example/?code=x'])->status);
        self::assertSame(202, $post('destinazione/google', ['indirizzo' => 'http://127.0.0.1:53682/?state=abc&code=4/0A'])->status);

        // Download: Nginx consegna il file preparato, il Core lo autorizza.
        $token = str_repeat('c', 32);
        file_put_contents($this->web . '/scaricamenti.json', json_encode([
            ['token' => $token, 'nome' => 'emp-backup-srv-20261118-020000.tar', 'byte' => 10, 'utente' => 'tecnico', 'scade' => time() + 600],
        ]));
        $file = $get('backup/file/' . $token);
        self::assertSame(200, $file->status);
        self::assertSame('/_emp_scaricamenti/' . $token . '.tar', $file->headers['X-Accel-Redirect']);
        self::assertStringContainsString('emp-backup-srv-20261118-020000.tar', $file->headers['Content-Disposition']);
        self::assertSame(404, $get('backup/file/' . str_repeat('d', 32))->status);

        // Tracciabilità senza password né chiavi.
        $audit = $this->pdo->query("SELECT action, COALESCE(after_value, '') FROM emp_core_audit WHERE action LIKE 'backup.%' OR action LIKE 'conferma%'")->fetchAll(\PDO::FETCH_NUM);
        $actions = array_column($audit, 0);
        foreach (['backup.backup', 'backup.impostazioni', 'backup.chiave', 'backup.scarica', 'backup.ripristina', 'backup.sftp', 'backup.scaricato', 'conferma_password_rifiutata'] as $a) {
            self::assertContains($a, $actions);
        }
        self::assertFalse(str_contains(json_encode($audit) ?: '', 'segreta'));
        self::assertFalse(str_contains(json_encode($audit) ?: '', self::PWD));
    }

    public function testUnavailableOutsideEmpServer(): void
    {
        $kernel = new Kernel($this->modules, $this->installer, new Clock());
        $kernel->access()->setupTecnico('tecnico', 'Mario Tecnico', self::PWD);
        $h = ['host' => 'localhost', 'x-emp-richiesta' => '1'];
        $login = $kernel->handle(new Request('POST', '/api/v1/accesso/login', $h, '127.0.0.1', (string) json_encode(['username' => 'tecnico', 'password' => self::PWD])));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $login->headers['Set-Cookie'], $m);
        $auth = $h + ['cookie' => 'emp_sessione=' . $m[1]];
        $state = json_decode($kernel->handle(new Request('GET', '/api/v1/backup', $auth, '127.0.0.1'))->body, true);
        self::assertFalse($state['disponibile']);
        self::assertSame(409, $kernel->handle(new Request('POST', '/api/v1/backup/esegui', $auth, '127.0.0.1', '{}'))->status);
    }
}
