<?php

declare(strict_types=1);

namespace Emp\Core\Module;

/**
 * Autoloader PSR-4 dei moduli, senza Composer a runtime (regola: solo PHP).
 * Ogni namespace punta a una cartella interna al modulo.
 */
final class Psr4Autoloader
{
    /** @var array<string, string> namespace => cartella assoluta */
    private array $map = [];
    private bool $registered = false;

    public function addModule(Manifest $manifest): void
    {
        $base = realpath($manifest->directory);
        if ($base === false) {
            throw new ManifestException(sprintf('Cartella del modulo inesistente: %s', $manifest->directory));
        }
        foreach ($manifest->autoload as $ns => $relative) {
            $dir = realpath($base . DIRECTORY_SEPARATOR . $relative);
            if ($dir === false || !str_starts_with($dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) {
                throw new ManifestException(sprintf('Cartella autoload non valida in "%s"', $manifest->id));
            }
            if (isset($this->map[$ns]) && $this->map[$ns] !== $dir) {
                throw new ManifestException(sprintf('Namespace "%s" dichiarato da più moduli', $ns));
            }
            $this->map[$ns] = $dir;
        }
        // Prefissi più lunghi per primi, così un namespace annidato vince sul padre.
        uksort($this->map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        if (!$this->registered) {
            spl_autoload_register($this->load(...));
            $this->registered = true;
        }
    }

    private function load(string $class): void
    {
        foreach ($this->map as $ns => $dir) {
            if (!str_starts_with($class, $ns)) {
                continue;
            }
            $relative = substr($class, strlen($ns));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative)) {
                continue;
            }
            $file = $dir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
