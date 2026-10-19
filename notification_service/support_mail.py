#!/usr/bin/env python3
"""Suportul pentru soferi prin email: tichetele create pe WhatsApp si raspunsurile echipei.

Trei etape, rulate de acelasi scheduler ca worker.py (sau separat, din CLI):

  send       Tichetele noi (driver_support_tickets, email_status = 'pending') pleaca pe email la
             SUPPORT_EMAIL_TO. Message-ID-ul se salveaza INAINTE de trimitere, ca o reincercare
             sa pastreze acelasi fir; Reply-To este casuta de suport cu tag-ul tichetului
             (inbox+sup-000123@domeniu). Dupa trimitere, soferul primeste confirmarea pe WhatsApp
             (mesajul "held" din whatsapp_outbox, pregatit de Fleet, este eliberat).
  followups  Mesajele noi ale soferului pe un tichet deschis pleaca in acelasi fir (In-Reply-To).
  poll       Raspunsurile echipei din casuta de suport (implicit casuta de aprobari, separata
             prin tag-ul +sup-NNNNNN) -> mesaj WhatsApp catre soferul tichetului, prin BaileyBot.

Corelare: In-Reply-To / References cu Message-ID-urile trimise de noi, apoi tag-ul din adresa,
apoi #SUP-NNNNNN din subiect. Doar expeditorii din SUPPORT_REPLY_ALLOWED_SENDERS (implicit
SUPPORT_EMAIL_TO) pot raspunde. Raspunsurile automate, bounce-urile si propriile emailuri se
ignora; fiecare email se proceseaza o singura data (UNIQUE pe Message-ID).

Configurare (.env): SUPPORT_EMAIL_ENABLED, SUPPORT_EMAIL_TO, SUPPORT_REPLY_ALLOWED_SENDERS,
SUPPORT_EMAIL_MAX_ATTEMPTS, SUPPORT_REPLY_LOOKBACK_DAYS si, optional, SUPPORT_INBOX_ADDRESS /
SUPPORT_INBOX_IMAP_HOST / _PORT / _USERNAME / _PASSWORD / _FOLDER (implicit: APPROVAL_INBOX_*).
SMTP: setarile existente MAIL_* (aceleasi ca approval_flow.py).
"""
from __future__ import annotations

import argparse
import email
import hashlib
import imaplib
import json
import re
import ssl
import sys
from dataclasses import dataclass, replace
from datetime import datetime, timedelta
from email.message import Message
from email.utils import getaddresses, make_msgid, parseaddr
from html import unescape
from html.parser import HTMLParser
from typing import Any

import approval_flow as af

# Header pus pe emailurile trimise de noi: daca il gasim in casuta, e chiar emailul nostru.
TICKET_HEADER = "X-Fleet-Support-Ticket"
TAG_PREFIX = "sup-"
REFERENCE_PATTERN = re.compile(r"SUP-(\d{6})\b", re.IGNORECASE)
TAG_IN_ADDRESS = re.compile(r"\+" + TAG_PREFIX + r"(\d{6})@", re.IGNORECASE)
TAG_FOLDER = re.compile(r"^" + TAG_PREFIX + r"\d{6}$", re.IGNORECASE)
MESSAGE_ID_TOKEN = re.compile(r"<[^<>\s]+>")

MAX_FORWARD_CHARS = 1500
SENDING_STALE_MINUTES = 15


@dataclass(frozen=True)
class SupportConfig:
    enabled: bool
    mail: af.ApprovalConfig          # SMTP-ul aplicatiei (MAIL_*) + casuta citita (suprascrisa mai jos)
    to_addresses: tuple[str, ...]
    allowed_senders: tuple[str, ...]
    inbox_address: str
    max_attempts: int
    lookback_days: int

    @property
    def problem(self) -> str | None:
        if not self.enabled:
            return "SUPPORT_EMAIL_ENABLED nu este activat."
        if not self.to_addresses:
            return "SUPPORT_EMAIL_TO lipseste."
        if not self.mail.smtp_host or not self.mail.from_address:
            return "SMTP neconfigurat (MAIL_HOST / MAIL_FROM_ADDRESS)."
        if "@" not in self.inbox_address:
            return "Casuta de suport lipseste (SUPPORT_INBOX_ADDRESS sau APPROVAL_INBOX_ADDRESS)."
        return None

    def reply_to(self, reference: str) -> str:
        """inbox+sup-000123@domeniu: Migadu livreaza raspunsul intr-un folder al tichetului."""
        local, _, domain = self.inbox_address.partition("@")
        number = reference.split("-", 1)[-1]
        return f"{local}+{TAG_PREFIX}{number}@{domain}"


