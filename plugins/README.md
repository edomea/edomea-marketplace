# Plugin dell'ecosistema EMP

Ogni cartella di `plugins/` è **un plugin** di Edomea Marketplace. I plugin:

- sono scritti in **PHP** e girano sopra il Core (`core/`);
- **si installano dall'interfaccia web del Core** (pagina *Plugin*), non da `installa.sh`;
- dichiarano nome, versione e dipendenze nel proprio `emp-module.json` ([specifica](../docs/architettura-moduli.md));
- vengono pubblicati come pacchetti ZIP verificati (SHA-256) negli allegati delle release.

Una cartella che contiene solo il `README.md` è un plugin **previsto ma non ancora sviluppato**: senza `emp-module.json` non viene impacchettato né mostrato dal Core.

## Elenco

| Plugin | Funzione | Deriva da (Edomea) | Stato |
|---|---|---|---|
| [emp-esempio](emp-esempio/) | Plugin dimostrativo della struttura | — | Esempio, non per la produzione |
| [emp-studio](emp-studio/) | Catalogo prodotti: anagrafica, EAN, varianti, prezzi, modifica massiva | edomea-view-edit, edomea-shop-manager | Previsto (P0) |
| [emp-magazzino](emp-magazzino/) | Giacenze, movimenti, carichi, inventari, multi-negozio | edomea-magazzino | Previsto (P0) ⚠️ |
| [emp-pos](emp-pos/) | Cassa e vendite al banco, documenti, consegne | edomea-pos | Previsto (P0) |
| [emp-scontrino](emp-scontrino/) | Registratore telematico e scontrini | edomea-scontrino | Previsto (P0) |
| [emp-contabilita](emp-contabilita/) | Fatture elettroniche, DDT, fornitori | edomea-contabilita | Previsto (P0) |
| [emp-assistenza](emp-assistenza/) | Riparazioni e laboratorio | edomea-assistenza | Previsto |
| [emp-statistiche](emp-statistiche/) | Analisi vendite multi-negozio | edomea-stat | Previsto |
| [emp-fidelity](emp-fidelity/) | Punti, premi, comunicazioni ai clienti | edomea-fidelity | Previsto |
| [emp-cartelli](emp-cartelli/) | Cartellini prezzo ed etichette | edomea-cartelli, edomea-dymo | Previsto |
| [emp-fornitori-eurocom](emp-fornitori-eurocom/) | Ordini e DDT Eurocom | edomea-eurocom | Previsto |
| [emp-fornitori-dayabridge](emp-fornitori-dayabridge/) | Ordini e DDT DayaBridge | DayaBridge | Previsto |
| [emp-fornitori-espo](emp-fornitori-espo/) | Ricambi ESPO/ASWO | edomea-espo | Previsto |
| [emp-amazon](emp-amazon/) | Integrazione Amazon | edomea-amazon-combo | Previsto |
| [emp-woocommerce](emp-woocommerce/) | Canale web WordPress/WooCommerce | — (nuovo, EMP Web) | Previsto |

I servizi comuni non sono plugin ma parte del Core: autorizzazioni (erede di `WCMAN_Access` di edomea-manager), tracciabilità, generatore EAN (erede di edomea-ean), anagrafica clienti condivisa.

**P0** = priorità del primo obiettivo: rendere il Negozio Zero autonomo.
⚠️ = regola di cautela: nessuna modifica senza autorizzazione esplicita del titolare.

## Struttura di un plugin

```
plugins/emp-<nome>/
├── emp-module.json      nome, versione, dipendenze, prefisso tabelle, permessi
├── README.md            a cosa serve, dipendenze, stato
├── src/Module.php       classe che registra rotte API e servizi
├── migrations/          0001_….sql, 0002_….sql (mai modificate dopo il rilascio)
└── public/              pagine web del plugin (facoltativo)
```

Regole valide per tutti i plugin: [docs/architettura-moduli.md §6](../docs/architettura-moduli.md).

## Come si installa un plugin

1. EMP Server (`sudo ./installa.sh`) installa il Core e copia il catalogo dei plugin del pacchetto.
2. Il Tecnico apre, in HTTPS da una postazione del negozio, la pagina **Plugin** del Core (`/amministrazione/plugin.html`).
3. La pagina elenca i plugin del catalogo con stato e dipendenze; *Installa* estrae i pacchetti verificati e prepara il database nell'ordine corretto.
