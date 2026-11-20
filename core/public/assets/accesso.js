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
let jobRoles = [];      // ruoli di negozio (Cassiere, Magazziniere…)
let permCatalog = [];   // permessi dichiarati dai moduli avviati
let current = null;

function show(ids) {
  for (const id of ["login-box", "password-box", "links-box", "cc-box", "profile-box", "ws-box", "users-box", "roles-box"]) {
    if ($(id)) $(id).hidden = !ids.includes(id);
  }
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
  if ($("link-backup")) $("link-backup").hidden = current.ruolo !== "tecnico";
  if ($("link-sistema")) $("link-sistema").hidden = current.ruolo !== "tecnico";
  if ($("link-marchio")) $("link-marchio").hidden = current.ruolo !== "tecnico";
  show(["links-box", "cc-box", "profile-box"]);
  if (typeof window.caricaCentro === "function" && $("cc-box")) window.caricaCentro();
  await Promise.all([loadProfile(), loadUsers(), loadWorkstations()]);
}

// ---- Postazioni autorizzate ---------------------------------------------------------------------

const WS_STATI = { attiva: "Autorizzata", in_attesa: "Codice in attesa", revocata: "Revocata" };

async function loadWorkstations() {
  if (!current || (current.ruolo !== "tecnico" && current.ruolo !== "amministratore")) return;
  let data;
  try {
    data = await EMP.api("postazioni");
  } catch (e) {
    // Senza HTTPS la gestione delle postazioni non è disponibile: il riquadro resta nascosto.
    return;
  }
  $("ws-box").hidden = false;
  $("ws-obbligo-riga").hidden = !data.puo_cambiare_obbligo;
  $("ws-obbligo").checked = data.obbligatorie;
  $("ws-obbligo-stato").textContent = data.obbligatorie
    ? "Obbligo attivo: gli operatori entrano solo dalle postazioni autorizzate."
    : "Obbligo non attivo: gli operatori possono entrare da qualsiasi postazione.";
  const here = data.postazioni.find((p) => p.questa);
  $("ws-questa").textContent = here ? here.nome : "non autorizzata";
  const body = $("ws").querySelector("tbody");
  body.replaceChildren();
  for (const p of data.postazioni) {
    const tr = document.createElement("tr");
    const name = document.createElement("td");
    name.textContent = p.nome + (p.questa ? " (questa)" : "");
    const state = document.createElement("td");
    state.textContent = WS_STATI[p.stato] || p.stato;
    if (p.stato === "in_attesa") state.textContent += " fino alle " + fmtDb(p.codice_scade).slice(11);
    if (p.stato === "revocata") state.textContent += " il " + fmtDb(p.revocata_il);
    const used = document.createElement("td");
    used.textContent = p.ultimo_uso ? fmtDb(p.ultimo_uso) + " da " + p.ultimo_ip : "—";
    const actions = document.createElement("td");
    if (p.stato !== "revocata") {
      actions.append(
        action("Rinomina", "secondario", () => renameWorkstation(actions, p)),
        action("Revoca", "conferma", () => confirmIn(actions, "Revocare " + p.nome + "? Le sessioni aperte da lì si chiudono.",
          () => wsPost("postazioni/" + p.id + "/revoca"))),
      );
    }
    tr.append(name, state, used, actions);
    body.appendChild(tr);
  }
  if (data.postazioni.length === 0) {
    const tr = document.createElement("tr");
    const td = document.createElement("td");
    td.colSpan = 4;
    td.textContent = "Nessuna postazione autorizzata.";
    tr.appendChild(td);
    body.appendChild(tr);
  }
}

function renameWorkstation(td, p) {
  const input = Object.assign(document.createElement("input"), { value: p.nome, maxLength: 64, autocomplete: "off" });
  td.replaceChildren(input, action("Salva", "", () => wsPost("postazioni/" + p.id + "/rinomina", { nome: input.value })),
    action("Annulla", "secondario", () => loadWorkstations()));
  input.focus();
}

async function wsPost(path, body) {
  try {
    const out = await EMP.api(path, "POST", body || {});
    if (out.messaggio) EMP.toast(out.messaggio);
    await loadWorkstations();
    return out;
  } catch (e) {
    EMP.toast(e.message, true);
    await loadWorkstations();
    return null;
  }
}

$("ws-obbligo").addEventListener("change", (ev) => wsPost("postazioni/obbligo", { attivo: ev.target.checked }));

