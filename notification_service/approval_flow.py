#!/usr/bin/env python3
"""Aprobare prin email pentru cererile din inactive_resource_approvals.

Doua etape independente, ambele rulate de acelasi scheduler ca worker.py:

  send    Cauta cereri 'pending' carora nu li s-a trimis inca email, emite cate un
          token de aprobare si unul de respingere, si trimite cardul cu butoane.
  poll    Citeste casuta de aprobari prin IMAP, identifica token-ul din adresa de
          reply (sub-addressing) sau din subiect, verifica expeditorul si aplica
          decizia in baza de date.

Token-ul circula in clar prin email, dar in baza de date se pastreaza doar
SHA-256. Un token este valabil o singura data si expira dupa APPROVAL_TOKEN_TTL_DAYS.

Modulul este intentionat independent de worker.py (isi are propriile helper-e de
env si DB) ca sa poata fi rulat si separat, si ca sa nu creeze import circular.
"""
from __future__ import annotations

import argparse
import email
import hashlib
import imaplib
import json
import os
import re
import secrets
import smtplib
import ssl
import sys
from dataclasses import dataclass
from datetime import datetime, timedelta
from email.header import decode_header, make_header
from email.message import EmailMessage
from email.utils import formataddr, make_msgid, parseaddr
from html import escape
from pathlib import Path
from typing import Any
from urllib.parse import quote

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

# Alfabet fara caractere ambigue, sigur si in adrese de email si in subiect.
TOKEN_ALPHABET = "abcdefghjkmnpqrstuvwxyz23456789"
TOKEN_LENGTH = 16
ADDRESS_TAG_PREFIX = "ap-"

# Header pus pe notificarile trimise de noi. Daca il regasim pe un mesaj din inbox
# inseamna ca este chiar mailul nostru, nu un raspuns -> se ignora.
REQUEST_HEADER = "X-Fleet-Approval-Request"

TOKEN_IN_SUBJECT = re.compile(r"\[#([" + TOKEN_ALPHABET + r"]{%d})\]" % TOKEN_LENGTH, re.IGNORECASE)
TOKEN_IN_ADDRESS = re.compile(
    r"\+" + ADDRESS_TAG_PREFIX + r"([" + TOKEN_ALPHABET + r"]{%d})@" % TOKEN_LENGTH,
    re.IGNORECASE,
)


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


def bool_env(names: list[str], default: bool = False) -> bool:
    raw = env_value(names, "1" if default else "0").strip().lower()
    return raw in {"1", "true", "yes", "on", "da"}


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


@dataclass(frozen=True)
class ApprovalConfig:
    enabled: bool
    app_name: str
    app_url: str
    # SMTP (reutilizeaza setarile existente ale aplicatiei)
    smtp_host: str
    smtp_port: int
    smtp_username: str
    smtp_password: str
    smtp_encryption: str
    from_address: str
    from_name: str
    return_path: str
    smtp_timeout: int
    # Casuta de aprobari
    inbox_address: str
    imap_host: str
    imap_port: int
    imap_username: str
    imap_password: str
    imap_folder: str
    # Destinatari
    force_recipient: str
    excluded_recipients: tuple[str, ...]
    token_ttl_days: int
    max_send_per_run: int

    @property
    def configured_for_send(self) -> tuple[bool, str]:
        if not self.enabled:
            return False, "APPROVAL_EMAIL_ENABLED nu este activat."
        if not self.smtp_host or not self.from_address:
            return False, "SMTP neconfigurat (MAIL_HOST / MAIL_FROM_ADDRESS)."
        if not self.inbox_address or "@" not in self.inbox_address:
            return False, "APPROVAL_INBOX_ADDRESS lipseste sau este invalid."
        return True, ""

    @property
    def configured_for_poll(self) -> tuple[bool, str]:
        ok, reason = self.configured_for_send
        if not ok:
            return False, reason
        if not self.imap_host:
            return False, "APPROVAL_INBOX_IMAP_HOST lipseste."
        if not self.imap_username or not self.imap_password:
            return False, "APPROVAL_INBOX_USERNAME / APPROVAL_INBOX_PASSWORD lipsesc."
        return True, ""


def approval_config() -> ApprovalConfig:
    from_address = env_value(["MAIL_FROM_ADDRESS"], env_value(["MAIL_USERNAME"]))
    inbox_address = env_value(["APPROVAL_INBOX_ADDRESS"])
    excluded = [
        item.strip().lower()
        for item in env_value(["APPROVAL_MAIL_EXCLUDE"]).split(",")
        if item.strip()
    ]

    return ApprovalConfig(
        enabled=bool_env(["APPROVAL_EMAIL_ENABLED"], False),
        app_name=env_value(["APP_NAME"], "Fleet Management"),
        app_url=env_value(["APP_URL"]).rstrip("/"),
        smtp_host=env_value(["MAIL_HOST", "SMTP_HOST"]),
        smtp_port=int_env(["MAIL_PORT", "SMTP_PORT"], 587),
        smtp_username=env_value(["MAIL_USERNAME", "SMTP_USERNAME"]),
        smtp_password=env_value(["MAIL_PASSWORD", "SMTP_PASSWORD"]),
        smtp_encryption=env_value(["MAIL_ENCRYPTION", "SMTP_ENCRYPTION"], "tls").lower(),
        from_address=from_address,
        from_name=env_value(["MAIL_FROM_NAME"], "Fleet Management"),
        return_path=env_value(["MAIL_RETURN_PATH"], from_address),
        smtp_timeout=max(10, int_env(["MAIL_TIMEOUT"], 45)),
        inbox_address=inbox_address,
        imap_host=env_value(["APPROVAL_INBOX_IMAP_HOST"]),
        imap_port=int_env(["APPROVAL_INBOX_IMAP_PORT"], 993),
        imap_username=env_value(["APPROVAL_INBOX_USERNAME"], inbox_address),
        imap_password=env_value(["APPROVAL_INBOX_PASSWORD"]),
        imap_folder=env_value(["APPROVAL_INBOX_FOLDER"], "INBOX"),
        force_recipient=env_value(["APPROVAL_MAIL_TO"]).strip(),
        excluded_recipients=tuple(excluded),
        token_ttl_days=max(1, int_env(["APPROVAL_TOKEN_TTL_DAYS"], 7)),
        max_send_per_run=max(1, int_env(["APPROVAL_MAX_SEND_PER_RUN"], 10)),
    )


