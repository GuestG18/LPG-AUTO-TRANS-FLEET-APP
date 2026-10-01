#!/usr/bin/env python3
"""
Citeste scanarile de facturi din Gmail si le pune in coada aplicatiei (pagina "Facturi").

    python3 scripts/fetch_invoice_emails.py --dry-run     doar listeaza ce ar lua (nu scrie, nu eticheteaza)
    python3 scripts/fetch_invoice_emails.py               ruleaza efectiv
    python3 scripts/fetch_invoice_emails.py --limit=5

Ce face, pentru fiecare email de la scanner (expeditor + textul scannerului din corp sau
inceputul de subiect, din .env)
care nu are inca eticheta de procesat / eroare:
  1. salveaza atasamentele PDF / JPG / PNG in storage/invoices/inbox/ (scriere atomica)
     plus un fisier .json cu datele emailului;
  2. abia dupa ce fisierele sunt pe disc pune eticheta Gmail INVOICE_IMAP_LABEL_DONE
     (implicit "Facturi/Procesat"); emailul ramane in Inbox, citit / necitit ca inainte.
     Un email fara atasament valid primeste INVOICE_IMAP_LABEL_ERROR ("Facturi/Eroare").

Citirea facturii (OCR) si crearea randurilor in Facturi le face pasul urmator:
scripts/process_invoice_inbox.php. Scriptul de fata nu atinge baza de date.

Configurare (.env): INVOICE_IMAP_HOST, INVOICE_IMAP_PORT, INVOICE_IMAP_USERNAME,
INVOICE_IMAP_PASSWORD, INVOICE_IMAP_FOLDER, INVOICE_IMAP_SENDER,
INVOICE_IMAP_SUBJECT_PREFIX, INVOICE_IMAP_LABEL_DONE, INVOICE_IMAP_LABEL_ERROR,
INVOICE_IMAP_LOOKBACK_DAYS, INVOICE_IMAP_BODY_MARKER. Doar biblioteca standard Python (fara pachete).
"""
from __future__ import annotations

import argparse
import email
import email.policy
import hashlib
import imaplib
import json
import os
import re
import ssl
import sys
from datetime import datetime, timezone
from email.utils import parseaddr, parsedate_to_datetime
from pathlib import Path

MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024  # aceeasi limita ca InvoiceStorageService::MAX_SIZE

ALLOWED_TYPES = {
    "application/pdf": "pdf",
    "image/jpeg": "jpg",
    "image/png": "png",
}
EXTENSION_TYPES = {"pdf": "application/pdf", "jpg": "image/jpeg", "jpeg": "image/jpeg", "png": "image/png"}


# ---------------------------------------------------------------------------
# Configurare
# ---------------------------------------------------------------------------

def load_dotenv(path: Path) -> None:
    """Incarca .env in os.environ fara sa suprascrie variabilele deja setate (ex. din cron)."""
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        os.environ.setdefault(key, value)


def config_from_env() -> dict:
    cfg = {
        "host": os.environ.get("INVOICE_IMAP_HOST", "imap.gmail.com"),
        "port": int(os.environ.get("INVOICE_IMAP_PORT", "993") or 993),
        "username": os.environ.get("INVOICE_IMAP_USERNAME", ""),
        "password": os.environ.get("INVOICE_IMAP_PASSWORD", ""),
        "folder": os.environ.get("INVOICE_IMAP_FOLDER", "INBOX") or "INBOX",
        "sender": os.environ.get("INVOICE_IMAP_SENDER", "").strip().lower(),
        "subject_prefix": os.environ.get("INVOICE_IMAP_SUBJECT_PREFIX", "").strip(),
        # Textul pus de scanner in corpul emailului; ramane si cand operatorul scrie
        # alt subiect pe scanner (ex. "cazare").
        "body_marker": (os.environ.get("INVOICE_IMAP_BODY_MARKER", "Scanned from MFP") or "Scanned from MFP").strip(),
        "label_done": os.environ.get("INVOICE_IMAP_LABEL_DONE", "Facturi/Procesat") or "Facturi/Procesat",
        "label_error": os.environ.get("INVOICE_IMAP_LABEL_ERROR", "Facturi/Eroare") or "Facturi/Eroare",
        "lookback_days": int(os.environ.get("INVOICE_IMAP_LOOKBACK_DAYS", "30") or 30),
    }
    missing = [name for name, key in (
        ("INVOICE_IMAP_USERNAME", "username"),
        ("INVOICE_IMAP_PASSWORD", "password"),
        ("INVOICE_IMAP_SENDER", "sender"),
        ("INVOICE_IMAP_SUBJECT_PREFIX", "subject_prefix"),
    ) if not cfg[key]]
    if missing:
        raise SystemExit("Lipsesc din .env: " + ", ".join(missing))
    return cfg


