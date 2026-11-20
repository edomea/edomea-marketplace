-- EMP Studio: anagrafica prodotti. {prefix} = prefisso installazione + "studio_".
-- Identificativi UUID generati in PHP; date nell'ora locale del Server; chi e quando su ogni riga
-- (lo storico completo prima/dopo è nella tracciabilità del Core).

CREATE TABLE IF NOT EXISTS {prefix}categorie (
    id CHAR(36) NOT NULL PRIMARY KEY,
    parent_id CHAR(36) NULL,
    nome VARCHAR(120) NOT NULL,
    ordine INT NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL
);

CREATE TABLE IF NOT EXISTS {prefix}marche (
    id CHAR(36) NOT NULL PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {prefix}marche_nome ON {prefix}marche (nome);

-- Attributi delle schede (es. capacità di carico, diagonale): tipo testo | numero | si_no | elenco.
CREATE TABLE IF NOT EXISTS {prefix}attributi (
    id CHAR(36) NOT NULL PRIMARY KEY,
    codice VARCHAR(64) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    tipo VARCHAR(16) NOT NULL,
    unita VARCHAR(16) NOT NULL,
    opzioni TEXT NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {prefix}attributi_codice ON {prefix}attributi (codice);

-- Famiglie (Lavatrici, TV, Smartphone…): quali attributi ha la scheda e quali sono obbligatori.
CREATE TABLE IF NOT EXISTS {prefix}famiglie (
    id CHAR(36) NOT NULL PRIMARY KEY,
    codice VARCHAR(64) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {prefix}famiglie_codice ON {prefix}famiglie (codice);

CREATE TABLE IF NOT EXISTS {prefix}famiglie_attributi (
    famiglia_id CHAR(36) NOT NULL,
    attributo_id CHAR(36) NOT NULL,
    obbligatorio INT NOT NULL,
    ordine INT NOT NULL,
    PRIMARY KEY (famiglia_id, attributo_id)
);

-- Prodotti. tipo: semplice | modello (raggruppa varianti, senza codici propri) | variante (di un modello).
-- stato: bozza | attivo | archiviato. completezza: percentuale calcolata a ogni salvataggio.
CREATE TABLE IF NOT EXISTS {prefix}prodotti (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tipo VARCHAR(16) NOT NULL,
    modello_id CHAR(36) NULL,
    sku VARCHAR(64) NULL,
    ean VARCHAR(14) NULL,
    ean_interno INT NOT NULL,
    mpn VARCHAR(64) NOT NULL,
    nome VARCHAR(200) NOT NULL,
    marca_id CHAR(36) NULL,
    categoria_id CHAR(36) NULL,
    famiglia_id CHAR(36) NULL,
    descrizione_breve TEXT NOT NULL,
    descrizione TEXT NOT NULL,
    iva VARCHAR(8) NOT NULL,
    um VARCHAR(8) NOT NULL,
    garanzia_mesi INT NULL,
    classe_energetica VARCHAR(8) NOT NULL,
    eprel VARCHAR(20) NOT NULL,
    stato VARCHAR(16) NOT NULL,
    completezza INT NOT NULL,
    creato_il DATETIME NOT NULL,
    creato_da VARCHAR(191) NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {prefix}prodotti_sku ON {prefix}prodotti (sku);
CREATE UNIQUE INDEX IF NOT EXISTS {prefix}prodotti_ean ON {prefix}prodotti (ean);
CREATE INDEX IF NOT EXISTS {prefix}prodotti_modello ON {prefix}prodotti (modello_id);
CREATE INDEX IF NOT EXISTS {prefix}prodotti_nome ON {prefix}prodotti (nome);

CREATE TABLE IF NOT EXISTS {prefix}valori (
    prodotto_id CHAR(36) NOT NULL,
    attributo_id CHAR(36) NOT NULL,
    valore TEXT NOT NULL,
    PRIMARY KEY (prodotto_id, attributo_id)
);

-- Foto (file in dati-moduli/emp-studio/pubblico/foto/), video (link) e documenti (manuali, etichette energetiche).
CREATE TABLE IF NOT EXISTS {prefix}media (
    id CHAR(36) NOT NULL PRIMARY KEY,
    prodotto_id CHAR(36) NOT NULL,
    tipo VARCHAR(16) NOT NULL,
    percorso VARCHAR(255) NOT NULL,
    titolo VARCHAR(200) NOT NULL,
    mime VARCHAR(64) NOT NULL,
    byte INT NOT NULL,
    ordine INT NOT NULL,
    creato_il DATETIME NOT NULL,
    creato_da VARCHAR(191) NOT NULL
);
CREATE INDEX IF NOT EXISTS {prefix}media_prodotto ON {prefix}media (prodotto_id);

-- Contatore degli EAN interni (circolazione ristretta, prefisso 2xx GS1).
CREATE TABLE IF NOT EXISTS {prefix}contatori (
    nome VARCHAR(32) NOT NULL PRIMARY KEY,
    valore INT NOT NULL
);
INSERT INTO {prefix}contatori (nome, valore) VALUES ('ean_interno', 0);
