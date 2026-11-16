//! Pacchetti ZIP dei moduli e indice emp-packages.json.
//! Verifica SHA-256 prima di aprire l'archivio; estrazione in una cartella
//! temporanea con controllo di ogni voce; sostituzione solo a estrazione completa,
//! conservando la versione precedente in modules/.precedenti/ per il ripristino.

use crate::manifest::{self, Manifest};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::fs;
use std::io::{Read, Write};
use std::path::{Component, Path, PathBuf};

pub const INDEX_FILE: &str = "emp-packages.json";
pub const STATE_FILE: &str = "installed.json";
const MAX_PACKAGE_BYTES: u64 = 256 * 1024 * 1024;
const MAX_UNPACKED_BYTES: u64 = 1024 * 1024 * 1024;
const MAX_ENTRIES: usize = 20_000;

#[derive(Debug, Clone, Deserialize)]
pub struct IndexEntry {
    pub id: String,
    pub version: String,
    pub file: String,
    pub sha256: String,
}

#[derive(Deserialize)]
struct Index {
    schema: u32,
    packages: Vec<IndexEntry>,
}

/// Pacchetto con impronta verificata e manifest letto dall'archivio.
pub struct VerifiedPackage {
    pub manifest: Manifest,
    pub path: PathBuf,
    pub sha256: String,
    bytes: Vec<u8>,
}

