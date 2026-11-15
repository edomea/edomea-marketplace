<?php

declare(strict_types=1);

/*
 * Costruisce i pacchetti dei moduli EMP e l'indice emp-packages.json.
 *
 *   php tools/build-packages.php <cartella-destinazione>
 *
 * - Core: core/ → emp-core-<versione>.zip con la cartella emp-core/ alla radice
 *   (esclusi test, vendor e file di sviluppo).
 * - Plugin: plugins/<id>/ → <id>-<versione>.zip, se contiene emp-module.json.
 *   I plugin dimostrativi (emp-esempio) sono inclusi solo con --con-esempi.
 * - Archivi riproducibili: voci ordinate e data fissa, così la stessa sorgente
 *   produce la stessa impronta SHA-256.
 * Solo PHP + ZipArchive (regola Edomea).
 */
if (PHP_SAPI !== 'cli' || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Serve PHP CLI con l'estensione zip\n");
    exit(2);
}
$args = array_slice($argv, 1);
$withExamples = in_array('--con-esempi', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--con-esempi'));
if (count($args) !== 1) {
    fwrite(STDERR, "Uso: php tools/build-packages.php [--con-esempi] <cartella-destinazione>\n");
    exit(2);
}
$root = dirname(__DIR__);
$out = $args[0];
if (!is_dir($out) && !mkdir($out, 0755, true)) {
    fwrite(STDERR, "Impossibile creare {$out}\n");
    exit(1);
}

const FIXED_MTIME = 315532800; // 01/01/1980, minimo rappresentabile in ZIP
const EXCLUDE = ['tests', 'vendor', 'composer.json', 'composer.lock', 'phpunit.xml', '.phpunit.result.cache', '.gitignore'];

function manifestOf(string $dir): array
{
    $m = json_decode((string) file_get_contents($dir . '/emp-module.json'), true, 16, JSON_THROW_ON_ERROR);
    foreach (['id', 'version'] as $k) {
        if (!isset($m[$k]) || !is_string($m[$k])) {
            throw new RuntimeException("{$dir}: manifest senza {$k}");
        }
    }

    return $m;
}

/** @return list<string> percorsi relativi, ordinati */
function filesOf(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        $first = explode('/', $rel)[0];
        if (in_array($first, EXCLUDE, true) || str_starts_with(basename($rel), '.')) {
            continue;
        }
        if ($f->isLink()) {
            throw new RuntimeException("Collegamento simbolico non ammesso: {$rel}");
        }
        $files[] = $rel;
    }
    sort($files, SORT_STRING);

    return $files;
}

function package(string $dir, string $out): array
{
    $m = manifestOf($dir);
    $id = $m['id'];
    if ($id !== 'emp-core' && basename($dir) !== $id) {
        throw new RuntimeException("{$dir}: la cartella deve chiamarsi {$id}");
    }
    $file = "{$id}-{$m['version']}.zip";
    $path = $out . '/' . $file;
    if (is_file($path)) {
        unlink($path);
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException("Impossibile creare {$path}");
    }
    foreach (filesOf($dir) as $rel) {
        $name = "{$id}/{$rel}";
        $zip->addFile($dir . '/' . $rel, $name);
        $zip->setMtimeName($name, FIXED_MTIME);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (is_executable($dir . '/' . $rel) ? 0100755 : 0100644) << 16);
    }
    $zip->close();

    return ['id' => $id, 'version' => $m['version'], 'file' => $file, 'sha256' => hash_file('sha256', $path)];
}

try {
    $packages = [package($root . '/core', $out)];
    foreach (glob($root . '/plugins/*/emp-module.json') ?: [] as $manifest) {
        $dir = dirname($manifest);
        if (!$withExamples && basename($dir) === 'emp-esempio') {
            continue;
        }
        $packages[] = package($dir, $out);
    }
    usort($packages, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
    file_put_contents(
        $out . '/emp-packages.json',
        json_encode(['schema' => 1, 'channel' => 'laboratorio', 'packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    foreach ($packages as $p) {
        echo "{$p['sha256']}  {$p['file']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
