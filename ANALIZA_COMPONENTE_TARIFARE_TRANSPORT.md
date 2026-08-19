# ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md

**Read-only component-level analysis: which exact values define the price of each transport type.**

| | |
|---|---|
| Repository | `C:\laragon\www\aplicatie_fleet` — branch `main` @ `2933432` |
| Prior report used as starting point | `ANALIZA_CONFIGURARE_TRANSPORT.md` — **every conclusion re-verified independently** |
| Live database | MySQL `if0_41456552_aplicatie_flota`, read-only (`SELECT`, `SHOW CREATE TABLE`) |
| Analysis date | 2026-08-18 |
| Changes made | **none** — no code, no schema, no data |

### Evidence tags

| Tag | Meaning |
|---|---|
| **[CODE]** | Read directly from the repository source |
| **[DB]** | Verified by a read-only query against live data |
| **[CODE+DB]** | Formula read from source **and** arithmetically confirmed against stored records |
| **[INFERRED]** | Reasoned from the above; not directly stated anywhere |
| **[UNKNOWN]** | Cannot be established from code or database |

---

## 1. Executive Summary

The application does **not** have "a price per transport type". It has **five structurally different pricing schemes**, using **13 distinct commercial values** spread across **two configuration levels** (beneficiary and route), plus **6 operational quantities** that enter the invoice.

The ten facts that most affect how pricing must be represented:

1. **Rates and quantities are configured at different levels, and this differs per transport type.** For `Primar km` / `Primar tone` the **rate lives on the beneficiary** and the **quantity (agreed km) lives on the route**. For `Distribuție` / `P+D` **both rate and quantity live on the route**. For `Compresor` **everything lives on the beneficiary** and there is no route at all. **[CODE+DB]**

2. **`Primar km` and `Primar tone` have no per-route rate.** `configurare_rute_primar` contains **no price column whatsoever** — only `km_tarifare` (a quantity), `cost_cursa` (a flat override) and switches. Every Primar route for a client shares one `pret_km` / `pret_tona`. **[CODE+DB]**

3. **Five formulas verified arithmetically against production records — all match exactly.** Primar km, Primar tone, Distribuție (both live modes), P+D and Compresor: 23 representative trips checked, 23 matches. §30.

4. **`pret_tarifare` carries a different unit in every transport type — and two different units *within* Distribuție.** Verified in live data: 1.21 (lei/km) for `primar`, 60.00 (lei/t) for `primar_tona`, **60.00 (lei/t) *and* 1.20 (lei/km) for `distributie` depending on the route's `tarif_mod`**, 75.00 (lei/t) for `primar_distributie`, 80.00 (lei/h) for `compresor`. **[CODE+DB]**

5. **`cost_km_primar`, `cost_km_distributie`, `cost_km_mixt`, `cost_km_compresor` are derived reporting KPIs, not rates — and one is provably decoupled from the invoice.** Trip #342 (`primar_tona`) is invoiced 540.00 lei on tonnage, yet stores `cost_km_primar = 1.21` — which is `pret_km`, not `540/180 = 3.00`. **[CODE+DB]** These must never be presented as editable.

6. **Vehicle changes rule *eligibility* everywhere, but can only change the *quantity* — never the rate — and only for Primar.** `configurare_rute_distributie` carries `UNIQUE (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope)`, so two different distribution rates for the same route are **structurally impossible**. `configurare_rute_primar` has **no such key**, so several rules with disjoint vehicle sets and different `km_tarifare` are possible — 0 such pairs exist in live data. **[CODE+DB]**

7. **Capacity, commodity and date influence pricing: NO, NO, NO.** `capacitate_transport` is passed into `normalizeTonInputToKgForPricing()` and then **ignored** (documented no-op). `tip_marfa` appears in no rate lookup and is absent from the repricing trigger list. **No configuration table has any effective-from/to column.** **[CODE+DB]**

8. **Route direction does not exist as a pricing dimension.** Both Primar and Distribuție resolvers try `(loc, zonă)`, then the **reverse** `(zonă, loc)`, then both again by normalised name. `A→B` and `B→A` are the **same rule**. **[CODE]**

9. **The distribution fallback chain has three tiers below the route rate, and all three are unreachable from the UI.** `loc.tarif`, `zona.tarif_distributie`, `zona.cost_extra_km` (DORMANT — no form posts them), then `beneficiar.pret_distributie_tona` / `pret_distributie_km` (HIDDEN — pass-through hidden inputs). One of them is non-zero in production: **ButanGas `pret_distributie_km = 1.50`** — dormant only because every ButanGas distribution route uses `tarif_mod = 'tona'`. **[CODE+DB]**

10. **50 of 74 priced trips never went through the pricing engine.** Two seeded batches (34 rows at `2026-08-10 15:56:59`, 16 rows at `2026-08-17 11:00:00`) have `created_at == updated_at` and `cost_km_mixt = 0` while the code would have computed a non-zero value. Their `total_facturare` matches the formulas — they were hand-computed — but they bypassed `validateRaceInput()`. **[DB]**

---

## 2. Canonical Transport Types

**Source of truth:** `DispecerCurseController::TRANSPORT_TYPES` (lines 11–17) and `curse_dispecer.tip_transport` ENUM. Cross-checked against 6 independent locations. **[CODE+DB]**

| Display label | Internal value | DB ENUM value | Pricing method |
|---|---|---|---|
| Primar km | `primar` | `primar` | **quantity × beneficiary rate** — `km_tarifare` (route) × `pret_km` (beneficiary) |
| Primar tone | `primar_tona` | `primar_tona` | **quantity × beneficiary rate** — `cantitate_incarcata` (trip) × `pret_tona` (beneficiary) |
| Distribuție | `distributie` | `distributie` | **mode-dependent, route rate** — `tona` / `km` / `tona_km` |
| Primar+Distribuție (P+D) | `primar_distributie` | `primar_distributie` | **two additive route components** — tonnage + km, mode locked to `tona_km` |
| Compresor | `compresor` | `compresor` | **five additive beneficiary components** |

### 2.1 Cross-source verification

| Source | File / object | Types listed |
|---|---|---|
| Controller constant | `DispecerCurseController.php:11-17` | all 5 |
| Database ENUM | `curse_dispecer.tip_transport` **[DB]** | all 5 |
| Billing centralizer | `CentralizatorFacturareService.php:6-12` | all 5 (different labels — §32.3) |
| Dashboard | `DashboardAnaliticController.php:8-14` | all 5 |
| Config page | `views/dispecer_curse/config.php:2-7` | **4** — `primar_tona` absent by design |
| Trip form JS | `assets/js/dispecer-curse.js` predicates | all 5 |
| Route scope ENUM | `configurare_rute_distributie.transport_scope` **[DB]** | 2 (`distributie`, `primar_distributie`) |

### 2.2 The 5-vs-4 asymmetry

`configurare_beneficiari_transport` has only **four** `suporta_*` flags. `primar` and `primar_tona` **share `suporta_primar`**. **[CODE]**

> **Evidence:** `resolveBeneficiaryRate()` line 6819:
> ```php
> if ($transportType === 'primar' || $transportType === 'primar_tona') {
>     if (!$supportsPrimary) { return 0.0; }
> ```
> and the checkbox whitelist at `configStoreBeneficiaryAction()` line 4186 accepts only `['primar','distributie','primar_distributie','compresor']`.

**Consequence:** enabling "Primar km" for a client automatically enables "Primar tone" and vice-versa. They cannot be sold independently.

### 2.3 Legacy / dead values

| Value | Where | Status |
|---|---|---|
| `'mixt'` | `DispecerCurseController.php:5651` — `$transportType === 'primar_distributie' \|\| $transportType === 'mixt'`; mirrored in `dispecer-curse.js:5399` | **DEAD** — not in the ENUM, not in `TRANSPORT_TYPES`, unreachable **[CODE+DB]** |
| 3-value ENUM `('primar','distributie','compresor')` | `reset_database.sql:617` | **STALE baseline** — live schema has 5 **[DB]** |
| `configurare_beneficiari_transport.tip_marfa` | wiped to `''` on every save (controller 4348, 4374) | **DEAD column** **[CODE]** |

---

## 3. Definition of Rate vs Quantity vs Fixed Price

Complete classification of every value that participates in a commercial calculation. **[CODE+DB]**

| Field | Table / origin | Type | Unit |
|---|---|---|---|
| `pret_km` | `configurare_beneficiari_transport` | **RATE** | lei/km |
| `pret_tona` | `configurare_beneficiari_transport` | **RATE** | lei/tonă |
| `pret_tarifare` *(config)* | `configurare_beneficiari_transport` | **RATE (fallback)** | ambiguous — §32.1 |
| `pret_distributie_tona` | `configurare_beneficiari_transport` | **RATE (fallback)** | lei/tonă |
| `pret_distributie_km` | `configurare_beneficiari_transport` | **RATE (fallback)** | lei/km |
| `pret_ora_aspirare` | `configurare_beneficiari_transport` | **RATE** | lei/oră |
| `pret_km_dislocare` | `configurare_beneficiari_transport` | **RATE** | lei/km |
| `pret_tona_livrata` | `configurare_beneficiari_transport` | **RATE** | lei/tonă |
| `pret_tona_aspirata_lichida` | `configurare_beneficiari_transport` | **RATE** | lei/tonă |
| `pret_tona_aspirata_gazoasa` | `configurare_beneficiari_transport` | **RATE** | lei/tonă |
| `tarif_tona` | `configurare_rute_distributie` | **RATE** | lei/tonă |
| `cost_extra_km` | `configurare_rute_distributie` | **RATE** | lei/km |
| `tarif` | `configurare_locuri_incarcare` | **RATE (dormant fallback)** | lei/tonă |
| `tarif_distributie` | `configurare_zone_distributie` | **RATE (dormant fallback)** | lei/tonă |
| `cost_extra_km` | `configurare_zone_distributie` | **RATE (dormant fallback)** | lei/km |
| `cost_cursa` | `configurare_rute_primar` | **FIXED PRICE** | lei/cursă |
| `cost_cursa` | `configurare_rute_distributie` | **FIXED PRICE** | lei/cursă |
| `km_tarifare` | `configurare_rute_primar` | **QUANTITY** ⚠ | km |
| `km_tarifare` | `configurare_rute_distributie` | **QUANTITY** ⚠ | km |
| `cantitate_incarcata` | `curse_dispecer` (operator) | **QUANTITY** | tone |
| `km_cursa` | `curse_dispecer` (route or operator) | **QUANTITY** | km |
| `km_totali` | `curse_dispecer` (operator) | **QUANTITY** | km |
| `ore_aspirare` | `curse_dispecer` (operator) | **QUANTITY** | ore |
| `km_dislocare` | `curse_dispecer` (operator) | **QUANTITY** | km |
| `tona_livrata` | `curse_dispecer` (operator) | **QUANTITY** | tone |
| `tona_aspirata_lichida` | `curse_dispecer` (operator) | **QUANTITY** | tone |
| `tona_aspirata_gazoasa` | `curse_dispecer` (operator) | **QUANTITY** | tone |
| `aplica_cost_cursa` | both route tables | **MODE / SWITCH** | bool |
| `km_agreati_manual` | `configurare_rute_primar` | **MODE / SWITCH** | bool |
| `tarif_mod` | `configurare_rute_distributie` | **MODE / SWITCH** | enum |
| `activ` | all config tables | **MODE / SWITCH** | bool |
| `suporta_*` (×4) | `configurare_beneficiari_transport` | **MODE / SWITCH** | bool |
| `beneficiar_id` | trip → config | **DIMENSION / LOOKUP KEY** | — |
| `loc_incarcare_id` | trip → config | **DIMENSION / LOOKUP KEY** | — |
| `zona_distributie_id` | trip → config | **DIMENSION / LOOKUP KEY** | — |
| `vehicle_id` / `vehicle_ids` | trip / route rules | **DIMENSION / LOOKUP KEY** | — |
| `transport_scope` | `configurare_rute_distributie` | **DIMENSION / LOOKUP KEY** | — |
| `cost_km_primar` | `curse_dispecer` | **DERIVED VALUE (KPI)** | lei/km |
| `cost_km_distributie` | `curse_dispecer` | **DERIVED VALUE (KPI)** | lei/km |
| `cost_km_mixt` | `curse_dispecer` | **DERIVED VALUE (KPI)** | lei/km |
| `cost_km_compresor` | `curse_dispecer` | **DERIVED VALUE (KPI)** | lei/km |
| `pret_tarifare` *(trip)* | `curse_dispecer` | **OUTPUT (snapshot)** | ambiguous |
| `total_facturare` | `curse_dispecer` | **OUTPUT** | lei |

⚠ **`km_tarifare` is named like a price and is a quantity.** See §32.2.

---

## 4. Primar km — Exact Calculation

### 4.1 Inputs — verified list

| Input | Source | Role | Confirmed |
|---|---|---|---|
| `beneficiar_id` | trip | dimension | **[CODE]** :5240 |
| `loc_incarcare_id` + `zona_distributie_id` | trip | dimension (route lookup) | **[CODE]** :5257 |
| `vehicle_id` | trip | dimension (rule eligibility) | **[CODE]** :5263 |
| `pret_km` | `configurare_beneficiari_transport` | **RATE** | **[CODE+DB]** |
| `pret_tarifare` (config) | `configurare_beneficiari_transport` | rate fallback | **[CODE]** :6828 |
| `km_tarifare` | `configurare_rute_primar` | **QUANTITY** | **[CODE+DB]** |
| `km_agreati_manual` | `configurare_rute_primar` | switch — quantity source | **[CODE]** :5268 |
| `km_cursa` | trip (operator) | quantity **only when `km_agreati_manual = 1`** | **[CODE]** :5279 |
| `cost_cursa` + `aplica_cost_cursa` | `configurare_rute_primar` | **FIXED PRICE override** | **[CODE]** :5270 |

