//! Installazione del Core di EMP da parte di EMP Desk.
//!
//! Ripartizione dei compiti (docs/architettura-moduli.md):
//! - **EMP Desk** installa e aggiorna solo il plugin **emp-core**, incluso nell'installer;
//! - **EMP Core** installa tutti gli altri plugin dalla propria interfaccia web.
//!
//! Flusso:
//! 1. `package::load_index` legge `emp-packages.json` incluso nell'installer, che deve
//!    contenere un solo pacchetto: `emp-core`;
//! 2. `package::verify` controlla l'impronta SHA-256 prima di aprire lo ZIP;
//! 3. `install_core` estrae i file in `modules/emp-core/` e aggiorna `installed.json`,
//!    conservando la versione precedente in `modules/.precedenti/`.
//!
//! Le migrazioni del database le esegue poi il Core (`php bin/emp installa`).

pub mod manifest;
pub mod package;
pub mod version;

use package::{InstalledModule, VerifiedPackage};
use std::path::Path;

/// Stato del Core mostrato all'operatore prima di confermare.
#[derive(Debug, Clone, serde::Serialize, PartialEq)]
pub struct CoreStep {
    pub name: String,
    pub version: String,
    /// "installa", "aggiorna" oppure "invariato"
    pub action: String,
    pub installed_version: Option<String>,
}

/// Legge e verifica il pacchetto del Core incluso nell'installer.
fn bundled_core(packages_dir: &Path) -> Result<VerifiedPackage, String> {
    let index = package::load_index(packages_dir)?;
    match index.as_slice() {
        [entry] if entry.id == manifest::CORE_ID => {
            let pkg = package::verify(packages_dir, entry)?;
            if !pkg.manifest.is_core {
                return Err("Il pacchetto incluso non è il Core".into());
            }
            Ok(pkg)
        }
        _ => Err("L'installer deve contenere solo il pacchetto emp-core: gli altri plugin si installano dall'interfaccia web del Core".into()),
    }
}

/// Confronta il Core incluso nell'installer con quello installato, senza modificare nulla.
pub fn core_plan(packages_dir: &Path, modules_dir: &Path) -> Result<CoreStep, String> {
    let pkg = bundled_core(packages_dir)?;
    step_for(&pkg, modules_dir)
}

fn step_for(pkg: &VerifiedPackage, modules_dir: &Path) -> Result<CoreStep, String> {
    let m = &pkg.manifest;
    let state = package::read_state(modules_dir)?;
    let installed = state.modules.get(&m.id);
    let action = match installed {
        None => "installa",
        Some(i) => {
            let current = version::Version::parse(&i.version)?;
            if current > m.version {
                return Err(format!("Il Core incluso ({}) è più vecchio di quello installato ({})", m.version, i.version));
            }
            if current == m.version {
                if i.sha256 != pkg.sha256 {
                    return Err(format!("Core {}: contenuto diverso da quello installato con la stessa versione. Le versioni non si riutilizzano.", m.version));
                }
                "invariato"
            } else {
                "aggiorna"
            }
        }
    };
    Ok(CoreStep {
        name: m.name.clone(),
        version: m.version.to_string(),
        action: action.into(),
        installed_version: installed.map(|i| i.version.clone()),
    })
}

/// Installa o aggiorna i file del Core.
pub fn install_core(packages_dir: &Path, modules_dir: &Path) -> Result<CoreStep, String> {
    let pkg = bundled_core(packages_dir)?;
    let step = step_for(&pkg, modules_dir)?;
    if step.action != "invariato" {
        package::install_files(&pkg, modules_dir)?;
        let mut state = package::read_state(modules_dir)?;
        state.modules.insert(pkg.manifest.id.clone(), InstalledModule { version: step.version.clone(), sha256: pkg.sha256.clone() });
        package::write_state(modules_dir, &state)?;
    }
    Ok(step)
}
