-- Notificari: canal SMS (SMSAlert).
-- Fiecare SMS este o livrare separata in notification_deliveries (channel = 'sms'),
-- cu numarul in recipient_phone; recipient_email ramane gol pentru SMS.
-- Se aplica si automat din NotificationRuleModel / worker.py (idempotent acolo).

ALTER TABLE notification_deliveries
    MODIFY channel ENUM('email','sms') NOT NULL DEFAULT 'email',
    MODIFY recipient_email VARCHAR(190) NULL,
    ADD COLUMN recipient_phone VARCHAR(32) NULL AFTER recipient_email,
    ADD KEY idx_notification_deliveries_phone (recipient_phone, created_at);
