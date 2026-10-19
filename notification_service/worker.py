#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import json
import os
import re
import smtplib
import ssl
import sys
import time
import unicodedata
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timedelta
from email.message import EmailMessage
from email.utils import formataddr, make_msgid
from pathlib import Path
from typing import Any
from urllib.parse import urlencode

try:
    import mysql.connector
except ImportError:
    sys.stderr.write(
        "Missing Python dependency: mysql-connector-python. "
        "Run: python -m pip install -r notification_service/requirements.txt\n"
    )
    raise

try:
    from dotenv import load_dotenv
except ImportError:
    def load_dotenv(path: Path) -> None:
        return None


PROJECT_ROOT = Path(__file__).resolve().parents[1]
load_dotenv(PROJECT_ROOT / ".env")


@dataclass(frozen=True)
class MailConfig:
    host: str
    port: int
    username: str
    password: str
    encryption: str
    from_address: str
    from_name: str
    return_path: str
    timeout: int
    retry_attempts: int
    retry_delay_ms: int
    app_name: str


def env_value(names: list[str], default: str = "") -> str:
    for name in names:
        value = os.getenv(name)
        if value is not None and value != "":
            return value
    return default


def int_env(names: list[str], default: int) -> int:
    try:
        return int(env_value(names, str(default)))
    except ValueError:
        return default


def connect_db():
    return mysql.connector.connect(
        host=env_value(["DB_HOST"], "127.0.0.1"),
        port=int_env(["DB_PORT"], 3306),
        database=env_value(["DB_NAME"]),
        user=env_value(["DB_USER"], "root"),
        password=env_value(["DB_PASS"], ""),
        charset=env_value(["DB_CHARSET"], "utf8mb4"),
        use_unicode=True,
        autocommit=False,
    )


def mail_config() -> MailConfig:
    from_address = env_value(["MAIL_FROM_ADDRESS"], env_value(["MAIL_USERNAME", "SMTP_USERNAME"]))
    return MailConfig(
        host=env_value(["MAIL_HOST", "SMTP_HOST"]),
        port=int_env(["MAIL_PORT", "SMTP_PORT"], 587),
        username=env_value(["MAIL_USERNAME", "SMTP_USERNAME"]),
        password=env_value(["MAIL_PASSWORD", "SMTP_PASSWORD"]),
        encryption=env_value(["MAIL_ENCRYPTION", "SMTP_ENCRYPTION"], "tls").lower(),
        from_address=from_address,
        from_name=env_value(["MAIL_FROM_NAME"], "Fleet Management MVP"),
        return_path=env_value(["MAIL_RETURN_PATH"], from_address),
        timeout=max(10, int_env(["MAIL_TIMEOUT"], 45)),
        retry_attempts=max(1, int_env(["MAIL_RETRY_ATTEMPTS"], 2)),
        retry_delay_ms=max(0, int_env(["MAIL_RETRY_DELAY_MS"], 400)),
        app_name=env_value(["APP_NAME"], "Fleet Management MVP"),
    )


@dataclass(frozen=True)
class SmsConfig:
    enabled: bool
    username: str
    api_key: str
    api_url: str
    modem_id: str
    country_code: str
    timeout: int
    max_send_per_run: int
    force_to: str

    @property
    def ready(self) -> bool:
        return self.enabled and bool(self.username) and bool(self.api_key)


def sms_config() -> SmsConfig:
    return SmsConfig(
        enabled=env_value(["SMSALERT_ENABLED"], "false").strip().lower() in {"1", "true", "yes", "on", "da"},
        username=env_value(["SMSALERT_USERNAME"]).strip(),
        api_key=env_value(["SMSALERT_API_KEY"]).strip(),
        api_url=env_value(["SMSALERT_API_URL"], "https://smsalert.mobi/api").rstrip("/"),
        modem_id=env_value(["SMSALERT_MODEM_ID"]).strip(),
        country_code=re.sub(r"\D", "", env_value(["SMSALERT_DEFAULT_COUNTRY_CODE"], "40")) or "40",
        timeout=max(5, int_env(["SMSALERT_TIMEOUT"], 20)),
        max_send_per_run=max(1, int_env(["SMSALERT_MAX_SEND_PER_RUN"], 25)),
        force_to=env_value(["SMSALERT_FORCE_TO"]).strip(),
    )


# Canalele unei reguli (coloana notification_rules.channel).
RULE_CHANNELS = {
    "email": {"email"},
    "sms": {"sms"},
    "email_sms": {"email", "sms"},
}

# API-ul SMSAlert permite 1 apel la 2 secunde (burst 5); pastram o marja.
SMS_MIN_INTERVAL_SECONDS = 2.1
SMS_MAX_LENGTH = 300


class SmsRateLimited(RuntimeError):
    """SMSAlert a raspuns 429: restul SMS-urilor raman pentru rularea urmatoare."""


def normalize_phone(raw: Any, country_code: str = "40") -> str | None:
    """Acelasi algoritm ca NotificationRuleModel::normalizePhone(): 07xx -> +407xx, 00 -> +."""
    text = str(raw or "").strip()
    if not text:
        return None
    has_plus = text.startswith("+")
    digits = re.sub(r"\D", "", text)
    if not digits:
        return None
    if not has_plus:
        if digits.startswith("00"):
            digits = digits[2:]
        elif digits.startswith("0"):
            digits = country_code + digits[1:]
        elif len(digits) == 9 and digits[0] == "7":
            digits = country_code + digits
    if len(digits) < 10 or len(digits) > 15:
        return None
    # Romania: 40 + 9 cifre; un numar cu o cifra lipsa nu trebuie sa plece.
    if digits.startswith("40") and len(digits) != 11:
        return None
    return "+" + digits


def sms_plain_text(text: str) -> str:
    """Fara diacritice: un SMS GSM are 160 de caractere, unul Unicode doar 70."""
    folded = unicodedata.normalize("NFKD", text)
    folded = "".join(ch for ch in folded if not unicodedata.combining(ch))
    folded = re.sub(r"\s+", " ", folded).strip()
    if len(folded) > SMS_MAX_LENGTH:
        folded = folded[: SMS_MAX_LENGTH - 3].rstrip() + "..."
    return folded


