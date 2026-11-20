<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

use Emp\Core\Module\Installer;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\Registry;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Module\Resolver;

/**
 * Gestione dei plugin dall'interfaccia web del Core.
 *
 * Fonti:
 * - cartella modules/: Core (installato da EMP Server) e plugin già presenti;
 * - catalogo di EMP Server: pacchetti verificati inclusi nel pacchetto del Server;
 * - catalogo locale: pacchetti caricati a mano dal Tecnico o importati da una
 *   release GitHub (opzione facoltativa). Per ogni plugin vale la versione più
 *   recente tra i due cataloghi; a parità di versione, quella di EMP Server.
 *
 * Installazione di un plugin: calcolo del piano con le dipendenze, estrazione
 * dal catalogo dei pacchetti mancanti o più recenti, migrazioni nel database
 * nell'ordine Core → dipendenze → plugin.
 */
final class PluginManager
{
    public function __construct(
        private readonly string $modulesDir,
        private readonly Catalog $catalog,
        private readonly Installer $installer,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly ?LocalCatalog $local = null,
        /** @var array<string, string> plugin installati ma non avviati (id => motivo) */
        private readonly array $stopped = [],
        private readonly bool $safeMode = false,
    ) {
    }

    public function localCatalog(): ?LocalCatalog
    {
        return $this->local;
    }

    /**
     * Aggiunge al catalogo locale un pacchetto caricato a mano. Il plugin non viene
     * ancora installato: compare nella pagina Plugin come installabile o aggiornabile.
     *
     * @return array{id: string, version: string, file: string, sha256: string, origine: string, nuovo: bool}
     */
    public function upload(string $zipPath, string $origin, ?string $expectedSha256 = null): array
    {
        if ($this->local === null) {
            throw new PackageException('Catalogo locale non configurato (local_catalog_dir)');
        }
        $entry = $this->local->add($zipPath, $origin, $expectedSha256);
        // Ricontrollo completo, come per ogni pacchetto del catalogo.
        $this->local->reader()->verify($entry);

        return $entry;
    }

    /** Toglie un pacchetto dal catalogo locale; il plugin eventualmente installato resta. */
    public function removePackage(string $id): bool
    {
        return $this->local !== null && $this->local->remove($id);
    }

    /**
     * Stato di tutti i plugin, per la pagina Plugin.
     *
     * @return array{catalogo: bool, core: ?string, plugin: list<array<string, mixed>>, errori: list<string>}
     */
    public function overview(): array
    {
        $installed = $this->installer->installed();
        $present = $this->presentById();
        $errors = [];
        $origins = [];
        $catalog = $this->catalogPackages($errors, $origins);
        $localIds = $this->local !== null ? array_keys($this->local->origins()) : [];

        $ids = array_unique(array_merge(array_keys($catalog), array_keys($present)));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            if ($id === Manifest::CORE_ID) {
                continue;
            }
            $available = $catalog[$id]->manifest ?? null;
            $local = $present[$id] ?? null;
            $info = $available ?? $local;
            $installedVersion = $installed[$id] ?? null;
            [$state, $reason] = $this->state($id, $available, $installedVersion, $present, $catalog);
            $rows[] = [
                'id' => $id,
                'nome' => $info?->name ?? $id,
                'descrizione' => $info?->description ?? '',
                'versione_disponibile' => $available !== null ? (string) $available->version : null,
                'versione_installata' => $installedVersion,
                'dipendenze' => $info !== null ? array_map('strval', $info->requires) : [],
                'stato' => $state,
                'motivo' => $reason,
                'origine' => $origins[$id] ?? null,
                'nel_catalogo_locale' => in_array($id, $localIds, true),
                // Installato ma fermato dalla console (avvio sicuro, plugin-disattiva): motivo, altrimenti null.
                'fermo' => $installedVersion !== null ? ($this->stopped[$id] ?? null) : null,
            ];
        }

