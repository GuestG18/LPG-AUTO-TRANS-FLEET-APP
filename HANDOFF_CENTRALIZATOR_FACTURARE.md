# Handoff — Centralizator facturare (billing centralizer)

Context report from a long working session (2026-09-14 → 2026-09-16), written 2026-09-17 so a new
conversation can continue without the full transcript. Paste or reference this file at the start.

> **Verify before relying on details.** All work below is committed (working tree was clean on
> 2026-09-17; relevant commits `cf1be2e`, `a4a0128`, `0c16f30`, `dbdf2b9`). Later sessions changed
> parts of it — see **§8 Superseded / changed later**.

---

## 1. Where things live

| What | Path |
|---|---|
| Page | `index.php?page=centralizator_facturare` (local: `http://aplicatie_fleet.test/htdocs/…`, also `php -S 127.0.0.1:8000 -t htdocs`) |
| Controller | `htdocs/controllers/CentralizatorFacturareController.php` (thin; `render('centralizator_facturare/dashboard.php')`) |
| Service (all data) | `htdocs/services/CentralizatorFacturareService.php` |
| View (markup + CSS + JS, one file) | `htdocs/views/centralizator_facturare/dashboard.php` |
| Pricing engine (read-only quotes) | `htdocs/services/TransportPricingService.php` → `quote(array $trip)` |
| Tariff versions / history | `htdocs/models/TransportTariffModel.php` (`getVersionsForBeneficiary`, `getHistory`, `COMPONENTS`, `TRANSPORT_TYPES`) |
| Trip save / legacy pricing | `htdocs/controllers/DispecerCurseController.php` (`applyVersionedPricing`, legacy calc ~l.6640–6800) |
| Live price calculator (form) | `htdocs/assets/js/dispecer-curse.js` (`recalculateTotal`) |

Filters (query string): `month`, `beneficiar_id` (empty = all), `tip_activitate` (`primar|primar_tona|distributie|primar_distributie|compresor`, empty = general view), `tip_marfa`, `loc_incarcare_id`, `zona_distributie_id`, `ruta`, `vehicle_id`, `vehicle_sort`, `view=compact|detaliat`.

---

## 2. What was built (final state from this session)

### 2.1 General view KPI cards (mode = all types)
- Three cards: **Total km** (all types, incl. Compresor relocation km), **Total curse**, **Total tone** (Primar tone + P+D + Distribuție; Compresor excluded because its activity mixes hours/tonnes).
- Each card expands (`data-kpi-toggle`) to a per-type breakdown: value, share bar, trips, % vs previous month.
- Service: `buildKpis()` general branch + `buildTypeBreakdown()`.

### 2.2 "Activități pe tipuri de transport" (general view) — multi-level accordion
Replaced the old "Activitate pe vehicule – Rezumat general" panel (removed with its dead helpers).

Hierarchy (all toggles use the existing `data-vehicle-toggle` JS handler; each level has its own `aria-controls` target):

```
Type row (Primar km …)                     [left ›]
 └─ Group rows (route, or tariff for Distribuție)
     Columns: [›] Calcul facturare | Rută / Tarif-rute | Curse | Valoare RON | [right ⌄ refunds]
     ├─ left ›  → trips table (Data, Vehicul, Nr. cursă, Rută, Calcul facturare, Valoare)
     └─ right ⌄ → Refacturări panel (id reinvoice-details-<type>-<key>)
            header "Refacturări  N grupuri • M înregistrări"
            groups (Tip + Denumire): [›] Tip | Denumire | Cantitate × Valoare unitară | Total
               └─ records: Data | Tip | Denumire | Nr. înmatriculare | buc | Valoare total
            footer "Total refacturări"
 TOTAL row (trips + value only, no formula)
 TOTAL REFACTURĂRI row (right toggle only if refunds of trips from other months exist)
```

