# Notification Worker

The web app manages rules and shows status only. This worker scans the rules, queues due notification email, sends queued jobs in the background, and updates `notification_deliveries`.

Set `APP_URL` in `.env` on the VPS so email links point to the public site.

## Install dependencies

```powershell
cd C:\laragon\www\aplicatie_fleet
python -m venv notification_service\.venv
notification_service\.venv\Scripts\python.exe -m pip install -r notification_service\requirements.txt
```

Linux VPS:

```bash
cd /path/to/aplicatie_fleet
python3 -m venv notification_service/.venv
notification_service/.venv/bin/python -m pip install -r notification_service/requirements.txt
```

## Test without sending

```powershell
notification_service\.venv\Scripts\python.exe notification_service\worker.py --dry-run
```

## Run once, scan and send

```powershell
notification_service\.venv\Scripts\python.exe notification_service\worker.py --limit 25
```

Useful modes:

```text
--enqueue-only  scan rules and queue due jobs without sending
--send-only     send existing queue rows without scanning rules
--dry-run       only report ready pending queue rows
```

## Install Windows scheduled task

```powershell
powershell -ExecutionPolicy Bypass -File notification_service\setup_windows_notification_worker.ps1
```

## Install Linux cron worker

```bash
bash notification_service/setup_linux_notification_worker.sh
```

## Remove Windows scheduled task

```powershell
powershell -ExecutionPolicy Bypass -File notification_service\remove_windows_notification_worker.ps1
```

## Remove Linux cron worker

```bash
bash notification_service/remove_linux_notification_worker.sh
```
