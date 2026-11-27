# Detalii vehicul: mileage carousel (3 sources + period + coupling history)

Date: 2026-11-27 · Status: implemented and validated **locally only**. Not committed, not deployed.
Built on: `AUDIT_SURSA_KILOMETRAJ.md`. Its findings were re-checked against the code before implementation and still hold.

Legend: **[V]** verified (test, query or browser) · **[A]** assumption / not verifiable locally.

---

## 1. Architecture

### 1.1 Files

| File | Change |
|---|---|
| `htdocs/services/VehicleMileageService.php` | **new**. Read-only service: direct strategy, semi-trailer coupling strategy, period resolution. |
| `htdocs/assets/js/vehicle-mileage.js` | **new**. Carousel, period popover, details popover, request sequencing. |
| `scripts/test_vehicle_mileage.php` | **new**. 49 tests: pure rules + read-only checks on the local DB. |
| `htdocs/models/OdometerCenterModel.php` | **+1 method** `getCardOilFillupsForPlates()`: SQL-filtered by plate, no DDL. Existing methods unchanged. |
| `htdocs/controllers/ModuleController.php` | `case 'mileage'` → `vehicleMileageAction()` (JSON); `showAction` adds `vehicleMileage` for `vehicule`. |
| `htdocs/index.php` | `require_once` for the new service. |
| `htdocs/views/module/_vehicle_detail.php` | **Only the km bord KPI card** is replaced by the carousel. The "Mentenanță" carousel field `km_bord` is untouched. |
| `htdocs/assets/css/vehicle-detail.css` | `.vd-km*` styles + two responsive rules. Nothing else changed. |

### 1.2 Reused methods (no duplicated formulas)

- `OdometerCalculator::flagAnomalies` (valid chain / anomalies), `sasReadingAt` (boundary: exact if parked, else 15-min tolerance), `sasFreshness`, `ts`.
- `OdometerCenterModel::getReadings` (the same last-80-readings window as `buildDashboard`), `getLastCardOilSync`, `DEFAULT_SETTINGS`.
- Settings read from `km_center_setari` (tolerances, freshness, CardOil max age).
- **Not called**: `OdometerCenterService` (its constructor runs `ensureSchema`), `buildDashboard`, `TrailerSasKmService` (constructor runs DDL; `run()` writes `km_bord`), any SAS/CardOil API.

### 1.3 Tables read (SELECT only)

`vehicule`, `vehicle_odometer_sources`, `vehicle_odometer_readings` (sas, can), `fuel_fillups`, `fuel_sync_logs`, `km_center_setari`, `vehicule_cuplaje`.
`vehicle_odometer_baselines` is not needed: absolute values and period distances don't use T0.

### 1.4 Flow

```mermaid
flowchart LR
  P[page=vehicule&action=show] --> S[VehicleMileageService::forVehicle(id,'all')]
  S -->|JSON in page| C[carousel: SAS GPS → SAS CAN → CardOil]
  C -->|switching source: no request| C
  C -->|period change: 1 request| E[page=vehicule&action=mileage&period=…]
  E --> S
  S --> D{tip_vehicul}
  D -->|camion / cap tractor / ușor| DIR[direct strategy]
  D -->|semiremorca*| TR[coupling-history strategy]
```

### 1.5 Calculation rules

**Direct vehicles** (each provider on its own; providers are never mixed):

| Period | SAS GPS / CAN | CardOil |
|---|---|---|
| Până în prezent | Last valid reading of the last 80 (= Centru Kilometraj) | Last valid fill-up (= Centru Kilometraj) |
| Interval [start, end] | `km = reading at end − reading at start`. Each boundary uses `sasReadingAt` (exact if parked, or ≤ 15 min). If a boundary is uncertain, the first/last reading **inside** the interval is used and the result is marked **partial**. | `km = last − first valid fill-up inside the interval`. **Complete** only if both are within `cardoil_varsta_max_ore` (72 h) of the boundaries, otherwise **partial**. |

- **Boundaries**: month = 1st 00:00 → now; previous month = full calendar month; year = 1 Jan → now; custom = start 00:00 → end 23:59:59, capped at now (Europe/Bucharest).
- **Invalid custom intervals are rejected**: reversed, in the future, impossible dates, missing dates (HTTP 422 + Romanian message).
- **Validation**:
  - Negative difference → rejected (unavailable).
  - Anomalous readings after the chosen end reading (for example an odometer reset) → partial, with a "Discontinuitate" note.
  - Missing data → `value: null`, never 0.
  - A real zero (parked through the whole period) → `confirmed_zero: true`.

**Semi-trailers** (`tip_vehicul` starts with `semiremorca`):

