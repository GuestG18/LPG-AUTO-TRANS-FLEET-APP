# IMPLEMENTARE — Cost operațional / km

**Pagina:** `?page=cost_operational` · **Data implementării:** 2026-08-26
**Bazată pe:** `ANALIZA_SURSE_COST_KM.md` (analiza read-only din 2026-08-25) + mockup-ul UI furnizat.

---

## 1. Arhitectura

```
┌──────────────────────────── SURSE FLEET EXISTENTE (read-only) ────────────────────────────┐
│ curse_dispecer · fuel_fillups (CardOil) · mentenanta · anvelope(+alocari) · soferi /      │
│ salary_history · soferi_vehicule · configurare_costuri_documente_vehicule(+override) ·    │
│ configurare_costuri_documente_soferi · inventar_dotari_* · vehicle_authorizations ·       │
│ office_expenses · administrative_expenses · staff_members · curse_cheltuieli ·            │
│ vehicule · vehicule_cuplaje · configurare_beneficiari_transport                           │
└──────────────────────────────────────┬────────────────────────────────────────────────────┘
                                       ▼
   htdocs/models/OperationalCostModel.php          ← REZOLVAREA SURSELOR (tot SQL-ul)
                                       ▼
   htdocs/services/OperationalCostService.php      ← NORMALIZARE + ALOCARE + AGREGARE + TRASABILITATE
   htdocs/services/CostNormalizationService.php    ← primitivele de formule §P (pure, fără DB)
   htdocs/services/CostBreakEvenService.php        ← BREAK-EVEN + SIMULARE (pure, fără DB)
                                       ▼
   htdocs/controllers/OperationalCostController.php← rute JSON + pagina + export + configurare
                                       ▼
   htdocs/views/cost_operational/index.php         ← UI 1:1 după mockup (fără calcule de business în JS)
```

Configurarea (NU date tranzacționale):
- `cost_operational_settings` — parametri: curs EUR/RON, multiplicator salarial, TVA fallback, mod alocare management, sursă km.
- `cost_operational_elemente` — registrul elementelor financiare: tip (fix/variabil), clasă sursă (auto/derived/config/missing), resolver (`sursa_referinta`), scop, periodicitate, alocare, valoare manuală + monedă + amortizare, regim TVA, activ.

## 2. Fișiere create

| Fișier | Rol |
|---|---|
| `database/update_cost_operational_km.sql` | migrare: 2 tabele config + seed 29 elemente mapate din analiză |
| `htdocs/models/OperationalCostModel.php` | rezolvarea surselor (settings, elemente CRUD, km/venit, carburant, mentenanță, documente, dotări, autorizații, salarii, diurnă) |
| `htdocs/services/OperationalCostService.php` | motorul de calcul (unități operaționale, elemente, agregări pe 5 scopuri, trasabilitate) |
| `htdocs/services/CostNormalizationService.php` | formulele de normalizare §P — folosite și de teste (modelul de referință) |
| `htdocs/services/CostBreakEvenService.php` | break-even + simulare in-memory |
| `htdocs/controllers/OperationalCostController.php` | `index / data / details / simulate / element_save / element_toggle / element_delete / settings_save / export` |
| `htdocs/views/cost_operational/index.php` | pagina 1:1 (filtre, rezumat, break-even, structură, donut, actual-vs-BE, simulare, tabele vehicule/șoferi/beneficiari, modale detalii + configurare + metodologie) |
| `scripts/test_cost_operational_km.php` | 42 de verificări (rollback garantat) |

## 3. Fișiere modificate

- `htdocs/index.php` — require-uri + ruta `case 'cost_operational'`.
- `htdocs/config/permissions.php` — pagina `cost_operational` (grup Contabilitate, scope `accountancy`), acțiuni: `view` (sensitive), `configure` (admin, sensitive), `export`.
- `htdocs/views/layout/header.php` — link sidebar „Cost operațional / km” sub Cheltuieli Administrative, gard `$can('cost_operational')`.

## 4. Migrare de rulat (o singură dată)

```bash
mysql -u <user> -p <baza> < database/update_cost_operational_km.sql
```

(Aplicată deja pe baza locală. Idempotentă — `CREATE TABLE IF NOT EXISTS` + `ON DUPLICATE KEY`.)

## 5. Surse financiare CONECTATE (verificate pe date live)

