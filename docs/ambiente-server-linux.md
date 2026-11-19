# EMP Server su Ubuntu Server LTS

Versione 1.0 · 16/11/2026 · Sostituisce EMP Desk (decisione del titolare del 16/11/2026: un unico ambiente Linux al posto di Windows e macOS).

EMP Server è il computer del negozio su cui gira **EMP Core** con i suoi plugin. È un'installazione di **Ubuntu Server LTS** (26.04 consigliata, 24.04 supportata). Può essere un PC dedicato o una **macchina virtuale** su un PC Windows del negozio.

Le postazioni del negozio non installano nulla: usano EMP dal **browser**, in **HTTPS**.

```
PC del negozio (browser) ──HTTPS──▶ EMP Server (Ubuntu) ── Nginx ── PHP-FPM (utente emp) ── EMP Core + plugin
                                                       └── MariaDB (solo socket locale)
```

---

## 1. Macchina virtuale con VMware Workstation Pro

VMware Workstation Pro è **gratuito anche per uso commerciale** dall'11/11/2024. Si scarica dal portale di supporto Broadcom (support.broadcom.com), dopo aver creato un account gratuito.

### 1.1 Requisiti del PC che ospita la VM

| | Minimo | Consigliato |
|---|---|---|
| CPU | 2 core con virtualizzazione (Intel VT-x / AMD-V) attiva nel BIOS | 4 core |
| RAM | 8 GB | 16 GB |
| Disco libero | 50 GB | SSD |
| Rete | cablata o Wi-Fi nella rete del negozio | cablata |

**Il PC deve restare acceso**: se va in sospensione o si spegne, anche EMP si ferma. In *Impostazioni → Sistema → Alimentazione* impostare la sospensione su **Mai** quando è collegato alla corrente.

### 1.2 Creare la VM

1. Scaricare l'immagine ISO di **Ubuntu Server 26.04 LTS** da ubuntu.com/download/server.
2. In VMware Workstation Pro: **File → New Virtual Machine → Typical**, poi:
   - *Installer disc image file (iso)*: l'ISO scaricata;
   - nome: `EMP-Server`;
   - disco: **40 GB**, *Store virtual disk as a single file*;
   - **Customize Hardware**:
     - *Memory*: **4096 MB**;
     - *Processors*: **2**;
     - *Network Adapter*: **Bridged**. Spuntare *Replicate physical network connection state* se il PC usa il Wi-Fi.
3. Avviare la VM.

> **Perché Bridged.** Con *Bridged* la VM riceve un proprio indirizzo nella rete del negozio, come un PC in più, e gli altri computer la raggiungono. Con *NAT*, il valore predefinito, la vedrebbe solo il PC che la ospita.

### 1.3 Installare Ubuntu Server

Nell'installazione guidata di Ubuntu:

| Schermata | Scelta |
|---|---|
| Lingua e tastiera | Italiano (o English) · tastiera **Italian** |
| Tipo di installazione | **Ubuntu Server** (non *minimized*) |
| Rete | Vedi sotto: **indirizzo fisso consigliato** |
| Disco | *Use an entire disk* (predefinito) |
| Profilo | nome del server: **`emp-server`**; un utente amministratore con **nome personale** (non `emp`, riservato a EMP) e password robusta |
| SSH | **Install OpenSSH server**: sì, per gestire il Server da un altro PC |
| Snap aggiuntivi | nessuno |

**Indirizzo fisso.** Le postazioni raggiungono EMP all'indirizzo IP del Server, quindi conviene che non cambi. Ci sono due modi:
- riservarlo nel router del negozio (*DHCP reservation*), cosa consigliata;
- impostarlo nell'installazione: scheda di rete → *Edit IPv4* → *Manual*.

Se l'indirizzo cambia comunque, il certificato si aggiorna da solo entro un giorno, oppure subito con `sudo emp-server certificato` (§ 5).

Al termine: *Reboot Now*, poi accesso con l'utente creato.

### 1.4 Avvio automatico della VM

Perché EMP riparta da solo quando si accende il PC:
1. In Windows, aprire **Servizi**, cercare **VMware Autostart Service** e impostare il tipo di avvio su **Automatico**.
2. In VMware Workstation Pro: tasto destro su **My Computer → Configure Auto Start VMs**, poi spuntare **EMP-Server**.

