# Audit logică — modul Carburanți / Alimentări

Data: 19.08.2026 · Scop: verificarea corectitudinii logicii de consum **înainte** de testarea integrării API.
Nicio linie de cod nu a fost modificată. Toate concluziile sunt validate atât pe cod, cât și pe datele reale din `if0_41456552_aplicatie_flota` (108 alimentări, 58 curse, 34 vehicule).

---

## Inventar fișiere implicate

| Rol | Fișier | Observații |
|---|---|---|
| Rutare | `htdocs/index.php:565-567` | `?page=carburanti&action=…` |
| Controller | `htdocs/controllers/FuelController.php` (388 l.) | `index`, `sync_now`, `link_fillup`, `set_full` |
| Model (toată logica) | `htdocs/models/FuelModel.php` (2034 l.) | schema, sync, asociere, **toate calculele de consum** |
| Client API | `htdocs/services/CardOilApiClient.php` (430 l.) | normalizare payload CardOil |
| Preț unitar | `htdocs/services/FuelPriceIndexService.php` | `backfillUnitPrices()` — doar tarifare, nu consum |
| View | `htdocs/views/carburanti/index.php` (86 KB) | 5 tab-uri; grafice SVG generate în PHP |
| CLI / cron | `scripts/sync_cardoil_alimentari.php` + `scripts/run_sync_cardoil.bat` | Task Scheduler 03:00, luna curentă |
| Tabele | `fuel_fillups`, `fuel_trip_links`, `fuel_sync_logs`, `fuel_sync_state`, `curse_dispecer`, `vehicule` | create automat de `ensureSchema()` |
| JS | — | **niciun JS dedicat**; sortarea tabelelor e inline în view |

Nu există repository layer. Nu există serviciu de calcul consum separat — totul e în `FuelModel`.

---

## A. Cum funcționează pagina acum

```
Task Scheduler 03:00 ──> sync_cardoil_alimentari.php
                            └─> FuelModel::syncFromApi()
                                  ├─ CardOilApiClient::fetchFillups()  (GET /alimentari/preluare_alimentari_v.2.4.php)
                                  ├─ normalizeFillup()  → api_id, vehicul, litri, odometru, is_full
                                  ├─ upsertFillups()    → INSERT … ON DUPLICATE KEY UPDATE (toate coloanele)
                                  ├─ refreshAutomaticAssociations()  → fuel_trip_links
                                  └─ refreshFuelTariffMonitoring()

GET ?page=carburanti ──> FuelController::indexAction()
      ├─ ensureSchema()                        (DDL la fiecare request)
      ├─ refreshAutomaticAssociations(from,to) (DELETE+INSERT la fiecare request)
      └─ getDashboardData(filters)
            ├─ getKpiSummary()            → KPI-urile mari
            ├─ getDailyConsumptionChart() → grafic zilnic
            ├─ getTransportBreakdown()    → litri pe tip transport
            ├─ getNormativeInterval()     → tab "Consum normat" (LOGICA T0)
            ├─ getConsumptionByTrip()     → tab "Consum pe curse"
            ├─ getConsumptionByTransport()→ tab "Consum pe tip transport"
            └─ getFillups / getUnassociatedFillups / getTripOptions / logs
```

Filtre: perioadă (implicit luna curentă), vehicule (multi), grup transport, tip carburant.
Acțiuni manuale: `set_full` (toggle Full/Parțial pe o alimentare) și `link_fillup` (asociere manuală alimentare→cursă).

---

## B. Logica T0

### Implementare actuală — `FuelModel::findStartFull()` (`FuelModel.php:1428-1455`)

```php
$windowStart = $monthStart->modify('-3 days')->format('Y-m-d 00:00:00');
$windowEnd   = $monthStart->modify('+3 days')->format('Y-m-d 23:59:59');
```

```sql
SELECT * FROM fuel_fillups
WHERE vehicul = :vehicle AND fuel_type = 'motorina' AND is_full = 1
  AND fillup_datetime BETWEEN :window_start AND :window_end
ORDER BY ABS(TIMESTAMPDIFF(SECOND, fillup_datetime, :month_start)) ASC,   -- (1) cel mai apropiat de 1 ale lunii
         CASE WHEN fillup_datetime >= :month_start THEN 0 ELSE 1 END ASC, -- (2) la egalitate: preferă DUPĂ 1
         fillup_datetime ASC, id ASC
LIMIT 1
```

`$monthStart` = prima zi a lunii din care face parte `filters.date_from`, nu perioada selectată ca atare.

### Regula dorită vs. implementare

