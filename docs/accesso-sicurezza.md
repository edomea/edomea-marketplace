# EMP — Account, ruoli e sicurezza di accesso

Versione 1.1 · 16/11/2026 · Deriva dall'allegato *VenVia 0.4 — Account, ruoli e sicurezza* (rev. 1.0), con la **decisione del titolare del 16/11/2026**:

> Il sistema deve funzionare **senza Internet**. TOTP (Google Authenticator) e OTP via email sono **rimossi**. Per tutti gli utenti l'accesso avviene con **nome utente e password**. Gli amministratori possono cambiare le password e revocare gli accessi; l'Amministratore Tecnico può usare una **Recovery Key** se perde la propria password.

Implementazione: `core/src/Access/`, migrazione `core/migrations/0002_accesso.sql`, pagine `core/public/techadmin/` (Amministratore Tecnico) e `core/public/admin/` (Amministratore Operativo e utenti).

Revisione 1.1 (16/11/2026): con il passaggio a **EMP Server su Ubuntu** ([ambiente](ambiente-server-linux.md)) il Server è spesso una macchina virtuale senza browser. Le funzioni sensibili non sono più limitate al «computer Server» ma all'**HTTPS**, e il primo setup richiede un **codice monouso** generato sulla console del Server.

---

## 1. Ruoli

| Ruolo | Responsabilità | Chi lo crea e lo gestisce |
|---|---|---|
| **Amministratore Tecnico** (unico) | Sicurezza, plugin, Control Center; crea e gestisce gli amministratori | Si crea da solo al primo setup; nessuno può reimpostarne la password, tranne la sua Recovery Key |
| **Amministratore** (gestionale) | Organizzazione, utenti operativi | Il Tecnico |
| **Operatore** | Cassa, magazzino, Studio… secondo i plugin | Un amministratore |

Il Tecnico non gestisce direttamente gli operatori e non svolge automaticamente le funzioni gestionali. Un amministratore non può agire sul Tecnico né usare le funzioni tecniche (ad esempio la pagina Plugin). Le operazioni amministrative non si eseguono sul proprio account: per sé si usa il cambio password del profilo.

## 2. Primo setup

1. Da qualsiasi postazione del negozio, in **HTTPS**: pagina `/techadmin/setup.html`. In HTTP il setup è rifiutato.
2. **Codice di setup**: mostrato alla fine di `installa.sh`, oppure generato con `sudo emp-server codice-setup`.
   - È composto da 15 caratteri casuali, formato `EMP-XXXXX-XXXXX-XXXXX`.
   - È valido **24 ore** e si usa **una sola volta**; generarne uno nuovo annulla il precedente.
   - Nel database c'è solo il suo hash.
   - Dopo 5 tentativi errati in 15 minuti il setup si blocca temporaneamente.

   Il codice dimostra di avere accesso alla console del Server e impedisce che chiunque in rete crei per primo il Tecnico. Non serve solo per le richieste fatte sul Server stesso: indirizzo di loopback e `Host` locale.
3. Nome utente, nome e cognome, password. Non esistono account o password predefiniti.
4. Il Core crea il Tecnico e genera la **Recovery Key**, mostrata **una sola volta**.
5. Il setup si può fare **una sola volta**: un secondo tentativo, anche simultaneo, viene rifiutato. Dopo il setup non si possono più generare codici.

## 3. Accesso

- Nome utente e password per tutti i ruoli, **senza servizi esterni**: funziona con il negozio offline.
- La sessione è un token casuale in un cookie `HttpOnly`, `SameSite=Strict` (`Secure` in HTTPS). Nel database c'è solo la sua impronta SHA-256.
- Durata massima 12 ore; chiusura automatica dopo 4 ore di inattività.
- Messaggio identico per utente inesistente e password errata.
- **Blocco temporaneo**: 5 tentativi falliti sullo stesso utente, o 20 dallo stesso indirizzo, bloccano i nuovi tentativi per 15 minuti.

## 4. Password

- Da 12 a 128 caratteri; non deve contenere il nome utente; almeno 5 caratteri diversi.
- Conservate solo come hash **Argon2id** (bcrypt se il server non lo supporta). Mai in chiaro, mai nei log.
- **Cambio della propria password**: richiede la password attuale; chiude le altre sessioni dell'utente.
- **Password temporanea** impostata da un amministratore: va cambiata al primo accesso; fino ad allora l'utente può solo cambiare la password. Chiude tutte le sessioni dell'utente.

