# Audit: `page=revizii` (Revizii)

Status as of 2026-11-12, `main` @ `cf59c69`. This is a read-only analysis written as a handoff for a refactor. Nothing was changed.

---

## 1. Purpose (what the page is for)

Revizii is a **predictive maintenance counter**. For each tracked component on each vehicle it answers one question: *how many km are left until the next service or replacement?*

It tracks 3 component categories:

| Category (`?tip=`) | Components (`tip_revizie`) | Motor vehicle (cap tractor / camion) | Semi-remorcă | Engine hours counted |
|---|---|---|---|---|
| `schimb_ulei` (default) | `schimb_ulei` | yes, 1 item | **no** | **yes** |
| `placute_frana` | `placute_fata`, `placute_spate` / `placute_semi` | Față + Spate | 1 "Set" | no |
| `anvelope` | `anvelope` | 1 set per vehicle | 1 set per vehicle | no |

The registry is hard-coded in `ReviziiModel::TABS` and `ReviziiModel::TAB_ITEMS` ([ReviziiModel.php:38-62](htdocs/models/ReviziiModel.php:38)).

The core idea is **"km echivalenți"** (equivalent km). Wear since the last service (T0) is computed as:

```
km_total  = consum_initial_km  (odometer delta at configuration, one-off)
          + km GPS            (Σ distance of SasFleet travelsheet segments since T0)
          + ore_eligibile × factor_km_ora   (oil only; default 40 km per hour)
km_ramasi = interval_km − km_total
status    = necesita (≤0) | se_apropie (< prag_avertizare_km) | in_regula
            | fara_date (no telemetry) | neconfigurat (no plan)
```

The eligible engine hours come from the `sursa_ore_motor` setting:
- `ore_aspirare` (code default): compressor/PTO hours typed on trips or trip phases in Dispecer curse.
- `sas_pe_loc`: SAS `idleEngineSeconds`, meaning the engine runs while the vehicle stands still.
- `workSeconds` is never used, because it includes driving, which the km already count.

A semi-remorcă has no telemetry of its own. Its km are **borrowed from the coupled tractor**: the tractor's segments are clipped to the coupling windows in `vehicule_cuplaje`. A trailer never receives engine hours.

Missing telemetry days are **not** counted as 0 km. They are listed as `zile_lipsa`. The result is then flagged as incomplete ("≤" in the UI). If no day at all is covered, the status becomes `fara_date`.

---

## 2. Screens (5 sub-pages, one route)

The sidebar group `grp_revizii` holds 5 links ([header.php:297-305](htdocs/views/layout/header.php:297)). The shared tab bar is [views/revizii/_tabs.php](htdocs/views/revizii/_tabs.php).

| URL | View | JS | What it shows |
|---|---|---|---|
| `?page=revizii&tip=prezentare` | `views/revizii/overview.php` | `revizii-overview.js` + `revizii-common.js` + Chart.js 4.4.3 (CDN) | Fleet KPIs (distinct vehicles, worst status), a donut per category, the alerts list (Necesită / Se apropie), "Activitate recentă" (client-side filter + paging), and a monthly chart. |
| `?page=revizii` (= `schimb_ulei`) | `views/revizii/index.php` | `revizii.js` + `revizii-common.js` + `datetime-picker-field.js` | One row per vehicle (oil has no ansambluri). Cards or table view, search/status/type filters, client paging. Modals: calculation detail, Configurare revizie, Înregistrează (record service). |
| `?page=revizii&tip=placute_frana` | same `index.php` | same | Rows can be an **ansamblu** (active coupling tractor + semi), a single tractor/truck, or a lone semi. |
| `?page=revizii&tip=anvelope` | same `index.php` | same | Same as plăcuțe. |
| `?page=revizii&tip=istoric` | `views/revizii/istoric.php` | `revizii-istoric.js` + Chart.js | Unified intervention history: server-side filters, sort and paging (10 per page), KPIs, monthly and category charts, detail panel, documents, reference, verify, delete, Excel/PDF export, saved reports. |

The simulator modal (`_sim_modal.php`) appears on the overview and category pages.

