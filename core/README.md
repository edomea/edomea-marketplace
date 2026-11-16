# EMP Core

Il **plugin core** di Edomea Marketplace, in **PHP** (8.2+): registro dei moduli, installazione con migrazioni, API, tracciabilità e **gestione degli altri plugin dall'interfaccia web**. Non dipende da WordPress né da Composer a runtime.

- Lo installa e lo aggiorna **EMP Desk** (modalità Server).
- Il Core installa a sua volta **tutti gli altri plugin**, dalla pagina `/amministrazione/plugin.html`.
- È esso stesso il modulo `emp-core` (manifest in `emp-module.json`). Specifica: [docs/architettura-moduli.md](../docs/architettura-moduli.md).

## Struttura

| Percorso | Ruolo |
|---|---|
| `src/Module/` | Manifest, Registry, Resolver (ordine Core → dipendenze → plugin), Installer (migrazioni tracciate), autoloader |
| `src/Plugin/` | Catalogo dei plugin (verifica SHA-256), estrazione sicura dei pacchetti, PluginManager |
| `src/Access/` | Servizio Accesso: utenti, ruoli, sessioni, password, Recovery Key del Tecnico |
| `src/Http/` | Router, richieste/risposte JSON, protezioni CSRF e "solo dal Server" |
| `src/Version/` | Versioni e vincoli (stesse regole di Desk) |
| `src/Support/` | Orologio dell'installazione (ora locale, formati gg/mm/aaaa) |
| `public/index.php` | Punto d'ingresso HTTP (radice del web server) |
| `public/amministrazione/` | Pagine web: primo setup, accesso e utenti, recupero del Tecnico, **Plugin** |
| `bin/emp` | Riga di comando: `piano`, `installa --utente=…`, `disinstalla`, `elenco` |
| `migrations/` | Migrazioni SQL del Core |

## API

| Metodo e percorso | Accesso | Funzione |
|---|---|---|
| `GET /api/v1/salute` | pubblico | Stato del servizio e versione del Core |
| `GET /api/v1/accesso/stato` | pubblico | Setup completato? Utente della sessione |
| `POST /api/v1/accesso/setup` | solo dal Server, una volta | Primo setup del Tecnico; restituisce la Recovery Key |
| `POST /api/v1/accesso/login` · `logout` | pubblico | Accesso con nome utente e password |
| `POST /api/v1/accesso/password` | utente | Cambio della propria password |
| `POST /api/v1/accesso/recupero` | solo dal Server | Recupero del Tecnico con la Recovery Key |
| `GET /api/v1/utenti` · `POST /api/v1/utenti` | Tecnico, amministratori | Elenco e creazione degli utenti gestiti |
| `POST /api/v1/utenti/{id}/password` · `revoca` · `disattiva` · `attiva` | Tecnico, amministratori | Password temporanea, revoca accessi, disattivazione |
| `GET /api/v1/plugin` | Tecnico, solo dal Server | Plugin disponibili e installati, con stato e dipendenze |
| `POST /api/v1/plugin/{id}/installa` | Tecnico, solo dal Server | Installa o aggiorna un plugin con le sue dipendenze |
| `POST /api/v1/plugin/{id}/disinstalla` | Tecnico, solo dal Server | Rimuove un plugin dal registro (i dati restano) |

Regole di accesso (solo password, funzionamento offline, Recovery Key del Tecnico): [docs/accesso-sicurezza.md](../docs/accesso-sicurezza.md).
Ogni richiesta che modifica dati richiede l'intestazione `X-EMP-Richiesta: 1` (protezione CSRF).

## Configurazione

File PHP fuori dalla cartella pubblica e fuori da Git, indicato dalla variabile `EMP_CONFIG`:

```php
<?php return [
    'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=emp',
    'user' => 'emp',
    'password' => '…',
    'prefix' => 'emp_',
    'timezone' => 'Europe/Rome',
    'modules_dir' => '/…/modules',     // dove Desk installa il Core e il Core installa i plugin
    'catalog_dir' => '/…/catalogo',    // facoltativo: emp-packages.json + ZIP dei plugin
];
```

## Test

```bash
composer install
vendor/bin/phpunit
```