def smsalert_send(config: SmsConfig, phone: str, text: str) -> str:
    """POST /v2/message/send (https://apidocs.smsalert.mobi). Basic auth: user + cheie API."""
    payload: dict[str, Any] = {
        "phoneNumber": phone,
        "message": text,
        "cleanupUtf8": True,
        "autoShortUrl": False,
    }
    if config.modem_id:
        payload["modemId"] = config.modem_id

    token = base64.b64encode(f"{config.username}:{config.api_key}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(
        f"{config.api_url}/v2/message/send",
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        method="POST",
        headers={
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            # Cloudflare-ul SMSAlert respinge User-Agent-ul implicit Python-urllib (Error 1010 / 403).
            "User-Agent": "FleetNotificationWorker/1.0",
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=config.timeout) as response:
            raw = response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace") if exc.fp else ""
        if exc.code == 429:
            raise SmsRateLimited("SMSAlert: limita de apeluri atinsa (HTTP 429).") from exc
        if exc.code == 401:
            raise RuntimeError("SMSAlert: autentificare respinsa (verifica SMSALERT_USERNAME / SMSALERT_API_KEY).") from exc
        raise RuntimeError(f"SMSAlert HTTP {exc.code}: {smsalert_error_text(body)}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"SMSAlert indisponibil: {exc.reason}") from exc

    try:
        data = json.loads(raw or "{}")
    except ValueError:
        raise RuntimeError(f"SMSAlert: raspuns invalid: {raw[:300]}")

    if not data.get("status"):
        raise RuntimeError(f"SMSAlert a refuzat mesajul: {smsalert_error_text(raw)}")

    return f"SMSAlert id={data.get('id') or '-'} sms={data.get('smsCount') or 1}"


def smsalert_error_text(body: str) -> str:
    try:
        data = json.loads(body or "{}")
    except ValueError:
        return body[:300] or "fara detalii"
    message = str(data.get("message") or "fara detalii")
    if data.get("errorCode") is not None:
        message += f" (cod {data.get('errorCode')})"
    fields = data.get("errorFields")
    if fields:
        message += " " + json.dumps(fields, ensure_ascii=False)[:300]
    return message


def ensure_sms_schema(conn) -> None:
    """Idempotent, ca NotificationRuleModel::ensureSmsDeliverySchema(): workerul poate rula
    inaintea primei deschideri a paginii Notificari dupa deploy."""
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'notification_deliveries'
        """
    )
    columns = {str(row["COLUMN_NAME"]): row for row in cursor.fetchall()}
    if not columns:
        cursor.close()
        return
    channel_type = str(columns.get("channel", {}).get("COLUMN_TYPE") or "").lower()
    if "channel" in columns and "'sms'" not in channel_type:
        cursor.execute("ALTER TABLE notification_deliveries MODIFY channel ENUM('email','sms') NOT NULL DEFAULT 'email'")
    if str(columns.get("recipient_email", {}).get("IS_NULLABLE") or "") == "NO":
        cursor.execute("ALTER TABLE notification_deliveries MODIFY recipient_email VARCHAR(190) NULL")
    if "recipient_phone" not in columns:
        cursor.execute(
            "ALTER TABLE notification_deliveries ADD COLUMN recipient_phone VARCHAR(32) NULL AFTER recipient_email, "
            "ADD KEY idx_notification_deliveries_phone (recipient_phone, created_at)"
        )
    cursor.close()
    conn.commit()


def count_pending(conn) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        SELECT COUNT(*)
        FROM notification_queue
        WHERE status = 'pending'
          AND scheduled_for <= NOW()
        """
    )
    value = cursor.fetchone()[0]
    cursor.close()
    return int(value)


def app_url() -> str:
    return env_value(["APP_URL"], "http://127.0.0.1:8000").rstrip("/")


def absolute_app_url(path: str, query: dict[str, Any]) -> str:
    return f"{app_url()}/{path.lstrip('/')}?{urlencode(query)}"


def fetch_enabled_rules(conn) -> list[dict[str, Any]]:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT *
        FROM notification_rules
        WHERE enabled = 1
        ORDER BY id ASC
        """
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_matches_for_rule(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    event_type = str(rule.get("event_type") or "")
    if event_type == "vehicle_document_expiry":
        return fetch_vehicle_document_matches(conn, rule)
    if event_type == "driver_document_expiry":
        return fetch_driver_document_matches(conn, rule)
    if event_type == "leave_starts_soon":
        return fetch_leave_start_matches(conn, rule)
    if event_type == "equipment_expiry":
        return fetch_equipment_date_matches(conn, rule, "data_expirarii", "Expirare dotare")
    if event_type == "equipment_inspection":
        return fetch_equipment_date_matches(conn, rule, "data_urmatoarei_inspectii", "Inspectie dotare")
    if event_type == "tire_km_limit":
        return fetch_tire_km_matches(conn, rule)
    if event_type == "tire_tread_depth":
        return fetch_tire_tread_matches(conn, rule)
    if event_type == "tire_dot_expiry":
        return fetch_tire_dot_matches(conn, rule)
    if event_type == "driver_birthday":
        return fetch_driver_birthday_matches(conn, rule)
    if event_type in REVIZIE_EVENT_CATEGORY:
        return fetch_revizie_matches(rule)
    if event_type == APPROVAL_EVENT:
        # Emailul de aprobare il trimite approval_flow.py; aici doar SMS-ul.
        return fetch_approval_sms_matches(conn, rule)
    return []


APPROVAL_EVENT = "inactive_approval_pending"


def rule_metadata(rule: dict[str, Any]) -> dict[str, Any]:
    raw: Any = rule.get("metadata_json") or "{}"
    if isinstance(raw, (bytes, bytearray)):
        raw = raw.decode("utf-8", "replace")
    try:
        data = json.loads(raw) if isinstance(raw, str) else raw
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def rule_channels(rule: dict[str, Any]) -> set[str]:
    channels = set(RULE_CHANNELS.get(str(rule.get("channel") or "email"), {"email"}))
    if str(rule.get("event_type") or "") == APPROVAL_EVENT:
        channels.discard("email")
    return channels


def fetch_approval_sms_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    if "sms" not in rule_channels(rule):
        return []
    metadata = rule_metadata(rule)
    resource_types = [str(v) for v in metadata.get("approval_resource_types") or []]
    reasons = [str(v) for v in metadata.get("approval_reasons") or []]
    repeat_days = max(1, int(metadata.get("repeat_days") or 1))

    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            a.id,
            a.resource_type,
            a.resource_label,
            a.inactive_reason,
            a.inactive_reason_label,
            a.updated_at,
            u.nume AS requested_by_name
        FROM inactive_resource_approvals a
        LEFT JOIN utilizatori u ON u.id = a.requested_by_user_id
        WHERE a.status = 'pending'
        ORDER BY a.requested_at ASC
        LIMIT 200
        """
    )
    rows = list(cursor.fetchall())
    cursor.close()

    matches: list[dict[str, Any]] = []
    epoch_day = (datetime.now().date() - datetime(1970, 1, 1).date()).days
    for row in rows:
        if resource_types and str(row.get("resource_type") or "") not in resource_types:
            continue
        if reasons and str(row.get("inactive_reason") or "") not in reasons:
            continue
        updated = row.get("updated_at")
        updated_key = updated.strftime("%Y%m%d%H%M%S") if hasattr(updated, "strftime") else str(updated or "")
        matches.append({
            "entity_id": int(row["id"]),
            "entity_type": "approval",
            "tip_document": str(row.get("inactive_reason_label") or row.get("inactive_reason") or "Cerere aprobare"),
            "owner_id": 0,
            "owner_name": str(row.get("resource_label") or ""),
            "owner_details": str(row.get("requested_by_name") or ""),
            "notification_title": "Cerere aprobare in asteptare",
            # Cererea repusa in asteptare = SMS nou; reamintirea urmeaza "Reaminteste la (zile)".
            "context_suffix": updated_key,
            "repeat_bucket": f"r{epoch_day // repeat_days}",
        })
    return matches


# Eveniment de notificare -> categoria (tab-ul) din Revizii.
REVIZIE_EVENT_CATEGORY = {
    "revizie_schimb_ulei": "schimb_ulei",
    "revizie_placute_frana": "placute_frana",
    "revizie_anvelope": "anvelope",
}

REVIZIE_STATUS_LABELS = {
    "necesita": "Necesita revizie",
    "se_apropie": "Se apropie revizia",
}

_revizie_alerts_cache: list[dict[str, Any]] | None = None


