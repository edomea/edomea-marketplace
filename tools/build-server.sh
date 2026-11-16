#!/usr/bin/env bash
# Costruisce il pacchetto di installazione di EMP Server:
#
#   tools/build-server.sh <cartella-destinazione> [--con-esempi]
#
# Produce emp-server-<versione>.tar.gz (e il file .sha256) con:
#   installa.sh, disinstalla.sh, emp-server, modelli/, VERSIONE, LEGGIMI.txt,
#   pacchetti/ (Core, con indice e impronte), catalogo/ (plugin installabili dal web),
#   SHA256SUMS (controllato da installa.sh prima di qualsiasi modifica).
# Archivio riproducibile: stesso sorgente, stessa impronta.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
out=${1:?uso: tools/build-server.sh <cartella-destinazione> [--con-esempi]}
examples=${2:-}
version=$(tr -d '[:space:]' < "$root/server/VERSIONE")
name=emp-server-$version

mkdir -p "$out"
out=$(cd "$out" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
pkg=$work/$name
mkdir -p "$pkg/pacchetti" "$pkg/catalogo"

cp "$root/server/installa.sh" "$root/server/disinstalla.sh" "$root/server/emp-server" "$root/server/VERSIONE" "$root/server/LEGGIMI.txt" "$pkg/"
cp -r "$root/server/modelli" "$pkg/modelli"
php "$root/tools/build-packages.php" core "$pkg/pacchetti" >/dev/null
if [[ $examples == --con-esempi ]]; then
    php "$root/tools/build-packages.php" plugin "$pkg/catalogo" --con-esempi >/dev/null
else
    php "$root/tools/build-packages.php" plugin "$pkg/catalogo" >/dev/null
fi
chmod 0755 "$pkg/installa.sh" "$pkg/disinstalla.sh" "$pkg/emp-server"

( cd "$pkg" && find . -type f -printf '%P\n' | LC_ALL=C sort | xargs sha256sum > "$work/SHA256SUMS" )
mv "$work/SHA256SUMS" "$pkg/SHA256SUMS"

tar --sort=name --mtime='1980-01-01 00:00:00Z' --owner=0 --group=0 --numeric-owner \
    -C "$work" -cf - "$name" | gzip -9n > "$out/$name.tar.gz"
( cd "$out" && sha256sum "$name.tar.gz" > "$name.tar.gz.sha256" )
cat "$out/$name.tar.gz.sha256"