# ---------------------------------------------------------------------------
# Gmail
# ---------------------------------------------------------------------------

def imap_quote(value: str) -> str:
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def gmail_label_search_name(label: str) -> str:
    """In cautarea Gmail, "/" si spatiile din numele etichetei devin "-"."""
    return re.sub(r"[/\s]+", "-", label.strip())


def subject_search_phrase(prefix: str) -> str:
    """
    Cuvintele intregi din prefix, pentru cautarea Gmail.

    Gmail cauta cuvinte intregi: "MFP07552300" e un singur cuvant, deci fraza
    "Send data from MFP" nu il gaseste. Ultimul cuvant al prefixului poate fi doar
    inceputul unui cuvant (ca "MFP"), asa ca il scoatem din cautare; prefixul complet
    se verifica oricum local, pe subiectul fiecarui email.
    """
    words = prefix.replace('"', "").split()
    if len(words) > 1 and not prefix.endswith(" "):
        words = words[:-1]
    return " ".join(words)


def build_query(cfg: dict, with_subject: bool = True, with_labels: bool = True) -> str:
    parts = [f"from:{cfg['sender']}"]
    # Textul scannerului din corp SAU inceputul de subiect (cuvinte intregi). Verificarea
    # exacta se face local, pe fiecare email (is_scanner_email).
    terms = []
    body_phrase = subject_search_phrase(cfg["body_marker"])
    if body_phrase:
        terms.append(f'"{body_phrase}"')
    subject_phrase = subject_search_phrase(cfg["subject_prefix"])
    if subject_phrase:
        terms.append(f'subject:"{subject_phrase}"')
    if with_subject and terms:
        parts.append(terms[0] if len(terms) == 1 else "{" + " ".join(terms) + "}")
    if not with_labels:
        parts.append(f"newer_than:{max(1, cfg['lookback_days'])}d")
        return " ".join(parts)
    parts += [
        "has:attachment",
        f"-label:{gmail_label_search_name(cfg['label_done'])}",
        f"-label:{gmail_label_search_name(cfg['label_error'])}",
        f"newer_than:{max(1, cfg['lookback_days'])}d",
    ]
    return " ".join(parts)


def is_scanner_email(cfg: dict, sender: str, subject: str, body: str) -> bool:
    """De la expeditorul configurat SI (textul scannerului in corp SAU subiectul scannerului)."""
    if sender != cfg["sender"]:
        return False
    marker = cfg["body_marker"].lower()
    prefix = cfg["subject_prefix"]

    return (bool(marker) and marker in body.lower()) or (bool(prefix) and subject.startswith(prefix))


def ensure_label(imap: imaplib.IMAP4_SSL, label: str) -> None:
    # CREATE pe o eticheta existenta intoarce NO; e in regula.
    try:
        imap.create(imap_quote(label))
    except imaplib.IMAP4.error:
        pass


def add_label(imap: imaplib.IMAP4_SSL, uid: bytes, label: str) -> None:
    status, data = imap.uid("STORE", uid, "+X-GM-LABELS", "(" + imap_quote(label) + ")")
    if status != "OK":
        raise RuntimeError(f"Eticheta {label} nu a putut fi pusa: {data!r}")


# ---------------------------------------------------------------------------
# Mesaje si atasamente
# ---------------------------------------------------------------------------

def decoded_text(message) -> str:
    try:
        body = message.get_body(preferencelist=("plain",))
        return body.get_content() if body is not None else ""
    except Exception:
        return ""


