use emp_modules::{install, plan};
use sha2::{Digest, Sha256};
use std::fs;
use std::io::Write;
use std::path::Path;
use zip::write::SimpleFileOptions;

fn manifest(id: &str, version: &str, requires: &str) -> String {
    let kind = if id == "emp-core" { "core" } else { "plugin" };
    format!(r#"{{"schema":1,"id":"{id}","name":"{id}","version":"{version}","type":"{kind}","php":">=8.2.0","requires":{{{requires}}}}}"#)
}

/// Crea uno ZIP con le voci date e restituisce (nome file, sha256).
fn make_zip(dir: &Path, file: &str, entries: &[(&str, &str)]) -> String {
    let path = dir.join(file);
    let mut w = zip::ZipWriter::new(fs::File::create(&path).unwrap());
    for (name, content) in entries {
        w.start_file(*name, SimpleFileOptions::default()).unwrap();
        w.write_all(content.as_bytes()).unwrap();
    }
    w.finish().unwrap();
    let digest = Sha256::digest(fs::read(&path).unwrap());
    digest.iter().map(|b| format!("{b:02x}")).collect()
}

fn write_index(dir: &Path, entries: &[(&str, &str, &str, &str)]) {
    let list: Vec<String> = entries
        .iter()
        .map(|(id, v, f, h)| format!(r#"{{"id":"{id}","version":"{v}","file":"{f}","sha256":"{h}"}}"#))
        .collect();
    fs::write(dir.join("emp-packages.json"), format!(r#"{{"schema":1,"packages":[{}]}}"#, list.join(","))).unwrap();
}

fn standard(dir: &Path) {
    let core = make_zip(dir, "emp-core-0.1.0.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.0", "")), ("emp-core/src/Kernel.php", "<?php")]);
    let pos = make_zip(
        dir,
        "emp-pos-0.1.0.zip",
        &[("emp-pos/emp-module.json", &manifest("emp-pos", "0.1.0", r#""emp-core":">=0.1.0","emp-magazzino":">=0.1.0""#))],
    );
    let mag = make_zip(dir, "emp-magazzino-0.1.0.zip", &[("emp-magazzino/emp-module.json", &manifest("emp-magazzino", "0.1.0", r#""emp-core":">=0.1.0""#))]);
    write_index(
        dir,
        &[
            ("emp-pos", "0.1.0", "emp-pos-0.1.0.zip", &pos),
            ("emp-core", "0.1.0", "emp-core-0.1.0.zip", &core),
            ("emp-magazzino", "0.1.0", "emp-magazzino-0.1.0.zip", &mag),
        ],
    );
}

#[test]
fn installs_core_first_then_plugins() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    standard(pk.path());
    let steps = install(pk.path(), md.path(), None).unwrap();
    let ids: Vec<&str> = steps.iter().map(|s| s.id.as_str()).collect();
    assert_eq!(ids, ["emp-core", "emp-magazzino", "emp-pos"]);
    assert!(steps.iter().all(|s| s.action == "installa"));
    assert!(md.path().join("emp-core/src/Kernel.php").is_file());
    assert!(md.path().join("emp-pos/emp-module.json").is_file());

    // Seconda esecuzione: nulla da fare.
    let again = plan(pk.path(), md.path(), None).unwrap();
    assert!(again.iter().all(|s| s.action == "invariato"));
}

#[test]
fn selection_installs_only_needed_modules() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    standard(pk.path());
    let sel = vec!["emp-magazzino".to_string()];
    let steps = install(pk.path(), md.path(), Some(&sel)).unwrap();
    assert_eq!(steps.iter().map(|s| s.id.as_str()).collect::<Vec<_>>(), ["emp-core", "emp-magazzino"]);
    assert!(!md.path().join("emp-pos").exists());
}

#[test]
fn tampered_package_rejected_before_extraction() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    standard(pk.path());
    let mut f = fs::OpenOptions::new().append(true).open(pk.path().join("emp-pos-0.1.0.zip")).unwrap();
    f.write_all(b"x").unwrap();
    let err = install(pk.path(), md.path(), None).unwrap_err();
    assert!(err.contains("impronta SHA-256 non corrispondente"), "{err}");
    assert!(!md.path().join("emp-core").exists(), "nessun file deve essere installato");
}

#[test]
fn malicious_paths_rejected() {
    for bad in ["emp-core/../../evil.php", "altro/file.php", "/etc/evil"] {
        let pk = tempfile::tempdir().unwrap();
        let md = tempfile::tempdir().unwrap();
        let h = make_zip(pk.path(), "emp-core-0.1.0.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.0", "")), (bad, "x")]);
        write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h)]);
        let err = install(pk.path(), md.path(), None).unwrap_err();
        assert!(err.contains("emp-core:"), "{bad}: {err}");
        assert!(!md.path().join("emp-core").exists(), "{bad}");
        assert!(!md.path().parent().unwrap().join("evil.php").exists());
    }
}

