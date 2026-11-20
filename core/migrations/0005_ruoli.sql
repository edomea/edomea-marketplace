-- Ruoli di negozio (mansioni) con i loro permessi, assegnati agli operatori.
-- I permessi li dichiarano i moduli (campo "permissions" di emp-module.json): un ruolo è un insieme di permessi.
-- Ruoli iniziali dal manuale tecnico-operativo (§04), modificabili; i permessi si aggiungono con i plugin.
-- {prefix} = prefisso installazione + "core_"
CREATE TABLE IF NOT EXISTS {prefix}ruoli (
    codice VARCHAR(64) NOT NULL PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    descrizione VARCHAR(255) NOT NULL,
    creato_il DATETIME NOT NULL,
    creato_da VARCHAR(191) NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL
);

CREATE TABLE IF NOT EXISTS {prefix}ruoli_permessi (
    ruolo VARCHAR(64) NOT NULL,
    permesso VARCHAR(128) NOT NULL,
    PRIMARY KEY (ruolo, permesso)
);

CREATE TABLE IF NOT EXISTS {prefix}utenti_ruoli (
    user_id CHAR(36) NOT NULL,
    ruolo VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_id, ruolo)
);
CREATE INDEX IF NOT EXISTS {prefix}utenti_ruoli_ruolo ON {prefix}utenti_ruoli (ruolo);

INSERT INTO {prefix}ruoli (codice, nome, descrizione, creato_il, creato_da, modificato_il, modificato_da) VALUES
    ('cassiere', 'Cassiere', 'Vendite al banco e incassi', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('store-manager', 'Store Manager', 'Responsabile del punto vendita', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('area-manager', 'Area Manager', 'Responsabile di più punti vendita; rettifiche di inventario', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('responsabile-reparto', 'Responsabile Reparto', 'Responsabile di un reparto del negozio', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('commerciale', 'Commerciale', 'Vendita e rapporti con i clienti', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('direttore-commerciale', 'Supervisor / Direttore Commerciale', 'Supervisione commerciale della rete', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('buyer', 'Buyer', 'Acquisti e rapporti con i fornitori', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('responsabile-logistica', 'Responsabile Logistica', 'Trasferimenti, spedizioni e logistica', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('magazziniere', 'Magazziniere', 'Carichi, scarichi e giacenze', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer'),
    ('contabilita', 'Contabilità', 'Documenti contabili e statistiche', '2026-11-20 00:00:00', 'installer', '2026-11-20 00:00:00', 'installer');
