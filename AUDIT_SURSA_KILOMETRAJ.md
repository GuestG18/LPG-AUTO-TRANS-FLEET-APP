# Audit: where mileage comes from (Centru Kilometraj vs Detalii vehicul)

Date: 2026-11-27 · Scope: read-only investigation · Nothing in code, schema, data, cron or API state was changed.
Database checked: the local `if0_41456552_aplicatie_flota` (Laragon). **It is a snapshot, not the VPS**: local SAS sync stopped on 2026-10-28, local CardOil sync on 2026-11-11, and there are no CAN rows at all (see §3.3).

Legend: **[V]** = verified in code or DB · **[I]** = inferred (strong evidence, not proven) · **[A]** = assumption / not verifiable locally.

---

## 0. Summary

1. **"km bord" in Detalii vehicul is not an odometer.** [V] It is `vehicule.km_bord`, a counter: the value typed when the vehicle was registered, plus the km of every trip saved in Dispecer Curse (for semi-trailers after the cutover date: plus the coupled tractor's SAS km). It is read straight from the `vehicule` row; nothing is calculated when the page loads.
2. **B 265 NET (id 79): km bord = 29,279. The real odometer is about 497,000.** [V] 29,279 = 16,934 km from 29 Dispecer trips + **12,345** [I] typed at registration (2026-08-11). This looks like a placeholder, and there is no audit row for it.
3. **Centru Kilometraj has no per-vehicle "assigned provider".** [V] There is one global policy, `km_center_setari.politica_total` (currently `sas`), and it applies to *km since T0*, not to the current odometer. The page shows each source's latest valid reading next to the others and never merges them.
4. **No existing backend function returns "the authoritative mileage of vehicle X".** [V] `OdometerCenterService::buildDashboard()` builds the whole fleet: it loads every CardOil fill-up, and the per-vehicle `buildComponent()` is private. Its constructor also runs DDL (`ensureSchema`). Separately, `ReviziiV2Service::currentKm()` (private) resolves the current km on its own with a different rule (CAN → GPS, its own per-plan policy, no CardOil, no anomaly chain at read time).
5. **Overwriting `vehicule.km_bord` with the real odometer would break things.** [V] Tyres, components, Revizii (legacy), technical health and maintenance all store km_bord snapshots and subtract them from the live km_bord later. A jump from 29k to 497k would add about 468,000 km of fake wear everywhere.
6. **Recommendation:** add a small, **read-only** per-vehicle resolver built on the existing `OdometerCalculator` and `OdometerCenterModel` read methods. Use it for the Detalii vehicul KPI only (phase 1), with the source and date shown. Leave `km_bord` as the internal counter. Migrate other modules one at a time later, starting with Revizii V2, so both pages show the same number. **One business decision is needed first**: which source is the "current odometer" (§4.2).

---

## 1. Centru Kilometraj — current architecture

### 1.1 Files [V]

| Layer | File | Key symbols |
|---|---|---|
| Route | `htdocs/index.php:763` | `case 'centru_kilometraj'` → `OdometerCenterController::handle()` |
| Controller | `htdocs/controllers/OdometerCenterController.php` | actions: `index`, `data`, `sync`, `t0_lookup`, `t0_save`, `history`, `can_km`, `save_policy`, `manual_add/cancel/check/tolerance`, `audit` |
| Service (state + sync + T0) | `htdocs/services/OdometerCenterService.php` | `buildDashboard`, `buildComponent` (private), `buildTelemetrySource`, `buildCardOilSource`, `buildManualSource`, `compareSources`, `syncSas`, `syncCanVehicle`, `refreshMappings`, `lookupT0`, `saveT0`, `savePolicy`, `vehicleHistory`, `sasCarId` |
| Pure rules | `htdocs/services/OdometerCalculator.php` | `sasReadingAt`, `cardOilReadingAt`, `manualReadingAt`, `flagAnomalies`, `plausibleStep`, `distanceSinceT0`, `sourceDifference`, `officialDistance`, `sasFreshness` |
| Provider adapters | `htdocs/services/OdometerProviders.php` | `SasFleetOdometerSource` (`fetchReadings` → travelsheet kmIndex, `fetchCanReadings` → `SasCanKmService`), `FuelFillupOdometerSource` (`fuel_fillups`) |
| Model | `htdocs/models/OdometerCenterModel.php` | `ensureSchema`, `getSettings`, `getVehicles`, `getActiveCouplings`, `getSources`, `storeReadings`, `getReadings`, `getReadingsAround`, `getRecentReadingsByVehicle`, `getCardOilFillups`, `getLastCardOilSync`, `getActiveBaselines`, `saveBaselineVersion`, manual readings / checks |
| Trailer km | `htdocs/services/TrailerSasKmService.php` | `run`, `cutover`, writes `vehicule.km_bord/km_revizie` for semi-trailers |
| CAN | `htdocs/services/SasCanKmService.php` | API `odometerCanStart/End` first, web report fallback |
| View / JS / CSS | `htdocs/views/centru_kilometraj/index.php`, `htdocs/assets/js/centru-kilometraj.js`, `htdocs/assets/css/centru-kilometraj.css` | auto-refresh every 60 s from the DB only |
| ACL | `htdocs/permissions/modules/centru_kilometraj.php` | `view`, `history`, `t0`, `settings`, `audit`, `manual` |
| CLI / cron | `scripts/sync_centru_kilometraj.php`, `scripts/run_sync_centru_kilometraj.sh` | cron `*/5` documented, **not installed on the VPS** (per project notes, 2026-10-22) [A] |
| Tests | `scripts/test_centru_kilometraj.php` | fixtures `KMCTEST-*` |
| Design doc | `CENTRU_KILOMETRAJ.md` | |

### 1.2 Tables [V]

| Table | Content |
|---|---|
| `vehicle_odometer_sources` | PK (vehicle_id, provider_code ∈ sas/cardoil/can), `external_provider_vehicle_id` (SAS carId or plate key), `enabled`, `last_checked_at`, `last_status`, `last_error` |
| `vehicle_odometer_readings` | SAS GPS and CAN readings (`provider_code` sas/can). Unique (provider, vehicle, `source_reading_id` = `carId@segment_end`), `INSERT IGNORE`. `validation_status` valid/anomaly is persisted by `revalidateStored()`. CardOil is **not** copied here. |
| `vehicle_odometer_baselines` | Versioned T0 per vehicle × provider (sas/cardoil/manual/can): `requested_t0_at`, `measured_t0_at`, `baseline_value_km`, `retrieval_status`, `baseline_version`, `is_active` |
| `vehicle_odometer_manual_readings` / `_manual_checks` | trailer hubodometer readings and their comparison with the coupled tractor's SAS km |
| `vehicle_odometer_audit` | T0 saves and policy changes |
| `km_center_setari` | `politica_total`, tolerances, freshness, trailer cutover `semiremorci_sas_de_la` |
| `fuel_fillups` (+ `fuel_sync_logs`) | CardOil source: `odometer_km` (already includes the manual correction `odometer_km_manual`), effective plate |

### 1.3 Data flow

```mermaid
flowchart LR
  subgraph External
    SAS[SAS Fleet API\ntravelsheet kmIndex]
    CAN[SAS CAN\nAPI odometerCan / web report]
    CO[CardOil API]
  end
  SAS -->|syncSas button / cron| R[(vehicle_odometer_readings\nsas)]
  CAN -->|syncCanVehicle, after SAS| R2[(vehicle_odometer_readings\ncan)]
  CO -->|sync_cardoil_alimentari cron| F[(fuel_fillups)]
  R & R2 & F --> B[buildDashboard\nflagAnomalies per series\ncurrent = last valid reading]
  T0[(vehicle_odometer_baselines)] --> B
  P[(km_center_setari\npolitica_total)] --> B
  B --> UI[Centru Kilometraj page\n(no API calls on load)]
```

### 1.4 How readings are retrieved, stored and mapped [V]

- **SAS GPS**: `SasFleetOdometerSource::fetchReadings()` → `SasFleetClient::getTravelSheet()`. Reading = `segments[].kmIndex` at segment end, `measurement_type = gps_km_index`. The window always starts no later than yesterday 00:00, because SAS returns kmIndex only for windows longer than one calendar day. Incremental: from `last_checked_at − 2 h`, max `sas_istoric_zile` (31). Throttle 650 ms, one retry, stop after 3 consecutive errors, file lock.
- **CAN**: same carId, `fetchCanReadings()` in ≤31-day chunks, run right after the same vehicle's SAS sync. A CAN error marks only the CAN source.
- **CardOil**: the Centru makes no API calls of its own. It reads `fuel_fillups` (`source_type` api/manual, `odometer_km > 0`), grouped by plate key = uppercase letters and digits only.
- **Mapping**: `refreshMappings()`. SAS/CAN by plate through `FleetLivePositionService::getFleetHierarchy()['cars'][].local_vehicle_id`. Vehicles that disappear from SAS are disabled. CardOil source when a fill-up plate matches `vehicule.nr_inmatriculare`. There is no manual mapping screen.

### 1.5 "Assigned provider" [V]

No per-vehicle assignment exists. `OdometerCalculator::officialDistance($policy, $sasKm, $cardOilKm, $canKm)` applies **one global** `politica_total` ∈ `'' | sas | cardoil | sas_cardoil | can | can_sas` to **km since T0**. Semi-trailers always use the manual hubodometer, whatever the policy. Local value: `politica_total = 'sas'` (set 2026-11-25).

### 1.6 T0 baselines [V]

`lookupT0()` / `saveT0()`: each source is looked up independently and the results are saved as a new version (older versions get `is_active = 0`).

| Source | Rule |
|---|---|
| SAS / CAN | `sasReadingAt`: last valid reading ≤ T0. **Exact** if the vehicle was parked between that reading and T0 (no travelsheet movement overlaps). Otherwise accepted only if it is ≤ `sas_toleranta_minute` (15) old, else `in_afara_tolerantei`. The lookup calls SAS live (T0 − 31 days … T0 + 1 day). |
| CardOil | `cardOilReadingAt`: last non-anomalous fill-up ≤ T0, ≤ `cardoil_varsta_max_ore` (72 h) old. No interpolation. |
| Manual (trailer) | `manualReadingAt`: closest reading before or after T0, within `manual_varsta_max_ore` (24 h). |

### 1.7 Formulas [V]

- **Current odometer of a source** = the last reading of the series that `flagAnomalies` keeps as valid (the last 80 SAS/CAN readings per vehicle; every CardOil fill-up).
- **Valid series** (`flagAnomalies`) = the longest chain where each step satisfies `plausibleStep`: `to ≥ from − 0.5` and `to ≤ from + hours × 95 + 60`. Everything else is an anomaly (typos, sentinel 16777215, resets).
- **Km since T0** (`distanceSinceT0`) = `round(current.km − baseline.km, 1)`. Null if there is no baseline, no current reading, or the current reading is older than T0. Negative values are flagged as an anomaly and excluded.
- **Official km** = `officialDistance(politica_total, …)`, i.e. one source's km since T0. **Sources are never summed.**
- **SAS − CardOil diagnostic** (`compareSources` + `sourceDifference`): SAS odometer at the CardOil T0 fill-up and at the latest fill-up (only if exact or within tolerance). Alert if |Δ| > 100 km **and** |%| > 5.
- **Freshness**: SAS/CAN `actual` if checked ≤ `sas_prospetime_ore` (2 h) ago; `eroare` keeps the last reading. CardOil `eveniment` if the last successful CardOil sync is ≤ 26 h old, else `neactualizat`.

### 1.8 Missing, stale, invalid data and source switching [V]

- Missing mapping or no readings → the source shows a `reason` text and nothing is invented. A vehicle counts as "monitored" only if at least one source has a real reading.
- A sync error keeps the last reading and marks it `eroare`. Anomalies are excluded from "current" and listed (last 30 days).
- **Changing the source**: changing `politica_total` is logged in the audit table and simply re-evaluates; there is no per-vehicle fallback history. A new T0 creates a new baseline version. CAN distances need a T0 saved after CAN was added.

### 1.9 Calculated, cached or stored? [V]

Readings, mapping status, baselines and settings are **stored**. Current odometer, km since T0, official km, anomalies shown, freshness and KPIs are **calculated on every request** from the DB (`buildDashboard`), with no cache. Only T0 lookups are cached for 15 min (file cache, so a save matches its lookup).

### 1.10 Reusable "authoritative mileage for one vehicle"? [V]

**None.** Closest pieces:

| Candidate | Why it is not directly reusable |
|---|---|
| `OdometerCenterService::buildDashboard()` | Whole fleet; loads every `fuel_fillups` row; `buildComponent` is private. |
| `OdometerCenterService::__construct` | Calls `OdometerCenterModel::ensureSchema()` (CREATE IF NOT EXISTS, conditional `ALTER … ENUM`, capacity-category schema). That is a side effect we don't want on a page view. |
| `OdometerCenterService::vehicleHistory()` | Per vehicle, but returns raw flagged series, not a resolved value. |
| `ReviziiV2Service::currentKm()` (`htdocs/services/ReviziiV2Service.php:182`) | Private. Its own rule: newest `valid` CAN/GPS row, plan-level `sursa_km` (auto/can/gps), staleness `date_vechi_ore`. Ignores `politica_total`, CardOil, T0. Trailers fall back to `km_bord`. |
| `ComponentSasKmService::kmAt()` | Odometer at a past moment via a **live** SAS call (~2 s), used by the component mount forms. Not suitable for a page KPI. |

Reusable building blocks: `OdometerCalculator` (pure, tested) and the model read methods `getReadings`, `getCardOilFillups(plateKeys)`, `getSources`, `getSettings`, `getLastCardOilSync`, `getActiveBaselines`.

---

## 2. Detalii vehicul — "km bord"

### 2.1 Display path [V]

```mermaid
flowchart LR
  U[?page=vehicule&action=show&id=79] --> MC[ModuleController::showAction\ncontrollers/ModuleController.php:5368]
  MC --> FM[ModuleModel::findById → SELECT row from vehicule]
  FM --> SV[views/module/show.php:16]
  SV --> VD[views/module/_vehicle_detail.php\n:206 $vdKmBord = $record['km_bord']\n:284 KPI 'km bord']
  VD --> CAR[carousel category 'mentenanta'\n:141 km_bord, km_revizie]
```

No calculation and no service: the stored column, formatted. It appears **twice** on the page: the KPI tile and the "Mentenanță" carousel field.

### 2.2 How `vehicule.km_bord` is produced [V]

| Writer | Where | Effect |
|---|---|---|
| Vehicle create/edit form | `config/modules.php:82` (required, "kilometrajul curent la înregistrare"), generic `ModuleController` save | Sets an absolute value typed by a user |
| Dispecer Curse trip create/update/close/delete | `DispecerCurseModel::buildRaceKmDistribution()` (`:6293`) → `applyKmDistributionDelta()` (`:6398`) → `applyKmDeltaToVehicle()` (`:10464`); called at `:5532/5582/5667/5800/10361` | `km_bord += Δ` where trip km = `km_totali` if > 0, else `km_cursa`; split by segment km; also applied to the coupled tractor/trailer (`getKmSyncVehicleIds`). `km_revizie` (km left until service) decreases by trip km + 40 × engine hours. |
| Trailer SAS km | `TrailerSasKmService::apply()` (`:251`) | After `semiremorci_sas_de_la`, trailers get the coupled tractor's SAS km. Dispecer stops adding km to them (`withoutSasTrailers`). Locally the cutover is **not set**, so it has never run here. |

`vehicule_service_km` (Km service), CardOil and SAS never write the km_bord of tractors or trucks. [V] The only `SET km_bord` statements are in `DispecerCurseModel` and `TrailerSasKmService`.

---

## 3. B 265 NET (vehicle 79): comparison

Vehicle: MERCEDES-BENZ AXOR, `tip_vehicul = camion`, active, created 2026-08-11. Not coupled, so the trailer logic does not apply.

### 3.1 Values (local DB, 2026-11-27)

| Measure | Value | Moment | Source |
|---|---:|---|---|
| **Detalii vehicul "km bord"** | **29,279** | `vehicule.updated_at` 2026-11-10 14:48 | `vehicule.km_bord` [V] |
| Dispecer trips, Σ effective km (29 trips, none deleted) | 16,934 | 31.08 → 10.11 | `curse_dispecer` [V] |
| km_bord − trips = value at registration | 12,345 | 2026-08-11 | [I] exact difference; no audit row for vehicle 79 in `audit_log` |
| **SAS GPS current** (last valid of 93 readings, 0 anomalies) | **497,175.2** | 2026-10-27 18:43 | `vehicle_odometer_readings` sas, carId 9116 [V] |
| SAS source last successful check | — | 2026-10-28 16:36 → freshness `neactualizat` | [V] |
| **CardOil current** (last valid of 23 fill-ups, 0 anomalies) | **497,270** | 2026-11-06 16:22 | `fuel_fillups` #5021 [V] |
| CardOil last successful sync (fleet-wide) | — | 2026-11-11 11:10 → `neactualizat` (> 26 h) | `fuel_sync_logs` [V] |
| CAN | — | no `can` source row, no readings locally | [V] locally; on the VPS [A] |
| T0 SAS / CardOil / CAN | **none** (only 9 baselines exist in the whole local DB, none for vehicle 79) | — | [V] |
| **Centru "official" km (policy `sas`)** | **null**: "Fără referință T0" | — | [V] by code path: no baseline → `distanceSinceT0` returns null |

### 3.2 Same-window cross-check (01.10 17:04 → 21.10 13:29, the CardOil fill-ups) [V]

| Source | Start | End | Km |
|---|---:|---:|---:|
| CardOil (pump) | 490,405 | 494,380 | **3,975** |
| SAS GPS (reading ≤ moment) | 491,400.6 | 496,162.0 | **4,761** (+19.8 % vs CardOil) |
| Dispecer trips dated 01–20.10 (approximate attribution) | — | — | ≈ 5,174 |

The GPS kmIndex runs ahead of the pump odometer and the gap grows: −996 km on 01.10, −1,782 km on 21.10. This matches the earlier observations in the project (GPS overstates; CAN is the source closest to the dashboard).

### 3.3 Why the numbers disagree

1. **km bord vs real odometer (−468,000 km)**: km_bord started at 12,345 on 2026-08-11 instead of about 487,000 [I], and only grows by trip km. It is a trip counter with an arbitrary starting value, not an odometer. [V for the mechanism]
2. **km bord growth vs real distance**: trip km are typed or estimated per trip (`km_cursa`/`km_totali`). They differ from the vehicle's real movement (empty runs, garage moves, open trip #1334 with NULL km). [V]
3. **SAS vs CardOil absolute values**: the SAS kmIndex is a GPS-integrated index, *not calibrated to the dashboard*. For this vehicle it happens to be close in absolute value, but it accumulates about 20 % more km per window. CardOil is the dashboard value typed by the driver, available only at fill-ups. [V]
4. **Centru shows no official km for vehicle 79** because no T0 was ever saved for it, and its "official" figure is a distance, not an odometer. [V]
5. **Local staleness**: local SAS sync stopped on 2026-10-28 and CardOil on 2026-11-11; no CAN data. Production values will be newer. **Re-run this comparison on the VPS before implementing.** [V locally / A for VPS]

---

## 4. Proposed architecture

### 4.1 Principles

- **Do not overwrite `vehicule.km_bord`.** Keep it as the internal counter: its absolute value is meaningless, but its *differences* drive tyres, components, revizii and technical health (§5).
- **One read-only resolver** used by every screen that *displays* the current odometer. It makes no API calls, runs no DDL and works per vehicle (or batch).
- **Reuse, don't duplicate**: `OdometerCalculator::flagAnomalies`, `sasFreshness`, the model read methods, and `km_center_setari` for settings.
- **Always show provenance**: source, reading time, freshness. When nothing is valid, say so and show the counter labelled "contor intern (Dispecer)", never as the odometer.

### 4.2 Decision needed: what is "the current odometer"?

`politica_total` defines *km since T0*. The user-facing "km bord" is an *absolute* value, so a rule is needed. Options:

| Option | Rule | Pros | Cons |
|---|---|---|---|
| **A (recommended)** | CAN fresh → CardOil latest valid → GPS latest valid → counter (labelled) | CAN and CardOil are dashboard values; GPS is only a fallback | Needs CAN synced on the VPS; CardOil is up to days old (date shown) |
| B | Follow `politica_total` (`sas` today) → absolute SAS GPS | One setting for the whole app | GPS absolute ≠ dashboard (−1,782 km on vehicle 79 after 3 weeks, growing) |
| C | Calibrated: preferred source's T0 value + km since T0 | Uses T0 as designed | Needs T0 for every vehicle (9 baselines locally) |

Whichever option is chosen should become a new setting (for example `politica_odometru`) in `km_center_setari`, so Revizii V2 and Detalii vehicul read the same rule.

### 4.3 Shape

```mermaid
flowchart LR
  subgraph Read-only resolver (new)
    RS[VehicleOdometerResolver::forVehicles(ids)\n→ value, source, measured_at, freshness, note, counter_km]
  end
  M[OdometerCenterModel read methods\n(no ensureSchema)] --> RS
  C[OdometerCalculator\nflagAnomalies / sasFreshness] --> RS
  S[(km_center_setari)] --> RS
  RS --> VD[Detalii vehicul KPI — phase 1]
  RS -.phase 2.-> RV[ReviziiV2Service::currentKm]
  RS -.phase 2.-> OTH[Revizii legacy, Stoc Anvelope header,\nStare tehnică, Mentenanță — display only]
  KB[(vehicule.km_bord counter)] --> RS
  KB --> DEP[wear / interval maths — unchanged]
```

- Batch: per vehicle id, the last N `vehicle_odometer_readings` (sas, can) + `getCardOilFillups([plateKey])`. For lists, a window query like `getRecentReadingsByVehicle` limited to the ids.
- Trailers: latest valid manual reading if present, else the counter (labelled "km tractor cuplat").
- Guard with `tableExists` instead of `ensureSchema`, the same way ReviziiV2Service does.

### 4.4 Phases

1. **Detalii vehicul only**: KPI shows the resolver value + source/date chip. The carousel "Km bord" becomes "Contor intern (Dispecer)" or keeps the label with a hint. Zero effect on calculations.
2. **Align Revizii V2**: `currentKm()` delegates to the resolver, so both screens show one number. Check that its `sursa_km` per-plan override still works.
3. **Other displays** (labels only): Stoc Anvelope fleet header ("Km bord (orientativ)"), Revizii legacy tables, Stare tehnică, Mentenanță Auto header, Vehicule list column (`config/modules.php` list_columns).
4. **Optional, separate decision**: move individual calculations off the counter (for example component wear from SAS like tyres already do). Each one needs its own cutover and a "from now on" freeze, as was done for tyres.

---

## 5. Modules that depend on `vehicule.km_bord` and regression risks [V]

| Module | File : line | Use | Risk if km_bord were overwritten | Risk with the resolver (display only) |
|---|---|---|---|---|
| Dispecer Curse | `DispecerCurseModel.php:6293–6420, 10464` | **writes** deltas | Next trip edit adds or subtracts deltas onto the new value (consistent), but older snapshots break | none |
| Trailer SAS km | `TrailerSasKmService.php:251` | **writes** deltas | same | none |
| Anvelope | `TireModel.php:187` (one-time freeze `km_bord − km_start`), `:3539, 3900, 5143, 5200` (`km_start` = km_bord at mount); `ModuleController.php:2285, 2409, 2614, 5214` | snapshot at mount; tyre km come from SAS since 2026-11-09 | `km_start` mixes the two scales; any code that still subtracts would jump | none |
| Components / X-ray | `VehicleComponentPanelService.php:48, 95` (`vehicleKm − km_montare`) | wear = difference | **+468k km wear** on every component | none |
| Stare tehnică | `TechnicalHealthModel.php:77` (`ensureVehicleStatuses(km_bord)`), view `technical_health/index.php:119` | baseline + display | wear jump | label mismatch only |
| Revizii (legacy) | `ReviziiModel.php:267, 446`; `ReviziiController.php:254–280` (validation `kmLast > km_bord`, `savePlan(..., km_bord)`); `ReviziiService.php:449`; `ReviziiIstoricModel.php:16, 814` (history stores km_bord snapshots) | intervals = differences; history snapshots | overdue/remaining km wrong; validation rejects or accepts wrongly | none if left on the counter; the user will see two different "km" on two pages until phase 2–3 |
| Revizii V2 | `ReviziiV2Service.php:182` (CAN/GPS readings; trailers → km_bord) | already telemetry-based | — | must align (phase 2) or the numbers differ from Detalii vehicul |
| Revizii weekly report | `ReviziiWeeklyReportService.php:384, 755` | prints km_bord | wrong values in email | label only |
| `km_revizie` countdown | `DispecerCurseModel::applyKmDeltaToVehicle` | km left until service, decremented by trips | unaffected by km_bord, but conceptually trip-based too | none |
| Mentenanță / Intervenții | `MaintenanceController.php:385, 628`; `views/maintenance/auto.php:89`; `InterventionWorkflowModel.php:591, 705, 716`; `_interventions_v2.php:24` | prefill and display of km at intervention | stored intervention km would jump scale mid-history | label only |
| Consumabile / fluids | `ConsumabileStockService.php:952, 1290`; `VehicleFluidService.php:311–338` (stores `km_can`, `km_gps`, `km_bord` separately) | snapshot | mixed scales in history | none |
| Rentabilitate | `OperationalProfitabilityService.php:489–490` | uses **trip km** (`km_totali`/`km_cursa`), not km_bord | none | none, but it is another "km" that will keep differing from the odometer distance; worth stating in the UI |
| Istoric activități șofer | `DriverActivityHistoryModel.php:1185` | aliases the CardOil odometer as "km_bord" | none | none |
| OCR piese | `OcrPartsModel` / `ocr_piese` views | their own `km_bord` columns on articles | none | none |

Other risks:

- **Performance**: do not call `buildDashboard()` from Detalii vehicul. It loads every fill-up for the whole fleet.
- **Side effects**: constructing `OdometerCenterService` runs `ensureSchema()` (DDL). The resolver must avoid it.
- **Freshness on production**: the Centru cron is not installed (per project notes) [A]. Unless someone presses "Actualizează datele", SAS/CAN readings age. The resolver must display staleness, and installing the cron (outside this audit's scope) becomes important.
- **CAN on production** [A]: the local DB has no CAN rows, so Option A's first step is unverified here.
- **Anomaly state**: `validation_status` is persisted only at sync time over the last 400 rows. The resolver should run `flagAnomalies` itself, as the Centru does, and not trust only the stored flag (ReviziiV2 trusts the flag).
- **Data-quality signal**: km_bord initial values like 12,345 suggest other vehicles were registered with placeholders. A one-off read-only report "km_bord vs latest odometer" per vehicle would size the problem before deciding phase 4.

---

## 6. Files to change in the implementation phase (proposal, not done)

Phase 1:
- **new** `htdocs/services/VehicleOdometerResolver.php`: read-only, per vehicle and batch.
- `htdocs/models/OdometerCenterModel.php`: maybe one read method for "recent readings for these ids" (or reuse `getReadings` per vehicle). No schema changes.
- `htdocs/models/OdometerCenterModel.php` `DEFAULT_SETTINGS`: new key `politica_odometru` (only if a setting is wanted; it is stored in the existing key/value table, no DDL).
- `htdocs/controllers/ModuleController.php` (`showAction`, vehicule only): pass `$viewData['odometer']`.
- `htdocs/views/module/_vehicle_detail.php` (`:206`, `:284`, carousel label for `km_bord`).
- `htdocs/assets/css/vehicle-detail.css`: source/freshness chip.
- `scripts/test_centru_kilometraj.php` (or a new test script): resolver cases (CAN fresh, CAN stale → CardOil, GPS fallback, anomalies, trailer manual, no data).
- `CENTRU_KILOMETRAJ.md`: document the resolver.

Phase 2–3:
- `htdocs/services/ReviziiV2Service.php` (`currentKm`).
- Display labels: `views/module/tire_stock/_fleet_unit_detail.php:199`, `views/revizii/index.php:296, 524–525`, `views/technical_health/index.php:119`, `views/maintenance/auto.php:89`, `config/modules.php` list column label.

**Do not touch**: `DispecerCurseModel` km sync, `TrailerSasKmService`, `TireModel`, `VehicleComponentPanelService`, `TechnicalHealthModel` maths, `ReviziiModel`/`ReviziiIstoricModel` snapshots.

---

## 7. Verified vs assumed

| Statement | Status |
|---|---|
| Detalii vehicul reads `vehicule.km_bord` directly | V |
| km_bord = initial + Dispecer trip deltas (+ trailer SAS after cutover) | V |
| Vehicle 79 initial km_bord was 12,345 | I (exact arithmetic; no audit row) |
| No per-vehicle provider assignment; one global `politica_total` | V |
| No reusable per-vehicle authoritative-km function | V |
| Vehicle 79 SAS 497,175.2 / CardOil 497,270 / no T0 / no CAN | V (local DB) |
| Production (VPS) has CAN readings and newer data | A |
| Centru cron not installed on VPS | A (project notes of 2026-10-22, not rechecked) |
| GPS overstates relative to the dashboard | V for vehicle 79 window; consistent with earlier project findings |