| Element | Sursă reală | Normalizare → lei/km | Clasă |
|---|---|---|---|
| Carburant (motorină) | `fuel_fillups` (CardOil API, `source_type='api'`) | Σ `total_value / (1+cota_tva/100)` per vehicul în perioadă; cota reală per rând din `raw_payload.$.cota_tva` (21% live), fallback config; ÷ km vehicul | **AUTO** |
| AdBlue | idem, `fuel_type='adblue'` | idem | **AUTO** |
| Salarii șoferi | `salary_history` (subject_type=driver, la sfârșitul lunii) fallback `soferi.salariu` | salariu × multiplicator (1,75 config) → alocat vehiculului asociat (`soferi_vehicule.is_primary` fallback `soferi.vehicle_id`); fără vehicul → „nealocat” vizibil | **AUTO** + CONFIG (multiplicatorul) |
| Diurnă | `curse_cheltuieli` tip `diurna` (net de refacturări facturate) | sumă realizată per vehicul+șofer în perioadă | **AUTO** |
| Taxe drum realizate | `curse_cheltuieli` tip `taxe_drum` | idem | **AUTO** |
| Taxe drum (autorizații) | `vehicle_authorizations.cost` | cost / zile fereastră × zile suprapuse cu perioada | **AUTO** (0 rânduri cu cost azi) |
| Revizii | `mentenanta` `record_type='intretinere'`, exclus `'Anvelopa - %'` și `anulata` | sumă evenimente în perioadă (coloana `cost`, nu manoperă+piese — anti-dublare backfill) | **DERIVED** |
| Reparații | `mentenanta` `record_type='reparatie'` | idem | **DERIVED** |
| Piese OCR | `ocr_piese_registru` (pret+pret_manopera) | idem — **dezactivat implicit** (ledger paralel cu mentenanța, risc dublare) | DERIVED (opt-in) |
| Anvelope | `anvelope.purchase_price` + `estimated_life_km` × alocări active | Σ(preț/durată km) × km perioadă; **azi LIPSĂ** (toate prețurile NULL) — raportat, nu 0 | DERIVED-BLOCKED |
| Documente vehicule (RCA, CASCO, ITP, Rovinietă, IPROCHIM, Copie conformă, Tahograf, Metrologie) | `configurare_costuri_documente_vehicule` + `_override` (precedență override) | cost × 365 / validity_days / 12 | **CONFIG** (tabel populat, valori 0 azi) |
| Documente șoferi (avize) | `configurare_costuri_documente_soferi` | idem, per șofer → vehiculul asociat | **CONFIG** (0 rânduri azi) |
| Dotări (ADR, extinctoare, echipamente) | `inventar_dotari_vehicule` + catalog | cost (fallback cost_implicit) / interval inspecție (luni) | **CONFIG** |
| Management / Office | `office_expenses` (net, fără categorii automate) + `administrative_expenses` (net) + salarii birou (automat: `staff_members`+`salary_history`, replica `getOfficeSalaryForMonth`) | total lunar ÷ vehicule active (sau proporțional cu km — configurabil) | **AUTO-READY** (module goale azi; salariile birou produc valoare când există personal office) |

## 6. Elemente rămase CONFIG/MISSING (fără sursă — valoare manuală în registru)

`impozit_auto`, `asigurare_risc`, `agreare`, `amortizare` (EUR / 6 ani, configurabil per element), `telefon`, `tped`, `gps`, `ssm_su`, `cursuri_soferi` — toate seed-uite ca elemente `missing` cu `valoare_config NULL`. Până la completare apar în bannerul „Calitate date” ca LIPSĂ și **nu contribuie cu 0** la total. Orice valoare introdusă în „Vezi detalierea completă costuri → Editează” intră automat în agregare.

## 7. Formulele implementate (identice cu §P din analiză)

- `FixKm(cat) = FixPerioadă(cat) / km(cat)`; pe lună: anual/12, documente: cost×365/validity/12.
- `FixKm(CT+SR)` = costurile tractorului + semiremorcii cuplate ÷ **km-ii tractorului** (semiremorca nu adaugă km — `vehicule_cuplaje` activ).
- `VarKm = Σ cheltuieli variabile reale în perioadă / km aceluiași scop` (carburant net de TVA cu cota reală).
- Manual: `lei = valoare × (curs dacă EUR)`; amortizare: `/ani/12`; per_100000km: `/100000 × km`; company: `/N vehicule active`.
- Break-even: `km_BE = CosturiFixe / (Venit/km − Variabil/km)`; nedefinit (cu motiv afișat) când venit lipsește sau marja e negativă.
- Simulare (§21): fixele constante, variabilele scalate cu rata lei/km, venit = km × venit/km simulat. 100% in-memory.
- EUR/km = lei/km ÷ curs (parametrul `eur_ron_rate` — nu există sursă dinamică în aplicație, conform §L).

## 8. Sursa de km (numitorul)

`curse_dispecer`, perioadă pe `data_inceput`, `deleted_at IS NULL`:
- **km reali** (implicit): `CASE WHEN km_totali>0 THEN km_totali WHEN km_cursa>0 THEN km_cursa ELSE 0 END` — aceeași expresie „kmEffective” din Dashboard Analitic;
- **km facturați** (comutator „Tip activitate”): prioritate inversată.
Vederile pe șofer/beneficiar folosesc **aceeași matrice** grupată pe `vehicle_id × driver_id × beneficiar_id` — niciun numitor amestecat. Limita cunoscută din analiză: `km_totali` este parțial copiat din `km_cursa` (sept/oct) — sursa SAS rămâne neconectată la MySQL (viitor).

