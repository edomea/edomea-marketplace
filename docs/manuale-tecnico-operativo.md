# Edomea Marketplace 0.4.1 — Manuale tecnico-operativo

**Software commerciale white label** · Baseline di progettazione · 15 novembre 2026

> *Vendi e compra dove vuoi e come vuoi.*

| Revisione | Data | Modifiche |
|---|---|---|
| 0.4 | 15/11/2026 | Baseline di progettazione (titolo di lavoro "VenVia") |
| 0.4.2 | 16/11/2026 | Accesso con sola password (funzionamento offline), Recovery Key del Tecnico; TOTP/OTP rimossi |
| 0.4.1 | 15/11/2026 | Il progetto assume il nome **Edomea Marketplace**; precisata la natura white label; regole di partecipazione open source e di approvazione delle modifiche; linguaggio di Edomea Desk: Rust |

---

## 01 / Finalità e stato

Edomea Marketplace è un ecosistema commerciale multicanale e multipiattaforma, non un progetto di infrastruttura. La versione 0.4 recupera i requisiti funzionali della 0.2 e sostituisce l'architettura di installazione e accesso ai dati. Le funzionalità già approvate non devono regredire salvo decisione esplicita.

Questo manuale consolida le decisioni progettuali disponibili; non certifica software già realizzato. Le procedure descrivono il comportamento richiesto agli installer e ai moduli futuri. Nessun pacchetto 0.4 è stato compilato o collaudato nell'ambito di questo documento.

### Nome e modello white label

- **Edomea Marketplace** è il nome del progetto, del repository ufficiale (`github.com/edomea/edomea-marketplace`) e della distribuzione di riferimento. Sostituisce il titolo di lavoro "VenVia" usato nelle bozze precedenti.
- Edomea Marketplace è un **software white label**: ogni azienda che lo adotta può presentarlo ai propri operatori e clienti con nome, logo, colori, domini e testi propri, senza modificare il Core.
- La personalizzazione del marchio è una **configurazione dell'installazione**, non un fork del codice: Core, Desk e moduli restano gli stessi per tutte le installazioni; cambiano solo i dati di identità (ragione sociale, marchio, grafica, domini, contatti).
- Il nome e il marchio **Edomea** restano del titolare del progetto. Il codice è open source; l'uso del marchio Edomea da parte di terzi non è concesso dalla licenza del codice e richiede autorizzazione separata.
- La prima installazione di riferimento è il negozio Edomea, che opera come cliente della piattaforma alle stesse condizioni di qualsiasi altra installazione white label.

### Componenti (nomi ufficiali)

| Nome nelle bozze 0.2–0.4 | Nome ufficiale |
|---|---|
| VenVia Core | Edomea Core |
| VenVia Desk (Server / Client) | Edomea Desk (Server / Client) |
| VenVia Web | Edomea Web |
| VenVia Studio | Edomea Studio |
| Control Center | Edomea Control Center |

In un'installazione white label ogni componente può essere mostrato all'utente finale con il marchio dell'operatore.

### Indice

02 Architettura e autonomia locale · 03 Installazione e prima configurazione · 04 Identità, sicurezza e accesso · 05 Funzioni gestionali e multicanalità · 06 API, sincronizzazione e giacenze · 07 Control Center e disaster recovery · 08 GitHub, partecipazione, distribuzione e migrazione · 09 Primo sviluppo: Desk Server e collaudo

### Primo obiettivo operativo

Realizzare Edomea Desk configurabile in modalità Server, con installazione grafica, ambiente PHP e MariaDB autonomo, Core avviabile e prima configurazione sicura. Windows, macOS e Linux sono le piattaforme obiettivo; iOS e iPadOS non rientrano nel perimetro Desk.

---

## 02 / Architettura e autonomia locale

### Un Core, due ambienti