`index.php` is a large view (681 lines). It builds the whole dataset into `<script id="rvPageData">` JSON plus per-row `<template>` blocks for the detail view. All filtering and paging on the category pages happens in the browser.

---

## 3. Code map (~10.5k lines)

### Backend
| File | Lines | Role |
|---|---|---|
| [controllers/ReviziiController.php](htdocs/controllers/ReviziiController.php) | 472 | Router for every action. It renders overview/category pages and handles `save_config`, `record`, `sync`, `simulate` and `raport_export`. All `istoric_*` actions are delegated to the controller below. |
| [controllers/ReviziiIstoricController.php](htdocs/controllers/ReviziiIstoricController.php) | 475 | Istoric tab: JSON data/detail, documents (via `InvoiceStorageService`, storage/revizii/documente), verify, reference, delete, export, reports. |
| [models/ReviziiModel.php](htdocs/models/ReviziiModel.php) | 851 | Registry constants, **runtime DDL** (`ensureSchema` + `ensureColumn` through INFORMATION_SCHEMA), settings, vehicles, couplings, plans, history insert, aspiration-hour events from Dispecer, SAS mapping/segments/coverage storage. |
| [models/ReviziiIstoricModel.php](htdocs/models/ReviziiIstoricModel.php) | 907 | Istoric queries (filters, KPIs, paging, monthly), side tables (meta, documents, reports, deleted copies), and `deleteIntervention` with T0 rollback. |
| [services/ReviziiService.php](htdocs/services/ReviziiService.php) | 970 | Orchestration: `load()` preloads everything, then `calculate()`, `computeComponent()`, `buildDashboard()`, `buildOverview()`, `recordService()`, `simulate()`, `chunkDays()`. |
| [services/ReviziiCalculator.php](htdocs/services/ReviziiCalculator.php) | 245 | **Pure** engine with no DB: segment normalisation and dedupe, implausible-segment rejection (>150 km/h, >2000 km), proportional window usage, event hours, missing days, `evaluate()`, `statusFor()`. |
| [services/ReviziiSasSyncService.php](htdocs/services/ReviziiSasSyncService.php) | 342 | SasFleet sync: refreshes the plate→carId mapping, computes needed days from the oldest T0, queues 7-day windows (recent first), 0.65 s pause between calls, aborts after 3 consecutive errors, file lock, idempotent window replace. |
| [services/ReviziiWeeklyReportService.php](htdocs/services/ReviziiWeeklyReportService.php) | 1011 | Weekly email/SMS report and its Excel export. It reads `buildOverview()`. |
| [services/ReviziiIstoricExportService.php](htdocs/services/ReviziiIstoricExportService.php) | 301 | Istoric Excel/PDF (PDF capped at 3000 rows; over the cap it refuses rather than truncating). |
| [permissions/modules/revizii.php](htdocs/permissions/modules/revizii.php) | 47 | ACL actions: `view`, `edit`\*, `simulate`\*, `export`, `documents`\*, `delete_history`\* (\* = default_admin). Includes the endpoint→action map. |

### Scripts / cron
| Script | Purpose |
|---|---|
| `scripts/sync_revizii_sas.php` + `run_sync_revizii.sh` | Cron sync (intended hourly, `15 * * * *`). On 2026-10-12 it was **not installed on the VPS**. Verify the current state. |
| `scripts/revizii_alerts.php` | Prints `buildOverview()['alerte']` as JSON for `notification_service/worker.py` (event "Revizie (km)"). |
| `scripts/revizii_raport_saptamanal.php` | PHP cron for the weekly report (once per ISO week per recipient). |
| `scripts/test_revizii_schimb_ulei.php` (58), `test_revizii_istoric.php` (47), `test_revizii_stergere.php` (18) | Existing tests. They use local data and cached SAS responses in `storage/cache/sas_trip_fuel`. **No live SAS calls.** |

### Frontend
`revizii.js` (757), `revizii-common.js` (186, simulator + sync button), `revizii-overview.js` (227), `revizii-istoric.js` (790), `revizii.css` (632).

