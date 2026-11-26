#!/usr/bin/env bash
# EMP Server — disinstallazione.
#
#   sudo ./disinstalla.sh
#
# Toglie EMP da Nginx e PHP-FPM, le operazioni pianificate e il comando emp-server.
# NON cancella i dati: database "emp", /var/lib/emp (moduli, backup) e /etc/emp
# (configurazione, certificati) restano, così una nuova installazione li ritrova.
# Nginx, PHP e MariaDB restano installati: sono pacchetti di Ubuntu.
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "ERRORE: eseguire con sudo: sudo ./disinstalla.sh" >&2; exit 1; }

echo "• Sito Nginx"
rm -f /etc/nginx/sites-enabled/emp /etc/nginx/sites-available/emp
if systemctl is-active --quiet nginx; then nginx -t -q && systemctl reload nginx; fi

echo "• Gruppo PHP-FPM"
for pool in /etc/php/*/fpm/pool.d/emp.conf; do
    [[ -e $pool ]] || continue
    dir=$(dirname "$pool")
    rm -f "$pool" "$dir/emp-wordpress.conf"
    [[ -f $dir/www.conf.disattivato ]] && mv "$dir/www.conf.disattivato" "$dir/www.conf"
    version=$(basename "$(dirname "$(dirname "$dir")")")
    systemctl restart "php${version}-fpm" || true
done

echo "• Operazioni pianificate"
systemctl disable -q --now emp-backup.timer emp-certificato.timer emp-backup-web.path emp-wordpress-cron.timer 2>/dev/null || true
rm -f /etc/systemd/system/emp-backup.service /etc/systemd/system/emp-backup.timer \
      /etc/systemd/system/emp-certificato.service /etc/systemd/system/emp-certificato.timer \
      /etc/systemd/system/emp-backup-web.service /etc/systemd/system/emp-backup-web.path \
      /etc/systemd/system/emp-wordpress-cron.service /etc/systemd/system/emp-wordpress-cron.timer
systemctl daemon-reload

echo "• Comando emp-server"
rm -f /usr/local/sbin/emp-server
rm -rf /opt/emp/server

echo
echo "EMP Server disinstallato. Dati conservati:"
echo "  database MariaDB \"emp\", /var/lib/emp (moduli, backup), /etc/emp (configurazione, certificati)"
echo "Per cancellarli definitivamente (irreversibile), fare prima un backup e poi:"
echo "  sudo mariadb -e 'DROP DATABASE emp; DROP USER emp@localhost' && sudo rm -rf /var/lib/emp /etc/emp"
echo "Le regole del firewall (porte 80 e 443) restano: sudo ufw delete allow 80/tcp && sudo ufw delete allow 443/tcp"
