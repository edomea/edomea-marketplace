<?php

declare(strict_types=1);

namespace Emp\Core;

use Emp\Core\Module\Installer;
use Emp\Core\Support\Clock;

/**
 * Configurazione dell'installazione. Il file sta fuori dalla cartella pubblica
 * e fuori da Git; il percorso arriva dalla variabile d'ambiente EMP_CONFIG,
 * impostata da EMP Desk (Server) o dall'amministratore (Web).
 *
 * Il file restituisce un array PHP:
 *   ['dsn' => 'mysql:unix_socket=…;dbname=emp', 'user' => …, 'password' => …,
 *    'prefix' => 'emp_', 'timezone' => 'Europe/Rome', 'modules_dir' => '/…/modules',
 *    'catalog_dir' => '/…/catalogo']  // facoltativo: pacchetti dei plugin installabili dal web
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

        return new Kernel(
            $this->modulesDir(),
            $this->installer(),
            $this->clock(),
            is_string($catalog) && $catalog !== '' ? $catalog : null,
        );
    }
}
