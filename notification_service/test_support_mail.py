"""Teste pentru support_mail.py (fara SMTP / IMAP reale: trimiterea si casuta sunt simulate).

    DB_NAME=fleet_wa_test notification_service/.venv/Scripts/python.exe -m unittest notification_service/test_support_mail.py -v

Testele cu baza de date ruleaza doar pe o copie de test (DB_NAME incepe cu "fleet_wa_test")
si sterg la final randurile create.
"""
from __future__ import annotations

import os
import sys
import unittest
from dataclasses import replace
from email.message import EmailMessage
from email.utils import make_msgid
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))

import approval_flow as af  # noqa: E402
import support_mail as sm  # noqa: E402

SUPPORT = "suport@lpg-test.ro"
INBOX = "aprobari@lpg-test.ro"


def make_config(**overrides) -> sm.SupportConfig:
    mail = replace(af.approval_config(), smtp_host="smtp.test", from_address=INBOX, inbox_address=INBOX,
                   imap_host="imap.test", imap_username=INBOX, imap_password="x", imap_folder="INBOX")
    base = sm.SupportConfig(enabled=True, mail=mail, to_addresses=(SUPPORT,), allowed_senders=(SUPPORT,),
                            inbox_address=INBOX, max_attempts=3, lookback_days=14)
    return replace(base, **overrides)


def reply_email(*, in_reply_to: str | None = None, sender: str = SUPPORT, subject: str = "Re: [Fleet App Support][SUP-000001] Problemă raportată de șofer",
                text: str | None = None, html: str | None = None, to: str = INBOX, headers: dict[str, str] | None = None) -> bytes:
    message = EmailMessage()
    message["From"] = f"Echipa Suport <{sender}>"
    message["To"] = to
    message["Subject"] = subject
    message["Message-ID"] = make_msgid(domain="client.test")
    if in_reply_to:
        message["In-Reply-To"] = in_reply_to
        message["References"] = in_reply_to
    for key, value in (headers or {}).items():
        message[key] = value
    if text is not None:
        message.set_content(text)
        if html is not None:
            message.add_alternative(html, subtype="html")
    else:
        message.set_content(html or "", subtype="html")
    return message.as_bytes()


GMAIL_REPLY = (
    "Vă rugăm să închideți complet aplicația și să o redeschideți. Dacă problema persistă, trimiteți-ne un mesaj aici.\n"
    "\n"
    "Cu stimă,\n"
    "Echipa suport\n"
    "\n"
    "On Mon, Oct 19, 2026 at 1:05 PM Fleet Management <aprobari+sup-000001@lpg-test.ro>\n"
    "wrote:\n"
    "\n"
    "> Tichet: #SUP-000001\n"
    "> Șofer: Test\n"
)


