# emp-pos — Cassa e vendite

**Stato:** previsto, priorità P0 · **Deriva da:** edomea-pos

## Scopo
Vendita al banco, sconti entro soglia, sottocosto con approvazione, resi, documenti emessi (DDT, prenotazioni, vendite differite), consegne con fascia oraria, sessioni di cassa. Opera sul Server del negozio anche senza Internet.

## Dipendenze previste
- `emp-core` (anagrafica clienti condivisa, autorizzazioni, tracciabilità)
- `emp-studio`, `emp-magazzino`
- `emp-scontrino` per l'emissione fiscale
