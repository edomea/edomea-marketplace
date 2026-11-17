# Installare EMP Server da GitHub

Con questa procedura EMP Server si installa e si aggiorna **con un pulsante su GitHub**, senza copiare pacchetti a mano sulla VM del negozio.

Sulla VM gira un **runner GitHub Actions**. È lui a collegarsi a GitHub, in uscita e in HTTPS, come fa un browser:
- nessuna porta aperta sul router;
- nessun IP pubblico;
- nessun accesso SSH da Internet.

I vincoli del manuale restano rispettati. Internet serve solo durante l'installazione; il negozio continua a lavorare offline.

```
GitHub (Actions)                               VM del negozio (LAN)
┌────────────────────────┐                    ┌───────────────────────────────────┐
│ "Installa EMP Server"  │                    │ runner (utente emp-runner)        │
│ 1. pacchetto           │ ◄── HTTPS in ───── │   │ sudo, unico comando ammesso    │
│    build-server.sh     │     uscita         │   ▼                               │
│    → artefatto         │                    │ emp-installa-da-github (root)     │
│ 2. installa ───────────┼──── lavoro ──────► │   verifica esecuzione e impronte  │
│                        │                    │   → installa.sh                   │
└────────────────────────┘                    └───────────────────────────────────┘
```

## 1. Preparare la VM (una volta sola)

Serve EMP Server su Ubuntu Server LTS come descritto in [EMP Server su Ubuntu](ambiente-server-linux.md), sezione 1, con accesso a Internet in uscita. EMP Server non deve essere già installato: lo installa GitHub.

**1.1 Prendere il token di registrazione.** Su GitHub: repository → **Settings → Actions → Runners → New self-hosted runner** → Linux. Copiare il token che compare nel comando `./config.sh --token …`. Vale **1 ora**.

**1.2 Preparare la VM.** Dalla console della VM, o via SSH dal PC del negozio:

```bash
git clone --depth 1 -b emp/server-linux https://github.com/edomea/edomea-marketplace
sudo edomea-marketplace/server/github/prepara-runner.sh
```

Lo script chiede il token, che non viene mostrato mentre lo si scrive. Poi:
- crea l'utente di sistema `emp-runner`, senza password e senza privilegi;
- scarica il runner ufficiale di GitHub e ne verifica l'impronta SHA-256 sulle note di rilascio;
- lo registra sul repository con l'etichetta `emp-server`;
- lo installa come servizio che parte da solo all'avvio della VM;
- installa `/usr/local/sbin/emp-installa-da-github` e consente a `emp-runner` di eseguire come root **solo quel comando**.

Opzioni:
- `--etichetta NOME`, per esempio `--etichetta negozio-ciro-marina` quando ci sono più negozi;
- `--nome NOME`, il nome del runner su GitHub;
- `--rimuovi`, per togliere il runner (chiede un token di rimozione; EMP Server e i dati non vengono toccati).

La cartella `edomea-marketplace` scaricata con `git clone` si può cancellare dopo la preparazione.

**1.3 Impostazioni di GitHub (obbligatorio).** Il repository è pubblico, quindi una pull request di un estraneo potrebbe tentare di usare il runner del negozio.
- **Settings → Actions → General → Fork pull request workflows from outside collaborators** → scegliere **Require approval for all outside collaborators**.
- Poi non approvare mai l'esecuzione di workflow di pull request esterne senza averne letto le modifiche.

**1.4 Approvazione esplicita (consigliato).** **Settings → Environments → server-negozio → Required reviewers**: aggiungere il titolare. Ogni installazione si ferma finché non si preme *Approve*.

## 2. Installare o aggiornare

1. GitHub → **Actions → Installa EMP Server → Run workflow**.
2. Scegliere il **branch** da installare (per ora `emp/server-linux`), l'etichetta del Server (predefinita `emp-server`) e se includere i plugin dimostrativi.
3. Il job **Pacchetto** costruisce `emp-server-<versione>.tar.gz` con il Core e i plugin del branch. L'impronta del pacchetto compare nel riepilogo dell'esecuzione.
4. Il job **Installazione sul Server** gira sulla VM ed esegue `installa.sh`. Alla prima esecuzione installa; dopo, **aggiorna** conservando database, plugin, certificati e utenti.

Se la VM è spenta o senza Internet, il job resta in attesa del runner. Si può annullare dalla pagina dell'esecuzione.

**Codici riservati.** I log di Actions di un repository pubblico sono **pubblici**. Per questo codice di setup e Recovery Key vengono sostituiti da `EMP-[nascosto]`. Il registro completo resta sul Server, leggibile solo da root, in `/var/log/emp/github-installa-<data>-<esecuzione>.log`. Per il primo setup dell'Amministratore Tecnico, dalla console della VM:

```bash
sudo emp-server codice-setup
```

## 3. Cosa verifica il Server prima di installare

`emp-installa-da-github` **non si fida del runner**. Riceve solo il numero dell'esecuzione e un token, scarica il pacchetto da sé e installa solo se:

| Controllo | Respinge |
|---|---|
| L'esecuzione appartiene a `edomea/edomea-marketplace`, codice compreso | pull request da fork |
| Workflow `.github/workflows/installa-server.yml` | altri workflow, anche dello stesso repository |
| Evento `workflow_dispatch` (avvio manuale) | push, pull request, pianificazioni |
| Avviata da un utente autorizzato (predefinito: il proprietario del repository) | chiunque altro |
| In corso e avviata da meno di 180 minuti | riuso di esecuzioni vecchie |
| Impronta dell'artefatto uguale a quella registrata da GitHub | artefatto alterato |
| Impronta del pacchetto (`.sha256`) e, poi, di ogni file (`SHA256SUMS`, controllato da `installa.sh`) | pacchetto alterato |
| Nessun percorso assoluto o `..` nell'archivio | file scritti fuori dalla cartella |

Il token passa sullo standard input e in un file temporaneo, mai negli argomenti dei comandi. Non viene inoltrato allo storage degli artefatti.

Configurazione, modificabile solo da root, in `/etc/emp/github.conf`:
- `AUTORIZZATI`: utenti GitHub separati da virgola;
- `ETA_MASSIMA_MIN`;
- `EMP_NOME_DNS`: nome DNS aggiuntivo nel certificato.

## 4. Più negozi (white label)

Un runner per ogni Server, ciascuno con la propria etichetta (`--etichetta`). Al momento di *Run workflow* si sceglie l'etichetta del negozio da aggiornare. Un operatore white label con il proprio fork usa lo stesso schema sul proprio repository: `--repo PROPRIETARIO/REPO` in `prepara-runner.sh`.

## 5. Limiti

- GitHub sconsiglia i runner self-hosted sui repository pubblici. Le contromisure sono:
  - l'approvazione obbligatoria delle pull request esterne (1.3);
  - un utente runner senza privilegi;
  - un solo comando root che verifica tutto da sé.

  Un workflow di pull request approvato per errore potrebbe comunque eseguire codice sulla VM come `emp-runner`, senza accesso a database, chiavi o dati di EMP, ma con accesso alla LAN del negozio.
- Il pacchetto non è firmato. La catena di fiducia è GitHub: chi controlla l'account del titolare può installare. Proteggere l'account con l'autenticazione a due fattori.
- Il runner si aggiorna da solo dalle release ufficiali di GitHub (`actions/runner`): è l'unico componente sulla VM che non viene dai repository di Ubuntu.
