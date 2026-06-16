#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import smtplib
import ssl
import sys
import time
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
    return []


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
    cursor = conn.cursor(dictionary=True)
    if str(rule.get("recipient_mode") or "admins") == "specific_users":
        cursor.execute(
            """
            SELECT u.id, u.nume, u.email, u.rol
            FROM notification_rule_recipients rr
            INNER JOIN utilizatori u ON u.id = rr.user_id
            WHERE rr.rule_id = %s
              AND u.status = 'activ'
              AND u.email IS NOT NULL
              AND u.email <> ''
            ORDER BY u.nume ASC
            """,
            (int(rule["id"]),),
        )
    else:
        cursor.execute(
            """
            SELECT id, nume, email, rol
            FROM utilizatori
            WHERE status = 'activ'
              AND rol = 'admin'
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY nume ASC
            """
        )
    rows = list(cursor.fetchall())
    cursor.close()
    return rows


def delivery_context_id(rule: dict[str, Any], match: dict[str, Any]) -> str:
    parts = [
        "rule",
        str(int(rule["id"])),
        str(rule.get("event_type") or ""),
        str(match.get("entity_type") or ""),
        str(int(match["entity_id"])),
    ]
    if int(rule.get("repeat_until_resolved") or 0) == 1:
        parts.append(datetime.now().strftime("%Y-%m-%d"))
    return ":".join(parts)


def delivery_dedupe_key(context_id: str, email: str) -> str:
    import hashlib

    return hashlib.sha256(f"{context_id}|{email.strip().lower()}".encode("utf-8")).hexdigest()


def existing_queue(conn, context_id: str, email: str) -> dict[str, Any] | None:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            q.id AS queue_id,
            q.status AS queue_status,
            d.id AS delivery_id,
            d.status AS delivery_status
        FROM notification_deliveries d
        LEFT JOIN notification_queue q ON q.delivery_id = d.id
        WHERE d.context = 'fleet_rule'
          AND d.context_id = %s
          AND d.recipient_email = %s
        ORDER BY d.id DESC
        LIMIT 1
        """,
        (context_id, email),
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


def create_pending_delivery(
    conn,
    context: str,
    context_id: str,
    recipient: dict[str, Any],
    subject: str,
    body: str,
    metadata: dict[str, Any],
) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO notification_deliveries (
            context,
            context_id,
            channel,
            recipient_email,
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
            'email',
            %s,
            %s,
            %s,
            %s,
            'pending',
            'smtp',
            %s,
            NOW()
        )
        """,
        (
            context[:80],
            context_id[:160],
            str(recipient.get("email") or "")[:190],
            str(recipient.get("nume") or "")[:190] or None,
            subject[:255],
            body,
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


def enqueue_due_notifications(conn, config: MailConfig) -> dict[str, int]:
    summary = {"rules": 0, "matched": 0, "queued": 0, "requeued": 0, "skipped": 0}
    rules = fetch_enabled_rules(conn)
    summary["rules"] = len(rules)

    for rule in rules:
        matches = fetch_matches_for_rule(conn, rule)
        summary["matched"] += len(matches)
        recipients = resolve_recipients(conn, rule)
        if not recipients:
            summary["skipped"] += len(matches)
            continue

        for match in matches:
            for recipient in recipients:
                email = str(recipient.get("email") or "").strip()
                if not email or "@" not in email:
                    summary["skipped"] += 1
                    continue

                context_id = delivery_context_id(rule, match)
                existing = existing_queue(conn, context_id, email)
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

                subject, body = build_notification_email(config, rule, match, recipient)
                delivery_id = create_pending_delivery(
                    conn,
                    "fleet_rule",
                    context_id,
                    recipient,
                    subject,
                    body,
                    {
                        "rule_id": int(rule["id"]),
                        "event_type": str(rule.get("event_type") or ""),
                        "entity_type": str(match.get("entity_type") or ""),
                        "entity_id": int(match["entity_id"]),
                        "document_id": int(match.get("document_id") or 0),
                    },
                )
                create_queue_job(conn, delivery_id, delivery_dedupe_key(context_id, email))
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
            d.recipient_email,
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
    return text[:2000]


def process_jobs(limit: int, enqueue: bool = True, send: bool = True) -> dict[str, Any]:
    conn = connect_db()
    config = mail_config()
    summary: dict[str, Any] = {
        "checked_at": datetime.now().isoformat(timespec="seconds"),
        "enqueue": None,
        "released_locks": 0,
        "fetched": 0,
        "sent": 0,
        "retry": 0,
        "failed": 0,
        "skipped_claimed": 0,
        "errors": [],
    }

    try:
        summary["released_locks"] = release_stale_processing(conn)
        if enqueue:
            summary["enqueue"] = enqueue_due_notifications(conn, config)
        if not send:
            return summary

        jobs = fetch_pending_jobs(conn, limit)
        summary["fetched"] = len(jobs)

        for job in jobs:
            queue_id = int(job["queue_id"])
            if not claim_job(conn, queue_id):
                summary["skipped_claimed"] += 1
                continue

            attempt_number = int(job.get("attempts") or 0) + 1
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