All'accensione del PC la VM parte anche senza che nessuno faccia accesso a Windows. Allo spegnimento del PC, Ubuntu si spegne in modo ordinato grazie a `open-vm-tools`, che l'installazione di EMP attiva automaticamente nelle VM VMware.

---

## 2. Installare EMP Server

In alternativa a questa sezione, EMP Server si installa e si aggiorna con un pulsante su GitHub, tramite un runner sulla VM e senza porte aperte: vedi [Installare EMP Server da GitHub](installazione-da-github.md).

### 2.1 Copiare il pacchetto sulla VM

Il pacchetto si chiama `emp-server-<versione>.tar.gz`. Si trova:
- negli allegati della release su GitHub;
- per le versioni di collaudo, nell'artefatto della CI.

Per copiarlo sulla VM ci sono due modi.

**Dal PC Windows** (PowerShell, nella cartella dove si trova il file). `scp` è già incluso in Windows 11:
```
scp emp-server-0.6.0-alpha.9.tar.gz utente@IP-DEL-SERVER:
```

**Direttamente sulla VM**, se ha accesso a Internet:
```
wget https://github.com/edomea/edomea-marketplace/releases/download/v0.6.0-alpha.9/emp-server-0.6.0-alpha.9.tar.gz
wget https://github.com/edomea/edomea-marketplace/releases/download/v0.6.0-alpha.9/emp-server-0.6.0-alpha.9.tar.gz.sha256
sha256sum -c emp-server-0.6.0-alpha.9.tar.gz.sha256
```

### 2.2 Eseguire l'installazione

Sulla VM, dalla console o via SSH (`ssh utente@IP-DEL-SERVER`):
```
tar -xzf emp-server-0.6.0-alpha.9.tar.gz
cd emp-server-0.6.0-alpha.9
sudo ./installa.sh
```

L'installazione richiede qualche minuto e:
1. controlla il sistema e le impronte SHA-256 di tutti i file del pacchetto;
2. installa dai **repository ufficiali di Ubuntu** Nginx, PHP-FPM, MariaDB, il firewall `ufw` e gli aggiornamenti di sicurezza automatici;
3. crea l'utente di sistema `emp` e le cartelle;
4. prepara MariaDB: database `emp`, accessibile **solo dal Server stesso**, senza password salvate;
5. crea l'autorità di certificazione locale e il certificato HTTPS;
6. installa il Core ed esegue le migrazioni;
7. configura i servizi, il firewall (porte 22, 80, 443) e i backup notturni;
8. verifica che il Core risponda.

Alla fine mostra quattro cose:
- l'indirizzo per scaricare il certificato dell'autorità locale e la sua **impronta SHA-256**;
- gli indirizzi `https://` di EMP;
- il **codice di setup** monouso, per esempio `EMP-ABCDE-FGHJK-LMNPQ`;
- i comandi di gestione.

Variabili facoltative:

| Variabile | Effetto |
|---|---|
| `EMP_NOME_DNS=emp.negozio.lan` | aggiunge un nome DNS del negozio al certificato |
| `EMP_SENZA_FIREWALL=1` | non configura `ufw` (firewall gestito altrove) |
| `EMP_CONSENTI_SISTEMA=1` | consente versioni di Ubuntu non collaudate |

Esempio: `sudo EMP_NOME_DNS=emp.negozio.lan ./installa.sh`

---

## 3. Le postazioni del negozio

### 3.1 Installare il certificato dell'autorità locale (una volta per PC)

EMP usa un certificato proprio. Il browser lo considera affidabile solo dopo che sul PC è stata installata l'**autorità di certificazione locale** di questo Server.

