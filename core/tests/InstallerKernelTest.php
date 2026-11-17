<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

final class InstallerKernelTest extends TestCase
{
    private string $modules;
    private \PDO $pdo;
    private Installer $installer;

    protected function setUp(): void
    {
        $this->modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $this->modules . '/emp-core');
        Fixtures::copy(dirname(__DIR__, 2) . '/plugins/emp-esempio', $this->modules . '/emp-esempio');
        $this->pdo = new \PDO('sqlite::memory:');
        $this->installer = new Installer($this->pdo, new Clock('Europe/Rome'), 'emp_');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function plan(?array $selected = null): array
    {
        return (new Resolver())->resolve((new Registry($this->modules))->discover(), $selected);
    }

    public function testInstallCoreThenPluginAndBoot(): void
    {
        $log = $this->installer->install($this->plan(), 'tecnico');
        self::assertSame(['Installato emp-core 0.1.3 (2 migrazioni)', 'Installato emp-esempio 0.1.0 (1 migrazioni)'], $log);
        self::assertSame(['emp-core' => '0.1.3', 'emp-esempio' => '0.1.0'], $this->installer->installed());

        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([
            'emp_core_audit', 'emp_core_login_attempts', 'emp_core_sessions', 'emp_core_settings', 'emp_core_users',
            'emp_esempio_note', 'emp_migrations', 'emp_modules',
        ], $tables);
        $who = $this->pdo->query('SELECT DISTINCT applied_by FROM emp_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['tecnico'], $who);

        $kernel = new Kernel($this->modules, $this->installer, new Clock());
        $health = $kernel->handle(new Request('GET', '/api/v1/salute'));
        self::assertSame(200, $health->status);
        self::assertSame('ok', json_decode($health->body, true)['stato']);
        $plugin = $kernel->handle(new Request('GET', '/api/v1/esempio'));
        self::assertSame(['modulo' => 'emp-esempio', 'versione' => '0.1.0'], json_decode($plugin->body, true));
        self::assertSame(404, $kernel->handle(new Request('GET', '/api/v1/inesistente'))->status);
        self::assertSame(405, $kernel->handle(new Request('POST', '/api/v1/salute', ['x-emp-richiesta' => '1']))->status);
        // Senza intestazione anti-CSRF ogni richiesta che modifica è rifiutata.
        self::assertSame(403, $kernel->handle(new Request('POST', '/api/v1/salute'))->status);
        self::assertSame(['emp-core', 'emp-esempio'], array_map(static fn ($m) => $m->id, $kernel->modules()));
    }

    public function testInstallIsIdempotent(): void
    {
        $this->installer->install($this->plan(), 'tecnico');
        $log = $this->installer->install($this->plan(), 'tecnico');
        self::assertSame(['emp-core 0.1.3 già installato', 'emp-esempio 0.1.0 già installato'], $log);
    }

    public function testPluginRefusedWithoutCore(): void
    {
        $plan = $this->plan();
        $this->expectExceptionMessage('Il Core deve essere installato prima dei plugin');
        $this->installer->install([$plan[1]], 'tecnico');
    }

    public function testKernelRefusesWhenCoreNotInstalled(): void
    {
        $kernel = new Kernel($this->modules, $this->installer, new Clock());
        self::assertSame(503, $kernel->handle(new Request('GET', '/api/v1/salute'))->status);
    }

    public function testOnlyInstalledPluginsBoot(): void
    {
        $this->installer->install($this->plan(['emp-core']), 'tecnico');
        $kernel = new Kernel($this->modules, $this->installer, new Clock());
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/v1/salute'))->status);
        self::assertSame(404, $kernel->handle(new Request('GET', '/api/v1/esempio'))->status);
    }

    public function testModifiedMigrationBlocksBoot(): void
    {
        $this->installer->install($this->plan(), 'tecnico');
        file_put_contents($this->modules . '/emp-esempio/migrations/0001_note.sql', "-- modificata\n", FILE_APPEND);
        $this->expectExceptionMessage('modificata dopo l\'applicazione');
        $this->installer->verify($this->plan());
    }

    public function testUpgradeRunsOnlyNewMigrations(): void
    {
        $this->installer->install($this->plan(), 'tecnico');
        $manifestFile = $this->modules . '/emp-esempio/emp-module.json';
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        $manifest['version'] = '0.1.1';
        file_put_contents($manifestFile, json_encode($manifest));
        file_put_contents($this->modules . '/emp-esempio/migrations/0002_colonna.sql', "ALTER TABLE {prefix}note ADD COLUMN store_id INT NOT NULL DEFAULT 0;\n");

        $log = $this->installer->install($this->plan(), 'tecnico');
        self::assertSame('Aggiornato emp-esempio da 0.1.0 a 0.1.1 (1 migrazioni)', $log[1]);
        self::assertSame('0.1.1', $this->installer->installed()['emp-esempio']);
    }

    public function testDowngradeRefused(): void
    {
        $this->installer->install($this->plan(), 'tecnico');
        $this->pdo->exec("UPDATE emp_modules SET version = '0.9.0' WHERE id = 'emp-esempio'");
        $this->expectExceptionMessage('inferiore a quella installata');
        $this->installer->install($this->plan(), 'tecnico');
    }

    public function testFailedMigrationIsRolledBackAndReported(): void
    {
        file_put_contents($this->modules . '/emp-esempio/migrations/0002_rotta.sql', "CREATE TABLE {prefix}ok (id INT);\nQUESTA NON E SQL;\n");
        try {
            $this->installer->install($this->plan(), 'tecnico');
            self::fail('Attesa eccezione');
        } catch (ResolutionException $e) {
            self::assertStringContainsString('0002_rotta.sql', $e->getMessage());
        }
        $applied = $this->pdo->query("SELECT file FROM emp_migrations WHERE module = 'emp-esempio'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['0001_note.sql'], $applied);
        self::assertArrayNotHasKey('emp-esempio', $this->installer->installed());
    }

    public function testUninstallBlockedByDependents(): void
    {
        Fixtures::writeModule($this->modules, Fixtures::manifest('emp-figlio', ['requires' => ['emp-core' => '>=0.1.0', 'emp-esempio' => '>=0.1.0']]));
        $this->installer->install($this->plan(), 'tecnico');
        $available = (new Registry($this->modules))->discover();
        try {
            $this->installer->uninstall('emp-esempio', $available, 'tecnico');
            self::fail('Attesa eccezione');
        } catch (ResolutionException $e) {
            self::assertStringContainsString('richiesto da: emp-figlio', $e->getMessage());
        }
        $this->installer->uninstall('emp-figlio', $available, 'tecnico');
        $this->installer->uninstall('emp-esempio', $available, 'tecnico');
        self::assertSame(['emp-core' => '0.1.3'], $this->installer->installed());
        // I dati restano.
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'emp_esempio_note'")->fetchColumn());
    }

    public function testCoreCannotBeUninstalled(): void
    {
        $this->installer->install($this->plan(), 'tecnico');
        $this->expectExceptionMessage('Il Core non può essere disinstallato');
        $this->installer->uninstall('emp-core', [], 'tecnico');
    }

    public function testActorRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->installer->install($this->plan(), ' ');
    }

    public function testSplitStatements(): void
    {
        self::assertSame(
            ['CREATE TABLE a (x INT)', "INSERT INTO a VALUES (1)"],
            Installer::splitStatements("-- commento\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1);"),
        );
    }
}