**Not inputs:** `cantitate_incarcata`, `km_totali`, `tip_marfa`, `capacitate_transport`, any date. **[CODE+DB]**

### 4.2 Formula

```
--- QUANTITY RESOLUTION  (validateRaceInput lines 5256-5287) ---
rule = resolvePrimaryRouteRuleForBeneficiaryBidirectional(
           beneficiar_id, loc_incarcare_id, zona_distributie_id, vehicle_id)

if rule matched AND rule.km_agreati_manual == 1:
        km = km_cursa                      # operator types it per trip
else if rule matched AND rule.km_tarifare > 0:
        km = rule.km_tarifare              # ← CONFIG OVERWRITES the operator's value
        (line 5285:  $km = $primaryRouteKmTariff;)
else:
        km = km_cursa                      # soft warning raised

--- RATE RESOLUTION  (resolveBeneficiaryRate, line 6809) ---
pret_km  = beneficiar.pret_km  > 0 ? beneficiar.pret_km  : beneficiar.pret_tarifare
pret_tona = beneficiar.pret_tona > 0 ? beneficiar.pret_tona : beneficiar.pret_tarifare

--- OUTPUT  (lines 5431-5447 for price, 5521-5532 for total) ---
if rule.aplica_cost_cursa AND rule.cost_cursa > 0:
        pret_tarifare   = rule.cost_cursa
        total_facturare = rule.cost_cursa           # ← FULL REPLACEMENT
else:
        pret_tarifare   = pret_km > 0 ? pret_km : pret_tona     (line 5445)
        total_facturare = km × pret_km                          (line 5532)
```

### 4.3 Rate source

**Beneficiary only.** `configurare_rute_primar` contains **no price column** — verified against the live schema **[DB]**:

```
configurare_rute_primar:  id · beneficiar_id · loc_incarcare_id · zona_distributie_id
                          km_tarifare(INT) · cost_cursa · aplica_cost_cursa
                          vehicle_ids · km_agreati_manual · activ · created_at · updated_at
```

**Finding:** The lei/km for Primar km is **global per beneficiary**. All Primar routes of a client share it.
**Evidence:** File `htdocs/controllers/DispecerCurseController.php`, function `resolveBeneficiaryRate()`, lines 6819-6828; table `configurare_beneficiari_transport`, column `pret_km`; **[DB]** confirmed — ButanGas and Forvest both `pret_km = 1.21`, and *all* their Primar trips price at exactly `km × 1.21` across 4 different routes.

### 4.4 Quantity source

**The route configuration, not the operator.** Line 5285 **overwrites** the posted `km_cursa` with `rule.km_tarifare`. The operator's typed value is discarded unless `km_agreati_manual = 1`. **[CODE]**

**[DB]** 1 of 11 primary routes uses manual km (`km_agreati_manual = 1`, rule id 12, beneficiary Forvest).

### 4.5 Overrides

`aplica_cost_cursa = 1` **AND** `cost_cursa > 0` → `total_facturare = cost_cursa`, replacing the entire km × rate calculation. **[CODE]** :5440, :5524.

**[DB]** Currently **inactive everywhere**: 1 route has `cost_cursa = 4000.00` (rule 15, Vixon) but `aplica_cost_cursa = 0` — configured and switched off. 0 routes have it active.

### 4.6 Scope

| Element | Scope |
|---|---|
| **Rate** (`pret_km`) | **per beneficiary** — global across all routes and vehicles |
| **Quantity** (`km_tarifare`) | **per beneficiary + loading location + unloading zone [+ vehicle set]** |
| **Fixed override** (`cost_cursa`) | **per route** (same tuple as quantity) |

### 4.7 Final output — verified **[CODE+DB]**

| Column | Value | Verified example |
|---|---|---|
| `pret_tarifare` | `pret_km` (lei/km) | trip #340 → `1.21` |
| `total_facturare` | `km × pret_km` | trip #340 → `630 × 1.21 = 762.30` ✅ |
| `cost_km_primar` | `totalPrimar / kmPrimar` = `pret_km` | trip #340 → `1.21` |
| `cost_km_mixt` | `= cost_km_primar` (line 5658) | trip #145 → `1.21` ✅ *(see §30.6 for the seeded rows storing 0)* |
| `cost_km_distributie` | `0` — no distribution segment | `0.00` |

---

## 5. Primar tone — Exact Calculation

### 5.1 Formula

```
--- QUANTITY ---
tone = cantitate_incarcata                  # operator input, used literally
                                            # normalizeTonInputToKgForPricing() is a NO-OP (line 7709)

--- RATE ---
pret_tona = beneficiar.pret_tona > 0 ? beneficiar.pret_tona : beneficiar.pret_tarifare

--- INVOICE CALCULATION  (lines 5524-5529) ---
if rule.aplica_cost_cursa AND rule.cost_cursa > 0:
        total_facturare = rule.cost_cursa
else:
        total_facturare = tone × pret_tona          # ← km PLAYS NO PART

--- REPORTING / DERIVED  (lines 5597-5648) — NOT invoiced ---
km_primar    = km_cursa                            # still taken from the Primar route rule
totalPrimar  = km_primar × pret_km                 # a notional figure that is NEVER invoiced
cost_km_primar = totalPrimar / km_primar = pret_km
cost_km_mixt   = cost_km_primar
```

### 5.2 Does km affect `total_facturare`? — **NO. Proven.**

> **Finding:** For `primar_tona`, kilometres are resolved from the route rule, stored on the trip, and used to compute `cost_km_primar` — but they **do not enter the invoiced amount**.
>
> **Evidence:**
> - File: `htdocs/controllers/DispecerCurseController.php`
> - Function: `validateRaceInput()`, lines 5524-5528 (`$total = $tonComponent;`) vs lines 5610-5623 (`$totalPrimar = $kmPrimar * $primaryPerKmRate`)
> - Table: `curse_dispecer`; columns: `total_facturare`, `cost_km_primar`, `km_cursa`
> - **Example database records [DB]:**
>
> | id | `cantitate_incarcata` | `pret_tona` | `km_cursa` | `total_facturare` | `cost_km_primar` |
> |---|---|---|---|---|---|
> | 342 | 9.00 | 60.00 | 180 | **540.00** = 9 × 60 | **1.21** |
> | 341 | 6.50 | 60.00 | 1100 | **390.00** = 6.5 × 60 | **1.21** |
>
> Trip #342: `540 / 180 = 3.00`, yet `cost_km_primar = 1.21` — which is exactly `pret_km`. Trip #341 has 6× the kilometres of #342 and a *lower* invoice. **Kilometres are decoupled from the invoice; `cost_km_primar` is decoupled from the invoice too.**

**INVOICE CALCULATION** → tonnage only.
**REPORTING / DERIVED CALCULATION** → `cost_km_primar`, `cost_km_mixt`, both driven by `pret_km` and km, neither invoiced.

### 5.3 Dependencies

| Dependency | Present? | Detail |
|---|---|---|
| rate | ✅ | `pret_tona` — **beneficiary level** |
| quantity | ✅ | `cantitate_incarcata` — **trip level, operator** |
| fixed override | ✅ | `cost_cursa` on the Primar route rule |
| route dependency | ⚠ **partial** | route is matched (to supply km + the override) but supplies **no rate**; the invoice is identical on every route |
| beneficiary dependency | ✅ | the only rate source |
| vehicle dependency | ⚠ | eligibility + which route variant matches; **no rate effect** |
| km dependency | ❌ **for the invoice**; ✅ for `cost_km_primar` | §5.2 |

### 5.4 A fallback inconsistency worth flagging

**[CODE]** The stored `pret_tarifare` and the invoiced `total_facturare` use **different fallback chains**:

- line 5444 — `$price = $pricePerTon > 0 ? $pricePerTon : $pricePerKm` → falls back to **lei/km**
- line 5527 — `$total = qty × $pricePerTon` → `$pricePerTon` falls back to **`pret_tarifare`**, never to `pret_km`

**[INFERRED]** If a beneficiary has `pret_tona = 0`, `pret_tarifare = 0`, `pret_km = 1.21`, a `primar_tona` trip stores `pret_tarifare = 1.21` (a lei/km value) while `total_facturare = 0`. Not observable in current data (no such beneficiary configuration exists **[DB]**), but reachable.

---

## 6. Distribuție — Exact Calculation

### 6.1 Formula

```
--- RULE LOOKUP  (lines 5348-5395) ---
rule = distribution route rule for (beneficiar, loc, zona, scope='distributie')
       filtered by vehicle  →  see §17
mode = normalizeDistributionRouteTariffModeInput(rule.tarif_mod)     # default 'tona_km'

--- RATE RESOLUTION  (lines 5454-5473) ---
usesTon = mode ∈ {tona_km, tona}
usesKm  = mode ∈ {tona_km, km}

effectiveTonRate = usesTon
    ? (rule.tarif_tona > 0 ? rule.tarif_tona : <FALLBACK CHAIN — §7>)
    : 0
effectiveKmRate  = usesKm
    ? (rule.cost_extra_km > 0 ? rule.cost_extra_km
       : zona.cost_extra_km > 0 ? zona.cost_extra_km
       : beneficiar.pret_distributie_km)
    : 0

--- INVOICE  (lines 5540-5560) ---
if rule.aplica_cost_cursa AND rule.cost_cursa > 0:
        pret_tarifare   = rule.cost_cursa
        total_facturare = rule.cost_cursa
else:
        pret_tarifare   = effectiveTonRate > 0 ? effectiveTonRate : effectiveKmRate   (line 5480)
        tonComponent    = cantitate_incarcata × effectiveTonRate
        kmComponent     = (effectiveKmRate > 0) ? km_cursa × effectiveKmRate : 0      (line 5551)
        total_facturare = tonComponent + kmComponent

--- DERIVED ---
cost_km_distributie = total_facturare / km_cursa        (lines 5637-5646)
cost_km_mixt        = cost_km_distributie               (line 5660)
```

⚠ **Note line 5551:** for `distributie` the km component applies **only if `effectiveKmRate > 0`** — i.e. it is optional. For `primar_distributie` it always applies (line 5553). **[CODE]**

### 6.2 Verified against production **[CODE+DB]**

| id | `tarif_mod` | `tarif_tona` | `cost_extra_km` | tone | km | expected | **stored** | `pret_tarifare` |
|---|---|---|---|---|---|---|---|---|
| 352 | `km` | 0.00 | 1.20 | 8.00 | 310 | 310 × 1.20 = **372.00** | **372.00** ✅ | **1.20** (lei/km) |
| 351 | `km` | 0.00 | 1.20 | 7.00 | 260 | 260 × 1.20 = **312.00** | **312.00** ✅ | **1.20** (lei/km) |
| 346 | `tona` | 60.00 | 0.00 | 8.00 | 190 | 8 × 60 = **480.00** | **480.00** ✅ | **60.00** (lei/t) |
| 345 | `tona` | 75.00 | 0.00 | 9.50 | 275 | 9.5 × 75 = **712.50** | **712.50** ✅ | **75.00** (lei/t) |
| 343 | `tona` | 75.00 | 0.00 | 11.50 | 320 | 11.5 × 75 = **862.50** | **862.50** ✅ | 75.00 |
| 149 | `tona` | 60.00 | 0.00 | 6.96 | 172 | 6.96 × 60 = **417.60** | **417.60** ✅ | 60.00 |

In `tona` mode the kilometres are ignored by the invoice even though they are stored (trip 346: 190 km, no km component). In `km` mode the tonnage is ignored (trip 352: 8 t, no tonnage component).

---

## 7. Distribuție Tariff Modes

**[CODE]** `DISTRIBUTION_ROUTE_TARIFF_MODE_LABELS`, lines 46-50. Three modes exist. Invalid/absent values coerce to `tona_km` (`normalizeDistributionRouteTariffModeInput()` :5812).

```
MODE: tona                       ("Doar Pret tona")
RATE:      configurare_rute_distributie.tarif_tona          [lei/tonă]
QUANTITY:  curse_dispecer.cantitate_incarcata               [tone]
FORMULA:   total_facturare = cantitate_incarcata × tarif_tona
NOTE:      cost_extra_km forced to 0 at save (controller :3057)
VERIFIED:  trip #346 → 8.00 × 60.00 = 480.00 = stored           [CODE+DB]

MODE: km                         ("Doar Pret km")
RATE:      configurare_rute_distributie.cost_extra_km       [lei/km]
QUANTITY:  curse_dispecer.km_cursa                          [km]
FORMULA:   total_facturare = km_cursa × cost_extra_km
NOTE:      tarif_tona forced to 0 at save (controller :3054)
VERIFIED:  trip #352 → 310 × 1.20 = 372.00 = stored             [CODE+DB]

MODE: tona_km                    ("Pret tona + Pret km")  — DEFAULT
RATE 1:      tarif_tona            [lei/tonă]
QUANTITY 1:  cantitate_incarcata   [tone]
RATE 2:      cost_extra_km         [lei/km]
QUANTITY 2:  km_cursa              [km]
FORMULA:     total_facturare = (cantitate_incarcata × tarif_tona)
                             + (km_cursa × cost_extra_km)
STATUS:      the mandatory mode for P+D; **0 distributie-scope routes use it** [DB]
```