def php_binary() -> str:
    configured = env_value(["PHP_BIN"])
    if configured:
        return configured
    import shutil

    found = shutil.which("php")
    if found:
        return found
    # Task Scheduler pe Windows nu are de obicei PHP-ul Laragon in PATH.
    laragon = sorted(Path("C:/laragon/bin/php").glob("*/php.exe"))
    if laragon:
        return str(laragon[-1])
    return "php"


def load_revizie_alerts() -> list[dict[str, Any]]:
    """Alertele Revizii (Se apropie / Necesita), calculate o singura data per rulare.

    Km ramasi se calculeaza doar in PHP (ReviziiService), deci le cerem de la
    scripts/revizii_alerts.php. Pragul e cel din Revizii, per componenta.
    """
    global _revizie_alerts_cache
    if _revizie_alerts_cache is not None:
        return _revizie_alerts_cache

    import subprocess

    script = PROJECT_ROOT / "scripts" / "revizii_alerts.php"
    result = subprocess.run(
        [php_binary(), str(script)],
        cwd=str(PROJECT_ROOT),
        capture_output=True,
        text=True,
        encoding="utf-8",
        timeout=180,
    )
    if result.returncode != 0:
        raise RuntimeError("revizii_alerts.php a esuat: " + (result.stderr or result.stdout).strip()[:500])
    payload = json.loads(result.stdout.strip() or "{}")
    _revizie_alerts_cache = list(payload.get("alerts") or [])
    return _revizie_alerts_cache


def fetch_revizie_matches(rule: dict[str, Any]) -> list[dict[str, Any]]:
    category = REVIZIE_EVENT_CATEGORY[str(rule.get("event_type") or "")]
    # Optional: doar componentele depasite (document_type = 'necesita').
    only_status = str(rule.get("document_type") or "").strip()
    try:
        alerts = load_revizie_alerts()
    except Exception as exc:
        # O eroare la Revizii nu trebuie sa opreasca celelalte reguli.
        sys.stderr.write(f"Revizii: {normalize_error(exc)}\n")
        return []
    rows: list[dict[str, Any]] = []
    for alert in alerts:
        if alert.get("categorie") != category:
            continue
        status = str(alert.get("status") or "")
        if only_status and status != only_status:
            continue
        km_ramasi = float(alert.get("km_ramasi") or 0)
        details = f"{alert.get('marca') or ''} / {alert.get('vehicul_tip') or ''}".strip(" /")
        rows.append({
            "entity_id": int(alert["plan_id"]),
            "entity_type": "revizie",
            "tip_document": str(alert.get("componenta") or alert.get("categorie_label") or "Revizie"),
            "owner_id": int(alert.get("vehicle_id") or 0),
            "owner_name": str(alert.get("nr") or ""),
            "owner_details": details,
            "notification_title": REVIZIE_STATUS_LABELS.get(status, "Revizie"),
            "km_remaining": km_ramasi,
            "revizie_status": status,
            "revizie_categorie": category,
            "revizie_interval_km": int(alert.get("interval_km") or 0),
            "revizie_prag_km": int(alert.get("prag_km") or 0),
            "revizie_complet": bool(alert.get("complet", True)),
            # Ciclu nou (T0 nou dupa revizie) sau trecere Se apropie -> Necesita = notificare noua.
            "context_suffix": f"{alert.get('baseline_at') or ''}:{status}",
        })
    return rows[:200]


