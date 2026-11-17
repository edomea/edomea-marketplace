#!/usr/bin/env bash
# EMP Server — prepara la VM del negozio per l'installazione da GitHub (una volta sola).
#
#   sudo ./prepara-runner.sh [--etichetta emp-server] [--nome NOME] [--repo PROPRIETARIO/REPO]
#   sudo ./prepara-runner.sh --rimuovi
#
# Installa un runner GitHub Actions "self-hosted" come servizio di sistema, con un utente
# dedicato senza privilegi (emp-runner). Il runner si collega a GitHub IN USCITA (HTTPS):
# nessuna porta aperta sul router, nessun IP pubblico, nessun accesso SSH da Internet.
# L'unico comando che il runner può eseguire come root è emp-installa-da-github, che
# verifica da solo provenienza e impronte del pacchetto prima di installarlo.
#
# Il token di registrazione (valido 1 ora) si ottiene su GitHub:
#   repository → Settings → Actions → Runners → New self-hosted runner → Linux
# e viene chiesto a schermo (oppure EMP_RUNNER_TOKEN=... nell'ambiente).
set -euo pipefail
umask 022

ORIGINE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
readonly ORIGINE
readonly UTENTE=emp-runner
readonly CASA=/var/lib/emp-runner
readonly RUNNER=/opt/actions-runner
readonly COMANDO=/usr/local/sbin/emp-installa-da-github
readonly SUDOERS=/etc/sudoers.d/emp-runner
readonly CONF=/etc/emp/github.conf

REPO=edomea/edomea-marketplace
ETICHETTA=emp-server
NOME=$(hostname -s)
RIMUOVI=0

die() { echo "ERRORE: $*" >&2; exit 1; }
passo() { echo; echo "==> $*"; }

while (($#)); do
    case $1 in
        --etichetta) ETICHETTA=${2:?}; shift 2 ;;
        --nome) NOME=${2:?}; shift 2 ;;
        --repo) REPO=${2:?}; shift 2 ;;
        --rimuovi) RIMUOVI=1; shift ;;
        -h|--aiuto) sed -n '2,16p' "$0"; exit 0 ;;
        *) die "opzione sconosciuta: $1" ;;
    esac
done
[[ $EUID -eq 0 ]] || die "eseguire con sudo: sudo $0"
[[ $REPO =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "repository non valido: $REPO"
[[ $ETICHETTA =~ ^[a-z0-9][a-z0-9-]{1,40}$ ]] || die "etichetta non valida: $ETICHETTA"
[[ $NOME =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$ ]] || die "nome non valido: $NOME"

chiedi_token() {
    local scopo=$1 token=${EMP_RUNNER_TOKEN:-}
    if [[ -z $token ]]; then
        [[ -t 0 ]] || die "token di $scopo mancante: impostare EMP_RUNNER_TOKEN"
        echo "Token di $scopo da GitHub ($REPO → Settings → Actions → Runners):" >&2
        read -rs -p "Token: " token; echo >&2
    fi
    [[ $token =~ ^[A-Za-z0-9_-]{20,200}$ ]] || die "token non valido"
    printf '%s' "$token"
}

come_runner() { (cd "$RUNNER" && sudo -u "$UTENTE" -H "$@"); }

# ----------------------------------------------------------------- rimozione
if ((RIMUOVI)); then
    passo "Rimozione del runner"
    if [[ -f $RUNNER/.runner ]]; then
        (cd "$RUNNER" || exit 1; ./svc.sh stop || true; ./svc.sh uninstall || true)
        token=$(chiedi_token rimozione)
        come_runner ./config.sh remove --token "$token" || echo "Avviso: rimuovere il runner anche da GitHub (Settings → Actions → Runners)."
    fi
    rm -f "$SUDOERS" "$COMANDO"
    rm -rf "$RUNNER"
    if id "$UTENTE" >/dev/null 2>&1; then userdel -r "$UTENTE" 2>/dev/null || userdel "$UTENTE"; fi
    echo "Runner rimosso. EMP Server e i suoi dati non sono stati toccati."
    exit 0
fi

# ----------------------------------------------------------------- 1. sistema
passo "Controllo del sistema"
# shellcheck disable=SC1091
[[ $(. /etc/os-release && echo "$ID") == ubuntu ]] || die "serve Ubuntu Server LTS"
[[ -d /run/systemd/system ]] || die "systemd non attivo"
case $(uname -m) in
    x86_64) ARCH=x64 ;;
    aarch64) ARCH=arm64 ;;
    *) die "architettura non supportata: $(uname -m)" ;;
esac
[[ -f $ORIGINE/emp-installa-da-github ]] || die "manca emp-installa-da-github accanto a questo script"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q --no-install-recommends curl jq unzip ca-certificates tar sudo
curl -fsS --max-time 15 -o /dev/null https://api.github.com || die "GitHub non raggiungibile: il runner ha bisogno di Internet in uscita (HTTPS)"

