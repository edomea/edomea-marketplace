//! Installazione, aggiornamento e disinstallazione dell'ambiente EMP su Windows.
//! Eseguito con i permessi di amministratore (richiesta UAC da EMP Desk).
//!
//! Installazione (ripetibile: la seconda volta è un aggiornamento):
//!  1. verifica dei componenti forniti da Desk con il manifest incorporato;
//!  2. controllo delle porte (se l'ambiente non è già installato);
//!  3. arresto del servizio esistente; copia dei componenti in %ProgramFiles%\EMP
//!     e nuova verifica nella destinazione;
//!  4. cartelle dati in %ProgramData%\EMP con permessi ristretti;
//!  5. inizializzazione di MariaDB (solo la prima volta), password casuali;
//!  6. configurazioni (my.ini, php.ini, Caddyfile), file del Core;
//!  7. registrazione e avvio del servizio EMPServer (LocalService, avvio automatico);
//!  8. database e utente applicativo (la prima volta), config.php, migrazioni del Core;
//!  9. controllo finale: http://127.0.0.1/api/v1/salute.
//!
//! I dati esistenti non vengono mai cancellati.

use crate::configs;
use crate::layout::{Layout, DB_PORT, FASTCGI_PORTS, HTTP_PORT};
use crate::manifest::{Manifest, COMPONENTS};
use std::fs;
use std::io::{Read, Write};
use std::net::{SocketAddr, TcpListener, TcpStream};
use std::path::Path;
use std::process::Command;
use std::time::{Duration, Instant};

pub struct Log {
    file: Option<fs::File>,
}

impl Log {
    pub fn open(l: &Layout) -> Log {
        let _ = fs::create_dir_all(l.logs());
        Log { file: fs::OpenOptions::new().create(true).append(true).open(l.install_log()).ok() }
    }
    pub fn line(&mut self, msg: &str) {
        println!("{msg}");
        if let Some(f) = self.file.as_mut() {
            let _ = writeln!(f, "{msg}");
        }
    }
}

/// Le porte necessarie sono libere su 127.0.0.1?
pub fn busy_ports() -> Vec<u16> {
    let mut ports = vec![HTTP_PORT, DB_PORT];
    ports.extend(FASTCGI_PORTS);
    ports
        .into_iter()
        .filter(|p| TcpListener::bind(SocketAddr::from(([127, 0, 0, 1], *p))).is_err())
        .collect()
}

