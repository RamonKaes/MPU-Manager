#!/usr/bin/env bash
# Erstellt /dist mit allen Dateien, die per FTP auf den Server müssen.
# Sensible Dateien (config/users.php) werden bewusst ausgelassen.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DIST_DIR="$PROJECT_ROOT/dist"

echo "==> dist wird gebaut: $DIST_DIR"

rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

rsync -a \
  --exclude='vendor/' \
  --exclude='tests/' \
  --exclude='dist/' \
  --exclude='.git/' \
  --exclude='.claude/' \
  --exclude='_prompts/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='.claudeignore' \
  --exclude='.gitignore' \
  --exclude='scripts/build.sh' \
  --exclude='scripts/optimize_assets.py' \
  --exclude='scripts/env_in_htaccess.py' \
  --exclude='scripts/pruefe_zugriff.sh' \
  --exclude='.github/' \
  --exclude='phpstan.neon' \
  --exclude='*.md' \
  --exclude='config/users.php' \
  --exclude='.env' \
  --exclude='ssh.txt' \
  --exclude='*.txt' \
  --exclude='*.pdf' \
  --exclude='.env*' \
  --exclude='*.gz' \
  --exclude='*.tar' \
  --exclude='*.tgz' \
  --exclude='*.zip' \
  --exclude='*.bak' \
  --exclude='*.csv' \
  --exclude='*.json' \
  --exclude='*.log' \
  --exclude='*.swp' \
  --exclude='*~' \
  --exclude='error_log' \
  --include='/schema.sql' \
  --include='/migrations/*.sql' \
  --exclude='*.sql' \
  "$PROJECT_ROOT/" "$DIST_DIR/"

# Credentials aus .env lesen und in dist/.htaccess eintragen
ENV_FILE="$PROJECT_ROOT/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "FEHLER: .env nicht gefunden ($ENV_FILE). Benötigte Werte: siehe README, Schritt 1" >&2
  exit 1
fi
source "$ENV_FILE"
export DB_USER DB_PASS DB_NAME
export DB_HOST="${DB_HOST:-localhost}"
python3 "$SCRIPT_DIR/env_in_htaccess.py" "$DIST_DIR/.htaccess"

# Kontrolle: ohne APP_FORCE_HTTPS hängt das Secure-Flag des Session-Cookies
# davon ab, ob der Hoster X-Forwarded-Proto von einer bekannten IP schickt.
if ! grep -q '^SetEnv APP_FORCE_HTTPS 1' "$DIST_DIR/.htaccess"; then
  echo "FEHLER: APP_FORCE_HTTPS konnte in dist/.htaccess nicht aktiviert werden." >&2
  exit 1
fi

# Icon-Assets verkleinern (Subsetting + Entfernen der Legacy-Font-Formate).
# Bricht ab, wenn eine benutzte ti-*-Klasse im Icon-Set unbekannt ist.
echo ""
echo "==> Assets werden optimiert"
if ! command -v pyftsubset >/dev/null 2>&1; then
  echo "FEHLER: pyftsubset fehlt. Installation: pip install 'fonttools[woff]==4.62.1'" >&2
  exit 1
fi
python3 "$SCRIPT_DIR/optimize_assets.py" "$DIST_DIR"

echo ""
echo "ACHTUNG: config/users.php wurde ausgelassen (Passwort-Hashes)."
echo "         Bitte manuell nach $DIST_DIR/config/ kopieren."
echo ""
echo "Inhalt (ohne assets/):"
find "$DIST_DIR" -not -path '*/assets/*' -not -type d | sort | sed "s|$DIST_DIR/||"
echo ""
echo "==> Fertig."