**[DB]** live mode distribution:

| `transport_scope` | `tarif_mod` | rules |
|---|---|---|
| `distributie` | `km` | 3 |
| `distributie` | `tona` | 6 |
| `primar_distributie` | `tona_km` | 4 |

**[CODE]** For `primar_distributie` the mode selector is **not offered in the UI** and is hard-forced to `tona_km` at save (controller line 3002) and at render (config.php, no `route_tarif_mod` input in the P+D form).

---

## 8. Distribuție Fallback Hierarchy

### 8.1 `tarif_tona` — resolution order

**[CODE]** lines 5459-5470 → `resolveDistributionTonRate()` line 7616.

```
1.  configurare_rute_distributie.tarif_tona          if > 0     → ACTIVE
2.  ↓ conditional branch on isSameDistributionRoute():
    ├── loc name == zone name (normalised):
    │      2a. configurare_locuri_incarcare.tarif          if > 0  → DORMANT
    │      2b. configurare_zone_distributie.tarif_distributie if >0 → DORMANT
    └── loc name != zone name:
           2a. configurare_zone_distributie.tarif_distributie if >0 → DORMANT
           2b. configurare_locuri_incarcare.tarif          if > 0  → DORMANT
3.  configurare_beneficiari_transport.pret_distributie_tona        → HIDDEN
4.  0.0  → soft error "Nu exista un tarif valid pentru distributie"
```

**The order of tiers 2a/2b flips** depending on whether the loading place and the unloading zone carry the **same normalised name** (`isSameDistributionRoute()` :7643, `normalizeDistributionPointName()` :7653 — lowercase + transliterate + collapse whitespace). **[CODE]**

### 8.2 `cost_extra_km` — resolution order

**[CODE]** line 5471-5473. Flat, unconditional:

```
1.  configurare_rute_distributie.cost_extra_km   if > 0   → ACTIVE
2.  configurare_zone_distributie.cost_extra_km   if > 0   → DORMANT
3.  configurare_beneficiari_transport.pret_distributie_km → HIDDEN
4.  0.0
```

### 8.3 Source status

| Source | Column | Status | Reachable from UI? | Live values **[DB]** |
|---|---|---|---|---|
| Route rule | `configurare_rute_distributie.tarif_tona` | **ACTIVE** | ✅ tabs 3 & 4 | 60.00 / 75.00 |
| Route rule | `configurare_rute_distributie.cost_extra_km` | **ACTIVE** | ✅ tabs 3 & 4 | 1.20 / 1.21 |
| Loading place | `configurare_locuri_incarcare.tarif` | **DORMANT** | ❌ catalog form posts only `loc_nume` | **0.00 in 19/19 rows** |
| Zone | `configurare_zone_distributie.tarif_distributie` | **DORMANT** | ❌ same | **0.00 in 19/19 rows** |
| Zone | `configurare_zone_distributie.cost_extra_km` | **DORMANT** | ❌ same | **0.00 in 19/19 rows** |
| Beneficiary | `pret_distributie_tona` | **HIDDEN** | ❌ hidden pass-through input (config.php:569) | 0.00 in 4/4 |
| Beneficiary | `pret_distributie_km` | **HIDDEN** | ❌ hidden pass-through input (config.php:570) | ⚠ **1.50 for ButanGas** |

> **Finding:** One hidden fallback rate is non-zero in production and is prevented from firing only by an unrelated setting.
>
> **Evidence:**
> - Table: `configurare_beneficiari_transport`, column `pret_distributie_km`, row `id = 33` (ButanGas) = **1.50** **[DB]**
> - No UI can display or edit it — `views/dispecer_curse/config.php:570` renders it as `<input type="hidden">`
> - It never fires today because **all 6 ButanGas `distributie` routes use `tarif_mod = 'tona'`** **[DB]**, so `usesKm = false` → `effectiveKmRate = 0` unconditionally (line 5471)
> - Switching any of those routes to `tona_km` or `km` while leaving `cost_extra_km = 0` would silently activate a 1.50 lei/km charge that no screen shows.

---

## 9. Distribuție — Configuration Scope

### 9.1 The uniqueness constraint decides this

**[DB]**
```
configurare_rute_distributie:
  UNIQUE KEY uk_config_rute_beneficiar_loc_zona_scope
             (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope)
```

**The pricing dimension tuple for Distribuție and P+D is therefore exactly:**

```
Distribution price rule =
      beneficiary
    + loading location
    + unloading zone
    + transport_scope   (distributie | primar_distributie)
```

**`vehicle` is NOT part of the tuple.**

### 9.2 Answers to the posed questions

| Question | Answer | Evidence |
|---|---|---|
| Two tariffs — same beneficiary, **different route**? | **YES** | The unique key includes loc + zonă. **[DB]** ButanGas has 6 `distributie` rules with different (loc, zonă) pairs and rates 60.00 / 75.00 |
| Two tariffs — same route, **different vehicle**? | **NO** | The unique key forbids a second row for the same tuple. `vehicle_ids` restricts *which vehicles may use* the single rule, not what they pay. **[CODE+DB]** |
| **Capacity**? | **NO** | Not a column on any route table; never read in a rate lookup — §18 |
| **Commodity**? | **NO** | Not a column on any route table; `tip_marfa` removed from the beneficiary — §19 |
| **Direction**? | **NO** | Resolution is bidirectional — §20 |
| **Date**? | **NO** | No effective-from/to column anywhere — §21 |

### 9.3 Contrast with Primar

**[DB]** `configurare_rute_primar` has **no unique key** on `(beneficiar_id, loc_incarcare_id, zona_distributie_id)` — only a non-unique index `idx_config_rute_primar_pereche`. Several rules for one pair, distinguished by vehicle set, are structurally permitted, and `getPrimaryRouteKmMap()` (`DispecerCurseModel.php:1564`) explicitly returns a `variants[]` array for exactly that purpose.

**[DB]** **0 duplicated pairs exist in live data** — the capability is unused today.

---

## 10. P+D — Exact Calculation

### 10.1 Formula

```
--- LOOKUP ---
rule = configurare_rute_distributie
       WHERE beneficiar_id, loc_incarcare_id, zona_distributie_id
         AND transport_scope = 'primar_distributie'
mode = 'tona_km'        # FORCED — no selector exists (controller :3002)

--- QUANTITY ---
km_cursa  : if empty AND rule.km_tarifare > 0  →  km_cursa = rule.km_tarifare   (line 5419)
tone      = cantitate_incarcata
km_totali = operator input

--- INVOICE  (lines 5540-5560) ---
if rule.aplica_cost_cursa AND rule.cost_cursa > 0:
        total_facturare = rule.cost_cursa
else:
        pret_tarifare   = rule.tarif_tona                       (line 5480)
        total_facturare = (tone × rule.tarif_tona)
                        + (km_cursa × rule.cost_extra_km)       (line 5560)

--- SEGMENTATION — REPORTING ONLY  (lines 5597-5665) ---
km_primar      = km_cursa
km_distributie = max(0, km_totali − km_cursa)
primaryPerKmRate  = rule.cost_extra_km        ⚠ the DISTRIBUTION km rate (line 5612)
totalPrimar       = km_primar × rule.cost_extra_km
totalDistributie  = tone × rule.tarif_tona
cost_km_primar      = totalPrimar      / km_primar       = cost_extra_km
cost_km_distributie = totalDistributie / km_distributie
cost_km_mixt        = total_facturare  / km_totali       (line 5654)
```

### 10.2 Which values affect what

| Value | affects `total_facturare` | affects derived only |
|---|---|---|
| `rule.tarif_tona` | ✅ | also `totalDistributie`, `cost_km_distributie` |
| `rule.cost_extra_km` | ✅ | also `totalPrimar`, `cost_km_primar` |
| `cantitate_incarcata` | ✅ | also `cost_km_distributie` |
| `km_cursa` | ✅ | also `cost_km_primar`, `km_distributie` |
| **`km_totali`** | ❌ **no** | **only** `km_distributie` → `cost_km_distributie`, and `cost_km_mixt` |
| `rule.km_tarifare` | ⚠ indirect — pre-fills `km_cursa` when empty | — |
| `rule.cost_cursa` + `aplica_cost_cursa` | ✅ full replacement | — |

### 10.3 Verified against production **[CODE+DB]**

| id | `tarif_tona` | `cost_extra_km` | tone | `km_cursa` | `km_totali` | tonnage comp. | km comp. | expected | **stored** |
|---|---|---|---|---|---|---|---|---|---|
| 348 | 75.00 | 1.21 | 8.00 | 630 | 760 | 600.00 | 762.30 | **1362.30** | **1362.30** ✅ |
| 347 | 60.00 | 1.21 | 10.00 | 1350 | 1520 | 600.00 | 1633.50 | **2233.50** | **2233.50** ✅ |

Derived values, trip #348: `cost_km_primar = 1.21` (= `cost_extra_km`, **not** `pret_km` — confirming line 5612); `cost_km_distributie = 600 / (760−630) = 600/130 = 4.615 → 4.62` ✅ stored.

⚠ `cost_km_mixt` stored `0.00` where the code computes `1362.30 / 760 = 1.79` — explained in §30.6 (seeded rows).

---

## 11. Compresor — Exact Calculation

### 11.1 Five independent components — none of them a generic tariff

**[CODE]** `resolveCompressorRates()` :6858; total at lines 5566-5580.

| # | Component | RATE | QUANTITY | UNIT | RATE SOURCE | WHEN USED |
|---|---|---|---|---|---|---|
| 1 | Suction time | `pret_ora_aspirare` | `ore_aspirare` | lei/oră | beneficiary | always (0 rate ⇒ 0 contribution) |
| 2 | Relocation | `pret_km_dislocare` | `km_dislocare` | lei/km | beneficiary | always |
| 3 | Delivered tonnage | `pret_tona_livrata` | `tona_livrata` | lei/tonă | beneficiary | always |
| 4 | Liquid suction | `pret_tona_aspirata_lichida` | `tona_aspirata_lichida` | lei/tonă | beneficiary | **only if rate > 0** (line 5573) |
| 5 | Gas suction | `pret_tona_aspirata_gazoasa` | `tona_aspirata_gazoasa` | lei/tonă | beneficiary | **only if rate > 0** (line 5576) |

### 11.2 Complete formula

```
total_facturare =   ore_aspirare          × pret_ora_aspirare
                  + km_dislocare          × pret_km_dislocare
                  + tona_livrata          × pret_tona_livrata
                  + (pret_tona_aspirata_lichida > 0
                        ? tona_aspirata_lichida × pret_tona_aspirata_lichida : 0)
                  + (pret_tona_aspirata_gazoasa > 0
                        ? tona_aspirata_gazoasa × pret_tona_aspirata_gazoasa : 0)

pret_tarifare = first non-zero of:
        pret_ora_aspirare → pret_km_dislocare → pret_tona_livrata
        → pret_tona_aspirata_lichida → pret_tona_aspirata_gazoasa        (lines 5499-5513)

cost_km_compresor = total_facturare / km_dislocare      (lines 5663-5668; 0 if km_dislocare = 0)
km_cursa is FORCIBLY DISCARDED for Compresor            (line 5122)
```

**There is no route, no loading location, no unloading zone and no `cost_cursa` for Compresor.** Vehicle involvement is limited to eligibility via `configurare_compresor_vehicule`. **[CODE+DB]**

### 11.3 Zero-rate behaviour

| Situation | Behaviour | Evidence |
|---|---|---|
| One component rate = 0 | that component contributes 0; the others still bill | multiplication, lines 5569-5578 |
| Components 4 or 5 rate = 0 | component skipped **and the input field is hidden on the trip form** (`shouldShowCompressorLiquidSuctionField`) | :5488-5489 **[CODE]** |
| **All five rates = 0** | soft error *"Beneficiarul selectat nu are tarife valide pentru transport Compresor."* — **non-blocking**; trip saves with `total_facturare = 0` | :5490-5497 |
| `km_dislocare = 0` | `cost_km_compresor = 0` (guarded division) | :5665 |

### 11.4 Verified against production **[CODE+DB]**

| id | `ore_aspirare` × `pret_ora_aspirare` | `tona_livrata` × `pret_tona_livrata` | expected | **stored** | `pret_tarifare` | `cost_km_compresor` |
|---|---|---|---|---|---|---|
| 350 | 8.00 × 80.00 = 640.00 | 5.00 × 50.00 = 250.00 | **890.00** | **890.00** ✅ | **80.00** (lei/h) | 0.00 (`km_dislocare = 0`) |
| 349 | 6.00 × 80.00 = 480.00 | 4.00 × 50.00 = 200.00 | **680.00** | **680.00** ✅ | 80.00 | 0.00 |

**[DB]** ButanGas is the only compressor-enabled beneficiary: `pret_ora_aspirare = 80.00`, `pret_tona_livrata = 50.00`, `pret_km_dislocare = 0.00`, both suction-tonnage rates `0.00`. So **2 of 5 components are live**, 3 are configured at zero.

---

## 12. Meaning of `pret_tarifare`

`curse_dispecer.pret_tarifare` is a **snapshot of a representative unit rate whose unit changes per transport type — and, for Distribuție, per route**. **[CODE+DB]**

