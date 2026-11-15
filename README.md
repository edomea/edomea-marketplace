# Edomea Marketplace

**Vendi e compra dove vuoi e come vuoi.**

Edomea Marketplace è una piattaforma commerciale open source, multicanale e multipiattaforma, distribuita come **software white label**: ogni azienda può adottarla e presentarla con il proprio nome, logo, colori e domini, usando lo stesso Core e gli stessi moduli.

Il progetto è in fase iniziale di sviluppo: EMP Desk 0.5.0-alpha.1 e Core 0.1.0 sono versioni di laboratorio, non pronte per la produzione.

## Componenti

| Componente | Linguaggio | Cartella | Ruolo |
|---|---|---|---|
| Edomea Core | PHP | [`core/`](core/) | API, modello dati, autorizzazioni, transazioni, moduli |
| Edomea Desk | Rust | [`desk/`](desk/) | App nativa Windows/macOS/Linux, modalità Server e Client |
| Moduli e plugin | PHP | [`plugins/`](plugins/) | Studio, cassa, logistica, integrazione Web/WooCommerce |

## Documentazione

- [Manuale tecnico-operativo](docs/manuale-tecnico-operativo.md): architettura, installazione, sicurezza, funzioni, sincronizzazione, disaster recovery e piano di sviluppo.
- [Architettura modulare e installazione](docs/architettura-moduli.md): manifest dei moduli, ordine Core → plugin, pacchetti verificati.
- [Fusione Edomea + VenVia](docs/fusione-edomea-venvia.md): cosa EMP prende da ciascuno e mappa dei plugin Edomea.

## Partecipare

Chiunque può partecipare: segnalazioni, proposte, codice e documentazione sono benvenuti. Ogni modifica entra nel progetto solo dopo l'**approvazione esplicita del titolare**. Leggi [CONTRIBUTING.md](CONTRIBUTING.md) prima di aprire una pull request.

## Marchio

Il codice è open source. Il nome e il marchio **Edomea** restano del titolare del progetto: le installazioni white label usano il proprio marchio, e l'uso del marchio Edomea richiede un'autorizzazione separata.

## Licenza

In definizione.
