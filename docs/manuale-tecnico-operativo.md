# Edomea Marketplace 0.5.0 — Manuale tecnico-operativo

**Software commerciale white label** · Baseline di progettazione · 17 novembre 2026

> *Vendi e compra dove vuoi e come vuoi.*

| Revisione | Data | Modifiche |
|---|---|---|
| 0.4 | 15/11/2026 | Baseline di progettazione (titolo di lavoro "VenVia") |
| 0.4.1 | 15/11/2026 | Il progetto assume il nome **Edomea Marketplace**; precisata la natura white label; regole di partecipazione open source e di approvazione delle modifiche; linguaggio di Edomea Desk: Rust; licenza AGPL-3.0 |
| 0.4.2 | 16/11/2026 | Accesso con sola password (funzionamento offline), Recovery Key del Tecnico; TOTP/OTP rimossi |
| 0.4.3 | 16/11/2026 | Web server: **Caddy** al posto di Nginx (Nginx per Windows è dichiarato dai suoi sviluppatori solo per sviluppo e test); database MariaDB 11.4 LTS; GUI di Desk: Tauri 2 |
| 0.5.0 | 16/11/2026 | **Edomea Desk eliminato** (decisione del titolare: troppo complesso mantenere Windows e macOS). Il Server del negozio è **EMP Server su Ubuntu Server LTS** (26.04 consigliata, 24.04 supportata), anche in macchina virtuale VMware Workstation Pro; Nginx, PHP-FPM e MariaDB dai repository di Ubuntu; **HTTPS in LAN** con autorità di certificazione locale; le postazioni usano il browser. Primo setup con codice monouso; recupero del Tecnico anche dalla console del Server |
| 0.5.1 | 17/11/2026 | Nuovo cliente white label da **VM master**: clone completo in VMware e total reset con `emp-server azzera --nome NOME` (dati, identità della macchina, autorità di certificazione, collegamento GitHub); collegamento del clone con `emp-server collega-github` (EMP Server 0.6.0-alpha.4) |

---

## 01 / Finalità e stato

Edomea Marketplace è un ecosistema commerciale multicanale e multipiattaforma, non un progetto di infrastruttura. La versione 0.4 recupera i requisiti funzionali della 0.2 e sostituisce l'architettura di installazione e accesso ai dati. Le funzionalità già approvate non devono regredire salvo decisione esplicita.

Questo manuale consolida le decisioni progettuali disponibili; non certifica software già realizzato. Le procedure descrivono il comportamento richiesto agli installer e ai moduli futuri. Nessun pacchetto 0.4 è stato compilato o collaudato nell'ambito di questo documento.

### Nome e modello white label

- **Edomea Marketplace** è il nome del progetto, del repository ufficiale (`github.com/edomea/edomea-marketplace`) e della distribuzione di riferimento. Sostituisce il titolo di lavoro "VenVia" usato nelle bozze precedenti.
- Edomea Marketplace è un **software white label**: ogni azienda che lo adotta può presentarlo ai propri operatori e clienti con nome, logo, colori, domini e testi propri, senza modificare il Core.
- La personalizzazione del marchio è una **configurazione dell'installazione**, non un fork del codice: Core, EMP Server e moduli restano gli stessi per tutte le installazioni; cambiano solo i dati di identità (ragione sociale, marchio, grafica, domini, contatti).
- Il nome e il marchio **Edomea** restano del titolare del progetto. Il codice è open source; l'uso del marchio Edomea da parte di terzi non è concesso dalla licenza del codice e richiede autorizzazione separata.
- La prima installazione di riferimento è il negozio Edomea, che opera come cliente della piattaforma alle stesse condizioni di qualsiasi altra installazione white label.

### Componenti (nomi ufficiali)

| Nome nelle bozze 0.2–0.4 | Nome ufficiale |
|---|---|
| VenVia Core | Edomea Core |
| VenVia Desk (Server / Client) | EMP Server su Ubuntu (Server); browser (postazioni) — Edomea Desk eliminato nella 0.5.0 |
| VenVia Web | Edomea Web |
| VenVia Studio | Edomea Studio |
| Control Center | Edomea Control Center |