1. **All** `vehicule_cuplaje` rows of the trailer, plus one query that finds the same tractors coupled to *another* trailer at the same time (conflict).
2. `buildCouplingIntervals`:
   - Each coupling is clipped to the period and to now; couplings that don't overlap the period are skipped.
   - An active coupling ends at the period end or now.
   - Overlaps: the later coupling starts where the earlier one ended. One that is fully covered becomes `duplicate` and is not counted.
   - `data_end < data_start` and "inactive without an end date" are marked `invalid`.
3. Per interval and provider: the **same** boundary functions as for direct vehicles, applied to the tractor's series. Readings are loaded once per tractor and provider, not once per interval.
4. `trailerSummary`:
   - The total adds only validated km.
   - Coverage is `complete` only if every analysed interval is complete. Otherwise it is `partial`, and the validated subtotal is shown as partial. With no km at all it is `unavailable`.
   - It returns: analysed, calculated, complete, incomplete and conflicting interval counts, earliest coverage, latest measurement, and the per-interval breakdown.
- The tractor's absolute odometer is never shown as the trailer's. Uncoupled periods add nothing, and uncoupling does not reset the history.
- The manual hubodometer and the Centru policies are not touched.

### 1.6 Response (one per period, all three sources)

```text
{ ok, generated_at, vehicle{id,plate,type,is_trailer}, period{code,label,start,end,absolute,short}, order[sas,can,cardoil],
  sources{ sas|can|cardoil: { provider, provider_label, label, measurement_type (gps_km_index|can_odometer|pump_odometer),
    mode (absolute|distance), via_coupling, value, status (ok|stale|partial|unavailable), coverage (complete|partial|unavailable),
    freshness, last_checked_at, measured_at, interval{from,to}, boundaries{start{km,at,precision},end{…}}, confirmed_zero,
    anomalies, reason, notes[], trailer{analysed,calculated,complete,incomplete,conflicts,earliest_coverage,latest_measurement,
    total_km,coverage,intervals[…]} } } }
```

### 1.7 UI

- **States**:
  - Direct vehicles: "Km desfășurare" (SAS GPS), "Km CAN", "Km bord" (CardOil). For a period: "Km parcurși GPS / CAN / bord".
  - Semi-trailers: "Km desfășurare", "Km CAN", "Km parcurși", with the meta line "…, prin cuplare".
- **Card content**: the value in ro-RO format and the label. The meta line shows provider • date (absolute) or provider • period, with a coloured dot: green = complete and current, amber = partial or stale, grey = unavailable.
- **Navigation**:
  - Arrows go in a circle, SAS GPS → SAS CAN → CardOil → SAS GPS, in both directions. ← / → work on the focused card.
  - Three dots (`role=tab`, `aria-selected`) for direct navigation.
  - Horizontal slide with a fade; no animation when `prefers-reduced-motion` is set. No auto-rotation.
- **Period**: a small calendar button in the corner opens a popover. Custom intervals use date fields inside the popover. The button turns blue when a period other than "Până în prezent" is active. The period stays selected while switching sources.
- **Details**: click the meta line. The popover shows source, measurement type, period, value, start and end readings (with precision), coverage, freshness, last sync, reasons and notes. For semi-trailers it adds "Calculat pe baza istoricului de cuplare", the interval counts and a table of couplings.
- **Errors and unavailable data**: unavailable sources show "—" plus a reason ("sursă indisponibilă", "citiri insuficiente", "istoric de cuplare indisponibil"…). `km_bord` is never used as a fallback.

---

## 2. Validation

### 2.1 Tests: `php scripts/test_vehicle_mileage.php` → **49 passed, 0 failed** [V]

- **Periods**: boundaries; custom end-date inclusion capped at now; 4 rejected invalid intervals.
- **Direct telemetry**:
  - parked at both ends → complete
  - moving at the start → partial
  - confirmed 0
  - no coverage → unavailable, not 0
  - no reading before the start → partial
  - odometer reset → partial, no negative value
  - sentinel 16777215 excluded
- **CardOil**:
  - only fill-ups inside the interval are used
  - 72 h tolerance → complete or partial
  - 0 or 1 fill-up → unavailable
  - pump typo excluded
- **Semi-trailers**:
  - the spec's example (A 4,200 + B 5,800 + C 2,850) = **12,850 complete**
  - tractor km before and after the coupling are excluded
  - uncoupled periods add nothing
  - period across two couplings
  - repeated coupling to the same tractor
  - active coupling: no km assumed after the last reading
  - duplicate not counted twice
  - two invalid records
  - partial overlap trimmed
  - conflicting tractor assignment excluded → partial
  - missing tractor readings → partial subtotal
