<?php

declare(strict_types=1);

namespace Emp\Core\Module;

use Emp\Core\Support\Clock;
use Emp\Core\Version\Version;

/**
 * Installa, aggiorna e disinstalla i moduli eseguendo le migrazioni SQL.
 * Vedi docs/architettura-moduli.md §5.
 *
 * Tabelle di sistema (create dall'installer, con il prefisso dell'installazione):
 * - {p}modules: moduli installati e versione;
 * - {p}migrations: migrazioni applicate con impronta, utente e ora locale.
 */
final class Installer
{
    private const MIGRATION_PATTERN = '/^\d{4}_[a-z0-9_]+\.sql$/';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
        private readonly string $installPrefix = 'emp_',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}_$/', $installPrefix)) {
            throw new \InvalidArgumentException('Prefisso installazione non valido');
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** Connessione al database dell'installazione (per i servizi del Core). */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** Prefisso completo delle tabelle di un modulo: installazione + table_prefix. */
    public function tablePrefix(string $moduleTablePrefix): string
    {
        return $this->installPrefix . $moduleTablePrefix;
    }

    public function ensureSystemTables(): void
    {
        $p = $this->installPrefix;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$p}modules (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            version VARCHAR(64) NOT NULL,
            installed_at DATETIME NOT NULL,
            installed_by VARCHAR(191) NOT NULL,
            updated_at DATETIME NOT NULL,
            updated_by VARCHAR(191) NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$p}migrations (
            module VARCHAR(64) NOT NULL,
            file VARCHAR(128) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL,
            applied_by VARCHAR(191) NOT NULL,
            PRIMARY KEY (module, file)
        )");
    }

    /** @return array<string, string> id => versione installata */
    public function installed(): array
    {
        $this->ensureSystemTables();
        $rows = $this->pdo->query("SELECT id, version FROM {$this->installPrefix}modules ORDER BY id")
            ?->fetchAll(\PDO::FETCH_KEY_PAIR);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Installa o aggiorna i moduli nell'ordine dato (calcolato dal Resolver).
     *
     * @param list<Manifest> $ordered
     * @param string $actor utente che esegue l'operazione (tracciabilità)
     * @return list<string> descrizione delle operazioni eseguite
     */
    public function install(array $ordered, string $actor): array
    {
        $this->assertActor($actor);
        $this->ensureSystemTables();
        $installed = $this->installed();
        $log = [];

        foreach ($ordered as $m) {
            if (!$m->isCore() && !isset($installed[Manifest::CORE_ID])) {
                throw new ResolutionException('Il Core deve essere installato prima dei plugin');
            }
            foreach ($m->requires as $dep => $constraint) {
                $have = $installed[$dep] ?? null;
                if ($have === null || !$constraint->isSatisfiedBy(Version::parse($have))) {
                    throw new ResolutionException(sprintf('"%s" richiede "%s" %s installato prima', $m->id, $dep, $constraint));
                }
            }
            $current = $installed[$m->id] ?? null;
            if ($current !== null && Version::parse($current)->compare($m->version) > 0) {
                throw new ResolutionException(sprintf(
                    '"%s": la versione %s è inferiore a quella installata (%s). Il ritorno a versioni precedenti avviene solo da backup.',
                    $m->id,
                    $m->version,
                    $current,
                ));
            }

            $applied = $this->runMigrations($m, $actor);
            $now = $this->clock->nowForDatabase();
            if ($current === null) {
                $stmt = $this->pdo->prepare("INSERT INTO {$this->installPrefix}modules (id, version, installed_at, installed_by, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$m->id, (string) $m->version, $now, $actor, $now, $actor]);
                $log[] = sprintf('Installato %s %s (%d migrazioni)', $m->id, $m->version, $applied);
            } elseif ($current !== (string) $m->version || $applied > 0) {
                $stmt = $this->pdo->prepare("UPDATE {$this->installPrefix}modules SET version = ?, updated_at = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([(string) $m->version, $now, $actor, $m->id]);
                $log[] = sprintf('Aggiornato %s da %s a %s (%d migrazioni)', $m->id, $current, $m->version, $applied);
            } else {
                $log[] = sprintf('%s %s già installato', $m->id, $m->version);
            }
            $installed[$m->id] = (string) $m->version;
        }

        return $log;
    }

    /**
     * Verifica che le migrazioni già applicate non siano state modificate.
     *
     * @param list<Manifest> $manifests
     */
    public function verify(array $manifests): void
    {
        $this->ensureSystemTables();
        $stmt = $this->pdo->prepare("SELECT file, sha256 FROM {$this->installPrefix}migrations WHERE module = ?");
        foreach ($manifests as $m) {
            $stmt->execute([$m->id]);
            /** @var array<string, string> $applied */
            $applied = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
            $files = $this->migrationFiles($m);
            foreach ($applied as $file => $hash) {
                if (!isset($files[$file])) {
                    throw new ResolutionException(sprintf('"%s": migrazione applicata "%s" assente dal pacchetto', $m->id, $file));
                }
                if (!hash_equals($hash, (string) hash_file('sha256', $files[$file]))) {
                    throw new ResolutionException(sprintf('"%s": la migrazione "%s" è stata modificata dopo l\'applicazione', $m->id, $file));
                }
            }
        }
    }

    /**
     * Rimuove un modulo dal registro. I dati e le tabelle restano (nessuna
     * cancellazione automatica di dati commerciali).
     *
     * @param list<Manifest> $available manifest presenti, per controllare chi dipende dal modulo
     */
    public function uninstall(string $id, array $available, string $actor): void
    {
        $this->assertActor($actor);
        if ($id === Manifest::CORE_ID) {
            throw new ResolutionException('Il Core non può essere disinstallato');
        }
        $installed = $this->installed();
        if (!isset($installed[$id])) {
            throw new ResolutionException(sprintf('"%s" non è installato', $id));
        }
        $dependents = [];
        foreach ($available as $m) {
            if (isset($installed[$m->id]) && isset($m->requires[$id])) {
                $dependents[] = $m->id;
            }
        }
        if ($dependents !== []) {
            sort($dependents);
            throw new ResolutionException(sprintf('"%s" è richiesto da: %s', $id, implode(', ', $dependents)));
        }
        $this->pdo->prepare("DELETE FROM {$this->installPrefix}modules WHERE id = ?")->execute([$id]);
    }

    private function runMigrations(Manifest $m, string $actor): int
    {
        $files = $this->migrationFiles($m);
        if ($files === []) {
            return 0;
        }
        $stmt = $this->pdo->prepare("SELECT file, sha256 FROM {$this->installPrefix}migrations WHERE module = ?");
        $stmt->execute([$m->id]);
        /** @var array<string, string> $applied */
        $applied = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        $tablePrefix = $this->installPrefix . (string) $m->tablePrefix;
        $count = 0;

        foreach ($files as $file => $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new ResolutionException(sprintf('Migrazione illeggibile: %s', $path));
            }
            $hash = hash('sha256', $sql);
            if (isset($applied[$file])) {
                if (!hash_equals($applied[$file], $hash)) {
                    throw new ResolutionException(sprintf('"%s": la migrazione "%s" è stata modificata dopo l\'applicazione', $m->id, $file));
                }
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                foreach (self::splitStatements(str_replace('{prefix}', $tablePrefix, $sql)) as $statement) {
                    $this->pdo->exec($statement);
                }
                $this->pdo->prepare("INSERT INTO {$this->installPrefix}migrations (module, file, sha256, applied_at, applied_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$m->id, $file, $hash, $this->clock->nowForDatabase(), $actor]);
                // In MariaDB le DDL chiudono implicitamente la transazione.
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new ResolutionException(sprintf('"%s": migrazione "%s" non riuscita: %s', $m->id, $file, $e->getMessage()), 0, $e);
            }
            $count++;
        }

        return $count;
    }

    /** @return array<string, string> nome file => percorso, in ordine */
    private function migrationFiles(Manifest $m): array
    {
        if ($m->migrations === null) {
            return [];
        }
        $base = realpath($m->directory);
        $dir = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . $m->migrations);
        if ($base === false || $dir === false || !str_starts_with($dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) {
            throw new ManifestException(sprintf('"%s": cartella migrazioni non valida', $m->id));
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (preg_match(self::MIGRATION_PATTERN, $name) && is_file($path) && !is_link($path)) {
                $files[$name] = $path;
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Divide uno script in istruzioni sul ";" a fine riga. Le migrazioni EMP
     * non usano procedure o trigger con ";" interni.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || mb_strlen($actor) > 191) {
            throw new \InvalidArgumentException('Utente esecutore obbligatorio (tracciabilità)');
        }
    }
}
