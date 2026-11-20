<?php

declare(strict_types=1);

namespace Emp\Plugin\Studio;

use Emp\Core\Access\User;
use Emp\Core\Http\ApiException;
use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;
use Emp\Core\Support\Uuid;

/**
 * Anagrafica prodotti di EMP Studio: prodotti, varianti, attributi, foto e documenti, struttura
 * del catalogo (categorie, marche, famiglie, attributi). Ogni modifica va nella tracciabilità del
 * Core con i valori prima e dopo. Le giacenze e i prezzi di vendita NON sono qui (Magazzino, Pricing).
 */
final class Catalog
{
    public const MODULE = 'emp-studio';
    public const STATES = ['bozza', 'attivo', 'archiviato'];
    public const TYPES = ['semplice', 'modello', 'variante'];
    public const VAT = ['22', '10', '5', '4', '0'];
    public const UNITS = ['pz', 'kg', 'g', 'l', 'm', 'mq', 'conf', 'servizio'];
    public const ENERGY = ['', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'A+++', 'A++', 'A+'];
    public const ATTRIBUTE_TYPES = ['testo', 'numero', 'si_no', 'elenco'];
    public const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_PHOTO = 8 * 1048576;
    public const MAX_DOCUMENT = 16 * 1048576;
    private const PAGE = 50;

    private readonly string $t;

    public function __construct(
        private readonly \PDO $pdo,
        string $tablePrefix,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly ?string $dataDir,
    ) {
        $this->t = $tablePrefix;
    }

    // ================================================================== prodotti

    /** @return array<string, int> */
    public function summary(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) AS totale,
                SUM(CASE WHEN stato = 'bozza' THEN 1 ELSE 0 END) AS bozze,
                SUM(CASE WHEN stato = 'attivo' THEN 1 ELSE 0 END) AS attivi,
                SUM(CASE WHEN stato <> 'archiviato' AND completezza = 100 THEN 1 ELSE 0 END) AS completi
            FROM {$this->t}prodotti WHERE tipo <> 'variante'")->fetch(\PDO::FETCH_ASSOC) ?: [];

