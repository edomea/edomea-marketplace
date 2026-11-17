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
| Profilo | nome del server: **`emp-server`**; un utente amministratore con password robusta |
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
scp emp-server-0.6.0-alpha.1.tar.gz utente@IP-DEL-SERVER:
```

**Direttamente sulla VM**, se ha accesso a Internet:
```
wget https://github.com/edomea/edomea-marketplace/releases/download/v0.6.0-alpha.1/emp-server-0.6.0-alpha.1.tar.gz
wget https://github.com/edomea/edomea-marketplace/releases/download/v0.6.0-alpha.1/emp-server-0.6.0-alpha.1.tar.gz.sha256
sha256sum -c emp-server-0.6.0-alpha.1.tar.gz.sha256
```

### 2.2 Eseguire l'installazione

Sulla VM, dalla console o via SSH (`ssh utente@IP-DEL-SERVER`):
```
tar -xzf emp-server-0.6.0-alpha.1.tar.gz
cd emp-server-0.6.0-alpha.1
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

Indirizzo: `https://IP-DEL-SERVER/amministrazione/`. Conviene salvarlo nei preferiti di ogni postazione. `sudo emp-server indirizzi` mostra tutti gli indirizzi validi.

### 3.3 Primo setup dell'Amministratore Tecnico

1. Aprire `https://IP-DEL-SERVER/amministrazione/setup.html`.
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
| `/var/lib/emp/backup/` | backup notturni (ultimi 14) | root |
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
| **Operazioni pianificate** | `emp-backup.timer` ogni notte alle 02:30; `emp-certificato.timer` ogni giorno, per scadenza o cambio di indirizzo IP. |

Il Core accetta le funzioni sensibili (primo setup, recupero del Tecnico, plugin) **solo in HTTPS**, oppure da richieste fatte sul Server stesso.

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
| `sudo emp-server backup` | Backup immediato |
| `sudo emp-server moduli` | Core e plugin installati |

### Aggiornare EMP

Copiare il nuovo pacchetto ed eseguire di nuovo `sudo ./installa.sh`.

L'aggiornamento:
- conserva database, plugin, certificati e utenti;
- mette da parte la versione precedente del Core;
- esegue solo le migrazioni nuove.

Le versioni di EMP non vengono mai riutilizzate.

Per aggiornare Ubuntu: `sudo apt update && sudo apt upgrade`. Gli aggiornamenti di sicurezza sono già automatici.

### Backup e ripristino

Ogni notte, in `/var/lib/emp/backup/emp-AAAAMMGG-HHMMSS/`, viene creata una cartella con:
- `database.sql.gz`: tutto il database;
- `configurazione.tar.gz`: configurazione, certificati e moduli;
- `SHA256SUMS`.

**I backup sono dentro la VM.** Copiarli regolarmente fuori, su un altro disco o un NAS. Con VMware si possono anche fare *snapshot* della VM spenta.

Copia da Windows:
```
scp -r utente@IP-DEL-SERVER:/var/lib/emp/backup/emp-AAAAMMGG-HHMMSS .
```
La cartella dei backup è riservata a root: prima copiarla in una cartella dell'utente con `sudo cp -r …` e assegnarla con `sudo chown -R utente …`.

**Ripristino del database**, su un Server installato:
```
sudo sha256sum -c SHA256SUMS
gunzip -c database.sql.gz | sudo mariadb
```

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
- I backup restano nella VM finché non vengono copiati fuori; non sono ancora cifrati, perché la *Master Recovery Key* è rinviata.
- Telefoni e tablet: l'installazione dell'autorità locale su Android e iOS va documentata.
- EMP Web, cioè il Core su hosting o VPS pubblico con certificato pubblico, è da progettare.
