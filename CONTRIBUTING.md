# Come partecipare a Edomea Marketplace

Grazie per l'interesse. Il progetto è aperto a tutti, con una regola fondamentale:

> **Nessuna modifica entra nel repository senza l'autorizzazione esplicita del titolare (@edomea).**

## Cosa puoi fare

- **Segnalare un problema o proporre un'idea**: apri una [issue](../../issues).
- **Proporre una modifica** al codice o alla documentazione, manuale compreso:
  1. fai un fork del repository sul tuo account;
  2. crea un branch con un nome descrittivo;
  3. apri una pull request verso `main` e compila il modello proposto.

## Come vengono approvate le modifiche

- Il branch `main` è protetto: nessuno può scriverci direttamente, nemmeno i collaboratori.
- Ogni pull request richiede la **review di approvazione del titolare** (file `.github/CODEOWNERS`).
- Se aggiungi commit dopo l'approvazione, serve una nuova approvazione.
- Il titolare può chiedere modifiche o chiudere una proposta senza unirla.
- Le release ufficiali sono pubblicate solo dal titolare.

## Regole

- Non includere mai credenziali, chiavi, certificati privati o dati reali di clienti e negozi.
- Rispetta l'architettura descritta nel [manuale tecnico-operativo](docs/manuale-tecnico-operativo.md): Desk in Rust, Core e plugin in PHP, accesso ai dati solo tramite le API del Core.
- Il marchio Edomea non può essere usato in fork o installazioni white label senza autorizzazione.
- Inviando una pull request accetti che il tuo contributo sia distribuito con la licenza del progetto, [AGPL-3.0](LICENSE).