| Aspect | Dorit | Implementat | Verdict |
|---|---|---|---|
| Limita inferioară a ferestrei | 29 ale lunii precedente 00:00 | 29 ale lunii precedente 00:00 | PASS |
| Limita superioară a ferestrei | **3** ale lunii curente 23:59 | **4** ale lunii curente 23:59 | FAIL (off-by-one) |
| Doar alimentări FULL | da | da (`is_full = 1`) | PASS |
| Doar motorină | implicit da | da | PASS |
| Fără T0 ⇒ lipsă, nu substituție | da | da — `status='invalid'`, `message='Lipsește full început lună'` | PASS |
| Intervenție manuală pentru T0 lipsă | da | **nu există** — doar toggle Full/Parțial per alimentare | FAIL |

Verificat prin execuție PHP: pentru august 2026, `windowStart = 2026-07-29 00:00:00`, `windowEnd = 2026-08-04 23:59:59`.
`modify('+3 days')` aplicat pe 1 august dă 4 august, nu 3. Fereastra are 7 zile în loc de 6.

### Când există mai multe FULL-uri în fereastră

Selecția este **deterministă**, dar criteriul este **proximitatea temporală absolută față de 1 ale lunii, în ambele direcții** — nu „ultimul FULL înainte de începutul lunii".

Riscul este real: cu un FULL pe 30 iulie și unul pe 2 august se alege **2 august** (122.400 s distanță vs. 136.800 s). T0 cade *după* începutul lunii ⇒ consumul zilelor 1–2 august nu intră în intervalul normat, iar litrii alimentați pe 1–2 august sunt atribuiți lunii precedente. La egalitate perfectă de distanță, tie-break-ul (2) preferă explicit alimentarea de *după* 1 ale lunii.

### VERDICT B: **FAIL**

Două defecte independente:
1. off-by-one pe fereastră (include 4 ale lunii);
2. criteriul „cel mai apropiat în valoare absolută" în loc de „ultimul FULL ≤ început lună, altfel primul FULL > început lună", ceea ce poate alege sistematic un T0 care taie din luna analizată.

Partea bună: **nu selectează arbitrar în afara ferestrei** — comportamentul „fără T0 ⇒ invalid" este corect implementat.

### BLOCAJ REAL: T0 nu poate exista deloc cu date din API

`CardOilApiClient::normalizeFullFlag()` (`CardOilApiClient.php:404-418`) caută `is_full` / `full` / `plin` / `full_tank` sau un câmp `tip_alimentare` care să conțină „full"/„plin". **CardOil nu trimite niciunul.**

Confirmat pe date reale:

```
source_type=api   is_full=0  → 100 rânduri
source_type=test  is_full=1  →   4 rânduri
source_type=test  is_full=0  →   4 rânduri
```

**Toate cele 100 de alimentări reale au `is_full = 0`.** Singurele FULL-uri din sistem sunt 4 rânduri de test injectate manual (`api_id = test-compare-…`).
În fereastra T0 pentru august (29.07–04.08) există 20 de alimentări reale — **niciuna FULL**.

Consecință: tabul „Consum normat" este mort pentru toată flota reală. Cu date de la API, T0 nu se poate stabili niciodată automat.

### CRITICAL: marcajul manual Full este șters la următoarea sincronizare

`upsertFillups()` (`FuelModel.php:317-333`):

```sql
ON DUPLICATE KEY UPDATE
    …
    is_full = VALUES(is_full),        -- VALUES(is_full) = 0 pentru orice rând CardOil
    source_type = VALUES(source_type),
    …
```

Dacă un operator marchează manual o alimentare CardOil ca Full (`set_full`), la următorul run al sincronizării (zilnic 03:00, luna curentă) `is_full` revine la 0. Log-urile confirmă că update-urile chiar se execută: sync #21 → 42 înregistrări primite, 0 inserate, **42 actualizate**.

Cele 4 FULL-uri de test supraviețuiesc doar pentru că `api_id`-urile lor (`test-compare-*`) nu apar niciodată în răspunsul API.

---

## C. Consum determinat din alimentări

Există **două** implementări diferite, folosite în locuri diferite ale aceleiași pagini.

### C1. KPI „Consum mediu Motorină" — metoda odometru

`getKpiSummary()` → `getOdometerConsumptionSummary()` → `getOdometerConsumptionRows()` (`FuelModel.php:985-1038`)

Pentru fiecare alimentare de motorină cu odometru din perioada filtrată:

