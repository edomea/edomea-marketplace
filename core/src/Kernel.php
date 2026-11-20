<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Access\AccessService;
use Emp\Core\Access\JobRoleService;
use Emp\Core\Access\WorkstationService;
use Emp\Core\Backup\BackupBridge;
use Emp\Core\Brand\BrandService;
use Emp\Core\Http\CsrfGuard;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Http\Router;
use Emp\Core\Module\BootControl;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ModuleInterface;
use Emp\Core\Module\Psr4Autoloader;
use Emp\Core\Module\Registry;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Module\Resolver;
use Emp\Core\Plugin\Arrivals;
use Emp\Core\Plugin\Catalog;
use Emp\Core\Plugin\CurlHttpClient;
use Emp\Core\Plugin\GitHubSource;
use Emp\Core\Plugin\HttpClient;
use Emp\Core\Plugin\LocalCatalog;
use Emp\Core\Plugin\PluginManager;
use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;

/**
 * Avvio del Core: scopre i moduli, avvia solo quelli installati e compatibili,
 * nell'ordine delle dipendenze (Core per primo), e smista le richieste API.
 */
final class Kernel
{
    public readonly Router $router;
    /** @var list<Manifest> */
    private array $booted = [];
    private readonly Psr4Autoloader $autoloader;
    private ?AccessService $access = null;
    private ?BootControl $bootControl = null;
    /** @var array<string, string> moduli installati ma non avviati: id => motivo */
    private array $skipped = [];

    public function __construct(
        private readonly string $modulesDir,
        private readonly Installer $installer,
        public readonly Clock $clock,
        private readonly ?string $catalogDir = null,
        private readonly ?string $localCatalogDir = null,
        private readonly ?HttpClient $http = null,
        /** Versione di EMP Server che ha installato il Core (null fuori da EMP Server). */
        public readonly ?string $serverVersion = null,
        /** Cartella di arrivo dei plugin caricati via scp (null: funzione non disponibile). */
        private readonly ?string $arrivalsDir = null,
        /** Cartella di scambio con il servizio dei backup di EMP Server (null fuori da EMP Server). */
        private readonly ?string $backupWebDir = null,
        /** File dell'avvio dei plugin deciso dalla console (avvio sicuro, plugin disattivati). */
        private readonly ?string $bootControlFile = null,
    ) {
        $this->router = new Router();
        $this->autoloader = new Psr4Autoloader();
    }

    public function boot(): void
    {
        if ($this->booted !== []) {
            return;
        }
        $available = (new Registry($this->modulesDir))->discover();
        $installed = $this->installer->installed();
        if (!isset($installed[Manifest::CORE_ID])) {
            throw new ResolutionException('Il Core non è installato: completare il primo setup');
        }

        $onDisk = [];
        foreach ($available as $m) {
            if (isset($installed[$m->id])) {
                $onDisk[$m->id] = $m;
            }
        }
        // Plugin fermati dalla console (avvio sicuro, plugin disattivati) e chi dipende da loro.
        $this->skipped = $this->bootControl()->skipped(array_values($onDisk));
        foreach (array_keys($installed) as $id) {
            if (!isset($onDisk[$id]) && $id !== Manifest::CORE_ID && ($this->bootControl()->safeMode || in_array($id, $this->bootControl()->disabled, true))) {
                $this->skipped[$id] = $this->bootControl()->safeMode ? BootControl::SAFE_MODE : BootControl::DISABLED;
            }
        }

        $present = [];
        foreach ($onDisk as $m) {
            if (isset($this->skipped[$m->id])) {
                continue;
            }
            if ((string) $m->version !== $installed[$m->id]) {
                throw new ResolutionException(sprintf(
                    '"%s": file alla versione %s, database alla %s. Completare l\'aggiornamento dal Control Center.',
                    $m->id,
                    $m->version,
                    $installed[$m->id],
                ));
            }
            $present[] = $m;
        }
        $missing = array_diff(array_keys($installed), array_keys($onDisk), array_keys($this->skipped));
        if ($missing !== []) {
            throw new ResolutionException('Moduli installati ma assenti dai file: ' . implode(', ', $missing));
        }

        $ordered = (new Resolver())->resolve($present);
        $this->installer->verify($ordered);

        foreach ($ordered as $m) {
            $this->autoloader->addModule($m);
            $module = new ($m->class)();
            if (!$module instanceof ModuleInterface) {
                throw new ResolutionException(sprintf('"%s": la classe %s non implementa ModuleInterface', $m->id, $m->class));
            }
            $module->register($this, $m);
            $this->booted[] = $m;
        }
    }

