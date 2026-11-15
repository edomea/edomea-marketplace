//! Piano di installazione: stesso algoritmo di core/src/Module/Resolver.php.
//! Core sempre primo, dipendenze prima dei dipendenti, ordine alfabetico a parità.

use crate::manifest::{Manifest, CORE_ID};
use std::collections::{BTreeMap, BTreeSet};

/// Restituisce i moduli da installare in ordine. `selected` = None installa tutti.
pub fn resolve<'a>(available: &'a [Manifest], selected: Option<&[String]>) -> Result<Vec<&'a Manifest>, String> {
    let mut by_id: BTreeMap<&str, &Manifest> = BTreeMap::new();
    for m in available {
        if by_id.insert(m.id.as_str(), m).is_some() {
            return Err(format!("Modulo duplicato: \"{}\"", m.id));
        }
    }
    if !by_id.contains_key(CORE_ID) {
        return Err("Il Core (emp-core) non è disponibile: installare prima il Core".into());
    }

    let mut wanted: BTreeSet<&str> = BTreeSet::new();
    wanted.insert(CORE_ID);
    let mut queue: Vec<String> = match selected {
        Some(ids) => ids.to_vec(),
        None => by_id.keys().map(|s| s.to_string()).collect(),
    };
    while let Some(id) = queue.pop() {
        let m = by_id.get(id.as_str()).ok_or_else(|| format!("Modulo richiesto non disponibile: \"{id}\""))?;
        if !wanted.insert(m.id.as_str()) && m.id != CORE_ID {
            continue;
        }
        for dep in m.requires.keys() {
            if !wanted.contains(dep.as_str()) {
                queue.push(dep.clone());
            }
        }
    }

    let mut prefixes: BTreeMap<&str, &str> = BTreeMap::new();
    for id in &wanted {
        let m = by_id[id];
        for (dep, c) in &m.requires {
            let d = by_id.get(dep.as_str()).ok_or_else(|| format!("\"{id}\" richiede \"{dep}\", che non è disponibile"))?;
            if !c.matches(&d.version) {
                return Err(format!("\"{id}\" richiede \"{dep}\" {c}, disponibile {}", d.version));
            }
        }
        if let Some(p) = &m.table_prefix {
            if let Some(other) = prefixes.insert(p.as_str(), id) {
                return Err(format!("Prefisso tabelle \"{p}\" usato sia da \"{other}\" sia da \"{id}\""));
            }
        }
    }

    let mut pending: BTreeMap<&str, usize> = wanted.iter().map(|id| (*id, by_id[id].requires.len())).collect();
    let mut dependents: BTreeMap<&str, Vec<&str>> = BTreeMap::new();
    for id in &wanted {
        for dep in by_id[id].requires.keys() {
            dependents.entry(dep.as_str()).or_default().push(id);
        }
    }
    // BTreeSet = scelta alfabetica tra i pronti; il Core è sempre il primo pronto
    // perché ogni plugin dipende da lui.
    let mut ready: BTreeSet<&str> = pending.iter().filter(|(_, n)| **n == 0).map(|(id, _)| *id).collect();
    let mut order = Vec::with_capacity(wanted.len());
    while let Some(id) = ready.iter().next().copied() {
        ready.remove(id);
        order.push(by_id[id]);
        for child in dependents.get(id).into_iter().flatten() {
            let n = pending.get_mut(child).expect("dipendente noto");
            *n -= 1;
            if *n == 0 {
                ready.insert(child);
            }
        }
    }
    if order.len() != wanted.len() {
        let stuck: Vec<&str> = pending.iter().filter(|(_, n)| **n > 0).map(|(id, _)| *id).collect();
        return Err(format!("Dipendenze circolari tra: {}", stuck.join(", ")));
    }
    if order.first().map(|m| m.id.as_str()) != Some(CORE_ID) {
        return Err("Il Core deve essere installato per primo".into());
    }
    Ok(order)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn m(id: &str, requires: &[(&str, &str)], prefix: Option<&str>, version: &str) -> Manifest {
        let kind = if id == CORE_ID { "core" } else { "plugin" };
        let req: serde_json::Map<String, serde_json::Value> = requires.iter().map(|(k, v)| (k.to_string(), (*v).into())).collect();
        let json = serde_json::json!({
            "schema": 1, "id": id, "name": id, "version": version, "type": kind, "php": ">=8.2.0",
            "requires": req, "table_prefix": prefix
        });
        Manifest::from_json(json.to_string().as_bytes()).unwrap()
    }
    fn p(id: &str) -> Manifest {
        m(id, &[("emp-core", ">=0.1.0 <0.2.0")], None, "0.1.0")
    }
    fn ids(v: Vec<&Manifest>) -> Vec<String> {
        v.into_iter().map(|m| m.id.clone()).collect()
    }

    #[test]
    fn core_first_then_dependencies_alphabetical() {
        let all = vec![
            m("emp-pos", &[("emp-core", ">=0.1.0"), ("emp-magazzino", "^0.1.0")], None, "0.1.0"),
            p("emp-zeta"),
            p("emp-magazzino"),
            p("emp-alfa"),
            m("emp-core", &[], None, "0.1.0"),
        ];
        assert_eq!(ids(resolve(&all, None).unwrap()), ["emp-core", "emp-alfa", "emp-magazzino", "emp-pos", "emp-zeta"]);
    }

    #[test]
    fn selection_adds_dependencies() {
        let all = vec![
            m("emp-core", &[], None, "0.1.0"),
            p("emp-magazzino"),
            m("emp-pos", &[("emp-core", ">=0.1.0"), ("emp-magazzino", ">=0.1.0")], None, "0.1.0"),
            p("emp-stat"),
        ];
        let sel = vec!["emp-pos".to_string()];
        assert_eq!(ids(resolve(&all, Some(&sel)).unwrap()), ["emp-core", "emp-magazzino", "emp-pos"]);
    }

    #[test]
    fn errors() {
        assert!(resolve(&[p("emp-pos")], None).unwrap_err().contains("installare prima il Core"));
        let incompatible = vec![m("emp-core", &[], None, "0.2.0"), p("emp-pos")];
        assert!(resolve(&incompatible, None).unwrap_err().contains("richiede \"emp-core\""));
        let cycle = vec![
            m("emp-core", &[], None, "0.1.0"),
            m("emp-a", &[("emp-core", ">=0.1.0"), ("emp-b", ">=0.1.0")], None, "0.1.0"),
            m("emp-b", &[("emp-core", ">=0.1.0"), ("emp-a", ">=0.1.0")], None, "0.1.0"),
        ];
        assert_eq!(resolve(&cycle, None).unwrap_err(), "Dipendenze circolari tra: emp-a, emp-b");
        let prefix = vec![
            m("emp-core", &[], None, "0.1.0"),
            m("emp-pos", &[("emp-core", ">=0.1.0")], Some("pos_"), "0.1.0"),
            m("emp-pos2", &[("emp-core", ">=0.1.0")], Some("pos_"), "0.1.0"),
        ];
        assert!(resolve(&prefix, None).unwrap_err().contains("Prefisso tabelle \"pos_\""));
        let missing = vec![m("emp-core", &[], None, "0.1.0"), m("emp-pos", &[("emp-core", ">=0.1.0"), ("emp-x", ">=0.1.0")], None, "0.1.0")];
        assert!(resolve(&missing, None).is_err());
    }

    #[test]
    fn manifest_rules() {
        let bad = [
            r#"{"schema":1,"id":"emp-pos","name":"x","version":"0.1.0","type":"plugin","php":">=8.2.0"}"#,
            r#"{"schema":1,"id":"emp-pos","name":"x","version":"0.1.0","type":"core","php":">=8.2.0"}"#,
            r#"{"schema":2,"id":"emp-core","name":"x","version":"0.1.0","type":"core","php":">=8.2.0"}"#,
            r#"{"schema":1,"id":"Emp","name":"x","version":"0.1.0","type":"core","php":">=8.2.0"}"#,
        ];
        for b in bad {
            assert!(Manifest::from_json(b.as_bytes()).is_err(), "{b}");
        }
    }
}