```sql
previous_odometer_km = (
    SELECT fp.odometer_km FROM fuel_fillups fp
    WHERE normalizat(fp.vehicle_registration) = normalizat(f.vehicle_registration)
      AND fp.fuel_type = 'motorina' AND fp.odometer_km > 0
      AND (fp.fillup_datetime < f.fillup_datetime
           OR (fp.fillup_datetime = f.fillup_datetime AND fp.id < f.id))
    ORDER BY fp.fillup_datetime DESC, fp.id DESC LIMIT 1
)
```

```php
$intervalKm = $currentKm - $previousKm;
if ($previousKm <= 0.0 || $intervalKm <= 0.0) { continue; }   // rând ELIMINAT complet
$row['km'] = $intervalKm;
```

Formula finală (`FuelModel.php:906`):

```
consum = Σ(litri alimentării_i) / Σ(odometru_i − odometru_{i−1}) × 100
```

| Întrebare | Răspuns din cod |
|---|---|
| Ce alimentări sunt folosite | **toate** alimentările de motorină cu odometru > 0 — FULL **și** parțiale, fără distincție |
| Cum e folosit T0 | **deloc**. `is_full` nu apare în această ramură |
| Interval de calcul | de la ultima alimentare cu odometru dinaintea perioadei (subinterogarea nu are restricție de dată — corect) până la ultima alimentare din perioadă |
| Sursa km | exclusiv `fuel_fillups.odometer_km` (câmpul `km_alimentare` / `kilometraj` din CardOil) |
| Alimentări intermediare | toate contează la numărător; nu există noțiune de „intermediar" |
| Închiderea intervalului | nu se cere FULL final |
| Alimentări fără kilometraj | **excluse din medie, dar incluse în KPI „Motorină consumată"** |
| Alimentări fără cursă | irelevant aici — nu se folosesc `fuel_trip_links` |
| Luni cu puține alimentări | dacă rămâne un singur rând valid, media se face pe un singur interval |

**Ipoteza ascunsă:** „litrii alimentați acum = combustibilul consumat de la alimentarea precedentă". Aceasta este adevărată doar dacă **fiecare** alimentare umple complet rezervorul. Cu 0 alimentări FULL în date reale, ipoteza nu este niciodată garantată — eroarea per interval este diferența de nivel în rezervor (poate fi de ordinul sutelor de litri la un camion).

**Fallback:** dacă nu există niciun interval valid (`km <= 0` sau `litri <= 0`), `getKpiSummary()` comută pe km din Dispecer (`getDistinctLinkedKm()`) și pe litrii doar ai alimentărilor asociate unei curse (`getLinkedFuelTotals()`). Sursa e afișată în UI ca „din odometru" / „din dispecer".
De asemenea, dacă filtrul `transport_group` este activ, `canUseOdometerConsumption()` întoarce `false` și **întreaga metodă odometru este dezactivată** — același KPI trece silențios pe altă bază de calcul.

#### Inconsistență numerică măsurată pe date reale (august 2026)

| Mărime afișată | Valoare |
|---|---|
| KPI „Motorină consumată" | **10.385,03 L** (44 alimentări) |
| Litri folosiți efectiv la numărătorul „Consum mediu" | **9.609,14 L** |
| Km folosiți la numitor | 97.986 km |
| Consum mediu rezultat | 9,81 L/100 km |

**775,89 L (7,5 %) apar în cardul „Motorină consumată" dar nu intră în „Consum mediu", afișate una lângă alta ca și cum ar descrie același lucru.**
Defalcare: 301,06 L pe 6 alimentări fără odometru; ~474,83 L pe alimentări care au odometru dar au fost eliminate de `continue` (odometru anterior lipsă sau interval ≤ 0).

### C2. Tab „Consum normat" — metoda FULL→FULL (tank-to-tank)

`getNormativeInterval()` (`FuelModel.php:1192-1259`)

```
T0        = findStartFull(vehicul, monthStart)        // FULL în fereastra ±3 zile
T1        = findNextFull(vehicul, T0.datetime, T0.id) // primul FULL strict după T0, fără limită de timp
km        = T1.odometer_km − T0.odometer_km           // getOdometerKmBetweenFulls()
            └─ dacă ≤ 0 ⇒ fallback pe km din curse    // getTripKmForVehicleInterval()
litri     = Σ quantity_liters WHERE fillup_datetime > T0 AND ≤ T1   // getFuelForVehicleInterval()
norm_l100 = litri / km × 100
```

Aceasta **este** formula corectă `FULL/T0 → alimentări intermediare → FULL următor`, iar excluderea alimentării T0 din sumă (`> :start_datetime`) și includerea celei finale (`<= :end_datetime`) sunt corecte.

