# Analysis: the "Curse în desfășurare (GPS live)" panel

*Read-only investigation, 2026-11-16. No application code, schema or data was changed.*

**Legend**
- **[C] Confirmed**: verified directly in the code (file and lines are given).
- **[I] Inferred**: suggested by the implementation, not conclusively verified.
- **[U] Unknown**: cannot be established without more information or runtime testing.

Line numbers refer to the working tree at commit `8e2c5fa`. All paths are relative to the repository root.

**How the evidence was gathered**
- Static reading of every file involved.
- Read-only `SELECT`/`SHOW` queries against the local database. These are marked "local DB".
- A read of the cached SAS file `storage/cache/sas_positions.json`.
- The test script `scripts/test_live_gps_association.php` was **not** run. It inserts rows inside a transaction it rolls back, and its constructor runs DDL, so it falls outside "no mutation".

---

## 1. Executive Summary

"Curse în desfășurare (GPS live)" is a **read-only, collapsible strip** on the Dispecer curse list page (`?page=dispecer_curse`). It puts two independent sources of truth side by side:

1. **SAS GPS (physical reality):** which vehicles are moving now or stopped recently, with their position, speed and heading.
2. **The application (`curse_dispecer`):** whether that vehicle has an *open* trip.

Core facts:

- **An open trip is defined as `curse_dispecer.ora_sfarsit IS NULL AND deleted_at IS NULL`.** There is no age limit [C].
  - `data_sfarsit` is `NOT NULL` in the schema [C, local DB], so the end *date* cannot distinguish open trips from closed ones.
- **The panel never creates, closes, resumes or modifies a trip** [C]. It has only two write-adjacent actions, and both hand over to existing screens:
  - **Revino la cursă** is a link to the edit page.
  - **Deschide cursă nouă** pre-fills the existing *Adaugă Cursă* form on the same page. It saves nothing.
- **Data flow:** the browser polls every 60 s. `DispecerCurseController::liveGpsAction` reads SAS positions from a 20 s shared disk cache, then runs batched DB lookups and returns JSON. `dispatcher-live.js` renders that JSON client-side.
- **Each vehicle gets one of four states, computed server-side:**
  - `trip`: moving, with an open trip.
  - `no_trip`: moving after the last closed trip, with no open trip.
  - `before_last_end`: moving, but before the end time recorded on the last trip.
  - `stale`: stopped, or the position is older than 3 minutes.

Most important findings (details in §9):

1. **Old forgotten trips are shown as "the associated trip".**
   - Locally there are 32 open trips, all 4–14 days old, and none under 3 days (local DB).
   - Most green "cu cursă asociată" rows are therefore likely trips the dispatcher forgot to close, not trips that are running now [I].
2. **During a SAS outage, the status is computed from a frozen `age_seconds`.**
   - Vehicles that were moving when SAS last answered stay "În mișcare" indefinitely, while the age text keeps growing [C].
3. **Trips waiting for approval or rejected (`blocks_trip`) are not filtered out.**
   - They are hidden from the Desfășurător list but appear here as associated open trips [C].
4. **Several definitions of "open trip" disagree across modules** [C]:
   - The panel uses no age limit and includes future-dated trips.
   - *Km în desfășurare* / Km pierduți ignore future trips and cut the window at the next trip start.
   - Fleet Assistant (`getRacesActiveOnDay`) still uses the old 3-day window, and its comment still says that matches this panel, which is no longer true.
5. **More than one open trip per vehicle is possible.**
   - The overlap check treats an open trip as ending at 23:59:59 on its own `data_sfarsit`, so it does not block a new trip on a later day [C].
   - The panel only *reacts* to the conflict, with an "N curse deschise · alege cursa" chooser.
6. **Multi-phase trips show phase-1 data.**
   - The vehicle used for matching is the last phase's vehicle (correct).
   - The **driver** and **start time** shown are still phase 1's (`curse_dispecer.driver_id` / `data_inceput`) [C].

---

## 2. Panel Location and Architecture

### 2.1 Where it is rendered

| Layer | File | Key symbols / lines |
|---|---|---|
| Page include | `htdocs/views/dispecer_curse/index.php:379` | `include '_live_gps_panel.php'` only when `!$embedMode`. It sits between the *Km service* card and the *Adaugă Cursă* form. |
| Markup (static shell) | `htdocs/views/dispecer_curse/_live_gps_panel.php` (88 lines) | Endpoint URLs at L15–16. `<section id="dispatcher-live" class="is-collapsed d-none">` at L22. Chips at L34–48. Tools at L50–67. Board/table header at L72–84. Script at L88 (`defer`). |
| Frontend logic | `htdocs/assets/js/dispatcher-live.js` (980 lines) | A single IIFE, `init()`. All rendering is client-side string HTML. |
| Styles | `htdocs/assets/css/dispatcher-live.css` (1340 lines) | Every rule is scoped under `.dispatcher-live`. Responsive rules at L1126–1330. Christmas-theme overrides at L116–125. |
| Backend endpoints | `htdocs/controllers/DispecerCurseController.php` | Routing at L309–314 (`live_gps`, `live_gps_movement`). Constants at L18–35. Logic at L608–1193. |
| GPS state service | `htdocs/services/SasDashboardService.php` | `getLiveOverview()` L446–456, `classifyPositions()` L464–484, `classify()` L1209–1233, `placeLabel()` L1236–1252, `getIntervalTrips()` L368–410. |
| GPS transport and cache | `htdocs/services/FleetLivePositionService.php` | `getLivePositions()` L48–95 (20 s disk cache, stale-on-error). `enrichWithLocalVehicles()` L292–320 (plate → local vehicle, `age_seconds`). |
| SAS HTTP client | `htdocs/services/SasFleetClient.php` | `getNormalizedPositions()` L345–369, `normalizePosition()` L377+. Timeout `SAS_API_TIMEOUT`, default 15 s, range 3–60 (L40). |
| Per-user hidden vehicles | `htdocs/controllers/FleetMapController.php:61–73` | `hiddenCarIdsForCurrentUser()` reads `utilizatori.harta_flota_ascunse`. |
| ACL | `htdocs/permissions/modules/dispecer_curse.php:62`, `htdocs/includes/access.php:243–262` | `live_gps` is not listed, so it needs only `dispecer_curse.view`. `live_gps_movement` needs `create`. |
| Form bridge | `htdocs/assets/js/dispecer-curse.js:2455–2490` | `window.dispecerVehicleCombos(vehicleId)`, exposed specifically for this panel. |
| Tests | `scripts/test_live_gps_association.php` | Unit tests of `liveVehicleState` plus DB association scenarios (rolled-back transaction). |

