"use strict";
/*
 * Pagina Email del Core EMP (solo Amministratore Tecnico, solo HTTPS): posta in uscita SMTP.
 * API: GET/POST /api/v1/email, POST /api/v1/email/prova, POST /api/v1/email/rimuovi.
 * La password SMTP non torna mai dal server: lasciare il campo vuoto la conserva.
 */

const $ = (id) => document.getElementById(id);
const fmtDb = (v) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(v || "");
  return m ? m[3] + "/" + m[2] + "/" + m[1] + " " + m[4] + ":" + m[5] : "—";
};

function card(id, text, detail, state) {
  $(id).textContent = text;
  $(id + "-dettaglio").textContent = detail;
  $(id).closest(".scheda").className = "scheda " + state;
}

function render(c) {
  card("stato", c.attiva ? "attivo" : (c.host ? "non attivo" : "non configurato"),
    c.host ? c.mittente + " via " + c.host + ":" + c.porta : "EMP funziona anche senza email", c.attiva ? "ok" : "neutra");
  const t = c.ultimo_test;
  card("prova", t ? (t.esito === "ok" ? "riuscita" : "non riuscita") : "mai fatta",
    t ? fmtDb(t.quando) + " → " + t.a + (t.esito === "ok" ? "" : ": " + t.messaggio) : "Salva la configurazione e invia una prova.",
    t ? (t.esito === "ok" ? "ok" : "ko") : "neutra");
  const f = $("smtp").elements;
  if (!$("smtp").contains(document.activeElement)) {
    f.host.value = c.host;
    f.porta.value = c.porta || 587;
    f.sicurezza.value = c.sicurezza || "starttls";
    f.utente.value = c.utente;
    f.mittente.value = c.mittente;
    f.nome_mittente.value = c.nome_mittente;
    f.rispondi_a.value = c.rispondi_a;
    f.attiva.checked = c.attiva;
    f.password.value = "";
  }
  f.password.placeholder = c.password_impostata ? "•••••••• (salvata)" : "";
  $("password-nota").textContent = c.password_impostata ? "Lascia vuoto per mantenere quella salvata." : "";
  if (!$("prova-form").elements.a.value && c.mittente) $("prova-form").elements.a.value = c.mittente;
}

function failed(e) {
  if (e.status === 401) {
    location.replace("index.html");
    return;
  }
  EMP.toast(e.message, true);
}

async function load() {
  try {
    const stato = await EMP.api("accesso/stato");
    EMP.showUser(stato.utente);
    if (!stato.utente || stato.utente.ruolo !== "tecnico") {
      location.replace("index.html");
      return;
    }
    if (!stato.protetto) {
      $("non-disponibile").textContent = "Funzione disponibile solo con l'indirizzo https://";
      $("non-disponibile").hidden = false;
      return;
    }
    $("contenuto").hidden = false;
    render((await EMP.api("email")).email);
  } catch (e) {
    failed(e);
  }
}

// Porta suggerita quando si cambia il tipo di sicurezza.
$("smtp").elements.sicurezza.addEventListener("change", (ev) => {
  const p = $("smtp").elements.porta;
  if (ev.target.value === "ssl" && (p.value === "587" || p.value === "25")) p.value = "465";
  if (ev.target.value === "starttls" && (p.value === "465" || p.value === "25")) p.value = "587";
});

$("smtp").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = ev.target.elements;
  try {
    const out = await EMP.api("email", "POST", {
      attiva: f.attiva.checked, host: f.host.value, porta: Number(f.porta.value), sicurezza: f.sicurezza.value,
      utente: f.utente.value, password: f.password.value, mittente: f.mittente.value, nome_mittente: f.nome_mittente.value,
      rispondi_a: f.rispondi_a.value, password_tecnico: f.password_tecnico.value,
    });
    f.password_tecnico.value = "";
    f.password.value = "";
    document.activeElement.blur();
    render(out.email);
    EMP.toast(out.messaggio);
  } catch (e) {
    f.password_tecnico.value = "";
    failed(e);
  }
});

$("rimuovi").addEventListener("click", async () => {
  const f = $("smtp").elements;
  if (!f.password_tecnico.value) {
    EMP.toast("Scrivi la tua password di EMP per confermare", true);
    f.password_tecnico.focus();
    return;
  }
  try {
    const out = await EMP.api("email/rimuovi", "POST", { password_tecnico: f.password_tecnico.value });
    f.password_tecnico.value = "";
    $("smtp").reset();
    EMP.toast(out.messaggio);
    render((await EMP.api("email")).email);
  } catch (e) {
    f.password_tecnico.value = "";
    failed(e);
  }
});

$("prova-form").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const btn = ev.submitter;
  if (btn) btn.disabled = true;
  try {
    const out = await EMP.api("email/prova", "POST", { a: ev.target.elements.a.value });
    $("dialogo").textContent = (out.dialogo || []).join("\n");
    $("dialogo-box").hidden = false;
    EMP.toast(out.messaggio);
  } catch (e) {
    failed(e);
  } finally {
    if (btn) btn.disabled = false;
    render((await EMP.api("email")).email);
  }
});

load();