def fetch_vehicle_document_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    days_before = max(0, int(rule.get("days_before") or 30))
    where = [
        "d.data_expirare IS NOT NULL",
        "d.data_expirare <= DATE_ADD(CURDATE(), INTERVAL %s DAY)",
        "v.status = 'activ'",
    ]
    params: list[Any] = [days_before]
    document_type = str(rule.get("document_type") or "").strip()
    if document_type:
        where.append("d.tip_document = %s")
        params.append(document_type)

    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        f"""
        SELECT
            d.id AS entity_id,
            d.id AS document_id,
            'vehicle' AS entity_type,
            d.tip_document,
            d.numar_document,
            d.data_expirare,
            DATEDIFF(d.data_expirare, CURDATE()) AS days_left,
            v.id AS owner_id,
            v.nr_inmatriculare AS owner_name,
            CONCAT(v.marca, ' ', v.model) AS owner_details
        FROM documente d
        INNER JOIN vehicule v ON v.id = d.vehicle_id
        WHERE {" AND ".join(where)}
        ORDER BY d.data_expirare ASC, d.id ASC
        LIMIT 200
        """,
        tuple(params),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_driver_document_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    days_before = max(0, int(rule.get("days_before") or 30))
    where = [
        "d.data_expirare IS NOT NULL",
        "d.data_expirare <= DATE_ADD(CURDATE(), INTERVAL %s DAY)",
        "s.status = 'activ'",
    ]
    params: list[Any] = [days_before]
    document_type = str(rule.get("document_type") or "").strip()
    if document_type:
        where.append("d.tip_document = %s")
        params.append(document_type)

    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        f"""
        SELECT
            d.id AS entity_id,
            d.id AS document_id,
            'driver' AS entity_type,
            d.tip_document,
            d.numar_document,
            d.data_expirare,
            DATEDIFF(d.data_expirare, CURDATE()) AS days_left,
            s.id AS owner_id,
            s.nume AS owner_name,
            COALESCE(v.nr_inmatriculare, '') AS owner_details
        FROM documente_soferi d
        INNER JOIN soferi s ON s.id = d.driver_id
        LEFT JOIN vehicule v ON v.id = s.vehicle_id
        WHERE {" AND ".join(where)}
        ORDER BY d.data_expirare ASC, d.id ASC
        LIMIT 200
        """,
        tuple(params),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_leave_start_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    days_before = max(0, int(rule.get("days_before") or 7))
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            c.id AS entity_id,
            'leave' AS entity_type,
            c.tip_concediu AS tip_document,
            c.data_inceput AS data_expirare,
            DATEDIFF(c.data_inceput, CURDATE()) AS days_left,
            s.id AS owner_id,
            s.nume AS owner_name,
            COALESCE(v.nr_inmatriculare, '') AS owner_details,
            CONCAT('Concediu incepe in ', DATEDIFF(c.data_inceput, CURDATE()), ' zile') AS notification_title
        FROM concedii c
        INNER JOIN soferi s ON s.id = c.driver_id
        LEFT JOIN vehicule v ON v.id = s.vehicle_id
        WHERE c.data_inceput BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL %s DAY)
          AND c.status IN ('aprobat', 'in_asteptare_aprobare', 'in_asteptare')
        ORDER BY c.data_inceput ASC, c.id ASC
        LIMIT 200
        """,
        (days_before,),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_driver_birthday_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    days_before = max(0, int(rule.get("days_before") or 7))
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            s.id AS entity_id,
            'driver' AS entity_type,
            'Zi de nastere' AS tip_document,
            CASE
                WHEN DATE_FORMAT(s.data_nasterii, CONCAT(YEAR(CURDATE()), '-%m-%d')) < CURDATE()
                THEN DATE_FORMAT(s.data_nasterii, CONCAT(YEAR(CURDATE()) + 1, '-%m-%d'))
                ELSE DATE_FORMAT(s.data_nasterii, CONCAT(YEAR(CURDATE()), '-%m-%d'))
            END AS data_expirare,
            DATEDIFF(
                CASE
                    WHEN DATE_FORMAT(s.data_nasterii, CONCAT(YEAR(CURDATE()), '-%m-%d')) < CURDATE()
                    THEN DATE_FORMAT(s.data_nasterii, CONCAT(YEAR(CURDATE()) + 1, '-%m-%d'))
                    ELSE DATE_FORMAT(s.data_nasterii, CONCAT(YEAR(CURDATE()), '-%m-%d'))
                END,
                CURDATE()
            ) AS days_left,
            s.id AS owner_id,
            s.nume AS owner_name,
            COALESCE(v.nr_inmatriculare, '') AS owner_details,
            'Zi de nastere sofer' AS notification_title
        FROM soferi s
        LEFT JOIN vehicule v ON v.id = s.vehicle_id
        WHERE s.status = 'activ'
          AND s.data_nasterii IS NOT NULL
        HAVING days_left BETWEEN 0 AND %s
        ORDER BY days_left ASC, s.nume ASC
        LIMIT 200
        """,
        (days_before,),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_equipment_date_matches(conn, rule: dict[str, Any], date_column: str, title: str) -> list[dict[str, Any]]:
    days_before = max(0, int(rule.get("days_before") or 30))
    document_type = str(rule.get("document_type") or "").strip()
    where = [
        f"i.{date_column} IS NOT NULL",
        f"i.{date_column} <= DATE_ADD(CURDATE(), INTERVAL %s DAY)",
        "v.status = 'activ'",
    ]
    params: list[Any] = [days_before]
    if document_type:
        where.append("c.nume = %s")
        params.append(document_type)

    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        f"""
        SELECT
            i.id AS entity_id,
            'equipment' AS entity_type,
            c.nume AS tip_document,
            i.{date_column} AS data_expirare,
            DATEDIFF(i.{date_column}, CURDATE()) AS days_left,
            v.id AS owner_id,
            v.nr_inmatriculare AS owner_name,
            CONCAT(v.marca, ' ', v.model) AS owner_details,
            %s AS notification_title
        FROM inventar_dotari_vehicule i
        INNER JOIN inventar_dotari_catalog c ON c.id = i.catalog_id
        INNER JOIN vehicule v ON v.id = i.vehicle_id
        WHERE {" AND ".join(where)}
        ORDER BY i.{date_column} ASC, i.id ASC
        LIMIT 200
        """,
        tuple([title] + params),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_tire_km_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    threshold_km = max(0, int(rule.get("threshold_km") or 0))
    if threshold_km <= 0:
        return []

    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            t.id AS entity_id,
            'tire' AS entity_type,
            CONCAT('Anvelopa ', COALESCE(t.brand, ''), ' ', COALESCE(t.model, '')) AS tip_document,
            NULL AS data_expirare,
            NULL AS days_left,
            v.id AS owner_id,
            v.nr_inmatriculare AS owner_name,
            CONCAT(v.marca, ' ', v.model, ' / ', p.position_code) AS owner_details,
            GREATEST(0, COALESCE(t.estimated_life_km, 0) - (COALESCE(t.km_initial, 0) + GREATEST(0, COALESCE(v.km_bord, 0) - COALESCE(a.km_start, 0)))) AS km_remaining,
            'Limita kilometri anvelopa' AS notification_title
        FROM anvelope t
        INNER JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
        INNER JOIN vehicule v ON v.id = a.vehicle_id
        LEFT JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
        WHERE t.estimated_life_km IS NOT NULL
          AND t.estimated_life_km > 0
          AND v.status = 'activ'
        HAVING km_remaining <= %s
        ORDER BY km_remaining ASC, t.id ASC
        LIMIT 200
        """,
        (threshold_km,),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def fetch_tire_tread_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    threshold = float(rule.get("threshold_tread_depth") or 0)
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            t.id AS entity_id,
            'tire' AS entity_type,
            CONCAT('Anvelopa ', COALESCE(t.brand, ''), ' ', COALESCE(t.model, '')) AS tip_document,
            NULL AS data_expirare,
            NULL AS days_left,
            v.id AS owner_id,
            v.nr_inmatriculare AS owner_name,
            CONCAT(v.marca, ' ', v.model, ' / ', p.position_code) AS owner_details,
            t.tread_depth_mm,
            COALESCE(NULLIF(%s, 0), t.min_tread_depth_mm) AS threshold_depth,
            'Adancime profil anvelopa' AS notification_title
        FROM anvelope t
        INNER JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
        INNER JOIN vehicule v ON v.id = a.vehicle_id
        LEFT JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
        WHERE t.tread_depth_mm IS NOT NULL
          AND v.status = 'activ'
        HAVING t.tread_depth_mm <= threshold_depth
        ORDER BY t.tread_depth_mm ASC, t.id ASC
        LIMIT 200
        """,
        (threshold,),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def dot_manufacture_date(dot_code: str | None) -> datetime | None:
    dot_code = str(dot_code or "").strip()
    if len(dot_code) < 4 or not dot_code[-4:].isdigit():
        return None
    week = int(dot_code[-4:-2])
    year = int(dot_code[-2:])
    if week < 1 or week > 53:
        return None
    year += 2000 if year < 80 else 1900
    return datetime.strptime(f"{year}-W{week:02d}-1", "%G-W%V-%u")


