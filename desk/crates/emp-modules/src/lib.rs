//! Motore di installazione dei moduli di EMP Desk.
//!
//! Flusso (docs/architettura-moduli.md §4):
//! 1. `package::verify_all` legge `emp-packages.json` e verifica l'SHA-256 di ogni ZIP;
//! 2. `plan::resolve` calcola l'ordine: Core per primo, poi i plugin per dipendenze;
//! 3. `install` estrae i file in quell'ordine e aggiorna `installed.json`.
//!
//! Le migrazioni del database le esegue poi il Core (`php bin/emp installa`),
//! nello stesso ordine.

pub mod manifest;
pub mod package;
pub mod plan;
pub mod version;

use package::{InstalledModule, VerifiedPackage};
use std::path::Path;

/// Voce del piano mostrata all'operatore prima di confermare.
#[derive(Debug, Clone, serde::Serialize, PartialEq)]
pub struct PlanStep {
    pub id: String,
    pub name: String,
    pub version: String,
    pub core: bool,
    /// "installa", "aggiorna" oppure "invariato"
    pub action: String,
    pub installed_version: Option<String>,
}

fn verified_plan<'a>(packages: &'a [VerifiedPackage], selected: Option<&[String]>) -> Result<Vec<&'a VerifiedPackage>, String> {
    let manifests: Vec<manifest::Manifest> = packages.iter().map(|p| p.manifest.clone()).collect();
    let order = plan::resolve(&manifests, selected)?;
    Ok(order.iter().map(|m| packages.iter().find(|p| p.manifest.id == m.id).expect("pacchetto presente")).collect())
}

/// Calcola il piano senza modificare nulla.
pub fn plan(packages_dir: &Path, modules_dir: &Path, selected: Option<&[String]>) -> Result<Vec<PlanStep>, String> {
    let packages = package::verify_all(packages_dir)?;
    let state = package::read_state(modules_dir)?;
    let order = verified_plan(&packages, selected)?;
    order
        .into_iter()
        .map(|p| {
            let m = &p.manifest;
            let installed = state.modules.get(&m.id);
            let action = match installed {
                None => "installa",
                Some(i) => {
                    let current = version::Version::parse(&i.version)?;
                    if current > m.version {
                        return Err(format!("\"{}\": il pacchetto {} è più vecchio della versione installata {}", m.id, m.version, i.version));
                    }
                    if current == m.version {
                        if i.sha256 != p.sha256 {
                            return Err(format!("\"{}\" {}: contenuto diverso da quello installato con la stessa versione. Le versioni non si riutilizzano.", m.id, m.version));
                        }
                        "invariato"
                    } else {
                        "aggiorna"
                    }
                }
            };
            Ok(PlanStep {
                id: m.id.clone(),
                name: m.name.clone(),
                version: m.version.to_string(),
                core: m.is_core,
                action: action.into(),
                installed_version: installed.map(|i| i.version.clone()),
            })
        })
        .collect()
}

/// Esegue il piano: estrae i file dei moduli nell'ordine calcolato.
pub fn install(packages_dir: &Path, modules_dir: &Path, selected: Option<&[String]>) -> Result<Vec<PlanStep>, String> {
    let steps = plan(packages_dir, modules_dir, selected)?;
    let packages = package::verify_all(packages_dir)?;
    let mut state = package::read_state(modules_dir)?;
    for step in &steps {
        if step.action == "invariato" {
            continue;
        }
        let pkg = packages.iter().find(|p| p.manifest.id == step.id).expect("pacchetto nel piano");
        package::install_files(pkg, modules_dir)?;
        state.modules.insert(step.id.clone(), InstalledModule { version: step.version.clone(), sha256: pkg.sha256.clone() });
        package::write_state(modules_dir, &state)?;
    }
    Ok(steps)
}