$("ws-new").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const mode = ev.submitter && ev.submitter.value === "questa" ? "questa" : "codice";
  const nome = ev.target.elements.nome.value;
  const out = await wsPost(mode === "questa" ? "postazioni/questa" : "postazioni/codice", { nome });
  if (!out) return;
  ev.target.reset();
  EMP.showVersion(true);
  if (mode === "codice") {
    const box = $("ws-codice");
    const strong = document.createElement("p");
    strong.className = "chiave";
    strong.textContent = out.codice;
    box.replaceChildren(
      Object.assign(document.createElement("h4"), { textContent: "Codice per " + nome }),
      strong,
      Object.assign(document.createElement("p"), {
        className: "note",
        textContent: "Sulla nuova postazione: apri " + location.origin + "/admin/ → \"Associa questa postazione\" e scrivi il codice. "
          + "Vale fino alle " + fmtDb(out.scade).slice(11) + ", una volta sola.",
      }),
      action("Chiudi", "secondario", () => { box.hidden = true; }),
    );
    box.hidden = false;
  }
});

$("show-pair").addEventListener("click", (ev) => {
  ev.preventDefault();
  $("pair-form").hidden = false;
  $("pair-form").elements.codice.focus();
});
$("pair-form").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  try {
    const out = await EMP.api("postazioni/associa", "POST", { codice: ev.target.elements.codice.value });
    ev.target.reset();
    ev.target.hidden = true;
    EMP.toast(out.messaggio);
    EMP.showVersion(true);
  } catch (e) {
    EMP.toast(e.message, true);
  }
});

// ---- Profilo dell'utente collegato ------------------------------------------------------------

/** "2026-11-20 09:42:00" (ora del Server) → "20/11/2026 09:42". */
function fmtDb(v) {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(v || "");
  return m ? m[3] + "/" + m[2] + "/" + m[1] + " " + m[4] + ":" + m[5] : "—";
}

async function loadProfile() {
  let p;
  try {
    p = (await EMP.api("accesso/profilo")).profilo;
  } catch (e) {
    EMP.toast(e.message, true);
    return;
  }
  $("pf-nome").textContent = p.nome;
  $("pf-username").textContent = p.username;
  $("pf-ruolo").textContent = p.ruolo_etichetta;
  $("pf-creato").textContent = fmtDb(p.creato_il) + " da " + p.creato_da;
  $("pf-modificato").textContent = fmtDb(p.modificato_il) + " da " + p.modificato_da;
  $("pf-password").textContent = fmtDb(p.password_cambiata_il);
  $("pf-sessioni").textContent = p.sessioni_aperte + (p.sessioni_aperte > 1 ? " (compresa questa)" : " (questa)");
  $("pf-accessi").replaceChildren(...(p.ultimi_accessi.length
    ? p.ultimi_accessi.map((a) => Object.assign(document.createElement("div"), { textContent: fmtDb(a.quando) + " da " + a.ip }))
    : [document.createTextNode("—")]));
  $("pf-falliti").textContent = String(p.tentativi_falliti);
  const f = $("profile-form");
  if (f.hidden) {
    f.elements.nome.value = p.nome;
    f.elements.username.value = p.username;
  }
}

$("pf-modifica").addEventListener("click", async () => {
  $("sessions-form").hidden = true;
  $("profile-form").hidden = false;
  await loadProfile();
  $("profile-form").elements.nome.focus();
});
$("pf-annulla").addEventListener("click", () => {
  $("profile-form").reset();
  $("profile-form").hidden = true;
  loadProfile();
});
$("profile-form").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = ev.target.elements;
  try {
    const out = await EMP.api("accesso/profilo", "POST", { nome: f.nome.value, username: f.username.value, password: f.password.value });
    ev.target.reset();
    ev.target.hidden = true;
    current = out.utente;
    EMP.showUser(current);
    EMP.toast(out.messaggio);
    await loadProfile();
  } catch (e) {
    f.password.value = "";
    EMP.toast(e.message, true);
  }
});
$("pf-chiudi").addEventListener("click", () => {
  $("profile-form").hidden = true;
  $("sessions-form").hidden = false;
  $("sessions-form").elements.password.focus();
});
$("pf-chiudi-annulla").addEventListener("click", () => {
  $("sessions-form").reset();
  $("sessions-form").hidden = true;
});
$("sessions-form").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  try {
    const out = await EMP.api("accesso/sessioni/chiudi-altre", "POST", { password: ev.target.elements.password.value });
    ev.target.reset();
    ev.target.hidden = true;
    EMP.toast(out.messaggio);
    await loadProfile();
  } catch (e) {
    ev.target.elements.password.value = "";
    EMP.toast(e.message, true);
  }
});

