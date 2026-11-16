//! Verifica dei componenti (PHP, MariaDB, Caddy, pacchetto del Core) con le
//! impronte SHA-256 incorporate nel programma al momento della build (CI).
//!
//! La verifica si ripete sui file copiati in `%ProgramFiles%\EMP`, cartella
//! scrivibile solo dagli amministratori: un file alterato prima o durante la
//! copia viene rifiutato prima che il servizio lo esegua.

use serde::Deserialize;
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::fs;
use std::io::Read;
use std::path::{Component, Path};

/// Cartelle di primo livello coperte dal manifest.
pub const COMPONENTS: [&str; 4] = ["php", "mariadb", "caddy", "pacchetti"];

#[derive(Debug, Deserialize, Default)]
pub struct Manifest {
    #[serde(default)]
    pub versions: BTreeMap<String, String>,
    /// percorso relativo con "/" → sha256
    #[serde(default)]
    pub files: BTreeMap<String, String>,
}

impl Manifest {
    pub fn embedded() -> Manifest {
        Self::parse(include_str!(concat!(env!("OUT_DIR"), "/manifest.json"))).unwrap_or_default()
    }

    pub fn parse(text: &str) -> Result<Manifest, String> {
        let m: Manifest = serde_json::from_str(text).map_err(|e| format!("Manifest dei componenti non valido: {e}"))?;
        for (path, hash) in &m.files {
            if !safe_relative(path) || hash.len() != 64 || !hash.bytes().all(|c| c.is_ascii_hexdigit()) {
                return Err(format!("Manifest dei componenti: voce non valida ({path})"));
            }
        }
        Ok(m)
    }

    pub fn is_empty(&self) -> bool {
        self.files.is_empty()
    }

    /// Verifica che `root` contenga esattamente i file del manifest per i componenti,
    /// con le impronte attese. Nessun file in più, nessuno in meno, nessun collegamento.
    pub fn verify(&self, root: &Path) -> Result<usize, String> {
        if self.is_empty() {
            return Err("Questa build non contiene il manifest dei componenti: installazione non consentita".into());
        }
        let mut found = BTreeMap::new();
        for c in COMPONENTS {
            collect(&root.join(c), c, &mut found)?;
        }
        for (path, expected) in &self.files {
            match found.remove(path) {
                None => return Err(format!("Componente mancante: {path}")),
                Some(actual) if !actual.eq_ignore_ascii_case(expected) => return Err(format!("Componente alterato: {path}")),
                Some(_) => {}
            }
        }
        if let Some(extra) = found.keys().next() {
            return Err(format!("File non previsto tra i componenti: {extra}"));
        }
        Ok(self.files.len())
    }
}

fn safe_relative(p: &str) -> bool {
    !p.is_empty()
        && !p.contains('\\')
        && COMPONENTS.iter().any(|c| p.starts_with(&format!("{c}/")))
        && Path::new(p).components().all(|c| matches!(c, Component::Normal(_)))
}

pub fn sha256_file(path: &Path) -> Result<String, String> {
    let mut f = fs::File::open(path).map_err(|e| format!("{}: {e}", path.display()))?;
    let mut h = Sha256::new();
    let mut buf = vec![0u8; 1 << 16];
    loop {
        let n = f.read(&mut buf).map_err(|e| format!("{}: {e}", path.display()))?;
        if n == 0 {
            break;
        }
        h.update(&buf[..n]);
    }
    Ok(h.finalize().iter().map(|b| format!("{b:02x}")).collect())
}

fn collect(dir: &Path, rel: &str, out: &mut BTreeMap<String, String>) -> Result<(), String> {
    let meta = fs::symlink_metadata(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    if meta.file_type().is_symlink() {
        return Err(format!("Collegamento non ammesso: {rel}"));
    }
    if meta.is_file() {
        out.insert(rel.to_string(), sha256_file(dir)?);
        return Ok(());
    }
    let mut entries: Vec<_> = fs::read_dir(dir).map_err(|e| format!("{}: {e}", dir.display()))?.collect::<Result<_, _>>().map_err(|e| e.to_string())?;
    entries.sort_by_key(|e| e.file_name());
    for e in entries {
        let name = e.file_name().to_string_lossy().to_string();
        collect(&e.path(), &format!("{rel}/{name}"), out)?;
    }
    Ok(())
}

/// Genera il manifest di una cartella di componenti (usato dalla CI).
pub fn generate(root: &Path, versions: BTreeMap<String, String>) -> Result<String, String> {
    let mut files = BTreeMap::new();
    for c in COMPONENTS {
        collect(&root.join(c), c, &mut files)?;
    }
    serde_json::to_string_pretty(&serde_json::json!({ "versions": versions, "files": files })).map_err(|e| e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tree() -> tempfile::TempDir {
        let d = tempfile::tempdir().unwrap();
        for c in COMPONENTS {
            fs::create_dir_all(d.path().join(c).join("sub")).unwrap();
            fs::write(d.path().join(c).join("sub").join("a.txt"), c).unwrap();
        }
        d
    }

    #[test]
    fn generate_and_verify() {
        let d = tree();
        let m = Manifest::parse(&generate(d.path(), BTreeMap::new()).unwrap()).unwrap();
        assert_eq!(m.verify(d.path()).unwrap(), 4);

        fs::write(d.path().join("php/sub/a.txt"), "alterato").unwrap();
        assert!(m.verify(d.path()).unwrap_err().contains("alterato"));
        fs::write(d.path().join("php/sub/a.txt"), "php").unwrap();

        fs::write(d.path().join("caddy/extra.exe"), "x").unwrap();
        assert!(m.verify(d.path()).unwrap_err().contains("non previsto"));
        fs::remove_file(d.path().join("caddy/extra.exe")).unwrap();

        fs::remove_file(d.path().join("mariadb/sub/a.txt")).unwrap();
        assert!(m.verify(d.path()).unwrap_err().contains("mancante"));
    }

    #[test]
    fn empty_manifest_refuses() {
        let d = tree();
        assert!(Manifest::default().verify(d.path()).unwrap_err().contains("non consentita"));
    }

    #[test]
    fn bad_entries_rejected() {
        assert!(Manifest::parse(r#"{"files":{"../x":"00"}}"#).is_err());
        assert!(Manifest::parse(&format!(r#"{{"files":{{"altro/x":"{}"}}}}"#, "0".repeat(64))).is_err());
        assert!(Manifest::parse(&format!(r#"{{"files":{{"php/x":"{}"}}}}"#, "0".repeat(64))).is_ok());
    }
}
