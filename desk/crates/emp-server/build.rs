//! Incorpora nel programma il manifest dei componenti (impronte SHA-256 di PHP,
//! MariaDB, Caddy e del pacchetto del Core) generato dalla CI.
//! Senza manifest il programma rifiuta di installare l'ambiente.
use std::{env, fs, path::PathBuf};

fn main() {
    println!("cargo:rerun-if-env-changed=EMP_RUNTIME_MANIFEST");
    let out = PathBuf::from(env::var("OUT_DIR").expect("OUT_DIR")).join("manifest.json");
    let content = match env::var("EMP_RUNTIME_MANIFEST") {
        Ok(path) if !path.is_empty() => {
            println!("cargo:rerun-if-changed={path}");
            fs::read_to_string(&path).expect("manifest dei componenti non leggibile")
        }
        _ => "{}".to_string(),
    };
    fs::write(out, content).expect("scrittura del manifest");
}