class PureTests(unittest.TestCase):
    def test_strip_gmail_quote_two_lines(self):
        self.assertEqual(sm.sanitize(sm.strip_quoted(GMAIL_REPLY)),
                         "Vă rugăm să închideți complet aplicația și să o redeschideți. Dacă problema persistă, trimiteți-ne un mesaj aici.\n\nCu stimă,\nEchipa suport")

    def test_strip_romanian_and_outlook_and_signature(self):
        self.assertEqual(sm.strip_quoted("Rezolvat.\nPe lun., 19 oct. 2026 la 13:05, Fleet <a@b.ro> a scris:\n> x").strip(), "Rezolvat.")
        self.assertEqual(sm.strip_quoted("Gata.\n\nFrom: Fleet\nSent: Monday").strip(), "Gata.")
        self.assertEqual(sm.strip_quoted("Gata.\n-----Original Message-----\nx").strip(), "Gata.")
        self.assertEqual(sm.strip_quoted("Gata.\n-- \nIon Popescu\n0722").strip(), "Gata.")
        self.assertEqual(sm.strip_quoted("Gata.\n\nSent from my iPhone").strip(), "Gata.")

    def test_html_reply_without_quote(self):
        html = '<div dir="ltr">Închideți <b>aplicația</b> &amp; redeschideți.<br>Mulțumim</div><div class="gmail_quote"><blockquote>Tichet vechi</blockquote></div><script>x()</script>'
        self.assertEqual(sm.sanitize(sm.html_to_text(html)), "Închideți aplicația & redeschideți.\nMulțumim")

    def test_sanitize_controls_and_length(self):
        self.assertEqual(sm.sanitize("a​\x07b\n\n\n\nc"), "ab\n\nc")
        self.assertTrue(len(sm.sanitize("x" * 5000)) <= sm.MAX_FORWARD_CHARS + 2)

    def test_automatic_detection(self):
        def msg(**h):
            m = EmailMessage()
            for k, v in h.items():
                m[k.replace("_", "-")] = v
            m.set_content("x")
            return m
        self.assertTrue(sm.is_automatic(msg(Auto_Submitted="auto-replied"), SUPPORT, "Re: x"))
        self.assertTrue(sm.is_automatic(msg(Precedence="bulk"), SUPPORT, "Re: x"))
        self.assertTrue(sm.is_automatic(msg(), "mailer-daemon@googlemail.com", "Delivery Status Notification (Failure)"))
        self.assertTrue(sm.is_automatic(msg(), SUPPORT, "Automatic reply: Out of office"))
        self.assertTrue(sm.is_automatic(msg(), SUPPORT, "Răspuns automat: concediu"))
        self.assertFalse(sm.is_automatic(msg(Auto_Submitted="no"), SUPPORT, "Re: [Fleet App Support][SUP-000001] x"))

    def test_reply_to_tag_and_reference_parsing(self):
        cfg = make_config()
        self.assertEqual(cfg.reply_to("SUP-000123"), "aprobari+sup-000123@lpg-test.ro")
        m = EmailMessage()
        m["To"] = "Fleet <aprobari+sup-000123@lpg-test.ro>"
        self.assertEqual(sm.reference_from_headers(m, "Re: altceva"), "SUP-000123")
        m2 = EmailMessage()
        self.assertEqual(sm.reference_from_headers(m2, "Re: [Fleet App Support][SUP-000045] x"), "SUP-000045")
        self.assertIsNone(sm.reference_from_headers(m2, "Aprobare cerere [#abcdefghjkmnpqrs]"))

    def test_config_defaults_to_support_to_and_approval_inbox(self):
        with mock.patch.dict(os.environ, {"SUPPORT_EMAIL_ENABLED": "1", "SUPPORT_EMAIL_TO": "A@x.ro; b@y.ro", "SUPPORT_REPLY_ALLOWED_SENDERS": "", "SUPPORT_INBOX_ADDRESS": ""}):
            cfg = sm.support_config()
        self.assertEqual(cfg.to_addresses, ("a@x.ro", "b@y.ro"))
        self.assertEqual(cfg.allowed_senders, cfg.to_addresses)
        self.assertEqual(cfg.inbox_address, af.env_value(["APPROVAL_INBOX_ADDRESS"]))