**Edomea Core**: motore PHP orientato agli oggetti, API, modello dati, autorizzazioni, transazioni e gestione dei moduli. Il Core non dipende da WordPress.

**Edomea Desk Server**: installa web server, PHP, MariaDB e Core sulla macchina aziendale scelta. Non richiede VM, hardware dedicato, WordPress o WooCommerce. Può essere contemporaneamente postazione operativa e cassa. Un solo server operativo autorizzato per negozio.

**Edomea Desk Client**: applicazione nativa per Windows, macOS e Linux; individua il server in LAN o accetta nome/IP manuale. Accede alle API del Core, non direttamente al database. Le funzioni dipendono dai permessi dell'operatore, non dal ruolo tecnico della macchina.

**Linguaggi**: Edomea Desk (Server e Client) è scritto in **Rust**; Core e moduli/plugin sono in **PHP**.

**Edomea Web**: ambiente opzionale su hosting WordPress compatibile o VPS; integra Core e WooCommerce per commercio online, coordinamento della rete e accesso autorizzato anche da Desk. WordPress gestisce i propri plugin, il Core quelli Edomea. Il futuro marketplace e le app clienti utilizzano API autorizzate.

### Flusso dati

Desk Client ↔ Desk Server (LAN) ↔ Edomea Web (HTTPS, quando disponibile) ↔ canali commerciali. Nessun collegamento diretto obbligatorio fra negozi. Un negozio può essere autonomo e associarsi successivamente alla rete.

### Vincoli infrastrutturali

Nessun dominio pubblico, router proprietario, VPN, IP pubblico statico o modifica manuale dei file hosts in negozio. IP LAN stabile consigliato; ricerca automatica con fallback manuale. DNS locale integrato, se mantenuto, opzionale e disattivato di default. Il server resta un punto di disponibilità locale: le casse dispongono anche di una modalità di emergenza.

---

## 03 / Installazione e prima configurazione

### Pacchetti ufficiali

Desk Windows: installer EXE. Desk macOS: pacchetto grafico firmato/notarizzato secondo i requisiti applicabili. Desk Linux: pacchetto grafico per distribuzioni supportate; Ubuntu LTS primo riferimento da validare. Desk deve offrire modalità Server e Client su tutte e tre le piattaforme. Edomea Web: ZIP per WordPress esistente. Formati macOS/Linux e matrice versioni ancora da definire.

I pacchetti ufficiali sono distribuiti con marchio Edomea. Le installazioni white label applicano il marchio dell'operatore durante la prima configurazione o da Control Center, senza pacchetti ricompilati.

### Procedura Server richiesta

1. Avviare il pacchetto ufficiale e scegliere Server, quindi Nuova installazione o Ripristina da backup.
2. Verificare compatibilità, spazio, permessi, porte, conflitti, servizi esistenti e percorso dati; non sovrascrivere installazioni senza autorizzazione.
3. Installare componenti isolati, creare database con credenziali uniche e limitate, registrare servizi ad avvio automatico.
4. Configurare identità azienda/negozio (incluso il marchio white label), nome server, indirizzo LAN, percorsi dati e backup. Creare l'Amministratore Tecnico al primo avvio, senza utenti predefiniti.
5. Generare la Recovery Key del Tecnico, mostrata una sola volta e scaricabile in TXT, con conferma di custodia; completare il setup (vedi [accesso e sicurezza](accesso-sicurezza.md)).
6. Avviare Control Center, controllare salute dei servizi e predisporre l'associazione dei client. Collegamento Web facoltativo.

### Procedura Client richiesta

Selezionare Client, rilevare il server oppure inserirne nome/IP; verificare l'identità e autorizzare l'associazione. Accedere con nome utente e password. Configurare eventuali periferiche di cassa. Consentire riparazione, aggiornamento e promozione a Server tramite procedura guidata. Nessun terminale per le operazioni ordinarie.

---

## 04 / Identità, sicurezza e accesso

### Ruoli e responsabilità

