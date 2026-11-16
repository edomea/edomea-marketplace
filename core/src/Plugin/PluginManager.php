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
 * - cartella modules/: Core (installato da EMP Desk) e plugin già presenti;
 * - catalogo: pacchetti verificati dei plugin installabili.
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
    ) {
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
        $catalog = $this->catalogPackages($errors);

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
            ];
        }

        return [
            'catalogo' => $this->catalog->isConfigured(),
            'core' => $installed[Manifest::CORE_ID] ?? null,
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
            throw new ResolutionException('Il Core si installa e si aggiorna da EMP Desk');
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
                throw new ResolutionException('Il Core si aggiorna da EMP Desk');
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
     * @return array<string, VerifiedPackage>
     */
    private function catalogPackages(array &$errors): array
    {
        $out = [];
        try {
            foreach ($this->catalog->entries() as $entry) {
                try {
                    $out[$entry['id']] = $this->catalog->verify($entry);
                } catch (PackageException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } catch (PackageException $e) {
            $errors[] = $e->getMessage();
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