1. Dal PC, aprire `http://IP-DEL-SERVER/emp-ca.crt`: si scarica `EMP-CA-locale.crt`.
2. **Confrontare l'impronta.** Aprire il file e guardare *Dettagli → Identificazione personale* (SHA-256). Deve coincidere con quella mostrata alla fine dell'installazione o da `sudo emp-server stato`. Se non coincide, non installarlo.
3. Installarlo:
   - **Windows**: doppio clic → *Installa certificato* → *Computer locale* → *Colloca tutti i certificati nel seguente archivio* → **Autorità di certificazione radice attendibili**. In alternativa, da PowerShell come amministratore:
     `Import-Certificate -FilePath .\EMP-CA-locale.crt -CertStoreLocation Cert:\LocalMachine\Root`
   - **macOS**: doppio clic, poi aggiungerlo al portachiavi *Sistema*. Aprirlo in *Accesso Portachiavi* → *Fidati* → **Fidati sempre**.
   - **Firefox**, se continua a segnalarlo: *Impostazioni → Privacy e sicurezza → Certificati → Mostra certificati → Autorità → Importa*, spuntando l'identificazione dei siti web.
4. Riaprire il browser.

> **Sicurezza dell'autorità locale.** L'autorità è limitata (*name constraints*) ai nomi di questo Server e agli indirizzi delle **reti private** (10.x, 172.16–31.x, 192.168.x). Anche se la sua chiave venisse sottratta, non potrebbe certificare siti Internet come quelli di una banca. La chiave resta sul Server, leggibile solo da root.

### 3.2 Aprire EMP

Indirizzi:
- `https://IP-DEL-SERVER/admin/`: Amministratore Operativo e utenti operativi. Conviene salvarlo nei preferiti di ogni postazione.
- `https://IP-DEL-SERVER/techadmin/`: solo per l'Amministratore Tecnico (primo setup, plugin, Recovery Key).

L'indirizzo `https://IP-DEL-SERVER/` porta a `/admin/`; il vecchio `/amministrazione/` porta a `/techadmin/`. `sudo emp-server indirizzi` mostra tutti gli indirizzi validi.

### 3.3 Primo setup dell'Amministratore Tecnico

1. Aprire `https://IP-DEL-SERVER/techadmin/setup.html`.
2. Inserire il **codice di setup** mostrato dall'installazione. È valido 24 ore e si usa una sola volta. Se è scaduto o perso, generarne un altro con `sudo emp-server codice-setup`.
3. Inserire nome utente, nome e password del Tecnico.
4. **Scaricare la Recovery Key** e conservarla offline ([accesso e sicurezza](accesso-sicurezza.md)).

Dopo il setup, il Tecnico crea gli amministratori e installa i plugin dalla pagina *Plugin*.

---

## 4. Cosa c'è sul Server

