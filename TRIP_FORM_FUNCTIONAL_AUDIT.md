# TRIP_FORM_FUNCTIONAL_AUDIT — "Adaugă Cursă" (Dispecer curse)

**Scope:** the "Adaugă Cursă" form on `?page=dispecer_curse` (Dispecer curse), from first render to database write, plus everything it shares with Edit and phases.
**Mode:** read-only reverse engineering. No code, layout, business logic or database behavior was changed.
**Date of audit:** 2026-10-07. Line numbers are from the working tree on that date (branch `main`, after commit `eb240e8`).

Where something could not be established with confidence, it is marked **NEEDS VERIFICATION** with the file/function to check.

### Path abbreviations used throughout

| Abbrev. | File (relative to `htdocs/`) |
|---|---|
| **IDX** | `views/dispecer_curse/index.php` |
| **FLD** | `views/dispecer_curse/_race_form_fields.php` |
| **ATT** | `views/dispecer_curse/_race_form_attrs.php` |
| **EDT** | `views/dispecer_curse/edit.php` |
| **JS** | `assets/js/dispecer-curse.js` |
| **LIVE** | `assets/js/dispatcher-live.js` |
| **APP** | `assets/js/app.js` |
| **C** | `controllers/DispecerCurseController.php` |
| **M** | `models/DispecerCurseModel.php` |
| **TPS** | `services/TransportPricingService.php` |
| **TTM / TTC** | `models/TransportTariffModel.php` / `controllers/TransportTariffController.php` |
| **IRS** | `services/InactiveResourceStatusService.php` |
| **IRA** | `models/InactiveResourceApprovalModel.php` |
| **PERM** | `permissions/modules/dispecer_curse.php` |

---

## Table of contents

