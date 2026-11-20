# IMPLEMENTATION — Rentabilitate Operațională

- **Date:** 2026-11-18
- **Page:** `?page=rentabilitate` (sidebar: "Rentabilitate Operațională")
- **Scope:** read-only analysis page, plus a backend read/calculation layer.
- **Not done:** nothing committed or deployed; no production data touched; no new transaction tables.

**The question the page answers:** what a truck or ensemble really costs with its driver, how much revenue its activity produces, for which customers, and where money is won or lost.

It answers it through:
- a compact table (vehicles or drivers);
- an expandable row with a summary strip, tabs, a category list and a transaction grid.

---

## 1. Data sources (Phase 1 audit)

| Need | Existing source reused | Status |
|---|---|---|
| Vehicle operating costs (≈30 elements) | `OperationalCostService` / `OperationalCostModel`, the engine behind the Cost operațional / km page. Covers fuel and AdBlue (CardOil, net of VAT), repairs and maintenance (`mentenanta`, capitalised investments already excluded), tyres, documents and taxes (`configurare_costuri_documente_*`), authorisations, equipment, overheads, manual configuration. | Reused without duplicating it. A small opt-in historical mode was added (§3.1). |
| Activity, km, revenue | `curse_dispecer` + `curse_segmente`. Resumed trips are split by segment km, exactly as the engine does. | Reused (same leg logic). |
| Revenue | `curse_dispecer.total_facturare`: the tariff snapshot from the transport tariffing engine (Primar km / tone, Distribuție, P+D, Compresor). | All 1,173 local trips are `in_curs_facturare`, so revenue is shown as **tariff revenue (not yet invoiced or collected)**. |
| Driver cost | `personal_luna` (Contabilitate Personal), finalised → used when present. Otherwise `soferi.salariu` / `salary_history` × employer multiplier, labelled **"estimat"**. Plus driver documents, per-diem from `curse_cheltuieli`, and manual per-driver elements. | `personal_luna` is empty locally, so every driver cost is currently **estimated**. Drivers with no salary are marked **"Cost șofer lipsă"**; nothing is invented. |
| Tractor–trailer | `vehicule_cuplaje` (`data_start` / `data_end`). | Read **as of each trip's date**. |
| Investments, acquisition | `VehicleFinancialRegisterService` / `InvestmentProjectService` (Istoric Financiar Vehicul). | Reused. |
| Documents | `documente` + `configurare_costuri_documente_vehicule.obligatoriu`. | Reused. |
| Driver eligibility | `soferi.status`, `soferi_vehicule`, `soferi.vehicle_id`, expired `documente_soferi`. | Reused (verified records only). |

**Never read as cost:** `supplier_invoices` and `invoice_vehicle_repairs` (a derived mirror). The repair invoice *number* is read from that mirror for display only. `ocr_piese_registru` (the inactive element) is not read either.

---

## 2. Files

**New**

| File | Role |
|---|---|
| `htdocs/services/OperationalProfitabilityService.php` | Calculation engine: months → engine in historical mode → trip legs → dated trailer allocation → driver allocation. Includes the cache and the data fingerprint. |
| `htdocs/services/ProfitabilityRegisterService.php` | Page read model: vehicle and driver rows, filters, search, sorting, pagination, totals without duplicates. Plus the tabs (cost lines, trips, beneficiaries, investments, documents, simulation, driver summary) and CSV export. |
| `htdocs/controllers/ProfitabilityController.php` | `index`, `list`, `vehicle`, `costs`, `trips`, `beneficiaries`, `investments`, `documents`, `drivers`, `driver`, `export`. All GET and read-only. |
| `htdocs/views/rentabilitate/index.php` | Page view. |
| `htdocs/assets/css/rentabilitate.css` | Page styles. |
| `htdocs/assets/js/rentabilitate.js` | Page script. |
| `htdocs/permissions/modules/rentabilitate.php` | `view` (sensitive) and `export` (admin by default). The registry was synced. |
| `scripts/test_rentabilitate.php` | Reconciliation and calculation tests (50). |

**Modified**

| File | Change |
|---|---|
| `htdocs/services/OperationalCostService.php` | Added `computeHistoricalMonth()`: the historical mode, off by default. |
| `htdocs/models/OperationalCostModel.php` | `getActiveDriversWithSalary()` takes an optional extra list of drivers who had trips in the period, including drivers who are inactive today. |
| `htdocs/index.php` | Route. |
| `htdocs/views/layout/header.php` | One sidebar entry. |

