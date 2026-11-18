<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Plugin\Arrivals;
use Emp\Core\Plugin\Catalog;
use Emp\Core\Plugin\CurlHttpClient;
use Emp\Core\Plugin\GitHubSource;
use Emp\Core\Plugin\HttpClient;
use Emp\Core\Plugin\LocalCatalog;
use Emp\Core\Plugin\PackageException;
use Emp\Core\Plugin\PluginManager;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/** Risposte HTTP finte, per provare la sorgente GitHub senza rete. */
final class FakeHttp implements HttpClient
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, string> $responses url => corpo */
    public function __construct(public array $responses = [])
    {
    }

    public function get(string $url, int $maxBytes): string
    {
        $this->requested[] = $url;
        if (!isset($this->responses[$url])) {
            throw new PackageException('Non trovato su GitHub (repository, release o allegato inesistente)');
        }

        return $this->responses[$url];
    }

    public function download(string $url, string $dest, int $maxBytes): void
    {
        $body = $this->get($url, $maxBytes);
        if (strlen($body) > $maxBytes) {
            throw new PackageException('File scaricato più grande del previsto');
        }
        file_put_contents($dest, $body);
    }
}

/**
 * Fonti dei plugin oltre al catalogo di EMP Server:
 * - caricamento manuale del pacchetto ZIP (modo normale, offline);
 * - sorgente GitHub facoltativa, solo release pubblicate.
 */