| Transport | `pret_tarifare` represents | Unit | Verified value **[DB]** |
|---|---|---|---|
| Primar km | `pret_km` (fallback `pret_tona`) | **lei/km** | 1.21 (trips 338-353) |
| Primar tone | `pret_tona` (fallback `pret_km` ⚠ §5.4) | **lei/tonă** | 60.00 (trips 341, 342) |
| Distribuție — mode `tona` | `tarif_tona` | **lei/tonă** | 60.00 / 75.00 (trips 343-346) |
| Distribuție — mode `km` | `cost_extra_km` | **lei/km** | **1.20** (trips 351, 352) |
| Distribuție — mode `tona_km` | `tarif_tona` if > 0, else `cost_extra_km` | **lei/tonă or lei/km** | no live rows |
| P+D | `tarif_tona` | **lei/tonă** | 60.00 / 75.00 (trips 347, 348) |
| Compresor | first non-zero of five rates | **lei/oră or lei/km or lei/tonă** | 80.00 = lei/oră (trips 349, 350) |

### 12.1 When `cost_cursa` is active

**[CODE]** lines 5440, 5478: `pret_tarifare = cost_cursa` and `total_facturare = cost_cursa`.

The column then holds a **lei/cursă** figure — a total, not a unit rate — in the same column that otherwise holds lei/km, lei/tonă or lei/oră. Applies to `primar`, `primar_tona`, `distributie`, `primar_distributie`. Not applicable to `compresor` (no `cost_cursa`). **[DB]** 0 live trips (no route has `aplica_cost_cursa = 1`).

### 12.2 Why this matters downstream

**[CODE]** `CentralizatorFacturareService::buildDistributionSection()` :287-300 groups distribution tonnage into **"tariff buckets" keyed on `pret_tarifare`**. With `distributie` routes in both `tona` and `km` mode, that grouping mixes lei/tonă buckets (60.00, 75.00) with lei/km buckets (1.20) in the same axis. **[DB]** confirmed: both mode families exist in production.

---

## 13. Commercial Configuration Fields

**Group A — values an administrator configures as a commercial rule.**

| Transport | Field | Category | Unit | Source (table.column) |
|---|---|---|---|---|
| Primar km | `pret_km` | RATE | lei/km | `configurare_beneficiari_transport.pret_km` |
| Primar km, Primar tone | `pret_tarifare` | RATE (fallback) | ambiguous | `configurare_beneficiari_transport.pret_tarifare` **HIDDEN** |
| Primar tone | `pret_tona` | RATE | lei/tonă | `configurare_beneficiari_transport.pret_tona` |
| Primar km, Primar tone | `km_tarifare` | **QUANTITY** (configured) | km | `configurare_rute_primar.km_tarifare` |
| Primar km, Primar tone | `cost_cursa` | FIXED PRICE | lei/cursă | `configurare_rute_primar.cost_cursa` |
| Primar km, Primar tone | `aplica_cost_cursa` | SWITCH | bool | `configurare_rute_primar.aplica_cost_cursa` |
| Primar km, Primar tone | `km_agreati_manual` | SWITCH | bool | `configurare_rute_primar.km_agreati_manual` |
| Distribuție, P+D | `tarif_tona` | RATE | lei/tonă | `configurare_rute_distributie.tarif_tona` |
| Distribuție, P+D | `cost_extra_km` | RATE | lei/km | `configurare_rute_distributie.cost_extra_km` |
| Distribuție | `tarif_mod` | SWITCH | enum | `configurare_rute_distributie.tarif_mod` |
| P+D | `km_tarifare` | **QUANTITY** (configured) | km | `configurare_rute_distributie.km_tarifare` |
| Distribuție, P+D | `cost_cursa` | FIXED PRICE | lei/cursă | `configurare_rute_distributie.cost_cursa` |
| Distribuție, P+D | `aplica_cost_cursa` | SWITCH | bool | `configurare_rute_distributie.aplica_cost_cursa` |
| Distribuție, P+D | `tarif` | RATE (dormant) | lei/tonă | `configurare_locuri_incarcare.tarif` **UNREACHABLE** |
| Distribuție, P+D | `tarif_distributie` | RATE (dormant) | lei/tonă | `configurare_zone_distributie.tarif_distributie` **UNREACHABLE** |
| Distribuție, P+D | `cost_extra_km` | RATE (dormant) | lei/km | `configurare_zone_distributie.cost_extra_km` **UNREACHABLE** |
| Distribuție, P+D | `pret_distributie_tona` | RATE (fallback) | lei/tonă | `configurare_beneficiari_transport` **HIDDEN** |
| Distribuție, P+D | `pret_distributie_km` | RATE (fallback) | lei/km | `configurare_beneficiari_transport` **HIDDEN** |
| Compresor | `pret_ora_aspirare` | RATE | lei/oră | `configurare_beneficiari_transport` |
| Compresor | `pret_km_dislocare` | RATE | lei/km | `configurare_beneficiari_transport` |
| Compresor | `pret_tona_livrata` | RATE | lei/tonă | `configurare_beneficiari_transport` |
| Compresor | `pret_tona_aspirata_lichida` | RATE | lei/tonă | `configurare_beneficiari_transport` |
| Compresor | `pret_tona_aspirata_gazoasa` | RATE | lei/tonă | `configurare_beneficiari_transport` |

**Count: 13 rate-type values (7 reachable, 6 dormant/hidden), 2 fixed prices, 2 configured quantities, 5 switches.**

---

## 14. Operational Quantity Fields

**Group B — values produced when a trip happens.**

| Transport | Field | Category | Unit | Source |
|---|---|---|---|---|
| Primar km | `km_cursa` | QUANTITY | km | **route config** — overwritten at line 5285 unless `km_agreati_manual = 1`, then operator |
| Primar tone | `cantitate_incarcata` | QUANTITY | tone | operator |
| Primar tone | `km_cursa` | QUANTITY (**not invoiced**) | km | route config; feeds `cost_km_primar` only |
| Distribuție | `cantitate_incarcata` | QUANTITY | tone | operator (used in modes `tona`, `tona_km`) |
| Distribuție | `km_cursa` | QUANTITY | km | operator (used in modes `km`, `tona_km`) |
| P+D | `cantitate_incarcata` | QUANTITY | tone | operator |
| P+D | `km_cursa` | QUANTITY | km | operator, pre-filled from `rule.km_tarifare` when empty |
| P+D | `km_totali` | QUANTITY (**not invoiced**) | km | operator; feeds `cost_km_distributie`, `cost_km_mixt` |
| Compresor | `ore_aspirare` | QUANTITY | ore | operator |
| Compresor | `km_dislocare` | QUANTITY | km | operator |
| Compresor | `tona_livrata` | QUANTITY | tone | operator |
| Compresor | `tona_aspirata_lichida` | QUANTITY | tone | operator |
| Compresor | `tona_aspirata_gazoasa` | QUANTITY | tone | operator |

**[CODE]** `capacitate_transport` is stored on the trip as a snapshot but is **not** an operational pricing quantity — §18.

---

## 15. Pricing Rule Scope

| Rate | Transport | Scope |
|---|---|---|
| `pret_km` | Primar km (+ P+D reporting only) | **per beneficiary** |
| `pret_tona` | Primar tone | **per beneficiary** |
| `pret_tarifare` (config) | Primar km, Primar tone | **per beneficiary** (hidden fallback) |
| `pret_distributie_tona` | Distribuție, P+D | **per beneficiary** (hidden fallback) |
| `pret_distributie_km` | Distribuție, P+D | **per beneficiary** (hidden fallback) |
| `pret_ora_aspirare` | Compresor | **per beneficiary** |
| `pret_km_dislocare` | Compresor | **per beneficiary** |
| `pret_tona_livrata` | Compresor | **per beneficiary** |
| `pret_tona_aspirata_lichida` | Compresor | **per beneficiary** |
| `pret_tona_aspirata_gazoasa` | Compresor | **per beneficiary** |
| `tarif_tona` | Distribuție, P+D | **per beneficiary + loc + zonă + scope** |
| `cost_extra_km` | Distribuție, P+D | **per beneficiary + loc + zonă + scope** |
| `tarif` (loc) | Distribuție, P+D fallback | **per beneficiary + loading location** (dormant) |
| `tarif_distributie` (zonă) | Distribuție, P+D fallback | **per beneficiary + unloading zone** (dormant) |
| `cost_extra_km` (zonă) | Distribuție, P+D fallback | **per beneficiary + unloading zone** (dormant) |
| `cost_cursa` (primar) | Primar km, Primar tone | **per beneficiary + loc + zonă [+ vehicle set]** |
| `cost_cursa` (distrib) | Distribuție, P+D | **per beneficiary + loc + zonă + scope** |
| `km_tarifare` (primar) | Primar km, Primar tone | **per beneficiary + loc + zonă [+ vehicle set]** |
| `km_tarifare` (distrib) | P+D | **per beneficiary + loc + zonă + scope** |

**Nothing is configured globally. Nothing is configured per vehicle alone, per capacity, per commodity or per date.** **[CODE+DB]**

---

## 16. Beneficiary Influence

**[CODE+DB]** The beneficiary is the **root of every pricing hierarchy** and the **only** rate source for `Primar km`, `Primar tone` and `Compresor`.

| Effect | Detail |
|---|---|
| Rate source | all 10 beneficiary-level rates |
| Eligibility gate | `suporta_primar` / `suporta_distributie` / `suporta_primar_distributie` / `suporta_compresor` — a rate of 0 is returned if the flag is off (`resolveBeneficiaryRate()` :6820, :6836, :6852) |
| Route ownership | loc + zonă rows are `beneficiar_id`-scoped with `ON DELETE CASCADE`; a trip whose loc/zonă belongs to another beneficiary is a **hard error** (:5236, :5340) |
| Repricing trigger | `beneficiar_id` ∈ `RACE_PRICING_INPUT_FIELDS` |
| Historical protection | `curse_dispecer.beneficiar_id` FK is `ON DELETE RESTRICT` — a beneficiary used in trips cannot be deleted **[DB]** |

---

## 17. Route Influence

| Transport | Route supplies | Route does NOT supply |
|---|---|---|
| Primar km | `km_tarifare` (quantity), `cost_cursa`, `km_agreati_manual` | **any rate** |
| Primar tone | `cost_cursa`; `km_tarifare` (feeds reporting only) | **any rate** |
| Distribuție | `tarif_tona`, `cost_extra_km`, `tarif_mod`, `cost_cursa` | — |
| P+D | `tarif_tona`, `cost_extra_km`, `km_tarifare`, `cost_cursa` | — |
| Compresor | **nothing — no route concept exists** | everything |

**[CODE]** If no Primar route matches, a soft error is raised (*"Combinatia selectata Loc ↔ Zona nu este configurata in Setari Primar"*, :5273) and `km_cursa` keeps the operator's value — **the trip still prices at `pret_km`**, because the rate never came from the route.

---

## 18. Vehicle Influence

### Q1 — Can two vehicles on the same route receive different pricing rules?

**Distribuție / P+D: NO.** The `UNIQUE (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope)` key makes a second rule for the same route impossible. **[DB]**

**Primar: the QUANTITY can differ; the RATE cannot.** `configurare_rute_primar` has no unique key, and `getPrimaryRouteKmMap()` (`DispecerCurseModel.php:1564-1613`) returns a `variants[]` array per pair specifically to support "km different per garage". But the lei/km is beneficiary-global, so two vehicles can be invoiced different **amounts** (different agreed km) at the **same rate**. **[CODE]**
**[DB]** 0 duplicated pairs exist today — the capability is unused.

### Q2 — Does vehicle capacity affect pricing?

**NO.** §19.

### Q3 — Is capacity only a visual grouping?

**YES, in the configuration UI.** `config.php` renders `$vehicleCapacityGroups` as collapsible groups inside every vehicle multiselect (tabs 1, 3, 4, 5). It never reaches a rate lookup. **[CODE]**

### Q4 — Does selecting another vehicle cause another route rule to match?

**YES — this is the main pricing effect of the vehicle.**

**[CODE]** `resolveDistributionRouteScopeForVehicle()` :7002-7115 and `getPrimaryRouteRuleForBeneficiary()` (`DispecerCurseModel.php:1441-1503`).

Primar tie-break (model :1483-1502):
```
rule containing this vehicle  →  rule with empty vehicle_ids  →  if exactly one rule, that one  →  else NO MATCH
```

Distribution scoping (controller :7101-7108):
```
if ANY rule for (beneficiary, scope) has a non-empty vehicle_ids:
        rules with EMPTY vehicle_ids are EXCLUDED for the selected vehicle
```

> **Finding:** the two resolvers use **opposite** fallback semantics.
> **Evidence:** `DispecerCurseModel::getPrimaryRouteRuleForBeneficiary()` lines 1489-1496 falls back to the unrestricted rule; `DispecerCurseController::resolveDistributionRouteScopeForVehicle()` lines 7101-7108 drops unrestricted rules as soon as one sibling rule is vehicle-scoped.
> **[DB]** not observable today — **all 13 distribution rules have a non-empty `vehicle_ids`**, so no unrestricted distribution rule exists to be dropped.

### Q5 — Can a rule apply to multiple vehicles?

**YES.** `vehicle_ids` is a sorted CSV in a `TEXT` column. **[DB]** live example: rule 57 → `15,16,17,18,19,20,21,22` (8 vehicles). `NULL` is interpreted as "any vehicle" **[CODE]** `parseDistributionRouteVehicleIds()` :7122.

**[DB]** 0 of 13 distribution rules and 8 of 11 primary rules have `vehicle_ids = NULL`.

