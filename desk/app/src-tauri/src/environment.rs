//! Ambiente Server definitivo (Macro 2): MariaDB, PHP e Caddy come servizio di
//! Windows "EMPServer", gestiti dal programma `emp-servizi` incluso in Desk.
//!
//! Desk non ha permessi di amministratore: per installare, aggiornare o
//! disinstallare avvia `emp-servizi` con la richiesta UAC di Windows. Lo stato
//! si legge senza permessi particolari.

use serde_json::Value;
use std::path::{Path, PathBuf};
use std::process::Command;

pub const ADMIN_URL: &str = "http://127.0.0.1/amministrazione/";

pub fn servizi_exe(resource_dir: &Path) -> Option<PathBuf> {
    let exe = if cfg!(windows) { "emp-servizi.exe" } else { "emp-servizi" };
    let p = resource_dir.join("servizi").join(exe);
    p.is_file().then_some(p)
}

/// Stato dell'ambiente (`emp-servizi stato`).
pub fn status(resource_dir: &Path) -> Value {
    let Some(exe) = servizi_exe(resource_dir) else {
        return serde_json::json!({ "disponibile": false, "servizio": "assente", "core_raggiungibile": false, "indirizzo": ADMIN_URL });
    };
    let mut cmd = Command::new(exe);
    cmd.arg("stato");
    hide(&mut cmd);
    match cmd.output() {
        Ok(out) if out.status.success() => {
            let mut v: Value = serde_json::from_slice(&out.stdout).unwrap_or_else(|_| serde_json::json!({}));
            v["disponibile"] = Value::Bool(cfg!(windows));
            v
        }
        _ => serde_json::json!({ "disponibile": false, "servizio": "sconosciuto", "core_raggiungibile": false, "indirizzo": ADMIN_URL }),
    }
}

#[cfg(windows)]
fn hide(cmd: &mut Command) {
    use std::os::windows::process::CommandExt;
    cmd.creation_flags(0x0800_0000);
}
#[cfg(not(windows))]
fn hide(_cmd: &mut Command) {}

/// Ultime righe del registro di installazione (leggibile dagli utenti).
pub fn log_tail(lines: usize) -> Vec<String> {
    let pd = std::env::var("ProgramData").unwrap_or_else(|_| r"C:\ProgramData".into());
    let path = Path::new(&pd).join("EMP").join("log").join("installazione.log");
    match std::fs::read_to_string(path) {
        Ok(text) => {
            let all: Vec<&str> = text.lines().collect();
            all[all.len().saturating_sub(lines)..].iter().map(|s| s.to_string()).collect()
        }
        Err(_) => vec![],
    }
}

/// Argomento tra virgolette per la riga di comando di Windows (percorso senza "\" finale).
pub fn quote_arg(s: &str) -> String {
    let trimmed = s.trim_end_matches('\\');
    format!("\"{}\"", trimmed.replace('"', ""))
}

/// Esegue `emp-servizi` con i permessi di amministratore (richiesta UAC) e attende la fine.
#[cfg(windows)]
pub fn run_elevated(exe: &Path, params: &str) -> Result<u32, String> {
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::Foundation::{CloseHandle, GetLastError};
    use windows_sys::Win32::System::Threading::{GetExitCodeProcess, WaitForSingleObject, INFINITE};
    use windows_sys::Win32::UI::Shell::{ShellExecuteExW, SEE_MASK_NOCLOSEPROCESS, SHELLEXECUTEINFOW};

    fn wide(s: &std::ffi::OsStr) -> Vec<u16> {
        s.encode_wide().chain(std::iter::once(0)).collect()
    }
    let verb = wide(std::ffi::OsStr::new("runas"));
    let file = wide(exe.as_os_str());
    let args = wide(std::ffi::OsStr::new(params));
    // SAFETY: struttura C inizializzata a zero e poi compilata con puntatori validi
    // per tutta la durata della chiamata; handle chiuso dopo l'uso.
    unsafe {
        let mut info: SHELLEXECUTEINFOW = std::mem::zeroed();
        info.cbSize = std::mem::size_of::<SHELLEXECUTEINFOW>() as u32;
        info.fMask = SEE_MASK_NOCLOSEPROCESS;
        info.lpVerb = verb.as_ptr();
        info.lpFile = file.as_ptr();
        info.lpParameters = args.as_ptr();
        info.nShow = 0; // SW_HIDE
        if ShellExecuteExW(&mut info) == 0 {
            return Err(match GetLastError() {
                1223 => "Operazione annullata: i permessi di amministratore non sono stati concessi".into(),
                code => format!("Avvio con permessi di amministratore non riuscito (errore {code})"),
            });
        }
        if info.hProcess.is_null() {
            return Err("Processo di installazione non disponibile".into());
        }
        WaitForSingleObject(info.hProcess, INFINITE);
        let mut code: u32 = 1;
        GetExitCodeProcess(info.hProcess, &mut code);
        CloseHandle(info.hProcess);
        Ok(code)
    }
}

#[cfg(not(windows))]
pub fn run_elevated(_exe: &Path, _params: &str) -> Result<u32, String> {
    Err("L'ambiente Server si installa solo su Windows".into())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quoting() {
        assert_eq!(quote_arg(r"C:\Users\Antonio\AppData\Local\EMP Desk\"), r#""C:\Users\Antonio\AppData\Local\EMP Desk""#);
        assert_eq!(quote_arg(r#"C:\a"b"#), r#""C:\ab""#);
    }

    #[test]
    fn missing_tool_reports_unavailable() {
        let d = std::env::temp_dir();
        assert_eq!(status(&d)["disponibile"], Value::Bool(false));
    }
}
