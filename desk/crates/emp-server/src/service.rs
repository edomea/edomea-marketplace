//! Servizio di Windows "EMPServer": avvio automatico, account LocalService,
//! riavvio automatico in caso di errore. Supervisiona MariaDB, PHP e Caddy.

use crate::layout::{Layout, SERVICE_DISPLAY, SERVICE_NAME};
use crate::supervisor::Supervisor;
use std::ffi::{OsStr, OsString};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;
use windows_service::service::{
    ServiceAccess, ServiceAction, ServiceActionType, ServiceControl, ServiceControlAccept, ServiceErrorControl, ServiceExitCode,
    ServiceFailureActions, ServiceFailureResetPeriod, ServiceInfo, ServiceStartType, ServiceState, ServiceStatus, ServiceType,
};
use windows_service::service_control_handler::{self, ServiceControlHandlerResult};
use windows_service::service_manager::{ServiceManager, ServiceManagerAccess};
use windows_service::{define_windows_service, service_dispatcher};

const LOCAL_SERVICE: &str = r"NT AUTHORITY\LocalService";

fn manager(access: ServiceManagerAccess) -> Result<ServiceManager, String> {
    ServiceManager::local_computer(None::<&str>, access).map_err(|e| format!("Gestione servizi di Windows non disponibile: {e}"))
}

pub fn exists() -> bool {
    manager(ServiceManagerAccess::CONNECT)
        .ok()
        .and_then(|m| m.open_service(SERVICE_NAME, ServiceAccess::QUERY_STATUS).ok())
        .is_some()
}

/// "assente", "in_esecuzione", "fermo", "in_avvio", "in_arresto"
pub fn state() -> String {
    let Ok(m) = manager(ServiceManagerAccess::CONNECT) else { return "sconosciuto".into() };
    let Ok(s) = m.open_service(SERVICE_NAME, ServiceAccess::QUERY_STATUS) else { return "assente".into() };
    match s.query_status().map(|st| st.current_state) {
        Ok(ServiceState::Running) => "in_esecuzione",
        Ok(ServiceState::Stopped) => "fermo",
        Ok(ServiceState::StartPending) => "in_avvio",
        Ok(ServiceState::StopPending) => "in_arresto",
        Ok(_) => "altro",
        Err(_) => "sconosciuto",
    }
    .into()
}

pub fn install_or_update(l: &Layout) -> Result<(), String> {
    let m = manager(ServiceManagerAccess::CONNECT | ServiceManagerAccess::CREATE_SERVICE)?;
    let info = ServiceInfo {
        name: OsString::from(SERVICE_NAME),
        display_name: OsString::from(SERVICE_DISPLAY),
        service_type: ServiceType::OWN_PROCESS,
        start_type: ServiceStartType::AutoStart,
        error_control: ServiceErrorControl::Normal,
        executable_path: l.bin().join("emp-servizi.exe"),
        launch_arguments: vec![OsString::from("servizio")],
        dependencies: vec![],
        account_name: Some(OsString::from(LOCAL_SERVICE)),
        account_password: None,
    };
    let access = ServiceAccess::QUERY_STATUS | ServiceAccess::CHANGE_CONFIG | ServiceAccess::START | ServiceAccess::STOP;
    let service = match m.open_service(SERVICE_NAME, access) {
        Ok(s) => {
            s.change_config(&info).map_err(|e| format!("Aggiornamento del servizio non riuscito: {e}"))?;
            s
        }
        Err(_) => m.create_service(&info, access).map_err(|e| format!("Creazione del servizio non riuscita: {e}"))?,
    };
    let _ = service.set_description("Edomea Marketplace: MariaDB, PHP e Caddy per il Core EMP (solo 127.0.0.1)");
    let restart = ServiceAction { action_type: ServiceActionType::Restart, delay: Duration::from_secs(10) };
    service
        .update_failure_actions(ServiceFailureActions {
            reset_period: ServiceFailureResetPeriod::After(Duration::from_secs(86_400)),
            reboot_msg: None,
            command: None,
            actions: Some(vec![restart.clone(), restart.clone(), restart]),
        })
        .map_err(|e| format!("Impostazione del riavvio automatico non riuscita: {e}"))?;
    Ok(())
}

