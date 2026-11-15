//! Versioni semantiche e vincoli: stesse regole e stessi casi di test di
//! `core/src/Version` (PHP). Vedi docs/architettura-moduli.md §2.

use std::cmp::Ordering;
use std::fmt;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Version {
    pub major: u32,
    pub minor: u32,
    pub patch: u32,
    /// (etichetta 0=alpha 1=beta 2=rc, numero); None = versione finale
    pre: Option<(u8, u32)>,
}

fn num(s: &str) -> Result<u32, String> {
    if s.is_empty() || s.len() > 9 || !s.bytes().all(|b| b.is_ascii_digit()) || (s.len() > 1 && s.starts_with('0')) {
        return Err(format!("Numero di versione non valido: \"{s}\""));
    }
    s.parse().map_err(|_| format!("Numero di versione non valido: \"{s}\""))
}

impl Version {
    pub fn parse(text: &str) -> Result<Self, String> {
        let err = || format!("Versione non valida: \"{text}\"");
        if text.len() > 64 {
            return Err(err());
        }
        let (core, pre) = match text.split_once('-') {
            Some((c, p)) => (c, Some(p)),
            None => (text, None),
        };
        let parts: Vec<&str> = core.split('.').collect();
        if parts.len() != 3 {
            return Err(err());
        }
        let pre = match pre {
            None => None,
            Some(p) => {
                let (label, n) = p.split_once('.').ok_or_else(err)?;
                let label = match label {
                    "alpha" => 0,
                    "beta" => 1,
                    "rc" => 2,
                    _ => return Err(err()),
                };
                Some((label, num(n).map_err(|_| err())?))
            }
        };
        Ok(Version {
            major: num(parts[0]).map_err(|_| err())?,
            minor: num(parts[1]).map_err(|_| err())?,
            patch: num(parts[2]).map_err(|_| err())?,
            pre,
        })
    }
}

impl Ord for Version {
    fn cmp(&self, other: &Self) -> Ordering {
        (self.major, self.minor, self.patch)
            .cmp(&(other.major, other.minor, other.patch))
            .then_with(|| match (&self.pre, &other.pre) {
                (None, None) => Ordering::Equal,
                (None, Some(_)) => Ordering::Greater,
                (Some(_), None) => Ordering::Less,
                (Some(a), Some(b)) => a.cmp(b),
            })
    }
}

impl PartialOrd for Version {
    fn partial_cmp(&self, other: &Self) -> Option<Ordering> {
        Some(self.cmp(other))
    }
}

impl fmt::Display for Version {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}.{}.{}", self.major, self.minor, self.patch)?;
        if let Some((label, n)) = self.pre {
            write!(f, "-{}.{}", ["alpha", "beta", "rc"][label as usize], n)?;
        }
        Ok(())
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Op {
    Eq,
    Gt,
    Ge,
    Lt,
    Le,
}

#[derive(Debug, Clone)]
pub struct Constraint {
    text: String,
    conditions: Vec<(Op, Version)>,
}

impl Constraint {
    pub fn parse(text: &str) -> Result<Self, String> {
        let trimmed = text.trim();
        if trimmed.is_empty() || trimmed.len() > 128 {
            return Err("Vincolo di versione vuoto o troppo lungo".into());
        }
        let mut conditions = Vec::new();
        for part in trimmed.split_whitespace() {
            let (op, rest) = if let Some(r) = part.strip_prefix(">=") {
                (">=", r)
            } else if let Some(r) = part.strip_prefix("<=") {
                ("<=", r)
            } else if let Some(r) = part.strip_prefix('>') {
                (">", r)
            } else if let Some(r) = part.strip_prefix('<') {
                ("<", r)
            } else if let Some(r) = part.strip_prefix('=') {
                ("=", r)
            } else if let Some(r) = part.strip_prefix('^') {
                ("^", r)
            } else {
                ("=", part)
            };
            let v = Version::parse(rest)?;
            match op {
                "^" => {
                    let upper = if v.major > 0 {
                        format!("{}.0.0-alpha.0", v.major + 1)
                    } else if v.minor > 0 {
                        format!("0.{}.0-alpha.0", v.minor + 1)
                    } else {
                        format!("0.0.{}-alpha.0", v.patch + 1)
                    };
                    conditions.push((Op::Ge, v));
                    conditions.push((Op::Lt, Version::parse(&upper)?));
                }
                ">=" => conditions.push((Op::Ge, v)),
                "<=" => conditions.push((Op::Le, v)),
                ">" => conditions.push((Op::Gt, v)),
                "<" => conditions.push((Op::Lt, v)),
                _ => conditions.push((Op::Eq, v)),
            }
        }
        Ok(Constraint { text: trimmed.to_string(), conditions })
    }

