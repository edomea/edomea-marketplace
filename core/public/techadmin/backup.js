"use strict";
/*
 * Pagina Backup del Core EMP (solo Amministratore Tecnico, solo HTTPS).
 * API: GET /api/v1/backup, POST /api/v1/backup/{aggiorna,esegui,impostazioni,destinazione,
 *      destinazione/google,scollega,chiave,scarica,ripristina}, GET /api/v1/backup/richiesta/{id},
 *      GET /api/v1/backup/file/{token}
 * Le operazioni le esegue EMP Server: ogni POST restituisce una richiesta, di cui si attende l'esito.
 * Helper standard Edomea: EMP.api(), EMP.esc(), EMP.toast(), EMP.fmtDateTime().
 */

const $ = (id) => document.getElementById(id);
const toast = EMP.toast;
let current = null;      // ultimo stato ricevuto
let polling = null;      // aggiornamento periodico durante un'operazione lunga
let restoring = false;   // ripristino in corso: EMP si riavvia

function el(tag, text, cls) {
  const e = document.createElement(tag);
  if (text !== undefined && text !== null) e.textContent = text;
  if (cls) e.className = cls;
  return e;
}

function button(label, cls, onClick) {
  const b = el("button", label, cls);
  b.type = "button";
  b.addEventListener("click", onClick);
  return b;
}

function fmtDate(iso) {
  return iso ? EMP.fmtDateTime(new Date(iso)) : "—";
}

function fmtSize(bytes) {
  if (!bytes) return "—";
  const units = ["byte", "KB", "MB", "GB"];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  return (i === 0 ? n : n.toFixed(1)).toString().replace(".", ",") + " " + units[i];
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Invia una richiesta a EMP Server e ne attende l'esito (lancia un errore se non riesce). */
async function perform(path, body, timeoutMs = 120000) {
  const out = await EMP.api("backup/" + path, "POST", body || {});
  startPolling();
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    await sleep(1000);
    let r;
    try {
      r = await EMP.api("backup/richiesta/" + out.richiesta);
    } catch (e) {
      if (e.status === 401 || e.status === 403) throw e;
      continue; // EMP in riavvio (ripristino): si riprova
    }
    if (r.pronta) {
      if (!r.ok) throw new Error(r.messaggio);
      return r;
    }
  }
  throw new Error("Operazione ancora in corso: seguire l'avanzamento in questa pagina");
}

function setBusy(busy) {
  for (const b of document.querySelectorAll("main button")) b.disabled = busy;
}

// ---- Stato -------------------------------------------------------------------------------

async function load() {
  let data;
  try {
    data = await EMP.api("backup");
  } catch (e) {
    if (e.status === 401) {
      location.replace("index.html");
      return;
    }
    if (restoring) return; // EMP in riavvio
    $("non-disponibile").hidden = false;
    $("non-disponibile").textContent = e.message;
    return;
  }
  if (!data.disponibile) {
    $("non-disponibile").hidden = false;
    $("non-disponibile").textContent = data.messaggio;
    $("contenuto").hidden = true;
    return;
  }
  $("non-disponibile").hidden = true;
  $("contenuto").hidden = false;
  current = data;
  render(data);
}

