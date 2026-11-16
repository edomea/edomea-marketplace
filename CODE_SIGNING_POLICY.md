# Firma del codice

## Stato attuale

Il pacchetto di EMP Server (`emp-server-<versione>.tar.gz`) **non è ancora firmato** con una chiave del progetto.

Fino all'introduzione della firma, l'integrità si verifica con:
- `SHA256SUMS.txt` allegato a ogni release e il file `.sha256` del pacchetto;
- `SHA256SUMS` dentro il pacchetto, controllato da `installa.sh` prima di qualsiasi modifica;
- l'attestazione di provenienza GitHub (`gh attestation verify <file> --repo edomea/edomea-marketplace`).

Nginx, PHP e MariaDB sono installati dai repository di Ubuntu, firmati da Canonical.

## Regole che varranno con la firma

- Si firmano solo pacchetti prodotti dalla CI di questo repository a partire da un tag approvato dal titolare; mai build locali.
- Le chiavi di firma non stanno nel repository né nei log; l'accesso richiede autenticazione a più fattori.
- Ogni release firmata viene pubblicata solo dopo l'approvazione del titolare.

Il metodo di firma (ad esempio GPG o Sigstore) non è ancora stato scelto. Questo documento verrà aggiornato quando lo sarà.