# ---------------------------------------------------------------------------
# Token-uri
# ---------------------------------------------------------------------------

def generate_token() -> str:
    return "".join(secrets.choice(TOKEN_ALPHABET) for _ in range(TOKEN_LENGTH))


def hash_token(token: str) -> str:
    return hashlib.sha256(token.strip().lower().encode("utf-8")).hexdigest()


def tagged_address(base_address: str, token: str) -> str:
    """gigel@domeniu.ro + token -> gigel+ap-<token>@domeniu.ro"""
    local, _, domain = base_address.partition("@")
    return f"{local}+{ADDRESS_TAG_PREFIX}{token}@{domain}"


def extract_token(candidates: list[str]) -> str | None:
    """Cauta token-ul intai in adrese (sub-addressing), apoi in subiect."""
    for value in candidates:
        if not value:
            continue
        match = TOKEN_IN_ADDRESS.search(value)
        if match:
            return match.group(1).lower()
    for value in candidates:
        if not value:
            continue
        match = TOKEN_IN_SUBJECT.search(value)
        if match:
            return match.group(1).lower()
    return None


# ---------------------------------------------------------------------------
# Interogari
# ---------------------------------------------------------------------------

APPROVAL_EVENT = "inactive_approval_pending"


def fetch_approval_rules(conn) -> list[dict[str, Any]]:
    """Regulile active de tip 'cerere aprobare in asteptare' de pe pagina Notificari."""
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT id, name, recipient_mode, repeat_until_resolved,
               daily_limit_enabled, metadata_json
        FROM notification_rules
        WHERE enabled = 1
          AND event_type = %s
        ORDER BY id ASC
        """,
        (APPROVAL_EVENT,),
    )
    rows = cursor.fetchall()
    cursor.close()

    rules = []
    for row in rows:
        metadata: Any = row.get("metadata_json") or "{}"
        if isinstance(metadata, (bytes, bytearray)):
            metadata = metadata.decode("utf-8", "replace")
        if isinstance(metadata, str):
            try:
                metadata = json.loads(metadata)
            except ValueError:
                metadata = {}
        if not isinstance(metadata, dict):
            metadata = {}

        rules.append({
            "id": int(row["id"]),
            "name": str(row.get("name") or ""),
            "recipient_mode": str(row.get("recipient_mode") or "admins"),
            "repeat": int(row.get("repeat_until_resolved") or 0) == 1,
            "daily_limit": int(row.get("daily_limit_enabled") or 0) == 1,
            "resource_types": [str(v) for v in metadata.get("approval_resource_types") or []],
            "reasons": [str(v) for v in metadata.get("approval_reasons") or []],
            "repeat_days": max(1, int(metadata.get("repeat_days") or 1)),
        })

    return rules


def default_rule() -> dict[str, Any]:
    """Comportamentul de dinaintea regulilor: o data catre toti adminii, fara filtre.

    Se foloseste doar cand nu exista nicio regula configurata, ca fluxul sa nu se
    opreasca in tacere pentru cine nu a intrat inca pe pagina Notificari.
    """
    return {
        "id": 0,
        "name": "(implicit)",
        "recipient_mode": "admins",
        "repeat": False,
        "daily_limit": True,
        "resource_types": [],
        "reasons": [],
        "repeat_days": 1,
    }


def rule_matches(approval: dict[str, Any], rule: dict[str, Any]) -> bool:
    """Lista goala inseamna 'toate', nu 'niciuna'."""
    types = rule["resource_types"]
    if types and str(approval.get("resource_type") or "") not in types:
        return False

    reasons = rule["reasons"]
    if reasons and str(approval.get("inactive_reason") or "") not in reasons:
        return False

    return True


def fetch_pending_approvals(conn, limit: int) -> list[dict[str, Any]]:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT
            a.id,
            a.resource_type,
            a.resource_label,
            a.inactive_reason,
            a.inactive_reason_label,
            a.inactive_since,
            a.usage_context,
            a.requested_at,
            a.updated_at,
            a.trip_id,
            a.snapshot_json,
            c.data_cursa AS trip_date,
            c.data_incarcare AS trip_loading_date,
            c.data_inceput AS trip_start_date,
            c.data_sfarsit AS trip_end_date,
            c.tip_transport AS trip_transport_type,
            c.loc_plecare AS trip_departure_text,
            c.loc_aspirare AS trip_suction_text,
            c.loc_livrare AS trip_delivery_text,
            c.loc_livrare_cursa AS trip_closing_location_text,
            trip_vehicle.nr_inmatriculare AS trip_vehicle_label,
            trip_driver.nume AS trip_driver_label,
            beneficiary.nume AS trip_beneficiary_name,
            load_location.nume AS trip_load_location_name,
            unload_zone.nume AS trip_unload_zone_name,
            u.nume AS requested_by_name
        FROM inactive_resource_approvals a
        LEFT JOIN utilizatori u ON u.id = a.requested_by_user_id
        LEFT JOIN curse_dispecer c ON c.id = a.trip_id
        LEFT JOIN vehicule trip_vehicle ON trip_vehicle.id = c.vehicle_id
        LEFT JOIN soferi trip_driver ON trip_driver.id = c.driver_id
        LEFT JOIN configurare_beneficiari_transport beneficiary ON beneficiary.id = c.beneficiar_id
        LEFT JOIN configurare_locuri_incarcare load_location ON load_location.id = c.loc_incarcare_id
        LEFT JOIN configurare_zone_distributie unload_zone ON unload_zone.id = c.zona_distributie_id
        WHERE a.status = 'pending'
        ORDER BY a.requested_at ASC
        LIMIT %s
        """,
        (int(limit),),
    )
    rows = cursor.fetchall()
    cursor.close()
    return rows


def last_sent_at(conn, approval_id: int, recipient_email: str) -> datetime | None:
    cursor = conn.cursor()
    cursor.execute(
        """
        SELECT MAX(sent_at)
        FROM approval_email_actions
        WHERE approval_id = %s AND recipient_email = %s
        """,
        (int(approval_id), recipient_email),
    )
    row = cursor.fetchone()
    cursor.close()
    return row[0] if row and row[0] else None


