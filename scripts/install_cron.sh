#!/usr/bin/env bash
# =============================================================================
# install_cron.sh – Cron-Einträge für MPU-Backup und -Cleanup einrichten
# =============================================================================
# Fügt tägliche Cron-Jobs für backup.sh (02:00 Uhr) und cleanup.php (03:00 Uhr)
# hinzu, falls noch keine existieren. Idempotent – mehrfaches Ausführen ist sicher.
#
# Cron startet ohne die Umgebungsvariablen der Webanwendung. Die Jobs laden sie
# deshalb aus einer Datei: MPU_ENV_FILE, sonst .env im Projektordner. Im
# Crontab steht nur der Pfad, nie ein Passwort.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_SCRIPT="${SCRIPT_DIR}/backup.sh"
CLEANUP_SCRIPT="${SCRIPT_DIR}/cleanup.php"
ENV_FILE="${MPU_ENV_FILE:-$(dirname "${SCRIPT_DIR}")/.env}"

if [ ! -r "${ENV_FILE}" ]; then
    echo "FEHLER: Einstellungsdatei nicht lesbar: ${ENV_FILE}" >&2
    echo "Inhalt siehe README, Abschnitt Backup & Betrieb. Anderer Pfad: MPU_ENV_FILE." >&2
    exit 1
fi

# Die Log-Pfade werden hier gebraucht, weil Cron die Umleitung vor dem Job öffnet.
# Die Datei wird in einer Unterschale gelesen und gibt nur diese beiden Werte aus.
BACKUP_LOG="$(set -a; . "${ENV_FILE}"; echo "${MPU_BACKUP_LOG:-/var/log/mpu_backup.log}")"
CLEANUP_LOG="$(set -a; . "${ENV_FILE}"; echo "${MPU_CLEANUP_LOG:-/var/log/mpu_cleanup.log}")"

# Cron öffnet die Umleitung vor dem Job. Scheitert das, läuft nichts, und nichts meldet es.
for LOG in "${BACKUP_LOG}" "${CLEANUP_LOG}"; do
    if ! { : >> "${LOG}"; } 2>/dev/null; then
        echo "FEHLER: Log-Datei nicht schreibbar: ${LOG}" >&2
        echo "Eigene Pfade in ${ENV_FILE} setzen: MPU_BACKUP_LOG und MPU_CLEANUP_LOG." >&2
        exit 1
    fi
done

LADEN="set -a; . $(printf '%q' "${ENV_FILE}"); set +a"
BACKUP_CMD="0 2 * * * ${LADEN}; $(printf '%q' "${BACKUP_SCRIPT}") >> $(printf '%q' "${BACKUP_LOG}") 2>&1"
CLEANUP_CMD="0 3 * * * ${LADEN}; php $(printf '%q' "${CLEANUP_SCRIPT}") >> $(printf '%q' "${CLEANUP_LOG}") 2>&1"

# ── Backup-Skript ausführbar machen (Absicherung) ────────────────────────────
chmod +x "${BACKUP_SCRIPT}"

# ── Backup-Cron: prüfen ob Eintrag bereits existiert ─────────────────────────
if crontab -l 2>/dev/null | grep -qF "backup.sh"; then
    echo "Backup-Cron-Eintrag ist bereits vorhanden – keine Änderung."
    crontab -l 2>/dev/null | grep "backup.sh"
else
    (crontab -l 2>/dev/null || true; echo "${BACKUP_CMD}") | crontab -
    echo "Backup-Cron-Eintrag wurde erfolgreich angelegt:"
    echo "  ${BACKUP_CMD}"
fi

# ── Cleanup-Cron: prüfen ob Eintrag bereits existiert ────────────────────────
if crontab -l 2>/dev/null | grep -qF "cleanup.php"; then
    echo "Cleanup-Cron-Eintrag ist bereits vorhanden – keine Änderung."
    crontab -l 2>/dev/null | grep "cleanup.php"
else
    (crontab -l 2>/dev/null || true; echo "${CLEANUP_CMD}") | crontab -
    echo "Cleanup-Cron-Eintrag wurde erfolgreich angelegt:"
    echo "  ${CLEANUP_CMD}"
fi
