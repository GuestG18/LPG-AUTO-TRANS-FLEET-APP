-- =====================================================================
-- Avatar: emoji devine BADGE independent, nu inlocuitor pentru poza
-- 2026-08-20
--
-- CONTEXT
--   Initial `avatar_type` era exclusiv ('none'|'image'|'emoji') si emoji-ul
--   era stocat in `avatar_value`. Noul comportament: poza si emoji coexista —
--   poza este avatarul de baza, emoji-ul este un badge in coltul din stanga-jos.
--
-- SAFETY CONTRACT
--   * Additive: o singura coloana noua, nullable.
--   * Datele existente de tip 'emoji' sunt MUTATE, nu sterse.
--   * Enum-ul `avatar_type` ramane neschimbat (valoarea 'emoji' devine nefolosita,
--     dar nu o eliminam ca sa nu atingem randuri istorice).
--   * `utilizatori.status` (securitate) ramane neatins.
-- =====================================================================

ALTER TABLE utilizatori
    ADD COLUMN avatar_emoji VARCHAR(16) NULL AFTER avatar_value;

-- Migrarea datelor existente: emoji-ul devine badge, baza revine la 'none'.
UPDATE utilizatori
SET avatar_emoji = avatar_value,
    avatar_type = 'none',
    avatar_value = NULL
WHERE avatar_type = 'emoji'
  AND avatar_value IS NOT NULL;
