"use strict";
/*
 * Pagina Plugin del Core EMP.
 * API: GET /api/v1/plugin, POST /api/v1/plugin/{id}/installa, POST /api/v1/plugin/{id}/disinstalla,
 *      POST /api/v1/plugin/carica, POST /api/v1/plugin/{id}/rimuovi-pacchetto,
 *      GET /api/v1/plugin/arrivi, POST /api/v1/plugin/arrivi/aggiungi|elimina,
 *      GET/POST /api/v1/plugin/github, GET /api/v1/plugin/github/release, POST /api/v1/plugin/github/importa
 * Helper standard Edomea: api(), esc(), toast().
 */

const STATI = {
  installato: "Installato",
  installabile: "Installabile",
  aggiornabile: "Aggiornamento disponibile",
  non_compatibile: "Non compatibile",
  non_disponibile: "Non nel catalogo",
};

// Helper comuni (comune.js). Funzione riservata all'Amministratore Tecnico.
const api = (path, method = "GET") => EMP.api(path, method);
const esc = EMP.esc;
const toast = EMP.toast;

function cell(text, small) {
  const td = document.createElement("td");
  td.textContent = text;
  if (small) {
    const s = document.createElement("small");
    s.textContent = small;
    td.appendChild(s);
  }
  return td;
}

function button(label, cls, onClick) {
  const b = document.createElement("button");
  b.type = "button";
  b.textContent = label;
  if (cls) b.className = cls;
  b.addEventListener("click", onClick);
  return b;
}

/** Conferma in pagina (niente finestre di dialogo del browser). */
function askConfirm(td, label, action, onCancel = () => load()) {
  td.replaceChildren(
    button("Conferma " + label.toLowerCase(), "conferma", action),
    button("Annulla", "secondario", onCancel),
  );
}

async function run(id, operation, td) {
  for (const b of document.querySelectorAll("button")) b.disabled = true;
  try {
    const out = await api("plugin/" + encodeURIComponent(id) + "/" + operation, "POST");
    toast(out.operazioni.join("\n"));
  } catch (e) {
    toast(e.message, true);
  } finally {
    await load();
  }
}

function actions(p) {
  const td = document.createElement("td");
  if (p.stato === "installabile" || p.stato === "aggiornabile") {
    const label = p.stato === "installabile" ? "Installa" : "Aggiorna";
    td.appendChild(button(label, "", () => askConfirm(td, label, () => run(p.id, "installa", td))));
  }
  if (p.versione_installata) {
    td.appendChild(button("Disinstalla", "secondario", () => askConfirm(td, "Disinstalla", () => run(p.id, "disinstalla", td))));
  }
  if (p.nel_catalogo_locale) {
    td.appendChild(button("Togli pacchetto", "secondario", () => askConfirm(td, "Togli pacchetto", () => run(p.id, "rimuovi-pacchetto", td))));
  }
  return td;
}

async function load() {
  const body = document.querySelector("#plugins tbody");
  try {
    const data = await api("plugin");
    document.getElementById("core-version").textContent = "Core " + (data.core || "non installato");
    document.getElementById("no-catalog").hidden = data.catalogo;
    const errBox = document.getElementById("errors");
    errBox.hidden = data.errori.length === 0;
    errBox.textContent = data.errori.join("\n");

    body.replaceChildren();
    if (data.plugin.length === 0) {
      const tr = document.createElement("tr");
      tr.appendChild(cell("Nessun plugin disponibile."));
      tr.firstChild.colSpan = 7;
      body.appendChild(tr);
      return;
    }
    for (const p of data.plugin) {
      const tr = document.createElement("tr");
      tr.appendChild(cell(p.nome + " (" + p.id + ")", p.descrizione));
      tr.appendChild(cell(p.versione_disponibile || "—"));
      tr.appendChild(cell(p.versione_installata || "—"));
      tr.appendChild(cell(Object.entries(p.dipendenze).map(([d, v]) => d + " " + v).join(", ") || "—"));
      tr.appendChild(cell(p.origine || "—"));
      const st = cell(STATI[p.stato] || p.stato, p.motivo || "");
      st.classList.add("stato", p.stato);
      tr.appendChild(st);
      tr.appendChild(actions(p));
      body.appendChild(tr);
    }
  } catch (e) {
    if (e.status === 401) {
      location.replace("index.html");
      return;
    }
    body.innerHTML = '<tr><td colspan="7">' + esc(e.message) + ' — <a href="index.html">Amministratore Tecnico</a></td></tr>';
  }
}

// ---- Caricamento manuale del pacchetto ZIP --------------------------------------------
document.getElementById("carica").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const file = document.getElementById("carica-file").files[0];
  if (!file) return;
  const headers = { Accept: "application/json", "X-EMP-Richiesta": "1", "Content-Type": "application/zip" };
  const sha = document.getElementById("carica-impronta").value.trim();
  if (sha) headers["X-EMP-Impronta"] = sha;
  const submit = ev.submitter || ev.target.querySelector("button");
  submit.disabled = true;
  try {
    const res = await fetch("/api/v1/plugin/carica", { method: "POST", headers, body: file, credentials: "same-origin" });
    let data = null;
    try { data = await res.json(); } catch (_) { /* risposta non JSON (es. file troppo grande per il server) */ }
    if (!res.ok) throw new Error((data && data.errore) || (res.status === 413 ? "Pacchetto troppo grande (massimo 64 MB)" : "Errore " + res.status));
    toast(data.operazioni.join("\n"));
    ev.target.reset();
  } catch (e) {
    toast(e.message, true);
  } finally {
    submit.disabled = false;
    await load();
  }
});