---

## 4. Data model

All tables are created **at runtime** by the models. A mirror is meant to exist in `database/update_revizii_schimb_ulei.sql`.

| Table | Meaning | Write pattern |
|---|---|---|
| `revizii_setari` | key/value: `factor_km_ora` (40), `prag_avertizare_km` (1500), `interval_implicit_km` (10000), `backfill_max_zile` (180), `sursa_ore_motor` | upsert. **Only `factor_km_ora` and `sursa_ore_motor` are editable in the UI.** |
| `revizii_planuri` | one plan per (vehicle, tip_revizie): `interval_km`, `prag_avertizare_km`, `baseline_at` (= **T0**), `km_ultima_revizie`, `consum_initial_km`/`_sursa` | insert/update |
| `revizii_istoric` | one row per real intervention, with a snapshot of the closed cycle (`km_efectuati`, `ore_motor`, `km_echivalenti`, `factor`, `sursa_ore`, `date_complete`) and `plan_anterior` (JSON for rollback) | **INSERT only** (deletes go through `revizii_istoric_sterse`) |
| `revizii_istoric_meta` | reference (invoice no.), `verificat_at` | upsert |
| `revizii_istoric_documente` | attachments (soft delete) | |
| `revizii_rapoarte` | generated reports (files in storage/revizii/rapoarte, visible to author + admin) | |
| `revizii_istoric_sterse` | full copy of deleted interventions (+ audit_log) | |
| `revizii_sas_vehicule` | local vehicle ↔ SAS carId (by plate), fully replaced on every sync | delete + insert |
| `revizii_sas_segmente` | travelsheet segments (km, work_seconds, idle_seconds) | window replace |
| `revizii_sas_acoperire` | per (carId, day): ok/eroare, `inchisa` (fetched after day end + 2 h) | upsert |

Tables read from other modules: `vehicule` (incl. `km_bord`), `vehicule_cuplaje`, `vehicule_categorii_capacitate`, `curse_dispecer` + `curse_segmente` (aspiration hours), `utilizatori`.

**Local DB snapshot (2026-11-12):** 1 plan (schimb_ulei, T0 2026-10-12), 0 history rows, 44 SAS mappings, 12 segments, coverage 2026-10-12 → 2026-10-28, `sursa_ore_motor = sas_pe_loc`. Locally the module is **practically unused**. Check the VPS before planning a data migration.

---

## 5. How the main flows work

### 5.1 Page load (overview / category)
1. `ReviziiService::load()` reads settings, active and all vehicles, all plans, SAS mapping, couplings and sync state. It then preloads **all segments + ok-days from the oldest T0 of any plan** for every mapped car, plus all aspiration events.
2. `buildDashboard($tab)` runs `computeComponent()` for every (vehicle × component of the tab). `buildOverview()` does the same for **every tab**. Each call runs `calculate()`: per car window it does missingDays → normalizeSegments → usage → aspiration hours → `evaluate()`, and builds a human-readable step list (`pasi`) for the detail modal.
3. Rows are grouped into ansambluri (only for tabs that have trailers, using active couplings) and sorted by severity, then by remaining km.
4. **No SAS API call happens during page load.** It reads the DB only.

### 5.2 Configure (`save_config`, edit)
- The modal has a single mode (one vehicle or row) and a bulk mode "Mai multe vehicule (pe categorii)" grouped by vehicle type or capacity category. JS writes hidden `items[]` fields.
- Per item it validates: interval 500–300 000, `prag < interval`, and `km_ultima_revizie ≤ km_bord`.
- `savePlan()` behaves as follows:
  - **New plan:** T0 = now, `consum_initial = km_bord − km_ultima_revizie`.
  - **Changed km_ultima_revizie:** T0 resets to now and consum_initial is recomputed.
  - **Interval/threshold only:** T0 is kept.
- The oil tab can also save the global `factor_km_ora` and `sursa_ore_motor`.
- After saving it sets `$_SESSION['revizii_autosync']`, so the page auto-starts a sync in 15-second chunks (because of `php -S` single-thread dev).