- **Grouping:** Distribuție groups by **price** (`billingPriceGroup`: unit + rate, from engine components; fallback saved price with unit inferred by `savedRateUnit`); all other types group by **route** (`billingRouteKey`: loc:zone, plus garages for 4-point routes).
- **Route code/label:** `billingRouteShort` / `billingRouteLabel`. Missing side = `?` (e.g. `O-?`, "Oradea → destinație necompletată"). 4-point (Vixon) = `C-B/N-G-P`, "CONTESTI → Brazi/Negoiesti → Giurgiu → PLOIESTI"; a "/" in a place name keeps each part's initial.
- **Calcul facturare** per trip is rebuilt with `TransportPricingService::quote()` at the trip date (`tripBillingBreakdown`, all 5 types via `COMPONENT_BILLING_TYPES`). Rendered by `$renderComponentCalc`, e.g. `8 t × 75 RON/t + 630 km × 1,21 RON/km`, `6 ore × 80 RON/ore + 4 t × 50 RON/t`, `2 curse × 2.500 RON/cursă (cost fix)`.
  - Invoiced value is always the **saved** `total_facturare`; engine result only explains it.
  - If engine total ≠ saved value, fall back to saved `pret_tarifare` **only if it reproduces the value exactly** (t / km / per trip) → grey info icon (`calc_source = saved`).
  - Otherwise orange warning icon with saved vs recalculated value + engine warnings; "calcul indisponibil" when nothing can be rebuilt; group line adds "+ N curse fără calcul".
  - Group components aggregate by **unit + rate** (never averaged).
- **Refunds:** `attachTripRefunds()` assigns refund lines to the group containing their trip (`cursa_id`); refunds of trips not in the month stay on the type (`refund_leftovers`). `refundLines()` turns one `curse_cheltuieli` row into lines with `type_label`, `name`, `quantity`, `unit_price`, `amount`, `vehicle_label`, `date_label`, `race_no`:
  - new toll model: `refacturare_tip_cheltuiala` in (taxa_acces, port, trece) + `refacturare_locatie` + `refacturare_bucati`;
  - legacy "taxe_drum": JSON in `refacturare_detalii` (bucati/pret/total), location in `refacturare_observatii`;
  - others: one line, description from text `refacturare_detalii` or observations.
  - Panel grouping (Tip + Denumire, case-insensitive) is **presentation only**; totals come from raw lines. Record count = distinct `expense_id`.
- Per-type refund totals equal the refund card (verified: Jul 198, Aug 1.420,25, Sep 133).

### 2.3 Tariff evolution (Administrare tarife)
- **"Evoluție tarife (Administrare tarife)"** panel (`buildTariffEvolution`): for beneficiaries with trips in the month, versions whose validity overlaps the month, with previous value (+/- %), valid from/to, changed by + date, fuel variation (from `transport_tariff_history` via `tariff_version_id`). Filtered by `tip_activitate`; Beneficiar column only when several. Always rendered (empty state) so the grid area never collapses.
- Grid gotcha: the page uses `grid-template-areas` per mode at two breakpoints — a new panel needs its area row added to **all 10 templates** (`tariffs` row added after each `vehicleDetail` row).
- Primar routes panel price column: `primaryRouteRate()` now uses **value ÷ km** first; `cost_km_primar` snapshot only as fallback (see §4).

---

## 3. Pricing rules learned (per beneficiary, same transport type can differ)

- **Primar km / tone:** if the route rule has `aplica_cost_cursa` → fixed price per trip (`cost_cursa`); else km × beneficiary `pret_km` (km = route `km_tarifare` unless `km_agreati_manual`), or tonnes × `pret_tona`.
- **Distribuție:** by rule `tarif_mod` (tona / km / tona_km); fixed `cost_cursa` replaces ton part and drops km part.
- **P+D:** tonnes × route `tarif_tona` + km × route `cost_extra_km` (or fixed cost).
- **Compresor:** sum of up to 5 parts: `ore_aspirare`, `km_dislocare`, `tona_livrata`, `tona_aspirata_lichida`, `tona_aspirata_gazoasa` × beneficiary rates. (Report "activity" column uses `ore_functionare` — different field.)
- **Rule choice:** route rules are vehicle-scoped; Vixon (`rute_primar_puncte_extinse = 1`) picks the rule by the trip's `loc_plecare` / `loc_intoarcere` garages (return list can be comma-separated).
- Versioned tariffs: `transport_tariff_versions` (`rule_signature = beneficiar|component|route_ref`, `valid_from/valid_to`, `unit`); `transport_tariff_history` (old/new, effective_from, changed_by_name, fuel_variation_percent).
- Saved `tariff_breakdown_json` exists only for some trips (0 of P+D/Compresor at the time) → why the report recomputes.

---

## 4. Data facts / known issues found

