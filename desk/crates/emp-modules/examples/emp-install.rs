//! Installazione da riga di comando, usata dalla CI per la prova completa
//! (pacchetti PHP → estrazione Desk → migrazioni Core).
//!
//!   cargo run -p emp-modules --example emp-install -- <pacchetti> <modules> [id …]
use std::path::Path;

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    if args.len() < 2 {
        eprintln!("Uso: emp-install <cartella-pacchetti> <cartella-modules> [id …]");
        std::process::exit(2);
    }
    let selected: Vec<String> = args[2..].to_vec();
    let sel = if selected.is_empty() { None } else { Some(selected.as_slice()) };
    match emp_modules::install(Path::new(&args[0]), Path::new(&args[1]), sel) {
        Ok(steps) => {
            for s in steps {
                println!("{} {} {}", s.action, s.id, s.version);
            }
        }
        Err(e) => {
            eprintln!("{e}");
            std::process::exit(1);
        }
    }
}
