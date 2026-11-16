# Registro delle modifiche

Formato: una sezione per versione di EMP Desk, data in formato gg/mm/aaaa. Le versioni non vengono mai riutilizzate.

## [0.5.0-alpha.3] — 16/11/2026

Prima versione di laboratorio di **EMP — Edomea Marketplace**, nata dalla fusione di Edomea (Ecosistema 1.0) e VenVia 0.4. **Non adatta alla produzione.**

### Contenuto
- **EMP Desk 0.5.0-alpha.3** (Windows x64), derivato da VenVia Desk 0.4 Alpha 4:
  - diagnostica del computer (sistema, CPU, RAM, dischi, porte 80/443/3306, processi runtime);
  - modalità Server o Client salvata in modo persistente; il Client accetta solo un indirizzo `https://`;
  - in modalità Server: **installazione automatica dell'ambiente** (Macro 2): MariaDB 11.4 LTS, PHP 8.3 e Caddy inclusi nell'installer e verificati, servizio di Windows *EMP Server* ad avvio automatico (account LocalService), tutto solo su `127.0.0.1`, password casuali, Core installato e database preparato; permessi di amministratore richiesti solo per installare, aggiornare o disinstallare; i dati non vengono mai cancellati;
  - **modalità laboratorio** facoltativa: Core con SQLite su `http://127.0.0.1:8180` finché Desk è aperto.
- **EMP Core 0.1.1** (PHP 8.2+): registro dei moduli, ordine per dipendenze, migrazioni tracciate, API `GET /api/v1/salute`, riga di comando `bin/emp`, e **pagina web Plugin** (riservata al Tecnico) per installare gli altri plugin dal catalogo.
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
- Il web server è **Caddy** e non Nginx, la cui versione per Windows è dichiarata dai suoi sviluppatori solo per sviluppo e test.
- Backup automatici non ancora presenti.
- Senza HTTPS in LAN le password viaggiano in chiaro sulla rete del negozio: primo setup, recupero del Tecnico e plugin sono quindi possibili solo dal computer Server.
- Nessuna verifica TLS del Server da parte del Client.
- Collaudo su Windows 11 pulito da completare.