### 2.2 Backend functions (DispecerCurseController)

| Function | Lines | Role |
|---|---|---|
| `liveGpsAction()` | 608–750 | Main JSON endpoint. |
| `openRacesByVehicleId()` | 752–821 | Open trips per vehicle; handles phases. |
| `lastClosedEndByVehicleId()` | 823–878 | Latest closed end per vehicle (trips + phases). |
| `liveSegmentsTableExists()` | 880–892 | Checks once per request whether `curse_segmente` exists. |
| `liveVehicleState()` *(public static)* | 894–915 | The 4-state machine. |
| `liveRacePayload()` | 917–930 | Shape of a trip in the JSON. |
| `liveGpsMovementAction()` | 932–1019 | Proposed start time for "Deschide cursă nouă". |
| `latestRacesByVehicleId()` | 1021–1054 | Template (beneficiary / type / driver) used by the pre-fill. |
| `liveVehicleDetailsById()` | 1056–1084 | Photo, type and technical capacity. |
| `liveDriverDirectory()` | 1086–1113 | All drivers, indexed by id, name and vehicle. |
| `resolveLiveDriver()` | 1115–1165 | Driver priority: race, then GPS, then vehicle. |
| `liveNameKey()`, `liveRaceRouteLabel()`, `liveRaceStartLabel()` | 1167–1193 | Formatting helpers. |
| `buildDeletedRaceRouteLabel()` *(shared)* | 4862+ | Builds the route label. |
| `raceEditUrl()` *(shared)* | 12248–12253 | `?page=dispecer_curse&action=edit&id=N`. |

### 2.3 Background processes and scheduled updates

- **The panel itself has no server-side job** [C]. All refreshes are browser polls.
- **The SAS positions cache `storage/cache/sas_positions.json` is shared** [C]. These all read and write it:
  - Harta Flota (`FleetMapController:162`).
  - The Sandbox/Dashboard (`SasDashboardService::getSnapshot`).
  - The cron `scripts/driver_first_movement.php` (`DriverFirstMovementService:244`, run every 2–5 min according to its header).
- Whichever caller arrives first after the 20 s TTL triggers the SAS call. The cron therefore keeps the cache warm, but it does not drive the panel.

---

## 3. Current Features and User Interactions

### 3.1 Header (always visible, even when collapsed)

| Element | What the user sees | Interaction | Source / calculation |
|---|---|---|---|
| Pulse dot + title "Curse în desfășurare (GPS live)" + green count badge | Badge = `counts.active` | Click toggles collapse/expand (`toggleEl`, JS L901–910) | `counts.active` = number of SAS vehicles with status `moving` or `idle`, excluding vehicles the user hid in Harta Flota (PHP L632–636, L676) [C] |
| Chip "N vehicule active" | `counts.active` | Sets filter `all`. If the panel is collapsed, it expands it first (JS L867–877) | as above |
| Chip "N cu cursă asociată" (green) | `counts.with_trip` | Filter `with_trip`. Clicking the active chip again resets to `all` | ≥1 open trip on the local vehicle, **whether the vehicle is moving or stopped** (PHP L677) [C] |
| Chip "N fără cursă asociată" (amber) | `counts.without_trip` | Filter `without_trip` | No open trip, **including SAS vehicles with no local match** (local id 0 means no trips) [C] |
| Chip "● N staționare" (slate) | `counts.stationary` | Filter `stationary` | `state === 'stale'` (PHP L678–680). It **overlaps** the two chips above: with + without = active, and stationary is a cross-cutting subset [C] |
| Search box | Placeholder "Caută după nr. înmatriculare, șofer, locație…" | 120 ms debounce, client-side only (JS L886–895) | Matches plate, vehicle label, driver name, place, `#id` and route of the open trips. Accent-insensitive, every token must match, also matched with spaces removed (JS L158–171) [C] |
| Select "Toate vehiculele / Cu cursă asociată / Fără cursă asociată / Staționare / poziție veche" | Mirror of the chips | Expands the panel and applies the filter (JS L879–884) | Same predicates as the chips (JS L149–156) |
| "Actualizat HH:MM" | Time of the **SAS fetch** (`fetched_at`), not of the browser request | — | `String(data.fetched_at).substring(11,16)` (JS L657). Turns red with "Actualizare eșuată, se reîncearcă..." when a fetch fails after the panel has been shown once (JS L665–667) |
| Refresh button ⟳ | Spins while loading | Manual refresh. It also runs when the tab is hidden (JS L897–899, L635–636) | — |

### 3.2 Body (hidden while collapsed)

**Error banner** `[data-dl-error]` (JS L658–661):
- Text: "GPS indisponibil momentan — se afișează ultimele poziții cunoscute."
- Shown when `data.error` is set, i.e. SAS failed and the cache was served.

**Board columns** (desktop):

| Column | Content | Rules |
|---|---|---|
| Vehicul | Chevron, photo thumb (240 px; falls back to a truck icon), coloured dot (`--dl-accent`), plate, "marca model" | Photo only when the local vehicle has `poza_stocata` **and** the file exists (`upload_image_thumb_url`) |
| Șofer | Avatar (photo or initials), name, source label | Labels: "Șofer cursă", "Raportat de GPS", "Alocat vehiculului". Shows "Șofer neidentificat" when no driver resolves (see §4.6) |
| Poziție (ultima actualizare) | POI, otherwise address + city, otherwise county; plus "acum X sec/min/h" | Age is ticked locally every 1 s from `position_ts` using the server clock offset (JS L79–86, L675–686) |
| Viteză | "N km/h" | "—" when speed is null, or when the state is stale and speed is not > 0. Muted with the title "Ultima viteză raportată" when stale (JS L225–233) |
| Status | Pill | See the state table in §4.3 (JS L174–194) |
| Cursă asociată | "Cursă #id" + "Început: dd.mm.yyyy HH:MM" (+ route and type when expanded) | Shows "Fără oră de început" when `ora_inceput` is empty. Shows "N curse deschise · #a, #b · alege cursa" with a warning icon when there are 2 or more (JS L241–267) |
| Acțiuni | See §3.3 | — |