Limitări:
- se calculează pentru **un singur vehicul** — cel din filtru sau, dacă filtrul e gol, cel cu cei mai mulți litri (`firstVehicleForNormative()`);
- `findNextFull()` nu are limită superioară de dată — T1 poate fi peste 3 luni, iar rezultatul e etichetat drept „consum normat interval curent";
- fallback-ul pe km din curse amestecă sursele: litri din alimentări, km din Dispecer.

### VERDICT C: **NECESITĂ VERIFICARE / PARȚIAL FAIL**

- C2 (FULL→FULL) — formula este **corectă**, dar **inaplicabilă**: fără `is_full` din API nu are date de intrare.
- C1 (odometru) — este metoda care alimentează efectiv toate KPI-urile, graficele și comparațiile, și **nu respectă deloc logica FULL/T0**. Este o aproximare care ignoră nivelul rezervorului.

Notă terminologică: **nu există nicăieri în cod o normă de consum per vehicul** (nici coloană în `vehicule`, nici tabel de configurare — verificat prin căutare pe tot proiectul). „Consum normat" din UI înseamnă de fapt „consum real calculat pe intervalul dintre două plinuri" — nu o normă.

---

## D. Consum determinat pe cursă

`getConsumptionByTrip()` (`FuelModel.php:1261-1300`)

```sql
SELECT c.id, f.vehicle_registration, c.tip_transport, c.data_inceput, c.data_sfarsit,
       {kmExpr} AS km,
       SUM(CASE WHEN f.fuel_type='motorina' THEN f.quantity_liters ELSE 0 END) AS motorina,
       SUM(CASE WHEN f.fuel_type='adblue'   THEN f.quantity_liters ELSE 0 END) AS adblue,
       SUM(f.total_value) AS total_value
FROM fuel_fillups f
INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
INNER JOIN curse_dispecer  c ON c.id = l.trip_id
WHERE …filtre… AND c.deleted_at IS NULL
GROUP BY c.id, f.vehicle_registration, c.tip_transport, c.data_inceput, c.data_sfarsit, km
```

```php
consum_motorina = km > 0 ? (motorina / km) * 100 : 0
consum_adblue   = motorina > 0 ? (adblue / motorina) * 100 : 0
```

### Ce înseamnă concret „consum pe cursă" aici

**Litrii pompați fizic în timpul cursei, împărțiți la km cursei.** Nu este consum — este *aprovizionare* raportată la km.

| Întrebare | Răspuns din cod |
|---|---|
| Sursa km | `effectiveKmExpr()` (`FuelModel.php:1924-1934`): `compresor && km_dislocare>0 → km_dislocare`; altfel `km_totali>0 → km_totali`; altfel `km_cursa>0 → km_cursa`; altfel 0. Km **declarați de dispecer**, nu GPS, nu odometru |
| Asocierea alimentare→cursă | `findMatchingTrip()` (`FuelModel.php:1395-1426`): același vehicul, cursă neștearsă, și `fillup_datetime BETWEEN start AND end`, unde `start = COALESCE(data_incarcare, data_inceput, data_cursa) + COALESCE(ora_inceput,'00:00:00')`, `end = COALESCE(data_sfarsit, data_inceput, data_cursa) + COALESCE(ora_sfarsit,'23:59:59')`. Se alege cursa cu **intervalul cel mai scurt**, apoi cea mai apropiată de momentul alimentării. Confidence fixă 0,95 |
| O alimentare poate aparține mai multor curse? | **Nu** — `UNIQUE KEY uk_fuel_trip_links_fillup (fillup_id)`. Relație 1:1 |
| Cursele dintre două alimentări | ignorate complet — `INNER JOIN` elimină orice cursă fără alimentare în interval |
| Repartizare proporțională a litrilor | **nu există** |
| Se folosește tipul de transport? | doar la gruparea în `getConsumptionByTransport()` și la alegerea coloanei de km pentru compresor |
| Se folosește norma vehiculului? | **nu există normă** |
| Consum real vs. normat | nu se face distincția |

### Acoperire reală măsurată

```
Total alimentări:            108
Legături automate:             6
Legături manuale:              0
Alimentări nelegate:         102   (94,4 %)
Curse existente:              58
```

Tabul „Consum pe curse" descrie **5,6 %** din alimentări. Restul de 94,4 % apar doar în lista „Alimentări neasociate".

### VERDICT D: **FAIL ca metrică de consum**

Metrica nu are semnificație fizică: un plin de 400 L făcut la începutul unei curse de 100 km produce „400 L/100 km". Este utilă cel mult ca alocare de cost pe cursă, nu ca și consum.

---

## E. Relația dintre cele două metode

