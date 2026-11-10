"""Teste pentru alertele Consumabile & Fluide Tehnice din worker.py (fara baza de date / PHP).

    notification_service/.venv/Scripts/python.exe -m unittest notification_service/test_consumabile_alerts.py -v
"""
from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))

import worker  # noqa: E402

ALERTS = [
    {"id": 7, "tip": "consumabil_completare", "vehicle_id": 12, "nr": "B 218 NET", "titlu": "Consumabil sub nivelul minim confirmat",
     "subiect": "B 218 NET · Motor", "detalii": "Nivel sub minim.", "recomandare": "Completează până la reper", "link_sub": "vehicule", "severitate": "critical"},
    {"id": 8, "tip": "consumabil_recipient_minim", "vehicle_id": 0, "nr": "", "titlu": "Recipient cu stoc minim atins",
     "subiect": "BUT-001 · Ulei", "detalii": "Cantitate 30 L (prag 40).", "recomandare": "Reumple", "link_sub": "stocuri", "severitate": "warn"},
]


class ConsumabilAlertsTest(unittest.TestCase):
    def rule(self, event: str, repeat: int = 0, metadata: str = "{}") -> dict:
        return {"id": 3, "event_type": event, "repeat_until_resolved": repeat, "metadata_json": metadata}

    def test_events_are_routed(self) -> None:
        with mock.patch.object(worker, "load_consumabil_alerts", return_value=ALERTS):
            rows = worker.fetch_matches_for_rule(None, self.rule("consumabil_completare"))
        self.assertEqual([r["entity_id"] for r in rows], [7])
        self.assertEqual(rows[0]["entity_type"], "consumabil")
        self.assertEqual(rows[0]["owner_id"], 12)

    def test_only_matching_type(self) -> None:
        rows = worker.consumabil_matches(self.rule("consumabil_recipient_minim"), ALERTS)
        self.assertEqual([r["entity_id"] for r in rows], [8])

    def test_same_open_alert_same_context(self) -> None:
        """Aceeasi alerta deschisa = acelasi context_id: o singura notificare (fara repetare)."""
        rule = self.rule("consumabil_completare")
        first = worker.delivery_context_id(rule, worker.consumabil_matches(rule, ALERTS)[0])
        second = worker.delivery_context_id(rule, worker.consumabil_matches(rule, ALERTS)[0])
        self.assertEqual(first, second)
        self.assertEqual(first, "rule:3:consumabil_completare:consumabil:7")

    def test_reopened_alert_is_new_notification(self) -> None:
        rule = self.rule("consumabil_completare")
        reopened = [dict(ALERTS[0], id=99)]
        self.assertNotEqual(
            worker.delivery_context_id(rule, worker.consumabil_matches(rule, ALERTS)[0]),
            worker.delivery_context_id(rule, worker.consumabil_matches(rule, reopened)[0]),
        )

    def test_reminder_bucket_uses_interval(self) -> None:
        rule = self.rule("consumabil_completare", repeat=1, metadata='{"repeat_days": 5}')
        match = worker.consumabil_matches(rule, ALERTS)[0]
        self.assertTrue(match["repeat_bucket"].startswith("r"))
        self.assertTrue(worker.delivery_context_id(rule, match).endswith(":" + match["repeat_bucket"]))

    def test_php_failure_does_not_break_other_rules(self) -> None:
        with mock.patch.object(worker, "load_consumabil_alerts", side_effect=RuntimeError("boom")):
            self.assertEqual(worker.fetch_consumabil_matches(self.rule("consumabil_completare")), [])

    def test_sms_and_email_text(self) -> None:
        config = mock.Mock(app_name="Flota")
        match = worker.consumabil_matches(self.rule("consumabil_completare"), ALERTS)[0]
        sms = worker.build_sms_text(config, self.rule("consumabil_completare"), match)
        self.assertIn("B 218 NET", sms)
        self.assertIn("Completeaz", sms)
        with mock.patch.object(worker, "absolute_app_url", return_value="http://app/x"):
            subject, body = worker.build_notification_email(config, self.rule("consumabil_completare"), match, {"nume": "Ana"})
        self.assertIn("B 218 NET", subject)
        self.assertIn("Actiune recomandata", body)


if __name__ == "__main__":
    unittest.main()