# ----------------------------------------------------------------- 2. utente e comando
passo "Utente $UTENTE e comando di installazione"
if ! id "$UTENTE" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "$CASA" --shell /bin/bash "$UTENTE"
fi
passwd -l "$UTENTE" >/dev/null
# Nessun gruppo con privilegi (sudo, adm, emp…): il runner resta un utente qualsiasi.
for g in sudo adm admin emp lxd docker; do gpasswd -d "$UTENTE" "$g" >/dev/null 2>&1 || true; done

install -m 0755 -o root -g root "$ORIGINE/emp-installa-da-github" "$COMANDO"
install -d -m 0755 -o root -g root /etc/emp
if [[ ! -f $CONF ]]; then
    cat > "$CONF" <<EOF
# Installazione da GitHub (letto da $COMANDO). Solo root può modificarlo.
REPO=$REPO
WORKFLOW=.github/workflows/installa-server.yml
ARTEFATTO=emp-server-pacchetto
# Utenti GitHub autorizzati ad avviare l'installazione, separati da virgola (predefinito: proprietario del repository)
AUTORIZZATI=${REPO%%/*}
# Minuti massimi tra l'avvio dell'esecuzione su GitHub e l'installazione
ETA_MASSIMA_MIN=180
# Nome DNS aggiuntivo nel certificato HTTPS (facoltativo)
EMP_NOME_DNS=
EOF
fi
chown root:root "$CONF"; chmod 0644 "$CONF"

tmp=$(mktemp)
# Niente "Defaults !requiretty": non serve su Ubuntu e sudo-rs (predefinito da Ubuntu 26.04) non lo riconosce.
printf '# Il runner GitHub può eseguire come root SOLO il comando di installazione verificata.\n%s ALL=(root) NOPASSWD: %s\n' \
    "$UTENTE" "$COMANDO" > "$tmp"
visudo -cqf "$tmp" || die "regola sudo non valida"
install -m 0440 -o root -g root "$tmp" "$SUDOERS"; rm -f "$tmp"
echo "Comando: $COMANDO · configurazione: $CONF"

# ----------------------------------------------------------------- 3. runner
passo "Runner GitHub Actions"
if [[ -f $RUNNER/.runner ]]; then
    echo "Runner già registrato: $(jq -r '.agentName + " su " + .gitHubUrl' "$RUNNER/.runner" 2>/dev/null || echo "$RUNNER")"
    echo "(per registrarlo di nuovo: sudo $0 --rimuovi, poi rieseguire)"
else
    rilascio=$(curl -fsS --max-time 30 https://api.github.com/repos/actions/runner/releases/latest)
    versione=$(jq -r .tag_name <<<"$rilascio"); versione=${versione#v}
    [[ $versione =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "versione del runner non riconosciuta"
    # Le note di rilascio ufficiali riportano l'impronta SHA-256 di ogni pacchetto.
    impronta=$(jq -r .body <<<"$rilascio" | grep -oE "<!-- BEGIN SHA linux-$ARCH -->[0-9a-f]{64}<!-- END SHA linux-$ARCH -->" \
        | grep -oE '[0-9a-f]{64}' | head -n1 || true)
    [[ -n $impronta ]] || die "impronta del runner $versione non trovata nelle note di rilascio"
    file=actions-runner-linux-$ARCH-$versione.tar.gz
    echo "Runner $versione ($ARCH)"
    curl -fsSL --max-time 600 -o "/tmp/$file" "https://github.com/actions/runner/releases/download/v$versione/$file"
    echo "$impronta  /tmp/$file" | sha256sum --check --strict --quiet || die "impronta del runner non corrispondente"

    install -d -m 0750 -o "$UTENTE" -g "$UTENTE" "$RUNNER"
    sudo -u "$UTENTE" tar -xzf "/tmp/$file" -C "$RUNNER"
    rm -f "/tmp/$file"
    "$RUNNER/bin/installdependencies.sh" >/dev/null

    token=$(chiedi_token registrazione)
    come_runner ./config.sh --unattended --url "https://github.com/$REPO" --token "$token" \
        --name "$NOME" --labels "$ETICHETTA" --work _work --replace
    unset token
    (cd "$RUNNER" && ./svc.sh install "$UTENTE" && ./svc.sh start)
fi

servizio=$(cat "$RUNNER/.service" 2>/dev/null || true)
if [[ -n $servizio ]] && systemctl is-active --quiet "$servizio"; then
    echo "Servizio attivo: $servizio (parte da solo all'avvio della VM)"
else
    die "il servizio del runner non risulta attivo"
fi

cat <<EOF

Fatto. Il runner "$NOME" (etichetta: $ETICHETTA) è collegato a $REPO.

Su GitHub, una volta sola:
  1. Settings → Actions → General → "Fork pull request workflows from outside collaborators":
     scegliere "Require approval for all outside collaborators".
  2. (Consigliato) Settings → Environments → server-negozio → Required reviewers: il titolare.

Per installare o aggiornare: Actions → "Installa EMP Server" → Run workflow
(scegliere il branch e, se ci sono più negozi, l'etichetta del Server).
EOF