Amministratore Tecnico unico, separato dall'Amministratore gestionale: setup, manutenzione, migrazioni, aggiornamenti, ripristini e promozione server. Ruoli commerciali: Cassiere, Store Manager, Area Manager, Responsabile Reparto, Commerciale, Supervisor/Direttore Commerciale, Buyer, Responsabile Logistica, Magazziniere e Contabilità. Applicare permessi granulari, per azienda, negozio e funzione; mantenere i passaggi di competenza degli affiliati approvati nella 0.2.

### Sicurezza locale e web

Accesso con nome utente e password per tutti, **senza servizi esterni** per garantire il funzionamento offline (decisione del 16/11/2026: TOTP e OTP via email rimossi); password robuste conservate solo come hash, blocco temporaneo dopo tentativi falliti, sessioni revocabili, Recovery Key del Tecnico, log di audit. Dettagli: [accesso e sicurezza](accesso-sicurezza.md). TLS anche in LAN: proposta di identità/certificati privati con associazione verificata nell'app Desk, senza certificato pubblico obbligatorio. Nessuna accettazione indiscriminata di certificati. MariaDB non esposto ai client. Per Web pubblico, HTTPS con certificato attendibile e rinnovo automatico; ACME DNS-01 se applicabile.

### Esclusività del server

Ogni negozio ha identità stabile; ogni server ha identità propria e autorizzazione operativa revocabile. Proposta: generazione progressiva firmata dell'autorità server, conservata anche dai client, con rifiuto di generazioni precedenti. Il server centrale riconosce solo l'istanza vigente quando connesso. Il certificato TLS da solo non conferisce autorità.

La promozione offline richiede isolamento verificato del vecchio server e autorizzazione tecnica. Una macchina isolata non può ricevere revoche: non promettere esclusività assoluta durante una partizione di rete. Il server precedente va posto in quarantena e può rientrare solo come client o mediante nuova procedura autorizzata.

---

## 05 / Funzioni gestionali e multicanalità

### Perimetro da preservare dalla 0.2

**Edomea Studio**: anagrafica unica, codici/EAN, descrizioni, immagini, video, categorie, varianti, prezzi. Creazione da Desk o Web; pubblicazione online esplicita e autorizzata. Catalogo comune, dati commerciali separati secondo proprietà e permessi.

**Vendite e cassa**: POS, sconti entro soglia, sottocosto con approvazione, registratore telematico, documenti e recupero delle vendite. Store Connector non esiste più autonomamente: tutte le sue funzioni confluiscono in Desk, inclusa la coda locale durevole e la copia minima del catalogo per emergenze. Fiscalità e dispositivi da collaudare prima dell'uso reale.

**Logistica e magazzino**: carichi, scarichi, inventario, rettifiche da Area Manager in su, costo medio ponderato, trasporto opzionale nel costo, trasferimenti secondo proprietà. Gestione monomagazzino o segmentata; contabilità e statistiche, assistenza tecnica/riparazioni, nuovo/usato, marketing, social e signage.

**Affiliati**: autonomia locale, separazione di giacenze, costi, clienti e documenti; regole autorizzative fino a Store Manager e trasferimenti di competenza Area Manager/Supervisor secondo assetto approvato. Conservare storico e revocare accessi non più spettanti.

### Canali

WooCommerce è il canale web iniziale, non il gestionale master. Il marketplace proprietario resta obiettivo. Il cliente potrà consultare disponibilità per negozio (modello IKEA), acquistare online e usare future app. Nessun uso di plugin terzi non autorizzati; eventuali eccezioni approvate per pagamenti, corrieri e altri componenti espliciti.

---

## 06 / API, sincronizzazione e giacenze

### API-first

Interfacce documentate e versionate per catalogo, giacenze, ordini, clienti, documenti e moduli. Autenticazione per utente/applicazione, scope minimi, isolamento aziende, audit e limiti di accesso. Nessuna app esterna interroga direttamente MariaDB. Il modello dati e le regole di dominio sono condivisi tra Desk e Web.