**Expandable row detail** (`detailHtml`, JS L321–414). It opens by clicking the chevron, any free area of the row, or the kebab menu → "Detalii vehicul". Only one row can be expanded at a time. It has five sections:
1. **Informații vehicul:** plate, label, type (`LIVE_VEHICLE_TYPES`), technical capacity "x,xx t" (`vehicule.capacitate_transport`, not the load), large photo, and "Nu există în fișa vehiculelor Fleet" when the vehicle has no local match.
2. **Informații șofer:** name, ID, source and phone (`tel:` link). The phone is shown **only when the user has `soferi.view`** (PHP L649).
3. **Map:**
   - Leaflet 1.9.4 is loaded lazily from unpkg with SRI, using OSM tiles at zoom 12 (JS L547–626).
   - There is a single map instance, reused across rows; the marker colour follows the state.
   - It also shows a place card and lat/lng to 4 decimals.
   - It shows "Locație indisponibilă" when there are no coordinates, and "Harta nu a putut fi încărcată" when the CDN fails.
4. **Detalii cursă:**
   - **One open trip:** shows #id, route, type, beneficiary, start, "Deschisă — fără oră de sfârșit" and a "Revino la cursă" button.
   - **Several open trips:** shows a warning plus the chooser.
   - **No open trip:**
     - In `no_trip` state: "Nicio cursă deschisă. GPS arată deplasare nouă. Ultima cursă încheiată: …".
     - In any other state: "… Deplasare nouă neconfirmată de GPS."
     - Plus "Deschide cursă nouă" when that is allowed.
5. **Viteză & direcție:** large speed, or "Viteză neconfirmată"/"Oprit"; compass label (N/NE/…/NV, plus degrees, only while moving); and "Ultima actualizare X min în urmă".

**Empty states** (JS L478–486, view L83):
- "Se încarcă pozițiile din SAS..." before the first load.
- "Niciun vehicul în mișcare acum." when no vehicles are returned.
- "Niciun vehicul nu corespunde filtrului sau căutării." when the filter or search hides everything.

### 3.3 Actions per row (`actionsCell`, JS L295–319)

| Action | Shown when | What happens |
|---|---|---|
| **Revino la cursă** (link) | Exactly 1 open trip (`vehicle.race`) | Navigates to `?page=dispecer_curse&action=edit&id=N` (full page load) |
| **Revino la cursă ▾** (dropdown) | 2 or more open trips | Lists `#id · start · route` (plus "(fără oră de început)"); each item links to its edit page. The dispatcher chooses; the code never picks one [C] |
| **Deschide cursă nouă** | `state === 'no_trip'` **and** no open trip **and** user has `dispecer_curse.create` **and** a local vehicle id exists **and** `#add-race-form` is on the page (JS L129–133, L291–293). Never shown together with "Revino" | Pre-fills the add form (see §4.5). Saves nothing |
| Map icon / "Vezi pe hartă" | User has `harta_flota.view` and the vehicle has an SAS id | Navigates to `?page=harta_flota&car=<sas id>` |
| Kebab ⋮ → "Detalii vehicul / Ascunde detaliile" | always | Toggles the detail row |
| Kebab ⋮ → "Copiază coordonatele" | coordinates present | `navigator.clipboard.writeText("lat, lng")` to 5 decimals. Errors are swallowed and there is no success feedback [C] |

### 3.4 Collapse behaviour (JS L854–962)

- The panel **always starts collapsed**, on every page load and after saving a trip. The state is not remembered, and the old `localStorage['dlgCollapsed']` key is actively removed (L901–905).
- When open, it **collapses itself automatically** in three cases:
  - The page scrolls past its bottom edge. Scroll position is corrected so the content below does not jump (L916–948).
  - The user clicks anywhere outside it. Clicks inside modals, `.race-view-overlay` or `[role=dialog]` do not count (L950–962).
  - "Deschide cursă nouă" is used. It collapses before scrolling to the form (L749).
- The whole `<section>` stays `d-none` until the first successful JSON response. It is hidden for good when `credentials === false` (L645–646).

---

## 4. Business Rules and Trip Lifecycle

### 4.1 Which fields decide whether a trip is "in progress"

**For the trip itself (the application side)** [C], in `openRacesByVehicleId`, L775–792:

```sql
WHERE c.deleted_at IS NULL
  AND c.ora_sfarsit IS NULL
  AND (c.vehicle_id IN (…) OR c.id IN (SELECT sg.cursa_id FROM curse_segmente sg
                                       WHERE sg.deleted_at IS NULL AND sg.vehicle_id IN (…)))
ORDER BY c.data_inceput DESC, COALESCE(c.ora_inceput,'00:00:00') DESC, c.id DESC
```

- **Start of a trip:** `data_inceput` (NOT NULL) + `ora_inceput` (nullable). Only used for display and sorting; it is **not** a condition. A trip with a **future** start and no end counts as open [C].
- **End of a trip:** `ora_sfarsit` alone. `data_sfarsit` is NOT NULL (schema, local DB); a trip saved without an end gets `data_sfarsit = data_inceput` (docblock L753–759).
  - Locally, 10 of 32 open trips have `data_sfarsit ≠ data_inceput` (local DB). An end *date* with no end *time* also exists, and the trip stays open.
- **A missing end time keeps the trip active forever.** The old 3-day window was removed on 2026-10-30 [C].
- **Deleted trips** (`deleted_at` set) are excluded. **Restored trips** (`deleted_at` back to NULL) reappear immediately if `ora_sfarsit` is NULL [C].
- **Incomplete trips** (missing `ora_inceput`, beneficiary, etc.) are still open. They are only flagged with "Fără oră de început" [C].
- **Trips awaiting approval** (`inactive_resource_approvals.blocks_trip = 1` with status `pending`/`rejected`) are **not excluded** (no `tripAwaitingApprovalSql()` filter, which is defined in `InactiveResourceApprovalModel.php:816–824`) [C].
- **Billing status** (`status`) is **not** a condition. An open trip that is already marked as invoiced would still show [C]. Whether that combination exists in practice is [U].

