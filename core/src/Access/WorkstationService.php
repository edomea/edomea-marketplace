<?php

declare(strict_types=1);

namespace Emp\Core\Access;

use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;
use Emp\Core\Support\Uuid;

/**
 * Postazioni autorizzate del negozio. Le postazioni sono browser (nessuna app da installare):
 * una postazione autorizzata conserva un token permanente nel cookie emp_postazione.
 *
 * Due modi per autorizzarla:
 *  - dalla postazione stessa, con un amministratore collegato ("Autorizza questa postazione");
 *  - con un codice di associazione monouso (30 minuti) creato da un amministratore e
 *    inserito sulla postazione, senza che l'amministratore debba collegarsi lì.
 *
 * Con l'obbligo attivo (lo decide il Tecnico) gli OPERATORI accedono solo da postazioni
 * autorizzate; Tecnico e amministratori restano liberi, per non chiudersi fuori.
 * Revocare una postazione chiude subito le sessioni aperte da lì.
 */
final class WorkstationService
{
    public const COOKIE = 'emp_postazione';
    public const COOKIE_DAYS = 1825;
    public const CODE_MINUTES = 30;
    private const SETTING_REQUIRED = 'postazioni_obbligatorie';
    private const MAX_CODE_FAILURES_PER_IP = 10;
    private const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private readonly string $table;
    private readonly string $sessions;
    private readonly string $settings;
    private readonly string $attempts;
    private readonly AuditLog $audit;

    public function __construct(private readonly \PDO $pdo, string $tablePrefix, private readonly Clock $clock)
    {
        $this->table = $tablePrefix . 'postazioni';
        $this->sessions = $tablePrefix . 'sessions';
        $this->settings = $tablePrefix . 'settings';
        $this->attempts = $tablePrefix . 'login_attempts';
        $this->audit = new AuditLog($pdo, $tablePrefix . 'audit', $clock);
    }

    // ------------------------------------------------------------------ obbligo

    public function isRequired(): bool
    {
        $stmt = $this->pdo->prepare("SELECT value FROM {$this->settings} WHERE name = ?");
        $stmt->execute([self::SETTING_REQUIRED]);

        return $stmt->fetchColumn() === 'si';
    }

    public function setRequired(User $actor, bool $required): void
    {
        if ($actor->role !== Role::TECNICO) {
            throw new AccessException('Solo l\'Amministratore Tecnico decide l\'obbligo delle postazioni', 403);
        }
        if ($required && $this->countActive() === 0) {
            throw new AccessException('Autorizzare almeno una postazione prima di rendere obbligatorio l\'accesso dalle postazioni', 409);
        }
        $before = $this->isRequired();
        $now = $this->now();
        $upd = $this->pdo->prepare("UPDATE {$this->settings} SET value = ?, updated_at = ?, updated_by = ? WHERE name = ?");
        $upd->execute([$required ? 'si' : 'no', $now, $actor->username, self::SETTING_REQUIRED]);
        if ($upd->rowCount() === 0) {
            $this->pdo->prepare("INSERT INTO {$this->settings} (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)")
                ->execute([self::SETTING_REQUIRED, $required ? 'si' : 'no', $now, $actor->username]);
        }
        $this->audit->record($actor->username, AccessService::MODULE, 'postazioni_obbligo', 'impostazione', self::SETTING_REQUIRED, 0,
            $before ? 'si' : 'no', $required ? 'si' : 'no');
    }

    /** Il ruolo deve accedere da una postazione autorizzata? */
    public function requiredFor(string $role): bool
    {
        return $role === Role::OPERATORE && $this->isRequired();
    }

    // ------------------------------------------------------------------ elenco e gestione

