# EMP — Fusione Edomea + VenVia

Versione 0.1 · 15/11/2026 · **Proposta da approvare**

EMP (Edomea Marketplace) nasce dall'unione di due linee di lavoro:

- **Edomea (Ecosistema 1.0)**: suite di plugin WordPress/WooCommerce già in uso in negozio, con funzioni mature e regole operative consolidate.
- **VenVia 0.4**: nuova architettura con Desk nativo (Rust/Tauri), Core PHP indipendente da WordPress, funzionamento in LAN anche senza Internet, Control Center, sicurezza della distribuzione.

## Cosa prende EMP da ciascuno

| Da VenVia | Da Edomea |
|---|---|
| Desk Rust/Tauri, modalità Server e Client | Funzioni gestionali collaudate in negozio |
| Core PHP API-first, non dipendente da WordPress | Autorizzazione centralizzata (`WCMAN_Access`) |
| Operatività LAN senza Internet, sincronizzazione con Centrale | Tracciabilità di ogni modifica (utente, ora, prima/dopo) |
| Control Center, backup, staging, migrazioni | Registro movimenti come unica fonte delle giacenze (`wcmag_movements`) |
| Gate di sicurezza CI (audit, Clippy, Defender, SHA-256) | Regole su orari locali, formato date, `store_id=0`, versioni mai riutilizzate |
| Design System VenVia | Standard delle app frontend (SPA autonome, header, helper JS) |

## Decisioni di fusione

1. **Il nome è EMP / Edomea Marketplace.** I nomi VenVia restano solo nello storico.
2. **WordPress non è un prerequisito del server locale.** Il Core gira su PHP + MariaDB + Nginx installati da Desk. WordPress/WooCommerce restano il canale web facoltativo (EMP Web). Questo chiude il "punto da riconciliare" dell'handover VenVia (§2).
3. **EMP Desk installa solo il Core**; tutti gli altri plugin si installano dall'interfaccia web del Core (pagina *Plugin*). Desk resta l'installatore dell'ambiente, il Core governa l'ecosistema dei plugin.
4. **I plugin Edomea diventano moduli EMP**, uno alla volta, sopra il Core. Finché un modulo EMP non è collaudato, il plugin Edomea corrispondente resta in uso: nessuna sostituzione forzata.
5. **I servizi trasversali passano nel Core**: autorizzazione, tracciabilità (audit), orologio locale, anagrafica clienti condivisa, generatore EAN, registro migrazioni.
6. **Negozio Zero (Cirò Marina)** è la prima installazione: priorità P0 dell'handover (login/ruoli, Studio, giacenze, POS, acquisti, clienti, documenti).

## Mappa plugin Edomea → moduli EMP

| Plugin Edomea | Modulo EMP proposto | Note |
|---|---|---|
| edomea-manager (`WCMAN_Access`, hub) | **emp-core** (servizio Accesso) | L'autorizzazione centralizzata diventa un servizio del Core |
| edomea-ean | **emp-core** (servizio EAN) | Stesso prefisso GS1 `209000`, idempotente per sorgente + riferimento |
| edomea-pos | emp-pos | Include clienti condivisi (`wcpos_customers` → anagrafica del Core) |
| edomea-scontrino | emp-scontrino | Effatta per RT; collaudo fiscale obbligatorio |
| edomea-magazzino | emp-magazzino | ⚠️ **Regola di cautela**: nessuna modifica senza autorizzazione esplicita di Antonio. Registro movimenti = unica fonte |
| edomea-view-edit, edomea-shop-manager | emp-studio | Catalogo, modifica massiva, inventario |
| edomea-contabilita | emp-contabilita | FatturaPA, DDT |
| edomea-assistenza | emp-assistenza | Riparazioni e laboratorio |
| edomea-stat | emp-statistiche | |
| edomea-fidelity | emp-fidelity | |
| edomea-cartelli, edomea-dymo | emp-cartelli | Cartellini prezzo ed etichette |
| edomea-eurocom | emp-fornitori-eurocom | Alimenta il magazzino solo tramite movimenti di carico via API |
| DayaBridge | emp-fornitori-dayabridge | Come sopra |
| edomea-espo | emp-fornitori-espo | Come sopra |
| edomea-amazon-combo | emp-amazon | Con estensione browser |
| — (nuovo) | emp-woocommerce | Canale web EMP Web |

Ogni plugin ha la propria cartella con un README descrittivo in [`plugins/`](../plugins/README.md).

L'ordine di porting verrà deciso modulo per modulo; ogni porting è una pull request separata da approvare.