| Concept | Unitate | Numărător | Numitor | Metodă |
|---|---|---|---|---|
| Consum mediu (KPI) | L/100 km | litri alimentați cu odometru valid | Δ odometru | C1 |
| Consum normat (tab) | L/100 km | litri între T0 și T1 | Δ odometru între FULL-uri | C2 |
| Consum pe cursă | L/100 km | litri pompați în timpul cursei | km declarați dispecer | D |
| Consum pe tip transport | L/100 km | idem, agregat | idem, `MAX(km)` per cursă | D |
| Consum mediu AdBlue | % | litri AdBlue | litri motorină (**nu km**) | — |
| Cost combustibil | lei | `SUM(total_value)` incl. AdBlue | — | — |
| Cost/km | lei/km | `total_value` | `linked_km` (sursă variabilă) | mixt |

Toate trei poartă eticheta „L/100 km" în UI, deci **par comparabile, dar nu sunt**:

1. **Numitorii sunt din surse diferite** — odometru CardOil vs. km declarați în Dispecer. Nu există nicio verificare de concordanță între ele.
2. **Numărătorii acoperă mulțimi diferite** — C1 ia toate alimentările cu odometru (9.609 L în august); D ia doar cele asociate unei curse (5,6 % din total).
3. **Perimetrul temporal diferă** — C1 = perioada filtrată; C2 = intervalul T0→T1, care poate depăși luna în ambele direcții; D = durata curselor.

Reconcilierea `Σ consum curse ≈ consum din alimentări` **nu este posibilă în starea actuală**, pentru că D acoperă 5,6 % din litri iar km-ii provin din altă sursă.

**Nu există nicio toleranță definită în proiect.** Verificat: niciun prag, nicio constantă de deviație, nicio alertă de discrepanță în `FuelModel`, `FuelController` sau view. Singura verificare de consistență existentă este `FuelPriceIndexService::verifyUnitPriceConsistency()`, care compară `unit_price` cu `total_value / quantity_liters` — preț, nu consum.

---

## F. Probleme identificate

