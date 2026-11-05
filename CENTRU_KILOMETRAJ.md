# Centru Kilometraj (V3)

Pagina `?page=centru_kilometraj` (meniu: **Centru Kilometraj**, înainte de *Km pierduți*).
Implementat 22.10.2026 după macheta aprobată. Meniul, antetul, autentificarea și ACL-ul
aplicației nu au fost schimbate (doar un link nou în meniu și o rută nouă).

## 1. Audit — unde stau deja datele

| Ce există | Cum e folosit |
|---|---|
| `vehicule` — `nr_inmatriculare`, `marca`, `model`, `tip_vehicul`, `status`, `poza_stocata` | Identitatea fiecărui vehicul fizic. Poza: `upload_image_thumb_url('vehicule', poza_stocata, 480)`, aceeași ca în Dispecer curse; lipsă pe disc = placeholder pe tipul vehiculului. |
| `vehicule.capacitate_transport` (reală) vs `vehicule.categorie_capacitate_id` (etichetă) + `vehicule_categorii_capacitate.ordine_afisare` | Grupare pe categorii în ordinea configurată; capacitatea reală doar afișată. Pentru ansamblu, categoria și capacitatea vin de la semiremorcă, cu fallback pe tractor (regula din Dispecer curse / Categorii capacitate). |
| `vehicule_cuplaje` (ultimul cuplaj activ per cap tractor) | Ansamblul e doar relația: un card cu cele două componente; semiremorca cuplată nu mai apare separat. Fiecare componentă își păstrează propriile citiri și T0. |
| SAS Fleet — `SasFleetClient::getTravelSheet` + maparea pe număr din `FleetLivePositionService::getFleetHierarchy` | Odometrul SAS = `segments[].kmIndex`, la **sfârșitul** fiecărui segment (verificat: `kmIndex(n) = kmIndex(n−1) + distance(n)`). Sesiunea SAS e partajată (`storage/cache/sas_session.json`). |
| CardOil — `scripts/sync_cardoil_alimentari.php` → `fuel_fillups` | Odometrul CardOil = `fuel_fillups.odometer_km`, valoarea **efectivă** (corecțiile din Carburanți, `odometer_km_manual`, sunt deja aplicate; la fel vehiculul corectat). Starea sincronizării: `fuel_sync_logs`. Centrul nu face apeluri CardOil proprii. |
| ACL: `permissions/modules/*.php` + garda din router | Modul nou `centru_kilometraj`: `view`, `history`, `t0`, `settings`, `audit`. |
| Cron: wrapper-e `run_sync_*.sh` cu `flock` | `scripts/run_sync_centru_kilometraj.sh` după același model. |

### Ce înseamnă fiecare sursă (nu sunt interschimbabile)

- **SAS Fleet**: odometru **GPS** cumulativ. **Nu e calibrat pe bord** (diferențe de zeci de mii de km față de bord, documentat deja în `FuelKmCheckModel`). Km de la T0 = diferența a două citiri SAS, deci km GPS reali.
- **CardOil Refill**: odometrul **tastat de șofer la pompă**, la momentul alimentării. O citire pe **eveniment**: între alimentări nu există valori, iar ora afișată e ora alimentării, nu a sincronizării. Conține greșeli de tastare (ex. B 615 NET: 689930 între 680850 și 682690; 667178; santinela 16777215), marcate ca anomalii.

### Limitări confirmate (documentate, nu ascunse)

1. **Nicio semiremorcă nu există în SAS Fleet** (48 de vehicule SAS, niciun număr de semiremorcă) și **nicio semiremorcă nu are alimentări** în `fuel_fillups`. Toate cele 4 sloturi ale ansamblului sunt afișate, dar ale semiremorcii arată „Date indisponibile pentru această sursă”. Nu se copiază nimic de la tractor.

### Semiremorci (din 2026-10-28 / 2026-11-02)

