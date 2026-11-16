//! Lettura del manifest emp-module.json lato Desk. Desk installa solo il Core,
//! quindi usa solo i campi che servono a identificarlo e a confrontare le
//! versioni; la validazione completa (classi, autoload, migrazioni) resta al
//! Core PHP prima dell'avvio.

use crate::version::{Constraint, Version};
use serde::Deserialize;
use std::collections::BTreeMap;

pub const CORE_ID: &str = "emp-core";
pub const FILE: &str = "emp-module.json";
const MAX_BYTES: usize = 65_536;

#[derive(Deserialize)]
struct Raw {
    schema: u32,
    id: String,
    name: String,
    version: String,
    #[serde(rename = "type")]
    kind: String,
    php: String,
    #[serde(default)]
    requires: BTreeMap<String, String>,
    #[serde(default)]
    table_prefix: Option<String>,
}

#[derive(Debug, Clone)]
pub struct Manifest {
    pub id: String,
    pub name: String,
    pub version: Version,
    pub is_core: bool,
    pub php: Constraint,
    pub requires: BTreeMap<String, Constraint>,
    pub table_prefix: Option<String>,
}

pub fn valid_id(id: &str) -> bool {
    let b = id.as_bytes();
    (3..=64).contains(&b.len())
        && b[0].is_ascii_lowercase()
        && b.iter().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || *c == b'-')
}

impl Manifest {
    pub fn from_json(bytes: &[u8]) -> Result<Self, String> {
        if bytes.len() > MAX_BYTES {
            return Err("Manifest troppo grande".into());
        }
        let raw: Raw = serde_json::from_slice(bytes).map_err(|e| format!("Manifest non valido: {e}"))?;
        if raw.schema != 1 {
            return Err("Campo \"schema\" non supportato (atteso 1)".into());
        }
        if !valid_id(&raw.id) {
            return Err(format!("Id non valido: \"{}\"", raw.id));
        }
        let is_core = match raw.kind.as_str() {
            "core" => true,
            "plugin" => false,
            _ => return Err("Tipo non valido: atteso \"core\" o \"plugin\"".into()),
        };
        if is_core != (raw.id == CORE_ID) {
            return Err("Solo \"emp-core\" può essere di tipo \"core\"".into());
        }
        let mut requires = BTreeMap::new();
        for (dep, c) in raw.requires {
            if !valid_id(&dep) || dep == raw.id {
                return Err(format!("Dipendenza non valida in \"{}\"", raw.id));
            }
            requires.insert(dep, Constraint::parse(&c)?);
        }
        if !is_core && !requires.contains_key(CORE_ID) {
            return Err(format!("\"{}\": ogni plugin deve dichiarare la dipendenza da \"emp-core\"", raw.id));
        }
        if is_core && !requires.is_empty() {
            return Err("Il Core non può avere dipendenze".into());
        }
        if let Some(p) = &raw.table_prefix {
            let b = p.as_bytes();
            let ok = (2..=32).contains(&b.len())
                && b[0].is_ascii_lowercase()
                && b.ends_with(b"_")
                && b.iter().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || *c == b'_');
            if !ok {
                return Err(format!("\"{}\": table_prefix non valido", raw.id));
            }
        }
        if raw.name.is_empty() || raw.name.chars().count() > 200 {
            return Err(format!("\"{}\": nome non valido", raw.id));
        }
        Ok(Manifest {
            version: Version::parse(&raw.version)?,
            php: Constraint::parse(&raw.php)?,
            id: raw.id,
            name: raw.name,
            is_core,
            requires,
            table_prefix: raw.table_prefix,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rules() {
        let ok = r#"{"schema":1,"id":"emp-core","name":"EMP Core","version":"0.1.0","type":"core","php":">=8.2.0"}"#;
        assert!(Manifest::from_json(ok.as_bytes()).unwrap().is_core);
        let bad = [
            r#"{"schema":1,"id":"emp-pos","name":"x","version":"0.1.0","type":"plugin","php":">=8.2.0"}"#,
            r#"{"schema":1,"id":"emp-pos","name":"x","version":"0.1.0","type":"core","php":">=8.2.0"}"#,
            r#"{"schema":2,"id":"emp-core","name":"x","version":"0.1.0","type":"core","php":">=8.2.0"}"#,
            r#"{"schema":1,"id":"Emp","name":"x","version":"0.1.0","type":"core","php":">=8.2.0"}"#,
            r#"{"schema":1,"id":"emp-core","name":"x","version":"0.1.0","type":"core","php":">=8.2.0","requires":{"emp-pos":">=0.1.0"}}"#,
        ];
        for b in bad {
            assert!(Manifest::from_json(b.as_bytes()).is_err(), "{b}");
        }
    }
}