def fetch_tire_dot_matches(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    days_before = max(0, int(rule.get("days_before") or 90))
    today = datetime.now()
    limit = today + timedelta(days=days_before)
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            t.id AS entity_id,
            'tire' AS entity_type,
            CONCAT('Anvelopa ', COALESCE(t.brand, ''), ' ', COALESCE(t.model, '')) AS tip_document,
            v.id AS owner_id,
            v.nr_inmatriculare AS owner_name,
            CONCAT(v.marca, ' ', v.model, ' / ', p.position_code) AS owner_details,
            t.dot_code,
            'DOT anvelopa expira' AS notification_title
        FROM anvelope t
        INNER JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
        INNER JOIN vehicule v ON v.id = a.vehicle_id
        LEFT JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
        WHERE t.dot_code IS NOT NULL
          AND t.dot_code <> ''
          AND v.status = 'activ'
        ORDER BY t.id ASC
        LIMIT 500
        """
    )
    rows: list[dict[str, Any]] = []
    for row in cursor.fetchall():
        manufacture = dot_manufacture_date(str(row.get("dot_code") or ""))
        if manufacture is None:
            continue
        expiry = manufacture + timedelta(days=365 * 5)
        if expiry <= limit:
            row["data_expirare"] = expiry.date()
            row["days_left"] = (expiry.date() - today.date()).days
            rows.append(row)
    cursor.close()
    return rows[:200]


def resolve_recipients(conn, rule: dict[str, Any]) -> list[dict[str, Any]]:
    """Utilizatorii regulii, cu email si/sau telefon; canalul se filtreaza la trimitere."""
    mode = str(rule.get("recipient_mode") or "admins")
    if mode == "drivers":
        # Doar soferul: destinatarii vin din driver_recipients().
        return []
    cursor = conn.cursor(dictionary=True)
    if mode == "specific_users":
        cursor.execute(
            """
            SELECT u.id, u.nume, u.email, u.telefon, u.rol
            FROM notification_rule_recipients rr
            INNER JOIN utilizatori u ON u.id = rr.user_id
            WHERE rr.rule_id = %s
              AND u.status = 'activ'
            ORDER BY u.nume ASC
            """,
            (int(rule["id"]),),
        )
    else:
        cursor.execute(
            """
            SELECT id, nume, email, telefon, rol
            FROM utilizatori
            WHERE status = 'activ'
              AND rol = 'admin'
            ORDER BY nume ASC
            """
        )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


# Entitatile al caror owner_id este chiar soferul (restul au owner_id = vehiculul).
DRIVER_OWNED_ENTITIES = {"driver", "leave"}


def driver_recipients(conn, match: dict[str, Any], cache: dict[tuple[str, int], list[dict[str, Any]]]) -> list[dict[str, Any]]:
    """Soferul notificarii: cel al documentului/concediului, altfel soferii alocati vehiculului."""
    entity_type = str(match.get("entity_type") or "")
    owner_id = int(match.get("owner_id") or 0)
    if owner_id <= 0 or entity_type == "approval":
        return []
    key = ("driver" if entity_type in DRIVER_OWNED_ENTITIES else "vehicle", owner_id)
    if key in cache:
        return cache[key]

    column = "id" if key[0] == "driver" else "vehicle_id"
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        f"""
        SELECT id AS driver_id, nume, telefon
        FROM soferi
        WHERE {column} = %s
          AND status = 'activ'
          AND telefon IS NOT NULL
          AND telefon <> ''
        ORDER BY nume ASC
        """,
        (owner_id,),
    )
    cache[key] = [dict(row, email="", is_driver=True) for row in cursor.fetchall()]
    cursor.close()
    return cache[key]


def rule_driver_ids(rule: dict[str, Any]) -> list[int]:
    """Soferii alesi in regula (metadata_json.driver_ids); gol = soferii alocati vehiculului."""
    ids: list[int] = []
    for value in rule_metadata(rule).get("driver_ids") or []:
        try:
            driver_id = int(value)
        except (TypeError, ValueError):
            continue
        if driver_id > 0 and driver_id not in ids:
            ids.append(driver_id)
    return ids


def picked_drivers(conn, driver_ids: list[int], cache: dict[tuple[str, int], list[dict[str, Any]]]) -> list[dict[str, Any]]:
    """Soferii alesi in regula, activi si cu telefon (aceeasi forma ca driver_recipients)."""
    drivers: list[dict[str, Any]] = []
    missing = [driver_id for driver_id in driver_ids if ("picked", driver_id) not in cache]
    if missing:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            f"""
            SELECT id AS driver_id, nume, telefon
            FROM soferi
            WHERE id IN ({", ".join(["%s"] * len(missing))})
              AND status = 'activ'
              AND telefon IS NOT NULL
              AND telefon <> ''
            """,
            tuple(missing),
        )
        found = {int(row["driver_id"]): dict(row, email="", is_driver=True) for row in cursor.fetchall()}
        cursor.close()
        for driver_id in missing:
            cache[("picked", driver_id)] = [found[driver_id]] if driver_id in found else []
    for driver_id in driver_ids:
        drivers.extend(cache[("picked", driver_id)])
    return drivers


def delivery_context_id(rule: dict[str, Any], match: dict[str, Any]) -> str:
    parts = [
        "rule",
        str(int(rule["id"])),
        str(rule.get("event_type") or ""),
        str(match.get("entity_type") or ""),
        str(int(match["entity_id"])),
    ]
    if match.get("context_suffix"):
        parts.append(str(match["context_suffix"]))
    if int(rule.get("repeat_until_resolved") or 0) == 1:
        parts.append(str(match.get("repeat_bucket") or datetime.now().strftime("%Y-%m-%d")))
    return ":".join(parts)


def delivery_dedupe_key(context_id: str, address: str) -> str:
    """address = emailul, sau 'sms:+40...' pentru SMS (aceeasi forma ca in PHP)."""
    import hashlib

    return hashlib.sha256(f"{context_id}|{address.strip().lower()}".encode("utf-8")).hexdigest()


def existing_queue(conn, context_id: str, address: str, channel: str = "email") -> dict[str, Any] | None:
    address_column = "d.recipient_phone" if channel == "sms" else "d.recipient_email"
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        f"""
        SELECT
            q.id AS queue_id,
            q.status AS queue_status,
            d.id AS delivery_id,
            d.status AS delivery_status
        FROM notification_deliveries d
        LEFT JOIN notification_queue q ON q.delivery_id = d.id
        WHERE d.context = 'fleet_rule'
          AND d.context_id = %s
          AND d.channel = %s
          AND {address_column} = %s
        ORDER BY d.id DESC
        LIMIT 1
        """,
        (context_id, channel, address),
    )
    row = cursor.fetchone()
    cursor.close()
    return row


def expiry_status_label(days_left: int) -> str:
    if days_left < 0:
        return f"Expirat de {abs(days_left)} zile"
    if days_left == 0:
        return "Expira astazi"
    return f"Expira in {days_left} zile"


def format_date(value: Any) -> str:
    if hasattr(value, "strftime"):
        return value.strftime("%d.%m.%Y")
    return str(value or "")


def build_notification_email(
    config: MailConfig,
    rule: dict[str, Any],
    match: dict[str, Any],
    recipient: dict[str, Any],
) -> tuple[str, str]:
    days_left_raw = match.get("days_left")
    days_left = int(days_left_raw or 0)
    document_type = str(match.get("tip_document") or "Document")
    owner_name = str(match.get("owner_name") or "")
    owner_details = str(match.get("owner_details") or "").strip()
    number = str(match.get("numar_document") or "").strip()
    recipient_name = str(recipient.get("nume") or "utilizator").strip()
    notification_title = str(match.get("notification_title") or "").strip()
    status = notification_title or (expiry_status_label(days_left) if days_left_raw is not None else str(rule.get("name") or "Notificare"))
    entity_type = str(match.get("entity_type") or "")
    entity_label = {
        "driver": "Sofer",
        "tire": "Anvelopa",
        "equipment": "Dotare",
        "leave": "Concediu",
    }.get(entity_type, "Vehicul")

    if entity_type == "revizie":
        return build_revizie_email(config, match, recipient_name, status, document_type, owner_name, owner_details)

    page = "documente"
    target_id = int(match.get("document_id") or match.get("entity_id") or 0)
    if entity_type == "driver" and "document_id" in match:
        page = "documente_soferi"
    elif entity_type == "equipment":
        page = "inventar_dotari_vehicule"
    elif entity_type == "leave":
        page = "programare_concedii"
    elif entity_type == "tire":
        page = "vehicule"

    url = absolute_app_url("index.php", {"page": page})
    if target_id > 0 and entity_type in {"vehicle", "driver"} and "document_id" in match:
        url = absolute_app_url("index.php", {"page": page, "action": "show", "id": target_id})

    subject = f"[{config.app_name}] {status}: {document_type} - {owner_name}"
    lines = [
        f"Salut, {recipient_name},",
        "",
        "Exista o notificare configurata in aplicatia de flota.",
        "",
        f"{entity_label}: {owner_name}",
    ]
    if owner_details:
        lines.append(f"Detalii: {owner_details}")
    lines.append(f"Tip: {document_type}")
    if number:
        lines.append(f"Serie / numar: {number}")
    if match.get("data_expirare"):
        lines.append(f"Data tinta: {format_date(match.get('data_expirare'))}")
    if days_left_raw is not None:
        lines.append(f"Status: {expiry_status_label(days_left)}")
    if match.get("km_remaining") is not None:
        lines.append(f"Km ramasi estimati: {int(match.get('km_remaining') or 0)}")
    if match.get("tread_depth_mm") is not None:
        lines.append(f"Profil actual: {match.get('tread_depth_mm')} mm")
    lines.extend(["", "Deschide in aplicatie:", url, "", config.app_name])
    return subject, "\n".join(lines)


def build_revizie_email(
    config: MailConfig,
    match: dict[str, Any],
    recipient_name: str,
    status: str,
    component: str,
    owner_name: str,
    owner_details: str,
) -> tuple[str, str]:
    km_remaining = int(round(float(match.get("km_remaining") or 0)))
    if km_remaining <= 0:
        km_line = f"Depasit cu: {abs(km_remaining)} km"
    else:
        km_line = f"Km ramasi estimati: {km_remaining}"
    url = absolute_app_url("index.php", {"page": "revizii", "tip": str(match.get("revizie_categorie") or "")})

    subject = f"[{config.app_name}] {status}: {component} - {owner_name}"
    lines = [
        f"Salut, {recipient_name},",
        "",
        "Exista o notificare de revizie configurata in aplicatia de flota.",
        "",
        f"Vehicul: {owner_name}",
    ]
    if owner_details:
        lines.append(f"Detalii: {owner_details}")
    lines.extend([
        f"Componenta: {component}",
        f"Status: {status}",
        km_line,
        f"Interval: {int(match.get('revizie_interval_km') or 0)} km",
        f"Prag notificare: {int(match.get('revizie_prag_km') or 0)} km ramasi",
    ])
    if not match.get("revizie_complet", True):
        lines.append("Atentie: calculul are zile fara telemetrie (date incomplete).")
    lines.extend(["", "Deschide in aplicatie:", url, "", config.app_name])
    return subject, "\n".join(lines)


def build_sms_text(config: MailConfig, rule: dict[str, Any], match: dict[str, Any]) -> str:
    """Text scurt (un singur SMS cand se poate), fara link: SMS-ul e doar alerta."""
    entity_type = str(match.get("entity_type") or "")
    component = str(match.get("tip_document") or "Notificare")
    owner = str(match.get("owner_name") or "").strip()
    title = str(match.get("notification_title") or "").strip()

    if entity_type == "revizie":
        km = int(round(float(match.get("km_remaining") or 0)))
        km_text = f"depasit cu {abs(km)} km" if km <= 0 else f"mai sunt {km} km"
        text = f"{title or 'Revizie'}: {component} - {owner}, {km_text}"
        interval = int(match.get("revizie_interval_km") or 0)
        if interval > 0:
            text += f" (interval {interval} km)"
        if not match.get("revizie_complet", True):
            text += ". Date GPS incomplete"
    elif entity_type == "approval":
        text = f"Cerere aprobare in asteptare: {owner} - {component}"
        if match.get("owner_details"):
            text += f", solicitata de {match['owner_details']}"
        text += ". Decide din aplicatie sau din email"
    else:
        days_left_raw = match.get("days_left")
        status = title or (expiry_status_label(int(days_left_raw or 0)) if days_left_raw is not None else str(rule.get("name") or "Notificare"))
        text = f"{status}: {component} - {owner}"
        if match.get("data_expirare"):
            text += f" ({format_date(match.get('data_expirare'))})"
        if match.get("km_remaining") is not None:
            text += f", {int(match.get('km_remaining') or 0)} km ramasi"
        if match.get("tread_depth_mm") is not None:
            text += f", profil {match.get('tread_depth_mm')} mm"

    return sms_plain_text(f"{config.app_name}: {text}.")


def sms_subject(config: MailConfig, rule: dict[str, Any], match: dict[str, Any], recipient: dict[str, Any]) -> str:
    """SMS-ul nu are subiect; il pastram doar pentru coloanele Coada / Istoric."""
    if match.get("entity_type") == "approval":
        return f"[{config.app_name}] Cerere aprobare: {match.get('owner_name') or ''}"
    subject, _ = build_notification_email(config, rule, match, recipient)
    return subject


def create_pending_delivery(
    conn,
    context: str,
    context_id: str,
    recipient: dict[str, Any],
    subject: str,
    body: str,
    metadata: dict[str, Any],
    channel: str = "email",
    phone: str | None = None,
) -> int:
    is_sms = channel == "sms"
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO notification_deliveries (
            context,
            context_id,
            channel,
            recipient_email,
            recipient_phone,
            recipient_name,
            subject,
            message,
            status,
            provider,
            metadata_json,
            created_at
        ) VALUES (
            %s,
            %s,
            %s,
            %s,
            %s,
            %s,
            %s,
            %s,
            'pending',
            %s,
            %s,
            NOW()
        )
        """,
        (
            context[:80],
            context_id[:160],
            "sms" if is_sms else "email",
            None if is_sms else str(recipient.get("email") or "")[:190],
            phone if is_sms else None,
            str(recipient.get("nume") or "")[:190] or None,
            subject[:255],
            body,
            "smsalert" if is_sms else "smtp",
            json.dumps(metadata, ensure_ascii=False),
        ),
    )
    delivery_id = int(cursor.lastrowid)
    cursor.close()
    return delivery_id


