# EMP Desk

Applicazione nativa per Windows (priorità), macOS e Linux, scritta in **Rust** con **Tauri 2**. Deriva da VenVia Desk 0.4 Alpha 4.

## Compito di Desk

Desk prepara il computer e **installa solo il Core** (`emp-core`):

| Modalità | Cosa fa |
|---|---|
| **Server** | Diagnostica, ambiente PHP/MariaDB/Nginx (Macro 2), installazione e aggiornamento del Core incluso nell'installer |
| **Client** | Salva l'indirizzo `https://` del Server e usa le sue API. Non installa database né moduli |

Tutti gli altri plugin si installano **dalla pagina web Plugin del Core**, non da Desk ([architettura](../docs/architettura-moduli.md)).

## Struttura

| Cartella | Contenuto |
|---|---|
| `crates/emp-core-installer/` | Verifica SHA-256 del pacchetto del Core, estrazione sicura (niente `..`, percorsi assoluti, collegamenti simbolici), versione precedente conservata in `modules/.precedenti/`, stato in `installed.json` |
| `app/src-tauri/` | Applicazione desktop: diagnostica, modalità Server/Client, controllo e installazione del Core |
| `app/src-tauri/pacchetti/` | Pacchetto del Core incluso nell'installer (generato dalla CI) |
| `app/ui/` | Interfaccia (HTML/CSS/JS locali, nessuna risorsa remota) |

## Stato (0.5.0-alpha.1)

Funziona:
- diagnostica del computer (sistema, CPU, RAM, dischi, porte 80/443/3306, processi runtime);
- modalità **Server** o **Client** salvata in modo persistente; il Client accetta solo un indirizzo `https://` del Server;
- in modalità Server: controllo e installazione del Core incluso nell'installer;
- **avvio del Core in modalità laboratorio**: PHP ufficiale per Windows incluso nell'installer (scaricato dalla CI da windows.php.net e verificato con SHA-256), database SQLite nella cartella dati, Core su `http://127.0.0.1:8180` raggiungibile solo dal computer Server finché Desk è aperto. Il pulsante *Apri amministrazione* porta al primo setup del Tecnico e all'accesso.

Non ancora presente (vedi roadmap del manuale):
- Nginx, MariaDB e Core come servizi di Windows ad avvio automatico (Macro 2 completa);
- DNS locale, certificati HTTPS e verifica TLS del Client (Macro 3);
- firma Authenticode dell'installer.

## Sviluppo

```bash
cd desk
cargo test --locked --workspace
cargo clippy --locked --workspace --all-targets -- -D warnings
```

Su Linux servono le librerie WebKitGTK (`libwebkit2gtk-4.1-dev`, `libgtk-3-dev`, `librsvg2-dev`).

L'installer Windows (NSIS) è prodotto solo dalla CI (`.github/workflows/desk-windows.yml`), con audit delle dipendenze, Clippy, test e scansione Microsoft Defender. Il pacchetto `EMP-Desk-security-validation-UNSIGNED` **non è firmato** e non va distribuito ai negozi.