- **Local DB (read-only)**:
  - 64 vehicles × 5 periods run, with no negative values and no "unavailable with a value".
  - "Până în prezent" equals Centru Kilometraj's current reading in **40/40** SAS comparisons.
  - Checksums of `vehicule (km_bord, km_revizie, updated_at)`, `vehicle_odometer_readings/baselines/sources`, `vehicule_cuplaje`, `km_center_setari` and `fuel_fillups` are identical before and after.

### 2.2 B 265 NET (id 79), local DB [V]

| Period | SAS GPS | SAS CAN | CardOil |
|---|---|---|---|
| Până în prezent | **497,175.2** @ 27.10.2026 18:43, stale (last check 28.10) | — "Sursă indisponibilă" (no CAN mapping locally) | **497,270** @ 06.11.2026 16:22, stale (sync 11.11) |
| Luna curentă (11.2026) | — citiri insuficiente (no SAS reading after 27.10 locally) | — | 730, partial (04.11 → 06.11) |
| Luna precedentă (10.2026) | 6,115.3, partial (start 29.09 14:24 parked; end 27.10 18:43, last reading) | — | 5,220, complete (01.10 17:04 → 30.10 11:52) |
| Anul curent | 6,903.0, partial (first stored reading 28.09) | — | 13,790, partial (02.09 → 06.11) |
| Custom 01–21.10 | 5,135.6, complete (both ends parked) | — | 3,975, complete |

These match the audit's reference values. `vehicule.km_bord` is still **29,279**.

### 2.3 Semi-trailer breakdown: B 405 NET (id 12), 4 couplings, 2 tractors, "Până în prezent" [V]

| # | Tractor | Coupled → uncoupled | Effective interval | SAS GPS start → end | km | CardOil start → end | km | Status |
|---|---|---|---|---|---:|---|---:|---|
| 11 | B 665 NET | 21.04.2026 12:07 → 05.05 12:44 | same | no readings (telemetry stored from 28.09) | — | no fill-ups in interval | — | unavailable |
| 14 | B 677 NET | 05.05 12:45 → 05.05 14:51 | same | — | — | — | — | unavailable |
| 17 | B 677 NET | 05.05 15:23 → 05.05 15:35 | same | — | — | — | — | unavailable |
| 18 | B 665 NET | 05.05 15:40 → **active** | 05.05 15:40 → now | 744,394.0 @ 28.09 07:11 → 752,919.8 @ 28.10 16:34 | **8,525.8** | 720,160 @ 03.06 09:08 → 758,159 @ 10.11 16:43 | **37,999** | partial |
| | | | | **Total SAS GPS** | **8,525.8 (partial, 0/4 complete)** | **Total CardOil** | **37,999 (partial)** | |

SAS CAN: unavailable ("Capul tractor nu are sursă SAS CAN").

- Luna precedentă (10.2026): GPS 7,258.6 (partial; end = last reading 28.10), CardOil 7,242 (**complete**, 01.10 07:27 → 29.10 07:43).
- B 805 NET (3 couplings, 2 tractors): GPS 6,555.7 partial, CardOil 28,666 partial.

Telemetry is stored only from about 28.09.2026, so every coupling from April to June is honestly reported as unavailable, and "Până în prezent" for trailers is a **partial** total.

### 2.4 Performance [V]