---

## 19. Capacity Influence

**NO — capacity participates in no commercial price lookup or formula.** **[CODE+DB]**

| Where capacity appears | Role |
|---|---|
| `curse_dispecer.capacitate_transport` | historical snapshot of the vehicle's capacity, written at save (`validateRaceInput()` :4948, :5730) |
| `normalizeTonInputToKgForPricing($value, $vehicleCapacityTon)` :7709 | **accepts capacity and ignores it** |
| `config.php` vehicle multiselects | visual grouping only (`$vehicleCapacityGroups`) |
| `CentralizatorFacturareService::normalizedLoadedTons()` :1743 | **reporting** kg→tonne heuristic (`qty > capacity × 3` ⇒ `/1000`) |
| `DispecerCurseModel` `$loadedTonsExpr` :2740, :6040 | same heuristic in SQL, **reporting** |

> **Finding:** the only function whose name suggests capacity-aware pricing explicitly performs no conversion.
> **Evidence:** File `htdocs/controllers/DispecerCurseController.php`, function `normalizeTonInputToKgForPricing()`, lines 7704-7717:
> ```php
> /**
>  * Normalizeaza cantitatea pentru calcule pe unitatea introdusa de operator.
>  * Valorile sunt folosite direct (fara conversie automata tone -> kg).
>  */
> private function normalizeTonInputToKgForPricing(?float $value, ?float $vehicleCapacityTon = null): ?float
> {
>     if ($value === null) { return null; }
>     $normalized = (float) $value;
>     return $normalized;                      // ← capacity never used
> }
> ```
> Called only for `tona_livrata` (Compresor), line 5175. Capacity has **zero** effect on any invoiced amount.

---

## 20. Commodity Influence

**NO — commodity does not affect any commercial rate today, and cannot.** **[CODE+DB]**

| Fact | Evidence |
|---|---|
| Values: `butan`, `propan`, `autogaz` | `GOODS_TYPES` :29-33 |
| Stored on the trip as a CSV | `curse_dispecer.tip_marfa VARCHAR(255)`, written at :5734 |
| **No config table has a commodity column** | `configurare_beneficiari_transport`, `configurare_rute_primar`, `configurare_rute_distributie` — none **[DB]** |
| `configurare_beneficiari_transport.tip_marfa` exists but is **wiped to `''` on every save** | controller :4348, :4374 |
| Not in `RACE_PRICING_INPUT_FIELDS` | :1332-1345 — changing commodity does **not** reprice |
| Used only for reporting breakdowns | `CentralizatorFacturareService::buildDistributionSection()` :5341-5361 (`cargo_by_tariff` matrix) |

**Could `Propan → 60 lei/t` vs `Autogaz → 65 lei/t` exist today?** **NO.** There is no column to hold it and no lookup that would read it. `STARE_PROIECT.md:257` records that `Tip marfa` was **deliberately removed** from the configuration page.

---

## 21. Route Direction

**`A → B` and `B → A` are the SAME pricing rule for every route-based transport type.** **[CODE]**

**Primar** — `resolvePrimaryRouteRuleForBeneficiaryBidirectional()` :7265-7360:
```
1. exact (beneficiar, loc, zonă) by id
2. REVERSED (beneficiar, zonă, loc) by id            ← line 7285
3. exact by normalised names
4. REVERSED by normalised names                      ← line 7354
```

**Distribuție / P+D** — `resolveDistributionRouteRuleForBeneficiaryBidirectional()` :7229 and `resolveDistributionRouteRuleByNamePairFromScopedMap()` :7205 apply the same four-step pattern.

Reinforcing this, `syncPrimaryRouteBidirectionalCatalog()` :5846-5910 **automatically mirrors** every zone name into a loading place and vice-versa on each page load, so the reverse pair physically exists in the catalog.

**Consequence:** a different return-leg rate is **not expressible** in the current model.

---

## 22. Date / Period Influence

**NO rate depends on any date.** **[CODE+DB]**

| Check | Result |
|---|---|
| `effective_from` / `valabil_de_la` columns | **none** in any `configurare_*` table **[DB]** |
| `effective_to` / `valabil_pana_la` | **none** |
| Any rate lookup filtered by trip date | **none** — every resolver filters on `beneficiar_id`, ids, `activ`, `transport_scope`, vehicle |
| `created_at` / `updated_at` used in a lookup | **no** — audit only |
| Versioning / `superseded_by` | **none** |

### The explicit question

> **Can two tariff versions for the same pricing rule coexist today for different periods?**
> **Example:** July `Lugoj → București = 1.21 lei/km`, August `= 1.35 lei/km`, selected by trip date.

**NO — confirmed, structurally impossible.** **[CODE+DB]**

**Evidence:**
- `pret_km` is a **single scalar column** on a single beneficiary row (`configurare_beneficiari_transport.pret_km`). There is one value at any instant.
- `configurare_rute_distributie` carries `UNIQUE (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope)` — a second row for the same route cannot exist, with or without a date.
- No resolver accepts a date parameter. `resolveBeneficiaryRate()`, `resolveDistributionTonRate()`, `getPrimaryRouteRuleForBeneficiary()`, `resolveDistributionRouteScopeForVehicle()` — none has a date argument.

The **only** period-dependence in the whole system is accidental: `curse_dispecer.pret_tarifare` / `total_facturare` are snapshots frozen at save time (§25). History is preserved by the snapshot, not by the configuration.

---

## 23. Fixed Trip Price Behaviour

### 23.1 Where `cost_cursa` exists

| Transport | Table | Configurable in UI |
|---|---|---|
| Primar km, Primar tone | `configurare_rute_primar.cost_cursa` + `.aplica_cost_cursa` | ✅ tab 5 |
| P+D | `configurare_rute_distributie.cost_cursa` + `.aplica_cost_cursa` | ✅ tab 4 |
| Distribuție | column exists but **forced to 0/false at save** (controller :3010-3012) | ❌ not in the tab-3 form |
| Compresor | **does not exist** | — |

### 23.2 Semantics — full replacement, not a minimum, not additive

**[CODE]**

```
Primar (lines 5440, 5523-5524):
    if aplica_cost_cursa AND cost_cursa > 0:
            pret_tarifare   = cost_cursa
            total_facturare = cost_cursa          # km × pret_km NOT computed

P+D / Distribuție (lines 5478, 5546-5556):
    fixedRideComponent = aplica_cost_cursa && cost_cursa > 0 ? cost_cursa : 0
    tonComponent       = routeApplyRideCost ? 0 : tone × effectiveTonRate
    kmComponent        = routeApplyRideCost ? 0 : ...
    total_facturare    = fixedRideComponent + tonComponent + kmComponent
                       = cost_cursa                # the other two are forced to 0
```

| Question | Answer |
|---|---|
| Completely replaces the normal calculation? | **YES** |
| Acts as a minimum price? | **NO** — it is applied unconditionally, even if lower |
| Additive? | **NO** — the additive form at line 5556 is neutralised because both other components are forced to 0 |
| Zero special meaning? | **YES** — `cost_cursa = 0` makes the override inert regardless of the switch (`> 0` is required at :5271, :5364); the save-time validator rejects switching it on with 0 (*"Completeaza Cost cursa cu o valoare mai mare ca 0…"*, controller :3090) |
| Switch without a value? | blocked at save |
| Value without the switch? | **stored and ignored** — **[DB]** rule 15 (Vixon): `cost_cursa = 4000.00`, `aplica_cost_cursa = 0` |

**[DB]** **0 routes have the override active** (`aplica_cost_cursa = 1` count = 0 in both tables), so **no live trip is priced this way**.

---

## 24. Repricing Triggers

**[CODE]** `RACE_PRICING_INPUT_FIELDS` :1332-1345 — 13 fields; comparison is value-based via a `canonical()` closure (numeric → `%.4F`, empty → `null`) at :1424-1430.

| Field changed | Effect | Reason |
|---|---|---|
| `tip_transport` | **CHANGES BOTH** | selects a different formula and a different rate family |
| `beneficiar_id` | **CHANGES COMMERCIAL RULE MATCH** | the root of every rate lookup |
| `vehicle_id` | **CHANGES COMMERCIAL RULE MATCH** | vehicle-scoped rule eligibility (§18 Q4); no rate of its own |
| `loc_incarcare_id` | **CHANGES BOTH** | route match → rate (Distr./P+D) and `km_tarifare` (Primar) |
| `zona_distributie_id` | **CHANGES BOTH** | as above |
| `km_cursa` | **CHANGES QUANTITY** | invoiced for Primar km, Distribuție (`km`/`tona_km`), P+D |
| `km_totali` | **CHANGES QUANTITY** (derived only) | affects `cost_km_distributie` / `cost_km_mixt`; **not** `total_facturare` |
| `cantitate_incarcata` | **CHANGES QUANTITY** | invoiced for Primar tone, Distribuție, P+D |
| `ore_aspirare` | **CHANGES QUANTITY** | Compresor component 1 |
| `km_dislocare` | **CHANGES QUANTITY** | Compresor component 2 |
| `tona_livrata` | **CHANGES QUANTITY** | Compresor component 3 |
| `tona_aspirata_lichida` | **CHANGES QUANTITY** | Compresor component 4 |
| `tona_aspirata_gazoasa` | **CHANGES QUANTITY** | Compresor component 5 |
| **`tip_marfa`** | **DOES NOT AFFECT PRICE** | not in the list; no rate lookup uses it |
| **`driver_id`** | **DOES NOT AFFECT PRICE** | not in the list |
| **dates / hours / duration** | **DOES NOT AFFECT PRICE** | not in the list |
| **`nr_clienti`, `cantitate_prelevata`** | **DOES NOT AFFECT PRICE** | not in the list |
| **`observatii`** | **DOES NOT AFFECT PRICE** | not in the list |
| **`capacitate_transport`** | **DOES NOT AFFECT PRICE** | not in the list; §19 |
| **nothing (plain re-save)** | **DOES NOT AFFECT PRICE** | canonical comparison finds no diff |

### 24.1 When the trip is already `facturat`

**[CODE]** :1434-1442:

```php
$isInvoiced = (string) ($existing['status_facturare'] ?? '') === 'facturat';
if (!$pricingChanged || $isInvoiced) {
    foreach (['pret_tarifare','total_facturare','cost_km_primar','cost_km_distributie',
              'cost_km_mixt','cost_km_compresor'] as $field) {
        $data[$field] = round((float) ($existing[$field] ?? 0), 2);
    }
    if ($pricingChanged && $isInvoiced) {
        flash_set('info', 'Cursa este deja facturata: valorile financiare existente au fost pastrate…');
    }
}
```

All six financial columns are restored from the stored row and an info flash is shown. **The edit itself is not blocked** — quantities change, money does not.

**[DB]** 1 of 58 non-deleted trips is `facturat`; 57 are `in_curs_facturare` and therefore repriceable.

---

## 25. Configuration Change Behaviour

### 25.1 Scenario: `pret_km` 1.21 → 1.30

| Target | Effect | Evidence |
|---|---|---|
| **Existing trips** | **unchanged** — `total_facturare` is a stored column | **[CODE+DB]** |
| **New trips** | priced at 1.30 | `resolveBeneficiaryRate()` reads live config at save |
| **Existing unbilled trips** (`in_curs_facturare`) | **unchanged until edited**; if any of the 13 trigger fields is then touched → **silently repriced at 1.30** | :1424-1442 |
| **Existing invoiced trips** (`facturat`) | **unchanged even when edited** + info flash | :1435-1441 |
| **Trip edit** | see above — the decisive factor is whether a trigger field changed | :1332-1345 |
| **Billing centralizer** | **unchanged** — reads only `c.total_facturare`, `c.pret_tarifare`, `c.cost_km_*`; joins `configurare_*` for **`nume` only** | `CentralizatorFacturareService::fetchTripRows()` :626-668 |
| **Reports / dashboards** | **unchanged** — `SUM(c.total_facturare)` throughout | `DispecerCurseModel` :2784, :2816, :2842, :6070 |

**Route rates (`tarif_tona`, `cost_extra_km`) behave identically** — same snapshot mechanism, same trigger list.

### 25.2 Does any mechanism recalculate existing trips?

**NO.** **[CODE]**

> **Finding:** No code path updates `curse_dispecer` financial columns as a consequence of a configuration change.
>
> **Evidence:**
> - The 13 `config*Action()` methods (`DispecerCurseController.php` :2953–4510) write only to `configurare_*` tables. None issues an `UPDATE curse_dispecer`.
> - The only writers of `total_facturare` are `DispecerCurseModel::createRace()` (:4159) and `::updateRace()` (:4249), both driven by `validateRaceInput()`.
> - The only other `UPDATE curse_dispecer` statements are: `parent_cursa_id` linking (controller :1063), billing-status changes (model :4302, :4328), soft delete/restore (model :4384, :4418), and `loc_incarcare_id = NULL` detach in the orphan `config_delete_loc`/`_zona` endpoints (§32.4).
> - The single DDL-time backfill (`ensureRaceCostPerKmColumns()` model :1978, `SET cost_km_compresor = ROUND(total_facturare / km_dislocare, 2)`) derives from the **stored total**, not from configuration.
> - No cron, scheduled task or CLI script touches trip financials — `scripts/` contains only the CardOil sync, leasing reminders, a seed script and test helpers.

**Answer to the posed classification: C, qualified by D.**
- **C** — a configuration change affects an existing trip **only when that trip is later edited**, and only if a trigger field changed;
- **D** — with a status-dependent exception: `facturat` trips are never repriced.
- **A** is true for trips that are never edited; **B** is false.

