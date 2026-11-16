# Registro delle modifiche

Formato: una sezione per versione di EMP Desk, data in formato gg/mm/aaaa. Le versioni non vengono mai riutilizzate.

## [0.5.0-alpha.1] — 16/11/2026

Prima versione di laboratorio di **EMP — Edomea Marketplace**, nata dalla fusione di Edomea (Ecosistema 1.0) e VenVia 0.4. **Non adatta alla produzione.**

### Contenuto
- **EMP Desk 0.5.0-alpha.1** (Windows x64), derivato da VenVia Desk 0.4 Alpha 4:
  - diagnostica del computer (sistema, CPU, RAM, dischi, porte 80/443/3306, processi runtime);
  - modalità Server o Client salvata in modo persistente; il Client accetta solo un indirizzo `https://`;
  - in modalità Server: piano di installazione e installazione dei file di Core e plugin, con il Core sempre per primo, verifica SHA-256 dei pacchetti ed estrazione sicura.
- **EMP Core 0.1.0** (PHP 8.2+): registro dei moduli, ordine di installazione per dipendenze, migrazioni tracciate, API `GET /api/v1/salute`, riga di comando `bin/emp`.
- Pacchetti dei moduli e indice `emp-packages.json` allegati alla release.

### Limiti noti
- L'installer **non è firmato**: Windows SmartScreen e i browser possono segnalarlo.
- PHP, MariaDB e Nginx non vengono ancora installati da Desk: le migrazioni del database si eseguono con `php bin/emp installa`.
- Nessuna verifica TLS del Server da parte del Client; nessun servizio Accesso nel Core.
- Collaudo su Windows 11 pulito da completare.
