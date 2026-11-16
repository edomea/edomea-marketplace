"use strict";
const invoke = window.__TAURI__.core.invoke;
const $ = (id) => document.getElementById(id);
const status = $("status"), report = $("report"), scan = $("scan"), save = $("save");
const clientBox = $("client-box"), serverUrl = $("server-url"), coreBox = $("core-box");
const planBtn = $("plan"), planTable = $("plan-table"), confirmBox = $("confirm-box");
const installBtn = $("install"), cancelBtn = $("cancel"), coreStatus = $("core-status");

const ACTIONS = { installa: "Installa", aggiorna: "Aggiorna", invariato: "Già installato" };

function say(el, text, isError) {
  el.textContent = text;
  el.classList.toggle("err", !!isError);
}

function selectedMode() {
  const m = document.querySelector("input[name=mode]:checked");
  return m ? m.value : "";
}

function applyMode(mode, saved) {
  clientBox.hidden = mode !== "client";
  // La sezione Core compare solo quando la modalità Server è salvata.
  coreBox.hidden = !(mode === "server" && saved);
}

function renderPlan(step) {
  const body = planTable.querySelector("tbody");
  body.replaceChildren();
  const tr = document.createElement("tr");
  [step.name, step.version, step.installed_version || "—", ACTIONS[step.action] || step.action]
    .forEach((text) => { const td = document.createElement("td"); td.textContent = text; tr.appendChild(td); });
  body.appendChild(tr);
  planTable.hidden = false;
  confirmBox.hidden = step.action === "invariato";
}

async function refresh() {
  scan.disabled = true;
  report.textContent = "Diagnostica in corso...";
  try {
    const r = await invoke("diagnostics");
    report.textContent = [
      r.os, r.arch, r.cpu, "CPU logiche: " + r.logical_cpus, "RAM MiB: " + r.ram_mib,
      ...r.disks.map((d) => d.mount + " — liberi " + d.free_mib + " MiB / " + d.total_mib),
      ...r.ports.map((p) => "TCP " + p.number + ": " + p.result),
      "Processi runtime rilevati: " + (r.processes.join(", ") || "nessuno"),
      "Controlli indicativi: non attestano compatibilità o stato dei servizi.",
    ].join("\n");
  } catch (e) {
    report.textContent = "Errore diagnostica: " + String(e);
  } finally {
    scan.disabled = false;
  }
}

for (const el of document.querySelectorAll("input[name=mode]")) {
  el.addEventListener("change", () => { applyMode(selectedMode(), false); say(status, ""); });
}

save.addEventListener("click", async () => {
  const mode = selectedMode();
  if (!mode) { say(status, "Seleziona una modalità.", true); return; }
  save.disabled = true;
  try {
    const s = await invoke("save_settings", { mode, serverUrl: mode === "client" ? serverUrl.value : null });
    if (s.server_url) serverUrl.value = s.server_url;
    say(status, mode === "server"
      ? "Modalità Server salvata. Puoi installare il Core."
      : "Modalità Client salvata. La verifica della connessione TLS al Server arriverà con la Macro 3.");
    applyMode(mode, true);
  } catch (e) {
    say(status, String(e), true);
  } finally {
    save.disabled = false;
  }
});

planBtn.addEventListener("click", async () => {
  planBtn.disabled = true;
  confirmBox.hidden = true;
  say(coreStatus, "Verifica del pacchetto in corso...");
  try {
    const step = await invoke("core_plan");
    renderPlan(step);
    say(coreStatus, step.action === "invariato" ? "Il Core è già installato e aggiornato." : "Pacchetto verificato. Controlla e conferma.");
  } catch (e) {
    planTable.hidden = true;
    say(coreStatus, String(e), true);
  } finally {
    planBtn.disabled = false;
  }
});

cancelBtn.addEventListener("click", () => { confirmBox.hidden = true; say(coreStatus, "Installazione annullata."); });

installBtn.addEventListener("click", async () => {
  installBtn.disabled = true; planBtn.disabled = true;
  say(coreStatus, "Installazione del Core in corso...");
  try {
    const step = await invoke("core_install");
    renderPlan({ ...step, installed_version: step.version, action: "invariato" });
    say(coreStatus, "File del Core installati. Il database e la pagina web Plugin saranno disponibili quando l'ambiente PHP/MariaDB/Nginx sarà installato (Macro 2).");
  } catch (e) {
    say(coreStatus, "Installazione interrotta: " + String(e), true);
  } finally {
    installBtn.disabled = false; planBtn.disabled = false;
  }
});

invoke("load_settings").then((s) => {
  for (const el of document.querySelectorAll("input[name=mode]")) el.checked = el.value === s.mode;
  if (s.server_url) serverUrl.value = s.server_url;
  applyMode(s.mode, !!s.mode);
}).catch((e) => say(status, "Configurazione: " + String(e), true));

refresh();
