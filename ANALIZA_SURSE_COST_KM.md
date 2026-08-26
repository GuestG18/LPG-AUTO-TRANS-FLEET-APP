# ANALIZĂ SURSE DATE — Forecast cost operațional / km

**Scop:** reproducerea modelului financiar din `costuri masini 24.06.2022.xls` (sheets `FIXE` + `VARIABILE`) într-o pagină nouă Fleet, alimentată din datele reale existente în aplicație.
**Tip analiză:** read-only. Nu s-a modificat nimic în aplicație sau în Excel.
**Data analizei:** 2026-08-25.
**Surse inspectate:** workbook-ul original (toate formulele extrase cell-cu-cell via Excel COM), codul complet (`htdocs/`, `database/`, `scripts/`), și baza de date live `if0_41456552_aplicatie_flota` (99 tabele, conținut real verificat prin interogări).

---

## A. Executive summary

**Aplicația poate reproduce structural modelul Excel, dar astăzi doar ~40% din valorile de intrare sunt populate cu date reale.** Restul infrastructurii există (tabele, câmpuri, chiar și un agregator de cost/zi pe documente scris dar neapelat), însă valorile financiare sunt în mare parte 0.00 sau NULL.

Clasificare pe cele 28 rânduri Excel (22 fixe + 6 variabile):

| Clasă | Nr. rânduri | Exemple |
|---|---|---|
| **AUTO** (date reale, citibile azi) | 5 | Preț motorină, preț AdBlue, % AdBlue, salariu șoferi, diurnă realizată |
| **CONFIG-READY** (tabelul există, valorile sunt 0/NULL) | 10 | RCA, CASCO, ITP, Rovinietă, Copie conformă, Tahograf, IPROCHIM, Metrologie, dotări ADR, documente șoferi (avize) |
| **DERIVED-BLOCKED** (istoric există, lipsește km-ul asociat) | 4 | Consum L/100km (parțial funcțional), revizii/100k, reparații/100k, anvelope/100k |
| **MISSING** (nu există nicio sursă) | 9 | Impozit auto, asigurare risc, agreare, amortizare (vehicule proprii), telefon/vehicul, TPED, SSM/SU, cursuri șoferi, GPS abonament, curs EUR/RON |

**Cele mai mari lipsuri:**
1. **Curs valutar EUR/RON** — zero infrastructură (niciun API, tabel sau câmp de conversie în toată aplicația).
2. **Amortizare** — nu există preț de achiziție pe vehiculele proprii; `leasing_contracts.initial_value` există ca structură dar are 0 rânduri.
3. **Numitorul km/lună** — nu există nicio agregare lunară de km persistată; sursa cea mai bună (SAS `totalDistance`, 48/48 mașini) trăiește doar în cache JSON, nu în MySQL.

**Cele mai mari riscuri tehnice:**
1. **TVA**: Excel scoate TVA cu `/1.19`; datele CardOil live au `cota_tva = 21.00` pe 100/100 rânduri verificate. Orice reproducere cu constanta 1.19 este greșită pe datele curente (vezi §K).
2. **Trei identități de vehicul necorelate**: `vehicule.id` (FK), `fuel_fillups.vehicle_registration` (string), `carId` SAS (doar în cache) — puntea unică e numărul de înmatriculare normalizat, implementat separat în 3 locuri.
3. **`km_totali` (km reali) din curse nu este de încredere** — în sept/oct 2026 este copiat identic din `km_cursa`; în iulie este sub 50% completat.
4. **Dublă numărare semiremorci**: km-ul curselor se propagă prin `vehicule_cuplaje` și pe tractor și pe semiremorcă în `vehicule.km_bord`.

---

## B. Modelul de calcul Excel (formule extrase, validate numeric)

### B.1 Structura workbook-ului

3 sheets: `FIXE` (37×10), `VARIABILE` (12×9), `Costuri consumabile` (ascuns; conține doar `Anvelope | 2700` — **nereferit de nicio formulă**, mort).

Coloanele de categorii (identice pe ambele sheets): **C/D = CAP TRACTOR**, **D/E = SEMIREMORCA**, **E/F = 7 TO**, **F/G = 10 TO**, **G/H = 13 TO** (VARIABILE e decalat cu o coloană).

### B.2 Parametrii manuali (celulele de input)

| Celulă | Valoare | Rol |
|---|---|---|
| `FIXE!A1` | **5.00** | **Curs EUR/RON**. Rol dublu: (a) convertește rezultatele finale lei→EUR; (b) convertește în lei trei costuri fixe introduse în EUR (metrologie, amortizare, trusă ADR) |
| `FIXE!A15` | **5.500** | Salariu brut lunar de bază (input ajustabil) |
| `FIXE!C26:G26` | **7.000** | km/lună per categorie (același pentru toate) |
| `VARIABILE!B1` | **7,50** | Preț motorină lei/l **cu TVA** |
| `VARIABILE!B2` | **5,90** | Preț AdBlue lei/l **cu TVA** |
| `VARIABILE!B3` | **55** | Diurnă lei/zi |

### B.3 Costurile fixe (FIXE, rândurile 3–24) — valori anuale în lei, cu 4 excepții

Rândurile simple sunt sume anuale în lei introduse direct (IMPOZIT 1.100, IPROCHIM 1.085, COPIE CONFORMĂ 260, ASIGURARE RISC 400, ITP+AGREARE 1.000, TAXE DRUM 5.987/3.562, CASCO, RCA, GPS 950, ECHIPAMENTE 1.000, TAHOGRAF 450, EXTINCTOARE 50–200, ANALIZE 250, SSM/SU 250, CURSURI 100).

Excepțiile cu formulă:

| Rând | Formulă | Semnificație |
|---|---|---|
| TELEFON (r12) | `=35*12` | 35 lei/lună × 12 |
| SALARIU (r15) | `=A15*1.75*12` = **115.500** | vezi §G |
| MANAGEMENT (r23) | `=25705*12` = **308.460** | 25.705 lei/lună cost birou, anualizat |
| REVIZII etc. | — | (pe sheet-ul VARIABILE) |

### B.4 Formula totalului fix anual (rândul 27) — cu tratamente speciale

```
=C3+C4+C5+C6+C7+C8+C9+C10+C12+C13 + C14/3*$A$1 + C16+C15+C17+C19+C20+C21+C22
 + C23/15 + C24 + C11/6*$A$1 + C18/3*$A$1
```

Adică, în notație lizibilă:

```
CostFixAnual = Σ(costuri anuale în lei)
             + METROLOGIE_EUR / 3 ani × curs      (r14: valoare în EUR, amortizată pe 3 ani)
             + AMORTIZARE_EUR / 6 ani × curs      (r11: valoarea vehiculului în EUR, pe 6 ani)
             + TRUSA_ADR_EUR / 3 ani × curs       (r18: valoare în EUR, pe 3 ani)
             + MANAGEMENT_anual / 15 vehicule     (r23: cost birou împărțit la 15 vehicule)
```

**Constatări cheie (nu sunt evidente din valorile afișate):**
- **AMORTIZARE, METROLOGIE și TRUSĂ ADR sunt introduse în EUR**, nu în lei (ex. AMORTIZARE cap tractor = 25.000 EUR → 25.000/6×5 = 20.833 lei/an; semiremorcă 95.000 EUR/6 ani).
- **MANAGEMENT se împarte la 15** — numărul de vehicule din flotă la data Excel-ului. Este o constantă hard-codată, nu o formulă pe numărul real de vehicule.
- Fiecare rând 3–24 apare exact o dată în sumă (nu există omisiuni sau dubluri).

### B.5 Cost fix / km (rândurile 31–32)

```
FIXE!C31 = (C27 + D27) / (C26 × 12)        ← Cap tractor + Semiremorcă COMBINAT
FIXE!E31 = E27 / (E26 × 12)                ← 7 TO (idem F31, G31)
FIXE!C32 = C31 / A1                        ← EUR/km
```

Adică: `cost fix lei/km = cost fix anual / (km_lună × 12)`. Pentru ansamblu, costurile anuale ale tractorului și semiremorcii se **adună** și se împart la **aceiași km** (semiremorca rulează km-ii tractorului).

