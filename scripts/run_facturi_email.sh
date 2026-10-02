#!/bin/sh
# Wrapper cron pentru facturile scanate (Linux VPS): Gmail -> coada -> OCR -> Facturi.
#
# Crontab recomandat (la fiecare 5 minute):
#   */5 * * * * /bin/sh /srv/apps/LPG-AUTO-TRANS-FLEET-APP/scripts/run_facturi_email.sh
#
# Pasi:
#   1. scripts/fetch_invoice_emails.py   ia scanarile noi din Gmail in storage/invoices/inbox
#                                        si le pune eticheta Facturi/Procesat;
#   2. scripts/process_invoice_inbox.php le citeste cu Claude si creeaza randurile in Facturi.
# Pasul 2 ruleaza si daca pasul 1 a esuat (de ex. Gmail indisponibil): coada locala
# si reincercarile OCR merg mai departe.
#
# Protectii:
#  - flock: doua rulari nu se suprapun (daca flock exista pe sistem);
#  - log cu rotatie simpla la 5 MB in storage/logs/facturi_email.log.
# Ambele scripturi citesc singure .env-ul din radacina proiectului.

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=$(dirname -- "$SCRIPT_DIR")
PHP_BIN="${PHP_BIN:-php}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
LOG_DIR="$APP_DIR/storage/logs"
LOG_FILE="$LOG_DIR/facturi_email.log"
LOCK_FILE="${TMPDIR:-/tmp}/facturi_email.lock"
MAX_LOG_BYTES=5242880

mkdir -p "$LOG_DIR"

if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$LOG_SIZE" -gt "$MAX_LOG_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
fi

run_steps() {
    cd "$APP_DIR" || exit 1
    "$PYTHON_BIN" scripts/fetch_invoice_emails.py >> "$LOG_FILE" 2>&1
    FETCH_STATUS=$?
    "$PHP_BIN" scripts/process_invoice_inbox.php >> "$LOG_FILE" 2>&1
    PROCESS_STATUS=$?
    [ "$FETCH_STATUS" -eq 0 ] && [ "$PROCESS_STATUS" -eq 0 ] && return 0
    return 1
}

if [ "${1:-}" = "--locked" ]; then
    run_steps
    exit $?
fi

if command -v flock >/dev/null 2>&1; then
    # Prin /bin/sh: scriptul nu e executabil in git (644), iar cron-ul il porneste tot cu /bin/sh.
    # Erorile lui flock merg in log: fara MTA, cron-ul arunca orice iesire.
    flock -n -E 200 "$LOCK_FILE" /bin/sh "$SCRIPT_DIR/run_facturi_email.sh" --locked >> "$LOG_FILE" 2>&1
    STATUS=$?
    if [ "$STATUS" -eq 200 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rulare sarita: alta rulare este in curs." >> "$LOG_FILE"
        exit 0
    fi
    exit "$STATUS"
fi

run_steps
exit $?