    pub fn matches(&self, v: &Version) -> bool {
        self.conditions.iter().all(|(op, bound)| {
            let c = v.cmp(bound);
            match op {
                Op::Eq => c == Ordering::Equal,
                Op::Gt => c == Ordering::Greater,
                Op::Ge => c != Ordering::Less,
                Op::Lt => c == Ordering::Less,
                Op::Le => c != Ordering::Greater,
            }
        })
    }
}

impl fmt::Display for Constraint {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.text)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // Stessi casi di core/tests/VersionTest.php
    #[test]
    fn compare() {
        let cases = [
            ("1.0.0", "1.0.0", Ordering::Equal),
            ("1.0.1", "1.0.0", Ordering::Greater),
            ("1.10.0", "1.9.9", Ordering::Greater),
            ("0.4.0-alpha.4", "0.4.0", Ordering::Less),
            ("0.4.0-alpha.4", "0.4.0-alpha.10", Ordering::Less),
            ("0.4.0-beta.1", "0.4.0-alpha.9", Ordering::Greater),
            ("0.4.0-rc.1", "0.4.0-beta.9", Ordering::Greater),
            ("2.0.0", "10.0.0", Ordering::Less),
        ];
        for (a, b, expected) in cases {
            let (va, vb) = (Version::parse(a).unwrap(), Version::parse(b).unwrap());
            assert_eq!(va.cmp(&vb), expected, "{a} vs {b}");
            assert_eq!(vb.cmp(&va), expected.reverse(), "{b} vs {a}");
        }
    }

    #[test]
    fn invalid() {
        for t in ["", "1", "1.0", "01.0.0", "1.0.0-", "1.0.0-dev", "1.0.0-alpha", "v1.0.0", "1.0.0 ", "9999999999.0.0"] {
            assert!(Version::parse(t).is_err(), "{t:?}");
        }
    }

    #[test]
    fn round_trip() {
        assert_eq!(Version::parse("0.4.0-alpha.4").unwrap().to_string(), "0.4.0-alpha.4");
    }

    #[test]
    fn constraints() {
        let cases = [
            (">=0.1.0 <0.2.0", "0.1.0", true),
            (">=0.1.0 <0.2.0", "0.1.9", true),
            (">=0.1.0 <0.2.0", "0.2.0", false),
            (">=0.1.0 <0.2.0", "0.0.9", false),
            ("^1.2.0", "1.9.0", true),
            ("^1.2.0", "2.0.0", false),
            ("^1.2.0", "2.0.0-alpha.1", false),
            ("^1.2.0", "1.1.9", false),
            ("^0.2.3", "0.2.9", true),
            ("^0.2.3", "0.3.0", false),
            ("^0.0.3", "0.0.3", true),
            ("^0.0.3", "0.0.4", false),
            ("1.2.3", "1.2.3", true),
            ("=1.2.3", "1.2.4", false),
            (">1.0.0", "1.0.0", false),
            ("<=1.0.0", "1.0.0", true),
            (">=8.2.0", "8.3.6", true),
        ];
        for (c, v, expected) in cases {
            assert_eq!(Constraint::parse(c).unwrap().matches(&Version::parse(v).unwrap()), expected, "{c} / {v}");
        }
        assert!(Constraint::parse(">= 1.0.0").is_err());
    }
}