## 5. Revoca degli accessi

Un amministratore, sugli utenti che gestisce, può:
- **revocare gli accessi**: chiude subito tutte le sessioni aperte;
- **disattivare** l'account: sessioni chiuse e nuovi accessi impossibili;
- **riattivare** l'account.

## 5.1 Profilo e modifica degli account

- **Il tuo profilo** (in /techadmin e in /admin): nome e cognome, nome utente, ruolo, quando e da chi è stato creato e modificato l'account, ultimo cambio password, sessioni aperte, ultimi 5 accessi (data e indirizzo IP), tentativi falliti in sospeso.
- **Modifica il profilo:** nome e cognome e nome utente, con la propria password attuale. Cambiando il nome utente si entra con quello nuovo dal prossimo accesso; le sessioni aperte restano valide.
- **Chiudi le altre sessioni:** con la propria password, chiude tutte le sessioni tranne quella in uso (es. un PC lasciato aperto).
- **Modifica di un account gestito:** il Tecnico modifica nome e cognome e nome utente degli Amministratori Operativi, l'Amministratore quelli dei suoi operatori. Nessuno modifica chi sta sopra di lui.
- Ogni modifica va nel registro di tracciabilità con i valori prima e dopo.

## 5.0 Ruoli di negozio e permessi

Oltre ai livelli di accesso (Tecnico, Amministratore, Operatore) ci sono i **ruoli di negozio**: Cassiere, Store Manager, Area Manager, Responsabile Reparto, Commerciale, Supervisor / Direttore Commerciale, Buyer, Responsabile Logistica, Magazziniere, Contabilità (dal manuale, §04), modificabili e ampliabili.
- Un ruolo è un **insieme di permessi**; i permessi li dichiarano i plugin (campo `permissions` di `emp-module.json`) e compaiono da soli quando il plugin è installato.
- A un **operatore** si assegnano uno o più ruoli: ha l'unione dei loro permessi. Gli **amministratori** hanno tutti i permessi gestionali; il **Tecnico** nessuno (funzioni tecniche).
- Creano operatori e assegnano ruoli sia il Tecnico (/techadmin) sia gli amministratori (/admin); entrambi gestiscono anche l'elenco dei ruoli. Un ruolo assegnato non si può eliminare.
- Nei plugin il controllo è `$kernel->jobRoles()->allows($utente, 'magazzino.carico')`. Ogni modifica è tracciata con i valori prima e dopo.

## 5.1.1 Postazioni autorizzate

Le postazioni sono i browser dei PC e dei tablet del negozio. Una postazione autorizzata conserva un token permanente nel cookie `emp_postazione` (HttpOnly, solo HTTPS, 5 anni); nel database c'è solo la sua impronta.
- **Chi le gestisce:** il Tecnico e gli amministratori, dal riquadro *Postazioni autorizzate* di /techadmin e /admin (solo in HTTPS).
- **Come si aggiungono:** con un **codice di associazione** monouso, valido 30 minuti, che si scrive sulla nuova postazione ("Associa questa postazione" nella pagina di accesso); oppure con **Autorizza questa postazione** dal browser in uso. Codici sbagliati: massimo 10 ogni 15 minuti per indirizzo.
- **Obbligo** (lo decide solo il Tecnico, con almeno una postazione attiva): gli **operatori** accedono solo dalle postazioni autorizzate. Tecnico e amministratori accedono da qualsiasi postazione, per non restare chiusi fuori.
- **Revoca:** il token non vale più e le sessioni aperte da quella postazione si chiudono subito.
- In fondo a ogni pagina compare il nome della postazione in uso. Ogni operazione è tracciata.

## 5.2 Marchio white label

