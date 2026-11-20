"use strict";
/*
 * Pagina Marchio del Core EMP (solo Amministratore Tecnico, solo HTTPS): nome, colore e logo
 * dell'attività mostrati in tutte le pagine (software white label).
 * API: GET/POST /api/v1/marchio, POST /api/v1/marchio/logo (immagine nel corpo),
 *      POST /api/v1/marchio/logo/rimuovi, POST /api/v1/marchio/predefinito.
 */

const $ = (id) => document.getElementById(id);
let preview = null; // URL temporaneo dell'immagine scelta, prima del caricamento

function render(b) {
  $("anteprima").style.backgroundColor = b.colore;
  $("anteprima-nome").textContent = b.nome;
  const img = $("anteprima-logo");
  if (preview) {
    img.src = preview;
    img.hidden = false;
  } else if (b.logo) {
    img.src = "/api/v1/marchio/logo?v=" + encodeURIComponent(b.logo);
    img.hidden = false;
  } else {
    img.removeAttribute("src");
    img.hidden = true;
  }
  $("predefinito").hidden = !b.predefinito;
  const f = $("dati").elements;
  if (!$("dati").contains(document.activeElement)) {
    f.nome.value = b.nome;
    f.colore.value = b.colore;
  }
  $("rimuovi-logo").disabled = !b.logo;
}

function failed(e) {
  if (e.status === 401) {
    location.replace("index.html");
    return;
  }
  if (e.status === 403 && /HTTPS|https/.test(e.message)) {
    const box = $("non-disponibile");
    box.textContent = e.message;
    box.hidden = false;
    $("contenuto").hidden = true;
    return;
  }
  EMP.toast(e.message, true);
}

async function load() {
  try {
    // Verifica accesso e ruolo: la pagina è del Tecnico.
    const stato = await EMP.api("accesso/stato");
    EMP.showUser(stato.utente);
    if (!stato.utente || stato.utente.ruolo !== "tecnico") {
      location.replace("index.html");
      return;
    }
    if (!stato.protetto) {
      const box = $("non-disponibile");
      box.textContent = "Funzione disponibile solo con l'indirizzo https://";
      box.hidden = false;
      return;
    }
    $("contenuto").hidden = false;
    render(await EMP.api("marchio"));
  } catch (e) {
    failed(e);
  }
}

async function done(out, message) {
  preview = null;
  render(out.marchio);
  await EMP.applyBrand(out.marchio);
  EMP.toast(message);
}

// Anteprima del colore mentre si sceglie.
$("dati").elements.colore.addEventListener("input", (ev) => { $("anteprima").style.backgroundColor = ev.target.value; });
$("dati").elements.nome.addEventListener("input", (ev) => { $("anteprima-nome").textContent = ev.target.value; });

$("dati").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = ev.target.elements;
  try {
    await done(await EMP.api("marchio", "POST", { nome: f.nome.value, colore: f.colore.value }), "Marchio salvato.");
  } catch (e) {
    failed(e);
  }
});

$("logo").elements.file.addEventListener("change", (ev) => {
  const file = ev.target.files[0];
  if (preview) URL.revokeObjectURL(preview);
  preview = file ? URL.createObjectURL(file) : null;
  if (preview) {
    $("anteprima-logo").src = preview;
    $("anteprima-logo").hidden = false;
  }
});

$("logo").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const file = ev.target.elements.file.files[0];
  if (!file) return;
  if (file.size > 524288) {
    EMP.toast("Logo troppo grande (massimo 512 KB)", true);
    return;
  }
  try {
    const res = await fetch("/api/v1/marchio/logo", {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-EMP-Richiesta": "1", "Content-Type": file.type || "application/octet-stream" },
      body: file,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.errore || "Caricamento non riuscito"), { status: res.status });
    ev.target.reset();
    await done(data, "Logo caricato.");
  } catch (e) {
    failed(e);
  }
});

$("rimuovi-logo").addEventListener("click", async () => {
  try {
    await done(await EMP.api("marchio/logo/rimuovi", "POST"), "Logo tolto.");
  } catch (e) {
    failed(e);
  }
});

$("predefinito-btn").addEventListener("click", async () => {
  try {
    await done(await EMP.api("marchio/predefinito", "POST"), "Marchio predefinito ripristinato.");
  } catch (e) {
    failed(e);
  }
});

load();
