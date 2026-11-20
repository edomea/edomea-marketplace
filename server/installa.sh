#!/usr/bin/env bash
# EMP Server — installazione e aggiornamento su Ubuntu Server LTS (26.04 consigliata, 24.04 supportata).
#
#   sudo ./installa.sh
#
# Dalla cartella estratta del pacchetto emp-server-<versione>.tar.gz. Rieseguito su un
# Server già installato, lo AGGIORNA: i dati (database, moduli, certificati) restano.
#
# Cosa fa, nell'ordine:
#   1. controlla sistema operativo e pacchetto (impronte SHA-256);
#   2. installa dai repository ufficiali di Ubuntu: Nginx, PHP-FPM, MariaDB, firewall,
#      aggiornamenti di sicurezza automatici;
#   3. crea l'utente di sistema "emp" e le cartelle (/etc/emp, /var/lib/emp);
#   4. MariaDB solo tramite socket locale; database "emp" e utente "emp" senza password
#      (autenticazione unix_socket: solo il processo che gira come utente emp può entrare);
#   5. autorità di certificazione locale e certificato HTTPS del Server;
#   6. installa o aggiorna il Core ed esegue le migrazioni del database;
#   7. configura PHP-FPM, Nginx, firewall (porte 22, 80, 443), backup notturno;
#   8. verifica che il Core risponda e mostra indirizzi e codice di setup.
#
# Variabili facoltative:
#   EMP_NOME_DNS=emp.negozio.lan   nome aggiuntivo nel certificato (DNS del negozio)
#   EMP_SENZA_FIREWALL=1           non configura ufw (firewall gestito altrove)
#   EMP_CONSENTI_SISTEMA=1         consente versioni di Ubuntu non collaudate
set -euo pipefail

SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
readonly SOURCE_DIR
# Funzioni e percorsi condivisi con il comando di gestione.
# shellcheck source-path=SCRIPTDIR source=emp-server
source "$SOURCE_DIR/emp-server"
umask 022

readonly SUPPORTED_UBUNTU="24.04 26.04"
readonly MIN_PHP=8.2
EMP_VERSION=$(tr -d '[:space:]' < "$SOURCE_DIR/VERSIONE")
readonly EMP_VERSION

step() { echo; echo "==> $*"; }

# Campo di /etc/os-release, letto in una sotto-shell per non sovrascrivere variabili.
os_field() {
    # shellcheck disable=SC1091
    (. /etc/os-release && printf '%s' "${!1:-}")
}

# ---------------------------------------------------------------- 1. controlli

check_system() {
    step "Controllo del sistema"
    [[ $EUID -eq 0 ]] || die "eseguire con sudo: sudo ./installa.sh"
    [[ -r /etc/os-release ]] || die "sistema operativo non riconosciuto"
    local os_id os_version os_name
    os_id=$(os_field ID)
    os_version=$(os_field VERSION_ID)
    os_name=$(os_field PRETTY_NAME)
    [[ $os_id == ubuntu ]] || die "serve Ubuntu Server LTS (trovato: ${os_name:-?})"
    if [[ " $SUPPORTED_UBUNTU " != *" ${os_version} "* && ${EMP_CONSENTI_SISTEMA:-} != 1 ]]; then
        die "Ubuntu ${os_version:-?} non collaudata (supportate: $SUPPORTED_UBUNTU). Per provare comunque: sudo EMP_CONSENTI_SISTEMA=1 ./installa.sh"
    fi
    [[ $(uname -m) == x86_64 || $(uname -m) == aarch64 ]] || die "architettura non supportata: $(uname -m)"
    command -v systemctl >/dev/null || die "serve systemd"
    info "${os_name}, $(uname -m), EMP Server ${EMP_VERSION}"
    if [[ -f $EMP_HOME/VERSIONE ]]; then
        info "Aggiornamento da EMP Server $(cat "$EMP_HOME/VERSIONE")"
    fi
}

check_package() {
    step "Controllo del pacchetto"
    ( cd "$SOURCE_DIR" && sha256sum --quiet --strict -c SHA256SUMS ) || die "pacchetto danneggiato o modificato (SHA256SUMS)"
    info "Impronte SHA-256 corrette"
}

