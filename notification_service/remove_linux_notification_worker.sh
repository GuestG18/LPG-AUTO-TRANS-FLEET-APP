#!/usr/bin/env bash
set -euo pipefail

MARKER="# FleetNotificationWorker"

if crontab -l >/tmp/fleet_notification_cron.$$ 2>/dev/null; then
  grep -v "$MARKER" /tmp/fleet_notification_cron.$$ | crontab -
  rm -f /tmp/fleet_notification_cron.$$
  echo "Removed cron worker: FleetNotificationWorker"
else
  rm -f /tmp/fleet_notification_cron.$$
  echo "No crontab found."
fi