| # | Sev. | Fișier : metodă | Problemă | Impact |
|---|---|---|---|---|
| 1 | **CRITICAL** | `CardOilApiClient.php:404` `normalizeFullFlag()` | API-ul CardOil nu trimite niciun indicator de plin ⇒ toate cele 100 de alimentări reale au `is_full = 0` | Întreaga logică T0 / FULL→FULL este inaplicabilă. Tabul „Consum normat" nu produce niciodată rezultat pentru vehicule reale |
| 2 | **CRITICAL** | `FuelModel.php:317` `upsertFillups()` | `ON DUPLICATE KEY UPDATE … is_full = VALUES(is_full), source_type = VALUES(source_type)` | Marcajul manual Full este șters la fiecare sync (zilnic 03:00). Confirmat: sync #21 a actualizat 42 rânduri. Singura cale de a stabili T0 se auto-anulează |
| 3 | **HIGH** | `FuelModel.php:906` `getKpiSummary()` | „Consum mediu" folosește 9.609,14 L, iar „Motorină consumată" afișează 10.385,03 L, pe același card | Discrepanță de 7,5 % între două numere prezentate ca fiind despre același lucru. Utilizatorul nu poate reface calculul |
| 4 | **HIGH** | `FuelModel.php:985` `getOdometerConsumptionRows()` | Formula presupune că fiecare alimentare umple rezervorul, dar nu verifică `is_full` | Consumul per interval este eronat cu diferența de nivel în rezervor. Nedetectabil din UI |
| 5 | **HIGH** | `FuelModel.php:1010` | `if ($previousKm <= 0 \|\| $intervalKm <= 0) continue;` — rândul e eliminat tăcut | Odometru resetat / introdus greșit ⇒ litrii dispar din medie fără avertisment. Confirmat: 4 astfel de cazuri în date (`GARAJ 39189`, odometru 7534 → 9) |
| 6 | **HIGH** | `FuelModel.php:1428` `findStartFull()` | `modify('+3 days')` pe 1 august dă 4 august, nu 3 | Fereastra T0 are 7 zile în loc de 6; un FULL din 4 ale lunii poate deveni T0 |
| 7 | **HIGH** | `FuelModel.php:1436` `findStartFull()` | `ORDER BY ABS(TIMESTAMPDIFF(...))` alege cel mai apropiat în ambele direcții, cu tie-break spre *după* 1 ale lunii | Cu FULL pe 30.07 și 02.08 se alege 02.08 ⇒ 1–2 august ies din intervalul lunii. T0 poate fi sistematic greșit |
| 8 | **HIGH** | `FuelModel.php:1261` `getConsumptionByTrip()` | Litri pompați în timpul cursei ÷ km cursei, fără repartizare proporțională | Valori fără sens fizic (un plin la începutul unei curse scurte ⇒ sute de L/100 km). Etichetat „Motorină L/100 km" |
| 9 | **HIGH** | — (date + `findMatchingTrip`) | 102 din 108 alimentări (94,4 %) nu au cursă asociată | Toate metricile pe cursă / tip transport descriu 5,6 % din combustibil, fără ca UI-ul să indice acoperirea |
| 10 | **MEDIUM** | `FuelModel.php:876` + `1592` `buildFillupWhere(includeTransport=true)` | `LEFT JOIN curse_dispecer c` + `WHERE c.deleted_at IS NULL` | O alimentare legată **manual** de o cursă ulterior ștearsă dispare din `motorina_liters` și `total_value`. Legăturile manuale nu sunt niciodată reevaluate de `refreshAutomaticAssociations()` (le exclude explicit) |
| 11 | **MEDIUM** | `FuelModel.php:1040` `canUseOdometerConsumption()` | Filtrul „grup transport" dezactivează complet metoda odometru | Același KPI schimbă baza de calcul (odometru → km dispecer) doar prin schimbarea unui filtru |
| 12 | **MEDIUM** | `FuelModel.php:1457` `findNextFull()` | Fără limită superioară de dată | T1 poate fi la luni distanță; rezultatul este totuși etichetat „interval curent" |
| 13 | **MEDIUM** | `FuelController.php:43` `indexAction()` | `refreshAutomaticAssociations()` face DELETE+INSERT la **fiecare GET** | Scrieri în DB pe request de citire; la volum mare devine lent și poate produce concurență între taburi deschise simultan |
| 14 | **MEDIUM** | `FuelModel.php:24` `ensureSchema()` | DDL (`CREATE TABLE`, `ALTER TABLE`, `information_schema`) la fiecare request | Overhead constant; risc dacă userul MySQL pierde drepturile DDL |
| 15 | **MEDIUM** | `FuelModel.php:1192` `getNormativeInterval()` | Se calculează pentru un singur vehicul (cel cu cei mai mulți litri, dacă filtrul e gol) | „Consum normat" descrie 1 din 34 de vehicule, fără ca acest lucru să fie evident |
| 16 | **MEDIUM** | `FuelModel.php:1236` | Fallback km: odometru ⇒ dispecer, în același număr afișat | Amestec de surse într-o singură cifră; sursa e indicată doar printr-un `<span>` discret |
| 17 | **MEDIUM** | `CardOilApiClient.php:283` `normalizeFuelType()` | Întoarce `null` pentru orice produs care nu conține „motorina/diesel/gazole/adblue" | Alte produse de pe card (taxe, spălare, uree la bidon) sunt tăcut aruncate; nu apar în log |
| 18 | **MEDIUM** | `FuelModel.php:1395` `findMatchingTrip()` | Alimentarea trebuie să cadă **strict între** start și sfârșit cursă | Alimentările făcute înainte de plecare sau după sosire, în aceeași zi, rămân neasociate. Este cauza principală a celor 94,4 % nelegate |
| 19 | **LOW** | `FuelModel.php:123` `getVehicleOptions()` | `DISTINCT TRIM(...)` fără eliminarea spațiilor | „B 235 NET" și „B235NET" pot apărea ca opțiuni separate; datele conțin ambele convenții (momentan fără conflict pe același vehicul, dar formatul e neuniform) |
| 20 | **LOW** | `FuelModel.php:1273` | `GROUP BY c.id, f.vehicle_registration, …` | O cursă cu alimentări de la două înmatriculări diferite (eroare de date) apare de două ori, cu km dublați |
| 21 | **LOW** | `FuelModel.php:1490` `getTripKmForVehicleInterval()` | Filtrează după **startul** cursei în interval | Cursele începute înainte de T0 și terminate după sunt excluse integral |
| 22 | **LOW** | — (date) | `GARAJ 39189` există în `fuel_fillups` dar nu în `vehicule`, cu odometru 9 (aparent contor de ore) | Poluează media flotei; nu e filtrat |
| 23 | **LOW** | `FuelModel.php:664` `getConsumptionByVehicle()` | `cost_per_km` folosește `total_value` care include AdBlue, împărțit la km motorină | Cost/km ușor supraevaluat |

### Ce **nu** este o problemă (verificat explicit)

