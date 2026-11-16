# Privacy

Informativa tecnica sul software EMP (Edomea Marketplace), versione 0.6.0-alpha.1.

## EMP Server
- **Non invia dati** a server esterni, al titolare del progetto o a terzi: nessuna telemetria, nessuna statistica d'uso.
- Durante l'installazione e gli aggiornamenti automatici di sicurezza contatta solo i repository ufficiali di Ubuntu, per scaricare i pacchetti del sistema (Nginx, PHP, MariaDB e loro aggiornamenti).
- Conserva sul Server: configurazione, certificati, moduli, registri del web server e backup del database (in `/var/lib/emp/backup`, ultimi 14).

## EMP Core
- Conserva i dati nel database dell'installazione, sotto il controllo di chi la gestisce.
- Non contatta servizi esterni.
- I registri di sicurezza non contengono password, chiavi o token.

## Titolare del trattamento
Ogni installazione è gestita da chi la utilizza (ad esempio il negozio o l'azienda che adotta EMP in white label), che è responsabile dei dati personali trattati, dei backup e dell'informativa verso clienti e dipendenti.

Le funzioni future che comunicano in rete (sincronizzazione con la Centrale, canale web) saranno descritte qui prima del rilascio.