    /** @return list<array<string, mixed>> postazioni, la più recente per prima (senza token né codici) */
    public function list(?string $currentToken): array
    {
        $current = $this->identify($currentToken, null);
        $rows = $this->pdo->query("SELECT * FROM {$this->table} ORDER BY creata_il DESC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if ($r['stato'] === 'in_attesa' && (string) $r['codice_scade'] < $this->now()) {
                continue; // codice scaduto e mai usato: non è una postazione
            }
            $out[] = [
                'id' => (string) $r['id'],
                'nome' => (string) $r['nome'],
                'stato' => (string) $r['stato'],
                'creata_il' => (string) $r['creata_il'],
                'creata_da' => (string) $r['creata_da'],
                'attivata_il' => $r['attivata_il'] !== null ? (string) $r['attivata_il'] : null,
                'codice_scade' => $r['stato'] === 'in_attesa' ? (string) $r['codice_scade'] : null,
                'ultimo_uso' => $r['ultimo_uso'] !== null ? (string) $r['ultimo_uso'] : null,
                'ultimo_ip' => $r['ultimo_ip'] !== null ? (string) $r['ultimo_ip'] : null,
                'revocata_il' => $r['revocata_il'] !== null ? (string) $r['revocata_il'] : null,
                'questa' => $current !== null && $current['id'] === $r['id'],
            ];
        }

