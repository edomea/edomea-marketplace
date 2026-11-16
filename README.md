# Edomea Marketplace

**Vendi e compra dove vuoi e come vuoi.**

Edomea Marketplace è una piattaforma commerciale open source, multicanale e multipiattaforma, distribuita come **software white label**: ogni azienda può adottarla e presentarla con il proprio nome, logo, colori e domini, usando lo stesso Core e gli stessi moduli.

Il progetto è in fase iniziale di sviluppo: EMP Desk 0.5.0-alpha.1 e Core 0.1.0 sono versioni di laboratorio, non pronte per la produzione.

## Componenti

Il repository è diviso in tre parti, con compiti separati:

| Parte | Cartella | Linguaggio | Cosa fa | Chi la installa |
|---|---|---|---|---|
| **EMP Desk** | [`desk/`](desk/) | Rust (Tauri) | App per Windows (poi macOS e Linux): prepara il computer come **Server** del negozio o come postazione **Client** | L'operatore, con l'installer |
| **EMP Core** | [`core/`](core/) | PHP | Il plugin core: API, dati, tracciabilità, gestione degli altri plugin | EMP Desk, in modalità Server |
| **Plugin dell'ecosistema** | [`plugins/`](plugins/) | PHP | Catalogo, magazzino, cassa, scontrini, contabilità, assistenza… ([elenco](plugins/README.md)) | Il Core, dalla sua pagina web **Plugin** |

```
EMP Desk ──▶ installa EMP Core ──▶ dalla pagina web Plugin del Core si installano gli altri plugin
```

Altre cartelle: [`docs/`](docs/) documentazione, [`tools/`](tools/) script per costruire i pacchetti, [`.github/`](.github/) automazioni di verifica e rilascio.

## Documentazione

- [Manuale tecnico-operativo](docs/manuale-tecnico-operativo.md): architettura, installazione, sicurezza, funzioni, sincronizzazione, disaster recovery e piano di sviluppo.
- [Architettura modulare e installazione](docs/architettura-moduli.md): manifest dei moduli, ordine Core → plugin, pacchetti verificati.
- [Account, ruoli e sicurezza di accesso](docs/accesso-sicurezza.md): solo password (funziona offline), ruoli, revoca degli accessi, Recovery Key del Tecnico.
- [Fusione Edomea + VenVia](docs/fusione-edomea-venvia.md): cosa EMP prende da ciascuno e mappa dei plugin Edomea.

## Partecipare

Chiunque può partecipare: segnalazioni, proposte, codice e documentazione sono benvenuti. Ogni modifica entra nel progetto solo dopo l'**approvazione esplicita del titolare**. Leggi [CONTRIBUTING.md](CONTRIBUTING.md) prima di aprire una pull request.

## Marchio

Il codice è open source. Il nome e il marchio **Edomea** restano del titolare del progetto: le installazioni white label usano il proprio marchio, e l'uso del marchio Edomea richiede un'autorizzazione separata.

## Licenza

In definizione.
