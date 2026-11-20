-- Postazioni autorizzate: i browser (PC di cassa, tablet…) da cui si può usare EMP.
-- Una postazione riceve un token permanente nel cookie emp_postazione: qui c'è solo la sua impronta.
-- Stato: in_attesa (codice di associazione da usare), attiva, revocata.
-- {prefix} = prefisso installazione + "core_"
CREATE TABLE IF NOT EXISTS {prefix}postazioni (
    id CHAR(36) NOT NULL PRIMARY KEY,
    nome VARCHAR(64) NOT NULL,
    stato VARCHAR(16) NOT NULL,
    token_hash CHAR(64) NULL,
    codice_hash CHAR(64) NULL,
    codice_scade DATETIME NULL,
    creata_il DATETIME NOT NULL,
    creata_da VARCHAR(191) NOT NULL,
    attivata_il DATETIME NULL,
    ultimo_uso DATETIME NULL,
    ultimo_ip VARCHAR(45) NULL,
    revocata_il DATETIME NULL,
    revocata_da VARCHAR(191) NULL
);
CREATE INDEX IF NOT EXISTS {prefix}postazioni_token ON {prefix}postazioni (token_hash);
CREATE INDEX IF NOT EXISTS {prefix}postazioni_codice ON {prefix}postazioni (codice_hash);

-- Sessione aperta da una postazione: revocando la postazione si chiudono le sue sessioni.
ALTER TABLE {prefix}sessions ADD COLUMN postazione_id CHAR(36) NULL;