**For the vehicle (the GPS side)** [C]:

| Step | Rule | Where |
|---|---|---|
| SAS status `moving` | speed > 0 and age ≤ 600 s | `SasDashboardService::classify`, L1209–1233 |
| SAS status `idle` | age ≤ 1800 s and `trigger_event ≠ 15` (15 = parked heartbeat) | same |
| `parked` / `offline` | age ≤ 24 h / older | same |
| Panel population | Only `moving` and `idle`, minus the user's hidden vehicles | `liveGpsAction` L632–636 |
| "Fresh moving" | status `moving` **and** age ≤ **180 s** (`LIVE_FRESH_SECONDS`) | L673 |

So a vehicle shows in the panel even with no trip, and an **open trip on a parked or offline vehicle never appears in the panel at all** [C].

### 4.2 Multi-phase trips (`curse_segmente`)

- A trip is matched if phase 1's vehicle **or** any non-deleted phase's vehicle is in the active set. It is then **re-assigned to the vehicle of the last non-deleted phase, by `ordine`** (L795–818) [C].
- Openness still comes from the parent `curse_dispecer.ora_sfarsit`. A last phase with an end time under a parent without one stays open. There are 0 such trips locally (local DB).
- The displayed **start time, driver and beneficiary come from the parent row (phase 1)**. `curse_segmente` has its own `driver_id`, `data_inceput` and `ora_inceput`, which are not used here [C].
- "Last closed end" (`lastClosedEndByVehicleId`, L831–878):
  - Uses `MAX(TIMESTAMP(data_sfarsit, ora_sfarsit))` over closed trips **without** phases.
  - Plus closed phases (`sg.ora_sfarsit IS NOT NULL`), each counted on its own vehicle and including phases of still-open parents [C].

### 4.3 The state machine — `liveVehicleState()` (L902–915) [C]

| Input | State | Pill | Accent / marker |
|---|---|---|---|
| not fresh moving (idle, or moving with age > 180 s) | `stale` | "Staționare" (idle) or "Ultima poziție > 3 min" (moving but late) | slate |
| fresh moving + ≥1 open trip | `trip` | "În mișcare" | green |
| fresh moving + no open trip + (no closed trip **or** position time > last closed end) | `no_trip` | "În mișcare · fără cursă" | amber |
| fresh moving + no open trip + position time ≤ last closed end | `before_last_end` | "În mișcare · înainte de sfârșitul ultimei curse" (slate pill) | **green** (no CSS rule; see §9) |

Notes:
- An open trip stays associated even when the vehicle is `stale`. The trip column and "Revino" stay visible.
- `before_last_end` only happens when a closed trip's recorded end lies **in the future** relative to the GPS position, i.e. the end time was typed ahead of time. There are 0 such trips locally (local DB).

### 4.4 Several open trips on the same vehicle

- **Possible in data.** Locally, 2 vehicles have 2 open trips each (local DB) [C].
- **Why the data layer allows it:** `DispecerCurseModel::findOverlappingRace` (L4656–4710) [C]:
  - Only compares trips that have `ora_inceput`.
  - Treats an open trip as ending at `data_sfarsit 23:59:59`, so a new trip on a later day does not overlap it.
  - Only checks `c.vehicle_id` (phase 1), not phases.
- **Panel behaviour** [C]:
  - `race = null` and `open_races = [all]`.
  - The trip column shows "N curse deschise".
  - "Revino" turns into a chooser; "Deschide cursă nouă" is suppressed.
  - The driver is resolved without a race, so it falls back to GPS or vehicle.

### 4.5 Can the panel create, update, resume or close a trip?

| Operation | Behaviour |
|---|---|
| Create | **No direct create.** "Deschide cursă nouă" only **pre-fills** the add form (JS L707–756) [C]. Steps: (1) beneficiary + transport type, (2) vehicle, (3) driver, (4) start time, (5) the dispatcher saves. |
| Update / Close / Resume | **No.** It only links to the edit page, where closing (setting `ora_sfarsit`) and *Reia cursa* (resume) happen [C]. |
| Any DB write from the panel endpoints | **None.** Both endpoints only run SELECTs and the SAS client [C]. The SAS session token file is written as a side effect of SAS calls (`persistSasSession`). |

**Pre-fill steps for "Deschide cursă nouă"** [C]:
1. **Beneficiary + transport type** come from `window.dispecerVehicleCombos(vehicleId)`, i.e. the combinations configured in Configurare Transport. The vehicle's latest trip (`latestRacesByVehicleId`) is used only as a tiebreaker or fallback.
2. **Vehicle:** set next. If the vehicle is not in that combination's list, the code selects `__show_all_vehicles__`, which triggers the form's native "Vehicul neconfigurat pe rută" dialog.
3. **Driver:** the last trip's driver. Otherwise, if the select has exactly one real option, that option.
4. **Start time:** a second request, `live_gps_movement`. The proposed start is the first SAS travelsheet segment that:
   - starts more than 300 s after the last closed end,
   - is at least 0.5 km long,
   - and only if the last closed end is within 72 h.

   Otherwise a warning note asks for manual entry. A visible note says nothing is saved until "Adaugă Cursă" is pressed (JS L761–806, PHP L939–1019).
5. **Save:** the dispatcher must press "Adaugă Cursă". All server validations apply.

### 4.6 Driver resolution — `resolveLiveDriver()` (L1120–1165) [C]

The driver shown is picked in this order:
1. The open trip's `driver_id`, matched in `soferi`. Otherwise the trip's `sofer_nume`. Shown as "Șofer cursă".
2. The SAS-reported driver name, matched by name (case-, space- and diacritic-insensitive, also with the name reversed). It is shown even if no local record matches. Shown as "Raportat de GPS".
3. The driver assigned to the vehicle (`soferi.vehicle_id`, status ≠ `inactiv`), **only if exactly one** exists. Shown as "Alocat vehiculului".
4. Otherwise `null`, shown as "Șofer neidentificat".

When there are several open trips, `$race` is null, so step 1 is skipped [C].

### 4.7 Vehicle movement without a trip

