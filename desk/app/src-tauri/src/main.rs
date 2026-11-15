#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]
//! EMP Desk — Edomea Marketplace.
//! Deriva da VenVia Desk 0.4 Alpha 4: diagnostica e modalità Server/Client,
//! più il motore di installazione dei moduli (Core per primo, poi i plugin).

use emp_modules::PlanStep;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    net::{SocketAddr, TcpListener},
    path::PathBuf,
};
use sysinfo::{Disks, System};
use tauri::Manager;

#[derive(Serialize)]
struct Report {
    os: String,
    arch: String,
    cpu: String,
    logical_cpus: usize,
    ram_mib: u64,
    disks: Vec<Disk>,
    ports: Vec<Port>,
    processes: Vec<String>,
}
#[derive(Serialize)]
struct Disk {
    mount: String,
    free_mib: u64,
    total_mib: u64,
}
#[derive(Serialize)]
struct Port {
    number: u16,
    result: String,
}

#[derive(Serialize, Deserialize, Default, Clone, PartialEq, Debug)]
struct Settings {
    mode: String,
    /// Solo modalità Client: indirizzo HTTPS del Server EMP del negozio.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    server_url: Option<String>,
}

fn valid_mode(mode: &str) -> bool {
    matches!(mode, "server" | "client")
}

/// Indirizzo del Server per i Client: solo HTTPS, nessuna credenziale nell'URL,
/// host composto da lettere, cifre, punti e trattini, porta facoltativa.
fn valid_server_url(url: &str) -> Result<String, String> {
    let url = url.trim();
    let rest = url.strip_prefix("https://").ok_or("L'indirizzo deve iniziare con https://")?;
    let authority = rest.strip_suffix('/').unwrap_or(rest);
    if authority.is_empty() || authority.len() > 253 || authority.contains(['/', '@', '?', '#', ' ']) {
        return Err("Indicare solo nome o indirizzo del server, ad esempio https://emp.negozio.lan".into());
    }
    let (host, port) = match authority.rsplit_once(':') {
        Some((h, p)) => (h, Some(p)),
        None => (authority, None),
    };
    if host.is_empty() || !host.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'.' || c == b'-') || host.starts_with(['.', '-']) {
        return Err("Nome del server non valido".into());
    }
    if let Some(p) = port {
        match p.parse::<u16>() {
            Ok(n) if n > 0 => {}
            _ => return Err("Porta non valida".into()),
        }
    }
    Ok(format!("https://{}", authority.to_ascii_lowercase()))
}

fn settings_path(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    Ok(app.path().app_config_dir().map_err(|e| e.to_string())?.join("settings.json"))
}

fn read_settings(app: &tauri::AppHandle) -> Result<Settings, String> {
    match fs::read(settings_path(app)?) {
        Ok(bytes) => {
            let s: Settings = serde_json::from_slice(&bytes).map_err(|e| e.to_string())?;
            if !valid_mode(&s.mode) {
                return Err("Modalità non valida".into());
            }
            Ok(s)
        }
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Settings::default()),
        Err(e) => Err(e.to_string()),
    }
}

