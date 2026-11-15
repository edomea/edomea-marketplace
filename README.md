# Edomea Marketplace

**Vendi e compra dove vuoi e come vuoi.**

Edomea Marketplace è una piattaforma commerciale open source, multicanale e multipiattaforma, distribuita come **software white label**: ogni azienda può adottarla e presentarla con il proprio nome, logo, colori e domini, usando lo stesso Core e gli stessi moduli.

Il progetto è in fase di progettazione (baseline 0.4.1). Non esistono ancora pacchetti installabili.

## Componenti

| Componente | Linguaggio | Cartella | Ruolo |
|---|---|---|---|
| Edomea Core | PHP | [`core/`](core/) | API, modello dati, autorizzazioni, transazioni, moduli |
| Edomea Desk | Rust | [`desk/`](desk/) | App nativa Windows/macOS/Linux, modalità Server e Client |
| Moduli e plugin | PHP | [`plugins/`](plugins/) | Studio, cassa, logistica, integrazione Web/WooCommerce |

## Documentazione

- [Manuale tecnico-operativo](docs/manuale-tecnico-operativo.md): architettura, installazione, sicurezza, funzioni, sincronizzazione, disaster recovery e piano di sviluppo.

## Partecipare

Chiunque può partecipare: segnalazioni, proposte, codice e documentazione sono benvenuti. Ogni modifica entra nel progetto solo dopo l'**approvazione esplicita del titolare**. Leggi [CONTRIBUTING.md](CONTRIBUTING.md) prima di aprire una pull request.

## Marchio

Il codice è open source. Il nome e il marchio **Edomea** restano del titolare del progetto: le installazioni white label usano il proprio marchio, e l'uso del marchio Edomea richiede un'autorizzazione separata.

## Licenza

Il codice è distribuito con licenza **GNU Affero General Public License v3.0** ([LICENSE](LICENSE)). Chiunque può usarlo, studiarlo, modificarlo e ridistribuirlo; chi lo modifica e lo offre come servizio, anche online, deve rendere disponibile il codice sorgente delle proprie modifiche alle stesse condizioni.

La licenza riguarda il codice, non il marchio Edomea.
