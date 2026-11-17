<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Access\AccessException;
use Emp\Core\Access\AccessService;
use Emp\Core\Access\Role;
use Emp\Core\Access\User;
use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/** Orologio controllabile per provare scadenze e blocchi. */
final class FixedClock extends Clock
{
    public \DateTimeImmutable $at;

    public function __construct()
    {
        parent::__construct('Europe/Rome');
        $this->at = new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('Europe/Rome'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function advance(string $modifier): void
    {
        $this->at = $this->at->modify($modifier);
    }
}

/**
 * Servizio Accesso: solo password, funzionamento offline, ruoli separati,
 * revoca degli accessi e Recovery Key del Tecnico.
 */
final class AccessServiceTest extends TestCase
{
    private const PWD_TECNICO = 'Chiave-Sicura-2026!';
    private \PDO $pdo;
    private FixedClock $clock;
    private AccessService $access;
    private string $modules;
    private Installer $installer;

    protected function setUp(): void
    {
        $this->modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $this->modules . '/emp-core');
        $this->pdo = new \PDO('sqlite::memory:');
        $this->clock = new FixedClock();
        $this->installer = new Installer($this->pdo, $this->clock, 'emp_');
        $this->installer->install((new Resolver())->resolve((new Registry($this->modules))->discover()), 'installer');
        $this->access = new AccessService($this->pdo, 'emp_core_', $this->clock);
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function setupTecnico(): string
    {
        return $this->access->setupTecnico('Tecnico', 'Mario Tecnico', self::PWD_TECNICO);
    }

    private function loginAs(string $username, string $password): User
    {
        [$token, $user] = $this->access->login($username, $password, '127.0.0.1');
        self::assertSame(64, strlen($token));

        return $user;
    }

    private function expectAccessError(int $status, \Closure $fn): AccessException
    {
        try {
            $fn();
        } catch (AccessException $e) {
            self::assertSame($status, $e->status, $e->getMessage());

            return $e;
        }
        self::fail("Attesa AccessException {$status}");
    }

    public function testSetupOnlyOnceAndRecoveryKeyShownOnce(): void
    {
        self::assertFalse($this->access->isInitialized());
        $key = $this->setupTecnico();
        self::assertTrue($this->access->isInitialized());
        self::assertTrue((bool) preg_match('/^EMP(-[A-Z2-9]{5}){5}$/', $key), $key);
        // Nel database c'è solo l'impronta della chiave, mai la chiave.
        $stored = (string) $this->pdo->query("SELECT value FROM emp_core_settings WHERE name = 'tecnico_recovery_key'")->fetchColumn();
        self::assertTrue(!str_contains($stored, substr($key, 4, 5)) && str_starts_with($stored, '$'));
        $this->expectAccessError(409, fn () => $this->access->setupTecnico('altro', 'Altro', 'Altra-Password-2026'));
    }

    public function testSetupCodeFromAnotherWorkstation(): void
    {
        // Da un'altra postazione senza codice generato: rifiutato.
        $this->expectAccessError(401, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, '', true, '192.168.1.20'));

        $code = $this->access->createSetupCode('console');
        self::assertTrue((bool) preg_match('/^EMP(-[A-Z2-9]{5}){3}$/', $code), $code);
        self::assertFalse(str_contains((string) $this->pdo->query("SELECT value FROM emp_core_settings WHERE name = 'codice_setup'")->fetchColumn(), substr($code, 4, 5)));

        // Codice errato e codice assente: rifiutati, anche per richieste dal computer stesso.
        $this->expectAccessError(401, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, 'EMP-AAAAA-BBBBB-CCCCC', true, '192.168.1.20'));
        $this->expectAccessError(401, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO));
        self::assertFalse($this->access->isInitialized());