- Only flagged as `no_trip` (amber). Nothing is created, logged or notified by the panel [C].
- *Separately*, the cron `DriverFirstMovementService` sends WhatsApp messages on the first movement of the day. It is independent of the panel and only shares the cache [C].

### 4.8 Conflicts between GPS and manual data

- **The application is always authoritative for trip state. GPS never overrides it** [C].
- The `before_last_end` state exists precisely so that a manually entered future end time is not contradicted. It is shown neutrally rather than as an alert.
- **Plate matching** is the only link between SAS and local vehicles. The plate is normalised by stripping non-alphanumerics and upper-casing, and `STOC-ANVELOPE` is excluded (`FleetLivePositionService::getLocalVehiclesByPlate`, L323–345) [C].
  - A plate mismatch means "no local vehicle". The row then shows as "fără cursă" with no create action.

### 4.9 Rules enforced in code vs. rules only implied by the UI

| Rule | Enforced? |
|---|---|
| Open trip = no `ora_sfarsit` | **Code** (SQL) |
| One open trip per vehicle | **Not enforced.** The UI only implies it, through the chooser and by hiding "Deschide cursă nouă" while any open trip exists. The add form still accepts a second open trip for the same vehicle on another day. |
| "Deschide cursă nouă" only when GPS shows new movement | **UI only** (button visibility). `live_gps_movement` re-checks for an open trip (L948–952) but does not check the state. |
| Proposed start time is the first real movement after the last closed trip | **Code** (L983–1002) |
| Trip shown only if the vehicle is moving/idle | **Code** (the population filter) |
| User-hidden vehicles excluded | **Code** (per user, shared with Harta Flota and the Km pierduți card) |

---

## 5. Data Sources and Synchronization

### 5.1 End-to-end flow

```
SAS API (/api/info, currentpositions)
   │  SasFleetClient (cURL, timeout 15 s default, session token persisted in storage/cache/sas_session.json)
   ▼
FleetLivePositionService::getLivePositions()
   • disk cache storage/cache/sas_positions.json, TTL 20 s, shared by ALL users/pages/cron
   • car list cache sas_cars.json, TTL 600 s
   • on SAS error → returns the last cache (any age) + error text
   • enrich: plate → local vehicle (id, marca/model, tip), age_seconds = now − timestamp (computed AT FETCH TIME)
   • drops positions without lat/lng
   ▼
SasDashboardService::getLiveOverview() → classify(): moving / idle / parked / offline, place label; sort moving→idle, then by age
   ▼
DispecerCurseController::liveGpsAction()  (GET ?page=dispecer_curse&action=live_gps, ACL: dispecer_curse.view)
   • filter moving|idle, minus user's hidden cars
   • 6 batched DB reads: open races (+segments), last closed ends (+segments), latest race, vehicle details, all drivers
   • per vehicle: state, driver, photo, payload
   ▼
JSON {success, credentials, fetched_at, server_ts, fresh_seconds, error, counts, permissions, map_url, vehicles[]}
   ▼
dispatcher-live.js: refresh() → state → renderCounters() + render() (innerHTML), tick() each 1 s
```

### 5.2 Endpoints

| Endpoint | Method | ACL | Response |
|---|---|---|---|
| `?page=dispecer_curse&action=live_gps` | GET | `dispecer_curse.view` | See above. HTTP 502 `{success:false}` on exception. `{success:true, credentials:false, vehicles:[]}` when SAS is not configured. |
| `?page=dispecer_curse&action=live_gps_movement&vehicle_id=N` | GET | `dispecer_curse.create` | `{success, start:{date,time,label}, last_end_label}`, or `start:null` + `reason` (`open_race` / `no_gps` / `no_reference` / `no_movement`) + `message`. 422 for an invalid id, 502 on exception. Calls the SAS **travelsheet** live; this call is **not cached**. |

### 5.3 Shape of one row in `vehicles[]`

`key` (SAS id or plate), `sas_vehicle_id`, `plate`, `vehicle_label`, `vehicle_type`, `capacity_t`, `vehicle_photo{thumb,large}`, `local_vehicle_id`, `driver{id,name,source,photo,phone}`, `status` (SAS), `state` (panel), `speed` (int), `heading` (only when moving), `place`, `latitude`, `longitude`, `position_ts`, `age_seconds`, `race{id,url,tip_transport,route,start_label,start_time_missing,beneficiar}`, `open_races[]` (only when 2 or more), `last_end{ts,label}`, `prefill{beneficiar_id,tip_transport,driver_id}` (only when there is no open trip).

### 5.4 Refresh, polling and caching

**Polling** (JS L22, L635–636, L964–972) [C]:

| Trigger | Behaviour |
|---|---|
| Automatic | Every 60 s (`REFRESH_MS`). Skipped while the tab is hidden. |
| Tab becomes visible | Immediate refresh. |
| First load | Always, even in a background tab. |
| Manual ⟳ | Always. |
| Overlap guard | `state.loading` prevents overlapping requests. |

**Rendering:**
- Each refresh rebuilds the rows' `innerHTML`. The expanded row and the map instance are preserved.
- If a dropdown is open, the render is deferred until it closes (`pendingRender`, L465–469, L850–852) [C].

**Caching:**
- Server: SAS positions are cached 20 s on disk (shared). The DB lookups are not cached; they run on every poll from every open tab [C].
- Freshness: the "Actualizat" label is the SAS cache time. Data can be up to about 80 s old (20 s cache + 60 s poll) [I].

### 5.5 Detecting new or changed trips

- There is **no change detection or push**. A new trip, a closed trip or a deleted trip shows up on the next 60 s poll or a manual refresh [C].
- After "Adaugă Cursă" the page reloads (normal form POST). The panel starts collapsed again [I, from the comment at JS L902–903].

### 5.6 Failure modes

