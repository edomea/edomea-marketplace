<?php

declare(strict_types=1);

/*
 * Controlli prima di una release EMP.
 *
 *   php tools/check-release.php <tag> [--note=<file>]
 *
 * - il tag è "v" + la versione di EMP Desk (es. v0.5.0-alpha.2);
 * - la versione coincide in desk/app/src-tauri/Cargo.toml e tauri.conf.json;
 * - CHANGELOG.md ha una sezione "## [<versione>]" con data gg/mm/aaaa;
 * - i manifest di Core e plugin hanno versioni valide;
 * - con --note scrive le note di rilascio (la sezione del CHANGELOG).
 */
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$root = dirname(__DIR__);
$tag = $argv[1] ?? '';
$notesFile = null;
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--note=')) {
        $notesFile = substr($a, 7);
    }
}
$errors = [];
$semver = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:alpha|beta|rc)\.(?:0|[1-9]\d*))?$/';

if (!preg_match('/^v(.+)$/', $tag, $m) || !preg_match($semver, $m[1])) {
    fwrite(STDERR, "Tag non valido: \"{$tag}\" (atteso v<versione>, es. v0.5.0-alpha.2)\n");
    exit(1);
}
$version = $m[1];

$cargo = (string) file_get_contents($root . '/desk/app/src-tauri/Cargo.toml');
if (!preg_match('/^version\s*=\s*"([^"]+)"/m', $cargo, $cm) || $cm[1] !== $version) {
    $errors[] = 'desk/app/src-tauri/Cargo.toml: versione ' . ($cm[1] ?? '?') . " diversa da {$version}";
}
$tauri = json_decode((string) file_get_contents($root . '/desk/app/src-tauri/tauri.conf.json'), true);
if (($tauri['version'] ?? null) !== $version) {
    $errors[] = 'tauri.conf.json: versione ' . ($tauri['version'] ?? '?') . " diversa da {$version}";
}

$manifests = array_merge([$root . '/core/emp-module.json'], glob($root . '/plugins/*/emp-module.json') ?: []);
foreach ($manifests as $file) {
    $mf = json_decode((string) file_get_contents($file), true);
    if (!is_array($mf) || !is_string($mf['version'] ?? null) || !preg_match($semver, $mf['version'])) {
        $errors[] = substr($file, strlen($root) + 1) . ': versione non valida';
    }
}

$changelog = (string) @file_get_contents($root . '/CHANGELOG.md');
$quoted = preg_quote($version, '/');
if (!preg_match('/^## \[' . $quoted . '\] — (\d{2}\/\d{2}\/\d{4})\s*$(.*?)(?=^## \[|\z)/ms', $changelog, $sm)) {
    $errors[] = "CHANGELOG.md: manca la sezione \"## [{$version}] — gg/mm/aaaa\"";
} elseif (trim($sm[2]) === '') {
    $errors[] = "CHANGELOG.md: la sezione {$version} è vuota";
}

if ($errors !== []) {
    fwrite(STDERR, "Release {$tag} non pronta:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
if ($notesFile !== null) {
    file_put_contents($notesFile, trim($sm[2]) . "\n");
}
echo "Release {$tag} pronta (EMP Desk {$version}, " . count($manifests) . " manifest controllati)\n";
