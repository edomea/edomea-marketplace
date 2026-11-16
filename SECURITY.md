# Sicurezza

## Segnalare una vulnerabilità

**Non aprire una issue pubblica.** Usa la segnalazione privata di GitHub:
scheda **Security** del repository → **Report a vulnerability**.

Indica la versione, i passi per riprodurre il problema e l'impatto che ti aspetti. Riceverai una prima risposta appena possibile; la correzione e la pubblicazione dell'avviso vengono concordate con chi ha segnalato.

## Versioni supportate

EMP è in fase di laboratorio (versioni `alpha`). Le correzioni di sicurezza vengono rilasciate solo sull'ultima versione pubblicata.

## Come sono prodotte le release

- Ogni build Windows passa: audit delle dipendenze Rust (avvisi bloccanti, salvo eccezioni motivate nel workflow), Clippy con warning bloccanti, test, scansione Microsoft Defender, impronte SHA-256.
- `Cargo.lock` è versionato e la build usa esattamente quelle dipendenze (`--locked`).
- Gli allegati delle release hanno un'**attestazione di provenienza** GitHub: `gh attestation verify <file> --repo edomea/edomea-marketplace`.
- Le release nascono come bozza e sono pubblicate solo dal titolare, dopo approvazione.
- Nessuna credenziale, chiave, certificato privato o dato reale di clienti e negozi è presente nel repository.

Nessuna scansione garantisce l'assenza assoluta di malware.