| Situation | Behaviour |
|---|---|
| SAS not configured | Panel stays hidden (`d-none`) permanently [C] |
| SAS fails, cache exists | Last cached positions are served, plus the amber banner "GPS indisponibil momentan…" [C]. **Status/state are computed from the cached `age_seconds`, not recomputed**, so they freeze (see §9 P2) [C] |
| SAS fails, no cache | Empty list ("Niciun vehicul în mișcare acum.") plus the banner [C] |
| SAS slow | The request blocks up to the cURL timeout (15 s default) in PHP; the browser fetch has no timeout of its own [C]. Each poll after the TTL retries SAS, with no backoff [C] |
| Endpoint exception | HTTP 502 JSON. If the panel was already visible, the label shows "Actualizare eșuată, se reîncearcă..."; if not, the panel **stays hidden silently** [C] |
| Session expired / 403 | The response is not JSON, so `response.json()` throws and the result is the same as the row above [I] |
| SAS fields missing | `speed`/`heading`/`place` become null and are shown as "—", "Locație indisponibilă" or "Șofer neidentificat". Positions without coordinates are dropped completely [C] |
| `Fake` flag (`gps_signal_missing`) / `disabled` | Read from SAS but **not used** in the classification or the panel [C] |
| Leaflet CDN blocked | "Harta nu a putut fi încărcată" in the detail [C] |

---

## 6. Database Structure and API Dependencies

### 6.1 Tables read

| Table | Columns used | Notes |
|---|---|---|
| `curse_dispecer` | `id, vehicle_id (NOT NULL), driver_id, beneficiar_id, tip_transport, data_inceput (NOT NULL), ora_inceput (NULL), data_sfarsit (NOT NULL), ora_sfarsit (NULL), deleted_at, loc_plecare, loc_aspirare, loc_livrare, loc_livrare_cursa, loc_incarcare_id, zona_distributie_id` | Indexes: `vehicle_id`, `data_inceput`, `deleted_at`. **No index on `ora_sfarsit`** (local DB) |
| `curse_segmente` | `cursa_id, ordine, vehicle_id, data_sfarsit, ora_sfarsit, deleted_at` | Also has `driver_id, data_inceput, ora_inceput`, which this panel **does not** use |
| `soferi` | `id, nume, poza_stocata, telefon, vehicle_id, status` | The **whole table** is read on every poll |
| `vehicule` | `id, tip_vehicul, capacitate_transport, poza_stocata` (+ `nr_inmatriculare, marca, model` in the cache enrichment) | — |
| `configurare_beneficiari_transport`, `configurare_locuri_incarcare`, `configurare_zone_distributie` | `nume` | Joins for the route and beneficiary labels |
| `utilizatori` | `harta_flota_ascunse` (JSON) | Per-user hidden SAS ids |

**No tables are written.** Files written as side effects: `storage/cache/sas_positions.json`, `sas_cars.json` and `sas_session.json`.

### 6.2 Local data snapshot (read-only, 2026-11-16 09:12)

| Metric | Value |
|---|---|
| Open trips (`ora_sfarsit IS NULL`, not deleted) | 32 |
| … of which started ≤ 3 days ago / 4–14 days / > 14 days | 0 / 32 / 0 |
| … with a future start | 0 |
| … without `ora_inceput` | 2 |
| … with `data_sfarsit ≠ data_inceput` (end date set, no end time) | 10 |
| … with active phases | 9 |
| Vehicles with more than one open trip (phase-1 vehicle) | 2 (max 2) |
| SAS positions in cache | 48 |

This matches the screenshot ("Km în desfășurare: 32 curse deschise · 30 vehicule · 32 de ≥3 zile") and the panel's "23 cu cursă asociată".

### 6.3 External APIs

| Service | Used for | Used by |
|---|---|---|
| SAS Fleet: `/api/info` (car list) and current positions | Vehicle positions | Main endpoint, through the shared cache |
| SAS travelsheet (`getTravelSheet`) | Proposed start time | Movement endpoint only |
| unpkg.com (Leaflet 1.9.4 JS/CSS, SRI-pinned) and tile.openstreetmap.org | Detail map | Browser only |

---

## 7. UI/UX Structure

### 7.1 Layout and placement

**Order on the page:**
1. Page head: "Dispecer curse", the "curse cu informații lipsă" button, Configurare Transport.
2. Km service card.
3. **This panel** (full width).
4. Adaugă Cursă form + insight cards (Km pierduți, Km în desfășurare, Alimentări).
5. Filters.
6. Desfășurător list.

**Shape:** a white card (12 px radius, border `#e3e8ef`) with:
- **Header:** title with badge, plus chips on the left; search, select, updated-time and refresh on the right.
- **Body:** a CSS-grid "table" with ARIA roles `table/row/cell`.

### 7.2 Colour tokens (CSS L5–25)

| Colour | Hex | Meaning |
|---|---|---|
| Green | `#16a34a` | trip / default accent |
| Amber | `#f59e0b` | no_trip |
| Slate | `#64748b` | stale |
| Blue | `#1d64d8` | buttons / links |

- Each row's left border, dot and expanded background follow `data-state` (L408–422).
- Map markers follow the same mapping (L1023–1035).

### 7.3 Sorting and grouping

- **Order:** the server order from `classifyPositions`, i.e. `moving` before `idle`, then by freshest position [C].
- **No user sorting and no grouping.** Rows that need action (`no_trip`) are not pinned to the top [C].

### 7.4 Responsive behaviour (CSS L1126–1330)

| Width | Change |
|---|---|
| ≤ 1500 px | Narrower columns; the detail grid becomes 6 columns (3+3 / 2+2+2) |
| < 1200 px | **The Poziție column is hidden.** Position is visible only in the expanded detail |
| < 992 px | **The Cursă asociată column is hidden.** The trip is visible only in the detail or through the actions; the detail becomes 2 columns |
| < 768 px | The table header is hidden. Each row becomes a card: vehicle + speed, driver + status, actions |
| `prefers-reduced-motion` | Animations disabled |

### 7.5 Loading, empty and error states

All are listed in §3.2 and §5.6. There is **no skeleton or spinner** in the body; only the refresh icon spins.

### 7.6 Usability limitations observed (not fixed)

