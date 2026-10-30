#!/bin/sh
# Wrapper cron pentru importul eToll (Linux VPS): sesiuni inchise -> Facturi (Toll) -> cursa.
#
# Crontab recomandat (la fiecare 30 de minute):
#   */30 * * * * /bin/sh /srv/apps/LPG-AUTO-TRANS-FLEET-APP/scripts/run_import_etoll.sh
#
# Protectii:
#  - flock: doua rulari nu se suprapun (daca flock exista pe sistem);
#  - log cu rotatie simpla la 5 MB in storage/logs/etoll_import.log.

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=$(dirname -- "$SCRIPT_DIR")
PHP_BIN="${PHP_BIN:-php}"
LOG_DIR="$APP_DIR/storage/logs"
LOG_FILE="$LOG_DIR/etoll_import.log"
LOCK_FILE="${TMPDIR:-/tmp}/etoll_import.lock"
MAX_LOG_BYTES=5242880

mkdir -p "$LOG_DIR"

if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$LOG_SIZE" -gt "$MAX_LOG_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
fi

cd "$APP_DIR" || exit 1

if command -v flock >/dev/null 2>&1; then
    flock -n -E 200 "$LOCK_FILE" "$PHP_BIN" scripts/import_etoll.php >> "$LOG_FILE" 2>&1
    STATUS=$?
    if [ "$STATUS" -eq 200 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rulare sarita: alta rulare este in curs." >> "$LOG_FILE"
        exit 0
    fi
    exit "$STATUS"
fi

"$PHP_BIN" scripts/import_etoll.php >> "$LOG_FILE" 2>&1
exit $?