### 5.3 Record a service (`record`, edit)
- One or more checked components, each with its own T0 (date **and** time required, not in the future, not before the last recorded intervention) and an optional km bord.
- If T0 ≥ the plan's current baseline, it computes a snapshot up to T0 and stores it in `revizii_istoric`. The plan is then upserted: baseline = T0, consum_initial = 0.
- Plăcuțe and anvelope **require** a configured plan first. Oil without a plan silently gets `interval_implicit_km` (10 000) and **no threshold** (NULL, so the global 1500 applies).
- If the record started from an overview alert (`return=prezentare`), the user is redirected back to the overview.

### 5.4 Sync (`sync`, view, AJAX)
`ReviziiSasSyncService::run(15 calls, 15 s)`. Cron runs it with a budget of 500 calls. The steps:
1. Refresh the mapping through `FleetLivePositionService::getFleetHierarchy()`.
2. Compute needs: per carId, from the oldest relevant T0, floored at `backfill_max_zile`.
3. Fetch the days that are missing, in error, or not yet closed.
4. Recent windows first.
5. `storeWindow` replaces the window atomically.

It shares the SAS session file `storage/cache/sas_session.json`.

### 5.5 Simulate (`simulate`, admin)
Uses the same `calculate()` on a chosen vehicle (including inactive ones), period (≤ 92 days, in the past only) and parameters. Missing days are filled **read-only** from SAS (≤ 20 calls, 7-day chunks). Nothing is persisted.

### 5.6 Istoric
This is a read model over `revizii_istoric` plus the side tables. Status precedence: Verificat > Finalizat (`date_complete=1` or first T0) > Date incomplete. The UI shows only "Înlocuire" / "Înlocuire set", because inspections and rotations are not recorded. Delete stores a copy and audits it. If that row produced the plan's current T0, the plan reverts to `plan_anterior`, to the previous intervention, or to `created_at`.

### 5.7 Notifications
- `notification_service/worker.py` shells out to `php scripts/revizii_alerts.php`. That script runs the **full `buildOverview()`** and returns the alerts. One alert cycle = `plan_id + baseline_at`.
- The weekly report (event `revizie_raport_saptamanal`) is configured in `page=notificari` (`NotificationRuleController`) and sent by its own PHP cron.

---

## 6. Coupling with other modules (refactor blast radius)

These consumers will break if tables or classes are renamed or dropped:

| Consumer | Depends on |
|---|---|
| `TireSasKmService` (Stoc Anvelope km since mounting) | `ReviziiCalculator::normalizeSegments/usage` |
| `TrailerSasKmService` (Centru Kilometraj, trailer km after cutover) | `ReviziiCalculator`, reads **`revizii_sas_vehicule`** |
| `ComponentSasKmService` → through `TrailerSasKmService::sasCarIds` | `revizii_sas_vehicule` |
| `VehicleFluidService` (Consumabile & fluide) | **`revizii_sas_vehicule`, `revizii_sas_segmente.work_seconds`, `revizii_sas_acoperire`**, `ReviziiModel::getAspirationEvents()` |
| `scripts/consumabile_alerts.php`, `test_consumabile_*` | instantiate `ReviziiModel` (for its schema side-effect) |
| `NotificationRuleController`, `views/notificari/index.php`, `worker.py` | `ReviziiWeeklyReportService`, `ReviziiModel::TABS`, `getVehicles()`, `revizii_alerts.php` output shape, deep link `?page=revizii&tip=<categorie>` |
| `ActivityReportModel` (Rapoarte activitate) | `revizii_istoric` (created_by), module key `revizii` |
| ACL (`access_permissions`) | module key `revizii` and action keys. **These are stable and stored in the DB.** |

**Important:** `ReviziiCalculator` and the `revizii_sas_*` tables have effectively become **shared SAS-telemetry infrastructure**, used for tires, trailers and fluids, not just Revizii. A refactor should move them out (for example `SasSegmentCalculator` / `sas_segmente`) rather than leave other modules importing "Revizii" code.

---

## 7. Findings: problems, risks, debt

