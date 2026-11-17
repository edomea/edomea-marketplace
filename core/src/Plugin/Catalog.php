<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

use Emp\Core\Module\Manifest;
use Emp\Core\Module\ManifestException;

/**
 * Catalogo dei plugin installabili dal Core: una cartella con l'indice
 * emp-packages.json e i pacchetti ZIP (ad esempio gli allegati di una release
 * GitHub approvata). Ogni pacchetto è verificato con l'impronta SHA-256
 * dell'indice prima di essere aperto.
 *
 * Il Core (emp-core) non si installa da qui: lo installa e aggiorna EMP Server (installa.sh).
 */
final class Catalog
{
    public const INDEX_FILE = 'emp-packages.json';
    private const MAX_INDEX_BYTES = 1048576;
    public const MAX_PACKAGE_BYTES = 268435456;

    public function __construct(private readonly ?string $dir)
    {
    }

    public function isConfigured(): bool
    {
        return $this->dir !== null && is_dir($this->dir);
    }

    /** @return list<array{id: string, version: string, file: string, sha256: string}> */
    public function entries(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        $path = $this->dir . DIRECTORY_SEPARATOR . self::INDEX_FILE;
        if (!is_file($path) || is_link($path) || (int) filesize($path) > self::MAX_INDEX_BYTES) {
            throw new PackageException('Indice del catalogo assente o non valido');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PackageException('Indice del catalogo non valido: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['packages'] ?? null)) {
            throw new PackageException('Indice del catalogo: schema non supportato');
        }
        $entries = [];
        foreach ($data['packages'] as $p) {
            if (!is_array($p)) {
                throw new PackageException('Indice del catalogo: voce non valida');
            }
            $id = $p['id'] ?? null;
            $file = $p['file'] ?? null;
            $sha = $p['sha256'] ?? null;
            $version = $p['version'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9-]{2,63}$/', $id)
                || !is_string($file) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.zip$/', $file)
                || !is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha)
                || !is_string($version)) {
                throw new PackageException('Indice del catalogo: voce non valida');
            }
            if ($id === Manifest::CORE_ID) {
                continue; // Il Core si aggiorna con installa.sh.
            }
            if (isset($entries[$id])) {
                throw new PackageException(sprintf('Indice del catalogo: plugin duplicato %s', $id));
            }
            $entries[$id] = ['id' => $id, 'version' => $version, 'file' => $file, 'sha256' => $sha];
        }
        ksort($entries);

        return array_values($entries);
    }

    /**
     * Verifica l'impronta e legge il manifest dal pacchetto.
     *
     * @param array{id: string, version: string, file: string, sha256: string} $entry
     */
    public function verify(array $entry): VerifiedPackage
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $entry['file'];
        if (!is_file($path) || is_link($path)) {
            throw new PackageException(sprintf('Pacchetto %s non trovato', $entry['file']));
        }
        if ((int) filesize($path) > self::MAX_PACKAGE_BYTES) {
            throw new PackageException(sprintf('Pacchetto %s troppo grande', $entry['file']));
        }
        $hash = (string) hash_file('sha256', $path);
        if (!hash_equals($entry['sha256'], $hash)) {
            throw new PackageException(sprintf('Pacchetto %s: impronta SHA-256 non corrispondente. Pacchetto rifiutato.', $entry['file']));
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new PackageException(sprintf('Pacchetto %s non è uno ZIP valido', $entry['file']));
        }
        try {
            $json = $zip->getFromName($entry['id'] . '/' . Manifest::FILE, 65536);
        } finally {
            $zip->close();
        }
        if (!is_string($json)) {
            throw new PackageException(sprintf('Pacchetto %s: manifest assente', $entry['file']));
        }
        try {
            $manifest = Manifest::fromJson($json, $entry['id']);
        } catch (ManifestException $e) {
            throw new PackageException(sprintf('Pacchetto %s: %s', $entry['file'], $e->getMessage()), 0, $e);
        }
        if ($manifest->id !== $entry['id'] || (string) $manifest->version !== $entry['version']) {
            throw new PackageException(sprintf('Pacchetto %s: id o versione diversi da quelli dell\'indice', $entry['file']));
        }

        return new VerifiedPackage($manifest, $path, $hash);
    }
}