### B.6 Costurile variabile (VARIABILE)

| Rând | Conținut | Exemplu formule sursă |
|---|---|---|
| r3 CARBURANT | **consum în litri/km** (0,38 = 38 L/100km cap tractor; 0,32 / 0,34 / 0,38; semiremorcă 0) | literal |
| r4 AD BLUE | litri/km (0,04 / 0 / 0 / 0,03 / 0,03) | literal |
| r5 REVIZII/100.000km | lei per 100.000 km = cost revizie × nr. revizii (`=2500*3`, `=500*2`, `=2300*4`, `=2500*3.5`) | formulă |
| r6 REPARAȚII/100.000km | lei per 100.000 km (13.000; `=400*3*2+650*6+4000`=10.300; 14.800; 16.200; 16.500) | mixt |
| r7 ANVELOPE/100.000km | nr. anvelope × 2.500 lei (`=6*2500`, `=8*2500`, `=10*2500`) | formulă |
| r8 DIURNĂ/lună | zile × B3: `=12*B3`=660 (cap tractor, 13 TO); `=15*B3`=825 (7 TO, 10 TO); semiremorcă — gol | formulă |

### B.7 Formula cost variabil / km (rândul 11)

Pentru o categorie simplă (ex. 7 TO, coloana F):

```
VARIABILE!F11 = $B$1/1.19 × F3          ← motorină: preț fără TVA × litri/km
              + $B$2/1.19 × F4          ← AdBlue:   preț fără TVA × litri/km
              + F5/100000 + F6/100000 + F7/100000   ← revizii+reparații+anvelope, lei/km
              + F8/7000                 ← diurnă lunară / km pe lună
```

**Semnificația fiecărei constante (cerută explicit la §10 din brief):**
- `$B$1`, `$B$2` = prețul motorinei / AdBlue-ului cu TVA (input manual);
- `/1.19` = scoaterea TVA de 19% (cota din 2022) — combustibilul e singurul cost de-TVA-izat;
- `/100000` = normalizarea valorilor „per 100.000 km" la lei/km;
- `/7000` = **km/lună hard-codat** — identic cu `FIXE!C26`, dar NU este o referință la acea celulă; dacă se schimbă km/lună în FIXE, sheet-ul VARIABILE rămâne pe 7.000 (defect de design al Excel-ului, de corectat în aplicație printr-un singur parametru).

Pentru **Cap tractor + Semiremorcă** (`D11`), formula adună **ambele coloane** — termenii tractorului (D3..D8) plus termenii semiremorcii (E3..E8). Practic: un singur combustibil/AdBlue/diurnă (semiremorca are 0 la acestea), dar revizii+reparații+anvelope pentru ambele active.

```
VARIABILE!E12/F12/G12/H12 = row11 / FIXE!$A$1     ← EUR/km (notă: rezultatul pt. ansamblu e scris în E12, decalat)
```

### B.8 Totalul final (FIXE, rândurile 35–36)

```
FIXE!D35 = C31 + VARIABILE!D11      ← total lei/km ansamblu (fix combinat + variabil combinat)
FIXE!E35 = E31 + VARIABILE!F11      ← 7 TO (idem F, G — atenție la decalajul de coloană între sheets)
FIXE!D36 = D35 / $A$1               ← EUR/km
```

### B.9 Validare numerică (cerința §18) — scenariul Cap tractor + Semiremorcă

Reconstruit programatic din formulele de mai sus, cu valorile din workbook:

| Mărime | Reconstruit | Excel | Diferență |
|---|---|---|---|
| Cost fix anual cap tractor (C27) | 189.205,00 | 189.205,00 | 0 |
| Cost fix anual semiremorcă (D27) | 90.105,00 | 90.105,00 | 0 |
| **Fix lei/km** = (C27+D27)/(7000×12) | **3,3251190476** | 3,3251190476 | 0 |
| **Variabil lei/km** (D11) | **3,3055630252** | 3,3055630252 | 0 |
| **Total lei/km** (D35) | **6,6306820728** | 6,6306820728 | 0 |
| **EUR/km** (D36, curs 5,00) | **1,3261364146** | 1,3261364146 | 0 |

Reproducere **exactă la precizie de virgulă mobilă completă** — modelul este integral înțeles; nu există formule ascunse sau dependențe externe.

---

## C. Maparea surselor — COSTURI FIXE

Legendă clasificare: **AUTO** = citibil automat azi · **CONFIG** = parametru de configurare (tabelul există sau trebuie creat) · **DERIVED** = calculabil din istoric · **MISSING** = fără sursă.