pub fn start() -> Result<(), String> {
    let m = manager(ServiceManagerAccess::CONNECT)?;
    let s = m.open_service(SERVICE_NAME, ServiceAccess::START | ServiceAccess::QUERY_STATUS).map_err(|e| e.to_string())?;
    if s.query_status().map(|st| st.current_state == ServiceState::Running).unwrap_or(false) {
        return Ok(());
    }
    s.start(&[] as &[&OsStr]).map_err(|e| format!("Avvio del servizio non riuscito: {e}"))
}

pub fn stop() -> Result<(), String> {
    let m = manager(ServiceManagerAccess::CONNECT)?;
    let Ok(s) = m.open_service(SERVICE_NAME, ServiceAccess::STOP | ServiceAccess::QUERY_STATUS) else { return Ok(()) };
    if s.query_status().map(|st| st.current_state == ServiceState::Stopped).unwrap_or(true) {
        return Ok(());
    }
    let _ = s.stop();
    for _ in 0..120 {
        if s.query_status().map(|st| st.current_state == ServiceState::Stopped).unwrap_or(true) {
            return Ok(());
        }
        std::thread::sleep(Duration::from_millis(500));
    }
    Err("Il servizio EMPServer non si è fermato entro 60 secondi".into())
}

pub fn remove() -> Result<(), String> {
    stop()?;
    let m = manager(ServiceManagerAccess::CONNECT)?;
    if let Ok(s) = m.open_service(SERVICE_NAME, ServiceAccess::DELETE) {
        s.delete().map_err(|e| format!("Rimozione del servizio non riuscita: {e}"))?;
    }
    Ok(())
}

define_windows_service!(ffi_service_main, service_main);

/// Punto d'ingresso quando Windows avvia il servizio.
pub fn run_dispatcher() -> Result<(), String> {
    service_dispatcher::start(SERVICE_NAME, ffi_service_main).map_err(|e| format!("Avvio come servizio non riuscito: {e}"))
}

fn service_main(_args: Vec<OsString>) {
    let stop = Arc::new(AtomicBool::new(false));
    let stop_handler = stop.clone();
    let handler = move |control| match control {
        ServiceControl::Stop | ServiceControl::Shutdown => {
            stop_handler.store(true, Ordering::SeqCst);
            ServiceControlHandlerResult::NoError
        }
        ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,
        _ => ServiceControlHandlerResult::NotImplemented,
    };
    let Ok(status) = service_control_handler::register(SERVICE_NAME, handler) else { return };
    let set = |state: ServiceState, accept: ServiceControlAccept, wait: u64| {
        let _ = status.set_service_status(ServiceStatus {
            service_type: ServiceType::OWN_PROCESS,
            current_state: state,
            controls_accepted: accept,
            exit_code: ServiceExitCode::Win32(0),
            checkpoint: 0,
            wait_hint: Duration::from_secs(wait),
            process_id: None,
        });
    };
    set(ServiceState::StartPending, ServiceControlAccept::empty(), 90);
    let layout = Layout::system();
    let mut sup = Supervisor::for_environment(&layout);
    sup.start_all();
    set(ServiceState::Running, ServiceControlAccept::STOP | ServiceControlAccept::SHUTDOWN, 0);
    while !stop.load(Ordering::SeqCst) {
        std::thread::sleep(Duration::from_millis(500));
        sup.tick();
    }
    set(ServiceState::StopPending, ServiceControlAccept::empty(), 45);
    sup.stop_all();
    set(ServiceState::Stopped, ServiceControlAccept::empty(), 0);
}
