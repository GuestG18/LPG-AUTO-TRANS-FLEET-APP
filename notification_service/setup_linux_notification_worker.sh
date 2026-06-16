#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PYTHON="$SCRIPT_DIR/.venv/bin/python"
WORKER="$SCRIPT_DIR/worker.py"
MARKER="# FleetNotificationWorker"

if [[ ! -x "$PYTHON" ]]; then
  PYTHON="$(command -v python3)"
fi

CRON_LINE="* * * * * cd \"$PROJECT_ROOT\" && \"$PYTHON\" \"$WORKER\" --limit 25 >/dev/null 2>&1 $MARKER"

(crontab -l 2>/dev/null | grep -v "$MARKER" || true; echo "$CRON_LINE") | crontab -

echo "Installed cron worker: FleetNotificationWorker"