function render(data) {
  const s = data.stato || { impostazioni: {}, destinazione: {}, locali: [], remoti: [] };
  const imp = s.impostazioni || {};
  const dest = s.destinazione || {};
  const locali = s.locali || [];
  const remoti = dest.collegata ? (s.remoti || []) : [];

  // Riepilogo
  const latest = [...locali, ...remoti].sort((x, y) => (x.data < y.data ? 1 : -1))[0];
  $("ultimo").textContent = latest ? fmtDate(latest.data) : "nessuno";
  $("ultimo-dettaglio").textContent = latest ? backupLabel(latest.nome) + " · " + ago(latest.data) : "Esegui il primo backup.";
  card("ultimo", latest && Date.now() - new Date(latest.data).getTime() < 36 * 3600 * 1000 ? "ok" : "ko");

  $("prossimo").textContent = imp.notturno ? fmtDate(imp.prossimo) : "disattivato";
  $("prossimo-dettaglio").textContent = imp.notturno ? "ogni notte alle " + imp.ora : "Attivalo in Impostazioni.";
  card("prossimo", imp.notturno ? "ok" : "ko");

  $("remota").textContent = dest.collegata ? shortDestination(dest) : "non collegata";
  $("remota-dettaglio").textContent = !dest.collegata
    ? "I backup restano solo su questo server."
    : (dest.raggiungibile ? "raggiungibile" + (dest.account ? " · " + dest.account : "") : "NON raggiungibile ora");
  card("remota", dest.collegata && dest.raggiungibile ? "ok" : "ko");

  const encrypted = locali.filter((x) => x.cifrato).length;
  const plain = locali.filter((x) => x.cifrato === false).length;
  $("cifratura").textContent = plain === 0 ? "attiva" : "attiva, " + plain + " da cifrare";
  $("cifratura-dettaglio").textContent = "Una sola chiave per tutti i backup" + (locali.length ? " · " + encrypted + " su " + locali.length + " cifrati qui" : "");
  card("cifratura", plain === 0 ? "ok" : "ko");

  $("aggiornato").textContent = s.aggiornato
    ? "Dati del " + fmtDate(s.aggiornato) + (data.in_coda ? " · " + data.in_coda + " richieste in coda" : "")
    : "In attesa di EMP Server…";

  // Impostazioni: non sovrascrivere mentre l'utente le sta modificando.
  if (!$("impostazioni").contains(document.activeElement)) {
    $("notturno").checked = imp.notturno !== false;
    $("ora").value = imp.ora || "02:30";
    $("conserva-locali").value = imp.conserva_locali || 14;
    $("conserva-remoti").value = imp.conserva_remoti || 30;
  }

  // Destinazione
  renderDestDetails(dest);
  $("dest-azioni").hidden = !dest.collegata;
  $("ricollega").hidden = !(dest.collegata && dest.tipo === "gdrive");
  if ($("google-box").hidden && $("chiave-box").hidden) {
    $("collega").hidden = dest.collegata && !$("collega").dataset.cambia;
    $("annulla-collega").hidden = !dest.collegata;
  }
  // Senza destinazione collegata le impostazioni si aprono da sole: c'è da collegarla.
  if (!dest.collegata && !$("impostazioni-box").dataset.toccato) $("impostazioni-box").open = true;

  renderBackups(locali, remoti, dest);
  renderJob(data.lavoro);
}

function card(id, state) {
  $(id).closest(".scheda").className = "scheda " + state;
}

function shortDestination(dest) {
  if (dest.tipo === "gdrive") return "Google Drive";
  if (dest.tipo === "sftp") return "Server SFTP";
  if (dest.tipo === "locale") return "Cartella";
  return dest.descrizione || "collegata";
}

/** "3 ore fa", "ieri", "5 giorni fa". */
function ago(iso) {
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (min < 2) return "adesso";
  if (min < 60) return min + " minuti fa";
  const h = Math.round(min / 60);
  if (h < 24) return h + (h === 1 ? " ora fa" : " ore fa");
  const d = Math.round(h / 24);
  return d === 1 ? "ieri" : d + " giorni fa";
}

/** Tipo del backup dal nome del file (etichetta messa da EMP Server). */
function backupLabel(name) {
  const m = /^emp-backup-.+-\d{8}-\d{6}(?:-(.+))?\.tar$/.exec(name);
  const label = m && m[1] ? m[1] : "";
  if (label === "") return "Backup";
  if (label === "prima-del-ripristino") return "Prima di un ripristino";
  if (label === "prima-di-aggiornamenti-ubuntu") return "Prima degli aggiornamenti di Ubuntu";
  const v = /^prima-di-(.+)$/.exec(label);
  if (v) return "Prima dell'aggiornamento a " + v[1];
  return label;
}

// Account Google collegato, da quando e con quale client (mai il Client Secret).
function renderDestDetails(dest) {
  $("dest-attuale").textContent = dest.collegata
    ? dest.descrizione + (dest.raggiungibile ? " — raggiungibile" : " — NON raggiungibile ora")
    : "nessuna: i backup restano solo su questo server";
  const gdrive = dest.collegata && dest.tipo === "gdrive";
  $("dest-riga-account").hidden = !gdrive;
  $("dest-account").textContent = dest.account
    || (dest.account_errore ? "non letto — " + dest.account_errore + ". Usa \"Cambia account / Ricollega\"." : "non ancora letto: clicca Aggiorna");
  $("dest-riga-dal").hidden = !(dest.collegata && dest.dal);
  $("dest-dal").textContent = dest.dal ? fmtDate(dest.dal) : "";
  $("dest-riga-client").hidden = !gdrive;
  $("dest-client").textContent = dest.client_proprio ? "proprio (" + dest.client_proprio + ")" : "predefinito";
}