1. **Collapsed by default, and it collapses itself** on scroll past it or on any outside click. A dispatcher watching it while working in the form below loses it repeatedly.
2. **The chip counters overlap.** "staționare" is a subset of both with and without, and the header badge equals "vehicule active". This is easy to misread as a partition.
3. **"Cu cursă asociată" mixes current and forgotten trips.** A 10-day-old unclosed trip looks exactly like one started this morning: no age badge, no "≥3 zile" flag. The *Km în desfășurare* card does have that flag.
4. **The two most important columns disappear on smaller screens:** Poziție below 1200 px and Cursă asociată below 992 px.
5. **No sort or grouping by state.** Amber "fără cursă" rows can be buried below green rows.
6. **The "Actualizat" time is the SAS fetch time.** Under a SAS outage the label stays at the old time and the banner sits inside the collapsed body, so a collapsed panel shows no error at all.
7. **"Copiază coordonatele" gives no feedback.**
8. **Labels differ between the chip and the select:** the chip says "staționare", the select says "Staționare / poziție veche". The pill "Ultima poziție > 3 min" is counted under "staționare".
9. **Vehicles without a local match** (plate mismatch) show as amber "fără cursă" with no action available and no explanation in the row. The note exists only in the detail.

---

## 8. Integration With Other Fleet App Modules

| Module | Relationship | Impact of changes |
|---|---|---|
| **Adaugă Cursă (same page)** | The panel writes into `#race_beneficiar_id`, `#race_tip_transport`, `#race_vehicle_id`, `#race_driver_id` and `#race_start_datetime` (dispatching `change` events), injects a `[data-dl-prefill-note]` alert, and depends on `window.dispecerVehicleCombos` (`dispecer-curse.js:2460`). Field ids come from `_race_form_fields.php` with `$fieldPrefix='race'` (`index.php:421`). | Renaming form ids or changing the cascade breaks the pre-fill **silently**: `setSelectValue` returns false and nothing is shown. |
| **Editare cursă** | "Revino la cursă" links to `action=edit&id=N`. Closing happens there (`ora_sfarsit`), as does "Închide cu km GPS" from the Km în desfășurare card. | Any change to how a trip is "closed" changes this panel's association. |
| **Desfășurător list** | Same `curse_dispecer`, but the list hides `blocks_trip` trips; the panel does not. | Inconsistent visibility (§9 P3). |
| **Trip overlap / validation** (`DispecerCurseModel::findOverlappingRace`, `tripConflictCheckAction` L513) | It defines what the form allows, and therefore whether several open trips can exist. | Tightening it (e.g. "at most one open trip per vehicle") would simplify the panel's chooser logic. |
| **Phases / Reia cursa** (`curse_segmente`) | The vehicle of the last phase is used for matching, and phase ends count as "last closed end". | Changes to phase semantics affect both the matching and the `no_trip` / `before_last_end` decision. |
| **Km în desfășurare / Km pierduți** (`KmPierdutiController::openTripWindowsByVehicle`, ~L656–720; card in `dispatcher-insights.js`) | Same "open = `ora_sfarsit IS NULL`" rule and the same per-user hidden vehicles (L113). They **differ** on future trips (ignored there), on the window cut at the next trip, and on "≥3 zile" flagging. | Users see "32 curse deschise / 30 vehicule" there vs "23 cu cursă asociată" here; these are different populations by design (moving/idle only here). |
| **Fleet Assistant API** (`DispecerCurseModel::getRacesActiveOnDay`, L4858–4905) | Uses open trips **from the last 3 days only**, and its comment says this matches the GPS panel; that stopped being true on 2026-10-30. | Its documentation has drifted; the two answers to "which trips are running" differ. |
| **Curse cu informații lipsă** (`_open_races_panel.php`, `getOpenRacesOverview` L3572) | A different concept: trips with the default billing status and missing data. The word "deschise" is used for both. | A naming collision to keep in mind for a redesign. |
| **Harta Flota** | Shares the SAS cache, the hidden-vehicle selection (`utilizatori.harta_flota_ascunse`) and the "Vezi pe hartă" deep link `?page=harta_flota&car=`. | Hiding a vehicle on the map also removes it from this panel and from the Km pierduți card. |
| **DriverFirstMovementService (cron, WhatsApp)** | Shares the SAS positions cache and the 0.5 km movement threshold. Otherwise independent. | Changing the cache TTL or file affects the cron, the map and the dashboard together. |
| **SAS Dashboard sandbox** | Same `SasDashboardService` classification thresholds (600 / 1800 / 86400 s). | Changing `classify()` changes which vehicles appear here. |

---

## 9. Existing Problems and Technical Risks

### 9.1 Confirmed problems (verified in code)

**P1. Forgotten open trips are associated as if they were current.**
- No age limit, and no check that the trip's start is before the GPS movement (`openRacesByVehicleId` L787–790).
- Every local open trip is 4–14 days old (local DB), so the green "În mișcare" plus "Cursă #N" is very likely an old unclosed trip.
- **Consequences:**
  - "Deschide cursă nouă" is suppressed for the real new departure.
  - The amber alert never appears for these vehicles.

**P2. A SAS outage freezes the status.**
- `age_seconds` is computed once, when the payload is fetched (`FleetLivePositionService` L307–309), and stored in the cache.
- On SAS failure the old cache is returned (L83–94), and `classify()` and `liveGpsAction` (L666, L673) use the stored age.
- **Consequences:**
  - A vehicle that was moving at the last successful fetch stays `moving`/fresh, i.e. `trip` or `no_trip`, for the whole outage.
  - Meanwhile the browser shows the age growing (it uses `position_ts`).
  - The counters and the population (moving/idle) freeze too.

**P3. Trips awaiting approval or rejected are shown as associated open trips.**
- `openRacesByVehicleId` has no `tripAwaitingApprovalSql` filter (compare `InactiveResourceApprovalModel.php:816–824`, which the Desfășurător list applies).

**P4. Future-dated open trips count as current.**
- No `data_inceput ≤ now` condition.
- KmPierduti explicitly skips them ("Cursele programate in viitor nu au inceput inca").
- There are 0 locally today, but the rule differs between modules.

**P5. Multi-phase trips show phase-1 driver and start.**
- `curse_dispecer.driver_id` / `data_inceput` / `ora_inceput` are displayed and used for the driver, while the vehicle comes from the last phase (L776–778, L814, L1126–1133).
- For a trip that changed driver in phase 2, the panel shows the phase-1 driver as "Șofer cursă".

**P6. The pre-fill template ignores phases.**
- `latestRacesByVehicleId` (L1036–1053) looks only at `curse_dispecer.vehicle_id`, i.e. phase 1.
- A vehicle that only appears as a later phase gets no template.
- The order uses `data_inceput, id` but not `ora_inceput` (ties on the same day are broken by id).

