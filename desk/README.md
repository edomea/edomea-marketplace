# EMP Desk

Applicazione nativa per Windows (priorità), macOS e Linux, scritta in **Rust** con **Tauri 2**. Deriva da VenVia Desk 0.4 Alpha 4.

| Cartella | Contenuto |
|---|---|
| `crates/emp-modules/` | Motore di installazione: verifica SHA-256 dei pacchetti, ordine Core → plugin, estrazione sicura, stato `installed.json` |
| `app/src-tauri/` | Applicazione desktop: diagnostica, modalità Server/Client, piano e installazione dei moduli |
| `app/ui/` | Interfaccia (HTML/CSS/JS locali, nessuna risorsa remota) |

## Stato (0.5.0-alpha.1)

Funziona:
- diagnostica del computer (sistema, CPU, RAM, dischi, porte 80/443/3306, processi runtime);
- modalità **Server** o **Client** salvata in modo persistente; il Client accetta solo un indirizzo `https://` del Server;
- in modalità Server: piano di installazione dei pacchetti inclusi nell'installer e installazione dei file, **Core per primo**, poi i plugin in ordine di dipendenza; le versioni sostituite restano in `modules/.precedenti/`.

Non ancora presente (vedi roadmap del manuale):
- installazione automatica di PHP, MariaDB e Nginx e dei servizi (Macro 2): fino ad allora le migrazioni del database si eseguono con `php bin/emp installa`;
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
