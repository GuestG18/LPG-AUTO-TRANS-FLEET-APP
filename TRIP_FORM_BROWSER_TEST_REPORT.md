# TRIP_FORM_BROWSER_TEST_REPORT — "Adaugă Cursă" runtime validation

**Date:** 2026-10-07
**Scope:** the "Adaugă Cursă" form on Dispecer curse, tested in a real browser against the running local application.
**Hypothesis under test:** [TRIP_FORM_FUNCTIONAL_AUDIT.md](TRIP_FORM_FUNCTIONAL_AUDIT.md) §0–17.
**Detailed test log:** audit §18 (BRT-001 … BRT-029).
**Screenshots:** `docs/trip-form-browser-test/`.

No code, CSS, configuration or permission was changed.

---

## 1. Executive summary

- **Static audit vs browser:**
  - The static audit was **largely accurate**: 22 of 29 runtime tests confirmed it fully or in substance.
  - The browser found **9 behaviors the code reading missed or under-rated**. Three of them change how risky the redesign is.
- **Billing trust.** The four representative saves (Primar km, Primar circuit, P+D, Compresor) stored **exactly** the total shown before saving. However, the displayed estimate is demonstrably unreliable in other situations:
  - it changes with the date only after a server round trip (2.150 → 1.960 lei);
  - it shows 0,00 lei where the backend would bill 150 lei;
  - it changes from 0,00 to 150,00 lei across a page reload with identical inputs;
  - for non-reviewer users the server preview is **always** rejected (HTTP 403), with no visible sign.
- **Three blocking defects reproduced:**
  1. An **"Alt vehicul → Doar pentru această cursă"** trip that triggers any soft warning **cannot be saved through the UI**. The vehicle is dropped on every round trip, giving an endless loop.
  2. A **non-reviewer selecting an inactive driver** is stuck: the only button is "Închide" and the submit is silently refused.
  3. After switching **Compresor → any other type**, "Cantitate Încărcată" stays **hidden but still submitted and priced** with its stale value.
- **Biggest friction sources for operators:**
  - silent clearing or overwriting when a selection changes;
  - dropdowns that lock themselves to their current value (Loc, Zonă, garages);
  - validation only after a full reload;
  - modals that stack, reappear or show stale data;
  - keyboard focus that falls to the page body.
- **Layout:**
  - It never breaks (no overflow) from 1100 to 1920 px.
  - The submit button and the total are **below the fold at ≤1536×864**.
  - The form jumps from 4 to 2 columns at 1200 px.
  - The card height changes by up to 232 px depending on the transport type.

---

## 2. Environment tested

| Item | Value |
|---|---|
| Application | local Laragon vhost `http://aplicatie_fleet.test/htdocs/index.php?page=dispecer_curse` |
| Code version | branch `main` @ `eb240e8` (+ unrelated uncommitted fleet-monitoring files) |
| Database | local MySQL `if0_41456552_aplicatie_flota` (production-like copy, 627 trips) |
| Browser | Claude desktop built-in browser (Chromium); pane 1680×930, plus emulated 1920×1080, 1536×864, 1366×768, 1280×720, 1199×720, 1100×720 |
| Users | `Administrator Sistem` (admin, reviewer). `Alexandra Iordache` (role `utilizator`, non-reviewer) for one view-only scenario |
| Authentication | local session file injection (the documented dev method, bypassing email 2FA); restored to admin afterwards |
| Test data created | trips #790–#794 (observations prefixed `TEST AUDIT BRT-`), dated 15–19/12/2026 so they could not collide with real trips or accommodation records |
| Cleanup | all 5 trips **soft-deleted** through the app's own delete action. Vehicle `km_bord` / `km_revizie` verified identical to the pre-test snapshot. No approval rows, configuration rows or accommodation links were created. The 5 rows remain in "Curse șterse" plus their `cursa_audit_log` rows; permanent deletion was left to the owner. |

**Interaction method:**
- Dates, numbers, text and modal buttons were exercised with real clicks and keystrokes.
- Selects were mostly driven by setting the value and dispatching `change`, which is what the browser does on a native selection, for speed and repeatability.
- A dedicated keyboard-only run used real key presses (§11).
- Form state was read back from the DOM after every step.

---

## 3. Browser / runtime state

- **Initial load:**
  - TTFB 0,8 s, DOMContentLoaded 1,8 s.
  - HTML is 7,3 MB decoded (311 KB transferred); ~48 000 DOM nodes.
  - No console errors.
- **Above the form** (1680 px wide), in order:
  - the page header (Km service, Configurare Transport);
  - the red badge "Atenție: curse cu informații lipsă (124)";
  - the **GPS live** panel.

  The form card starts at y = 321 px, or 395 px at ≤1366 px, where the GPS panel wraps.
- **Background polling**, which starts immediately with no vehicle selected:
  - `races_activity` every 30 s;
  - `live_gps` every 60 s;
  - `inactive_approvals&action=missing_fees` once.
- **Initial form state:** exactly as documented in audit §3.2 for "no type":
  - start and end = today without time;
  - vehicle placeholder "Selecteaza mai intai beneficiarul";
  - zone, km totali and nr clienți hidden;
  - total 0,00 lei.