**Database changes:** none. Cache files go to `storage/cache/rentabilitate/` (git-ignored).

---

## 3. Calculations

### 3.1 Historical mode of the cost engine

The Cost operațional / km page has two limits for profitability analysis. It attaches every trailer to the tractor it is coupled to **today**, and it puts each driver's salary on the vehicle in his record.

`computeHistoricalMonth()` reuses exactly the same element resolution, with two differences:
- no trailer merging: each physical vehicle keeps its own costs;
- personnel elements (salary, driver documents, per-diem with a known driver, manual per-driver elements) go to a **driver ledger**, not to a vehicle.

The normal `compute()` path is unchanged. The `test_cost_operational_km` regression passes 42/42, and a dedicated test confirms the classic page still attaches the trailer to the active tractor.

### 3.2 Month by month

For each month (or part of a month) in the range:

1. **Activity.** One "leg" per trip (or segment), with km actually driven (`km_totali`, else `km_cursa`), billable km (`km_cursa`, else `km_totali`), dislocation km, tariff revenue, tonnes, beneficiary and type. Prorated by segment km.
2. **Vehicle costs** come from the historical engine, per physical vehicle.
3. **Trailer, using dated couplings.** For each leg, the trailer is the one whose coupling was valid at the trip's start time (`data_start <= time < data_end`).
   - The trailer's costs for the month go to the tractors that used it, **in proportion to the km driven with it**.
   - If it drove no km, the split is by coupling time.
   - Any uncoupled share stays on the trailer. Allocated + unallocated = the trailer's cost, which is tested.
   - Trailer tyres: rate × km driven with the trailer (the engine multiplies by the trailer's own km, which are 0).
4. **Driver.** The month's cost (finalised payroll, else estimated salary, plus documents, per-diem and other personnel costs) is split across the vehicles he drove, **in proportion to his actual km**.
   - A driver shared between vehicles is therefore allocated once.
   - A month with no km stays as "cost neocupat" (unused cost), visible in Șoferi mode.
5. **Exact values internally.** Rounding happens only on display (`Intl.NumberFormat`).

### 3.3 Formulas

| Indicator | Formula |
|---|---|
| Cost vehicul/km | (own vehicle costs + allocated trailer shares, excluding personnel) ÷ km driven by the vehicle |
| Cost șofer/km | personnel costs allocated to the vehicle ÷ the same km |
| Cost total/km | (vehicle cost + driver cost) ÷ km. The components add up because they share the same km basis. |
| Venit/km | Σ trip tariffs ÷ km actually driven (non-billable and dislocation km included in the denominator). Billable km are shown separately in the trip list. |
| Rezultat/km | (revenue − total cost) ÷ km |
| Acoperire | revenue ÷ total cost × 100 |
| Beneficiary | km and revenue of the beneficiary's trips. Cost = vehicle cost/km × beneficiary km. Reconciles to the vehicle's cost. |
| Transport type / beneficiary filter | Km and revenue from matching trips only. Cost allocated in proportion to those km. |
| Driver contribution | driver revenue − (vehicle cost/km × his km on each vehicle) − his personnel cost |
| Trip result | tariff − (period total cost/km of the vehicle × trip km) |

**Totals without duplicates:**
- tractor and truck rows are summed;
- for trailers (which also appear individually), only the unallocated share is added.

**Separate concepts:**
- "Rezultat" here is an **operating contribution**, not accounting profit or net profit.
- Investment recovery and depreciation are not calculated (§5).

### 3.4 Data quality, shown instead of hidden

The yellow banner and the ⚠ markers in the rows show "Cost parțial / Date incomplete". Triggers:
- **Configuration elements with no value or source**, for example tax, conformity copy, risk insurance, depreciation. They are not treated as 0.
  - Event-based elements (repairs, fuel, per-diem, tolls) are excluded: for those, "no records in a month" means nothing happened, not missing data.
- **Driver cost** that is estimated (no finalised payroll) or missing (no salary).
- **"Costuri fără curse înregistrate în <lună>"** (costs without recorded trips) when a vehicle has fuel in a month but no trips.
  - Example in the local data: June 2026 has 415k lei of fuel but only 3 trips, because trips start on 29 June.
  - That is why the default range is the **last 3 months**.
- **Revenue is the trip tariff**, not collected money (banner note).

---

## 4. UI (from the approved mockup)

**Toolbar**
- Vehicule / Șoferi selector.
- Live search, debounced 300 ms, server-side. Matches: plate, model, driver, beneficiary, transport type, trip id (`#1234` or `1234`), trailers used.
- Tip transport, Beneficiar and Status filters (Profitabil / Pe pierdere / Date incomplete / Fără activitate).
- Date range with presets and custom dates.
- Exportă (CSV with the current filters).
- "+ Adaugă": links to the existing workflows (Dispecer curse, Cheltuieli, Istoric Financiar, cost element configuration). Nothing is recreated here.

**Table**
- Columns as in the mockup.
- Status dot: green = active in the period, gray = no trips, red = inactive.
- Coloured transport pills, a result pill (red / green), coverage coloured around 100%.
- Server-side sorting on the column headers and server-side pagination (10/25/50/100).
- Row checkboxes and a floating action menu that is never clipped.

**Expanded row** (directly under the row, animated)
- **Summary strip:** vehicle + trailers used, driver + Activ badge, cost vehicul / șofer / total / venit / rezultat / acoperire.
- **Tabs:**
  - **Costuri:** 7 categories on the left; the grid on the right shows Data, Descriere, Furnizor/sursă, Pe (vehicle / trailer with its share / driver), Cost, Km la intervenție, Cost/km, Document, ⋯. Rows expand to show the original source (`mentenanta #id`, `fuel_fillups #id`, payroll / configuration, share and allocation basis). Document links open the existing modules.
  - **Activitate:** paginated trips with the historical ensemble (e.g. "B 677 NET + B 679 NET"); clicking a trip opens it in Dispecer curse.
  - **Beneficiari:** expandable to that beneficiary's trips.
  - **Investiții:** acquisition dossier, capitalised value, projects, capitalised repairs, depreciation status, link to Istoric Financiar.
  - **Documente:** vehicle + trailers used, validity, missing mandatory types.
  - **Șoferi / Simulare:** historical vs simulated cost per driver, labelled as such. Selecting a driver previews the effect; revenue/km is editable. **Nothing is assigned.** The real assignment change goes through the Șoferi page.
- **Șoferi mode** (same visual system):
  - Columns: Șofer, Vehicule, Tip, Km, cost vehicul/șofer/total, Venit/km, Contribuție/km, Acoperire.
  - Expanded: Costuri (personnel components + allocation to vehicles), Curse, Vehicule, Beneficiari, Istoric activitate (monthly, with the source of the cost).

**Motion:** expand/collapse, the tab morph, the category grid and the Vehicule/Șoferi switch are animated with restraint, and `prefers-reduced-motion` is respected.

---

## 5. Known missing financial inputs

1. **Depreciation.** The `amortizare` element has no value, and accounting has not approved a useful life or method per asset. Capitalised acquisitions and investments are shown in the Investiții tab but **do not enter cost/km**.
2. **Verified driver payroll.** `personal_luna` has no rows, so all driver costs are estimated from the configured salary × multiplier (1.75). 4 of 30 active drivers have no salary.
3. **Collected revenue.** Trips are not yet invoiced; revenue is the tariff.
4. **Configuration costs with no value:** vehicle tax, conformity copy, risk insurance, approval, toll sticker, CASCO (some), SSM, courses, GPS, phone, and others. All are listed in the banner.
5. **Tyres:** purchase prices are missing on mounted tyres, so the tyre cost is mostly unknown (not 0).
6. **Trips before 29 June 2026** are not recorded, while fuel exists. Ranges that include June are flagged.

---

## 6. Allocation assumptions (documented)

- **Trailer → tractor:** by km driven with the trailer in that month; without km, by coupling time.
- **Driver → vehicle:** by the driver's actual km in that month.
- **Beneficiary / transport type:** cost by km (vehicle cost/km × km).
- **Resumed trip on segments:** km, revenue and driver follow segment km, as in Cost / km.
- **Overheads** (the `management_office` element): allocated by the existing engine's configuration (equally across active tractors and trucks, or by km). Shown under "Alte costuri".

---

## 7. Tests

**`scripts/test_rentabilitate.php`: 50/50**

It runs on `fleet_fin_test`, in a controlled scenario in a month with no other data:
- tractors T1 and T2 share trailer S across a coupling change on the 16th;
- driver D1 has a salary and drives both tractors; D2 has no salary;
- two beneficiaries and three trips (primar / distribuție);
- repairs on S and T1, a 20,000 lei capitalised investment on T1, and one CardOil fill-up.

| Required area | What is checked |
|---|---|
| Cost reconciliation | Each category's grid lines sum to the category total, for T1, T2 and S |
| Vehicle + driver | cost total/km = (vehicle + driver) / km exactly; the per-km components add up |
| Tractor + trailer + driver | S split 50/50 by km; allocated + unallocated = cost of S; not all on the current tractor |
| Historical associations | Coupling valid on the trip date; the trip's driver, not today's assignment |
| Beneficiaries | km, revenue, cost reconciling to the vehicle; driver's beneficiaries |
| Billing types | T2 is "mixt"; the type filter restricts km, revenue and cost |
| Capitalised investment | Excluded from repairs but still recorded in the ledger |
| Incomplete data | Missing and estimated driver cost flagged |
| Simulation | Historical separate from simulated; driver assignments unchanged (checksum) |
| Sources | `mentenanta #id`, `fuel_fillups #id`, trailer share |
| Search, pagination, totals | By plate, trip id, driver; server pagination; totals without trailer duplication |
| Ranges and cache | Range across two months; per-month cache; invalidation after a data change |
| Regression | Cost / km page still attaches the trailer to the active tractor |

**Browser test (Playwright, 1672 × 940): 33/33**
- Page load and live search (plate, beneficiary, type).
- Filters (type, status, beneficiary), range presets, pagination, sorting.
- Expand directly under the row; categories and grid; source details.
- Floating menu not clipped.
- All six vehicle tabs, beneficiary expand, simulation preview and editable revenue.
- Collapse, CSV export, Vehicule → Șoferi switch and the driver tabs.
- Responsive at 820 px (no page overflow).
- No JavaScript errors.

**Regressions on `fleet_fin_test`**

| Suite | Result |
|---|---|
| `test_cost_operational_km` | 42/42 |
| `test_fundatie_financiara` | 130/130 |
| `test_revizii_v2` | 97/97 |
| `test_cheltuieli_unificat` | 43/43 |

The local pages Cost operațional (and its `data` endpoint) and Istoric Financiar respond normally.

**Defects found and fixed during testing**
- A JavaScript variable named `benefHtml` shadowed the beneficiaries renderer, so that tab stayed empty.
- `setSelectionRange` was called on a `number` input, which throws an error.
- The default `per_page` caused a division by zero.
- Event-based elements were wrongly reported as missing data.
- The document pill showed a constructed number. It now shows the real invoice number from Reparații, or "Fișa #id".

---

## 8. Screenshot comparison

Screenshots were taken at 1672 × 940 with Chromium and compared with the mockup.

**Corrected after the first pass**
- The toolbar wrapped onto two rows; the selects are now fixed-width and everything fits on one row.
- Category labels were truncated by a ⚠ glyph; it was replaced with a dotted underline.
- The document column was too narrow.
- Six tabs overflowed; they now flex up to 270 px.

**Intentional differences**
- The app's header (Christmas banner) and sidebar are kept, per instructions.
- A one-line data-quality banner sits above the table, because incomplete costs must be visible.
- An extra "Pe" (vehicle/driver) column in the grid, which the brief requires.
- A sixth tab, "Șoferi / Simulare".
- Values are real, so most vehicles show strong losses: fuel ≈ 2.8–3.4 lei/km against tariff revenue ≈ 1.2–1.4 lei/km. This is the actual data, not a calculation error.

---

## 9. Remaining limitations

- Driver eligibility uses status, assignments and expired documents. There is no data on licence category or ADR qualification per vehicle type, so these are not checked.
- The simulation estimates a driver's cost/km from his average monthly cost ÷ the vehicle's monthly km. It is a projection, not a forecast.
- Very long ranges are computed month by month. The cache makes later requests fast, but the first calculation over several years takes several seconds.
- Exporting selected rows only is not implemented; export uses the current filters.
- The Cost operațional / km page still uses the current coupling. Switching it to the historical mode is a separate decision, because it would change the numbers on that page.