In un'installazione white label ogni componente può essere mostrato all'utente finale con il marchio dell'operatore.

### Indice

02 Architettura e autonomia locale · 03 Installazione e prima configurazione · 04 Identità, sicurezza e accesso · 05 Funzioni gestionali e multicanalità · 06 API, sincronizzazione e giacenze · 07 Control Center e disaster recovery · 08 GitHub, partecipazione, distribuzione e migrazione · 09 Primo sviluppo: EMP Server e collaudo

### Primo obiettivo operativo

Realizzare EMP Server su Ubuntu Server LTS, installabile con un solo comando (anche in una macchina virtuale su un PC del negozio), con Nginx, PHP-FPM e MariaDB, Core avviabile, HTTPS in LAN e prima configurazione sicura. Le postazioni del negozio (Windows, macOS, Linux, tablet) usano il browser.

---

## 02 / Architettura e autonomia locale

### Un Core, due ambienti

**Edomea Core**: motore PHP orientato agli oggetti, API, modello dati, autorizzazioni, transazioni e gestione dei moduli. Il Core non dipende da WordPress.

**EMP Server**: installa Nginx, PHP-FPM, MariaDB e Core su **Ubuntu Server LTS** con `sudo ./installa.sh`, usando solo i repository ufficiali di Ubuntu.
- Può girare su un PC dedicato o in una **macchina virtuale** (VMware Workstation Pro, rete *Bridged*, avvio automatico) su un PC del negozio.
- I servizi di sistema partono all'avvio; MariaDB non ha porte di rete; HTTPS in LAN con autorità di certificazione locale.
- Backup notturni e aggiornamenti di sicurezza automatici.
- Un solo server operativo autorizzato per negozio.

Vedi [EMP Server su Ubuntu](ambiente-server-linux.md).

**Postazioni del negozio**: nessuna applicazione da installare. Si usa il **browser** (Windows, macOS, Linux, tablet) all'indirizzo `https://` del Server, dopo aver installato una volta l'autorità di certificazione locale. Le postazioni accedono alle API del Core, non direttamente al database. Le funzioni dipendono dai permessi dell'operatore.

**Linguaggi**: Core e moduli/plugin in **PHP**; EMP Server in **Bash** (script verificati con shellcheck), su componenti di Ubuntu. Edomea Desk in Rust è stato eliminato nella 0.5.0; il codice resta nella cronologia di Git.

**Edomea Web**: ambiente opzionale su hosting WordPress compatibile o VPS; integra Core e WooCommerce per commercio online, coordinamento della rete e accesso autorizzato anche dalle postazioni del negozio. WordPress gestisce i propri plugin, il Core quelli Edomea. Il futuro marketplace e le app clienti utilizzano API autorizzate.

### Flusso dati

Browser delle postazioni ↔ EMP Server (LAN, HTTPS) ↔ Edomea Web (HTTPS, quando disponibile) ↔ canali commerciali. Nessun collegamento diretto obbligatorio fra negozi. Un negozio può essere autonomo e associarsi successivamente alla rete.

### Vincoli infrastrutturali

Nessun dominio pubblico, router proprietario, VPN, IP pubblico statico o modifica manuale dei file hosts in negozio. IP LAN stabile consigliato; ricerca automatica con fallback manuale. DNS locale integrato, se mantenuto, opzionale e disattivato di default. Il server resta un punto di disponibilità locale: le casse dispongono anche di una modalità di emergenza.

---

## 03 / Installazione e prima configurazione

### Pacchetti ufficiali

**EMP Server**: pacchetto `emp-server-<versione>.tar.gz` per Ubuntu Server LTS (26.04 consigliata, 24.04 supportata), con impronte SHA-256 di ogni file, controllate prima di qualsiasi modifica. Edomea Web: ZIP per WordPress esistente.

I pacchetti ufficiali sono distribuiti con marchio Edomea. Le installazioni white label applicano il marchio dell'operatore durante la prima configurazione o da Control Center, senza pacchetti ricompilati.

### Procedura Server