---

## 26. Historical Trip Behaviour

**[CODE+DB]** `curse_dispecer` stores a **financial snapshot**: `pret_tarifare`, `total_facturare`, `cost_km_primar`, `cost_km_distributie`, `cost_km_mixt`, `cost_km_compresor`.

| Property | Status |
|---|---|
| Snapshot exists | ✅ 6 columns |
| Reference to the rule that produced it | ❌ **none** — no `rule_id`, no version |
| Rate breakdown preserved | ❌ only one representative unit rate |
| Reconstructible from the trip alone | ⚠ partially — `pret_tarifare` + quantities allow a check, but the *unit* of `pret_tarifare` must be inferred from `tip_transport` **and** (for Distribuție) the route's current `tarif_mod` |
| Protected against later config edits | ✅ unless the trip is edited and a trigger field changes |
| Protected when `facturat` | ✅ absolutely |

**[DB]** `pret_tarifare` and `total_facturare` are part of `RACE_DUPLICATE_KEY_FIELDS` (`DispecerCurseModel.php:28-67`) — the sha256 duplicate key. A repriced trip therefore gets a **different `duplicate_key`**, so a genuine duplicate created before and after a rate change would no longer be detected as one.

---

## 27. Pricing Hierarchy per Transport Type

Four distinct hierarchies. **[CODE+DB]**

```
PRIMAR KM  /  PRIMAR TONE
    Beneficiary ──────────────► RATE   (pret_km | pret_tona, fallback pret_tarifare)
        │
        └── Loading location + Unloading zone (bidirectional)
                │
                └── [optional] Vehicle set ──► QUANTITY (km_tarifare)
                                               SWITCH   (km_agreati_manual)
                                               OVERRIDE (cost_cursa + aplica_cost_cursa)

DISTRIBUȚIE
    Beneficiary (gate: suporta_distributie)
        │
        └── Loading location + Unloading zone + scope='distributie'   [UNIQUE]
                │
                ├── MODE  (tarif_mod: tona | km | tona_km)
                ├── RATE  (tarif_tona)       ─┐
                ├── RATE  (cost_extra_km)    ─┼─► selected by MODE
                ├── OVERRIDE (cost_cursa)     │
                └── Vehicle set ──► ELIGIBILITY ONLY (cannot change the rate)
                        │
                        └── fallback ► zone ► loading place ► beneficiary  [all dormant/hidden]

P+D
    Beneficiary (gate: suporta_primar_distributie)
        │
        └── Loading location + Unloading zone + scope='primar_distributie'   [UNIQUE]
                │
                ├── MODE forced to tona_km
                ├── RATE  (tarif_tona)      → tonnage component
                ├── RATE  (cost_extra_km)   → km component
                ├── QUANTITY (km_tarifare)  → pre-fills km_cursa
                ├── OVERRIDE (cost_cursa)
                └── Vehicle set ──► ELIGIBILITY ONLY

COMPRESOR
    Beneficiary (gate: suporta_compresor)
        │
        ├── RATE pret_ora_aspirare
        ├── RATE pret_km_dislocare
        ├── RATE pret_tona_livrata
        ├── RATE pret_tona_aspirata_lichida     (also controls field visibility)
        ├── RATE pret_tona_aspirata_gazoasa     (also controls field visibility)
        └── configurare_compresor_vehicule ──► ELIGIBILITY ONLY
    NO route · NO loading location · NO unloading zone · NO cost_cursa
```

---

## 28. Calculation Trees

```
PRIMAR KM
    Beneficiary
        ↓  pret_km                                   [RATE, lei/km, beneficiary]
    Route (loc ↔ zonă, bidirectional)
        ↓  km_tarifare                               [QUANTITY, km, route]
        ↓  km_agreati_manual → if 1, km from operator [SWITCH]
    Vehicle
        ↓  rule eligibility / variant selection       [DIMENSION]
    Formula
        ↓  total_facturare = km × pret_km
    Override?
        ↓  aplica_cost_cursa && cost_cursa > 0  →  total_facturare = cost_cursa
    Snapshot
        ↓  pret_tarifare = pret_km        (or cost_cursa)
        ↓  cost_km_primar = pret_km       [DERIVED KPI]
        ↓  cost_km_mixt  = cost_km_primar [DERIVED KPI]

PRIMAR TONE
    Beneficiary
        ↓  pret_tona                                 [RATE, lei/t, beneficiary]
    Trip
        ↓  cantitate_incarcata                       [QUANTITY, tone, operator]
    Route
        ↓  km_tarifare → km_cursa   (stored, NOT invoiced)
        ↓  cost_cursa                                [OVERRIDE]
    Formula
        ↓  total_facturare = cantitate_incarcata × pret_tona
    Snapshot
        ↓  pret_tarifare = pret_tona
        ↓  cost_km_primar = pret_km      ⚠ NOT total/km — decoupled from the invoice
        ↓  cost_km_mixt  = cost_km_primar

DISTRIBUȚIE
    Beneficiary  (suporta_distributie)
    Route (loc ↔ zonă, scope='distributie')  [UNIQUE]
        ↓  tarif_mod                                 [SWITCH]
        ↓  tarif_tona        if mode ∈ {tona, tona_km}   [RATE, lei/t]
        ↓  cost_extra_km     if mode ∈ {km, tona_km}     [RATE, lei/km]
        ↓  fallback ► zonă ► loc ► beneficiary  (all dormant/hidden)
    Vehicle
        ↓  eligibility only
    Trip
        ↓  cantitate_incarcata, km_cursa             [QUANTITY]
    Formula
        ↓  total = tone × effectiveTonRate + (effectiveKmRate > 0 ? km × effectiveKmRate : 0)
    Override?
        ↓  cost_cursa   (column exists but forced to 0 at save for this scope)
    Snapshot
        ↓  pret_tarifare = effectiveTonRate > 0 ? effectiveTonRate : effectiveKmRate
        ↓  cost_km_distributie = total / km_cursa
        ↓  cost_km_mixt = cost_km_distributie

P+D
    Beneficiary  (suporta_primar_distributie)
    Route (loc ↔ zonă, scope='primar_distributie')  [UNIQUE]
        ↓  tarif_tona        [RATE, lei/t]   → tonnage component
        ↓  cost_extra_km     [RATE, lei/km]  → km component
        ↓  km_tarifare       [QUANTITY]      → pre-fills km_cursa when empty
        ↓  cost_cursa + aplica_cost_cursa    [OVERRIDE]
    Vehicle
        ↓  eligibility only
    Trip
        ↓  cantitate_incarcata, km_cursa, km_totali
    Formula
        ↓  total_facturare = tone × tarif_tona + km_cursa × cost_extra_km
    Derived (NOT invoiced)
        ↓  km_distributie = max(0, km_totali − km_cursa)
        ↓  cost_km_primar = cost_extra_km      ⚠ uses the DISTRIBUTION km rate
        ↓  cost_km_distributie = (tone × tarif_tona) / km_distributie
        ↓  cost_km_mixt = total_facturare / km_totali
    Snapshot
        ↓  pret_tarifare = tarif_tona

COMPRESOR
    Beneficiary  (suporta_compresor)
        ↓  pret_ora_aspirare            [RATE, lei/oră]
        ↓  pret_km_dislocare            [RATE, lei/km]
        ↓  pret_tona_livrata            [RATE, lei/t]
        ↓  pret_tona_aspirata_lichida   [RATE, lei/t]  + controls field visibility
        ↓  pret_tona_aspirata_gazoasa   [RATE, lei/t]  + controls field visibility
    configurare_compresor_vehicule
        ↓  eligibility only
    Trip
        ↓  ore_aspirare · km_dislocare · tona_livrata
        ↓  tona_aspirata_lichida · tona_aspirata_gazoasa
    Formula
        ↓  total_facturare = Σ (quantity_i × rate_i)   over 5 components
    Override?
        ↓  NONE — cost_cursa does not exist for Compresor
    Snapshot
        ↓  pret_tarifare = first non-zero rate in a fixed 5-step cascade
        ↓  cost_km_compresor = total / km_dislocare
```

---

## 29. Pricing Component Matrix

| Component | Primar km | Primar tone | Distribuție | P+D | Compresor |
|---|---|---|---|---|---|
| **lei/km** | **YES** — `pret_km` (beneficiary) | NO for invoice; YES for `cost_km_primar` | **CONDITIONAL** — `cost_extra_km` (route), only in mode `km`/`tona_km` | **YES** — `cost_extra_km` (route) | **YES** — `pret_km_dislocare` (beneficiary) |
| **lei/tonă** | NO | **YES** — `pret_tona` (beneficiary) | **CONDITIONAL** — `tarif_tona` (route), only in mode `tona`/`tona_km` | **YES** — `tarif_tona` (route) | **YES** — 3 rates: `pret_tona_livrata`, `..._lichida`, `..._gazoasa` |
| **lei/oră** | NO | NO | NO | NO | **YES** — `pret_ora_aspirare` |
| **cost/cursă** | **YES** — route, currently inactive | **YES** — route, currently inactive | **NO** — forced to 0 at save | **YES** — route, currently inactive | **NO** — does not exist |
| **route dependent** | **CONDITIONAL** — route supplies the *quantity* and the override, **never a rate** | **CONDITIONAL** — override only (km not invoiced) | **YES** — route is the only rate source | **YES** — route is the only rate source | **NO** — no route concept |
| **vehicle dependent** | **CONDITIONAL** — eligibility + which km-variant matches; **cannot change the rate** | **CONDITIONAL** — same | **CONDITIONAL** — eligibility only (UNIQUE key forbids per-vehicle rates) | **CONDITIONAL** — eligibility only | **CONDITIONAL** — eligibility via `configurare_compresor_vehicule` |
| **capacity dependent** | NO | NO | NO | NO | NO |
| **commodity dependent** | NO | NO | NO | NO | NO |
| **date dependent** | NO | NO | NO | NO | NO |
| **mode/switch dependent** | **YES** — `km_agreati_manual`, `aplica_cost_cursa` | **YES** — `aplica_cost_cursa` | **YES** — `tarif_mod` | **YES** — `aplica_cost_cursa` (mode locked) | **CONDITIONAL** — components 4/5 self-gate on rate > 0 |
| **has fallback chain** | **YES** — `pret_km` → `pret_tarifare` | **YES** — `pret_tona` → `pret_tarifare` | **YES** — 4 tiers (§8) | **YES** — 4 tiers (§8) | **NO** — rates used directly |
| **direction dependent** | NO — bidirectional | NO | NO | NO | N/A |

### Conditional cases explained

- **Distribuție lei/km & lei/tonă** — mutually exclusive in modes `tona`/`km`; both apply in `tona_km`. **[DB]** no production `distributie` route uses `tona_km`.
- **Route dependency for Primar** — the route is matched and supplies `km_tarifare`, but the invoice rate is beneficiary-global; changing route changes the invoiced *amount* via the quantity, never via the rate.
- **Vehicle dependency** — everywhere it selects *which rule applies*; for Distribuție/P+D the UNIQUE key means only one rule can exist per route, so the selection is binary (eligible / not), not a rate choice.
- **Compresor components 4/5** — a rate of 0 both removes the component and hides its input field on the trip form.

---

## 30. Representative Database Calculations

All read-only. 23 trips checked across 5 transport types — **23 matches, 0 mismatches**. **[CODE+DB]**

### 30.1 Primar km

```
Trip #340   Transport: primar   Beneficiary: 33 (ButanGas)
  Rule matched:  configurare_rute_primar (33, loc 56, zonă 64) → km_tarifare = 630
  Rate:          configurare_beneficiari_transport.pret_km = 1.21   [beneficiary level]
  Quantity:      km_cursa = 630   (overwritten from km_tarifare, line 5285)
  Formula:       630 × 1.21
  Expected  =    762.30
  Stored    =    762.30      MATCH ✅   pret_tarifare = 1.21   cost_km_primar = 1.21

Trip #339   1350 × 1.21  → Expected 1633.50 · Stored 1633.50   MATCH ✅
Trip #338    180 × 1.21  → Expected  217.80 · Stored  217.80   MATCH ✅
Trip #353    245 × 1.21  → Expected  296.45 · Stored  296.45   MATCH ✅  (beneficiary 53, Forvest)
Trip #145   1200 × 1.21  → Expected 1452.00 · Stored 1452.00   MATCH ✅  cost_km_mixt = 1.21
```

### 30.2 Primar tone

```
Trip #342   Transport: primar_tona   Beneficiary: 33
  Rate:      pret_tona = 60.00        [beneficiary level]
  Quantity:  cantitate_incarcata = 9.00 t
  Formula:   9.00 × 60.00
  Expected = 540.00
  Stored   = 540.00        MATCH ✅   pret_tarifare = 60.00
  ⚠ km_cursa = 180 stored but NOT invoiced;  cost_km_primar = 1.21 (= pret_km, not 540/180 = 3.00)

Trip #341   6.50 × 60.00 → Expected 390.00 · Stored 390.00   MATCH ✅
            km_cursa = 1100 (6× trip #342) yet a LOWER invoice — km is not billed.
```

### 30.3 Distribuție

