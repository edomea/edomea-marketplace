# emp-esempio — Plugin dimostrativo

**Stato:** esempio, **non installare in produzione**. Non è incluso nei pacchetti di release (solo con `--con-esempi`).

Mostra la struttura minima di un plugin EMP:

| File | Ruolo |
|---|---|
| `emp-module.json` | Identità, versione, dipendenza da `emp-core`, prefisso tabelle `esempio_`, permessi |
| `src/Module.php` | Registra la rotta `GET /api/v1/esempio` |
| `migrations/0001_note.sql` | Crea la tabella `{prefix}note` (con utente e data di creazione) |
