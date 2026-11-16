//! Avvio del Core in **modalità laboratorio** (passo intermedio verso la Macro 2).
//!
//! - PHP ufficiale per Windows è incluso nell'installer (risorsa `php/`, scaricata
//!   e verificata con SHA-256 dalla CI).
//! - Database SQLite nella cartella dati di Desk (MariaDB arriverà con la Macro 2).
//! - Il Core risponde solo su `http://127.0.0.1:8180`, cioè dal computer Server stesso,
//!   finché Desk resta aperto. Alla chiusura di Desk il Core si ferma.
//!
//! Non è una configurazione di produzione: il server integrato di PHP serve una
//! richiesta alla volta. La Macro 2 lo sostituirà con Nginx + PHP + MariaDB come
//! servizi di Windows.

use serde::Serialize;
use std::fs;
use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::time::{Duration, Instant};

pub const HOST: &str = "127.0.0.1";
pub const PORT: u16 = 8180;

/// Processo del Core avviato da Desk (uno solo).
#[derive(Default)]
pub struct CoreProcess(pub Mutex<Option<Child>>);

/// Cartelle di lavoro del Core, tutte nella cartella dati di Desk.
pub struct Paths {
    pub modules: PathBuf,
    pub data: PathBuf,
    pub catalog: PathBuf,
    pub config: PathBuf,
    pub log: PathBuf,
}

impl Paths {
    pub fn new(app_data: &Path) -> Self {
        let data = app_data.join("dati");
        Paths {
            modules: app_data.join("modules"),
            catalog: app_data.join("catalogo"),
            config: data.join("config.php"),
            log: data.join("core.log"),
            data,
        }
    }

    fn core_dir(&self) -> PathBuf {
        self.modules.join("emp-core")
    }
}

#[derive(Serialize, Clone, Debug, PartialEq)]
pub struct Status {
    pub core_installato: bool,
    pub php_disponibile: bool,
    pub in_esecuzione: bool,
    pub indirizzo: String,
    pub cartella_dati: String,
}

pub fn base_url() -> String {
    format!("http://{HOST}:{PORT}")
}

/// Stringa PHP tra apici singoli: si raddoppiano solo `\` e `'`.
pub fn php_string(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}

/// Percorso con le barre "/" (PHP e SQLite le accettano anche su Windows).
fn slash(p: &Path) -> String {
    p.display().to_string().replace('\\', "/")
}

/// Contenuto del file di configurazione del Core (fuori dalla cartella pubblica).
pub fn config_php(paths: &Paths) -> String {
    format!(
        "<?php\n// Configurazione generata da EMP Desk (modalità laboratorio: SQLite).\nreturn [\n    'dsn' => {},\n    'prefix' => 'emp_',\n    'timezone' => 'Europe/Rome',\n    'modules_dir' => {},\n    'catalog_dir' => {},\n];\n",
        php_string(&format!("sqlite:{}", slash(&paths.data.join("emp.sqlite")))),
        php_string(&slash(&paths.modules)),
        php_string(&slash(&paths.catalog)),
    )
}

/// php.exe incluso nell'installer. In sviluppo (build di debug) si può indicare
/// un PHP del sistema con la variabile EMP_PHP.
pub fn php_binary(resource_dir: &Path) -> Option<PathBuf> {
    let exe = if cfg!(windows) { "php.exe" } else { "php" };
    let bundled = resource_dir.join("php").join(exe);
    if bundled.is_file() {
        return Some(bundled);
    }
    if cfg!(debug_assertions) {
        if let Ok(p) = std::env::var("EMP_PHP") {
            let p = PathBuf::from(p);
            if p.is_file() {
                return Some(p);
            }
        }
    }
    None
}

fn php_command(php: &Path) -> Command {
    let mut cmd = Command::new(php);
    // php.ini incluso accanto a php.exe (estensioni pdo_sqlite, mbstring, zip).
    if let Some(dir) = php.parent() {
        let ini = dir.join("php.ini");
        if ini.is_file() {
            cmd.arg("-c").arg(ini);
        }
        // Percorso assoluto delle estensioni: un percorso relativo dipenderebbe dalla cartella corrente.
        let ext = dir.join("ext");
        if ext.is_dir() {
            cmd.arg("-d").arg(format!("extension_dir={}", ext.display()));
        }
    }
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        const CREATE_NO_WINDOW: u32 = 0x0800_0000;
        cmd.creation_flags(CREATE_NO_WINDOW);
    }
    cmd
}