## 9. Venit / beneficiari / șoferi

- Venit: `curse_dispecer.total_facturare` (RON, snapshot tarifar, fără TVA). NB: `cost_km_*` din curse sunt VENIT/km — nu au fost folosite drept cost.
- Beneficiari: `configurare_beneficiari_transport` prin FK `curse_dispecer.beneficiar_id` (relația reală, nimic inventat).
- Șoferi: `curse_dispecer.driver_id`; cost personal = salariu×mult + documente șofer + diurnă + elemente manuale per șofer; costuri operaționale alocate BY_KM din vehiculele conduse (rata ne-personală a unității × km șofer pe acel vehicul).

## 10. Reguli anti-dublare implementate

1. Semiremorca cuplată → costuri pe tractor, km o singură dată (test 14–15).
2. Salariul unui șofer apare o singură dată (alocat vehiculului SAU în „nealocat”) (test 16).
3. `mentenanta.cost` (nu + manoperă + piese); exclus `'Anvelopa - %'` (cost 0 hard-codat de TireModel).
4. `ocr_piese_registru` dezactivat implicit (paralel cu mentenanța).
5. Tabelele derivate `supplier_invoices` / `invoice_*` nefolosite.
6. De-TVA-izare o singură dată, la normalizare, doar pe sursele `brut` (carburant).
7. Vehiculele inactive cu activitate în perioadă sunt incluse (costuri/km reale), dar nu primesc costuri „de stare” (documente, alocare management).

## 11. Permisiuni

- `cost_operational.view` — scope implicit `accountancy` (admin sau contabilitate), marcat sensibil.
- `cost_operational.configure` — doar admin: elemente financiare + parametri (inclusiv cursul EUR/RON din filtre).
- `cost_operational.export` — export CSV.
Integrare completă în `drepturi_acces` (matricea existentă) + gard central `require_route_access`.

## 12. Teste — `php scripts/test_cost_operational_km.php`

**42/42 PASS** (rulate pe baza live, tranzacție anulată). Acoperă toate cele 24 de cerințe §44:
normalizări fixe/variabile/anual→perioadă/per-100k/carburant/TVA (1–6), validarea Excel §43 (7–10),
reconciliere ierarhie + categorii + vehicule + km SQL independent (11–13), anti-dublare CT+SR (14–15),
anti-dublare salarii (16), șofer (17–18), beneficiar (19), consistența filtrelor (20), reconciliere
drill-down (21), LIPSĂ ≠ 0 (22–23), alocare partajată (24–25), 0 km (26–27), break-even (28–31),
simulare + fixele rămân fixe (32–34), imutabilitatea datelor la simulare (35), numitor consecvent (36).

**Validarea §43 (modelul de referință Excel)** — reproduse exact, la 1e-9:
Fix CT+SR 3,3251190476 · Var 7TO 2,5246638655 · Var 10TO 2,8589537815 · Var 13TO 3,1404831933 ·
Total CT+SR 6,6306820728 · EUR 1,3261364146 la curs 5,00 (țintele ≈3,33 / ≈3,31 / ≈6,63 / ≈1,33).

## 13. Limitări asumate (nu au putut fi eliminate — lipsesc datele, nu codul)

1. **Valorile config = 0**: RCA/CASCO/ITP/etc. au tabele populate dar costuri 0 → apar ca LIPSĂ până la completarea `configurare_costuri_documente_vehicule` (pagina „Configurare costuri documente” existentă).
2. **Anvelope**: `purchase_price` NULL pe 150/150 → element blocat, raportat.
3. **km SAS** nepersistat în MySQL → numitorul folosește km-ii din curse (documentat pe pagină); persistarea lunară SAS rămâne pas viitor (§O.2.2 din analiză).
4. **Categoria „13 TO”** apare doar dacă există vehicule cu capacitate >10t cu activitate (azi doar inactive) — bucketele sunt dinamice: ≤7 → 7 TO; ≤10 → 10 TO; >10 → 13 TO; 0/NULL → „Camioane fără capacitate” (de curățat datele).
5. **Alocarea salariului pe vehicul** folosește asocierea șofer→vehicul; șoferii fără vehicul asociat apar ca „nealocat” la nivel de flotă (vizibil în reconciliere).
6. **Cursul EUR/RON** este parametru (nicio infrastructură valutară în aplicație — §L).
7. **Mix beneficiari în simulare**: se păstrează mixul actual (alocarea urmează km-ii reali); simularea pe mix alternativ ar cere tarife pe beneficiar complet populate.