// ---- Pacchetti arrivati via scp -------------------------------------------------------------
async function loadArrivals() {
  const box = document.getElementById("arrivi-box");
  let data;
  try {
    data = await api("plugin/arrivi");
  } catch (e) {
    box.hidden = true;
    return;
  }
  box.hidden = false;
  document.getElementById("arrivi-cartella").textContent = data.cartella;
  const body = document.querySelector("#arrivi tbody");
  body.replaceChildren();
  if (data.file.length === 0) {
    const tr = document.createElement("tr");
    tr.appendChild(cell("Nessun pacchetto arrivato."));
    tr.firstChild.colSpan = 4;
    body.appendChild(tr);
    return;
  }
  for (const f of data.file) {
    const tr = document.createElement("tr");
    tr.appendChild(cell(f.nome, f.leggibile ? "" : "Non leggibile dal Core: ricaricarlo con permessi di lettura"));
    tr.appendChild(cell((f.dimensione / 1048576).toFixed(1) + " MB"));
    tr.appendChild(cell(EMP.fmtDateTime(new Date(f.arrivato_il))));
    const td = document.createElement("td");
    const act = (azione, etichetta, classe) => button(etichetta, classe, () => askConfirm(td, etichetta, async () => {
      try {
        const out = await EMP.api("plugin/arrivi/" + azione, "POST", { file: f.nome });
        toast(out.operazioni.join("\n"));
      } catch (e) {
        toast(e.message, true);
      }
      await Promise.all([load(), loadArrivals()]);
    }, () => loadArrivals()));
    if (f.leggibile) td.appendChild(act("aggiungi", "Aggiungi", ""));
    td.appendChild(act("elimina", "Elimina", "secondario"));
    tr.appendChild(td);
    body.appendChild(tr);
  }
}

// ---- Sorgente GitHub facoltativa ----------------------------------------------------------
async function loadGithub() {
  try {
    const g = await api("plugin/github");
    document.getElementById("github-attiva").checked = g.attiva;
    document.getElementById("github-repo").value = g.repository;
    document.getElementById("github-curl").hidden = g.curl;
    document.getElementById("github-release").hidden = !g.attiva;
    document.getElementById("github-modifica").textContent = g.modificata_il
      ? "Ultima modifica: " + EMP.fmtDateTime(new Date(g.modificata_il)) + " — " + g.modificata_da : "";
  } catch (e) {
    document.getElementById("github-box").hidden = true;
  }
}

document.getElementById("github").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  try {
    const out = await EMP.api("plugin/github", "POST", {
      attiva: document.getElementById("github-attiva").checked,
      repository: document.getElementById("github-repo").value.trim(),
    });
    toast(out.operazioni.join("\n"));
  } catch (e) {
    toast(e.message, true);
  }
  document.getElementById("release").hidden = true;
  await loadGithub();
});

document.getElementById("github-cerca").addEventListener("click", async (ev) => {
  const table = document.getElementById("release");
  const body = table.querySelector("tbody");
  ev.target.disabled = true;
  try {
    const data = await api("plugin/github/release");
    body.replaceChildren();
    if (data.release.length === 0) {
      const tr = document.createElement("tr");
      tr.appendChild(cell("Nessuna release pubblicata."));
      tr.firstChild.colSpan = 4;
      body.appendChild(tr);
    }
    for (const r of data.release) {
      const tr = document.createElement("tr");
      tr.appendChild(cell(r.nome, r.tag));
      tr.appendChild(cell(r.pubblicata_il ? EMP.fmtDateTime(new Date(r.pubblicata_il)) : "—"));
      tr.appendChild(cell(r.catalogo ? "Sì" : "No", r.catalogo ? "" : "La release non allega emp-packages.json"));
      const td = document.createElement("td");
      const importa = () => button("Importa", "", () => askConfirm(td, "Importa", async () => {
          for (const b of document.querySelectorAll("button")) b.disabled = true;
          try {
            const out = await EMP.api("plugin/github/importa", "POST", { tag: r.tag });
            toast(out.operazioni.join("\n"));
          } catch (e) {
            toast(e.message, true);
          } finally {
            for (const b of document.querySelectorAll("button")) b.disabled = false;
            await load();
          }
        }, () => td.replaceChildren(importa())));
      if (r.catalogo) td.appendChild(importa());
      tr.appendChild(td);
      body.appendChild(tr);
    }
    table.hidden = false;
  } catch (e) {
    toast(e.message, true);
  } finally {
    ev.target.disabled = false;
  }
});

EMP.api("accesso/stato").then((s) => EMP.showUser(s.utente)).catch(() => {});
load();
loadArrivals();
loadGithub();
