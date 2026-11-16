"use strict";
/*
 * Pagina Plugin del Core EMP.
 * API: GET /api/v1/plugin, POST /api/v1/plugin/{id}/installa, POST /api/v1/plugin/{id}/disinstalla
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
function askConfirm(td, label, action) {
  td.replaceChildren(
    button("Conferma " + label.toLowerCase(), "conferma", action),
    button("Annulla", "secondario", () => load()),
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
      tr.firstChild.colSpan = 6;
      body.appendChild(tr);
      return;
    }
    for (const p of data.plugin) {
      const tr = document.createElement("tr");
      tr.appendChild(cell(p.nome + " (" + p.id + ")", p.descrizione));
      tr.appendChild(cell(p.versione_disponibile || "—"));
      tr.appendChild(cell(p.versione_installata || "—"));
      tr.appendChild(cell(Object.entries(p.dipendenze).map(([d, v]) => d + " " + v).join(", ") || "—"));
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
    body.innerHTML = '<tr><td colspan="6">' + esc(e.message) + ' — <a href="index.html">Amministrazione</a></td></tr>';
  }
}

EMP.api("accesso/stato").then((s) => EMP.showUser(s.utente)).catch(() => {});
load();