def _addresses(raw: str) -> tuple[str, ...]:
    return tuple(a.strip().lower() for a in raw.replace(";", ",").split(",") if "@" in a)


def support_config() -> SupportConfig:
    base = af.approval_config()
    inbox_address = af.env_value(["SUPPORT_INBOX_ADDRESS"], base.inbox_address)
    mail = replace(
        base,
        inbox_address=inbox_address,
        imap_host=af.env_value(["SUPPORT_INBOX_IMAP_HOST"], base.imap_host),
        imap_port=af.int_env(["SUPPORT_INBOX_IMAP_PORT"], base.imap_port),
        imap_username=af.env_value(["SUPPORT_INBOX_USERNAME"], base.imap_username),
        imap_password=af.env_value(["SUPPORT_INBOX_PASSWORD"], base.imap_password),
        imap_folder=af.env_value(["SUPPORT_INBOX_FOLDER"], base.imap_folder),
    )
    to_addresses = _addresses(af.env_value(["SUPPORT_EMAIL_TO"]))
    allowed = _addresses(af.env_value(["SUPPORT_REPLY_ALLOWED_SENDERS"])) or to_addresses
    return SupportConfig(
        enabled=af.bool_env(["SUPPORT_EMAIL_ENABLED"], False),
        mail=mail,
        to_addresses=to_addresses,
        allowed_senders=allowed,
        inbox_address=inbox_address,
        max_attempts=max(1, af.int_env(["SUPPORT_EMAIL_MAX_ATTEMPTS"], 5)),
        lookback_days=max(1, af.int_env(["SUPPORT_REPLY_LOOKBACK_DAYS"], 14)),
    )


# ---------------------------------------------------------------------------
# Continutul emailului primit
# ---------------------------------------------------------------------------

