"use strict";
const invoke = window.__TAURI__.core.invoke;
const $ = (id) => document.getElementById(id);
const status = $("status"), report = $("report"), scan = $("scan"), save = $("save");
const clientBox = $("client-box"), serverUrl = $("server-url"), modulesBox = $("modules-box");
const planBtn = $("plan"), planTable = $("plan-table"), confirmBox = $("confirm-box");
const installBtn = $("install"), cancelBtn = $("cancel"), modulesStatus = $("modules-status");

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
  // La sezione moduli compare solo quando la modalità Server è salvata.
  modulesBox.hidden = !(mode === "server" && saved);
}

function renderPlan(steps) {
  const body = planTable.querySelector("tbody");
  body.replaceChildren();
  steps.forEach((s, i) => {
    const tr = document.createElement("tr");
    if (s.core) tr.className = "core";
    [String(i + 1), s.name + " (" + s.id + ")", s.version, s.installed_version || "—", ACTIONS[s.action] || s.action]
      .forEach((text) => { const td = document.createElement("td"); td.textContent = text; tr.appendChild(td); });
    body.appendChild(tr);
  });
  planTable.hidden = steps.length === 0;
  confirmBox.hidden = !steps.some((s) => s.action !== "invariato");
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
      ? "Modalità Server salvata. Puoi preparare l'installazione di Core e plugin."
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
  say(modulesStatus, "Verifica dei pacchetti in corso...");
  try {
    const steps = await invoke("module_plan", { selected: null });
    renderPlan(steps);
    say(modulesStatus, steps.every((s) => s.action === "invariato") ? "Tutto già installato." : "Pacchetti verificati. Controlla il piano e conferma.");
  } catch (e) {
    planTable.hidden = true;
    say(modulesStatus, String(e), true);
  } finally {
    planBtn.disabled = false;
  }
});

cancelBtn.addEventListener("click", () => { confirmBox.hidden = true; say(modulesStatus, "Installazione annullata."); });

installBtn.addEventListener("click", async () => {
  installBtn.disabled = true; planBtn.disabled = true;
  say(modulesStatus, "Installazione in corso...");
  try {
    const steps = await invoke("module_install", { selected: null });
    renderPlan(steps.map((s) => ({ ...s, installed_version: s.action === "invariato" ? s.version : s.installed_version })));
    confirmBox.hidden = true;
    say(modulesStatus, "File di Core e plugin installati. Il database verrà preparato dal Core quando l'ambiente PHP/MariaDB sarà installato (Macro 2).");
  } catch (e) {
    say(modulesStatus, "Installazione interrotta: " + String(e), true);
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