### Operatività e velocità

Casse, Studio, contabilità e logistica operano sul server LAN anche senza Internet. La sincronizzazione è asincrona e non deve bloccare le transazioni locali. In caso di guasto del server, la cassa conserva le operazioni essenziali in una coda durevole protetta, con recupero e deduplicazione. Le altre funzioni possono restare indisponibili fino al ripristino.

### Sincronizzazione proposta da implementare

Identificativi globali, transazioni atomiche, eventi persistenti, idempotenza, retry, checkpoint e riconciliazione. Il server del negozio comunica con Web tramite connessioni HTTPS in uscita. Definire la proprietà di ogni dato e i conflitti per campo; nessuna sovrascrittura indiscriminata del database.

### Disponibilità vendibile

Già approvata la protezione del pezzo esposto e la scelta tra negozio selezionato e disponibilità aggregata. Mostrare giacenze per negozio, distinguere fisico/vendibile/riservato e indicare l'ultimo aggiornamento. Durante disconnessioni, non promettere stock in tempo reale: proposta di quote online o sospensione delle vendite sui dati scaduti, da approvare. Le prenotazioni e la riconciliazione devono evitare doppie vendite per quanto consentito dalla connettività.

---

## 07 / Control Center e disaster recovery

### Funzioni tecniche

Edomea Control Center gestisce setup, configurazioni, identità white label, salute servizi, backup, aggiornamenti, staging, migrazioni, rollback e ripristino. Backend gestionale installa moduli ufficiali tramite flusso autorizzato; rimozione di emergenza controllata, con accesso ai file per il Tecnico. Non rendere SFTP un requisito delle installazioni Desk.

### Backup e promozione

Backup consistenti, cifrati, verificati e ripristinabili su installazione pulita: database, media, documenti, configurazioni, identità e versioni. Destinazione esterna programmabile; Google Drive cifrato previsto in precedenza, USB opzionale per le casse. Recovery key offline; definire conservazione, frequenza, RPO/RTO e verifica periodica.

Ripristina come Server: isolare vecchio server, verificare backup e compatibilità, ripristinare su client compatibile, attribuire nuova autorità, riallacciare gli altri client e riconciliare operazioni successive al backup. Non è failover istantaneo; possibili perdite dopo l'ultimo backup se non recuperabili dalle code.

### Staging e aggiornamento

«Clona in staging»: preflight, backup verificato, clone isolato, credenziali e integrazioni separate, preservazione dello staging precedente fino a swap validato. Evitare emissioni fiscali, pagamenti e pubblicazioni reali.

«Migra in produzione»: promuovere solo modifiche collaudate e approvate, con checkpoint, migrazioni dati controllate e preservazione dei dati reali. Mai sostituire automaticamente il DB live con quello di staging. Entrambe le operazioni reversibili: rollback automatico ove sicuro e manuale autorizzato, con verifica di consistenza.

---

## 08 / GitHub, partecipazione, distribuzione e migrazione

### Sviluppo open source

GitHub è la piattaforma ufficiale per codice, issue, test, release e pacchetti. Repository ufficiale: **`github.com/edomea/edomea-marketplace`**, pubblico.

### Partecipazione e approvazione delle modifiche

- **Chiunque può partecipare**: segnalare problemi e proposte tramite issue, discutere, e proporre codice o documentazione tramite fork e pull request dal proprio account GitHub.
- **Nessuna modifica entra nel repository senza l'autorizzazione esplicita del titolare** (account GitHub `edomea`). Questo vale per codice, documentazione, configurazioni, workflow e per questo manuale.
- La regola è applicata tecnicamente, non solo dichiarata:
  - il branch `main` è protetto: niente push diretti, niente force-push, niente cancellazione;
  - ogni modifica arriva tramite pull request;
  - il file `CODEOWNERS` assegna l'intero repository al titolare, e la pull request non può essere unita senza la sua **approvazione esplicita** (review "Approve");
  - una nuova modifica dopo l'approvazione richiede una nuova approvazione.