| Percorso | Contenuto | Proprietario |
|---|---|---|
| `/opt/emp/server/` | comando `emp-server`, versione installata | root |
| `/etc/emp/config.php` | configurazione del Core (nessuna password) | root, leggibile dal gruppo `emp` |
| `/etc/emp/tls/` | autorità locale e certificato; le chiavi sono leggibili solo da root | root |
| `/var/lib/emp/moduli/emp-core/` | EMP Core | **root**: il processo web non può modificarlo |
| `/var/lib/emp/moduli/<plugin>/` | plugin installati dalla pagina web | `emp` |
| `/var/lib/emp/catalogo/` | plugin installabili, con impronte SHA-256 | root |
| `/var/lib/emp/backup/` | backup completi di EMP (ultimi 14, modificabile) | root, leggibili dal gruppo emp-file |
| `/etc/emp/backup.conf` | orario del backup notturno e quanti backup conservare (della macchina) | root |
| `/var/lib/emp/backup-web/` | scambio tra la pagina Backup e EMP Server: richieste del Core, esiti, stato | root; richieste ed esiti `emp` |
| `/var/lib/emp/scaricamenti/` | backup preparati per il download dalla pagina Backup (un'ora) | root, letti solo da Nginx |
| `/var/lib/emp/pacchetto/` | pacchetto della versione installata (incluso nei backup) | root |
| `/var/lib/emp/arrivi/plugin/` | ZIP dei plugin caricati via scp | emp, gruppo emp-file |
| `/etc/emp/backup-remoto/` | destinazione remota e chiave dei backup (della macchina) | root |
| `/var/lib/emp/precedenti/` | ultime 5 versioni del Core sostituite | root |
| `/var/log/emp/`, `/var/log/nginx/emp-*.log` | registri | |

### Servizi e protezioni

| Componente | Configurazione |
|---|---|
| **Nginx** | Porta 443 con TLS 1.2/1.3 e certificato locale. La porta 80 serve solo `/emp-ca.crt` e rinvia tutto il resto a HTTPS. Intestazioni `nosniff`, `DENY` per i frame, nessun referrer, versione nascosta. |
| **PHP-FPM** | Gruppo dedicato `emp`, separato da Nginx. Socket locale; `open_basedir` limitato a `/var/lib/emp` e alla configurazione; funzioni di esecuzione di comandi disattivate; nessun accesso a URL esterni. |
| **MariaDB** | Nessuna porta di rete (`skip-networking`). L'utente `emp` entra tramite `unix_socket`: solo un processo che gira come utente di sistema `emp` può accedere al database, senza password da custodire. |
| **Cartella moduli** | Con *sticky bit*: il processo web installa i plugin ma non può modificare, rinominare o cancellare il Core. |
| **Firewall** | `ufw`: in ingresso solo SSH (22), 80 e 443. |
| **Aggiornamenti** | Aggiornamenti di sicurezza di Ubuntu automatici, senza riavvii automatici. |
| **Operazioni pianificate** | `emp-backup.timer` ogni notte (02:30, modificabile dalla pagina Backup); `emp-certificato.timer` ogni giorno, per scadenza o cambio di indirizzo IP. |
| **Pagina Backup** | Il Core non esegue comandi: deposita una richiesta in `/var/lib/emp/backup-web/richieste/`. `emp-backup-web.path` avvia `emp-backup-web` (root), che accetta solo le operazioni previste, con valori controllati, e le esegue con `emp-server`. Le chiavi non finiscono mai nei file leggibili dal Core né nel registro; i download li consegna Nginx da una cartella interna, dopo il controllo del Core. |

Il Core accetta le funzioni sensibili (primo setup, recupero del Tecnico, plugin, backup) **solo in HTTPS**, oppure da richieste fatte sul Server stesso.

---

## 5. Gestione quotidiana

Sul Server, dalla console o via SSH:

| Comando | A cosa serve |
|---|---|
| `sudo emp-server stato` | Servizi, moduli, scadenza del certificato, impronta dell'autorità, indirizzi |
| `sudo emp-server indirizzi` | Indirizzi `https://` per le postazioni |
| `sudo emp-server codice-setup` | Nuovo codice per il primo setup |
| `sudo emp-server recupero-tecnico NOME` | Nuova password del Tecnico e nuova Recovery Key, se la Recovery Key è persa |
| `sudo emp-server certificato` | Nuovo certificato, per esempio dopo un cambio di indirizzo IP |
| `sudo emp-server backup` | Backup completo immediato (e copia remota, se collegata) |
| `sudo emp-server backup-elenco` | Backup locali e remoti |
| `sudo emp-server backup-impostazioni` | Orario del backup notturno, attivazione, quanti backup conservare (`--ora 02:30 --notturno si --conserva 14 --conserva-remoti 30`) |
| `sudo emp-server backup-scarica --remoto NOME` | Copia (decifrata) un backup remoto su questa macchina |
| `sudo emp-server backup-remoto collega gdrive` | Copia cifrata di ogni backup su Google Drive (anche `sftp`, `locale`) |
| `sudo emp-server ripristina FILE` / `--remoto NOME` | Rimette EMP com'era nel backup, sulla stessa macchina o su una nuova |
| `sudo emp-server accesso-scp NOME` | L'utente NOME scarica i backup e carica i plugin via scp (`--togli` per revocare) |
| `sudo emp-server elimina-vecchio-utente` | Elimina l'account personale `emp`, se Ubuntu è stato installato con quel nome (§ 5.x) |
| `sudo emp-server sistema stato\|verifica\|aggiorna\|riavvia` | Aggiornamenti dei pacchetti di Ubuntu (backup di EMP prima dell'installazione); anche dalla pagina **Sistema** |
| `sudo emp-server moduli` | Core e plugin installati |
| `sudo emp-server azzera --nome NOME` | Total reset di un clone del master per un nuovo cliente (vedi sotto) |
| `sudo emp-server collega-github` | Collega il Server a GitHub per gli aggiornamenti |

### Aggiornare EMP

Copiare il nuovo pacchetto ed eseguire di nuovo `sudo ./installa.sh`.

L'aggiornamento:
- conserva database, plugin, certificati e utenti;
- mette da parte la versione precedente del Core;
- esegue solo le migrazioni nuove.

Le versioni di EMP non vengono mai riutilizzate.

### Aggiornare Ubuntu

Gli aggiornamenti di sicurezza si installano da soli ogni notte (`unattended-upgrades`). Per tutti gli altri aggiornamenti dei pacchetti di Ubuntu, come Nginx, PHP, MariaDB, kernel e sistema, la strada più breve è la pagina **Sistema** di `/techadmin` (`https://IP-DEL-SERVER/techadmin/sistema.html`):

1. **Verifica aggiornamenti**: elenco dei pacchetti da aggiornare, con quelli di sicurezza segnati.
2. **Installa gli aggiornamenti** (con la password del Tecnico): EMP fa prima un **backup completo** (`…-prima-di-aggiornamenti-ubuntu`), poi installa. Se il backup non riesce, non installa nulla. Alla fine controlla che EMP risponda.
3. Se compare **Riavvio necessario** (tipicamente un kernel nuovo), usa **Riavvia il server** (password e `RIAVVIA`): il riavvio parte dopo 1 minuto ed EMP torna disponibile da solo.

Le stesse operazioni dalla console sono `sudo emp-server sistema stato`, `… verifica`, `… aggiorna` e `… riavvia`.

Si resta sempre nella stessa versione di Ubuntu: il passaggio a una versione nuova (es. 24.04 → 26.04) non si fa da qui. Durante l'installazione EMP può non rispondere per qualche secondo, mentre si riavviano i servizi aggiornati: meglio a negozio chiuso.

### Utente di EMP e accesso scp

EMP gira con l'utente di sistema **`emp`**: non entra in SSH, non usa `sudo`, non ha una cartella personale. Chi gestisce il Server entra con il **proprio** utente.

**Se Ubuntu è stato installato con l'utente `emp`**, EMP userebbe un account personale con permessi di amministratore: `installa.sh` ed `emp-server stato` lo segnalano. Per correggere:
1. crea il tuo utente: `sudo adduser NOME && sudo usermod -aG sudo NOME`;
2. rientra con quello (`ssh NOME@IP-DEL-SERVER`) e chiudi le sessioni di `emp`;
3. `sudo emp-server elimina-vecchio-utente`, poi scrivi `ELIMINA`.

L'account viene cancellato con la sua cartella. Al suo posto EMP riceve un utente di sistema con lo stesso nome; dati, database e plugin non cambiano. Il comando si rifiuta se non viene eseguito con `sudo` da un altro amministratore o se `emp` ha ancora sessioni aperte.

**Accesso scp per backup e plugin.** `sudo emp-server accesso-scp NOME` abilita l'utente NOME, a partire dal suo accesso SSH successivo:
- **scarica i backup** dal PC: `scp NOME@IP-DEL-SERVER:/var/lib/emp/backup/emp-backup-….tar .`
- **carica i plugin** dal PC: `scp mio-plugin.zip NOME@IP-DEL-SERVER:/var/lib/emp/arrivi/plugin/`

Gli ZIP caricati compaiono nella pagina **Plugin** del Tecnico, sezione *Arrivati via scp*. *Aggiungi* li controlla come un caricamento dal browser e li mette nel catalogo locale; poi si installano col pulsante. `--togli` revoca l'accesso.

### Backup e ripristino

Tutto si gestisce dalla pagina **Backup** di `/techadmin` (Amministratore Tecnico, solo `https://`); i comandi della console fanno le stesse cose.

| Nella pagina Backup | Note |
|---|---|
| Stato | ultimo backup, prossimo backup automatico, destinazione remota e se è raggiungibile; avanzamento dell'operazione in corso |
| Esegui un backup adesso | come `sudo emp-server backup` |
| Pianificazione e conservazione | backup notturno sì/no, orario, quanti backup conservare sulla macchina (1–90) e sulla destinazione remota (1–365) |
| Copia remota cifrata | collega **Google Drive**, un server **SFTP** o una **cartella** (disco USB o NAS montato sotto `/mnt`, `/media`, `/srv`); mostra la chiave dei backup; scollega. Serve la tua password |
| Backup su questa macchina e sulla destinazione remota | **Scarica** (il file arriva al PC, non cifrato) e **Ripristina** (scrivere `RIPRISTINA`). Serve la tua password |

**Google Drive dalla pagina, senza SSH:** *Collega* → si apre Google → accedi con l'account dei backup e consenti → il browser finisce su una pagina che non si apre (`http://127.0.0.1:53682/…`): è normale. Copia quell'indirizzo dalla barra e incollalo nella pagina Backup. Alla fine compare la **chiave dei backup**: scaricala in TXT e conservala fuori dalla VM.

**Dopo un ripristino** EMP torna allo stato del backup, comprese utenze e password di allora: se la pagina chiede di accedere di nuovo, usare la password valida al momento del backup.

Il backup riguarda **tutto EMP e solo EMP**.

**Contiene:**
- il database;
- il Core e i plugin installati;
- i cataloghi dei plugin;
- il pacchetto della versione di EMP Server in uso, così un ripristino rimette esattamente quella versione.

**Non contiene ciò che appartiene alla macchina:** Ubuntu, nome host, chiavi SSH, certificato HTTPS e sua autorità, collegamento a GitHub, destinazione dei backup remoti, impostazioni dei backup.

**Quando parte:**
- ogni notte alle 02:30 (si conservano gli ultimi 14): orario, attivazione e numero si cambiano dalla pagina Backup;
- **automaticamente prima di ogni aggiornamento**, da `installa.sh` o dal tasto *Installa EMP Server*. Se il backup non riesce, l'aggiornamento non parte;
- a mano, con `sudo emp-server backup`.

Ogni backup è un solo file, `/var/lib/emp/backup/emp-backup-<server>-<AAAAMMGG-HHMMSS>[-etichetta].tar`, con le impronte SHA-256 di ogni parte. `sudo emp-server backup-elenco` mostra quelli presenti sulla macchina e sulla destinazione remota.

**Copia remota cifrata (Google Drive) dalla console.** Si collega una volta sola (oppure dalla pagina Backup, vedi sopra). Dal PC apri una connessione SSH con il tunnel per l'accesso a Google:
```
ssh -L 53682:127.0.0.1:53682 utente@IP-DEL-SERVER
sudo emp-server backup-remoto collega gdrive
```
Poi apri in Chrome il link `http://127.0.0.1:53682/…` che compare e accedi con l'account Google dei backup.
- **Accesso limitato:** EMP vede solo i file che crea lui, nella cartella `EMP-backup` di Drive.
- **Cifratura:** ogni backup viene copiato su Drive cifrato con la **chiave dei backup**, mostrata una sola volta alla fine del collegamento. Va salvata fuori dalla VM (gestore di password, foglio in cassaforte): senza chiave i backup remoti non si possono aprire. Per rivederla: `sudo emp-server backup-remoto chiave`.
- **Conservazione:** su Drive restano gli ultimi 30 backup di ogni server (modificabile).
- **Altre destinazioni:** `collega sftp utente@host[:porta]` oppure `collega locale /percorso`, per un disco USB o un NAS montato.

**Ripristino sulla stessa macchina**, per esempio dopo un aggiornamento andato male:
```
sudo emp-server backup-elenco
sudo emp-server ripristina /var/lib/emp/backup/emp-backup-…-prima-di-0.6.0-alpha.9.tar
```
Chiede di scrivere `RIPRISTINA`. Prima di sostituire i dati salva lo stato attuale (backup `…-prima-del-ripristino`). Se il backup è di un'altra versione, reinstalla quella versione dal pacchetto contenuto nel backup.

**Ripristino su una macchina nuova** ("new installation"):
1. Ubuntu Server nuovo, EMP Server installato con `installa.sh` o con il tasto su GitHub. La macchina ha il suo nome e il suo certificato.
2. Primo setup del Tecnico sulla macchina nuova, poi pagina **Backup** → *Collega* con la **chiave dei backup esistente** (oppure `sudo emp-server backup-remoto collega gdrive --chiave CHIAVE-DEI-BACKUP`).
3. Nell'elenco *Backup sulla destinazione remota*: **Ripristina** (oppure `sudo emp-server backup-elenco` e `sudo emp-server ripristina --remoto emp-backup-….tar`).

Tornano database, utenti, Core e plugin. Il certificato resta quello della macchina nuova, come in una nuova installazione: va installato sulle postazioni come al primo avvio.

### Nuovo cliente white label: master → clone → azzera

Una VM **master** resta sempre aggiornata all'ultima release e in stato di fabbrica: nessun Amministratore Tecnico, nessun dato. Non si usa mai in produzione. Per ogni nuovo cliente:

1. **Clonare il master.** In VMware Workstation Pro, con il master spento: *VM → Manage → Clone → Full clone*. All'avvio del clone, se VMware lo chiede, scegliere **I copied it** (nuovo indirizzo MAC).
2. **Azzerare il clone.** Dalla console del clone:

   ```bash
   sudo emp-server azzera --nome NOME-CLIENTE
   ```

   Il comando chiede di scrivere `AZZERA NOME-CLIENTE` come conferma, poi:
   - toglie da questa macchina il collegamento a GitHub copiato dal master (su GitHub il runner del master resta registrato);
   - svuota il database e cancella plugin, catalogo locale, backup e registri di prova;
   - dà al clone un'identità propria: nome host, identificativo della macchina, chiavi SSH;
   - crea una nuova autorità di certificazione e un nuovo certificato HTTPS con il nuovo nome;
   - mostra l'impronta della nuova autorità e il codice monouso per il primo setup.

   Restano Ubuntu, i pacchetti e il Core installato. Il nome: lettere minuscole, cifre e trattini, da 2 a 41 caratteri, diverso per ogni Server.
3. **Riavviare** (`sudo reboot`). Se l'indirizzo IP cambia, riservarlo nel router del cliente: il certificato si aggiorna da solo.
4. **Postazioni:** togliere la vecchia autorità "EMP CA locale" e installare la nuova (§ 3.1).
5. **Primo setup** dell'Amministratore Tecnico con il codice monouso (§ 3.3) e custodia della nuova Recovery Key.
6. **GitHub (facoltativo):** prendere un token di registrazione (*Settings → Actions → Runners → New self-hosted runner*, vale 1 ora) ed eseguire `sudo emp-server collega-github`. Il runner prende come nome ed etichetta il nome del Server.

`azzera` è irreversibile: prima del comando fare un'istantanea o una copia della VM. Non eseguirlo sul master.

### Disinstallare

`sudo ./disinstalla.sh` toglie EMP da Nginx, PHP-FPM e dalle operazioni pianificate. **Non cancella i dati**: una nuova installazione li ritrova. Lo script indica i comandi da usare per cancellarli definitivamente, dopo un backup.

---

## 6. Problemi frequenti

| Problema | Causa e soluzione |
|---|---|
| Il browser dice «connessione non privata» | L'autorità locale non è installata su quel PC (§ 3.1), oppure si sta usando un indirizzo non coperto dal certificato: `sudo emp-server certificato`. |
| Gli altri PC non raggiungono il Server | Scheda di rete della VM non in modalità **Bridged**, oppure PC host in sospensione. |
| «Nessun indirizzo di rete privata» | La VM non ha ricevuto un indirizzo dalla rete del negozio: controllare la modalità Bridged e il cavo o il Wi-Fi. |
| «Codice di setup non valido o scaduto» | `sudo emp-server codice-setup` genera un codice nuovo; quello precedente non vale più. |
| «Funzione disponibile solo in HTTPS» | EMP è stato aperto con `http://`: usare `https://`. |
| Il Core non risponde | `sudo emp-server stato`; registri in `/var/log/nginx/emp-errori.log` e `/var/log/emp/php-errori.log`. |
| L'installazione dice che Ubuntu non è collaudata | Usare 26.04 o 24.04 LTS. In alternativa `EMP_CONSENTI_SISTEMA=1`, a proprio rischio. |

---

## 7. Limiti noti di questa versione

- Pacchetto **non firmato**: l'integrità si basa sulle impronte SHA-256, sulla release GitHub in HTTPS e sull'attestazione di provenienza della CI.
- Un solo Server per negozio; nessuna replica.
- Senza destinazione remota collegata i backup restano solo nella VM. I backup locali non sono cifrati (cartella riservata a root); la copia remota sì, con la chiave dei backup.
- Telefoni e tablet: l'installazione dell'autorità locale su Android e iOS va documentata.
- EMP Web, cioè il Core su hosting o VPS pubblico con certificato pubblico, è da progettare.
