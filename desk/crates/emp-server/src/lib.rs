//! EMP Server: installazione dell'ambiente Windows di Edomea Marketplace
//! (MariaDB, PHP, Caddy) e servizio che ne supervisiona i processi.
//! Il programma da riga di comando è `emp-servizi` (src/main.rs).

pub mod configs;
pub mod layout;
pub mod manifest;
pub mod provision;
#[cfg(windows)]
pub mod service;
pub mod supervisor;