        return $out;
    }

    /** Autorizza il browser da cui sta lavorando l'amministratore. @return string token per il cookie */
    public function authorizeCurrent(User $actor, string $name, string $ip): string
    {
        $this->assertManager($actor);
        $name = $this->checkName($name);
        $token = bin2hex(random_bytes(32));
        $id = Uuid::v4();
        $now = $this->now();
        $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, nome, stato, token_hash, codice_hash, codice_scade, creata_il, creata_da, attivata_il, ultimo_uso, ultimo_ip, revocata_il, revocata_da)
             VALUES (?, ?, 'attiva', ?, NULL, NULL, ?, ?, ?, ?, ?, NULL, NULL)",
        )->execute([$id, $name, hash('sha256', $token), $now, $actor->username, $now, $now, substr($ip, 0, 45)]);
        $this->audit->record($actor->username, AccessService::MODULE, 'postazione_autorizzata', 'postazione', $id, 0, null, $name . ' (da questa postazione, ' . $ip . ')');

        return $token;
    }

    /** Codice di associazione monouso per una nuova postazione. @return array{codice: string, scade: string, id: string} */
    public function createCode(User $actor, string $name): array
    {
        $this->assertManager($actor);
        $name = $this->checkName($name);
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }
        $id = Uuid::v4();
        $now = $this->now();
        $expires = $this->clock->now()->modify('+' . self::CODE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, nome, stato, token_hash, codice_hash, codice_scade, creata_il, creata_da, attivata_il, ultimo_uso, ultimo_ip, revocata_il, revocata_da)
             VALUES (?, ?, 'in_attesa', NULL, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL)",
        )->execute([$id, $name, hash('sha256', $raw), $expires, $now, $actor->username]);
        $this->audit->record($actor->username, AccessService::MODULE, 'postazione_codice', 'postazione', $id, 0, null, $name . ', scade il ' . $expires);

        return ['codice' => substr($raw, 0, 4) . '-' . substr($raw, 4), 'scade' => $expires, 'id' => $id];
    }

    /** Sulla postazione: inserimento del codice. @return array{0: string, 1: string} token per il cookie e nome della postazione */
    public function pair(string $code, string $ip): array
    {
        $since = $this->clock->now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        $fails = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->attempts} WHERE ip = ? AND kind = 'postazione' AND success = 0 AND attempted_at > ?");
        $fails->execute([substr($ip, 0, 45), $since]);
        if ((int) $fails->fetchColumn() >= self::MAX_CODE_FAILURES_PER_IP) {
            throw new AccessException('Troppi codici sbagliati. Riprovare tra 15 minuti.', 429);
        }
        $clean = strtoupper((string) preg_replace('/[\s-]+/', '', $code));
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE codice_hash = ? AND stato = 'in_attesa'");
        $stmt->execute([hash('sha256', $clean)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) $row['codice_scade'] < $this->now()) {
            $this->pdo->prepare("INSERT INTO {$this->attempts} (id, username, ip, kind, success, attempted_at) VALUES (?, '-', ?, 'postazione', 0, ?)")
                ->execute([Uuid::v4(), substr($ip, 0, 45), $this->now()]);
            throw new AccessException('Codice non valido o scaduto: chiederne uno nuovo all\'amministratore', 401);
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->now();
        $upd = $this->pdo->prepare("UPDATE {$this->table} SET stato = 'attiva', token_hash = ?, codice_hash = NULL, codice_scade = NULL, attivata_il = ?, ultimo_uso = ?, ultimo_ip = ? WHERE id = ? AND stato = 'in_attesa'");
        $upd->execute([hash('sha256', $token), $now, $now, substr($ip, 0, 45), $row['id']]);
        if ($upd->rowCount() !== 1) {
            throw new AccessException('Codice già usato', 409);
        }
        $this->audit->record('postazione', AccessService::MODULE, 'postazione_associata', 'postazione', (string) $row['id'], 0, null, $row['nome'] . ' (' . $ip . ')');

        return [$token, (string) $row['nome']];
    }

    public function rename(User $actor, string $id, string $name): void
    {
        $this->assertManager($actor);
        $row = $this->find($id);
        $name = $this->checkName($name);
        $this->pdo->prepare("UPDATE {$this->table} SET nome = ? WHERE id = ?")->execute([$name, $id]);
        $this->audit->record($actor->username, AccessService::MODULE, 'postazione_rinominata', 'postazione', $id, 0, (string) $row['nome'], $name);
    }

    /** Revoca: il token non vale più e le sessioni aperte da quella postazione si chiudono. @return int sessioni chiuse */
    public function revoke(User $actor, string $id): int
    {
        $this->assertManager($actor);
        $row = $this->find($id);
        if ($row['stato'] === 'revocata') {
            return 0;
        }
        $now = $this->now();
        $this->pdo->prepare("UPDATE {$this->table} SET stato = 'revocata', token_hash = NULL, codice_hash = NULL, codice_scade = NULL, revocata_il = ?, revocata_da = ? WHERE id = ?")
            ->execute([$now, $actor->username, $id]);
        $stmt = $this->pdo->prepare("UPDATE {$this->sessions} SET revoked_at = ? WHERE postazione_id = ? AND revoked_at IS NULL");
        $stmt->execute([$now, $id]);
        $closed = $stmt->rowCount();
        $this->audit->record($actor->username, AccessService::MODULE, 'postazione_revocata', 'postazione', $id, 0, (string) $row['stato'], "revocata, {$closed} sessioni chiuse");

        return $closed;
    }

    /**
     * Postazione del browser (dal cookie), se attiva; aggiorna ultimo uso e indirizzo quando $ip è dato.
     *
     * @return array{id: string, nome: string}|null
     */
    public function identify(?string $token, ?string $ip): ?array
    {
        if ($token === null || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, nome FROM {$this->table} WHERE token_hash = ? AND stato = 'attiva'");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($ip !== null) {
            $this->pdo->prepare("UPDATE {$this->table} SET ultimo_uso = ?, ultimo_ip = ? WHERE id = ?")->execute([$this->now(), substr($ip, 0, 45), $row['id']]);
        }

        return ['id' => (string) $row['id'], 'nome' => (string) $row['nome']];
    }

    // ------------------------------------------------------------------ interni

    private function countActive(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE stato = 'attiva'")->fetchColumn();
    }

    private function assertManager(User $actor): void
    {
        if (!in_array($actor->role, [Role::TECNICO, Role::AMMINISTRATORE], true)) {
            throw new AccessException('Le postazioni le gestiscono il Tecnico e gli amministratori', 403);
        }
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new AccessException('Postazione non trovata', 404);
        }

        return $row;
    }

    private function checkName(string $name): string
    {
        $n = trim($name);
        if ($n === '' || mb_strlen($n) > 64 || preg_match('/[\x00-\x1F\x7F<>]/u', $n)) {
            throw new AccessException('Nome della postazione non valido (1–64 caratteri, es. "Cassa 1")', 422);
        }

        return $n;
    }

    private function now(): string
    {
        return $this->clock->nowForDatabase();
    }
}
