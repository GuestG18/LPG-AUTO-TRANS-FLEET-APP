-- =====================================================================
-- Ordinea personalizata a meniului lateral (drag & drop)
-- 2026-09-16
--
-- CONTEXT
--   Fiecare utilizator isi poate rearanja paginile din meniul lateral.
--   Ordinea se tine ca JSON: {"top": [chei...], "groups": {"grup": [chei...]}}.
--   NULL = ordinea implicita din header.php.
--
-- SAFETY CONTRACT
--   * Additive: o singura coloana noua, nullable.
-- =====================================================================

ALTER TABLE utilizatori
    ADD COLUMN sidebar_order TEXT NULL;
