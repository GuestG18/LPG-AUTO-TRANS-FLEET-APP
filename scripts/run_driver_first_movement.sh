#!/bin/sh
# Wrapper cron pentru notificarea WhatsApp la prima miscare a zilei (Linux VPS).
#
# Crontab recomandat (la fiecare 3 minute):
#   */3 * * * * /bin/sh /path/to/app/scripts/run_driver_first_movement.sh
#
# Protectii:
#  - flock: doua rulari nu se suprapun (daca flock exista pe sistem); scriptul PHP are
#    in plus GET_LOCK in MySQL si UNIQUE pe sofer / vehicul / zi;
#  - log cu rotatie simpla la 5 MB in storage/logs/driver_first_movement.log.
# Scriptul nu face nimic pana cand DRIVER_WA_ENABLED=1 in .env.

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=$(dirname -- "$SCRIPT_DIR")
PHP_BIN="${PHP_BIN:-php}"
LOG_DIR="$APP_DIR/storage/logs"
LOG_FILE="$LOG_DIR/driver_first_movement.log"
LOCK_FILE="${TMPDIR:-/tmp}/driver_first_movement.lock"
MAX_LOG_BYTES=5242880

mkdir -p "$LOG_DIR"

if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$LOG_SIZE" -gt "$MAX_LOG_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
fi

run_check() {
    cd "$APP_DIR" || exit 1
    "$PHP_BIN" scripts/driver_first_movement.php "$@" >> "$LOG_FILE" 2>&1
}

if [ "${1:-}" = "--locked" ]; then
    shift
    run_check "$@"
    exit $?
fi

if command -v flock >/dev/null 2>&1; then
    flock -n -E 200 "$LOCK_FILE" /bin/sh "$SCRIPT_DIR/run_driver_first_movement.sh" --locked "$@"
    STATUS=$?
    if [ "$STATUS" -eq 200 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rulare sarita: alta rulare este in curs." >> "$LOG_FILE"
        exit 0
    fi
    exit "$STATUS"
fi

run_check "$@"
exit $?
