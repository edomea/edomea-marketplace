<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

/**
 * Cartella di arrivo dei pacchetti dei plugin caricati via scp
 * (/var/lib/emp/arrivi/plugin, scrivibile dagli utenti abilitati con
 * "sudo emp-server accesso-scp NOME").
 *
 * Gli ZIP arrivati non vengono usati da soli: il Tecnico li vede nella pagina
 * Plugin e li aggiunge al catalogo locale, con gli stessi controlli del
 * caricamento dal browser. Dopo l'aggiunta (o su richiesta) il file viene eliminato.
 */
final class Arrivals
{
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.zip$/';

    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** @return list<array{nome: string, dimensione: int, arrivato_il: string, leggibile: bool}> */
    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            $path = $this->dir . DIRECTORY_SEPARATOR . $name;
            if (!preg_match(self::NAME_PATTERN, $name) || is_link($path) || !is_file($path)) {
                continue;
            }
            $out[] = [
                'nome' => $name,
                'dimensione' => (int) filesize($path),
                'arrivato_il' => date(\DateTimeInterface::ATOM, (int) filemtime($path)),
                'leggibile' => is_readable($path),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['arrivato_il'], $a['arrivato_il']));

        return $out;
    }

    /** Percorso di un file arrivato, dopo aver controllato il nome (niente percorsi o collegamenti). */
    public function path(string $name): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        if (!preg_match(self::NAME_PATTERN, $name) || str_contains($name, '..') || is_link($path) || !is_file($path)) {
            throw new PackageException('File non presente tra gli arrivi');
        }
        if (!is_readable($path)) {
            throw new PackageException("{$name} non è leggibile dal Core: ricaricarlo con permessi di lettura (es. chmod 644)");
        }

        return $path;
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (!@unlink($path)) {
            throw new PackageException("Impossibile eliminare {$name}");
        }
    }
}