- **Stale `cost_km_primar` snapshots:** 20 ButanGas Primar trips created 04.09–09.09.2026 store 1,21 while billed at 1,27 (version 91, 1,27 from 01.08.2026; version 90 = 1,21 in July). Cause: snapshot refresh in `applyVersionedPricing` was added 10.09 (commit `043a1a1`). Report no longer reads it. (Later session: saving a trip now recalculates — see §8.)
- **Duplicate locations:** "Lugoj" exists twice as loading place (ids 55, 84) and zone (56, 83), all active → trips on them form separate routes. Not merged (needs user decision; DB change).
- Many local trips lack unloading zone / loading place → routes `X-?`; several with 0,00 value or beneficiary without rates → warnings are real data problems.
- Local DB `if0_41456552_aplicatie_flota` (same for aplicatie_fleet.test and :8000). The user's VPS data (e.g. Vixon's 12 four-point routes at 2.500 lei, Port/Octogon refunds) is **not** in the local DB.
- Local refunds are mostly legacy demo rows (no location/pieces).

---

## 5. How to test locally (methods used)

- **PHP CLI:** `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`. For DB scripts load `../vendor/autoload.php`, `config/config.php`, `config/database.php` → `get_pdo()`; never print `DB_PASS`.
- **Authenticated browser without 2FA:** write `C:/laragon/tmp/sess_<hex>` with `session_initialized|i:<time>;auth_user|<serialized admin>` and set cookie `fleet_mvp_session`. Set the cookie **while the tab is on `aplicatie_fleet.test`**, otherwise the first load hits login. Delete the session file afterwards.
- **Private methods:** `ReflectionMethod::setAccessible(true)` on `CentralizatorFacturareService` (e.g. `buildActivitySummary($rows, true)`, `buildPrimaryRoutes`). Require `models/BaseModel.php`, `models/TransportTariffModel.php`, `services/TransportPricingService.php` first (no autoloader for app classes).
- **Render test for scenarios missing in data:** build `$report = $service->getReport([...])`, inject lines in memory, `include` the view with `includes/helpers.php` loaded, parse HTML with `DOMXPath`. Read-only.
- **Browser pane:** 800 px wide → sidebar overlays content; use `resize_window` 1400×900, `find` + `scroll_to` (window doesn't scroll via JS), reset with preset `desktop`.
- **CSS gotcha:** nested expanded rows inherit the sticky `--cf-visible-width` → text clipped on the left; disabled for `.cf-type-routes .cf-trip-detail-cell > .cf-breakdown`.
- `querySelectorAll('tbody tr')` inside nested tables also matches outer-tbody rows (counting artefacts in checks).

---

## 6. User preferences observed

- Wants to see **exactly how each value was calculated**, per transport type and per beneficiary formula; route first, then trips.
- Column order everywhere: **Calcul facturare → Rută/Tarif → Curse → Valoare RON**; TOTAL rows show only trips + value (no formula).
- Refunds attached to their exact billing row, expandable via an icon-only right button identical to the left one; grouped Tip + Denumire with `Cantitate × Valoare unitară` (no averaging, aggregate identical unit prices).
- Sends detailed specs with mockups for visual changes; expects no redesign of surrounding sections and verification of listed scenarios.
- Communicates in English/Romanian mix; UI text in Romanian with diacritics; code comments in Romanian without diacritics.
- Don't change financial data or merge duplicate master data without explicit approval; deploy to VPS with `git up` (see memory).

---

## 7. Open items at end of session

1. Duplicate "Lugoj" location/zone records — merge in DB, or group routes by name? (awaiting decision)
2. Per-group count pills in refund panel (mockup had them, written spec said no badges) — left out.
3. Compresor "Activitate" column uses `ore_functionare` while pricing uses `ore_aspirare` — not changed.
4. Verify refund grouping and Vixon 4-point circuits against VPS data (not available locally).

---

## 8. Superseded / changed later (from memory notes, 2026-09-16/17)

- **Primar routes table** ("Detalii Primar km – pe rute"): now **one row per (route × tariff actually billed)**, each expandable with the change explanation (previous tariff + %, from when, who, fuel variation). This supersedes this session's single-row label `1,21 (2 curse) · 1,27 (3 curse)`. Grouping by value ÷ km, linked to versions by value + date overlap on `unit = 'lei/km'` (not by `tariff_version_id`, which for P+D is the ton component). The separate "Evoluție tarife" panel was still present in the view on 2026-09-17 — check whether it is still wanted.
- **Distribuție billing unit** (km / tonă / tonă+km / cost fix) is read per trip via `distributionBillingUnits()` across KPI cards, summaries, matrix, vehicle table and CSV export.
- **Recalculation on save:** from 2026-09-17 saving an unpaid trip recalculates `pret_tarifare` / `total_facturare` / `cost_km_*`; the "Recalculează tariful" button and `recalculate_tariff` flag were removed (invoiced trips stay immutable).
- Refund-related additions elsewhere: approvals drawer "Taxe de refacturat lipsă" driven by `page=reguli_taxe_refacturare`.
