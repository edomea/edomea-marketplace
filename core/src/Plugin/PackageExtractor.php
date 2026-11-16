<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

/**
 * Estrae un pacchetto verificato in modules/<id>/: ogni voce deve stare
 * dentro la cartella del modulo, niente "..",
 * percorsi assoluti o collegamenti simbolici, limiti di dimensione reali.
 * I file vanno prima in una cartella temporanea; la versione precedente resta
 * in modules/.precedenti/ per il ripristino.
 */
final class PackageExtractor
{
    private const MAX_UNPACKED_BYTES = 1073741824;
    private const MAX_ENTRIES = 20000;
    private const S_IFMT = 0170000;
    private const S_IFLNK = 0120000;

    public function __construct(private readonly string $modulesDir)
    {
    }

    public function extract(VerifiedPackage $package): void
    {
        $id = $package->manifest->id;
        $staging = $this->modulesDir . DIRECTORY_SEPARATOR . '.installazione-' . $id;
        self::removeTree($staging);
        if (!mkdir($staging, 0755, true)) {
            throw new PackageException('Impossibile creare la cartella temporanea');
        }
        try {
            $this->unpack($package, $staging);
        } catch (\Throwable $e) {
            self::removeTree($staging);
            throw $e instanceof PackageException ? $e : new PackageException("{$id}: " . $e->getMessage(), 0, $e);
        }

        $dest = $this->modulesDir . DIRECTORY_SEPARATOR . $id;
        if (file_exists($dest) || is_link($dest)) {
            $backups = $this->modulesDir . DIRECTORY_SEPARATOR . '.precedenti';
            if (!is_dir($backups) && !mkdir($backups, 0755, true)) {
                throw new PackageException('Impossibile creare la cartella delle versioni precedenti');
            }
            $n = 0;
            while (file_exists($backups . DIRECTORY_SEPARATOR . "{$id}-{$n}")) {
                $n++;
            }
            if (!rename($dest, $backups . DIRECTORY_SEPARATOR . "{$id}-{$n}")) {
                throw new PackageException("{$id}: conservazione della versione precedente non riuscita");
            }
        }
        if (!rename($staging . DIRECTORY_SEPARATOR . $id, $dest)) {
            throw new PackageException("{$id}: attivazione non riuscita");
        }
        self::removeTree($staging);
    }

    private function unpack(VerifiedPackage $package, string $staging): void
    {
        $id = $package->manifest->id;
        $zip = new \ZipArchive();
        if ($zip->open($package->path, \ZipArchive::RDONLY) !== true) {
            throw new PackageException("{$id}: pacchetto non leggibile");
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new PackageException("{$id}: pacchetto con troppi file");
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $opsys = 0;
                $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & self::S_IFMT) === self::S_IFLNK)) {
                    throw new PackageException("{$id}: collegamento simbolico non ammesso ({$name})");
                }
                $rel = self::safeRelative($name, $id);
                $target = $staging . DIRECTORY_SEPARATOR . $rel;
                if (str_ends_with($name, '/')) {
                    if (!is_dir($target) && !mkdir($target, 0755, true)) {
                        throw new PackageException("{$id}: impossibile creare {$rel}");
                    }
                    continue;
                }
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
                    throw new PackageException("{$id}: impossibile creare {$rel}");
                }
                $in = $zip->getStream($name);
                $out = @fopen($target, 'xb');
                if ($in === false || $out === false) {
                    throw new PackageException("{$id}: estrazione di {$rel} non riuscita");
                }
                try {
                    // Limite sui byte realmente scritti, non sulla dimensione dichiarata.
                    $limit = self::MAX_UNPACKED_BYTES - $total;
                    $written = stream_copy_to_stream($in, $out, $limit + 1);
                    if ($written === false) {
                        throw new PackageException("{$id}: estrazione di {$rel} non riuscita");
                    }
                    $total += $written;
                    if ($written > $limit) {
                        throw new PackageException("{$id}: pacchetto troppo grande una volta estratto");
                    }
                } finally {
                    fclose($in);
                    fclose($out);
                }
            }
        } finally {
            $zip->close();
        }
        if (!is_file($staging . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'emp-module.json')) {
            throw new PackageException("{$id}: manifest assente dopo l'estrazione");
        }
    }

    /** Percorso relativo sicuro: deve iniziare con "<id>/" e non uscire dalla cartella. */
    private static function safeRelative(string $name, string $id): string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            throw new PackageException("{$id}: percorso non ammesso ({$name})");
        }
        $parts = explode('/', rtrim($name, '/'));
        if ($parts[0] !== $id) {
            throw new PackageException("{$id}: voce fuori dalla cartella del modulo ({$name})");
        }
        foreach ($parts as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                throw new PackageException("{$id}: percorso non ammesso ({$name})");
            }
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