def create_queue_job(conn, delivery_id: int, dedupe_key: str) -> None:
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO notification_queue (
            delivery_id,
            dedupe_key,
            status,
            attempts,
            max_attempts,
            scheduled_for,
            created_at,
            updated_at
        ) VALUES (
            %s,
            %s,
            'pending',
            0,
            3,
            NOW(),
            NOW(),
            NOW()
        )
        """,
        (delivery_id, dedupe_key),
    )
    cursor.close()


def requeue_existing_delivery(conn, queue_id: int, delivery_id: int) -> None:
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE notification_queue
        SET status = 'pending',
            attempts = 0,
            scheduled_for = NOW(),
            locked_at = NULL,
            last_error = NULL,
            updated_at = NOW()
        WHERE id = %s
        """,
        (queue_id,),
    )
    cursor.execute(
        """
        UPDATE notification_deliveries
        SET status = 'pending',
            error_message = NULL,
            provider_response = NULL,
            sent_at = NULL
        WHERE id = %s
        """,
        (delivery_id,),
    )
    cursor.close()


def delivery_targets(
    conn,
    rule: dict[str, Any],
    match: dict[str, Any],
    users: list[dict[str, Any]],
    channels: set[str],
    sms: SmsConfig,
    driver_cache: dict[tuple[str, int], list[dict[str, Any]]],
) -> tuple[list[tuple[str, str, dict[str, Any]]], int]:
    """(canal, adresa, destinatar) pentru o potrivire, fara dubluri; + cati au fost sariti."""
    targets: list[tuple[str, str, dict[str, Any]]] = []
    seen: set[tuple[str, str]] = set()
    skipped = 0

    def add(channel: str, address: str | None, recipient: dict[str, Any]) -> None:
        nonlocal skipped
        if not address:
            skipped += 1
            return
        key = (channel, address.lower())
        if key not in seen:
            seen.add(key)
            targets.append((channel, address, recipient))

    for user in users:
        if "email" in channels:
            email = str(user.get("email") or "").strip()
            add("email", email if "@" in email else None, user)
        if "sms" in channels:
            add("sms", normalize_phone(user.get("telefon"), sms.country_code), user)

    is_drivers_mode = str(rule.get("recipient_mode") or "") == "drivers"
    wants_driver = is_drivers_mode or bool(rule_metadata(rule).get("notify_driver"))
    if "sms" in channels and wants_driver:
        # „Doar soferul” cu soferi alesi in regula: ei primesc mereu SMS-ul, oricare ar fi vehiculul.
        picked = rule_driver_ids(rule) if is_drivers_mode else []
        drivers = picked_drivers(conn, picked, driver_cache) if picked else driver_recipients(conn, match, driver_cache)
        for driver in drivers:
            add("sms", normalize_phone(driver.get("telefon"), sms.country_code), driver)

    return targets, skipped


