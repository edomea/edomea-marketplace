//! emp-servizi — ambiente Windows di EMP (Edomea Marketplace).
//!
//!   emp-servizi installa --sorgente <cartella>   installa o aggiorna (amministratore)
//!   emp-servizi disinstalla                      rimuove servizio e programmi; i dati restano (amministratore)
//!   emp-servizi stato                            stato del servizio in JSON (qualsiasi utente)
//!   emp-servizi servizio                         avviato da Windows come servizio EMPServer
//!   emp-servizi manifest <cartella> [k=v …]      genera il manifest dei componenti (CI)
//!
//! Codici di uscita: 0 riuscito, 1 errore, 2 uso errato.

#[cfg(windows)]
use emp_server::service;
use emp_server::{layout::Layout, manifest, provision};
use std::collections::BTreeMap;
use std::path::PathBuf;

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let code = match args.first().map(String::as_str) {
        Some("manifest") if args.len() >= 2 => {
            let versions: BTreeMap<String, String> = args[2..].iter().filter_map(|a| a.split_once('=')).map(|(k, v)| (k.into(), v.into())).collect();
            match manifest::generate(&PathBuf::from(&args[1]), versions) {
                Ok(json) => {
                    println!("{json}");
                    0
                }
                Err(e) => fail(&e),
            }
        }
        Some("stato") => {
            println!("{}", status_json());
            0
        }
        #[cfg(windows)]
        Some("installa") => {
            match (args.get(1).map(String::as_str), args.get(2)) {
                (Some("--sorgente"), Some(dir)) => {
                    let layout = Layout::system();
                    match provision::install(&PathBuf::from(dir), &layout) {
                        Ok(()) => 0,
                        Err(e) => {
                            provision::Log::open(&layout).line(&format!("ERRORE: {e}"));
                            1
                        }
                    }
                }
                _ => usage(),
            }
        }
        #[cfg(windows)]
        Some("disinstalla") => {
            let layout = Layout::system();
            match provision::uninstall(&layout) {
                Ok(()) => 0,
                Err(e) => {
                    provision::Log::open(&layout).line(&format!("ERRORE: {e}"));
                    1
                }
            }
        }
        #[cfg(windows)]
        Some("servizio") => match service::run_dispatcher() {
            Ok(()) => 0,
            Err(e) => fail(&e),
        },
        _ => usage(),
    };
    std::process::exit(code);
}

fn status_json() -> String {
    let l = Layout::system();
    #[cfg(windows)]
    let state = service::state();
    #[cfg(not(windows))]
    let state = String::from("non_supportato");
    let health = provision::http_get("/api/v1/salute", std::time::Duration::from_secs(2))
        .map(|(c, b)| c == 200 && b.contains("\"stato\":\"ok\""))
        .unwrap_or(false);
    serde_json::json!({
        "versione": env!("CARGO_PKG_VERSION"),
        "servizio": state,
        "core_raggiungibile": health,
        "indirizzo": "http://127.0.0.1/amministrazione/",
        "programmi": l.program.display().to_string(),
        "dati": l.data.display().to_string(),
        "registro": l.install_log().display().to_string(),
        "componenti_inclusi": !manifest::Manifest::embedded().is_empty(),
    })
    .to_string()
}

fn fail(msg: &str) -> i32 {
    eprintln!("{msg}");
    1
}

fn usage() -> i32 {
    eprintln!("Uso: emp-servizi installa --sorgente <cartella> | disinstalla | stato | servizio | manifest <cartella> [nome=versione …]");
    2
}
