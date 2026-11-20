<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Access\AccessException;
use Emp\Core\Access\Role;
use Emp\Core\Access\WorkstationService;
use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/** Postazioni autorizzate: associazione, obbligo per gli operatori, revoca con chiusura delle sessioni. */
final class WorkstationTest extends TestCase
{
    private const PWD = 'Chiave-Sicura-2026!';
    private Kernel $kernel;

    protected function setUp(): void
    {
        $modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $modules . '/emp-core');
        $pdo = new \PDO('sqlite::memory:');
        $installer = new Installer($pdo, new Clock(), 'emp_');
        $installer->install((new Resolver())->resolve((new Registry($modules))->discover()), 'installer');
        $this->kernel = new Kernel($modules, $installer, new Clock());
        $this->kernel->access()->setupTecnico('tecnico', 'Mario Tecnico', self::PWD);
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function error(int $status, \Closure $fn): void
    {
        try {
            $fn();
        } catch (AccessException $e) {
            self::assertSame($status, $e->status, $e->getMessage());

            return;
        }
        self::fail("Attesa AccessException {$status}");
    }

    public function testWorkstations(): void
    {
        $access = $this->kernel->access();
        $ws = $this->kernel->workstations();
        [, $tecnico] = $access->login('tecnico', self::PWD, '127.0.0.1', $ws, null);
        $access->createUser($tecnico, 'anna', 'Anna', Role::AMMINISTRATORE, 'Temporanea-Admin-1');
        $access->changeOwnPassword($access->login('anna', 'Temporanea-Admin-1', '127.0.0.1')[1], 'Temporanea-Admin-1', 'Definitiva-Admin-1', null);
        [, $anna] = $access->login('anna', 'Definitiva-Admin-1', '127.0.0.1');
        $access->createUser($anna, 'luca', 'Luca', Role::OPERATORE, 'Temporanea-Cassa-1');
        $access->changeOwnPassword($access->login('luca', 'Temporanea-Cassa-1', '127.0.0.1')[1], 'Temporanea-Cassa-1', 'Definitiva-Cassa-1', null);
        [, $luca] = $access->login('luca', 'Definitiva-Cassa-1', '127.0.0.1');

        // Obbligo: solo il Tecnico, e solo con almeno una postazione attiva.
        $this->error(409, fn () => $ws->setRequired($tecnico, true));
        $this->error(403, fn () => $ws->setRequired($anna, true));
        $this->error(403, fn () => $ws->createCode($luca, 'Cassa 1'));

        // Codice di associazione: sbagliato rifiutato, giusto una volta sola.
        $code = $ws->createCode($anna, 'Cassa 1');
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code['codice']);
        $this->error(401, fn () => $ws->pair('AAAA-BBBB', '192.168.1.50'));
        [$token, $name] = $ws->pair(strtolower($code['codice']), '192.168.1.50');
        self::assertSame('Cassa 1', $name);
        $this->error(401, fn () => $ws->pair($code['codice'], '192.168.1.51'));
        $list = $ws->list($token);
        self::assertSame(['Cassa 1', 'attiva', true, '192.168.1.50'], [$list[0]['nome'], $list[0]['stato'], $list[0]['questa'], $list[0]['ultimo_ip']]);
        self::assertArrayNotHasKey('token_hash', $list[0]);

        // Con l'obbligo: l'operatore entra solo dalla postazione; Tecnico e amministratori ovunque.
        $ws->setRequired($tecnico, true);
        $this->error(403, fn () => $access->login('luca', 'Definitiva-Cassa-1', '192.168.1.60', $ws, null));
        $this->error(403, fn () => $access->login('luca', 'Definitiva-Cassa-1', '192.168.1.60', $ws, str_repeat('a', 64)));
        [$session] = $access->login('luca', 'Definitiva-Cassa-1', '192.168.1.50', $ws, $token);
        $access->login('anna', 'Definitiva-Admin-1', '192.168.1.60', $ws, null);
        $access->login('tecnico', self::PWD, '192.168.1.60', $ws, null);

        // Revoca: token non più valido, sessione aperta da lì chiusa.
        self::assertSame(1, $ws->revoke($anna, $list[0]['id']));
        self::assertNull($access->userForToken($session));
        $this->error(403, fn () => $access->login('luca', 'Definitiva-Cassa-1', '192.168.1.50', $ws, $token));