        return [
            'catalogo' => $this->catalog->isConfigured() || $this->local !== null,
            'catalogo_locale' => $this->local?->isWritable() ?? false,
            'core' => $installed[Manifest::CORE_ID] ?? null,
            'avvio_sicuro' => $this->safeMode,
            'plugin' => $rows,
            'errori' => $errors,
        ];
    }

    /**
     * Installa o aggiorna un plugin (con le sue dipendenze).
     *
     * @return list<string> operazioni eseguite
     */
    public function install(string $id, string $actor): array
    {
        if ($id === Manifest::CORE_ID) {
            throw new ResolutionException('Il Core si installa e si aggiorna con EMP Server (sudo ./installa.sh)');
        }
        $errors = [];
        $catalog = $this->catalogPackages($errors);
        $present = $this->presentById();
        $plan = (new Resolver())->resolve($this->candidates($present, $catalog), [$id], $this->phpVersion);

        $log = [];
        $extractor = new PackageExtractor($this->modulesDir);
        foreach ($plan as $m) {
            $package = $catalog[$m->id] ?? null;
            if ($package === null || $m !== $package->manifest) {
                continue; // già presente e più recente o uguale
            }
            if ($m->isCore()) {
                throw new ResolutionException('Il Core si aggiorna con EMP Server (sudo ./installa.sh)');
            }
            $extractor->extract($package);
            $log[] = sprintf('File di %s %s estratti dal catalogo', $m->id, $m->version);
        }

        // Rilettura dai file effettivamente presenti e migrazioni nel database.
        $onDisk = (new Registry($this->modulesDir))->discover();
        $plan = (new Resolver())->resolve($onDisk, [$id], $this->phpVersion);

        return array_merge($log, $this->installer->install($plan, $actor));
    }

    public function uninstall(string $id, string $actor): void
    {
        $this->installer->uninstall($id, (new Registry($this->modulesDir))->discover(), $actor);
    }

    /** @return array<string, Manifest> */
    private function presentById(): array
    {
        $out = [];
        foreach ((new Registry($this->modulesDir))->discover() as $m) {
            $out[$m->id] = $m;
        }

        return $out;
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $origins id => origine del pacchetto scelto
     * @return array<string, VerifiedPackage>
     */
    private function catalogPackages(array &$errors, array &$origins = []): array
    {
        $out = [];
        $sources = [[$this->catalog, null]];
        if ($this->local !== null) {
            $sources[] = [$this->local->reader(), $this->local->origins()];
        }
        foreach ($sources as [$catalog, $localOrigins]) {
            try {
                foreach ($catalog->entries() as $entry) {
                    try {
                        $package = $catalog->verify($entry);
                    } catch (PackageException $e) {
                        $errors[] = $e->getMessage();
                        continue;
                    }
                    $id = $entry['id'];
                    if (isset($out[$id]) && $package->manifest->version->compare($out[$id]->manifest->version) <= 0) {
                        continue;
                    }
                    $out[$id] = $package;
                    $origins[$id] = $localOrigins === null ? 'Pacchetto EMP Server' : ($localOrigins[$id] ?? 'Catalogo locale');
                }
            } catch (PackageException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * Insieme di moduli su cui calcolare il piano: per ogni id la versione più
     * recente tra quella presente sul disco e quella del catalogo.
     *
     * @param array<string, Manifest> $present
     * @param array<string, VerifiedPackage> $catalog
     * @return list<Manifest>
     */
    private function candidates(array $present, array $catalog): array
    {
        $set = $present;
        foreach ($catalog as $id => $package) {
            if (!isset($set[$id]) || $package->manifest->version->compare($set[$id]->version) > 0) {
                $set[$id] = $package->manifest;
            }
        }
        ksort($set);

        return array_values($set);
    }

    /**
     * @param array<string, Manifest> $present
     * @param array<string, VerifiedPackage> $catalog
     * @return array{string, string}
     */
    private function state(string $id, ?Manifest $available, ?string $installedVersion, array $present, array $catalog): array
    {
        if ($installedVersion !== null && ($available === null || (string) $available->version === $installedVersion)) {
            return ['installato', ''];
        }
        if ($available !== null && $installedVersion !== null && $available->version->compare(\Emp\Core\Version\Version::parse($installedVersion)) < 0) {
            return ['installato', 'Il catalogo contiene una versione precedente a quella installata'];
        }
        if ($available === null) {
            return ['non_disponibile', 'Pacchetto assente dal catalogo'];
        }
        try {
            (new Resolver())->resolve($this->candidates($present, $catalog), [$id], $this->phpVersion);
        } catch (ResolutionException $e) {
            return ['non_compatibile', $e->getMessage()];
        }

        return [$installedVersion === null ? 'installabile' : 'aggiornabile', ''];
    }
}
