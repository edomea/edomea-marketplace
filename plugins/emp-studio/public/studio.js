"use strict";
/* EMP Studio — pagina del catalogo prodotti. Viste: #prodotti, #prodotto/<id>, #nuovo, #struttura. */
(() => {
  const $ = (id) => document.getElementById(id);
  const esc = (s) => EMP.esc(s == null ? "" : s);
  const S = { struttura: null, permessi: { modifica: false, configura: false }, scheda: null, timer: null, pagina: 1 };

  const TIPI_ATTR = { testo: "Testo", numero: "Numero", si_no: "Sì / No", elenco: "Elenco" };
  const ETICHETTE_STATO = { bozza: "Bozza", attivo: "Attivo", archiviato: "Archiviato" };

  async function avvio() {
    const user = await EMP.initPage();
    if (!user) return;
    try {
      [S.permessi, S.struttura] = await Promise.all([EMP.api("studio/permessi"), EMP.api("studio/struttura")]);
    } catch (e) {
      EMP.toast(e.message, true);
      return;
    }
    $("nuovo").hidden = !S.permessi.modifica;
    $("tab-struttura").hidden = !S.permessi.configura;
    $("tab-tabelle").hidden = !S.permessi.configura;
    riempiSelect();
    collega();
    window.addEventListener("hashchange", instrada);
    instrada();
  }

  // ------------------------------------------------------------------ navigazione
  function mostra(vista) {
    for (const v of ["prodotti", "scheda", "struttura", "tabelle"]) $("vista-" + v).hidden = v !== vista;
    for (const a of document.querySelectorAll("#schede-vista a")) {
      a.classList.toggle("attiva", a.dataset.vista === (vista === "scheda" ? "prodotti" : vista));
    }
    window.scrollTo(0, 0);
  }

  function instrada() {
    const h = location.hash.replace(/^#/, "");
    if (h.startsWith("prodotto/")) return apriScheda(h.slice(9));
    if (h === "nuovo" && S.permessi.modifica) return apriScheda(null);
    if (h === "struttura" && S.permessi.configura) { mostra("struttura"); return disegnaStruttura(); }
    if (h === "tabelle" && S.permessi.configura) { mostra("tabelle"); return disegnaTabelle(); }
    mostra("prodotti");
    caricaElenco();
  }

  // ------------------------------------------------------------------ elenco
  function opzioni(sel, voci, primo) {
    const corrente = sel.value;
    sel.innerHTML = primo !== undefined ? '<option value="">' + esc(primo) + "</option>" : "";
    for (const [v, t] of voci) sel.insertAdjacentHTML("beforeend", '<option value="' + esc(v) + '">' + esc(t) + "</option>");
    sel.value = corrente;
  }

  function riempiSelect() {
    const st = S.struttura;
    const cat = st.categorie.map((c) => [c.id, "  ".repeat(c.livello) + c.nome]);
    const marche = st.marche.map((m) => [m.id, m.nome]);
    const f = $("filtri");
    opzioni(f.categoria, cat, "Tutte le categorie");
    opzioni(f.marca, marche, "Tutte le marche");
    const s = $("scheda");
    opzioni(s.marca_id, marche, "—");
    opzioni(s.categoria_id, cat, "—");
    opzioni(s.famiglia_id, st.famiglie.map((x) => [x.id, x.nome]), "—");
    opzioni(s.iva, st.tabelle.iva.voci.map((v) => [v.codice, (v.codice === v.valore ? v.valore + "%" : v.codice + " (" + v.valore + "%)") + " — " + v.descrizione]));
    opzioni(s.um, st.tabelle.um.voci.map((v) => [v.codice, v.codice + " — " + v.descrizione]));
    $("elenco-caratteristiche").innerHTML = st.attributi.map((a) => '<option value="' + esc(a.nome) + '">').join("");
    opzioni($("nuova-categoria").parent_id, cat, "(principale)");
  }

  async function caricaElenco() {
    const f = $("filtri");
    const q = new URLSearchParams();
    for (const k of ["q", "categoria", "marca", "stato"]) if (f[k].value) q.set(k, f[k].value);
    if (f.incompleti.checked) q.set("incompleti", "1");
    q.set("pagina", String(S.pagina));
    try {
      const [r, sum] = await Promise.all([EMP.api("studio/prodotti?" + q), EMP.api("studio/riepilogo")]);
      $("riepilogo").innerHTML = [["Schede", sum.totale], ["Attive", sum.attivi], ["Bozze", sum.bozze], ["Complete", sum.completi]]
        .map(([t, n]) => '<div class="scheda"><span class="note">' + t + "</span><strong>" + n + "</strong></div>").join("");
      $("righe").innerHTML = r.prodotti.length ? r.prodotti.map(rigaProdotto).join("")
        : '<tr><td colspan="6" class="note">' + (sum.totale ? "Nessun prodotto con questi filtri." : "Il catalogo è vuoto: crea la prima scheda con «Nuova scheda».") + "</td></tr>";
      const pagine = Math.max(1, Math.ceil(r.totale / r.per_pagina));
      $("pagine").innerHTML = r.totale > r.per_pagina
        ? (S.pagina > 1 ? '<button type="button" class="secondario" data-pag="-1">← Precedenti</button>' : "")
          + '<span class="note">Pagina ' + S.pagina + " di " + pagine + " · " + r.totale + " prodotti</span>"
          + (S.pagina < pagine ? '<button type="button" class="secondario" data-pag="1">Successivi →</button>' : "")
        : '<span class="note">' + r.totale + " prodotti</span>";
      applicaStili();
    } catch (e) {
      EMP.toast(e.message, true);
    }
  }

  function barra(p) {
    return '<div class="barra ' + (p >= 100 ? "" : p >= 60 ? "media" : "bassa") + '" title="' + p + '%"><span data-larghezza="' + p + '"></span></div>';
  }

  function rigaProdotto(p) {
    const codici = [p.sku, p.ean, p.varianti ? p.varianti + (p.varianti === 1 ? " variante" : " varianti") : ""].filter(Boolean).join(" · ");
    return '<tr class="cliccabile" data-id="' + esc(p.id) + '">'
      + '<td class="miniatura">' + (p.foto ? '<img src="' + esc(p.foto) + '" alt="" loading="lazy">' : "<span></span>") + "</td>"
      + "<td><strong>" + esc(p.nome) + "</strong><small>" + esc(codici) + "</small></td>"
      + "<td>" + esc(p.marca || "—") + "</td><td>" + esc(p.categoria || "—") + "</td>"
      + '<td><span class="etichetta-stato ' + esc(p.stato) + '">' + ETICHETTE_STATO[p.stato] + "</span></td>"
      + "<td>" + barra(p.completezza) + '<small>' + p.completezza + "%</small></td></tr>";
  }

  /** La CSP (style-src 'self') non ammette attributi style nell'HTML: larghezze e rientri si applicano qui. */
  function applicaStili() {
    for (const el of document.querySelectorAll("[data-larghezza]")) { el.style.width = el.dataset.larghezza + "%"; el.removeAttribute("data-larghezza"); }
    for (const el of document.querySelectorAll("[data-rientro]")) { el.style.paddingLeft = el.dataset.rientro + "px"; el.removeAttribute("data-rientro"); }
  }

  // ------------------------------------------------------------------ scheda
  async function apriScheda(id) {
    mostra("scheda");
    let d = null;
    if (id) {
      try {
        d = await EMP.api("studio/prodotti/" + encodeURIComponent(id));
      } catch (e) {
        EMP.toast(e.message, true);
        location.hash = "#prodotti";
        return;
      }
    }
    S.scheda = d;
    const prima = (t) => (S.struttura.tabelle[t].voci[0] || {}).codice || "";
    const p = d ? d.prodotto : { tipo: "semplice", stato: "bozza", iva: prima("iva"), um: prima("um") };
    const f = $("scheda");
    f.reset();
    for (const k of ["tipo", "sku", "ean", "mpn", "nome", "marca_id", "categoria_id", "famiglia_id", "stato", "iva", "um",
      "descrizione_breve", "descrizione"]) {
      if (f[k]) f[k].value = p[k] == null ? "" : p[k];
    }
    f.tipo.disabled = !!d;
    $("riga-tipo").hidden = !!d && p.tipo === "variante";
    for (const el of f.querySelectorAll(".solo-codici")) el.hidden = p.tipo === "modello";
    $("titolo-scheda").textContent = d ? p.nome : "Nuova scheda";
    $("stato-scheda").className = "etichetta-stato " + p.stato;
    $("stato-scheda").textContent = d ? ETICHETTE_STATO[p.stato] + (p.tipo === "variante" ? " · variante" : p.tipo === "modello" ? " · modello" : "") : "";
    $("ean-nota").textContent = d && p.ean_interno ? "EAN interno (circolazione ristretta)" : "";
    $("completezza-box").hidden = !d;
    if (d) {
      $("completezza-barra").style.width = p.completezza + "%";
      $("completezza-barra").parentElement.className = "barra " + (p.completezza >= 100 ? "" : p.completezza >= 60 ? "media" : "bassa");
      $("completezza-testo").textContent = "Completezza " + p.completezza + "%" + (d.mancanti.length ? " — manca: " + d.mancanti.join(", ") : " — scheda completa")
        + (d.modello ? " · variante di «" + d.modello.nome + "»" : "");
    }
    S.righe = d ? d.attributi.map((a) => ({ ...a, valore: d.valori[a.id] || "" })) : [];
    allineaFamiglia();
    disegnaAttributi();
    const sola = !S.permessi.modifica;
    $("aggiungi-caratteristica").hidden = sola;
    for (const el of f.elements) if (el.name) el.disabled = sola || (el.name === "tipo" && !!d);
    $("genera-ean").hidden = sola;
    $("azioni-scheda").hidden = sola;
    $("box-media").hidden = !d;
    $("carica-media").hidden = sola;
    $("zona-foto").hidden = !d;
    $("carica-foto").hidden = !d || sola;
    $("foto-dopo").hidden = !!d || sola;
    if (d) disegnaMedia(d.media);
    $("box-varianti").hidden = !d || p.tipo !== "modello";
    if (d && p.tipo === "modello") disegnaVarianti(d);
  }

  function famiglia(id) {
    return S.struttura.famiglie.find((f) => f.id === id) || null;
  }

  function attributo(id) {
    return S.struttura.attributi.find((a) => a.id === id) || null;
  }

  function campoAttributo(a, valore, obbligatorio) {
    const nome = "attr:" + a.id;
    const lab = esc(a.nome) + (a.unita ? " (" + esc(a.unita) + ")" : "") + (obbligatorio ? " *" : "");
    let input;
    if (a.tipo === "si_no") {
      input = '<select name="' + nome + '"><option value="">—</option><option value="si">Sì</option><option value="no">No</option></select>';
    } else if (a.tipo === "elenco") {
      input = '<select name="' + nome + '"><option value="">—</option>' + a.opzioni.map((o) => '<option value="' + esc(o) + '">' + esc(o) + "</option>").join("") + "</select>";
    } else {
      input = '<input name="' + nome + '"' + (a.tipo === "numero" ? ' inputmode="decimal"' : "") + ' maxlength="500" value="' + esc(valore || "") + '">';
    }
    return "<label>" + lab + " " + input + "</label>";
  }

  /* Caratteristiche della scheda: S.righe = [{id (null se nuova), nome, tipo, unita, opzioni, obbligatorio, famiglia, valore}]. */
  function leggiRighe() {
    for (const el of $("attributi").querySelectorAll("[data-riga]")) S.righe[Number(el.dataset.riga)].valore = el.value;
  }

  /** La famiglia è un modello di scheda: aggiunge le sue caratteristiche; quelle della famiglia precedente, se vuote, spariscono. */
  function allineaFamiglia() {
    const fam = famiglia($("scheda").famiglia_id.value);
    const della = new Map((fam ? fam.attributi : []).map((x) => [x.id, x.obbligatorio]));
    S.righe = S.righe.filter((r) => !r.famiglia || della.has(r.id) || r.valore !== "");
    for (const r of S.righe) {
      r.famiglia = della.has(r.id);
      r.obbligatorio = !!della.get(r.id);
    }
    for (const [id, obbl] of della) {
      const a = attributo(id);
      if (a && !S.righe.some((r) => r.id === id)) S.righe.push({ ...a, obbligatorio: obbl, famiglia: true, valore: "" });
    }
    $("famiglia-nome").textContent = fam ? "— modello «" + fam.nome + "» (* = obbligatoria)" : "";
  }

  function disegnaAttributi() {
    const sola = !S.permessi.modifica;
    $("attributi").innerHTML = S.righe.length ? S.righe.map((r, i) => {
      let input;
      const attr = ' data-riga="' + i + '"' + (sola ? " disabled" : "");
      if (r.tipo === "si_no") {
        input = "<select" + attr + '><option value="">—</option><option value="si">Sì</option><option value="no">No</option></select>';
      } else if (r.tipo === "elenco") {
        input = "<select" + attr + '><option value="">—</option>' + r.opzioni.map((o) => '<option value="' + esc(o) + '">' + esc(o) + "</option>").join("") + "</select>";
      } else {
        input = "<input" + attr + (r.tipo === "numero" ? ' inputmode="decimal"' : "") + ' maxlength="500" value="' + esc(r.valore) + '">';
      }
      return '<div class="riga-car"><span class="nome-car">' + esc(r.nome) + (r.unita ? " <small>(" + esc(r.unita) + ")</small>" : "") + (r.obbligatorio ? " *" : "")
        + (r.id ? "" : ' <small class="nuova">nuova</small>') + "</span>" + input
        + (sola || r.famiglia ? '<span class="spazio-togli"></span>' : '<button type="button" class="secondario piccolo" data-togli="' + i + '" title="Togli dalla scheda">✕</button>')
        + "</div>";
    }).join("") : '<p class="note">Nessuna caratteristica: aggiungi solo quelle che servono a questo prodotto.</p>';
    for (const el of $("attributi").querySelectorAll("select[data-riga]")) el.value = S.righe[Number(el.dataset.riga)].valore;
  }

  function aggiungiCaratteristica() {
    const nome = $("nuova-car-nome").value.trim();
    const valore = $("nuova-car-valore").value.trim();
    if (!nome) { $("nuova-car-nome").focus(); return; }
    leggiRighe();
    const a = S.struttura.attributi.find((x) => x.nome.toLowerCase() === nome.toLowerCase());
    const gia = S.righe.findIndex((r) => (a && r.id === a.id) || (!r.id && r.nome.toLowerCase() === nome.toLowerCase()));
    if (gia >= 0) {
      if (valore) S.righe[gia].valore = valore;
    } else {
      S.righe.push(a ? { ...a, obbligatorio: false, famiglia: false, valore } : { id: null, nome, tipo: "testo", unita: "", opzioni: [], obbligatorio: false, famiglia: false, valore });
    }
    disegnaAttributi();
    $("nuova-car-nome").value = "";
    $("nuova-car-valore").value = "";
    $("nuova-car-nome").focus();
  }

  function datiScheda() {
    const f = $("scheda");
    const out = { valori: {}, nuove: [] };
    for (const el of f.elements) {
      if (el.name && !el.name.startsWith("attr:")) out[el.name] = el.value;
    }
    leggiRighe();
    for (const r of S.righe) {
      if (r.id) out.valori[r.id] = r.valore;
      else if (r.valore !== "") out.nuove.push({ nome: r.nome, valore: r.valore });
    }
    return out;
  }

  async function salvaScheda(ev) {
    ev.preventDefault();
    const d = S.scheda;
    const btn = $("scheda").querySelector("button[type=submit]");
    btn.disabled = true;
    try {
      const r = await EMP.api("studio/prodotti" + (d ? "/" + encodeURIComponent(d.prodotto.id) : ""), "POST", datiScheda());
      EMP.toast(r.messaggio);
      if (!d) location.hash = "#prodotto/" + r.id;
      else apriScheda(d.prodotto.id);
    } catch (e) {
      EMP.toast(e.message, true);
    } finally {
      btn.disabled = false;
    }
  }

  function disegnaMedia(media) {
    const sola = !S.permessi.modifica;
    const foto = media.filter((m) => m.tipo === "foto");
    const [principale, ...galleria] = foto;
    $("foto-principale").innerHTML = principale
      ? '<a href="' + esc(principale.url) + '" target="_blank" rel="noopener noreferrer"><img src="' + esc(principale.url) + '" alt="Immagine principale"></a>'
        + '<p class="riga-azioni"><span class="note">Immagine principale</span>'
        + (sola ? "" : '<button type="button" class="pericolo piccolo" data-elimina-media="' + esc(principale.id) + '">Elimina</button>') + "</p>"
      : '<div class="vuota">Nessuna immagine principale</div>';
    $("galleria-conta").textContent = galleria.length ? "(" + galleria.length + ")" : "";
    $("galleria").innerHTML = galleria.length ? galleria.map((m) => '<figure><a href="' + esc(m.url) + '" target="_blank" rel="noopener noreferrer"><img src="' + esc(m.url) + '" alt="" loading="lazy"></a>'
      + (sola ? "" : '<p class="riga-azioni"><button type="button" class="secondario piccolo" data-principale="' + esc(m.id) + '" title="Diventa l\'immagine principale">★ Principale</button>'
        + '<button type="button" class="pericolo piccolo" data-elimina-media="' + esc(m.id) + '" title="Elimina">✕</button></p>') + "</figure>").join("")
      : '<p class="note">Nessuna foto aggiuntiva.</p>';
    const altri = media.filter((m) => m.tipo !== "foto");
    $("documenti").innerHTML = altri.length ? altri.map((m) => "<li><span>" + (m.tipo === "video" ? "▶ " : "📄 ")
      + '<a href="' + esc(m.url) + '" target="_blank" rel="noopener noreferrer">' + esc(m.titolo || (m.tipo === "video" ? "Video" : "Documento")) + "</a></span>"
      + (sola ? "" : '<button type="button" class="pericolo piccolo" data-elimina-media="' + esc(m.id) + '">Elimina</button>') + "</li>").join("")
      : '<li class="note">Nessun video o documento.</li>';
  }

  async function caricaFile(files, principale) {
    const id = S.scheda.prodotto.id;
    leggiRighe();
    for (const file of files) {
      try {
        const res = await fetch("/api/v1/studio/media?prodotto=" + encodeURIComponent(id) + "&titolo=" + encodeURIComponent(file.name.replace(/\.[^.]+$/, ""))
          + (principale ? "&principale=1" : ""), {
          method: "POST", credentials: "same-origin", body: file,
          headers: { "X-EMP-Richiesta": "1", "Content-Type": file.type || "application/octet-stream", Accept: "application/json" },
        });
        const data = await res.json().catch(() => null);
        if (!res.ok) throw new Error((data && data.errore) || "Errore " + res.status);
        EMP.toast(file.name + ": " + (data.messaggio || "caricato."));
      } catch (e) {
        EMP.toast(file.name + ": " + e.message, true);
      }
    }
    for (const i of ["file", "file-principale", "file-galleria"]) $(i).value = "";
    aggiornaMedia();
  }

  /** Ricarica solo foto e documenti, senza perdere le modifiche non salvate della scheda. */
  async function aggiornaMedia() {
    try {
      const d = await EMP.api("studio/prodotti/" + encodeURIComponent(S.scheda.prodotto.id));
      S.scheda.media = d.media;
      disegnaMedia(d.media);
      $("completezza-barra").style.width = d.prodotto.completezza + "%";
      $("completezza-testo").textContent = "Completezza " + d.prodotto.completezza + "%" + (d.mancanti.length ? " — manca: " + d.mancanti.join(", ") : " — scheda completa");
    } catch (e) {
      EMP.toast(e.message, true);
    }
  }

  function disegnaVarianti(d) {
    const fam = famiglia(d.prodotto.famiglia_id);
    const attrs = fam ? fam.attributi.map((x) => attributo(x.id)).filter(Boolean) : [];
    $("varianti-testa").innerHTML = "<tr><th>Variante</th><th>SKU · EAN</th>" + attrs.map((a) => "<th>" + esc(a.nome) + "</th>").join("") + "<th>Completezza</th></tr>";
    $("varianti").innerHTML = d.varianti.length ? d.varianti.map((v) => '<tr class="cliccabile" data-id="' + esc(v.id) + '"><td><strong>' + esc(v.nome) + "</strong></td>"
      + "<td>" + esc([v.sku, v.ean].filter(Boolean).join(" · ")) + "</td>" + attrs.map((a) => "<td>" + esc(v.valori[a.id] || "") + "</td>").join("")
      + "<td>" + barra(v.completezza) + "</td></tr>").join("")
      : '<tr><td colspan="' + (3 + attrs.length) + '" class="note">Nessuna variante. Aggiungine una qui sotto (es. un colore o una memoria).</td></tr>';
    applicaStili();
    $("nuova-variante").hidden = !S.permessi.modifica;
    // Le caratteristiche che distinguono le varianti si compilano nella scheda della variante.
    $("variante-campi").querySelectorAll("label.attr").forEach((el) => el.remove());
    for (const a of attrs.filter((x) => x.tipo === "elenco").slice(0, 3)) {
      $("variante-campi").insertAdjacentHTML("beforeend", campoAttributo(a, "", false).replace("<label>", '<label class="attr">'));
    }
  }

  async function nuovaVariante(ev) {
    ev.preventDefault();
    const f = ev.target;
    const body = { nome: f.nome.value, sku: f.sku.value, ean: f.ean.value, valori: {} };
    for (const el of f.querySelectorAll("[name^='attr:']")) body.valori[el.name.slice(5)] = el.value;
    try {
      const r = await EMP.api("studio/prodotti/" + encodeURIComponent(S.scheda.prodotto.id) + "/variante", "POST", body);
      EMP.toast(r.messaggio);
      f.reset();
      apriScheda(S.scheda.prodotto.id);
    } catch (e) {
      EMP.toast(e.message, true);
    }
  }

  // ------------------------------------------------------------------ struttura
  async function ricaricaStruttura() {
    S.struttura = await EMP.api("studio/struttura");
    riempiSelect();
    disegnaStruttura();
  }

  function pulsanti(tipo, id, nome, prodotti) {
    return '<button type="button" class="secondario piccolo" data-rinomina="' + tipo + '" data-id="' + esc(id) + '" data-nome="' + esc(nome) + '">Rinomina</button>'
      + (prodotti ? "" : '<button type="button" class="pericolo piccolo" data-elimina="' + tipo + '" data-id="' + esc(id) + '" data-nome="' + esc(nome) + '">Elimina</button>');
  }

  function disegnaStruttura() {
    const st = S.struttura;
    $("categorie").innerHTML = st.categorie.length ? st.categorie.map((c) => '<li data-rientro="' + (6 + c.livello * 18) + '"><span>' + esc(c.nome)
      + " <small>" + c.prodotti + "</small></span>" + pulsanti("categorie", c.id, c.nome, c.prodotti) + "</li>").join("") : '<li class="note">Nessuna categoria.</li>';
    $("marche").innerHTML = st.marche.length ? st.marche.map((m) => "<li><span>" + esc(m.nome) + " <small>" + m.prodotti + "</small></span>"
      + pulsanti("marche", m.id, m.nome, m.prodotti) + "</li>").join("") : '<li class="note">Nessuna marca.</li>';
    $("famiglie").innerHTML = st.famiglie.map((f) => "<tr><td><strong>" + esc(f.nome) + "</strong></td><td>"
      + f.attributi.map((x) => { const a = attributo(x.id); return a ? esc(a.nome) + (x.obbligatorio ? "*" : "") : ""; }).join(", ")
      + "</td><td>" + f.prodotti + '</td><td class="riga-azioni"><button type="button" class="secondario piccolo" data-famiglia="' + esc(f.id) + '">Modifica</button>'
      + (f.prodotti ? "" : '<button type="button" class="pericolo piccolo" data-elimina="famiglie" data-id="' + esc(f.id) + '" data-nome="' + esc(f.nome) + '">Elimina</button>')
      + "</td></tr>").join("");
    $("attributi-elenco").innerHTML = st.attributi.map((a) => "<tr><td>" + esc(a.nome) + (a.tipo === "elenco" ? "<small>" + esc(a.opzioni.join(", ")) + "</small>" : "")
      + "</td><td>" + TIPI_ATTR[a.tipo] + "</td><td>" + esc(a.unita || "") + "</td><td>" + a.prodotti + '</td><td class="riga-azioni"><button type="button" class="secondario piccolo" data-attributo="' + esc(a.id) + '">Modifica</button>'
      + (a.prodotti ? "" : '<button type="button" class="pericolo piccolo" data-elimina="attributi" data-id="' + esc(a.id) + '" data-nome="' + esc(a.nome) + '">Elimina</button>') + "</td></tr>").join("");
    applicaStili();
  }

  function apriFamiglia(id) {
    const f = $("famiglia-form");
    const fam = id ? famiglia(id) : null;
    f.dataset.id = id || "";
    $("famiglia-titolo").textContent = fam ? "Modifica «" + fam.nome + "»" : "Nuova famiglia";
    f.nome.value = fam ? fam.nome : "";
    const scelte = new Map((fam ? fam.attributi : []).map((x) => [x.id, x.obbligatorio]));
    $("famiglia-attributi").innerHTML = S.struttura.attributi.map((a) => '<label><input type="checkbox" data-attr="' + esc(a.id) + '"' + (scelte.has(a.id) ? " checked" : "") + "> "
      + esc(a.nome) + ' <select data-obbl="' + esc(a.id) + '"><option value="0">facoltativa</option><option value="1"' + (scelte.get(a.id) ? " selected" : "") + ">obbligatoria</option></select></label>").join("");
    f.hidden = false;
    f.nome.focus();
  }

  async function salvaFamiglia(ev) {
    ev.preventDefault();
    const f = ev.target;
    const attributi = [...f.querySelectorAll("input[data-attr]:checked")].map((c) => ({
      id: c.dataset.attr, obbligatorio: f.querySelector('select[data-obbl="' + c.dataset.attr + '"]').value === "1",
    }));
    try {
      await EMP.api("studio/famiglie" + (f.dataset.id ? "/" + f.dataset.id : ""), "POST", { nome: f.nome.value, attributi });
      EMP.toast("Famiglia salvata.");
      f.hidden = true;
      await ricaricaStruttura();
    } catch (e) {
      EMP.toast(e.message, true);
    }
  }

  function apriAttributo(id) {
    const f = $("attributo-form");
    const a = id ? attributo(id) : null;
    f.dataset.id = id || "";
    $("attributo-titolo").textContent = a ? "Modifica «" + a.nome + "»" : "Nuova caratteristica";
    f.nome.value = a ? a.nome : "";
    f.tipo.value = a ? a.tipo : "testo";
    f.unita.value = a ? a.unita : "";
    f.opzioni.value = a ? a.opzioni.join(", ") : "";
    $("attributo-annulla").hidden = !a;
    f.scrollIntoView({ block: "center" });
  }

  async function salvaAttributo(ev) {
    ev.preventDefault();
    const f = ev.target;
    try {
      await EMP.api("studio/attributi" + (f.dataset.id ? "/" + f.dataset.id : ""), "POST", {
        nome: f.nome.value, tipo: f.tipo.value, unita: f.unita.value, opzioni: f.opzioni.value.split(",").map((s) => s.trim()).filter(Boolean),
      });
      EMP.toast("Caratteristica salvata.");
      apriAttributo(null);
      await ricaricaStruttura();
    } catch (e) {
      EMP.toast(e.message, true);
    }
  }

  // ------------------------------------------------------------------ tabelle di base (IVA, unità di misura…)
  function disegnaTabelle() {
    const t = S.struttura.tabelle;
    $("tabelle").innerHTML = Object.keys(t).map((k) => {
      const tab = t[k];
      return '<section class="box"><h3>' + esc(tab.nome) + '</h3><table class="elenco-prodotti"><thead><tr><th>Codice</th><th>Descrizione</th>'
        + (tab.valore ? "<th>" + esc(tab.valore) + "</th>" : "") + "<th>Prodotti</th><th></th></tr></thead><tbody>"
        + tab.voci.map((v) => "<tr><td><strong>" + esc(v.codice) + "</strong></td><td>" + esc(v.descrizione) + "</td>" + (tab.valore ? "<td>" + esc(v.valore) + "</td>" : "")
          + "<td>" + v.prodotti + '</td><td class="riga-azioni"><button type="button" class="secondario piccolo" data-voce-modifica="' + esc(k) + '" data-codice="' + esc(v.codice) + '">Modifica</button>'
          + (v.prodotti ? "" : '<button type="button" class="pericolo piccolo" data-voce-elimina="' + esc(k) + '" data-codice="' + esc(v.codice) + '">Elimina</button>') + "</td></tr>").join("")
        + '</tbody></table><form class="riga form-voce" data-tabella="' + esc(k) + '" novalidate><h4>Nuova voce</h4>'
        + '<input name="codice" placeholder="Codice' + (k === "iva" ? " (es. 22, N4)" : " (es. pz)") + '" maxlength="16" autocomplete="off">'
        + '<input name="descrizione" placeholder="Descrizione" maxlength="120">'
        + (tab.valore ? '<input name="valore" inputmode="decimal" placeholder="' + esc(tab.valore) + '">' : "")
        + '<button type="submit" class="secondario">Salva</button><button type="button" class="secondario" data-voce-annulla hidden>Annulla</button></form></section>';
    }).join("");
  }

  function collegaTabelle() {
    const box = $("tabelle");
    box.addEventListener("click", async (e) => {
      const m = e.target.closest("[data-voce-modifica]");
      const d = e.target.closest("[data-voce-elimina]");
      const a = e.target.closest("[data-voce-annulla]");
      if (m) {
        const v = S.struttura.tabelle[m.dataset.voceModifica].voci.find((x) => x.codice === m.dataset.codice);
        const f = box.querySelector('form[data-tabella="' + m.dataset.voceModifica + '"]');
        f.dataset.vecchio = v.codice;
        f.querySelector("h4").textContent = "Modifica «" + v.codice + "»";
        f.codice.value = v.codice;
        f.descrizione.value = v.descrizione;
        if (f.valore) f.valore.value = v.valore;
        f.querySelector("[data-voce-annulla]").hidden = false;
        f.descrizione.focus();
      } else if (a) {
        disegnaTabelle();
      } else if (d) {
        if (!confirm("Eliminare la voce «" + d.dataset.codice + "»?")) return;
        try {
          await EMP.api("studio/tabelle/" + d.dataset.voceElimina + "/" + encodeURIComponent(d.dataset.codice) + "/elimina", "POST");
          await ricaricaStruttura();
          disegnaTabelle();
        } catch (err) {
          EMP.toast(err.message, true);
        }
      }
    });
    box.addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target;
      const old = f.dataset.vecchio;
      try {
        const r = await EMP.api("studio/tabelle/" + f.dataset.tabella + (old ? "/" + encodeURIComponent(old) : ""), "POST",
          { codice: f.codice.value, descrizione: f.descrizione.value, valore: f.valore ? f.valore.value : "" });
        EMP.toast(r.messaggio);
        await ricaricaStruttura();
        disegnaTabelle();
      } catch (err) {
        EMP.toast(err.message, true);
      }
    });
  }

  // ------------------------------------------------------------------ eventi
  function collega() {
    collegaTabelle();
    const f = $("filtri");
    f.addEventListener("submit", (e) => e.preventDefault());
    f.q.addEventListener("keydown", async (e) => {
      // Lettore di codici a barre: digita il codice e preme Invio → apre direttamente la scheda.
      if (e.key !== "Enter") return;
      e.preventDefault();
      const v = f.q.value.trim();
      if (!/^\S+$/.test(v)) return;
      try {
        const r = await EMP.api("studio/cerca?codice=" + encodeURIComponent(v));
        if (r.id) location.hash = "#prodotto/" + r.id;
      } catch (_) { /* ricerca normale */ }
    });
    f.q.addEventListener("input", () => { clearTimeout(S.timer); S.timer = setTimeout(() => { S.pagina = 1; caricaElenco(); }, 300); });
    for (const el of [f.categoria, f.marca, f.stato, f.incompleti]) el.addEventListener("change", () => { S.pagina = 1; caricaElenco(); });
    $("nuovo").addEventListener("click", () => { location.hash = "#nuovo"; });
    $("pagine").addEventListener("click", (e) => {
      const b = e.target.closest("[data-pag]");
      if (b) { S.pagina += Number(b.dataset.pag); caricaElenco(); }
    });
    for (const id of ["righe", "varianti"]) {
      $(id).addEventListener("click", (e) => {
        const tr = e.target.closest("tr[data-id]");
        if (tr) location.hash = "#prodotto/" + tr.dataset.id;
      });
    }

    const s = $("scheda");
    s.addEventListener("submit", salvaScheda);
    s.famiglia_id.addEventListener("change", () => { leggiRighe(); allineaFamiglia(); disegnaAttributi(); });
    $("nuova-car").addEventListener("click", aggiungiCaratteristica);
    for (const id of ["nuova-car-nome", "nuova-car-valore"]) {
      $(id).addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); aggiungiCaratteristica(); } });
    }
    $("attributi").addEventListener("click", (e) => {
      const b = e.target.closest("[data-togli]");
      if (!b) return;
      leggiRighe();
      S.righe.splice(Number(b.dataset.togli), 1);
      disegnaAttributi();
    });
    $("file-principale").addEventListener("change", (e) => { if (e.target.files.length) caricaFile([e.target.files[0]], true); });
    $("file-galleria").addEventListener("change", (e) => { if (e.target.files.length) caricaFile([...e.target.files], false); });
    const zona = $("box-foto");
    zona.addEventListener("dragover", (e) => { if (S.scheda && S.permessi.modifica) { e.preventDefault(); zona.classList.add("trascina"); } });
    zona.addEventListener("dragleave", () => zona.classList.remove("trascina"));
    zona.addEventListener("drop", (e) => {
      zona.classList.remove("trascina");
      if (!S.scheda || !S.permessi.modifica) return;
      e.preventDefault();
      const files = [...e.dataTransfer.files].filter((f) => /^image\//.test(f.type));
      if (files.length) caricaFile(files, false);
    });
    s.tipo.addEventListener("change", () => { for (const el of s.querySelectorAll(".solo-codici")) el.hidden = s.tipo.value === "modello"; });
    $("genera-ean").addEventListener("click", async () => {
      if (s.ean.value.trim() && !confirm("Sostituire l'EAN scritto con un nuovo EAN interno?")) return;
      try {
        s.ean.value = (await EMP.api("studio/ean-interno")).ean;
        $("ean-nota").textContent = "EAN interno (circolazione ristretta): si assegna salvando la scheda.";
      } catch (e) {
        EMP.toast(e.message, true);
      }
    });
    $("file").addEventListener("change", (e) => { if (e.target.files.length) caricaFile([...e.target.files], false); });
    $("video").addEventListener("submit", async (e) => {
      e.preventDefault();
      try {
        const r = await EMP.api("studio/video", "POST", { prodotto: S.scheda.prodotto.id, url: e.target.url.value, titolo: e.target.titolo.value });
        EMP.toast(r.messaggio);
        e.target.reset();
        aggiornaMedia();
      } catch (err) {
        EMP.toast(err.message, true);
      }
    });
    $("vista-scheda").addEventListener("click", async (e) => {
      const p = e.target.closest("[data-principale]");
      const d = e.target.closest("[data-elimina-media]");
      if (!p && !d) return;
      if (d && !confirm("Eliminare questo file dalla scheda?")) return;
      try {
        const r = await EMP.api("studio/media/" + (p ? p.dataset.principale + "/principale" : d.dataset.eliminaMedia + "/elimina"), "POST");
        EMP.toast(r.messaggio);
        aggiornaMedia();
      } catch (err) {
        EMP.toast(err.message, true);
      }
    });
    $("nuova-variante").addEventListener("submit", nuovaVariante);

    $("nuova-categoria").addEventListener("submit", async (e) => {
      e.preventDefault();
      try {
        await EMP.api("studio/categorie", "POST", { nome: e.target.nome.value, parent_id: e.target.parent_id.value });
        e.target.nome.value = "";
        await ricaricaStruttura();
      } catch (err) {
        EMP.toast(err.message, true);
      }
    });
    $("nuova-marca").addEventListener("submit", async (e) => {
      e.preventDefault();
      try {
        await EMP.api("studio/marche", "POST", { nome: e.target.nome.value });
        e.target.nome.value = "";
        await ricaricaStruttura();
      } catch (err) {
        EMP.toast(err.message, true);
      }
    });
    $("vista-struttura").addEventListener("click", async (e) => {
      const b = e.target.closest("button");
      if (!b) return;
      try {
        if (b.dataset.rinomina) {
          const nome = prompt("Nuovo nome:", b.dataset.nome);
          if (!nome || nome === b.dataset.nome) return;
          const body = { nome };
          if (b.dataset.rinomina === "categorie") {
            const c = S.struttura.categorie.find((x) => x.id === b.dataset.id);
            body.parent_id = c ? c.parent_id || "" : "";
          }
          await EMP.api("studio/" + b.dataset.rinomina + "/" + b.dataset.id, "POST", body);
        } else if (b.dataset.elimina) {
          if (!confirm("Eliminare «" + b.dataset.nome + "»?")) return;
          await EMP.api("studio/" + b.dataset.elimina + "/" + b.dataset.id + "/elimina", "POST");
        } else if (b.dataset.famiglia) {
          return apriFamiglia(b.dataset.famiglia);
        } else if (b.dataset.attributo) {
          return apriAttributo(b.dataset.attributo);
        } else {
          return;
        }
        await ricaricaStruttura();
      } catch (err) {
        EMP.toast(err.message, true);
      }
    });
    $("nuova-famiglia").addEventListener("click", () => apriFamiglia(null));
    $("famiglia-annulla").addEventListener("click", () => { $("famiglia-form").hidden = true; });
    $("famiglia-form").addEventListener("submit", salvaFamiglia);
    $("attributo-form").addEventListener("submit", salvaAttributo);
    $("attributo-annulla").addEventListener("click", () => apriAttributo(null));
  }

  document.addEventListener("DOMContentLoaded", avvio);
})();