- „Duplicatele" aparente (același vehicul, același timestamp — 6 perechi în date) sunt de fapt perechi **motorină + AdBlue** de pe același bon, cu `api_id` distincte. Corect. `getOdometerConsumptionRows()` filtrează `fuel_type='motorina'`, deci km nu se dublează.
- Idempotența sync-ului: `UNIQUE KEY uk_fuel_fillups_api_id` previne inserarea dublă a aceleiași tranzacții.
- Subinterogarea `previous_odometer_km` **nu** are restricție de dată — corect, permite calcul corect la granița de lună / an.
- `getFuelForVehicleInterval()` exclude corect alimentarea T0 și include corect T1.
- `getConsumptionByTransport()` folosește `MAX(km)` per cursă înainte de agregare — evită dublarea km la curse cu mai multe alimentări.
- Cursele soft-deleted sunt excluse consecvent prin `activeRaceCondition()` pe ramurile automate.

---

## G. Scenariile cerute

### Scenariul 1 — T0 valid
> FULL 31 iulie · lună analizată august · alimentare 5 august · FULL 10 august

- Fereastră: 29.07 00:00 – 04.08 23:59. FULL-ul din 31.07 este singurul candidat ⇒ **T0 = 31 iulie**. Corect.
- `findNextFull()` ⇒ **T1 = FULL 10 august** (5 august nu e FULL).
- km = `odometru(10.08) − odometru(31.07)`; dacă unul lipsește ⇒ fallback pe km din curse.
- litri = alimentarea din 5 august + cea din 10 august (T0 exclus, T1 inclus).
- `norm_l100 = litri / km × 100`. **Corect.**
- **Dar:** KPI-ul mare din capul paginii ignoră complet acest interval și calculează separat pe toate alimentările din 1–31 august prin metoda odometru.

### Scenariul 2 — lipsă T0
> Ultimul FULL 25 iulie · următorul FULL 5 august

Fereastra 29.07–04.08 nu conține niciun FULL ⇒ `findStartFull()` întoarce `null`.

```php
return $base;  // status='invalid', message='Lipsește full început lună', norm_l100 = 0.0
```