- **Odometrul semiremorcii e unul singur, manual** (`vehicle_odometer_manual_readings`): operatorul citește hubodometrul. Cardul arată doar acest odometru; T0 = cea mai apropiată citire manuală la ±`manual_varsta_max_ore` (24 h).
- **Verificare cu capul tractor**: fiecare citire se compară cu cea anterioară: km semiremorcii vs km SAS ai capului cuplat pe toată perioada (`vehicle_odometer_manual_checks`). Odometrul SAS la un moment = interval [înainte, după] când capul era în mers. Peste `manual_toleranta_km` (implicit 20 km) = anomalie.
- **Km semiremorcii în restul aplicației** (`TrailerSasKmService`, `scripts/sync_km_semiremorci.php`): de la `km_center_setari.semiremorci_sas_de_la` (fixată la prima rulare = următorul 00:00), `vehicule.km_bord` / `km_revizie` ale semiremorcii cresc din km SAS (distanța pe segmente) ai capului tractor, pe ferestrele de cuplare datate; cursele din Dispecer datate de la comutare nu mai adaugă km semiremorcii. Contorul rămâne contor (anvelope, piese, revizii lucrează pe diferențe, fără salt); ce s-a numărat înainte nu se schimbă. Zile închise, o singură dată (`semiremorci_km_sas`), jurnal în `semiremorci_km_sas_log`.
2. **kmIndex apare doar pentru ferestre de mai mult de o zi calendaristică** (verificat 22.10.2026: de la azi 00:00 → `kmIndex` null; de la ieri 00:00 → prezent). Sincronizarea cere mereu de la cel târziu ieri 00:00. Limitele segmentelor depind de fereastră (un segment tăiat de începutul ferestrei vine scurtat), iar kmIndex variază cu fracțiuni de metru între cereri; identitatea citirii e `carId@sfârșit_segment`.
3. Câteva vehicule SAS nu raportează deloc kmIndex (ex. B 34 NET): au mapare SAS, dar nicio citire, deci nu sunt considerate monitorizate pe SAS.
4. **Nu există o regulă de business pentru „km oficiali”.** Totalul oficial e calculat doar după politica aleasă explicit în pagină (KPI *Total km de la T0*): *SAS Fleet*, *CardOil Refill* sau *SAS, altfel CardOil*. Implicit **neconfigurată**: se afișează km per sursă, niciodată însumați.
5. Maparea pe furnizor e automată după numărul de înmatriculare (convenția existentă în aplicație). Nu am adăugat un ecran de mapare manuală; tabelul `vehicle_odometer_sources` o suportă (`enabled`, `external_provider_vehicle_id`).

## 2. Reguli de calcul (`htdocs/services/OdometerCalculator.php`, pur, testat)

- **T0 SAS**: ultima citire cu `measured_at ≤ T0`. E **exactă** dacă vehiculul a stat parcat de la citire până la T0 (nicio deplasare din foaia de parcurs, inclusiv segmentele fără kmIndex, nu se suprapune intervalului). Dacă era în mers, se acceptă doar în toleranța configurabilă (implicit **15 min**); altfel *în afara toleranței* și nu se salvează.
- **T0 CardOil**: ultima alimentare validă `≤ T0`, acceptată dacă nu e mai veche de `cardoil_varsta_max_ore` (implicit 72 h). Fără toleranța de 15 min (citirile sunt pe eveniment). Momentul real al alimentării se păstrează și se afișează. **Nicio interpolare.**
- **Km de la T0**, separat pe sursă: `curent − referință`. Negativ → anomalie, exclus din totaluri.
- **Diferența SAS − CardOil** (diagnostic): SAS se ia **între aceleași două momente** ca CardOil (alimentarea de referință → ultima alimentare), din citirile SAS salvate, doar dacă odometrul SAS la acele momente e exact sau în toleranță. Procent cu numitorul = distanța SAS. Alertă peste max(100 km, 5%).
- **Anomalii**: seria validă = cel mai lung lanț de citiri în care odometrul nu scade și fiecare pas se poate parcurge în timpul scurs (95 km/h + 60 km marjă). Prinde valori prea mari, prea mici și santinele; citirea curentă e ultima validă.
- **Prospețime SAS**: *actual* doar dacă SAS a fost interogat în ultimele 2 h (configurabil); eroarea păstrează ultima citire, dar o marchează *eroare*. CardOil e *eveniment* (niciodată „live”) cât timp sincronizarea CardOil are sub 26 h.
- **Identitate / KPI**: fiecare vehicul fizic activ o singură dată; tractor și semiremorcă nu se adună; sursele nu se adună.

## 3. Baza de date (`database/migrations/2026_10_22_000002_centru_kilometraj.sql`, rollback separat)

- `vehicle_odometer_sources` — mapare vehicul × furnizor + starea ultimei verificări.
- `vehicle_odometer_readings` — istoricul citirilor **SAS** (unic pe `provider, vehicle, source_reading_id`; `INSERT IGNORE` = idempotent). CardOil nu e duplicat aici: rămâne în `fuel_fillups`.
- `vehicle_odometer_baselines` — T0 versionat per vehicul × furnizor: T0 cerut, momentul real al citirii, valoare, tip măsură, id citire, stare, motiv, versiune, utilizator. Versiunile vechi rămân (`is_active = 0`). O sursă fără citire validă se salvează cu motivul; o sursă inexistentă nu se salvează.
- `vehicle_odometer_audit` — fiecare T0 și fiecare schimbare de politică.
- `km_center_setari` — politica oficială, toleranța SAS, prospețimea, vârsta maximă CardOil.

Tabelele se creează și automat (`OdometerCenterModel::ensureSchema`). Nu se modifică niciun tabel existent.

## 4. Sincronizare

