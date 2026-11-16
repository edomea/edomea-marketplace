//! Supervisione dei processi dell'ambiente: MariaDB, PHP (FastCGI) e Caddy.
//!
//! Ordine di avvio: MariaDB (attesa della porta) → PHP → Caddy.
//! Un processo che termina viene riavviato con attesa crescente (1 s → 60 s).
//! All'arresto: Caddy e PHP terminati, MariaDB chiuso in modo ordinato.

use crate::layout::{Layout, DB_PORT, FASTCGI_PORTS};
use std::fs::OpenOptions;
use std::net::{SocketAddr, TcpStream};
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

#[derive(Debug, Clone)]
pub struct Spec {
    pub name: String,
    pub program: PathBuf,
    pub args: Vec<String>,
    pub env: Vec<(String, String)>,
    pub log: PathBuf,
}

impl Spec {
    pub fn command(&self) -> Command {
        let mut c = Command::new(&self.program);
        c.args(&self.args).stdin(Stdio::null());
        for (k, v) in &self.env {
            c.env(k, v);
        }
        if let Ok(f) = OpenOptions::new().create(true).append(true).open(&self.log) {
            if let Ok(f2) = f.try_clone() {
                c.stdout(f).stderr(f2);
            }
        }
        no_window(&mut c);
        c
    }
}

#[cfg(windows)]
pub fn no_window(c: &mut Command) {
    use std::os::windows::process::CommandExt;
    c.creation_flags(0x0800_0000); // CREATE_NO_WINDOW
}
#[cfg(not(windows))]
pub fn no_window(_c: &mut Command) {}

/// Processi dell'ambiente, nell'ordine di avvio.
pub fn environment_specs(l: &Layout) -> Vec<Spec> {
    let log = |n: &str| l.logs().join(format!("{n}.log"));
    let mut specs = vec![Spec {
        name: "mariadb".into(),
        program: l.mariadb_bin("mariadbd"),
        args: vec![format!("--defaults-file={}", l.my_ini().display()), "--console".into()],
        env: vec![],
        log: log("mariadb-console"),
    }];
    for port in FASTCGI_PORTS {
        specs.push(Spec {
            name: format!("php-{port}"),
            program: l.php_cgi(),
            args: vec!["-c".into(), l.php_ini().display().to_string(), "-b".into(), format!("127.0.0.1:{port}")],
            env: vec![
                ("EMP_CONFIG".into(), l.core_config().display().to_string()),
                ("PHP_FCGI_MAX_REQUESTS".into(), "10000".into()),
            ],
            log: log(&format!("php-{port}")),
        });
    }
    specs.push(Spec {
        name: "caddy".into(),
        program: l.caddy(),
        args: vec!["run".into(), "--config".into(), l.caddyfile().display().to_string(), "--adapter".into(), "caddyfile".into()],
        env: vec![
            ("XDG_DATA_HOME".into(), l.caddy_data().display().to_string()),
            ("XDG_CONFIG_HOME".into(), l.caddy_data().display().to_string()),
            ("AppData".into(), l.caddy_data().display().to_string()),
        ],
        log: log("caddy"),
    });
    specs
}

pub fn wait_port(port: u16, timeout: Duration) -> bool {
    let addr: SocketAddr = ([127, 0, 0, 1], port).into();
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if TcpStream::connect_timeout(&addr, Duration::from_millis(300)).is_ok() {
            return true;
        }
        std::thread::sleep(Duration::from_millis(300));
    }
    false
}

struct Managed {
    spec: Spec,
    child: Option<Child>,
    restarts: u32,
    next_start: Instant,
}

pub struct Supervisor {
    procs: Vec<Managed>,
    /// Porta da attendere dopo l'avvio del primo processo (MariaDB), se presente.
    pub wait_first_port: Option<u16>,
    shutdown_db: Option<Spec>,
}

impl Supervisor {
    pub fn new(specs: Vec<Spec>) -> Self {
        let now = Instant::now();
        Supervisor {
            procs: specs.into_iter().map(|spec| Managed { spec, child: None, restarts: 0, next_start: now }).collect(),
            wait_first_port: None,
            shutdown_db: None,
        }
    }

    pub fn for_environment(l: &Layout) -> Self {
        let mut s = Supervisor::new(environment_specs(l));
        s.wait_first_port = Some(DB_PORT);
        s.shutdown_db = Some(Spec {
            name: "mariadb-shutdown".into(),
            program: l.mariadb_bin("mariadb-admin"),
            args: vec![format!("--defaults-extra-file={}", l.root_cnf().display()), "shutdown".into()],
            env: vec![],
            log: l.logs().join("mariadb-console.log"),
        });
        s
    }

