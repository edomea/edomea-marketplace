//! File di configurazione generati per l'ambiente: MariaDB, PHP, Caddy e Core.
//! Tutto ascolta solo su 127.0.0.1: le postazioni Client arriveranno con HTTPS (Macro 3).

use crate::layout::{slash, Layout, DB_NAME, DB_PORT, DB_USER, FASTCGI_PORTS, HTTP_PORT};

pub fn my_ini(l: &Layout) -> String {
    format!(
        "# Generato da EMP Server. MariaDB raggiungibile solo da questo computer.\n\
         [mysqld]\n\
         datadir={datadir}\n\
         port={DB_PORT}\n\
         bind-address=127.0.0.1\n\
         skip-name-resolve\n\
         character-set-server=utf8mb4\n\
         collation-server=utf8mb4_unicode_ci\n\
         innodb_buffer_pool_size=512M\n\
         max_connections=100\n\
         log-error={log}\n\
         [client]\n\
         port={DB_PORT}\n\
         default-character-set=utf8mb4\n",
        datadir = slash(&l.db_data()),
        log = slash(&l.logs().join("mariadb.log")),
    )
}

pub fn php_ini(l: &Layout) -> String {
    let open_basedir = [l.modules(), l.data.join("dati"), l.catalog(), l.tmp()]
        .iter()
        .map(|p| slash(p))
        .collect::<Vec<_>>()
        .join(";");
    format!(
        "; Generato da EMP Server.\n\
         extension_dir=\"{ext}\"\n\
         extension=pdo_mysql\n\
         extension=pdo_sqlite\n\
         extension=mbstring\n\
         extension=zip\n\
         memory_limit=256M\n\
         max_execution_time=120\n\
         display_errors=Off\n\
         log_errors=On\n\
         error_log=\"{errlog}\"\n\
         expose_php=Off\n\
         cgi.fix_pathinfo=0\n\
         allow_url_fopen=Off\n\
         open_basedir=\"{open_basedir}\"\n\
         sys_temp_dir=\"{tmp}\"\n\
         upload_tmp_dir=\"{tmp}\"\n\
         date.timezone=Europe/Rome\n",
        ext = slash(&l.php_dir().join("ext")),
        errlog = slash(&l.logs().join("php-errori.log")),
        tmp = slash(&l.tmp()),
    )
}

pub fn caddyfile(l: &Layout) -> String {
    let upstreams = FASTCGI_PORTS.iter().map(|p| format!("127.0.0.1:{p}")).collect::<Vec<_>>().join(" ");
    format!(
        "# Generato da EMP Server. Core raggiungibile solo da questo computer (HTTPS in LAN: Macro 3).\n\
         {{\n\
         \tadmin off\n\
         \tauto_https off\n\
         \tpersist_config off\n\
         \tstorage file_system {storage}\n\
         }}\n\
         \n\
         http://127.0.0.1:{HTTP_PORT}, http://localhost:{HTTP_PORT} {{\n\
         \tbind 127.0.0.1\n\
         \troot * {root}\n\
         \tencode gzip\n\
         \theader {{\n\
         \t\tX-Content-Type-Options nosniff\n\
         \t\tX-Frame-Options DENY\n\
         \t\tReferrer-Policy no-referrer\n\
         \t\t-Server\n\
         \t}}\n\
         \tphp_fastcgi {upstreams} {{\n\
         \t\tenv EMP_CONFIG {config}\n\
         \t}}\n\
         \tfile_server\n\
         \tlog {{\n\
         \t\toutput file {access}\n\
         \t}}\n\
         }}\n",
        storage = slash(&l.caddy_data()),
        root = slash(&l.modules().join("emp-core").join("public")),
        config = slash(&l.core_config()),
        access = slash(&l.logs().join("caddy-accessi.log")),
    )
}

/// Stringa PHP tra apici singoli.
pub fn php_string(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}

pub fn core_config(l: &Layout, db_password: &str) -> String {
    format!(
        "<?php\n// Generato da EMP Server. Contiene credenziali: non copiare fuori da questo computer.\nreturn [\n    'dsn' => {dsn},\n    'user' => {user},\n    'password' => {pw},\n    'prefix' => 'emp_',\n    'timezone' => 'Europe/Rome',\n    'modules_dir' => {modules},\n    'catalog_dir' => {catalog},\n];\n",
        dsn = php_string(&format!("mysql:host=127.0.0.1;port={DB_PORT};dbname={DB_NAME};charset=utf8mb4")),
        user = php_string(DB_USER),
        pw = php_string(db_password),
        modules = php_string(&slash(&l.modules())),
        catalog = php_string(&slash(&l.catalog())),
    )
}

