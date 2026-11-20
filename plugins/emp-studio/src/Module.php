<?php

declare(strict_types=1);

namespace Emp\Plugin\Studio;

use Emp\Core\Http\Api;
use Emp\Core\Http\ApiException;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Kernel;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ModuleInterface;

/**
 * EMP Studio — schede anagrafiche dei prodotti. Pagina: /m/emp-studio/index.html.
 *
 * Permessi: studio.vedi (consultare), studio.modifica (schede, foto, varianti),
 * studio.configura (categorie, marche, famiglie, attributi). Gli amministratori li hanno tutti.
 *
 *   GET  /api/v1/studio/riepilogo · /permessi
 *   GET  /api/v1/studio/prodotti?q=&categoria=&marca=&famiglia=&stato=&incompleti=1&pagina=
 *   GET  /api/v1/studio/cerca?codice=           EAN, SKU o codice del produttore (lettore di codici a barre)
 *   GET  /api/v1/studio/prodotti/{id}
 *   POST /api/v1/studio/prodotti                nuova scheda
 *   POST /api/v1/studio/prodotti/{id}           modifica
 *   POST /api/v1/studio/prodotti/{id}/variante  nuova variante di un modello
 *   GET  /api/v1/studio/ean-interno             nuovo EAN interno (209…)
 *   POST /api/v1/studio/media?prodotto=&titolo= foto (JPEG/PNG/WebP) o PDF nel corpo
 *   POST /api/v1/studio/video                   {prodotto, url, titolo}
 *   POST /api/v1/studio/media/{id}/principale · /elimina
 *   GET  /api/v1/studio/struttura
 *   POST /api/v1/studio/{categorie|marche|attributi|famiglie}[/{id}] · /{id}/elimina
 */
final class Module implements ModuleInterface
{
    public function register(Kernel $kernel, Manifest $manifest): void
    {
        $r = $kernel->router;
        $id = $manifest->id;
        $catalog = static function () use ($kernel, $manifest): Catalog {
            try {
                $dir = $kernel->moduleDataDir($manifest->id);
            } catch (\RuntimeException) {
                $dir = null;
            }

            return new Catalog($kernel->pdo(), $kernel->tablePrefix($manifest), $kernel->clock(), $kernel->audit(), $dir);
        };
        $get = static fn (string $path, string $perm, \Closure $fn) => $r->add('GET', '/api/v1/studio' . $path, $id,
            static fn (Request $q): Response => Api::run(static fn (): Response => Response::json($fn($catalog(), Api::user($kernel, $q, $perm), $q))));
        $post = static fn (string $path, string $perm, \Closure $fn) => $r->add('POST', '/api/v1/studio' . $path, $id,
            static fn (Request $q): Response => Api::run(static fn (): Response => Response::json(['ok' => true] + $fn($catalog(), Api::user($kernel, $q, $perm), $q))));

        $get('/riepilogo', 'studio.vedi', static fn (Catalog $c) => $c->summary());
        $r->add('GET', '/api/v1/studio/permessi', $id, static fn (Request $q): Response => Api::run(static function () use ($kernel, $q): Response {
            $user = Api::user($kernel, $q, 'studio.vedi');
            $roles = $kernel->jobRoles();

            return Response::json(['modifica' => $roles->allows($user, 'studio.modifica'), 'configura' => $roles->allows($user, 'studio.configura')]);
        }));
        $get('/prodotti', 'studio.vedi', static fn (Catalog $c, $u, Request $q) => $c->search(array_map('strval', $q->query)));
        $get('/cerca', 'studio.vedi', static fn (Catalog $c, $u, Request $q) => ['id' => $c->lookup((string) ($q->query['codice'] ?? ''))]);
        $get('/prodotti/{id}', 'studio.vedi', static fn (Catalog $c, $u, Request $q) => $c->product($q->param('id')));
        $post('/prodotti', 'studio.modifica', static fn (Catalog $c, $u, Request $q) => ['id' => $c->save($u, null, $q->json()), 'messaggio' => 'Scheda creata.']);
        $post('/prodotti/{id}', 'studio.modifica', static fn (Catalog $c, $u, Request $q) => ['id' => $c->save($u, $q->param('id'), $q->json()), 'messaggio' => 'Scheda salvata.']);
        $post('/prodotti/{id}/variante', 'studio.modifica', static fn (Catalog $c, $u, Request $q) => ['id' => $c->createVariant($u, $q->param('id'), $q->json()), 'messaggio' => 'Variante creata.']);
        $get('/ean-interno', 'studio.modifica', static fn (Catalog $c) => ['ean' => $c->nextInternalEan()]);

        $post('/media', 'studio.modifica', static function (Catalog $c, $u, Request $q): array {
            $product = (string) ($q->query['prodotto'] ?? '');
            if ($product === '') {
                throw new ApiException('Prodotto mancante');
            }

            // Corpo completo (fino a 16 MB; $q->body ne contiene solo i primi 64 KiB).
            $in = $q->openBody();
            $bytes = (string) stream_get_contents($in, Catalog::MAX_DOCUMENT + 1);
            fclose($in);

            return ['id' => $c->upload($u, $product, $bytes, (string) $q->header('content-type'), (string) ($q->query['titolo'] ?? '')), 'messaggio' => 'File caricato.'];
        });
        $post('/video', 'studio.modifica', static fn (Catalog $c, $u, Request $q) => ['id' => $c->addVideo($u, $q->input('prodotto'), $q->input('url'), $q->input('titolo')), 'messaggio' => 'Video aggiunto.']);
        $post('/media/{id}/principale', 'studio.modifica', static function (Catalog $c, $u, Request $q): array {
            $c->makeMain($u, $q->param('id'));

            return ['messaggio' => 'Foto principale aggiornata.'];
        });
        $post('/media/{id}/elimina', 'studio.modifica', static function (Catalog $c, $u, Request $q): array {
            $c->removeMedia($u, $q->param('id'));

            return ['messaggio' => 'File eliminato.'];
        });

        $get('/struttura', 'studio.vedi', static fn (Catalog $c) => $c->structure());
        foreach (['categorie', 'marche', 'attributi', 'famiglie'] as $kind) {
            $save = static function (Catalog $c, $u, Request $q, ?string $itemId) use ($kind): array {
                $j = $q->json();
                $newId = match ($kind) {
                    'categorie' => $c->saveCategory($u, $itemId, $q->input('nome'), (string) ($j['parent_id'] ?? '')),
                    'marche' => $c->saveBrand($u, $itemId, $q->input('nome')),
                    'attributi' => $c->saveAttribute($u, $itemId, $q->input('nome'), $q->input('tipo'), $q->input('unita'), is_array($j['opzioni'] ?? null) ? $j['opzioni'] : []),
                    'famiglie' => $c->saveFamily($u, $itemId, $q->input('nome'), is_array($j['attributi'] ?? null) ? $j['attributi'] : []),
                };

                return ['id' => $newId, 'messaggio' => 'Salvato.'];
            };
            $post('/' . $kind, 'studio.configura', static fn (Catalog $c, $u, Request $q) => $save($c, $u, $q, null));
            $post('/' . $kind . '/{id}', 'studio.configura', static fn (Catalog $c, $u, Request $q) => $save($c, $u, $q, $q->param('id')));
            $post('/' . $kind . '/{id}/elimina', 'studio.configura', static function (Catalog $c, $u, Request $q) use ($kind): array {
                $c->deleteStructure($u, $kind, $q->param('id'));

                return ['messaggio' => 'Eliminato.'];
            });
        }
    }
}