### 7.1 Functional overlap (the biggest issue)
The app now has **3–4 parallel ways** to track the same maintenance, each with its own counter rules:

| Concern | Revizii | Other module |
|---|---|---|
| Engine oil | `schimb_ulei`: GPS km + idle **or** aspiration hours × 40 | **Consumabile & fluide** (`VehicleFluidService`): "Motor (ulei motor)" from **km CAN + engine hours from `work_seconds`** (which Revizii explicitly rejects as double counting) |
| Tyres | `anvelope`: one set per vehicle, own interval | **Stoc Anvelope**: per-tyre, per-position km from SAS since the mount minute (`TireSasKmService`) |
| Brake pads / components | `placute_*` plans | **Reparații Auto** component catalog with intervals + `VehicleComponentPanelService` "următoarea revizie" + **Intervenții V2** (component work orders with a `motiv=revizie` preset) |
| Legacy | none | `vehicule.km_revizie` counter in Dispecer curse (Revizii deliberately does not touch it) |

The same oil change can show different "km left" values on two pages. Recording an intervention in Intervenții V2 or Consumabile **does not** reset the Revizii T0, and the reverse is also true. Before refactoring, decide which module is the single source of truth per component.

### 7.2 Calculation and data correctness
1. **`consum_initial_km` uses `vehicule.km_bord`**, which is the sum of Dispecer km and not the real odometer (see memory "Km bord orientativ"). Since the trailer cutover, a trailer's `km_bord` grows from SAS instead. So `km_bord − km_ultima_revizie` mixes a pseudo-odometer with a real dashboard reading, and the validation `km_ultima_revizie ≤ km_bord` can reject correct values. The real CAN odometer now exists (Centru Kilometraj, `sas-can-km-raport-web`) and should be used instead.
2. **The km source is GPS travelsheet distance**, while the newer modules prefer CAN km. These are two different km notions in the same app.
3. Recording oil **without a plan** silently creates one with the default 10 000 km and no threshold. Plăcuțe/anvelope refuse in the same situation. The behaviour is inconsistent.
4. `interval_implicit_km`, `prag_avertizare_km` (global) and `backfill_max_zile` are **not editable in the UI**.
5. `factor_km_ora` and `sursa_ore_motor` are **global**, not per vehicle or engine. Changing them retroactively changes every open cycle, because the calculation reruns from T0 on every load. Only closed cycles keep their snapshot.
6. If `ore_aspirare` is the source, any compressor trip without hours marks the result as incomplete (`ore_incomplete`), even when the km are complete.
7. A trailer before the first coupling record (2026-04-20) gets missing days that can never be filled. A trailer plan with an older T0 stays `fara_date` / "≤" for the whole cycle.
8. Coupling windows for trailers use the whole `vehicule_cuplaje` history, but the `ansamblu` grouping on screen uses only **active** couplings. The rows shown and the km allocated can differ, which is correct but potentially confusing.

### 7.3 Performance / scalability
1. **Every page load recomputes everything.** The overview runs `computeComponent` for every vehicle × every component across all 3 tabs, preloads all segments since the oldest T0 (no upper bound other than the T0 itself), and loops windows × segments in PHP (O(windows × segments) per car). Cost grows with the age of the oldest plan.
2. The same full `buildOverview()` also runs in `revizii_alerts.php` (every worker run) and in the weekly report. There is no cache or materialised state.
3. `ReviziiModel::__construct` runs 6 `CREATE TABLE IF NOT EXISTS` + 3 INFORMATION_SCHEMA lookups **per request** (static flag only per process). `ReviziiIstoricModel` adds more. Other modules instantiate `ReviziiModel` just to trigger this.
4. Category pages ship all rows plus per-row detail templates as HTML/JSON and filter client-side. That is fine at ~45 vehicles but won't scale.
5. `ReviziiService::load()` loads `getVehicles()` twice (active + all).