class _TextExtractor(HTMLParser):
    BLOCK = {"p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "table"}
    SKIP = {"script", "style", "head", "title"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self.skip_depth = 0
        self.quote_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        classes = " ".join(v or "" for k, v in attrs if k == "class")
        if tag in self.SKIP:
            self.skip_depth += 1
        # Citatul din raspunsurile Gmail / Outlook: nu il trimitem mai departe.
        if tag == "blockquote" or "gmail_quote" in classes or "OutlookMessageHeader" in classes or ("id", "divRplyFwdMsg") in attrs:
            self.quote_depth += 1
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in self.SKIP and self.skip_depth:
            self.skip_depth -= 1
        if (tag == "blockquote" or tag == "div") and self.quote_depth:
            # Un div inchis poate fi chiar citatul; inchidem cel mult un nivel per tag.
            if tag == "blockquote":
                self.quote_depth -= 1
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if not self.skip_depth and not self.quote_depth:
            self.parts.append(data)


def html_to_text(html: str) -> str:
    parser = _TextExtractor()
    try:
        parser.feed(html)
        parser.close()
    except Exception:
        return unescape(re.sub(r"<[^>]+>", " ", html))
    return "".join(parser.parts)


def message_text(message: Message) -> str:
    """Textul scris de om: partea text/plain, altfel HTML-ul convertit. Fara atasamente."""
    plain, html = None, None
    for part in message.walk() if message.is_multipart() else [message]:
        if part.is_multipart() or part.get_content_disposition() == "attachment":
            continue
        ctype = part.get_content_type()
        try:
            payload = part.get_payload(decode=True) or b""
            text = payload.decode(part.get_content_charset() or "utf-8", errors="replace")
        except Exception:
            continue
        if ctype == "text/plain" and plain is None:
            plain = text
        elif ctype == "text/html" and html is None:
            html = text
    if plain is not None and plain.strip():
        return plain
    return html_to_text(html) if html else ""


_CUT_LINES = [
    re.compile(r"^\s*>"),
    re.compile(r"^\s*-{2,}\s*(original message|mesaj original|forwarded message|mesaj redirec\w*)\s*-{2,}", re.IGNORECASE),
    re.compile(r"^\s*_{8,}\s*$"),
    re.compile(r"^\s*(from|de la|von|de)\s*:\s*\S", re.IGNORECASE),
    re.compile(r"^\s*(sent from my|trimis de pe|trimis de la|get outlook for)\b", re.IGNORECASE),
    re.compile(r"^--\s*$"),
]
_WROTE = re.compile(r"\b(wrote|a scris|scrie|schrieb|a écrit|escribió)\s*:\s*$", re.IGNORECASE)
_WROTE_START = re.compile(r"^\s*(on|pe|în data de|in data de|la|am|le|el)\b", re.IGNORECASE)


def strip_quoted(text: str) -> str:
    """Pastreaza doar raspunsul nou: taie la citat ("On ... wrote:", "> "), la semnatura ("-- ")
    sau la antetul mesajului citat (Outlook "From:" / "De la:")."""
    lines = text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    for index, line in enumerate(lines):
        if any(p.search(line) for p in _CUT_LINES):
            return "\n".join(lines[:index])
        if _WROTE.search(line):
            # Gmail rupe "On <data> <nume> <adresa>\nwrote:" pe doua randuri.
            start = index
            if not _WROTE_START.search(line) and index > 0 and _WROTE_START.search(lines[index - 1]):
                start = index - 1
            return "\n".join(lines[:start])
    return "\n".join(lines)


def sanitize(text: str) -> str:
    text = re.sub(r"[​-‏ -‮⁠﻿]", "", text)
    text = "".join(ch for ch in text if ch == "\n" or ch == "\t" or ord(ch) >= 32)
    text = "\n".join(line.rstrip() for line in text.split("\n"))
    text = re.sub(r"\n{3,}", "\n\n", text).strip()
    if len(text) > MAX_FORWARD_CHARS:
        text = text[:MAX_FORWARD_CHARS].rstrip() + " …"
    return text


AUTO_SUBJECTS = re.compile(
    r"^\s*(automatic reply|auto(matic)?[- ]?reply|out of (the )?office|r[aă]spuns automat|absent|"
    r"undeliver|delivery status notification|mail delivery (failed|subsystem)|returned mail|failure notice)",
    re.IGNORECASE,
)


def is_automatic(message: Message, from_email: str, subject: str) -> bool:
    auto_submitted = str(message.get("Auto-Submitted") or "no").strip().lower()
    precedence = str(message.get("Precedence") or "").strip().lower()
    local = from_email.split("@", 1)[0]
    return (
        auto_submitted != "no"
        or precedence in {"bulk", "junk", "list", "auto_reply"}
        or bool(message.get("X-Autoreply") or message.get("X-Autorespond") or message.get("X-Auto-Response-Suppress") and "oof" in str(message.get("X-Auto-Response-Suppress")).lower())
        or message.get_content_type() == "multipart/report"
        or local in {"mailer-daemon", "postmaster", "no-reply", "noreply"}
        or bool(AUTO_SUBJECTS.search(subject))
    )


def referenced_ids(message: Message) -> list[str]:
    raw = " ".join(str(message.get(h) or "") for h in ("In-Reply-To", "References"))
    return MESSAGE_ID_TOKEN.findall(raw)


def reference_from_headers(message: Message, subject: str) -> str | None:
    fields = " ".join(str(message.get(h) or "") for h in ("Delivered-To", "X-Original-To", "To", "Cc"))
    match = TAG_IN_ADDRESS.search(fields) or REFERENCE_PATTERN.search(subject)
    return f"SUP-{match.group(1)}" if match else None


# ---------------------------------------------------------------------------
# Baza de date
# ---------------------------------------------------------------------------

def _rows(conn, sql: str, params: tuple = ()) -> list[dict[str, Any]]:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(sql, params)
    rows = cursor.fetchall()
    cursor.close()
    return rows


def _exec(conn, sql: str, params: tuple = ()) -> int:
    cursor = conn.cursor()
    cursor.execute(sql, params)
    count = cursor.rowcount
    last_id = cursor.lastrowid
    cursor.close()
    return last_id if sql.lstrip().upper().startswith("INSERT") and count == 1 else count


def release_driver_ack(conn, ticket_id: int, email_ok: bool) -> None:
    """Elibereaza confirmarea potrivita catre sofer (pregatita de Fleet) si o anuleaza pe cealalta."""
    keep, drop = (f"support_ack:{ticket_id}", f"support_ack_failed:{ticket_id}")
    if not email_ok:
        keep, drop = drop, keep
    _exec(conn, "UPDATE whatsapp_outbox SET status = 'pending', next_attempt_at = NOW(), updated_at = NOW() WHERE dedupe_key = %s AND status = 'held'", (keep,))
    _exec(conn, "UPDATE whatsapp_outbox SET status = 'cancelled', updated_at = NOW() WHERE dedupe_key = %s AND status = 'held'", (drop,))


# ---------------------------------------------------------------------------
# Etapa 1: tichetele noi -> email
# ---------------------------------------------------------------------------

def ticket_email(config: SupportConfig, ticket: dict[str, Any]) -> tuple[str, str]:
    reference = ticket["reference"]
    subject = f"[Fleet App Support][{reference}] Problemă raportată de șofer"
    created = ticket["created_at"].strftime("%d.%m.%Y %H:%M") if isinstance(ticket["created_at"], datetime) else str(ticket["created_at"])
    lines = [
        f"Tichet: #{reference}",
        f"Șofer: {ticket.get('driver_name') or '-'}",
        f"ID șofer în Fleet App: {ticket['driver_id']}",
        f"Vehicul: {ticket.get('plate') or 'nealocat / necunoscut'}",
        f"Data și ora: {created}",
        f"Canal: WhatsApp (ID mesaj {ticket.get('source_wa_message_id') or '-'})",
        "Status: deschis",
        "",
        "Descrierea problemei (mesajul WhatsApp al șoferului):",
        str(ticket["description"]).strip(),
        "",
        "—",
        "Răspundeți la acest email (Reply): textul scris de dumneavoastră deasupra citatului",
        "va fi trimis șoferului pe WhatsApp, în conversația privată.",
    ]
    return subject, "\n".join(lines)


def send_new_tickets(conn, config: SupportConfig, limit: int = 10) -> dict[str, Any]:
    summary: dict[str, Any] = {"sent": 0, "retry": 0, "failed": 0, "errors": []}

    # Un "sending" ramas de la o rulare oprita la mijloc se reia (acelasi Message-ID).
    _exec(conn, "UPDATE driver_support_tickets SET email_status = 'pending' WHERE email_status = 'sending' AND updated_at < %s",
          (datetime.now() - timedelta(minutes=SENDING_STALE_MINUTES),))
    conn.commit()

    tickets = _rows(conn, """
        SELECT t.*, s.nume AS driver_name, v.nr_inmatriculare AS plate
        FROM driver_support_tickets t
        LEFT JOIN soferi s ON s.id = t.driver_id
        LEFT JOIN vehicule v ON v.id = t.vehicle_id
        WHERE t.email_status = 'pending' AND t.reference IS NOT NULL
          AND (t.email_next_attempt_at IS NULL OR t.email_next_attempt_at <= NOW())
        ORDER BY t.id ASC LIMIT %s
    """, (limit,))

    for ticket in tickets:
        ticket_id = int(ticket["id"])
        # Revendicare atomica: doua rulari simultane nu trimit acelasi tichet.
        if _exec(conn, "UPDATE driver_support_tickets SET email_status = 'sending', email_attempts = email_attempts + 1, updated_at = NOW() WHERE id = %s AND email_status = 'pending'", (ticket_id,)) != 1:
            conn.rollback()
            continue
        message_id = ticket.get("email_message_id")
        if not message_id:
            domain = config.mail.from_address.partition("@")[2] or "fleet.local"
            message_id = make_msgid(idstring=ticket["reference"].lower(), domain=domain)
            _exec(conn, "UPDATE driver_support_tickets SET email_message_id = %s WHERE id = %s", (message_id, ticket_id))
        conn.commit()

        attempts = int(ticket["email_attempts"]) + 1
        subject, body = ticket_email(config, ticket)
        headers = {
            "Reply-To": config.reply_to(ticket["reference"]),
            TICKET_HEADER: ticket["reference"],
            # RFC 3834: raspunsurile automate (concediu etc.) nu se trimit la acest email.
            "Auto-Submitted": "auto-generated",
        }
        try:
            for address in config.to_addresses:
                af.send_mail(config.mail, address, "", subject, body, headers=headers, message_id=message_id)
        except Exception as exc:
            error = str(exc)[:250]
            final = attempts >= config.max_attempts
            _exec(conn, """
                UPDATE driver_support_tickets
                SET email_status = %s, email_last_error = %s, email_next_attempt_at = %s, updated_at = NOW()
                WHERE id = %s
            """, ("failed" if final else "pending", error, datetime.now() + timedelta(minutes=min(60, 5 * attempts)), ticket_id))
            if final:
                release_driver_ack(conn, ticket_id, email_ok=False)
            conn.commit()
            summary["failed" if final else "retry"] += 1
            summary["errors"].append({"ticket": ticket["reference"], "error": error})
            continue

        _exec(conn, "UPDATE driver_support_tickets SET email_status = 'sent', email_sent_at = NOW(), email_last_error = NULL, updated_at = NOW() WHERE id = %s", (ticket_id,))
        _exec(conn, """
            UPDATE driver_support_messages SET delivery_status = 'sent', delivered_at = NOW(), email_message_id = %s, attempts = attempts + 1
            WHERE ticket_id = %s AND direction = 'whatsapp_to_email' AND source_message_id = %s
        """, (message_id, ticket_id, ticket.get("source_wa_message_id") or ""))
        release_driver_ack(conn, ticket_id, email_ok=True)
        conn.commit()
        summary["sent"] += 1

    return summary


# ---------------------------------------------------------------------------
# Etapa 2: mesajele noi ale soferului pe un tichet -> acelasi fir de email
# ---------------------------------------------------------------------------

def send_followups(conn, config: SupportConfig, limit: int = 10) -> dict[str, Any]:
    summary: dict[str, Any] = {"sent": 0, "failed": 0, "errors": []}
    rows = _rows(conn, """
        SELECT m.*, t.reference, t.email_message_id AS ticket_message_id, s.nume AS driver_name
        FROM driver_support_messages m
        JOIN driver_support_tickets t ON t.id = m.ticket_id
        LEFT JOIN soferi s ON s.id = t.driver_id
        WHERE m.direction = 'whatsapp_to_email' AND m.delivery_status = 'pending'
          AND t.email_status = 'sent' AND m.source_message_id <> COALESCE(t.source_wa_message_id, '')
          AND m.attempts < %s
        ORDER BY m.id ASC LIMIT %s
    """, (config.max_attempts, limit))

    for row in rows:
        message_id = row.get("email_message_id")
        if not message_id:
            domain = config.mail.from_address.partition("@")[2] or "fleet.local"
            message_id = make_msgid(idstring=row["reference"].lower(), domain=domain)
            _exec(conn, "UPDATE driver_support_messages SET email_message_id = %s WHERE id = %s", (message_id, row["id"]))
            conn.commit()
        subject = f"Re: [Fleet App Support][{row['reference']}] Mesaj nou de la șofer"
        body = "\n".join([
            f"Tichet: #{row['reference']}",
            f"Șofer: {row.get('driver_name') or '-'}",
            "",
            "Mesaj nou primit pe WhatsApp:",
            str(row.get("content") or "").strip(),
            "",
            "—",
            "Răspundeți la acest email: textul nou va fi trimis șoferului pe WhatsApp.",
        ])
        headers = {
            "Reply-To": config.reply_to(row["reference"]),
            TICKET_HEADER: row["reference"],
            "Auto-Submitted": "auto-generated",
            "In-Reply-To": row["ticket_message_id"],
            "References": row["ticket_message_id"],
        }
        try:
            for address in config.to_addresses:
                af.send_mail(config.mail, address, "", subject, body, headers=headers, message_id=message_id)
        except Exception as exc:
            _exec(conn, "UPDATE driver_support_messages SET attempts = attempts + 1, last_error = %s, delivery_status = IF(attempts + 1 >= %s, 'failed', 'pending') WHERE id = %s",
                  (str(exc)[:250], config.max_attempts, row["id"]))
            conn.commit()
            summary["failed"] += 1
            summary["errors"].append({"ticket": row["reference"], "error": str(exc)[:250]})
            continue
        _exec(conn, "UPDATE driver_support_messages SET delivery_status = 'sent', delivered_at = NOW(), attempts = attempts + 1 WHERE id = %s", (row["id"],))
        conn.commit()
        summary["sent"] += 1
    return summary


# ---------------------------------------------------------------------------
# Etapa 3: raspunsurile echipei -> WhatsApp
# ---------------------------------------------------------------------------

def find_ticket(conn, message: Message, subject: str) -> dict[str, Any] | None:
    ids = referenced_ids(message)
    if ids:
        placeholders = ", ".join(["%s"] * len(ids))
        rows = _rows(conn, f"""
            SELECT t.* FROM driver_support_tickets t WHERE t.email_message_id IN ({placeholders})
            UNION
            SELECT t.* FROM driver_support_tickets t JOIN driver_support_messages m ON m.ticket_id = t.id
            WHERE m.email_message_id IN ({placeholders})
            LIMIT 1
        """, tuple(ids) + tuple(ids))
        if rows:
            return rows[0]
    reference = reference_from_headers(message, subject)
    if reference:
        rows = _rows(conn, "SELECT * FROM driver_support_tickets WHERE reference = %s", (reference.upper(),))
        if rows:
            return rows[0]
    return None


def process_reply(conn, config: SupportConfig, raw_bytes: bytes, commit: bool = True) -> str:
    """Un email din casuta de suport. Intoarce rezultatul (pentru rezumat si teste)."""
    message = email.message_from_bytes(raw_bytes)
    if message.get(TICKET_HEADER):
        return "ignored_own_message"

    subject = af.decoded_header(message.get("Subject"))
    from_email = parseaddr(str(message.get("From") or ""))[1].strip().lower()
    message_id = str(message.get("Message-ID") or "").strip()
    if not message_id:
        message_id = "<sha256-" + hashlib.sha256(raw_bytes).hexdigest()[:40] + "@support.local>"

    ticket = find_ticket(conn, message, subject)
    if ticket is None:
        # Corespondenta fara legatura cu un tichet (inclusiv aprobari): nu o atingem si nu o jurnalizam.
        return "no_ticket"

    ticket_id = int(ticket["id"])
    exists = _rows(conn, "SELECT id FROM driver_support_messages WHERE direction = 'email_to_whatsapp' AND source_message_id = %s", (message_id[:255],))
    if exists:
        return "duplicate"

    def record(status: str, content: str | None, outcome: str) -> str:
        _exec(conn, """
            INSERT IGNORE INTO driver_support_messages (ticket_id, direction, source_message_id, sender, content, delivery_status, last_error, created_at)
            VALUES (%s, 'email_to_whatsapp', %s, %s, %s, %s, %s, NOW())
        """, (ticket_id, message_id[:255], from_email[:255], content, status, outcome if status == "ignored" else None))
        if commit:
            conn.commit()
        return outcome

    if is_automatic(message, from_email, subject):
        return record("ignored", None, "ignored_automatic")
    if from_email not in config.allowed_senders:
        return record("ignored", None, "ignored_unauthorized_sender")

    text = sanitize(strip_quoted(message_text(message)))
    if not text:
        return record("ignored", None, "ignored_empty")

    support_message_id = _exec(conn, """
        INSERT IGNORE INTO driver_support_messages (ticket_id, direction, source_message_id, sender, content, delivery_status, created_at)
        VALUES (%s, 'email_to_whatsapp', %s, %s, %s, 'pending', NOW())
    """, (ticket_id, message_id[:255], from_email[:255], text))
    if not support_message_id:
        if commit:
            conn.rollback()
        return "duplicate"

    body = f"📩 Răspuns de la suport — #{ticket['reference']}\n{text}"
    outbox_id = _exec(conn, """
        INSERT IGNORE INTO whatsapp_outbox
            (dedupe_key, purpose, driver_id, body, options_json, status, attempts, max_attempts, next_attempt_at,
             related_type, related_id, created_at, updated_at)
        VALUES (%s, 'support_reply', %s, %s, NULL, 'pending', 0, 5, NOW(), 'support_message', %s, NOW(), NOW())
    """, (f"support_reply:{support_message_id}", int(ticket["driver_id"]), body, support_message_id))
    _exec(conn, "UPDATE driver_support_messages SET outbox_id = %s WHERE id = %s", (outbox_id or None, support_message_id))
    _exec(conn, "UPDATE driver_support_tickets SET status = IF(status = 'open', 'answered', status), last_reply_at = NOW(), updated_at = NOW() WHERE id = %s", (ticket_id,))
    if commit:
        conn.commit()
    return "forwarded"


def poll_replies(conn, config: SupportConfig) -> dict[str, Any]:
    summary: dict[str, Any] = {"fetched": 0, "outcomes": {}, "errors": []}
    mail = config.mail
    if not mail.imap_host or not mail.imap_username or not mail.imap_password:
        summary["skipped_reason"] = "Casuta de suport fara IMAP (SUPPORT_INBOX_* / APPROVAL_INBOX_*)."
        return summary

    imap = imaplib.IMAP4_SSL(mail.imap_host, mail.imap_port, ssl_context=ssl.create_default_context())
    try:
        imap.login(mail.imap_username, mail.imap_password)
        since = (datetime.now() - timedelta(days=config.lookback_days)).strftime("%d-%b-%Y")
        # Folderele de tag (Migadu: inbox+sup-000123 -> folderul "sup-000123") si, ca plasa,
        # emailurile din INBOX cu SUP- in subiect. Totul cu BODY.PEEK: nu schimbam "citit".
        targets = [(name, "ALL") for name in af.list_mailboxes(imap) if TAG_FOLDER.match(name.rsplit("/", 1)[-1])]
        targets.append((mail.imap_folder, f'(SINCE {since} SUBJECT "SUP-")'))
        for folder, criteria in targets:
            try:
                status, _ = imap.select(folder, readonly=True)
                if status != "OK":
                    continue
                status, data = imap.search(None, criteria)
                if status != "OK":
                    summary["errors"].append({"folder": folder, "error": f"IMAP search: {status}"})
                    continue
                for number in (data[0] or b"").split():
                    try:
                        status, payload = imap.fetch(number, "(BODY.PEEK[])")
                        raw = next((item[1] for item in payload or [] if isinstance(item, tuple)), None)
                        if status != "OK" or raw is None:
                            continue
                        summary["fetched"] += 1
                        outcome = process_reply(conn, config, raw)
                        summary["outcomes"][outcome] = summary["outcomes"].get(outcome, 0) + 1
                    except Exception as exc:
                        conn.rollback()
                        summary["errors"].append({"folder": folder, "message": number.decode(errors="replace"), "error": str(exc)[:250]})
                imap.close()
            except Exception as exc:
                summary["errors"].append({"folder": folder, "error": str(exc)[:250]})
    finally:
        try:
            imap.logout()
        except Exception:
            pass
    return summary


# ---------------------------------------------------------------------------
# Rulare
# ---------------------------------------------------------------------------

def run(send: bool = True, poll: bool = True) -> dict[str, Any]:
    config = support_config()
    if config.problem:
        return {"skipped_reason": config.problem}
    conn = af.connect_db()
    result: dict[str, Any] = {"checked_at": datetime.now().isoformat(timespec="seconds")}
    try:
        if send:
            result["tickets"] = send_new_tickets(conn, config)
            result["followups"] = send_followups(conn, config)
        if poll:
            result["replies"] = poll_replies(conn, config)
    finally:
        conn.close()
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Suport soferi: tichete pe email si raspunsuri inapoi pe WhatsApp")
    parser.add_argument("--send-only", action="store_true", help="Doar trimite tichetele / mesajele noi")
    parser.add_argument("--poll-only", action="store_true", help="Doar citeste raspunsurile din casuta")
    args = parser.parse_args()
    try:
        result = run(send=not args.poll_only, poll=not args.send_only)
        print(json.dumps(result, ensure_ascii=False, default=str))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)[:500]}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