def should_send(last_sent: datetime | None, approval: dict[str, Any], rule: dict[str, Any], now: datetime) -> bool:
    if last_sent is None:
        return True

    # Cererea si-a schimbat starea de la ultimul email (tipic: repusa in asteptare).
    updated_at = approval.get("updated_at")
    if updated_at and last_sent < updated_at:
        return True

    if not rule["repeat"]:
        return False

    return last_sent <= now - timedelta(days=rule["repeat_days"])


def fetch_approval_documents(conn, approval_id: int) -> list[dict[str, Any]]:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT document_name, document_status, expiry_date
        FROM inactive_resource_approval_documents
        WHERE approval_id = %s
        ORDER BY document_name ASC
        """,
        (int(approval_id),),
    )
    rows = cursor.fetchall()
    cursor.close()
    return rows


def approval_recipients(conn, config: ApprovalConfig, rule: dict[str, Any] | None = None) -> list[dict[str, Any]]:
    """Destinatarii regulii. APPROVAL_MAIL_TO forteaza un singur destinatar (mod test)."""
    cursor = conn.cursor(dictionary=True)

    if rule is not None and rule.get("recipient_mode") == "specific_users" and int(rule.get("id") or 0) > 0:
        cursor.execute(
            """
            SELECT u.id, u.nume, u.email
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
            SELECT id, nume, email
            FROM utilizatori
            WHERE status = 'activ'
              AND rol = 'admin'
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY nume ASC
            """
        )

    rows = cursor.fetchall()
    cursor.close()

    if config.force_recipient:
        match = next(
            (r for r in rows if str(r["email"]).strip().lower() == config.force_recipient.lower()),
            None,
        )
        return [match] if match else [{"id": None, "nume": "", "email": config.force_recipient}]

    return [
        row for row in rows
        if str(row["email"]).strip().lower() not in config.excluded_recipients
    ]


# ---------------------------------------------------------------------------
# Compunerea emailului
# ---------------------------------------------------------------------------

def format_date(value: Any) -> str:
    if value is None or value == "":
        return "-"
    if isinstance(value, datetime):
        return value.strftime("%d.%m.%Y %H:%M")
    try:
        return value.strftime("%d.%m.%Y")
    except AttributeError:
        return str(value)


def resource_type_label(resource_type: str) -> str:
    return {
        "vehicle": "Vehicul",
        "driver": "Sofer",
        "repair": "Reparatie",
    }.get(resource_type, resource_type)


TRANSPORT_TYPE_LABELS = {
    "primar": "Primar km",
    "primar_tona": "Primar tone",
    "distributie": "Distributie",
    "primar_distributie": "Primar+Distributie",
    "compresor": "Compresor",
}


def clean(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def first_non_empty(*values: Any) -> str:
    for value in values:
        text = clean(value)
        if text:
            return text
    return ""


def parse_date(value: Any) -> datetime | None:
    if value is None or value == "":
        return None
    if isinstance(value, datetime):
        return value
    try:
        return datetime.combine(value, datetime.min.time())
    except TypeError:
        pass
    text = str(value).strip()[:10]
    try:
        return datetime.strptime(text, "%Y-%m-%d")
    except ValueError:
        return None


def format_date_compact(value: Any) -> str:
    parsed = parse_date(value)
    if parsed is not None:
        return parsed.strftime("%d.%m.%Y")
    if isinstance(value, datetime):
        return value.strftime("%d.%m.%Y %H:%M")
    return clean(value)


def format_datetime_compact(value: Any) -> str:
    if value is None or value == "":
        return ""
    if isinstance(value, datetime):
        return value.strftime("%d.%m.%Y %H:%M")
    text = str(value).strip()
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M"):
        try:
            return datetime.strptime(text, fmt).strftime("%d.%m.%Y %H:%M")
        except ValueError:
            pass
    return text


def document_problem(documents: list[dict[str, Any]], fallback: str) -> str:
    expired: list[str] = []
    missing: list[str] = []
    for document in documents:
        name = clean(document.get("document_name"))
        if not name:
            continue
        if clean(document.get("document_status")) == "missing":
            missing.append(name)
        else:
            expired.append(name)

    names = ", ".join(dict.fromkeys(expired + missing))
    if expired and missing:
        return f"Documente lipsa/expirate: {names}"
    if expired:
        return ("Document expirat: " if len(expired) == 1 else "Documente expirate: ") + ", ".join(dict.fromkeys(expired))
    if missing:
        return ("Document lipsa: " if len(missing) == 1 else "Documente lipsa: ") + ", ".join(dict.fromkeys(missing))
    return fallback


def first_document_expiry(documents: list[dict[str, Any]]) -> Any:
    dates: list[str] = []
    for document in documents:
        if clean(document.get("document_status")) != "expired":
            continue
        parsed = parse_date(document.get("expiry_date"))
        if parsed is not None:
            dates.append(parsed.strftime("%Y-%m-%d"))
    return sorted(dates)[0] if dates else ""


def operation_title(approval: dict[str, Any]) -> str:
    trip_id = int(approval.get("trip_id") or 0)
    if trip_id <= 0:
        return "Solicitare fara cursa asociata"
    transport = TRANSPORT_TYPE_LABELS.get(clean(approval.get("trip_transport_type")), clean(approval.get("trip_transport_type")))
    return f"Cursa #{trip_id}" + (f" · {transport}" if transport else "")


def usage_date(approval: dict[str, Any]) -> Any:
    return first_non_empty(
        approval.get("trip_loading_date"),
        approval.get("trip_start_date"),
        approval.get("trip_date"),
    )


def route_label(approval: dict[str, Any]) -> str:
    start = first_non_empty(
        approval.get("trip_load_location_name"),
        approval.get("trip_departure_text"),
        approval.get("trip_suction_text"),
    )
    end = first_non_empty(
        approval.get("trip_unload_zone_name"),
        approval.get("trip_delivery_text"),
        approval.get("trip_closing_location_text"),
    )
    if start and end and start.lower() != end.lower():
        return f"{start} -> {end}"
    return start or end


def overdue_label(use_date: Any, documents: list[dict[str, Any]]) -> str:
    expiry = first_document_expiry(documents)
    parsed_usage = parse_date(use_date)
    parsed_expiry = parse_date(expiry)
    if parsed_usage is None or parsed_expiry is None or parsed_usage <= parsed_expiry:
        return ""
    days = (parsed_usage - parsed_expiry).days
    return "1 zi" if days == 1 else f"{days} zile"


def append_row(rows: list[tuple[str, str]], label: str, value: Any) -> None:
    text = clean(value)
    if label and text:
        rows.append((label, text))


def approval_scope_message(approval: dict[str, Any], primary_label: str, problem: str) -> str:
    resource_type = clean(approval.get("resource_type"))
    trip_id = int(approval.get("trip_id") or 0)
    resource_text = "soferului" if resource_type == "driver" else "vehiculului"
    request_resource_text = "soferul" if resource_type == "driver" else "vehiculul"
    target = f"{resource_text} {primary_label}".strip()
    request_target = f"{request_resource_text} {primary_label}".strip()
    suffix = f", desi exista motivul: {problem}." if problem else "."
    if trip_id > 0:
        return f"Prin aprobare permiti utilizarea {target} in cursa #{trip_id}{suffix}"
    return (
        f"Prin aprobare confirmi aceasta solicitare din Dispecer curse pentru {request_target}{suffix} "
        "Resursa nu este reactivata global."
    )


def build_approval_context(approval: dict[str, Any], documents: list[dict[str, Any]]) -> dict[str, Any]:
    primary_label = first_non_empty(approval.get("resource_label"), resource_type_label(clean(approval.get("resource_type"))))
    problem = document_problem(documents, clean(approval.get("inactive_reason_label")) or "Alt motiv")
    use_date = usage_date(approval)
    inactive_date = first_document_expiry(documents) or approval.get("inactive_since")

    rows: list[tuple[str, str]] = []
    append_row(rows, "Solicitare pentru", operation_title(approval))
    append_row(rows, "Data incarcarii" if clean(approval.get("trip_loading_date")) else "Data utilizarii", format_date_compact(use_date))
    append_row(rows, "Expirat/Inactiv din", format_date_compact(inactive_date))
    append_row(rows, "Depasire", overdue_label(use_date, documents))
    append_row(rows, "Beneficiar", approval.get("trip_beneficiary_name"))
    append_row(rows, "Vehicul cursa", approval.get("trip_vehicle_label"))
    append_row(rows, "Sofer cursa", approval.get("trip_driver_label"))
    append_row(rows, "Loc incarcare", first_non_empty(approval.get("trip_load_location_name"), approval.get("trip_departure_text"), approval.get("trip_suction_text")))
    append_row(rows, "Descarcare / zona", first_non_empty(approval.get("trip_unload_zone_name"), approval.get("trip_delivery_text"), approval.get("trip_closing_location_text")))
    append_row(rows, "Ruta", route_label(approval))
    append_row(rows, "Solicitat de", approval.get("requested_by_name"))
    append_row(rows, "Solicitat la", format_datetime_compact(approval.get("requested_at")))

    return {
        "primary_label": primary_label,
        "problem": problem,
        "operation": operation_title(approval),
        "rows": rows,
        "scope": approval_scope_message(approval, primary_label, problem),
    }


def mailto_link(address: str, subject: str, body: str) -> str:
    return f"mailto:{address}?subject={quote(subject)}&body={quote(body)}"


def build_approval_email(
    approval: dict[str, Any],
    documents: list[dict[str, Any]],
    approve_token: str,
    reject_token: str,
    config: ApprovalConfig,
    recipient_name: str,
) -> tuple[str, str, str]:
    """Returneaza (subject, text_body, html_body).

    Atentie: subiectul NU contine token-ul. Notificarea poate ateriza chiar in
    casuta ascultata de worker, iar un token in subiect ar putea fi confundat cu
    un raspuns. Token-ul apare doar in linkurile mailto.
    """
    context = build_approval_context(approval, documents)
    label = str(context["primary_label"] or approval.get("resource_label") or "-")
    reason = str(approval.get("inactive_reason_label") or "-")
    problem = str(context["problem"] or reason)
    approval_id = int(approval["id"])

    approve_address = tagged_address(config.inbox_address, approve_token)
    reject_address = tagged_address(config.inbox_address, reject_token)
    approve_subject = f"APROB [#{approve_token}]"
    reject_subject = f"RESPING [#{reject_token}]"
    reply_hint = "Trimite acest mesaj asa cum este. Nu modifica subiectul."

    approve_mailto = mailto_link(approve_address, approve_subject, reply_hint)
    reject_mailto = mailto_link(reject_address, reject_subject, reply_hint)

    # Butoanele principale sunt linkuri catre aplicatie: un apas, fara sa mai trimiti email.
    # Linkul deschide o pagina de confirmare - decizia se aplica abia pe POST, pentru ca
    # scanerele de securitate deschid preventiv linkurile din mesaje.
    # Raspunsul pe email ramane ca rezerva: functioneaza si daca aplicatia e inaccesibila.
    has_links = bool(config.app_url)
    approve_url = f"{config.app_url}/index.php?page=aprobare_email&t={approve_token}" if has_links else approve_mailto
    reject_url = f"{config.app_url}/index.php?page=aprobare_email&t={reject_token}" if has_links else reject_mailto

    rows = list(context["rows"])
    scope = str(context["scope"])

    subject = f"Cerere aprobare: {label} ({reason})"

    greeting = f"Salut, {recipient_name}," if recipient_name else "Salut,"
    text_lines = [
        greeting,
        "",
        f"Ai o cerere de aprobare in asteptare pentru {resource_type_label(str(approval.get('resource_type')))}: {label}.",
        f"Motiv: {problem}",
        "",
    ]
    text_lines += [f"{key}: {value}" for key, value in rows]
    text_lines += ["", f"Ce aprob? {scope}"]
    if has_links:
        text_lines += [
            "",
            f"APROBA:  {approve_url}",
            f"RESPINGE: {reject_url}",
            "",
            "Daca linkurile nu se deschid, poti decide si raspunzand la email:",
            f"  aproba  -> {approve_address} (subiect: {approve_subject})",
            f"  respinge -> {reject_address} (subiect: {reject_subject})",
        ]
    else:
        text_lines += [
            "",
            "Ca sa APROBI, raspunde la aceasta adresa:",
            f"  {approve_address}",
            f"  cu subiectul: {approve_subject}",
            "",
            "Ca sa RESPINGI, raspunde la aceasta adresa:",
            f"  {reject_address}",
            f"  cu subiectul: {reject_subject}",
        ]

    text_lines += [
        "",
        f"Valabil {config.token_ttl_days} zile, o singura utilizare.",
        "",
        config.app_name,
    ]
    text_body = "\n".join(text_lines)

    detail_rows = "".join(
        f"""
        <tr>
          <td style="padding:6px 0;color:#64748b;font-size:14px;vertical-align:top;white-space:nowrap;">{escape(key)}</td>
          <td style="padding:6px 0 6px 16px;color:#0f172a;font-size:14px;font-weight:600;">{escape(value)}</td>
        </tr>"""
        for key, value in rows
    )

    if has_links:
        footer_html = f"""<p style="margin:18px 0 0;color:#64748b;font-size:12px;line-height:1.6;">
            Apesi butonul, confirmi pe pagina care se deschide, gata. Nu ai nevoie de cont sau de parola.<br>
            Nu se deschid linkurile? Poti decide si prin email:
            <a href="{escape(approve_mailto)}" style="color:#16a34a;">aproba</a> /
            <a href="{escape(reject_mailto)}" style="color:#dc2626;">respinge</a>.
          </p>"""
    else:
        footer_html = """<p style="margin:18px 0 0;color:#64748b;font-size:12px;line-height:1.6;">
            Butonul deschide aplicatia ta de email cu mesajul deja completat &mdash; trebuie doar sa apesi
            <strong>Send</strong>. Nu ai nevoie de browser sau de cont in aplicatie.<br>
            Confirmarea vine inapoi pe email in cateva minute.
          </p>"""

    html_body = f"""<!doctype html>