/// Scrive la configurazione (se manca) ed esegue le migrazioni del Core.
pub fn prepare_database(php: &Path, paths: &Paths) -> Result<String, String> {
    if !paths.core_dir().join("emp-module.json").is_file() {
        return Err("Il Core non è installato".into());
    }
    for d in [&paths.data, &paths.catalog] {
        fs::create_dir_all(d).map_err(|e| format!("{}: {e}", d.display()))?;
    }
    if !paths.config.is_file() {
        fs::write(&paths.config, config_php(paths)).map_err(|e| format!("Configurazione non scritta: {e}"))?;
    }
    let out = php_command(php)
        .arg(paths.core_dir().join("bin").join("emp"))
        .args(["installa", "--utente=emp-desk", "emp-core"])
        .env("EMP_CONFIG", &paths.config)
        .stdin(Stdio::null())
        .output()
        .map_err(|e| format!("Avvio di PHP non riuscito: {e}"))?;
    let text = format!("{}{}", String::from_utf8_lossy(&out.stdout), String::from_utf8_lossy(&out.stderr)).trim().to_string();
    if out.status.success() {
        Ok(text)
    } else {
        Err(format!("Preparazione del database non riuscita: {text}"))
    }
}

fn port_in_use() -> bool {
    let addr: SocketAddr = format!("{HOST}:{PORT}").parse().expect("indirizzo valido");
    TcpStream::connect_timeout(&addr, Duration::from_millis(300)).is_ok()
}

/// Richiesta GET minima, senza librerie esterne, per controllare /api/v1/salute.
pub fn http_get(path: &str, timeout: Duration) -> Result<(u16, String), String> {
    let addr: SocketAddr = format!("{HOST}:{PORT}").parse().expect("indirizzo valido");
    let mut s = TcpStream::connect_timeout(&addr, timeout).map_err(|e| e.to_string())?;
    s.set_read_timeout(Some(timeout)).map_err(|e| e.to_string())?;
    // Richiesta inviata in un'unica scrittura: il server integrato di PHP può
    // chiudere la connessione se riceve una richiesta spezzata in più pacchetti.
    let request = format!("GET {path} HTTP/1.0\r\nHost: {HOST}:{PORT}\r\nConnection: close\r\n\r\n");
    s.write_all(request.as_bytes()).map_err(|e| e.to_string())?;
    s.flush().map_err(|e| e.to_string())?;
    let mut buf = Vec::new();
    s.take(1_048_576).read_to_end(&mut buf).map_err(|e| e.to_string())?;
    parse_response(&String::from_utf8_lossy(&buf))
}

pub fn parse_response(raw: &str) -> Result<(u16, String), String> {
    let (head, body) = raw.split_once("\r\n\r\n").ok_or("Risposta non valida")?;
    let status = head
        .lines()
        .next()
        .and_then(|l| l.split_whitespace().nth(1))
        .and_then(|c| c.parse::<u16>().ok())
        .ok_or("Risposta non valida")?;
    Ok((status, body.to_string()))
}

/// Avvia il Core se non è già in esecuzione e attende che risponda.
pub fn start(state: &CoreProcess, php: &Path, paths: &Paths) -> Result<(), String> {
    let mut guard = state.0.lock().map_err(|_| "Stato non disponibile")?;
    if let Some(child) = guard.as_mut() {
        if child.try_wait().map_err(|e| e.to_string())?.is_none() {
            return Ok(());
        }
        *guard = None;
    }
    if port_in_use() {
        return Err(format!("La porta {PORT} è già occupata da un altro programma: chiuderlo e riprovare."));
    }
    prepare_database(php, paths)?;
    let public = paths.core_dir().join("public");
    let log = fs::File::create(&paths.log).map_err(|e| e.to_string())?;
    let log_err = log.try_clone().map_err(|e| e.to_string())?;
    let child = php_command(php)
        .arg("-S")
        .arg(format!("{HOST}:{PORT}"))
        .arg("-t")
        .arg(&public)
        .arg(public.join("index.php"))
        .env("EMP_CONFIG", &paths.config)
        .current_dir(&public)
        .stdin(Stdio::null())
        .stdout(log)
        .stderr(log_err)
        .spawn()
        .map_err(|e| format!("Avvio del Core non riuscito: {e}"))?;
    *guard = Some(child);
    drop(guard);

    let deadline = Instant::now() + Duration::from_secs(10);
    while Instant::now() < deadline {
        if let Ok((200, body)) = http_get("/api/v1/salute", Duration::from_secs(2)) {
            if body.contains("\"stato\":\"ok\"") {
                return Ok(());
            }
        }
        std::thread::sleep(Duration::from_millis(250));
    }
    stop(state);
    Err(format!("Il Core non risponde. Dettagli nel file {}", paths.log.display()))
}

pub fn stop(state: &CoreProcess) {
    if let Ok(mut guard) = state.0.lock() {
        if let Some(mut child) = guard.take() {
            let _ = child.kill();
            let _ = child.wait();
        }
    }
}

