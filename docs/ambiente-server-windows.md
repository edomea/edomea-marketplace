# EMP — Ambiente Server su Windows (Macro 2)

Versione 1.0 · 16/11/2026

EMP Desk installa sul computer Server del negozio tutto ciò che serve al Core, senza passaggi manuali: **MariaDB**, **PHP** e il web server **Caddy**, gestiti dal servizio di Windows **EMP Server**.

## Componenti

| Componente | Versione | Fonte e verifica |
|---|---|---|
| MariaDB | 11.4.13 (serie LTS, supportata fino a maggio 2029) | archive.mariadb.org; SHA-256 fissato in `desk/componenti.json` |
| Caddy | 2.11.4 | release ufficiale GitHub; SHA-512 fissato in `desk/componenti.json` |
| PHP | 8.3 NTS x64 (ultima della serie) | windows.php.net; SHA-256 dall'indice ufficiale |
| EMP Core | dal pacchetto incluso | SHA-256 dell'indice dei pacchetti |

I componenti sono **inclusi nell'installer**: l'installazione funziona anche senza Internet. La CI genera un manifest con l'impronta SHA-256 di ogni file e lo incorpora nel programma `emp-servizi`.

**Perché Caddy e non Nginx.** Gli sviluppatori di Nginx dichiarano la versione per Windows adatta solo a sviluppo e test (beta, solo `select()`, nessun servizio). Caddy è supportato ufficialmente su Windows, è un unico programma e ha HTTPS locale integrato, utile per la Macro 3.

## Dove si trova

| Cartella | Contenuto | Permessi |
|---|---|---|
| `C:\Program Files\EMP` | `bin\emp-servizi.exe`, `php\`, `mariadb\`, `caddy\`, `pacchetti\` | scrittura solo amministratori |
| `C:\ProgramData\EMP` | `mariadb\` (dati), `config\`, `dati\config.php`, `segreti\`, `modules\`, `catalogo\`, `caddy\`, `tmp\` | sistema e amministratori; servizio (LocalService) in modifica; **utenti nessun accesso** |
| `C:\ProgramData\EMP\log` | registri di installazione e dei processi | come sopra + utenti in sola lettura |

Il servizio esegue solo programmi da `Program Files`, mai da cartelle scrivibili dall'utente. I file vengono verificati con il manifest prima e dopo la copia.

## Servizio EMP Server

- Nome `EMPServer`, avvio **automatico**, account **LocalService** (non amministratore).
- In caso di errore Windows lo riavvia (3 tentativi, 10 secondi di attesa).
- Avvia nell'ordine: MariaDB → 4 processi PHP FastCGI (porte 9000–9003) → Caddy (porta 80), e riavvia quelli che si fermano, con attesa crescente.
- All'arresto chiude Caddy e PHP e poi MariaDB in modo ordinato.

**Tutto ascolta solo su `127.0.0.1`.** Il Core è raggiungibile da `http://127.0.0.1/amministrazione/` sul computer Server. L'accesso dalle postazioni Client arriverà con HTTPS nella Macro 3.

## Installazione da EMP Desk

Modalità Server → sezione **2. Ambiente Server** → **Installa ambiente**. Windows chiede i permessi di amministratore (UAC) **solo** per questa operazione, per l'aggiornamento e per la disinstallazione.

Passaggi automatici:
1. verifica dei componenti forniti da Desk;
2. controllo delle porte 80, 3306 e 9000–9003 (prima installazione): se sono occupate da altri programmi, l'installazione si ferma senza toccarli;
3. copia in `Program Files\EMP` e nuova verifica;
4. cartelle dati con proprietario Administrators e permessi ristretti; rifiuto di cartelle preparate come collegamenti;
5. inizializzazione di MariaDB con password di `root` casuale, conservata in `segreti\`;
6. configurazioni: `my.ini`, `php.ini` (estensioni minime, `open_basedir`, niente URL remoti, errori nel registro), `Caddyfile` (intestazioni di sicurezza, nessuna amministrazione remota);
7. Core estratto in `modules\emp-core`;
8. registrazione e avvio del servizio;
9. database `emp` e utente `emp` (solo da 127.0.0.1) con password casuale, scritta solo in `dati\config.php`;
10. migrazioni del Core e controllo finale di `/api/v1/salute`.

Al termine **Apri amministrazione** porta al primo setup dell'Amministratore Tecnico.

**Aggiornamento**: lo stesso pulsante. Ferma il servizio, sostituisce i programmi, aggiorna il Core se il pacchetto è più recente, esegue le nuove migrazioni e riavvia. Database, password e plugin restano.

**Disinstallazione**: rimuove servizio e programmi. I dati in `C:\ProgramData\EMP` **restano**: una nuova installazione li ritrova.

## Verifiche automatiche (CI)

A ogni modifica, su un Windows pulito della CI:
- installazione reale come servizio;
- account e tipo di avvio del servizio;
- MariaDB e Caddy in ascolto **solo** su 127.0.0.1;
- primo setup del Tecnico e accesso su MariaDB;
- riavvio del servizio senza perdita di dati;
- aggiornamento;
- `segreti\` non leggibile dagli utenti;
- disinstallazione con dati conservati.

## Limiti noti

- Installer e `emp-servizi` non sono ancora firmati: finché manca la firma, Windows mostra un editore sconosciuto nella richiesta UAC. Si consiglia di installare solo pacchetti scaricati dalla release ufficiale e verificati con `SHA256SUMS.txt`.
- Backup automatici, HTTPS in LAN e accesso dalle casse: Macro 3 e successive.
- La modalità laboratorio di Desk (SQLite, porta 8180) resta disponibile per prove e dimostrazioni ed è separata dall'ambiente Server.