<html lang="ro">
<body style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:520px;margin:0 auto;">
    <tr><td>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;">
        <tr><td style="padding:24px;">

          <div style="display:inline-block;padding:5px 12px;border-radius:999px;background:#fee2e2;color:#b91c1c;font-size:12px;font-weight:700;">
            {escape(reason)}
          </div>

          <h1 style="margin:16px 0 4px;font-size:22px;color:#0f172a;">{escape(label)}</h1>
          <p style="margin:0 0 20px;color:#64748b;font-size:13px;">
            {escape(resource_type_label(str(approval.get('resource_type'))))} &middot; cerere #{approval_id}
          </p>

          <p style="margin:0 0 14px;color:#0f172a;font-size:15px;font-weight:700;line-height:1.4;">
            {escape(problem)}
          </p>

          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                 style="border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:8px 0;">
            {detail_rows}
          </table>

          <div style="margin:16px 0 0;padding:12px;border:1px solid #bbf7d0;border-radius:8px;
                      background:#f0fdf4;color:#166534;font-size:13px;font-weight:700;line-height:1.45;">
            <strong>Ce aprob?</strong><br>
            {escape(scope)}
          </div>

          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:24px;">
            <tr>
              <td width="48%" align="center" style="padding-right:6px;">
                <a href="{escape(reject_url)}"
                   style="display:block;padding:14px 8px;border:2px solid #dc2626;border-radius:8px;
                          color:#dc2626;font-size:16px;font-weight:700;text-decoration:none;">Resping</a>
              </td>
              <td width="48%" align="center" style="padding-left:6px;">
                <a href="{escape(approve_url)}"
                   style="display:block;padding:14px 8px;border:2px solid #16a34a;border-radius:8px;
                          background:#16a34a;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;">Aproba</a>
              </td>
            </tr>
          </table>

          {footer_html}

        </td></tr>
      </table>

      <p style="margin:16px 0 0;text-align:center;color:#94a3b8;font-size:11px;line-height:1.6;">
        Valabil {config.token_ttl_days} zile, o singura utilizare.<br>
        Daca nu tu ai cerut asta, ignora mesajul &mdash; nu se intampla nimic.
      </p>
    </td></tr>
  </table>