1. Preparare Ubuntu Server LTS: PC dedicato o macchina virtuale VMware con rete *Bridged*; indirizzo IP fisso o riservato nel router.
2. Copiare il pacchetto ed eseguire `sudo ./installa.sh`. Lo script:
   - verifica sistema e impronte;
   - installa e configura i servizi, con database dedicato e senza password salvate (unix_socket);
   - crea certificato HTTPS, firewall e backup notturni;
   - installa il Core.

   Non sovrascrive i dati: rieseguito, aggiorna.
3. Installare su ogni postazione l'autorità di certificazione locale, verificandone l'impronta SHA-256.
4. Dal browser, in HTTPS, completare il primo setup dell'Amministratore Tecnico con il **codice monouso** mostrato dall'installazione. Nessun utente predefinito.
5. Scaricare la Recovery Key del Tecnico, mostrata una sola volta, e confermarne la custodia (vedi [accesso e sicurezza](accesso-sicurezza.md)).
6. Configurare identità azienda/negozio, incluso il marchio white label, e installare i plugin dalla pagina *Plugin*. Collegamento Web facoltativo.

Dettagli: [EMP Server su Ubuntu](ambiente-server-linux.md).

### Nuovo cliente white label (VM master)

Una macchina virtuale **master** resta aggiornata all'ultima release e in stato di fabbrica, senza Amministratore Tecnico né dati, e non si usa in produzione. Per ogni nuovo cliente:

1. clone completo del master in VMware Workstation Pro, con nuovo indirizzo MAC;
2. sul clone, `sudo emp-server azzera --nome NOME-CLIENTE`: cancella dati, plugin, backup e registri; assegna nome host, identificativo della macchina e chiavi SSH propri; crea nuova autorità di certificazione e nuovo certificato; toglie il collegamento a GitHub copiato dal master;
3. installazione della nuova autorità sulle postazioni e primo setup dell'Amministratore Tecnico con il codice monouso;
4. se il Server deve aggiornarsi da GitHub, `sudo emp-server collega-github` con un token di registrazione autorizzato dal titolare.

Ogni Server ha un nome diverso. L'azzeramento è irreversibile: prima si fa un'istantanea o una copia della VM.

### Postazioni

Aprire nel browser `https://IP-DEL-SERVER/admin/` (Amministratore Operativo e utenti operativi) oppure `https://IP-DEL-SERVER/techadmin/` (solo Amministratore Tecnico) e accedere con nome utente e password. Il vecchio indirizzo `/amministrazione/` porta a `/techadmin/`. Periferiche di cassa da definire con i plugin POS/Scontrino. Nessun terminale per le operazioni ordinarie delle postazioni; la manutenzione del Server si fa con `emp-server`.

---

## 04 / Identità, sicurezza e accesso

### Ruoli e responsabilità

Amministratore Tecnico unico, separato dall'Amministratore gestionale: setup, manutenzione, migrazioni, aggiornamenti, ripristini e promozione server. Ruoli commerciali: Cassiere, Store Manager, Area Manager, Responsabile Reparto, Commerciale, Supervisor/Direttore Commerciale, Buyer, Responsabile Logistica, Magazziniere e Contabilità. Applicare permessi granulari, per azienda, negozio e funzione; mantenere i passaggi di competenza degli affiliati approvati nella 0.2.

### Sicurezza locale e web

Accesso con nome utente e password per tutti, **senza servizi esterni** per garantire il funzionamento offline (decisione del 16/11/2026: TOTP e OTP via email rimossi); password robuste conservate solo come hash, blocco temporaneo dopo tentativi falliti, sessioni revocabili, Recovery Key del Tecnico, log di audit. Dettagli: [accesso e sicurezza](accesso-sicurezza.md). TLS anche in LAN: autorità di certificazione locale del Server, limitata con *name constraints* ai suoi nomi e alle reti private, installata sulle postazioni dopo la verifica dell'impronta; nessun certificato pubblico obbligatorio. Nessuna accettazione indiscriminata di certificati. MariaDB non esposto ai client. Per Web pubblico, HTTPS con certificato attendibile e rinnovo automatico; ACME DNS-01 se applicabile.

### Esclusività del server

