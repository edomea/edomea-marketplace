<?php

declare(strict_types=1);

namespace Emp\Core\Module;

/**
 * Scopre i moduli presenti nella cartella modules/ (una sottocartella per modulo,
 * con emp-module.json alla radice) e ne restituisce i manifest validati.
 */
final class Registry
{
    public function __construct(private readonly string $modulesDir)
    {
    }

    /** @return list<Manifest> */
    public function discover(): array
    {
        if (!is_dir($this->modulesDir)) {
            throw new ManifestException(sprintf('Cartella moduli inesistente: %s', $this->modulesDir));
        }
        $found = [];
        $entries = scandir($this->modulesDir);
        if ($entries === false) {
            throw new ManifestException(sprintf('Cartella moduli illeggibile: %s', $this->modulesDir));
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $this->modulesDir . DIRECTORY_SEPARATOR . $entry;
            // I collegamenti simbolici non sono ammessi: un modulo è una cartella reale.
            if (!is_dir($path) || is_link($path) || !is_file($path . DIRECTORY_SEPARATOR . Manifest::FILE)) {
                continue;
            }
            $found[] = Manifest::fromDirectory($path);
        }
        usort($found, static fn (Manifest $a, Manifest $b): int => strcmp($a->id, $b->id));

        return $found;
    }
}