Il Tecnico sceglie dalla pagina **Marchio** di /techadmin (solo in HTTPS) il nome dell'attività, il colore principale e il logo, che compaiono in tutte le pagine di /admin e /techadmin (intestazione e titolo della scheda), anche in quelle di accesso.
- Colore: deve avere contrasto almeno 4,5:1 con il testo bianco dell'intestazione, altrimenti è rifiutato.
- Logo: PNG, JPEG o WebP (niente SVG), al massimo 512 KB, da 16 a 2000 pixel per lato; il Core controlla che sia davvero un'immagine e lo serve con `X-Content-Type-Options: nosniff`.
- "Torna al marchio predefinito" toglie nome, colore e logo. Il marchio è nel database: va nei backup e si azzera con `emp-server azzera` (nuovo cliente).

## 6. Recovery Key dell'Amministratore Tecnico

- Generata al primo setup: 25 caratteri casuali (125 bit), formato `EMP-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX`.
- Mostrata una sola volta, **scaricabile in TXT**, con l'avviso obbligatorio e la conferma «Ho scaricato la Recovery Key e mi assumo la responsabilità della sua custodia».
- Nel database c'è solo il suo hash: nessuno, nemmeno il Tecnico, può rileggerla.
- **Uso** (pagina `/techadmin/recupero.html`, solo in HTTPS): nome utente del Tecnico, Recovery Key, nuova password. Il Core imposta la nuova password, chiude tutte le sessioni, **invalida la chiave usata e ne genera una nuova**, da salvare allo stesso modo.
- Massimo 3 tentativi falliti ogni 15 minuti.
- **Recovery Key persa**: dalla console del Server, `sudo emp-server recupero-tecnico NOME`.
  - Chiede la nuova password senza mostrarla, chiude tutte le sessioni del Tecnico e genera una **nuova** Recovery Key.
  - Chi ha i permessi di root sul Server controlla già l'intera installazione, quindi questa via non apre nuovi rischi.
  - Viene registrata nella tracciabilità (`recupero_console`).
- Amministratori e operatori non hanno Recovery Key: se perdono la password, la reimposta chi li gestisce.

Testo dell'avviso:

> ATTENZIONE — La Recovery Key consente di reimpostare la password dell'Amministratore Tecnico. Viene mostrata una sola volta e non può essere recuperata. Conservare il file in un luogo sicuro, preferibilmente offline. Chi entra in possesso della chiave e raggiunge EMP dalla rete del negozio può prendere il controllo dell'installazione.

## 7. Tracciabilità

Nel registro del Core (`core_audit`), senza password, chiavi o token: generazione del codice di setup, primo setup riuscito e rifiutato, accessi riusciti e rifiutati, creazione utenti, cambi password, password temporanee, revoche, disattivazioni e riattivazioni, recuperi riusciti e rifiutati, recuperi dalla console. Ogni voce ha esecutore, data e ora locale, utente interessato.

## 8. Protezioni delle richieste

- Ogni richiesta che modifica dati richiede l'intestazione `X-EMP-Richiesta: 1` e, se il browser invia `Origin`, la stessa origine del Core (protezione CSRF).
- Primo setup, recupero del Tecnico, gestione plugin e backup: **solo in HTTPS** oppure da richieste fatte sul Server stesso (loopback e `Host` locale), tramite `AdminGuard`.
  - Lo stato HTTPS lo stabilisce Nginx (`fastcgi_param HTTPS on`); un'intestazione inviata dal client non vale.
  - Su EMP Server la porta 80 rinvia comunque a HTTPS.

- **Conferma della password** (pagina Backup): mostrare la chiave dei backup, collegare o scollegare la destinazione remota, scaricare o ripristinare un backup richiedono di nuovo la password del Tecnico già collegato. I tentativi sbagliati sono limitati come l'accesso (5 in 15 minuti) e registrati (`conferma_password_rifiutata`).

## 9. Limiti noti e punti rinviati

- **HTTPS in LAN**: c'è, con un'autorità di certificazione locale da installare una volta su ogni postazione ([ambiente](ambiente-server-linux.md) § 3.1). Il login resta tecnicamente possibile anche in HTTP, ma su EMP Server la porta 80 non raggiunge mai il Core.
- **Master Recovery Key** per la cifratura dei backup: rinviata alla progettazione dei backup.
- Permessi per negozio e per funzione degli operatori: arriveranno con i plugin gestionali.
- TOTP/OTP: rimossi per decisione; reintroducibili in futuro solo come opzione che non blocchi il funzionamento offline.
