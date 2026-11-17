<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

use Emp\Core\Module\Manifest;
use Emp\Core\Module\ManifestException;

/**
 * Catalogo locale, scrivibile dal Core: contiene i pacchetti dei plugin
 * caricati a mano dal Tecnico e quelli importati da una release GitHub.
 *
 * Stesso formato del catalogo di EMP Server (emp-packages.json + ZIP), con in
 * più l'origine di ogni pacchetto. Il catalogo di EMP Server (di root) resta
 * separato e non viene mai modificato dal Core.
 *
 * Un pacchetto entra solo dopo i controlli: un'unica cartella <id>/ alla radice,
 * manifest valido, nessun percorso pericoloso, non il Core. Una versione già
 * presente con contenuto diverso è rifiutata: per cambiare un plugin si
 * aumenta la versione.
 */
final class LocalCatalog
{
    public const MAX_UPLOAD_BYTES = 67108864; // 64 MiB
    private const ORIGIN_PATTERN = '/^[\p{L}\p{N} .,:@\/_()+-]{1,160}$/u';

    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function isWritable(): bool
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    /** Lettore del catalogo (stessa verifica del catalogo di EMP Server). */
    public function reader(): Catalog
    {
        return new Catalog(is_file($this->indexPath()) ? $this->dir : null);
    }

    /** @return array<string, string> id => origine */
    public function origins(): array
    {
        $out = [];
        foreach ($this->readIndex() as $p) {
            $out[$p['id']] = (string) ($p['origine'] ?? 'Catalogo locale');
        }

        return $out;
    }

    /**
     * Aggiunge un pacchetto da un file ZIP (copiato, l'originale non viene toccato).
     *
     * @param string|null $expectedSha256 impronta comunicata insieme al pacchetto (facoltativa)
     * @return array{id: string, version: string, file: string, sha256: string, origine: string, nuovo: bool}
     */
    public function add(string $zipPath, string $origin, ?string $expectedSha256 = null): array
    {
        if (!$this->isWritable()) {
            throw new PackageException('Catalogo locale non disponibile: rieseguire l\'installazione di EMP Server');
        }
        if (!preg_match(self::ORIGIN_PATTERN, $origin)) {
            throw new PackageException('Origine del pacchetto non valida');
        }
        if (!is_file($zipPath) || is_link($zipPath)) {
            throw new PackageException('Pacchetto non trovato');
        }
        $size = (int) filesize($zipPath);
        if ($size === 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new PackageException(sprintf('Pacchetto vuoto o troppo grande (massimo %d MB)', intdiv(self::MAX_UPLOAD_BYTES, 1048576)));
        }
        $sha = (string) hash_file('sha256', $zipPath);
        if ($expectedSha256 !== null && $expectedSha256 !== '') {
            $expected = strtolower(trim($expectedSha256));
            if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
                throw new PackageException('Impronta SHA-256 indicata non valida (64 caratteri esadecimali)');
            }
            if (!hash_equals($expected, $sha)) {
                throw new PackageException('Impronta SHA-256 diversa da quella indicata: pacchetto rifiutato');
            }
        }
        $manifest = self::inspect($zipPath);
        $file = $manifest->id . '-' . $manifest->version . '.zip';

