SET NAMES utf8mb4;

-- Corectie de date, una singura.
--
-- Bug: la salvarea unei curse Primar pe o ruta cu MAI MULTE locuri de intoarcere,
-- in `curse_dispecer.loc_intoarcere` se scria toata lista rutei (ex.
-- "Giurgiu,LUGOJ,ORADEA") in loc de punctul ales de dispecer. O cursa are un singur
-- capat de traseu, deci valoarea era invalida.
--
-- Codul e reparat (se salveaza alegerea dispecerului, altfel primul punct din lista).
-- Aici raman de curatat doar cursele salvate INAINTE de reparatie.
--
-- Alegerea reala nu a fost inregistrata nicaieri, deci nu poate fi recuperata: pastram
-- PRIMUL punct din lista, adica exact ce foloseste si codul ca varianta implicita.
--
-- Km si totalul facturat NU se ating: ele au fost calculate din regula de ruta, care era
-- corecta. Se corecteaza strict punctul de intoarcere inregistrat pe cursa.
--
-- Idempotent: dupa rulare nu mai raman valori cu virgula, deci o a doua rulare
-- afecteaza 0 randuri.
--
-- Inainte de rulare poti vedea exact ce se schimba:
--   SELECT id, beneficiar_id, loc_intoarcere,
--          TRIM(SUBSTRING_INDEX(loc_intoarcere, ',', 1)) AS devine
--   FROM curse_dispecer
--   WHERE loc_intoarcere LIKE '%,%';

UPDATE curse_dispecer
SET loc_intoarcere = TRIM(SUBSTRING_INDEX(loc_intoarcere, ',', 1))
WHERE loc_intoarcere LIKE '%,%';