final class PluginSourcesTest extends TestCase
{
    private const REPO = 'edomea/edomea-marketplace';
    private const API = 'https://api.github.com/repos/edomea/edomea-marketplace';
    private const DL = 'https://github.com/edomea/edomea-marketplace/releases/download';
    private string $modules;
    private string $local;
    private Installer $installer;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->modules = Fixtures::tempDir();
        $this->local = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $this->modules . '/emp-core');
        $this->pdo = new \PDO('sqlite::memory:');
        $this->installer = new Installer($this->pdo, new Clock(), 'emp_');
        $this->installer->install((new Resolver())->resolve((new Registry($this->modules))->discover()), 'installer');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** ZIP di un plugin con la cartella <id>/ alla radice. */
    private function zip(string $sourceDir, string $id, array $extra = [], ?string $root = null): string
    {
        $path = Fixtures::tempDir() . "/{$id}.zip";
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $zip->addFile($f->getPathname(), ($root ?? $id) . '/' . substr($f->getPathname(), strlen($sourceDir) + 1));
        }
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function esempioZip(): string
    {
        return $this->zip(dirname(__DIR__, 2) . '/plugins/emp-esempio', 'emp-esempio');
    }

    private function manager(): PluginManager
    {
        return new PluginManager($this->modules, new Catalog(null), $this->installer, PHP_VERSION, new LocalCatalog($this->local));
    }

    private function versione(string $version): string
    {
        $dir = Fixtures::tempDir() . '/src';
        Fixtures::copy(dirname(__DIR__, 2) . '/plugins/emp-esempio', $dir);
        $m = json_decode((string) file_get_contents($dir . '/emp-module.json'), true);
        $m['version'] = $version;
        file_put_contents($dir . '/emp-module.json', json_encode($m));

        return $this->zip($dir, 'emp-esempio');
    }

    // ------------------------------------------------------------ caricamento manuale

    public function testUploadThenInstall(): void
    {
        $zip = $this->esempioZip();
        $entry = $this->manager()->upload($zip, 'Caricato a mano da tecnico', hash_file('sha256', $zip));
        self::assertTrue($entry['nuovo']);
        self::assertSame('emp-esempio', $entry['id']);

        $row = $this->manager()->overview()['plugin'][0];
        self::assertSame('installabile', $row['stato']);
        self::assertSame('Caricato a mano da tecnico', $row['origine']);
        self::assertTrue($row['nel_catalogo_locale']);

        $this->manager()->install('emp-esempio', 'tecnico');
        self::assertSame('installato', $this->manager()->overview()['plugin'][0]['stato']);
        // Ricaricare lo stesso pacchetto non cambia nulla.
        self::assertFalse($this->manager()->upload($zip, 'Caricato a mano da tecnico')['nuovo']);
    }

    public function testUploadWrongExpectedHashRejected(): void
    {
        $this->expectExceptionMessage('Impronta SHA-256 diversa');
        $this->manager()->upload($this->esempioZip(), 'x', str_repeat('a', 64));
    }

    public function testUploadCoreRejected(): void
    {
        $zip = $this->zip(dirname(__DIR__), 'emp-core');
        $this->expectExceptionMessage('EMP Server');
        $this->manager()->upload($zip, 'x');
    }

    public function testUploadRejectsBadLayouts(): void
    {
        $src = dirname(__DIR__, 2) . '/plugins/emp-esempio';
        $cases = [
            'due cartelle' => [$this->zip($src, 'emp-esempio', ['altro/x.php' => '<?php']), 'una sola cartella'],
            'percorso ..' => [$this->zip($src, 'emp-esempio', ['emp-esempio/../../x.php' => '<?php']), 'Percorso non ammesso'],
            'cartella con nome diverso' => [$this->zip($src, 'emp-esempio', [], 'emp-altro'), 'deve chiamarsi come il plugin'],
            'non ZIP' => [(static function (): string {
                $p = Fixtures::tempDir() . '/x.zip';
                file_put_contents($p, 'non sono uno zip');

                return $p;
            })(), 'non è un pacchetto ZIP'],
        ];
        foreach ($cases as $name => [$zip, $message]) {
            try {
                $this->manager()->upload($zip, 'x');
                self::fail("Atteso rifiuto: {$name}");
            } catch (PackageException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $name);
            }
        }
        self::assertSame([], $this->manager()->overview()['plugin']);
    }

    public function testSameVersionDifferentContentRejectedNewVersionReplaces(): void
    {
        $this->manager()->upload($this->versione('0.1.0'), 'a');
        $altered = $this->zip(dirname(__DIR__, 2) . '/plugins/emp-esempio', 'emp-esempio', ['emp-esempio/NOTE.txt' => 'diverso']);
        try {
            $this->manager()->upload($altered, 'b');
            self::fail('Atteso rifiuto');
        } catch (PackageException $e) {
            self::assertStringContainsString('aumentare la versione', $e->getMessage());
        }
        $this->manager()->upload($this->versione('0.2.0'), 'c');
        self::assertSame('0.2.0', $this->manager()->overview()['plugin'][0]['versione_disponibile']);
        self::assertSame(['emp-esempio-0.2.0.zip', 'emp-packages.json'], array_values(array_filter(scandir($this->local) ?: [], static fn (string $f): bool => $f[0] !== '.')));
    }

    public function testRemovePackageKeepsInstalledPlugin(): void
    {
        $this->manager()->upload($this->esempioZip(), 'a');
        $this->manager()->install('emp-esempio', 'tecnico');
        self::assertTrue($this->manager()->removePackage('emp-esempio'));
        self::assertFalse($this->manager()->removePackage('emp-esempio'));
        $row = $this->manager()->overview()['plugin'][0];
        self::assertSame('0.1.0', $row['versione_installata']);
        self::assertFalse($row['nel_catalogo_locale']);
    }

    public function testHigherVersionWinsAcrossCatalogs(): void
    {
        // Catalogo di EMP Server con la 0.1.0, catalogo locale con la 0.2.0.
        $system = Fixtures::tempDir();
        $zip = $this->versione('0.1.0');
        copy($zip, $system . '/emp-esempio-0.1.0.zip');
        file_put_contents($system . '/emp-packages.json', json_encode(['schema' => 1, 'packages' => [
            ['id' => 'emp-esempio', 'version' => '0.1.0', 'file' => 'emp-esempio-0.1.0.zip', 'sha256' => hash_file('sha256', $zip)],
        ]]));
        $manager = new PluginManager($this->modules, new Catalog($system), $this->installer, PHP_VERSION, new LocalCatalog($this->local));
        self::assertSame('Pacchetto EMP Server', $manager->overview()['plugin'][0]['origine']);
        $manager->upload($this->versione('0.2.0'), 'Caricato a mano da tecnico');
        $row = $manager->overview()['plugin'][0];
        self::assertSame(['0.2.0', 'Caricato a mano da tecnico'], [$row['versione_disponibile'], $row['origine']]);
    }

    // ------------------------------------------------------------ sorgente GitHub

    /** Release finta con il catalogo e il pacchetto di emp-esempio. */
    private function release(string $tag, bool $draft = false, bool $prerelease = false, ?string $tamper = null): array
    {
        $zip = (string) file_get_contents($this->esempioZip());
        $index = json_encode(['schema' => 1, 'packages' => [
            ['id' => 'emp-esempio', 'version' => '0.1.0', 'file' => 'emp-esempio-0.1.0.zip', 'sha256' => hash('sha256', $zip)],
        ]]);
        $release = [
            'tag_name' => $tag, 'name' => "EMP {$tag}", 'draft' => $draft, 'prerelease' => $prerelease,
            'published_at' => '2026-11-17T08:00:00Z', 'html_url' => "https://github.com/edomea/edomea-marketplace/releases/tag/{$tag}",
            'assets' => [
                ['name' => 'emp-packages.json', 'size' => strlen((string) $index), 'browser_download_url' => self::DL . "/{$tag}/emp-packages.json"],
                ['name' => 'emp-esempio-0.1.0.zip', 'size' => strlen($zip), 'browser_download_url' => self::DL . "/{$tag}/emp-esempio-0.1.0.zip"],
            ],
        ];

        return [$release, [
            self::DL . "/{$tag}/emp-packages.json" => (string) $index,
            self::DL . "/{$tag}/emp-esempio-0.1.0.zip" => $tamper ?? $zip,
        ]];
    }

    private function github(FakeHttp $http): GitHubSource
    {
        return new GitHubSource(new LocalCatalog($this->local), $http);
    }

    public function testGitHubDisabledByDefaultAndNoNetwork(): void
    {
        $http = new FakeHttp();
        $source = $this->github($http);
        self::assertFalse($source->settings()['attiva']);
        self::assertSame(self::REPO, $source->settings()['repository']);
        try {
            $source->releases();
            self::fail('Attesa eccezione');
        } catch (PackageException $e) {
            self::assertStringContainsString('disattivata', $e->getMessage());
        }
        // La pagina Plugin non interroga mai GitHub da sola.
        $this->manager()->overview();
        self::assertSame([], $http->requested);
    }

    public function testOnlyPublishedReleasesListedAndImported(): void
    {
        [$pub, $files] = $this->release('v0.6.0');
        [$draft] = $this->release('v0.7.0', true);
        [$pre] = $this->release('v0.7.0-rc.1', false, true);
        $http = new FakeHttp($files + [
            self::API . '/releases?per_page=20' => json_encode([$draft, $pre, $pub]),
            self::API . '/releases/tags/v0.6.0' => json_encode($pub),
            self::API . '/releases/tags/v0.7.0-rc.1' => json_encode($pre),
        ]);
        $source = $this->github($http);
        $source->saveSettings(true, self::REPO, 'tecnico', new \DateTimeImmutable());

        self::assertSame(['v0.6.0'], array_column($source->releases(), 'tag'));
        try {
            $source->import('v0.7.0-rc.1');
            self::fail('Pre-release importata');
        } catch (PackageException $e) {
            self::assertStringContainsString('non è pubblicata', $e->getMessage());
        }

        $imported = $source->import('v0.6.0');
        self::assertSame('GitHub edomea/edomea-marketplace v0.6.0', $imported[0]['origine']);
        $row = $this->manager()->overview()['plugin'][0];
        self::assertSame(['installabile', 'GitHub edomea/edomea-marketplace v0.6.0'], [$row['stato'], $row['origine']]);
        $this->manager()->install('emp-esempio', 'tecnico');
        self::assertSame('installato', $this->manager()->overview()['plugin'][0]['stato']);
        // Nessuna cartella temporanea rimasta.
        self::assertSame([], glob($this->local . '/.github-*'));
    }

    public function testTamperedReleaseAssetRejectedNothingImported(): void
    {
        [$pub, $files] = $this->release('v0.6.0', false, false, 'contenuto alterato');
        $http = new FakeHttp($files + [self::API . '/releases/tags/v0.6.0' => json_encode($pub)]);
        $source = $this->github($http);
        $source->saveSettings(true, self::REPO, 'tecnico', new \DateTimeImmutable());
        try {
            $source->import('v0.6.0');
            self::fail('Atteso rifiuto');
        } catch (PackageException $e) {
            self::assertStringContainsString('impronta SHA-256 non corrispondente', $e->getMessage());
        }
        self::assertSame([], $this->manager()->overview()['plugin']);
    }

    public function testGitHubSettingsValidated(): void
    {
        $source = $this->github(new FakeHttp());
        foreach (['', 'solo-nome', 'a/b/c', 'https://github.com/x/y', '../x/y'] as $bad) {
            try {
                $source->saveSettings(true, $bad, 'tecnico', new \DateTimeImmutable());
                self::fail("Accettato: {$bad}");
            } catch (PackageException) {
                self::assertFalse($source->settings()['attiva']);
            }
        }
        $this->expectExceptionMessage('Release non valida');
        $source->saveSettings(true, self::REPO, 'tecnico', new \DateTimeImmutable());
        $source->import('../../etc');
    }

    public function testOnlyGitHubHttpsUrlsAllowed(): void
    {
        foreach ([
            'https://api.github.com/repos/x/y' => true,
            'https://github.com/x/y/releases/download/v1/a.zip' => true,
            'https://objects.githubusercontent.com/abc' => true,
            'https://release-assets.githubusercontent.com/abc' => true,
            'http://github.com/x' => false,
            'https://github.com.evil.example/x' => false,
            'https://evil.example/github.com' => false,
            'https://user:pass@github.com/x' => false,
            'https://github.com:8443/x' => false,
            'file:///etc/passwd' => false,
        ] as $url => $expected) {
            self::assertSame($expected, CurlHttpClient::allowedUrl($url), $url);
        }
    }

    // ------------------------------------------------------------ arrivi via scp

    public function testArrivalsListAddAndDelete(): void
    {
        $dir = Fixtures::tempDir();
        copy($this->esempioZip(), $dir . '/emp-esempio.zip');
        file_put_contents($dir . '/note.txt', 'non uno zip');
        symlink('/etc/passwd', $dir . '/collegamento.zip');
        $arrivals = new Arrivals($dir);
        self::assertSame(['emp-esempio.zip'], array_column($arrivals->list(), 'nome'));

        foreach (['../etc/passwd', 'collegamento.zip', 'note.txt', 'assente.zip', '.zip'] as $bad) {
            try {
                $arrivals->path($bad);
                self::fail("Accettato: {$bad}");
            } catch (PackageException) {
                self::addToAssertionCount(1);
            }
        }
        $entry = $this->manager()->upload($arrivals->path('emp-esempio.zip'), 'Arrivato via scp (emp-esempio.zip)');
        self::assertTrue($entry['nuovo']);
        $arrivals->delete('emp-esempio.zip');
        self::assertSame([], $arrivals->list());
        self::assertTrue(is_link($dir . '/collegamento.zip'), 'il collegamento non va seguito né eliminato');
    }

    // ------------------------------------------------------------ API HTTP

    public function testHttpUploadAndGitHubRoutes(): void
    {
        [$pub, $files] = $this->release('v0.6.0');
        $http = new FakeHttp($files + [
            self::API . '/releases?per_page=20' => json_encode([$pub]),
            self::API . '/releases/tags/v0.6.0' => json_encode($pub),
        ]);
        $kernel = new Kernel($this->modules, $this->installer, new Clock(), null, $this->local, $http);
        $kernel->access()->setupTecnico('tecnico', 'Mario Tecnico', 'Chiave-Sicura-2026!');
        $h = ['host' => 'localhost', 'x-emp-richiesta' => '1'];
        $login = $kernel->handle(new Request('POST', '/api/v1/accesso/login', $h, '127.0.0.1', (string) json_encode(['username' => 'tecnico', 'password' => 'Chiave-Sicura-2026!'])));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $login->headers['Set-Cookie'], $m);
        $auth = $h + ['cookie' => 'emp_sessione=' . $m[1]];
        $zip = $this->esempioZip();
        $upload = static fn (array $headers): Request => new Request('POST', '/api/v1/plugin/carica', $headers, '127.0.0.1', '', [], $zip);

        // Senza sessione, senza intestazione anti-CSRF o con tipo sbagliato: rifiutato.
        self::assertSame(401, $kernel->handle($upload($h + ['content-type' => 'application/zip']))->status);
        self::assertSame(403, $kernel->handle($upload(['host' => 'localhost', 'content-type' => 'application/zip', 'cookie' => $auth['cookie']]))->status);
        self::assertSame(409, $kernel->handle($upload($auth + ['content-type' => 'text/plain']))->status);
        // Impronta indicata sbagliata: rifiutato.
        self::assertSame(409, $kernel->handle($upload($auth + ['content-type' => 'application/zip', 'x-emp-impronta' => str_repeat('0', 64)]))->status);

        $ok = $kernel->handle($upload($auth + ['content-type' => 'application/zip', 'x-emp-impronta' => hash_file('sha256', $zip)]));
        self::assertSame(200, $ok->status, $ok->body);
        $list = json_decode($kernel->handle(new Request('GET', '/api/v1/plugin', $auth, '127.0.0.1'))->body, true);
        self::assertSame('Caricato a mano da tecnico', $list['plugin'][0]['origine']);
        self::assertTrue($list['catalogo_locale']);
        self::assertSame(200, $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/rimuovi-pacchetto', $auth, '127.0.0.1'))->status);

        // GitHub: disattivata → 409; attivata → release e importazione.
        self::assertSame(409, $kernel->handle(new Request('GET', '/api/v1/plugin/github/release', $auth, '127.0.0.1'))->status);
        $on = $kernel->handle(new Request('POST', '/api/v1/plugin/github', $auth, '127.0.0.1', (string) json_encode(['attiva' => true, 'repository' => self::REPO])));
        self::assertSame(200, $on->status, $on->body);
        $releases = json_decode($kernel->handle(new Request('GET', '/api/v1/plugin/github/release', $auth, '127.0.0.1'))->body, true);
        self::assertSame('v0.6.0', $releases['release'][0]['tag']);
        $imp = $kernel->handle(new Request('POST', '/api/v1/plugin/github/importa', $auth, '127.0.0.1', (string) json_encode(['tag' => 'v0.6.0'])));
        self::assertSame(200, $imp->status, $imp->body);
        self::assertSame(200, $kernel->handle(new Request('POST', '/api/v1/plugin/emp-esempio/installa', $auth, '127.0.0.1'))->status);

        // Arrivi via scp: elenco, aggiunta al catalogo locale (file eliminato), nome non valido rifiutato.
        $arrivi = Fixtures::tempDir();
        $kernelScp = new Kernel($this->modules, $this->installer, new Clock(), null, $this->local, $http, null, $arrivi);
        $kernelScp->plugins()->removePackage('emp-esempio');
        copy($zip, $arrivi . '/emp-esempio.zip');
        $list = json_decode($kernelScp->handle(new Request('GET', '/api/v1/plugin/arrivi', $auth, '127.0.0.1'))->body, true);
        self::assertSame('emp-esempio.zip', $list['file'][0]['nome']);
        self::assertSame(409, $kernelScp->handle(new Request('POST', '/api/v1/plugin/arrivi/aggiungi', $auth, '127.0.0.1', (string) json_encode(['file' => '../x.zip'])))->status);
        $add = $kernelScp->handle(new Request('POST', '/api/v1/plugin/arrivi/aggiungi', $auth, '127.0.0.1', (string) json_encode(['file' => 'emp-esempio.zip'])));
        self::assertSame(200, $add->status, $add->body);
        self::assertFileDoesNotExist($arrivi . '/emp-esempio.zip');
        // Senza cartella configurata la funzione non è disponibile.
        self::assertSame(409, $kernel->handle(new Request('GET', '/api/v1/plugin/arrivi', $auth, '127.0.0.1'))->status);

        // Tracciabilità: caricamento, rimozione, impostazione e importazione registrati.
        $actions = $this->pdo->query("SELECT action FROM emp_core_audit WHERE action LIKE 'plugin.%' ORDER BY occurred_at")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['plugin.pacchetto_caricato', 'plugin.pacchetto_rimosso', 'plugin.sorgente_github', 'plugin.pacchetto_importato', 'plugin.pacchetto_arrivato'] as $a) {
            self::assertContains($a, $actions);
        }
    }
}
