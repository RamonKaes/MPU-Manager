#!/usr/bin/env bash
# =============================================================================
# pruefe_zugriff.sh – Zugriffsregeln der .htaccess an einem echten Apache messen
# =============================================================================
# `php -S` wertet die .htaccess nicht aus, die PHP-Tests sagen darüber nichts.
# Das Skript kopiert den gebauten Stand, legt Dateien hinein, die nie ausgeliefert
# werden dürfen, und fragt sie ab. PHP läuft dabei nicht: Geprüft werden nur die
# Regeln, nie die Anwendung.
#
# Aufruf: bash scripts/pruefe_zugriff.sh dist
# =============================================================================
set -euo pipefail

QUELLE="${1:?Aufruf: pruefe_zugriff.sh <Verzeichnis, z. B. dist>}"
APACHE="$(command -v apache2 || echo /usr/sbin/apache2)"
MODULE="${APACHE_MODULE:-/usr/lib/apache2/modules}"
PORT="${PRUEFE_PORT:-8099}"

ARBEIT="$(mktemp -d)"
beenden() {
    "$APACHE" -f "$ARBEIT/apache.conf" -k stop 2>/dev/null || true
    rm -rf "$ARBEIT"
}
trap beenden EXIT

cp -a "$QUELLE" "$ARBEIT/web"
# Zugangsdaten braucht hier niemand, und SetEnv verlangt nichts Weiteres.
sed -i '/^SetEnv /d' "$ARBEIT/web/.htaccess"

GESPERRT=(backup.sql.gz mpu_20260101_010101.sql.gz dump.sql .env .env.bak .env.local x.zip x.tar.gz x.bak
          error_log x.csv x.json x.pdf x.log akte.php~ akte.php.bak)
for DATEI in "${GESPERRT[@]}"; do
    echo geheim > "$ARBEIT/web/$DATEI"
done

{
    echo "ServerRoot $ARBEIT"
    echo "ServerName localhost"
    echo "Listen 127.0.0.1:$PORT"
    echo "PidFile $ARBEIT/pid"
    echo "ErrorLog $ARBEIT/fehler.log"
    echo "TypesConfig /etc/mime.types"
    for MODUL in mpm_prefork authz_core authz_host rewrite dir env mime headers expires filter deflate autoindex; do
        echo "LoadModule ${MODUL}_module $MODULE/mod_$MODUL.so"
    done
    echo "DirectoryIndex index.php"
    echo "DocumentRoot $ARBEIT/web"
    echo "<Directory />"
    echo "    AllowOverride None"
    echo "</Directory>"
    echo "<Directory $ARBEIT/web>"
    echo "    AllowOverride All"
    echo "    Require all granted"
    echo "</Directory>"
} > "$ARBEIT/apache.conf"

"$APACHE" -f "$ARBEIT/apache.conf" -k start
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
    sleep 0.1
done

FEHLER=0
erwarte() {
    local soll="$1" pfad="$2"
    shift 2
    local ist
    ist="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: praxis.example' "$@" "http://127.0.0.1:$PORT/$pfad")"
    if [ "$ist" != "$soll" ]; then
        echo "FALSCH: /$pfad liefert $ist, erwartet $soll" >&2
        FEHLER=1
    fi
}

PROXY=(-H 'X-Forwarded-Proto: https')
erwarte 301 login.php
erwarte 200 login.php "${PROXY[@]}"
for DATEI in "${GESPERRT[@]}" schema.sql LICENSE .htaccess assets/ config/allowed_values.php logic/request.php \
             handlers/akte_handler.php partials/_head.php migrations/ scripts/migrate.php auth.php triage.php; do
    erwarte 403 "$DATEI" "${PROXY[@]}"
done
for DATEI in assets/css/tabler.min.css assets/fonts/inter.css favicon.ico; do
    erwarte 200 "$DATEI" "${PROXY[@]}"
done

if [ "$FEHLER" -ne 0 ]; then
    echo "Fehlerlog:" >&2
    tail -5 "$ARBEIT/fehler.log" >&2 || true
    exit 1
fi
echo "Zugriffsregeln stimmen."
