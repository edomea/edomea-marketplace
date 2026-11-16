# Firma del codice

## Stato attuale

Gli installer di EMP Desk **non sono ancora firmati digitalmente** (Authenticode). Il nome dei file lo indica con `UNSIGNED`. Windows SmartScreen e i browser possono quindi mostrare avvisi.

Fino all'introduzione della firma, l'integrità si verifica con:
- `SHA256SUMS.txt` allegato a ogni release;
- l'attestazione di provenienza GitHub (`gh attestation verify <file> --repo edomea/edomea-marketplace`).

## Regole che varranno con la firma

- Si firmano solo binari prodotti dalla CI di questo repository a partire da un tag approvato dal titolare; mai build locali.
- Le chiavi di firma non stanno nel repository né nei log; l'accesso richiede autenticazione a più fattori.
- Ogni firma include una marca temporale.
- Ogni release firmata viene pubblicata solo dopo l'approvazione del titolare.

Il servizio di firma non è ancora stato scelto. Questo documento verrà aggiornato quando lo sarà; fino ad allora non va dichiarato alcun servizio di firma.