</body>
</html>"""

    return subject, text_body, html_body


# ---------------------------------------------------------------------------
# SMTP
# ---------------------------------------------------------------------------

def send_mail(
    config: ApprovalConfig,
    to_email: str,
    to_name: str,
    subject: str,
    text_body: str,
    html_body: str | None = None,
    headers: dict[str, str] | None = None,
) -> str:
    if not config.smtp_host or not config.from_address:
        raise RuntimeError("SMTP neconfigurat (MAIL_HOST / MAIL_FROM_ADDRESS).")

    message = EmailMessage()
    message["Subject"] = subject
    message["From"] = formataddr((config.from_name, config.from_address))
    message["To"] = formataddr((to_name, to_email)) if to_name else to_email
    message["Message-ID"] = make_msgid()
    if config.return_path:
        message["Return-Path"] = config.return_path
    for key, value in (headers or {}).items():
        message[key] = value

    message.set_content(text_body)
    if html_body:
        message.add_alternative(html_body, subtype="html")

    context = ssl.create_default_context()
    if config.smtp_encryption == "ssl":
        smtp = smtplib.SMTP_SSL(config.smtp_host, config.smtp_port, timeout=config.smtp_timeout, context=context)
    else:
        smtp = smtplib.SMTP(config.smtp_host, config.smtp_port, timeout=config.smtp_timeout)

    try:
        smtp.ehlo()
        if config.smtp_encryption == "tls":
            smtp.starttls(context=context)
            smtp.ehlo()
        if config.smtp_username:
            smtp.login(config.smtp_username, config.smtp_password)
        refused = smtp.send_message(
            message,
            from_addr=config.return_path or config.from_address,
            to_addrs=[to_email],
        )
    finally:
        try:
            smtp.quit()
        except smtplib.SMTPException:
            smtp.close()

    if refused:
        raise RuntimeError("SMTP a refuzat destinatarii: " + json.dumps(refused, default=str))

    return str(message["Message-ID"])


# ---------------------------------------------------------------------------
# Etapa 1: trimitere
# ---------------------------------------------------------------------------

def store_action(
    conn,
    approval_id: int,
    action: str,
    token: str,
    recipient: dict[str, Any],
    reply_address: str,
    ttl_days: int,
) -> int:
    now = datetime.now()
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO approval_email_actions
            (approval_id, action, token_hash, recipient_user_id, recipient_email,
             reply_address, status, sent_at, expires_at, created_at, updated_at)
        VALUES (%s, %s, %s, %s, %s, %s, 'active', %s, %s, %s, %s)
        """,
        (
            approval_id,
            action,
            hash_token(token),
            recipient.get("id"),
            str(recipient.get("email")),
            reply_address,
            now,
            now + timedelta(days=ttl_days),
            now,
            now,
        ),
    )
    action_id = int(cursor.lastrowid)
    cursor.close()
    return action_id


