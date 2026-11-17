"use strict";
/* Recupero del Tecnico con Recovery Key: nuova password e nuova chiave (mostrata una volta). */

const form = document.getElementById("recupero");
form.addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = new FormData(form);
  if (f.get("nuova") !== f.get("ripeti")) {
    EMP.toast("Le due password non coincidono", true);
    return;
  }
  const btn = form.querySelector("button");
  btn.disabled = true;
  const username = String(f.get("username")).toLowerCase().trim();
  try {
    const out = await EMP.api("accesso/recupero", "POST", {
      username,
      recovery_key: String(f.get("recovery_key")),
      nuova: String(f.get("nuova")),
    });
    form.reset();
    document.getElementById("form-box").hidden = true;
    EMP.toast("Password reimpostata. Salva subito la nuova Recovery Key.");
    EMP.showRecoveryKey(document.getElementById("key-box"), out.recovery_key, username, () => {
      document.getElementById("done-box").hidden = false;
    });
  } catch (e) {
    EMP.toast(e.message, true);
    btn.disabled = false;
  }
});