/// File di credenziali per i comandi di amministrazione di MariaDB.
pub fn root_cnf(root_password: &str) -> String {
    format!("[client]\nuser=root\npassword={root_password}\nhost=127.0.0.1\nport={DB_PORT}\n")
}

/// SQL di creazione del database e dell'utente applicativo (solo da 127.0.0.1).
pub fn create_db_sql(db_password: &str) -> String {
    format!(
        "CREATE DATABASE IF NOT EXISTS `{DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
         CREATE USER IF NOT EXISTS '{DB_USER}'@'127.0.0.1' IDENTIFIED BY '{db_password}'; \
         CREATE USER IF NOT EXISTS '{DB_USER}'@'localhost' IDENTIFIED BY '{db_password}'; \
         GRANT ALL PRIVILEGES ON `{DB_NAME}`.* TO '{DB_USER}'@'127.0.0.1'; \
         GRANT ALL PRIVILEGES ON `{DB_NAME}`.* TO '{DB_USER}'@'localhost'; \
         DELETE FROM mysql.global_priv WHERE User = ''; \
         FLUSH PRIVILEGES;"
    )
}

/// Password casuale di 32 caratteri alfanumerici (sicura dentro SQL e file .cnf).
pub fn random_password() -> String {
    const CHARS: &[u8] = b"ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";
    let mut out = String::with_capacity(32);
    while out.len() < 32 {
        let mut b = [0u8; 64];
        getrandom::fill(&mut b).expect("generatore casuale del sistema non disponibile");
        for x in b {
            // Scarta i valori che introdurrebbero uno sbilanciamento.
            if (x as usize) < 256 - (256 % CHARS.len()) && out.len() < 32 {
                out.push(CHARS[x as usize % CHARS.len()] as char);
            }
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn layout() -> Layout {
        Layout::new(r"C:\Program Files\EMP", r"C:\ProgramData\EMP")
    }

    #[test]
    fn everything_listens_on_loopback() {
        let l = layout();
        assert!(my_ini(&l).contains("bind-address=127.0.0.1"));
        assert!(my_ini(&l).contains("datadir=C:/ProgramData/EMP/mariadb"));
        let c = caddyfile(&l);
        assert!(c.contains("bind 127.0.0.1"));
        assert!(c.contains("admin off"));
        assert!(c.contains("root * C:/ProgramData/EMP/modules/emp-core/public"));
        assert!(c.contains("php_fastcgi 127.0.0.1:9000 127.0.0.1:9001 127.0.0.1:9002 127.0.0.1:9003"));
        assert!(c.contains("env EMP_CONFIG C:/ProgramData/EMP/dati/config.php"));
    }

    #[test]
    fn php_is_hardened() {
        let p = php_ini(&layout());
        for needle in ["extension=pdo_mysql", "display_errors=Off", "expose_php=Off", "allow_url_fopen=Off", "cgi.fix_pathinfo=0", "open_basedir=\"C:/ProgramData/EMP/modules;"] {
            assert!(p.contains(needle), "{needle}");
        }
    }

    #[test]
    fn core_config_and_sql() {
        let c = core_config(&layout(), "Abc123");
        assert!(c.contains("'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=emp;charset=utf8mb4'"));
        assert!(c.contains("'password' => 'Abc123'"));
        assert!(c.contains("'modules_dir' => 'C:/ProgramData/EMP/modules'"));
        let sql = create_db_sql("Abc123");
        assert!(sql.contains("'emp'@'127.0.0.1' IDENTIFIED BY 'Abc123'"));
        assert!(!sql.contains("'%'"), "mai accesso da altri computer");
    }

    #[test]
    fn passwords_are_random_and_safe() {
        let a = random_password();
        let b = random_password();
        assert_eq!(a.len(), 32);
        assert_ne!(a, b);
        assert!(a.chars().all(|c| c.is_ascii_alphanumeric()));
    }
}
