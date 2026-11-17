"use strict";
/*
 * Accesso, cambio password e gestione degli utenti, per le due aree separate:
 * - /techadmin/  Amministratore Tecnico (gestisce gli amministratori, plugin, recupero);
 * - /admin/    Amministratore Operativo e utenti operativi.
 * Chi entra nell'area sbagliata viene portato in quella del proprio ruolo.
 */

const $ = (id) => document.getElementById(id);
const AREA = document.body.dataset.area;
const AREA_DEL_RUOLO = (ruolo) => (ruolo === "tecnico" ? "techadmin" : "admin");
const RUOLI = { amministratore: "Amministratore", operatore: "Operatore" };
let current = null;

function show(ids) {
  for (const id of ["login-box", "password-box", "links-box", "users-box"]) $(id).hidden = !ids.includes(id);
  $("logout").hidden = !current;
}

async function load() {
  let stato;
  try {
    stato = await EMP.api("accesso/stato");
  } catch (e) {
    EMP.toast(e.message, true);
    return;
  }
  if (!stato.inizializzato) {
    location.replace("/techadmin/setup.html");
    return;
  }
  current = stato.utente;
  if (current && AREA_DEL_RUOLO(current.ruolo) !== AREA) {
    location.replace("/" + AREA_DEL_RUOLO(current.ruolo) + "/");
    return;
  }
  EMP.showUser(current);
  if (!current) {
    show(["login-box"]);
    return;
  }
  if (current.cambio_password) {
    $("password-title").textContent = "Imposta la tua password";
    $("password-note").textContent = "Stai usando una password temporanea: sceglierne una personale per continuare.";
    show(["password-box"]);
    return;
  }
  if ($("link-plugin")) $("link-plugin").hidden = current.ruolo !== "tecnico";
  show(["links-box"]);
  await loadUsers();
}

async function loadUsers() {
  const data = await EMP.api("utenti");
  if (!data.gestibile) return;
  $("users-box").hidden = false;
  $("users-title").textContent = data.gestibile === "amministratore" ? "Amministratori" : "Operatori";
  $("new-title").textContent = "Nuovo " + RUOLI[data.gestibile].toLowerCase();
  $("new-user").dataset.ruolo = data.gestibile;
  const body = $("users").querySelector("tbody");
  body.replaceChildren();
  for (const u of data.utenti.filter((x) => x.id !== current.id)) {
    const tr = document.createElement("tr");
    const who = document.createElement("td");
    who.textContent = u.nome + " (" + u.username + ")";
    const role = document.createElement("td");
    role.textContent = u.ruolo_etichetta;
    const state = document.createElement("td");
    state.textContent = !u.attivo ? "Disattivato" : (u.cambio_password ? "Password temporanea" : "Attivo");
    const actions = document.createElement("td");
    actions.append(
      action("Password temporanea", "secondario", () => askPassword(actions, u)),
      action("Revoca accessi", "secondario", () => confirmIn(actions, "Chiudere tutte le sessioni di " + u.username + "?", () => post("utenti/" + u.id + "/revoca"))),
      u.attivo
        ? action("Disattiva", "conferma", () => confirmIn(actions, "Disattivare " + u.username + "?", () => post("utenti/" + u.id + "/disattiva")))
        : action("Riattiva", "", () => post("utenti/" + u.id + "/attiva")),
    );
    tr.append(who, role, state, actions);
    body.appendChild(tr);
  }
}

function action(label, cls, fn) {
  const b = document.createElement("button");
  b.type = "button";
  b.textContent = label;
  if (cls) b.className = cls;
  b.addEventListener("click", fn);
  return b;
}

/** Conferma in pagina (niente finestre di dialogo del browser). */
function confirmIn(td, question, fn) {
  const p = document.createElement("span");
  p.textContent = question + " ";
  td.replaceChildren(p, action("Conferma", "conferma", fn), action("Annulla", "secondario", () => loadUsers()));
}

function askPassword(td, user) {
  const input = document.createElement("input");
  input.type = "password";
  input.autocomplete = "new-password";
  input.placeholder = "Nuova password temporanea";
  td.replaceChildren(input, action("Imposta", "", () => post("utenti/" + user.id + "/password", { password: input.value })), action("Annulla", "secondario", () => loadUsers()));
  input.focus();
}

async function post(path, body) {
  try {
    const out = await EMP.api(path, "POST", body || {});
    EMP.toast(out.messaggio || "Fatto");
  } catch (e) {
    EMP.toast(e.message, true);
  }
  await loadUsers();
}

$("login").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = new FormData(ev.target);
  try {
    await EMP.api("accesso/login", "POST", { username: String(f.get("username")), password: String(f.get("password")) });
    ev.target.reset();
    await load();
  } catch (e) {
    EMP.toast(e.message, true);
  }
});

$("password").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = new FormData(ev.target);
  if (f.get("nuova") !== f.get("ripeti")) {
    EMP.toast("Le due password non coincidono", true);
    return;
  }
  try {
    const out = await EMP.api("accesso/password", "POST", { attuale: String(f.get("attuale")), nuova: String(f.get("nuova")) });
    ev.target.reset();
    EMP.toast(out.messaggio);
    await load();
  } catch (e) {
    EMP.toast(e.message, true);
  }
});

$("new-user").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = new FormData(ev.target);
  try {
    await EMP.api("utenti", "POST", {
      username: String(f.get("username")),
      nome: String(f.get("nome")),
      password: String(f.get("password")),
      ruolo: ev.target.dataset.ruolo,
    });
    ev.target.reset();
    EMP.toast("Utente creato. Dovrà cambiare la password al primo accesso.");
    await loadUsers();
  } catch (e) {
    EMP.toast(e.message, true);
  }
});

$("show-password").addEventListener("click", (ev) => {
  ev.preventDefault();
  $("password-title").textContent = "Cambia la tua password";
  $("password-note").textContent = "Le altre sessioni aperte con il tuo account verranno chiuse.";
  $("password-box").hidden = false;
});

$("logout").addEventListener("click", async () => {
  try { await EMP.api("accesso/logout", "POST"); } catch (_) { /* già uscito */ }
  location.reload();
});

load();