def enqueue_due_notifications(conn, config: MailConfig, sms: SmsConfig) -> dict[str, int]:
    summary = {"rules": 0, "matched": 0, "queued": 0, "queued_sms": 0, "requeued": 0, "skipped": 0, "sms_disabled": 0}
    rules = fetch_enabled_rules(conn)
    summary["rules"] = len(rules)
    driver_cache: dict[tuple[str, int], list[dict[str, Any]]] = {}

    for rule in rules:
        channels = rule_channels(rule)
        if "sms" in channels and not sms.ready:
            # SMS oprit / neconfigurat: nu umplem coada cu livrari care oricum ar esua.
            channels.discard("sms")
            summary["sms_disabled"] += 1
        if not channels:
            continue

        matches = fetch_matches_for_rule(conn, rule)
        summary["matched"] += len(matches)
        users = resolve_recipients(conn, rule)

        for match in matches:
            targets, skipped = delivery_targets(conn, rule, match, users, channels, sms, driver_cache)
            summary["skipped"] += skipped
            if not targets:
                continue

            context_id = delivery_context_id(rule, match)
            for channel, address, recipient in targets:
                existing = existing_queue(conn, context_id, address, channel)
                if existing:
                    if str(existing.get("queue_status") or "") == "failed":
                        requeue_existing_delivery(
                            conn,
                            int(existing["queue_id"]),
                            int(existing["delivery_id"]),
                        )
                        summary["requeued"] += 1
                    else:
                        summary["skipped"] += 1
                    continue

                metadata = {
                    "rule_id": int(rule["id"]),
                    "event_type": str(rule.get("event_type") or ""),
                    "entity_type": str(match.get("entity_type") or ""),
                    "entity_id": int(match["entity_id"]),
                    "document_id": int(match.get("document_id") or 0),
                }
                if channel == "sms":
                    subject = sms_subject(config, rule, match, recipient)
                    if recipient.get("is_driver"):
                        metadata["driver_id"] = int(recipient.get("driver_id") or 0)
                    delivery_id = create_pending_delivery(
                        conn, "fleet_rule", context_id, recipient, subject,
                        build_sms_text(config, rule, match), metadata, channel="sms", phone=address,
                    )
                    create_queue_job(conn, delivery_id, delivery_dedupe_key(context_id, f"sms:{address}"))
                    summary["queued_sms"] += 1
                else:
                    subject, body = build_notification_email(config, rule, match, recipient)
                    delivery_id = create_pending_delivery(conn, "fleet_rule", context_id, recipient, subject, body, metadata)
                    create_queue_job(conn, delivery_id, delivery_dedupe_key(context_id, address))
                    summary["queued"] += 1

    conn.commit()
    return summary


def release_stale_processing(conn, stale_minutes: int = 15) -> int:
    cursor = conn.cursor()
    cursor.execute(
        f"""
        UPDATE notification_queue
        SET status = 'pending',
            locked_at = NULL,
            updated_at = NOW(),
            last_error = 'Worker lock expired before completion.'
        WHERE status = 'processing'
          AND locked_at < DATE_SUB(NOW(), INTERVAL {max(1, stale_minutes)} MINUTE)
        """
    )
    changed = cursor.rowcount
    conn.commit()
    cursor.close()
    return int(changed)


def fetch_pending_jobs(conn, limit: int) -> list[dict[str, Any]]:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            q.id AS queue_id,
            q.delivery_id,
            q.attempts,
            q.max_attempts,
            d.channel,
            d.recipient_email,
            d.recipient_phone,
            d.recipient_name,
            d.subject,
            d.message,
            d.context,
            d.context_id
        FROM notification_queue q
        INNER JOIN notification_deliveries d ON d.id = q.delivery_id
        WHERE q.status = 'pending'
          AND q.scheduled_for <= NOW()
        ORDER BY q.scheduled_for ASC, q.id ASC
        LIMIT %s
        """,
        (max(1, limit),),
    )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def claim_job(conn, queue_id: int) -> bool:
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE notification_queue
        SET status = 'processing',
            attempts = attempts + 1,
            locked_at = NOW(),
            updated_at = NOW()
        WHERE id = %s
          AND status = 'pending'
          AND scheduled_for <= NOW()
        """,
        (queue_id,),
    )
    claimed = cursor.rowcount == 1
    conn.commit()
    cursor.close()
    return claimed


def smtp_send(config: MailConfig, job: dict[str, Any]) -> str:
    if not config.host or not config.from_address:
        raise RuntimeError("SMTP is not configured. Check MAIL_HOST and MAIL_FROM_ADDRESS.")

    recipient_email = str(job["recipient_email"])
    recipient_name = str(job.get("recipient_name") or "")

    message = EmailMessage()
    message["Subject"] = str(job["subject"])
    message["From"] = formataddr((config.from_name, config.from_address))
    message["To"] = formataddr((recipient_name, recipient_email)) if recipient_name else recipient_email
    message["Message-ID"] = make_msgid()
    if config.return_path:
        message["Return-Path"] = config.return_path
    message.set_content(str(job["message"]))

    from_addr = config.return_path or config.from_address
    context = ssl.create_default_context()

    if config.encryption == "ssl":
        smtp = smtplib.SMTP_SSL(config.host, config.port, timeout=config.timeout, context=context)
    else:
        smtp = smtplib.SMTP(config.host, config.port, timeout=config.timeout)

    try:
        smtp.ehlo()
        if config.encryption == "tls":
            smtp.starttls(context=context)
            smtp.ehlo()
        if config.username:
            smtp.login(config.username, config.password)
        refused = smtp.send_message(message, from_addr=from_addr, to_addrs=[recipient_email])
    finally:
        try:
            smtp.quit()
        except smtplib.SMTPException:
            smtp.close()

    if refused:
        raise RuntimeError("SMTP refused recipients: " + json.dumps(refused, default=str))

    return "SMTP accepted message"


