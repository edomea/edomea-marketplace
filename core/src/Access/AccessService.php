<?php

declare(strict_types=1);

namespace Emp\Core\Access;

use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;
use Emp\Core\Support\Uuid;

/**
 * Servizio Accesso del Core. Funziona interamente offline: nome utente e
 * password per tutti, nessun OTP o servizio esterno.
 *
 * - Primo setup: crea l'unico Amministratore Tecnico e la sua Recovery Key.
 * - Login: sessione con token casuale; nel database solo l'impronta SHA-256.
 * - Blocco temporaneo dopo troppi tentativi falliti (per utente e per indirizzo).
 * - Gli amministratori impostano password temporanee, revocano accessi,
 *   disattivano e riattivano gli utenti che gestiscono (vedi Role).
 * - Recovery Key del Tecnico: unico modo per reimpostare la sua password;
 *   monouso, sostituita da una nuova a ogni utilizzo.
 * - Ogni evento di sicurezza è registrato nel registro di tracciabilità,
 *   senza password, chiavi o token.
 */
final class AccessService
{
    public const MODULE = 'emp-core';
    public const MIN_PASSWORD = 12;
    public const MAX_PASSWORD = 128;
    public const SESSION_HOURS = 12;          // durata massima di una sessione
    public const SESSION_IDLE_MINUTES = 240;  // inattività massima
    public const LOCK_WINDOW_MINUTES = 15;
    public const MAX_FAILURES_PER_USER = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const MAX_RECOVERY_FAILURES = 3;
    private const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,63}$/';
    private const SETTING_RECOVERY = 'tecnico_recovery_key';
    private const SETTING_SETUP = 'setup_tecnico';
    private const BASE32 = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private readonly string $users;
    private readonly string $sessions;
    private readonly string $attempts;
    private readonly string $settings;
    private readonly AuditLog $audit;
    private ?string $dummyHash = null;

    public function __construct(
        private readonly \PDO $pdo,
        string $tablePrefix,
        private readonly Clock $clock,
    ) {
        $this->users = $tablePrefix . 'users';
        $this->sessions = $tablePrefix . 'sessions';
        $this->attempts = $tablePrefix . 'login_attempts';
        $this->settings = $tablePrefix . 'settings';
        $this->audit = new AuditLog($pdo, $tablePrefix . 'audit', $clock);
    }

    // ------------------------------------------------------------------ setup

    public function isInitialized(): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->users} WHERE role = ?");
        $stmt->execute([Role::TECNICO]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Crea l'unico Amministratore Tecnico. Restituisce la Recovery Key in chiaro:
     * è l'unica volta in cui esiste fuori dal computer dell'utente.
     */
    public function setupTecnico(string $username, string $displayName, string $password): string
    {
        if ($this->isInitialized()) {
            throw new AccessException('Il primo setup è già stato completato', 409);
        }
        $username = $this->checkUsername($username);
        $displayName = $this->checkDisplayName($displayName);
        $this->checkPassword($password, $username);

        $this->pdo->beginTransaction();
        try {
            // La chiave primaria su settings impedisce un secondo setup anche con richieste simultanee.
            try {
                $this->pdo->prepare("INSERT INTO {$this->settings} (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)")
                    ->execute([self::SETTING_SETUP, 'completato', $this->now(), $username]);
            } catch (\PDOException) {
                throw new AccessException('Il primo setup è già stato completato', 409);
            }
            if ($this->isInitialized()) {
                throw new AccessException('Il primo setup è già stato completato', 409);
            }
            $id = $this->insertUser($username, $displayName, Role::TECNICO, $password, false, $username);
            $key = $this->storeNewRecoveryKey($username);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->audit->record($username, self::MODULE, 'setup_tecnico', 'utente', $id, 0, null, $this->snapshot($username, Role::TECNICO, true));

        return $key;
    }

    // ------------------------------------------------------------------ sessioni

    /** @return array{0: string, 1: User} token di sessione (da mettere nel cookie) e utente */
    public function login(string $username, string $password, string $ip): array
    {
        $username = strtolower(trim($username));
        $this->assertNotLocked($username, $ip, 'login');
        $row = $this->findByUsername($username);
        // Verifica sempre una password, anche per utenti inesistenti, per non rivelarli dai tempi di risposta.
        $ok = password_verify($password, $row['password_hash'] ?? $this->dummyHash());
        if (!$ok || $row === null || (int) $row['active'] !== 1) {
            $this->recordAttempt($username, $ip, 'login', false);
            $this->audit->record($username !== '' ? $username : '(vuoto)', self::MODULE, 'accesso_rifiutato', 'utente', $row['id'] ?? '-', 0);
            throw new AccessException('Nome utente o password non corretti', 401);
        }
        $this->recordAttempt($username, $ip, 'login', true);
        if (password_needs_rehash((string) $row['password_hash'], self::algorithm())) {
            $this->pdo->prepare("UPDATE {$this->users} SET password_hash = ? WHERE id = ?")->execute([self::hash($password), $row['id']]);
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->pdo->prepare("INSERT INTO {$this->sessions} (token_hash, user_id, created_at, last_seen_at, expires_at, revoked_at, ip) VALUES (?, ?, ?, ?, ?, NULL, ?)")
            ->execute([hash('sha256', $token), $row['id'], $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $now->modify('+' . self::SESSION_HOURS . ' hours')->format('Y-m-d H:i:s'), substr($ip, 0, 45)]);
        $this->audit->record($username, self::MODULE, 'accesso', 'utente', (string) $row['id'], 0);

        return [$token, User::fromRow($row)];
    }

    /** Utente della sessione, oppure null se il token è assente, scaduto o revocato. */
    public function userForToken(?string $token): ?User
    {
        if ($token === null || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            "SELECT s.last_seen_at, s.expires_at, s.revoked_at, u.* FROM {$this->sessions} s JOIN {$this->users} u ON u.id = s.user_id WHERE s.token_hash = ?",
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['revoked_at'] !== null || (int) $row['active'] !== 1) {
            return null;
        }
        $now = $this->clock->now();
        $idleLimit = (new \DateTimeImmutable((string) $row['last_seen_at'], $now->getTimezone()))->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes');
        if ($now >= new \DateTimeImmutable((string) $row['expires_at'], $now->getTimezone()) || $now >= $idleLimit) {
            return null;
        }
        $this->pdo->prepare("UPDATE {$this->sessions} SET last_seen_at = ? WHERE token_hash = ?")->execute([$now->format('Y-m-d H:i:s'), $hash]);

        return User::fromRow($row);
    }

    public function logout(?string $token): void
    {
        if ($token !== null && preg_match('/^[0-9a-f]{64}$/', $token)) {
            $this->pdo->prepare("UPDATE {$this->sessions} SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL")
                ->execute([$this->now(), hash('sha256', $token)]);
        }
    }

    // ------------------------------------------------------------------ password

    /** Cambio della propria password. Chiude le altre sessioni dell'utente. */
    public function changeOwnPassword(User $user, string $current, string $new, ?string $keepToken): void
    {
        $row = $this->findById($user->id);
        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            throw new AccessException('La password attuale non è corretta', 403);
        }
        if (password_verify($new, (string) $row['password_hash'])) {
            throw new AccessException('La nuova password deve essere diversa da quella attuale', 422);
        }
        $this->checkPassword($new, $user->username);
        $this->updatePassword($user->id, $new, false, $user->username);
        $this->revokeAllSessions($user->id, $keepToken);
        $this->audit->record($user->username, self::MODULE, 'cambio_password', 'utente', $user->id, 0);
    }

    /**
     * Recupero dell'Amministratore Tecnico con la Recovery Key: imposta una
     * nuova password, chiude tutte le sessioni e restituisce una NUOVA chiave.
     */
    public function recoverTecnico(string $username, string $recoveryKey, string $newPassword, string $ip): string
    {
        $username = strtolower(trim($username));
        $this->assertNotLocked($username, $ip, 'recupero');
        $row = $this->findByUsername($username);
        $stored = $this->setting(self::SETTING_RECOVERY);
        $ok = password_verify(self::normalizeKey($recoveryKey), $stored ?? $this->dummyHash());
        if (!$ok || $stored === null || $row === null || $row['role'] !== Role::TECNICO) {
            $this->recordAttempt($username, $ip, 'recupero', false);
            $this->audit->record($username !== '' ? $username : '(vuoto)', self::MODULE, 'recupero_rifiutato', 'utente', $row['id'] ?? '-', 0);
            throw new AccessException('Nome utente o Recovery Key non corretti', 401);
        }
        $this->checkPassword($newPassword, $username);
        $this->recordAttempt($username, $ip, 'recupero', true);

        $this->pdo->beginTransaction();
        try {
            $this->updatePassword((string) $row['id'], $newPassword, false, $username);
            $this->pdo->prepare("UPDATE {$this->users} SET active = 1 WHERE id = ?")->execute([$row['id']]);
            $this->revokeAllSessions((string) $row['id'], null);
            $key = $this->storeNewRecoveryKey($username);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->audit->record($username, self::MODULE, 'recupero_tecnico', 'utente', (string) $row['id'], 0, null, 'nuova Recovery Key generata');

        return $key;
    }

    // ------------------------------------------------------------------ gestione utenti

    /** @return list<User> l'utente stesso e quelli che può gestire */
    public function listUsers(User $actor): array
    {
        $managed = Role::managedBy($actor->role);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->users} WHERE id = ? OR role = ? ORDER BY role, username");
        $stmt->execute([$actor->id, $managed ?? '-']);

        return array_map(User::fromRow(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function createUser(User $actor, string $username, string $displayName, string $role, string $temporaryPassword): User
    {
        $this->assertCanManageRole($actor, $role);
        $username = $this->checkUsername($username);
        $displayName = $this->checkDisplayName($displayName);
        $this->checkPassword($temporaryPassword, $username);
        if ($this->findByUsername($username) !== null) {
            throw new AccessException('Nome utente già in uso', 409);
        }
        $id = $this->insertUser($username, $displayName, $role, $temporaryPassword, true, $actor->username);
        $this->audit->record($actor->username, self::MODULE, 'crea_utente', 'utente', $id, 0, null, $this->snapshot($username, $role, true));

        return User::fromRow((array) $this->findById($id));
    }

    /** Password temporanea impostata da un amministratore: da cambiare al primo accesso. */
    public function setTemporaryPassword(User $actor, string $targetId, string $temporaryPassword): void
    {
        $target = $this->managedTarget($actor, $targetId);
        $this->checkPassword($temporaryPassword, $target->username);
        $this->updatePassword($target->id, $temporaryPassword, true, $actor->username);
        $this->revokeAllSessions($target->id, null);
        $this->audit->record($actor->username, self::MODULE, 'password_temporanea', 'utente', $target->id, 0);
    }

    /** Revoca degli accessi: chiude subito tutte le sessioni dell'utente. */
    public function revokeAccess(User $actor, string $targetId): int
    {
        $target = $this->managedTarget($actor, $targetId);
        $n = $this->revokeAllSessions($target->id, null);
        $this->audit->record($actor->username, self::MODULE, 'revoca_accessi', 'utente', $target->id, 0, null, "{$n} sessioni chiuse");

        return $n;
    }

    public function setActive(User $actor, string $targetId, bool $active): void
    {
        $target = $this->managedTarget($actor, $targetId);
        $this->pdo->prepare("UPDATE {$this->users} SET active = ?, updated_at = ?, updated_by = ? WHERE id = ?")
            ->execute([$active ? 1 : 0, $this->now(), $actor->username, $target->id]);
        if (!$active) {
            $this->revokeAllSessions($target->id, null);
        }
        $this->audit->record(
            $actor->username,
            self::MODULE,
            $active ? 'riattiva_utente' : 'disattiva_utente',
            'utente',
            $target->id,
            0,
            $target->active ? 'attivo' : 'disattivo',
            $active ? 'attivo' : 'disattivo',
        );
    }

    // ------------------------------------------------------------------ interni

    private function managedTarget(User $actor, string $targetId): User
    {
        if ($targetId === $actor->id) {
            throw new AccessException('Per il proprio account usare il cambio password del profilo', 422);
        }
        $row = $this->findById($targetId);
        if ($row === null) {
            throw new AccessException('Utente non trovato', 404);
        }
        $target = User::fromRow($row);
        $this->assertCanManageRole($actor, $target->role);

        return $target;
    }

    private function assertCanManageRole(User $actor, string $role): void
    {
        if (!in_array($role, Role::ALL, true) || !Role::canManage($actor->role, $role)) {
            throw new AccessException('Operazione non consentita al tuo ruolo', 403);
        }
    }

    private function insertUser(string $username, string $displayName, string $role, string $password, bool $mustChange, string $actor): string
    {
        $id = Uuid::v4();
        $now = $this->now();
        $this->pdo->prepare(
            "INSERT INTO {$this->users} (id, username, display_name, role, password_hash, must_change_password, active, password_changed_at, created_at, created_by, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)",
        )->execute([$id, $username, $displayName, $role, self::hash($password), $mustChange ? 1 : 0, $now, $now, $actor, $now, $actor]);

        return $id;
    }

    private function updatePassword(string $userId, string $password, bool $mustChange, string $actor): void
    {
        $now = $this->now();
        $this->pdo->prepare("UPDATE {$this->users} SET password_hash = ?, must_change_password = ?, password_changed_at = ?, updated_at = ?, updated_by = ? WHERE id = ?")
            ->execute([self::hash($password), $mustChange ? 1 : 0, $now, $now, $actor, $userId]);
    }

    private function revokeAllSessions(string $userId, ?string $keepToken): int
    {
        $keep = $keepToken !== null ? hash('sha256', $keepToken) : '';
        $stmt = $this->pdo->prepare("UPDATE {$this->sessions} SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND token_hash <> ?");
        $stmt->execute([$this->now(), $userId, $keep]);

        return $stmt->rowCount();
    }

    private function storeNewRecoveryKey(string $actor): string
    {
        $key = self::generateKey();
        $hash = self::hash(self::normalizeKey($key));
        $now = $this->now();
        $upd = $this->pdo->prepare("UPDATE {$this->settings} SET value = ?, updated_at = ?, updated_by = ? WHERE name = ?");
        $upd->execute([$hash, $now, $actor, self::SETTING_RECOVERY]);
        if ($upd->rowCount() === 0) {
            $this->pdo->prepare("INSERT INTO {$this->settings} (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)")
                ->execute([self::SETTING_RECOVERY, $hash, $now, $actor]);
        }

        return $key;
    }

    /** Chiave di 25 caratteri (125 bit), leggibile: EMP-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX */
    private static function generateKey(): string
    {
        $chars = '';
        for ($i = 0; $i < 25; $i++) {
            $chars .= self::BASE32[random_int(0, 31)];
        }

        return 'EMP-' . implode('-', str_split($chars, 5));
    }

    public static function normalizeKey(string $key): string
    {
        $k = strtoupper((string) preg_replace('/[\s-]+/', '', $key));
        if (str_starts_with($k, 'EMP') && strlen($k) === 28) {
            $k = substr($k, 3);
        }

        return $k;
    }

    private function assertNotLocked(string $username, string $ip, string $kind): void
    {
        $since = $this->clock->now()->modify('-' . self::LOCK_WINDOW_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $byUser = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->attempts} WHERE username = ? AND kind = ? AND success = 0 AND attempted_at > ?");
        $byUser->execute([$username, $kind, $since]);
        $byIp = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->attempts} WHERE ip = ? AND success = 0 AND attempted_at > ?");
        $byIp->execute([$ip, $since]);
        $limitUser = $kind === 'recupero' ? self::MAX_RECOVERY_FAILURES : self::MAX_FAILURES_PER_USER;
        if ((int) $byUser->fetchColumn() >= $limitUser || (int) $byIp->fetchColumn() >= self::MAX_FAILURES_PER_IP) {
            throw new AccessException(sprintf('Troppi tentativi non riusciti. Riprovare tra %d minuti.', self::LOCK_WINDOW_MINUTES), 429);
        }
    }

    private function recordAttempt(string $username, string $ip, string $kind, bool $success): void
    {
        $this->pdo->prepare("INSERT INTO {$this->attempts} (id, username, ip, kind, success, attempted_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([Uuid::v4(), substr($username, 0, 64), substr($ip, 0, 45), $kind, $success ? 1 : 0, $this->now()]);
        if ($success) {
            // Un accesso riuscito azzera i tentativi falliti di quell'utente.
            $this->pdo->prepare("DELETE FROM {$this->attempts} WHERE username = ? AND kind = ? AND success = 0")->execute([$username, $kind]);
        }
    }

    /** @return array<string, mixed>|null */
    private function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->users} WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->users} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function setting(string $name): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM {$this->settings} WHERE name = ?");
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();

        return is_string($v) ? $v : null;
    }

    private function checkUsername(string $username): string
    {
        $u = strtolower(trim($username));
        if (!preg_match(self::USERNAME_PATTERN, $u)) {
            throw new AccessException('Nome utente non valido: 3–64 caratteri tra lettere minuscole, cifre, punto, trattino e trattino basso', 422);
        }

        return $u;
    }

    private function checkDisplayName(string $name): string
    {
        $n = trim($name);
        if ($n === '' || mb_strlen($n) > 128 || preg_match('/[\x00-\x1F\x7F]/', $n)) {
            throw new AccessException('Nome e cognome non validi', 422);
        }

        return $n;
    }

    private function checkPassword(string $password, string $username): void
    {
        $len = mb_strlen($password);
        if ($len < self::MIN_PASSWORD || $len > self::MAX_PASSWORD) {
            throw new AccessException(sprintf('La password deve avere da %d a %d caratteri', self::MIN_PASSWORD, self::MAX_PASSWORD), 422);
        }
        if (self::algorithm() === PASSWORD_BCRYPT && strlen($password) > 72) {
            throw new AccessException('La password è troppo lunga per questo server (massimo 72 byte)', 422);
        }
        if (str_contains(strtolower($password), $username)) {
            throw new AccessException('La password non deve contenere il nome utente', 422);
        }
        if (count(array_unique(mb_str_split($password))) < 5) {
            throw new AccessException('La password è troppo ripetitiva', 422);
        }
    }

    private function snapshot(string $username, string $role, bool $active): string
    {
        return json_encode(['username' => $username, 'ruolo' => $role, 'attivo' => $active], JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function now(): string
    {
        return $this->clock->nowForDatabase();
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= self::hash(bin2hex(random_bytes(16)));
    }

    private static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function hash(string $secret): string
    {
        return password_hash($secret, self::algorithm());
    }
}
