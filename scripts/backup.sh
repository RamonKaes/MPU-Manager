#!/usr/bin/env bash
# =============================================================================
# backup.sh – MPU-Manager Datenbank-Backup
# =============================================================================
# Liest die Einstellungen aus Umgebungsvariablen:
#   DB_NAME          (Pflicht)
#   DB_USER          (Pflicht)
#   DB_PASS          (Pflicht)
#   DB_HOST          (Optional, Default: localhost)
#   MPU_BACKUP_DIR   (Optional, Default: /var/backups/mpu)
#   MPU_BACKUP_LOG   (Optional, Default: /var/log/mpu_backup.log)
#
# Beide Default-Pfade verlangen Schreibrechte, die es auf Shared Hosting meist
# nicht gibt. Dort eigene Pfade setzen. Der Sicherungsordner muss außerhalb des
# Webroots liegen, denn die Dumps enthalten Gesundheitsdaten.
#
# Ablage:   <MPU_BACKUP_DIR>/mpu_YYYYMMDD_HHMMSS.sql.gz
# Rotation: behalte die 30 neuesten Dateien, ältere werden gelöscht
# Log:      eine Zeile pro Lauf, ohne schreibbares Log auf stderr
# Exit:     ungleich 0, wenn keine gültige Sicherung entstanden ist
# =============================================================================

set -euo pipefail

# Dumps und Temp-Dateien sind nur für den eigenen Benutzer lesbar.
umask 077

# ── Konfiguration ─────────────────────────────────────────────────────────────
BACKUP_DIR="${MPU_BACKUP_DIR:-/var/backups/mpu}"
LOG_FILE="${MPU_BACKUP_LOG:-/var/log/mpu_backup.log}"
KEEP=30
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
DUMP_FILE="${BACKUP_DIR}/mpu_${TIMESTAMP}.sql.gz"
# Der Punkt am Anfang hält die Temp-Datei aus dem Muster der Rotation heraus.
TEMP_FILE="${BACKUP_DIR}/.mpu_${TIMESTAMP}.sql.gz.tmp"

# Ein Fehlschlag darf nicht unsichtbar bleiben, auch nicht ohne schreibbares Log.
log_zeile() {
    { echo "[${TIMESTAMP}] $1" >> "${LOG_FILE}"; } 2>/dev/null || echo "[${TIMESTAMP}] $1" >&2
}

fehlschlag() {
    local grund="$1" code="$2"
    rm -f -- "${TEMP_FILE}"
    log_zeile "ERR ${grund} (Exit-Code ${code})"
    exit "${code}"
}

# ── Einstellungen prüfen ──────────────────────────────────────────────────────
for variable in DB_NAME DB_USER DB_PASS; do
    if [ -z "${!variable:-}" ]; then
        fehlschlag "Umgebungsvariable ${variable} ist nicht gesetzt" 1
    fi
done
DB_HOST="${DB_HOST:-localhost}"

# ── Backup-Verzeichnis anlegen ────────────────────────────────────────────────
if ! mkdir -p -- "${BACKUP_DIR}" 2>/dev/null; then
    fehlschlag "Backup-Verzeichnis ${BACKUP_DIR} kann nicht angelegt werden" 1
fi

# ── Dump erstellen ────────────────────────────────────────────────────────────
# Credentials über MYSQL_PWD übergeben (vermeidet Passwort in der Prozessliste).
# Erst in eine Temp-Datei: Ein abgebrochener Dump darf nie als Sicherung gelten
# und in der Rotation keine gute Sicherung verdrängen.
EXIT_CODE=0
MYSQL_PWD="${DB_PASS}" mysqldump \
    --user="${DB_USER}" \
    --host="${DB_HOST}" \
    --single-transaction \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -9 > "${TEMP_FILE}" || EXIT_CODE=$?

if [ "${EXIT_CODE}" -ne 0 ]; then
    fehlschlag "Backup fehlgeschlagen" "${EXIT_CODE}"
fi

gzip -t -- "${TEMP_FILE}" 2>/dev/null || fehlschlag "Dump ist kein gültiges gzip-Archiv" 1
mv -- "${TEMP_FILE}" "${DUMP_FILE}" || fehlschlag "Dump konnte nicht abgelegt werden" 1

# ── Logging ───────────────────────────────────────────────────────────────────
FILE_SIZE=$(du -sh -- "${DUMP_FILE}" | cut -f1)
log_zeile "OK  ${DUMP_FILE}  (${FILE_SIZE})"

# ── Rotation: nur die 30 neuesten Dumps behalten ─────────────────────────────
# ls -t sortiert nach Änderungszeit (neueste zuerst); tail gibt alles ab #31 aus
ls -t -- "${BACKUP_DIR}"/mpu_*.sql.gz 2>/dev/null \
    | tail -n +$((KEEP + 1)) \
    | xargs -r -d '\n' rm --