    fn spawn(m: &mut Managed) {
        match m.spec.command().spawn() {
            Ok(c) => m.child = Some(c),
            Err(e) => {
                eprintln!("[EMP] avvio di {} non riuscito: {e}", m.spec.name);
                m.child = None;
            }
        }
    }

    /// Avvia tutti i processi nell'ordine.
    pub fn start_all(&mut self) {
        for i in 0..self.procs.len() {
            Self::spawn(&mut self.procs[i]);
            if i == 0 {
                if let Some(port) = self.wait_first_port {
                    if !wait_port(port, Duration::from_secs(60)) {
                        eprintln!("[EMP] MariaDB non risponde sulla porta {port}");
                    }
                }
            }
        }
    }

    /// Un giro di controllo: riavvia i processi terminati. Restituisce i riavvii fatti.
    pub fn tick(&mut self) -> usize {
        let mut restarted = 0;
        let now = Instant::now();
        for m in &mut self.procs {
            let alive = match m.child.as_mut() {
                Some(c) => matches!(c.try_wait(), Ok(None)),
                None => false,
            };
            if alive {
                continue;
            }
            if m.child.take().is_some() {
                m.restarts += 1;
                let backoff = Duration::from_secs((1u64 << m.restarts.min(6)).min(60));
                m.next_start = now + backoff;
                eprintln!("[EMP] {} terminato, nuovo avvio tra {:?}", m.spec.name, backoff);
                continue;
            }
            if now >= m.next_start {
                Self::spawn(m);
                restarted += 1;
            }
        }
        restarted
    }

    /// Ciclo principale fino alla richiesta di arresto.
    pub fn run(&mut self, stop: &AtomicBool) {
        self.start_all();
        while !stop.load(Ordering::SeqCst) {
            std::thread::sleep(Duration::from_millis(500));
            self.tick();
        }
        self.stop_all();
    }

    pub fn running(&mut self) -> usize {
        let mut n = 0;
        for m in &mut self.procs {
            if let Some(c) = m.child.as_mut() {
                if matches!(c.try_wait(), Ok(None)) {
                    n += 1;
                }
            }
        }
        n
    }

    pub fn restarts(&self, name: &str) -> u32 {
        self.procs.iter().find(|m| m.spec.name == name).map(|m| m.restarts).unwrap_or(0)
    }

    /// Arresto: prima Caddy e PHP, poi MariaDB in modo ordinato.
    pub fn stop_all(&mut self) {
        for m in self.procs.iter_mut().skip(1).rev() {
            if let Some(mut c) = m.child.take() {
                let _ = c.kill();
                let _ = c.wait();
            }
        }
        if let Some(first) = self.procs.first_mut() {
            if let Some(mut c) = first.child.take() {
                if let Some(spec) = &self.shutdown_db {
                    let _ = spec.command().status();
                    let deadline = Instant::now() + Duration::from_secs(30);
                    while Instant::now() < deadline {
                        if let Ok(Some(_)) = c.try_wait() {
                            return;
                        }
                        std::thread::sleep(Duration::from_millis(250));
                    }
                }
                let _ = c.kill();
                let _ = c.wait();
            }
        }
    }
}

#[cfg(all(test, unix))]
mod tests {
    use super::*;

    fn spec(name: &str, program: &str, args: &[&str], log: &std::path::Path) -> Spec {
        Spec { name: name.into(), program: program.into(), args: args.iter().map(|s| s.to_string()).collect(), env: vec![], log: log.join(format!("{name}.log")) }
    }

    #[test]
    fn restarts_crashed_process_and_stops_all() {
        let d = tempfile::tempdir().unwrap();
        let mut s = Supervisor::new(vec![spec("lungo", "sleep", &["30"], d.path()), spec("breve", "true", &[], d.path())]);
        s.start_all();
        std::thread::sleep(Duration::from_millis(300));
        s.tick(); // "breve" è terminato: programmato un nuovo avvio
        assert_eq!(s.restarts("breve"), 1);
        assert_eq!(s.restarts("lungo"), 0);
        std::thread::sleep(Duration::from_millis(2200));
        assert_eq!(s.tick(), 1, "riavviato dopo l'attesa");
        s.stop_all();
        assert_eq!(s.running(), 0);
    }

    #[test]
    fn environment_order_and_loopback() {
        let l = Layout::new("/p", "/d");
        let specs = environment_specs(&l);
        let names: Vec<_> = specs.iter().map(|s| s.name.as_str()).collect();
        assert_eq!(names, ["mariadb", "php-9000", "php-9001", "php-9002", "php-9003", "caddy"]);
        assert!(specs[1].args.contains(&"127.0.0.1:9000".to_string()));
        assert!(specs[1].env.iter().any(|(k, _)| k == "EMP_CONFIG"));
    }
}
