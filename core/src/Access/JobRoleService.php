<?php

declare(strict_types=1);

namespace Emp\Core\Access;

use Emp\Core\Module\Manifest;
use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;

/**
 * Ruoli di negozio (Cassiere, Magazziniere, Store Manager…): ciascuno è un insieme di permessi
 * dichiarati dai moduli installati (campo "permissions" di emp-module.json). Si assegnano agli
 * operatori; un operatore può avere più ruoli e ha l'unione dei loro permessi.
 *
 * - Gli amministratori hanno tutti i permessi gestionali; il Tecnico nessuno (funzioni tecniche).
 * - Ruoli e assegnazioni li gestiscono il Tecnico e gli amministratori.
 * - Per i plugin: $kernel->jobRoles()->allows($user, 'magazzino.carico').
 */
final class JobRoleService
{
    private readonly string $roles;
    private readonly string $grants;
    private readonly string $assignments;
    private readonly string $users;
    private readonly AuditLog $audit;

    /** @param list<Manifest> $modules moduli avviati: i loro permessi sono quelli assegnabili */
    public function __construct(private readonly \PDO $pdo, string $tablePrefix, private readonly Clock $clock, private readonly array $modules)
    {
        $this->roles = $tablePrefix . 'ruoli';
        $this->grants = $tablePrefix . 'ruoli_permessi';
        $this->assignments = $tablePrefix . 'utenti_ruoli';
        $this->users = $tablePrefix . 'users';
        $this->audit = new AuditLog($pdo, $tablePrefix . 'audit', $clock);
    }

