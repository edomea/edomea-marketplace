# EMP — Architettura modulare e installazione

Specifica tecnica per **Edomea Marketplace (EMP)**. Versione 0.3 · 16/11/2026 (EMP Server su Ubuntu al posto di EMP Desk).

Questo documento definisce come si installano il Core e i plugin. Integra il [manuale tecnico-operativo](manuale-tecnico-operativo.md) e la [mappa di fusione Edomea/VenVia](fusione-edomea-venvia.md).

## Chi installa cosa

| Componente | Cartella nel repository | Chi lo installa | Come |
|---|---|---|---|
| **EMP Server** | `server/` | Il tecnico, sulla console | `sudo ./installa.sh` su Ubuntu Server LTS |
| **emp-core** (plugin core) | `core/` | **EMP Server** (`installa.sh`) | Pacchetto incluso in `emp-server-<versione>.tar.gz` |
| **Plugin dell'ecosistema** | `plugins/<id>/` | **Il Core**, dalla pagina web *Plugin* | Pacchetti del catalogo |

```
EMP Server (installa.sh) ──installa──▶ emp-core ──installa dal web──▶ emp-studio, emp-magazzino, emp-pos, …
Postazioni del negozio ──browser in HTTPS──▶ pagine e API del Core (nessuna installazione)
```

---

## 1. Principi

1. **Prima il Core, poi i plugin.** EMP Server installa solo il Core; i plugin si installano dopo, dall'interfaccia web del Core. Nessun plugin si installa né si avvia se il Core non è installato e compatibile.
2. **Un solo formato di modulo.** Core e plugin sono entrambi *moduli* descritti dallo stesso manifest `emp-module.json`. Il Core è il modulo `emp-core` di tipo `core`; tutti gli altri sono di tipo `plugin`.
3. **Ordine deterministico.** L'ordine di installazione e di avvio è calcolato dalle dipendenze dichiarate (ordinamento topologico), con il Core sempre primo. A parità di dipendenze, l'ordine è alfabetico per `id`, quindi è identico su ogni installazione.
4. **Il Core governa i plugin, non WordPress.** In EMP Web, WordPress ospita il Core ma non installa né attiva i moduli EMP.
5. **Nessuna scrittura tra moduli.** Un modulo scrive solo nelle proprie tabelle (prefisso dichiarato nel manifest). Per i dati di altri moduli usa le API del Core.
6. **Pacchetti verificati.** Ogni pacchetto ha un'impronta SHA-256 nell'indice di distribuzione; un pacchetto con impronta diversa viene rifiutato prima dell'estrazione.
7. **Reversibilità.** Installazione e aggiornamento non cancellano dati commerciali. La disinstallazione di un modulo è bloccata se altri moduli installati ne dipendono.

---

## 2. Il manifest `emp-module.json`

Ogni modulo ha nella propria cartella radice un file `emp-module.json`:

```json
{
  "schema": 1,
  "id": "emp-esempio",
  "name": "Esempio",
  "version": "0.1.0",
  "type": "plugin",
  "description": "Plugin dimostrativo.",
  "php": ">=8.2.0",
  "requires": {
    "emp-core": ">=0.1.0 <0.2.0"
  },
  "class": "Emp\\Plugin\\Esempio\\Module",
  "autoload": { "Emp\\Plugin\\Esempio\\": "src/" },
  "table_prefix": "esempio_",
  "migrations": "migrations",
  "permissions": ["esempio.leggi"]
}
```

