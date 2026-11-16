# Sicurezza

## Segnalare una vulnerabilità

**Non aprire una issue pubblica.** Usa la segnalazione privata di GitHub:
scheda **Security** del repository → **Report a vulnerability**.

Indica la versione, i passi per riprodurre il problema e l'impatto che ti aspetti. Riceverai una prima risposta appena possibile; la correzione e la pubblicazione dell'avviso vengono concordate con chi ha segnalato.

## Versioni supportate

EMP è in fase di laboratorio (versioni `alpha`). Le correzioni di sicurezza vengono rilasciate solo sull'ultima versione pubblicata.

## Come sono prodotte le release

- Ogni release passa:
  - test del Core su PHP 8.3 e 8.5;
  - shellcheck degli script del Server;
  - **installazione reale di EMP Server su Ubuntu 24.04 e 26.04**: HTTPS verificato, permessi, aggiornamento, disinstallazione.
- Il pacchetto `emp-server-<versione>.tar.gz` è **riproducibile** e contiene `SHA256SUMS` di ogni file; `installa.sh` lo controlla prima di qualsiasi modifica.
- Nginx, PHP e MariaDB non sono inclusi nel pacchetto: arrivano dai repository ufficiali di Ubuntu, con i loro aggiornamenti di sicurezza.
- Gli allegati delle release hanno un'**attestazione di provenienza** GitHub: `gh attestation verify <file> --repo edomea/edomea-marketplace`.
- Le release nascono come bozza e sono pubblicate solo dal titolare, dopo approvazione.
- Nessuna credenziale, chiave, certificato privato o dato reale di clienti e negozi è presente nel repository.

Nessuna scansione garantisce l'assenza assoluta di malware.