        // Autorizzazione dal browser in uso, rinomina.
        $own = $ws->authorizeCurrent($anna, 'Ufficio', '192.168.1.70');
        $ownId = $ws->identify($own, null)['id'];
        $ws->rename($tecnico, $ownId, 'Ufficio amministrazione');
        self::assertSame('Ufficio amministrazione', $ws->identify($own, null)['nome']);
        $this->error(422, fn () => $ws->rename($tecnico, $ownId, '<script>'));
    }

    public function testHttpPairingSetsCookie(): void
    {
        $k = $this->kernel;
        $h = ['host' => 'localhost', 'x-emp-richiesta' => '1'];
        $login = $k->handle(new Request('POST', '/api/v1/accesso/login', $h, '127.0.0.1', (string) json_encode(['username' => 'tecnico', 'password' => self::PWD])));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $login->headers['Set-Cookie'], $m);
        $auth = $h + ['cookie' => 'emp_sessione=' . $m[1]];
        $code = json_decode($k->handle(new Request('POST', '/api/v1/postazioni/codice', $auth, '127.0.0.1', '{"nome":"Cassa 2"}'))->body, true)['codice'];
        $paired = $k->handle(new Request('POST', '/api/v1/postazioni/associa', $h, '127.0.0.1', (string) json_encode(['codice' => $code])));
        self::assertSame(200, $paired->status, $paired->body);
        self::assertMatchesRegularExpression('/^' . WorkstationService::COOKIE . '=[0-9a-f]{64}; Path=\/; Max-Age=\d+; HttpOnly; SameSite=Strict/', $paired->headers['Set-Cookie']);
        preg_match('/=([0-9a-f]{64});/', $paired->headers['Set-Cookie'], $t);
        $here = $k->handle(new Request('GET', '/api/v1/postazioni/questa', ['host' => 'localhost', 'cookie' => WorkstationService::COOKIE . '=' . $t[1]], '127.0.0.1'));
        self::assertSame(['postazione' => ['nome' => 'Cassa 2'], 'obbligatorie' => false], json_decode($here->body, true));
        // Dalla rete del negozio senza HTTPS l'associazione non si fa (il cookie non sarebbe protetto).
        $lan = ['host' => '192.168.1.10', 'x-emp-richiesta' => '1'];
        self::assertSame(403, $k->handle(new Request('POST', '/api/v1/postazioni/associa', $lan, '192.168.1.20', '{"codice":"AAAA-BBBB"}'))->status);
    }

    /** Ruoli di negozio: iniziali dal manuale, permessi dei moduli, assegnazione agli operatori. */
    public function testJobRoles(): void
    {
        $access = $this->kernel->access();
        $this->kernel->boot();
        $roles = $this->kernel->jobRoles();
        [, $tecnico] = $access->login('tecnico', self::PWD, '127.0.0.1');
        $codes = array_column($roles->list(), 'codice');
        foreach (['cassiere', 'magazziniere', 'store-manager', 'area-manager', 'contabilita'] as $c) {
            self::assertContains($c, $codes);
        }

        // Il Tecnico crea direttamente un operatore e gli assegna ruoli.
        $op = $access->createUser($tecnico, 'gina', 'Gina Cassa', Role::OPERATORE, 'Temporanea-Cassa-1');
        $roles->assign($tecnico, $access->manageable($tecnico, $op->id), ['cassiere', 'magazziniere']);
        self::assertSame(['cassiere', 'magazziniere'], $roles->assignmentsByUser()[$op->id]);
        $this->error(422, fn () => $roles->assign($tecnico, $op, ['inesistente']));

        // Un ruolo nuovo con permessi dei moduli avviati (solo il Core qui: nessun permesso).
        $this->error(422, fn () => $roles->save($tecnico, null, 'Vetrinista', 'Allestimenti', ['magazzino.carico']));
        $code = $roles->save($tecnico, null, 'Vetrinista', 'Allestimenti', []);
        self::assertSame('vetrinista', $code);
        self::assertSame('vetrinista-2', $roles->save($tecnico, null, 'Vetrinista', '', []));
        $roles->save($tecnico, $code, 'Vetrinista senior', 'Allestimenti e vetrine', []);
        self::assertContains('Vetrinista senior', array_column($roles->list(), 'nome'));

        // Un ruolo assegnato non si elimina; libero sì.
        $this->error(409, fn () => $roles->delete($tecnico, 'cassiere'));
        $roles->delete($tecnico, 'vetrinista-2');
        self::assertNotContains('vetrinista-2', array_column($roles->list(), 'codice'));

        // Permessi: operatore = unione dei ruoli; il Tecnico non ha permessi gestionali; gli operatori non gestiscono ruoli.
        self::assertSame([], $roles->permissionsFor($tecnico));
        self::assertFalse($roles->allows($op, 'esempio.leggi'));
        $this->error(403, fn () => $roles->save($op, null, 'X', '', []));
        $this->error(422, fn () => $roles->assign($tecnico, $tecnico, ['cassiere']));
    }
}
