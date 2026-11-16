-- Servizio Accesso del Core: utenti, sessioni, tentativi di accesso, impostazioni.
-- Funziona senza Internet: solo password (hash), nessun OTP.
-- {prefix} = prefisso installazione + "core_"

CREATE TABLE IF NOT EXISTS {prefix}users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(128) NOT NULL,
    role VARCHAR(32) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password INT NOT NULL,
    active INT NOT NULL,
    password_changed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(191) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {prefix}users_username ON {prefix}users (username);

-- Il token di sessione non è mai salvato: solo la sua impronta SHA-256.
CREATE TABLE IF NOT EXISTS {prefix}sessions (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    ip VARCHAR(45) NOT NULL
);
CREATE INDEX IF NOT EXISTS {prefix}sessions_user ON {prefix}sessions (user_id);

CREATE TABLE IF NOT EXISTS {prefix}login_attempts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    success INT NOT NULL,
    attempted_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS {prefix}login_attempts_user ON {prefix}login_attempts (username, attempted_at);
CREATE INDEX IF NOT EXISTS {prefix}login_attempts_ip ON {prefix}login_attempts (ip, attempted_at);

-- Impostazioni di sicurezza (es. impronta della Recovery Key del Tecnico).
CREATE TABLE IF NOT EXISTS {prefix}settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(191) NOT NULL
);
