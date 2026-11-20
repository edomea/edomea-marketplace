<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\BootControl;
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
        self::assertSame(['Installato emp-core 0.1.11 (2 migrazioni)', 'Installato emp-esempio 0.1.0 (1 migrazioni)'], $log);
        self::assertSame(['emp-core' => '0.1.11', 'emp-esempio' => '0.1.0'], $this->installer->installed());

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
        self::assertNull(json_decode($health->body, true)['server']);
        // Versione di EMP Server (dalla configurazione) mostrata nelle pagine.
        $withServer = new Kernel($this->modules, $this->installer, new Clock(), null, null, null, '0.6.0-alpha.5');
        $versions = json_decode($withServer->handle(new Request('GET', '/api/v1/salute'))->body, true);
        self::assertSame(['0.6.0-alpha.5', '0.1.11'], [$versions['server'], $versions['core']]);
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
        self::assertSame(['emp-core 0.1.11 già installato', 'emp-esempio 0.1.0 già installato'], $log);
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
        self::assertSame(['emp-core' => '0.1.11'], $this->installer->installed());
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

    /** Plugin che blocca EMP: fermato dalla console (plugin-disattiva, avvio-sicuro), il Core riparte. */
    public function testBootControlStopsBrokenPlugin(): void
    {
        $broken = <<<'PHP'
            <?php
            namespace Emp\Test\EmpRotto;
            final class Module implements \Emp\Core\Module\ModuleInterface
            {
                public function register(\Emp\Core\Kernel $kernel, \Emp\Core\Module\Manifest $manifest): void
                {
                    throw new \RuntimeException('plugin rotto');
                }
            }
            PHP;
        Fixtures::writeModule($this->modules, Fixtures::manifest('emp-rotto'), ['src/Module.php' => $broken]);
        $child = <<<'PHP'
            <?php
            namespace Emp\Test\EmpFiglio;
            final class Module implements \Emp\Core\Module\ModuleInterface
            {
                public function register(\Emp\Core\Kernel $kernel, \Emp\Core\Module\Manifest $manifest): void
                {
                }
            }
            PHP;
        Fixtures::writeModule($this->modules, Fixtures::manifest('emp-figlio', [
            'requires' => ['emp-core' => '>=0.1.0 <0.2.0', 'emp-rotto' => '>=0.1.0'],
        ]), ['src/Module.php' => $child]);
        $this->installer->install($this->plan(), 'tecnico');

        $conf = Fixtures::tempDir() . '/avvio.conf';
        $kernel = fn (): Kernel => new Kernel($this->modules, $this->installer, new Clock(), bootControlFile: $conf);
        // Senza interventi: il plugin rotto blocca tutto.
        self::assertSame(503, $kernel()->handle(new Request('GET', '/api/v1/salute'))->status);

        file_put_contents($conf, "# prova\nAVVIO_SICURO=no\nDISATTIVATI=emp-rotto,emp-core,NON VALIDO\n");
        $k = $kernel();
        $health = $k->handle(new Request('GET', '/api/v1/salute'));
        self::assertSame(200, $health->status);
        self::assertFalse(json_decode($health->body, true)['avvio_sicuro']);
        self::assertSame(['emp-core', 'emp-esempio'], array_map(static fn ($m) => $m->id, $k->modules()));
        self::assertSame(['emp-figlio', 'emp-rotto'], array_keys($k->skipped()));
        self::assertSame(BootControl::DISABLED, $k->skipped()['emp-rotto']);
        self::assertStringContainsString('emp-rotto', $k->skipped()['emp-figlio']);
        self::assertSame(200, $k->handle(new Request('GET', '/api/v1/esempio'))->status);
        $rows = array_column($k->plugins()->overview()['plugin'], 'fermo', 'id');
        self::assertSame(BootControl::DISABLED, $rows['emp-rotto']);
        self::assertNull($rows['emp-esempio']);

        // Avvio sicuro: solo il Core.
        file_put_contents($conf, "AVVIO_SICURO=si\nDISATTIVATI=\n");
        $k = $kernel();
        $health = $k->handle(new Request('GET', '/api/v1/salute'));
        self::assertSame(200, $health->status);
        self::assertTrue(json_decode($health->body, true)['avvio_sicuro']);
        self::assertSame(['emp-core'], array_map(static fn ($m) => $m->id, $k->modules()));
        self::assertSame(404, $k->handle(new Request('GET', '/api/v1/esempio'))->status);
        self::assertTrue($k->plugins()->overview()['avvio_sicuro']);

        // File tolto (plugin-riattiva / avvio-sicuro --fine): di nuovo tutto attivo, e il plugin rotto blocca.
        unlink($conf);
        self::assertSame(503, $kernel()->handle(new Request('GET', '/api/v1/salute'))->status);
    }

    public function testBootControlParse(): void
    {
        $c = BootControl::parse("AVVIO_SICURO=si\nDISATTIVATI= emp-b , emp-a,emp-a,../x\nALTRO=1\n");
        self::assertTrue($c->safeMode);
        self::assertSame(['emp-a', 'emp-b'], $c->disabled);
        self::assertFalse(BootControl::fromFile('/percorso/inesistente')->safeMode);
        self::assertFalse(BootControl::parse("AVVIO_SICURO=SI\n")->safeMode);
    }
}
