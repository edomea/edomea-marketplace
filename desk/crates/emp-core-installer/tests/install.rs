//! Prove dell'installazione del Core da parte di EMP Desk.
use emp_core_installer::{core_plan, install_core};
use sha2::{Digest, Sha256};
use std::fs;
use std::io::Write;
use std::path::Path;
use zip::write::SimpleFileOptions;

fn manifest(id: &str, version: &str) -> String {
    let (kind, req) = if id == "emp-core" { ("core", "") } else { ("plugin", r#""emp-core":">=0.1.0""#) };
    format!(r#"{{"schema":1,"id":"{id}","name":"{id}","version":"{version}","type":"{kind}","php":">=8.2.0","requires":{{{req}}}}}"#)
}

fn sha(path: &Path) -> String {
    Sha256::digest(fs::read(path).unwrap()).iter().map(|b| format!("{b:02x}")).collect()
}

/// Crea uno ZIP con le voci date e restituisce lo sha256.
fn make_zip(dir: &Path, file: &str, entries: &[(&str, &str)]) -> String {
    let path = dir.join(file);
    let mut w = zip::ZipWriter::new(fs::File::create(&path).unwrap());
    for (name, content) in entries {
        w.start_file(*name, SimpleFileOptions::default()).unwrap();
        w.write_all(content.as_bytes()).unwrap();
    }
    w.finish().unwrap();
    sha(&path)
}

fn write_index(dir: &Path, entries: &[(&str, &str, &str, &str)]) {
    let list: Vec<String> = entries
        .iter()
        .map(|(id, v, f, h)| format!(r#"{{"id":"{id}","version":"{v}","file":"{f}","sha256":"{h}"}}"#))
        .collect();
    fs::write(dir.join("emp-packages.json"), format!(r#"{{"schema":1,"packages":[{}]}}"#, list.join(","))).unwrap();
}

fn core_bundle(dir: &Path, version: &str, extra: &[(&str, &str)]) -> String {
    let file = format!("emp-core-{version}.zip");
    let m = manifest("emp-core", version);
    let mut entries = vec![("emp-core/emp-module.json", m.as_str()), ("emp-core/src/Kernel.php", "<?php")];
    entries.extend_from_slice(extra);
    let h = make_zip(dir, &file, &entries);
    write_index(dir, &[("emp-core", version, &file, &h)]);
    h
}

#[test]
fn installs_core_and_is_idempotent() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    core_bundle(pk.path(), "0.1.0", &[]);
    assert_eq!(core_plan(pk.path(), md.path()).unwrap().action, "installa");
    let step = install_core(pk.path(), md.path()).unwrap();
    assert_eq!((step.action.as_str(), step.version.as_str()), ("installa", "0.1.0"));
    assert!(md.path().join("emp-core/src/Kernel.php").is_file());
    assert_eq!(install_core(pk.path(), md.path()).unwrap().action, "invariato");
}

#[test]
fn installer_must_contain_only_the_core() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    let h = core_bundle(pk.path(), "0.1.0", &[]);
    let hp = make_zip(pk.path(), "emp-pos-0.1.0.zip", &[("emp-pos/emp-module.json", &manifest("emp-pos", "0.1.0"))]);
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h), ("emp-pos", "0.1.0", "emp-pos-0.1.0.zip", &hp)]);
    assert!(install_core(pk.path(), md.path()).unwrap_err().contains("solo il pacchetto emp-core"));

    // Un plugin al posto del Core.
    write_index(pk.path(), &[("emp-pos", "0.1.0", "emp-pos-0.1.0.zip", &hp)]);
    assert!(install_core(pk.path(), md.path()).unwrap_err().contains("solo il pacchetto emp-core"));
    assert!(!md.path().join("emp-pos").exists());
}

#[test]
fn tampered_package_rejected_before_extraction() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    core_bundle(pk.path(), "0.1.0", &[]);
    let mut f = fs::OpenOptions::new().append(true).open(pk.path().join("emp-core-0.1.0.zip")).unwrap();
    f.write_all(b"x").unwrap();
    let err = install_core(pk.path(), md.path()).unwrap_err();
    assert!(err.contains("impronta SHA-256 non corrispondente"), "{err}");
    assert!(!md.path().join("emp-core").exists());
}

#[test]
fn malicious_paths_rejected() {
    for bad in ["emp-core/../../evil.php", "altro/file.php", "/etc/evil"] {
        let pk = tempfile::tempdir().unwrap();
        let md = tempfile::tempdir().unwrap();
        core_bundle(pk.path(), "0.1.0", &[(bad, "x")]);
        let err = install_core(pk.path(), md.path()).unwrap_err();
        assert!(err.contains("emp-core:"), "{bad}: {err}");
        assert!(!md.path().join("emp-core").exists(), "{bad}");
        assert!(!md.path().parent().unwrap().join("evil.php").exists());
    }
}

#[test]
fn symlink_rejected() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    let path = pk.path().join("emp-core-0.1.0.zip");
    let mut w = zip::ZipWriter::new(fs::File::create(&path).unwrap());
    w.start_file("emp-core/emp-module.json", SimpleFileOptions::default()).unwrap();
    w.write_all(manifest("emp-core", "0.1.0").as_bytes()).unwrap();
    w.add_symlink("emp-core/link", "/etc/passwd", SimpleFileOptions::default()).unwrap();
    w.finish().unwrap();
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &sha(&path))]);
    let err = install_core(pk.path(), md.path()).unwrap_err();
    assert!(err.contains("Collegamento simbolico"), "{err}");
    assert!(!md.path().join("emp-core").exists());
}

#[test]
fn index_must_match_manifest_and_names_are_validated() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    let h = make_zip(pk.path(), "emp-core-0.1.0.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.1"))]);
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h)]);
    assert!(install_core(pk.path(), md.path()).unwrap_err().contains("diversi da quelli dell'indice"));
    write_index(pk.path(), &[("emp-core", "0.1.0", "../emp-core.zip", &"0".repeat(64))]);
    assert!(install_core(pk.path(), md.path()).unwrap_err().contains("voce non valida"));
}

#[test]
fn upgrade_keeps_previous_version_and_rejects_reused_or_older_versions() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    core_bundle(pk.path(), "0.1.0", &[]);
    install_core(pk.path(), md.path()).unwrap();

    core_bundle(pk.path(), "0.1.0", &[("emp-core/nuovo.php", "x")]);
    assert!(core_plan(pk.path(), md.path()).unwrap_err().contains("Le versioni non si riutilizzano"));

    core_bundle(pk.path(), "0.1.1", &[]);
    let step = install_core(pk.path(), md.path()).unwrap();
    assert_eq!(step.action, "aggiorna");
    assert_eq!(step.installed_version.as_deref(), Some("0.1.0"));
    assert!(md.path().join(".precedenti/emp-core-0/emp-module.json").is_file());

    core_bundle(pk.path(), "0.1.0", &[]);
    assert!(core_plan(pk.path(), md.path()).unwrap_err().contains("più vecchio"));
}