#[derive(Debug, Default, Serialize, Deserialize, PartialEq)]
pub struct InstalledState {
    /// id → (versione, sha256 del pacchetto)
    pub modules: BTreeMap<String, InstalledModule>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct InstalledModule {
    pub version: String,
    pub sha256: String,
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

fn valid_file_name(name: &str) -> bool {
    !name.is_empty()
        && name.len() <= 128
        && name.ends_with(".zip")
        && !name.starts_with('.')
        && name.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'-' || c == b'_' || c == b'.')
}

/// Legge e valida l'indice dei pacchetti di una cartella di distribuzione.
pub fn load_index(dir: &Path) -> Result<Vec<IndexEntry>, String> {
    let path = dir.join(INDEX_FILE);
    let bytes = fs::read(&path).map_err(|e| format!("Indice pacchetti non leggibile ({}): {e}", path.display()))?;
    if bytes.len() > 1024 * 1024 {
        return Err("Indice pacchetti troppo grande".into());
    }
    let index: Index = serde_json::from_slice(&bytes).map_err(|e| format!("Indice pacchetti non valido: {e}"))?;
    if index.schema != 1 {
        return Err("Indice pacchetti: schema non supportato".into());
    }
    let mut seen = BTreeMap::new();
    for e in &index.packages {
        if !manifest::valid_id(&e.id) || !valid_file_name(&e.file) {
            return Err(format!("Indice pacchetti: voce non valida ({})", e.id));
        }
        if e.sha256.len() != 64 || !e.sha256.bytes().all(|c| c.is_ascii_hexdigit() && !c.is_ascii_uppercase()) {
            return Err(format!("Indice pacchetti: impronta SHA-256 non valida per {}", e.id));
        }
        if seen.insert(e.id.clone(), ()).is_some() {
            return Err(format!("Indice pacchetti: modulo duplicato {}", e.id));
        }
    }
    Ok(index.packages)
}

/// Verifica l'impronta e legge il manifest contenuto nel pacchetto.
pub fn verify(dir: &Path, entry: &IndexEntry) -> Result<VerifiedPackage, String> {
    let path = dir.join(&entry.file);
    let meta = fs::symlink_metadata(&path).map_err(|e| format!("Pacchetto {} non trovato: {e}", entry.file))?;
    if !meta.is_file() {
        return Err(format!("Pacchetto {} non è un file regolare", entry.file));
    }
    if meta.len() > MAX_PACKAGE_BYTES {
        return Err(format!("Pacchetto {} troppo grande", entry.file));
    }
    let bytes = fs::read(&path).map_err(|e| format!("Pacchetto {} illeggibile: {e}", entry.file))?;
    let digest = hex(&Sha256::digest(&bytes));
    if digest != entry.sha256 {
        return Err(format!("Pacchetto {}: impronta SHA-256 non corrispondente. Pacchetto rifiutato.", entry.file));
    }
    let mut zip = zip::ZipArchive::new(std::io::Cursor::new(&bytes)).map_err(|e| format!("Pacchetto {} non è uno ZIP valido: {e}", entry.file))?;
    let manifest_name = format!("{}/{}", entry.id, manifest::FILE);
    let mut raw = Vec::new();
    zip.by_name(&manifest_name)
        .map_err(|_| format!("Pacchetto {}: manca {manifest_name}", entry.file))?
        .take(65_537)
        .read_to_end(&mut raw)
        .map_err(|e| e.to_string())?;
    let manifest = Manifest::from_json(&raw)?;
    if manifest.id != entry.id || manifest.version.to_string() != entry.version {
        return Err(format!("Pacchetto {}: id o versione diversi da quelli dell'indice", entry.file));
    }
    Ok(VerifiedPackage { manifest, path, sha256: digest, bytes })
}

/// Verifica tutti i pacchetti dell'indice.
pub fn verify_all(dir: &Path) -> Result<Vec<VerifiedPackage>, String> {
    load_index(dir)?.iter().map(|e| verify(dir, e)).collect()
}

fn safe_relative(name: &Path, id: &str) -> Result<PathBuf, String> {
    let mut comps = name.components();
    match comps.next() {
        Some(Component::Normal(first)) if first == id => {}
        _ => return Err(format!("Voce fuori dalla cartella del modulo: {}", name.display())),
    }
    let mut out = PathBuf::from(id);
    for c in comps {
        match c {
            Component::Normal(part) => out.push(part),
            _ => return Err(format!("Percorso non ammesso nel pacchetto: {}", name.display())),
        }
    }
    Ok(out)
}

/// Estrae il pacchetto in `modules_dir/<id>` in modo sicuro.
pub fn install_files(pkg: &VerifiedPackage, modules_dir: &Path) -> Result<(), String> {
    let id = &pkg.manifest.id;
    fs::create_dir_all(modules_dir).map_err(|e| e.to_string())?;
    let staging = modules_dir.join(format!(".installazione-{id}"));
    if staging.exists() {
        fs::remove_dir_all(&staging).map_err(|e| format!("Pulizia cartella temporanea non riuscita: {e}"))?;
    }
    fs::create_dir(&staging).map_err(|e| e.to_string())?;

    let result = (|| {
        let mut zip = zip::ZipArchive::new(std::io::Cursor::new(&pkg.bytes)).map_err(|e| e.to_string())?;
        if zip.len() > MAX_ENTRIES {
            return Err("Pacchetto con troppi file".to_string());
        }
        let mut total: u64 = 0;
        for i in 0..zip.len() {
            let mut file = zip.by_index(i).map_err(|e| e.to_string())?;
            let shown = file.name().map(|n| n.into_owned()).unwrap_or_else(|_| "(nome non leggibile)".into());
            if file.is_symlink() {
                return Err(format!("Collegamento simbolico non ammesso: {shown}"));
            }
            let name = file.enclosed_name().ok_or_else(|| format!("Percorso non ammesso: {shown}"))?;
            let rel = safe_relative(&name, id)?;
            let target = staging.join(&rel);
            if file.is_dir() {
                fs::create_dir_all(&target).map_err(|e| e.to_string())?;
                continue;
            }
            if let Some(parent) = target.parent() {
                fs::create_dir_all(parent).map_err(|e| e.to_string())?;
            }
            let mut out = fs::OpenOptions::new().write(true).create_new(true).open(&target).map_err(|e| format!("{}: {e}", rel.display()))?;
            // Limite reale sui byte scritti, non sulla dimensione dichiarata.
            let limit = MAX_UNPACKED_BYTES - total;
            let written = std::io::copy(&mut (&mut file).take(limit + 1), &mut out).map_err(|e| e.to_string())?;
            total += written;
            if written > limit {
                return Err("Pacchetto troppo grande una volta estratto".into());
            }
            out.flush().map_err(|e| e.to_string())?;
        }
        if !staging.join(id).join(manifest::FILE).is_file() {
            return Err("Manifest assente dopo l'estrazione".into());
        }
        Ok(())
    })();
    if let Err(e) = result {
        let _ = fs::remove_dir_all(&staging);
        return Err(format!("{id}: {e}"));
    }

    let dest = modules_dir.join(id);
    if fs::symlink_metadata(&dest).is_ok() {
        let backups = modules_dir.join(".precedenti");
        fs::create_dir_all(&backups).map_err(|e| e.to_string())?;
        let mut n = 0u32;
        let backup = loop {
            let candidate = backups.join(format!("{id}-{n}"));
            if !candidate.exists() {
                break candidate;
            }
            n += 1;
        };
        fs::rename(&dest, &backup).map_err(|e| format!("{id}: conservazione della versione precedente non riuscita: {e}"))?;
    }
    fs::rename(staging.join(id), &dest).map_err(|e| format!("{id}: attivazione non riuscita: {e}"))?;
    let _ = fs::remove_dir_all(&staging);
    Ok(())
}

pub fn read_state(modules_dir: &Path) -> Result<InstalledState, String> {
    match fs::read(modules_dir.join(STATE_FILE)) {
        Ok(b) => serde_json::from_slice(&b).map_err(|e| format!("Stato installazione non valido: {e}")),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(InstalledState::default()),
        Err(e) => Err(e.to_string()),
    }
}

pub fn write_state(modules_dir: &Path, state: &InstalledState) -> Result<(), String> {
    let tmp = modules_dir.join(format!("{STATE_FILE}.tmp"));
    fs::write(&tmp, serde_json::to_vec_pretty(state).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
    fs::rename(&tmp, modules_dir.join(STATE_FILE)).map_err(|e| e.to_string())
}