**P7. `before_last_end` has no styling of its own.**
- There are no `[data-state="before_last_end"]` rules for the row accent or the map marker (CSS L408–422, L1034–1035).
- The row dot, the expanded border/background and the marker are therefore **green** like `trip`, while the pill is slate.

**P8. A stale comment and contract in Fleet Assistant.**
- `getRacesActiveOnDay` (L4858–4861) says "same window as the GPS live panel (3 days)". The panel has had no window since 2026-10-30, while Fleet Assistant still uses 3 days (L4905).

**P9. The panel stays silently invisible if the first load fails.**
- The section starts as `d-none` (view L22) and is shown only on success (JS L646).
- The error label is set only when `everShown` (L666).
- A failing endpoint, an expired session or a 502 on first load leaves no trace for the user.

**P10. `gps_signal_missing` (`Fake`) and `disabled` are ignored.**
- Both are normalised from SAS (`SasFleetClient` L404) but never consulted in `classify()` or the panel.

**P11. Several open trips per vehicle are allowed by the form.**
- `findOverlappingRace` (L4656–4710) closes an open trip at the end of its own `data_sfarsit`, skips trips without `ora_inceput`, and ignores phases.
- 2 vehicles currently have 2 open trips each (local DB).

### 9.2 Potential risks (not observed failing)

**R1. DB load per poll.** Every open Dispecer tab runs 6 queries per minute.
- Two of them have no row limit: all drivers, and **every non-deleted trip** of each active vehicle in `latestRacesByVehicleId`, sorted in PHP to keep the first.
- Plus a `SHOW TABLES` per request (static, so once per request) and the controller constructor's `ensureSchema()`.
- **Grows linearly with trip history** [I].

**R2. No index on `ora_sfarsit`.** The open-trip query relies on the `vehicle_id` index plus filtering; fine at today's volume, and it degrades with history [I].

**R3. SAS retry storm.**
- After the 20 s TTL, every concurrent request with a missing or expired cache calls SAS; there is no lock (unlike the cron, which uses `GET_LOCK`).
- On SAS failure there is no backoff: each poll retries and blocks PHP for up to 15 s [C for the mechanism, I for the impact].

**R4. Browser fetch without a timeout.**
- A hung request keeps `state.loading = true`, which blocks all later automatic refreshes until it settles [C mechanism].

**R5. Time zones.**
- `position_ts = strtotime(SAS 'Date')` and `TIMESTAMP(data_sfarsit, ora_sfarsit)` are compared directly in `liveVehicleState`. This assumes SAS returns local server time with no offset [U]. If SAS ever sends UTC, the `no_trip` / `before_last_end` decision shifts by 2–3 h.

**R6. The pre-fill depends on DOM ids and on a global function from another script.**
- Load order: `dispatcher-live.js` is `defer` and included before the form in the DOM.
- It only reads the function on click, so this is safe today [I].

**R7. A full `innerHTML` rebuild every minute.**
- Keyboard focus inside a row (other than the chevron restore) and text selection are lost on refresh [I].
- Open dropdowns are protected; open detail rows are preserved.

**R8. Leaflet and tiles come from public CDNs.**
- They are blocked on offline or restricted networks.
- The OSM tile usage policy applies to production traffic [U whether that matters at this scale].

**R9. Hidden-vehicle coupling.**
- A user hiding a vehicle on Harta Flota for map clarity also loses it from this operational panel and from the Km pierduți card, with no indication [C behaviour; I that it is unintended].

**R10. Trailers and vehicles without GPS never appear**, so their open trips are invisible here [C]. Whether SAS tracks any trailers is [U].

### 9.3 Unknown (needs runtime testing or information)

- **U1.** Real SAS latency and failure frequency, and therefore how often P2 occurs in production.
- **U2.** Whether SAS timestamps are local time (R5).
- **U3.** How many users have hidden vehicles on Harta Flota (R9).
- **U4.** Whether open trips that are already invoiced exist in production (§4.1).
- **U5.** Actual production volume of `curse_dispecer` per vehicle (R1/R2).
- **U6.** Whether the dispatchers rely on the auto-collapse or are hindered by it (§7.6).
- **U7.** The VPS version of the code and data. This analysis covers the local working tree and the local DB only.

---

## 10. Recommendations and Considerations for Future Changes

*Considerations only — nothing here has been implemented.*

1. **Decide on a single definition of "trip in progress"** and use it everywhere: the panel, Km în desfășurare, Km pierduți and Fleet Assistant. Questions to settle:
   - Should future starts count?
   - Should there be a maximum age, or an age badge?
   - Should `blocks_trip` trips count?
   - Is the window cut at the next trip start?

   A shared helper/service would remove four diverging SQL copies.
2. **Surface the age of an associated trip** (e.g. "deschisă de 6 zile") and treat "open trip started before the last N hours of movement" as its own state. This addresses P1 without auto-closing anything.
3. **Recompute `age_seconds` at read time** from `timestamp`, in the service or the controller, so that classification under a SAS outage reflects reality (P2). Also make the error visible in the collapsed header.
4. **Decide whether "at most one open trip per vehicle" should be enforced** at save time. If so, the chooser becomes a data-repair tool rather than a routine path (P11).
5. **For multi-phase trips**, take the driver, start time and template from the last phase (P5, P6).
6. **Before adding features, consider:**
   - A server-side "attention" sort (`no_trip` first).
   - A stable layout that keeps Poziție and Cursă visible on smaller screens.
   - Revisiting the auto-collapse.
   - A non-overlapping counter model.
7. **Performance guard-rails before widening the panel:**
   - Limit `latestRacesByVehicleId` per vehicle (window function or `MAX`).
   - Index `(vehicle_id, ora_sfarsit, deleted_at)`.
   - Cache the driver directory.
   - Add a lock/backoff around the SAS refresh.
8. **Keep the panel read-only by design** (the current, explicit principle in the comments at controller L608–617 and JS L1–18). Any future "close trip from GPS" action should reuse the edit page's existing closing flow (`kmp_close`) rather than write from the panel.
9. **Regression coverage:** extend `scripts/test_live_gps_association.php` for:
   - `blocks_trip` trips,
   - future-dated trips,
   - phase-driver display,
   - the stale-cache case.

   These are exactly the confirmed gaps above.