/** Un solo elenco: ogni backup una riga, con dove si trova (questo server, destinazione remota o entrambi). */
function renderBackups(locali, remoti, dest) {
  const body = $("elenco").querySelector("tbody");
  body.replaceChildren();
  const byName = new Map();
  for (const x of locali) byName.set(x.nome, { nome: x.nome, data: x.data, byte: x.byte, locale: true, remoto: false });
  for (const x of remoti) {
    const row = byName.get(x.nome) || { nome: x.nome, data: x.data, byte: x.byte, locale: false, remoto: false };
    row.remoto = true;
    byName.set(x.nome, row);
  }
  const rows = [...byName.values()].sort((x, y) => (x.data < y.data ? 1 : -1));
  if (rows.length === 0) {
    const tr = el("tr");
    const td = el("td", dest.collegata && !dest.raggiungibile ? "Nessun backup su questo server; destinazione remota non raggiungibile ora." : "Nessun backup.");
    td.colSpan = 5;
    tr.appendChild(td);
    body.appendChild(tr);
    return;
  }
  const remoteName = shortDestination(dest);
  for (const b of rows) {
    const tr = el("tr");
    tr.appendChild(el("td", fmtDate(b.data)));
    const type = el("td", backupLabel(b.nome));
    type.appendChild(el("small", b.nome));
    tr.appendChild(type);
    const where = el("td");
    if (b.locale) where.appendChild(el("span", "Server", "etichetta-dove"));
    if (b.remoto) where.appendChild(el("span", remoteName, "etichetta-dove"));
    tr.appendChild(where);
    tr.appendChild(el("td", fmtSize(b.byte)));
    // Si usa la copia su questo server se c'è (più veloce), altrimenti quella remota.
    const origin = b.locale ? "locale" : "remoto";
    const td = el("td");
    const reset = () => td.replaceChildren(
      button("Scarica", "secondario piccolo", () => askPassword(td, "Scarica il backup del " + fmtDate(b.data), false, (pw) => download(b, origin, pw), reset)),
      button("Ripristina", "pericolo piccolo", () => askPassword(td, "Ripristina il backup del " + fmtDate(b.data), true, (pw) => restore(b, origin, pw), reset)),
    );
    reset();
    tr.appendChild(td);
    body.appendChild(tr);
  }
}

function renderJob(job) {
  const box = $("lavoro");
  // Qui solo le operazioni dei backup: gli aggiornamenti di Ubuntu stanno nella pagina Sistema.
  // Un'operazione finita si mostra per 10 minuti, poi sparisce.
  const recent = job && (!job.finito || Date.now() - new Date(job.finito).getTime() < 10 * 60000);
  if (!job || String(job.azione || "").startsWith("sistema_") || !recent) {
    box.hidden = true;
    return;
  }
  box.hidden = false;
  $("lavoro-titolo").textContent = job.titolo + " — " + fmtDate(job.iniziato);
  const running = !job.finito;
  $("lavoro-stato").textContent = running ? "in corso…" : (job.esito === 0 ? "completato" : (job.esito === 3 ? "completato, copia remota non riuscita" : "non riuscito"));
  $("lavoro-stato").className = "stato " + (running ? "" : (job.esito === 0 ? "installato" : "non_disponibile"));
  $("lavoro-dettagli").open = running || (job.esito !== 0 && job.esito !== null);
  const pre = $("lavoro-righe");
  pre.textContent = (job.righe || []).join("\n");
  pre.scrollTop = pre.scrollHeight;
  if (running) startPolling();
}

function startPolling() {
  if (polling) return;
  polling = setInterval(async () => {
    await load();
    const job = current && current.lavoro;
    if (!restoring && (!job || job.finito) && !(current && current.in_coda)) {
      clearInterval(polling);
      polling = null;
    }
  }, 2000);
}

// ---- Conferma con la password ---------------------------------------------------------------

