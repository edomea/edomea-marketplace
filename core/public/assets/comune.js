"use strict";
/*
 * Funzioni comuni delle pagine di amministrazione del Core EMP.
 * Helper standard Edomea: api(), esc(), toast(), fmtDateTime().
 */

const EMP = {
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
  EMP.applyBrand();
  EMP.showVersion();
});