def attachment_parts(message):
    """Atasamentele utile: PDF / JPG / PNG (si octet-stream cu extensie potrivita)."""
    for part in message.iter_attachments():
        filename = part.get_filename() or ""
        content_type = part.get_content_type()
        extension = Path(filename).suffix.lower().lstrip(".")
        if content_type not in ALLOWED_TYPES and extension in EXTENSION_TYPES:
            content_type = EXTENSION_TYPES[extension]
        if content_type in ALLOWED_TYPES:
            yield part, filename, content_type


def safe_name(value: str) -> str:
    return re.sub(r"[^A-Za-z0-9._-]+", "_", value).strip("._")[:120] or "scan"


def write_atomic(path: Path, data: bytes) -> None:
    tmp = path.with_name(path.name + ".part")
    tmp.write_bytes(data)
    os.replace(tmp, path)


# ---------------------------------------------------------------------------
# Rulare
# ---------------------------------------------------------------------------

def run(cfg: dict, inbox_dir: Path, dry_run: bool, limit: int) -> dict:
    summary = {
        "rulat_la": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "dry_run": dry_run,
        "gasite": 0,
        "emailuri_procesate": 0,
        "fisiere_salvate": 0,
        "emailuri_eroare": 0,
        "erori": [],
    }

    if not dry_run:
        inbox_dir.mkdir(parents=True, exist_ok=True)

    imap = imaplib.IMAP4_SSL(cfg["host"], cfg["port"], ssl_context=ssl.create_default_context())
    try:
        imap.login(cfg["username"], cfg["password"])
        status, _ = imap.select(imap_quote(cfg["folder"]), readonly=dry_run)
        if status != "OK":
            raise RuntimeError(f"Folderul {cfg['folder']} nu poate fi deschis")

        query = build_query(cfg)
        status, data = imap.uid("SEARCH", "X-GM-RAW", imap_quote(query))
        if status != "OK":
            raise RuntimeError(f"Cautarea Gmail a esuat: {data!r}")
        uids = (data[0] or b"").split()
        summary["gasite"] = len(uids)

        if not uids:
            # Ajutor de diagnostic: cate emailuri ar gasi o cautare mai larga.
            for label, relaxed in (
                ("doar expeditor + perioada", build_query(cfg, with_subject=False, with_labels=False)),
                ("expeditor + subiect + perioada", build_query(cfg, with_labels=False)),
            ):
                status, wide = imap.uid("SEARCH", "X-GM-RAW", imap_quote(relaxed))
                count = len((wide[0] or b"").split()) if status == "OK" else "?"
                print(f"  diagnostic: {label}: {count} emailuri  ({relaxed})")
        if limit > 0:
            uids = uids[:limit]

        if not dry_run and uids:
            ensure_label(imap, cfg["label_done"])
            ensure_label(imap, cfg["label_error"])

        for uid in uids:
            try:
                status, payload = imap.uid("FETCH", uid, "(BODY.PEEK[])")
                raw = next((item[1] for item in payload or [] if isinstance(item, tuple)), None)
                if status != "OK" or raw is None:
                    raise RuntimeError("mesajul nu a putut fi descarcat")

                message = email.message_from_bytes(raw, policy=email.policy.default)
                sender = parseaddr(str(message.get("From", "")))[1].lower()
                subject = str(message.get("Subject", "")).strip()
                message_id = str(message.get("Message-ID", "")).strip()
                try:
                    received = parsedate_to_datetime(str(message.get("Date"))).astimezone().isoformat(timespec="seconds")
                except Exception:
                    received = None

                # A doua verificare, locala: cautarea Gmail e larga (cuvinte, oriunde in email).
                body = decoded_text(message)
                if not is_scanner_email(cfg, sender, subject, body):
                    print(f"  sarit (nu e de la scanner): {sender} | {subject}")
                    continue

                pages_match = re.search(r"Pages:\s*(\d+)", body)
                attachments = list(attachment_parts(message))
                label = cfg["label_done"]
                saved_here = 0
                problems = []

                for index, (part, filename, content_type) in enumerate(attachments, start=1):
                    content = part.get_payload(decode=True) or b""
                    if not content:
                        problems.append(f"{filename or 'atasament'}: gol")
                        continue
                    if len(content) > MAX_ATTACHMENT_BYTES:
                        problems.append(f"{filename or 'atasament'}: peste 10 MB")
                        continue

                    sha256 = hashlib.sha256(content).hexdigest()
                    base = f"{datetime.now():%Y%m%d%H%M%S}_{uid.decode()}_{index}_{sha256[:12]}"
                    extension = ALLOWED_TYPES[content_type]
                    meta = {
                        "fisier": f"{base}.{extension}",
                        "nume_original": safe_name(filename or f"scan_{index}.{extension}"),
                        "mime": content_type,
                        "marime": len(content),
                        "sha256": sha256,
                        "email_message_id": message_id,
                        "email_uid": uid.decode(),
                        "email_de_la": sender,
                        "email_subiect": subject,
                        "email_primit_la": received,
                        "pagini_scanate": int(pages_match.group(1)) if pages_match else None,
                        "atasament": index,
                        "atasamente_in_email": len(attachments),
                    }
                    if dry_run:
                        print(f"  [dry-run] {received} | {subject} | {meta['nume_original']} ({len(content)} B)")
                    else:
                        # Fisierul intai, apoi .json: procesatorul PHP ia doar perechile complete.
                        write_atomic(inbox_dir / meta["fisier"], content)
                        write_atomic(inbox_dir / f"{base}.json", json.dumps(meta, ensure_ascii=False, indent=2).encode("utf-8"))
                    saved_here += 1

                if saved_here == 0:
                    label = cfg["label_error"]
                    problems.append("niciun atasament PDF / JPG / PNG valid")

                if dry_run:
                    if saved_here == 0:
                        print(f"  [dry-run] {received} | {subject} -> ar primi {label}: {'; '.join(problems)}")
                    continue

                add_label(imap, uid, label)
                summary["emailuri_procesate"] += 1
                summary["fisiere_salvate"] += saved_here
                if label == cfg["label_error"]:
                    summary["emailuri_eroare"] += 1
                if problems:
                    summary["erori"].append(f"{subject}: {'; '.join(problems)}")
                print(f"  {received} | {subject} -> {saved_here} fisier(e), eticheta {label}")
            except Exception as exc:  # emailul ramane neetichetat si se reincearca la rularea urmatoare
                summary["erori"].append(f"uid {uid.decode(errors='replace')}: {exc}")
                print(f"  EROARE uid {uid.decode(errors='replace')}: {exc}", file=sys.stderr)
    finally:
        try:
            imap.logout()
        except Exception:
            pass

    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description="Citeste scanarile de facturi din Gmail.")
    parser.add_argument("--dry-run", action="store_true", help="doar listeaza; nu scrie fisiere si nu pune etichete")
    parser.add_argument("--limit", type=int, default=0, help="cel mult N emailuri pe rulare (0 = toate)")
    args = parser.parse_args()

    root = Path(__file__).resolve().parent.parent
    load_dotenv(root / ".env")
    cfg = config_from_env()
    inbox_dir = root / "storage" / "invoices" / "inbox"

    print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] Gmail {cfg['username']} | cautare: {build_query(cfg)}"
          + (" | DRY-RUN" if args.dry_run else ""))
    try:
        summary = run(cfg, inbox_dir, args.dry_run, args.limit)
    except imaplib.IMAP4.error as exc:
        print(f"EROARE IMAP (login / conexiune): {exc}", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"EROARE: {exc}", file=sys.stderr)
        return 1

    print(f"Gasite: {summary['gasite']} | emailuri procesate: {summary['emailuri_procesate']} | "
          f"fisiere salvate: {summary['fisiere_salvate']} | cu eroare: {summary['emailuri_eroare']}")
    if not args.dry_run:
        write_atomic(inbox_dir / ".last_fetch.json", json.dumps(summary, ensure_ascii=False, indent=2).encode("utf-8"))
    return 0 if not summary["erori"] else 2


if __name__ == "__main__":
    sys.exit(main())