def send_pending_approvals(conn, config: ApprovalConfig) -> dict[str, Any]:
    summary: dict[str, Any] = {
        "rules": 0, "pending": 0, "sent": 0, "skipped": 0, "errors": [],
    }

    ok, reason = config.configured_for_send
    if not ok:
        summary["skipped_reason"] = reason
        return summary

    rules = fetch_approval_rules(conn)
    if not rules:
        rules = [default_rule()]
        summary["using_default_rule"] = True
    summary["rules"] = len(rules)

    approvals = fetch_pending_approvals(conn, 200)
    summary["pending"] = len(approvals)
    if not approvals:
        return summary

    now = datetime.now()
    documents_cache: dict[int, list[dict[str, Any]]] = {}

    for rule in rules:
        recipients = approval_recipients(conn, config, rule)
        if not recipients:
            summary["errors"].append({
                "rule": rule["name"],
                "error": "Regula nu are niciun destinatar eligibil.",
            })
            continue

        for approval in approvals:
            if summary["sent"] >= config.max_send_per_run:
                summary["skipped_reason"] = (
                    "Limita APPROVAL_MAX_SEND_PER_RUN atinsa; restul se trimit la rularea urmatoare."
                )
                return summary

            if not rule_matches(approval, rule):
                continue

            approval_id = int(approval["id"])
            if approval_id not in documents_cache:
                documents_cache[approval_id] = fetch_approval_documents(conn, approval_id)
            documents = documents_cache[approval_id]

            for recipient in recipients:
                recipient_email = str(recipient.get("email") or "").strip()
                if not recipient_email:
                    summary["skipped"] += 1
                    continue

                if not should_send(last_sent_at(conn, approval_id, recipient_email), approval, rule, now):
                    summary["skipped"] += 1
                    continue

                approve_token = generate_token()
                reject_token = generate_token()
                subject, text_body, html_body = build_approval_email(
                    approval, documents, approve_token, reject_token, config,
                    str(recipient.get("nume") or ""),
                )

                try:
                    store_action(conn, approval_id, "approve", approve_token, recipient,
                                 tagged_address(config.inbox_address, approve_token), config.token_ttl_days)
                    store_action(conn, approval_id, "reject", reject_token, recipient,
                                 tagged_address(config.inbox_address, reject_token), config.token_ttl_days)

                    send_mail(
                        config,
                        recipient_email,
                        str(recipient.get("nume") or ""),
                        subject,
                        text_body,
                        html_body,
                        headers={REQUEST_HEADER: str(approval_id)},
                    )
                    conn.commit()
                    summary["sent"] += 1
                except Exception as exc:
                    conn.rollback()
                    summary["errors"].append({
                        "approval_id": approval_id, "rule": rule["name"], "error": str(exc),
                    })

    return summary


# ---------------------------------------------------------------------------
# Etapa 2: citire inbox si aplicare decizie
# ---------------------------------------------------------------------------

def decoded_header(raw: Any) -> str:
    if not raw:
        return ""
    try:
        return str(make_header(decode_header(str(raw))))
    except Exception:
        return str(raw)


def log_inbox_message(
    conn,
    message_id: str | None,
    from_email: str | None,
    to_address: str | None,
    subject: str | None,
    token_found: bool,
    action_id: int | None,
    outcome: str,
    detail: str | None,
) -> bool:
    """Scrie in jurnal. False daca Message-ID exista deja (mesaj procesat anterior)."""
    now = datetime.now()
    cursor = conn.cursor()
    try:
        cursor.execute(
            """
            INSERT INTO approval_email_inbox_log
                (message_id, from_email, to_address, subject, token_found,
                 action_id, outcome, detail, received_at, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                (message_id or None), from_email, to_address,
                (subject or "")[:255], 1 if token_found else 0,
                action_id, outcome, (detail or "")[:500] or None, now, now,
            ),
        )
        return True
    except mysql.connector.IntegrityError:
        return False
    finally:
        cursor.close()


def find_action_by_token(conn, token: str) -> dict[str, Any] | None:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT ea.*, a.status AS approval_status, a.resource_label
        FROM approval_email_actions ea
        INNER JOIN inactive_resource_approvals a ON a.id = ea.approval_id
        WHERE ea.token_hash = %s
        """,
        (hash_token(token),),
    )
    row = cursor.fetchone()
    cursor.close()
    return row


