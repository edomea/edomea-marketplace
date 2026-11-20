"use strict";
/*
 * Funzioni comuni delle pagine di amministrazione del Core EMP.
 * Helper standard Edomea: api(), esc(), toast(), fmtDateTime().
 */

/*
 * Struttura delle pagine di amministrazione: intestazione con il marchio (clic = pagina iniziale),
 * menu a sinistra, contenuto a destra con il pulsante per tornare alla pagina iniziale.
 * Le pagine la attivano con <body data-shell="techadmin|admin" data-voce="…">.
 */
const EMP_MENU = {
  techadmin: {
    home: "/techadmin/",
    homeLabel: "Control Center",
    voci: [
      { id: "profilo", label: "Il tuo profilo", href: "/techadmin/#profilo" },
      { id: "utenti", label: "Amministratori e operatori", href: "/techadmin/#utenti" },
      { id: "postazioni", label: "Postazioni", href: "/techadmin/#postazioni" },
      { id: "ruoli", label: "Ruoli e permessi", href: "/techadmin/#ruoli" },
      { id: "backup", label: "Backup", href: "/techadmin/backup.html" },
      { id: "sistema", label: "Sistema", href: "/techadmin/sistema.html" },
      { id: "email", label: "Email (SMTP)", href: "/techadmin/email.html" },
      { id: "plugin", label: "Plugin", href: "/techadmin/plugin.html" },
      { id: "marchio", label: "Marchio", href: "/techadmin/marchio.html" },
    ],
  },
  admin: {
    home: "/admin/",
    homeLabel: "Operatori",
    voci: [
      { id: "profilo", label: "Il tuo profilo", href: "/admin/#profilo" },
      { id: "utenti", label: "Operatori", href: "/admin/#utenti", ruoli: ["amministratore"] },
      { id: "postazioni", label: "Postazioni", href: "/admin/#postazioni", ruoli: ["amministratore"] },
      { id: "ruoli", label: "Ruoli e permessi", href: "/admin/#ruoli", ruoli: ["amministratore"] },
    ],
  },
};