0. [Executive summary](#0-executive-summary)
1. [Complete implementation map](#1-complete-implementation-map)
2. [Field inventory](#2-field-inventory)
3. [Conditional form states](#3-conditional-form-states)
4. [Operator workflow](#4-operator-workflow)
5. [Calculations and derived data](#5-calculations-and-derived-data)
6. [Data dependencies](#6-data-dependencies)
7. [Validation audit](#7-validation-audit)
8. [Submission lifecycle](#8-submission-lifecycle)
9. [Add / Edit / Phases / Duplicate — shared vs unique](#9-add--edit--phases--duplicate--shared-vs-unique)
10. [UX audit of the current implementation](#10-ux-audit-of-the-current-implementation)
11. [Operator decision points](#11-operator-decision-points)
12. [UI elements that MUST NOT be changed blindly](#12-ui-elements-that-must-not-be-changed-blindly)
13. [Form behavior specification](#13-form-behavior-specification)
14. [Behavior matrix](#14-behavior-matrix)
15. [Interaction dependency graph](#15-interaction-dependency-graph)
16. [Behavior that MUST survive the redesign](#16-behavior-that-must-survive-the-redesign)
17. [Open items — NEEDS VERIFICATION](#17-open-items--needs-verification)
18. [Browser Runtime Validation](#18-browser-runtime-validation)

---

## 0. Executive summary

- **Five transport types.** They are hard-coded in `C::TRANSPORT_TYPES` (C:10-16), mirrored by the DB ENUM, and nothing else exists:
  - `primar` ("Primar km")
  - `primar_tona` ("Primar tone")
  - `distributie` ("Distributie")
  - `primar_distributie` ("Primar+Distributie", referred to as **P+D** below)
  - `compresor` ("Compresor")

  The JS also handles a legacy value `mixt`. That branch is dead code.
- **The form is driven by two primary decisions:**
  - **Beneficiary** decides which transport types are valid, which vehicles are eligible, and which locations, zones and route rules apply.
  - **Transport type** decides which fields exist, which are required, and which pricing formula runs.

  The **vehicle** is the third pivot. It filters the drivers, picks the route-rule variant, and supplies capacity and default locations.
- **There is almost no client-side validation.**
  - The form has `novalidate`. The `required` attributes have no effect.
  - The only client-side blockers are:
    - date parsing (red border, no message);
    - the async overlap check;
    - the inactive-resource approval modal.
  - All other validation happens on the server after a full page round trip. It comes in two classes:
    - **HARD errors** re-render the form with inline messages.
    - **SOFT errors** ("incomplete") show a "Salvezi cursa fara toate informatiile?" modal. The operator can confirm and save anyway.
- **Three pricing engines exist and they can disagree** (§5.6):
  1. The local JS estimate.
  2. A server preview via `tarife_transport&action=preview`. It runs only for users with `tarife_transport.view`.
  3. The stored value. This is the legacy controller formula, replaced by the versioned quote only if one or more components resolved from a tariff version.

  The "Total Facturare (estimare)" an operator sees is therefore **not guaranteed** to be what is saved.
- **Save is a single transaction plus several post-commit writes:**
  - Inside the transaction: INSERT `curse_dispecer`, UPDATE vehicle odometer and revision counters (`vehicule`), INSERT `cursa_audit_log`.
  - After commit, each separately and without a transaction: tariff traceability UPDATE, accommodation-expense rematch, inactive-resource approval rows, optional permanent route-config change.
- **The Edit form is a hand-maintained copy of the Add form markup** (EDT:603-1017). Both run the same JS (`initRaceForm`).
  - The shared partial `_race_form_fields.php` is used only by the Add form today.
  - Its "phase" mode branch is dead, because `_race_segments_panel.php` is not included anywhere.
  - Phases are edited in EDT in "phase mode".
- **No "duplicate trip" feature exists.** Only duplicate *detection*.

---

## 1. Complete implementation map

### 1.1 Files involved

| Layer | File | Role |
|---|---|---|
| Front controller / router | `htdocs/index.php` (437-503, 652-655, 682-685) | Reads `page`/`action`. Calls `require_route_access()`, then `DispecerCurseController::handle()`. Routes `tarife_transport&action=preview` to `TransportTariffController`. |
| ACL | `includes/access.php` (`require_route_access` 243-262, `can` 181-193, `access_deny_403` 284-301), **PERM** | `store` → `dispecer_curse.create`. AJAX helpers need only `view`. |
| Auth / CSRF | `includes/auth.php:46-52`, `includes/csrf.php` (`csrf_field`, `ensure_csrf_or_redirect` 27-33) | Session login. `_token` field. |
| Controller | **C** | `handle` 273 · `indexAction` 1260-1431 · `storeAction` 2202-2328 · `validateRaceInput` 7065-7967 · `applyVersionedPricing` 2687 · `persistTariffTraceability` 2766 · `applyPermanentVehicleRouteConfig` 1590 · AJAX: `tripConflictCheckAction` 457, `racesActivityAction` 518, `inactiveResourceStatusAction` 945, `requestInactiveVehicleApprovalAction` 998, `cancelInactiveVehicleApprovalAction` 1184 · route resolvers 9223-9747 · normalizers 10050-10253 |
| Model | **M** | Option sources 224-1830 · `findDuplicateRaceId` 4576 · `findOverlappingRace` 4614 · `findSimilarRaces` 4676 · `createRaceAndSyncVehicleKm` 5101 · `createRace` 4989 · km sync 10025-10185 · `logRaceAudit` 6499 · ensure-schema DDL 2198-2540 |
| Pricing | **TPS**, **TTM**, **TTC** (`previewAction` 1021-1051) | Versioned tariff quote by trip date. |
| Inactive resources | **IRS**, **IRA** | Detects inactive vehicles and drivers. Stores approval requests. |
| Accommodation side-effect | `models/AccommodationExpenseModel.php`, `services/TripExpenseMirrorService.php` | Post-save rematch of accommodation expenses to trips. |
| View (page) | **IDX** 289-325 (form), 2671-2692 (vehicle route decision modal), 2697-2788 (maintenance and incomplete modals) | Form shell, hidden inputs, modals, page-level JSON config. |
| View (fields) | **FLD** | All visible fields. Shared partial: `$fieldMode = 'trip'|'phase'`. |
| View (config attrs) | **ATT** | `data-*` JSON configuration and AJAX URLs on the `<form>`. |
| Modals / panels | `_inactive_resource_modal.php`, `_trip_conflict_modal.php`, `_expense_prompt_modal.php`, `_race_day_panel.php`, `_live_gps_panel.php`, `_open_races_panel.php` | Approval, overlap/similar, post-create expense prompt, vehicle's existing trips, GPS prefill, incomplete trips. |
| JS (form) | **JS** `initRaceForm` 62-6659 · `initCreateExpenseInRaceForm` 6661 (inert) · versioned preview IIFE 6927-7059 | All client behavior. |
| JS (prefill) | **LIVE** 620-695 | "Adaugă cursă" from the live-GPS strip. |
| JS (global) | **APP** 227-273 (double-submit guard), 1129 | Generic form submit handling. |
| CSS | `assets/css/style.css` 4618, 4644-4646, 4875-4972 | Disabled look, hidden notes, grid widths, circuit reorder. |

### 1.2 Dependency map (end to end)

```
Page load  GET ?page=dispecer_curse
  └─ index.php → require_route_access(dispecer_curse,index) → C::handle('index') → indexAction
       ├─ consumeFormFlash('race_create')        (old values + errors after a failed POST)
       ├─ defaultRaceFormData()                   (dates = today, everything else empty)
       ├─ ~20 model queries → option lists + JSON maps (§6)
       └─ render IDX → FLD (fields) + ATT (data-* JSON on <form>)
            └─ DOMContentLoaded → JS initRaceForm(form) for every .dispatcher-race-form
                 └─ initGoodsTypeDropdown → syncTransportMode → syncConfigTransportLink → syncRaceDurationHint

Operator input
  Frontend field ─(change/input)→ JS handler ─→ cascades (§3, §15)
       ├─ local recalculation  recalculateTotal()            (JS 5537)
       ├─ server preview  POST ?page=tarife_transport&action=preview (debounced, Add form only)
       ├─ GET  action=inactive_resource_status   (on beneficiary/vehicle/driver/start-date change)
       └─ GET  action=races_activity             (vehicle change, every 30 s, window focus)

Submit (capture-phase listener JS 6557)
  ├─ parse date displays → hidden dd/mm/yyyy + HH:MM      (block if invalid)
  ├─ GET action=trip_conflict_check   → overlap modal (block) / similar modal (confirm)
  ├─ inactive check → approval modal (admin: approve now/later · user: request)
  └─ native POST ?page=dispecer_curse&action=store
        └─ index.php ACL (view + create) → C::handle('store')
             ├─ syncTariffLegacyValues()                                  (writes legacy tariff columns)
             └─ storeAction
                  ├─ CSRF
                  ├─ validateRaceInput($_POST)  → $data, HARD $errors, $old, SOFT $softErrors
                  ├─ HARD → flash old+errors → redirect (form re-rendered with inline errors)
                  ├─ SOFT and !confirm_incomplete → incomplete modal round-trip
                  ├─ inactive resources need a decision → inline alert round-trip
                  ├─ applyVersionedPricing (TPS::quote)
                  ├─ duplicate (warn+stop) → overlap (block) → similar (warn after save)
                  ├─ TRANSACTION: INSERT curse_dispecer · UPDATE vehicule km · INSERT cursa_audit_log
                  ├─ post-commit: maintenance popup · accommodation rematch · tariff traceability
                  │               · inactive approvals · permanent route config
                  └─ flashes + post-create expense prompt → redirect ?page=dispecer_curse
```

### 1.3 Routing and ACL details

- `require_route_access($page, $action)` runs before the controller.
  - It requires `can('dispecer_curse','view')`, plus the endpoint action from **PERM**: `'store' => 'create'` (PERM:40).
  - On failure it returns a 403: JSON for XHR, otherwise `errors/403.php`.
- The AJAX actions used by the form are **not** in PERM's `endpoints` map, so they only need `view`:
  - `inactive_resource_status`
  - `trip_conflict_check`
  - `races_activity`
  - `request_inactive_vehicle_approval`
  - `cancel_inactive_vehicle_approval`

  NEEDS VERIFICATION: the router's fallback for unmapped actions (`includes/access.php` `require_route_access`).
- `C::handle('store')` calls `syncTariffLegacyValues()` before `storeAction()` (C:307-310). That writes legacy tariff columns for the posted beneficiary, and it does so **before** the POST-method and CSRF checks.
- The versioned preview endpoint `tarife_transport&action=preview` requires `tarife_transport.view` or admin (`TTC::requireView`, TTC:76-88, 1023). Other users get a 403 HTML page, which the JS ignores silently.
- The Add form is rendered for every user who can view the page. The view does not check `can('dispecer_curse','create')` (IDX:4-6 checks only edit/delete/delete_bulk). A user without `create` fills the form and gets a 403 on submit.

---

## 2. Field inventory

**Conventions**
- *Server kind*: **HARD** = blocking error; **SOFT** = "incomplete" confirmation; — = not validated.
- *JS-required* = the JS toggles the `required` attribute. It has **no effect**, because the form is `novalidate`.
- All visible inputs have `id="race_<name>"` on the Add form (`$fieldPrefix='race'`).
- The column is the `curse_dispecer` column unless stated otherwise.

### 2.1 Hidden / technical inputs (IDX:297-302)

| Input `name` | Default | Written by | Read by | Purpose / notes |
|---|---|---|---|---|
| `_token` | session token | `csrf_field()` | `ensure_csrf_or_redirect`. JS reads it for approval POSTs (JS:2807) | CSRF |
| `vehicle_config_decision` | `""` (hard-coded, never restored after a round trip) | JS `setVehicleConfigDecision` (2609) via the vehicle-route modal | C:2213, C:7240 | `trip` = use an unconfigured vehicle for this trip only. `permanent` = also add it to Configurare Transport. Its **presence** enables the "Alt vehicul" expansion in JS (2577). Add form only. |
| `inactive_approval_decision` | restored from `$formData` | JS (2729-2745) | C:2234 via `normalizeInactiveApprovalDecision` (honored only for reviewers) | `approved` / `pending` / `''` |
| `inactive_approval_signature` | `""` | JS (`vehicle:driver:trip:data_inceput`, 2721) | **never read by the server** | Tells JS whether the decision still matches the selection |
| `confirm_incomplete` | `""` | inline script IDX:2767-2777 (sets `1` and calls `requestSubmit`) | C:2227 | Bypasses the SOFT-error confirmation |
| `confirm_similar` | `""` | JS 6176-6189 (similar modal confirm) | C:2224 (skips the similar lookup) | Never reset after it is set |
| `<datalist id="race_time_options">` | 00:00…23:45 | — | **nothing** (no `list=` attribute) | Dead markup |

### 2.2 Visible fields (FLD, trip mode), in DOM order

| # | Label (operator) | `name` | Column | Type | Default | Options source | Visibility | Server rule | Saved as |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Beneficiar transport * | `beneficiar_id` | `beneficiar_id` | select | empty | `getTransportBeneficiaries(true)`: `configurare_beneficiari_transport WHERE activ=1` | always | SOFT if empty ("Beneficiarul de transport nu este selectat."). HARD if missing or inactive ("Beneficiarul selectat nu este disponibil."). | int / NULL |
| 2 | Tip Transport * | `tip_transport` | `tip_transport` (ENUM) | select | empty | `C::TRANSPORT_TYPES` (5 values, not filtered by beneficiary in the markup) | always | HARD "Tipul de transport este invalid." | as-is |
| 3 | Nr. Înmatriculare * | `vehicle_id` | `vehicle_id` | select (options carry `data-capacitate-transport`) | empty | `getVehicleOptions(false)`: all non-semitrailer `vehicule`, **no status filter**. JS then filters per beneficiary × type (§3.3). | always | HARD "Selecteaza un vehicul valid." HARD "Vehiculul selectat nu este configurat pentru beneficiarul si tipul de transport ales." unless `vehicle_config_decision ∈ {trip,permanent}` | int |
| 4 | Sofer * | `driver_id` | `driver_id` | select | "-- Selecteaza mai intai vehiculul --" | `driversByVehicle[vehicle]` (`soferi_vehicule`, all statuses, primary assignment first) plus the "Alt sofer" expansion = all active drivers (`soferi.status='activ'`) | always | SOFT "Soferul nu este selectat." HARD "Soferul selectat nu exista." **No driver↔vehicle check.** | int / NULL |
| 5 | Data si ora inceput * | display `#race_start_datetime` (no name). Hidden `data_inceput` (dd/mm/yyyy) and `ora_inceput` (HH:MM) | `data_inceput`, `ora_inceput`, **and `data_cursa` = `data_inceput`** | text + popover picker | today, no time | — | always | HARD "Data de inceput este invalida." Time optional; HARD "Ora de inceput este invalida." | Y-m-d / H:i:s |
| 6 | Data incarcare | display `#race_data_incarcare`, hidden `data_incarcare` | `data_incarcare` | text + date picker | empty | — | always (trip mode). DOM-reordered **before** start date for `distributie` only | HARD only if invalid | Y-m-d / NULL |
| 7 | Data si ora sfarsit * | display `#race_end_datetime`, hidden `data_sfarsit`, `ora_sfarsit` | `data_sfarsit`, `ora_sfarsit`, `durata_cursa_minute` (derived) | text + picker | today, no time | — | always | HARD: invalid date; end < start; end time without start time; end ≤ start. **End time is optional** despite the asterisk (trip in progress). | Y-m-d / H:i:s / int |
| 8 | Loc Încărcare * | `loc_incarcare_id` | `loc_incarcare_id` | select | empty | server: all active `configurare_locuri_incarcare`. JS rebuilds per beneficiary/type/vehicle/route (§3.4). | hidden for `compresor` | SOFT "Locul de incarcare nu este selectat." HARD invalid/missing. Primar/distribution: HARD if it belongs to another beneficiary. | int / NULL (forced NULL for compresor) |
| 9 | Loc plecare * | `loc_plecare` | `loc_plecare` | text | empty | free text | `compresor` only | HARD "Completeaza Loc plecare." (compresor). Max 255. | text. For non-compresor the column gets the Primar rule's `garaj_plecare` instead. |
| 10 | Loc aspirare * | `loc_aspirare` | `loc_aspirare` | text | | | `compresor` only | HARD "Completeaza Loc aspirare." | text |
| 11 | Loc livrare * | `loc_livrare` | `loc_livrare` | text | | | `compresor` only | HARD "Completeaza Loc livrare." | text |
| 12 | Loc inchidere cursa * | `loc_livrare_cursa` | `loc_livrare_cursa` | text | | | `compresor` only | HARD "Completeaza Loc inchidere cursa." | text |
| 13 | Tip marfa * | `tip_marfa[]` | `tip_marfa` (CSV) | checkbox dropdown that behaves as **single-select** | none | `C::GOODS_TYPES` = butan, propan, autogaz | always (never disabled) | SOFT "Tipul de marfa nu este selectat." HARD "Selecteaza doar tipuri de marfa valide." | `implode(',')` |
| 14 | Cantitate Încărcată | `cantitate_incarcata` | `cantitate_incarcata` | number step .01 | empty | — | hidden for `compresor` (see bug U-H4) | HARD if invalid or negative. SOFT for `primar_tona`, `distributie`, P+D when empty. SOFT capacity warnings (§7.3). | decimal(12,2) |
| 15 | Capacitate transport reala | `capacitate_transport` | `capacitate_transport` | number, **readonly** | empty | vehicle option `data-capacitate-transport`: real capacity; for a tractor, the actively coupled trailer | hidden for `compresor` | Posted value **ignored**. The server re-reads it from the DB (`getVehicleCapacityInfo`). | snapshot + `capacitate_transport_confirmata` |
| 16 | Km agreati / Km efectuati | `km_cursa` | `km_cursa` (int) | number step 1 | empty | — | hidden for `compresor`. Readonly for primar types and P+D unless the Primar rule is `km_agreati_manual`. | HARD negative. Primar: **overwritten** by the route's `km_tarifare`. SOFT if route km missing or manual km empty. `primar`: SOFT "Km agreati (tarifare) nu sunt completati." | `(int)` cast: decimals truncated, non-numeric becomes 0 silently |
| 17 | Nr. Clienți | `nr_clienti` | `nr_clienti` | number step 1 | empty | — | `distributie`, P+D only | HARD negative | `(int)` |
| 18 | Zona distributie / Loc descarcare / Zona descarcare | `zona_distributie_id` | `zona_distributie_id` | select (label shows zone tariff) | empty | server: all active zones. JS rebuilds per beneficiary/type/vehicle/route. | hidden for `compresor` and for empty type | HARD invalid. SOFT for primar types ("Locul de descarcare nu este selectat.") and distribution ("Zona de distributie nu este selectata."). HARD if it belongs to another beneficiary (primar/distribution). | int / NULL |
| 19 | Loc plecare (garaj) | `loc_plecare_ruta` | not stored directly. Used to pick the Primar rule variant; the column `loc_plecare` gets the rule's `garaj_plecare`. | select, no placeholder | — | Primar rule variants (`garaj_plecare`) | primar types **and** (beneficiary has `rute_primar_puncte_extinse` **or** the resolved rule has garages) | — | — |
| 20 | Loc intoarcere (garaj) | `loc_intoarcere` | `loc_intoarcere` | select | — | Primar rule `garaj_intoarcere` (comma list) | same as #19 | — (falls back to the first return point) | text |
| 21 | Km totali / Km efectuati | `km_totali` | `km_totali` (int) | number step 1 | empty | — | primar, primar_tona, P+D | HARD negative | `(int)`. Used for the odometer sync (preferred over `km_cursa`). |
| 22 | Ore aspirare | `ore_aspirare` | `ore_aspirare` **and** `ore_functionare` | text ("ex: 2h sau 2") | empty | — | `compresor` only | HARD "Ore aspirare este invalid (ex: 2 sau 2h)." Regex `^\d+([.,]\d+)?\s*(h|hr|hrs|ora|ore|hours?)?$` | decimal |
| 23 | Tona lichida aspirata | `tona_aspirata_lichida` | same | number | | | `compresor` | HARD invalid/negative | decimal |
| 24 | Tona gazoasa aspirata | `tona_aspirata_gazoasa` | same | number | | | `compresor` | HARD invalid/negative | decimal |
| 25 | Cantitate livrata (tone) | `tona_livrata` | `tona_livrata` | number | | | `compresor`, `distributie`, P+D (the FLD comment saying "compresor only" is stale) | HARD invalid/negative | decimal. For distribution it **replaces** the loaded quantity in pricing when > 0. |
| 26 | Km efectuati | `km_dislocare` | `km_dislocare` | number step .01 | | | `compresor` only | HARD invalid/negative | decimal |
| 27 | Total Facturare (estimare) | — (div `data-role=total-preview`) | — | display | "0,00 lei" | JS / server preview | always | — | — |
| 28 | Cost/km Primar | — | — | display | | | **never shown** (`showCostPrimar=false`) | — | — |
| 29 | Cost/km Distribuție (label has an encoding glitch "Distribu?ie") | — | — | display | | | `distributie`, P+D | — | — |
| 30 | Cost/km Mixt | — | — | display | | | P+D | — | — |
| 31 | Observații | `observatii` | `observatii` | textarea | empty | — | always | HARD > 5000 chars | text / NULL |

**Fields posted but not rendered (accepted by the server anyway):**
- `status_facturare`: if posted, `facturat` / `nefacturat` / `in_curs_facturare` are accepted (C:7484-7487). Otherwise `in_curs_facturare` is used.
- `cantitate_prelevata`: validated, stored for compresor only.
- `data_cursa`: used as a fallback for `data_inceput`.

**Columns written but not entered:**
- `data_cursa` (= start date)
- `durata_cursa_minute`
- `capacitate_transport_confirmata`
- `ore_functionare` (= `ore_aspirare`)
- `pret_tarifare`, `total_facturare`, `cost_km_primar`, `cost_km_distributie`, `cost_km_mixt`, `cost_km_compresor`
- `created_by`, `created_at`, `updated_at`
- `duplicate_key`
- `tariff_version_id`, `tariff_breakdown_json` (post-commit)

### 2.3 Dynamically generated / transformed elements

- **Sentinel options**, all handled in the JS change handlers:
  - vehicle: `__show_all_vehicles__` ("➕ Alt vehicul…") and separator `__sep_vehicles__`;
  - driver: `__show_all_drivers__` ("➕ Alt sofer…") and separator `__sep__`.
- **Preserved stored option** (`data-stored-out-of-scope="1"`): only when `data-inactive-trip-id` is non-empty, i.e. Edit/phase. **Never on Add.**
- **Primar circuit selects** (#19/#20): they start with no options. JS fills them only when visible and empties them when hidden.
- **`cost-km-mixt-calculation` note**: JS creates it if missing (JS:154-160).
- **Date popover DOM**: built entirely by JS inside `[data-role=*-datetime-popover]`.
- **Labels rewritten by transport type** (from `data-default-label` / `data-primary-label` / `data-primary-km-label`):

  | Label | Rule |
  |---|---|
  | `km_cursa` | "Km agreati" for `primar` and P+D; otherwise "Km efectuati" |
  | `km_totali` | "Km efectuati" for `primar` and P+D; otherwise "Km totali" |
  | zone | "Loc descarcare" for `primar`; "Zona descarcare" for `primar_tona`; otherwise "Zona distributie" |

### 2.4 Dead or inert elements found

| Element | Where | Status |
|---|---|---|
| `[data-role="pret-calc"]` | JS:116, 5762-5836 | No element in the markup, so the whole block is a no-op |
| `[data-role="time-now"]` / `applyCurrentTime` | JS:685-695, 5983-6005 | No markup |
| `race_time_options` datalist | IDX:303-309 | Unreferenced |
| `cost-km-compresor-preview` | JS preview IIFE | No element |
| `data-active-driver-vehicle-ids` | ATT, JS:433-446 | Parsed, never used. The vehicle `title` claims a filter that does not exist. |
| `getAssignedVehicleSetForBeneficiary` | JS:2194 | Never called |
| `getDistributionRouteTemplateRule` | JS:4989 | Not used in pricing (NEEDS VERIFICATION: other callers) |
| `initCreateExpenseInRaceForm` | JS:6661 | Exits early; no `data-role="create-expense-enabled"` markup |
| `durata-cursa-hint` | FLD:262 | Hidden by CSS `.dispatcher-hover-note {display:none !important}` (style.css:4644). The text only lives in a `title` on a hidden input, so it is **invisible** |
| `mixt` transport branches | JS 1706, 5345, 5395, 5737; C:7847 | Dead (not in `TRANSPORT_TYPES`) |
| `_race_segments_panel.php` + FLD/ATT phase mode | — | Not included anywhere |

---

## 3. Conditional form states

### 3.1 What triggers state changes

`syncTransportMode()` (JS:5404-5535) runs on `tip_transport` change and once at init. It is the only function that changes field **visibility**. Beneficiary, vehicle, location and zone changes alter **option lists and auto-filled values**, but not visibility. The one exception is the Primar circuit fields, which `recalculateTotal` toggles.

`setFieldState(wrapper, field, enabled, hideWhenDisabled)` (JS:3513):
- adds/removes `d-none` and `dispatcher-field-disabled` on the wrapper;
- sets `field.disabled`;
- removes `required` when disabling, and **never re-adds it** (only explicit code does).

**Disabled fields are not submitted by the browser.** Hiding a field therefore also stops it from being posted.

### 3.2 State table (generated from JS:5421-5449, 5451-5525)

✔ = visible and enabled · ✖ = hidden and disabled (not posted) · R = JS `required` attribute (cosmetic)

| Wrapper / field | No type `''` | `primar` | `primar_tona` | `distributie` | P+D | `compresor` |
|---|---|---|---|---|---|---|
| beneficiar, tip, vehicul, sofer, start, end, data incarcare, tip marfa, observatii | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| loc_incarcare_id | ✔ R | ✔ R | ✔ R | ✔ R | ✔ R | ✖ |
| loc_plecare / loc_aspirare / loc_livrare / loc_livrare_cursa | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ R |
| km_cursa | ✔ | ✔ R, readonly* | ✔ readonly* | ✔ | ✔ R, readonly | ✖ |
| km_totali | ✖ | ✔ | ✔ | ✖ | ✔ | ✖ |
| cantitate_incarcata | ✔ | ✔ | ✔ R | ✔ R | ✔ R | ✖ |
| nr_clienti | ✖ | ✖ | ✖ | ✔ | ✔ | ✖ |
| capacitate_transport (readonly) | ✔ | ✔ | ✔ | ✔ | ✔ | ✖ |
| zona_distributie_id | ✖ | ✔ R | ✔ R | ✔ R | ✔ R | ✖ |
| loc_plecare_ruta / loc_intoarcere (circuit) | hidden | conditional† | conditional† | hidden | hidden | hidden |
| ore_aspirare | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ R |
| km_dislocare, tona_aspirata_lichida, tona_aspirata_gazoasa | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ |
| tona_livrata | ✖ | ✖ | ✖ | ✔ | ✔ | ✔ |
| Total estimare | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Cost/km Distribuție | — | — | — | ✔ | ✔ | — |
| Cost/km Mixt | — | — | — | — | ✔ | — |
| Distributie notes (location/zone) | — | — | — | ✔ (as `title` only) | ✔ | — |
| Primar notes | — | ✔ (`title` only) | ✔ | — | — | — |
| "Cost/km Distributie (calcul)" note | — | — | — | — | ✔ | — |

\* **Readonly rules for `km_cursa`:**
- readonly for primar types and P+D;
- `applyPrimaryRouteKmTariff` makes it editable (and required) **only** when the resolved Primar rule has `km_agreati_manual`.

† **Circuit visibility:** shown when `isPrimary && (beneficiary ∈ data-primary-extended-beneficiaries || resolvedRule has garaj_plecare/garaj_intoarcere)` (JS:4233-4284).

**Form-level CSS classes:**

| Class | Applied when |
|---|---|
| `dispatcher-primary-layout` | `''` / primary / distribution |
| `dispatcher-compressor-layout` | compresor |
| `dispatcher-primar-km-compact-layout` | `primar` |
| `dispatcher-circuit-order` | circuit visible (CSS flex reorder) |

**DOM reorder:** for `distributie` only, `field-data-incarcare` is moved before `field-start-datetime`. For all other types it goes back after it (JS:5472-5484).

### 3.3 Vehicle list states (JS:2538-2596, 2300-2325)

| Context | Vehicle placeholder | Options |
|---|---|---|
| No beneficiary | "-- Selecteaza mai intai beneficiarul --" | none |
| Beneficiary, type empty or not supported (`suporta_*` flag false) | "-- Tip transport indisponibil pentru beneficiar --" | none |
| Supported, but no configured vehicles | "-- Configureaza vehiculele Primar\|Compresor in Configurare transport --" / "…pentru acest tip de transport --" | none, plus "➕ Alt vehicul" |
| Normal | "-- Selecteaza --" | eligible vehicles, plus "➕ Alt vehicul (arata toate vehiculele)…" if other vehicles exist |

**Eligible vehicle set per type:**

| Type | Eligible set |
|---|---|
| Primar | union of `vehicle_ids` of active Primar rules for the beneficiary. Only the **default entry** of each Loc↔Zonă pair is used (see NEEDS VERIFICATION #1) |
| Distribution | union of `vehicle_ids` of active distribution rules in scope (`distributie` / `primar_distributie`) |
| Compresor | `configurare_compresor_vehicule` for the beneficiary |

**"Alt vehicul" expansion:**
- Appends a separator "── Alte vehicule (necesita decizie) ──", then every other vehicle.
- Choosing a non-eligible vehicle opens the **vehicle route decision modal** (IDX:2671): "nu este configurat pentru beneficiarul și tipul de transport ales":
  - "Doar pentru această cursă" → `trip`
  - "Permanent" → `permanent`
  - Closing without choosing clears the vehicle.

### 3.4 Location / zone list states (JS:4498-4594)

**Distribution types**
- **Base list:** the beneficiary's active locations and zones.
- **When the selected vehicle is covered by vehicle-scoped rules:** both lists narrow to the pairs of those rules. The narrowing cascades both ways: picking a location narrows the zones and vice versa.
- **Defaults:** load location from the vehicle's garage (name match), else the per-beneficiary vehicle default, else the global vehicle default. Zone from the per-beneficiary vehicle default, else the global one.
- **P+D only:** `km_cursa` is auto-filled from the matched route's `km_tarifare`.

**Primar types**
- **Base list:** only the locations and zones that appear in the beneficiary's active Primar rules, including **reverse pairs matched by name**.
- **Beneficiary with no Primar rules:** both lists are **empty**. A note explains why, but it is visible only as a tooltip.
- **Circuit garages:** when a departure or return garage is chosen, the lists narrow further to the routes containing it.
- **Route resolved:** `km_cursa` is auto-filled from `km_tarifare` (readonly), or made editable when the rule is manual.

**Compresor / empty type**
- Lists are restored from the initial server snapshot. The fields are hidden for compresor anyway.

### 3.5 What is preserved or cleared when the operator changes a selection

| Change | Cleared (silently, **no change event**) | Overwritten | Preserved |
|---|---|---|---|
| **Transport type** | vehicle if not eligible for the new beneficiary×type → driver list rebuilt (driver cleared if not assigned to the remaining vehicle); location/zone if not in the new scoped list; circuit selects emptied when hidden; `km_cursa` cleared if it had been auto-filled and no rule now applies | `km_cursa` (Primar/P+D route km); defaults for location/zone **only when empty** | all typed values in now-hidden fields stay in the DOM (just disabled/not posted). A stale auto-filled km **stays** when switching from Primar to distributie/compresor (JS:4406). |
| **Beneficiary** | vehicle (if not eligible) → driver; location/zone not in the new list | **load location overwritten** with the vehicle default (`applyVehicleDefaultLoadLocation(false)`); zone default only when empty | transport type (even if unsupported; vehicle list then shows "indisponibil") |
| **Vehicle** | `vehicle_config_decision` reset; driver if not assigned to the new vehicle; location/zone not in scope | capacity (always); **load location and zone overwritten** with the vehicle defaults (distribution); route km | everything else |
| **Driver** | — | — | — (also re-runs the inactive check) |
| **Load location / zone** | the other list narrows; circuit options | `km_cursa` (route km), totals | — |
| **Start date** | inactive decision cleared and re-checked | — | — |

Clearing a field programmatically does **not** dispatch `change`. Dependent listeners are invoked explicitly by the calling handler instead. The exceptions are the vehicle-decision modal dismissal and the goods-type uncheck, which do dispatch `change`.

### 3.6 State-transition map

```
[Empty form]  beneficiary='' type=''
  │  vehicle list: "Selecteaza mai intai beneficiarul"; zone, km_totali, nr_clienti hidden
  │
  ├─ select Beneficiary ─→ [Beneficiary chosen, no type]
  │       vehicle list: "Tip transport indisponibil pentru beneficiar"
  │
  └─ select Type ─────────→ one of:

[PRIMAR KM]  (primar)
   visible: loc incarcare, Loc descarcare (zone), Km agreati (readonly auto), Km efectuati (km_totali),
            cantitate (optional, not priced), capacity
   circuit garages if beneficiary.rute_primar_puncte_extinse or rule has garages
   price = route cost_cursa (fixed) OR km_agreati × pret_km
   ├─ rule with km_agreati_manual → Km agreati editable + required
   └─ no rule for pair → km cleared, readonly, SOFT error on save

[PRIMAR TONE] (primar_tona)
   as Primar km, but zone label "Zona descarcare", km label "Km efectuati"/"Km totali",
   cantitate required; price = fixed ride cost OR cantitate × pret_tona

[DISTRIBUTIE] (distributie)
   visible: Data incarcare moved FIRST, loc incarcare, zona, Km efectuati (typed), Nr clienti,
            cantitate (required), tona livrata, Cost/km Distributie
   defaults: loc/zona from vehicle; price = route rule (tarif_mod) or loc/zone/beneficiary fallback

[P+D] (primar_distributie)
   visible: as distributie + Km totali ("Km efectuati"), Km agreati (readonly from route), Cost/km Mixt,
            "Cost/km Distributie (calcul)" note; Data incarcare after start
   price = tons × ton rate + km_agreati × km rate (or fixed ride cost)

[COMPRESOR] (compresor)
   hidden: loc incarcare, zona, km, km totali, cantitate, capacity, nr clienti
   visible: Loc plecare/aspirare/livrare/inchidere (all required), Ore aspirare, Km efectuati (km_dislocare),
            tona livrata, tona lichida, tona gazoasa
   price = Σ quantity × compressor rate
   ⚠ switching back to another type leaves "Cantitate Încărcată" HIDDEN (bug, §10 U-H4)

Any type → any other type: syncTransportMode re-runs; see §3.5 for clears.
```

Nested states inside Primar:
- **Route resolved / not resolved / manual km.**
- **Circuit visible / hidden.**
- **Multi-garage variant:** picking a departure/return garage changes which rule variant applies, and therefore the km and price.

Nested state inside distribution:
- **Vehicle-scoped rules present** (lists narrowed) / not present.

---

## 4. Operator workflow

### 4.1 Main path (create a trip)

1. **Operator opens "Dispecer curse".**
   - Layout from the top: header ("Km service", "Configurare Transport"), open/incomplete trips panel, live GPS strip, then the **"Adaugă Cursă" card** (`#add-race-form`).
   - Initial form state: start date and end date = today with no time, every other field empty, vehicle list showing "Selecteaza mai intai beneficiarul".
   - Popups queued earlier appear on load: post-create expense prompt, maintenance alert (admins), flash messages.
2. **Selects Beneficiary.** The system:
   - recomputes eligible vehicles (none until a type is chosen);
   - updates the header "Configurare Transport" link with `beneficiar_edit_id=<id>`;
   - rebuilds the location and zone lists;
   - runs the inactive-resource check if a vehicle or driver is already selected.
3. **Selects Tip Transport.**
   - The form re-shapes (§3.2): labels change and fields appear or disappear.
   - The vehicle list is filtered to vehicles configured for this beneficiary × type.
4. **Selects Vehicle.**
   - If the vehicle is not configured, the route decision modal asks "this trip only" or "permanently".
   - Otherwise:
     - assigned drivers are filled in, primary assignment first (no auto-select);
     - real capacity is filled in;
     - locations and zones narrow to the vehicle's route pairs; for distribution, the vehicle's default load location and zone are applied;
     - the Primar rule variant for this vehicle is picked.
   - `inactive_resource_status` is called. If the vehicle is inactive (documents, repair, manual), the approval modal opens.
   - The "Curse deja inregistrate" day panel shows the vehicle's full history (up to 500 trips), highlighting trips on the selected start date and "similar" ones.
5. **Selects Driver.**
   - Unassigned active drivers are available through "➕ Alt sofer".
   - The inactive check runs again for the driver.
6. **Enters start, end and loading date/time.**
   - Accepted typing: `dd/mm/yyyy HH:mm`, `0830`, `8h30`, `yyyy-mm-ddTHH:mm`. A popover calendar and time spinner are also available.
   - End time may stay empty (trip in progress).
   - Changing the start date re-runs the inactive check and the day-panel highlighting.
7. **Selects Loc Încărcare and Zona / Loc descarcare.**
   - Primar: `km_cursa` ("Km agreati") is auto-filled from the route and locked. If the beneficiary uses circuits, the garage selects appear.
   - P+D: `km_cursa` is auto-filled from the distribution route.
8. **Enters quantities** (depends on type): cantitate, Km efectuati / km totali, nr clienti, tona livrata, or the compressor metrics.
   - The estimated total and cost/km update on every keystroke from the local calculation.
   - After 250–450 ms the server versioned preview overwrites them, but only for users with `tarife_transport.view`.
9. **Selects Tip marfa** (single choice in practice) and optional Observații.
10. **Clicks "Adaugă Cursă".** Client-side checks, in order:
    1. Date parse. An invalid value gets a red border and focus.
    2. Overlap/similar AJAX check:
       - overlap → blocking modal with a link to the conflicting trip;
       - similar trips on the same day → confirm modal "Da, este o cursa noua".
    3. Inactive-resource check, if a decision is needed:
       - admin: "Aproba acum" / "Aproba ulterior";
       - user: "Solicita aprobare" (vehicle only).
    4. Native POST.
11. **Server.**
    - Validation:
      - HARD errors → the page reloads with inline red messages and the form refilled.
      - SOFT errors only → the page reloads with the **"Salvezi cursa fara toate informatiile?"** modal. "Da, salveaza oricum" resubmits with `confirm_incomplete=1`, and all client checks run again.
    - Then: inactive-decision check → versioned pricing → duplicate check (warning, not saved) → overlap check (error, not saved).
    - Then the transaction.
12. **Trip stored.**
    - Vehicle odometer `km_bord += km`; revision counter `km_revizie -= km + ore×40`. Coupled tractor/trailer are updated too.
    - Audit row written.
    - Accommodation expenses rematched.
    - Tariff traceability stored.
    - Inactive approval rows created.
    - Route configuration updated if the vehicle decision was "permanent".
13. **Redirect to the list.**
    - Flash messages, in order: (approval info), (vehicle decision info), "Cursa a fost adaugata cu succes.", (similar-trip warning).
    - Modal **"Cursa a fost adaugata — Vrei sa adaugi cheltuieli pe cursa acum?"**:
      - Da → Edit `#expense-section` with `flux=cursa_noua`;
      - Nu → second modal: "Nu e cazul" (`not_applicable`) or "Nu acum" (`pending`).
    - Admins may also get the "Alertă revizie vehicul" modal.

### 4.2 Variant paths

| Path | Difference |
|---|---|
| **Live GPS "Adaugă cursă"** (LIVE:620-695) | Prefills beneficiary, type, vehicle and driver from the vehicle's latest trip, or the first valid combo. Dispatches `change` after each value. Scrolls to the form and flashes it. Uses "Alt vehicul" automatically when needed, which opens the route decision modal. Dates, locations and quantities are not prefilled. |
| **Unconfigured vehicle** | The route decision modal opens. `trip` → info flash after save. `permanent` → the vehicle is written into `configurare_compresor_vehicule`, or appended to `vehicle_ids` of the matching Primar/distribution rules. **No permission check** (§10 U-H9). |
| **Inactive resource, admin/reviewer** | "Approve now" → an `approved` approval row is created after save, flash "aprobata imediat". "Approve later" → a `pending` row. |
| **Inactive vehicle, normal user** | "Solicita aprobare" POSTs a pending request before saving. The save is then allowed and the request stays pending. |
| **Inactive driver, normal user** | The modal shows only "Inchide". The server always rejects. **Dead end** (§10 U-C2). |
| **Incomplete trip** | Saved after confirmation. It appears in "curse cu informatii lipsa" (`_open_races_panel.php`), which links to Edit with `focus=` on the missing fields. |
| **Exact duplicate** (same `duplicate_key`, a hash of 39 fields) | Not saved. Warning with "Deschide cursa #id"; the form is refilled. |
| **Resume trip ("Reia cursa")** | Not part of Add. Goes to `edit&faza=noua` (§9). |

---

## 5. Calculations and derived data

### 5.1 Inventory of calculations

| # | Value | Where | Inputs | Formula | When | Rounding / units | Stored? |
|---|---|---|---|---|---|---|---|
| C1 | Duration | JS `computeRaceDurationMinutes` 1592; server `computeRaceDurationMinutes` C:10110 | start/end date+time | `floor((end−start)/60)` min. `null` if any part is missing. JS `-1` / server error when end < start | JS: date/time input/change. Server: save | minutes | `durata_cursa_minute` (only if both times are set) |
| C2 | `data_cursa` | C:7922 | `data_inceput` | identical | save | Y-m-d | yes. It is the **business date for tariff versions** |
| C3 | Capacity | JS `syncVehicleTransportCapacity` 5197; server `getVehicleCapacityInfo` M:7787 | vehicle; for a tractor, the latest active coupled trailer | read from `vehicule.capacitate_transport` | vehicle change | t, 2 dp | server snapshot (the posted value is ignored) + `capacitate_transport_confirmata` |
| C4 | Primar km (`km_cursa`) | JS `applyPrimaryRouteKmTariff` 4406; server C:7417-7457 | Primar rule (beneficiary, Loc↔Zonă bidirectional, vehicle, garages) | `km_cursa = round(km_tarifare)` unless `km_agreati_manual` | JS: every recalculation. Server: always overrides the posted value | int | yes |
| C5 | P+D km (`km_cursa`) | JS `applyDistributionRouteKmTariff` 4464; server C:7611-7620 | distribution rule | `km_cursa = km_tarifare`. JS always overwrites; the server only fills it when the posted km is empty | same | int | yes |
| C6 | Billable tons (distribution) | JS 5560; server C:7316-7320; TPS `billableTons` 43 | `tona_livrata`, `cantitate_incarcata` | `tona_livrata > 0 ? tona_livrata : cantitate_incarcata` | | t (no kg conversion anywhere in pricing) | no (used in the total) |
| C7 | Loaded tons for the fill-grade check | C `normalizeLoadedQuantityToTons` 10228 | quantity, capacity | if `> 3×capacity` or `≥ 1000` → `/1000` (assumes kg) | save | t | no (soft warnings only) |
| C8 | **Total facturare** | §5.2 | | | | | `total_facturare` (2 dp) |
| C9 | `pret_tarifare` | C:7625-7713 | rates | Primar: fixed ride, else for `primar_tona` the ton rate (fallback km), for `primar` the km rate (fallback ton). Distribution: fixed ride, else `ton>0 ? ton : km`. Compresor: first positive of hour → km → delivered → liquid → gas | save | 2 dp | yes |
| C10 | Cost/km | §5.3 | | | | | `cost_km_*` |
| C11 | Odometer / revision km | M `buildRaceKmDistribution` 6078, `applyKmDeltaToVehicle` 10132 | `km_totali` (if > 0) else `km_cursa`; `ore_aspirare` | `km_bord += km`; `km_revizie = max(0, km_revizie − (km + round(ore×40)))`, applied to the vehicle and its active coupled tractor/trailer | save (in the transaction) | km | `vehicule.km_bord`, `vehicule.km_revizie` |
| C12 | Duplicate key | M `buildRaceDuplicateKey` 9904 | 39 normalized fields, including prices and observations | sha256 of the JSON | save | — | `duplicate_key` (UNIQUE) |
| C13 | Preview total including invoiced re-invoice | JS 5839 | `data-invoiced-refacturare-total` | total + invoiced re-invoice total (always 0 on Add) | | | no |

### 5.2 Total facturare — formulas per transport type

`R` = route rule (Primar or distribution). `ben` = the beneficiary's legacy rates (`configurare_beneficiari_transport`).

**`primar`**
- `total = R.cost_cursa` when `R.aplica_cost_cursa && R.cost_cursa > 0`.
- Otherwise `total = km_cursa × pret_km`, with fallback to `pret_tarifare` when `pret_km` is 0.
- The rate is 0 if `!suporta_primar` (server `resolveBeneficiaryRate`, C:9155).

**`primar_tona`**
- Same fixed ride cost as `primar`.
- Otherwise `total = cantitate_incarcata × pret_tona` (fallback `pret_tarifare`). Km do not contribute.

**`distributie` / P+D** (C:7715-7791; JS 5612-5653)
- Total is 0 unless both location and zone are selected (JS).
- `mode = R.tarif_mod ∈ {tona_km (default), tona, km}`. With no active route, both the ton and the km component apply.
- **Ton rate:**
  1. `R.tarif_tona` if > 0;
  2. else `resolveDistributionTonRate(loc.tarif, zona.tarif_distributie, benTon, sameName)`:
     - same location/zone name → location tariff, then zone tariff;
     - otherwise → zone tariff, then location tariff;
     - then `benTon`.
- **Km rate:** `R.cost_extra_km`, else `zona.cost_extra_km`, else `ben.pret_distributie_km`.
- **Fixed ride:** `fixed = R.aplica_cost_cursa && R.cost_cursa > 0 ? R.cost_cursa : 0`.
- **Total:** `total = fixed + (fixed>0 ? 0 : tons × tonRate) + (fixed>0 ? 0 : kmComponent)`, where `kmComponent` is:
  - `distributie`: `km_cursa × kmRate`, only when `kmRate > 0`;
  - P+D: `km_cursa × kmRate`, always. For P+D, `km_cursa` holds the route's "Km agreati".
- Server: HARD error if the beneficiary does not support the type; SOFT error if all rates are ≤ 0 and there is no ride cost.

**`compresor`** (C:7778-7790; JS 5654)
- `total = ore_aspirare×pret_ora_aspirare + km_dislocare×pret_km_dislocare + tona_livrata×pret_tona_livrata + tona_aspirata_lichida×pret_tona_aspirata_lichida + tona_aspirata_gazoasa×pret_tona_aspirata_gazoasa`.
- Server: HARD error if `!suporta_compresor`; SOFT error if all five rates are 0.

**Rounding**
- Server: `round(total, 2)`.
- JS: not rounded; displayed with `toLocaleString('ro-RO', 2 decimals)`.

### 5.3 Cost/km

The formulas are the same in JS (5694-5750) and on the server legacy path (C:7793-7869).

| Type | km split | Cost/km Primar | Cost/km Distribuție | Cost/km Mixt |
|---|---|---|---|---|
| `primar` / `primar_tona` | `kmPrimar = km_cursa` | `totalPrimar / kmPrimar` | — | = Primar |
| `distributie` | `kmDistributie = km_cursa` | — | `total / km_cursa` | = Distribuție |
| P+D | `kmPrimar = km_cursa` (agreed); `kmDistributie = max(0, km_totali − km_cursa)` | `km_cursa × kmRate / km_cursa` | `(fixed or tons×tonRate) / kmDistributie` | `total / km_totali` |
| `compresor` | — | — | — | `cost_km_compresor = total / km_dislocare` |

- All values are rounded to 2 dp. Division by 0 gives 0.
- Cost/km Primar is **never displayed**.
- When versioned pricing applies (`applyVersionedPricing`, C:2687), the stored cost/km is recomputed from the **rounded versioned total**:
  - primar types: `cost_km_primar` and `cost_km_mixt` = `total / km_cursa`;
  - `distributie`: `cost_km_distributie` and `cost_km_mixt` = `total / km_cursa`;
  - P+D: **only** `cost_km_mixt = total / km_totali`. Primar and Distribuție cost/km stay legacy-based;
  - compresor: `cost_km_compresor`.

### 5.4 Recalculation triggers (frontend)

**`recalculateTotal()`** runs on:
- `syncTransportMode` (type change, init);
- beneficiary, vehicle, load location, zone and circuit-garage change;
- `input` and `change` on `km_cursa`, `km_totali`, `cantitate_incarcata`, `zona_distributie_id`, `ore_aspirare`, `km_dislocare`, `tona_livrata`, `tona_aspirata_lichida`, `tona_aspirata_gazoasa`.

It does **not** run on date changes. The server preview does.

**Server preview IIFE:**
- form-level `input` (450 ms debounce) and `change` (250 ms);
- stale responses are discarded;
- **not run on page load**, including after a validation re-render.

### 5.5 Tariff version resolution (stored value)

`TPS::quote` resolves each rate through `resolveRate(beneficiary, component, routeRefId, data_cursa, legacy)`:

```sql
SELECT * FROM transport_tariff_versions
 WHERE rule_signature = '<ben>|<component>|<route_id or 0>'
   AND valid_from <= :d AND (valid_to IS NULL OR valid_to >= :d)
 ORDER BY valid_from DESC, id DESC LIMIT 1
```

- If no version is found, it falls back to the legacy config column (`source='legacy_config'`).
- Components:
  - beneficiary-level: `pret_km`, `pret_tona`, and the 5 compressor rates;
  - route-level: `tarif_tona`, `cost_extra_km`, `cost_cursa`.
- `applyVersionedPricing` replaces `pret_tarifare` / `total_facturare` **only if at least one component has `source==='version'`**. Otherwise the controller's legacy numbers are stored.
- After commit, a separate UPDATE writes `tariff_version_id` and `tariff_breakdown_json` (the full quote). Errors in that UPDATE are swallowed.

### 5.6 Frontend vs backend discrepancies (explicit)

| # | Situation | JS local estimate (A) | Server preview (B) — overwrites A | Stored value (C) |
|---|---|---|---|---|
| D1 | Rate date | today's legacy columns embedded at page load | version valid at `data_cursa` | legacy formula, replaced by B only if some component is versioned |
| D2 | Distribution beneficiary ton fallback | `pret_distributie_tona → pret_tona → pret_tarifare` | `pret_distributie_tona` only | `pret_distributie_tona` only |
| D3 | `distributie`/P+D with **no matching route rule** | location/zone/beneficiary fallback | **0,00 lei** (ok:true, total 0) | location/zone/beneficiary fallback. A **non-zero total is saved while 0 was shown** |
| D4 | P+D `tarif_mod` | honored | ignored (always tons + km) | honored |
| D5 | P+D route rate = 0 | falls back | no fallback (0) | falls back |
| D6 | `suporta_*` flag off | rates 0 | not checked | Primar: rate 0 (SOFT). Distribution/Compresor: HARD error |
| D7 | Vehicle-scoped distribution rules | rule containing the vehicle (score 2) > unrestricted rule (score 1) | rule containing the vehicle; otherwise, if exactly one rule exists, **that rule even if it is scoped to another vehicle**; otherwise none | if **any** rule of the beneficiary is vehicle-scoped, **unrestricted rules are excluded**. Result: fallback rates + SOFT "Combinatia … nu este configurata pentru vehiculul ales." |
| D8 | Beneficiary with no distribution rules in scope | — | — | fallback model query `getDistributionRouteRuleForBeneficiary` **does not filter by beneficiary** (it only orders by it), so it can pick another beneficiary's rule for the same Loc/Zonă ids |
| D9 | Primar direction priority | direct, then reverse | mixed (`ORDER BY id DESC`) | direct, then reverse |
| D10 | Primar: vehicle not in any variant and no unrestricted variant | name/scope fallbacks return the default entry (km and price shown) | `pickRuleForVehicle`: none, or the single rule | none → SOFT "Combinatia … nu este configurata in Setari Primar" |
| D11 | Primar garage matching | departure+return → departure → first vehicle variant; list membership | case-insensitive garage filter applied **before** the vehicle filter | departure+return → departure → return → first; exact trim match |
| D12 | P+D cost/km | Distribuție and Mixt from legacy rates | overwrites Mixt only | Distribuție/Primar cost/km stay legacy even if the total was versioned |
| D13 | Total rounding | unrounded | rounded 2 dp | rounded 2 dp; cost/km from the unrounded (legacy) or rounded (versioned) total, so values can differ by ±0.01 |
| D14 | `ore_aspirare` parsing | `parseFloat` prefix ("2h30" → 2) | regex prefix ("2h30" → 2) | strict regex: "2h30" → **HARD error** |
| D15 | Integer km/clients | `type=number step=1` | — | `(int)` cast: "12.7" → 12, "abc" → 0, no error |
| D16 | Users without `tarife_transport.view` | see A only | 403, ignored silently | C |
| D17 | Edit and phase forms | A only (no server preview) | — | C on update |
| D18 | Primar beneficiary without `suporta_primar` | rates 0 | not checked | only the vehicle-config gate catches it, and that is bypassable with `vehicle_config_decision=trip`. Saved with total 0 after the SOFT confirmation |

---

## 6. Data dependencies

All data is loaded by `indexAction` (C:1260-1431) and embedded in the page. A `PDOException` turns every list into `[]` and shows a danger flash.

| Value | Source (model method → table) | Filter | Used for |
|---|---|---|---|
| **Vehicles** | `getVehicleOptions(false)` M:224 → `vehicule` + active `vehicule_cuplaje` trailer + `vehicule_categorii_capacitate` | `tip_vehicul NOT IN (semiremorca*)`. **No status filter**: inactive vehicles are listed and handled by the approval flow | vehicle select, `data-capacitate-transport` |
| Vehicle garage | `getVehicleGarageMap(true)` M:339 → `vehicule.garaj` | `status='activ'` | default load location (name match) |
| **Drivers per vehicle** | `getDriversGroupedByVehicle()` M:281 → `soferi_vehicule` ⋈ `soferi` | **all statuses**; `is_primary DESC`, then active first, then name | driver list |
| All active drivers | `getDriverOptions(true)` M:323 → `soferi` | `status='activ'` | "Alt sofer" |
| Vehicles with an assigned driver | `getActiveVehicleIdsWithAssignedDriver()` M:252 | — | **unused by JS** |
| **Beneficiaries** | `getTransportBeneficiaries(true)` M:2922 → `configurare_beneficiari_transport` | `activ=1` | beneficiary select, `suporta_*` flags, legacy rates, `rute_primar_puncte_extinse` |
| Beneficiary pricing map | `buildBeneficiaryPricingMap` C:9049 | — | JS rates and type support |
| **Load locations** | `getLoadLocations(true)` M:364, `…ByBeneficiary` M:384, tariffs M:410 → `configurare_locuri_incarcare` | `activ=1` | load location list, `tarif` |
| **Distribution zones** | `getDistributionZones(true)` M:945, `…ByBeneficiary` M:964, tariffs M:991/1007 → `configurare_zone_distributie` | `activ=1` | zone list, `tarif_distributie`, `cost_extra_km` |
| Vehicle default location/zone | M:426, 458, 624, 656 → `configurare_locuri_incarcare_vehicule`, `configurare_zone_distributie_vehicule` | latest wins | distribution defaults |
| **Distribution route rules** | `getDistributionRouteTariffMap(true)` M:1342 → `configurare_rute_distributie` ⋈ locations ⋈ zones | `activ=1`, all scopes; key `loc|zona` → list of rules | eligibility, scoping, pricing, P+D km |
| **Primar route rules** | `getPrimaryRouteKmMap(true)` M:1775 → `configurare_rute_primar` | `activ=1`; key `ben|loc|zona` → default entry + `variants[]` | eligibility, scoping, Primar km, garages, fixed ride cost |
| Compressor vehicles | `getCompressorVehicleMapByBeneficiary` M:822 → `configurare_compresor_vehicule` | — | compressor eligibility |
| **Tariff versions** | TPS / TTM → `transport_tariff_versions` | by `rule_signature` and `data_cursa` | stored price, server preview |
| Transport types | `C::TRANSPORT_TYPES` (const C:10) | — | type select |
| Goods types | `C::GOODS_TYPES` (const C:55) | — | tip marfa |
| Inactive status | IRS → `vehicule.status`, `documente` + `configurare_costuri_documente_vehicule`, `mentenanta*`, `soferi`, `documente_soferi`, `concedii`, employment | documents and leave are evaluated against **today**; employment against the trip date (NEEDS VERIFICATION for repair/leave) | approval flow |
| Day panel | `getVehicleRaces` M:4735 (limit 500), `getRacesCreatedAfter` M:4874 | not deleted | "Curse deja inregistrate", "N curse noi" |

**Not used by the form**, despite their names:
- `BillingMonthRule`: read-time only (Centralizator, Dashboard V2).
- `RaceCompletenessService`: drives the "missing info" panel, not the save confirmation.

**Important server-side looseness:**
- `existsLoadLocation`, `existsDistributionZone` and `existsVehicle` check **existence only**. There is no `activ` filter and no semitrailer exclusion.
- No server-side check that the driver is assigned to the vehicle.

---

## 7. Validation audit

### 7.1 Frontend validation

| Trigger | Condition | Feedback | Blocking | Fields |
|---|---|---|---|---|
| blur/change on a date display | text does not parse (`parseStartDateTimeDisplayValue`) | `is-invalid` red border + `aria-invalid`, **no text message** | no (marks only) | start / end / loading |
| submit | start or end display empty or unparseable | red border + focus | **yes** | start, end (loading may be empty) |
| submit (async) | `trip_conflict_check` → `has_overlap` | modal with "Am inteles" + link to the conflicting trip | **yes** | vehicle + interval |
| submit (async) | `similar_count > 0` | modal "Da, este o cursa noua" / "Nu, verific lista" | until confirmed | vehicle, beneficiary, location, start date |
| submit (async) | conflict endpoint error, or no vehicle / no start date | none | **no (fail-open)** | — |
| selection change + submit | inactive vehicle/driver needs a decision | approval modal | **yes** on the submit path | vehicle, driver, start date |
| submit | inactive-status endpoint error | `alert('Nu s-a putut verifica statusul resurselor inactive. Incearca din nou.')` | **yes (fail-closed)** | — |
| vehicle change | vehicle not in the eligible set | route decision modal (fallback `window.confirm`, which is blocked in the embedded browser) | dismissing it clears the vehicle | vehicle |

Date display parsing accepts:
- `dd/mm/yyyy[ HH:mm]` and `yyyy-mm-dd[THH:mm]`;
- time written as `8`, `830`, `8h30` or `8:5`.

More than 2 tokens is invalid, e.g. `07/10/2026 8,30`.

**Not present on the client:**
- no `setCustomValidity` / `checkValidity`;
- no required-field check (the `required` attributes are ignored because of `novalidate`);
- no numeric range checks beyond `min="0"` (not enforced either);
- no capacity check.

### 7.2 Backend validation — HARD (blocks; form re-rendered with inline errors)

| Field (error key) | Condition | Message | Types |
|---|---|---|---|
| vehicle_id | ≤ 0 or not in `vehicule` | "Selecteaza un vehicul valid." | all |
| tip_transport | not in `TRANSPORT_TYPES` | "Tipul de transport este invalid." | all |
| data_incarcare | non-empty and invalid | "Data de incarcare este invalida." | all |
| data_inceput | empty or invalid (accepts `Y-m-d`, `d/m/Y`, `d.m.Y`, `d-m-Y`) | "Data de inceput este invalida." | all |
| data_sfarsit | invalid | "Data de sfarsit este invalida." | all |
| data_sfarsit | end < start | "Data de sfarsit trebuie sa fie dupa sau egala cu data de inceput." | all |
| ora_inceput / ora_sfarsit | invalid (`HH:MM` or `HH:MM:SS`) | "Ora de inceput este invalida." / "Ora de sfarsit este invalida." | all |
| ora_inceput | end time set without a start time | "Completeaza ora de inceput daca setezi ora de sfarsit." | all |
| ora_sfarsit | end ≤ start | "Ora de sfarsit trebuie sa fie dupa ora de inceput." | all |
| loc_plecare / loc_aspirare / loc_livrare / loc_livrare_cursa | empty | "Completeaza Loc plecare." / "…aspirare." / "…livrare." / "Completeaza Loc inchidere cursa." | compresor |
| same 4 | > 255 chars | "Campul este prea lung (maxim 255 caractere)." | all |
| loc_incarcare_id | not found | "Selecteaza un loc de incarcare valid." / "Locul de incarcare selectat nu exista." | non-compresor |
| beneficiar_id | not found or inactive | "Beneficiarul selectat nu este disponibil." | all |
| vehicle_id | vehicle not in the beneficiary×type configuration **and** `vehicle_config_decision ∉ {trip,permanent}` | "Vehiculul selectat nu este configurat pentru beneficiarul si tipul de transport ales." | all |
| driver_id | not found (any status) | "Soferul selectat nu exista." | all |
| nr_clienti / km_cursa / km_totali | < 0 | "Numarul de clienti nu poate fi negativ." / "Km efectuati nu poate fi negativ." / "Km totali nu poate fi negativ." | all |
| cantitate_incarcata, cantitate_prelevata, km_dislocare, tona_livrata, tona_aspirata_lichida/gazoasa | non-numeric or < 0 | "…este invalida." / "…este invalid." | all |
| ore_aspirare | fails the regex, or < 0 | "Ore aspirare este invalid (ex: 2 sau 2h)." | all |
| zona_distributie_id | not found | "Zona de distributie selectata este invalida." / "…nu exista." | all |
| loc_incarcare_id | location's beneficiary ≠ selected one | "Pentru Primar, selecteaza un loc de incarcare configurat pentru beneficiarul ales." / "Pentru distributie, …" | primar / distribution |
| zona_distributie_id | zone's beneficiary ≠ selected one | "Pentru Primar, selecteaza o zona configurata pentru beneficiarul ales." / "Zona de distributie selectata nu este configurata pentru beneficiarul ales." | primar / distribution |
| tip_marfa | some values valid, some invalid | "Selecteaza doar tipuri de marfa valide." | all |
| beneficiar_id | `!suporta_distributie` / `!suporta_primar_distributie` | "Beneficiarul selectat nu este configurat pentru transport distributie." / "…Primar+Distributie." | distribution |
| beneficiar_id | `!suporta_compresor` | "Beneficiarul selectat nu este configurat pentru transport Compresor." | compresor |
| observatii | > 5000 chars | "Observatiile sunt prea lungi." | all |
| inactive_resources | inactive vehicle/driver without a valid decision (non-reviewers: without their own pending request) | "Utilizarea resurselor inactive necesita alegerea unei aprobari: …" (yellow alert at the top of the form) | all |
| *(store, not validation)* `duplicate_key` already exists | | "Exista deja o cursa salvata cu aceleasi detalii (ID n). …" (warning, form refilled) | all |
| *(store)* overlap | same vehicle, intervals intersect, both trips have a start time | "Vehiculul X este deja pe cursa #N in acest interval (…). Acelasi vehicul nu poate fi in doua curse in acelasi timp — …" (danger, form refilled) | all |

### 7.3 Backend validation — SOFT (incomplete; save allowed after confirmation)

| Key | Condition | Message | Types |
|---|---|---|---|
| loc_incarcare_id | empty | "Locul de incarcare nu este selectat." | non-compresor |
| beneficiar_id | empty | "Beneficiarul de transport nu este selectat." | all |
| driver_id | empty | "Soferul nu este selectat." | all |
| tip_marfa | no valid value | "Tipul de marfa nu este selectat." | all |
| zona_distributie_id | empty | "Locul de descarcare nu este selectat." | primar types |
| zona_distributie_id | no Primar rule for the pair | "Combinatia selectata Loc ↔ Zona nu este configurata in Setari Primar pentru beneficiarul ales." | primar types |
| km_cursa | manual rule and km empty | "Km agreati nu sunt completati pentru ruta Primar selectata." | primar types |
| km_cursa | rule has `km_tarifare ≤ 0` | "Km efectuati nu sunt configurati in Setari Primar pentru combinatia selectata." | primar types |
| km_cursa | km empty | "Km agreati (tarifare) nu sunt completati." | `primar` |
| cantitate_incarcata | empty | "Cantitatea incarcata nu este completata (necesara facturarii pe tone)." | `primar_tona` |
| cantitate_incarcata | vehicle has no real capacity (qty > 0) | "Vehiculul nu are capacitate de transport reala completata, …" | all |
| cantitate_incarcata | capacity not confirmed | "Capacitatea reala a vehiculului (X t) nu este inca verificata, deci gradul de umplere de Y% este orientativ. …" | all |
| cantitate_incarcata | **qty > capacity + 0.001** | "Cantitatea incarcata (X t) depaseste capacitatea reala a vehiculului (Y t): grad de umplere Z%." | all (overload is **never** blocking) |
| cantitate_incarcata | empty (and no tona_livrata) | "Cantitatea incarcata nu este completata (necesara facturarii distributiei)." | distribution |
| zona_distributie_id | empty | "Zona de distributie nu este selectata." | distribution |
| zona_distributie_id | vehicle-scoped rules exist, but none for this vehicle | "Pentru vehiculul selectat nu exista perechi de ruta configurate (Loc ↔ Zona)." | distribution |
| zona_distributie_id | no rule for the pair and vehicle | "Combinatia selectata Loc ↔ Zona nu este configurata pentru vehiculul ales." | distribution |
| beneficiar_id | no valid Primar rate and no ride cost | "Beneficiarul selectat nu are tarife valide pentru transport primar." | primar types |
| zona_distributie_id | no valid distribution rate | "Nu exista un tarif valid pentru distributie (Loc incarcare, Zona sau Cost extra km)." | distribution |
| beneficiar_id | all 5 compressor rates are 0 | "Beneficiarul selectat nu are tarife valide pentru transport Compresor." | compresor |

When HARD errors exist, the SOFT messages are merged into the inline field errors and no modal is shown; HARD wins on a key collision. One message is kept per field key; a later rule overwrites an earlier one.

### 7.4 Frontend vs backend validation mismatches

1. **Asterisks do not match the server.**
   - The UI shows `*` on Beneficiar, Sofer, Loc Încărcare and Tip marfa. The server treats all four as **SOFT**, so they can be saved empty after confirmation.
   - "Data si ora sfarsit *": the **end time** is optional on the server.
   - Zona has no `*` but is SOFT-required for primar and distribution.
   - `ore_aspirare` gets a JS `required` but is optional on the server.
2. **No client-side required checks at all.** Every error costs a full page round trip.
3. **The server accepts things the UI never offers:**
   - inactive locations and zones;
   - semitrailer and inactive vehicle ids;
   - any driver;
   - a posted `status_facturare=facturat`;
   - a posted `zona_distributie_id` for compresor (stored, beneficiary not checked).
4. **Integer fields:** `type=number step=1` on the client; silent `(int)` truncation on the server.
5. **Overlap timing:** checked client-side before the incomplete round trip, but server-side **after** it. An operator can confirm "save incomplete" and only then get the overlap error.
6. **Similar trips:** a confirm modal on the client, but only a post-save warning on the server. It never blocks.
7. **Inactive driver for a non-reviewer:** the client offers no action ("Inchide" only) and the server rejects permanently.
8. **`inactive_approval_signature`** is enforced only by JS.

### 7.5 Combination rules (summary)

- **Vehicle configuration.** `vehicle ∈ configured(beneficiary, type)` OR `vehicle_config_decision ∈ {trip, permanent}`. The configured set is:
  - Primar: the union of the beneficiary's active `configurare_rute_primar.vehicle_ids`;
  - distribution: the union of `configurare_rute_distributie.vehicle_ids` in scope;
  - compresor: `configurare_compresor_vehicule`.

  Rules with empty `vehicle_ids` contribute **nothing**. A beneficiary whose rules are all unrestricted therefore rejects every vehicle unless a decision is given.
- **Beneficiary ownership.** `location.beneficiar_id == beneficiary` and `zone.beneficiar_id == beneficiary` (primar, distribution).
- **Type support.** The type must be supported by the beneficiary flags: HARD for distribution and compresor; for Primar only indirectly, through rate = 0.
- **Quantity vs capacity.** SOFT warning only.
- **Interval vs other trips of the same vehicle.** Blocking. It only runs when the new trip has a start time; existing trips without a start time are ignored, and **phases are not considered**.
- **Exact duplicate.** All business fields equal, including price and observations: blocking.

---

## 8. Submission lifecycle

### 8.1 Request

- `POST /index.php?page=dispecer_curse&action=store`, `application/x-www-form-urlencoded`, native (not AJAX).
- **Payload:** every enabled named input:
  - `_token`, `vehicle_config_decision`, `inactive_approval_decision`, `inactive_approval_signature`, `confirm_incomplete`, `confirm_similar`;
  - `beneficiar_id`, `tip_transport`, `vehicle_id`, `driver_id`;
  - `data_inceput` / `data_sfarsit` / `data_incarcare` (dd/mm/yyyy), `ora_inceput` / `ora_sfarsit` (HH:MM);
  - type-dependent fields per §3.2 (disabled fields are omitted);
  - `tip_marfa[]`, `loc_plecare_ruta` / `loc_intoarcere` (when the circuit is visible), `observatii`;
  - `capacitate_transport` (ignored by the server).
- **Double submit:** the APP guard (`dataset.submitting`, which disables the submit buttons) acts only on the final native submit. The async checks have no in-flight guard.

### 8.2 Server sequence (C:2202-2328)

| # | Step | Effect on failure |
|---|---|---|
| 0 | `syncTariffLegacyValues()`: writes the legacy tariff columns for the posted beneficiary, **before** the CSRF check | logged, ignored |
| 1 | Method must be POST | redirect |
| 2 | CSRF `_token` | flash "Token CSRF invalid. Reincearca operatiunea." + redirect |
| 3 | `validateRaceInput($_POST, false)`: sanitize and normalize (§2, §7) | — |
| 4 | Read `vehicle_config_decision` ∈ {trip, permanent} | — |
| 5 | HARD errors | `setFormFlash('race_create', $old, $errors + $softErrors)` → redirect, inline errors |
| 6 | SOFT errors and `confirm_incomplete !== '1'` | session list → redirect → incomplete modal |
| 7 | Inactive resources that need a decision | `formErrors['inactive_resources']` → redirect |
| 8 | `applyVersionedPricing` (TPS quote by `data_cursa`) | exception logged, legacy values kept |
| 9 | Set `created_by`, `created_at`, `updated_at` | — |
| 10 | `findDuplicateRaceId` (also runs the ensure-schema DDL and the `duplicate_key` backfill) | warning flash + link, form refilled, **not saved** |
| 11 | `findOverlappingRace` | danger flash + link, form refilled, **not saved** |
| 12 | `findSimilarRaces` (skipped if `confirm_similar=1`) | warning after save |
| 13 | **Transaction** `createRaceAndSyncVehicleKm` (M:5101), in order: `INSERT curse_dispecer` → `SELECT … FOR UPDATE` + `UPDATE vehicule SET km_bord, km_revizie` (vehicle + coupled) → `INSERT cursa_audit_log(action='created')` → COMMIT. The `cursa_audit_log` and `curse_segmente` schemas are ensured before BEGIN | rollback + exception → catch (step 20) |
| 14 | `queueMaintenancePopupAlerts` (admins only; `km_revizie` crossed 0) | session |
| 15 | `rematchAccommodationExpenses()`: scans **all** unassociated `cheltuieli_cazare`, re-associates them by driver and date, upserts the mirror rows in `curse_cheltuieli` / `curse_cheltuieli_documente` | errors swallowed |
| 16 | `persistTariffTraceability`: `UPDATE curse_dispecer SET tariff_version_id, tariff_breakdown_json` | errors swallowed |
| 17 | `createForInactiveResources` → `inactive_resource_approvals` (+ document snapshots), `approved` or `pending` | **not caught individually**: an exception reaches step 20 *after* the trip was committed |
| 18 | `vehicle_config_decision`: `permanent` → `applyPermanentVehicleRouteConfig` (INSERT into `configurare_compresor_vehicule`, or UPDATE the `vehicle_ids` CSV of matching `configurare_rute_primar` / `configurare_rute_distributie` rules; no transaction, no lock, **no permission check**). `trip` → info flash | errors → warning flash |
| 19 | `setPostCreateExpensePrompt($id,'created')`, success flash, similar warning | — |
| 20 | `catch (Throwable)` | duplicate-key violation → warning; anything else → danger "A aparut o eroare la salvare…" (or a schema message); form refilled |
| 21 | `redirect('?page=dispecer_curse')` | — |

**Generated values:**
- `id`: AUTO_INCREMENT.
- `status_facturare`: `in_curs_facturare`.
- `cheltuieli_status`: DB default `pending`.
- `duplicate_key`: sha256.
- `created_by`: the current user.

No notifications are sent from PHP. Pending approvals are polled by `notification_service/approval_flow.py`.

### 8.3 Failure behavior — what survives a round trip

**Restored from `$old`:**
- beneficiary, type, vehicle, driver;
- dates and times;
- location and zone;
- compressor text fields;
- goods type;
- all quantities and km (Primar `km_cursa` already overridden by the route);
- observations.

**Lost:**
- `vehicle_config_decision` (hard-coded `""`);
- `confirm_similar`;
- `inactive_approval_signature`;
- the **chosen Primar departure garage**: the select reads `formData['loc_plecare']`, but the value was posted as `loc_plecare_ruta`;
- an **"Alt sofer" driver**: the server renders only assigned drivers;
- an **"Alt vehicul" vehicle**: the JS rebuild drops it, because the expansion is collapsed and the add form does not preserve out-of-scope values;
- the server tariff preview, which does not run again until the operator interacts.

**Errors:**
- validation → inline, per field;
- duplicate / overlap / exception → flash only, no field errors.

### 8.4 Success behavior

- Redirect to the list.
- Flash messages: approval info/warning, vehicle-decision info/warning, "Cursa a fost adaugata cu succes.", similar-trip warning.
- Expense prompt modal:
  - Da → Edit `#expense-section`;
  - Nu → "Nu e cazul" / "Nu acum", posted to `update_expense_status` (needs `dispecer_curse.expenses`).
- Maintenance modal (admins).
- The Add form is empty again (defaults).

---

## 9. Add / Edit / Phases / Duplicate — shared vs unique

### 9.1 Overview

| Workflow | Markup | JS | Server action | Validation | Notes |
|---|---|---|---|---|---|
| **Add** | IDX form + **FLD** (`trip` mode) + **ATT** | `initRaceForm` + versioned preview IIFE | `store` → `storeAction` | `validateRaceInput($_POST, false)` (inactive beneficiary rejected) | Only form with `vehicle_config_decision`, the "Alt vehicul" decision modal and the server tariff preview |
| **Edit** | **EDT:539-1017, a hand-maintained duplicate** of the FLD/ATT markup. It does NOT include the partials | `initRaceForm` (same code) | `update` → `updateAction` + `mergeRaceUpdateData` | `validateRaceInput($input, false, true)` (inactive beneficiary allowed) | Today the `data-role` and `name` sets are identical to Add (77 roles), apart from the edit-only `tariff-recalc-block` and the hidden `cursa_id`/`segment_id`/`segment_origin` |
| **Phases** ("Reia cursa", `edit&faza=noua|<id>`) | EDT in phase mode: same visible fields, action switched to `segment_store` / `segment_update` | `initRaceForm` | `storeRaceSegmentAction` / `updateRaceSegmentAction` | `validateRaceSegmentInput` (different rules, see below) followed by `repriceRaceFromSegments` → `validateRaceInput` | `_race_segments_panel.php` (which uses FLD in phase mode) is **not included anywhere**, so the FLD/ATT phase branches are dead code |
| **Duplicate trip** | — | — | — | — | **No such feature.** Only duplicate detection (`duplicate_key`) |
| **Live GPS prefill** | uses Add | LIVE + `window.dispecerVehicleCombos` | `store` | as Add | Sets 4 fields by id (`race_*`) |
| **Fleet Assistant API / SAS sandbox prefill** | — | — | — | — | Read-only, or sandbox only. Neither writes trips through this form |

### 9.2 Edit vs Add differences that a redesign must respect

**Option lists and selects**
- **Beneficiaries:** Edit lists inactive beneficiaries as well, labelled "(inactiv)".
- **Tip transport:** Edit has no placeholder and defaults to `primar` when the stored value is empty.
- **Drivers:** Edit keeps the stored driver as an out-of-scope option. This happens because `data-inactive-trip-id` is non-empty.

**Field state**
- When the trip has more than one phase, these fields are read-only with the note "Se calculează din faze": `km_cursa`, `km_totali`, `cantitate_incarcata`, `nr_clienti`, `tona_livrata`.
- Previews start from the stored totals.
- The "facturat" lock notice appears.

**Pricing behaviour**
- The update only reprices trips that are not `facturat`. Invoiced trips keep their financial fields frozen; every other field stays editable.
- The "lostTariff" safety net restores the stored total when the recomputed one is ≤ 0.
- `status_facturare` is forced to the stored value. Store, by contrast, accepts the posted value.
- `data_cursa` is never changed after creation.

**Vehicle configuration**
- On update, the vehicle-config gate is bypassed by injecting `vehicle_config_decision='trip'`, but only when the vehicle is unchanged.
- Edit has no modal. Changing to an unconfigured vehicle therefore fails with a HARD error.

**Merge semantics**
- **Omitted POST fields keep their stored values** (`mergeRaceUpdateData`).
- A field that is newly hidden or disabled in Edit therefore stops being *cleared*. It keeps its old value instead.

**Redirect**
- After a successful update, the user is sent back to the list.

### 9.3 Phase (segment) rules

**`validateRaceSegmentInput`**
- Only checks that `vehicle_id` and `driver_id` are greater than 0.
- No existence check, no configuration gate, no inactive check.
- Start date is required. End date is optional, but if given it must be ≥ start. The phase start must be ≥ the trip start.
- Phase km = the first positive value among `km`, `km_dislocare`, `km_totali`, `km_cursa`.
- No SOFT errors, and no duplicate, overlap or similar check on the server.
- On error, the typed input is lost (flash message only).

**Fields that are discarded**
- In phase mode, EDT still shows beneficiary, type, goods, loading date and the compressor tonnages.
- `validateRaceSegmentInput` ignores them, so changes made to them there are **silently discarded**.

**Roll-up to the trip** (`refreshRaceFromSegments`)
- Dates and times: first and last phase.
- Duration.
- Summed km (into `km_totali` for primar and P+D, otherwise into `km_cursa`), quantity, `tona_livrata`, `nr_clienti`, hours.
- `duplicate_key`.
- Vehicle and driver on the trip row are **not** changed.

**Reprice** (`repriceRaceFromSegments`)
- Skipped for invoiced trips.
- Aborts silently on any HARD validation error. Example: a vehicle accepted "doar pentru aceasta cursa" fails the config gate on reprice, so the tariff stays stale.
- Does **not** refresh `tariff_version_id` / `tariff_breakdown_json`.

### 9.4 Shared pieces and their blast radius

| Shared piece | Used by | Risk if changed for Add |
|---|---|---|
| `initRaceForm` (JS 62-6659) | Add, Edit (trip mode), Edit (phase mode) | Renaming a `data-role` or `name` breaks Edit unless EDT is updated by hand as well |
| FLD / ATT partials | Add only (plus the dead phase panel) | Edit does **not** inherit changes, so Add and Edit drift apart |
| `validateRaceInput` | store, update, segment reprice | A change in rules affects all three. `vehicle_config_decision` semantics affect reprice |
| `trip_conflict_check`, `inactive_resource_status`, `races_activity` | Add, Edit (and phase mode for conflict) | `data-inactive-trip-id` changes behaviour: preserving stored options, excluding the trip from conflict checks |
| Incomplete-confirm inline script | IDX:2767 and EDT:2300, both `document.querySelector('form.dispatcher-race-form')` | Rendering any other race form earlier in the DOM breaks the confirmation |

---

## 10. UX audit of the current implementation

Severity scale: **CRITICAL** = can cause wrong billing data or block work entirely. **HIGH** = frequent friction or silent data loss. **MEDIUM** = slows entry or confuses. **LOW** = cosmetic or dead code.

### CRITICAL

| ID | Issue | Why it creates friction / risk |
|---|---|---|
| U-C1 | **"Total Facturare (estimare)" can differ from the saved total** (§5.6 D1-D18). Examples: Distribuție/P+D without a matching route rule shows **0,00 lei** but saves a fallback total. Users without `tarife_transport.view` only see the legacy estimate. P+D `tarif_mod` is ignored by the preview. | Operators trust the preview to check billing. A mismatch is invisible until invoicing. |
| U-C2 | **A normal user cannot save a trip with an inactive driver.** The modal offers only "Inchide", and the server rejects the save on every attempt (C:8803-8815, 2234-2241). Requesting approval exists only for vehicles. | Hard dead end with no explanation of how to proceed. |
| U-C3 | **An error after commit looks like a failed save.** An exception while creating approvals (or in later steps) shows the red "A aparut o eroare la salvare" and refills the form, even though the trip is already saved. | The operator re-submits. The duplicate check catches identical data, but if they change anything (e.g. observations) a real duplicate is created. |

### HIGH

| ID | Issue | Why |
|---|---|---|
| U-H1 | **Errors appear only after a full page reload.** There are no client-side required checks; the `*` marks are decorative (`novalidate`). | Slow feedback loop. Scroll position and context are lost on every error. |
| U-H2 | **The asterisks do not match the real rules.** Beneficiar, Sofer, Loc încărcare and Tip marfă are `*` but can be saved empty. The end time is `*` but optional. Zona has no `*` but is needed for billing. | Operators cannot tell what is truly required. |
| U-H3 | **Choices made in the "incomplete" confirmation round trip are lost**: the vehicle route decision, the "Alt sofer" driver, the "Alt vehicul" vehicle and the Primar departure garage (§8.3). | After confirming "salveaza oricum", the save can fail again with "Vehiculul selectat nu este configurat…", or a driver or garage silently disappears. |
| U-H4 | **"Cantitate Încărcată" disappears after switching Compresor → another type** (`setFieldState(..., true, false)` never removes `d-none`, JS:5435-5439). | A required billing field becomes invisible. The operator thinks the form is broken. |
| U-H5 | **Silent clearing on cascading changes.** Changing beneficiary or type clears the vehicle, driver, location or zone without any notice. Changing the vehicle **overwrites** the load location and zone the operator had picked (distribution). | Accidental data loss and confusion about why a field went blank. |
| U-H6 | **P+D with no km on the route: `km_cursa` is readonly, empty and "required".** The operator cannot type it. | Blocked input with no explanation. The save gets a SOFT error, or bills 0 km. |
| U-H7 | **Primar beneficiary with no Primar routes: empty Loc/Zonă dropdowns.** The explanation exists only as a hover `title`, because the notes are hidden by CSS. | Looks broken. The fix (Configurare Transport) is not discoverable. |
| U-H8 | **Overlap is checked after the incomplete confirmation on the server.** The client check is fail-open on network errors. | The operator confirms "save incomplete", then gets an overlap error, which is the wrong order of questions. |
| U-H9 | **"Permanent" vehicle decision writes route configuration with no permission check.** Configurare Transport itself is `admin_only`. | Any operator can change tariff-relevant configuration from a trip form, probably without realizing it. |
| U-H10 | **Invalid date gives a red border with no message.** `07/10/2026 8,30` is rejected while `8,30` alone is accepted. | The operator does not know what format is expected. |
| U-H11 | **Server preview does not run after a validation re-render** and only appears after a debounce; the local value flashes first. | The total shown on reload may be the less reliable one. The number "jumps". |

### MEDIUM

| ID | Issue | Why |
|---|---|---|
| U-M1 | Labels change meaning by type. "Km efectuati" is `km_cursa` for distributie, `km_totali` for primar/P+D, and `km_dislocare` for compresor. The zone label is one of three different names. | The same words map to different fields, which is error-prone when switching type. |
| U-M2 | Field order changes. "Data incarcare" jumps before the start date only for `distributie`. Circuit fields reorder the grid via CSS `order`. | Layout shifts that make the operator think something broke. Muscle memory fails. |
| U-M3 | Transport type is a free list of 5 even when the beneficiary supports fewer. An unsupported choice is signalled only in the vehicle placeholder ("Tip transport indisponibil pentru beneficiar"). | Error discovered late, in an unrelated field. |
| U-M4 | Beneficiary must be chosen before type, but the DOM allows any order. Choosing type first leaves the vehicle list empty with "Selecteaza mai intai beneficiarul". | Hidden dependency. |
| U-M5 | "Tip marfa" is rendered as a multi-select dropdown but behaves as single-select. | Misleading control and extra clicks. |
| U-M6 | The duration hint is computed but never visible (CSS `display:none !important`; text only in the title of a hidden input). | The operator has no confirmation of the computed trip duration. |
| U-M7 | Hover-only notes for routing rules (`dispatcher-hover-note` is hidden; text is copied into `title`). | Important routing explanations are effectively invisible, and do not work on touch devices. |
| U-M8 | "Alt vehicul" / "Alt sofer" are sentinel options inside the select. Picking "Alt vehicul" resets the vehicle to empty and needs a second selection. | Extra clicks and an unexpected reset. |
| U-M9 | Post-save stacking: up to 4 flashes plus the expense modal plus the maintenance modal in one page load. | Notification overload. Important warnings (similar trip) get lost. |
| U-M10 | Datetime picker: picking a day does not move on to the time; picking only a time silently fills today's date. Fallbacks use `window.confirm`/`alert`, which are blocked in the embedded browser. | Slower entry. Unexpected date. Broken fallback in embedded contexts. |
| U-M11 | The "Curse deja inregistrate" panel re-downloads the vehicle's full history (up to 500 trips) every 30 s and on window focus. | Performance cost, especially on mobile and slow links. |
| U-M12 | The similar-trip check never blocks on the server. If the AJAX failed, the warning appears only **after** the save. | Duplicate trips slip through. |
| U-M13 | Integer km fields silently truncate decimals or turn garbage into 0. | Wrong km without any feedback. |
| U-M14 | The form is shown to users without `create`. The 403 comes only on submit. | Wasted data entry. |
| U-M15 | A stale auto-filled Primar km stays in `km_cursa` after switching to `distributie`. | A wrong km value carries over to another type. |

### LOW

| ID | Issue |
|---|---|
| U-L1 | Encoding glitches: "Cost/km Distribu?ie" (FLD:445), "Loc ? Zona" in notes (FLD:278, 281, 368, 371). |
| U-L2 | Dead elements: `pret-calc`, `time-now`, `race_time_options` datalist, `cost-km-compresor-preview`, `data-active-driver-vehicle-ids`, `mixt` branches, `initCreateExpenseInRaceForm`. |
| U-L3 | Cost/km Primar is computed but never shown. Cost/km Compresor is stored but never previewed. |
| U-L4 | The vehicle select `title` claims a filter ("vehicule active cu sofer asociat") that does not exist. |
| U-L5 | Keyboard: no navigation inside the calendar grids, and the custom dropdown (tip marfă) is not a native control. ArrowDown opens the picker and Escape closes it. |
| U-L6 | Stale FLD comment says `tona_livrata` is compressor-only. It is also used for distribution. |

---

## 11. Operator decision points

| Class | Inputs | Effect |
|---|---|---|
| **PRIMARY DECISIONS** (radically change the form) | **Beneficiar**, **Tip Transport** | Set the eligible vehicles, valid transport types, location/zone universe, field set, required set, labels and pricing formula. |
| **PRIMARY (data pivot)** | **Vehicle** | Sets the drivers, capacity, default locations/zones, the route-rule variant (km and price), inactive approvals, the day panel, and the route decision for an unconfigured vehicle. |
| **SECONDARY DECISIONS** (reveal or narrow small groups) | Loc încărcare ↔ Zonă / Loc descărcare (route pair) | Resolve the route rule. They also set km (Primar/P+D), the fixed ride cost, the tariff mode, and whether the garage selects appear. |
| | Loc plecare (garaj) / Loc întoarcere (garaj) | Pick the Primar route variant, and with it the km and price. |
| | "Alt vehicul" → *doar pentru această cursă* / *permanent* | Bypasses the configuration gate. "Permanent" also changes the configuration. |
| | Inactive approval (admin: acum / ulterior; user: solicită) | Allows saving and creates approval records. |
| | Similar-trip confirmation, incomplete confirmation | Allow saving. |
| **DATA ENTRY** | Driver, dates and times, data încărcare, cantitate, km efectuați (distributie), km totali, nr clienți, tona livrată, compressor locations and metrics, tip marfă, observații | Plain values. Some feed pricing (§5). |
| **AUTO-CALCULATED** (the operator should not have to reason about these) | Km agreați (Primar/P+D route km), capacitate reală, durata, total facturare, cost/km (Primar/Distribuție/Mixt/Compresor), billable tons (delivered vs loaded), `data_cursa`, `loc_plecare` / `loc_intoarcere` stored from the route | Computed in JS and/or on the server. The server always recomputes. |
| **SYSTEM INFORMATION** (better displayed than entered) | Vehicle capacity and whether it is confirmed, fill grade, route rule found/missing and its km/price, tariff version and validity date, inactive status and reasons, the vehicle's existing trips that day, overlap/similar trips, km to revision, configuration gaps ("vehicle not configured", "no tariff") | Today scattered across tooltips, hidden notes, modals and post-save flashes. |

---

## 12. UI elements that MUST NOT be changed blindly

| # | Element / contract | Where | Risk |
|---|---|---|---|
| 1 | **Init gate:** `[data-role="tip-transport"]`, `[name="beneficiar_id"]`, `[data-role="total-preview"]` must exist in each race form | JS:162 | If any one is missing, `initRaceForm` returns early and **all** form behavior disappears: filtering, pricing, dates, approvals, conflict checks. |
| 2 | Form class `.dispatcher-race-form` | JS:6914; IDX:2767; EDT:2300 | The JS boots on it. The incomplete-confirm script targets the **first** such form in the DOM. |
| 3 | Form `action` containing `action=store` | JS:6936 | The versioned server preview binds only to it. |
| 4 | `name` attributes (`beneficiar_id`, `tip_transport`, `vehicle_id`, `driver_id`, `data_inceput`, `ora_inceput`, `data_sfarsit`, `ora_sfarsit`, `data_incarcare`, `loc_incarcare_id`, `zona_distributie_id`, `km_cursa`, `km_totali`, `cantitate_incarcata`, `nr_clienti`, `tona_livrata`, `ore_aspirare`, `km_dislocare`, `tona_aspirata_*`, `loc_plecare`, `loc_aspirare`, `loc_livrare`, `loc_livrare_cursa`, `loc_plecare_ruta`, `loc_intoarcere`, `tip_marfa[]`, `observatii`) | FLD, EDT, C | The server reads these exact keys. Update merges "missing = keep stored". Segment km take the first positive of `km`, `km_dislocare`, `km_totali`, `km_cursa`. |
| 5 | Hidden inputs `vehicle_config_decision` (with `data-vehicle-config-decision`), `inactive_approval_decision`, `inactive_approval_signature`, `confirm_incomplete`, `confirm_similar` (`data-trip-similar-confirm-flag`), `_token` | IDX:297-302 | The server gates depend on them. The **presence** of `data-vehicle-config-decision` enables "Alt vehicul". Adding it to Edit changes Edit's behavior. |
| 6 | `data-inactive-trip-id` (empty on Add) | IDX:296, EDT:567 | Non-empty means stored out-of-scope options are preserved and the trip is excluded from conflict and inactive checks. |
| 7 | ~77 `data-role` hooks (field wrappers `field-*`, inputs `km`, `km-totali`, `cantitate`, `zona`, `ruta-plecare`, …, labels `km-label`, `zona-label`, notes, previews, date parts) | FLD, EDT, JS | JS finds every field by these, and visibility is toggled on the **wrapper** roles. They must be kept identical in Add and Edit. |
| 8 | Label data attributes `data-default-label`, `data-primary-label`, `data-primary-km-label`; `data-default-text` (duration); `data-initial-value` (circuit selects) | FLD | JS rewrites labels from them and restores the garages from them. |
| 9 | Vehicle `<option data-capacitate-transport>` | FLD:121 | Capacity autofill, and the snapshot of the master vehicle list. |
| 10 | **Option snapshots taken at init** (`initialVehicleOptions`, `initialLoadLocationOptions`, `initialZoneOptions`) | JS:408-431 | Options added to the DOM after init are invisible to the filters. Lists must be fully rendered before `initRaceForm` runs. |
| 11 | Sentinel values `__show_all_vehicles__`, `__sep_vehicles__`, `__show_all_drivers__`, `__sep__`; any value starting with `__` is treated as not real | JS:462-472; LIVE:666-673 | The expansion logic and the GPS prefill depend on them. |
| 12 | `disabled` on hidden fields (not just `display:none`) | `setFieldState` | Disabled fields are **not posted**. That is how irrelevant type fields are excluded server-side. Merely hiding them would post stale values. Making them non-disabled in Edit would change the merge results. |
| 13 | `required` toggling | JS:5486-5525 | No validation effect today (because of `novalidate`). Removing `novalidate` without care would make the browser enforce attributes that are wrong in places (e.g. the P+D readonly km). |
| 14 | `readonly` `km_cursa` / `capacitate_transport` | JS:5465, FLD:337 | Readonly inputs **are** posted. The server overrides Primar km and capacity anyway, but the P+D km is taken from the post when non-empty. |
| 15 | CSS classes toggled by JS: `d-none`, `dispatcher-field-disabled`, form classes `dispatcher-primary-layout`, `dispatcher-compressor-layout`, `dispatcher-primar-km-compact-layout`, `dispatcher-circuit-order`; `is-invalid` on date displays | JS, style.css:4618, 4875-4972 | Visibility and the grid order depend on them. `d-none` on wrappers is the *only* hide mechanism (see U-H4). |
| 16 | Grid assumption: fields are direct children of `.row.g-3` with a `col-md-6` class; the wrapper classes `dispatcher-top-field`, `-schedule-field`, `-primary-grid-field`, `-compressor-grid-field`, `-compressor-metric-field` | style.css:4875-4900 | Widths and ordering break. |
| 17 | DOM hierarchy: `field-data-incarcare` and `field-start-datetime` must share a parent (reorder); the date popover must sit inside `[data-role=*-datetime-field]` (outside-click detection); goods label via `checkbox.closest('label').querySelector('span')`; `[data-bs-toggle=dropdown]` inside the goods dropdown | JS:5476, 1314, 4731, 4749 | Silent malfunction. |
| 18 | Element types: date toggles must be `<button>`, hidden date/time must be `<input>`, vehicle and driver must be `<select>` | JS:706-728, 2722 | The picker context returns null and the picker is skipped. |
| 19 | Hidden date format **dd/mm/yyyy** | JS:584, 6253; C:10061 | The day panel splits on `/`. The server accepts both formats, but the JS "selected day" match does not. |
| 20 | IDs `#add-race-form`, `race_beneficiar_id`, `race_tip_transport`, `race_vehicle_id`, `race_driver_id`; global `window.dispecerVehicleCombos` (the last initialised form wins) | LIVE:128, 640-645; JS:2332 | The GPS prefill breaks if the prefix or ids change, or if another race form is initialised after the Add form. |
| 21 | Modal hooks found with `document.querySelector`: `[data-vehicle-route-decision-modal]` (+`-name`, `-trip`, `-permanent`), `[data-inactive-resource-modal]` and ~13 children, `[data-trip-overlap-modal]` (+`-message`, `-link`), `[data-trip-similar-modal]` (+`-list`, `-confirm`); IDs `#incompleteTripConfirmModal`, `#postCreateExpensePromptModal`, `#postCreateExpenseChoiceModal`, `#kmRevizieAlertModal` | IDX, partials | The modals must exist page-wide, outside the form. |
| 22 | Bootstrap loaded in the footer **after** the view scripts; modal instances created on `DOMContentLoaded` | footer.php:10; JS:2601, 2688 | Moving the init earlier breaks every modal and falls back to `confirm()`/`alert()`, which are blocked in the embedded browser. |
| 23 | The submit listener is in the **capture phase** and stops propagation while it defers; the global APP double-submit guard runs in the bubble phase | JS:6557; APP:227 | Changing the phase or adding another submit handler can double-submit or skip the checks. |
| 24 | Day panel `[data-race-day-panel]` and its children; `[data-race-new-toggle]`, `[data-race-new-panel]`; `data-races-activity-url`/`-since` | IDX:450, 474-554; ATT | Activity polling and rendering. |
| 25 | Vehicle option text format `"PLATE - Marca Model"` | JS:6207 | Used for the day-panel context label. |
| 26 | The server-rendered `$formErrors` blocks (`invalid-feedback d-block`) and the top alert for `inactive_resources` | FLD:73-80 and per field | The only display of server validation. |
| 27 | AJAX URLs in ATT (`data-trip-conflict-check-url`, `data-inactive-*`, `data-races-activity-*`) and the JSON config attributes (19 blobs) | ATT:20-24; EDT duplicates them | The JS reads all configuration from them. Edit builds them separately, so a change must be made twice. |

---

## 13. Form behavior specification

*Implementation-independent description of what the Add Trip form does. Source of truth for the redesign.*

### Trip Creation Form

#### Entry conditions
- The user is logged in and has `dispecer_curse.view`. Submitting requires `dispecer_curse.create`.
- On open, the form is pre-filled:
  - start date and end date = today, no times;
  - status "in curs de facturare" (implicit, not shown);
  - everything else empty.
- The form can also be pre-filled from:
  - a failed or confirmation round trip (all old values);
  - the live-GPS "Adaugă cursă" action (beneficiary, type, vehicle, driver).

#### Global fields (all types)
- Beneficiar
- Tip transport
- Vehicul
- Șofer
- Start date+time
- End date+time
- Data încărcare
- Tip marfă
- Observații
- Total estimate

#### Transport selection
- The five types are listed in §0.
- A type is usable only if the beneficiary's support flag is set:
  - `suporta_primar` covers both Primar types;
  - `suporta_distributie`;
  - `suporta_primar_distributie`;
  - `suporta_compresor`.
- Changing the type re-shapes the field set (§14), re-filters the vehicles, re-scopes locations and zones, re-applies route km, and recalculates the price. Values in fields that become irrelevant are not sent.

#### Primar workflow (`primar` km / `primar_tona`)
- **Location and zone** ("Loc descărcare" for km, "Zona descărcare" for tone) come only from the beneficiary's Primar route pairs. The pairs match in both directions (by id, and by name in reverse).
- **The route rule is resolved** from beneficiary + pair + vehicle (+ departure/return garage). It provides:
  - agreed km (`km_tarifare`), filled in and locked;
  - or, for a manual rule, km that the operator must type;
  - an optional fixed ride cost;
  - the departure and return garages that will be stored.
- **Garage selects** appear when the beneficiary uses extended route points, or when the rule defines garages.
- **Price:**
  - `primar`: fixed ride cost, or agreed km × beneficiary km rate;
  - `primar_tona`: fixed ride cost, or loaded quantity × beneficiary ton rate.
- **Km totali** ("Km efectuați" for km) and quantity are optional data. Km totali feeds the odometer.

#### Distribuție workflow
- The loading date is shown before the start date.
- Location and zone come from the beneficiary. When the vehicle is covered by vehicle-scoped route rules, both narrow to those pairs.
- The vehicle supplies defaults for location (garage name match first) and zone.
- Typed fields: km efectuați, nr clienți, quantity, delivered tons. Delivered tons, if greater than 0, replace the loaded quantity for billing.
- Price comes from the route rule (tariff mode ton/km/ton+km, or a fixed ride cost). Without a rule it falls back to location → zone → beneficiary rates.
- Cost/km Distribuție is shown.

#### P+D workflow
- Same as Distribuție, plus:
  - agreed km is auto-filled from the distribution route and locked;
  - Km totali ("Km efectuați") is typed.
- Price = tons × ton rate + agreed km × km rate, or a fixed ride cost.
- Shown: distribution km = km totali − agreed km, Cost/km Distribuție, Cost/km Mixt (total / km totali), and an explanatory note.

#### Compresor workflow
- **Hidden:** load location, zone, km, km totali, quantity, capacity, nr clienți.
- **Required text fields:** loc plecare, loc aspirare, loc livrare, loc închidere cursă.
- **Optional metrics:** ore aspirare ("2" / "2h"), km efectuați (relocation), delivered tons, liquid tons, gas tons.
- **Price** = Σ metric × beneficiary compressor rate.
- **Effects:** one aspiration hour counts as 40 km for the revision counter. Stored cost/km = total / km dislocare.

#### Vehicle logic
- **List:** non-trailer vehicles configured for beneficiary × type (Primar/distribution route rules, or the compressor list).
- **"Alt vehicul"** shows all the others. Choosing one requires a decision:
  - *this trip only*;
  - *permanent*, which adds the vehicle to the configuration.

  Dismissing the decision clears the vehicle.
- **Selecting a vehicle:**
  - loads its assigned drivers;
  - sets the real capacity (taken from the coupled trailer for tractors);
  - sets the distribution defaults;
  - selects the route variant;
  - triggers the inactive-resource check and the existing-trips panel.

#### Driver logic
- The list shows drivers assigned to the vehicle (primary assignment first, all statuses). "Alt șofer" adds all active drivers for this trip.
- The driver is not auto-selected (except in the GPS prefill when exactly one exists).
- Selecting a driver triggers the inactive check.
- A missing driver is a soft (confirmable) error.

#### Customer / beneficiary logic
- Only active beneficiaries can be chosen.
- The beneficiary determines the allowed types, vehicles, locations, zones, route rules and rates.
- Missing is a soft error. Inactive or unknown is a hard error.

#### Cargo / product logic
- One goods type: butan, propan or autogaz. It is stored as CSV (the column allows several).
- Missing is a soft error.

#### Capacity logic
- Capacity is display-only. It comes from the vehicle record; the server re-reads it and stores a snapshot together with a "confirmed" flag.
- Loaded tons above capacity, missing capacity, or unconfirmed capacity produce soft warnings that show the fill grade. They never block.

#### Quantity logic
- Loaded quantity, in tonnes, with no unit conversion in pricing.
- Delivered tons override loaded tons for distribution billing.
- Required (soft) for primar_tona, distribuție and P+D.

#### Kilometer logic
- `km_cursa`:
  - Primar: agreed km from the route;
  - P+D: agreed km from the route;
  - distribuție: typed;
  - compresor: not used.
- `km_totali` is the typed total for Primar types and P+D.
- `km_dislocare` is used by compresor.
- Odometer increment = `km_totali` if greater than 0, else `km_cursa`. The revision counter decreases by the same km plus 40 × hours, on the vehicle and its coupled units.
- Integers only (decimals are truncated).

#### Pricing / tariff logic
- The stored price comes from the tariff version valid on the trip start date (`data_cursa`), per component. If no component is versioned, the legacy configuration formula (§5.2) is used.
- Stored values: unit price, total (2 dp), cost/km per type, tariff version id, and the full breakdown JSON.
- The estimate shown while typing must not contradict the stored value (today it can — §5.6).

#### Road / access charges
- **Not part of the Add form.** Road taxes, accommodation and other expenses are added after creation from Edit (`#expense-section`, prompted by the post-create modal).
- On every trip creation, unassigned accommodation expenses are automatically re-matched to trips by driver and date.

#### Date / time logic
- Start date is required; start time is optional.
- End date is required (default today); end time is optional (trip in progress).
- End ≥ start. An end time requires a start time.
- Duration in minutes is derived when both times exist.
- The loading date is optional.
- Accepted typing formats: §7.1.

#### Multi-segment logic
- Not available at creation. A trip becomes multi-phase through "Reia cursa" in Edit.
- Phases roll up dates, km, quantities and hours into the trip and trigger a reprice.

#### Automatic calculations
- See §5.1: duration, data_cursa, capacity, route km, billable tons, total, unit price, cost/km, odometer and revision, duplicate key.

#### Validation
- Blocking and confirmable rules: §7.2 / §7.3.
- Pre-submit checks: date parse; same-vehicle time overlap (block); same day + vehicle + beneficiary + location (confirm); inactive vehicle/driver (approval).

#### Save operation
- One POST. Server order: §8.2.
- One DB transaction (trip + vehicle km + audit), followed by the post-commit steps (tariff trace, accommodation rematch, approvals, optional configuration change).

#### Error states
- Inline field errors with the form refilled.
- "Incomplete" confirmation modal.
- Inactive-resources alert.
- Duplicate warning with a link.
- Overlap error with a link.
- Generic or schema error flash.
- CSRF error.
- 403 for no permission.

#### Success state
- Redirect to the list with a success flash, plus optional approval, decision and similar-trip messages.
- The expense prompt modal appears. The maintenance modal appears for admins.
- The Add form resets.

#### Permissions
- `dispecer_curse.create` is required to save.
- `inactive_approvals.review` lets the user approve inactive resources immediately or later.
- Non-reviewers can request approval for vehicles only.
- `tarife_transport.view` enables the accurate server price preview.
- `dispecer_curse.expenses` is required for the post-save "Nu e cazul / Nu acum" choice.

#### Database effects
- **INSERT** `curse_dispecer` (39+ columns).
- **UPDATE** `vehicule.km_bord`, `km_revizie` (vehicle + coupled).
- **INSERT** `cursa_audit_log`.
- **UPDATE** `curse_dispecer.tariff_version_id`, `tariff_breakdown_json`.
- **UPSERT** `cheltuieli_cazare` / `curse_cheltuieli` / `curse_cheltuieli_documente` (rematch).
- **INSERT** `inactive_resource_approvals` (+documents).
- Optional **INSERT** `configurare_compresor_vehicule` or **UPDATE** `configurare_rute_primar` / `configurare_rute_distributie.vehicle_ids`.
- Pre-save writes of the legacy tariff sync (`syncTariffLegacyValues`).
- Runtime schema-ensure DDL, plus two every-request backfill UPDATEs (`cost_km_compresor`, `duplicate_key`).
- No triggers.

---

## 14. Behavior matrix

**R** = required (server HARD) · **S** = required for completeness (server SOFT: saving needs confirmation) · **O** = optional · **A** = automatic (system-filled or derived) · **H** = hidden / not applicable (not posted)

| Field / function | Primar km | Primar tone | Distribuție | P+D | Compresor |
|---|---|---|---|---|---|
| Beneficiar | S | S | S | S | S |
| Tip transport | R | R | R | R | R |
| Vehicul (+ config gate) | R | R | R | R | R |
| Șofer | S | S | S | S | S |
| Data început | R | R | R | R | R |
| Ora început | O | O | O | O | O |
| Data sfârșit | R (default today) | R | R | R | R |
| Ora sfârșit | O | O | O | O | O |
| Data încărcare | O | O | O (shown first) | O | O |
| Loc încărcare | S | S | S | S | H |
| Zonă / Loc descărcare | S | S | S | S | H |
| Loc plecare / aspirare / livrare / închidere (text) | H | H | H | H | R |
| Loc plecare (garaj) / Loc întoarcere (garaj) | A/O* | A/O* | H | H | H |
| Tip marfă | S | S | S | S | S |
| Cantitate încărcată | O (not priced) | S | S† | S† | H |
| Capacitate reală | A | A | A | A | H |
| Km agreați / efectuați (`km_cursa`) | A (route; S if manual) | A (route; S if manual) | O (typed) | A (route) | H |
| Km totali (`km_totali`) | O ("Km efectuați") | O ("Km totali") | H | O ("Km efectuați") | H |
| Nr. clienți | H | H | O | O | H |
| Cantitate livrată (tone) | H | H | O (overrides quantity) | O (overrides quantity) | O |
| Ore aspirare | H | H | H | H | O |
| Km efectuați (`km_dislocare`) | H | H | H | H | O |
| Tonă lichidă / gazoasă aspirată | H | H | H | H | O |
| Observații | O | O | O | O | O |
| Total facturare (estimare) | A | A | A | A | A |
| Cost/km Primar | A (not shown) | A (not shown) | H | A (not shown) | H |
| Cost/km Distribuție | H | H | A | A | H |
| Cost/km Mixt | A (not shown) | A (not shown) | A (not shown) | A | H |
| Cost/km Compresor | H | H | H | H | A (stored, not shown) |
| Route rule resolution | A | A | A | A | H |
| Vehicle defaults (location/zone) | H | H | A | A | H |
| Capacity soft warnings | A | A | A | A | H (quantity hidden) |
| Inactive approval check | A | A | A | A | A |
| Overlap check (needs start time) | A | A | A | A | A |
| Similar-trip check (needs load location) | A | A | A | A | H (no load location) |
| Odometer / revision update | A | A | A | A | A (+40 km/h) |

\* Visible when the beneficiary has `rute_primar_puncte_extinse` or the resolved rule has garages. The value picks the route variant.
† Soft-required unless "Cantitate livrată" > 0.

---

## 15. Interaction dependency graph

```
Beneficiar
├── determines → allowed Tip transport (suporta_* flags)
├── determines → Location universe (configurare_locuri_incarcare.beneficiar_id)
├── determines → Zone universe (configurare_zone_distributie.beneficiar_id)
├── determines → Route rules (Primar / Distribuție / P+D) and Compressor vehicle list
├── determines → legacy rates + tariff versions (pricing)
├── flag rute_primar_puncte_extinse → Garage selects (Primar)
└── updates → "Configurare Transport" header link

Tip transport
├── Primar km / Primar tone
│   ├── field set: Loc încărcare, Loc/Zona descărcare, Km agreați(A), Km totali, Cantitate, Capacitate
│   ├── Location/Zone options = Primar route pairs (bidirectional, by name)
│   ├── Vehicle list = vehicles in Primar rules
│   ├── Pair + Vehicle + Garages → Primar rule variant
│   │   ├── km_tarifare → Km agreați (locked) | manual → typed
│   │   ├── cost_cursa (fixed) → Total
│   │   └── garaj_plecare / garaj_intoarcere → stored loc_plecare / loc_intoarcere
│   └── Total = fixed | km × pret_km (km) | qty × pret_tona (tone)
├── Distribuție
│   ├── field set: Data încărcare(first), Loc, Zona, Km efectuați, Nr clienți, Cantitate, Tona livrată
│   ├── Vehicle list = vehicles in distribution rules (scope distributie)
│   ├── Vehicle → default Loc/Zona, vehicle-scoped pairs
│   ├── Pair + Vehicle → distribution rule (tarif_mod, tarif_tona, cost_extra_km, cost_cursa)
│   └── Total = fixed | tons×tonRate (+ km×kmRate if kmRate>0); tons = livrată>0 ? livrată : încărcată
├── P+D
│   ├── as Distribuție + Km totali; Km agreați(A from route km_tarifare)
│   ├── Total = fixed | tons×tonRate + kmAgreati×kmRate
│   └── Cost/km Distribuție = distrib. part / (km totali − km agreați); Cost/km Mixt = total / km totali
└── Compresor
    ├── field set: 4 location texts (R), Ore aspirare, Km dislocare, Tona livrată/lichidă/gazoasă
    ├── Vehicle list = configurare_compresor_vehicule
    └── Total = Σ metric × compressor rate; revision km += 40 × ore

Vehicul
├── Șofer options (soferi_vehicule; "Alt șofer" = all active)
├── Capacitate reală (tractor → coupled trailer) → capacity soft warnings
├── Route variant selection (Primar) / vehicle-scoped pairs (Distribuție, P+D)
├── Default Loc încărcare (garage name → per-beneficiary default → global default) and Zona
├── Not configured → decision: trip | permanent (writes config)
├── Inactive check (documents/repair/manual) → approval flow
└── Existing trips panel (history, same-day, similar)

Șofer ── Inactive check (documents/leave/employment) → approval flow (reviewers only can proceed)

Data început
├── data_cursa → tariff version date
├── Inactive check reference date (employment)
├── Overlap check (with times) / Similar check (same day)
└── Duration (with end)

Loc încărcare ↔ Zonă
├── narrow each other (route pairs)
├── → route rule → km (Primar, P+D), price, garages
└── Similar check (Loc încărcare)

Quantities (cantitate, km, ore, tone) ── Total estimate + Cost/km (live) ── server preview (debounced)

Submit ── date parse → overlap/similar → inactive → POST
       └─ server: validate (HARD/SOFT) → inactive → price → duplicate → overlap → TX → post-commit
```

**Natural groupings for a redesign** (derived from the graph, not a design):
1. *Who and what*: Beneficiar → Tip transport.
2. *Resources*: Vehicul → Șofer (+ capacity, inactive status, existing trips).
3. *When*: start, end, loading date.
4. *Route*: Loc încărcare ↔ Zonă (+ garages) → resolved rule.
5. *Type-specific measures*: quantities and km per type.
6. *Result*: total and cost/km + tariff source.

---

## 16. Behavior that MUST survive the redesign

Each line is written as a testable requirement (acceptance test).

### Shape and visibility
1. **Given** type = `compresor`, **then** Loc încărcare, Zonă, Km (`km_cursa`), Km totali, Cantitate, Capacitate and Nr clienți are not submitted, and Loc plecare/aspirare/livrare/închidere, Ore aspirare, Km dislocare and the three tonnage fields are shown.
2. **Given** type = `distributie` or P+D, **then** Nr clienți and Cantitate livrată are shown. **Given** a Primar type, **then** they are not submitted.
3. **Given** a Primar type or P+D, **then** Km totali is shown. **Given** `distributie` or `compresor`, **then** it is not submitted.
4. **Given** no transport type, **then** Zonă, Km totali and Nr clienți are not submitted.
5. **Given** type = `primar`, **then** the zone field is labelled "Loc descarcare". Given `primar_tona`, "Zona descarcare". Otherwise "Zona distributie".
6. `km_cursa` is labelled "Km agreati" for `primar` and P+D, and "Km efectuati" otherwise. `km_totali` is labelled "Km efectuati" for `primar` and P+D, and "Km totali" otherwise.
7. Switching between any two types and back restores visibility for every field of the final type, including "Cantitate Încărcată" after leaving Compresor. *(This fixes today's bug U-H4. The intended behavior is "visible".)*
8. Fields irrelevant to the selected type are never submitted. Their old values must not reach the server.

### Option filtering and defaults
9. With no beneficiary, the vehicle list offers no vehicles and says to choose a beneficiary first.
10. A type that the beneficiary does not support (`suporta_*` = 0) yields no eligible vehicles, and the operator is told the type is unavailable for this beneficiary.
11. The eligible vehicles are exactly:
    - Primar: the vehicles in the beneficiary's active Primar rules;
    - distribution / P+D: the vehicles in the active distribution rules of that scope;
    - compresor: the beneficiary's compressor vehicles.

    Semitrailers are never listed.
12. "Alt vehicul" exposes all other vehicles (Add only). Selecting one requires a decision *trip* / *permanent*. Dismissing the decision clears the vehicle. *Permanent* adds the vehicle to the matching configuration after save.
13. Driver options are the drivers assigned to the selected vehicle, primary first. "Alt șofer" adds all active drivers. Without a vehicle, no driver can be chosen.
14. Selecting a vehicle sets "Capacitate transport reala" from the vehicle record. For a tractor, it comes from the actively coupled trailer.
15. Distribution types: selecting a vehicle sets Loc încărcare to the location whose name equals the vehicle's garage, else to the configured vehicle default (per beneficiary, then global). It sets Zona to the configured default. Changing the beneficiary or type fills these only when they are empty.
16. Primar types: Loc încărcare and Zonă offer only the pairs of the beneficiary's active Primar rules, matched in both directions.
17. Distribution types with vehicle-scoped rules covering the selected vehicle: Loc încărcare and Zonă offer only those pairs, and each narrows the other.
18. Primar: when the beneficiary has extended route points, or the resolved rule defines garages, the departure/return garage choices appear. The chosen garage selects the route variant, and therefore the km and price.

### Auto-calculation
19. Primar: when a non-manual rule resolves, `km_cursa` equals the rule's `km_tarifare` and is not editable. When the rule is manual, `km_cursa` is editable and required. When no rule resolves, `km_cursa` is cleared.
20. P+D: `km_cursa` equals the matched distribution route's `km_tarifare`.
21. The server always overrides the Primar `km_cursa` with the route km (unless the rule is manual), and always re-reads capacity from the DB.
22. The total, unit price and cost/km stored for a trip equal `TransportPricingService::quote` at `data_cursa` when any component is versioned, and the legacy formula of §5.2 otherwise. They are rounded to 2 decimals.
23. For distribution, delivered tons > 0 replace loaded tons in pricing.
24. A fixed ride cost (`aplica_cost_cursa` and `cost_cursa` > 0) replaces the calculated total for Primar and distribution.
25. Cost/km values follow §5.3. Distribution km for P+D = max(0, km_totali − km_cursa).
26. `durata_cursa_minute` = whole minutes between start and end when both times exist.
27. `data_cursa` = `data_inceput` on creation, and is never changed later.
28. The total estimate updates without reload when beneficiary, type, vehicle, location, zone, garage or any priced quantity changes. *(Redesign target: the estimate must equal the stored value — see U-C1.)*

### Validation
29. Every HARD rule in §7.2 blocks saving with its message on the relevant field.
30. Every SOFT rule in §7.3 lets the operator save only after an explicit "save anyway" confirmation that lists all missing items.
31. End before start, or an end time without a start time, is rejected.
32. A vehicle not configured for beneficiary × type is rejected unless the operator chose *trip* or *permanent*.
33. A location or zone belonging to another beneficiary is rejected for Primar and distribution types.
34. Quantity above capacity produces a warning with the fill grade, never a block.
35. A trip whose interval overlaps another non-deleted trip of the same vehicle (both with a start time) is not saved, and the operator gets a link to the other trip.
36. A trip identical in all business fields to an existing non-deleted trip is not saved, and the operator gets a link to the existing trip.
37. Before saving, the operator is asked to confirm when trips exist on the same day with the same vehicle, beneficiary and load location. Confirming saves the trip.
38. An inactive vehicle or driver (documents, repair, leave, employment, manual) requires an approval decision:
    - reviewers: approve now (approved record) or later (pending record);
    - non-reviewers: request approval for a vehicle (pending record).

    Without a decision, the server refuses to save.
39. If the inactive-status check fails, the trip is not submitted (fail-closed). If the conflict check fails, the trip is submitted and the server re-checks overlap.

### Persistence
40. Saving inserts one `curse_dispecer` row with `status_facturare='in_curs_facturare'`, `created_by` = the user, `duplicate_key`, the capacity snapshot and the computed prices.
41. In the same transaction, the vehicle's (and coupled units') `km_bord` increases by `km_totali` (or `km_cursa`), and `km_revizie` decreases by that km + 40 × aspiration hours (floored at 0). An audit row `created` is written.
42. When versioned pricing applied, `tariff_version_id` and `tariff_breakdown_json` are stored.
43. Inactive approval records are linked to the new trip.
44. After saving, unassigned accommodation expenses are re-matched to trips.
45. Goods type is stored as a comma-separated list of the allowed keys.
46. For non-compresor trips, `loc_plecare` / `loc_intoarcere` are stored from the Primar route rule's garages, not from free text.
47. After a failed save, every entered value is restored. *(Redesign target: this includes the vehicle decision, the "Alt" driver or vehicle, and the garage — see U-H3.)*
48. After a successful save, the operator is returned to the list, sees "Cursa a fost adaugata cu succes.", and is asked whether to add expenses (Da → expenses section of the new trip; Nu → "Nu e cazul" / "Nu acum"). Admins are alerted when a vehicle reaches 0 km to revision.
49. Saving requires `dispecer_curse.create`, and CSRF is enforced.

### Compatibility
50. The Edit form and the phase form keep working with identical field names and behavior hooks. Omitted fields on update keep their stored values. Phase km are read from `km` / `km_dislocare` / `km_totali` / `km_cursa`.
51. The live-GPS "Adaugă cursă" action can still pre-fill beneficiary, type, vehicle (including an unconfigured vehicle through the decision flow) and driver.
52. The "curse cu informatii lipsa" deep links (`edit&focus=…`) still focus the right fields in Edit.

---

## 17. Open items — NEEDS VERIFICATION

| # | Item | Where to check |
|---|---|---|
| 1 | Primar vehicle eligibility uses only the **default entry** of each Loc↔Zonă pair (`buildPrimaryRouteRulesByBeneficiary` ignores `variants`). Vehicles listed only in non-default variants may be missing from the vehicle list, which conflicts with the "Rute Primar multi-garaj" behavior. The server (`collectPrimaryVehicleIdsForBeneficiary`) uses all rules. | JS:1850-1901, 2248-2268; C:9282 |
| 2 | Quantity wrapper staying hidden after Compresor → other type. Confirmed by reading the code; not yet reproduced in a browser. | JS:5435-5439 |
| 3 | Circuit selects with no fallback may be left with `selectedIndex = -1` and post nothing. | JS `fillCircuitSelect` 4193-4231 |
| 4 | Whether the vehicle route decision modal reliably finds Bootstrap at init. | JS:2601 |
| 5 | Whether repair, leave and document inactivity should use the trip date instead of today. | IRS `getVehicleRepairIssue`, `getDriverLeaveIssue`, document checks |
| 6 | Whether an inactive driver can be saved by a non-reviewer through any other flow (`getPendingForRequesterResourceContext`). | C:8876-8910; IRA:409-435 |
| 7 | Whether the requester's earlier pending vehicle approval (`trip_id NULL`) is ever linked to the new trip. | C:2288-2304; IRA |
| 8 | The router's permission for AJAX actions not listed in PERM `endpoints`. | `includes/access.php` `require_route_access` |
| 9 | The exact output keys of `getPrimaryRouteKmMap` beyond what the JS uses. | M:1775-1830 |
| 10 | `getDistributionRouteTemplateRule`: any caller. | JS:4989 |
| 11 | Inline driver/vehicle pickers on the trips list (the memory notes mention them). No routed endpoint was found in `handle()`. They may have been removed or moved. | C:273-429; `race-view-overlay.js` |
| 12 | Whether non-reviewers are expected to bypass the "Alt vehicul → permanent" configuration write (no ACL today). | C:1590-1702, 2305 |
| 13 | Behavior of `syncTariffLegacyValues` running before CSRF/POST checks: is it safe on GET `action=store`? | C:259-271, 307-310 |

---

## 18. Browser Runtime Validation

*Added 2026-10-07 after testing the running local application in a real browser. Sections 0–17 above were written from the code alone, so they are the **hypothesis**; this section records what actually happened at runtime and does not rewrite the sections above. Where the two disagree, the runtime result wins and is stated explicitly. Environment, method and the full narrative are in [TRIP_FORM_BROWSER_TEST_REPORT.md](TRIP_FORM_BROWSER_TEST_REPORT.md); screenshots are in `docs/trip-form-browser-test/`.*

**Legend.** Result: **PASS** = the runtime behavior matches the static audit · **PARTIAL** · **FAIL** = the static audit was wrong or incomplete · **NOT TESTABLE**.
A *bug confirmed exactly as the audit predicted* is a **PASS of the audit**, but the product severity still applies.

### 18.1 Runtime test log

#### BRT-001 — Initial state of "Adaugă Cursă"
- **Preconditions:** admin session; fresh load of `?page=dispecer_curse`.
- **Steps:**
  1. Open the page.
  2. Inspect the form before touching it.
- **Expected from static audit:**
  - start and end date = today, no time;
  - every select empty;
  - vehicle placeholder "Selecteaza mai intai beneficiarul";
  - zone, km totali and nr clienți hidden;
  - circuit selects hidden with `selectedIndex -1`;
  - total 0,00 lei.
- **Actual:**
  - Exactly as expected.
  - Also: the form starts 321 px down the page (1680×930), below the page header, the "curse cu informații lipsă" alert and the GPS live panel.
  - `races_activity` polls every 30 s and `live_gps` every 60 s from load, even with no vehicle selected.
  - No console errors.
- **Result:** PASS
- **UX observation:** before anything is selected, Loc Încărcare already lists every beneficiary's locations, with duplicate names (Contesti ×2, Lugoj ×2, Tileagd ×3).
- **Evidence:** —
- **Related code:** IDX:289-325, FLD, C:6745
- **Severity:** LOW (duplicates)

#### BRT-002 — Primar km happy path and stored price
- **Preconditions:** ButanGas, vehicle B 605 NET (28), driver Ene Daniel.
- **Steps:**
  1. Beneficiar → Primar km → vehicle → driver.
  2. Dates 15/12/2026 08:00–20:00.
  3. BGR Navodari → Contesti.
  4. Km efectuați 640, Propan.
  5. Save.
- **Expected:**
  - Km agreați = route km, read-only.
  - Total = 630 × 1,27.
  - Odometer increases by km_totali.
- **Actual:**
  - Km agreați 630, read-only. Local total 800,10, then server preview 800,10.
  - Stored `total_facturare` 800.10, `tariff_version_id` 91.
  - `km_bord` +640, `km_revizie` −640; audit row `created`.
  - Saved directly (no incomplete prompt); the expense prompt appeared.
  - The "curse cu informații lipsă" counter still went 124 → 125.
- **Result:** PASS
- **UX observation:** the driver is not auto-selected although the vehicle has exactly one driver.
- **Evidence:** 01-primar-km-route-resolved.jpg
- **Related code:** JS 4406, C:2202, M:5101

#### BRT-003 — Loc ↔ Zonă narrowing (Primar)
- **Preconditions:** BRT-002 state.
- **Steps:**
  1. With Loc and Zonă both selected, try to change Loc to Brazi.
  2. Clear Zonă and retry.
  3. Clear Loc.
- **Expected from static audit:** each list narrows the other (§3.4).
- **Actual:**
  - With both selected, **each dropdown contains only its own current value**, so Brazi cannot be chosen.
  - Clearing Zonă still leaves Loc with a single option.
  - The operator must reset **Loc** to "-- Selecteaza --" to see alternatives.
  - Going A→B→A works only via that reset; km is cleared and refilled.
- **Result:** FAIL (audit incomplete: the self-narrowing lock was not documented)
- **UX observation:** a hidden rule; the operator thinks the list is broken.
- **Related code:** JS `getScopedPrimaryOptions` 3833, `getScopedDistributionOptions` 3733
- **Severity:** HIGH (UX FRICTION)

#### BRT-004 — Primar tone state
- **Steps:** switch the BRT-002 form to Primar tone.
- **Expected:**
  - zone label "Zona descarcare";
  - quantity required;
  - total = quantity × pret_tona.
- **Actual:**
  - All values kept.
  - Zone label "Zona descarcare". `km_cursa` stays **read-only 630 but is now labelled "Km efectuati"**; `km_totali` is labelled "Km totali".
  - Total 0,00 (ButanGas has no ton rate); local and server agree.
- **Result:** PASS
- **UX observation:** the route's agreed km is labelled "Km efectuati" in this type, which is misleading.
- **Severity:** MEDIUM

#### BRT-005 — Distribuție state, delivered-tons override, server preview
- **Steps:**
  1. ButanGas / Distribuție / vehicle 28.
  2. BGR Navodari → Moldova, quantity 18.
  3. Then Cantitate livrată 12.
- **Expected:**
  - Data încărcare moves first.
  - nr clienți and tona livrată appear.
  - Delivered tons replace loaded tons in billing.
- **Actual:**
  - As expected: 18 × 85 = 1.530,00, then with delivered 12, 12 × 85 = 1.020,00.
  - Local and server agree.
  - Data încărcare jumps to the first slot of the dates row.
- **Result:** PASS
- **Related code:** JS 5560, C:7316

#### BRT-006 — Transitions between populated types (9 transitions)
- **Steps:** see report §7, a full transition table with before/after diffs.
- **Expected:** §3.5.
- **Actual:** confirmed:
  - selections are cleared silently;
  - hidden fields are disabled and not posted;
  - typed P+D km is overwritten by the route km.

  Plus **four behaviors the audit did not predict** (BRT-007, -008, -009, -010).
- **Result:** PARTIAL
- **Severity:** HIGH

#### BRT-007 — Compresor → other type: Cantitate Încărcată (NEEDS VERIFICATION #2)
- **Preconditions:** a quantity (18) was entered while in Distribuție.
- **Steps:**
  1. Distribuție (qty 18) → Compresor.
  2. Compresor → Distribuție (also → Primar km, → P+D).
- **Expected from code:** the field stays hidden (`d-none` not removed).
- **Actual:**
  - Confirmed for → Distribuție, → Primar km and → P+D.
  - **Worse than expected:** the input is hidden but **enabled**, so its stale value (18) is **submitted and priced**. With Distribuție, Sud and no delivered tons, the total became 1.260,00 lei = 18 × 70, computed from a field the operator cannot see. `FormData` contains `cantitate_incarcata=18`.
- **Result:** PASS of the audit (bug confirmed), with an aggravating detail
- **Classification:** BUG + DATA INTEGRITY RISK
- **Related code:** JS 5435-5439, `setFieldState`
- **Severity:** CRITICAL

#### BRT-008 — Stale route km keeps pricing after leaving the route
- **Steps:**
  1. Primar (630 km auto-filled).
  2. → Distribuție → Compresor (vehicle 23).
  3. → Primar km.
- **Expected from static audit:** "a stale autofilled km stays" (U-M15).
- **Actual:** after returning to Primar km with **no vehicle and no Loc descărcare**:
  - `km_cursa` = 630, **read-only**, so the operator cannot correct it;
  - Total shows **800,10 lei**.

  In Distribuție the stale 630 also fed Cost/km Distribuție (1.530 / 630 = 2,43).
- **Result:** PARTIAL (the audit rated it MEDIUM; at runtime it produces a confident price for an incomplete trip)
- **Evidence:** 07-compresor-to-primar-stale-total-hidden-quantity.jpg
- **Severity:** HIGH (BUG)

#### BRT-009 — Stale delivered tons priced in Compresor
- **Steps:** Distribuție with Cantitate livrată 16 → Compresor.
- **Actual:** the Compresor total immediately shows 1.120,00 lei (16 × 70, `pret_tona_livrata`) before any compressor data is entered. `tona_livrata` is shared between the two types and keeps its value.
- **Result:** FAIL (not documented)
- **Severity:** MEDIUM (BUG / UX)

#### BRT-010 — Distribuție → P+D overwrites typed km
- **Actual:** the typed Km efectuați 250 is replaced by the route's 630, read-only, without warning. Going back to Distribuție leaves 630.
- **Result:** PASS (predicted)
- **Severity:** MEDIUM

#### BRT-011 — "Alt vehicul" + "Doar pentru această cursă" + any SOFT error = unsaveable trip
- **Preconditions:** ButanGas / Distribuție; vehicle B 677 NET (26) is not configured for that combination.
- **Steps:**
  1. "➕ Alt vehicul" → B 677 NET → modal → "Doar pentru această cursă".
  2. Fill everything and save.
  3. In the incomplete modal click "Da, salveaza oricum".
  4. Re-select the vehicle and the decision, then save again.
- **Expected from static audit:** the vehicle decision is lost after the round trip and the save "can fail again" (U-H3).
- **Actual:**
  - After step 2 the page reloads with the incomplete modal. Vehicle and driver are **empty**, capacity is empty, and the total changed **0,00 → 150,00 lei** for identical inputs.
  - Step 3 gives the HARD error "Selecteaza un vehicul valid.". The capacity error now blames the vehicle record.
  - Step 4 gives the same incomplete modal with the vehicle dropped again. **There is no UI path to save this trip.** The SOFT error is itself caused by using an unconfigured vehicle ("Pentru vehiculul selectat nu exista perechi de ruta configurate"), so it can never be cleared from the form.
  - Additionally, re-selecting the vehicle **overwrote** Loc Încărcare (BGR Navodari → Salonta, from the vehicle's garage).
- **Result:** PASS of the audit, but severity escalated
- **Classification:** BUG, CRITICAL
- **Evidence:** 03-incomplete-modal-vehicle-dropped-total-changed.jpg, 04-save-anyway-hard-error-vehicle-lost.jpg
- **Related code:** IDX:298 (hard-coded `value=""`), JS 2538-2596 (no preserve on Add), C:7231-7243
- **Severity:** CRITICAL

#### BRT-012 — Displayed vs stored total (D-series)
| Scenario | Local estimate | Server preview | Stored | Result |
|---|---|---|---|---|
| Primar km, ButanGas, 630 km | 800,10 | 800,10 | 800.10 (v91) | PASS |
| Primar km, Vixon circuit PLOIESTI/PLOIESTI | 2.150,00 | 2.150,00 | 2150.00 (v112) | PASS |
| P+D, ButanGas, Contesti→Oradea, 10 t | 2.464,50 | 2.464,50 | 2464.50 (v120); cost/km D 5,00, mixt 1,64 = stored | PASS |
| Compresor, ButanGas, 2 h + 3 t | 370,00 | 370,00 | 370.00 (legacy) | PASS |
| Vixon, date moved to 15/07/2026 (D1) | 2.150,00 | **1.960,00** (jumps after ~0,5 s) | not saved (driver not hired yet on that date) | D1 CONFIRMED |
| Distribuție, no rule for the vehicle (D3) | **150,00** | **0,00** | not saveable (BRT-011) | D3 CONFIRMED (display) |
| `ore_aspirare` "2h30" (D14) | 160 (2 h) | 160 | HARD error | D14 CONFIRMED |
| Non-reviewer user (D16) | local only | **HTTP 403** on every preview, silently ignored | — | D16 CONFIRMED |

D2, D4–D8 and D10–D13 could **not** be reproduced. The local data has no location/zone fallback tariffs, every route rule is vehicle-scoped, and the versioned values equal the legacy ones for the tested rules. They remain open (§18.3).

#### BRT-013 — Overlap, similar and exact duplicate
- **Steps:**
  1. Resubmit trip #790 identically.
  2. Same day, 20:30–22:00.
  3. Exact duplicate *without times* (trip #791 saved first, then resubmitted).
- **Actual:**
  1. **Overlap modal**, blocking, with the link "Deschide cursa #790". The form is kept and there is no reload. Clear wording.
  2. **Similar modal**: "Exista deja o cursa asemanatoare…", then "Nu, verific lista" / "Da, este o cursa noua". The form is kept.
  3. The similar modal appears first; after "Da" the server returns the warning "Exista deja o cursa salvata cu aceleasi detalii (ID 791)…" with a link. Not saved; form refilled.
- **Result:** PASS
- **Observation:** an exact duplicate *with* a start time is always reported as an **overlap**. The duplicate message is only reachable for trips without a start time, and then only after the operator confirms "este o cursa noua".
- **Evidence:** 05-overlap-modal.jpg, 06-similar-modal.jpg
- **Severity:** LOW (wording / concept clarity)

#### BRT-014 — Vehicle decision modal ("Alt vehicul")
- **Actual:**
  - Clicking "➕ Alt vehicul" **clears the vehicle and driver already selected**.
  - The expanded list includes passenger cars (Dacia Logan/Sandero) and inactive trucks, with no marker.
  - Choosing an unconfigured vehicle opens "Vehicul neconfigurat pe rută" with Renunță / Doar pentru această cursă / Adaugă permanent pe rută:
    - **Renunță** clears both vehicle and driver;
    - **Doar…** sets `vehicle_config_decision=trip`.
- **"Permanent":** NOT EXECUTED (destructive configuration change).
- **Result:** PASS
- **Severity:** MEDIUM

#### BRT-015 — Stacked modals
- **Steps:** choose an unconfigured vehicle that is also inactive: B 232 NET (in repair) for ButanGas Distribuție.
- **Actual:** both "Vehicul neconfigurat pe rută" **and** "Vehicul inactiv utilizat" open at once, with 2 backdrops. Clicks hit the top modal; the second appears as the first closes.
- **Result:** FAIL (not documented)
- **Evidence:** 02-stacked-modals-unconfigured-and-inactive-vehicle.jpg
- **Severity:** HIGH (UX FRICTION / BUG)

#### BRT-016 — Inactive resources (admin / reviewer)
- **Actual:**
  - Vehicle B 105 NET (ADR and ITP expired) gives the modal "Vehicul inactiv utilizat" with Anulează / Aprobă ulterior / Aprobă acum.
  - Selecting its listed driver (Andreias Iulian, terminated, documents expired) then re-opens the modal with **both** resources, including the vehicle already dismissed.
  - A driver can also be inactive purely because the trip date precedes the hire date (Gaie Marius-Mihai on 15/07/2026). The check is date-aware for employment.
  - **Race:** a check started by a date change resolved *after* the vehicle was switched and showed the **previous** vehicle and driver.
- **Result:** PARTIAL (re-prompt and stale-race behavior not documented)
- **Evidence:** 08-inactive-vehicle-and-driver-admin-modal.jpg
- **Severity:** MEDIUM

#### BRT-017 — Inactive driver, non-reviewer (NEEDS VERIFICATION #6)
- **Preconditions:** local test session as a role `utilizator` (no `inactive_approvals.review`); approval mode `user`.
- **Steps:**
  1. ButanGas / Distribuție / B 375 NET → driver Bodiu Sorin (ADR expired).
  2. Fill the form and submit.
- **Actual:**
  - The modal "Sofer inactiv utilizat" says "Continuarea… se face pe propria raspundere", but the **only button is "Închide"**.
  - Submitting re-opens the same modal; nothing is posted.
  - For an inactive *vehicle* the same user gets "Solicită aprobare / Amână / Închide".
- **Result:** PASS of the audit: dead end confirmed. No approval request was sent.
- **Evidence:** 12-non-reviewer-inactive-driver-dead-end.jpg
- **Severity:** CRITICAL (BUSINESS LOGIC RISK)

#### BRT-018 — Vixon circuit / garages (NEEDS VERIFICATION #3)
- **Actual:**
  - After the vehicle is selected and before a route is chosen, "Loc plecare (garaj)" and "Loc întoarcere (garaj)" are **visible but empty** (no selected option); they would post nothing.
  - After Contesti → Giurgiu they auto-fill CONTESTI / CONTESTI (270 km, 2.150 lei). Choosing return "Rompetrol Midia" switches to 205 km / 1.950 lei.
  - **The garage selects lock each other**, like Loc/Zonă: departure PLOIESTI is not offered while return is Rompetrol Midia.
  - **Garage choices survive a route change** and silently pick the variant for the new route (Brazi/Negoiesti→Giurgiu opened on the 290 km CONTESTI→Rompetrol Midia variant instead of the default).
  - Layout: the circuit grid puts start and end date on different rows.
- **Result:** PASS (#3 confirmed), with undocumented locking and carry-over
- **Evidence:** 09-vixon-circuit-empty-garage-selects.jpg
- **Severity:** HIGH

#### BRT-019 — Manual-km Primar route (Mol) and beneficiary without routes (Flaga)
- **Actual:**
  - **Mol:** Km agreați becomes editable, empty and required; 212 × 1,18 = 250,16 (local = server).
  - **Flaga:** the vehicle placeholder reads "Configureaza vehiculele Primar in Configurare transport", and **Loc/Zonă contain only the placeholder**. The explanation "Nu exista perechi Primar configurate pentru beneficiarul selectat." exists only as a tooltip.
- **Result:** PASS
- **Evidence:** 10-flaga-primar-no-routes-empty-lists.jpg
- **Severity:** HIGH (U-H7 confirmed)

#### BRT-020 — Beneficiary changes
- **Actual:**
  - ButanGas → Forvest (Distribuție, populated): vehicle, driver, Loc, Zonă and capacity are **silently cleared**; quantity and km survive; total → 0.
  - ButanGas → Vixon with Compresor selected: the type **stays "Compresor"**. The only signal is the vehicle placeholder "Tip transport indisponibil pentru beneficiar".
  - All 5 types are always offered, whatever the beneficiary.
  - Before a type is chosen, the vehicle placeholder already says "Tip transport indisponibil pentru beneficiar", which is misleading.
- **Result:** PASS
- **Severity:** HIGH

#### BRT-021 — Validation on submit
- **Actual:**
  - **Empty submit:** full reload with 6 inline errors. An empty transport type reads "Tipul de transport este invalid." (not "not selected").
  - **Invalid typed date** "32/13/2026 25:99": red border and focus, **no text**, submit blocked; the hidden field keeps the previous valid date.
  - **End before start**, missing compressor locations, negative "Cantitate livrată" (−5) and `ore_aspirare` "2h30": all reported only after a reload, values preserved, messages inline. The local total had silently ignored the −5.
  - **Old error messages stay red** after the operator corrects the field, until the next reload.
- **Result:** PASS
- **Evidence:** 11-empty-submit-inline-errors.jpg
- **Severity:** HIGH (late feedback)

#### BRT-022 — Capacity
- **Steps:** Distribuție, B 285 NET (capacity 7 t, not verified), quantity 9 t.
- **Actual:**
  - Incomplete modal with **only** "Capacitatea reala a vehiculului (7 t) nu este inca verificata, deci gradul de umplere de 128.6% este orientativ".
  - The overload message is **not shown**.
  - For vehicle 28 (18,5 t, not verified) **every** trip with a quantity triggers this unfixable SOFT warning, forcing an extra round trip.
- **Result:** FAIL (the audit lists overload as a separate SOFT message; at runtime it is masked)
- **Severity:** HIGH (BUSINESS LOGIC RISK / UX)

#### BRT-023 — Incomplete confirmation round trip
- **Actual (P+D trip with a valid configured vehicle):**
  - Every value survived the reload, including vehicle, driver, Loc, Zonă, read-only km, quantities, goods, dates and observations.
  - "Da, salveaza oricum" saved the trip.
  - Scroll position after the reload: top of the page.
- **Result:** PASS for configured vehicles; FAIL for "Alt vehicul" (BRT-011)

#### BRT-024 — Post-save flow
- **Actual:**
  - Flash "Cursa a fost adaugata cu succes." Modal "Cursa a fost adaugata — Vrei sa adaugi cheltuieli…": Da / Nu. Nu → "Cheltuieli cursa": Nu e cazul / Nu acum. "Nu acum" → flash "Cursa ramane in atentii pentru cheltuieli.".
  - The success flash **stays on screen while the next trip is entered**.
  - The alert "Curse inregistrate de cand ai deschis pagina…" accumulates.
- **Result:** PASS

#### BRT-025 — Odometer side effects
- **Actual:**
  - Primar: `km_bord` +`km_totali` (640, not the agreed 630).
  - Compresor (2 h, km_dislocare 15): `km_bord` **unchanged**, `km_revizie` −80 (2 × 40). Relocation km are not counted.
  - Deleting the 5 test trips restored every vehicle counter exactly.
- **Result:** PASS (matches the code); the compressor case needs a business decision
- **Classification:** EXPECTED BUSINESS BEHAVIOR?

#### BRT-026 — "Curse deja înregistrate" panel
- **Actual:**
  - It renders below the "Filtre" and "Km service" cards, i.e. **away from the form and usually below the fold**.
  - Full vehicle history: B 605 NET has 16 trips; B 275 NET has 18 "Seamănă" rows.
  - After a type change cleared the vehicle, the panel **kept showing the previous vehicle's history**.
  - It re-fetches on every vehicle change, including each ↓ key press in the select.
- **Result:** PARTIAL (stale panel not documented)
- **Severity:** MEDIUM

#### BRT-027 — Keyboard-only completion
- **Actual:**
  - **67 Tab stops** before the first form field.
  - Inside the form: 17 stops, including 3 calendar buttons and the read-only capacity field.
  - In a native select, ↓ **commits** each option immediately. Arrowing over 5 vehicles fired 5 `inactive_resource_status` and 5 activity requests, and the first option (an inactive vehicle) opened a modal that **stole focus**.
  - **Escape** closes the modal but focus goes to `<body>`.
  - Selecting a goods type with Space closes the dropdown and focus again goes to `<body>`. The arrows also selected an unintended option.
  - A full keyboard-only completion was **not achievable** without the mouse.
- **Result:** FAIL (keyboard problems were only partly anticipated)
- **Severity:** HIGH (ACCESSIBILITY/KEYBOARD)

#### BRT-028 — Resolutions
| Viewport | Grid | Form top | Submit bottom | Notes |
|---|---|---|---|---|
| 1920×1080 | 4 col (460/368 px) | 321 | 884 | everything visible |
| 1680×930 (pane) | 4 col | 321 | 884 | fits |
| 1536×864 | 4 col (364/291) | 321 | 884 | **submit below the fold** |
| 1366×768 | 4 col (322/257) | **395** (GPS panel wraps) | 958 | total and submit below the fold; "Distribu?ie" glitch visible |
| 1280×720 | 4 col (300/240) | 395 | 958 | only ~4 rows visible |
| 1199 / 1100 | **2 col** (breakpoint 1200) | 369 | 1215 | the form doubles in height |

- No horizontal page overflow and no wrapped labels at any width.
- Per transport type at 1366: card height 511 (none / Primar km) → 578 (Primar tone / Distribuție / Compresor) → 743 (P+D). The submit button moves between 891 and 1122 px.
- **Result:** PASS (no breakage); the layout-stability findings are in the report §12/§20.
- **Evidence:** 13-16 *.jpg

#### BRT-029 — Console and network
- **Actual:**
  - No JavaScript errors during admin use.
  - 403 errors on `tarife_transport&action=preview` during the non-reviewer session (silent in the UI).
  - Page load: TTFB 0,8 s, DOMContentLoaded 1,8 s, 7,3 MB HTML (311 KB gzipped), ~48 000 DOM nodes.
  - 82 preview POSTs over the session. Debounced, but every select/typing burst fires one, and type switches fire several.
- **Result:** PASS (no init failure)
- **Severity:** MEDIUM (PERFORMANCE)

### 18.2 Status of the §17 NEEDS VERIFICATION items

| # | Item | Runtime status | Evidence |
|---|---|---|---|
| 1 | Primar eligibility uses only default entries | **NOT REPRODUCED** with current data. Every Vixon/ButanGas vehicle also appears in some pair's default entry, so the union hides the gap. Remains a code-level risk. | BRT-018 |
| 2 | Quantity hidden after Compresor | **CONFIRMED**, worse: hidden but enabled and priced | BRT-007 |
| 3 | Circuit select `selectedIndex −1` | **CONFIRMED** (visible empty selects before a route is chosen) | BRT-018 |
| 4 | Bootstrap available for the decision modal | **NOT REPRODUCED**: the modal works | BRT-014 |
| 5 | Repair/leave/documents checked against today vs trip date | **PARTIALLY CONFIRMED**: employment uses the trip date (hire-date case). Repair/documents could not be isolated from the UI. | BRT-016 |
| 6 | Inactive driver unsaveable for non-reviewers | **CONFIRMED** | BRT-017 |
| 7 | Requester approval linked to the new trip | **NOT TESTABLE FROM UI** without creating approval records (not executed) | — |
| 8 | Permission for AJAX helpers | **CONFIRMED** in part: a role `utilizator` user called `inactive_resource_status` and `races_activity` successfully | BRT-017 |
| 9 | `getPrimaryRouteKmMap` output keys | **NOT TESTABLE FROM UI** | — |
| 10 | `getDistributionRouteTemplateRule` callers | **NOT TESTABLE FROM UI** | — |
| 11 | Inline driver/vehicle pickers on the list | **NOT TESTED** (outside the form) | — |
| 12 | "Permanent" decision without ACL | **NOT EXECUTED**: destructive configuration change | BRT-014 |
| 13 | `syncTariffLegacyValues` before CSRF | **NOT EXECUTED** (writes configuration) | — |

### 18.3 Corrections to sections 0–17 (no earlier text rewritten)

- **§3.4:** add that in Primar and in vehicle-scoped Distribuție a **selected** Loc (or Zonă, or garage) narrows **its own** list to one option. Changing it requires resetting it to the placeholder first (BRT-003, BRT-018).
- **§3.5:**
  - The quantity bug also keeps the **hidden quantity posted and priced** (BRT-007).
  - Stale `km_cursa` and `tona_livrata` keep pricing across type switches (BRT-008, BRT-009).
  - "Alt vehicul" clears the current vehicle and driver (BRT-014).
- **§7.3:** when capacity is not verified, the overload message is not shown at all (BRT-022).
- **§10:**
  - Escalate **U-H3 → CRITICAL** ("Alt vehicul" trips with any SOFT warning cannot be saved, BRT-011).
  - Escalate **U-H4 → CRITICAL** (BRT-007).
  - Escalate **U-M15 → HIGH** (BRT-008).
  - Add stacked modals (BRT-015), stale panel (BRT-026), stale inactive modal race (BRT-016), and keyboard focus loss (BRT-027).
- **§16 requirement 7:** the intended behavior must also include "the quantity value of a hidden field is never submitted".