async function loadUsers() {
  const data = await EMP.api("utenti");
  const managed = data.gestibili || (data.gestibile ? [data.gestibile] : []);
  if (!managed.length) return;
  await loadRoles();
  $("users-box").hidden = false;
  $("users-title").textContent = managed.length > 1 ? "Amministratori e operatori" : "Operatori";
  $("new-title").textContent = managed.length > 1 ? "Nuovo utente" : "Nuovo operatore";
  $("new-livello-riga").hidden = managed.length < 2;
  if (managed.length < 2) $("new-livello").value = managed[0];
  renderRoleChoices($("new-ruoli"), []);
  $("new-ruoli").hidden = $("new-livello").value !== "operatore";
  const names = Object.fromEntries(jobRoles.map((r) => [r.codice, r.nome]));
  const body = $("users").querySelector("tbody");
  body.replaceChildren();
  for (const u of data.utenti.filter((x) => x.id !== current.id)) {
    const tr = document.createElement("tr");
    const who = document.createElement("td");
    who.textContent = u.nome + " (" + u.username + ")";
    const role = document.createElement("td");
    role.textContent = u.ruolo_etichetta;
    const jobs = document.createElement("td");
    jobs.textContent = u.ruolo === "operatore"
      ? ((u.ruoli || []).map((c) => names[c] || c).join(", ") || "nessun ruolo")
      : (u.ruolo === "amministratore" ? "tutti i permessi" : "—");
    const state = document.createElement("td");
    state.textContent = !u.attivo ? "Disattivato" : (u.cambio_password ? "Password temporanea" : "Attivo");
    const actions = document.createElement("td");
    actions.append(
      action("Modifica", "secondario", () => editUser(actions, u)),
      ...(u.ruolo === "operatore" ? [action("Ruoli", "secondario", () => editUserRoles(actions, u))] : []),
      action("Password temporanea", "secondario", () => askPassword(actions, u)),
      action("Revoca accessi", "secondario", () => confirmIn(actions, "Chiudere tutte le sessioni di " + u.username + "?", () => post("utenti/" + u.id + "/revoca"))),
      u.attivo
        ? action("Disattiva", "conferma", () => confirmIn(actions, "Disattivare " + u.username + "?", () => post("utenti/" + u.id + "/disattiva")))
        : action("Riattiva", "", () => post("utenti/" + u.id + "/attiva")),
    );
    tr.append(who, role, jobs, state, actions);
    body.appendChild(tr);
  }
}

// ---- Ruoli di negozio e permessi ---------------------------------------------------------------

async function loadRoles() {
  let data;
  try {
    data = await EMP.api("ruoli");
  } catch (e) {
    return;
  }
  jobRoles = data.ruoli;
  permCatalog = data.permessi;
  $("roles-box").hidden = false;
  const body = $("roles").querySelector("tbody");
  body.replaceChildren();
  for (const r of jobRoles) {
    const tr = document.createElement("tr");
    const name = document.createElement("td");
    name.textContent = r.nome;
    if (r.descrizione) name.appendChild(Object.assign(document.createElement("small"), { textContent: r.descrizione }));
    const perms = document.createElement("td");
    perms.textContent = r.permessi.length ? r.permessi.length + (r.permessi.length === 1 ? " permesso" : " permessi") : "nessuno";
    perms.title = r.permessi.join(", ");
    const users = document.createElement("td");
    users.textContent = String(r.utenti);
    const actions = document.createElement("td");
    actions.append(
      action("Modifica", "secondario", () => openRoleForm(r)),
      action("Elimina", "conferma", () => confirmIn(actions, "Eliminare il ruolo " + r.nome + "?", () => rolePost("ruoli/" + r.codice + "/elimina"))),
    );
    tr.append(name, perms, users, actions);
    body.appendChild(tr);
  }
}