        // Codice corretto (scritto in minuscolo e senza trattini): setup riuscito e codice consumato.
        $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, strtolower(str_replace('-', ' ', $code)), true, '192.168.1.20');
        self::assertTrue($this->access->isInitialized());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM emp_core_settings WHERE name = 'codice_setup'")->fetchColumn());
        // Dopo il setup non si generano più codici.
        $this->expectAccessError(409, fn () => $this->access->createSetupCode('console'));
    }

    public function testSetupCodeExpiresAndIsReplaced(): void
    {
        $first = $this->access->createSetupCode('console');
        $second = $this->access->createSetupCode('console');
        // Il nuovo codice annulla il precedente.
        $this->expectAccessError(401, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, $first, true, '10.0.0.2'));
        $this->clock->advance('+' . (AccessService::SETUP_CODE_HOURS * 60 + 1) . ' minutes');
        $this->expectAccessError(401, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, $second, true, '10.0.0.2'));
        self::assertFalse($this->access->isInitialized());
    }

    public function testSetupCodeAttemptsAreLimited(): void
    {
        $code = $this->access->createSetupCode('console');
        for ($i = 0; $i < AccessService::MAX_FAILURES_PER_USER; $i++) {
            $this->expectAccessError(401, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, 'EMP-AAAAA-BBBBB-CCCCC', true, '10.0.0.' . $i));
        }
        $this->expectAccessError(429, fn () => $this->access->setupTecnico('tecnico', 'T', self::PWD_TECNICO, $code, true, '10.0.0.99'));
    }

    public function testConsoleResetTecnico(): void
    {
        $oldKey = $this->setupTecnico();
        [$token] = $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1');
        $newKey = $this->access->consoleResetTecnico('tecnico', 'Nuova-Password-Console-26', 'console');
        self::assertNotSame($oldKey, $newKey);
        self::assertNull($this->access->userForToken($token));
        $this->loginAs('tecnico', 'Nuova-Password-Console-26');
        // La vecchia Recovery Key non vale più, la nuova sì.
        $this->expectAccessError(401, fn () => $this->access->recoverTecnico('tecnico', $oldKey, 'Altra-Password-Sicura-1', '127.0.0.1'));
        $this->access->recoverTecnico('tecnico', $newKey, 'Altra-Password-Sicura-1', '127.0.0.1');
        $this->expectAccessError(404, fn () => $this->access->consoleResetTecnico('nessuno', 'Nuova-Password-Console-26', 'console'));
        $this->expectAccessError(422, fn () => $this->access->consoleResetTecnico('tecnico', 'corta', 'console'));
    }

    public function testPasswordRules(): void
    {
        $this->expectAccessError(422, fn () => $this->access->setupTecnico('tecnico', 'T', 'corta'));
        $this->expectAccessError(422, fn () => $this->access->setupTecnico('tecnico', 'T', 'tecnico-1234567890'));
        $this->expectAccessError(422, fn () => $this->access->setupTecnico('tecnico', 'T', 'aaaaaaaaaaaaaaaa'));
        $this->expectAccessError(422, fn () => $this->access->setupTecnico('x', 'T', self::PWD_TECNICO));
        self::assertFalse($this->access->isInitialized());
    }

    public function testLoginSessionLogoutAndHashedStorage(): void
    {
        $this->setupTecnico();
        [$token, $user] = $this->access->login('TECNICO', self::PWD_TECNICO, '127.0.0.1');
        self::assertSame(Role::TECNICO, $user->role);
        self::assertSame('tecnico', $this->access->userForToken($token)?->username);
        // Token e password non sono mai salvati in chiaro.
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM emp_core_sessions WHERE token_hash = '{$token}'")->fetchColumn());
        self::assertFalse(str_contains((string) $this->pdo->query('SELECT password_hash FROM emp_core_users')->fetchColumn(), self::PWD_TECNICO));

        $this->access->logout($token);
        self::assertNull($this->access->userForToken($token));
        self::assertNull($this->access->userForToken('non-valido'));
    }

    public function testWrongPasswordAndTemporaryLock(): void
    {
        $this->setupTecnico();
        $e = $this->expectAccessError(401, fn () => $this->access->login('tecnico', 'sbagliata-123456', '127.0.0.1'));
        self::assertSame('Nome utente o password non corretti', $e->getMessage());
        // Utente inesistente: stesso messaggio.
        $e = $this->expectAccessError(401, fn () => $this->access->login('nessuno', 'sbagliata-123456', '127.0.0.1'));
        self::assertSame('Nome utente o password non corretti', $e->getMessage());

        for ($i = 0; $i < 4; $i++) {
            $this->expectAccessError(401, fn () => $this->access->login('tecnico', 'sbagliata-123456', '127.0.0.1'));
        }
        // Bloccato anche con la password giusta.
        $this->expectAccessError(429, fn () => $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1'));
        $this->clock->advance('+16 minutes');
        $this->loginAs('tecnico', self::PWD_TECNICO);
    }

    public function testSessionExpiresWhenIdleOrTooOld(): void
    {
        $this->setupTecnico();
        [$token] = $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1');
        $this->clock->advance('+239 minutes');
        self::assertNotNull($this->access->userForToken($token));
        $this->clock->advance('+241 minutes');
        self::assertNull($this->access->userForToken($token), 'inattività');

        [$token] = $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1');
        for ($i = 0; $i < 4; $i++) {
            $this->clock->advance('+3 hours');
            $alive = $this->access->userForToken($token);
        }
        self::assertNull($alive, 'durata massima 12 ore');
    }

    public function testRoleSeparation(): void
    {
        $this->setupTecnico();
        $tecnico = $this->loginAs('tecnico', self::PWD_TECNICO);

        // Il Tecnico crea amministratori, non operatori.
        $admin = $this->access->createUser($tecnico, 'anna.admin', 'Anna Admin', Role::AMMINISTRATORE, 'Temporanea-Admin-1');
        self::assertTrue($admin->mustChangePassword);
        $this->expectAccessError(403, fn () => $this->access->createUser($tecnico, 'op', 'Op', Role::OPERATORE, 'Temporanea-Op-123'));
        $this->expectAccessError(403, fn () => $this->access->createUser($tecnico, 'tec2', 'T2', Role::TECNICO, 'Temporanea-Tec-123'));
        $this->expectAccessError(409, fn () => $this->access->createUser($tecnico, 'anna.admin', 'Doppia', Role::AMMINISTRATORE, 'Temporanea-Admin-2'));

        // L'amministratore deve prima cambiare la password temporanea.
        $adminUser = $this->loginAs('anna.admin', 'Temporanea-Admin-1');
        self::assertTrue($adminUser->mustChangePassword);
        $this->access->changeOwnPassword($adminUser, 'Temporanea-Admin-1', 'Definitiva-Admin-1', null);
        $adminUser = $this->loginAs('anna.admin', 'Definitiva-Admin-1');
        self::assertFalse($adminUser->mustChangePassword);

        // L'amministratore crea operatori, non amministratori, e non tocca il Tecnico.
        $op = $this->access->createUser($adminUser, 'luca.cassa', 'Luca Cassa', Role::OPERATORE, 'Temporanea-Cassa-1');
        $this->expectAccessError(403, fn () => $this->access->createUser($adminUser, 'b', 'B', Role::AMMINISTRATORE, 'Temporanea-Admin-9'));
        $this->expectAccessError(403, fn () => $this->access->setTemporaryPassword($adminUser, $tecnico->id, 'Presa-Di-Possesso-1'));
        $this->expectAccessError(403, fn () => $this->access->revokeAccess($adminUser, $tecnico->id));
        // Il Tecnico non gestisce direttamente gli operatori.
        $this->expectAccessError(403, fn () => $this->access->revokeAccess($tecnico, $op->id));
        // Le operazioni amministrative non si fanno su se stessi.
        $this->expectAccessError(422, fn () => $this->access->setActive($adminUser, $adminUser->id, false));

        self::assertSame(['anna.admin', 'tecnico'], array_map(static fn (User $u) => $u->username, $this->access->listUsers($tecnico)));
        self::assertSame(['anna.admin', 'luca.cassa'], array_map(static fn (User $u) => $u->username, $this->access->listUsers($adminUser)));
    }

    public function testAdminResetsPasswordRevokesAndDeactivates(): void
    {
        $this->setupTecnico();
        $tecnico = $this->loginAs('tecnico', self::PWD_TECNICO);
        $this->access->createUser($tecnico, 'anna', 'Anna', Role::AMMINISTRATORE, 'Temporanea-Admin-1');
        $anna = $this->loginAs('anna', 'Temporanea-Admin-1');
        $this->access->changeOwnPassword($anna, 'Temporanea-Admin-1', 'Definitiva-Admin-1', null);
        $anna = $this->loginAs('anna', 'Definitiva-Admin-1');
        $luca = $this->access->createUser($anna, 'luca', 'Luca', Role::OPERATORE, 'Temporanea-Cassa-1');

        [$t1] = $this->access->login('luca', 'Temporanea-Cassa-1', '10.0.0.5');
        [$t2] = $this->access->login('luca', 'Temporanea-Cassa-1', '10.0.0.6');
        self::assertSame(2, $this->access->revokeAccess($anna, $luca->id));
        self::assertNull($this->access->userForToken($t1));
        self::assertNull($this->access->userForToken($t2));

        // Password temporanea impostata dall'amministratore: obbligo di cambio, vecchia non valida.
        $this->access->setTemporaryPassword($anna, $luca->id, 'Nuova-Temporanea-2');
        $this->expectAccessError(401, fn () => $this->access->login('luca', 'Temporanea-Cassa-1', '10.0.0.5'));
        self::assertTrue($this->loginAs('luca', 'Nuova-Temporanea-2')->mustChangePassword);

        // Disattivazione: sessioni chiuse e login impossibile; riattivazione.
        [$t3] = $this->access->login('luca', 'Nuova-Temporanea-2', '10.0.0.5');
        $this->access->setActive($anna, $luca->id, false);
        self::assertNull($this->access->userForToken($t3));
        $this->expectAccessError(401, fn () => $this->access->login('luca', 'Nuova-Temporanea-2', '10.0.0.5'));
        $this->access->setActive($anna, $luca->id, true);
        $this->loginAs('luca', 'Nuova-Temporanea-2');
    }

    public function testChangeOwnPasswordKeepsCurrentSessionOnly(): void
    {
        $this->setupTecnico();
        [$a, $user] = $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1');
        [$b] = $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1');
        $this->expectAccessError(403, fn () => $this->access->changeOwnPassword($user, 'sbagliata-12345', 'Nuova-Password-2026', $a));
        $this->expectAccessError(422, fn () => $this->access->changeOwnPassword($user, self::PWD_TECNICO, self::PWD_TECNICO, $a));
        $this->access->changeOwnPassword($user, self::PWD_TECNICO, 'Nuova-Password-2026', $a);
        self::assertNotNull($this->access->userForToken($a));
        self::assertNull($this->access->userForToken($b));
        $this->loginAs('tecnico', 'Nuova-Password-2026');
    }

    public function testRecoveryKeyResetsTecnicoPasswordAndIsReplaced(): void
    {
        $key = $this->setupTecnico();
        [$token] = $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1');

        $this->expectAccessError(401, fn () => $this->access->recoverTecnico('tecnico', 'EMP-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA', 'Recuperata-2026-x', '127.0.0.1'));
        // La chiave si può digitare in minuscolo e senza trattini.
        $newKey = $this->access->recoverTecnico('tecnico', strtolower(str_replace('-', ' ', $key)), 'Recuperata-2026-x', '127.0.0.1');
        self::assertNotSame($key, $newKey);
        self::assertNull($this->access->userForToken($token), 'sessioni chiuse');
        $this->expectAccessError(401, fn () => $this->access->login('tecnico', self::PWD_TECNICO, '127.0.0.1'));
        $this->loginAs('tecnico', 'Recuperata-2026-x');
        // La chiave usata non vale più; la nuova sì.
        $this->expectAccessError(401, fn () => $this->access->recoverTecnico('tecnico', $key, 'Ancora-Nuova-2026', '127.0.0.1'));
        $this->access->recoverTecnico('tecnico', $newKey, 'Ancora-Nuova-2026', '127.0.0.1');
    }

    public function testRecoveryOnlyForTecnicoAndRateLimited(): void
    {
        $key = $this->setupTecnico();
        $tecnico = $this->loginAs('tecnico', self::PWD_TECNICO);
        $this->access->createUser($tecnico, 'anna', 'Anna', Role::AMMINISTRATORE, 'Temporanea-Admin-1');
        $this->expectAccessError(401, fn () => $this->access->recoverTecnico('anna', $key, 'Presa-Di-Possesso-1', '127.0.0.1'));

        for ($i = 0; $i < 3; $i++) {
            $this->expectAccessError(401, fn () => $this->access->recoverTecnico('tecnico', 'EMP-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA', 'Recuperata-2026-x', '127.0.0.1'));
        }
        $this->expectAccessError(429, fn () => $this->access->recoverTecnico('tecnico', $key, 'Recuperata-2026-x', '127.0.0.1'));
    }

    public function testAuditRecordsEventsWithoutSecrets(): void
    {
        $key = $this->setupTecnico();
        $tecnico = $this->loginAs('tecnico', self::PWD_TECNICO);
        $this->access->createUser($tecnico, 'anna', 'Anna', Role::AMMINISTRATORE, 'Temporanea-Admin-1');
        $this->expectAccessError(401, fn () => $this->access->login('anna', 'sbagliata-12345', '127.0.0.1'));
        $this->access->recoverTecnico('tecnico', $key, 'Recuperata-2026-x', '127.0.0.1');

        $actions = $this->pdo->query('SELECT action FROM emp_core_audit ORDER BY occurred_at, action')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['setup_tecnico', 'accesso', 'crea_utente', 'accesso_rifiutato', 'recupero_tecnico'] as $expected) {
            self::assertTrue(in_array($expected, $actions, true), $expected);
        }
        $dump = json_encode($this->pdo->query('SELECT * FROM emp_core_audit')->fetchAll(\PDO::FETCH_ASSOC));
        foreach ([self::PWD_TECNICO, 'Temporanea-Admin-1', 'sbagliata-12345', 'Recuperata-2026-x', substr($key, 4, 5)] as $secret) {
            self::assertFalse(str_contains((string) $dump, $secret), "segreto nel registro: {$secret}");
        }
    }

    public function testHttpFlow(): void
    {
        $kernel = new Kernel($this->modules, $this->installer, $this->clock);
        $local = ['host' => 'localhost', 'x-emp-richiesta' => '1'];
        $post = static fn (string $path, array $body, array $h = [], string $ip = '127.0.0.1'): Request => new Request('POST', $path, $h + $local, $ip, (string) json_encode($body));

        $state = json_decode($kernel->handle(new Request('GET', '/api/v1/accesso/stato'))->body, true);
        self::assertSame(['inizializzato' => false, 'utente' => null, 'protetto' => false, 'codice_setup' => true], $state);

        // Il primo setup da un'altra postazione: non in HTTP, non senza intestazione anti-CSRF, non senza codice.
        $body = ['username' => 'tecnico', 'nome' => 'Mario', 'password' => self::PWD_TECNICO];
        $lan = ['host' => '192.168.1.10', 'x-emp-https' => '1'];
        self::assertSame(403, $kernel->handle($post('/api/v1/accesso/setup', $body, ['host' => '192.168.1.10'], '192.168.1.30'))->status);
        self::assertSame(403, $kernel->handle(new Request('POST', '/api/v1/accesso/setup', $lan, '192.168.1.30', (string) json_encode($body)))->status);
        self::assertSame(401, $kernel->handle($post('/api/v1/accesso/setup', $body, $lan, '192.168.1.30'))->status);
        $httpsState = json_decode($kernel->handle(new Request('GET', '/api/v1/accesso/stato', $lan, '192.168.1.30'))->body, true);
        self::assertTrue($httpsState['protetto'] && $httpsState['codice_setup']);

        // Con il codice generato sulla console del Server: riuscito.
        $code = $kernel->access()->createSetupCode('console');
        $setup = $kernel->handle($post('/api/v1/accesso/setup', $body + ['codice' => $code], $lan, '192.168.1.30'));
        self::assertSame(200, $setup->status, $setup->body);
        self::assertTrue(str_starts_with(json_decode($setup->body, true)['recovery_key'], 'EMP-'));
        self::assertSame(409, $kernel->handle($post('/api/v1/accesso/setup', $body))->status);

        // Login da una postazione Client: consentito; cookie protetto.
        $login = $kernel->handle($post('/api/v1/accesso/login', ['username' => 'tecnico', 'password' => self::PWD_TECNICO], ['host' => 'emp.negozio.lan'], '192.168.1.30'));
        self::assertSame(200, $login->status, $login->body);
        $cookie = $login->headers['Set-Cookie'];
        self::assertTrue(str_contains($cookie, 'HttpOnly') && str_contains($cookie, 'SameSite=Strict'));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $cookie, $m);
        $auth = ['cookie' => 'emp_sessione=' . $m[1]];

        self::assertSame('tecnico', json_decode($kernel->handle(new Request('GET', '/api/v1/accesso/stato', $auth))->body, true)['utente']['username']);

        // Creazione di un amministratore e gestione.
        $created = $kernel->handle($post('/api/v1/utenti', ['username' => 'anna', 'nome' => 'Anna', 'ruolo' => 'amministratore', 'password' => 'Temporanea-Admin-1'], $auth));
        self::assertSame(201, $created->status, $created->body);
        $annaId = json_decode($created->body, true)['utente']['id'];
        self::assertSame(200, $kernel->handle($post("/api/v1/utenti/{$annaId}/revoca", [], $auth))->status);
        self::assertSame(200, $kernel->handle($post("/api/v1/utenti/{$annaId}/password", ['password' => 'Altra-Temporanea-1'], $auth))->status);
        self::assertSame(200, $kernel->handle($post("/api/v1/utenti/{$annaId}/disattiva", [], $auth))->status);
        self::assertSame(200, $kernel->handle($post("/api/v1/utenti/{$annaId}/attiva", [], $auth))->status);
        $list = json_decode($kernel->handle(new Request('GET', '/api/v1/utenti', $auth))->body, true);
        self::assertSame('amministratore', $list['gestibile']);

        // Senza sessione: 401. Con password temporanea da cambiare: solo il cambio password.
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/v1/utenti'))->status);
        $annaLogin = $kernel->handle($post('/api/v1/accesso/login', ['username' => 'anna', 'password' => 'Altra-Temporanea-1']));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $annaLogin->headers['Set-Cookie'], $am);
        $annaAuth = ['cookie' => 'emp_sessione=' . $am[1]];
        self::assertSame(403, $kernel->handle(new Request('GET', '/api/v1/utenti', $annaAuth))->status);
        self::assertSame(200, $kernel->handle($post('/api/v1/accesso/password', ['attuale' => 'Altra-Temporanea-1', 'nuova' => 'Definitiva-Admin-1'], $annaAuth))->status);
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/v1/utenti', $annaAuth))->status);
        // L'amministratore non può gestire i plugin (funzione tecnica).
        self::assertSame(403, $kernel->handle(new Request('GET', '/api/v1/plugin', $annaAuth + $local, '127.0.0.1'))->status);

        // Origine estranea rifiutata anche con la sessione valida.
        self::assertSame(403, $kernel->handle($post('/api/v1/utenti', [], $auth + ['origin' => 'https://evil.example']))->status);

        // Logout.
        $out = $kernel->handle($post('/api/v1/accesso/logout', [], $auth));
        self::assertTrue(str_contains($out->headers['Set-Cookie'], 'Max-Age=0'));
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/v1/utenti', $auth))->status);
    }
}
