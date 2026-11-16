//! Cartelle dell'ambiente EMP sul computer Server.
//!
//! - Programmi: `%ProgramFiles%\EMP` — scrivibile solo dagli amministratori.
//!   Il servizio esegue solo file da qui, mai da cartelle dell'utente.
//! - Dati: `%ProgramData%\EMP` — accessibile al sistema, agli amministratori e
//!   all'account del servizio (LocalService); gli utenti leggono solo i log.

use std::path::{Path, PathBuf};

pub const SERVICE_NAME: &str = "EMPServer";
pub const SERVICE_DISPLAY: &str = "EMP Server — Edomea Marketplace";
pub const HTTP_PORT: u16 = 80;
pub const DB_PORT: u16 = 3306;
pub const FASTCGI_PORTS: [u16; 4] = [9000, 9001, 9002, 9003];
pub const DB_NAME: &str = "emp";
pub const DB_USER: &str = "emp";

#[derive(Debug, Clone)]
pub struct Layout {
    pub program: PathBuf,
    pub data: PathBuf,
}

impl Layout {
    pub fn new(program: impl Into<PathBuf>, data: impl Into<PathBuf>) -> Self {
        Layout { program: program.into(), data: data.into() }
    }

    /// Percorsi standard di Windows.
    pub fn system() -> Self {
        let pf = std::env::var("ProgramFiles").unwrap_or_else(|_| r"C:\Program Files".into());
        let pd = std::env::var("ProgramData").unwrap_or_else(|_| r"C:\ProgramData".into());
        Layout::new(Path::new(&pf).join("EMP"), Path::new(&pd).join("EMP"))
    }

    // --- programmi
    pub fn bin(&self) -> PathBuf {
        self.program.join("bin")
    }
    pub fn php_dir(&self) -> PathBuf {
        self.program.join("php")
    }
    pub fn php_cgi(&self) -> PathBuf {
        self.php_dir().join(exe("php-cgi"))
    }
    pub fn php_cli(&self) -> PathBuf {
        self.php_dir().join(exe("php"))
    }
    pub fn mariadb_dir(&self) -> PathBuf {
        self.program.join("mariadb")
    }
    pub fn mariadb_bin(&self, name: &str) -> PathBuf {
        self.mariadb_dir().join("bin").join(exe(name))
    }
    pub fn caddy(&self) -> PathBuf {
        self.program.join("caddy").join(exe("caddy"))
    }
    pub fn packages(&self) -> PathBuf {
        self.program.join("pacchetti")
    }

    // --- dati
    pub fn db_data(&self) -> PathBuf {
        self.data.join("mariadb")
    }
    pub fn my_ini(&self) -> PathBuf {
        self.data.join("config").join("my.ini")
    }
    pub fn php_ini(&self) -> PathBuf {
        self.data.join("config").join("php.ini")
    }
    pub fn caddyfile(&self) -> PathBuf {
        self.data.join("config").join("Caddyfile")
    }
    pub fn core_config(&self) -> PathBuf {
        self.data.join("dati").join("config.php")
    }
    pub fn secrets(&self) -> PathBuf {
        self.data.join("segreti")
    }
    pub fn root_cnf(&self) -> PathBuf {
        self.secrets().join("mariadb-root.cnf")
    }
    pub fn modules(&self) -> PathBuf {
        self.data.join("modules")
    }
    pub fn catalog(&self) -> PathBuf {
        self.data.join("catalogo")
    }
    pub fn caddy_data(&self) -> PathBuf {
        self.data.join("caddy")
    }
    pub fn tmp(&self) -> PathBuf {
        self.data.join("tmp")
    }
    pub fn logs(&self) -> PathBuf {
        self.data.join("log")
    }
    pub fn install_log(&self) -> PathBuf {
        self.logs().join("installazione.log")
    }

    /// Cartelle di dati da creare (i log a parte: leggibili dagli utenti).
    pub fn data_dirs(&self) -> Vec<PathBuf> {
        vec![
            self.data.clone(),
            self.db_data(),
            self.data.join("config"),
            self.data.join("dati"),
            self.secrets(),
            self.modules(),
            self.catalog(),
            self.caddy_data(),
            self.tmp(),
            self.logs(),
        ]
    }
}

pub fn exe(name: &str) -> String {
    if cfg!(windows) {
        format!("{name}.exe")
    } else {
        name.to_string()
    }
}

/// Percorso con "/" (accettato da PHP, MariaDB e Caddy anche su Windows).
pub fn slash(p: &Path) -> String {
    p.display().to_string().replace('\\', "/")
}
