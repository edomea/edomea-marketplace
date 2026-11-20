<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Http\AdminGuard;
use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Module\Resolver;
use Emp\Core\Plugin\Catalog;
use Emp\Core\Plugin\PackageException;
use Emp\Core\Plugin\PluginManager;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * Installazione dei plugin dall'interfaccia web del Core:
 * il Core è già installato (da EMP Server), i plugin arrivano dal catalogo.
 */
final class PluginManagerTest extends TestCase
{
    private string $modules;
    private string $catalog;
    private Installer $installer;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->modules = Fixtures::tempDir();
        $this->catalog = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $this->modules . '/emp-core');
        $this->pdo = new \PDO('sqlite::memory:');
        $this->installer = new Installer($this->pdo, new Clock(), 'emp_');
        $core = (new Resolver())->resolve((new Registry($this->modules))->discover());
        $this->installer->install($core, 'installer');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** Crea lo ZIP di un plugin nel catalogo a partire da una cartella; restituisce la voce d'indice. */
    private function pack(string $sourceDir, string $id, array $extraEntries = []): array
    {
        $manifest = json_decode((string) file_get_contents($sourceDir . '/emp-module.json'), true);
        $file = "{$id}-{$manifest['version']}.zip";
        $zip = new \ZipArchive();
        $zip->open($this->catalog . '/' . $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $zip->addFile($f->getPathname(), $id . '/' . substr($f->getPathname(), strlen($sourceDir) + 1));
        }
        foreach ($extraEntries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return ['id' => $id, 'version' => $manifest['version'], 'file' => $file, 'sha256' => hash_file('sha256', $this->catalog . '/' . $file)];
    }

    private function writeIndex(array $entries): void
    {
        file_put_contents($this->catalog . '/emp-packages.json', json_encode(['schema' => 1, 'packages' => $entries]));
    }

    private function manager(): PluginManager
    {
        return new PluginManager($this->modules, new Catalog($this->catalog), $this->installer);
    }

    private function esempio(): array
    {
        return $this->pack(dirname(__DIR__, 2) . '/plugins/emp-esempio', 'emp-esempio');
    }

    public function testOverviewAndInstallFromCatalog(): void
    {
        $this->writeIndex([$this->esempio()]);
        $overview = $this->manager()->overview();
        self::assertTrue($overview['catalogo']);
        self::assertSame('0.1.16', $overview['core']);
        self::assertSame('installabile', $overview['plugin'][0]['stato']);
        self::assertSame(['emp-core' => '>=0.1.0 <0.2.0'], $overview['plugin'][0]['dipendenze']);

        $log = $this->manager()->install('emp-esempio', 'tecnico');
        self::assertSame(['File di emp-esempio 0.1.0 estratti dal catalogo', 'emp-core 0.1.16 già installato', 'Installato emp-esempio 0.1.0 (1 migrazioni)'], $log);
        self::assertSame('installato', $this->manager()->overview()['plugin'][0]['stato']);

        $kernel = new Kernel($this->modules, $this->installer, new Clock(), $this->catalog);
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/v1/esempio'))->status);
    }

    public function testDependenciesInstalledFirstFromCatalog(): void
    {
        $base = Fixtures::writeModule(Fixtures::tempDir(), Fixtures::manifest('emp-base', ['table_prefix' => 'base_']));
        $figlio = Fixtures::writeModule(Fixtures::tempDir(), Fixtures::manifest('emp-figlio', [
            'requires' => ['emp-core' => '>=0.1.0', 'emp-base' => '^0.1.0'],
        ]));
        $this->writeIndex([$this->pack($figlio, 'emp-figlio'), $this->pack($base, 'emp-base')]);

        $log = $this->manager()->install('emp-figlio', 'tecnico');
        self::assertSame('File di emp-base 0.1.0 estratti dal catalogo', $log[0]);
        self::assertSame('File di emp-figlio 0.1.0 estratti dal catalogo', $log[1]);
        self::assertSame(['emp-base' => '0.1.0', 'emp-core' => '0.1.16', 'emp-figlio' => '0.1.0'], $this->installer->installed());

        try {
            $this->manager()->uninstall('emp-base', 'tecnico');
            self::fail('Attesa eccezione');
        } catch (ResolutionException $e) {
            self::assertStringContainsString('richiesto da: emp-figlio', $e->getMessage());
        }
    }

    public function testIncompatiblePluginReportedNotInstalled(): void
    {
        $dir = Fixtures::writeModule(Fixtures::tempDir(), Fixtures::manifest('emp-futuro', ['requires' => ['emp-core' => '>=0.2.0']]));
        $this->writeIndex([$this->pack($dir, 'emp-futuro')]);
        $row = $this->manager()->overview()['plugin'][0];
        self::assertSame('non_compatibile', $row['stato']);
        self::assertStringContainsString('richiede "emp-core"', $row['motivo']);
        $this->expectException(ResolutionException::class);
        $this->manager()->install('emp-futuro', 'tecnico');
    }

    public function testTamperedPackageRejected(): void
    {
        $entry = $this->esempio();
        file_put_contents($this->catalog . '/' . $entry['file'], 'x', FILE_APPEND);
        $this->writeIndex([$entry]);
        $overview = $this->manager()->overview();
        self::assertStringContainsString('impronta SHA-256 non corrispondente', $overview['errori'][0]);
        self::assertSame([], $overview['plugin']);
        self::assertFalse(is_dir($this->modules . '/emp-esempio'));
    }

    public function testMaliciousPathRejected(): void
    {
        $dir = Fixtures::writeModule(Fixtures::tempDir(), Fixtures::manifest('emp-cattivo'));
        $this->writeIndex([$this->pack($dir, 'emp-cattivo', ['emp-cattivo/../../evasione.php' => '<?php'])]);
        try {
            $this->manager()->install('emp-cattivo', 'tecnico');
            self::fail('Attesa eccezione');
        } catch (PackageException $e) {
            self::assertStringContainsString('percorso non ammesso', $e->getMessage());
        }
        self::assertFalse(is_dir($this->modules . '/emp-cattivo'));
        self::assertFalse(is_file(dirname($this->modules) . '/evasione.php'));
    }

    public function testCoreNotInstallableFromWeb(): void
    {
        $this->writeIndex([['id' => 'emp-core', 'version' => '9.0.0', 'file' => 'emp-core-9.0.0.zip', 'sha256' => str_repeat('0', 64)]]);
        self::assertSame([], $this->manager()->overview()['plugin']);
        $this->expectExceptionMessage('EMP Server');
        $this->manager()->install('emp-core', 'tecnico');
    }

    public function testNoCatalogConfigured(): void
    {
        $manager = new PluginManager($this->modules, new Catalog(null), $this->installer);
        self::assertFalse($manager->overview()['catalogo']);
    }

    public function testAdminGuard(): void
    {
        $headers = static fn (array $h): array => array_change_key_case($h, CASE_LOWER);
        // Dal computer stesso.
        self::assertNull(AdminGuard::check(new Request('GET', '/api/v1/plugin', $headers(['host' => 'localhost']), '127.0.0.1')));
        self::assertNull(AdminGuard::check(new Request('POST', '/api/v1/plugin/x/installa', $headers(['host' => '127.0.0.1:8080']), '::1')));
        // Da un'altra postazione in HTTPS: consentito.
        self::assertNull(AdminGuard::check(new Request('GET', '/api/v1/plugin', $headers(['host' => '192.168.1.10', 'x-emp-https' => '1']), '192.168.1.20')));
        // Da un'altra postazione in HTTP: rifiutato.
        self::assertSame(403, AdminGuard::check(new Request('GET', '/api/v1/plugin', $headers(['host' => '192.168.1.10']), '192.168.1.20'))?->status);
        // DNS rebinding: Host non locale in HTTP.
        self::assertSame(403, AdminGuard::check(new Request('GET', '/api/v1/plugin', $headers(['host' => 'evil.example']), '127.0.0.1'))?->status);
    }

    public function testApiEndpointsThroughKernel(): void
    {
        $this->writeIndex([$this->esempio()]);
        $kernel = new Kernel($this->modules, $this->installer, new Clock(), $this->catalog);
        $anon = ['host' => 'localhost', strtolower(AdminGuard::HEADER) => '1'];

        // Senza accesso: rifiutato. Dopo il setup e il login del Tecnico: consentito.
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/v1/plugin', $anon, '127.0.0.1'))->status);
        $kernel->access()->setupTecnico('tecnico', 'Tecnico', 'Password-Sicura-2026');
        [$token] = $kernel->access()->login('tecnico', 'Password-Sicura-2026', '127.0.0.1');
        $h = $anon + ['cookie' => 'emp_sessione=' . $token];

        $list = $kernel->handle(new Request('GET', '/api/v1/plugin', $h, '127.0.0.1'));
        self::assertSame(200, $list->status);
        self::assertSame('installabile', json_decode($list->body, true)['plugin'][0]['stato']);

        self::assertSame(403, $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/installa', $h, '10.0.0.5'))->status);
        // Senza intestazione dedicata (form da un altro sito): rifiutato da CsrfGuard.
        self::assertSame(403, $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/installa', ['host' => 'localhost', 'cookie' => $h['cookie']], '127.0.0.1'))->status);
        // Origine estranea: rifiutata.
        self::assertSame(403, $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/installa', $h + ['origin' => 'https://evil.example'], '127.0.0.1'))->status);
        $ok = $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/installa', $h, '127.0.0.1'));
        self::assertSame(200, $ok->status, $ok->body);
        self::assertSame('tecnico', $this->pdo->query("SELECT installed_by FROM emp_modules WHERE id = 'emp-esempio'")->fetchColumn());

        $missing = $kernel->handle(new Request('POST', '/api/v1/plugin/emp-inesistente/installa', $h, '127.0.0.1'));
        self::assertSame(409, $missing->status);

        $off = $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/disinstalla', $h, '127.0.0.1'));
        self::assertSame(200, $off->status);
        self::assertSame(['emp-core' => '0.1.16'], $this->installer->installed());
    }
}