        return array_map('intval', $row + ['totale' => 0, 'bozze' => 0, 'attivi' => 0, 'completi' => 0]);
    }

    /**
     * Ricerca a più parole su nome, codici, marca; filtri per categoria (con le sottocategorie), marca, famiglia, stato.
     *
     * @param array<string, string> $f
     * @return array{prodotti: list<array<string, mixed>>, totale: int, pagina: int, per_pagina: int}
     */
    public function search(array $f): array
    {
        $where = ["p.tipo <> 'variante'"];
        $args = [];
        $state = $f['stato'] ?? '';
        if ($state === '') {
            $where[] = "p.stato <> 'archiviato'";
        } elseif (in_array($state, self::STATES, true)) {
            $where[] = 'p.stato = ?';
            $args[] = $state;
        }
        foreach (preg_split('/\s+/', trim($f['q'] ?? '')) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($word)) . '%';
            // Le varianti si trovano anche per il loro EAN/SKU: si mostra il modello.
            $where[] = "(LOWER(p.nome) LIKE ? OR LOWER(COALESCE(p.sku, '')) LIKE ? OR COALESCE(p.ean, '') LIKE ? OR LOWER(p.mpn) LIKE ? OR LOWER(COALESCE(m.nome, '')) LIKE ?
                OR EXISTS (SELECT 1 FROM {$this->t}prodotti v WHERE v.modello_id = p.id AND (COALESCE(v.ean, '') LIKE ? OR LOWER(COALESCE(v.sku, '')) LIKE ?)))";
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        }
        if (($f['categoria'] ?? '') !== '') {
            $ids = $this->categoryAndChildren($f['categoria']);
            $where[] = 'p.categoria_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
        foreach (['marca' => 'p.marca_id', 'famiglia' => 'p.famiglia_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $where[] = $col . ' = ?';
                $args[] = $f[$k];
            }
        }
        if (($f['incompleti'] ?? '') === '1') {
            $where[] = 'p.completezza < 100';
        }
        $sql = "FROM {$this->t}prodotti p LEFT JOIN {$this->t}marche m ON m.id = p.marca_id
            LEFT JOIN {$this->t}categorie c ON c.id = p.categoria_id WHERE " . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) ' . $sql);
        $count->execute($args);
        $page = max(1, (int) ($f['pagina'] ?? 1));
        $stmt = $this->pdo->prepare("SELECT p.id, p.tipo, p.sku, p.ean, p.nome, p.stato, p.completezza, p.modificato_il, m.nome AS marca, c.nome AS categoria,
                (SELECT COUNT(*) FROM {$this->t}prodotti v WHERE v.modello_id = p.id) AS varianti,
                (SELECT percorso FROM {$this->t}media f WHERE f.prodotto_id = p.id AND f.tipo = 'foto' ORDER BY f.ordine LIMIT 1) AS foto
            " . $sql . ' ORDER BY p.modificato_il DESC LIMIT ' . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE));
        $stmt->execute($args);
        $rows = array_map(fn (array $r): array => [
            'id' => $r['id'], 'tipo' => $r['tipo'], 'sku' => $r['sku'], 'ean' => $r['ean'], 'nome' => $r['nome'], 'stato' => $r['stato'],
            'completezza' => (int) $r['completezza'], 'marca' => $r['marca'], 'categoria' => $r['categoria'], 'varianti' => (int) $r['varianti'],
            'foto' => $r['foto'] !== null ? $this->publicUrl((string) $r['foto']) : null, 'modificato_il' => $r['modificato_il'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        return ['prodotti' => $rows, 'totale' => (int) $count->fetchColumn(), 'pagina' => $page, 'per_pagina' => self::PAGE];
    }

    /** Ricerca esatta per codice (lettore di codici a barre): EAN (anche UPC), SKU o codice del produttore. */
    public function lookup(string $code): ?string
    {
        $code = trim($code);
        $ean = Ean::normalize($code);
        $stmt = $this->pdo->prepare("SELECT id, modello_id FROM {$this->t}prodotti WHERE ean = ? OR sku = ? OR (mpn <> '' AND mpn = ?) LIMIT 1");
        $stmt->execute([$ean ?? $code, $code, $code]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($r) ? (string) $r['id'] : null;
    }

    /** @return array<string, mixed> scheda completa: dati, attributi della famiglia, valori, media, varianti, completezza */
    public function product(string $id): array
    {
        $p = $this->row($id);
        $family = $p['famiglia_id'] !== null ? $this->familyAttributes((string) $p['famiglia_id']) : [];
        $values = $this->values($id);
        $model = $p['modello_id'] !== null ? $this->row((string) $p['modello_id']) : null;
        $variants = [];
        if ($p['tipo'] === 'modello') {
            $stmt = $this->pdo->prepare("SELECT id, sku, ean, nome, stato, completezza FROM {$this->t}prodotti WHERE modello_id = ? ORDER BY nome");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $v) {
                $v['completezza'] = (int) $v['completezza'];
                $v['valori'] = $this->values((string) $v['id']);
                $variants[] = $v;
            }
        }

        return [
            'prodotto' => $this->publicRow($p),
            'modello' => $model !== null ? ['id' => $model['id'], 'nome' => $model['nome']] : null,
            'attributi' => $family,
            'valori' => $values,
            'media' => $this->media($id),
            'varianti' => $variants,
            'mancanti' => $this->completeness($p)['mancanti'],
        ];
    }

    /**
     * Nuovo prodotto ($id null) o modifica. Campi: tipo, sku, ean, mpn, nome, marca_id, categoria_id, famiglia_id,
     * descrizione_breve, descrizione, iva, um, garanzia_mesi, classe_energetica, eprel, stato, valori {attributo_id: valore}.
     *
     * @param array<string, mixed> $in
     */
    public function save(User $user, ?string $id, array $in): string
    {
        $before = $id !== null ? $this->row($id) : null;
        $type = $before['tipo'] ?? (string) ($in['tipo'] ?? 'semplice');
        if (!in_array($type, ['semplice', 'modello'], true) && $before === null) {
            throw new ApiException('Tipo non valido: semplice o modello (le varianti si creano dal modello)');
        }
        $d = $this->validate($in, $type, $id);
        $now = $this->clock->nowForDatabase();
        $this->pdo->beginTransaction();
        try {
            if ($before === null) {
                $id = Uuid::v4();
                if ($type !== 'modello' && $d['sku'] === null) {
                    $d['sku'] = $this->nextSku();
                }
                $this->pdo->prepare("INSERT INTO {$this->t}prodotti (id, tipo, modello_id, sku, ean, ean_interno, mpn, nome, marca_id, categoria_id, famiglia_id,
                        descrizione_breve, descrizione, iva, um, garanzia_mesi, classe_energetica, eprel, stato, completezza, creato_il, creato_da, modificato_il, modificato_da)
                        VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)")
                    ->execute([$id, $type, $d['sku'], $d['ean'], $d['ean_interno'], $d['mpn'], $d['nome'], $d['marca_id'], $d['categoria_id'], $d['famiglia_id'],
                        $d['descrizione_breve'], $d['descrizione'], $d['iva'], $d['um'], $d['garanzia_mesi'], $d['classe_energetica'], $d['eprel'], $d['stato'],
                        $now, $user->username, $now, $user->username]);
            } else {
                if ($d['sku'] === null && $type !== 'modello') {
                    $d['sku'] = $before['sku'] ?? $this->nextSku();
                }
                $this->pdo->prepare("UPDATE {$this->t}prodotti SET sku = ?, ean = ?, ean_interno = ?, mpn = ?, nome = ?, marca_id = ?, categoria_id = ?, famiglia_id = ?,
                        descrizione_breve = ?, descrizione = ?, iva = ?, um = ?, garanzia_mesi = ?, classe_energetica = ?, eprel = ?, stato = ?, modificato_il = ?, modificato_da = ?
                        WHERE id = ?")
                    ->execute([$d['sku'], $d['ean'], $d['ean_interno'], $d['mpn'], $d['nome'], $d['marca_id'], $d['categoria_id'], $d['famiglia_id'],
                        $d['descrizione_breve'], $d['descrizione'], $d['iva'], $d['um'], $d['garanzia_mesi'], $d['classe_energetica'], $d['eprel'], $d['stato'],
                        $now, $user->username, $id]);
            }
            $valuesBefore = $before !== null ? $this->values((string) $id) : [];
            if (isset($in['valori']) && is_array($in['valori'])) {
                $this->saveValues((string) $id, $d['famiglia_id'], $in['valori']);
            }
            $this->refreshCompleteness((string) $id);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->duplicate($e);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $after = $this->row((string) $id);
        $this->trace($user, $before === null ? 'prodotto.creato' : 'prodotto.modificato', (string) $id,
            $before !== null ? $this->snapshot($before) + ['valori' => $valuesBefore] : null,
            $this->snapshot($after) + ['valori' => $this->values((string) $id)]);

        return (string) $id;
    }

    /** Variante di un modello: eredita marca, categoria, famiglia, testi, IVA e unità; ha codici e valori propri. */
    public function createVariant(User $user, string $modelId, array $in): string
    {
        $model = $this->row($modelId);
        if ($model['tipo'] !== 'modello') {
            throw new ApiException('Le varianti si aggiungono a un prodotto di tipo "modello"');
        }
        $data = [
            'nome' => trim((string) ($in['nome'] ?? '')) !== '' ? $in['nome'] : $model['nome'],
            'sku' => $in['sku'] ?? '', 'ean' => $in['ean'] ?? '', 'mpn' => $in['mpn'] ?? '',
            'marca_id' => $model['marca_id'], 'categoria_id' => $model['categoria_id'], 'famiglia_id' => $model['famiglia_id'],
            'descrizione_breve' => $model['descrizione_breve'], 'descrizione' => $model['descrizione'], 'iva' => $model['iva'], 'um' => $model['um'],
            'garanzia_mesi' => $model['garanzia_mesi'], 'classe_energetica' => $model['classe_energetica'], 'eprel' => $model['eprel'],
            'stato' => 'bozza', 'valori' => is_array($in['valori'] ?? null) ? $in['valori'] : [],
        ];
        $id = $this->save($user, null, $data + ['tipo' => 'semplice']);
        $this->pdo->prepare("UPDATE {$this->t}prodotti SET tipo = 'variante', modello_id = ? WHERE id = ?")->execute([$modelId, $id]);
        $this->refreshCompleteness($id);
        $this->trace($user, 'variante.creata', $id, null, ['modello' => $modelId, 'nome' => $data['nome']]);

        return $id;
    }

    /** Nuovo EAN interno (circolazione ristretta): non ancora assegnato, si usa salvando la scheda. */
    public function nextInternalEan(): string
    {
        $this->pdo->beginTransaction();
        try {
            do {
                $this->pdo->exec("UPDATE {$this->t}contatori SET valore = valore + 1 WHERE nome = 'ean_interno'");
                $n = (int) $this->pdo->query("SELECT valore FROM {$this->t}contatori WHERE nome = 'ean_interno'")->fetchColumn();
                $ean = Ean::internal($n);
                $used = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}prodotti WHERE ean = ?");
                $used->execute([$ean]);
            } while ((int) $used->fetchColumn() > 0);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $ean;
    }

    // ================================================================== foto, video, documenti

    /** Foto (JPEG, PNG, WebP) o documento (PDF) caricato dalla pagina. */
    public function upload(User $user, string $productId, string $bytes, string $mime, string $title): string
    {
        $this->row($productId);
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if (isset(self::PHOTO_TYPES[$mime])) {
            $info = @getimagesizefromstring($bytes);
            if (strlen($bytes) > self::MAX_PHOTO || !is_array($info) || image_type_to_mime_type($info[2]) !== $mime) {
                throw new ApiException('Foto non valida: JPEG, PNG o WebP fino a 8 MB');
            }
            [$kind, $ext, $folder] = ['foto', self::PHOTO_TYPES[$mime], 'foto'];
        } elseif ($mime === 'application/pdf') {
            if (strlen($bytes) > self::MAX_DOCUMENT || !str_starts_with($bytes, '%PDF-')) {
                throw new ApiException('Documento non valido: PDF fino a 16 MB');
            }
            [$kind, $ext, $folder] = ['documento', 'pdf', 'documenti'];
        } else {
            throw new ApiException('Formato non ammesso: foto JPEG, PNG, WebP o documenti PDF');
        }
        if ($this->dataDir === null) {
            throw new ApiException('Cartella dei file non disponibile su questo server', 503);
        }
        $id = Uuid::v4();
        $rel = $folder . '/' . substr($productId, 0, 2) . '/' . $id . '.' . $ext;
        $path = $this->dataDir . '/pubblico/' . $rel;
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new ApiException('Impossibile salvare il file', 500);
        }
        if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new ApiException('Impossibile salvare il file', 500);
        }
        @chmod($path, 0644);
        $this->insertMedia($user, $productId, $kind, $rel, $this->cleanTitle($title), $mime, strlen($bytes));

        return $id;
    }

    /** Video: collegamento (YouTube, Vimeo o sito del produttore), solo https. */
    public function addVideo(User $user, string $productId, string $url, string $title): string
    {
        $this->row($productId);
        $url = trim($url);
        if (!preg_match('#^https://[^\s<>"\']{4,500}$#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ApiException('Indirizzo del video non valido (deve iniziare con https://)');
        }

        return $this->insertMedia($user, $productId, 'video', $url, $this->cleanTitle($title), 'text/uri-list', 0);
    }

    public function removeMedia(User $user, string $mediaId): void
    {
        $m = $this->mediaRow($mediaId);
        $this->pdo->prepare("DELETE FROM {$this->t}media WHERE id = ?")->execute([$mediaId]);
        if ($m['tipo'] !== 'video' && $this->dataDir !== null) {
            @unlink($this->dataDir . '/pubblico/' . $m['percorso']);
        }
        $this->refreshCompleteness((string) $m['prodotto_id']);
        $this->touch($user, (string) $m['prodotto_id']);
        $this->trace($user, 'media.eliminato', (string) $m['prodotto_id'], $m['tipo'] . ' ' . $m['percorso'], null);
    }

    /** La foto diventa la principale (prima). */
    public function makeMain(User $user, string $mediaId): void
    {
        $m = $this->mediaRow($mediaId);
        $this->pdo->prepare("UPDATE {$this->t}media SET ordine = ordine + 1 WHERE prodotto_id = ? AND tipo = ?")->execute([$m['prodotto_id'], $m['tipo']]);
        $this->pdo->prepare("UPDATE {$this->t}media SET ordine = 0 WHERE id = ?")->execute([$mediaId]);
        $this->touch($user, (string) $m['prodotto_id']);
        $this->trace($user, 'media.principale', (string) $m['prodotto_id'], null, $m['percorso']);
    }

    // ================================================================== struttura del catalogo

    /** @return array<string, mixed> categorie (albero piatto con livello), marche, famiglie con attributi, attributi */
    public function structure(): array
    {
        $cats = $this->pdo->query("SELECT c.id, c.parent_id, c.nome, c.ordine, (SELECT COUNT(*) FROM {$this->t}prodotti p WHERE p.categoria_id = c.id) AS prodotti
            FROM {$this->t}categorie c ORDER BY c.ordine, c.nome")->fetchAll(\PDO::FETCH_ASSOC);
        $byParent = [];
        foreach ($cats as $c) {
            $byParent[(string) ($c['parent_id'] ?? '')][] = $c;
        }
        $tree = [];
        $walk = function (string $parent, int $level, string $path) use (&$walk, &$tree, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $c) {
                $full = $path === '' ? $c['nome'] : $path . ' › ' . $c['nome'];
                $tree[] = ['id' => $c['id'], 'parent_id' => $c['parent_id'], 'nome' => $c['nome'], 'percorso' => $full, 'livello' => $level, 'prodotti' => (int) $c['prodotti']];
                if ($level < 6) {
                    $walk((string) $c['id'], $level + 1, $full);
                }
            }
        };
        $walk('', 0, '');
        $brands = $this->pdo->query("SELECT b.id, b.nome, (SELECT COUNT(*) FROM {$this->t}prodotti p WHERE p.marca_id = b.id) AS prodotti
            FROM {$this->t}marche b ORDER BY b.nome")->fetchAll(\PDO::FETCH_ASSOC);
        $attributes = [];
        foreach ($this->pdo->query("SELECT id, codice, nome, tipo, unita, opzioni FROM {$this->t}attributi ORDER BY nome")->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $a['opzioni'] = json_decode((string) $a['opzioni'], true) ?: [];
            $attributes[] = $a;
        }
        $families = [];
        foreach ($this->pdo->query("SELECT f.id, f.codice, f.nome, (SELECT COUNT(*) FROM {$this->t}prodotti p WHERE p.famiglia_id = f.id) AS prodotti
            FROM {$this->t}famiglie f ORDER BY f.nome")->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $f['prodotti'] = (int) $f['prodotti'];
            $f['attributi'] = array_map(static fn (array $x): array => ['id' => $x['id'], 'obbligatorio' => $x['obbligatorio']], $this->familyAttributes((string) $f['id']));
            $families[] = $f;
        }

        return [
            'categorie' => $tree,
            'marche' => array_map(static fn (array $b): array => ['id' => $b['id'], 'nome' => $b['nome'], 'prodotti' => (int) $b['prodotti']], $brands),
            'famiglie' => $families,
            'attributi' => $attributes,
            'iva' => self::VAT, 'unita' => self::UNITS, 'classi_energetiche' => array_values(array_filter(self::ENERGY)),
        ];
    }

    public function saveCategory(User $user, ?string $id, string $name, ?string $parent): string
    {
        $name = $this->cleanName($name, 'Nome della categoria');
        $parent = $parent !== null && $parent !== '' ? $parent : null;
        if ($parent !== null) {
            $this->exists('categorie', $parent, 'Categoria superiore non trovata');
            if ($id !== null && in_array($parent, $this->categoryAndChildren($id), true)) {
                throw new ApiException('Una categoria non può stare dentro sé stessa o una sua sottocategoria');
            }
        }
        $now = $this->clock->nowForDatabase();
        if ($id === null) {
            $id = Uuid::v4();
            $order = (int) $this->pdo->query("SELECT COALESCE(MAX(ordine), 0) + 1 FROM {$this->t}categorie")->fetchColumn();
            $this->pdo->prepare("INSERT INTO {$this->t}categorie (id, parent_id, nome, ordine, modificato_il, modificato_da) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$id, $parent, $name, $order, $now, $user->username]);
            $this->trace($user, 'categoria.creata', $id, null, $name);
        } else {
            $old = $this->exists('categorie', $id, 'Categoria non trovata');
            $this->pdo->prepare("UPDATE {$this->t}categorie SET nome = ?, parent_id = ?, modificato_il = ?, modificato_da = ? WHERE id = ?")
                ->execute([$name, $parent, $now, $user->username, $id]);
            $this->trace($user, 'categoria.modificata', $id, $old['nome'], $name);
        }

        return $id;
    }

    public function saveBrand(User $user, ?string $id, string $name): string
    {
        $name = $this->cleanName($name, 'Nome della marca');
        $now = $this->clock->nowForDatabase();
        try {
            if ($id === null) {
                $id = Uuid::v4();
                $this->pdo->prepare("INSERT INTO {$this->t}marche (id, nome, modificato_il, modificato_da) VALUES (?, ?, ?, ?)")->execute([$id, $name, $now, $user->username]);
                $this->trace($user, 'marca.creata', $id, null, $name);
            } else {
                $old = $this->exists('marche', $id, 'Marca non trovata');
                $this->pdo->prepare("UPDATE {$this->t}marche SET nome = ?, modificato_il = ?, modificato_da = ? WHERE id = ?")->execute([$name, $now, $user->username, $id]);
                $this->trace($user, 'marca.modificata', $id, $old['nome'], $name);
            }
        } catch (\PDOException $e) {
            throw $this->duplicate($e, 'Esiste già una marca con questo nome');
        }

        return $id;
    }

    /** @param list<string> $options */
    public function saveAttribute(User $user, ?string $id, string $name, string $type, string $unit, array $options): string
    {
        $name = $this->cleanName($name, 'Nome dell\'attributo');
        if (!in_array($type, self::ATTRIBUTE_TYPES, true)) {
            throw new ApiException('Tipo di attributo non valido: testo, numero, si_no, elenco');
        }
        $unit = trim($unit);
        if (mb_strlen($unit) > 16 || preg_match('/[<>]/', $unit)) {
            throw new ApiException('Unità di misura non valida');
        }
        $options = array_values(array_unique(array_filter(array_map(static fn ($o): string => trim((string) $o), $options), static fn (string $o): bool => $o !== '')));
        if ($type === 'elenco' && $options === []) {
            throw new ApiException('Un attributo a elenco ha bisogno delle sue voci');
        }
        foreach ($options as $o) {
            if (mb_strlen($o) > 80 || preg_match('/[<>]/', $o)) {
                throw new ApiException('Voce dell\'elenco non valida: ' . $o);
            }
        }
        $now = $this->clock->nowForDatabase();
        $json = (string) json_encode($type === 'elenco' ? $options : [], JSON_UNESCAPED_UNICODE);
        if ($id === null) {
            $id = Uuid::v4();
            $code = $this->code('attributi', $name);
            $this->pdo->prepare("INSERT INTO {$this->t}attributi (id, codice, nome, tipo, unita, opzioni, modificato_il, modificato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$id, $code, $name, $type, $unit, $json, $now, $user->username]);
            $this->trace($user, 'attributo.creato', $id, null, $name . ' (' . $type . ')');
        } else {
            $old = $this->exists('attributi', $id, 'Attributo non trovato');
            $this->pdo->prepare("UPDATE {$this->t}attributi SET nome = ?, tipo = ?, unita = ?, opzioni = ?, modificato_il = ?, modificato_da = ? WHERE id = ?")
                ->execute([$name, $type, $unit, $json, $now, $user->username, $id]);
            $this->trace($user, 'attributo.modificato', $id, $old['nome'] . ' (' . $old['tipo'] . ')', $name . ' (' . $type . ')');
        }

        return $id;
    }

    /** @param list<array{id: string, obbligatorio: bool}> $attributes */
    public function saveFamily(User $user, ?string $id, string $name, array $attributes): string
    {
        $name = $this->cleanName($name, 'Nome della famiglia');
        $now = $this->clock->nowForDatabase();
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $id = Uuid::v4();
                $this->pdo->prepare("INSERT INTO {$this->t}famiglie (id, codice, nome, modificato_il, modificato_da) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$id, $this->code('famiglie', $name), $name, $now, $user->username]);
                $before = null;
            } else {
                $old = $this->exists('famiglie', $id, 'Famiglia non trovata');
                $before = $old['nome'] . ': ' . json_encode($this->familyAttributes($id));
                $this->pdo->prepare("UPDATE {$this->t}famiglie SET nome = ?, modificato_il = ?, modificato_da = ? WHERE id = ?")->execute([$name, $now, $user->username, $id]);
                $this->pdo->prepare("DELETE FROM {$this->t}famiglie_attributi WHERE famiglia_id = ?")->execute([$id]);
            }
            $ins = $this->pdo->prepare("INSERT INTO {$this->t}famiglie_attributi (famiglia_id, attributo_id, obbligatorio, ordine) VALUES (?, ?, ?, ?)");
            $seen = [];
            foreach (array_values($attributes) as $i => $a) {
                $aid = (string) ($a['id'] ?? '');
                if (isset($seen[$aid])) {
                    continue;
                }
                $this->exists('attributi', $aid, 'Attributo non trovato');
                $seen[$aid] = true;
                $ins->execute([$id, $aid, !empty($a['obbligatorio']) ? 1 : 0, $i + 1]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        // Gli obbligatori cambiano: si ricalcola la completezza dei prodotti della famiglia.
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->t}prodotti WHERE famiglia_id = ?");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $pid) {
            $this->refreshCompleteness((string) $pid);
        }
        $this->trace($user, $before === null ? 'famiglia.creata' : 'famiglia.modificata', $id, $before, $name . ': ' . json_encode($this->familyAttributes($id)));

        return $id;
    }

    /** Elimina categoria, marca, famiglia o attributo solo se nessun prodotto li usa. */
    public function deleteStructure(User $user, string $kind, string $id): void
    {
        $map = ['categorie' => 'categoria_id', 'marche' => 'marca_id', 'famiglie' => 'famiglia_id'];
        $row = $this->exists($kind, $id, 'Elemento non trovato');
        if (isset($map[$kind])) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}prodotti WHERE {$map[$kind]} = ?");
            $stmt->execute([$id]);
            if (($n = (int) $stmt->fetchColumn()) > 0) {
                throw new ApiException(sprintf('"%s" è usato da %d prodotti: spostarli prima', $row['nome'], $n), 409);
            }
        }
        if ($kind === 'categorie') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}categorie WHERE parent_id = ?");
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new ApiException('La categoria ha delle sottocategorie: spostarle o eliminarle prima', 409);
            }
        }
        if ($kind === 'attributi') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}famiglie_attributi WHERE attributo_id = ?");
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new ApiException('L\'attributo è in una o più famiglie: toglierlo prima da lì', 409);
            }
            $this->pdo->prepare("DELETE FROM {$this->t}valori WHERE attributo_id = ?")->execute([$id]);
        }
        if ($kind === 'famiglie') {
            $this->pdo->prepare("DELETE FROM {$this->t}famiglie_attributi WHERE famiglia_id = ?")->execute([$id]);
        }
        $this->pdo->prepare("DELETE FROM {$this->t}{$kind} WHERE id = ?")->execute([$id]);
        $this->trace($user, $kind . '.eliminato', $id, (string) $row['nome'], null);
    }

    // ================================================================== interni

    /** @return array<string, mixed> */
    private function validate(array $in, string $type, ?string $id): array
    {
        $str = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $d = [];
        $d['nome'] = $this->cleanName((string) ($in['nome'] ?? ''), 'Nome del prodotto', 200);
        $sku = strtoupper($str('sku', 64));
        if ($sku !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,63}$/', $sku)) {
            throw new ApiException('Codice articolo (SKU) non valido: lettere, cifre, . _ / -');
        }
        $d['sku'] = $type === 'modello' ? null : ($sku !== '' ? $sku : null);
        $eanIn = $str('ean', 20);
        $d['ean'] = null;
        $d['ean_interno'] = 0;
        if ($type !== 'modello' && $eanIn !== '') {
            $ean = Ean::normalize($eanIn);
            if ($ean === null) {
                throw new ApiException('EAN non valido: controllare le cifre (EAN-13, EAN-8 o UPC-A)');
            }
            $d['ean'] = $ean;
            $d['ean_interno'] = Ean::isInternal($ean) ? 1 : 0;
        }
        $d['mpn'] = $str('mpn', 64);
        if (preg_match('/[<>\x00-\x1F]/', $d['mpn'])) {
            throw new ApiException('Codice del produttore non valido');
        }
        foreach (['marca_id' => 'marche', 'categoria_id' => 'categorie', 'famiglia_id' => 'famiglie'] as $k => $table) {
            $v = $str($k, 36);
            $d[$k] = $v !== '' ? (string) $this->exists($table, $v, 'Riferimento non trovato: ' . $k)['id'] : null;
        }
        $d['descrizione_breve'] = $str('descrizione_breve', 1000);
        $d['descrizione'] = $str('descrizione', 20000);
        $d['iva'] = in_array((string) ($in['iva'] ?? '22'), self::VAT, true) ? (string) ($in['iva'] ?? '22') : throw new ApiException('Aliquota IVA non valida');
        $d['um'] = in_array((string) ($in['um'] ?? 'pz'), self::UNITS, true) ? (string) ($in['um'] ?? 'pz') : throw new ApiException('Unità di misura non valida');
        $g = $in['garanzia_mesi'] ?? null;
        $d['garanzia_mesi'] = $g === null || $g === '' ? null : ((is_numeric($g) && (int) $g >= 0 && (int) $g <= 120) ? (int) $g : throw new ApiException('Garanzia non valida (mesi, da 0 a 120)'));
        $energy = strtoupper($str('classe_energetica', 8));
        $d['classe_energetica'] = in_array($energy, self::ENERGY, true) ? $energy : throw new ApiException('Classe energetica non valida');
        $eprel = $str('eprel', 20);
        $d['eprel'] = $eprel === '' || preg_match('/^\d{3,12}$/', $eprel) ? $eprel : throw new ApiException('Numero di registrazione EPREL non valido (solo cifre)');
        $state = (string) ($in['stato'] ?? 'bozza');
        $d['stato'] = in_array($state, self::STATES, true) ? $state : throw new ApiException('Stato non valido');

        return $d;
    }

    /** @param array<string, mixed> $values attributo_id => valore */
    private function saveValues(string $productId, ?string $familyId, array $values): void
    {
        $allowed = [];
        foreach ($familyId !== null ? $this->familyAttributes($familyId) : [] as $a) {
            $allowed[$a['id']] = $a;
        }
        $del = $this->pdo->prepare("DELETE FROM {$this->t}valori WHERE prodotto_id = ? AND attributo_id = ?");
        $ins = $this->pdo->prepare("INSERT INTO {$this->t}valori (prodotto_id, attributo_id, valore) VALUES (?, ?, ?)");
        foreach ($values as $aid => $raw) {
            $a = $allowed[(string) $aid] ?? null;
            if ($a === null) {
                continue; // attributo non (più) della famiglia: ignorato
            }
            $v = trim(is_bool($raw) ? ($raw ? 'si' : 'no') : (string) $raw);
            $del->execute([$productId, $aid]);
            if ($v === '') {
                continue;
            }
            $v = match ($a['tipo']) {
                'numero' => is_numeric(str_replace(',', '.', $v)) ? rtrim(rtrim(number_format((float) str_replace(',', '.', $v), 3, '.', ''), '0'), '.')
                    : throw new ApiException($a['nome'] . ': serve un numero'),
                'si_no' => in_array($v, ['si', 'no'], true) ? $v : throw new ApiException($a['nome'] . ': sì o no'),
                'elenco' => in_array($v, $a['opzioni'], true) ? $v : throw new ApiException($a['nome'] . ': scegliere una voce dell\'elenco'),
                default => mb_strlen($v) <= 500 && !preg_match('/[<>]/', $v) ? $v : throw new ApiException($a['nome'] . ': testo non valido'),
            };
            $ins->execute([$productId, $aid, $v]);
        }
    }

    /**
     * Completezza della scheda: campi base + attributi obbligatori della famiglia + almeno una foto.
     *
     * @param array<string, mixed> $p
     * @return array{percentuale: int, mancanti: list<string>}
     */
    private function completeness(array $p): array
    {
        $missing = [];
        $checks = [
            'Nome' => $p['nome'] !== '',
            'Marca' => $p['marca_id'] !== null,
            'Categoria' => $p['categoria_id'] !== null,
            'Famiglia' => $p['famiglia_id'] !== null,
            'Descrizione breve' => trim((string) $p['descrizione_breve']) !== '',
        ];
        if ($p['tipo'] !== 'modello') {
            $checks['EAN'] = $p['ean'] !== null;
        }
        $photoOwner = $p['tipo'] === 'variante' && $p['modello_id'] !== null ? [(string) $p['id'], (string) $p['modello_id']] : [(string) $p['id']];
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}media WHERE tipo = 'foto' AND prodotto_id IN (" . implode(',', array_fill(0, count($photoOwner), '?')) . ')');
        $stmt->execute($photoOwner);
        $checks['Almeno una foto'] = (int) $stmt->fetchColumn() > 0;
        if ($p['famiglia_id'] !== null) {
            $values = $this->values((string) $p['id']);
            $modelValues = $p['modello_id'] !== null ? $this->values((string) $p['modello_id']) : [];
            foreach ($this->familyAttributes((string) $p['famiglia_id']) as $a) {
                if ($a['obbligatorio']) {
                    $checks[$a['nome']] = ($values[$a['id']] ?? $modelValues[$a['id']] ?? '') !== '';
                }
            }
        }
        foreach ($checks as $label => $ok) {
            if (!$ok) {
                $missing[] = $label;
            }
        }

        return ['percentuale' => (int) floor((count($checks) - count($missing)) * 100 / max(1, count($checks))), 'mancanti' => $missing];
    }

    private function refreshCompleteness(string $id): void
    {
        $p = $this->row($id);
        $this->pdo->prepare("UPDATE {$this->t}prodotti SET completezza = ? WHERE id = ?")->execute([$this->completeness($p)['percentuale'], $id]);
        if ($p['tipo'] === 'modello') {
            $stmt = $this->pdo->prepare("SELECT id FROM {$this->t}prodotti WHERE modello_id = ?");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $v) {
                $vr = $this->row((string) $v);
                $this->pdo->prepare("UPDATE {$this->t}prodotti SET completezza = ? WHERE id = ?")->execute([$this->completeness($vr)['percentuale'], $v]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function familyAttributes(string $familyId): array
    {
        $stmt = $this->pdo->prepare("SELECT a.id, a.codice, a.nome, a.tipo, a.unita, a.opzioni, fa.obbligatorio FROM {$this->t}famiglie_attributi fa
            JOIN {$this->t}attributi a ON a.id = fa.attributo_id WHERE fa.famiglia_id = ? ORDER BY fa.ordine, a.nome");
        $stmt->execute([$familyId]);

        return array_map(static fn (array $a): array => [
            'id' => (string) $a['id'], 'codice' => (string) $a['codice'], 'nome' => (string) $a['nome'], 'tipo' => (string) $a['tipo'],
            'unita' => (string) $a['unita'], 'opzioni' => json_decode((string) $a['opzioni'], true) ?: [], 'obbligatorio' => (int) $a['obbligatorio'] === 1,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string, string> */
    private function values(string $productId): array
    {
        $stmt = $this->pdo->prepare("SELECT attributo_id, valore FROM {$this->t}valori WHERE prodotto_id = ?");
        $stmt->execute([$productId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['attributo_id']] = (string) $r['valore'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function media(string $productId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->t}media WHERE prodotto_id = ? ORDER BY tipo, ordine, creato_il");
        $stmt->execute([$productId]);

        return array_map(fn (array $m): array => [
            'id' => $m['id'], 'tipo' => $m['tipo'], 'titolo' => $m['titolo'], 'mime' => $m['mime'], 'byte' => (int) $m['byte'],
            'url' => $m['tipo'] === 'video' ? $m['percorso'] : $this->publicUrl((string) $m['percorso']),
            'creato_il' => $m['creato_il'], 'creato_da' => $m['creato_da'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function insertMedia(User $user, string $productId, string $kind, string $path, string $title, string $mime, int $bytes): string
    {
        $id = Uuid::v4();
        $order = $this->pdo->prepare("SELECT COALESCE(MAX(ordine), -1) + 1 FROM {$this->t}media WHERE prodotto_id = ? AND tipo = ?");
        $order->execute([$productId, $kind]);
        $this->pdo->prepare("INSERT INTO {$this->t}media (id, prodotto_id, tipo, percorso, titolo, mime, byte, ordine, creato_il, creato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$id, $productId, $kind, $path, $title, $mime, $bytes, (int) $order->fetchColumn(), $this->clock->nowForDatabase(), $user->username]);
        $this->refreshCompleteness($productId);
        $this->touch($user, $productId);
        $this->trace($user, 'media.aggiunto', $productId, null, $kind . ' ' . $path . ($title !== '' ? ' — ' . $title : ''));

        return $id;
    }

    /** @return array<string, mixed> */
    private function mediaRow(string $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->t}media WHERE id = ?");
        $stmt->execute([$id]);
        $m = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($m)) {
            throw new ApiException('File non trovato', 404);
        }

        return $m;
    }

    private function publicUrl(string $rel): string
    {
        return '/m-file/' . self::MODULE . '/' . $rel;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->t}prodotti WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            throw new ApiException('Prodotto non trovato', 404);
        }

        return $r;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function publicRow(array $r): array
    {
        $r['completezza'] = (int) $r['completezza'];
        $r['ean_interno'] = (int) $r['ean_interno'] === 1;
        $r['garanzia_mesi'] = $r['garanzia_mesi'] !== null ? (int) $r['garanzia_mesi'] : null;

        return $r;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function snapshot(array $r): array
    {
        $keep = ['tipo', 'sku', 'ean', 'mpn', 'nome', 'marca_id', 'categoria_id', 'famiglia_id', 'iva', 'um', 'garanzia_mesi', 'classe_energetica', 'eprel', 'stato'];
        $out = array_intersect_key($r, array_flip($keep));
        $out['descrizione_breve'] = mb_substr((string) $r['descrizione_breve'], 0, 300);
        $out['descrizione_hash'] = substr(hash('sha256', (string) $r['descrizione']), 0, 12);

        return $out;
    }

    private function trace(User $user, string $action, string $id, mixed $before, mixed $after): void
    {
        $enc = static fn (mixed $v): ?string => $v === null ? null : (is_string($v) ? $v : (json_encode($v, JSON_UNESCAPED_UNICODE) ?: null));
        $this->audit->record($user->username, self::MODULE, $action, explode('.', $action)[0], $id, 0, $enc($before), $enc($after));
    }

    private function touch(User $user, string $productId): void
    {
        $this->pdo->prepare("UPDATE {$this->t}prodotti SET modificato_il = ?, modificato_da = ? WHERE id = ?")
            ->execute([$this->clock->nowForDatabase(), $user->username, $productId]);
    }

    private function nextSku(): string
    {
        $this->pdo->exec("UPDATE {$this->t}contatori SET valore = valore + 1 WHERE nome = 'sku'");
        $n = $this->pdo->query("SELECT valore FROM {$this->t}contatori WHERE nome = 'sku'")->fetchColumn();
        if ($n === false) {
            $this->pdo->exec("INSERT INTO {$this->t}contatori (nome, valore) VALUES ('sku', 1)");
            $n = 1;
        }
        do {
            $sku = 'P' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $used = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}prodotti WHERE sku = ?");
            $used->execute([$sku]);
            $taken = (int) $used->fetchColumn() > 0;
            if ($taken) {
                $this->pdo->exec("UPDATE {$this->t}contatori SET valore = valore + 1 WHERE nome = 'sku'");
                $n = (int) $n + 1;
            }
        } while ($taken);

        return $sku;
    }

    /** @return list<string> */
    private function categoryAndChildren(string $id): array
    {
        $all = $this->pdo->query("SELECT id, parent_id FROM {$this->t}categorie")->fetchAll(\PDO::FETCH_ASSOC);
        $out = [$id];
        for ($i = 0; $i < count($out) && $i < 1000; $i++) {
            foreach ($all as $c) {
                if ($c['parent_id'] === $out[$i] && !in_array($c['id'], $out, true)) {
                    $out[] = (string) $c['id'];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function exists(string $table, string $id, string $message): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->t}{$table} WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            throw new ApiException($message, 404);
        }

        return $r;
    }

    private function code(string $table, string $name): string
    {
        $ascii = function_exists('iconv') ? (@iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name) : $name;
        $base = trim(substr(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $ascii)), 0, 50), '_') ?: 'voce';
        $code = $base;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->t}{$table} WHERE codice = ?");
        for ($i = 2; $stmt->execute([$code]) && (int) $stmt->fetchColumn() > 0; $i++) {
            $code = $base . '_' . $i;
        }

        return $code;
    }

    private function cleanName(string $name, string $what, int $max = 120): string
    {
        $n = trim($name);
        if ($n === '' || mb_strlen($n) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $n)) {
            throw new ApiException($what . ': obbligatorio, massimo ' . $max . ' caratteri, senza < e >');
        }

        return $n;
    }

    private function cleanTitle(string $title): string
    {
        $t = trim($title);

        return mb_strlen($t) <= 200 && !preg_match('/[\x00-\x1F<>]/', $t) ? $t : throw new ApiException('Titolo non valido');
    }

    private function duplicate(\PDOException $e, string $message = ''): \Throwable
    {
        $text = $e->getMessage();
        if (str_contains($text, 'UNIQUE') || str_contains($text, 'Duplicate') || ($e->errorInfo[1] ?? 0) === 1062) {
            if ($message !== '') {
                return new ApiException($message, 409);
            }
            if (str_contains($text, 'ean')) {
                return new ApiException('Questo EAN è già usato da un altro prodotto', 409);
            }

            return new ApiException('Questo codice articolo (SKU) è già usato da un altro prodotto', 409);
        }

        return $e;
    }
}