const EMP = {
  /** Costruisce intestazione cliccabile, menu laterale e pulsante "indietro" (una volta per pagina). */
  buildShell() {
    const area = document.body.dataset.shell;
    const menu = EMP_MENU[area];
    const main = document.querySelector("main");
    if (!menu || !main || document.querySelector(".emp-layout")) return;
    // Marchio e titolo nell'intestazione: portano alla pagina iniziale.
    const title = document.querySelector(".emp-header strong");
    if (title && !title.closest("a")) {
      const a = document.createElement("a");
      a.className = "emp-home";
      a.href = menu.home;
      a.title = menu.homeLabel;
      title.replaceWith(a);
      a.appendChild(title);
    }
    const layout = document.createElement("div");
    layout.className = "emp-layout";
    const nav = document.createElement("nav");
    nav.className = "emp-menu";
    nav.setAttribute("aria-label", "Menu");
    const ul = document.createElement("ul");
    for (const v of menu.voci) {
      const li = document.createElement("li");
      li.dataset.voce = v.id;
      if (v.ruoli) li.dataset.ruoli = v.ruoli.join(" ");
      const a = document.createElement("a");
      a.href = v.href;
      a.textContent = v.label;
      li.appendChild(a);
      ul.appendChild(li);
    }
    nav.appendChild(ul);
    main.before(layout);
    const content = document.createElement("div");
    content.className = "emp-contenuto";
    layout.append(nav, content);
    content.appendChild(main);
    const back = document.createElement("a");
    back.id = "emp-indietro";
    back.className = "emp-indietro";
    back.href = menu.home;
    back.textContent = "← " + menu.homeLabel;
    main.prepend(back);
    EMP.setVoce(document.body.dataset.voce || "");
  },

  /** Voce attiva del menu ("" = pagina iniziale: niente pulsante indietro). */
  setVoce(voce) {
    for (const li of document.querySelectorAll(".emp-menu li")) li.classList.toggle("attiva", li.dataset.voce === voce);
    const back = document.getElementById("emp-indietro");
    if (back) back.hidden = voce === "";
  },

  /** Mostra il menu (dopo l'accesso) con le sole voci del ruolo dell'utente; null = nasconde. */
  setMenuUser(ruolo) {
    document.body.classList.toggle("con-menu", !!ruolo);
    for (const li of document.querySelectorAll(".emp-menu li[data-ruoli]")) {
      li.hidden = !ruolo || !li.dataset.ruoli.split(" ").includes(ruolo);
    }
    if (ruolo) EMP.loadPluginMenu();
  },

  /** Pagine dei plugin che l'utente può aprire (secondo i permessi dei suoi ruoli), in fondo al menu. */
  async loadPluginMenu() {
    const ul = document.querySelector(".emp-menu ul");
    if (!ul || ul.dataset.plugin) return;
    ul.dataset.plugin = "1";
    let pages = [];
    try {
      pages = (await EMP.api("menu")).pagine;
    } catch (_) {
      return;
    }
    if (!pages.length) return;
    const sep = document.createElement("li");
    sep.className = "emp-menu-gruppo";
    sep.textContent = "Funzioni";
    ul.appendChild(sep);
    for (const p of pages) {
      const li = document.createElement("li");
      li.dataset.voce = p.modulo + ":" + p.id;
      const a = document.createElement("a");
      a.href = p.href;
      a.textContent = p.label;
      li.appendChild(a);
      ul.appendChild(li);
    }
    EMP.setVoce(document.body.dataset.voce || "");
  },

  /**
   * Avvio di una pagina di plugin: utente collegato (altrimenti alla pagina di accesso), menu e badge.
   * @returns {Promise<object|null>} l'utente, o null se la pagina sta per cambiare
   */
  async initPage() {
    let stato;
    try {
      stato = await EMP.api("accesso/stato");
    } catch (e) {
      EMP.toast(e.message, true);
      return null;
    }
    if (!stato.utente || stato.utente.cambio_password) {
      location.replace("/admin/");
      return null;
    }
    EMP.showUser(stato.utente);
    EMP.setMenuUser(stato.utente.ruolo);
    return stato.utente;
  },

  /** Versioni installate in fondo a ogni pagina (EMP Server e Core). */
  async showVersion(refresh = false) {
    let footer = document.querySelector("footer.emp-versione");
    if (!footer) {
      footer = document.createElement("footer");
      footer.className = "emp-versione";
      document.body.appendChild(footer);
    } else if (!refresh) {
      return;
    }
    try {
      const [s, p] = await Promise.all([EMP.api("salute"), EMP.api("postazioni/questa").catch(() => null)]);
      footer.textContent = (s.server ? "EMP Server " + s.server + " · " : "") + "Core " + s.core
        + (p && p.postazione ? " · Postazione: " + p.postazione.nome : "")
        + (s.avvio_sicuro ? " · AVVIO SICURO: plugin fermi (sudo emp-server avvio-sicuro --fine)" : "");
    } catch (_) {
      footer.textContent = "Versione non disponibile";
    }
  },

  /**
   * Marchio white label (nome, colore, logo) nell'intestazione e nel titolo della scheda.
   * Le pagine scrivono "EMP — …": "EMP" diventa il nome dell'attività.
   */
  async applyBrand(brand) {
    let b = brand;
    if (!b) {
      try {
        b = await EMP.api("marchio");
      } catch (_) {
        return;
      }
    }
    document.documentElement.style.setProperty("--emp-blu", b.colore);
    const title = document.querySelector(".emp-header strong");
    if (title) {
      if (!title.dataset.testo) title.dataset.testo = title.textContent.replace(/^EMP\b/, "");
      title.textContent = b.nome + title.dataset.testo;
      const old = document.querySelector(".emp-header img.emp-logo");
      if (old) old.remove();
      if (b.logo) {
        const img = document.createElement("img");
        img.className = "emp-logo";
        img.alt = "";
        img.src = "/api/v1/marchio/logo?v=" + encodeURIComponent(b.logo);
        title.before(img);
      }
    }
    if (!document.documentElement.dataset.titolo) document.documentElement.dataset.titolo = document.title;
    document.title = document.documentElement.dataset.titolo.replace(/\bEMP\b/, b.nome);
  },

  /** Chiamata alle API del Core. Le richieste che modificano portano X-EMP-Richiesta. */
  async api(path, method = "GET", body = null) {
    const opts = { method, headers: { Accept: "application/json" }, credentials: "same-origin" };
    if (method !== "GET") {
      opts.headers["X-EMP-Richiesta"] = "1";
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body || {});
    }
    const res = await fetch("/api/v1/" + path, opts);
    let data = null;
    try { data = await res.json(); } catch (_) { /* risposta non JSON */ }
    if (!res.ok) {
      const err = new Error((data && data.errore) || "Errore " + res.status);
      err.status = res.status;
      throw err;
    }
    return data;
  },

  esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  },

  toast(message, isError) {
    const el = document.getElementById("toast");
    if (!el) return;
    el.textContent = message;
    el.classList.toggle("err", !!isError);
    el.hidden = false;
    clearTimeout(EMP._toastTimer);
    EMP._toastTimer = setTimeout(() => { el.hidden = true; }, isError ? 9000 : 5000);
  },

  /** Data e ora in formato gg/mm/aaaa HH:MM. */
  fmtDateTime(d) {
    return d.toLocaleDateString("it-IT", { day: "2-digit", month: "2-digit", year: "numeric" }) + " "
      + d.toLocaleTimeString("it-IT", { hour: "2-digit", minute: "2-digit" });
  },

  /** Badge del ruolo nell'intestazione: "Ruolo — Nome Utente". */
  showUser(user) {
    const el = document.getElementById("user-badge");
    if (el) el.textContent = user ? user.ruolo_etichetta + " — " + user.nome : "";
  },

  /** Scarica un file di testo generato nel browser (non passa dalla rete). */
  downloadText(filename, text) {
    const url = URL.createObjectURL(new Blob([text], { type: "text/plain;charset=utf-8" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  },

  /**
   * Mostra la Recovery Key una sola volta, con download TXT e conferma obbligatoria.
   * onDone viene chiamato solo dopo download e conferma.
   */
  showRecoveryKey(container, key, username, onDone) {
    const now = new Date();
    const text = [
      "EMP — Edomea Marketplace",
      "Recovery Key dell'Amministratore Tecnico",
      "",
      "Utente: " + username,
      "Generata il: " + EMP.fmtDateTime(now),
      "",
      key,
      "",
      "Serve a reimpostare la password dell'Amministratore Tecnico dalla pagina",
      "/techadmin/recupero.html di EMP (indirizzo https://). Dopo l'uso viene",
      "sostituita da una nuova chiave. Conservare questo file offline.",
    ].join("\r\n");
    container.replaceChildren();
    container.hidden = false;
    const h = document.createElement("h2");
    h.textContent = "Recovery Key";
    const code = document.createElement("p");
    code.className = "chiave";
    code.textContent = key;
    const warn = document.createElement("p");
    warn.className = "avviso";
    warn.textContent = "ATTENZIONE — La Recovery Key consente di reimpostare la password dell'Amministratore Tecnico. "
      + "Viene mostrata una sola volta e non può essere recuperata. Conservare il file in un luogo sicuro, preferibilmente offline. "
      + "Chi entra in possesso della chiave e raggiunge EMP dalla rete del negozio può prendere il controllo dell'installazione.";
    const dl = document.createElement("button");
    dl.type = "button";
    dl.textContent = "Scarica la Recovery Key (TXT)";
    const label = document.createElement("label");
    label.className = "conferma-custodia";
    const check = document.createElement("input");
    check.type = "checkbox";
    check.disabled = true;
    label.append(check, " Ho scaricato la Recovery Key e mi assumo la responsabilità della sua custodia");
    const done = document.createElement("button");
    done.type = "button";
    done.textContent = "Continua";
    done.disabled = true;
    dl.addEventListener("click", () => {
      EMP.downloadText("EMP-Recovery-Key-" + username + ".txt", text);
      check.disabled = false;
    });
    check.addEventListener("change", () => { done.disabled = !check.checked; });
    done.addEventListener("click", () => { container.replaceChildren(); container.hidden = true; onDone(); });
    container.append(h, code, warn, dl, label, done);
  },
};

document.addEventListener("DOMContentLoaded", () => {
  EMP.buildShell();
  // Pagine del solo Tecnico (Backup, Sistema…): il menu è sempre visibile.
  if (document.body.dataset.shell && !document.body.dataset.area) document.body.classList.add("con-menu");
  EMP.applyBrand();
  EMP.showVersion();
});