- **Defects visible before any interaction:**
  - Loc Încărcare already lists every beneficiary's locations, with duplicate names.
  - The vehicle `title` mentions a driver filter that does not exist.

---

## 4. Scenario matrix

| ID | Scenario | Type(s) | Saved? | Result |
|---|---|---|---|---|
| BRT-001 | Initial state | — | — | PASS |
| BRT-002 | Primar km happy path + stored price | Primar km | #790 | PASS |
| BRT-003 | Loc↔Zonă change A→B→A | Primar km | — | FAIL (undocumented lock) |
| BRT-004 | Primar tone state | Primar tone | — | PASS |
| BRT-005 | Distribuție + delivered tons | Distribuție | — | PASS |
| BRT-006 | 9 populated transitions | all | — | PARTIAL |
| BRT-007 | Compresor → other: quantity | all | — | CONFIRMED BUG (worse) |
| BRT-008 | Stale route km after type changes | Primar / Distribuție | — | PARTIAL (worse) |
| BRT-009 | Stale delivered tons in Compresor | Distribuție → Compresor | — | FAIL (new) |
| BRT-010 | Distribuție → P+D km overwrite | P+D | — | PASS |
| BRT-011 | Alt vehicul + doar această cursă + soft warning | Distribuție | impossible | CONFIRMED, escalated |
| BRT-012 | Displayed vs stored total | all | #790, #792–#794 | PASS / D1, D3, D14, D16 confirmed |
| BRT-013 | Overlap / similar / duplicate | Primar km | #791 | PASS |
| BRT-014 | Vehicle decision modal | Distribuție | — | PASS ("permanent" not executed) |
| BRT-015 | Unconfigured + inactive vehicle | Distribuție | — | FAIL (stacked modals) |
| BRT-016 | Inactive resources, reviewer | Primar km | — | PARTIAL |
| BRT-017 | Inactive driver, non-reviewer | Distribuție | — | CONFIRMED dead end |
| BRT-018 | Vixon garage circuit | Primar km | #792 | PASS + undocumented locking |
| BRT-019 | Manual km (Mol), no routes (Flaga) | Primar km | — | PASS |
| BRT-020 | Beneficiary changes | Distribuție, Compresor | — | PASS |
| BRT-021 | Validation (empty, dates, negatives, compressor) | Compresor, all | — | PASS |
| BRT-022 | Quantity > capacity | Distribuție | — | FAIL (overload masked) |
| BRT-023 | Incomplete round trip, configured vehicle | P+D | #793 | PASS |
| BRT-024 | Post-save flow | — | — | PASS |
| BRT-025 | Odometer side effects | Primar, Compresor | #790, #794 | PASS (business question) |
| BRT-026 | Existing-trips panel | — | — | PARTIAL |
| BRT-027 | Keyboard-only completion | Primar km | — | FAIL |
| BRT-028 | Resolutions | Distribuție | — | PASS (layout notes) |
| BRT-029 | Console / network | — | — | PASS (performance notes) |

---

## 5. Test results

The full Steps / Expected / Actual / Result / Severity entries for every BRT ID are in **audit §18.1**. This report gives the synthesis.

| Outcome | Count |
|---|---|
| PASS (audit confirmed) | 17 |
| PARTIAL (confirmed, with undocumented or worse aspects) | 6 |
| FAIL (audit wrong or incomplete) | 6 |
| Not executed (destructive) | 3 sub-scenarios: "Permanent" route decision, approval request/approve, `syncTariffLegacyValues` probe |

---

## 6. Transport-type testing