pub fn is_running(state: &CoreProcess) -> bool {
    match state.0.lock() {
        Ok(mut guard) => match guard.as_mut() {
            Some(child) => matches!(child.try_wait(), Ok(None)),
            None => false,
        },
        Err(_) => false,
    }
}

pub fn status(state: &CoreProcess, resource_dir: &Path, paths: &Paths) -> Status {
    Status {
        core_installato: paths.core_dir().join("emp-module.json").is_file(),
        php_disponibile: php_binary(resource_dir).is_some(),
        in_esecuzione: is_running(state),
        indirizzo: format!("{}/amministrazione/", base_url()),
        cartella_dati: paths.data.display().to_string(),
    }
}

/// Apre l'indirizzo dell'amministrazione nel browser predefinito.
pub fn open_in_browser(url: &str) -> Result<(), String> {
    // Solo indirizzi locali del Core: nessun URL arbitrario arriva qui.
    if !url.starts_with(&format!("{}/", base_url())) {
        return Err("Indirizzo non consentito".into());
    }
    #[cfg(windows)]
    let result = {
        use std::os::windows::process::CommandExt;
        Command::new("rundll32").args(["url.dll,FileProtocolHandler", url]).creation_flags(0x0800_0000).spawn()
    };
    #[cfg(target_os = "macos")]
    let result = Command::new("open").arg(url).spawn();
    #[cfg(all(unix, not(target_os = "macos")))]
    let result = Command::new("xdg-open").arg(url).spawn();
    result.map(|_| ()).map_err(|e| format!("Apertura del browser non riuscita: {e}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_strings_are_escaped() {
        assert_eq!(php_string(r"C:\Users\O'Brien\dati"), r"'C:\\Users\\O\'Brien\\dati'");
    }

    #[test]
    fn config_uses_sqlite_and_forward_slashes() {
        let p = Paths::new(Path::new(r"C:\Users\antonio\AppData\Roaming\it.edomea.desk"));
        let c = config_php(&p);
        assert!(c.contains("'dsn' => 'sqlite:C:/Users/antonio/AppData/Roaming/it.edomea.desk/dati/emp.sqlite'"), "{c}");
        assert!(c.contains("'modules_dir' => 'C:/Users/antonio/AppData/Roaming/it.edomea.desk/modules'"), "{c}");
        assert!(c.starts_with("<?php\n"));
    }

    #[test]
    fn response_parsing() {
        assert_eq!(parse_response("HTTP/1.1 200 OK\r\nA: b\r\n\r\n{\"stato\":\"ok\"}").unwrap(), (200, "{\"stato\":\"ok\"}".into()));
        assert!(parse_response("spazzatura").is_err());
    }

    /// Prova completa con un PHP reale: eseguita dalla CI, che imposta
    /// EMP_TEST_CORE_PACKAGES (pacchetto del Core) ed EMP_TEST_PHP.
    #[test]
    fn start_core_and_reach_setup_page() {
        let (Ok(packages), Ok(php)) = (std::env::var("EMP_TEST_CORE_PACKAGES"), std::env::var("EMP_TEST_PHP")) else {
            eprintln!("prova saltata: EMP_TEST_CORE_PACKAGES/EMP_TEST_PHP non impostate");
            return;
        };
        let dir = std::env::temp_dir().join(format!("emp-desk-runtime-{}", std::process::id()));
        let paths = Paths::new(&dir);
        emp_core_installer::install_core(Path::new(&packages), &paths.modules).expect("installazione del Core");
        // Ferma il Core anche se la prova fallisce, per non lasciare la porta occupata.
        struct StopOnDrop(CoreProcess);
        impl Drop for StopOnDrop {
            fn drop(&mut self) {
                stop(&self.0);
            }
        }
        let guard = StopOnDrop(CoreProcess::default());
        let state = &guard.0;
        start(state, Path::new(&php), &paths).expect("avvio del Core");
        assert!(is_running(state));
        let (code, body) = http_get("/api/v1/accesso/stato", Duration::from_secs(5)).unwrap();
        assert_eq!(code, 200);
        assert!(body.contains("\"inizializzato\":false"), "{body}");
        assert_eq!(http_get("/amministrazione/setup.html", Duration::from_secs(5)).unwrap().0, 200);
        // Seconda partenza: nessun errore, stesso processo.
        start(state, Path::new(&php), &paths).expect("già avviato");
        stop(state);
        assert!(!is_running(state));
        let _ = fs::remove_dir_all(&dir);
    }

    #[test]
    fn only_local_admin_urls_open() {
        assert!(open_in_browser("https://example.com/").is_err());
        assert!(open_in_browser("http://127.0.0.1:8180evil/").is_err());
    }
}
