<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Access\Role;
use Emp\Core\Http\Request;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use Emp\Plugin\Studio\Ean;
use PHPUnit\Framework\TestCase;

/** Plugin EMP Studio (../plugins/emp-studio) sul Core reale: schede, varianti, foto, struttura, permessi, pagine nel menu. */
final class StudioTest extends TestCase
{
    private const PWD = 'Chiave-Sicura-2026!';
    private const LAVATRICI = '00000000-0000-4000-9000-000000000002';
    private Kernel $kernel;
    private \PDO $pdo;
    private string $data;
    /** @var array<string, string> */
    private array $auth;
    /** @var array<string, string> */
    private array $tecnico;

    protected function setUp(): void
    {
        $modules = Fixtures::tempDir();
        $this->data = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $modules . '/emp-core');
        Fixtures::copy(dirname(__DIR__, 2) . '/plugins/emp-studio', $modules . '/emp-studio');
        $this->pdo = new \PDO('sqlite::memory:');
        $installer = new Installer($this->pdo, new Clock(), 'emp_');
        $installer->install((new Resolver())->resolve((new Registry($modules))->discover()), 'installer');
        $this->kernel = new Kernel($modules, $installer, new Clock(), moduleDataDir: $this->data);
        $this->kernel->access()->setupTecnico('tecnico', 'Mario Tecnico', self::PWD);
        // Il Tecnico gestisce il sistema, non i dati commerciali: lavora sul catalogo un amministratore.
        $this->tecnico = $this->login('tecnico', self::PWD);
        $access = $this->kernel->access();
        $access->createUser($access->userForToken(substr($this->tecnico['cookie'], 13)), 'anna', 'Anna', Role::AMMINISTRATORE, 'Temporanea-Admin-1');
        $access->changeOwnPassword($access->login('anna', 'Temporanea-Admin-1', '127.0.0.1')[1], 'Temporanea-Admin-1', 'Definitiva-Admin-1', null);
        $this->auth = $this->login('anna', 'Definitiva-Admin-1');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** @return array<string, string> */
    private function login(string $user, string $password): array
    {
        $r = $this->kernel->handle(new Request('POST', '/api/v1/accesso/login', ['host' => 'localhost', 'x-emp-richiesta' => '1'], '127.0.0.1',
            (string) json_encode(['username' => $user, 'password' => $password])));
        self::assertSame(200, $r->status, $r->body);
        preg_match('/emp_sessione=([0-9a-f]{64})/', $r->headers['Set-Cookie'], $m);

        return ['cookie' => 'emp_sessione=' . $m[1], 'host' => 'localhost', 'x-emp-richiesta' => '1'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, mixed $body = null, ?array $auth = null, array $query = [], array $headers = []): array
    {
        $raw = is_string($body) ? $body : ($body === null ? '' : (string) json_encode($body));
        $in = '';
        if (is_string($body)) {
            $in = Fixtures::tempDir() . '/corpo';
            file_put_contents($in, $body);
        }
        $r = $this->kernel->handle(new Request($method, $path, $headers + ($auth ?? $this->auth), '127.0.0.1', substr($raw, 0, 65536), [], $in !== '' ? $in : 'php://input', $query));

        return [$r->status, json_decode($r->body, true) ?? []];
    }

    public function testEan(): void
    {
        self::assertSame('8001234567897', Ean::normalize('8001234567897'));
        self::assertNull(Ean::normalize('8001234567891'));
        self::assertSame('0012345678905', Ean::normalize('012345678905')); // UPC-A
        self::assertSame('2090000000016', Ean::internal(1));
        self::assertTrue(Ean::isInternal('2090000000016'));
        self::assertNotNull(Ean::normalize(Ean::internal(987654)));
    }

    public function testManifestPagesAndMenu(): void
    {
        $m = Manifest::fromJson((string) json_encode(Fixtures::manifest('emp-prova', ['permissions' => ['prova.vedi'], 'pages' => [['id' => 'elenco', 'label' => 'Elenco', 'file' => 'index.html', 'permission' => 'prova.vedi']]])));
        self::assertSame('elenco', $m->pages[0]['id']);
        foreach ([['file' => '../x.html'], ['permission' => 'altro.vedi'], ['id' => 'A B']] as $bad) {
            try {
                Manifest::fromJson((string) json_encode(Fixtures::manifest('emp-prova', ['permissions' => ['prova.vedi'], 'pages' => [$bad + ['id' => 'elenco', 'label' => 'Elenco', 'file' => 'index.html', 'permission' => 'prova.vedi']]])));
                self::fail('pagina non valida accettata: ' . json_encode($bad));
            } catch (\Throwable $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        [$s, $menu] = $this->call('GET', '/api/v1/menu');
        self::assertSame(200, $s);
        self::assertSame([], $this->call('GET', '/api/v1/menu', null, $this->tecnico)[1]['pagine']);
        self::assertSame(403, $this->call('GET', '/api/v1/studio/prodotti', null, $this->tecnico)[0]);
        self::assertSame([['modulo' => 'emp-studio', 'id' => 'catalogo', 'label' => 'Catalogo prodotti', 'href' => '/m/emp-studio/index.html']], $menu['pagine']);
    }

    public function testProductLifecycle(): void
    {
        [, $st] = $this->call('GET', '/api/v1/studio/struttura');
        self::assertCount(5, $st['famiglie']);
        [$s, $b] = $this->call('POST', '/api/v1/studio/marche', ['nome' => 'Samsung']);
        self::assertSame(200, $s, json_encode($b));
        $brand = $b['id'];
        self::assertSame(409, $this->call('POST', '/api/v1/studio/marche', ['nome' => 'Samsung'])[0]);
        $parent = $this->call('POST', '/api/v1/studio/categorie', ['nome' => 'Grandi elettrodomestici'])[1]['id'];
        $cat = $this->call('POST', '/api/v1/studio/categorie', ['nome' => 'Lavatrici', 'parent_id' => $parent])[1]['id'];
        self::assertSame(422, $this->call('POST', '/api/v1/studio/categorie/' . $parent, ['nome' => 'X', 'parent_id' => $cat])[0]);

        // Scheda incompleta: EAN sbagliato rifiutato, SKU automatico, completezza parziale.
        self::assertSame(422, $this->call('POST', '/api/v1/studio/prodotti', ['nome' => 'Lavatrice', 'ean' => '8001234567891'])[0]);
        [$s, $r] = $this->call('POST', '/api/v1/studio/prodotti', ['nome' => 'Lavatrice WW90', 'ean' => '8001234567897', 'marca_id' => $brand,
            'categoria_id' => $cat, 'famiglia_id' => self::LAVATRICI, 'classe_energetica' => 'A', 'eprel' => '1234567',
            'valori' => ['00000000-0000-4000-8000-000000000006' => '9', '00000000-0000-4000-8000-000000000007' => 'tanti']]);
        self::assertSame(422, $s, 'numero non valido');
        [$s, $r] = $this->call('POST', '/api/v1/studio/prodotti', ['nome' => 'Lavatrice WW90', 'ean' => '8001234567897', 'marca_id' => $brand,
            'categoria_id' => $cat, 'famiglia_id' => self::LAVATRICI, 'classe_energetica' => 'A', 'eprel' => '1234567',
            'valori' => ['00000000-0000-4000-8000-000000000006' => '9,0', '00000000-0000-4000-8000-000000000007' => '1400']]);
        self::assertSame(200, $s, json_encode($r));
        $id = $r['id'];
        [, $p] = $this->call('GET', '/api/v1/studio/prodotti/' . $id);
        self::assertSame('P000001', $p['prodotto']['sku']);
        self::assertSame('9', $p['valori']['00000000-0000-4000-8000-000000000006']);
        self::assertContains('Descrizione breve', $p['mancanti']);
        self::assertContains('Almeno una foto', $p['mancanti']);
        self::assertLessThan(100, $p['prodotto']['completezza']);
        self::assertSame(409, $this->call('POST', '/api/v1/studio/prodotti', ['nome' => 'Doppione', 'ean' => '8001234567897'])[0]);

        // Foto (PNG vero) e PDF: la scheda diventa completa con i campi obbligatori della famiglia.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        self::assertSame(422, $this->call('POST', '/api/v1/studio/media', 'non è una foto', null, ['prodotto' => $id], ['content-type' => 'image/png'])[0]);
        [$s, $m] = $this->call('POST', '/api/v1/studio/media', $png, null, ['prodotto' => $id, 'titolo' => 'Fronte'], ['content-type' => 'image/png']);
        self::assertSame(200, $s, json_encode($m));
        self::assertSame(200, $this->call('POST', '/api/v1/studio/media', "%PDF-1.4\n%%EOF", null, ['prodotto' => $id], ['content-type' => 'application/pdf'])[0]);
        self::assertSame(422, $this->call('POST', '/api/v1/studio/video', ['prodotto' => $id, 'url' => 'javascript:alert(1)', 'titolo' => ''])[0]);
        self::assertSame(200, $this->call('POST', '/api/v1/studio/video', ['prodotto' => $id, 'url' => 'https://www.youtube.com/watch?v=abc', 'titolo' => 'Demo'])[0]);
        $this->call('POST', '/api/v1/studio/prodotti/' . $id, array_merge($p['prodotto'], ['descrizione_breve' => 'Lavatrice 9 kg, 1400 giri', 'stato' => 'attivo']));
        [, $p] = $this->call('GET', '/api/v1/studio/prodotti/' . $id);
        self::assertSame([], $p['mancanti'], json_encode($p['mancanti']));
        self::assertSame(100, $p['prodotto']['completezza']);
        self::assertCount(3, $p['media']);
        $photo = array_values(array_filter($p['media'], static fn ($x) => $x['tipo'] === 'foto'))[0];
        self::assertMatchesRegularExpression('#^/m-file/emp-studio/foto/[0-9a-f]{2}/[0-9a-f-]{36}\.png$#', $photo['url']);
        self::assertFileExists($this->data . '/emp-studio/pubblico/' . substr($photo['url'], strlen('/m-file/emp-studio/')));

        // Ricerca: testo, EAN letto dal lettore, filtro per categoria superiore.
        self::assertSame(1, $this->call('GET', '/api/v1/studio/prodotti', null, null, ['q' => 'samsung ww90'])[1]['totale']);
        self::assertSame(1, $this->call('GET', '/api/v1/studio/prodotti', null, null, ['categoria' => $parent])[1]['totale']);
        self::assertSame($id, $this->call('GET', '/api/v1/studio/cerca', null, null, ['codice' => '8001234567897'])[1]['id']);
        self::assertSame(['totale' => 1, 'bozze' => 0, 'attivi' => 1, 'completi' => 1], $this->call('GET', '/api/v1/studio/riepilogo')[1]);

        // Eliminazione della marca bloccata finché è usata; foto eliminata anche dal disco.
        self::assertSame(409, $this->call('POST', '/api/v1/studio/marche/' . $brand . '/elimina')[0]);
        self::assertSame(200, $this->call('POST', '/api/v1/studio/media/' . $photo['id'] . '/elimina')[0]);
        self::assertFileDoesNotExist($this->data . '/emp-studio/pubblico/' . substr($photo['url'], strlen('/m-file/emp-studio/')));

        // Tracciabilità con prima/dopo.
        $audit = $this->pdo->query("SELECT action, before_value, after_value FROM emp_core_audit WHERE module = 'emp-studio' AND action = 'prodotto.modificato'")->fetch(\PDO::FETCH_ASSOC);
        self::assertStringContainsString('"stato":"bozza"', (string) $audit['before_value']);
        self::assertStringContainsString('"stato":"attivo"', (string) $audit['after_value']);
    }

    public function testVariantsAndInternalEan(): void
    {
        [$s, $r] = $this->call('POST', '/api/v1/studio/prodotti', ['tipo' => 'modello', 'nome' => 'Galaxy S25', 'famiglia_id' => '00000000-0000-4000-9000-000000000005', 'ean' => '8001234567897']);
        self::assertSame(200, $s, json_encode($r));
        $model = $r['id'];
        [, $p] = $this->call('GET', '/api/v1/studio/prodotti/' . $model);
        self::assertNull($p['prodotto']['sku']);
        self::assertNull($p['prodotto']['ean'], 'il modello non ha codici propri');
        $ean = $this->call('GET', '/api/v1/studio/ean-interno')[1]['ean'];
        self::assertSame('2090000000016', $ean);
        [$s, $v] = $this->call('POST', '/api/v1/studio/prodotti/' . $model . '/variante', ['nome' => 'Galaxy S25 256 GB', 'ean' => $ean, 'valori' => ['00000000-0000-4000-8000-000000000013' => '256']]);
        self::assertSame(200, $s, json_encode($v));
        [, $vp] = $this->call('GET', '/api/v1/studio/prodotti/' . $v['id']);
        self::assertSame('variante', $vp['prodotto']['tipo']);
        self::assertTrue($vp['prodotto']['ean_interno']);
        self::assertSame($model, $vp['modello']['id']);
        [, $p] = $this->call('GET', '/api/v1/studio/prodotti/' . $model);
        self::assertCount(1, $p['varianti']);
        self::assertSame('256', $p['varianti'][0]['valori']['00000000-0000-4000-8000-000000000013']);
        // La variante si trova cercando il suo EAN (si mostra il modello); non si aggiungono varianti a una variante.
        self::assertSame($model, $this->call('GET', '/api/v1/studio/prodotti', null, null, ['q' => '2090000000016'])[1]['prodotti'][0]['id']);
        self::assertSame(422, $this->call('POST', '/api/v1/studio/prodotti/' . $v['id'] . '/variante', ['nome' => 'x'])[0]);
        self::assertSame('2090000000023', $this->call('GET', '/api/v1/studio/ean-interno')[1]['ean']);
    }

    public function testFamiliesAndAttributes(): void
    {
        self::assertSame(422, $this->call('POST', '/api/v1/studio/attributi', ['nome' => 'Tipo pannello', 'tipo' => 'elenco', 'unita' => '', 'opzioni' => []])[0]);
        $attr = $this->call('POST', '/api/v1/studio/attributi', ['nome' => 'Tipo pannello', 'tipo' => 'elenco', 'unita' => '', 'opzioni' => ['OLED', 'QLED', 'LED']])[1]['id'];
        [$s, $f] = $this->call('POST', '/api/v1/studio/famiglie', ['nome' => 'Monitor', 'attributi' => [['id' => $attr, 'obbligatorio' => true]]]);
        self::assertSame(200, $s, json_encode($f));
        $id = $this->call('POST', '/api/v1/studio/prodotti', ['nome' => 'Monitor 27', 'famiglia_id' => $f['id'], 'valori' => [$attr => 'OLED']])[1]['id'];
        self::assertSame(422, $this->call('POST', '/api/v1/studio/prodotti/' . $id, ['nome' => 'Monitor 27', 'famiglia_id' => $f['id'], 'valori' => [$attr => 'CRT']])[0]);
        self::assertSame(409, $this->call('POST', '/api/v1/studio/attributi/' . $attr . '/elimina')[0]);
        self::assertSame(409, $this->call('POST', '/api/v1/studio/famiglie/' . $f['id'] . '/elimina')[0]);
        [, $st] = $this->call('GET', '/api/v1/studio/struttura');
        $monitor = array_values(array_filter($st['famiglie'], static fn ($x) => $x['id'] === $f['id']))[0];
        self::assertSame([['id' => $attr, 'obbligatorio' => true]], $monitor['attributi']);
    }

    public function testPermissions(): void
    {
        $tecnico = $this->kernel->access()->userForToken(substr($this->tecnico['cookie'], 13));
        $access = $this->kernel->access();
        $access->createUser($tecnico, 'luca', 'Luca', Role::OPERATORE, 'Temporanea-Cassa-1');
        $access->changeOwnPassword($access->login('luca', 'Temporanea-Cassa-1', '127.0.0.1')[1], 'Temporanea-Cassa-1', 'Definitiva-Cassa-1', null);
        $luca = $this->login('luca', 'Definitiva-Cassa-1');

        // Operatore senza ruolo: niente Studio, niente voce nel menu.
        self::assertSame(403, $this->call('GET', '/api/v1/studio/prodotti', null, $luca)[0]);
        self::assertSame([], $this->call('GET', '/api/v1/menu', null, $luca)[1]['pagine']);

        // Ruolo con la sola consultazione: legge, non modifica, non configura.
        $roles = $this->kernel->jobRoles();
        $role = $roles->save($tecnico, null, 'Commesso', 'Consulta il catalogo', ['studio.vedi']);
        $lucaUser = $access->userForToken(substr($luca['cookie'], 13));
        $roles->assign($tecnico, $lucaUser, [$role]);
        self::assertSame(200, $this->call('GET', '/api/v1/studio/prodotti', null, $luca)[0]);
        self::assertSame(['modifica' => false, 'configura' => false], $this->call('GET', '/api/v1/studio/permessi', null, $luca)[1]);
        self::assertSame(403, $this->call('POST', '/api/v1/studio/prodotti', ['nome' => 'X'], $luca)[0]);
        self::assertSame(403, $this->call('POST', '/api/v1/studio/marche', ['nome' => 'X'], $luca)[0]);
        self::assertCount(1, $this->call('GET', '/api/v1/menu', null, $luca)[1]['pagine']);
        self::assertSame(['modifica' => true, 'configura' => true], $this->call('GET', '/api/v1/studio/permessi')[1]);
        // Senza sessione: 401.
        self::assertSame(401, $this->call('GET', '/api/v1/studio/prodotti', null, ['host' => 'localhost'])[0]);
    }
}
