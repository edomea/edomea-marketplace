# Registro delle modifiche

Formato: una sezione per versione di EMP Desk, data in formato gg/mm/aaaa. Le versioni non vengono mai riutilizzate.

## [0.5.0-alpha.1] — 16/11/2026

Prima versione di laboratorio di **EMP — Edomea Marketplace**, nata dalla fusione di Edomea (Ecosistema 1.0) e VenVia 0.4. **Non adatta alla produzione.**

### Contenuto
- **EMP Desk 0.5.0-alpha.1** (Windows x64), derivato da VenVia Desk 0.4 Alpha 4:
  - diagnostica del computer (sistema, CPU, RAM, dischi, porte 80/443/3306, processi runtime);
  - modalità Server o Client salvata in modo persistente; il Client accetta solo un indirizzo `https://`;
  - in modalità Server: installazione e aggiornamento del **solo Core**, incluso nell'installer, con verifica SHA-256 ed estrazione sicura;
  - **avvio del Core in modalità laboratorio**: PHP 8.3 ufficiale incluso, database SQLite, Core su `http://127.0.0.1:8180` finché Desk è aperto, pulsante *Apri amministrazione*.
- **EMP Core 0.1.0** (PHP 8.2+): registro dei moduli, ordine per dipendenze, migrazioni tracciate, API `GET /api/v1/salute`, riga di comando `bin/emp`, e **pagina web Plugin** (riservata al Tecnico) per installare gli altri plugin dal catalogo.
- **Servizio Accesso** del Core, funzionante **senza Internet**:
  - primo setup dell'unico Amministratore Tecnico, senza account predefiniti;
  - accesso con nome utente e password per tutti (password solo come hash, blocco dopo tentativi falliti);
  - ruoli separati: il Tecnico gestisce gli amministratori, gli amministratori gestiscono gli operatori;
  - cambio password, password temporanee, revoca degli accessi, disattivazione;
  - Recovery Key del Tecnico, mostrata una sola volta e sostituita a ogni utilizzo;
  - registro degli eventi di sicurezza senza segreti.
- Cartelle descrittive dei plugin previsti in `plugins/` (nessun plugin di produzione ancora sviluppato).

### Limiti noti
- L'installer **non è firmato**: Windows SmartScreen e i browser possono segnalarlo.
- Modalità laboratorio: server integrato di PHP (una richiesta alla volta) e SQLite; il Core non parte da solo all'avvio di Windows. Nginx, MariaDB e servizi di Windows arriveranno con la Macro 2.
- Senza HTTPS in LAN le password viaggiano in chiaro sulla rete del negozio: primo setup, recupero del Tecnico e plugin sono quindi possibili solo dal computer Server.
- Nessuna verifica TLS del Server da parte del Client.
- Collaudo su Windows 11 pulito da completare.
