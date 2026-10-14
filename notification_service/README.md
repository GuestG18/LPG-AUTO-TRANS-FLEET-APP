# Notification Worker

The web app manages rules and shows status only. This worker scans the rules, queues due notification email, sends queued jobs in the background, and updates `notification_deliveries`.

Set `APP_URL` in `.env` on the VPS so email links point to the public site.

## SMS (SMSAlert)

Rules with channel `SMS` or `Email + SMS` are also sent as SMS through [SMSAlert](https://apidocs.smsalert.mobi) (`POST /v2/message/send`, Basic auth with username + API key). Each SMS is a separate row in `notification_deliveries` (`channel = 'sms'`, number in `recipient_phone`).

- `.env`: `SMSALERT_ENABLED=true`, `SMSALERT_USERNAME`, `SMSALERT_API_KEY` (see `.env.example`). While SMS is disabled, no SMS deliveries are queued.
- Recipients: the rule's users (`utilizatori.telefon`); with "Trimite SMS si soferului", also the drivers assigned to the vehicle (or the driver themself for driver documents, leave and birthdays), using `soferi.telefon`. Numbers are normalized to `+40...`.
- The API allows 1 call every 2 seconds: the worker paces calls and sends at most `SMSALERT_MAX_SEND_PER_RUN` per run. The rest stays pending until the next run, and so does everything after an HTTP 429.
- `SMSALERT_FORCE_TO`: test mode, where every SMS goes only to this number.
- Approval requests: the email still comes from `approval_flow.py`; the SMS (an alert only, no approve/reject link) comes from the worker.

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