```
Trip #352   mode: km    Rule (53, loc 86, zonă 84) cost_extra_km = 1.20
  Formula:  310 km × 1.20        Expected 372.00 · Stored 372.00   MATCH ✅
  pret_tarifare = 1.20  ← lei/km   ·  tonnage 8.00 t ignored

Trip #351   mode: km    260 × 1.20  → Expected 312.00 · Stored 312.00   MATCH ✅
Trip #346   mode: tona  Rule (33, loc 81, zonă 55) tarif_tona = 60.00
  Formula:  8.00 t × 60.00       Expected 480.00 · Stored 480.00   MATCH ✅
  pret_tarifare = 60.00 ← lei/t   ·  km 190 ignored  ·  cost_km_distributie = 480/190 = 2.53 ✅
Trip #345   mode: tona   9.50 × 75.00 → Expected 712.50 · Stored 712.50   MATCH ✅
Trip #343   mode: tona  11.50 × 75.00 → Expected 862.50 · Stored 862.50   MATCH ✅
Trip #344   mode: tona   6.00 × 60.00 → Expected 360.00 · Stored 360.00   MATCH ✅
Trip #187   mode: tona  10.00 × 75.00 → Expected 750.00 · Stored 750.00   MATCH ✅
Trip #151   mode: tona   9.70 × 75.00 → Expected 727.50 · Stored 727.50   MATCH ✅
Trip #149   mode: tona   6.96 × 60.00 → Expected 417.60 · Stored 417.60   MATCH ✅
Trip #150   mode: tona   5.08 × 60.00 → Expected 304.80 · Stored 304.80   MATCH ✅
            km_cursa NULL → cost_km_distributie = 0.00 (guarded division) ✅
```

### 30.4 P+D

```
Trip #348   Transport: primar_distributie   Rule (33, loc 61, zonă 58, scope P+D)
  Rates:     tarif_tona = 75.00 · cost_extra_km = 1.21 · km_tarifare = 630
  Quantities: tone 8.00 · km_cursa 630 · km_totali 760
  8.00 t × 75.00 lei/t   =  600.00
  630 km × 1.21 lei/km   =  762.30
  Expected = 1362.30
  Stored   = 1362.30       MATCH ✅
  pret_tarifare = 75.00 (lei/t)
  cost_km_primar = 1.21  ← the DISTRIBUTION km rate, per line 5612 ✅
  cost_km_distributie = 600 / (760 − 630) = 600/130 = 4.615 → 4.62 ✅
  cost_km_mixt = 0.00   ⚠ code computes 1362.30/760 = 1.79 — see §30.6

Trip #347   10.00 × 60.00 = 600.00 · 1350 × 1.21 = 1633.50
  Expected = 2233.50 · Stored = 2233.50   MATCH ✅
```

### 30.5 Compresor

```
Trip #350   Transport: compresor   Beneficiary: 33
  Component 1: ore_aspirare 8.00 × pret_ora_aspirare 80.00 = 640.00
  Component 3: tona_livrata 5.00 × pret_tona_livrata 50.00 = 250.00
  Components 2, 4, 5: rates = 0.00 → contribute 0
  Expected = 890.00
  Stored   = 890.00        MATCH ✅
  pret_tarifare = 80.00 ← lei/oră (first non-zero in the cascade) ✅
  cost_km_compresor = 0.00 (km_dislocare = 0, guarded) ✅

Trip #349   6.00 × 80.00 + 4.00 × 50.00 = 480 + 200
  Expected = 680.00 · Stored = 680.00   MATCH ✅
```

### 30.6 A data-provenance caveat on `cost_km_mixt`

> **Finding:** 17 of 24 priced trips store `cost_km_mixt = 0.00` where the code would compute a non-zero value. This is **not a formula defect** — those rows never passed through the pricing engine.
>
> **Evidence [DB]:**
>
> | `created_at` | rows | `created_at == updated_at` | `cost_km_mixt = 0` |
> |---|---|---|---|
> | 2026-08-10 15:56:59 | **34** | 34 | 34 |
> | 2026-08-17 11:00:00 | **16** | 16 | 16 |
> | individual timestamps | 1 each | 0 | 0 |
>
> Two batches of 34 and 16 rows share an identical second-precision timestamp with `created_at == updated_at` — the signature of a direct SQL seed. Trip #145 (`created_at 2026-07-07 14:27:42`, `updated_at 2026-08-05 15:26:16`, `created_by = 5`) went through the application and **does** store `cost_km_mixt = 1.21`, exactly as line 5658 prescribes.
>
> **Consequence:** `total_facturare` in the seeded rows is arithmetically correct (it matches every formula above), but the derived KPI columns were not populated. Any verification of `cost_km_*` against this dataset must exclude the seeded batches.

---

## 31. Edge Cases

| Case | Actual behaviour | Evidence |
|---|---|---|
| **Zero rate** — all Primar rates 0 | soft error *"Beneficiarul selectat nu are tarife valide pentru transport primar."*; **non-blocking** — trip saves with `total_facturare = 0` | :5437-5438 **[CODE]**; **[DB]** Vixon & Mol Romania have every rate 0.00 |
| **Zero rate** — all Compresor rates 0 | soft error; saves at 0 | :5490-5497 |
| **Missing route** (Primar) | soft error *"Combinatia selectata Loc ↔ Zona nu este configurata in Setari Primar"*; `km_cursa` keeps the operator value; **the rate still applies** (beneficiary-level) | :5271-5274 |
| **Missing route** (Distribuție) | `tarif_mod` defaults to `tona_km`, rates fall through to the dormant/hidden chain → all 0 → soft error *"Nu exista un tarif valid pentru distributie"* | :5474-5475 |
| **Fallback rate** actually used | **never in production** — all dormant sources are 0.00 in 19/19 rows and 4/4 beneficiaries, except `pret_distributie_km = 1.50` (ButanGas) which cannot fire (§8.3) | **[DB]** |
| **Fixed trip price** active | **no live example** — `aplica_cost_cursa = 1` count is 0 in both route tables | **[DB]** |
| **Fixed price configured but switched off** | value stored and ignored — rule 15 (Vixon): `cost_cursa = 4000.00`, `aplica_cost_cursa = 0` | **[DB]** |
| **Manual agreed km** | 1 route (id 12, Forvest, `km_agreati_manual = 1`, `km_tarifare = 0`); the operator's `km_cursa` is used; km validation skipped at save (controller :3268) | **[CODE+DB]** |
| **Vehicle-specific route rule** | 1 primary rule restricted to a vehicle set (id 8 → `27,57,59`); all 13 distribution rules are vehicle-restricted | **[DB]** |
| **Same loading/unloading location** | flips the tonnage fallback order to `loc.tarif` → `zona.tarif_distributie` (`isSameDistributionRoute()` :7643). **[DB]** trip #345 uses loc 55 / zonă 55, but a route rule matched (`tarif_tona = 75`), so the fallback never engaged | **[CODE+DB]** |
| **Different loading/unloading location** | standard order `zona.tarif_distributie` → `loc.tarif` | :7616-7640 |
| **`km_cursa` NULL** | division guarded → `cost_km_distributie = 0.00`; trip #150 confirms | **[CODE+DB]** |
| **Trip with no beneficiary** | `$price` block is skipped entirely (`if ($beneficiary !== null …)` :5432) → `pret_tarifare = 0`, `total_facturare = 0`; soft error only | **[CODE]**; **[DB]** 0 such trips exist |
| **Inactive route rule** (`activ = 0`) | excluded from every lookup (`onlyActive = true` in all resolvers) | model :1210, :1550 |

---

## 32. Ambiguous / Legacy Fields

### 32.1 `pret_tarifare` — the most ambiguous field in the system

| Aspect | Detail |
|---|---|
| **Field** | `curse_dispecer.pret_tarifare` **and** `configurare_beneficiari_transport.pret_tarifare` — **two different things sharing one name** |
| **Possible meanings (trip column)** | lei/km · lei/tonă · lei/oră · lei/cursă |
| **Possible meanings (config column)** | a generic fallback rate for both `pret_km` and `pret_tona` — i.e. **simultaneously lei/km and lei/tonă** |
| **Transport types affected** | **all five** |
| **Risk for future UI** | Presenting it as one column implies one unit. **[DB]** live `distributie` rows already hold 1.20 (lei/km) and 60.00 (lei/t) in the same column for the same transport type. Any UI grouping, sorting, charting or comparison across it is meaningless without also reading `tip_transport` **and** the route's `tarif_mod`. `CentralizatorFacturareService::buildDistributionSection()` :287 already groups by it and therefore already mixes units. |

### 32.2 `km_tarifare` — a quantity named like a price

| Aspect | Detail |
|---|---|
| **Field** | `configurare_rute_primar.km_tarifare`, `configurare_rute_distributie.km_tarifare` |
| **Actual semantics** | **CONFIGURED QUANTITY** — the agreed billable kilometres for a route |
| **Type** | `INT UNSIGNED` — no decimals |
| **Label in UI** | "Km tarifare" (tab 5), "Km agreati" (tab 4) — inconsistent between tabs |
| **Risk for future UI** | The Romanian "tarifare" reads as *tariff/pricing*. It sits in the pricing configuration screen and is the **only numeric field on the Primar tab**, which makes that tab look like a rate screen when it contains no rate at all. |

### 32.3 `cost_km_primar` / `cost_km_distributie` / `cost_km_mixt` / `cost_km_compresor`

| Aspect | Detail |
|---|---|
| **Actual semantics** | **CALCULATED KPI** written into the trip at save |
| **Never an input** | absent from every rate lookup; recomputed from scratch on each repricing |
| **Provably decoupled** | Primar tone trip #342: invoice 540.00 (tonnage), `cost_km_primar` 1.21 (= `pret_km`), `540/180 = 3.00` **[DB]** |
| **Cross-type inconsistency** | for P+D, `cost_km_primar` uses `cost_extra_km` — the *distribution* km rate (line 5612) — not `pret_km` |
| **Risk for future UI** | Name and unit (lei/km) are indistinguishable from a configured rate. Exposing them as editable would let a user "set" a value that the engine overwrites on the next save. |

### 32.4 Complete legacy / dead inventory

| Field or object | Classification | Evidence |
|---|---|---|
| `configurare_beneficiari_transport.tip_marfa` | **LEGACY FIELD** — wiped to `''` on every save | controller :4348, :4374 |
| `configurare_beneficiari_transport.pret_tarifare` | **HIDDEN fallback rate** | config.php:568 hidden input |
| `pret_distributie_tona` / `pret_distributie_km` | **HIDDEN fallback rates** — one non-zero | config.php:569-570; **[DB]** |
| `configurare_locuri_incarcare.tarif` | **DORMANT** — read by engine, no UI writes it | catalog form posts only `loc_nume` |
| `configurare_zone_distributie.tarif_distributie` / `.cost_extra_km` | **DORMANT** — same | same |
| `configurare_rute_distributie.cost_cursa` for scope `distributie` | **UNREACHABLE** — forced to 0 at save | controller :3010-3012 |
| `'mixt'` transport type | **DEAD** | :5651; not in ENUM |
| `config_store_loc` / `_zona` / `config_delete_loc` / `_zona` | **ORPHAN endpoints** — no form posts to them; `config_delete_loc` detaches trips (`SET loc_incarcare_id = NULL`) then cascades away route rules | controller :3842, :3956, :3996, :4117 |
| `beneficiar_view_id` ("Detalii") | **DEAD** — controller loads `$viewBeneficiary` (:2469), view never renders it | config.php:482 |

---

## 33. Risks for Representing Pricing in UI

| # | Risk | Severity | Why |
|---|---|---|---|
| 1 | Treating `pret_tarifare` as one comparable rate | **CRITICAL** | Four different units across types, two within Distribuție; already proven in live data |
| 2 | Presenting `cost_km_*` as editable rates | **CRITICAL** | They are outputs; #342 proves `cost_km_primar` is not even the invoiced rate |
| 3 | Showing "Rute Primar" as a rate screen | **HIGH** | It contains **no rate** — only `km_tarifare` (quantity) and `cost_cursa` (override) |
| 4 | Implying Primar rates are per-route | **HIGH** | They are beneficiary-global; editing one "route price" would silently move every route of that client |
| 5 | Implying a per-vehicle rate is possible for Distribuție/P+D | **HIGH** | The UNIQUE key forbids it; only eligibility is per-vehicle |
| 6 | Hiding the 4-tier fallback chain | **HIGH** | `pret_distributie_km = 1.50` (ButanGas) is live, invisible and one `tarif_mod` change away from applying |
| 7 | Presenting `km_tarifare` as a price | **HIGH** | Name and placement both suggest it |
| 8 | Implying date-based tariff versions exist | **HIGH** | They are structurally impossible today (§22) |
| 9 | Implying commodity or capacity pricing exists | **MEDIUM** | Both are absent from every lookup; commodity was deliberately removed |
| 10 | Implying `A→B` and `B→A` can be priced differently | **MEDIUM** | Resolution is bidirectional in four places, and the catalog auto-mirrors names |
| 11 | Presenting Compresor as one tariff | **MEDIUM** | Five independent additive components; two live, three at zero |
| 12 | Showing `cost_cursa` as a minimum or surcharge | **MEDIUM** | It is a **full replacement** |
| 13 | Not distinguishing `distributie` from `primar_distributie` scope | **MEDIUM** | Same table, same route pair, different rows, different rates, different mode rules |
| 14 | Treating the `suporta_*` flags as four independent switches | **LOW** | `primar` and `primar_tona` share one flag |

---

## 34. Confirmed Facts vs Unknowns

### CONFIRMED FROM CODE + DATABASE

