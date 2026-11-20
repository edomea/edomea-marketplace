"use strict";
/*
 * Control Center dell'Amministratore Tecnico: stato del server a colpo d'occhio.
 * Ogni scheda è verde (a posto), rossa (da guardare) o grigia (non disponibile) e porta alla sua pagina.
 * Dati: GET /api/v1/salute, /api/v1/backup (stato scritto da EMP Server: backup, sistema, server),
 *       /api/v1/plugin, /api/v1/postazioni, /api/v1/marchio. Richiamato da accesso.js dopo l'accesso.
 */

(() => {
  const $ = (id) => document.getElementById(id);
  let refreshed = false;

  const fmt = (iso) => (iso ? EMP.fmtDateTime(new Date(iso)) : "—");
  const gb = (bytes) => (bytes / 1073741824).toFixed(1).replace(".", ",") + " GB";
  const days = (iso) => Math.floor((new Date(iso).getTime() - Date.now()) / 86400000);

  function ago(iso) {
    const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (min < 2) return "adesso";
    if (min < 60) return min + " minuti fa";
    const h = Math.round(min / 60);
    if (h < 24) return h + (h === 1 ? " ora fa" : " ore fa");
    const d = Math.round(h / 24);
    return d === 1 ? "ieri" : d + " giorni fa";
  }

  /** Scheda: titolo, valore principale, dettaglio, stato (ok | ko | neutra), collegamento. */
  function card(title, value, detail, state, href) {
    const a = document.createElement(href ? "a" : "div");
    a.className = "scheda cc " + state;
    if (href) a.href = href;
    const t = document.createElement("span");
    t.className = "etichetta";
    t.textContent = title;
    const v = document.createElement("strong");
    v.textContent = value;
    const d = document.createElement("span");
    d.className = "note";
    d.textContent = detail;
    a.append(t, v, d);
    return a;
  }

  const safe = (p) => p.catch((e) => ({ errore: e }));

  async function load() {
    const [salute, backup, plugin, postazioni, marchio] = await Promise.all([
      safe(EMP.api("salute")), safe(EMP.api("backup")), safe(EMP.api("plugin")), safe(EMP.api("postazioni")), safe(EMP.api("marchio")),
    ]);
    const https = !(backup.errore && backup.errore.status === 403);
    $("cc-avviso").hidden = https;
    $("cc-avviso").textContent = https ? "" : "Aperto senza https://: il Control Center mostra solo una parte dei dati. Usa l'indirizzo https:// del server.";

    const st = backup.disponibile ? backup.stato || {} : {};
    const server = st.server || {};
    const sistema = st.sistema || {};
    const cards = [];

    // 1. EMP
    cards.push(card("EMP", salute.errore ? "non risponde" : "Server " + salute.server,
      salute.errore ? "" : "Core " + salute.core + (salute.avvio_sicuro ? " · AVVIO SICURO: plugin fermi" : ""),
      salute.errore || salute.avvio_sicuro ? "ko" : "ok", null));

    // 2. Servizi
    const services = server.servizi || [];
    const down = services.filter((s) => s.stato !== "active");
    cards.push(services.length
      ? card("Servizi", down.length ? down.length + " non attivi" : "tutti attivi",
        down.length ? down.map((s) => s.nome + ": " + s.stato).join(", ") : services.map((s) => s.nome.replace(/\.timer$/, "")).join(" · "),
        down.length ? "ko" : "ok", null)
      : card("Servizi", "—", "dati non disponibili", "neutra", null));

    // 3. Backup
    if (backup.disponibile) {
      const all = [...(st.locali || []), ...(st.remoti || [])].sort((x, y) => (x.data < y.data ? 1 : -1));
      const last = all[0];
      const fresh = last && Date.now() - new Date(last.data).getTime() < 36 * 3600000;
      const dest = st.destinazione || {};
      const plain = (st.locali || []).filter((x) => x.cifrato === false).length;
      cards.push(card("Backup", last ? ago(last.data) : "nessuno",
        (dest.collegata ? (dest.raggiungibile ? "copia remota OK" : "copia remota NON raggiungibile") : "nessuna copia remota")
          + " · " + (plain ? plain + " non cifrati" : "cifrati"),
        fresh && dest.collegata && dest.raggiungibile && !plain ? "ok" : "ko", "backup.html"));
    } else {
      cards.push(card("Backup", "—", https ? (backup.messaggio || "non disponibile") : "solo in HTTPS", "neutra", "backup.html"));
    }

    // 4. Aggiornamenti di Ubuntu
    if (sistema.ubuntu) {
      const pending = sistema.disponibili || 0;
      cards.push(card("Ubuntu", sistema.riavvio ? "riavvio necessario" : (pending ? pending + " aggiornamenti" : "aggiornato"),
        sistema.ubuntu + (sistema.sicurezza ? " · " + sistema.sicurezza + " di sicurezza" : "") + (sistema.verificato ? " · verificato " + ago(sistema.verificato) : ""),
        sistema.riavvio || sistema.sicurezza ? "ko" : "ok", "sistema.html"));
    } else {
      cards.push(card("Ubuntu", "—", "dati non disponibili", "neutra", "sistema.html"));
    }

    // 5. Certificato HTTPS
    if (server.certificato_scade) {
      const left = days(server.certificato_scade);
      cards.push(card("Certificato HTTPS", left + " giorni", "scade il " + fmt(server.certificato_scade).slice(0, 10) + " · si rinnova da solo",
        left > 30 ? "ok" : "ko", null));
    } else {
      cards.push(card("Certificato HTTPS", "—", "dati non disponibili", "neutra", null));
    }

    // 6. Spazio su disco e memoria
    if (server.disco_totale) {
      const freePct = Math.round((server.disco_libero / server.disco_totale) * 100);
      cards.push(card("Disco", gb(server.disco_libero) + " liberi", freePct + "% di " + gb(server.disco_totale)
        + (server.memoria_totale ? " · memoria libera " + gb(server.memoria_libera) + " di " + gb(server.memoria_totale) : ""),
        freePct >= 15 ? "ok" : "ko", null));
    } else {
      cards.push(card("Disco", "—", "dati non disponibili", "neutra", null));
    }

    // 7. Plugin
    if (!plugin.errore) {
      const installed = plugin.plugin.filter((p) => p.versione_installata);
      const stopped = installed.filter((p) => p.fermo);
      const updates = plugin.plugin.filter((p) => p.stato === "aggiornabile");
      cards.push(card("Plugin", installed.length + (installed.length === 1 ? " installato" : " installati"),
        (plugin.avvio_sicuro ? "AVVIO SICURO" : (stopped.length ? stopped.length + " fermi" : "tutti avviati")) + (updates.length ? " · " + updates.length + " da aggiornare" : ""),
        plugin.avvio_sicuro || stopped.length ? "ko" : "ok", "plugin.html"));
    } else {
      cards.push(card("Plugin", "—", "solo in HTTPS", "neutra", "plugin.html"));
    }

    // 8. Postazioni
    if (!postazioni.errore) {
      const active = postazioni.postazioni.filter((p) => p.stato === "attiva");
      cards.push(card("Postazioni", active.length + (active.length === 1 ? " autorizzata" : " autorizzate"),
        postazioni.obbligatorie ? "operatori solo da postazioni autorizzate" : "obbligo non attivo", "ok", "#ws-box"));
    } else {
      cards.push(card("Postazioni", "—", "solo in HTTPS", "neutra", null));
    }

    // 9. Marchio e macchina
    cards.push(card("Marchio", marchio.errore ? "—" : marchio.nome,
      (marchio.predefinito ? "predefinito · " : "") + (server.nome ? server.nome + (server.indirizzo ? " · " + server.indirizzo : "") : "")
        + (server.avviato ? " · acceso da " + ago(server.avviato).replace(" fa", "") : ""),
      "ok", "marchio.html"));

    $("cc-schede").replaceChildren(...cards);
    $("cc-aggiornato").textContent = st.aggiornato ? "Dati del " + fmt(st.aggiornato) : "";
  }

  /** All'apertura chiede a EMP Server dati freschi, poi rilegge. */
  async function refresh() {
    try {
      const out = await EMP.api("backup/aggiorna", "POST", {});
      for (let i = 0; i < 20; i++) {
        await new Promise((r) => setTimeout(r, 1000));
        const r = await EMP.api("backup/richiesta/" + out.richiesta);
        if (r.pronta) break;
      }
    } catch (_) { /* senza HTTPS o servizio non disponibile: restano i dati che ci sono */ }
    await load();
  }

  window.caricaCentro = async () => {
    await load();
    if (!refreshed) {
      refreshed = true;
      refresh();
    }
  };

  $("cc-aggiorna").addEventListener("click", async () => {
    $("cc-aggiorna").disabled = true;
    await refresh();
    $("cc-aggiorna").disabled = false;
  });
})();
