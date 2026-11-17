# emp-fornitori-eurocom — Fornitore Eurocom

**Stato:** previsto · **Deriva da:** edomea-eurocom

## Scopo
Lettura degli ordini e dei DDT del fornitore Eurocom, cruscotto ordini, spedizioni e file di carico per il magazzino.

Il plugin **non scrive direttamente** nelle tabelle del magazzino: produce movimenti di carico tramite le API di `emp-magazzino`.

## Dipendenze previste
- `emp-core`, `emp-studio`, `emp-magazzino`
