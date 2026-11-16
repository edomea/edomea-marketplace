//! Installazione del Core da riga di comando, usata dalla CI per la prova completa
//! (pacchetto PHP → estrazione Desk → migrazioni Core → plugin via API del Core).
//!
//!   cargo run -p emp-core-installer --example emp-install -- <pacchetti> <modules>
use std::path::Path;

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    if args.len() != 2 {
        eprintln!("Uso: emp-install <cartella-pacchetti> <cartella-modules>");
        std::process::exit(2);
    }
    match emp_core_installer::install_core(Path::new(&args[0]), Path::new(&args[1])) {
        Ok(step) => println!("{} emp-core {}", step.action, step.version),
        Err(e) => {
            eprintln!("{e}");
            std::process::exit(1);
        }
    }
}