# ---------------------------------------------------------------- 2. pacchetti di sistema

install_packages() {
    step "Installazione dei componenti dai repository ufficiali di Ubuntu"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -q
    apt-get install -y -q --no-install-recommends \
        nginx mariadb-server mariadb-client \
        php-fpm php-cli php-mysql php-mbstring php-zip php-curl \
        openssl ca-certificates curl ufw unattended-upgrades lsb-release rclone
    # In una macchina virtuale VMware: strumenti per lo spegnimento ordinato insieme al PC che la ospita.
    if [[ $(systemd-detect-virt 2>/dev/null || true) == vmware ]]; then
        apt-get install -y -q --no-install-recommends open-vm-tools
        systemctl enable -q --now open-vm-tools 2>/dev/null || true
        info "Macchina virtuale VMware: open-vm-tools attivi"
    fi
    PHP_VERSION=$(php_version)
    readonly PHP_VERSION
    php -r "exit(version_compare(PHP_VERSION, '${MIN_PHP}', '>=') ? 0 : 1);" || die "serve PHP ${MIN_PHP} o successivo (trovato ${PHP_VERSION})"
    for ext in pdo_mysql mbstring zip; do
        php -m | grep -qix "$ext" || die "estensione PHP mancante: $ext"
    done
    info "Nginx $(nginx -v 2>&1 | cut -d/ -f2), PHP ${PHP_VERSION}, $(mariadb --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+-MariaDB' | head -1)"

    # Aggiornamenti di sicurezza automatici (senza riavvii automatici).
    cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
    info "Aggiornamenti di sicurezza automatici attivi"
}

# ---------------------------------------------------------------- 3. utente e cartelle

create_layout() {
    step "Utente di sistema e cartelle"
    if ! id -u "$EMP_USER" >/dev/null 2>&1; then
        useradd --system --home-dir "$EMP_DATA" --no-create-home --shell /usr/sbin/nologin "$EMP_USER"
        info "Creato l'utente di sistema $EMP_USER"
    elif emp_user_is_personal; then
        # Ubuntu installato con l'utente "emp": per ora EMP lo usa, ma va sostituito (avviso nel riepilogo).
        info "ATTENZIONE: '$EMP_USER' è un account personale: vedere l'avviso alla fine"
    fi
    create_dirs
    if [[ -n ${EMP_NOME_DNS:-} ]]; then
        [[ $EMP_NOME_DNS =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]] || die "EMP_NOME_DNS non valido: $EMP_NOME_DNS"
        echo "$EMP_NOME_DNS" > "$EMP_CONF_DIR/nome-dns"
    fi
    info "Programmi: $EMP_HOME — Dati: $EMP_DATA — Configurazione: $EMP_CONF_DIR"
}

# ---------------------------------------------------------------- 4. database

setup_database() {
    step "Database MariaDB"
    install -m 0644 "$SOURCE_DIR/modelli/mariadb-emp.cnf" /etc/mysql/mariadb.conf.d/90-emp.cnf
    systemctl enable -q mariadb
    systemctl restart mariadb
    # root accede tramite unix_socket (impostazione predefinita di Ubuntu).
    mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS emp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'emp'@'localhost' IDENTIFIED VIA unix_socket;
GRANT ALL PRIVILEGES ON emp.* TO 'emp'@'localhost';
DELETE FROM mysql.global_priv WHERE User = '';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
SQL
    info "Database emp pronto; accesso solo dal socket locale, senza password"
}

write_core_config() {
    local tmp
    tmp=$(mktemp)
    cat > "$tmp" <<EOF
<?php
// EMP Server — configurazione del Core. Generata da installa.sh.
// Nessuna password: MariaDB riconosce l'utente di sistema "emp" (unix_socket).
return [
    'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=emp;charset=utf8mb4',
    'user' => 'emp',
    'password' => '',
    'prefix' => 'emp_',
    'timezone' => 'Europe/Rome',
    'modules_dir' => '${EMP_MODULES}',
    'catalog_dir' => '${EMP_DATA}/catalogo',
    'local_catalog_dir' => '${EMP_DATA}/catalogo-locale',
    'server_version' => '${EMP_VERSION}',
    'arrivals_dir' => '${EMP_DATA}/arrivi/plugin',
    'backup_web_dir' => '${EMP_DATA}/backup-web',
    'boot_control_file' => '${EMP_CONF_DIR}/avvio.conf',
];
EOF
    install -m 0640 -o root -g "$EMP_USER" "$tmp" "$EMP_CONFIG"
    rm -f "$tmp"
}

