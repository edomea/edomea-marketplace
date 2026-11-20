<?php

declare(strict_types=1);

namespace Emp\Core\Module;

/**
 * Avvio dei plugin deciso dalla console del Server, per uscire da un plugin che blocca EMP:
 *
 *   sudo emp-server plugin-disattiva NOME   il plugin resta installato (dati compresi) ma non si avvia
 *   sudo emp-server avvio-sicuro            si avvia solo il Core (pagine /techadmin e /admin raggiungibili)
 *
 * File /etc/emp/avvio.conf, di root e leggibile da EMP, righe CHIAVE=valore:
 *   AVVIO_SICURO=si|no
 *   DISATTIVATI=emp-uno,emp-due
 * Un file assente o illeggibile vale "tutto attivo". Righe sconosciute o non valide sono ignorate.
 */
final class BootControl
{
    public const SAFE_MODE = 'avvio sicuro: si avvia solo il Core';
    public const DISABLED = 'disattivato dalla console del Server';

    /** @param list<string> $disabled */
    private function __construct(public readonly bool $safeMode, public readonly array $disabled)
    {
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public static function fromFile(?string $path): self
    {
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return self::none();
        }
        $text = @file_get_contents($path, false, null, 0, 65536);

        return $text === false ? self::none() : self::parse($text);
    }

    public static function parse(string $text): self
    {
        $safe = false;
        $disabled = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === 'AVVIO_SICURO') {
                $safe = $value === 'si';
            } elseif ($key === 'DISATTIVATI') {
                foreach (explode(',', $value) as $id) {
                    $id = trim($id);
                    if ($id !== Manifest::CORE_ID && preg_match('/^[a-z][a-z0-9-]{2,63}$/', $id)) {
                        $disabled[$id] = true;
                    }
                }
            }
        }
        $ids = array_keys($disabled);
        sort($ids);

        return new self($safe, $ids);
    }

    /**
     * Moduli da non avviare, con il motivo. Chi dipende da un modulo escluso resta escluso anche lui.
     *
     * @param list<Manifest> $modules moduli installati presenti sul disco
     * @return array<string, string> id => motivo
     */
    public function skipped(array $modules): array
    {
        $skip = [];
        foreach ($modules as $m) {
            if ($m->isCore()) {
                continue;
            }
            if ($this->safeMode) {
                $skip[$m->id] = self::SAFE_MODE;
            } elseif (in_array($m->id, $this->disabled, true)) {
                $skip[$m->id] = self::DISABLED;
            }
        }
        do {
            $changed = false;
            foreach ($modules as $m) {
                if (isset($skip[$m->id]) || $m->isCore()) {
                    continue;
                }
                foreach (array_keys($m->requires) as $dep) {
                    if (isset($skip[$dep])) {
                        $skip[$m->id] = sprintf('non avviato: dipende da %s, che è fermo', $dep);
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        ksort($skip);

        return $skip;
    }
}
