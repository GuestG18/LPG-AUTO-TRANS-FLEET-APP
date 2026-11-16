-- Situatia eToll: inregistrari adaugate manual (sesiuni / alimentari pe care API-ul eToll nu le-a adus).
-- Aditiv si idempotent la runtime: EtollStatementModel::ensureSchema adauga aceleasi coloane daca lipsesc.
--
--   etoll_tranzactii.tx_id      'manual-<hex>' pentru inregistrarile manuale (la sesiuni si session_id)
--   etoll_tranzactii.observatii nota operatorului (motivul, sursa datelor)
--   etoll_tranzactii.adaugat_de utilizatorul care a adaugat-o
--   etoll_tranzactii.inlocuita_de / inlocuita_la
--                               cand eToll aduce totusi aceeasi miscare (acelasi camion, aceeasi suma,
--                               ora la cel mult 6 h): id-ul real; randul manual iese din calcule
--                               (EtollManualService::reconcile).
SET NAMES utf8mb4;

ALTER TABLE etoll_tranzactii
    ADD COLUMN observatii VARCHAR(255) NULL AFTER session_id,
    ADD COLUMN adaugat_de INT UNSIGNED NULL AFTER observatii,
    ADD COLUMN inlocuita_de VARCHAR(80) NULL AFTER adaugat_de,
    ADD COLUMN inlocuita_la DATETIME NULL AFTER inlocuita_de;