# ---------------------------------------------------------------- 6. Core

package_field() { # $1 = campo; legge pacchetti/emp-packages.json con PHP
    # shellcheck disable=SC2016  # codice PHP, non variabili della shell
    php -r '$i = json_decode(file_get_contents($argv[1]), true); foreach ($i["packages"] as $p) { if ($p["id"] === "emp-core") { echo $p[$argv[2]]; exit(0); } } exit(1);' \
        "$SOURCE_DIR/pacchetti/emp-packages.json" "$1"
}

install_core() {
    step "EMP Core"
    local file sha version staging dest backups n
    file=$(package_field file)
    sha=$(package_field sha256)
    version=$(package_field version)
    [[ $file =~ ^emp-core-[0-9A-Za-z.+-]+\.zip$ ]] || die "nome del pacchetto del Core non valido"
    echo "${sha}  ${SOURCE_DIR}/pacchetti/${file}" | sha256sum --quiet --strict -c - || die "impronta del pacchetto del Core non corrispondente"

    staging=$(mktemp -d "$EMP_DATA/.core-XXXXXX")
    # Estrazione con PHP ZipArchive e controllo dei percorsi (nessun ../, nessun percorso assoluto).
    # shellcheck disable=SC2016  # codice PHP, non variabili della shell
    php -r '
        $z = new ZipArchive();
        if ($z->open($argv[1]) !== true) { fwrite(STDERR, "archivio non leggibile\n"); exit(1); }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!str_starts_with($n, "emp-core/") || str_contains($n, "..") || str_contains($n, "\\") || str_starts_with($n, "/")) {
                fwrite(STDERR, "percorso non consentito: $n\n"); exit(1);
            }
        }
        if (!$z->extractTo($argv[2])) { exit(1); }
    ' "$SOURCE_DIR/pacchetti/$file" "$staging"
    [[ -f $staging/emp-core/emp-module.json ]] || die "pacchetto del Core non valido"
    chown -R root:root "$staging/emp-core"
    chmod -R u=rwX,go=rX "$staging/emp-core"
    chmod 0755 "$staging/emp-core/bin/emp"

    dest=$EMP_MODULES/emp-core
    if [[ -f $dest/emp-module.json ]]; then
        # Mai una versione più vecchia di quella installata (regole delle versioni del Core).
        # shellcheck disable=SC2016  # codice PHP, non variabili della shell
        php -r '
            require $argv[1] . "/src/bootstrap.php";
            $new = Emp\Core\Version\Version::parse(json_decode(file_get_contents($argv[1] . "/emp-module.json"), true)["version"]);
            $old = Emp\Core\Version\Version::parse(json_decode(file_get_contents($argv[2] . "/emp-module.json"), true)["version"]);
            if ($new->compare($old) < 0) { fwrite(STDERR, "il pacchetto contiene il Core $new, più vecchio di quello installato ($old)\n"); exit(1); }
        ' "$staging/emp-core" "$dest" || { rm -rf "$staging"; die "aggiornamento rifiutato"; }
    fi
    if [[ -d $dest ]]; then
        backups=$EMP_DATA/precedenti
        install -d -m 0700 -o root -g root "$backups"
        n=$(date +%Y%m%d-%H%M%S)
        mv "$dest" "$backups/emp-core-$n"
        # Conserva le ultime 5 versioni precedenti.
        find "$backups" -mindepth 1 -maxdepth 1 -name 'emp-core-*' | sort -r | tail -n +6 | xargs -r rm -rf --
        info "Versione precedente conservata in $backups/emp-core-$n"
    fi
    mv "$staging/emp-core" "$dest"
    rm -rf "$staging"
    info "File del Core ${version} installati"

    # Catalogo dei plugin installabili dalla pagina web del Core.
    if [[ -d $SOURCE_DIR/catalogo ]]; then
        find "$EMP_DATA/catalogo" -mindepth 1 -maxdepth 1 -type f -delete
        find "$SOURCE_DIR/catalogo" -mindepth 1 -maxdepth 1 -type f -exec install -m 0644 -o root -g root {} "$EMP_DATA/catalogo/" \;
    fi

    core_cli installa --utente=installer | sed 's/^/  /'
}

