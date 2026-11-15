-- Registro di tracciabilità del Core (regola Edomea: nessuna modifica anonima).
-- Ogni modulo registra qui chi ha modificato cosa, quando, con valore prima/dopo.
-- {prefix} = prefisso installazione + "core_"
CREATE TABLE IF NOT EXISTS {prefix}audit (
    id CHAR(36) NOT NULL PRIMARY KEY,
    occurred_at DATETIME NOT NULL,
    actor VARCHAR(191) NOT NULL,
    module VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity VARCHAR(64) NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    store_id INT NOT NULL,
    before_value TEXT NULL,
    after_value TEXT NULL
);
CREATE INDEX IF NOT EXISTS {prefix}audit_entity ON {prefix}audit (entity, entity_id);
CREATE INDEX IF NOT EXISTS {prefix}audit_occurred ON {prefix}audit (occurred_at);