def mark_sent(conn, queue_id: int, delivery_id: int, response: str) -> None:
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE notification_queue
        SET status = 'sent',
            locked_at = NULL,
            last_error = NULL,
            updated_at = NOW()
        WHERE id = %s
        """,
        (queue_id,),
    )
    cursor.execute(
        """
        UPDATE notification_deliveries
        SET status = 'sent',
            provider_response = %s,
            error_message = NULL,
            sent_at = NOW()
        WHERE id = %s
        """,
        (response, delivery_id),
    )
    conn.commit()
    cursor.close()


def mark_failed_or_retry(conn, job: dict[str, Any], error_message: str, attempt_number: int) -> str:
    max_attempts = int(job.get("max_attempts") or 3)
    queue_id = int(job["queue_id"])
    delivery_id = int(job["delivery_id"])
    cursor = conn.cursor()

    if attempt_number < max_attempts:
        delay_minutes = min(60, 5 * attempt_number)
        scheduled_for = datetime.now() + timedelta(minutes=delay_minutes)
        cursor.execute(
            """
            UPDATE notification_queue
            SET status = 'pending',
                scheduled_for = %s,
                locked_at = NULL,
                last_error = %s,
                updated_at = NOW()
            WHERE id = %s
            """,
            (scheduled_for.strftime("%Y-%m-%d %H:%M:%S"), error_message, queue_id),
        )
        cursor.execute(
            """
            UPDATE notification_deliveries
            SET status = 'pending',
                error_message = %s
            WHERE id = %s
            """,
            (error_message, delivery_id),
        )
        status = "retry"
    else:
        cursor.execute(
            """
            UPDATE notification_queue
            SET status = 'failed',
                locked_at = NULL,
                last_error = %s,
                updated_at = NOW()
            WHERE id = %s
            """,
            (error_message, queue_id),
        )
        cursor.execute(
            """
            UPDATE notification_deliveries
            SET status = 'failed',
                error_message = %s
            WHERE id = %s
            """,
            (error_message, delivery_id),
        )
        status = "failed"

    conn.commit()
    cursor.close()
    return status


def normalize_error(exception: BaseException) -> str:
    text = str(exception).strip() or exception.__class__.__name__
    password = env_value(["MAIL_PASSWORD", "SMTP_PASSWORD"])
    if password:
        text = text.replace(password, "[hidden-password]")
    api_key = env_value(["SMSALERT_API_KEY"])
    if api_key:
        text = text.replace(api_key, "[hidden-api-key]")
    return text[:2000]


def run_approval_flow() -> dict[str, Any] | None:
    """Ruleaza fluxul de aprobare prin email, daca este activat din .env.

    Import lazy si erori izolate: un flux de aprobare picat nu trebuie sa opreasca
    trimiterea notificarilor obisnuite.
    """
    if os.getenv("APPROVAL_EMAIL_ENABLED", "").strip().lower() not in {"1", "true", "yes", "on", "da"}:
        return None

    try:
        import approval_flow
    except ImportError as exc:
        return {"error": f"approval_flow indisponibil: {exc}"}

    try:
        return approval_flow.run()
    except Exception as exc:
        return {"error": normalize_error(exc)}


def run_support_mail() -> dict[str, Any] | None:
    """Suportul soferilor (tichete WhatsApp -> email, raspunsuri email -> WhatsApp), daca e activat.

    Ca run_approval_flow(): import lazy si erori izolate.
    """
    if os.getenv("SUPPORT_EMAIL_ENABLED", "").strip().lower() not in {"1", "true", "yes", "on", "da"}:
        return None

    try:
        import support_mail
    except ImportError as exc:
        return {"error": f"support_mail indisponibil: {exc}"}

    try:
        return support_mail.run()
    except Exception as exc:
        return {"error": normalize_error(exc)}


class SmsSender:
    """Trimite SMS-urile unei rulari respectand limita SMSAlert (1 apel / 2 s)."""

    def __init__(self, config: SmsConfig) -> None:
        self.config = config
        self.sent = 0
        self.blocked_reason: str | None = None
        self._last_call = 0.0

    def can_send(self) -> bool:
        return self.blocked_reason is None and self.sent < self.config.max_send_per_run

    def send(self, job: dict[str, Any]) -> str:
        if not self.config.ready:
            raise RuntimeError("SMS neconfigurat sau oprit (SMSALERT_ENABLED / SMSALERT_USERNAME / SMSALERT_API_KEY).")
        phone = str(job.get("recipient_phone") or "")
        target = normalize_phone(self.config.force_to, self.config.country_code) if self.config.force_to else phone
        if not target:
            raise RuntimeError(f"Numar de telefon invalid: {phone or '-'}")

        wait = SMS_MIN_INTERVAL_SECONDS - (time.monotonic() - self._last_call)
        if wait > 0:
            time.sleep(wait)
        self._last_call = time.monotonic()
        try:
            response = smsalert_send(self.config, target, str(job["message"]))
        except SmsRateLimited as exc:
            self.blocked_reason = str(exc)
            raise
        self.sent += 1
        return response if target == phone else f"{response} (mod test: trimis la {target})"


def process_jobs(limit: int, enqueue: bool = True, send: bool = True) -> dict[str, Any]:
    conn = connect_db()
    config = mail_config()
    sms = sms_config()
    summary: dict[str, Any] = {
        "checked_at": datetime.now().isoformat(timespec="seconds"),
        "enqueue": None,
        "released_locks": 0,
        "fetched": 0,
        "sent": 0,
        "sent_sms": 0,
        "retry": 0,
        "failed": 0,
        "skipped_claimed": 0,
        "deferred_sms": 0,
        "errors": [],
    }

    try:
        ensure_sms_schema(conn)
        summary["released_locks"] = release_stale_processing(conn)
        if enqueue:
            summary["enqueue"] = enqueue_due_notifications(conn, config, sms)
        if not send:
            return summary

        jobs = fetch_pending_jobs(conn, limit)
        summary["fetched"] = len(jobs)
        sms_sender = SmsSender(sms)

        for job in jobs:
            queue_id = int(job["queue_id"])
            is_sms = str(job.get("channel") or "email") == "sms"
            if is_sms and not sms_sender.can_send():
                # Limita pe rulare sau 429: raman pending pentru rularea urmatoare.
                summary["deferred_sms"] += 1
                continue
            if not claim_job(conn, queue_id):
                summary["skipped_claimed"] += 1
                continue

            attempt_number = int(job.get("attempts") or 0) + 1
            if is_sms:
                # O singura incercare pe rulare: un timeout dupa acceptare ar dubla SMS-ul.
                try:
                    response = sms_sender.send(job)
                    mark_sent(conn, queue_id, int(job["delivery_id"]), response)
                    summary["sent_sms"] += 1
                except Exception as exc:
                    error = normalize_error(exc)
                    status = mark_failed_or_retry(conn, job, error, attempt_number)
                    summary[status] += 1
                    summary["errors"].append({"queue_id": queue_id, "channel": "sms", "status": status, "error": error})
                continue

            try:
                response = None
                last_error = None
                for attempt in range(config.retry_attempts):
                    try:
                        response = smtp_send(config, job)
                        last_error = None
                        break
                    except Exception as exc:
                        last_error = exc
                        if attempt + 1 < config.retry_attempts and config.retry_delay_ms > 0:
                            time.sleep(config.retry_delay_ms / 1000)

                if last_error is not None:
                    raise last_error

                mark_sent(conn, queue_id, int(job["delivery_id"]), str(response))
                summary["sent"] += 1
            except Exception as exc:
                error = normalize_error(exc)
                status = mark_failed_or_retry(conn, job, error, attempt_number)
                summary[status] += 1
                summary["errors"].append({"queue_id": queue_id, "status": status, "error": error})
    finally:
        conn.close()

    approvals = run_approval_flow()
    if approvals is not None:
        summary["approvals"] = approvals

    support = run_support_mail()
    if support is not None:
        summary["support"] = support

    return summary


def dry_run() -> dict[str, Any]:
    conn = connect_db()
    try:
        return {
            "checked_at": datetime.now().isoformat(timespec="seconds"),
            "pending_ready": count_pending(conn),
        }
    finally:
        conn.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Fleet notification background worker")
    parser.add_argument("--limit", type=int, default=20, help="Maximum jobs to process in one run")
    parser.add_argument("--dry-run", action="store_true", help="Only report ready pending jobs")
    parser.add_argument("--send-only", action="store_true", help="Skip the due-rule scan and only send queued jobs")
    parser.add_argument("--enqueue-only", action="store_true", help="Scan rules and queue due jobs without sending")
    args = parser.parse_args()

    try:
        result = dry_run() if args.dry_run else process_jobs(
            max(1, args.limit),
            enqueue=not args.send_only,
            send=not args.enqueue_only,
        )
        print(json.dumps(result, ensure_ascii=False, default=str))
        return 0
    except Exception as exc:
        print(json.dumps({"error": normalize_error(exc)}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