| Aspect | Primar km | Primar tone | Distribuție | P+D | Compresor |
|---|---|---|---|---|---|
| **Visible** | Loc încărcare, Loc descărcare, Km agreați (RO), Km efectuați (km_totali), Cantitate, Capacitate | same, labels "Zona descărcare", "Km efectuați" (km_cursa), "Km totali" | Data încărcare (moved first), Loc, Zona, Km efectuați, Nr clienți, Cantitate, Cantitate livrată, Cost/km Distribuție | as Distribuție + Km agreați (RO), Km efectuați (km_totali), Cost/km Mixt + calculation note | 4 location texts, Ore aspirare, Km efectuați (km_dislocare), Cantitate livrată, Tonă lichidă/gazoasă |
| **Hidden/disabled** | nr clienți, tona livrată, compressor fields | same | km totali, compressor fields | compressor fields | Loc, Zonă, km, km totali, cantitate, capacitate, nr clienți |
| **Required-looking (\*)** | Beneficiar, Tip, Nr. înm., Șofer, start, end, Loc încărcare, Tip marfă (all, always) | same | same | same | + 4 location texts; Loc încărcare hidden |
| **Auto-populated** | Km agreați = route km (RO); capacity | Km (RO) | default Loc from the vehicle garage / config; capacity | Km agreați from the distribution route | capacity (hidden) |
| **Dependent dropdowns** | Loc ↔ Zonă from Primar pairs (both directions), self-locking | same | Loc ↔ Zonă narrowed by the vehicle's rules, self-locking | same | none |
| **Calculations shown** | Total | Total | Total, Cost/km Distribuție | Total, Cost/km Distribuție, Cost/km Mixt, note | Total |
| **Layout** | compact 4-col, card 511 px | 578 px | dates row reordered (Data încărcare first), 578 px | 743 px (note) | 578 px, locations replace the route row |
| **Modals** | vehicle decision, inactive | same | same + stacked case | same | same |
| **Validation (server)** | soft: zone, route, km, driver, goods | soft: quantity | soft: quantity, zone, vehicle pair, capacity | same | hard: 4 locations, `ore_aspirare` format |
| **Submission** | saved directly (#790, #792) | not saved | incomplete round trip common (capacity) | saved after the incomplete round trip (#793) | saved directly (#794) |
| **Matches audit §3.2** | yes | yes (label caveat) | yes | yes | yes |

---

## 7. Transition testing

Starting from fully populated forms (vehicle B 605 NET / B 275 NET, ButanGas):

| Transition | Appears | Disappears | Survives | Cleared silently | Overwritten | Total | Warning? |
|---|---|---|---|---|---|---|---|
| Primar → Distribuție | nr clienți, tona livrată, Cost/km D | km totali | vehicle, driver, Loc, qty, **km 630 (stale, now editable)** | **Zonă** | — | 800,10 → 0 | no |
| Distribuție → Primar | km totali | nr clienți, tona livrată | vehicle, driver, Loc | **Zonă, km** | — | 1.360 → 0 | no |
| Distribuție → P+D | km totali, Km agreați (RO), Mixt | — | all | — | **typed km 250 → 630** | 1.360 → 2.160,10 | no |
| P+D → Distribuție | — | km totali | all, **km 630 stays** | — | — | 2.160,10 → 1.360 | no |
| Primar → Compresor | compressor block | Loc, Zonă, km, qty, capacity | hidden values kept in the DOM | **vehicle, driver** | — | → 0 | no |
| Distribuție → Compresor | compressor block | as above | **tona livrată 16 → priced immediately** | vehicle, driver | — | 1.360 → **1.120** with no compressor data | no |
| Compresor → Distribuție | Loc, Zona, km, nr clienți | compressor block | **hidden qty 18 posted and priced** | Zonă | **Loc → vehicle garage default** | 370 → 0 → 1.260 (from hidden qty) | no |
| Compresor → Primar | Loc, Zonă, km | compressor block | **km 630 (RO, stale)**; qty hidden | vehicle, driver, Zonă | — | 160 → **800,10 with no vehicle / no route** | no |
| Compresor → P+D | as P+D | compressor block | km 630, qty hidden | vehicle, driver | — | 160 → 0 | no |

**Suspected issue verified:** "Cantitate Încărcată" stays hidden after Compresor → *any* other type.

**Reproduction:**
1. Choose ButanGas.
2. Choose Distribuție.
3. Type 18 in Cantitate Încărcată.
4. Switch Tip Transport to Compresor.
5. Switch back to Distribuție, Primar km or P+D.

**Result:** the field is gone from the layout, but its value 18 is still submitted and used by the estimate.

---

## 8. Validation testing

| Case | When reported | How | Values kept? | Recovery |
|---|---|---|---|---|
| Nothing filled | after a full reload | 6 inline red messages; empty type reads "Tipul de transport este invalid." | yes | fix and resubmit; **stale red messages persist** after fixing |
| Invalid typed date (`32/13/2026 25:99`) | on blur / submit (client) | red border + focus, **no text**; the hidden field keeps the old date | yes | guess the format |
| End before start | after a reload | "Ora de sfarsit trebuie sa fie dupa ora de inceput." | yes | fix |
| Compressor locations missing | after a reload | 4 "Completeaza Loc …" messages | yes | fix |
| Negative tonnage (−5) | after a reload | "Tona livrata este invalida."; the estimate had silently ignored it | yes | fix |
| `ore_aspirare` "2h30" | after a reload | "Ore aspirare este invalid (ex: 2 sau 2h)." after the estimate had priced it as 2 h | yes | retype |
| Missing driver / goods / location | incomplete modal (soft) | list of messages | yes (configured vehicle) / **no** ("Alt vehicul") | "Da, salveaza oricum" or fix |
| Quantity > capacity | incomplete modal | only "capacitatea … nu este inca verificata … 128.6% … orientativ"; **overload not stated** | yes | save anyway |
| Overlap | before submit (client) | blocking modal + link | yes, no reload | change interval / vehicle |
| Similar | before submit (client) | confirm modal + links | yes | confirm or cancel |
| Exact duplicate | after a reload (server) | warning + link | yes | not saved |
| Inactive driver (non-reviewer) | on selection and on submit | modal, "Închide" only | yes | **none** |

**Comparison with audit §7:**
- All HARD and SOFT rules that were exercised produced the documented messages.
- Exception: the capacity overload message is masked by the "not verified" message.

---

## 9. Pricing testing

**Representative saves: DISPLAYED TOTAL BEFORE SAVE vs TOTAL STORED AFTER SAVE**

| Trip | Type | Displayed | Stored | Tariff source | Match |
|---|---|---|---|---|---|
| #790 | Primar km, ButanGas BGR Navodari→Contesti 630 km | 800,10 lei | 800.10 | version 91 (pret_km 1,27) | ✅ |
| #792 | Primar km, Vixon Brazi/Negoiesti→Giurgiu, PLOIESTI/PLOIESTI | 2.150,00 lei | 2150.00 | version 112 (cost_cursa) | ✅ |
| #793 | P+D, ButanGas Contesti→Oradea, 10 t, km totali 1500 | 2.464,50 lei (C/km D 5,00, Mixt 1,64) | 2464.50 (5.00 / 1.64) | version 120 (+122) | ✅ |
| #794 | Compresor, ButanGas, 2 h + 3 t livrate | 370,00 lei | 370.00 | legacy | ✅ |

**Discrepancies observed at runtime** (all classified **CRITICAL — billing trust issue**):

| # | Observation | Audit ref |
|---|---|---|
| P1 | Changing only the **date** (Vixon PLOIESTI route, 17/12 → 15/07/2026) changes the total from 2.150,00 to **1.960,00 lei** about 0,5 s later. The local estimate never knows about tariff versions, so the operator sees the price jump with no explanation. | D1 |
| P2 | Distribuție with an unconfigured vehicle: local estimate **150,00 lei**, server preview overwrites it with **0,00 lei**. After the incomplete round trip, the same inputs show **150,00 lei**, because the server preview does not run on page load. The backend would bill 150; the trip could not be saved (BRT-011). | D3, U-H11 |
| P3 | Compresor → Primar km leaves a read-only stale km of 630, so the form shows **800,10 lei with no vehicle and no unloading point**. | BRT-008 |
| P4 | A hidden stale quantity is priced: 1.260,00 lei from an invisible field. | BRT-007 |
| P5 | "2h30" is priced as 2 h in both previews, then rejected on save. | D14 |
| P6 | Non-reviewer users: every server preview returns **HTTP 403**. Only the local estimate is shown, with no indication. | D16 |

**Reaction and visual behavior:**
- The local estimate updates synchronously on input.
- The server value replaces it after a 250–450 ms debounce plus ~150 ms of request time.
- **There is no loading state, no "estimate vs tariff" indicator** (only a `title` tooltip on the total), and no visible error when the preview fails.
- Values visibly jump when they differ.

---

## 10. Modal / async behavior

| Modal | Trigger | Observations |
|---|---|---|
| Vehicul neconfigurat pe rută | unconfigured vehicle via "Alt vehicul" | Clear wording. Renunță clears vehicle and driver. Fades in about 300 ms after selection. |
| Vehicul / Șofer inactiv utilizat (admin) | inactive vehicle/driver on change, start-date change, submit | Re-shows a vehicle already dismissed when the driver changes. **Stale race:** showed the previous vehicle after a switch. |
| Same (user mode) | as above | Vehicle: Solicită aprobare / Amână / Închide. **Driver: Închide only** (dead end). |
| **Stacked** | unconfigured + inactive vehicle | two modals and two backdrops at once |
| Overlap / Similar | submit (AJAX) | clear and distinct; the form is kept |
| Salvezi cursa fara toate informatiile? | reload after soft errors | auto-opens; "Da" resubmits the first form; vehicle and decision lost on the "Alt vehicul" path |
| Cursa a fost adăugată → Cheltuieli cursă | after save | two-step; "Nu acum" gives "Cursa ramane in atentii pentru cheltuieli." |

**Async observations:**
- The submit button shows **no busy state** during the 1–2 s conflict and inactive checks.
- Each vehicle change fires an `inactive_resource_status` request and a `races_activity` request; keyboard arrowing over 5 vehicles fired 5 of each.
- The existing-trips panel stays stale after the vehicle is cleared.

---

## 11. Keyboard workflow

**Tab stops:**
- 67 before the first field (navigation, header, open-trips, GPS panel).
- 17 inside the form for a Distribuție layout, including 3 calendar buttons and the read-only capacity field.

**Run:**
1. ↓ on Beneficiar → ButanGas; Tab; ↓ on Tip → Primar km; Tab.
2. ↓ on the vehicle **commits** B 105 NET (inactive). The modal opens and takes focus; Escape puts focus on `<body>`. The operator must go back to the field (67+ Tabs, or the mouse).
3. Arrowing to B 605 NET fires one inactive-status request and one activity request **per option passed**.
4. The dates accept typed `22/12/2026 0800` and `1900`; each needs two Tabs (field + calendar button).
5. The Tip marfă dropdown opens with Enter. ↓↓ plus Space checked **Butan** (not the intended option), the dropdown closed, and **focus went to `<body>`** again.

**Verdict:**
- Entering a trip with the keyboard only is not practical.
- With the mouse, a clean Primar trip needs ~12 interactions.
- For an operator entering dozens of trips per day, the focus losses and per-option network calls are the main cost.

---

## 12. Resolution / layout tests

| Viewport | Columns | Form top | Total visible without scrolling? | Submit visible? | Notes |
|---|---|---|---|---|---|
| 1920×1080 | 4 | 321 | yes | yes | — |
| 1680×930 | 4 | 321 | yes | yes (bottom 884) | — |
| 1536×864 | 4 | 321 | yes | **no** (884 > 864) | — |
| 1366×768 | 4 | 395 | **no** | **no** (958) | GPS panel wraps; "Distribu?ie" glitch |
| 1280×720 | 4 | 395 | no | no | only ~4 rows visible |
| <1200 (1199, 1100) | **2** | 369 | no | no (1215) | form height doubles |

- No horizontal overflow, no wrapped labels, no squeezed inputs at any tested width.
- Column widths are inconsistent across rows (e.g. 460 / 368 / 614 px at 1920) because the date row uses narrower columns and later rows use 3 columns.

**LAYOUT SHIFT log:**

| Shift | Trigger | Rating |
|---|---|---|
| Card height 511 → 578 → 743 px, submit moves 891 → 1122 px | changing transport type | **DISRUPTIVE** (the button moves out of view) |
| Data încărcare jumps before the start date | Distribuție only | **CONFUSING** |
| Garage fields inserted; start and end date split onto different rows | Primar with circuits (Vixon) | **CONFUSING** |
| Compressor locations replace the Loc/route row; Tip marfă moves | Compresor | EXPECTED |
| Cantitate Încărcată disappears; Capacitate slides into its place | after Compresor → other | **DISRUPTIVE** (bug) |
| Red error messages push rows down after a reload | validation | EXPECTED |
| Form starts lower (321 → 395 px) when the GPS panel wraps | ≤1366 px | EXPECTED |
| Existing-trips panel appears far below, between "Km service" and "Desfășurător" | vehicle selection | **CONFUSING** (out of sight) |

---

## 13. Console / network observations

| Request | Status | Frequency | UI handling |
|---|---|---|---|
| `tarife_transport&action=preview` (POST) | 200 (admin) / **403 (non-reviewer)** | once per debounced input/change burst; 82 in the session | 403 silently ignored (console errors only) |
| `dispecer_curse&action=inactive_resource_status` | 200 | every beneficiary, vehicle or driver change and start-date change, plus on submit | modal |
| `dispecer_curse&action=trip_conflict_check` | 200 | on submit when the signature changed | modal; fail-open |
| `dispecer_curse&action=races_activity` | 200 | every 30 s + window focus + every vehicle change | silent; full history up to 500 rows |
| `dispecer_curse&action=live_gps` | 200 | every 60 s | GPS panel |

- No JavaScript exceptions.
- One browser deprecation warning.

---

## 14. Operator friction map

| Step | Cognitive decision / hidden rule | Friction | Severity |
|---|---|---|---|
| 1. Beneficiar | Must be first. Type and vehicle lists depend on it, but nothing says so. | LOW | MEDIUM |
| 2. Tip transport | All 5 types offered, even unsupported ones. Unsupported is signalled only in the vehicle placeholder. | must know which customer supports what | HIGH |
| 3. Vehicul | The list mixes configured vehicles with inactive ones (no marker). "Alt vehicul" clears the current choice and opens a decision; for some combinations the trip is then **unsaveable**. Arrow keys fire checks per option. | must know the configuration rules | CRITICAL |
| 4. Șofer | Never auto-selected even with one candidate. Terminated and inactive drivers are listed without a marker. Inactive driver as non-reviewer = dead end. | repeated selection; dead end | HIGH / CRITICAL |
| 5. Dates | Free text plus a calendar button for each field. Invalid input gives a red border with no explanation. End time optional despite the `*`. | format guessing | MEDIUM |
| 6. Loc ↔ Zonă (and garages) | Once selected, each dropdown shows only its own value; changing requires resetting to "-- Selecteaza --" first. Garage choices carry over to the next route. | **must know a hidden reset rule** | HIGH |
| 7. Km | Read-only or editable depending on type and rule; labels swap meaning between types; stale km can persist read-only. | must remember which km is which | HIGH |
| 8. Quantities | Delivered tons silently replace loaded tons for distribution; a hidden quantity may still be priced. | invisible influence on price | CRITICAL |
| 9. Tip marfă | Looks multi-select, behaves single-select; marked `*` but saveable empty. | LOW | MEDIUM |
| 10. Total | Can jump after a delay, depends on permission, can be stale or from hidden fields; no source indicator. | trust | CRITICAL |
| 11. Submit | No busy state; errors after a reload; unfixable "capacity not verified" warning forces an extra round trip for affected vehicles. | slow loop | HIGH |
| 12. After save | Up to 4 flashes + 2-step expense modal + (admin) maintenance modal; the success flash stays during the next entry. | noise | MEDIUM |

**Approximate effort** (mouse, clean data):

| Type | Selects | Typed fields | Clicks (approx.) | Typical modal interruptions | Reloads |
|---|---|---|---|---|---|
| Primar km | 6 (ben, tip, veh, drv, loc, zonă) | 3 (2 dates, km totali) | ~14 | 0–1 (inactive) + expense prompt | 1 |
| Primar tone | 6 | 4 (+ quantity) | ~15 | + incomplete (capacity) for unverified vehicles | 1–2 |
| Distribuție | 6 | 5 (dates, km, qty, nr clienți) | ~16 | incomplete (capacity) frequent | 2 |
| P+D | 6 | 5 (dates, qty, km totali, nr clienți) | ~16 | incomplete (capacity) observed | 2 |
| Compresor | 4 | 9 (dates, 4 locations, hours, km, tons) | ~18 | 0 | 1 |

Each "Alt vehicul" adds 2 clicks and a modal. Each inactive resource adds a modal (or a dead end).

---

## 15. Browser-vs-code contradictions

| # | Static audit said | Browser showed | Status |
|---|---|---|---|
| C1 | Loc/Zonă narrow each other (§3.4) | a selected Loc/Zonă/garage narrows **its own** list to one option; changing needs a reset | CONTRADICTED (incomplete) |
| C2 | Quantity wrapper stays hidden (NV#2, HIGH) | hidden **and still posted and priced** | CONFIRMED, worse → CRITICAL |
| C3 | "Alt vehicul" decision lost; may fail again (U-H3, HIGH) | **endless loop, unsaveable** | CONFIRMED, worse → CRITICAL |
| C4 | Stale km after a type change (U-M15, MEDIUM) | stale read-only km prices a trip with no route | CONFIRMED, worse → HIGH |
| C5 | Overload gives its own SOFT message (§7.3) | masked by "capacity not verified" | CONTRADICTED |
| C6 | — | stacked modals (unconfigured + inactive) | NEW |
| C7 | — | stale inactive modal after a vehicle switch (async race) | NEW |
| C8 | — | stale "Curse deja înregistrate" panel after the vehicle is cleared | NEW |
| C9 | — | `tona_livrata` carried from Distribuție into Compresor pricing | NEW |
| C10 | — | garage selections carry over and pick the variant of the next route | NEW |
| C11 | — | keyboard: ↓ commits options and fires network calls; focus lost to `<body>` after modal Escape and goods selection | NEW |
| C12 | Exact duplicate gives a duplicate warning (§7.2) | with times it is always reported as **overlap**; the duplicate message only appears for trips without a start time, after the similar modal | PARTIAL |

---

## 16. Confirmed bugs

| ID | Bug | Classification | Severity |
|---|---|---|---|
| B1 | "Alt vehicul / doar această cursă" + any soft warning ⇒ vehicle dropped on every round trip ⇒ trip unsaveable | BUG | CRITICAL |
| B2 | Hidden "Cantitate Încărcată" after Compresor → other type stays enabled, posted and priced | BUG + DATA INTEGRITY RISK | CRITICAL |
| B3 | Non-reviewer + inactive driver ⇒ no action available; submit silently refused | BUSINESS LOGIC RISK | CRITICAL |
| B4 | Stale read-only `km_cursa` after a type change prices trips without a route | BUG | HIGH |
| B5 | Stale `tona_livrata` priced after Distribuție → Compresor | BUG | MEDIUM |
| B6 | Stacked modals for an unconfigured and inactive vehicle | BUG | HIGH |
| B7 | Inactive modal shows stale resources after a fast selection change | BUG | MEDIUM |
| B8 | Existing-trips panel not cleared when the vehicle is cleared | BUG | MEDIUM |
| B9 | Circuit garage selects visible but empty (post nothing) until a route resolves | BUG | MEDIUM |
| B10 | Server preview not re-run after a reload; displayed total differs from the pre-reload value | BUG | HIGH |
| B11 | Overload message masked when capacity is not verified | BUSINESS LOGIC RISK | HIGH |
| B12 | Server preview 403 for users without `tarife_transport.view`, silently ignored | BUG / BUSINESS LOGIC RISK | CRITICAL (billing trust) |
| B13 | Keyboard focus lost to `<body>` after a modal Escape and after a goods selection | ACCESSIBILITY/KEYBOARD | HIGH |
| B14 | Encoding glitch "Distribu?ie", "Loc ? Zona" | VISUAL | LOW |

---

## 17. UX friction

| ID | Friction | Classification | Severity |
|---|---|---|---|
| X1 | Validation only after a full page reload; no client-side required checks; stale red messages | UX FRICTION | HIGH |
| X2 | Silent clearing/overwriting on beneficiary, type and vehicle changes (Zonă, vehicle, driver, Loc → garage default, km) with no warning | UX FRICTION / DATA LOSS | HIGH |
| X3 | Self-locking dropdowns (Loc, Zonă, garages) | UX FRICTION | HIGH |
| X4 | Unsupported type only visible in the vehicle placeholder; all 5 types always offered | UX FRICTION | HIGH |
| X5 | Empty Loc/Zonă for a beneficiary without routes; explanation only in a tooltip | UX FRICTION | HIGH |
| X6 | Km labels change meaning by type ("Km efectuați" = 3 different fields) | UX FRICTION | MEDIUM |
| X7 | Invalid date: red border, no message | UX FRICTION | MEDIUM |
| X8 | "Alt vehicul" clears the current selection; the list mixes cars, inactive trucks and semi-configured vehicles without markers | UX FRICTION | MEDIUM |
| X9 | Driver not auto-selected when exactly one candidate | UX FRICTION | LOW |
| X10 | Unfixable "capacity not verified" soft warning forces an extra round trip for affected vehicles | UX FRICTION | HIGH |
| X11 | No loading state on submit or on the price | UX FRICTION | MEDIUM |
| X12 | Post-save notification stacking; the success flash persists while the next trip is entered | UX FRICTION | MEDIUM |
| X13 | Submit/total below the fold at common laptop sizes; card height varies by type | VISUAL/LAYOUT PROBLEM | HIGH |
| X14 | Existing-trips panel far from the form; full history re-downloaded every 30 s | PERFORMANCE / UX | MEDIUM |
| X15 | 7,3 MB page, 48k DOM nodes, ~1,8 s to interactive | PERFORMANCE PROBLEM | MEDIUM |
| X16 | Tip marfă: multi-select look, single-select behavior, `*` but optional | UX FRICTION | LOW |
| X17 | Compresor relocation km not counted on the odometer | EXPECTED BUSINESS BEHAVIOR? (needs decision) | — |
| X18 | Trips saved without an incomplete prompt still increase "curse cu informații lipsă" (different rule sets) | UX FRICTION | MEDIUM |

---

## 18. Remaining unverified behavior

- **Not reproducible with the current data:** pricing discrepancies D2, D4–D8 and D10–D13. They need:
  - location/zone fallback tariffs;
  - unrestricted distribution rules;
  - versions that differ from the legacy values.

  They remain open code-level risks.
- **Primar vehicle eligibility from default entries only (NV#1):** not reproducible with the current data.
- **Not executed** (they would modify configuration or create records outside test trips):
  - "Adaugă permanent pe rută";
  - "Aprobă acum / ulterior" and "Solicită aprobare";
  - the `syncTariffLegacyValues`-before-CSRF probe.
- **Not testable from the UI:** NV#9, NV#10.
- **Edit / phase forms:** out of scope for this run. The shared JS means BRT-003, -007, -008, -018 and -027 very likely apply there too (unverified).

---

## 19. Screenshots / evidence

Folder: `docs/trip-form-browser-test/`

| File | Shows |
|---|---|
| 01-primar-km-route-resolved.jpg | Primar km with route km 630 and total 800,10 |
| 02-stacked-modals-unconfigured-and-inactive-vehicle.jpg | two modals overlapping |
| 03-incomplete-modal-vehicle-dropped-total-changed.jpg | incomplete modal; vehicle and driver empty; total now 150,00 |
| 04-save-anyway-hard-error-vehicle-lost.jpg | "Selecteaza un vehicul valid." after "salveaza oricum" |
| 05-overlap-modal.jpg | overlap block with link |
| 06-similar-modal.jpg | similar-trip confirmation |
| 07-compresor-to-primar-stale-total-hidden-quantity.jpg | 800,10 lei with no vehicle/route; quantity missing |
| 08-inactive-vehicle-and-driver-admin-modal.jpg | reviewer modal with vehicle + driver |
| 09-vixon-circuit-empty-garage-selects.jpg | empty garage selects; circuit layout |
| 10-flaga-primar-no-routes-empty-lists.jpg | beneficiary without routes |
| 11-empty-submit-inline-errors.jpg | server errors after an empty submit |
| 12-non-reviewer-inactive-driver-dead-end.jpg | "Închide"-only modal |
| 13–16 *.jpg | 1920×1080, 1536×864, 1366×768, 1280×720 |
| 17-expense-prompt-transition.jpg | post-save expense prompt |

DB evidence is in audit §18 (BRT-002, -012, -025): stored values queried read-only after each save.

---

## 20. Redesign constraints discovered from runtime testing

1. **The form must not rely on reload-based validation to carry decisions.** Any state that is not a plain field value is lost on every round trip today:
   - the vehicle decision;
   - the "Alt" selections;
   - the garages;
   - the server price.
2. **Hidden must mean "not submitted".** Hiding without disabling (or clearing) leaks values into billing (B2).
3. **Type-dependent values must be reset or re-derived on type change.** km, tona livrată and quantities currently leak across types (B4, B5).
4. **One modal at a time, owned by a single queue.** Today route-decision, inactive, conflict, incomplete and expense modals are independent and can stack or reappear stale (B6, B7).
5. **Price display must show its source and state:** local estimate vs tariff version at date, loading, failed or no permission. The current single number can be wrong in 6 observed ways (§9).
6. **Dependent dropdowns must allow changing a choice without resetting it first** (X3). They must also explain empty states inline, not in tooltips (X5).
7. **Keyboard:**
   - committing an option must not trigger network checks or modals until the selection is final (blur or debounce);
   - focus must return to the triggering field after a modal;
   - the form needs a skip-link or autofocus, given the 67 stops before it.
8. **A stable action area.** The submit button and the total must stay reachable regardless of type and of viewports down to 1280×720, and the card height must not swing by 230 px between types.
9. **Unfixable warnings** (vehicle capacity not verified) should not force the incomplete round trip on every trip, or must at least be distinguishable from missing operator input. This needs a business decision.
10. **The existing-trips context must stay close to the vehicle field and stay in sync** with the current selection.

---

# REDESIGN BASELINE

## Must preserve exactly
*Confirmed correct at runtime and relied upon by billing or data.*

1. Stored `total_facturare`, `pret_tarifare` and `cost_km_*` for Primar km, Primar circuit (garage variant), P+D and Compresor equal the values computed today (BRT-002, -012).
2. Primar: the route's `km_tarifare` fills Km agreați as read-only, unless the rule is manual. Manual rules require typed km (BRT-002, -019).
3. P+D: Km agreați comes from the distribution route. Distribution km = km totali − km agreați. Cost/km Distribuție and Mixt are as shown and stored (BRT-012).
4. Distribution: delivered tons > 0 replace loaded tons in billing (BRT-005).
5. Compresor: total = Σ metric × rate; "2" and "2h" are accepted; 1 h = 40 km off `km_revizie` (BRT-012, -025).
6. Tariff versions resolved by trip start date (`data_cursa`) for the stored price (BRT-012 / P1).
7. On save: odometer `km_bord` += km_totali (or km_cursa); `km_revizie` decreases by the same km (+40/h), floored at 0; coupled units are included; delete reverts exactly (BRT-002, -025).
8. Overlap block, similar confirmation and exact-duplicate refusal, each with a link to the existing trip (BRT-013).
9. Server HARD/SOFT validation rules and messages as listed in audit §7.2/§7.3, except the masked overload (see below).
10. Garage (circuit) selection determines the Primar route variant, km and price, and is stored as `loc_plecare` / `loc_intoarcere` (BRT-018).
11. Field names, hidden inputs and the `data-role` contract listed in audit §12. Edit and phase forms depend on them.

## Must preserve semantically
*The business behavior stays; the interaction may change.*

1. Beneficiary → allowed types → eligible vehicles → drivers → route pairs, as a dependency chain.
2. Unconfigured vehicle: the operator decides between "this trip only" and "permanently". The redesign may present this differently, but must keep the decision through the whole save flow.
3. Inactive vehicle/driver: an approval is required before saving (reviewers approve now or later; non-reviewers request). The redesign must **add** a driver path for non-reviewers (B3) or a clear explanation.
4. Incomplete trips can be saved after explicit confirmation that lists what is missing.
5. Default load location and zone from the vehicle (garage name, then configuration). The redesign may ask before overwriting.
6. Vehicle capacity shown, with fill-grade warnings (non-blocking).
7. The vehicle's existing trips shown while creating, with same-day and similar highlighting.
8. Post-save prompt for expenses ("Da" / "Nu e cazul" / "Nu acum").
9. Date entry accepting the operator formats (`dd/mm/yyyy`, `HH:mm`, `0830`, `8h30`, ISO).

## Safe to redesign
*Presentation only, no business dependency.*

1. Field order and grouping, the grid, card layout, column widths, and placement of the totals and the submit button.
2. Labels and wording, including unifying the km labels and Romanian diacritics/encoding.
3. The date-picker UI (keep the accepted formats and the hidden dd/mm/yyyy + HH:mm contract, or update the server normalizer accordingly).
4. The Tip marfă control (single choice).
5. Placement of the GPS panel, the open-trips badge and the existing-trips panel.
6. Notification and flash presentation after save.
7. Loading and busy indicators; inline validation messages (in addition to the server).
8. Keyboard flow and focus management.

## Existing bugs — DO NOT PRESERVE

1. Vehicle, driver, garage and route-decision state dropped on every validation or incomplete round trip, making "Alt vehicul" trips unsaveable (B1).
2. Hidden "Cantitate Încărcată" after leaving Compresor, still posted and priced (B2).
3. Non-reviewer dead end for inactive drivers (B3).
4. Stale `km_cursa` / `tona_livrata` across type changes, priced (B4, B5).
5. Stacked or stale modals (B6, B7); stale existing-trips panel (B8).
6. Circuit selects visible with no value (B9).
7. Displayed total changing across a reload, and silent 403 on the preview (B10, B12).
8. Self-locking Loc/Zonă/garage dropdowns (X3).
9. Garage choices carrying over and selecting a variant for a different route.
10. Overload message masked by the "capacity not verified" message (B11).
11. Keyboard focus lost to `<body>` (B13); per-option network calls while arrowing.
12. Encoding glitches (B14); duplicate location names before a type is chosen; misleading vehicle `title`; "Tipul de transport este invalid" for an empty choice.

## Requires decision before redesign

1. **Which price is authoritative while typing?** The local estimate uses today's legacy rates; the server uses the version at the trip date; the save uses the legacy formula unless a component is versioned. Should all users (including those without `tarife_transport.view`) see the server quote?
2. **Distribuție/P+D without a matching route rule:** should it bill the fallback (as stored today) or 0 (as the server preview shows)? (D3)
3. **"Adaugă permanent pe rută" from the trip form:** keep it for every operator (today there is no ACL) or restrict it to configuration admins?
4. **Inactive driver for non-reviewers:** add a "request approval" path like vehicles, or keep it blocked with an explicit explanation?
5. **"Capacity not verified" warning:** should it keep forcing the incomplete confirmation on every trip of such vehicles?
6. **Overload (quantity > capacity):** stay a soft warning, or become blocking?
7. **Compresor relocation km (`km_dislocare`):** should they count on the odometer?
8. **Two "incomplete" rule sets** (save-time soft errors vs the "curse cu informații lipsă" panel): unify or keep separate?
9. **Exact duplicate vs overlap wording:** should an identical trip with times be reported as "duplicate" rather than "overlap"?
10. **Tip marfă:** single value (as the UI behaves) or multiple (as the column allows)?
11. **Changing beneficiary or type after data entry:** warn and confirm before clearing, or keep silent clearing?
12. **Edit and phase forms:** redesign together with Add (shared JS and contract) or later (risk of divergence)?
