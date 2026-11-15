<?php

declare(strict_types=1);

namespace Emp\Core\Module;

use Emp\Core\Version\InvalidVersion;
use Emp\Core\Version\Version;

/**
 * Calcola l'ordine di installazione e di avvio dei moduli.
 *
 * Regole (docs/architettura-moduli.md §1 e §4):
 * - emp-core sempre presente e sempre primo;
 * - ogni dipendenza deve esistere ed essere compatibile;
 * - nessun ciclo; nessun table_prefix condiviso;
 * - a parità di dipendenze l'ordine è alfabetico per id (deterministico).
 */
final class Resolver
{
    /**
     * @param list<Manifest> $available moduli disponibili
     * @param list<string>|null $selected id da installare (le dipendenze vengono aggiunte); null = tutti
     * @param string $phpVersion versione PHP in uso (es. PHP_VERSION)
     * @return list<Manifest> in ordine di installazione
     */
    public function resolve(array $available, ?array $selected = null, string $phpVersion = PHP_VERSION): array
    {
        $byId = [];
        foreach ($available as $m) {
            if (isset($byId[$m->id])) {
                throw new ResolutionException(sprintf('Modulo duplicato: "%s"', $m->id));
            }
            $byId[$m->id] = $m;
        }
        if (!isset($byId[Manifest::CORE_ID])) {
            throw new ResolutionException('Il Core (emp-core) non è disponibile: installare prima il Core');
        }

        // Chiusura delle dipendenze a partire dalla selezione (Core sempre incluso).
        $wanted = [Manifest::CORE_ID => true];
        $queue = $selected ?? array_keys($byId);
        while ($queue !== []) {
            $id = array_shift($queue);
            if (!isset($byId[$id])) {
                throw new ResolutionException(sprintf('Modulo richiesto non disponibile: "%s"', $id));
            }
            if (isset($wanted[$id]) && $id !== Manifest::CORE_ID) {
                continue;
            }
            $wanted[$id] = true;
            foreach (array_keys($byId[$id]->requires) as $dep) {
                if (!isset($wanted[$dep])) {
                    $queue[] = $dep;
                }
            }
        }

        $php = $this->phpVersion($phpVersion);
        $prefixes = [];
        foreach (array_keys($wanted) as $id) {
            $m = $byId[$id];
            if (!$m->php->isSatisfiedBy($php)) {
                throw new ResolutionException(sprintf('"%s" richiede PHP %s (in uso %s)', $id, $m->php, $phpVersion));
            }
            foreach ($m->requires as $dep => $constraint) {
                if (!isset($byId[$dep])) {
                    throw new ResolutionException(sprintf('"%s" richiede "%s", che non è disponibile', $id, $dep));
                }
                if (!$constraint->isSatisfiedBy($byId[$dep]->version)) {
                    throw new ResolutionException(sprintf(
                        '"%s" richiede "%s" %s, disponibile %s',
                        $id,
                        $dep,
                        $constraint,
                        $byId[$dep]->version,
                    ));
                }
            }
            if ($m->tablePrefix !== null) {
                if (isset($prefixes[$m->tablePrefix])) {
                    throw new ResolutionException(sprintf(
                        'Prefisso tabelle "%s" usato sia da "%s" sia da "%s"',
                        $m->tablePrefix,
                        $prefixes[$m->tablePrefix],
                        $id,
                    ));
                }
                $prefixes[$m->tablePrefix] = $id;
            }
        }

        // Ordinamento topologico (Kahn) con scelta alfabetica tra i pronti.
        $pending = [];
        $dependents = [];
        foreach (array_keys($wanted) as $id) {
            $pending[$id] = count($byId[$id]->requires);
            foreach (array_keys($byId[$id]->requires) as $dep) {
                $dependents[$dep][] = $id;
            }
        }
        $ready = array_keys(array_filter($pending, static fn (int $n): bool => $n === 0));
        $order = [];
        while ($ready !== []) {
            sort($ready, SORT_STRING);
            // Il Core, se pronto, precede sempre.
            $pos = array_search(Manifest::CORE_ID, $ready, true);
            $id = $pos === false ? array_shift($ready) : array_splice($ready, (int) $pos, 1)[0];
            $order[] = $byId[$id];
            foreach ($dependents[$id] ?? [] as $child) {
                if (--$pending[$child] === 0) {
                    $ready[] = $child;
                }
            }
        }
        if (count($order) !== count($wanted)) {
            $stuck = array_keys(array_filter($pending, static fn (int $n): bool => $n > 0));
            sort($stuck);
            throw new ResolutionException('Dipendenze circolari tra: ' . implode(', ', $stuck));
        }
        if ($order[0]->id !== Manifest::CORE_ID) {
            throw new ResolutionException('Il Core deve essere installato per primo');
        }

        return $order;
    }

    private function phpVersion(string $text): Version
    {
        // PHP_VERSION può contenere suffissi (es. 8.3.6-1ubuntu): si confrontano solo i tre numeri.
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $text, $m)) {
            throw new ResolutionException(sprintf('Versione PHP non riconosciuta: %s', $text));
        }
        try {
            return Version::parse("{$m[1]}.{$m[2]}.{$m[3]}");
        } catch (InvalidVersion $e) {
            throw new ResolutionException($e->getMessage(), 0, $e);
        }
    }
}
