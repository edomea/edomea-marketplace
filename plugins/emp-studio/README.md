# emp-studio — EMP Studio (catalogo prodotti)

**Stato:** 0.2.0, priorità P0 · **Deriva da:** edomea-view-edit, edomea-shop-manager · **Richiede:** Core ≥ 0.1.19 (EMP Server ≥ 0.6.0-alpha.23)

## Cosa fa (0.2.0)
- **Schede prodotto**: SKU (automatico `P000001…` se vuoto), EAN-13/EAN-8/UPC-A con controllo della cifra, codice del produttore (MPN), nome, marca, categoria (albero), testi, IVA e unità di misura (dalle Tabelle).
- **Caratteristiche al momento**: dalla scheda "Aggiungi caratteristica" (es. Garanzia → 24 mesi). Una caratteristica nuova resta disponibile per tutte le schede; una scheda ha solo le caratteristiche che le servono (garanzia, classe energetica, EPREL non sono più campi fissi: la 0.2.0 sposta i valori già inseriti).
- **Tabelle**: aliquote IVA e unità di misura modificabili dalla pagina "Tabelle" (altre tabelle si aggiungono in `Catalog::TABLES`).
- **EAN interni** (circolazione ristretta, prefisso `209`) per articoli senza codice del produttore.
- **Famiglie (facoltative)**: modelli di scheda (Lavatrici, Televisori, Smartphone…) che propongono le loro caratteristiche e indicano quali sono obbligatorie.
- **Completezza** della scheda (0–100%) con l'elenco di ciò che manca: dati base, EAN, almeno una foto, caratteristiche obbligatorie della famiglia.
- **Foto**: immagine principale e galleria di foto aggiuntive (JPEG/PNG/WebP fino a 8 MB, anche trascinandole); una foto della galleria può diventare la principale.
- **Video e documenti**: PDF fino a 16 MB, video come link https. File in `/var/lib/emp/dati-moduli/emp-studio/pubblico/`, inclusi nei backup cifrati.
- **Varianti**: un "modello" (es. Galaxy S25) con varianti (memoria, colore) che ereditano marca, categoria, famiglia e testi e hanno codici e caratteristiche proprie.
- **Ricerca** a più parole su nome, marca e codici; il lettore di codici a barre (Invio) apre direttamente la scheda.
- **Tracciabilità**: ogni modifica nel registro del Core con utente, data, valori prima/dopo.

## Permessi
| Permesso | Consente |
|---|---|
| `studio.vedi` | consultare catalogo e schede (voce "Catalogo prodotti" nel menu) |
| `studio.modifica` | creare e modificare schede, foto, varianti |
| `studio.configura` | categorie, marche, famiglie, caratteristiche |

Gli amministratori operativi li hanno tutti; agli operatori si danno con un ruolo (Ruoli e permessi). L'Amministratore Tecnico gestisce il sistema e **non** vede i dati commerciali.

## Prossimi passi
Prezzi (plugin Pricing), giacenze (Magazzino), importazione da WooCommerce/Excel, modifica massiva, esportazione `.xlsx`, dati da Icecat ed EPREL.
