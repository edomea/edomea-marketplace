<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

/**
 * Sorgente GitHub dei plugin: opzione FACOLTATIVA, disattivata di predefinito.
 *
 * Il modo normale di installare un plugin resta il catalogo di EMP Server o il
 * caricamento manuale del pacchetto. Con la sorgente attiva, il Tecnico può
 * importare nel catalogo locale i plugin di una release del repository indicato:
 *
 * - solo release PUBBLICATE: le bozze e le pre-release sono escluse, così entra
 *   solo ciò che il titolare del repository ha approvato pubblicandolo;
 * - la release deve allegare emp-packages.json e i pacchetti ZIP che elenca;
 * - ogni pacchetto è verificato con l'impronta SHA-256 dell'indice e con il suo
 *   manifest prima di entrare nel catalogo locale; se uno non è valido non entra nessuno;
 * - nessuna connessione automatica: GitHub viene interrogato solo quando il
 *   Tecnico lo chiede. Il negozio continua a funzionare offline.
 *
 * L'importazione non installa: i plugin compaiono nella pagina Plugin e si
 * installano con il pulsante, come quelli del catalogo di EMP Server.
 */
final class GitHubSource
{
    public const SETTINGS_FILE = 'sorgente-github.json';
    public const DEFAULT_REPOSITORY = 'edomea/edomea-marketplace';
    private const REPO_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9._-]{1,100}$/';
    private const TAG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
    private const API = 'https://api.github.com/repos/';
    private const MAX_JSON_BYTES = 4194304;

    public function __construct(
        private readonly LocalCatalog $local,
        private readonly HttpClient $http,
    ) {
    }

    /** @return array{attiva: bool, repository: string, modificata_il: ?string, modificata_da: ?string} */
    public function settings(): array
    {
        $default = ['attiva' => false, 'repository' => self::DEFAULT_REPOSITORY, 'modificata_il' => null, 'modificata_da' => null];
        $path = $this->settingsPath();
        if (!is_file($path) || is_link($path) || (int) filesize($path) > 65536) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return $default;
        }
        $repo = is_string($data['repository'] ?? null) && preg_match(self::REPO_PATTERN, $data['repository']) ? $data['repository'] : self::DEFAULT_REPOSITORY;

        return [
            'attiva' => ($data['attiva'] ?? false) === true,
            'repository' => $repo,
            'modificata_il' => is_string($data['modificata_il'] ?? null) ? $data['modificata_il'] : null,
            'modificata_da' => is_string($data['modificata_da'] ?? null) ? $data['modificata_da'] : null,
        ];
    }

    /** @return array{attiva: bool, repository: string, modificata_il: ?string, modificata_da: ?string} */
    public function saveSettings(bool $active, string $repository, string $actor, \DateTimeImmutable $now): array
    {
        $repository = trim($repository);
        if (!preg_match(self::REPO_PATTERN, $repository)) {
            throw new PackageException('Repository GitHub non valido: usare la forma proprietario/nome (es. ' . self::DEFAULT_REPOSITORY . ')');
        }
        if (!$this->local->isWritable()) {
            throw new PackageException('Catalogo locale non disponibile: rieseguire l\'installazione di EMP Server');
        }
        $settings = ['attiva' => $active, 'repository' => $repository, 'modificata_il' => $now->format(\DateTimeInterface::ATOM), 'modificata_da' => $actor];
        $tmp = $this->settingsPath() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
            || !rename($tmp, $this->settingsPath())) {
            @unlink($tmp);
            throw new PackageException('Salvataggio delle impostazioni GitHub non riuscito');
        }

        return $settings;
    }

    /**
     * Release pubblicate (niente bozze né pre-release), dalla più recente.
     *
     * @return list<array{tag: string, nome: string, pubblicata_il: string, indirizzo: string, catalogo: bool}>
     */
    public function releases(): array
    {
        $repo = $this->activeRepository();
        $list = $this->json(self::API . $repo . '/releases?per_page=20');
        if (!array_is_list($list)) {
            throw new PackageException('Risposta di GitHub non valida');
        }
        $out = [];
        foreach ($list as $r) {
            if (!is_array($r) || !self::isPublished($r) || !is_string($r['tag_name'] ?? null) || !preg_match(self::TAG_PATTERN, $r['tag_name'])) {
                continue;
            }
            $out[] = [
                'tag' => $r['tag_name'],
                'nome' => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : $r['tag_name'],
                'pubblicata_il' => (string) ($r['published_at'] ?? ''),
                'indirizzo' => (string) ($r['html_url'] ?? ''),
                'catalogo' => isset(self::assets($r)[Catalog::INDEX_FILE]),
            ];
        }

        return $out;
    }

    /**
     * Importa nel catalogo locale i plugin di una release pubblicata.
     *
     * @return list<array{id: string, version: string, file: string, sha256: string, origine: string, nuovo: bool}>
     */
    public function import(string $tag): array
    {
        $repo = $this->activeRepository();
        if (!preg_match(self::TAG_PATTERN, $tag)) {
            throw new PackageException('Release non valida');
        }
        $release = $this->json(self::API . $repo . '/releases/tags/' . rawurlencode($tag));
        if (!self::isPublished($release)) {
            throw new PackageException("La release {$tag} non è pubblicata (bozza o pre-release): non importabile");
        }
        $assets = self::assets($release);
        if (!isset($assets[Catalog::INDEX_FILE])) {
            throw new PackageException("La release {$tag} non contiene il catalogo dei plugin (" . Catalog::INDEX_FILE . ')');
        }

        $work = $this->local->dir() . DIRECTORY_SEPARATOR . '.github-' . bin2hex(random_bytes(6));
        if (!mkdir($work, 0700)) {
            throw new PackageException('Impossibile creare la cartella temporanea');
        }
        try {
            $this->http->download($assets[Catalog::INDEX_FILE]['url'], $work . DIRECTORY_SEPARATOR . Catalog::INDEX_FILE, 1048576);
            $catalog = new Catalog($work);
            $entries = $catalog->entries(); // formato validato; il Core (emp-core) è escluso
            if ($entries === []) {
                throw new PackageException("La release {$tag} non contiene plugin");
            }
            // Prima si scaricano e verificano tutti i pacchetti, poi si aggiungono.
            $verified = [];
            foreach ($entries as $entry) {
                $asset = $assets[$entry['file']] ?? null;
                if ($asset === null) {
                    throw new PackageException("La release {$tag} non allega {$entry['file']}, elencato nel catalogo");
                }
                if ($asset['size'] > LocalCatalog::MAX_UPLOAD_BYTES) {
                    throw new PackageException("{$entry['file']} troppo grande");
                }
                $this->http->download($asset['url'], $work . DIRECTORY_SEPARATOR . $entry['file'], LocalCatalog::MAX_UPLOAD_BYTES);
                $verified[] = [$catalog->verify($entry), $entry];
            }
            $origin = "GitHub {$repo} {$tag}";
            $out = [];
            foreach ($verified as [$package, $entry]) {
                $out[] = $this->local->add($package->path, $origin, $entry['sha256']);
            }

            return $out;
        } finally {
            self::removeDir($work);
        }
    }

    private function activeRepository(): string
    {
        $s = $this->settings();
        if (!$s['attiva']) {
            throw new PackageException('Sorgente GitHub disattivata: attivarla nella pagina Plugin');
        }

        return $s['repository'];
    }

    /** @return array<mixed> */
    private function json(string $url): array
    {
        try {
            $data = json_decode($this->http->get($url, self::MAX_JSON_BYTES), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PackageException('Risposta di GitHub non valida', 0, $e);
        }
        if (!is_array($data)) {
            throw new PackageException('Risposta di GitHub non valida');
        }

        return $data;
    }

    /** @param array<mixed> $release */
    private static function isPublished(array $release): bool
    {
        return ($release['draft'] ?? true) === false && ($release['prerelease'] ?? true) === false;
    }

    /**
     * @param array<mixed> $release
     * @return array<string, array{url: string, size: int}>
     */
    private static function assets(array $release): array
    {
        $out = [];
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $a) {
            if (is_array($a) && is_string($a['name'] ?? null) && is_string($a['browser_download_url'] ?? null)
                && CurlHttpClient::allowedUrl($a['browser_download_url'])) {
                $out[$a['name']] = ['url' => $a['browser_download_url'], 'size' => (int) ($a['size'] ?? 0)];
            }
        }

        return $out;
    }

    private function settingsPath(): string
    {
        return $this->local->dir() . DIRECTORY_SEPARATOR . self::SETTINGS_FILE;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir . DIRECTORY_SEPARATOR . $f);
            }
        }
        @rmdir($dir);
    }
}
