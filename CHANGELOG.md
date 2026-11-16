# Registro delle modifiche

Formato: una sezione per versione di EMP Desk, data in formato gg/mm/aaaa. Le versioni non vengono mai riutilizzate.

## [0.5.0-alpha.1] — 16/11/2026

Prima versione di laboratorio di **EMP — Edomea Marketplace**, nata dalla fusione di Edomea (Ecosistema 1.0) e VenVia 0.4. **Non adatta alla produzione.**

### Contenuto
- **EMP Desk 0.5.0-alpha.1** (Windows x64), derivato da VenVia Desk 0.4 Alpha 4:
  - diagnostica del computer (sistema, CPU, RAM, dischi, porte 80/443/3306, processi runtime);
  - modalità Server o Client salvata in modo persistente; il Client accetta solo un indirizzo `https://`;
  - in modalità Server: installazione e aggiornamento del **solo Core**, incluso nell'installer, con verifica SHA-256 ed estrazione sicura.
- **EMP Core 0.1.0** (PHP 8.2+): registro dei moduli, ordine per dipendenze, migrazioni tracciate, API `GET /api/v1/salute`, riga di comando `bin/emp`, e **pagina web Plugin** per installare gli altri plugin dal catalogo.
- Cartelle descrittive dei plugin previsti in `plugins/` (nessun plugin di produzione ancora sviluppato).

### Limiti noti
- L'installer **non è firmato**: Windows SmartScreen e i browser possono segnalarlo.
- PHP, MariaDB e Nginx non vengono ancora installati da Desk: le migrazioni del Core si eseguono con `php bin/emp installa` e la pagina Plugin richiede un web server configurato a mano.
- La gestione dei plugin funziona solo dal computer Server finché non c'è il servizio Accesso (login e permessi).
- Nessuna verifica TLS del Server da parte del Client.
- Collaudo su Windows 11 pulito da completare.