/** Chiede la password (e per il ripristino la parola RIPRISTINA) dentro il contenitore. */
function askPassword(container, title, restoreWord, onOk, onCancel) {
  const form = el("form", null, "conferma-password");
  form.noValidate = true;
  form.appendChild(el("strong", title));
  const pw = el("input");
  pw.type = "password";
  pw.autocomplete = "current-password";
  pw.placeholder = "La tua password";
  form.appendChild(pw);
  let word = null;
  if (restoreWord) {
    word = el("input");
    word.autocomplete = "off";
    word.placeholder = "Scrivi RIPRISTINA";
    form.appendChild(word);
  }
  const ok = el("button", restoreWord ? "Conferma il ripristino" : "Conferma", restoreWord ? "conferma" : "");
  ok.type = "submit";
  form.append(ok, button("Annulla", "secondario", onCancel));
  form.addEventListener("submit", (ev) => {
    ev.preventDefault();
    if (!pw.value) return pw.focus();
    if (word && word.value.trim() !== "RIPRISTINA") {
      toast("Per confermare scrivere RIPRISTINA", true);
      return word.focus();
    }
    onOk(pw.value);
  });
  container.replaceChildren(form);
  container.hidden = false;
  pw.focus();
}

// ---- Azioni ---------------------------------------------------------------------------------

async function action(fn) {
  setBusy(true);
  try {
    await fn();
  } catch (e) {
    if (e.status === 401) {
      location.replace("index.html");
      return;
    }
    toast(e.message, true);
  } finally {
    setBusy(false);
    await load();
  }
}

$("esegui").addEventListener("click", () => action(async () => {
  toast("Backup avviato: può richiedere qualche minuto.");
  const r = await perform("esegui", {}, 3 * 3600 * 1000);
  toast(r.messaggio, r.messaggio.includes("NON"));
}));

$("aggiorna").addEventListener("click", () => action(async () => {
  await perform("aggiorna");
}));

$("impostazioni").addEventListener("submit", (ev) => {
  ev.preventDefault();
  action(async () => {
    const r = await perform("impostazioni", {
      notturno: $("notturno").checked,
      ora: $("ora").value,
      conserva_locali: parseInt($("conserva-locali").value, 10),
      conserva_remoti: parseInt($("conserva-remoti").value, 10),
    });
    document.activeElement.blur();
    toast(r.messaggio);
  });
});

async function download(b, origin, password) {
  await action(async () => {
    toast(origin === "remoto" ? "Download dalla destinazione remota e preparazione del file…" : "Preparazione del file…");
    const r = await perform("scarica", { nome: b.nome, origine: origin, password }, 3 * 3600 * 1000);
    // Il Core autorizza, Nginx consegna il file: la pagina resta aperta.
    location.href = "/api/v1/backup/file/" + encodeURIComponent(r.dati.token);
    toast("Download di " + r.dati.nome + " avviato (" + fmtSize(r.dati.byte) + ").");
  });
}

async function restore(b, origin, password) {
  setBusy(true);
  restoring = true;
  toast("Ripristino avviato: EMP verrà riavviato. Non chiudere la pagina.");
  try {
    const r = await perform("ripristina", { nome: b.nome, origine: origin, password, conferma: "RIPRISTINA" }, 6 * 3600 * 1000);
    toast(r.messaggio);
  } catch (e) {
    // Dopo il ripristino la sessione di prima di solito non esiste più: si accede di nuovo.
    if (e.status === 401) {
      restoring = false;
      alertAndLogin();
      return;
    }
    toast(e.message, true);
  }
  restoring = false;
  setBusy(false);
  await load();
}

function alertAndLogin() {
  $("contenuto").hidden = true;
  const box = $("non-disponibile");
  box.hidden = false;
  box.className = "box";
  box.replaceChildren(
    el("p", "Ripristino eseguito: EMP è tornato allo stato del backup, comprese le password di allora."),
    Object.assign(el("a", "Accedi di nuovo"), { href: "index.html" }),
  );
}

// ---- Destinazione remota ----------------------------------------------------------------------

function showType() {
  const type = $("collega").querySelector("input[name=tipo]:checked").value;
  for (const e of $("collega").querySelectorAll("[data-tipo]")) e.hidden = e.dataset.tipo !== type;
}
for (const r of document.querySelectorAll("#collega input[name=tipo]")) r.addEventListener("change", showType);

$("impostazioni-box").addEventListener("toggle", () => { $("impostazioni-box").dataset.toccato = "1"; });