- Nu selectează 25 iulie — corect.
- Nu selectează 5 august — corect.
- Cere intervenție manuală? **Parțial** — afișează mesajul, dar **nu există niciun mecanism de setare manuală a T0**. Singura acțiune disponibilă e `set_full` pe o alimentare, iar aceasta e ștearsă la următorul sync (problema #2).

Comportament corect ca principiu, dar fără ieșire practică.

### Scenariul 3 — două FULL-uri în fereastră
> FULL 30 iulie 10:00 · FULL 2 august 10:00 · lună august

Distanțe față de 01.08 00:00: 30.07 → 136.800 s; 02.08 → 122.400 s.

**Se alege 2 august.** Determinist, dar contraintuitiv: T0 cade *în interiorul* lunii analizate, iar consumul dintre 1 și 2 august este atribuit lunii precedente. Alegerea așteptată din perspectiva business ar fi 30 iulie (ultimul plin înainte de începutul lunii).

### Scenariul 4 — alimentări multiple între două FULL-uri
> T0/FULL = 100 L · +40 L · +35 L · FULL +50 L · Δodometru = 1.000 km

**Metoda C2 (tab „Consum normat"):**
```
litri  = 40 + 35 + 50 = 125 L     (cei 100 L ai T0 sunt EXCLUȘI — corect)
km     = 1.000
consum = 125 / 1.000 × 100 = 12,50 L/100 km
```
Corect.

**Metoda C1 (KPI-ul mare, cea care se vede efectiv):**
Fiecare alimentare produce propriul interval; suma litrilor este aceeași (125 L) **doar dacă T0 cade în afara perioadei filtrate**. Dacă T0 este în perioadă, cei 100 L intră și ei la numărător ⇒ `225 / 1.000 × 100 = 22,50 L/100 km`.
**Rezultatul depinde de fereastra de filtrare aleasă de utilizator, nu de fizica alimentărilor.**

### Scenariul 5 — curse multiple între alimentări
> mai multe curse între două FULL-uri

- Fiecare alimentare se leagă de **cel mult o** cursă (`UNIQUE KEY` pe `fillup_id`), și doar dacă momentul alimentării cade strict în intervalul cursei.
- Cursele fără alimentare în intervalul lor **nu apar deloc** în tab (INNER JOIN).
- Nu există repartizare proporțională a litrilor pe km-ii curselor.

Rezultat: `Σ(litri pe curse) ≤ litri totali`, cu egalitate doar dacă fiecare alimentare nimerește o cursă. Pe date reale: **6 din 108**. Suma consumurilor pe curse **nu** este coerentă cu consumul din alimentări și nu poate deveni coerentă fără repartizare proporțională.

---

## H. Ce trebuie validat cu date locale înainte de API

1. **Sursa `is_full`.** Cea mai importantă întrebare deschisă: trimite CardOil vreun câmp care indică plinul? Rulați un sync și inspectați `raw_payload`:
   ```sql
   SELECT JSON_KEYS(raw_payload) FROM fuel_fillups WHERE source_type='api' LIMIT 1;
   ```
   Dacă nu există, trebuie decis un alt mecanism (marcaj manual persistent, prag „litri ≥ X % din capacitatea rezervorului", sau import separat).

2. **Persistența marcajului manual.** Marcați o alimentare CardOil ca Full, rulați `php scripts/sync_cardoil_alimentari.php --from=… --to=…` pe aceeași lună și verificați `is_full`. Confirmă problema #2.

3. **Fereastra T0.** Cu FULL-uri de test pe 03.08 și 04.08, verificați care sunt acceptate. Confirmă off-by-one (#6).

4. **Selecția T0 cu două candidate.** FULL pe 30.07 și 02.08 pe același vehicul ⇒ verificați ce apare la „Full început". Confirmă #7.

5. **Reconcilierea celor două metode pe un vehicul curat.** Alegeți un vehicul cu odometru complet și marcați manual toate alimentările ca FULL. Comparați KPI-ul „Consum mediu" cu „Consum normat" — pe date perfecte ar trebui să coincidă. Dacă nu coincid, problema e în formulă, nu în date.

6. **Calitatea odometrului din API.**
   ```sql
   SELECT vehicle_registration, COUNT(*) FROM fuel_fillups
   WHERE odometer_km IS NULL OR odometer_km = 0 GROUP BY vehicle_registration;
   ```
   Actualmente: 14 din 108 fără odometru, 4 cazuri de odometru descrescător.

7. **Litrii pierduți.** Verificați `Σ motorina_liters` vs. `Σ` litri intrați în calculul mediei. Pe august: 10.385,03 vs. 9.609,14 L. Decideți dacă diferența trebuie afișată explicit sau eliminată.

8. **Acoperirea asocierii cu Dispecer.** 94,4 % dintre alimentări nu au cursă. Verificați dacă e din cauza datelor lipsă din Dispecer sau a condiției strict-între-start-și-sfârșit (#18), încercând o fereastră de toleranță (ex. ±4 h în afara cursei).

9. **Produsele filtrate.** Verificați câte poziții din răspunsul API sunt aruncate de `normalizeFuelType()` — momentan tăcut. Comparați `nr_inregistrari` din meta cu `records_received` din `fuel_sync_logs`.

10. **Normalizarea înmatriculărilor.** Datele conțin atât „B 235 NET" cât și „B235NET". Confirmați că nu există același vehicul sub ambele forme (acum nu există, dar formatul e neuniform și `getVehicleOptions()` nu normalizează).

11. **Vehiculele fantomă.** `GARAJ 39189` nu există în `vehicule` și are odometru 9. Decideți dacă intră în medii.

12. **Toleranța de reconciliere.** Nu există definită în proiect. Trebuie stabilită ca decizie de business, nu inventată în cod.

---

## Verdict final

# LOGICA ESTE PARȚIAL CORECTĂ

Detaliat:

- **Logica T0 / FULL→FULL** (`getNormativeInterval` + helperi): formula este **corectă conceptual** (T0 exclus, T1 inclus, Δodometru), cu **două defecte de selecție** (fereastră cu o zi în plus, criteriu de proximitate absolută în loc de „ultimul FULL înainte de lună"). Comportamentul „fără T0 ⇒ invalid, fără substituție" este **corect implementat**. Însă este **inaplicabilă în practică**, pentru că API-ul nu furnizează `is_full`, iar marcajul manual este șters la fiecare sincronizare.

- **Consumul afișat efectiv în KPI-uri, grafice și comparații** nu folosește deloc T0 și nu verifică `is_full`. Este o aproximare pe diferență de odometru care presupune implicit plin la fiecare alimentare — ipoteză nevalidată de date.

- **Consumul pe cursă** nu este consum, ci raport aprovizionare/km, acoperă 5,6 % din combustibil și nu se reconciliază cu celelalte metode.

**Nu putem avea încredere în valorile de consum afișate în starea actuală.** Numerele sunt plauzibile ca ordin de mărime (9,81 L/100 km pentru august e realist), dar provin dintr-o metodă care nu e cea documentată ca regulă de business, iar cele trei metrici etichetate „L/100 km" în aceeași pagină nu sunt comparabile între ele.

**Recomandare de precedență, înainte de testarea API:** rezolvarea problemelor #1 și #2 (sursa și persistența `is_full`). Fără ele, întreaga logică T0 rămâne cod mort, indiferent de corectitudinea restului.
