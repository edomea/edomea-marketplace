# EMP Core

Il **plugin core** di Edomea Marketplace, in **PHP** (8.2+): registro dei moduli, installazione con migrazioni, API, tracciabilità e **gestione degli altri plugin dall'interfaccia web**. Non dipende da WordPress né da Composer a runtime.

- Lo installa e lo aggiorna **EMP Server** (`sudo ./installa.sh` su Ubuntu Server LTS, vedi [docs/ambiente-server-linux.md](../docs/ambiente-server-linux.md)).
- Il Core installa a sua volta **tutti gli altri plugin**, dalla pagina `/techadmin/plugin.html`.
- È esso stesso il modulo `emp-core` (manifest in `emp-module.json`). Specifica: [docs/architettura-moduli.md](../docs/architettura-moduli.md).

## Struttura

| Percorso | Ruolo |
|---|---|
| `src/Module/` | Manifest, Registry, Resolver (ordine Core → dipendenze → plugin), Installer (migrazioni tracciate), autoloader |
| `src/Plugin/` | Catalogo dei plugin (verifica SHA-256), estrazione sicura dei pacchetti, PluginManager |
| `src/Access/` | Servizio Accesso: utenti, ruoli, sessioni, password, Recovery Key del Tecnico |
| `src/Http/` | Router, richieste/risposte JSON, protezioni CSRF e "solo in HTTPS" (AdminGuard) |
| `src/Version/` | Versioni e vincoli (semver con pre-release) |
| `src/Support/` | Orologio dell'installazione (ora locale, formati gg/mm/aaaa) |
| `public/index.php` | Punto d'ingresso HTTP (radice del web server) |
| `public/techadmin/` | Pagine dell'Amministratore Tecnico: primo setup, accesso, amministratori, recupero, **Plugin** |
| `public/admin/` | Pagine dell'Amministratore Operativo e degli utenti operativi: accesso, utenti, cambio password |
| `public/assets/` | Script e stile comuni alle due aree |
| `bin/emp` | Riga di comando: `piano`, `installa --utente=…`, `disinstalla`, `elenco`, `codice-setup`, `recupero-tecnico --tecnico=…` |
| `migrations/` | Migrazioni SQL del Core |

## API

| Metodo e percorso | Accesso | Funzione |
|---|---|---|
| `GET /api/v1/salute` | pubblico | Stato del servizio e versione del Core |
| `GET /api/v1/accesso/stato` | pubblico | Setup completato? Utente della sessione |
| `POST /api/v1/accesso/setup` | solo HTTPS, con codice di setup, una volta | Primo setup del Tecnico; restituisce la Recovery Key |
| `POST /api/v1/accesso/login` · `logout` | pubblico | Accesso con nome utente e password |
| `POST /api/v1/accesso/password` | utente | Cambio della propria password |
| `POST /api/v1/accesso/recupero` | solo HTTPS | Recupero del Tecnico con la Recovery Key |
| `GET /api/v1/utenti` · `POST /api/v1/utenti` | Tecnico, amministratori | Elenco e creazione degli utenti gestiti |
| `POST /api/v1/utenti/{id}/password` · `revoca` · `disattiva` · `attiva` | Tecnico, amministratori | Password temporanea, revoca accessi, disattivazione |
| `GET /api/v1/plugin` | Tecnico, solo HTTPS | Plugin disponibili e installati, con stato e dipendenze |
| `POST /api/v1/plugin/{id}/installa` | Tecnico, solo HTTPS | Installa o aggiorna un plugin con le sue dipendenze |
| `POST /api/v1/plugin/{id}/disinstalla` | Tecnico, solo HTTPS | Rimuove un plugin dal registro (i dati restano) |

Regole di accesso (solo password, funzionamento offline, Recovery Key del Tecnico): [docs/accesso-sicurezza.md](../docs/accesso-sicurezza.md).
Ogni richiesta che modifica dati richiede l'intestazione `X-EMP-Richiesta: 1` (protezione CSRF).

## Configurazione

File PHP fuori dalla cartella pubblica e fuori da Git, indicato dalla variabile `EMP_CONFIG`:

```php
<?php return [
    'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=emp',
    'user' => 'emp',
    'password' => '',                  // EMP Server: nessuna password, autenticazione unix_socket
    'prefix' => 'emp_',
    'timezone' => 'Europe/Rome',
    'modules_dir' => '/…/modules',     // dove installa.sh mette il Core e il Core installa i plugin
    'catalog_dir' => '/…/catalogo',    // facoltativo: emp-packages.json + ZIP dei plugin
];
```

## Test

```bash
composer install
vendor/bin/phpunit
```