- **Page render**, direct vehicle: 7 SQL queries, about 16 ms (service only). Semi-trailer: 10–11 queries, about 30 ms.
- **Worst case** in the fleet-wide test: 10 queries, 66 ms.
- **Source switching**: 0 requests (measured in the browser with `performance.getEntriesByType`; the only traffic was the app's existing 5-second `approvals_live` poll).
- **Period change**: exactly 1 request.
- **Stale responses**: a race test (month, then previous month in quick succession) kept only the later answer, using `AbortController` plus a sequence number.
- **API calls**: none during render, navigation or period change; the service has no API client.

### 2.5 Browser checks (local, test session) [V]

| Check | Result |
|---|---|
| Card size at 1672×941 | All four KPI cards are 305×66 px, aligned, and the size stays the same during navigation. (The original card was about 60 px; the extra meta line from the reference design adds about 6 px.) |
| Navigation | Next ×3 → GPS→CAN→CardOil→GPS; prev; dot click; ← / → keys: all correct. |
| Popovers | Period list, custom interval with date fields, inline validation ("Data de început este după data de sfârșit."), details popover for direct vehicles and trailers. |
| Responsive | 1250 px: the provider is hidden in the meta line because the card is narrow. 768 px and 375 px: no horizontal scroll. |
| Console | No JS errors. |

Screenshots taken during the session: GPS / CAN / CardOil states, period popover, custom interval, details popover (custom period), semi-trailer details popover, tablet and mobile.

### 2.6 Regression scope

- **Writers untouched**: no code that writes `km_bord`, Revizii, tyres, components, technical health, maintenance or profitability was modified. The only shared file with a logic change is `OdometerCenterModel`, and that change is an added method; existing methods are unchanged.
- **Centru Kilometraj tests not run**: `scripts/test_centru_kilometraj.php` was **not** run. It creates `KMCTEST` fixtures and runs DDL and syncs on the DB, which conflicts with the "no DB changes" rule. Centru Kilometraj's behaviour is unchanged because no method it uses was modified.

---

## 3. Final confirmation

- `vehicule.km_bord` / `km_revizie`: unchanged, by checksum before and after the fleet-wide run, and vehicle 79 still shows 29,279 in the DB. [V]
- No historical records, couplings, T0 baselines or settings were modified (checksums). [V]
- No schema changes and no cron changes. The service runs no DDL and only reads. [V]
- No external API synchronisation was triggered (no client in the code path). [V]
- Mileage-dependent modules keep their behaviour; their code is untouched. [V, code review]
- Nothing was committed or deployed.

## 3b. History backfill (2026-11-27, local)

- `scripts/backfill_centru_kilometraj.php --from=2026-04-01`. For each SAS-mapped vehicle and each source (GPS, CAN) it fetches only the gap before the first reading already stored, in windows of up to 30 days. Writes use `INSERT IGNORE`, so re-runs don't create duplicates. It uses the same lock as the sync and stops after 3 consecutive errors.
- Anomaly flags are rewritten **exactly like Centru Kilometraj** (`revalidateStored`: the last 400 readings; older readings stay `valid`).
- `--dry-run` shows the plan without calling SAS; `--revalidate-only` redoes the flags without calling SAS.
- Local result: 626 requests, 0 errors, 40,053 new readings. GPS and CAN history now start on 01.04.2026.
- B 915 NET, 11.09–04.10 (via B 635 NET):

  | Source | Km | Coverage |
  |---|---:|---|
  | GPS | 7,972.7 | complete |
  | CAN | 7,621.2 | complete |
  | CardOil | 7,205 | complete |

- B 405 NET, Până în prezent (4 couplings): GPS 49,384.8 / CAN 51,456.9 / CardOil 37,999 km. All are partial: the first coupling (21.04) starts before the first stored reading.
- **SAS recalibrated the GPS index on some vehicles.** The SAS GPS index drops abruptly and then stays close to CAN: B 605 NET on 28.10 (1,442,483 → about 1,371,000), B 400 NET around 12.09 and B 401 NET around 08.09.
  - With the full history loaded, Centru's longest-chain rule over the last 400 readings keeps the **old** series as valid. The readings after the recalibration end up marked as anomalies in `validation_status` (B 605 NET: 155, B 400 NET: 146, B 401 NET: 106).
  - The carousel's "Până în prezent" (the last 80 readings, the same as the Centru page) shows the correct post-recalibration value. Periods that span the recalibration are marked partial with "Discontinuitate de odometru".
  - Revizii V2 relies on the stored flag, but it uses CAN when CAN is fresh. GPS is only its fallback.
  - The proper fix is to treat a downward jump followed by a consistent series as a new baseline in `OdometerCalculator::flagAnomalies`. That changes Centru Kilometraj's behaviour, so it was **not** done without approval.

## 4. Open points and limitations

1. **Local data is old**: SAS until 28.10, CardOil until 11.11, no CAN rows. CAN shows "Sursă indisponibilă" everywhere locally. CAN behaviour is covered by the same code path as GPS and by the pure tests, but has **not been seen with real CAN data**. [A]
2. **Telemetry history starts ≈ 28.09.2026** (31-day backfill at the first Centru sync). Older periods and couplings are partial or unavailable by design.
3. **A boundary is "exact" only when the vehicle was parked** (Centru rule). At month boundaries crossed while driving, the result is partial and the actual reading times are shown.
4. **The CardOil "complete" label** means both fill-ups are within 72 h of the boundaries. The km between the boundary and the fill-up are not counted, and the popover says so.
5. **B 218 NET (`camion`) has coupling records as a trailer**: it is treated as a direct vehicle because of its type. This looks like a data inconsistency.
6. **The plate prefilter** in `getCardOilFillupsForPlates` strips spaces, `-` and `.`. A plate stored with other separators would be missed by the SQL prefilter.
7. **The VPS cron for Centru Kilometraj sync is still not installed** (per project notes). Without it, "Date neactualizate" will be common on production.
