<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Module\Installer;
use Emp\Core\Support\Clock;

/**
 * Configurazione dell'installazione. Il file sta fuori dalla cartella pubblica
 * e fuori da Git; il percorso arriva dalla variabile d'ambiente EMP_CONFIG,
 * impostata da EMP Server (PHP-FPM, /etc/emp/config.php) o dall'amministratore (Web).
 *
 * Il file restituisce un array PHP:
 *   ['dsn' => 'mysql:unix_socket=…;dbname=emp', 'user' => …, 'password' => …,
 *    'prefix' => 'emp_', 'timezone' => 'Europe/Rome', 'modules_dir' => '/…/modules',
 *    'catalog_dir' => '/…/catalogo',          // facoltativo: catalogo di EMP Server (sola lettura)
 *    'local_catalog_dir' => '/…/catalogo-locale',  // facoltativo: pacchetti caricati a mano o importati da GitHub
 *    'server_version' => '0.6.0-alpha.7',         // facoltativo: versione di EMP Server, mostrata nelle pagine
 *    'arrivals_dir' => '/…/arrivi/plugin',        // facoltativo: plugin caricati via scp
 *    'backup_web_dir' => '/var/lib/emp/backup-web',  // facoltativo: scambio con il servizio dei backup di EMP Server
 *    'boot_control_file' => '/etc/emp/avvio.conf',
 *    'module_data_dir' => '/var/lib/emp/dati-moduli']  // facoltativo: file dei plugin (foto…)    // facoltativo: avvio sicuro e plugin disattivati dalla console
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= getenv('EMP_CONFIG') ?: '';
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Configurazione EMP non trovata (variabile EMP_CONFIG)');
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('Configurazione EMP non valida');
        }
        foreach (['dsn', 'modules_dir'] as $key) {
            if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
                throw new \RuntimeException(sprintf('Configurazione EMP: manca "%s"', $key));
            }
        }

        return new self($values);
    }

    public function pdo(): \PDO
    {
        return new \PDO(
            (string) $this->values['dsn'],
            isset($this->values['user']) ? (string) $this->values['user'] : null,
            isset($this->values['password']) ? (string) $this->values['password'] : null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    public function clock(): Clock
    {
        return new Clock((string) ($this->values['timezone'] ?? 'Europe/Rome'));
    }

    public function modulesDir(): string
    {
        return (string) $this->values['modules_dir'];
    }

    public function installer(): Installer
    {
        return new Installer($this->pdo(), $this->clock(), (string) ($this->values['prefix'] ?? 'emp_'));
    }

    public function kernel(): Kernel
    {
        $catalog = $this->values['catalog_dir'] ?? null;
        $local = $this->values['local_catalog_dir'] ?? null;
        $server = $this->values['server_version'] ?? null;
        $arrivals = $this->values['arrivals_dir'] ?? null;
        $backup = $this->values['backup_web_dir'] ?? null;
        $boot = $this->values['boot_control_file'] ?? null;
        $moduleData = $this->values['module_data_dir'] ?? null;

        return new Kernel(
            $this->modulesDir(),
            $this->installer(),
            $this->clock(),
            is_string($catalog) && $catalog !== '' ? $catalog : null,
            is_string($local) && $local !== '' ? $local : null,
            null,
            is_string($server) && preg_match('/^[0-9A-Za-z.+~-]{1,40}$/', $server) ? $server : null,
            is_string($arrivals) && $arrivals !== '' ? $arrivals : null,
            is_string($backup) && $backup !== '' ? $backup : null,
            is_string($boot) && $boot !== '' ? $boot : null,
            is_string($moduleData) && $moduleData !== '' ? $moduleData : null,
        );
    }
}
