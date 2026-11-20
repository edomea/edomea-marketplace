-- Marchio white label dell'installazione: nome, colore e logo mostrati in tutte le pagine.
-- Una sola riga (id = 1). Il logo è salvato in base64 (PNG, JPEG o WebP, al massimo 512 KB).
-- {prefix} = prefisso installazione + "core_"
CREATE TABLE IF NOT EXISTS {prefix}marchio (
    id INT NOT NULL PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    colore CHAR(7) NOT NULL,
    logo_tipo VARCHAR(32) NULL,
    logo MEDIUMTEXT NULL,
    logo_impronta CHAR(64) NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(191) NOT NULL
);