        return $this->locked(function () use ($zipPath, $manifest, $file, $sha, $origin): array {
            $index = $this->readIndex();
            foreach ($index as $p) {
                if ($p['id'] === $manifest->id && $p['version'] === (string) $manifest->version) {
                    if (!hash_equals($p['sha256'], $sha)) {
                        throw new PackageException(sprintf(
                            '%s %s è già nel catalogo locale con un contenuto diverso: per modificarlo aumentare la versione nel manifest',
                            $manifest->id,
                            $manifest->version,
                        ));
                    }

                    return $p + ['nuovo' => false];
                }
            }
            $tmp = $this->dir . DIRECTORY_SEPARATOR . '.' . $file . '.' . bin2hex(random_bytes(4));
            if (!copy($zipPath, $tmp) || !hash_equals($sha, (string) hash_file('sha256', $tmp))) {
                @unlink($tmp);
                throw new PackageException('Copia del pacchetto nel catalogo locale non riuscita');
            }
            chmod($tmp, 0640);
            // Una sola versione per plugin nel catalogo locale: la nuova sostituisce la precedente.
            $kept = [];
            $old = [];
            foreach ($index as $p) {
                if ($p['id'] === $manifest->id) {
                    $old[] = $p['file'];
                } else {
                    $kept[] = $p;
                }
            }
            if (!rename($tmp, $this->dir . DIRECTORY_SEPARATOR . $file)) {
                @unlink($tmp);
                throw new PackageException('Registrazione del pacchetto non riuscita');
            }
            $entry = ['id' => $manifest->id, 'version' => (string) $manifest->version, 'file' => $file, 'sha256' => $sha, 'origine' => $origin];
            $kept[] = $entry;
            $this->writeIndex($kept);
            foreach ($old as $f) {
                if ($f !== $file) {
                    @unlink($this->dir . DIRECTORY_SEPARATOR . $f);
                }
            }

            return $entry + ['nuovo' => true];
        });
    }

    /** Toglie un plugin dal catalogo locale (non lo disinstalla). */
    public function remove(string $id): bool
    {
        if (!$this->isWritable()) {
            return false;
        }

        return $this->locked(function () use ($id): bool {
            $kept = [];
            $removed = null;
            foreach ($this->readIndex() as $p) {
                if ($p['id'] === $id) {
                    $removed = $p['file'];
                } else {
                    $kept[] = $p;
                }
            }
            if ($removed === null) {
                return false;
            }
            $this->writeIndex($kept);
            @unlink($this->dir . DIRECTORY_SEPARATOR . $removed);

            return true;
        });
    }

    /**
     * Controlla il contenuto di uno ZIP di plugin senza estrarlo e ne legge il manifest.
     * L'estrazione (PackageExtractor) ripete i controlli su percorsi e dimensioni.
     */
    public static function inspect(string $zipPath): Manifest
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new PackageException('Il file non è un pacchetto ZIP valido');
        }
        try {
            if ($zip->numFiles === 0 || $zip->numFiles > 20000) {
                throw new PackageException('Pacchetto vuoto o con troppi file');
            }
            $root = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
                    throw new PackageException("Percorso non ammesso nel pacchetto ({$name})");
                }
                $first = explode('/', $name)[0];
                if ($root === null) {
                    $root = $first;
                } elseif ($first !== $root) {
                    throw new PackageException('Il pacchetto deve contenere una sola cartella, con il nome del plugin');
                }
                foreach (explode('/', rtrim($name, '/')) as $part) {
                    if ($part === '..' || $part === '.') {
                        throw new PackageException("Percorso non ammesso nel pacchetto ({$name})");
                    }
                }
            }
            $json = $zip->getFromName($root . '/' . Manifest::FILE, 65536);
        } finally {
            $zip->close();
        }
        if (!is_string($json)) {
            throw new PackageException('Manifest emp-module.json assente nella cartella del plugin');
        }
        try {
            $manifest = Manifest::fromJson($json, (string) $root);
        } catch (ManifestException $e) {
            throw new PackageException('Manifest non valido: ' . $e->getMessage(), 0, $e);
        }
        if ($manifest->id !== $root) {
            throw new PackageException(sprintf('La cartella del pacchetto (%s) deve chiamarsi come il plugin (%s)', $root, $manifest->id));
        }
        if ($manifest->isCore()) {
            throw new PackageException('Il Core si installa e si aggiorna con EMP Server (sudo ./installa.sh)');
        }

        return $manifest;
    }

    private function indexPath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . Catalog::INDEX_FILE;
    }

    /** @return list<array{id: string, version: string, file: string, sha256: string, origine?: string}> */
    private function readIndex(): array
    {
        if (!is_file($this->indexPath())) {
            return [];
        }
        $entries = (new Catalog($this->dir))->entries();
        $raw = json_decode((string) file_get_contents($this->indexPath()), true);
        $origins = [];
        foreach (is_array($raw['packages'] ?? null) ? $raw['packages'] : [] as $p) {
            if (is_array($p) && is_string($p['id'] ?? null) && is_string($p['origine'] ?? null)) {
                $origins[$p['id']] = $p['origine'];
            }
        }
        foreach ($entries as &$e) {
            if (isset($origins[$e['id']])) {
                $e['origine'] = $origins[$e['id']];
            }
        }

        return $entries;
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeIndex(array $entries): void
    {
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        $json = json_encode(['schema' => 1, 'channel' => 'locale', 'packages' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = $this->indexPath() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->indexPath())) {
            @unlink($tmp);
            throw new PackageException('Scrittura dell\'indice del catalogo locale non riuscita');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function locked(\Closure $fn): mixed
    {
        $lock = fopen($this->dir . DIRECTORY_SEPARATOR . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new PackageException('Catalogo locale occupato da un\'altra operazione');
        }
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