def close_action(conn, action_id: int, status: str, from_email: str, message_id: str, note: str) -> None:
    now = datetime.now()
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE approval_email_actions
        SET status = %s, used_at = %s, used_from_email = %s,
            used_message_id = %s, result_note = %s, updated_at = %s
        WHERE id = %s
        """,
        (status, now, from_email[:190], message_id[:255], note[:255], now, int(action_id)),
    )
    cursor.close()


def apply_decision(conn, action: dict[str, Any], from_email: str) -> tuple[bool, str]:
    """Aplica decizia pe cerere. Returneaza (aplicat, mesaj)."""
    approval_id = int(action["approval_id"])
    target_status = "approved" if action["action"] == "approve" else "rejected"
    note = f"{'Aprobat' if target_status == 'approved' else 'Respins'} prin email de {from_email}."
    now = datetime.now()

    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE inactive_resource_approvals
        SET status = %s,
            reviewed_by_user_id = %s,
            reviewed_at = %s,
            review_note = %s,
            updated_at = %s
        WHERE id = %s
          AND status = 'pending'
        """,
        (target_status, action.get("recipient_user_id"), now, note, now, approval_id),
    )
    changed = cursor.rowcount
    cursor.close()

    if changed == 0:
        return False, f"Cererea nu mai era in asteptare (stare curenta: {action.get('approval_status')})."

    # Restul token-urilor pentru aceeasi cerere devin inutile.
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE approval_email_actions
        SET status = 'expired', updated_at = %s,
            result_note = COALESCE(result_note, 'Cerere decisa intre timp.')
        WHERE approval_id = %s AND status = 'active' AND id <> %s
        """,
        (now, approval_id, int(action["id"])),
    )
    cursor.close()

    return True, note


def send_reply_receipt(config: ApprovalConfig, to_email: str, label: str, ok: bool, message: str) -> None:
    icon = "OK" if ok else "ATENTIE"
    subject = f"[{icon}] {label}"
    body = f"{message}\n\n{config.app_name}"
    try:
        send_mail(config, to_email, "", subject, body)
    except Exception:
        # Confirmarea este optionala: decizia s-a aplicat deja, nu o anulam pentru un mail esuat.
        pass


def process_inbox_message(conn, config: ApprovalConfig, raw_bytes: bytes) -> str:
    message = email.message_from_bytes(raw_bytes)

    # Mesaj trimis chiar de noi (notificarea originala), nu un raspuns.
    if message.get(REQUEST_HEADER):
        return "ignored_own_notification"

    message_id = str(message.get("Message-ID") or "").strip()
    subject = decoded_header(message.get("Subject"))
    from_email = parseaddr(str(message.get("From") or ""))[1].strip().lower()

    address_fields = [
        str(message.get("Delivered-To") or ""),
        str(message.get("X-Original-To") or ""),
        str(message.get("To") or ""),
        str(message.get("Cc") or ""),
    ]
    to_address = next((a for a in address_fields if a), "")

    token = extract_token(address_fields + [subject])
    if not token:
        # Corespondenta obisnuita: nu o jurnalizam, ca sa nu ajunga subiecte
        # personale in baza de date fara niciun rost.
        return "no_token"

    if not log_inbox_message(conn, message_id, from_email, to_address, subject,
                             True, None, "processing", None):
        conn.rollback()
        return "duplicate_message"

    outcome, action_id, detail = decide_from_token(conn, config, token, from_email, message_id)
    update_inbox_log(conn, message_id, outcome, action_id, detail)
    conn.commit()
    return outcome


def update_inbox_log(conn, message_id: str, outcome: str, action_id: int | None, detail: str | None) -> None:
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE approval_email_inbox_log
        SET outcome = %s, action_id = %s, detail = %s
        WHERE message_id = %s
        """,
        (outcome, action_id, (detail or "")[:500] or None, message_id),
    )
    cursor.close()


def decide_from_token(
    conn,
    config: ApprovalConfig,
    token: str,
    from_email: str,
    message_id: str,
) -> tuple[str, int | None, str | None]:
    """Valideaza token-ul si aplica decizia. Returneaza (outcome, action_id, detaliu).

    Nu face commit: apelantul decide cand incheie tranzactia.
    """
    action = find_action_by_token(conn, token)
    if action is None:
        return "unknown_token", None, "Token inexistent."

    action_id = int(action["id"])
    expected_email = str(action["recipient_email"]).strip().lower()
    label = str(action.get("resource_label") or f"cerere #{action['approval_id']}")

    # Token-ul este o cheie la purtator: verificam ca raspunsul vine chiar de la aprobator.
    if from_email != expected_email:
        detail = f"Expeditor neasteptat: {from_email}"
        close_action(conn, action_id, "refused", from_email, message_id, detail)
        send_reply_receipt(config, expected_email, label, False,
                           f"O incercare de decizie pentru '{label}' a venit de la {from_email}, "
                           f"adresa care nu corespunde aprobatorului. Cererea a ramas neschimbata.")
        return "sender_mismatch", action_id, detail

    if str(action["status"]) != "active":
        send_reply_receipt(config, from_email, label, False,
                           f"Linkul pentru '{label}' a fost deja folosit sau nu mai este valabil. "
                           f"Cererea nu a fost modificata.")
        return "token_not_active", action_id, f"Stare token: {action['status']}."

    if action["expires_at"] and datetime.now() > action["expires_at"]:
        close_action(conn, action_id, "expired", from_email, message_id, "Token expirat.")
        send_reply_receipt(config, from_email, label, False,
                           f"Linkul pentru '{label}' a expirat. Deschide aplicatia ca sa decizi.")
        return "token_expired", action_id, "Token expirat."

    applied, note = apply_decision(conn, action, from_email)
    close_action(conn, action_id, "used" if applied else "expired", from_email, message_id, note)
    send_reply_receipt(config, from_email, label, applied, note)
    return ("applied" if applied else "already_decided"), action_id, note


TAG_FOLDER_PATTERN = re.compile(
    r"^" + ADDRESS_TAG_PREFIX + r"[" + TOKEN_ALPHABET + r"]{%d}$" % TOKEN_LENGTH,
    re.IGNORECASE,
)

LIST_LINE = re.compile(r'^\([^)]*\)\s+(?:"[^"]*"|NIL)\s+(.+)$')


def list_mailboxes(imap: imaplib.IMAP4) -> list[str]:
    status, data = imap.list()
    if status != "OK":
        return []

    names: list[str] = []
    for raw in data or []:
        if not raw:
            continue
        line = raw.decode(errors="replace") if isinstance(raw, bytes) else str(raw)
        match = LIST_LINE.match(line.strip())
        if not match:
            continue
        name = match.group(1).strip()
        if len(name) > 1 and name.startswith('"') and name.endswith('"'):
            name = name[1:-1]
        names.append(name)
    return names


def tag_folders(names: list[str]) -> list[str]:
    """Foldere create automat de furnizor pentru sub-addressing.

    Migadu livreaza mesajele trimise la adresa+tag@domeniu intr-un folder numit
    dupa tag, nu in INBOX. Fiecare token primeste astfel propriul folder.
    """
    return [name for name in names if TAG_FOLDER_PATTERN.match(name.rsplit("/", 1)[-1])]


