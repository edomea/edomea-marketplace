"use strict";
/*
 * Funzioni comuni delle pagine di amministrazione del Core EMP.
 * Helper standard Edomea: api(), esc(), toast(), fmtDateTime().
 */

const EMP = {
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
      "/amministrazione/recupero.html del computer Server. Dopo l'uso viene",
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
      + "Chi entra in possesso della chiave e accede al computer Server può prendere il controllo dell'installazione.";
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
