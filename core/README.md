# EMP Core

Motore **PHP** (8.2+) di Edomea Marketplace: registro dei moduli, installazione con migrazioni, API, tracciabilità. Non dipende da WordPress né da Composer a runtime.

- Specifica dei moduli: [docs/architettura-moduli.md](../docs/architettura-moduli.md)
- Il Core è esso stesso il modulo `emp-core` (manifest in `emp-module.json`).

## Struttura

| Percorso | Ruolo |
|---|---|
| `src/Module/` | Manifest, Registry, Resolver (ordine Core → plugin), Installer (migrazioni), autoloader |
| `src/Version/` | Versioni e vincoli (stesse regole di Desk) |
| `src/Http/` | Router e risposte JSON delle API `/api/v1/…` |
| `public/index.php` | Punto d'ingresso HTTP (radice del web server) |
| `bin/emp` | Riga di comando: `piano`, `installa --utente=…`, `disinstalla`, `elenco` |
| `migrations/` | Migrazioni SQL del Core |

La configurazione (database, cartella moduli, fuso orario) sta in un file PHP fuori dal web e da Git, indicato dalla variabile `EMP_CONFIG`.

## Test

```bash
composer install
vendor/bin/phpunit
```