# ---------------------------------------------------------------- 7. servizi

configure_services() {
    step "PHP-FPM, Nginx, firewall e operazioni pianificate"
    local subst=(-e "s#@EMP_DATA@#${EMP_DATA}#g" -e "s#@EMP_MODULES@#${EMP_MODULES}#g" -e "s#@EMP_TLS@#${EMP_TLS}#g" -e "s#@EMP_CONFIG@#${EMP_CONFIG}#g" -e "s#@EMP_BOOT_CONF@#${EMP_CONF_DIR}/avvio.conf#g")

    sed "${subst[@]}" "$SOURCE_DIR/modelli/php-fpm-emp.conf" > "/etc/php/${PHP_VERSION}/fpm/pool.d/emp.conf"
    # Il gruppo predefinito "www" di Ubuntu non serve: disattivato per ridurre la superficie.
    if [[ -f /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf ]]; then
        mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disattivato"
    fi
    "php-fpm${PHP_VERSION}" -t -y "/etc/php/${PHP_VERSION}/fpm/php-fpm.conf" 2>/dev/null || die "configurazione PHP-FPM non valida"
    systemctl enable -q "php${PHP_VERSION}-fpm"
    systemctl restart "php${PHP_VERSION}-fpm"

    # Senza IPv6 nel sistema (es. disattivato nella VM) Nginx ascolta solo in IPv4.
    [[ -e /proc/net/if_inet6 ]] || subst+=(-e '/listen \[::\]/d')
    sed "${subst[@]}" "$SOURCE_DIR/modelli/nginx-emp.conf" > /etc/nginx/sites-available/emp
    ln -sfn /etc/nginx/sites-available/emp /etc/nginx/sites-enabled/emp
    rm -f /etc/nginx/sites-enabled/default
    nginx -t -q || die "configurazione Nginx non valida"
    systemctl enable -q nginx
    systemctl restart nginx

    if [[ ${EMP_SENZA_FIREWALL:-} != 1 ]]; then
        ufw --force default deny incoming >/dev/null
        ufw --force default allow outgoing >/dev/null
        ufw allow 22/tcp comment 'SSH' >/dev/null
        ufw allow 80/tcp comment 'EMP certificato e rinvio HTTPS' >/dev/null
        ufw allow 443/tcp comment 'EMP' >/dev/null
        ufw --force enable >/dev/null
        info "Firewall attivo: aperte solo le porte 22 (SSH), 80 e 443"
    fi

    install -m 0644 "$SOURCE_DIR"/modelli/systemd/emp-*.service "$SOURCE_DIR"/modelli/systemd/emp-*.timer \
        "$SOURCE_DIR"/modelli/systemd/emp-*.path /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable -q --now emp-certificato.timer emp-backup-web.path
    systemctl reset-failed emp-backup-web.path emp-backup-web.service 2>/dev/null || true
    systemctl restart emp-backup-web.path
    # Orario, attivazione e conservazione dei backup: impostazioni della macchina (pagina Backup di /techadmin).
    apply_backup_timer
    if [[ $BACKUP_NIGHTLY == si ]]; then
        info "Backup ogni notte alle $BACKUP_TIME in $EMP_BACKUP (ultimi $BACKUP_KEEP); controllo giornaliero del certificato"
    else
        info "Backup notturno disattivato (pagina Backup di /techadmin); controllo giornaliero del certificato"
    fi
}

