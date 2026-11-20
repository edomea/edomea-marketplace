<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Brand\BrandService;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Kernel;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Registry;
use Emp\Core\Module\Resolver;
use Emp\Core\Support\Clock;
use PHPUnit\Framework\TestCase;

/** Marchio white label: lettura pubblica, modifica solo del Tecnico in HTTPS, logo controllato. */
final class BrandTest extends TestCase
{
    private const PWD = 'Chiave-Sicura-2026!';
    private Kernel $kernel;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $modules . '/emp-core');
        $this->pdo = new \PDO('sqlite::memory:');
        $installer = new Installer($this->pdo, new Clock(), 'emp_');
        $installer->install((new Resolver())->resolve((new Registry($modules))->discover()), 'installer');
        $this->kernel = new Kernel($modules, $installer, new Clock());
        $this->kernel->access()->setupTecnico('tecnico', 'Mario Tecnico', self::PWD);
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** PNG valido di $w×$h pixel, senza estensioni grafiche. */
    private static function png(int $w, int $h): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0" . str_repeat("\x1e\x3a\x5f", $w);
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    }

    public function testBrandRoutes(): void
    {
        $k = $this->kernel;
        $h = ['host' => 'localhost', 'x-emp-richiesta' => '1'];
        $login = $k->handle(new Request('POST', '/api/v1/accesso/login', $h, '127.0.0.1', (string) json_encode(['username' => 'tecnico', 'password' => self::PWD])));
        preg_match('/emp_sessione=([0-9a-f]{64})/', $login->headers['Set-Cookie'], $m);
        $auth = $h + ['cookie' => 'emp_sessione=' . $m[1]];
        $post = static fn (string $path, string $body, array $headers): Response => $k->handle(new Request('POST', '/api/v1/' . $path, $headers, '127.0.0.1', $body));
        $get = static fn (string $path): Response => $k->handle(new Request('GET', '/api/v1/' . $path, ['host' => 'localhost'], '127.0.0.1'));

        // Predefinito, leggibile senza accesso (serve alle pagine di login).
        self::assertSame(['nome' => 'EMP', 'colore' => '#1e3a5f', 'logo' => null, 'predefinito' => true], json_decode($get('marchio')->body, true));
        self::assertSame(404, $get('marchio/logo')->status);

        // Modifica: solo con sessione del Tecnico, solo dalla macchina o in HTTPS.
        self::assertSame(401, $post('marchio', '{"nome":"Edomea","colore":"#123456"}', $h)->status);
        $lan = ['host' => '192.168.1.10', 'x-emp-richiesta' => '1', 'cookie' => $auth['cookie']];
        self::assertSame(403, $post('marchio', '{"nome":"Edomea","colore":"#123456"}', $lan)->status);
        self::assertSame(422, $post('marchio', '{"nome":"Edomea","colore":"#ffee00"}', $auth)->status); // troppo chiaro
        self::assertSame(422, $post('marchio', '{"nome":"<b>x</b>","colore":"#123456"}', $auth)->status);
        $ok = $post('marchio', '{"nome":"Edomea Store","colore":"#0B3D2E"}', $auth);
        self::assertSame(200, $ok->status, $ok->body);
        self::assertSame(['Edomea Store', '#0b3d2e'], [json_decode($ok->body, true)['marchio']['nome'], json_decode($ok->body, true)['marchio']['colore']]);

        // Logo: solo immagini vere, tipi ammessi, dimensioni ragionevoli.
        $png = self::png(40, 20);
        self::assertSame(422, $post('marchio/logo', '<svg onload="alert(1)"/>', $auth + ['content-type' => 'image/png'])->status);
        self::assertSame(422, $post('marchio/logo', $png, $auth + ['content-type' => 'image/svg+xml'])->status);
        self::assertSame(422, $post('marchio/logo', self::png(8, 8), $auth + ['content-type' => 'image/png'])->status);
        $up = $post('marchio/logo', $png, $auth + ['content-type' => 'image/png']);
        self::assertSame(200, $up->status, $up->body);
        $brand = json_decode($get('marchio')->body, true);
        self::assertSame(substr(hash('sha256', $png), 0, 16), $brand['logo']);
        $logo = $get('marchio/logo');
        self::assertSame([200, 'image/png', 'nosniff', $png], [$logo->status, $logo->headers['Content-Type'], $logo->headers['X-Content-Type-Options'], $logo->body]);

        // Rimozione del logo e ritorno al predefinito.
        self::assertSame(200, $post('marchio/logo/rimuovi', '', $auth)->status);
        self::assertNull(json_decode($get('marchio')->body, true)['logo']);
        self::assertSame('Edomea Store', json_decode($get('marchio')->body, true)['nome']);
        self::assertSame(200, $post('marchio/predefinito', '', $auth)->status);
        self::assertTrue(json_decode($get('marchio')->body, true)['predefinito']);

        $actions = $this->pdo->query("SELECT action FROM emp_core_audit WHERE action LIKE 'marchio.%' ORDER BY occurred_at")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['marchio.modifica', 'marchio.logo', 'marchio.logo_rimosso', 'marchio.predefinito'] as $a) {
            self::assertContains($a, $actions);
        }
    }

    public function testContrast(): void
    {
        self::assertGreaterThan(4.5, BrandService::contrastWithWhite('#1e3a5f'));
        self::assertLessThan(4.5, BrandService::contrastWithWhite('#ffee00'));
        self::assertEqualsWithDelta(21.0, BrandService::contrastWithWhite('#000000'), 0.01);
    }
}