### 7.4 Architecture / code quality
1. The **component registry is hard-coded** (`TABS` / `TAB_ITEMS`, a `match` in `componentName()` / `actionName()`, and a `FIELD(...)` sort in `ReviziiIstoricModel`). Adding a component, such as gearbox oil or filters, touches 5+ places. It is not linked to the Reparații Auto catalog (`mentenanta_auto_componente`).
2. `ReviziiService` mixes loading/caching, calculation orchestration, presentation (`pasi` strings with formatted numbers, notes in Romanian), row grouping and write logic (`recordService`).
3. Severity maps are duplicated (`buildOverview`, `row()`), as are the "last sync" loops and the `fmt`/`esc` helpers in 3 JS files.
4. Mutable state lives in the service (`$loaded`, preload caches with "extend if older" logic). It is hard to reason about and to test.
5. The controller routes Istoric actions by name prefix inside `ReviziiController::handle`. The permission file maps endpoints separately. There are 2 sources to keep in sync.
6. The "Revizii" name sits on generic SAS infrastructure (see §6).
7. Romanian user-facing messages are built inside services and models, so presentation is not separated from logic.

### 7.5 Operations
1. The sync depends on cron (`run_sync_revizii.sh`). If cron is not installed, data is only refreshed when someone presses "Sincronizează" or saves config. After 36 h without a successful sync the UI shows `date_vechi`.
2. The sync only fetches cars that have a Revizii plan. **`VehicleFluidService` reads `revizii_sas_segmente.work_seconds` too, so engine hours in Consumabile are only available for vehicles that happen to have a Revizii plan.** This is a hidden dependency.
3. The SAS session file and the ~60 req/min limit are shared with other SAS consumers. Each service has its own pause and lock, with no global rate limiter.

---

## 8. What works well (keep this)
- The pure calculator (`ReviziiCalculator`): deterministic, well commented, and reused elsewhere.
- Honest handling of missing data: no invented 0 km, "≤" lower bounds, `fara_date`, rejection of implausible segments.
- Idempotent window-replace sync with a coverage table, recent-first priority, abort on outage, and a lock.
- Immutable history with snapshots, plus side tables for annotations. Delete keeps a full copy and rolls the T0 back correctly (`plan_anterior`).
- One shared calculation for production and simulation, plus a step-by-step explanation in the detail modal (good for user trust).
- Test scripts exist (123 tests total) and run on local and cached data.

---

## 9. Suggested questions to settle before the refactor
1. **Single source of truth per component:** should Revizii stay the owner of oil, pads and tyres, or become a *view* over Consumabile (oil), Stoc Anvelope (tyres) and Reparații Auto / Intervenții V2 (pads, other parts)?
2. **Which km:** GPS travelsheet distance or CAN odometer (Centru Kilometraj)? And `consum_initial` from a real odometer reading instead of `km_bord`?
3. **Hour rule:** keep the global `factor_km_ora` + source, or per engine/vehicle? And how should it reconcile with `VehicleFluidService`, which uses `work_seconds`?
4. Should the component registry come from the `mentenanta_auto_componente` catalog?
5. Should computed state be stored (for example updated after each sync) instead of recomputed on every page load, alert run and report?
6. Extract the SAS segment storage, sync and calculator into a neutral `Sas*` layer used by Revizii, tyres, trailers and fluids, keeping table names or migrating them with views.
7. Keep stable: ACL keys (`revizii.*`), the deep links `?page=revizii&tip=…` used by worker.py and emails, the `revizii_alerts.php` JSON shape (`plan_id + baseline_at` identifies an alert cycle), and the `revizii_istoric` immutability contract.

## 10. How to verify a refactor
- Run `scripts/test_revizii_schimb_ulei.php`, `test_revizii_istoric.php` and `test_revizii_stergere.php`, plus the consumabile, tyre and trailer tests that import `ReviziiCalculator` or `ReviziiModel`.
- Do not make live SAS calls from tests. Use `storage/cache/sas_trip_fuel`.
- Do not call `ensure*Schema` (DDL) inside a test transaction, because it causes an implicit COMMIT.
- For authenticated local endpoint checks, see the memory note "Testare endpoint autentificat local" (a session file in `C:/laragon/tmp`).