$("cambia-dest").addEventListener("click", () => {
  $("collega").dataset.cambia = "1";
  $("collega").hidden = false;
  $("annulla-collega").hidden = false;
  clearAutofilledKey();
});

// ---- Chiave dei backup esistente: niente riempimento automatico, formato controllato subito ----

const KEY_RE = /^EMP-BK(-[A-Z2-7]{6}){5}$/;
const KEY_PREFIX_RE = /^E(M(P(-(B(K(-[A-Z2-7-]{0,34})?)?)?)?)?)?$/;

/** Messaggio d'errore per la chiave scritta (null se vuota o valida). */
function keyProblem(value, complete) {
  if (value === "") return null;
  if (KEY_RE.test(value)) return null;
  if (!complete && KEY_PREFIX_RE.test(value)) return null; // si sta ancora scrivendo
  return "Formato non valido: la chiave è come EMP-BK-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX (lettere A-Z e cifre 2-7). "
    + "Se non hai una chiave di un server precedente, lascia il campo vuoto.";
}

function checkKey(complete) {
  const input = $("chiave-esistente");
  const value = input.value.trim().toUpperCase().replace(/\s+/g, "");
  if (value !== input.value) input.value = value;
  const problem = keyProblem(value, complete);
  $("chiave-errore").textContent = problem || "";
  $("chiave-errore").hidden = !problem;
  input.setAttribute("aria-invalid", problem ? "true" : "false");
  return !problem;
}

// Se il browser ha messo qui un nome utente o altro, si svuota: la chiave si scrive sempre a mano.
function clearAutofilledKey() {
  const input = $("chiave-esistente");
  if (input.value && !KEY_PREFIX_RE.test(input.value.trim().toUpperCase())) input.value = "";
  checkKey(false);
}

$("chiave-esistente").addEventListener("input", () => checkKey(false));
$("chiave-esistente").addEventListener("blur", () => checkKey(true));
$("chiave-esistente").addEventListener("change", () => checkKey(true));
$("annulla-collega").addEventListener("click", () => {
  delete $("collega").dataset.cambia;
  $("collega").reset();
  showType();
  render(current);
});

$("collega").addEventListener("submit", (ev) => {
  ev.preventDefault();
  const f = ev.target.elements;
  const tipo = ev.target.querySelector("input[name=tipo]:checked").value;
  if (!checkKey(true)) {
    toast("Chiave dei backup esistente non valida", true);
    return f.chiave.focus();
  }
  if (!f.password.value) {
    toast("Inserire la tua password", true);
    return f.password.focus();
  }
  if (tipo === "gdrive" && (f.client_id.value.trim() !== "") !== (f.client_secret.value.trim() !== "")) {
    toast("Client Google proprio: servono sia il Client ID sia il Client Secret (oppure nessuno dei due)", true);
    return (f.client_id.value.trim() ? f.client_secret : f.client_id).focus();
  }
  action(async () => {
    const body = { tipo, password: f.password.value, chiave: f.chiave.value.trim() };
    if (tipo === "gdrive" && f.client_id.value.trim()) {
      Object.assign(body, { client_id: f.client_id.value.trim(), client_secret: f.client_secret.value.trim() });
    }
    if (tipo === "sftp") Object.assign(body, { destinazione: f.destinazione.value.trim(), password_sftp: f.password_sftp.value });
    if (tipo === "cartella") body.percorso = f.percorso.value.trim();
    const r = await perform("destinazione", body, tipo === "gdrive" ? 60000 : 600000);
    f.password.value = "";
    f.password_sftp.value = "";
    f.client_secret.value = "";
    if (tipo === "gdrive") {
      showGoogle(r.dati.url);
      return;
    }
    connected(r);
  });
});

function showGoogle(url) {
  $("google-link").href = url;
  $("collega").hidden = true;
  $("google-box").hidden = false;
  $("google").reset();
}

// Cambia account Google o ricollega lo stesso (token scaduto, accesso revocato): resta la stessa
// chiave dei backup, così quelli già su Drive si aprono ancora; il client Google proprio resta.
$("ricollega").addEventListener("click", () => askPassword($("conferma-box"),
  "Cambia account / Ricollega Google Drive (resta la stessa chiave dei backup). Con lo stesso account si "
  + "ritrovano i backup già inviati; con un account diverso quelli vecchi restano sull'altro account.", false, (pw) => action(async () => {
    const r = await perform("destinazione", { tipo: "gdrive", password: pw, ricollega: true }, 60000);
    $("conferma-box").hidden = true;
    showGoogle(r.dati.url);
  }), () => { $("conferma-box").hidden = true; }));