- Five transport types; `primar` and `primar_tona` share `suporta_primar`
- All five invoice formulas (§4–§11), verified on 23 trips, 23 matches
- `configurare_rute_primar` contains no price column
- `pret_tarifare` carries four different units across types and two within Distribuție
- `cost_km_*` are derived KPIs, decoupled from the invoice for `primar_tona`
- `UNIQUE (beneficiar, loc, zonă, scope)` on `configurare_rute_distributie`; no such key on `configurare_rute_primar`
- Capacity, commodity and date have zero pricing effect
- Route direction is bidirectional
- Fallback chain has 4 tiers; tiers 2–4 are dormant/hidden; `pret_distributie_km = 1.50` is live but inert
- `cost_cursa` is a full replacement; 0 routes have it active
- 13 repricing triggers; `facturat` blocks repricing
- No mechanism recalculates existing trips after a config change
- 50 of 74 trips are seeded rows that bypassed the engine

### CONFIRMED FROM CODE ONLY (not observable in current data)

- Primar can hold several rules per pair with disjoint vehicle sets (0 such pairs exist)
- `distributie` scope supports `tona_km` mode (0 routes use it)
- The `primar_tona` fallback inconsistency of §5.4 (no beneficiary configuration triggers it)
- Distribution vs Primar vehicle-fallback asymmetry (all 13 distribution rules are vehicle-scoped, so no unrestricted rule exists to be dropped)
- `cost_cursa` behaviour (no live example)

### UNKNOWN

- **Why `pret_distributie_km = 1.50` is set for ButanGas** when no UI can set it and no route can apply it. Historical import, manual SQL, or a leftover from an earlier UI — cannot be determined from code, schema or data.
- **Whether the seeded batches were intended as production data** or as test fixtures. Their `total_facturare` is arithmetically correct, but `created_by = 1` and the identical timestamps give no further provenance.
- **Whether `pret_tarifare` (config) is an intentional live fallback or a retired field.** It is 0.00 for all 4 beneficiaries, hidden in the UI, yet still first in two fallback chains.

---

## 35. Information Required by the Future Visual Reference

*Inventory only — no UI is proposed, per the brief.*

For each transport type the reference must be able to represent, accurately and without conflation:

- the **level** at which each rate is configured (beneficiary vs route) — because it differs per type;
- the **unit** of each rate — because four units are in play and one column carries all of them;
- which values are **rates**, which are **quantities**, which are **switches**, which are **outputs**;
- the **rule dimension tuple** — and the fact that vehicle is a dimension of *eligibility*, not of price, for three of the five types;
- the **fallback chain** and the status of each tier;
- the **override** and its full-replacement semantics;
- the fact that **no date dimension exists**.

---

# DATA THE FUTURE VISUAL REFERENCE MUST REPRESENT

*Only confirmed values.*

```
PRIMAR KM
Commercial values:
- pret_km                       [RATE, lei/km, beneficiary]
- pret_tarifare                 [RATE fallback, ambiguous unit, beneficiary, HIDDEN]
- cost_cursa                    [FIXED PRICE, lei/cursă, route]
Rule dimensions:
- beneficiary
- loading location + unloading zone   (bidirectional — A→B ≡ B→A)
- vehicle set                          (eligibility + km-variant selection; NOT a rate dimension)
Operational values affecting calculation:
- km_cursa                      [km — supplied by route config, not the operator,
                                 unless km_agreati_manual = 1]
Configured quantity:
- km_tarifare                   [QUANTITY, km, route]
Switches:
- km_agreati_manual, aplica_cost_cursa, activ, suporta_primar
Overrides:
- cost_cursa + aplica_cost_cursa  → full replacement of km × pret_km
Fallbacks:
- pret_km → pret_tarifare
Number of independently configurable commercial values: 3
  (pret_km · pret_tarifare(hidden) · cost_cursa)

PRIMAR TONE
Commercial values:
- pret_tona                     [RATE, lei/tonă, beneficiary]
- pret_tarifare                 [RATE fallback, beneficiary, HIDDEN]
- cost_cursa                    [FIXED PRICE, lei/cursă, route]
Rule dimensions:
- beneficiary
- loading location + unloading zone  (matched, but supplies NO rate)
- vehicle set                         (eligibility only)
Operational values affecting calculation:
- cantitate_incarcata           [tone — operator]
Operational values NOT affecting the invoice:
- km_cursa                      [km — stored, feeds cost_km_primar only]
Overrides:
- cost_cursa + aplica_cost_cursa  → full replacement
Fallbacks:
- pret_tona → pret_tarifare      (invoice path)
- pret_tona → pret_km            (pret_tarifare snapshot path — INCONSISTENT, §5.4)
Number of independently configurable commercial values: 3

DISTRIBUȚIE
Commercial values:
- tarif_tona                    [RATE, lei/tonă, route]
- cost_extra_km                 [RATE, lei/km, route]
- tarif (loc)                   [RATE fallback, lei/tonă, loading location, DORMANT]
- tarif_distributie (zonă)      [RATE fallback, lei/tonă, unloading zone, DORMANT]
- cost_extra_km (zonă)          [RATE fallback, lei/km, unloading zone, DORMANT]
- pret_distributie_tona         [RATE fallback, lei/tonă, beneficiary, HIDDEN]
- pret_distributie_km           [RATE fallback, lei/km, beneficiary, HIDDEN — 1.50 live]
Rule dimensions:
- beneficiary
- loading location
- unloading zone
- transport_scope = 'distributie'
  → UNIQUE: exactly ONE rule per tuple
- vehicle set                    (eligibility only — cannot carry a second rate)
Operational values affecting calculation:
- cantitate_incarcata           [tone — modes tona, tona_km]
- km_cursa                      [km   — modes km, tona_km]
Mode:
- tarif_mod ∈ {tona, km, tona_km}   — selects which rate(s) apply
Overrides:
- none reachable (cost_cursa forced to 0 for this scope at save)
Fallbacks:
- tarif_tona:     route → [zonă|loc, order flips if names match] → beneficiary
- cost_extra_km:  route → zonă → beneficiary
Number of independently configurable commercial values: 2 reachable (+5 dormant/hidden)

P+D  (primar_distributie)
Commercial values:
- tarif_tona                    [RATE, lei/tonă, route]
- cost_extra_km                 [RATE, lei/km, route]
- cost_cursa                    [FIXED PRICE, lei/cursă, route]
  (+ the same 5 dormant/hidden fallbacks as Distribuție)
Rule dimensions:
- beneficiary
- loading location
- unloading zone
- transport_scope = 'primar_distributie'
  → UNIQUE: exactly ONE rule per tuple
- vehicle set                    (eligibility only)
Operational values affecting calculation:
- cantitate_incarcata           [tone]
- km_cursa                      [km]
Operational values NOT affecting the invoice:
- km_totali                     [km — feeds cost_km_distributie and cost_km_mixt only]
Configured quantity:
- km_tarifare                   [QUANTITY, km, route — pre-fills km_cursa when empty]
Mode:
- forced to tona_km, not selectable
Overrides:
- cost_cursa + aplica_cost_cursa  → full replacement of both components
Fallbacks:
- same 4-tier chains as Distribuție
Number of independently configurable commercial values: 3 reachable (+5 dormant/hidden)

COMPRESOR
Commercial values (five independent, additive):
- pret_ora_aspirare             [RATE, lei/oră,  beneficiary]
- pret_km_dislocare             [RATE, lei/km,   beneficiary]
- pret_tona_livrata             [RATE, lei/tonă, beneficiary]
- pret_tona_aspirata_lichida    [RATE, lei/tonă, beneficiary — also gates field visibility]
- pret_tona_aspirata_gazoasa    [RATE, lei/tonă, beneficiary — also gates field visibility]
Rule dimensions:
- beneficiary
- vehicle set via configurare_compresor_vehicule   (eligibility only)
- NO route · NO loading location · NO unloading zone
Operational values affecting calculation:
- ore_aspirare · km_dislocare · tona_livrata
- tona_aspirata_lichida · tona_aspirata_gazoasa
Overrides:
- NONE — cost_cursa does not exist for Compresor
Fallbacks:
- NONE — rates are used directly
Number of independently configurable commercial values: 5
```

---

# VALUES THAT CAN SUFFER COMMERCIAL CHANGES

Every confirmed mutable commercial field. **No recommendation is made about whether or why any of them should change.**

| Field | Commercial rate? | Editable today? | Changes invoice calculation? | Unit | Scope |
|---|---|---|---|---|---|
| `pret_km` | **YES** | **YES** — config tab 1 | **YES** — Primar km | lei/km | per beneficiary |
| `pret_tona` | **YES** | **YES** — config tab 1 | **YES** — Primar tone | lei/tonă | per beneficiary |
| `pret_ora_aspirare` | **YES** | **YES** — config tab 1 | **YES** — Compresor | lei/oră | per beneficiary |
| `pret_km_dislocare` | **YES** | **YES** — config tab 1 | **YES** — Compresor | lei/km | per beneficiary |
| `pret_tona_livrata` | **YES** | **YES** — config tab 1 | **YES** — Compresor | lei/tonă | per beneficiary |
| `pret_tona_aspirata_lichida` | **YES** | **YES** — config tab 1 | **YES** — Compresor | lei/tonă | per beneficiary |
| `pret_tona_aspirata_gazoasa` | **YES** | **YES** — config tab 1 | **YES** — Compresor | lei/tonă | per beneficiary |
| `tarif_tona` | **YES** | **YES** — config tabs 3 & 4 | **YES** — Distribuție, P+D | lei/tonă | beneficiary + loc + zonă + scope |
| `cost_extra_km` (route) | **YES** | **YES** — config tabs 3 & 4 | **YES** — Distribuție, P+D | lei/km | beneficiary + loc + zonă + scope |
| `cost_cursa` (primar) | **YES** — fixed price | **YES** — config tab 5 | **YES** when `aplica_cost_cursa = 1` | lei/cursă | beneficiary + loc + zonă [+ vehicle set] |
| `cost_cursa` (distribuție/P+D) | **YES** — fixed price | **YES** — config tab 4 | **YES** when `aplica_cost_cursa = 1` (P+D only) | lei/cursă | beneficiary + loc + zonă + scope |
| `pret_tarifare` (config) | **YES** — fallback | **NO** — hidden pass-through input | **YES** — when `pret_km`/`pret_tona` = 0 | ambiguous | per beneficiary |
| `pret_distributie_tona` | **YES** — fallback | **NO** — hidden pass-through input | **YES** — when route + zonă + loc all 0 | lei/tonă | per beneficiary |
| `pret_distributie_km` | **YES** — fallback | **NO** — hidden pass-through input | **YES** — when route + zonă = 0 and mode uses km | lei/km | per beneficiary |
| `configurare_locuri_incarcare.tarif` | **YES** — fallback | **NO** — no form posts it | **YES** — when route rate = 0 | lei/tonă | beneficiary + loading location |
| `configurare_zone_distributie.tarif_distributie` | **YES** — fallback | **NO** — no form posts it | **YES** — when route rate = 0 | lei/tonă | beneficiary + unloading zone |
| `configurare_zone_distributie.cost_extra_km` | **YES** — fallback | **NO** — no form posts it | **YES** — when route km rate = 0 | lei/km | beneficiary + unloading zone |

### Configured values that change the invoice but are NOT rates

| Field | Commercial rate? | Editable today? | Changes invoice calculation? | Unit | Scope |
|---|---|---|---|---|---|
| `km_tarifare` (primar) | **NO** — configured quantity | **YES** — config tab 5 | **YES** — supplies the invoiced km for Primar km | km | beneficiary + loc + zonă [+ vehicle set] |
| `km_tarifare` (P+D) | **NO** — configured quantity | **YES** — config tab 4 | **INDIRECT** — pre-fills `km_cursa` when empty | km | beneficiary + loc + zonă + scope |
| `tarif_mod` | **NO** — mode switch | **YES** — config tab 3 | **YES** — selects which rate(s) apply | enum | beneficiary + loc + zonă + scope |
| `aplica_cost_cursa` | **NO** — switch | **YES** — config tabs 4 & 5 | **YES** — activates full replacement | bool | route |
| `km_agreati_manual` | **NO** — switch | **YES** — config tab 5 | **YES** — moves the km source from config to operator | bool | route |
| `vehicle_ids` | **NO** — dimension | **YES** — config tabs 3, 4, 5 | **YES** — changes which rule matches | CSV | route |
| `activ` | **NO** — switch | **YES** — config tabs 1 & 5 | **YES** — an inactive rule is excluded from every lookup | bool | beneficiary / route |
| `suporta_primar` / `_distributie` / `_primar_distributie` / `_compresor` | **NO** — eligibility gate | **YES** — config tab 1 | **YES** — a disabled flag forces the rate to 0.0 | bool | per beneficiary |

### Values that must NOT be presented as editable commercial rates

| Field | Actual nature |
|---|---|
| `curse_dispecer.pret_tarifare` | **OUTPUT / SNAPSHOT** — written by the engine |
| `curse_dispecer.total_facturare` | **OUTPUT** |
| `curse_dispecer.cost_km_primar` | **CALCULATED KPI** |
| `curse_dispecer.cost_km_distributie` | **CALCULATED KPI** |
| `curse_dispecer.cost_km_mixt` | **CALCULATED KPI** |
| `curse_dispecer.cost_km_compresor` | **CALCULATED KPI** |
| `configurare_beneficiari_transport.tip_marfa` | **LEGACY** — wiped on every save |

---

*End of report. No application code, database object, migration or configuration value was created or modified. All database access was read-only.*
