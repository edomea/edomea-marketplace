<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Access\AccessService;
use Emp\Core\Http\CsrfGuard;
use Emp\Core\Http\Request;
use Emp\Core\Http\Response;
use Emp\Core\Http\Router;
use Emp\Core\Module\Installer;
use Emp\Core\Module\Manifest;
use Emp\Core\Module\ModuleInterface;
use Emp\Core\Module\Psr4Autoloader;
use Emp\Core\Module\Registry;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Module\Resolver;
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

    public function __construct(
        private readonly string $modulesDir,
        private readonly Installer $installer,
        public readonly Clock $clock,
        private readonly ?string $catalogDir = null,
        private readonly ?string $localCatalogDir = null,
        private readonly ?HttpClient $http = null,
        /** Versione di EMP Server che ha installato il Core (null fuori da EMP Server). */
        public readonly ?string $serverVersion = null,
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

        $present = [];
        foreach ($available as $m) {
            if (isset($installed[$m->id])) {
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
        }
        $missing = array_diff(array_keys($installed), array_map(static fn (Manifest $m): string => $m->id, $present));
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

    /** Gestione dei plugin (pagina web Plugin del Core). */
    public function plugins(): PluginManager
    {
        return new PluginManager(
            $this->modulesDir,
            new Catalog($this->catalogDir),
            $this->installer,
            PHP_VERSION,
            $this->localCatalogDir !== null ? new LocalCatalog($this->localCatalogDir) : null,
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