def poll_inbox(conn, config: ApprovalConfig) -> dict[str, Any]:
    summary: dict[str, Any] = {"fetched": 0, "folders": [], "outcomes": {}, "errors": []}

    ok, reason = config.configured_for_poll
    if not ok:
        summary["skipped_reason"] = reason
        return summary

    context = ssl.create_default_context()
    imap = imaplib.IMAP4_SSL(config.imap_host, config.imap_port, ssl_context=context)
    try:
        imap.login(config.imap_username, config.imap_password)

        # In INBOX ne uitam doar la mesajele necitite, ca sa nu reprocesam corespondenta veche.
        # In folderele de tag citim tot: sunt dedicate unui singur token, iar mesajul poate fi
        # deja marcat citit daca l-ai deschis pe telefon.
        targets: list[tuple[str, str, bool]] = [(config.imap_folder, "UNSEEN", False)]
        for name in tag_folders(list_mailboxes(imap)):
            targets.append((name, "ALL", True))

        for folder_name, criteria, is_tag_folder in targets:
            try:
                status, _ = imap.select(folder_name)
                if status != "OK":
                    continue

                status, data = imap.search(None, criteria)
                if status != "OK":
                    summary["errors"].append({"folder": folder_name, "error": f"IMAP search: {status}"})
                    imap.close()
                    continue

                message_numbers = (data[0] or b"").split()
                if message_numbers:
                    summary["folders"].append({"folder": folder_name, "messages": len(message_numbers)})
                summary["fetched"] += len(message_numbers)

                for number in message_numbers:
                    try:
                        status, payload = imap.fetch(number, "(RFC822)")
                        if status != "OK" or not payload or not isinstance(payload[0], tuple):
                            continue

                        outcome = process_inbox_message(conn, config, payload[0][1])
                        summary["outcomes"][outcome] = summary["outcomes"].get(outcome, 0) + 1

                        if is_tag_folder:
                            # Folderul exista doar pentru acest token; mesajul procesat se sterge.
                            imap.store(number, "+FLAGS", "\\Deleted")
                        elif outcome in {"no_token", "ignored_own_notification"}:
                            # Nu ascundem corespondenta reala din casuta.
                            imap.store(number, "-FLAGS", "\\Seen")
                        else:
                            imap.store(number, "+FLAGS", "\\Seen")
                    except Exception as exc:
                        conn.rollback()
                        summary["errors"].append({
                            "folder": folder_name,
                            "message": number.decode(errors="replace"),
                            "error": str(exc),
                        })

                if is_tag_folder:
                    imap.expunge()
                imap.close()

                # Folderul de tag ramas gol nu mai are rost; daca stergerea nu merge,
                # ramane un folder gol - inofensiv.
                if is_tag_folder:
                    try:
                        imap.delete(folder_name)
                    except Exception:
                        pass
            except Exception as exc:
                summary["errors"].append({"folder": folder_name, "error": str(exc)})
    finally:
        try:
            imap.logout()
        except Exception:
            pass

    return summary


def expire_stale_tokens(conn) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE approval_email_actions
        SET status = 'expired', updated_at = NOW(),
            result_note = COALESCE(result_note, 'Expirat automat.')
        WHERE status = 'active' AND expires_at < NOW()
        """
    )
    changed = cursor.rowcount
    cursor.close()
    return int(changed)


# ---------------------------------------------------------------------------
# Rulare
# ---------------------------------------------------------------------------

def run(send: bool = True, poll: bool = True) -> dict[str, Any]:
    config = approval_config()
    result: dict[str, Any] = {
        "checked_at": datetime.now().isoformat(timespec="seconds"),
        "enabled": config.enabled,
    }

    if not config.enabled:
        result["skipped_reason"] = "APPROVAL_EMAIL_ENABLED nu este activat."
        return result

    conn = connect_db()
    try:
        result["expired_tokens"] = expire_stale_tokens(conn)
        conn.commit()
        if send:
            result["send"] = send_pending_approvals(conn, config)
        if poll:
            result["poll"] = poll_inbox(conn, config)
    finally:
        conn.close()

    return result


def preview() -> dict[str, Any]:
    """Arata ce s-ar trimite, fara sa trimita nimic si fara sa scrie in baza de date."""
    config = approval_config()
    conn = connect_db()
    try:
        send_ok, send_reason = config.configured_for_send
        poll_ok, poll_reason = config.configured_for_poll

        rules = fetch_approval_rules(conn)
        using_default = not rules
        if using_default:
            rules = [default_rule()]

        approvals = fetch_pending_approvals(conn, 200)
        now = datetime.now()
        planned = []

        for rule in rules:
            recipients = approval_recipients(conn, config, rule)
            targets = []
            for approval in approvals:
                if not rule_matches(approval, rule):
                    continue
                due = [
                    str(r.get("email"))
                    for r in recipients
                    if should_send(last_sent_at(conn, int(approval["id"]), str(r.get("email") or "")),
                                   approval, rule, now)
                ]
                if due:
                    targets.append({
                        "id": approval["id"],
                        "label": approval["resource_label"],
                        "reason": approval["inactive_reason_label"],
                        "catre": due,
                    })

            planned.append({
                "regula": rule["name"],
                "destinatari": [r.get("email") for r in recipients],
                "filtre": {"tip_resursa": rule["resource_types"] or "toate",
                           "motiv": rule["reasons"] or "toate"},
                "repeta": f"la {rule['repeat_days']} zile" if rule["repeat"] else "nu",
                "de_trimis": targets,
            })

        return {
            "enabled": config.enabled,
            "can_send": send_ok or send_reason,
            "can_poll": poll_ok or poll_reason,
            "inbox_address": config.inbox_address,
            "force_recipient": config.force_recipient or None,
            "foloseste_regula_implicita": using_default,
            "pending_total": len(approvals),
            "reguli": planned,
        }
    finally:
        conn.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Aprobare prin email pentru cererile de flota")
    parser.add_argument("--preview", action="store_true", help="Arata ce s-ar trimite, fara sa trimita")
    parser.add_argument("--send-only", action="store_true", help="Doar trimite cererile noi")
    parser.add_argument("--poll-only", action="store_true", help="Doar citeste casuta si aplica deciziile")
    args = parser.parse_args()

    try:
        if args.preview:
            result = preview()
        else:
            result = run(send=not args.poll_only, poll=not args.send_only)
        print(json.dumps(result, ensure_ascii=False, default=str))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