fn write_settings(app: &tauri::AppHandle, s: &Settings) -> Result<(), String> {
    let p = settings_path(app)?;
    fs::create_dir_all(p.parent().ok_or("Percorso non valido")?).map_err(|e| e.to_string())?;
    let tmp = p.with_extension("json.tmp");
    fs::write(&tmp, serde_json::to_vec_pretty(s).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
    fs::rename(&tmp, &p).map_err(|e| e.to_string())
}

#[tauri::command]
fn load_settings(app: tauri::AppHandle) -> Result<Settings, String> {
    read_settings(&app)
}

#[tauri::command]
fn save_settings(app: tauri::AppHandle, mode: String, server_url: Option<String>) -> Result<Settings, String> {
    if !valid_mode(&mode) {
        return Err("Modalità non valida".into());
    }
    let server_url = if mode == "client" {
        Some(valid_server_url(server_url.as_deref().ok_or("Indicare l'indirizzo del Server")?)?)
    } else {
        None
    };
    let s = Settings { mode, server_url };
    write_settings(&app, &s)?;
    Ok(s)
}

/// Pacchetti inclusi nell'installer (risorsa "pacchetti/").
fn packages_dir(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    Ok(app.path().resource_dir().map_err(|e| e.to_string())?.join("pacchetti"))
}

/// Cartella dei moduli installati: dati dell'applicazione, fuori dalla cartella del programma.
fn modules_dir(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    Ok(app.path().app_data_dir().map_err(|e| e.to_string())?.join("modules"))
}

fn require_server(app: &tauri::AppHandle) -> Result<(), String> {
    if read_settings(app)?.mode != "server" {
        return Err("Core e plugin si installano solo sul computer configurato come Server. I Client usano le API del Server.".into());
    }
    Ok(())
}

fn clean_selection(selected: Option<Vec<String>>) -> Result<Option<Vec<String>>, String> {
    match selected {
        None => Ok(None),
        Some(ids) => {
            if ids.len() > 200 || ids.iter().any(|id| !emp_modules::manifest::valid_id(id)) {
                return Err("Selezione dei moduli non valida".into());
            }
            Ok(Some(ids))
        }
    }
}

/// Mostra il piano di installazione senza modificare nulla.
#[tauri::command]
async fn module_plan(app: tauri::AppHandle, selected: Option<Vec<String>>) -> Result<Vec<PlanStep>, String> {
    require_server(&app)?;
    let selected = clean_selection(selected)?;
    let (pk, md) = (packages_dir(&app)?, modules_dir(&app)?);
    tauri::async_runtime::spawn_blocking(move || emp_modules::plan(&pk, &md, selected.as_deref()))
        .await
        .map_err(|e| e.to_string())?
}

/// Installa i file di Core e plugin nell'ordine del piano. Le migrazioni del
/// database saranno eseguite dal Core quando l'ambiente PHP/MariaDB sarà
/// predisposto da Desk (Macro 2).
#[tauri::command]
async fn module_install(app: tauri::AppHandle, selected: Option<Vec<String>>) -> Result<Vec<PlanStep>, String> {
    require_server(&app)?;
    let selected = clean_selection(selected)?;
    let (pk, md) = (packages_dir(&app)?, modules_dir(&app)?);
    tauri::async_runtime::spawn_blocking(move || emp_modules::install(&pk, &md, selected.as_deref()))
        .await
        .map_err(|e| e.to_string())?
}

#[tauri::command]
async fn diagnostics() -> Result<Report, String> {
    tauri::async_runtime::spawn_blocking(|| {
        let mut s = System::new_all();
        s.refresh_all();
        let disks = Disks::new_with_refreshed_list()
            .list()
            .iter()
            .map(|d| Disk {
                mount: d.mount_point().display().to_string(),
                free_mib: d.available_space() / 1_048_576,
                total_mib: d.total_space() / 1_048_576,
            })
            .collect();
        let ports = [80, 443, 3306]
            .into_iter()
            .map(|number| {
                let addr = SocketAddr::from(([127, 0, 0, 1], number));
                let result = match TcpListener::bind(addr) {
                    Ok(_) => "Bind locale riuscito",
                    Err(_) => "Bind locale non disponibile",
                }
                .to_string();
                Port { number, result }
            })
            .collect();
        let mut processes: Vec<String> = s
            .processes()
            .values()
            .filter_map(|p| {
                let n = p.name().to_string_lossy().to_string();
                let lower = n.to_lowercase();
                if ["php", "nginx", "maria", "mysql", "httpd", "apache", "w3wp"].iter().any(|x| lower.starts_with(*x)) {
                    Some(n)
                } else {
                    None
                }
            })
            .collect();
        processes.sort();
        processes.dedup();
        Report {
            os: System::long_os_version().unwrap_or_else(|| "Non rilevato".into()),
            arch: std::env::consts::ARCH.into(),
            cpu: s.cpus().first().map(|c| c.brand().to_string()).unwrap_or_default(),
            logical_cpus: s.cpus().len(),
            ram_mib: s.total_memory() / 1_048_576,
            disks,
            ports,
            processes,
        }
    })
    .await
    .map_err(|e| e.to_string())
}

fn main() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![diagnostics, load_settings, save_settings, module_plan, module_install])
        .run(tauri::generate_context!())
        .expect("Avvio EMP Desk non riuscito");
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn modes() {
        assert!(valid_mode("server"));
        assert!(valid_mode("client"));
        assert!(!valid_mode(""));
        assert!(!valid_mode("../server"));
    }

    #[test]
    fn server_url() {
        assert_eq!(valid_server_url("https://EMP.Negozio.lan/").unwrap(), "https://emp.negozio.lan");
        assert_eq!(valid_server_url(" https://192.168.1.10:8443 ").unwrap(), "https://192.168.1.10:8443");
        for bad in ["http://emp.lan", "https://", "https://user:pw@emp.lan", "https://emp.lan/percorso", "https://emp.lan:0", "https://emp.lan:99999", "https://-emp.lan", "ftp://emp.lan"] {
            assert!(valid_server_url(bad).is_err(), "{bad}");
        }
    }

    #[test]
    fn selection() {
        assert!(clean_selection(None).unwrap().is_none());
        assert!(clean_selection(Some(vec!["emp-pos".into()])).is_ok());
        assert!(clean_selection(Some(vec!["../x".into()])).is_err());
    }
}