@unittest.skipUnless(os.getenv("DB_NAME", "").startswith("fleet_wa_test"), "doar pe copia de test (DB_NAME=fleet_wa_test)")
class DatabaseTests(unittest.TestCase):
    def setUp(self):
        self.conn = af.connect_db()
        self.cfg = make_config()
        self.ticket_ids: list[int] = []
        sm._exec(self.conn, "DELETE FROM whatsapp_outbox WHERE dedupe_key LIKE 'pytest:%%'")
        self.conn.commit()

    def tearDown(self):
        for ticket_id in self.ticket_ids:
            for sql in (
                "DELETE FROM whatsapp_outbox WHERE related_type = 'support_message' AND related_id IN (SELECT id FROM driver_support_messages WHERE ticket_id = %s)",
                "DELETE FROM whatsapp_outbox WHERE dedupe_key IN (CONCAT('support_ack:', %s), CONCAT('support_ack_failed:', %s))",
                "DELETE FROM driver_support_messages WHERE ticket_id = %s",
                "DELETE FROM driver_support_tickets WHERE id = %s",
            ):
                params = (ticket_id, ticket_id) if sql.count("%s") == 2 else (ticket_id,)
                sm._exec(self.conn, sql, params)
        self.conn.commit()
        self.conn.close()

    def new_ticket(self, description="Nu pot să mă autentific în aplicație. Îmi apare sesiune expirată.") -> dict:
        source = "PYTEST" + os.urandom(6).hex().upper()
        ticket_id = sm._exec(self.conn, """
            INSERT INTO driver_support_tickets (driver_id, vehicle_id, description, status, email_status, email_next_attempt_at, source_wa_message_id, created_at, updated_at)
            VALUES (48, 48, %s, 'open', 'pending', NOW(), %s, NOW(), NOW())
        """, (description, source))
        reference = f"SUP-{ticket_id:06d}"
        sm._exec(self.conn, "UPDATE driver_support_tickets SET reference = %s WHERE id = %s", (reference, ticket_id))
        sm._exec(self.conn, "INSERT INTO driver_support_messages (ticket_id, direction, source_message_id, sender, content, delivery_status, created_at) VALUES (%s, 'whatsapp_to_email', %s, 'driver:48', %s, 'pending', NOW())", (ticket_id, source, description))
        for key, body in ((f"support_ack:{ticket_id}", "ok"), (f"support_ack_failed:{ticket_id}", "fail")):
            sm._exec(self.conn, "INSERT INTO whatsapp_outbox (dedupe_key, purpose, driver_id, body, status, next_attempt_at, related_type, related_id, created_at, updated_at) VALUES (%s, 'support_ack', 48, %s, 'held', NOW(), 'ticket', %s, NOW(), NOW())", (key, body, ticket_id))
        self.conn.commit()
        self.ticket_ids.append(ticket_id)
        return {"id": ticket_id, "reference": reference}

    def ticket(self, ticket_id: int) -> dict:
        return sm._rows(self.conn, "SELECT * FROM driver_support_tickets WHERE id = %s", (ticket_id,))[0]

    def ack_status(self, ticket_id: int) -> tuple[str, str]:
        rows = {r["dedupe_key"]: r["status"] for r in sm._rows(self.conn, "SELECT dedupe_key, status FROM whatsapp_outbox WHERE dedupe_key IN (%s, %s)", (f"support_ack:{ticket_id}", f"support_ack_failed:{ticket_id}"))}
        return rows[f"support_ack:{ticket_id}"], rows[f"support_ack_failed:{ticket_id}"]

    def send_only(self, ticket_id: int, mocked) -> dict:
        # Doar tichetul acestui test (alte tichete de pe copia de test nu se trimit).
        sm._exec(self.conn, "UPDATE driver_support_tickets SET email_next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE email_status = 'pending' AND id <> %s", (ticket_id,))
        self.conn.commit()
        with mock.patch.object(af, "send_mail", mocked):
            return sm.send_new_tickets(self.conn, self.cfg)

    def test_ticket_email_sent_once_with_threading_headers(self):
        t = self.new_ticket()
        sent = []
        result = self.send_only(t["id"], lambda cfg, to, name, subject, body, headers=None, message_id=None: sent.append((to, subject, body, headers, message_id)) or message_id)
        self.assertEqual(result["sent"], 1)
        to, subject, body, headers, message_id = sent[0]
        self.assertEqual(to, SUPPORT)
        self.assertEqual(subject, f"[Fleet App Support][{t['reference']}] Problemă raportată de șofer")
        for expected in (f"Tichet: #{t['reference']}", "ID șofer în Fleet App: 48", "Vehicul: B 165 NET", "sesiune expirată", "Status: deschis"):
            self.assertIn(expected, body)
        self.assertEqual(headers["Reply-To"], f"aprobari+sup-{t['reference'][4:]}@lpg-test.ro")
        self.assertEqual(headers[sm.TICKET_HEADER], t["reference"])
        row = self.ticket(t["id"])
        self.assertEqual((row["email_status"], row["email_message_id"]), ("sent", message_id))
        self.assertEqual(self.ack_status(t["id"]), ("pending", "cancelled"))
        # A doua rulare nu retrimite.
        again = []
        self.send_only(t["id"], lambda *a, **k: again.append(1))
        self.assertEqual(again, [])

    def test_email_failure_retries_with_same_message_id_then_gives_up(self):
        t = self.new_ticket()
        ids = []

        def failing(cfg, to, name, subject, body, headers=None, message_id=None):
            ids.append(message_id)
            raise OSError("SMTP indisponibil")

        r1 = self.send_only(t["id"], failing)
        self.assertEqual(r1["retry"], 1)
        self.assertEqual(self.ticket(t["id"])["email_status"], "pending")
        self.assertEqual(self.ack_status(t["id"]), ("held", "held"))
        for _ in range(2):
            sm._exec(self.conn, "UPDATE driver_support_tickets SET email_next_attempt_at = NOW() WHERE id = %s", (t["id"],))
            self.conn.commit()
            self.send_only(t["id"], failing)
        self.assertEqual(len(set(ids)), 1, "acelasi Message-ID la fiecare reincercare")
        row = self.ticket(t["id"])
        self.assertEqual((row["email_status"], row["email_attempts"]), ("failed", 3))
        self.assertEqual(self.ack_status(t["id"]), ("cancelled", "pending"))
        self.assertEqual(sm._rows(self.conn, "SELECT COUNT(*) n FROM driver_support_tickets WHERE source_wa_message_id = %s", (row["source_wa_message_id"],))[0]["n"], 1)

    def sent_ticket(self) -> dict:
        t = self.new_ticket()
        self.send_only(t["id"], lambda cfg, to, name, subject, body, headers=None, message_id=None: message_id)
        t["message_id"] = self.ticket(t["id"])["email_message_id"]
        return t

    def outbox_for(self, ticket_id: int) -> list[dict]:
        return sm._rows(self.conn, """
            SELECT o.* FROM whatsapp_outbox o JOIN driver_support_messages m ON m.id = o.related_id
            WHERE o.related_type = 'support_message' AND m.ticket_id = %s
        """, (ticket_id,))

    def test_reply_forwarded_once_without_quote(self):
        t = self.sent_ticket()
        raw = reply_email(in_reply_to=t["message_id"], subject=f"Re: [Fleet App Support][{t['reference']}] Problemă raportată de șofer", text=GMAIL_REPLY)
        self.assertEqual(sm.process_reply(self.conn, self.cfg, raw), "forwarded")
        outbox = self.outbox_for(t["id"])
        self.assertEqual(len(outbox), 1)
        self.assertEqual(outbox[0]["body"], f"📩 Răspuns de la suport — #{t['reference']}\nVă rugăm să închideți complet aplicația și să o redeschideți. Dacă problema persistă, trimiteți-ne un mesaj aici.\n\nCu stimă,\nEchipa suport")
        self.assertEqual((outbox[0]["driver_id"], outbox[0]["status"]), (48, "pending"))
        row = self.ticket(t["id"])
        self.assertEqual(row["status"], "answered")
        self.assertIsNotNone(row["last_reply_at"])
        # Acelasi email citit din nou (alt folder, alta rulare) -> nimic nou.
        self.assertEqual(sm.process_reply(self.conn, self.cfg, raw), "duplicate")
        self.assertEqual(len(self.outbox_for(t["id"])), 1)

    def test_correlation_by_address_tag_and_by_subject(self):
        t = self.sent_ticket()
        number = t["reference"][4:]
        by_tag = reply_email(to=f"aprobari+sup-{number}@lpg-test.ro", subject="Re: problema", text="Prin tag.")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, by_tag), "forwarded")
        by_subject = reply_email(subject=f"Re: [Fleet App Support][{t['reference']}] x", text="Prin subiect.")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, by_subject), "forwarded")
        self.assertEqual(len(self.outbox_for(t["id"])), 2)

    def test_unauthorized_automatic_own_and_unrelated_are_not_forwarded(self):
        t = self.sent_ticket()
        subject = f"Re: [Fleet App Support][{t['reference']}] x"
        self.assertEqual(sm.process_reply(self.conn, self.cfg, reply_email(in_reply_to=t["message_id"], sender="atacator@exemplu.com", subject=subject, text="Trimite-mi datele")), "ignored_unauthorized_sender")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, reply_email(in_reply_to=t["message_id"], subject="Automatic reply: " + subject, text="Sunt in concediu")), "ignored_automatic")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, reply_email(in_reply_to=t["message_id"], subject=subject, text="x", headers={"Auto-Submitted": "auto-replied"})), "ignored_automatic")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, reply_email(in_reply_to=t["message_id"], subject=subject, text="copie", headers={sm.TICKET_HEADER: t["reference"]})), "ignored_own_message")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, reply_email(subject="Factura octombrie", text="Buna ziua")), "no_ticket")
        self.assertEqual(sm.process_reply(self.conn, self.cfg, reply_email(in_reply_to=t["message_id"], subject=subject, text="\n> doar citat\n")), "ignored_empty")
        self.assertEqual(self.outbox_for(t["id"]), [])

    def test_html_only_reply(self):
        t = self.sent_ticket()
        raw = reply_email(in_reply_to=t["message_id"], subject=f"Re: [Fleet App Support][{t['reference']}] x",
                          html='<div>Reinstalați <i>aplicația</i>.</div><div class="gmail_quote">On ... wrote:<blockquote>vechi</blockquote></div>')
        self.assertEqual(sm.process_reply(self.conn, self.cfg, raw), "forwarded")
        self.assertTrue(self.outbox_for(t["id"])[0]["body"].endswith("\nReinstalați aplicația."))

    def test_poll_reads_tag_folders_and_inbox_without_marking(self):
        t = self.sent_ticket()
        reply = reply_email(in_reply_to=t["message_id"], subject=f"Re: [Fleet App Support][{t['reference']}] x", text="Din folderul de tag.")
        other = reply_email(subject="Aprobare cerere", text="nu e suport")
        folder = f"sup-{t['reference'][4:]}"

        class FakeImap:
            stores = []

            def __init__(self, *a, **k):
                self.boxes = {folder: [reply, reply], "INBOX": [other], "ap-abcdefghjkmnpqrs": [other]}
                self.current = None

            def login(self, *a): return "OK", []
            def list(self): return "OK", [f'(\\HasNoChildren) "/" "{name}"'.encode() for name in self.boxes]
            def select(self, name, readonly=False):
                self.current = name
                return ("OK", []) if name in self.boxes else ("NO", [])
            def search(self, charset, criteria): return "OK", [b" ".join(str(i + 1).encode() for i in range(len(self.boxes[self.current])))]
            def fetch(self, number, what): return "OK", [(b"1", self.boxes[self.current][int(number) - 1])]
            def store(self, *a): FakeImap.stores.append(a); return "OK", []
            def close(self): return "OK", []
            def logout(self): return "OK", []

        with mock.patch.object(sm.imaplib, "IMAP4_SSL", FakeImap):
            summary = sm.poll_replies(self.conn, self.cfg)
        self.assertEqual(summary["outcomes"], {"forwarded": 1, "duplicate": 1, "no_ticket": 1})
        self.assertEqual(FakeImap.stores, [], "casuta nu se modifica (aprobarile raman neatinse)")
        self.assertEqual(len(self.outbox_for(t["id"])), 1)


if __name__ == "__main__":
    unittest.main()
