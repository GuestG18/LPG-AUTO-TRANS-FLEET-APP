-- Fazele urmeaza cursa la stergere: o cursa cu faze se sterge INTREAGA, iar la
-- restaurare isi primeste fazele inapoi. Pana acum stergerea (soft delete pe
-- curse_dispecer) lasa randurile din curse_segmente active, asa ca fazele
-- ramaneau in baza dupa ce cursa disparea din Desfasurator.

ALTER TABLE curse_segmente
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at,
    ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at,
    ADD KEY idx_segment_deleted (deleted_at);

-- Fazele ramase orfane de la stergerile facute inainte de migrare primesc data
-- si autorul stergerii cursei lor.
UPDATE curse_segmente seg
INNER JOIN curse_dispecer c ON c.id = seg.cursa_id
SET seg.deleted_at = c.deleted_at,
    seg.deleted_by = c.deleted_by
WHERE c.deleted_at IS NOT NULL
  AND seg.deleted_at IS NULL;
