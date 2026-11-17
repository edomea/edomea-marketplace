# Registro delle modifiche

Formato: una sezione per versione di EMP, data in formato gg/mm/aaaa. Le versioni non vengono mai riutilizzate.

## [0.6.0-alpha.1] — 16/11/2026

Prima versione di laboratorio di **EMP — Edomea Marketplace**, nata dalla fusione di Edomea (Ecosistema 1.0) e VenVia 0.4. **Non adatta alla produzione.**

### Contenuto
- **EMP Server 0.6.0-alpha.1** per **Ubuntu Server LTS** (26.04 consigliata, 24.04 supportata), anche in una macchina virtuale VMware Workstation Pro:
  - installazione e aggiornamento con un solo comando, `sudo ./installa.sh`, che controlla le impronte SHA-256 di tutti i file prima di qualsiasi modifica;
  - Nginx, PHP-FPM e MariaDB dai repository ufficiali di Ubuntu; MariaDB senza porte di rete e senza password salvate (unix_socket);
  - **HTTPS in LAN** con un'autorità di certificazione locale limitata ai nomi del Server e alle reti private; certificato rinnovato da solo anche se cambia l'indirizzo IP;
  - firewall (porte 22, 80, 443), aggiornamenti di sicurezza automatici, backup notturno (ultimi 14);
  - comando `emp-server`: stato, indirizzi, codice di setup, recupero del Tecnico, certificato, backup, moduli;
  - disinstallazione che conserva sempre i dati; un Core più vecchio di quello installato viene rifiutato.
- **EMP Core 0.1.2** (PHP 8.2+, provato con 8.3 e 8.5):
  - registro dei moduli, ordine per dipendenze, migrazioni tracciate;
  - API `GET /api/v1/salute`, riga di comando `bin/emp`;
  - **pagina web Plugin**, riservata al Tecnico, per installare gli altri plugin dal catalogo.
- **Servizio Accesso** del Core, funzionante **senza Internet**:
  - primo setup dell'unico Amministratore Tecnico, dal browser in HTTPS, con un **codice monouso** mostrato dall'installazione e valido 24 ore; nessun account predefinito;
  - accesso con nome utente e password per tutti (password solo come hash, blocco dopo tentativi falliti);
  - ruoli separati: il Tecnico gestisce gli amministratori, gli amministratori gestiscono gli operatori;
  - cambio password, password temporanee, revoca degli accessi, disattivazione;
  - Recovery Key del Tecnico, mostrata una sola volta e sostituita a ogni utilizzo; se è persa, recupero dalla console del Server;
  - funzioni sensibili (setup, recupero, plugin) solo in HTTPS;
  - registro degli eventi di sicurezza senza segreti.
- Postazioni del negozio: nessuna installazione, si usa il browser.
- Cartelle descrittive dei plugin previsti in `plugins/` (nessun plugin di produzione ancora sviluppato).

### Cambiato rispetto alle prove precedenti
- **EMP Desk (Windows) è stato eliminato** per decisione del titolare: un solo ambiente Linux al posto di Windows e macOS.
- Le build di prova di EMP Desk 0.5.0-alpha.1, alpha.2 e alpha.3 non sono state pubblicate come release; il codice resta nella cronologia di Git.

### Limiti noti
- Il pacchetto **non è firmato**: l'integrità si verifica con le impronte SHA-256 e con l'attestazione di provenienza.
- Su ogni postazione va installata una volta l'autorità di certificazione locale (guida per Windows, macOS e Firefox; Android e iOS da documentare).
- I backup restano nella VM finché non vengono copiati altrove, e non sono ancora cifrati.
- Identità white label e Control Center non ancora presenti.
- Collaudo su una VM VMware Workstation Pro reale da completare.