Ogni negozio ha identità stabile; ogni server ha identità propria e autorizzazione operativa revocabile. Proposta: generazione progressiva firmata dell'autorità server, conservata anche dai client, con rifiuto di generazioni precedenti. Il server centrale riconosce solo l'istanza vigente quando connesso. Il certificato TLS da solo non conferisce autorità.

La promozione offline richiede isolamento verificato del vecchio server e autorizzazione tecnica. Una macchina isolata non può ricevere revoche: non promettere esclusività assoluta durante una partizione di rete. Il server precedente va posto in quarantena e può rientrare solo come client o mediante nuova procedura autorizzata.

---

## 05 / Funzioni gestionali e multicanalità

### Perimetro da preservare dalla 0.2

**Edomea Studio**: anagrafica unica, codici/EAN, descrizioni, immagini, video, categorie, varianti, prezzi. Creazione dalle postazioni o dal Web; pubblicazione online esplicita e autorizzata. Catalogo comune, dati commerciali separati secondo proprietà e permessi.

**Vendite e cassa**: POS, sconti entro soglia, sottocosto con approvazione, registratore telematico, documenti e recupero delle vendite. Store Connector non esiste più autonomamente: tutte le sue funzioni confluiscono nel plugin POS di EMP (postazioni nel browser), inclusa la coda locale durevole e la copia minima del catalogo per emergenze. Fiscalità e dispositivi da collaudare prima dell'uso reale.

**Logistica e magazzino**: carichi, scarichi, inventario, rettifiche da Area Manager in su, costo medio ponderato, trasporto opzionale nel costo, trasferimenti secondo proprietà. Gestione monomagazzino o segmentata; contabilità e statistiche, assistenza tecnica/riparazioni, nuovo/usato, marketing, social e signage.

**Affiliati**: autonomia locale, separazione di giacenze, costi, clienti e documenti; regole autorizzative fino a Store Manager e trasferimenti di competenza Area Manager/Supervisor secondo assetto approvato. Conservare storico e revocare accessi non più spettanti.

### Canali

WooCommerce è il canale web iniziale, non il gestionale master. Il marketplace proprietario resta obiettivo. Il cliente potrà consultare disponibilità per negozio (modello IKEA), acquistare online e usare future app. Nessun uso di plugin terzi non autorizzati; eventuali eccezioni approvate per pagamenti, corrieri e altri componenti espliciti.

---

## 06 / API, sincronizzazione e giacenze

### API-first

Interfacce documentate e versionate per catalogo, giacenze, ordini, clienti, documenti e moduli. Autenticazione per utente/applicazione, scope minimi, isolamento aziende, audit e limiti di accesso. Nessuna app esterna interroga direttamente MariaDB. Il modello dati e le regole di dominio sono condivisi tra EMP Server e Web.

### Operatività e velocità

Casse, Studio, contabilità e logistica operano sul server LAN anche senza Internet. La sincronizzazione è asincrona e non deve bloccare le transazioni locali. In caso di guasto del server, la cassa conserva le operazioni essenziali in una coda durevole protetta, con recupero e deduplicazione. Le altre funzioni possono restare indisponibili fino al ripristino.

### Sincronizzazione proposta da implementare

Identificativi globali, transazioni atomiche, eventi persistenti, idempotenza, retry, checkpoint e riconciliazione. Il server del negozio comunica con Web tramite connessioni HTTPS in uscita. Definire la proprietà di ogni dato e i conflitti per campo; nessuna sovrascrittura indiscriminata del database.

### Disponibilità vendibile

Già approvata la protezione del pezzo esposto e la scelta tra negozio selezionato e disponibilità aggregata. Mostrare giacenze per negozio, distinguere fisico/vendibile/riservato e indicare l'ultimo aggiornamento. Durante disconnessioni, non promettere stock in tempo reale: proposta di quote online o sospensione delle vendite sui dati scaduti, da approvare. Le prenotazioni e la riconciliazione devono evitare doppie vendite per quanto consentito dalla connettività.

---

## 07 / Control Center e disaster recovery

### Funzioni tecniche