#[test]
fn index_must_match_manifest() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    let h = make_zip(pk.path(), "emp-core-0.1.0.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.1", ""))]);
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h)]);
    assert!(install(pk.path(), md.path(), None).unwrap_err().contains("diversi da quelli dell'indice"));
}

#[test]
fn index_file_names_validated() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    write_index(pk.path(), &[("emp-core", "0.1.0", "../emp-core.zip", &"0".repeat(64))]);
    assert!(install(pk.path(), md.path(), None).unwrap_err().contains("voce non valida"));
}

#[test]
fn upgrade_keeps_previous_version_and_rejects_reused_version() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    let h1 = make_zip(pk.path(), "emp-core-0.1.0.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.0", ""))]);
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h1)]);
    install(pk.path(), md.path(), None).unwrap();

    // Stessa versione, contenuto diverso: rifiutato.
    let h1b = make_zip(pk.path(), "emp-core-0.1.0.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.0", "")), ("emp-core/nuovo.php", "x")]);
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h1b)]);
    assert!(plan(pk.path(), md.path(), None).unwrap_err().contains("Le versioni non si riutilizzano"));

    // Nuova versione: aggiornamento, la precedente resta in .precedenti/.
    let h2 = make_zip(pk.path(), "emp-core-0.1.1.zip", &[("emp-core/emp-module.json", &manifest("emp-core", "0.1.1", ""))]);
    write_index(pk.path(), &[("emp-core", "0.1.1", "emp-core-0.1.1.zip", &h2)]);
    let steps = install(pk.path(), md.path(), None).unwrap();
    assert_eq!(steps[0].action, "aggiorna");
    assert_eq!(steps[0].installed_version.as_deref(), Some("0.1.0"));
    assert!(md.path().join(".precedenti/emp-core-0/emp-module.json").is_file());

    // Versione più vecchia: rifiutata.
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h1b)]);
    assert!(plan(pk.path(), md.path(), None).unwrap_err().contains("più vecchio"));
}

#[test]
fn symlink_rejected() {
    let pk = tempfile::tempdir().unwrap();
    let md = tempfile::tempdir().unwrap();
    let path = pk.path().join("emp-core-0.1.0.zip");
    let mut w = zip::ZipWriter::new(fs::File::create(&path).unwrap());
    w.start_file("emp-core/emp-module.json", SimpleFileOptions::default()).unwrap();
    w.write_all(manifest("emp-core", "0.1.0", "").as_bytes()).unwrap();
    w.add_symlink("emp-core/link", "/etc/passwd", SimpleFileOptions::default()).unwrap();
    w.finish().unwrap();
    let h: String = Sha256::digest(fs::read(&path).unwrap()).iter().map(|b| format!("{b:02x}")).collect();
    write_index(pk.path(), &[("emp-core", "0.1.0", "emp-core-0.1.0.zip", &h)]);
    let err = install(pk.path(), md.path(), None).unwrap_err();
    assert!(err.contains("Collegamento simbolico"), "{err}");
    assert!(!md.path().join("emp-core").exists());
}