    /** Avvio dei plugin deciso dalla console del Server (avvio sicuro, plugin disattivati). */
    public function bootControl(): BootControl
    {
        return $this->bootControl ??= BootControl::fromFile($this->bootControlFile);
    }

    /** @return array<string, string> moduli installati ma non avviati (id => motivo), dopo boot() */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<Manifest> moduli avviati, in ordine */
    public function modules(): array
    {
        return $this->booted;
    }

    /** Servizio Accesso: utenti, sessioni, password, Recovery Key del Tecnico. */
    public function access(): AccessService
    {
        return $this->access ??= new AccessService($this->installer->pdo(), $this->installer->tablePrefix('core_'), $this->clock);
    }

    /** Ruoli di negozio con i permessi dei moduli avviati (per i plugin: allows($user, 'permesso')). */
    public function jobRoles(): JobRoleService
    {
        return new JobRoleService($this->installer->pdo(), $this->installer->tablePrefix('core_'), $this->clock, $this->booted);
    }

    /** Postazioni autorizzate del negozio. */
    public function workstations(): WorkstationService
    {
        return new WorkstationService($this->installer->pdo(), $this->installer->tablePrefix('core_'), $this->clock);
    }

    /** Gestione dei plugin (pagina web Plugin del Core). */
    public function plugins(): PluginManager
    {
        return new PluginManager(
            $this->modulesDir,
            new Catalog($this->catalogDir),
            $this->installer,
            PHP_VERSION,
            $this->localCatalogDir !== null ? new LocalCatalog($this->localCatalogDir) : null,
            $this->skipped,
            $this->bootControl()->safeMode,
        );
    }

    /** Sorgente GitHub facoltativa dei plugin (null senza catalogo locale). */
    public function githubSource(): ?GitHubSource
    {
        if ($this->localCatalogDir === null) {
            return null;
        }

        return new GitHubSource(new LocalCatalog($this->localCatalogDir), $this->http ?? new CurlHttpClient());
    }

    /** Pacchetti dei plugin arrivati via scp (null senza cartella configurata). */
    public function arrivals(): ?Arrivals
    {
        return $this->arrivalsDir !== null ? new Arrivals($this->arrivalsDir) : null;
    }

    /** Pagina Backup: richieste al servizio dei backup di EMP Server (null se non configurato). */
    public function backup(): ?BackupBridge
    {
        return $this->backupWebDir !== null ? new BackupBridge($this->backupWebDir) : null;
    }

    /** Marchio white label (nome, colore, logo) mostrato nelle pagine. */
    public function brand(): BrandService
    {
        return new BrandService($this->installer->pdo(), $this->installer->tablePrefix('core_'), $this->clock, $this->audit());
    }

    /** Registro di tracciabilità del Core. */
    public function audit(): AuditLog
    {
        return new AuditLog($this->installer->pdo(), $this->installer->tablePrefix('core_') . 'audit', $this->clock);
    }

    public function handle(Request $request): Response
    {
        try {
            $this->boot();

            // Ogni richiesta che modifica dati, di qualsiasi modulo, passa la protezione CSRF.
            return CsrfGuard::check($request) ?? $this->router->dispatch($request);
        } catch (\Throwable $e) {
            error_log('[EMP] ' . $e::class . ': ' . $e->getMessage());

            // Il dettaglio resta nel log del server: al client solo un messaggio generico.
            return Response::error(503, 'Servizio non disponibile: verificare il Control Center');
        }
    }
}
