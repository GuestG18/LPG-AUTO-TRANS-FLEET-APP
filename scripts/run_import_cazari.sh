#!/bin/sh
# Wrapper cron pentru importul automat de cazari din Google Sheet (Linux VPS).
#
# Crontab recomandat (orar, la minutul 7):
#   7 * * * * /srv/apps/LPG-AUTO-TRANS-FLEET-APP/scripts/run_import_cazari.sh
#
# Protectii:
#  - suprapunerea cu alta rulare sau cu butonul din pagina e blocata in baza de
#    date (GET_LOCK in CazariSheetImportService), nu e nevoie de flock;
#  - log cu rotatie simpla la 5 MB in storage/logs/cazari_import.log;
#  - iese cu codul de iesire al PHP-ului (cron MAILTO prinde esecurile).

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=$(dirname -- "$SCRIPT_DIR")
PHP_BIN="${PHP_BIN:-php}"
LOG_DIR="$APP_DIR/storage/logs"
LOG_FILE="$LOG_DIR/cazari_import.log"
MAX_LOG_BYTES=5242880

mkdir -p "$LOG_DIR"

# Rotatie simpla: peste 5 MB, pastreaza o singura arhiva .1
if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$LOG_SIZE" -gt "$MAX_LOG_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
fi

cd "$APP_DIR" || exit 1
"$PHP_BIN" scripts/import_cazari_sheet.php "$@" >> "$LOG_FILE" 2>&1
exit $?