- Le release ufficiali sono pubblicate solo dal titolare.
- La GitHub App resta sotto controllo esclusivo del titolare; nessuna credenziale privata distribuita. I collaboratori operano con account propri.
- Licenza open source e condizioni d'uso del marchio white label da formalizzare (vedi 09, decisioni aperte).

### Pipeline richiesta

GitHub Actions: build su runner Windows, macOS e Linux; test automatici, pacchetti nativi, manifest delle dipendenze, checksum, firma ove prevista, pubblicazione in Releases dopo approvazione del titolare. ZIP Web e archivio di ogni aggiornamento/fix con istruzioni di backup, installazione, verifica e rollback; mantenere trasferibilità SCP per ambienti che la utilizzano. Nessuna compilazione manuale per l'utente.

Linguaggio di Desk: Rust (deciso). Framework GUI, packaging, firma, architetture CPU e compatibilità periferiche da determinare tramite prototipo. Non scegliere automaticamente C# o Inno Setup come vincoli definitivi.

### Migrazione dalla 0.2

Preservare l'ambiente DEV esistente e tutti i suoi dati fino al completamento del Core definitivo. Inventariare componenti, versioni, database, documenti, media, utenti e configurazioni. Esportare in formato portabile; importare in staging 0.4, verificare integrità e funzioni, approvare e migrare con rollback. Creare contestualmente installazione white label pulita con stesso Core, senza dati o credenziali del negozio reale.

Portabilità bidirezionale locale/Web da mantenere nei limiti dei servizi e dei ruoli compatibili. Certificati pubblici e segreti specifici dell'ambiente vanno gestiti o riemessi in sicurezza; non clonare indiscriminatamente identità o credenziali.

---

## 09 / Primo sviluppo: Desk Server

### P0 — prototipo installabile

1. Definire contratto Core/Desk e struttura repository.
2. Preparare pipeline GitHub per le tre piattaforme.
3. Realizzare launcher e installer Server (Rust) con prerequisiti, servizi PHP/MariaDB/web server e disinstallazione non distruttiva.
4. Implementare setup grafico, identità tecnica e white label, accesso con password e Recovery Key del Tecnico, Control Center minimo.
5. Aggiungere API di salute e associazione Client.
6. Generare release di laboratorio, non produzione.

### Criteri di accettazione

- Installazione pulita e riavvio riusciti su Windows, macOS e Linux supportati, senza VM o WordPress.
- Control Center raggiungibile, database privato, amministratore unico e recovery verificato.
- Marchio white label configurato senza ricompilare i pacchetti.
- Client associato tramite nome/IP e rilevamento automatico; rifiuto di server non autorizzato.
- Operazioni LAN senza Internet; benchmark di latenza e carico con più client (soglie da definire prima del collaudo).
- Backup esterno e ripristino su seconda macchina; rifiuto del vecchio server e recupero coda cassa.
- Aggiornamento in staging e rollback verificati; pacchetti ufficiali riproducibili e autenticabili.

### Decisioni e verifiche ancora aperte

Framework GUI di Desk, web server e packaging; versioni OS/CPU; discovery e fiducia crittografica; Master Recovery Key per i backup cifrati; protocollo di sincronizzazione e inventario; compatibilità fiscale; licenza open source del codice e condizioni d'uso del marchio Edomea; obiettivi prestazionali e di recupero. Nessun test è dichiarato superato. Il manuale si basa sulle decisioni recuperate dalle conversazioni 0.2–0.4, non su un confronto integrale con il file originale della 0.2.

---

*15 novembre 2026 · Baseline progettuale 0.4.1 · Edomea Marketplace — software white label*
