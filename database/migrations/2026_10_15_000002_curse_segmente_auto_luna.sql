-- Fazele create de impartirea automata a curselor peste granita de luna
-- (RaceMonthSplitService): 0 = faza operatorului, 1 = continuarea dupa miezul
-- noptii, 2 = cursa insasi, materializata ca prima faza.
-- Coloana se adauga si singura (DispecerCurseModel::ensureRaceSegmentsTable).
ALTER TABLE curse_segmente
    ADD COLUMN auto_luna TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER observatii;