/** Caselle dei ruoli (per la creazione di un operatore o la modifica dei suoi ruoli). */
function renderRoleChoices(container, selected) {
  const legend = container.querySelector("legend");
  container.replaceChildren(...(legend ? [legend] : []));
  for (const r of jobRoles) {
    const label = document.createElement("label");
    label.className = "spunta";
    const box = Object.assign(document.createElement("input"), { type: "checkbox", value: r.codice, checked: selected.includes(r.codice) });
    label.append(box, " " + r.nome);
    container.appendChild(label);
  }
}

const checkedValues = (container) => [...container.querySelectorAll("input[type=checkbox]:checked")].map((b) => b.value);

function editUserRoles(td, user) {
  const box = document.createElement("div");
  box.className = "scelta-ruoli";
  renderRoleChoices(box, user.ruoli || []);
  td.replaceChildren(box,
    action("Salva", "", () => post("utenti/" + user.id + "/ruoli", { ruoli: checkedValues(box) })),
    action("Annulla", "secondario", () => loadUsers()));
}

function openRoleForm(role) {
  const f = $("role-form");
  f.dataset.codice = role ? role.codice : "";
  $("role-form-title").textContent = role ? "Modifica il ruolo " + role.nome : "Nuovo ruolo";
  f.elements.nome.value = role ? role.nome : "";
  f.elements.descrizione.value = role ? role.descrizione : "";
  const box = $("role-perms");
  box.replaceChildren();
  if (!permCatalog.length && !(role && role.permessi.length)) {
    box.appendChild(Object.assign(document.createElement("p"), { className: "note",
      textContent: "Nessun permesso disponibile: arrivano con i plugin (Cassa, Magazzino…). Il ruolo si può creare ora e completare dopo." }));
  }
  // Permessi raggruppati per modulo; quelli assegnati di moduli non più avviati restano visibili.
  const groups = new Map();
  for (const p of permCatalog) {
    if (!groups.has(p.nome_modulo)) groups.set(p.nome_modulo, []);
    groups.get(p.nome_modulo).push(p.permesso);
  }
  const orphan = role ? role.permessi.filter((p) => !permCatalog.some((c) => c.permesso === p)) : [];
  if (orphan.length) groups.set("Moduli non avviati", orphan);
  for (const [module, perms] of groups) {
    const fs = document.createElement("fieldset");
    fs.className = "scelta-ruoli";
    fs.appendChild(Object.assign(document.createElement("legend"), { textContent: module }));
    for (const p of perms) {
      const label = document.createElement("label");
      label.className = "spunta";
      label.append(Object.assign(document.createElement("input"), { type: "checkbox", value: p, checked: !!role && role.permessi.includes(p) }), " " + p);
      fs.appendChild(label);
    }
    box.appendChild(fs);
  }
  f.hidden = false;
  f.elements.nome.focus();
}

async function rolePost(path, body) {
  try {
    const out = await EMP.api(path, "POST", body || {});
    EMP.toast(out.messaggio || "Fatto");
    $("role-form").hidden = true;
  } catch (e) {
    EMP.toast(e.message, true);
  }
  await loadUsers();
}

$("role-new").addEventListener("click", () => openRoleForm(null));
$("role-cancel").addEventListener("click", () => { $("role-form").hidden = true; });
$("role-form").addEventListener("submit", (ev) => {
  ev.preventDefault();
  const f = ev.target;
  const code = f.dataset.codice;
  rolePost(code ? "ruoli/" + code : "ruoli", {
    nome: f.elements.nome.value, descrizione: f.elements.descrizione.value, permessi: checkedValues($("role-perms")),
  });
});
$("new-livello").addEventListener("change", (ev) => { $("new-ruoli").hidden = ev.target.value !== "operatore"; });

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

/** Modifica in riga di nome e cognome e nome utente di un utente gestito. */
function editUser(td, user) {
  const nome = Object.assign(document.createElement("input"), { value: user.nome, placeholder: "Nome e cognome", autocomplete: "off" });
  const username = Object.assign(document.createElement("input"), { value: user.username, placeholder: "Nome utente", autocomplete: "off" });
  td.replaceChildren(nome, username,
    action("Salva", "", () => post("utenti/" + user.id + "/modifica", { nome: nome.value, username: username.value })),
    action("Annulla", "secondario", () => loadUsers()));
  nome.focus();
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
    const livello = $("new-livello").value;
    await EMP.api("utenti", "POST", {
      username: String(f.get("username")),
      nome: String(f.get("nome")),
      password: String(f.get("password")),
      ruolo: livello,
      ruoli: livello === "operatore" ? checkedValues($("new-ruoli")) : [],
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