    /** @return list<array{permesso: string, modulo: string, nome_modulo: string}> permessi dei moduli avviati */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->modules as $m) {
            foreach ($m->permissions as $p) {
                $out[] = ['permesso' => $p, 'modulo' => $m->id, 'nome_modulo' => $m->name];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> ruoli con permessi e numero di utenti */
    public function list(): array
    {
        $rows = $this->pdo->query("SELECT r.*, (SELECT COUNT(*) FROM {$this->assignments} a WHERE a.ruolo = r.codice) AS utenti FROM {$this->roles} r ORDER BY r.nome")
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $perms = [];
        foreach ($this->pdo->query("SELECT ruolo, permesso FROM {$this->grants} ORDER BY permesso")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $g) {
            $perms[(string) $g['ruolo']][] = (string) $g['permesso'];
        }

        return array_map(static fn (array $r): array => [
            'codice' => (string) $r['codice'],
            'nome' => (string) $r['nome'],
            'descrizione' => (string) $r['descrizione'],
            'permessi' => $perms[(string) $r['codice']] ?? [],
            'utenti' => (int) $r['utenti'],
            'modificato_il' => (string) $r['modificato_il'],
            'modificato_da' => (string) $r['modificato_da'],
        ], $rows);
    }

    /**
     * Crea (codice null) o modifica un ruolo. Si accettano solo permessi dei moduli avviati;
     * quelli di moduli non più avviati, già assegnati, restano finché non si tolgono.
     *
     * @param list<string> $permissions
     */
    public function save(User $actor, ?string $code, string $name, string $description, array $permissions): string
    {
        $this->assertManager($actor);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
            throw new AccessException('Nome del ruolo non valido (1–80 caratteri)', 422);
        }
        $description = trim($description);
        if (mb_strlen($description) > 255 || preg_match('/[\x00-\x1F\x7F<>]/u', $description)) {
            throw new AccessException('Descrizione del ruolo non valida (massimo 255 caratteri)', 422);
        }
        $known = array_column($this->catalog(), 'permesso');
        $before = $code !== null ? $this->find($code) : null;
        if ($code !== null && $before === null) {
            throw new AccessException('Ruolo non trovato', 404);
        }
        $beforePerms = $before !== null ? $this->permissionsOf((string) $code) : [];
        $kept = array_values(array_diff($beforePerms, $known));
        $permissions = array_values(array_unique(array_map('strval', $permissions)));
        foreach ($permissions as $p) {
            if (!in_array($p, $known, true) && !in_array($p, $kept, true)) {
                throw new AccessException('Permesso sconosciuto: ' . $p, 422);
            }
        }
        sort($permissions);
        $now = $this->clock->nowForDatabase();

        $this->pdo->beginTransaction();
        try {
            if ($before === null) {
                $code = $this->newCode($name);
                $this->pdo->prepare("INSERT INTO {$this->roles} (codice, nome, descrizione, creato_il, creato_da, modificato_il, modificato_da) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$code, $name, $description, $now, $actor->username, $now, $actor->username]);
            } else {
                $this->pdo->prepare("UPDATE {$this->roles} SET nome = ?, descrizione = ?, modificato_il = ?, modificato_da = ? WHERE codice = ?")
                    ->execute([$name, $description, $now, $actor->username, $code]);
                $this->pdo->prepare("DELETE FROM {$this->grants} WHERE ruolo = ?")->execute([$code]);
            }
            $ins = $this->pdo->prepare("INSERT INTO {$this->grants} (ruolo, permesso) VALUES (?, ?)");
            foreach ($permissions as $p) {
                $ins->execute([$code, $p]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->audit->record($actor->username, AccessService::MODULE, $before === null ? 'ruolo_creato' : 'ruolo_modificato', 'ruolo', (string) $code, 0,
            $before !== null ? (json_encode(['nome' => $before['nome'], 'permessi' => $beforePerms], JSON_UNESCAPED_UNICODE) ?: null) : null,
            json_encode(['nome' => $name, 'permessi' => $permissions], JSON_UNESCAPED_UNICODE) ?: null);

        return (string) $code;
    }

    public function delete(User $actor, string $code): void
    {
        $this->assertManager($actor);
        $role = $this->find($code);
        if ($role === null) {
            throw new AccessException('Ruolo non trovato', 404);
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->assignments} WHERE ruolo = ?");
        $stmt->execute([$code]);
        $n = (int) $stmt->fetchColumn();
        if ($n > 0) {
            throw new AccessException(sprintf('Il ruolo "%s" è assegnato a %d utenti: toglierlo prima a loro', $role['nome'], $n), 409);
        }
        $this->pdo->prepare("DELETE FROM {$this->grants} WHERE ruolo = ?")->execute([$code]);
        $this->pdo->prepare("DELETE FROM {$this->roles} WHERE codice = ?")->execute([$code]);
        $this->audit->record($actor->username, AccessService::MODULE, 'ruolo_eliminato', 'ruolo', $code, 0, (string) $role['nome'], null);
    }

    /** @return array<string, list<string>> user_id => codici dei ruoli */
    public function assignmentsByUser(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT user_id, ruolo FROM {$this->assignments} ORDER BY ruolo")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $a) {
            $out[(string) $a['user_id']][] = (string) $a['ruolo'];
        }

        return $out;
    }

    /**
     * Ruoli di un operatore (sostituisce quelli che aveva). $target deve essere gestibile da $actor
     * (lo controlla AccessService::manageable prima della chiamata).
     *
     * @param list<string> $codes
     */
    public function assign(User $actor, User $target, array $codes): void
    {
        $this->assertManager($actor);
        if ($target->role !== Role::OPERATORE) {
            throw new AccessException('I ruoli si assegnano agli operatori: gli amministratori hanno già tutti i permessi gestionali', 422);
        }
        $codes = array_values(array_unique(array_map('strval', $codes)));
        foreach ($codes as $c) {
            if ($this->find($c) === null) {
                throw new AccessException('Ruolo sconosciuto: ' . $c, 422);
            }
        }
        sort($codes);
        $before = $this->assignmentsByUser()[$target->id] ?? [];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM {$this->assignments} WHERE user_id = ?")->execute([$target->id]);
            $ins = $this->pdo->prepare("INSERT INTO {$this->assignments} (user_id, ruolo) VALUES (?, ?)");
            foreach ($codes as $c) {
                $ins->execute([$target->id, $c]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->audit->record($actor->username, AccessService::MODULE, 'ruoli_assegnati', 'utente', $target->id, 0, implode(', ', $before), implode(', ', $codes));
    }

    /** @return list<string> permessi effettivi dell'utente */
    public function permissionsFor(User $user): array
    {
        if ($user->role === Role::AMMINISTRATORE) {
            return array_values(array_unique(array_column($this->catalog(), 'permesso')));
        }
        if ($user->role !== Role::OPERATORE) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT DISTINCT g.permesso FROM {$this->assignments} a JOIN {$this->grants} g ON g.ruolo = a.ruolo WHERE a.user_id = ? ORDER BY g.permesso");
        $stmt->execute([$user->id]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Il controllo da usare nei plugin prima di ogni funzione protetta. */
    public function allows(User $user, string $permission): bool
    {
        return $user->active && in_array($permission, $this->permissionsFor($user), true);
    }

    // ------------------------------------------------------------------ interni

    private function assertManager(User $actor): void
    {
        if (!in_array($actor->role, [Role::TECNICO, Role::AMMINISTRATORE], true)) {
            throw new AccessException('Ruoli e permessi li gestiscono il Tecnico e gli amministratori', 403);
        }
    }

    /** @return array<string, mixed>|null */
    private function find(string $code): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->roles} WHERE codice = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    private function permissionsOf(string $code): array
    {
        $stmt = $this->pdo->prepare("SELECT permesso FROM {$this->grants} WHERE ruolo = ? ORDER BY permesso");
        $stmt->execute([$code]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function newCode(string $name): string
    {
        $ascii = function_exists('iconv') ? (@iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name) : $name;
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii));
        $base = trim(substr($base, 0, 50), '-') ?: 'ruolo';
        $code = $base;
        for ($i = 2; $this->find($code) !== null; $i++) {
            $code = $base . '-' . $i;
        }

        return $code;
    }
}
