"use strict";
/*
 * Pagina Sistema del Core EMP: aggiornamenti dei pacchetti di Ubuntu (solo Amministratore Tecnico, solo HTTPS).
 * API: GET /api/v1/sistema, POST /api/v1/sistema/{verifica,aggiorna,riavvia}, GET /api/v1/backup/richiesta/{id}
 * Le operazioni le esegue EMP Server (servizio emp-backup-web): ogni POST restituisce una richiesta,
 * di cui si attende l'esito. Helper standard Edomea: EMP.api(), EMP.toast(), EMP.fmtDateTime().
 */

const $ = (id) => document.getElementById(id);
const toast = EMP.toast;
let current = null;
let polling = null;
let rebooting = false;

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

const fmtDate = (iso) => (iso ? EMP.fmtDateTime(new Date(iso)) : "—");
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Invia una richiesta a EMP Server e ne attende l'esito (lancia un errore se non riesce). */
async function perform(path, body, timeoutMs) {
  const out = await EMP.api("sistema/" + path, "POST", body || {});
  startPolling();
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    await sleep(1500);
    let r;
    try {
      r = await EMP.api("backup/richiesta/" + out.richiesta);
    } catch (e) {
      if (e.status === 401 || e.status === 403) throw e;
      continue; // servizi in riavvio durante l'aggiornamento: si riprova
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

async function load() {
  let data;
  try {
    data = await EMP.api("sistema");
  } catch (e) {
    if (e.status === 401) {
      location.replace("index.html");
      return;
    }
    if (rebooting) return;
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
  if (rebooting) {
    rebooting = false;
    toast("Il server è di nuovo disponibile.");
  }
  $("non-disponibile").hidden = true;
  $("contenuto").hidden = false;
  current = data;
  render(data);
}

function render(data) {
  const s = data.sistema;
  if (!s) {
    $("ubuntu").textContent = "in attesa di EMP Server…";
    return;
  }
  $("ubuntu").textContent = s.ubuntu || "—";
  $("kernel").textContent = s.kernel || "—";
  $("verificato").textContent = s.verificato ? fmtDate(s.verificato) : "mai: premere Verifica aggiornamenti";
  const n = s.verificato ? s.disponibili : null;
  $("disponibili").textContent = n === null ? "—" : n === 0 ? "nessuno: Ubuntu è aggiornato"
    : n + (s.sicurezza ? " (di sicurezza: " + s.sicurezza + ")" : "");
  $("disponibili").className = n ? "stato aggiornabile" : (n === 0 ? "stato installato" : "");
  $("riavvio").textContent = s.riavvio ? "sì" + (s.riavvio_per ? " — per: " + s.riavvio_per : "") : "no";
  $("riavvio").className = s.riavvio ? "stato aggiornabile" : "";
  $("automatici").textContent = s.automatici ? "attiva: gli aggiornamenti di sicurezza si installano anche da soli ogni notte" : "NON attiva";
  if ($("conferma").hidden) {
    $("installa").hidden = !n;
    $("riavvia").hidden = !s.riavvio;
  }

  const body = $("pacchetti").querySelector("tbody");
  body.replaceChildren();
  for (const p of s.pacchetti || []) {
    const tr = el("tr");
    tr.append(el("td", p.nome), el("td", p.installata), el("td", p.nuova), el("td", p.sicurezza ? "sicurezza" : "", p.sicurezza ? "stato aggiornabile" : ""));
    body.appendChild(tr);
  }
  $("pacchetti-box").hidden = !(s.pacchetti || []).length;
  renderJob(data.lavoro);
}

function renderJob(job) {
  if (!job) {
    $("lavoro").hidden = true;
    return;
  }
  $("lavoro").hidden = false;
  $("lavoro-titolo").textContent = job.titolo + " — " + fmtDate(job.iniziato);
  const running = !job.finito;
  $("lavoro-stato").textContent = running ? "in corso…" : (job.esito === 0 ? "completato" : "non riuscito");
  $("lavoro-stato").className = "stato " + (running ? "" : (job.esito === 0 ? "installato" : "non_disponibile"));
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
    if (!rebooting && (!job || job.finito) && !(current && current.in_coda)) {
      clearInterval(polling);
      polling = null;
    }
  }, 2000);
}

/** Password (e per il riavvio la parola RIAVVIA) nel riquadro di conferma. */
function askPassword(title, word, onOk) {
  const box = $("conferma");
  const close = () => { box.hidden = true; box.replaceChildren(); render(current); };
  const form = el("form", null, "conferma-password");
  form.noValidate = true;
  form.appendChild(el("strong", title));
  const pw = el("input");
  pw.type = "password";
  pw.autocomplete = "current-password";
  pw.placeholder = "La tua password";
  form.appendChild(pw);
  let w = null;
  if (word) {
    w = el("input");
    w.autocomplete = "off";
    w.placeholder = "Scrivi " + word;
    form.appendChild(w);
  }
  const ok = el("button", "Conferma", "conferma");
  ok.type = "submit";
  form.append(ok, button("Annulla", "secondario", close));
  form.addEventListener("submit", (ev) => {
    ev.preventDefault();
    if (!pw.value) return pw.focus();
    if (w && w.value.trim() !== word) {
      toast("Per confermare scrivere " + word, true);
      return w.focus();
    }
    const value = pw.value;
    close();
    onOk(value, word);
  });
  $("installa").hidden = true;
  $("riavvia").hidden = true;
  box.replaceChildren(form);
  box.hidden = false;
  pw.focus();
}

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

$("verifica").addEventListener("click", () => action(async () => {
  const r = await perform("verifica", {}, 600000);
  toast(r.messaggio);
}));

$("installa").addEventListener("click", () => askPassword("Installa gli aggiornamenti di Ubuntu (prima viene fatto un backup di EMP)", null, (pw) => action(async () => {
  const r = await perform("aggiorna", { password: pw }, 3600000);
  toast(r.messaggio);
})));

$("riavvia").addEventListener("click", () => askPassword("Riavvia il server: EMP non risponde per 1–2 minuti", "RIAVVIA", (pw, word) => action(async () => {
  const r = await perform("riavvia", { password: pw, conferma: word }, 120000);
  toast(r.messaggio);
  rebooting = true;
  startPolling();
})));

EMP.api("accesso/stato").then((s) => EMP.showUser(s.utente)).catch(() => {});
load();
