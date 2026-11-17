# emp-studio — Catalogo prodotti

**Stato:** previsto, priorità P0 · **Deriva da:** edomea-view-edit, edomea-shop-manager

## Scopo
Anagrafica unica dei prodotti: codici, EAN, descrizioni, immagini, video, categorie, varianti e prezzi. Inserimento rapido e massivo, modifica massiva, ricerca a più parole, esportazione Excel. Funziona senza WooCommerce; la pubblicazione online è un'operazione esplicita e autorizzata.

## Dipendenze previste
- `emp-core` (autorizzazioni, tracciabilità, generatore EAN)

## Note dalla versione Edomea
- Esportazione `.xlsx` in OOXML nativo con ZipArchive.
- Filtro per fornitore; permessi a tre livelli tramite il servizio Accesso del Core.