$("google").addEventListener("submit", (ev) => {
  ev.preventDefault();
  const url = ev.target.elements.indirizzo.value.trim();
  action(async () => {
    const r = await perform("destinazione/google", { indirizzo: url }, 600000);
    $("google-box").hidden = true;
    connected(r);
  });
});
$("google-annulla").addEventListener("click", () => {
  $("google-box").hidden = true;
  render(current);
});

function connected(r) {
  delete $("collega").dataset.cambia;
  $("collega").reset();
  checkKey(false);
  showType();
  toast(r.messaggio);
  if (r.dati && r.dati.chiave) showKey(r.dati.chiave, true);
}

$("mostra-chiave").addEventListener("click", () => askPassword($("conferma-box"), "Mostra la chiave dei backup", false, (pw) => action(async () => {
  const r = await perform("chiave", { password: pw });
  $("conferma-box").hidden = true;
  showKey(r.dati.chiave, false);
}), () => { $("conferma-box").hidden = true; }));

$("scollega").addEventListener("click", () => askPassword($("conferma-box"), "Scollega la destinazione remota (i backup già inviati restano dove sono)", false, (pw) => action(async () => {
  const r = await perform("scollega", { password: pw });
  $("conferma-box").hidden = true;
  toast(r.messaggio);
}), () => { $("conferma-box").hidden = true; }));

/** Chiave dei backup: mostrata, scaricabile in TXT; se nuova, conferma obbligatoria di averla salvata. */
function showKey(key, isNew) {
  const box = $("chiave-box");
  const text = [
    "EMP — Edomea Marketplace",
    "Chiave dei backup",
    "",
    "Server: " + location.hostname,
    "Destinazione: " + ((current && current.stato && current.stato.destinazione && current.stato.destinazione.descrizione) || "—"),
    "Data: " + EMP.fmtDateTime(new Date()),
    "",
    key,
    "",
    "Serve ad aprire tutti i backup di questo server: quelli sul server, quelli scaricati",
    "e quelli sulla destinazione remota, anche da una nuova installazione",
    "(pagina Backup → Collega una destinazione → Chiave dei backup esistente).",
    "Senza questa chiave i backup NON si possono ripristinare. Conservarla offline.",
  ].join("\r\n");
  box.replaceChildren();
  box.hidden = false;
  box.appendChild(el("h4", "Chiave dei backup"));
  box.appendChild(el("p", key, "chiave"));
  box.appendChild(el("p", "Salvare la chiave fuori da questa macchina (gestore di password, foglio in cassaforte). "
    + "Senza la chiave i backup non si possono aprire; chi la possiede e ha un file di backup può leggere tutti i dati di EMP.", "avviso"));
  const dl = button("Scarica la chiave (TXT)", "", () => {
    EMP.downloadText("EMP-chiave-backup-" + location.hostname + ".txt", text);
    check.disabled = false;
  });
  const label = el("label", null, "conferma-custodia");
  const check = el("input");
  check.type = "checkbox";
  check.disabled = isNew;
  label.append(check, " Ho salvato la chiave dei backup in un luogo sicuro");
  const done = button("Chiudi", "secondario", () => {
    box.replaceChildren();
    box.hidden = true;
    render(current);
  });
  done.disabled = isNew;
  check.addEventListener("change", () => { done.disabled = !check.checked; });
  if (!isNew) label.hidden = true;
  box.append(dl, label, done);
}

// ---- Avvio ------------------------------------------------------------------------------------

EMP.api("accesso/stato").then((s) => {
  EMP.showUser(s.utente);
  if (s.utente) $("collega-utente").value = s.utente.username;
}).catch(() => {});
showType();
// Il browser può riempire i campi dopo il caricamento: si ricontrolla la chiave poco dopo.
setTimeout(clearAutofilledKey, 600);
load().then(() => {
  // All'apertura si rilegge lo stato da EMP Server (anche l'elenco della destinazione remota).
  if (current) EMP.api("backup/aggiorna", "POST", {}).then(startPolling).catch(() => {});
});
