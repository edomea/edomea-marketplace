-- EMP Studio 0.2.0
-- 1. Tabelle di base (aliquote IVA, unità di misura, e altre in futuro) gestite dalla pagina "Tabelle".
-- 2. Garanzia, classe energetica ed EPREL non sono più campi fissi della scheda ma caratteristiche
--    che si aggiungono solo ai prodotti che ne hanno bisogno: i valori già inseriti vengono spostati.

CREATE TABLE IF NOT EXISTS {prefix}tabelle_voci (
    tabella VARCHAR(32) NOT NULL,
    codice VARCHAR(16) NOT NULL,
    descrizione VARCHAR(120) NOT NULL,
    valore VARCHAR(32) NOT NULL,
    ordine INT NOT NULL,
    modificato_il DATETIME NOT NULL,
    modificato_da VARCHAR(191) NOT NULL,
    PRIMARY KEY (tabella, codice)
);

INSERT INTO {prefix}tabelle_voci (tabella, codice, descrizione, valore, ordine, modificato_il, modificato_da) VALUES
    ('iva', '22', 'Aliquota ordinaria', '22', 1, '2026-11-20 00:00:00', 'installer'),
    ('iva', '10', 'Aliquota ridotta', '10', 2, '2026-11-20 00:00:00', 'installer'),
    ('iva', '5', 'Aliquota ridotta', '5', 3, '2026-11-20 00:00:00', 'installer'),
    ('iva', '4', 'Aliquota minima', '4', 4, '2026-11-20 00:00:00', 'installer'),
    ('iva', '0', 'Esente / non imponibile', '0', 5, '2026-11-20 00:00:00', 'installer'),
    ('um', 'pz', 'Pezzo', '', 1, '2026-11-20 00:00:00', 'installer'),
    ('um', 'conf', 'Confezione', '', 2, '2026-11-20 00:00:00', 'installer'),
    ('um', 'kg', 'Chilogrammo', '', 3, '2026-11-20 00:00:00', 'installer'),
    ('um', 'g', 'Grammo', '', 4, '2026-11-20 00:00:00', 'installer'),
    ('um', 'l', 'Litro', '', 5, '2026-11-20 00:00:00', 'installer'),
    ('um', 'm', 'Metro', '', 6, '2026-11-20 00:00:00', 'installer'),
    ('um', 'mq', 'Metro quadrato', '', 7, '2026-11-20 00:00:00', 'installer'),
    ('um', 'servizio', 'Servizio', '', 8, '2026-11-20 00:00:00', 'installer');

INSERT INTO {prefix}attributi (id, codice, nome, tipo, unita, opzioni, modificato_il, modificato_da) VALUES
    ('00000000-0000-4000-8000-000000000017', 'garanzia', 'Garanzia', 'testo', '', '[]', '2026-11-20 00:00:00', 'installer'),
    ('00000000-0000-4000-8000-000000000018', 'classe_energetica', 'Classe energetica', 'elenco', '', '["A","B","C","D","E","F","G","A+++","A++","A+"]', '2026-11-20 00:00:00', 'installer'),
    ('00000000-0000-4000-8000-000000000019', 'eprel', 'N. registrazione EPREL', 'testo', '', '[]', '2026-11-20 00:00:00', 'installer');

INSERT INTO {prefix}valori (prodotto_id, attributo_id, valore)
    SELECT id, '00000000-0000-4000-8000-000000000017', garanzia_mesi FROM {prefix}prodotti WHERE garanzia_mesi IS NOT NULL;
INSERT INTO {prefix}valori (prodotto_id, attributo_id, valore)
    SELECT id, '00000000-0000-4000-8000-000000000018', classe_energetica FROM {prefix}prodotti WHERE classe_energetica <> '';
INSERT INTO {prefix}valori (prodotto_id, attributo_id, valore)
    SELECT id, '00000000-0000-4000-8000-000000000019', eprel FROM {prefix}prodotti WHERE eprel <> '';

ALTER TABLE {prefix}prodotti DROP COLUMN garanzia_mesi;
ALTER TABLE {prefix}prodotti DROP COLUMN classe_energetica;
ALTER TABLE {prefix}prodotti DROP COLUMN eprel;