| Cost Excel | Sursă în aplicație | Tabel | Câmpuri | Relație vehicul | Periodicitate | Clasă | Note |
|---|---|---|---|---|---|---|---|
| IMPOZIT AUTO | NOT FOUND / REQUIRES NEW SOURCE | — (doar categoria firm-level „Taxe și impozite" în `administrative_expense_categories`, 0 cheltuieli) | — | — | anual | **MISSING** | Nu există câmp de impozit per vehicul |
| IPROCHIM | Config costuri documente vehicule | `configurare_costuri_documente_vehicule` | `document_cost`, `validity_days` (`document_type='IPROCHIM'`, pe semiremorci+camion) | via `vehicle_type` + override per `vehicle_id` | `validity_days` (365) | **CONFIG** | Tabel populat cu tipuri, dar **toate costurile = 0,00** |
| COPIE CONFORMĂ | idem | idem (`'Copie conforma'`, cap_tractor+camion) | idem | idem | idem | **CONFIG** | cost = 0,00 |
| ASIGURARE RISC | NOT FOUND / REQUIRES NEW SOURCE | — (doar categoria „Asigurări firmă", firm-level, 0 rânduri) | — | — | anual | **MISSING** | Nu există tip de document dedicat |
| ITP + AGREARE | ITP: config documente; AGREARE: NOT FOUND | `configurare_costuri_documente_vehicule` + `_override` | `document_cost`, `validity_days` | tip + override | `validity_days` | **CONFIG** | Singura valoare reală din tot sistemul: override ITP 200 lei / 61 zile pe `vehicle_id=50`. „Agreare" nu apare nicăieri |
| TAXE DRUM | 3 surse parțiale | (1) `configurare_costuri_documente_vehicule` (`'Rovinieta'`, cost 0); (2) `vehicle_authorizations` (`cost` DECIMAL(12,2), `zone_id`, `start_date`/`end_date`); (3) `curse_cheltuieli` (`tip_cheltuiala='taxe_drum'`, 4 rânduri realizate, 419 lei) | — | (1) tip; (2) `vehicle_id`; (3) via `cursa_id`→`vehicle_id` | (1) 365 z; (2) fereastra autorizației; (3) eveniment | **CONFIG + DERIVED** | `vehicle_authorizations` e cel mai apropiat de „taxe drum" cu valabilitate; `curse_cheltuieli` dă istoricul realizat |
| CASCO | Config documente | `configurare_costuri_documente_vehicule` (`'CASCO'`, toate tipurile) | `document_cost`, `validity_days` | tip + override | 365 z | **CONFIG** | cost = 0,00; polițele scanate există în `documente` (65 rânduri CASCO) dar fără câmp de valoare |
| RCA | idem | idem (`'RCA'`) | idem | idem | 365 z | **CONFIG** | cost = 0,00; 63 documente RCA scanate, fără valoare |
| AMORTIZARE | NOT FOUND pentru vehicule proprii; leasing ca structură goală | `leasing_contracts` (`initial_value`, `advance_amount`, `total_installments`, `currency`), `leasing_installments` (`amount`, `due_date`) | multe | `vehicle_id` | rate `frequency` | **MISSING** (0 contracte; niciun `pret_achizitie`/`data_achizitie` pe `vehicule`) | Excel: valoare EUR / 6 ani × curs. Aplicația nu are valoarea vehiculului nicăieri |
| TELEFON | NOT FOUND per vehicul | — (doar categoria office „Internet / telefonie", 0 rânduri) | — | — | lunar | **MISSING** | Excel: 35 lei/lună |
| TPED | NOT FOUND / REQUIRES NEW SOURCE | — (zero apariții în cod/schemă) | — | — | anual | **MISSING** | |
| METROLOGIE (BRML) | Config documente | `configurare_costuri_documente_vehicule` (`'METROLOGIE'`, semiremorca_distributie+camion); în `documente` există 30 rânduri METROLOGIE/BRML/MID | `document_cost`, `validity_days` | tip + override | 365 z (Excel: 3 ani, în EUR!) | **CONFIG** | Atenție: Excel amortizează pe 3 ani și valoarea e în EUR; `validity_days` configurat azi = 365 |
| SALARIU | Salarii reale șoferi | `soferi.salariu` (26/27 șoferi activi populați, medie 5.000 lei), `salary_history` (time series), `employee_employment_periods.salary` | `salariu` DECIMAL(10,2) | `soferi.vehicle_id` + `soferi_vehicule` (multi-vehicul, `is_primary`) | lunar | **AUTO** (baza) + **CONFIG** (multiplicatorul 1,75) | Vezi §G. Aplicația NU are date de contribuții angajator |
| GPS | NOT FOUND | — (SAS e doar telemetrie; niciun abonament/cost) | — | — | anual | **MISSING** | |
| ECHIPAMENTE PROTECȚIE (ADR+HAINE) | Inventar dotări | `inventar_dotari_catalog` (`cost_implicit`: Mască 80, Filtru 35, Vestă 30, Trusă Medicală 90, Apă Ochi 45…) + `inventar_dotari_vehicule` (`cost`, `data_achizitiei`, `interval_inspectie_luni`, `data_expirarii`) + `inventar_dotari_reguli` (ce dotare la ce tip de vehicul) | `cost`, `cost_implicit` | `vehicle_id` (alocare; azi 1 rând alocat) | `interval_inspectie_luni` / expirare | **CONFIG** (catalog populat, alocări aproape goale) | UI-ul propriu numește explicit modulul „costuri fixe asignate vehiculelor" |
| TRUSĂ ADR | idem | `inventar_dotari_catalog`: **„Trusă ADR" = 250,00 lei, inspecție 12 luni** | idem | idem | 12 luni (Excel: 3 ani, EUR) | **CONFIG** | |
| TAHOGRAF | Config documente | `configurare_costuri_documente_vehicule` (`'Tahograf'`, cap_tractor+camion); 38 documente scanate | `document_cost`, `validity_days` | tip + override | 365 z | **CONFIG** | cost = 0,00 |
| EXTINCTOARE | Inventar dotări | `inventar_dotari_catalog`: **„Extinctor" = 120,00 lei, inspecție 12 luni** | `cost_implicit` / `cost` | `vehicle_id` | 12 luni | **CONFIG** | |
| ANALIZE MEDICALE + PSIHOLOGICE | Documente șoferi + config costuri | `documente_soferi` (tipuri reale: AVIZ MEDICAL ×29, AVIZ PSIHOLOGIC ×29, MEDICINA MUNCII ×30, cu `data_expirare`) + `configurare_costuri_documente_soferi` (`driver_id`, `document_type`, `document_cost`, `validity_days`) | `document_cost`, `validity_days` | **per șofer** (nu per vehicul) → alocabil pe vehicul prin `soferi.vehicle_id` | `validity_days` | **CONFIG** | Tabelul de costuri șoferi are **0 rânduri**; documentele există |
| SSM/SU | NOT FOUND / REQUIRES NEW SOURCE | — | — | — | anual | **MISSING** | |
| MANAGEMENT (OFFICE) | Module cheltuieli birou + administrative | `office_expenses` (+`monthly_rent_amount`, `rent_period_*`) și `administrative_expenses` (`amount_net`, `vat_amount`, `amount_total`, `expense_date`, `category_id`); categoria **„Salarii birou" e automată** — calculată live din `staff_members.salariu`+`salary_history` unde `staff_types.category='office'`; `office_expense_categories.expense_scope` separă `administrative` de `operational` | vezi §J | **firm-level, fără vehicle_id** | lunar (pe `expense_date`) | **AUTO-READY** (structură + salarii birou automate; cheltuielile propriu-zise au 0 rânduri) | Excel: 25.705 lei/lună împărțit la **15 vehicule** (constantă). Alocarea = decizie viitoare (vezi §J) |
| CURSURI ȘOFERI | NOT FOUND / REQUIRES NEW SOURCE | — (doar categoria administrativă „Resurse umane / Training", 0 rânduri) | — | — | anual | **MISSING** | |

**Infrastructură-cheie deja scrisă dar NEFOLOSITĂ:** `DocumentModel::getVehicleDocumentDailyCost(int $vehicleId)` (`htdocs/models/DocumentModel.php:1389-1455`, duplicat identic în `htdocs/includes/helpers.php:394`) calculează deja `Σ(document_cost / validity_days)` = **lei/zi pe documente**, cu precedență override-per-vehicul → config-per-tip și normalizarea tipurilor legacy. Niciun apelant în toată aplicația. Este exact primitiva de care are nevoie pagina de forecast.

---

## D. Maparea surselor — COSTURI VARIABILE

| Cost Excel | Sursă | Tabel/Serviciu | Cum se obține | Clasă | Note |
|---|---|---|---|---|---|
| CARBURANT (preț) | CardOil API, sincronizat | `fuel_fillups.unit_price` (DECIMAL(12,4), `unit_price_source ∈ {api, derived}`; 378/378 = api) + `FuelPriceIndexService::getWeightedDieselPrice()` = `SUM(total_value)/SUM(quantity_liters)` ponderat pe volum, filtrat `source_type='api'` | **dinamic, fără mentenanță manuală** — exact cerința §4 din brief | **AUTO** | Preț **cu TVA** (21% live). Medie curentă ~10,15 lei/l vs 7,50 în Excel |
| CARBURANT (consum L/km) | Motor de consum pe odometru | `FuelModel::getOdometerConsumptionRows()` — delte odometru între alimentări, gardă plauzibilitate 4–120 L/100km, fallback km din curse legate (`fuel_trip_links`); alternativ SAS `CANFuelUsedPer100Km` (40/48 mașini, doar cache) | `consum L/100km / 100 = litri/km` per vehicul/perioadă | **DERIVED** (funcțional, dar acoperire: 42 numere, 1 iul–19 aug 2026) | `vehicule.consum_mediu` există în DB cu valori dar are **zero referințe în PHP** (coloană moartă). Nicio medie lunară persistată |
| AD BLUE (preț) | CardOil | `fuel_fillups` cu `fuel_type='adblue'` (40 rânduri, medie 5,69 lei/l) | idem motorină; **NB:** `FuelPriceIndexService` exclude AdBlue by design — trebuie interogare separată | **AUTO** | |
| AD BLUE (%) | Calculat deja | `FuelModel`: `adblue_percent = litri_adblue / litri_motorina × 100` | raport, nu L/100km | **AUTO/DERIVED** | |
| REVIZII / 100.000 km | Istoric mentenanță | `mentenanta` cu `record_type='intretinere'` (125 rânduri, dar total doar 780 lei; `cost`, `cost_manopera`, `cost_piese`, `data_interventie`, `km_interventie`) | conceptual: `Σcost(intretinere, perioadă) / km_parcurși(perioadă) × 100000` | **DERIVED-BLOCKED** → azi **CONFIG** | **Blocant: `km_interventie` = NULL pe 128/128 rânduri.** Numitorul km trebuie luat din altă sursă (vezi §E); volumul de istoric e deocamdată nesemnificativ |
| REPARAȚII / 100.000 km | idem | `mentenanta` cu `record_type='reparatie'` (3 rânduri, 1.090 lei) + registrul paralel `ocr_piese_registru` (`pret`+`pret_manopera`, `vehicle_id`, `km_bord`, 2 rânduri) + `invoice_repair_parts` (net/TVA/brut — dar TVA scris mereu 0) | idem | **DERIVED-BLOCKED** → azi **CONFIG** | `ocr_piese_registru` e un **ledger separat care nu curge în `mentenanta`** — risc dublă numărare la UNION. `supplier_invoices`/`invoice_*` sunt DERIVATE (șterse și regenerate din `mentenanta` la fiecare încărcare a paginii) — nu se adună la `mentenanta` |
| ANVELOPE / 100.000 km | Modul anvelope | `anvelope.purchase_price` + `estimated_life_km` + `anvelope_alocari` (`vehicle_id`, `km_start`, `km_end`) + `vehicule.anvelope_km_durata` | conceptual: `Σ(purchase_price) / Σ(km_end−km_start) × 100000` sau `nr_anvelope × preț / durata_km` | **DERIVED-BLOCKED** → azi **CONFIG** | **Blocant: `purchase_price` = NULL pe 150/150 anvelope.** Capcană: montajul creează rânduri `mentenanta` `'Anvelopa - …'` cu **cost hard-codat 0,00** — anvelopele NU se iau din `mentenanta`. Sheet-ul ascuns Excel avea 2.700 lei/anvelopă |
| DIURNĂ / lună | Cheltuieli pe cursă | `curse_cheltuieli` (`tip_cheltuiala='diurna'`, `suma`, `data_cheltuiala` — 1 rând realizat, 180 lei) + grila dispecer calculează **zile diurnă = durata_cursă_minute ÷ (12×60)** | Excel: `zile/lună × tarif/zi`. Tariful/zi **nu există** configurat nicăieri | **CONFIG** (tarif) + **DERIVED** (zile din durata curselor, sau suma realizată) | Nu există „deconturi" șoferi |

---

## E. Analiza surselor de kilometri (critic — numitorul întregului model)

### E.1 Inventar complet

| # | Sursă | Tabel.câmp | Tip km | Per vehicul | Agregabil lunar | Fiabilitate azi |
|---|---|---|---|---|---|---|
| 1 | Curse dispecer — **km facturați** | `curse_dispecer.km_cursa` (pt. primar forțat din `configurare_rute_primar.km_tarifare` când `km_agreati_manual=0`) | **km facturați** | da (`vehicle_id`) | da (`data_inceput`) | bună pe câmpul în sine; 71/77 curse completate; dar 40/63 vehicule au curse |
| 2 | Curse dispecer — **km reali declarați** | `curse_dispecer.km_totali` („Km efectuați"; sincronizează `vehicule.km_bord` prin delte) | km reali | da | da | **slabă**: 13/77 lipsă; sept/oct = copiat identic din `km_cursa`; iul sub 50% |
| 3 | Curse compresor | `curse_dispecer.km_dislocare` | ambele | da | da | ok pe nișa compresor |
| 4 | **SAS Fleet API** | travelsheet `totalDistance` (GPS, 48/48 mașini) + `segments[].kmIndex` (odometru CAN, 37/48) | **km reali măsurați** | 43/63 vehicule mapate (prin număr de înmatriculare; semiremorcile nu au GPS) | da — 1 apel travelsheet / vehicul / lună funcționează (verificat: interval de 31 zile în cache) | **cea mai bună sursă de km reali**, dar **NEPERSISTATĂ în DB** — doar `storage/cache/*.json` (`sas_dash_odometer.json`, `sas_dash_day_stats.json`, `sas_dash_range_*.json`) |
| 5 | Odometru la alimentare | `fuel_fillups.odometer_km` (+`odometer_km_manual`) → delte în `FuelModel::getOdometerConsumptionRows()` cu gardă 4–120 L/100km | km reali | prin string număr înmatriculare (42 numere) | da | bună calitativ (gardă), dar istoric doar 1 iul–19 aug 2026 |
| 6 | Odometru curent | `vehicule.km_bord`, `km_revizie` | cumulativ | da | **nu** (fără istoric; doar contor) | corupt de propagarea pe semiremorci (dublare) și de editări de curse |
| 7 | Evenimente | `mentenanta.km_interventie` (0/128!), `anvelope_alocari.km_start/km_end` (133 rânduri, copiate din `km_bord`), `ocr_piese_registru.km_bord` (2), `alimentari.km_bord` (2 — modul mort) | punctual | da | nu | sporadic |

### E.2 km facturați vs. km reali

Aplicația face deja distincția formal, în Dashboard-ul Analitic (`DispecerCurseModel::getDashboardAnalyticData()`):
- `kmEffectiveExpr` = `km_totali > 0 ? km_totali : km_cursa` → „km reali";
- `kmBilledExpr` = `km_cursa > 0 ? km_cursa : km_totali` → „km facturați";
- plus `km_nefacturati`, `km_salvati`, `km_exces`.

**Atenție:** singurul cost/km existent în aplicație (`cheltuieli / km` la `DispecerCurseModel.php:6264`) folosește ca numitor **km facturați**. Pentru forecast-ul de cost operațional, brief-ul cere km reali — deci expresia trebuie inversată pe `kmEffectiveExpr`, sau, ideal, pe km SAS.

**Capcană de denumire:** `curse_dispecer.cost_km_primar/_distributie/_mixt/_compresor` sunt **VENIT/km** (`total_facturare / km`), nu cost/km.

### E.3 km/lună

**NOT FOUND** — nu există nicăieri o valoare `km/lună` calculată sau persistată (nici tabel de agregare, nici medie). Pentru echivalentul celulei `FIXE!C26 = 7000`, opțiunile susținute de datele existente sunt:
1. **SAS `totalDistance` pe luna calendaristică** — 1 apel/vehicul/lună, acoperă 43 vehicule mapate (necesită persistare nouă sau apel live cu cache);
2. `SUM(km_totali)` din curse pe lună (incomplet, vezi E.1#2);
3. Delte `fuel_fillups.odometer_km` pe lună (fereastră istorică scurtă);
4. Parametru manual de forecast (comportamentul Excel actual).

Semiremorcile nu au km propriu în nicio sursă reală — moștenesc km-ul tractorului cuplat (`vehicule_cuplaje`), exact ca ipoteza Excel („semitrailer km = tractor km"). La agregare pe `vehicule.km_bord` acest lucru **dublează** km-ii — de evitat.

---

## F. Maparea categoriilor de vehicule Excel ↔ Fleet

Câmpuri reale: `vehicule.tip_vehicul` ENUM(`autovehicul`,`autoutilitara`,`camion`,`cap_tractor`,`semiremorca`,`semiremorca_primar`,`semiremorca_distributie`) + `vehicule.capacitate_transport` DECIMAL(10,2) (tone) + `vehicule_cuplaje` (tractor↔semiremorcă, `activ`, istoric `data_start`/`data_end`).

| Clasă Excel | Entitate Fleet | Câmp determinant | Situație live |
|---|---|---|---|
| CAP TRACTOR | `tip_vehicul='cap_tractor'` | tip_vehicul | 14 active (capacitate 0/NULL — capacitatea vine din semiremorca cuplată, pattern implementat deja în `DispecerCurseModel`/`FuelModel`) |
| SEMIREMORCA | `tip_vehicul IN ('semiremorca_primar','semiremorca_distributie')` (legacy `'semiremorca'` e normalizat la primar de `vehicle_type_label()`) | tip_vehicul | 6 primar (16–20 t) + 6 distribuție (18–20 t) active |
| CAP TRACTOR + SEMIREMORCA | cuplaj activ din `vehicule_cuplaje` (`activ=1`) — conceptul „ansamblu" există în cod (mentenanță, concedii, badge UI) | join | 33 cuplaje istorice |
| 7 TO | `tip_vehicul='camion'` + `capacitate_transport = 7.00` | capacitate | există (active: 7,00) |
| 10 TO | `camion` + `capacitate_transport ∈ {9.50, 10.00}` | capacitate | există |
| 13 TO | **NU EXISTĂ echivalent exact.** Capacitățile reale: activ max 10,00; inactiv există 12,00 | capacitate | **NOT FOUND** — necesită decizie: fie buckete (ex. „>10 t"), fie clasa dispare/se redenumește |

Constatări:
- Bucketele de tonaj („X tone") sunt deja construite **dinamic la afișare** din `capacitate_transport` în `views/dispecer_curse/config.php:322-342` — nu există un tabel de clase de tonaj. Pagina de forecast poate refolosi același mecanism, dar maparea exactă 7/10/13 e o **propunere**, nu o realitate din date.
- `configurare_costuri_documente_vehicule.vehicle_type` folosește un ENUM propriu **fără** `autoutilitara` — normalizarea din `getVehicleDocumentDailyCost()` tratează cazurile legacy.
- Unele `camion` active au `capacitate_transport = 0.00` — necesită curățare de date înainte de bucketare.

---

## G. Analiza salariului

### G.1 Ce face Excel (exact)

```
FIXE!J14 = A15 × 1.75        = 5.500 × 1,75 = 9.625 lei/lună   (cost angajator lunar)
FIXE!C15 = A15 × 1.75 × 12   = 115.500 lei/an
```

- `A15` = salariu de bază lunar, input manual (5.500);
- `1,75` = multiplicator unic de cost angajator (taxe/contribuții/beneficii) — **constantă hard-codată, fără detaliere**;
- `12` = luni; **un singur șofer per vehicul**; semiremorca are salariu 0;
- același salariu pentru toate categoriile motorizate (C=E=F=G).

### G.2 Ce are aplicația

| Element | Sursă | Stare |
|---|---|---|
| Salariu de bază per șofer | `soferi.salariu` | **26/27 șoferi activi populați, medie 5.000 lei** — REAL |
| Istoric salarial (valoare la o dată) | `salary_history` (`current_salary`, `effective_date`, `subject_type='driver'/'staff'`) | funcțional; pattern-ul „salariul lunii X" e deja implementat în `OfficeExpenseModel::getOfficeSalaryForMonth()` |
| Șofer ↔ vehicul | `soferi.vehicle_id` + `soferi_vehicule` (`is_primary`) | există |
| Multiplicator cost angajator (1,75) | — | **NOT FOUND** — aplicația nu are date de contribuții/taxe salariale |
| Salarii personal birou | `staff_members.salariu` + `staff_types.category='office'` | există; alimentează automat categoria „Salarii birou" din cheltuieli birou |

### G.3 Dovezi pentru decizia viitoare (fără a decide acum)

- **Baza salarială poate veni AUTO** din `soferi.salariu`/`salary_history` (real, întreținut).
- **Multiplicatorul 1,75 trebuie să rămână CONFIG** (nu există sursă transacțională).
- Modelul hibrid este direct susținut de date: `cost_salarial_anual(vehicul) = salariu_șofer_asociat × multiplicator_config × 12`, cu fallback pe un salariu mediu de forecast pentru vehicule fără șofer asociat.

---

## H. Analiza Carburant & AdBlue (flux API → formulă)

### H.1 Fluxul existent

```
CardOil API (preluare_alimentari_v.2.4) ──sync (cron + buton)──▶ fuel_fillups
   pu_alimentare  → unit_price (4 zecimale, CU TVA, unit_price_source='api')
   valoare_alimentare → total_value (brut)
   cantitate_alimentare → quantity_liters
   nume_produs → fuel_type ∈ {motorina, adblue}  (matching pe substring — fragil la redenumiri)
   km_alimentare → odometer_km (+ override manual protejat la re-sync)
   cota_tva (21.00 în datele live) → NU se persistă (rămâne doar în raw_payload JSON)

fuel_fillups ──▶ FuelPriceIndexService::getWeightedDieselPrice()
   = SUM(total_value)/SUM(quantity_liters), ponderat pe volum, motorină, source_type='api'
   (deja consumat de modulul de tarife: transport_tariff_versions.reference_fuel_price)

fuel_fillups ──▶ FuelModel — consum L/100km pe delte odometru cu garda 4–120
```

### H.2 Cum alimentează formula Excel

| Termen Excel | Echivalent aplicație | Diferență de tratat |
|---|---|---|
| `B1` (7,50 lei/l motorină cu TVA) | `getWeightedDieselPrice()` — **dinamic, fără întreținere manuală** | preț live ~10,15 |
| `B2` (5,90 AdBlue) | `SUM(total_value)/SUM(quantity_liters)` pe `fuel_type='adblue'` (interogare nouă — indexul exclude AdBlue by design) | medie live 5,69 |
| `/1.19` | de înlocuit cu de-TVA pe cota reală din `raw_payload.$.cota_tva` (21% azi) sau parametru TVA configurabil | **critic — vezi §K** |
| `D3` (0,38 l/km) | `L/100km / 100` din motorul de consum, per vehicul/categorie/perioadă — sau parametru | acoperire limitată (42 numere, 7 săptămâni) |
| `D4` (AdBlue l/km) | din `adblue_percent` × consum motorină, sau direct litri AdBlue / km | idem |

### H.3 Precauții obligatorii (din cod)

1. Filtrați `source_type='api'` — dashboard-ul carburanți NU filtrează și include rânduri demo/test.
2. Nu folosiți `FuelModel::cost_per_km` existent — amestecă AdBlue în costul motorinei și rotunjește la 2 zecimale.
3. AdBlue e exclus din motorul de consum pe odometru — procentul AdBlue vine ca raport de litri.
4. Întrebare deschisă către CardOil (nedecidabilă din cod): dacă prețul stocat e preț listă sau net de discount.

---

## I. Analiza mentenanței (revizii, reparații, anvelope → /100.000 km)

### I.1 Ce există

- **`mentenanta`** — tabela centrală de fapte: `vehicle_id`, `record_type ∈ {intretinere, reparatie}`, `data_interventie`, `km_interventie`, `cost` (+ split `cost_manopera`/`cost_piese`, invariant `cost = manoperă + piese` când splitul e completat), `centru_cost`, `atelier`, `furnizor_piesa`, `zile_imobilizare`, scan factură.
- **Categorii disponibile pentru split:** `record_type` (revizii vs. reparații — cel mai curat), prefixul `'Anvelopa - '` în `tip_interventie` (marker anvelope), `centru_cost` (Motor/Transmisie/Frânare/…, seed prin regex), `technical_category_id` (sporadic).
- **`ocr_piese_registru`** — registru per vehicul din facturi OCR: `vehicle_id`, `data_interventie`, `pret` + `pret_manopera`, `km_bord`, split text `reparatii/inlocuiri/imbunatatiri`, `factura_id` → `ocr_piese_facturi` (`total_factura`, `furnizor`, `moneda`).
- **Anvelope:** `anvelope` (`purchase_price`, `purchase_date`, `supplier`, `invoice_number`, `estimated_life_km`, `current_mileage`, DOT, poziții) + `anvelope_alocari` (`vehicle_id`, `km_start`, `km_end` — fereastra de utilizare în km) + planul pe vehicul (`vehicule.anvelope_km_durata`).

### I.2 Ce lipsește pentru /100.000 km (verificat pe date live)

| Blocant | Măsurat |
|---|---|
| `mentenanta.km_interventie` | **NULL pe 128/128 rânduri** |
| Volum istoric mentenanță | 780 lei întreținere + 1.090 lei reparații, mar–mai 2026 — nesemnificativ statistic |
| `anvelope.purchase_price` | **NULL pe 150/150 anvelope** |
| Cost anvelope în `mentenanta` | hard-codat **0,00** la montaj (`TireModel`) — anvelopele NU se pot lua din mentenanță |
| TVA pe lanțul de mentenanță | coloanele net/TVA/brut din `invoice_repair_parts` există dar **scriitorul pune TVA=0**; `mentenanta.cost` e netipizat (net? brut? necunoscut) |
| Agregare cost/km | **nicio împărțire cost-mentenanță/km nu există nicăieri în cod** — metrică nouă |

### I.3 Forma de calcul susținută de schema actuală (când datele se vor popula)

```
REVIZII/100k(categorie, perioadă)  = Σ mentenanta.cost [record_type='intretinere',
                                       tip_interventie NOT LIKE 'Anvelopa - %',
                                       status <> 'anulata', vehicule din categorie]
                                     / Σ km_reali(aceiași vehicule, perioadă) × 100.000
REPARATII/100k = idem cu record_type='reparatie'  (+ eventual UNION ocr_piese_registru, cu verificare anti-dublare)
ANVELOPE/100k  = Σ anvelope.purchase_price [alocări încheiate] / Σ (km_end − km_start) × 100.000
```

Numitorul km vine obligatoriu din sursele §E (nu din mentenanță). `supplier_invoices`/`invoice_vehicle_repairs`/`invoice_repair_parts` sunt tabele DERIVATE (regenerate din `mentenanta` la fiecare încărcare a paginii de reparații) — nu se folosesc ca sursă și nu se adună la `mentenanta`.

---

## J. Cheltuieli administrative / partajate (MANAGEMENT OFFICE)

### J.1 Ce există

Două module paralele, ambele cu `amount_net` + `vat_amount` + `amount_total` + `expense_date` + categorii:

- **`administrative_expenses`** — 10 categorii seed (Taxe și impozite, Asigurări firmă, Contabilitate/Audit, Consultanță juridică, Licențe, Deplasări, Marketing, Comisioane bancare, HR/Training, Alte). **0 rânduri de date.**
- **`office_expenses`** — 12 categorii seed (Chirie birou cu câmpuri dedicate de contract/chirie lunară, Utilități, Internet/telefonie, IT, …, **Salarii birou**). **0 rânduri de date**, DAR:
  - `office_expense_categories.expense_scope ∈ {administrative, operational}` — separare deja gândită între overhead birou și costuri atribuibile flotei;
  - categoria **„Salarii birou" este automată** (`is_automatic=1`): suma se calculează live din `staff_members.salariu` + `salary_history` pentru `staff_types.category='office'` (`OfficeExpenseModel::getOfficeSalaryForMonth()`), cu introducere manuală blocată în controller.

### J.2 Asocierea cu vehiculele

Ambele module sunt **firm-level: nu există `vehicle_id`, nici centru de cost structurat** (singurul „centru_cost" din aplicație e un VARCHAR liber pe `mentenanta`). Excel rezolvă asta împărțind la constanta **15 vehicule**.

### J.3 Ce permit datele actuale ca bază de alocare (fără a decide regula)

| Bază posibilă | Sursă disponibilă |
|---|---|
| Număr de vehicule active (echivalentul lui „/15") | `COUNT(*) FROM vehicule WHERE status='activ'` — eventual filtrat pe tipurile grele (azi: 14 cap tractor + 15 camioane + 12 semiremorci) |
| Cotă de km | orice sursă din §E, odată persistată lunar |
| Cotă de venit | `curse_dispecer.total_facturare` per vehicul (există) |

Echivalentul lunii `25.705 lei` din Excel ar fi: `Σ office_expenses(lună) + Σ administrative_expenses(lună) + Salarii birou(lună, automat)` — complet AUTO **după** ce modulele încep să fie folosite; azi doar componenta de salarii birou ar produce o valoare reală.

---

## K. Analiza TVA (`/1.19`)

### K.1 În Excel

| Categorie | De-TVA-izare? |
|---|---|
| Motorină (`B1`) și AdBlue (`B2`) | **DA** — `/1.19` (cota 19% din 2022); prețurile de pompă sunt cu TVA, iar modelul lucrează în net |
| Toate costurile fixe (r3–24) | **NU** — introduse ca atare (implicit net sau irelevant-TVA: salarii, impozit, RCA) |
| Revizii / Reparații / Anvelope / Diurnă | **NU** — valorile per 100.000 km intră ca atare |

Deci: **singurele sume de-TVA-izate în Excel sunt cele două prețuri de combustibil.**

### K.2 În aplicație — cum sunt stocate sumele (verificat în cod și date)

| Sursă | Stocare | Compatibilitate cu Excel |
|---|---|---|
| `fuel_fillups.unit_price` / `total_value` | **BRUT (cu TVA)**; `cota_tva` NU e persistată (doar în `raw_payload`); **datele live au 21%, nu 19%** | Reproducerea corectă: `preț_brut / (1 + cota_reală)` cu cota din `raw_payload.$.cota_tva` sau parametru configurabil. **Constanta 1,19 ar fi greșită azi** |
| `administrative_expenses` / `office_expenses` | `amount_net` + `vat_amount` + `amount_total` — **cel mai curat model din aplicație** | folosiți direct `amount_net`; fără risc |
| `mentenanta.cost` | netipizat (fără coloană TVA; probabil amestec) | asumpție de documentat; risc de inconsecvență ±19–21% |
| `invoice_repair_parts` | schema net/TVA/brut completă, dar scriitorul pune `vat_percent=0`, `vat_value=0`, `total_with_vat=value_without_vat` | tratați ca net |
| `ocr_piese_facturi.total_factura` | euristica OCR preferă „total de plată" → **BRUT**; subtotal+TVA sunt detectate dar aruncate de controller | necesită normalizare |
| `ocr_piese_registru.pret` | valoarea liniei de factură → convențional **NET** pe facturile RO, neimpus | asumpție de documentat |
| `anvelope.purchase_price`, `leasing`, `configurare_costuri_documente_*`, `inventar_dotari` | netipizat | de definit convenția la populare |

**Regula anti-dublă-de-TVA-izare pentru motorul de calcul:** fiecare sursă trebuie etichetată la nivel de sursă (`net` / `brut+cotă` / `necunoscut-tratat-ca-net`), iar de-TVA-izarea se aplică **o singură dată, la stratul de normalizare**, niciodată în formulele finale. Singura sursă care necesită azi de-TVA-izare activă este combustibilul (21%).

---

## L. Conversia valutară (EUR/RON)

**Excel:** `A1 = 5,00` manual; `EUR = LEI / A1` la final (r28, r32, r36, VARIABILE r12) — confirmat din formule. Plus rolul secundar: cele 3 costuri introduse în EUR se convertesc în lei cu `× A1` (§B.4).

**Aplicație:** **NOT FOUND / REQUIRES NEW SOURCE — nimic.** Zero integrare BNR, zero tabel `curs_valutar`, zero cod de conversie. Sume exclusiv în lei (etichete „RON"/„lei/km" hard-codate în servicii de facturare/tarifare). Singurele urme de valută: `leasing_contracts.currency` (VARCHAR liber, default `'lei'`, 0 rânduri) și `ocr_piese_facturi.moneda` (detector RON/EUR, fără conversie).

Consecință: cursul EUR/RON rămâne obligatoriu **CONFIG** (parametru editabil de forecast, ca în Excel) sau necesită o integrare nouă (ex. cursul BNR) — decizie ulterioară.

---

## M. Lista explicită a informațiilor fără sursă (MISSING)

1. **Impozit auto** per vehicul — nicio coloană/tabel.
2. **Asigurare risc** — niciun tip de document sau câmp.
3. **Agreare** (din „ITP + agreare") — zero apariții.
4. **Valoarea de achiziție a vehiculelor proprii** (baza amortizării) — nu există; leasingul are structură dar 0 contracte.
5. **Telefon** per vehicul (35 lei/lună) — doar categorie firm-level goală.
6. **TPED** — zero apariții.
7. **SSM/SU** — zero apariții.
8. **Cursuri șoferi** — doar categorie administrativă goală.
9. **Cost abonament GPS** — SAS e doar telemetrie.
10. **Multiplicatorul de cost angajator** (1,75) — fără date de contribuții.
11. **Tarif diurnă lei/zi** — doar sume realizate per cursă, fără rată configurată.
12. **Curs EUR/RON** — nimic.
13. **km/lună** ca valoare calculată/persistată — nimic (surse brute există, agregarea nu).
14. **Clasa „13 TO"** — nicio capacitate activă corespunzătoare în `vehicule`.
15. **Valori populate** pentru: `configurare_costuri_documente_vehicule.document_cost` (toate 0), `configurare_costuri_documente_soferi` (0 rânduri), `anvelope.purchase_price` (toate NULL), `mentenanta.km_interventie` (toate NULL), `administrative_expenses`/`office_expenses`/`leasing_contracts` (0 rânduri) — infrastructura există, datele nu.

---

## N. Diagrama fluxului de date propus

```
┌─────────────────────────────── MODULE FLEET EXISTENTE ───────────────────────────────┐
│                                                                                      │
│  Documente vehicule          Carburanți (CardOil)      Mentenanță + OCR piese        │
│  configurare_costuri_        fuel_fillups              mentenanta / ocr_piese_       │
│  documente_vehicule(+ovr)    FuelPriceIndexService     registru / anvelope(+alocari) │
│                                                                                      │
│  Inventar dotări             Curse dispecer            SAS Fleet API                 │
│  inventar_dotari_*           curse_dispecer            travelsheet totalDistance /   │
│                              (km_cursa / km_totali)    kmIndex  [azi: doar cache]    │
│                                                                                      │
│  Personal & salarii          Cheltuieli birou/admin    Leasing (gol azi)             │
│  soferi.salariu,             office_expenses,          leasing_contracts /           │
│  salary_history              administrative_expenses   installments                  │
└───────────────┬──────────────────────┬──────────────────────────┬────────────────────┘
                ▼                      ▼                          ▼
        ┌───────────────────────────────────────────────────────────────┐
        │  STRAT SURSE FINANCIARE (per vehicul / per categorie)         │
        │  · etichetare TVA per sursă (net / brut+cotă)                 │
        │  · rezolvare identitate vehicul (id ↔ număr ↔ carId SAS)      │
        │  · cuplaje tractor↔semiremorcă (vehicule_cuplaje)             │
        └──────────────────────────────┬────────────────────────────────┘
                                       ▼
        ┌───────────────────────────────────────────────────────────────┐
        │  MOTOR DE NORMALIZARE / CALCUL                                │
        │  · totul → lei/km  (matricea §O.2)                            │
        │  · parametri forecast: km/lună, multiplicator salarial,       │
        │    tarif diurnă, curs EUR/RON, ani amortizare, nr. vehicule   │
        │    pt. alocare management                                     │
        └───────────────┬──────────────────────────┬────────────────────┘
                        ▼                          ▼
              FIX lei/km (per categorie)   VARIABIL lei/km (per categorie)
                        └──────────────┬───────────┘
                                       ▼
                              TOTAL lei/km  ──÷ curs──▶  EUR/km
```

---

## O. Precondiții de implementare (de existat ÎNAINTE de dezvoltare — NU implementate acum)

### O.1 Date de populat (fără schimbări de schemă)
1. `configurare_costuri_documente_vehicule.document_cost` — valorile reale RCA/CASCO/ITP/Rovinietă/Copie conformă/Tahograf/IPROCHIM/METROLOGIE per tip de vehicul (+ `validity_days` corecte — ex. metrologie multi-anuală).
2. `configurare_costuri_documente_soferi` — costuri avize medicale/psihologice/atestate per șofer.
3. `anvelope.purchase_price` (+ `estimated_life_km` unde lipsește).
4. `inventar_dotari_vehicule` — alocările efective de dotări pe vehicule.
5. `leasing_contracts` / sau decizia privind sursa valorii de achiziție (vezi O.2.1).
6. Utilizarea efectivă a modulelor `office_expenses` / `administrative_expenses` (azi 0 rânduri).
7. Disciplina `mentenanta.km_interventie` și `km_totali` pe curse (sau renunțarea la ele în favoarea km SAS).
8. Curățarea `capacitate_transport = 0` la camioanele active.

### O.2 Structuri/decizii noi necesare
1. **Sursă pentru valoarea de achiziție a vehiculelor proprii** (câmp nou pe `vehicule` sau tabel dedicat) + anii de amortizare per categorie (Excel: 6) — pentru AMORTIZARE.
2. **Persistarea km lunari per vehicul** (tabel nou alimentat din SAS travelsheet — 1 apel/vehicul/lună — și/sau din curse), cu marcarea sursei; alternativ, apel SAS live cu cache. Fără asta, `km/lună` rămâne parametru manual ca în Excel.
3. **Tabel/mecanism de parametri de forecast** (nu există niciun store global de setări; singurul pattern e `transport_tariff_settings` key-value, scoped pe modul — comentariul din migrare recomandă explicit pattern-ul per-modul): km/lună per categorie, multiplicator salarial (1,75), tarif diurnă + zile/lună, curs EUR/RON, cota TVA combustibil, nr. vehicule pentru alocarea management, ani amortizare (metrologie 3, trusă ADR 3, vehicul 6).
4. **Maparea categoriilor** (Cap tractor / Semiremorcă / 7/10/13 TO) — pe `tip_vehicul` + buckete `capacitate_transport`, cu rezolvarea clasei „13 TO" (§F).
5. **Serviciu nou de calcul** (ex. `CostForecastService`) care implementează matricea de normalizare și specificația §P; refolosește `getVehicleDocumentDailyCost()` (există, neapelat), `FuelPriceIndexService`, motorul de consum din `FuelModel`, `VehicleCouplingModel`.
6. **Reguli anti-dublare**: excludere `'Anvelopa - %'` din mentenanță; nefolosirea tabelelor derivate `supplier_invoices`/`invoice_*`; UNION `mentenanta` ⊎ `ocr_piese_registru` doar cu verificare de suprapunere; km semiremorci = km tractor cuplat (nu sumă).
7. **Decizie TVA per sursă** conform §K + confirmare CardOil (preț listă vs. net de discount).

### O.3 Matricea de normalizare (cerința §15)

| Cost | Unitate brută | Perioadă | Alocare necesară | Unitate țintă |
|---|---|---|---|---|
| Documente vehicul (RCA, ITP, …) | lei / `validity_days` | valabilitate document | per tip vehicul (+override per vehicul) | lei/zi → ×365 → lei/an → /(km/lună×12) → **lei/km** |
| Autorizații zone (taxe drum) | lei / fereastră autorizație | `start_date`–`end_date` | per vehicul | lei/an → lei/km |
| Dotări (extinctor, trusă ADR…) | lei / interval inspecție sau ani amortizare | 12 luni / 3 ani | per vehicul (reguli per tip) | lei/an → lei/km |
| Amortizare | valoare achiziție (EUR sau lei) / ani | 6 ani (Excel) | per vehicul | lei/an → lei/km |
| Salariu | lei/lună × multiplicator | lunar | per șofer → vehicul asociat | ×12 → lei/an → lei/km |
| Management/Office | lei/lună (Σ module birou+admin) | lunar | firm-level → împărțire la N vehicule (parametru) | lei/an/vehicul → lei/km |
| Carburant | lei/l (brut, dinamic) | continuu | per vehicul/categorie prin consum l/km | (preț/(1+TVA)) × l/km = **lei/km** |
| AdBlue | lei/l (brut) | continuu | idem | idem |
| Revizii / Reparații | lei (evenimente istorice) | istoric ales | per vehicul + km aceleiași perioade | lei/100.000km → /100.000 → lei/km |
| Anvelope | lei/anvelopă / durata km | ciclu de viață | per vehicul prin alocări | lei/100.000km → lei/km |
| Diurnă | lei/zi × zile/lună | lunar | per șofer/categorie | /(km/lună) → lei/km |
| Total | — | — | — | lei/km → /curs → **EUR/km** |

---

## P. Specificația de formule (implementabilă fără a redeschide Excel-ul)

**Convenții:** `EUR` = cursul EUR/RON (parametru, ex. 5,00) · `KM_L` = km/lună per categorie (parametru sau derivat, Excel 7.000) · toate sumele în lei net (după normalizarea TVA din §K) · categoriile: CT (cap tractor), SR (semiremorcă), C7/C10/C13 (camioane pe tonaj) · rotunjire: Excel nu rotunjește niciun rezultat intermediar; afișarea se face la 2 zecimale (lei/km) — motorul trebuie să calculeze în precizie completă și să rotunjească doar la afișare.

### P.1 Cost fix anual per categorie

```
FixAnual(cat) = Σ costuri_anuale_lei(cat)                        [impozit, iprochim, copie conformă,
                                                                  asigurare risc, ITP+agreare, taxe drum,
                                                                  CASCO, RCA, GPS, echipamente, tahograf,
                                                                  extinctoare, analize, SSM/SU, cursuri]
              + TELEFON_lunar × 12
              + SALARIU_lunar × MULT_SALARIAL × 12               [MULT_SALARIAL = 1,75 config; SR: 0]
              + MANAGEMENT_lunar × 12 / N_VEHICULE               [N_VEHICULE = 15 în Excel; config]
              + METROLOGIE_EUR / 3 × EUR
              + TRUSA_ADR_EUR / 3 × EUR
              + AMORTIZARE_EUR / 6 × EUR
```
*Surse:* documente → `configurare_costuri_documente_vehicule` (via `getVehicleDocumentDailyCost×365` sau direct `document_cost×365/validity_days`); dotări → `inventar_dotari_*`; salariu → `soferi.salariu`; management → `office_expenses`+`administrative_expenses`+salarii birou automate; restul → parametri până apar surse.
*Edge cases:* `validity_days=0` → termen 0 (garda există deja în agregatorul din `DocumentModel`); vehicul fără șofer asociat → salariu de forecast; SR nu primește: copie conformă, asigurare risc, taxe drum(CT-nivel), telefon, salariu, GPS, echipamente, trusă ADR, tahograf, analize, SSM, management, cursuri, amortizare-CT (are propria amortizare 95.000 EUR și primește în schimb IPROCHIM, TPED, metrologie).

### P.2 Cost fix lei/km

```
FixKm(C7|C10|C13) = FixAnual(cat) / (KM_L(cat) × 12)
FixKm(CT+SR)      = (FixAnual(CT) + FixAnual(SR)) / (KM_L(CT) × 12)     ← km comuni, costuri însumate
```

### P.3 Cost variabil lei/km

```
VarKm(cat) = PretMotorina_brut / (1 + TVA_c) × ConsumMotorina_l_km(cat)
           + PretAdBlue_brut  / (1 + TVA_c) × ConsumAdBlue_l_km(cat)
           + (REVIZII_100k(cat) + REPARATII_100k(cat) + ANVELOPE_100k(cat)) / 100.000
           + DIURNA_zi × ZILE_DIURNA_luna(cat) / KM_L(cat)

VarKm(CT+SR) = VarKm(CT) + [ (REVIZII_100k(SR) + REPARATII_100k(SR) + ANVELOPE_100k(SR)) / 100.000 ]
               (SR are consum=0, adblue=0, diurnă=0 — contribuie doar mentenanță+anvelope)
```
*Surse:* `PretMotorina_brut` = `FuelPriceIndexService::getWeightedDieselPrice()` (AUTO); `PretAdBlue_brut` = interogare pe `fuel_fillups` `fuel_type='adblue'` (AUTO); `TVA_c` = cota reală din `raw_payload.$.cota_tva` sau parametru (azi 0,21 — **nu 0,19**); consumuri = motorul din `FuelModel` per categorie sau parametru; `*_100k` = §I.3 când datele se populează, altfel parametri; `DIURNA_zi` parametru (Excel 55); `ZILE_DIURNA_luna` parametru (Excel: 12 pentru CT și C13, 15 pentru C7/C10) sau derivat din `durata_cursa_minute/720`.
*Notă de fidelitate:* Excel folosește `/7000` hard-codat aici — în aplicație se folosește `KM_L(cat)` (același parametru ca la fix), ceea ce corectează defectul de design al workbook-ului; la `KM_L=7000` rezultatele coincid exact.
*Edge cases:* `KM_L=0` → eroare de validare (nu division-by-zero); preț combustibil indisponibil (index gol/stale — pragul `fuel_data_stale_days=7` există în `transport_tariff_settings`) → fallback pe ultimul preț persistat + avertisment; consum în afara gărzii 4–120 L/100km → exclus (comportament existent).

### P.4 Totaluri

```
TotalKm(cat)   = FixKm(cat) + VarKm(cat)          [lei/km]
FixEurKm(cat)  = FixKm(cat) / EUR
TotalEurKm(cat)= TotalKm(cat) / EUR               [EUR/km]
FixAnualEur    = FixAnual(cat) / EUR              (echivalentul r28)
```

### P.5 Testul de acceptanță al motorului

Cu parametrii Excel (EUR=5; KM_L=7000; salariu 5.500×1,75; management 25.705×12/15; motorină 7,50; AdBlue 5,90; TVA 19%; diurnă 55×12; valorile fixe din §B.3–B.4 și variabile din §B.6), motorul TREBUIE să reproducă exact:

| Categorie | Fix lei/km | Variabil lei/km | Total lei/km | EUR/km |
|---|---|---|---|---|
| CT + SR | 3,3251190476 | 3,3055630252 | 6,6306820728 | 1,3261364146 |
| 7 TO | 2,2183452381 | 2,5246638655 | 4,7430091036 | 0,9486018207 |
| 10 TO | 2,3935833333 | 2,8589537815 | 5,2525371148 | 1,0505074230 |
| 13 TO | 2,5071746032 | 3,1404831933 | 5,6476577965 | 1,1295315593 |

(Toate verificate împotriva valorilor calculate de Excel în workbook-ul original.)

---

## Harta de trasabilitate — sinteză finală (cerința §19)

```
Rând Excel                → Sursă Fleet                                  → Transformare                        → Clasă
─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
IMPOZIT AUTO              → (nimic)                                      → parametru                           → MISSING
IPROCHIM                  → configurare_costuri_documente_vehicule       → cost×365/validity → /km             → CONFIG
COPIE CONFORMĂ            → idem                                         → idem                                → CONFIG
ASIGURARE RISC            → (nimic)                                      → parametru                           → MISSING
ITP + AGREARE             → config documente (ITP) / nimic (agreare)     → idem                                → CONFIG+MISSING
TAXE DRUM                 → config (Rovinietă) + vehicle_authorizations  → cost/fereastră → anual → /km        → CONFIG+DERIVED
CASCO / RCA               → configurare_costuri_documente_vehicule       → cost×365/validity → /km             → CONFIG
AMORTIZARE                → (nimic; leasing gol)                         → valoare/6 ani → /km                 → MISSING
TELEFON                   → (nimic; categorie office goală)              → lunar×12 → /km                      → MISSING
TPED                      → (nimic)                                      → parametru                           → MISSING
METROLOGIE (BRML)         → config documente ('METROLOGIE')              → cost/3 ani → /km                    → CONFIG
SALARIU                   → soferi.salariu + salary_history              → ×1,75(config)×12 → /km              → AUTO+CONFIG
GPS                       → (nimic; SAS e doar telemetrie)               → parametru                           → MISSING
ECHIPAMENTE / TRUSĂ ADR / → inventar_dotari_catalog + _vehicule          → cost/interval → anual → /km         → CONFIG
  EXTINCTOARE
TAHOGRAF                  → config documente                             → cost×365/validity → /km             → CONFIG
ANALIZE MED.+PSIHO        → documente_soferi + config costuri șoferi     → cost/validity → șofer→vehicul → /km → CONFIG
SSM/SU / CURSURI          → (nimic)                                      → parametru                           → MISSING
MANAGEMENT (OFFICE)       → office_expenses + administrative_expenses    → Σ lunar × 12 / N vehicule → /km     → AUTO-READY
                            + salarii birou (automat)
CARBURANT preț            → FuelPriceIndexService (CardOil)              → /(1+TVA 21%) × l/km                 → AUTO
CARBURANT consum          → FuelModel odometru (gardă 4–120) / SAS CAN   → L/100km / 100                       → DERIVED
AD BLUE                   → fuel_fillups (fuel_type='adblue')            → idem carburant                      → AUTO
REVIZII /100k             → mentenanta (record_type='intretinere')       → Σcost/km_perioadă × 100000          → DERIVED-BLOCKED
REPARAȚII /100k           → mentenanta ('reparatie') + ocr_piese_registru→ idem (anti-dublare)                 → DERIVED-BLOCKED
ANVELOPE /100k            → anvelope.purchase_price + anvelope_alocari   → Σpreț/Σkm_alocări × 100000          → DERIVED-BLOCKED
DIURNĂ                    → curse_cheltuieli (realizat) / durata curse   → zile×tarif(config) / km_lună        → CONFIG+DERIVED
km/lună                   → SAS totalDistance / curse km_totali /        → agregare lunară (de persistat)      → DERIVED-BLOCKED
                            fuel odometer deltas
Curs EUR/RON              → (nimic)                                      → parametru                           → MISSING
```

*Raport generat exclusiv prin inspecție read-only. Nicio modificare de cod, schemă, date sau Excel.*
