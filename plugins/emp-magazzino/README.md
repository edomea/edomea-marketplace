# emp-magazzino — Giacenze e movimenti

**Stato:** previsto, priorità P0 · **Deriva da:** edomea-magazzino

> ⚠️ **Regola di cautela:** prima di qualsiasi modifica a questo plugin serve un'analisi approfondita e l'**autorizzazione esplicita del titolare**.

## Scopo
Giacenze per negozio, carichi, scarichi, trasferimenti, rettifiche (da Area Manager in su), inventari, costo medio ponderato con trasporto opzionale. Gestione monomagazzino o segmentata.

## Principi ereditati
- Il **registro dei movimenti** è l'unica fonte delle giacenze; la giacenza si ricalcola in valore assoluto, mai per differenza.
- Nessuna sincronizzazione automatica che possa sovrascrivere le giacenze.
- `store_id = 0` è il contenitore "non identificato" e non si somma mai a un negozio reale.
- Le variazioni di prezzo da importazione vanno tracciate come movimento con valore prima/dopo.

## Dipendenze previste
- `emp-core`
- `emp-studio` (anagrafica prodotti)