- Buton **Actualizează datele**: loturi de 6 cereri SAS (≤ 20 s), repetate până nu mai rămân vehicule „scadente” (neverificate în ultimele 4 min); apoi pagina se reîmprospătează și **rulează doar odometrele care s-au schimbat**.
- Cron (de instalat pe VPS, la 5 minute):
  ```
  */5 * * * * /path/to/app/scripts/run_sync_centru_kilometraj.sh
  ```
  Lacăt (`flock` + lock în PHP), pauză 650 ms între cereri, o reîncercare după 2 s, oprire după 3 erori consecutive, log în `storage/logs/centru_kilometraj_sync.log`.
- Pagina se reîmprospătează singură la 60 s, **doar din baza de date** (fără apeluri API).

## 5. Fișiere

**Noi**: `htdocs/services/OdometerCalculator.php`, `htdocs/services/OdometerProviders.php` (adaptoare SAS / CardOil), `htdocs/services/OdometerCenterService.php`, `htdocs/models/OdometerCenterModel.php`, `htdocs/controllers/OdometerCenterController.php`, `htdocs/views/centru_kilometraj/index.php`, `htdocs/assets/css/centru-kilometraj.css`, `htdocs/assets/js/centru-kilometraj.js`, `htdocs/permissions/modules/centru_kilometraj.php`, `scripts/sync_centru_kilometraj.php`, `scripts/run_sync_centru_kilometraj.sh`, `scripts/test_centru_kilometraj.php`, migrarea și rollback-ul.

**Modificate**: `htdocs/index.php` (require-uri + ruta), `htdocs/views/layout/header.php` (linkul din meniu).

## 6. Teste

- `php scripts/test_centru_kilometraj.php` — **57/57**: reguli pure + flux complet pe BD cu furnizori simulați (sincronizare idempotentă, cădere SAS, ansamblu cu 4 sloturi fără citiri fabricate, T0 independent pe surse și versionat, totaluri fără dublă numărare, categorii în ordinea configurată, capacitate reală ≠ categorie, poze reale / placeholder, ACL). Fixture-uri `KMCTEST-*`, șterse la final; mapările reale nu sunt atinse.
- În browser (Playwright, 1672×941, 768, 375): fără animație la prima afișare sau la date neschimbate; update SAS → rulează doar SAS-ul acelui vehicul, CardOil își păstrează ora reală; update CardOil → doar CardOil; `prefers-reduced-motion` fără tranziție; fără scroll orizontal pe tabletă / mobil. La o sincronizare reală, exact cele 5 odometre SAS schimbate au rulat.
- Integrare reală (local): 44 vehicule SAS verificate în ~30 s, ~5.800 de citiri istorice; căutare T0 reală pe B 165 NET (SAS 18.10 02:36 parcat până la T0; CardOil 16.10 22:00).

## 7. Odometrul CAN (de bord) — din 02.11.2026

- **Sursă a treia, separată**: `can`. Vine din raportul web SAS „Foaie de parcurs cu distanțe din CAN” (`/SASGWebReports/SASGRecordSheetCan.aspx`), pe care API-ul public SAS nu îl are. Cererea folosește un `SessionId` de rapoarte obținut cu tokenul API (`PrivateAPI/getreportssessionid`), deci aceleași credențiale din `.env`. HTML-ul e transformat în date de `SasCanKmService`.
- **Citire** = Index CAN oprire la sfârșitul fiecărui tronson (`can:<carId>@<sfârșit>`), în `vehicle_odometer_readings` cu `provider_code = 'can'`. Maparea = același `carId` ca SAS. Vehiculele fără CAN (ex. B 34 NET, B 70 NET, STC-urile) rămân mapate, dar fără citiri: „fără citire CAN”, nimic inventat.
- **Aceleași reguli ca SAS**: T0 = citirea de la sfârșitul ultimei deplasări înainte de T0 (exactă dacă parcat, altfel toleranța de minute), anomalii pe lanțul valid, prospețime „verificat”. Se sincronizează imediat după SAS-ul aceluiași vehicul (aceeași coadă și lacăt); o eroare CAN nu oprește SAS. Raportul acceptă max. 31 de zile pe cerere, fereastra se împarte.
- **Nu se adună** cu GPS sau CardOil. Politici noi pentru total: *CAN (bord)* și *CAN (bord), altfel SAS Fleet*. T0-urile salvate înainte nu au referință CAN: CAN de la T0 apare după o nouă salvare T0 (versiune nouă, cele vechi rămân).
- Observat la prima sincronizare reală: 38/44 vehicule SAS au CAN; CAN e aproape de odometrul tastat la pompă (B 165 NET: CAN 1.352.399, CardOil 1.348.602, GPS 1.418.050). B 430 NET trimite din când în când 449.500 în loc de 1.140.709 (marcate anomalii) și indexul stă pe loc zile întregi — de verificat.
- Teste: 14 teste noi în `scripts/test_centru_kilometraj.php` (parsare raport, sincronizare, T0, politică, istoric). Schema: `database/migrations/2026_11_02_000002_centru_kilometraj_can.sql` (aplicată și automat).