Edomea Control Center gestisce setup, configurazioni, identità white label, salute servizi, backup, aggiornamenti, staging, migrazioni, rollback e ripristino. Backend gestionale installa moduli ufficiali tramite flusso autorizzato; rimozione di emergenza controllata, con accesso ai file per il Tecnico. Non rendere SFTP un requisito delle installazioni EMP Server (resta disponibile SSH per la manutenzione).

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
- Licenza del codice: **GNU AGPL-3.0**. Chi modifica il software e lo offre come servizio, anche online, deve pubblicarne il sorgente alle stesse condizioni; i contributi esterni sono distribuiti con la stessa licenza. Le condizioni d'uso del marchio Edomea per le installazioni white label restano da formalizzare (vedi 09).

### Pipeline richiesta

GitHub Actions: test del Core su PHP 8.3 e 8.5, **installazione reale di EMP Server su Ubuntu 24.04 e 26.04**, pacchetto riproducibile, manifest delle dipendenze, checksum, firma ove prevista, pubblicazione in Releases dopo approvazione del titolare. ZIP Web e archivio di ogni aggiornamento/fix con istruzioni di backup, installazione, verifica e rollback; mantenere trasferibilità SCP per ambienti che la utilizzano. Nessuna compilazione manuale per l'utente.

Edomea Desk (Rust) è stato eliminato nella 0.5.0: un solo ambiente server, Ubuntu Server LTS, al posto di Windows e macOS.

### Migrazione dalla 0.2

Preservare l'ambiente DEV esistente e tutti i suoi dati fino al completamento del Core definitivo. Inventariare componenti, versioni, database, documenti, media, utenti e configurazioni. Esportare in formato portabile; importare in staging 0.4, verificare integrità e funzioni, approvare e migrare con rollback. Creare contestualmente installazione white label pulita con stesso Core, senza dati o credenziali del negozio reale.

Portabilità bidirezionale locale/Web da mantenere nei limiti dei servizi e dei ruoli compatibili. Certificati pubblici e segreti specifici dell'ambiente vanno gestiti o riemessi in sicurezza; non clonare indiscriminatamente identità o credenziali.

---

## 09 / Primo sviluppo: EMP Server

### P0 — prototipo installabile

1. Struttura del repository e contratto Core/Server. *Fatto.*
2. Pipeline GitHub con installazione reale su Ubuntu. *Fatto.*
3. Installer EMP Server: Nginx, PHP-FPM, MariaDB, HTTPS in LAN, backup, disinstallazione non distruttiva. *Fatto nella 0.6.0-alpha.1.*
4. Primo setup, accesso con password e Recovery Key del Tecnico. *Fatto.* Identità white label e Control Center minimo: da fare.
5. API di salute (*fatta*); elenco delle postazioni autorizzate.
6. Release di laboratorio, non produzione.

### Criteri di accettazione

- Installazione pulita, riesecuzione e riavvio riusciti su Ubuntu 24.04 e 26.04 LTS, anche in macchina virtuale VMware Workstation Pro, senza WordPress.
- Core raggiungibile in HTTPS dalle postazioni con certificato verificato; database privato; amministratore unico e recovery verificati.
- Marchio white label configurato senza ricompilare i pacchetti.
- Operazioni LAN senza Internet; benchmark di latenza e carico con più postazioni (soglie da definire prima del collaudo).
- Backup esterno e ripristino su seconda macchina; rifiuto del vecchio server e recupero coda cassa.
- Aggiornamento in staging e rollback verificati; pacchetti ufficiali riproducibili e autenticabili.

### Decisioni e verifiche ancora aperte

Firma del pacchetto EMP Server; installazione dell'autorità locale su Android e iOS; Master Recovery Key per i backup cifrati; protocollo di sincronizzazione e inventario; compatibilità fiscale; condizioni d'uso del marchio Edomea (la licenza del codice, AGPL-3.0, è decisa); obiettivi prestazionali e di recupero. Nessun test è dichiarato superato. Il manuale si basa sulle decisioni recuperate dalle conversazioni 0.2–0.4, non su un confronto integrale con il file originale della 0.2.

---

*16 novembre 2026 · Baseline progettuale 0.5.0 · Edomea Marketplace — software white label*