pub fn http_get(path: &str, timeout: Duration) -> Result<(u16, String), String> {
    let addr: SocketAddr = ([127, 0, 0, 1], HTTP_PORT).into();
    let mut s = TcpStream::connect_timeout(&addr, timeout).map_err(|e| e.to_string())?;
    s.set_read_timeout(Some(timeout)).map_err(|e| e.to_string())?;
    let req = format!("GET {path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    s.write_all(req.as_bytes()).map_err(|e| e.to_string())?;
    let mut buf = Vec::new();
    s.take(1_048_576).read_to_end(&mut buf).map_err(|e| e.to_string())?;
    let text = String::from_utf8_lossy(&buf);
    let (head, body) = text.split_once("\r\n\r\n").ok_or("Risposta non valida")?;
    let code = head.lines().next().and_then(|l| l.split_whitespace().nth(1)).and_then(|c| c.parse().ok()).ok_or("Risposta non valida")?;
    Ok((code, body.to_string()))
}

pub fn wait_health(timeout: Duration) -> Result<(), String> {
    let deadline = Instant::now() + timeout;
    let mut last = String::new();
    while Instant::now() < deadline {
        match http_get("/api/v1/salute", Duration::from_secs(3)) {
            Ok((200, body)) if body.contains("\"stato\":\"ok\"") => return Ok(()),
            Ok((code, body)) => last = format!("HTTP {code}: {}", body.chars().take(200).collect::<String>()),
            Err(e) => last = e,
        }
        std::thread::sleep(Duration::from_millis(500));
    }
    Err(format!("Il Core non risponde su http://127.0.0.1/ ({last})"))
}

fn copy_dir(src: &Path, dst: &Path) -> Result<(), String> {
    fs::create_dir_all(dst).map_err(|e| format!("{}: {e}", dst.display()))?;
    for entry in fs::read_dir(src).map_err(|e| format!("{}: {e}", src.display()))? {
        let entry = entry.map_err(|e| e.to_string())?;
        let ty = entry.file_type().map_err(|e| e.to_string())?;
        let to = dst.join(entry.file_name());
        if ty.is_symlink() {
            return Err(format!("Collegamento non ammesso: {}", entry.path().display()));
        } else if ty.is_dir() {
            copy_dir(&entry.path(), &to)?;
        } else {
            fs::copy(entry.path(), &to).map_err(|e| format!("{}: {e}", to.display()))?;
        }
    }
    Ok(())
}

/// Sostituisce i componenti in %ProgramFiles%\EMP e li verifica nella destinazione.
pub fn install_components(source: &Path, l: &Layout, manifest: &Manifest, log: &mut Log) -> Result<(), String> {
    manifest.verify(source).map_err(|e| format!("Componenti forniti da EMP Desk: {e}"))?;
    log.line("Componenti verificati (SHA-256).");
    fs::create_dir_all(&l.program).map_err(|e| e.to_string())?;
    for c in COMPONENTS {
        let dst = l.program.join(c);
        if dst.exists() {
            fs::remove_dir_all(&dst).map_err(|e| format!("Rimozione di {} non riuscita: {e}", dst.display()))?;
        }
        copy_dir(&source.join(c), &dst)?;
    }
    manifest.verify(&l.program).map_err(|e| format!("Verifica dopo la copia: {e}"))?;
    // Il programma del servizio stesso.
    fs::create_dir_all(l.bin()).map_err(|e| e.to_string())?;
    let me = std::env::current_exe().map_err(|e| e.to_string())?;
    let target = l.bin().join(crate::layout::exe("emp-servizi"));
    if me != target {
        fs::copy(&me, &target).map_err(|e| format!("Copia di emp-servizi non riuscita: {e}"))?;
    }
    log.line(&format!("Componenti installati in {}.", l.program.display()));
    Ok(())
}

fn run(cmd: &mut Command, what: &str) -> Result<String, String> {
    crate::supervisor::no_window(cmd);
    let out = cmd.output().map_err(|e| format!("{what}: {e}"))?;
    let text = format!("{}{}", String::from_utf8_lossy(&out.stdout), String::from_utf8_lossy(&out.stderr)).trim().to_string();
    if out.status.success() {
        Ok(text)
    } else {
        Err(format!("{what} non riuscito: {text}"))
    }
}

/// Permessi delle cartelle dati: sistema e amministratori controllo completo,
/// account del servizio (LocalService) modifica, utenti nessun accesso
/// tranne la lettura dei log.
pub fn secure_data_dirs(l: &Layout) -> Result<(), String> {
    for d in l.data_dirs() {
        // Una cartella preparata in anticipo come collegamento (junction) potrebbe
        // dirottare i dati altrove: rifiutata.
        if let Ok(m) = fs::symlink_metadata(&d) {
            if m.file_type().is_symlink() {
                return Err(format!("{} è un collegamento: installazione interrotta per sicurezza", d.display()));
            }
        }
        fs::create_dir_all(&d).map_err(|e| format!("{}: {e}", d.display()))?;
    }
    if cfg!(windows) {
        // Proprietario: Administrators (una cartella creata prima da un altro utente non resta sua).
        let data = l.data.display().to_string();
        run(Command::new("icacls").args([&data, "/setowner", "*S-1-5-32-544", "/T", "/C", "/Q"]), "Proprietario dei dati")?;
        const SYSTEM: &str = "*S-1-5-18:(OI)(CI)F";
        const ADMINS: &str = "*S-1-5-32-544:(OI)(CI)F";
        const LOCAL_SERVICE: &str = "*S-1-5-19:(OI)(CI)M";
        run(Command::new("icacls").args([&data, "/inheritance:r", "/grant:r", SYSTEM, ADMINS, LOCAL_SERVICE]), "Permessi dei dati")?;
        // Le sottocartelle ereditano solo dalla radice appena protetta.
        run(Command::new("icacls").args([&data, "/reset", "/T", "/C", "/Q"]), "Permessi delle sottocartelle")?;
        run(Command::new("icacls").args([&data, "/inheritance:r", "/grant:r", SYSTEM, ADMINS, LOCAL_SERVICE]), "Permessi dei dati")?;
        let logs = l.logs().display().to_string();
        run(Command::new("icacls").args([&logs, "/grant", "*S-1-5-32-545:(OI)(CI)RX"]), "Permessi dei log")?;
    }
    Ok(())
}

pub fn write_configs(l: &Layout) -> Result<(), String> {
    for (path, content) in [(l.my_ini(), configs::my_ini(l)), (l.php_ini(), configs::php_ini(l)), (l.caddyfile(), configs::caddyfile(l))] {
        fs::write(&path, content).map_err(|e| format!("{}: {e}", path.display()))?;
    }
    Ok(())
}

/// Prima inizializzazione di MariaDB: dati vuoti → mariadb-install-db con password di root casuale.
pub fn init_database_files(l: &Layout, log: &mut Log) -> Result<bool, String> {
    if l.db_data().join("mysql").is_dir() {
        return Ok(false);
    }
    let root_pw = configs::random_password();
    run(
        Command::new(l.mariadb_bin("mariadb-install-db"))
            .arg(format!("--datadir={}", l.db_data().display()))
            .arg(format!("--password={root_pw}"))
            .arg(format!("--port={DB_PORT}")),
        "Inizializzazione di MariaDB",
    )?;
    fs::write(l.root_cnf(), configs::root_cnf(&root_pw)).map_err(|e| e.to_string())?;
    log.line("MariaDB inizializzato (password di amministrazione casuale, conservata in segreti\\).");
    Ok(true)
}

/// Database e utente applicativo, poi config.php del Core (solo se manca).
pub fn ensure_app_database(l: &Layout, log: &mut Log) -> Result<(), String> {
    if l.core_config().is_file() {
        return Ok(());
    }
    let pw = configs::random_password();
    run(
        Command::new(l.mariadb_bin("mariadb"))
            .arg(format!("--defaults-extra-file={}", l.root_cnf().display()))
            .arg("-e")
            .arg(configs::create_db_sql(&pw)),
        "Creazione del database",
    )?;
    fs::write(l.core_config(), configs::core_config(l, &pw)).map_err(|e| e.to_string())?;
    log.line("Database 'emp' e utente applicativo creati.");
    Ok(())
}

pub fn install_core(l: &Layout, log: &mut Log) -> Result<(), String> {
    let step = emp_core_installer::install_core(&l.packages(), &l.modules())?;
    log.line(&format!("Core: {} {}.", step.action, step.version));
    Ok(())
}

pub fn migrate(l: &Layout, log: &mut Log) -> Result<(), String> {
    let core = l.modules().join("emp-core").join("bin").join("emp");
    let out = run(
        Command::new(l.php_cli())
            .arg("-c")
            .arg(l.php_ini())
            .arg(core)
            .args(["installa", "--utente=emp-installazione", "emp-core"])
            .env("EMP_CONFIG", l.core_config()),
        "Preparazione del database del Core",
    )?;
    log.line(&out);
    Ok(())
}

#[cfg(windows)]
pub fn is_admin() -> bool {
    // SAFETY: funzione di sistema senza parametri.
    unsafe { windows_sys::Win32::UI::Shell::IsUserAnAdmin() != 0 }
}
#[cfg(not(windows))]
pub fn is_admin() -> bool {
    false
}

#[cfg(windows)]
pub fn install(source: &Path, l: &Layout) -> Result<(), String> {
    let mut log = Log::open(l);
    log.line(&format!("=== Installazione ambiente EMP {} ===", env!("CARGO_PKG_VERSION")));
    if !is_admin() {
        return Err("Servono i permessi di amministratore".into());
    }
    let manifest = Manifest::embedded();
    let existed = crate::service::exists();
    if existed {
        log.line("Ambiente già presente: aggiornamento.");
        crate::service::stop()?;
    } else {
        let busy = busy_ports();
        if !busy.is_empty() {
            return Err(format!(
                "Porte già in uso da altri programmi: {:?}. Chiuderli (ad esempio altri web server o database) e riprovare.",
                busy
            ));
        }
    }
    install_components(source, l, &manifest, &mut log)?;
    secure_data_dirs(l)?;
    write_configs(l)?;
    init_database_files(l, &mut log)?;
    install_core(l, &mut log)?;
    crate::service::install_or_update(l)?;
    crate::service::start()?;
    log.line("Servizio EMPServer avviato.");
    if !crate::supervisor::wait_port(DB_PORT, Duration::from_secs(90)) {
        return Err("MariaDB non risponde: vedere mariadb.log".into());
    }
    ensure_app_database(l, &mut log)?;
    migrate(l, &mut log)?;
    wait_health(Duration::from_secs(60))?;
    log.line("Ambiente pronto: http://127.0.0.1/amministrazione/");
    Ok(())
}

#[cfg(windows)]
pub fn uninstall(l: &Layout) -> Result<(), String> {
    let mut log = Log::open(l);
    log.line("=== Disinstallazione ambiente EMP ===");
    if !is_admin() {
        return Err("Servono i permessi di amministratore".into());
    }
    crate::service::remove()?;
    for c in COMPONENTS.iter().chain(["bin"].iter()) {
        let p = l.program.join(c);
        if p.exists() {
            // Il programma in uso non si può cancellare: verrà rimosso al riavvio successivo.
            let _ = fs::remove_dir_all(&p);
        }
    }
    log.line(&format!("Servizio e programmi rimossi. I dati restano in {}.", l.data.display()));
    Ok(())
}