| Campo | Obbligatorio | Regole |
|---|---|---|
| `schema` | sì | Versione del formato del manifest. Oggi `1`. |
| `id` | sì | Minuscole, cifre e trattini, 3–64 caratteri, inizia con una lettera. Coincide con il nome della cartella. I moduli ufficiali iniziano con `emp-`. |
| `name` | sì | Nome leggibile. Nelle installazioni white label può essere rinominato in interfaccia, non nell'`id`. |
| `version` | sì | Versione semantica `MAJOR.MINOR.PATCH`, con eventuale suffisso `-alpha.N` / `-beta.N` / `-rc.N`. **Mai riutilizzata** dopo una consegna. |
| `type` | sì | `core` (solo `emp-core`) oppure `plugin`. |
| `php` | sì | Vincolo sulla versione di PHP. |
| `requires` | per i plugin | Mappa `id → vincolo`. Ogni plugin deve dichiarare `emp-core`. |
| `class` | sì | Classe che implementa `Emp\Core\Module\ModuleInterface`. |
| `autoload` | sì | Mappa PSR-4 `namespace → cartella` relativa al modulo. |
| `table_prefix` | se usa tabelle | Prefisso esclusivo delle tabelle del modulo (dopo il prefisso dell'installazione). Due moduli non possono avere lo stesso prefisso. |
| `migrations` | no | Cartella con le migrazioni SQL (vedi §5). |
| `permissions` | no | Permessi che il modulo registra presso il servizio di autorizzazione del Core. |

### Vincoli di versione

Un vincolo è una lista di condizioni separate da spazi, tutte da soddisfare:

- `>=0.1.0 <0.2.0`: da 0.1.0 incluso a 0.2.0 escluso;
- `=1.2.3` oppure `1.2.3`: esattamente quella versione;
- `^1.2.0`: compatibile, cioè `>=1.2.0 <2.0.0` (per `^0.x.y`: `>=0.x.y <0.(x+1).0`);
- operatori ammessi: `=`, `>`, `>=`, `<`, `<=`, `^`.

Le versioni con suffisso pre-release sono inferiori alla versione finale (`0.4.0-alpha.4 < 0.4.0`).

Le regole sono implementate nel Core (`src/Version/`) e usate anche da `installa.sh` per rifiutare un Core più vecchio di quello installato.

---

## 3. Pacchetti e indice di distribuzione

Un pacchetto è uno ZIP con **la cartella del modulo alla radice** (regola Edomea):

```
emp-esempio-0.1.0.zip
└── emp-esempio/
    ├── emp-module.json
    ├── src/…
    └── migrations/…
```

I pacchetti di una distribuzione sono elencati in `emp-packages.json`:

```json
{
  "schema": 1,
  "channel": "stable",
  "packages": [
    { "id": "emp-core", "version": "0.1.0", "file": "emp-core-0.1.0.zip", "sha256": "…" },
    { "id": "emp-esempio", "version": "0.1.0", "file": "emp-esempio-0.1.0.zip", "sha256": "…" }
  ]
}
```

Ci sono due indici distinti, generati da `tools/build-packages.php`:

| Indice | Contenuto | Dove si usa |
|---|---|---|
| Pacchetto del Core (`build-packages.php core`) | solo `emp-core` | `pacchetti/` del pacchetto EMP Server |
| Catalogo plugin (`build-packages.php plugin`) | tutti i plugin sviluppati | `catalogo/` del pacchetto EMP Server → `catalog_dir` del Core |

Entrambi sono generati dalla CI e allegati alla release GitHub, che il titolare approva pubblicandola (vedi *Release su GitHub*, §4-bis). In una fase successiva l'indice sarà firmato; fino ad allora la sua integrità dipende dal canale (release GitHub su HTTPS, attestazione di provenienza, `SHA256SUMS` del pacchetto EMP Server controllato da `installa.sh`).

---

## 4. Installazione del Core con EMP Server

Su Ubuntu Server LTS, con `sudo ./installa.sh` dal pacchetto `emp-server-<versione>.tar.gz` ([ambiente](ambiente-server-linux.md)):

1. **Controlli**: sistema operativo (Ubuntu 24.04 o 26.04 LTS), architettura, systemd, impronte SHA-256 di **tutti** i file del pacchetto (`SHA256SUMS`).
2. **Ambiente**: Nginx, PHP-FPM e MariaDB dai repository ufficiali di Ubuntu; MariaDB senza porte di rete; certificato HTTPS da un'autorità locale.
3. **Core**: l'indice `pacchetti/emp-packages.json` deve indicare `emp-core`. L'installazione:
   - verifica lo SHA-256 del pacchetto;
   - lo estrae in una cartella temporanea, rifiutando percorsi assoluti e `..`;
   - **rifiuta una versione più vecchia** di quella installata.
4. I file del Core vanno in `/var/lib/emp/moduli/emp-core/`, di proprietà di root e protetti dallo *sticky bit* della cartella dei moduli. La versione precedente resta in `/var/lib/emp/precedenti/`.
5. Le migrazioni si eseguono con `bin/emp installa`, come utente `emp`. Lo stato dei moduli è nel registro del database (`emp_modules`).

Le postazioni del negozio **non installano nulla**: usano le pagine e le API del Core dal browser, in HTTPS.

---

## 4-bis. Installazione dei plugin dall'interfaccia web del Core

Pagina: `/amministrazione/plugin.html` del Core. API:

| Metodo e percorso | Funzione |
|---|---|
| `GET /api/v1/plugin` | Elenco: versione disponibile e installata, dipendenze, stato (installabile, aggiornamento disponibile, installato, non compatibile con il motivo) |
| `POST /api/v1/plugin/{id}/installa` | Installa o aggiorna il plugin e le sue dipendenze |
| `POST /api/v1/plugin/{id}/disinstalla` | Rimuove il plugin dal registro; i dati restano |

Installazione di un plugin:
1. il Core legge il catalogo (`catalog_dir`), verifica lo SHA-256 di ogni pacchetto e ignora un'eventuale voce `emp-core` (il Core si aggiorna solo con `installa.sh`);
2. calcola il piano: dipendenze prima, Core sempre già presente, nessun conflitto di versione o di prefisso tabelle;
3. estrae i pacchetti mancanti o più recenti rifiutando percorsi assoluti, `..` e collegamenti simbolici;
4. esegue le migrazioni nell'ordine del piano, registrando l'esecutore.

**Accesso.** La gestione dei plugin è riservata all'**Amministratore Tecnico** autenticato ([accesso e sicurezza](accesso-sicurezza.md)) e risponde **solo in HTTPS** (o a richieste fatte sul Server stesso). In HTTP le altre postazioni ricevono `403`. Nella tracciabilità l'esecutore è il nome utente del Tecnico.

### Fonti dei pacchetti

I plugin si installano **a mano dal Core**: il Tecnico sceglie il plugin e preme *Installa*. I pacchetti possono arrivare da tre fonti; per ogni plugin vale la versione più recente, e a parità di versione quella di EMP Server. La colonna *Origine* della pagina mostra da dove viene ciascun pacchetto.

| Fonte | Cartella | Chi la riempie | Rete |
|---|---|---|---|
| Catalogo di EMP Server | `catalog_dir` (`/var/lib/emp/catalogo`, di root) | `installa.sh`, dal pacchetto EMP Server | no |
| **Caricamento manuale** | `local_catalog_dir` (`/var/lib/emp/catalogo-locale`, utente `emp`) | il Tecnico, dal browser (*Carica un pacchetto*) | no |
| **GitHub, facoltativa** | `local_catalog_dir` | il Tecnico, importando una release pubblicata (*Da GitHub*) | solo durante l'importazione |

Caricare o importare **non installa**: il pacchetto entra nel catalogo locale e il plugin compare nell'elenco come installabile o aggiornabile.

**Caricamento manuale** (`POST /api/v1/plugin/carica`, corpo `application/zip`, massimo 64 MB). Il pacchetto viene rifiutato se:
- ha più di una cartella alla radice o una cartella diversa dall'`id` del plugin;
- contiene percorsi con `..`, percorsi assoluti o collegamenti simbolici;
- il manifest non è valido, oppure il pacchetto è il Core;
- l'impronta SHA-256 non coincide con quella indicata (campo facoltativo, intestazione `X-EMP-Impronta`);
- la stessa versione è già presente con un contenuto diverso: per cambiare un plugin si aumenta la versione.

Il catalogo locale tiene una sola versione per plugin. *Togli pacchetto* (`POST /api/v1/plugin/{id}/rimuovi-pacchetto`) lo toglie dal catalogo, ma il plugin installato resta.

**Sorgente GitHub** (`GET/POST /api/v1/plugin/github`, `GET /api/v1/plugin/github/release`, `POST /api/v1/plugin/github/importa`):
- **disattivata di predefinito**; la attiva il Tecnico indicando il repository (predefinito `edomea/edomea-marketplace`). Disattivata, il Core non contatta mai GitHub;
- legge **solo le release pubblicate**: bozze e pre-release sono escluse, quindi entra solo ciò che il titolare ha approvato pubblicandolo;
- la release deve allegare `emp-packages.json` e i pacchetti che elenca. Tutti i pacchetti vengono scaricati e verificati (SHA-256 dell'indice e manifest) **prima** di entrare nel catalogo locale: se uno non è valido, non entra nessuno;
- connessioni solo HTTPS verso i domini di GitHub, anche nei reindirizzamenti, senza credenziali (estensione `php-curl`; `allow_url_fopen` resta disattivato);
- connessione solo quando il Tecnico preme *Mostra le release* o *Importa*.

Caricamenti, importazioni, rimozioni e modifiche della sorgente GitHub sono registrati nella tracciabilità (`core_audit`), con versione, impronta e origine.

### Release su GitHub

Il workflow `.github/workflows/release.yml` parte con un tag `vVERSIONE`, uguale a `server/VERSIONE`, e crea una release **in bozza** con allegati:
- il pacchetto `emp-server-<versione>.tar.gz` con il suo `.sha256`;
- il catalogo dei plugin, cioè `emp-packages.json` e i file ZIP.

La bozza non è visibile ai Server finché il titolare non la **pubblica**: pubblicare la release è l'atto di approvazione. Per aggiornare EMP Server dalla release si usa il pacchetto allegato con `sudo ./installa.sh`, oppure il workflow *Installa EMP Server* ([installazione da GitHub](installazione-da-github.md)).

---

## 5. Ciclo di vita nel Core

Il registro dei moduli del Core (`Emp\Core\Module\Registry`) scopre i manifest in `modules/`, li valida e calcola l'ordine. Per ogni modulo:

| Fase | Cosa succede |
|---|---|
| `install` | Esegue le migrazioni non ancora applicate, nell'ordine dei nomi dei file (`0001_…sql`, `0002_…sql`). Registra il modulo come installato. |
| `boot` | A ogni richiesta, nell'ordine calcolato: registra servizi, rotte API e permessi. |
| `upgrade` | Su nuova versione: esegue solo le migrazioni nuove. Una versione inferiore a quella installata è rifiutata. |
| `uninstall` | Bloccata se un altro modulo installato ne dipende. I dati non vengono eliminati automaticamente. |

Ogni migrazione applicata è registrata nella tabella `emp_migrations` (modulo, file, impronta SHA-256, utente, data e ora locale). Una migrazione già applicata il cui contenuto è cambiato blocca l'avvio: le migrazioni pubblicate non si modificano, se ne aggiunge una nuova.

Nelle migrazioni il segnaposto `{prefix}` viene sostituito con il prefisso dell'installazione più il `table_prefix` del modulo.

Attenzione: in MariaDB le istruzioni DDL (`CREATE`, `ALTER`, `DROP`) non sono transazionali. Per questo ogni aggiornamento passa prima dallo staging con backup verificato (manuale, sezione 07).

---

## 6. Regole ereditate da Edomea, valide per tutti i moduli

- **Autorizzazione centralizzata.** Nessun modulo implementa una propria logica di ruoli o negozi: tutti chiedono al servizio di accesso del Core (erede di `WCMAN_Access`).
- **Tracciabilità.** Ogni operazione che modifica dati (giacenze, prezzi, anagrafiche, movimenti, ordini, sessioni) registra utente, data e ora, valore precedente e nuovo. Nessuna modifica anonima.
- **Ora locale esplicita.** Data e ora sono sempre impostate dal Core con il fuso dell'installazione, mai con i valori predefiniti del database.
- **Date in interfaccia**: `gg/mm/aaaa` e `gg/mm/aaaa HH:MM`.
- **`store_id = 0`** indica il contenitore "non identificato": non si somma né si attribuisce mai a un negozio reale.
- **Versioni mai riutilizzate**: anche una correzione immediata incrementa almeno la patch, in manifest, costanti e nome dello ZIP.
- **Solo PHP** per Core e plugin: niente interpreti esterni, niente librerie di sistema non standard.