install_programs() {
    install -d -m 0755 -o root -g root "$EMP_HOME"
    install -m 0755 "$SOURCE_DIR/emp-server" "$EMP_HOME/emp-server"
    # Esecutore (root) delle richieste della pagina Backup di /techadmin.
    install -m 0755 "$SOURCE_DIR/emp-backup-web" "$EMP_HOME/emp-backup-web"
    install -m 0644 "$SOURCE_DIR/VERSIONE" "$EMP_HOME/VERSIONE"
    # Script per collegare il Server a GitHub (emp-server collega-github).
    if [[ -d $SOURCE_DIR/github ]]; then
        install -d -m 0755 -o root -g root "$EMP_HOME/github"
        install -m 0755 "$SOURCE_DIR/github/prepara-runner.sh" "$SOURCE_DIR/github/emp-installa-da-github" "$EMP_HOME/github/"
    fi
    ln -sfn "$EMP_HOME/emp-server" /usr/local/sbin/emp-server
    # Copia del pacchetto installato: va nei backup, così un ripristino rimette questa versione.
    install -d -m 0700 -o root -g root "$EMP_PACKAGE_DIR"
    find "$EMP_PACKAGE_DIR" -mindepth 1 -maxdepth 1 -name 'emp-server-*.tar.gz' ! -name "emp-server-${EMP_VERSION}.tar.gz" -delete
    tar -C "$(dirname "$SOURCE_DIR")" --transform "s#^$(basename "$SOURCE_DIR")#emp-server-${EMP_VERSION}#" \
        -czf "$EMP_PACKAGE_DIR/emp-server-${EMP_VERSION}.tar.gz" "$(basename "$SOURCE_DIR")"
}

# Prima di aggiornare un Server già in uso: backup completo di EMP. Se non riesce, niente aggiornamento.
backup_before_update() {
    [[ ${EMP_RIPRISTINO:-} == 1 ]] && return 0   # ripristino in corso: il backup è già stato fatto
    [[ -f $EMP_MODULES/emp-core/emp-module.json ]] || return 0
    emp_database_exists || return 0
    step "Backup di EMP prima dell'aggiornamento"
    local rc=0
    cmd_backup --etichetta "prima-di-${EMP_VERSION}" || rc=$?
    # 3 = copia remota non riuscita: il backup locale è valido e l'aggiornamento prosegue.
    ((rc == 0 || rc == 3)) || die "backup non riuscito: aggiornamento annullato, nessuna modifica eseguita"
}

# ---------------------------------------------------------------- 8. verifica e riepilogo

wait_health() {
    step "Verifica"
    local _
    for _ in $(seq 1 30); do
        if curl -fsS --max-time 5 --cacert "$EMP_TLS/ca.crt" --resolve "localhost:443:127.0.0.1" \
            https://localhost/api/v1/salute >/dev/null 2>&1; then
            info "Il Core risponde in HTTPS"
            return 0
        fi
        sleep 1
    done
    die "il Core non risponde. Controllare: /var/log/nginx/emp-errori.log e $EMP_LOGS/php-errori.log"
}

summary() {
    local initialized code
    initialized=$(curl -fsS --cacert "$EMP_TLS/ca.crt" --resolve "localhost:443:127.0.0.1" https://localhost/api/v1/accesso/stato \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["inizializzato"] ? "si" : "no";')
    echo
    echo "================================================================"
    echo " EMP Server ${EMP_VERSION} installato"
    echo "================================================================"
    echo
    echo " 1) Su ogni PC del negozio installare UNA VOLTA l'autorità locale:"
    for ip in $(private_ipv4); do echo "      http://${ip}/emp-ca.crt"; done
    echo "    Impronta SHA-256 da confrontare prima di installarla:"
    echo "      $(ca_fingerprint)"
    echo
    echo " 2) Aprire EMP dal browser:"
    cmd_indirizzi
    if [[ $initialized == no ]]; then
        code=$(core_cli codice-setup | sed -n 's/^Codice di setup: //p')
        echo
        echo " 3) Primo setup dell'Amministratore Tecnico — codice monouso (valido 24 ore):"
        echo
        echo "      ${code}"
        echo
        echo "    Se scade o si perde: sudo emp-server codice-setup"
    fi
    echo
    echo " Gestione: sudo emp-server stato | backup | ripristina | backup-remoto | accesso-scp | certificato | collega-github"
    echo
    warn_personal_emp_user
}

main() {
    check_system
    check_package
    backup_before_update
    install_packages
    create_layout
    install_programs
    setup_database
    write_core_config
    cmd_certificato --se-necessario
    install_core
    configure_services
    wait_health
    summary
}

main "$@"
