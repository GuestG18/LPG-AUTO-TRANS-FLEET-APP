-- Reluare cursa (segmente legate): o cursa noua poate continua o cursa existenta,
-- pastrand contextul comercial dar cu sofer/vehicul propriu si calcule per segment.
-- Coloana este NULL pentru cursele obisnuite; segmentele de continuare refera cursa-parinte.
ALTER TABLE curse_dispecer
    ADD COLUMN parent_cursa_id INT UNSIGNED NULL DEFAULT NULL AFTER duplicate_key,
    ADD KEY idx_parent_cursa_id (parent_cursa_id);
