#!/bin/sh
# Wrapper cron pentru sincronizarea telemetriei SasFleet - Revizii / schimb ulei (Linux VPS).
#
# Fara argumente aduce zilele lipsa / neinchise (recomandat, orar):
#   15 * * * * /path/to/app/scripts/run_sync_revizii.sh
# Argumentele sunt pasate scriptului PHP, de exemplu:
#   run_sync_revizii.sh --max-calls=50
#
# Protectii:
#  - flock: doua rulari nu se pot suprapune (daca flock exista pe sistem);
#  - log cu rotatie simpla la 5 MB in storage/logs/revizii_sas_sync.log;
#  - iese cu codul de iesire al PHP-ului (cron MAILTO prinde esecurile).

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=$(dirname -- "$SCRIPT_DIR")
PHP_BIN="${PHP_BIN:-php}"
LOG_DIR="$APP_DIR/storage/logs"
LOG_FILE="$LOG_DIR/revizii_sas_sync.log"
LOCK_FILE="${TMPDIR:-/tmp}/revizii_sas_sync.lock"
MAX_LOG_BYTES=5242880

mkdir -p "$LOG_DIR"

# Rotatie simpla: peste 5 MB, pastreaza o singura arhiva .1
if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$LOG_SIZE" -gt "$MAX_LOG_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
fi

run_sync() {
    cd "$APP_DIR" || exit 1
    "$PHP_BIN" scripts/sync_revizii_sas.php "$@" >> "$LOG_FILE" 2>&1
}

# Sub lock: prima invocare se re-lanseaza prin flock; ramura --locked ruleaza
# efectiv sincronizarea. Codul 200 = lock ocupat (alta rulare in curs).
if [ "${1:-}" = "--locked" ]; then
    shift
    run_sync "$@"
    exit $?
fi

if command -v flock >/dev/null 2>&1; then
    flock -n -E 200 "$LOCK_FILE" "$0" --locked "$@"
    STATUS=$?
    if [ "$STATUS" -eq 200 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rulare sarita: alta sincronizare este in curs." >> "$LOG_FILE"
        exit 0
    fi
    exit "$STATUS"
fi

# Fara flock pe sistem: ruleaza direct.
run_sync "$@"
exit $?
