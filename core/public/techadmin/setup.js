"use strict";
/* Primo setup: crea l'Amministratore Tecnico e mostra la Recovery Key una sola volta. */

(async () => {
  try {
    const stato = await EMP.api("accesso/stato");
    if (stato.inizializzato) {
      location.replace("index.html");
      return;
    }
    document.getElementById("avviso-http").hidden = stato.protetto;
    if (!stato.codice_setup) {
      const campo = document.getElementById("campo-codice");
      campo.querySelector("input").required = false;
      campo.hidden = true;
    }
  } catch (e) {
    EMP.toast(e.message, true);
  }

  const form = document.getElementById("setup");
  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const f = new FormData(form);
    if (f.get("password") !== f.get("ripeti")) {
      EMP.toast("Le due password non coincidono", true);
      return;
    }
    const btn = form.querySelector("button");
    btn.disabled = true;
    try {
      const out = await EMP.api("accesso/setup", "POST", {
        codice: String(f.get("codice") || ""),
        username: String(f.get("username")),
        nome: String(f.get("nome")),
        password: String(f.get("password")),
      });
      form.reset();
      document.getElementById("form-box").hidden = true;
      EMP.showRecoveryKey(document.getElementById("key-box"), out.recovery_key, String(f.get("username")).toLowerCase().trim(), () => {
        document.getElementById("done-box").hidden = false;
      });
    } catch (e) {
      EMP.toast(e.message, true);
      btn.disabled = false;
    }
  });
})();
