# TRIP_RESUME_PHASE_BROWSER_AUDIT — "Reia cursa" / resumed trips (phases)

| | |
|---|---|
| **Date** | 2026-10-07 |
| **Scope** | The "Reia cursa" workflow (adding, editing and deleting phases of an existing trip) on Dispecer curse |
| **Method** | Tested in the running local application, through the normal UI, compared against the code and against [TRIP_FORM_FUNCTIONAL_AUDIT.md](TRIP_FORM_FUNCTIONAL_AUDIT.md) §9.3 and [TRIP_FORM_BROWSER_TEST_REPORT.md](TRIP_FORM_BROWSER_TEST_REPORT.md) |
| **Changes made** | None to code, CSS, schema, configuration or permissions |
| **Evidence** | `docs/trip-resume-browser-test/` |

Test IDs are **RES-xx**. Code references:

| Abbreviation | File |
|---|---|
| C | `htdocs/controllers/DispecerCurseController.php` |
| M | `htdocs/models/DispecerCurseModel.php` |
| EDT | `htdocs/views/dispecer_curse/edit.php` |
| VIEW | `htdocs/views/dispecer_curse/view.php` |

---

## 1. Executive summary

**What "Reia cursa" is.** It adds a **phase** (`curse_segmente` row) to an existing trip. The first time, the original trip is silently copied into "Faza 1", and the new entry becomes "Faza 2". The trip stays a single `curse_dispecer` row whose dates, km, quantities, clients and hours are then **re-derived from the sum of its phases**, and it is **repriced**.

**How it looks to the operator.** The UI is the full Edit/Add trip form, under the page heading **"Editeaza cursa"**. The only phase cue is a small card title, "Faza N (nouă)", and the page anchor scrolls that title **under the fixed top bar**. Every parent-level control (Beneficiar, Tip transport, Tip marfă, Data încărcare, Loc/Zonă) is shown editable. Several of them are **silently discarded** on save.

**Contradictions between what the UI claims and what happens:**

- **"Fără tarif suplimentar"** (tooltip and success flash) is false for tonnage-billed trips. A Distribuție phase raised the total from 680 to 1.105 lei, and a P+D phase from 2.464,50 to 2.839,50 lei. For Primar km it is true only because the price uses the route's agreed km.
- **P+D phase with no km typed added 1.350 km** to the odometer. The read-only "Km agreați" field is auto-filled and posted, and it becomes the phase km when "Km efectuați" is empty.
- **Phase validation is minimal.** Phases saved through the UI included:
  - overlapping phases;
  - an **inactive driver** (no approval check, no modal);
  - another beneficiary's Loc/Zonă;
  - Compresor hours on a Distribuție trip.
- **Invoiced trips can be resumed.** On a "facturat" trip the phase changed quantity, km and dates, while the billed total stayed frozen.
- **Any phase validation error loses everything typed.** Errors come back as one flash message that is **off-screen**, with no inline errors.
- **Editing the parent's km** on a multi-phase Distribuție trip is possible. The read-only lock is removed by the JS. The edit is stored and moved the odometer by +649 km, then was silently overwritten at the next phase change.
- **Repricing changes `total_facturare` but not `tariff_breakdown_json`**, so stored traceability goes stale (DATA INTEGRITY / AUDITABILITY RISK).

**Do the runtime and business behavior support the target model "Cursa #123 continuă → Adaugă o nouă etapă"?** Yes:

- The trip stays one record.
- Phases own vehicle, driver, interval, km, quantities, delivered tons, clients and hours.
- Everything else (beneficiary, transport type, goods, loading date, route used for pricing, billing) belongs to the parent.

The current UI does not express this model.

---

## 2. How "Reia cursa" is reached

| Place | Control | Condition shown | Evidence |
|---|---|---|---|
| **Desfășurător list → click a row → trip card overlay "Cursa #N · doar vizualizare"** | Button "↻ Reia cursa" (top right, next to Editează / Șterge) | Whenever the user can edit (`$dispCanEdit`). **No condition** on billing status, end date or existing phases. Shown on an invoiced trip (RES-15). | 01-trip-card-reia-cursa.jpg; VIEW:120 |
| Full view page `action=view&id=N` | same button | same | VIEW:120 |
| Edit page, trip mode | "↻ Reia cursa" next to "Salveaza cursa" | only while the trip has **no** phases | EDT:1019-1043 |
| Edit page, on the **last** phase | "↻ Reia cursa" next to "Salveaza faza" | last phase only; not on earlier phases and not on the parent once phases exist | EDT:1021 |
| List row itself | none | — | — |
| Legacy `?resume_id=` | redirect to `edit&faza=noua` | no current UI emits it | C:1279 |

**Tooltip (both places):** "Deschide formularul cursei pregătit pentru o fază nouă (alt șofer / alt vehicul). Cursa rămâne una singură, fără tarif suplimentar."

**Is it obvious?** Not really:
- "Reia" (resume) suggests continuing an *unfinished* trip.
- The tooltip explains the use (another driver or vehicle) only on hover.
- Nothing tells the operator that the original trip will become "Faza 1".

---

## 3. Current operator workflow (observed)

1. Click the row in the Desfășurător list. The trip card opens.
2. Click "Reia cursa". The browser goes to `?page=dispecer_curse&action=edit&id=795&faza=noua#race-form`, document title "Editare cursa".
3. The anchor scrolls the page so that the **card header "Faza 2 (nouă)" is hidden under the top bar**. The operator lands on the Beneficiar row (02-resume-landing-header-hidden.jpg).
4. The form looks like the full trip form (03-faza-2-noua-full-form.jpg):
   - pre-filled with the parent's (or previous phase's) vehicle, driver, Loc, Zonă, Beneficiar, Tip transport, Tip marfă and Data încărcare;
   - start date-time = previous end;
   - end and all quantities empty;
   - "Total facturare (estimare)" 0,00 lei.
5. Below the form: "Adaugă cheltuială" / "Cheltuieli cursă" sections (parent-level).
6. Fill end, km, quantities, observations, then click "Salvează faza".
7. Redirect to the **list**, with the flash "Cursa #795 a fost reluata: faza a fost inregistrata pe aceeasi cursa, fara tarif suplimentar."
8. The parent now has phases: in Edit, two small unlabeled arrows (‹ ›) let the operator step "Cursa → Faza 1 din N → … → Faza N din N".

**Operator mental model (someone who does not know the DB):**

| Question | Answered by the UI? |
|---|---|
| What is a phase? | No. The word "Faza" appears only in the card title and the success flash. |
| What belongs to the trip vs the phase? | No. All fields look equally editable. |
| What is inherited? | Partially: values are pre-filled, but there is no "from trip" marking. |
| What adds up, what is replaced, what is recalculated? | No. Nothing says that km/quantities add to the trip or that the price is recomputed. |
| Does it stay one trip? | Only the tooltip and the flash say so. |
| That the original trip became "Faza 1"? | No. The operator never created it; it appears in the arrows afterwards. |

**Conclusion:** using the feature correctly requires prior knowledge of the data model.

---

## 4. Phase field inventory (new phase, Distribuție parent #795; confirmed by save)

**Legend:**
- **P** = belongs to the phase (saved in `curse_segmente`).
- **T** = belongs to the parent trip.
- **Discarded** = editable in the UI but not saved anywhere.

| Field (label) | Prefilled with | Editable? | Persisted? | Owner | Roll-up / pricing effect | Misleading-control rating |
|---|---|---|---|---|---|---|
| Beneficiar transport | parent value; **all beneficiaries offered** | yes | **Discarded** (RES-06: changed to Forvest, parent stayed ButanGas) | T | changing it re-filters vehicles/locations, so it influences what *is* saved | **CRITICAL** |
| Tip Transport | parent; all 5 types | yes | **Discarded** (RES-07: Compresor phase on a Distribuție trip) | T | changes which fields are posted and the km field that wins | **CRITICAL** |
| Nr. înmatriculare | last phase's vehicle, else parent's | yes; list = configured vehicles + stored out-of-scope; no "Alt vehicul" | **P** (`vehicle_id`) | P | odometer share; parent vehicle unchanged | OK |
| Șofer | last phase's driver, else parent's | yes, incl. "Alt șofer" | **P** | P | no inactive check | OK (validation gap) |
| Data încărcare | **parent's** loading date | yes | **Discarded** | T | none | **HIGH** |
| Data și ora început | previous phase end (or parent end) | yes | **P** | P | earliest phase start → parent start | OK |
| Data și ora sfârșit | empty | yes | **P** | P | **last phase's** end → parent end | OK |
| Loc încărcare | last phase's, else parent's | yes | **P** (`loc_incarcare_id`), even another beneficiary's location | P (stored), but pricing uses the **parent's** | not used for pricing | **HIGH** (looks like it changes the route/price; it does not) |
| Zona / Loc descărcare | same | yes | **P**, same caveat | P/T | not used for pricing | **HIGH** |
| Loc plecare (garaj) / întoarcere (Primar circuits) | — | yes when visible | `loc_plecare` → P; `loc_intoarcere` **Discarded** | P/T | — | MEDIUM (not exercised on a circuit trip) |
| Tip marfă | parent | yes | **Discarded** (RES-02: Butan → parent stayed Propan) | T | none | **HIGH** |
| Cantitate încărcată | empty | yes | **P** | P | **summed** into parent `cantitate_incarcata` | OK |
| Capacitate transport reală | vehicle capacity | read-only | — | info | none | LOW (needless) |
| Km efectuați (`km_cursa`) – Distribuție | empty | yes | **P** (`km`) | P | summed into parent `km_cursa`; odometer | OK |
| Km agreați (`km_cursa`) – P+D/Primar | **route km, auto-filled, read-only** | no | **used as phase km if "Km efectuați" is empty** | — | **adds the full route km to km_totali and to the odometer** | **CRITICAL** |
| Km efectuați / Km totali (`km_totali`) – P+D/Primar | empty | yes | **P** (wins over Km agreați) | P | summed into parent `km_totali` | OK, but two km fields shown |
| Nr. clienți | empty | yes | **P** | P | summed | OK |
| Cantitate livrată (tone) | empty | yes | **P** | P | summed (Distribuție/P+D/Compresor) | OK |
| Ore aspirare (Compresor fields) | empty | yes | **P** as `ore_functionare` (numbers only; "2h" rejected) | P | summed into parent `ore_functionare`, **even on non-compressor parents** | MEDIUM |
| Loc plecare / aspirare / livrare / închidere (Compresor text) | `loc_plecare` from previous | yes | `loc_plecare`, `loc_livrare` → P; `loc_aspirare`, `loc_livrare_cursa` **Discarded** | P/T | — | **HIGH** (2 of 4 discarded) |
| Km efectuați (`km_dislocare`, Compresor) | empty | yes | **P** (`km`, highest precedence among posted fields) | P | summed into the parent km field of the parent's type | MEDIUM |
| Tonă lichidă / gazoasă aspirată | empty | yes | **Discarded** | — | none | **HIGH** |
| Observații | empty | yes | **P** (phase observation; parent observation untouched) | P | none | LOW (not obviously phase-only) |
| Total facturare (estimare) | phase-only local estimate (0 → e.g. 425 lei; P+D: 1.714,50 before any input) | display | — | — | **does not show the trip total that will result** | **CRITICAL** (wrong number for the decision) |
| Cost/km Distribuție / Mixt | phase-only | display | — | — | — | MEDIUM |
| Expense sections below the form | parent's | yes | parent-level (separate forms) | T | — | MEDIUM (distracting) |

---

## 5. Parent vs phase ownership (runtime-confirmed)

| Data | Owner | Evidence |
|---|---|---|
| Beneficiar, Tip transport, Tip marfă, Data încărcare, `data_cursa`, `status_facturare`, Observații (trip) | **Parent** | RES-02/06/07: unchanged after phases changed them |
| Loc/Zonă used for **pricing** | **Parent** | RES-06: phase stored Forvest Tileagd→Bihor, parent stayed BGR Navodari→Moldova and was priced on it |
| Parent vehicle / driver | **Parent**, but synced *into* phase 1 when the parent is edited (`syncEdgeSegmentsWithRace`) | RES-06: phase 3 vehicle 16 / driver 28, parent stayed 28 / 41 |
| Vehicle, driver, interval, km, quantity, delivered tons, clients, hours, Loc/Zonă (stored), observation | **Phase** | stored rows 194–202 |
| Parent start / end / duration | **Derived**: first phase start, **last phase's** end | RES-02/05/06 |
| Parent km / quantity / delivered / clients / hours | **Derived**: sums | RES-02, -05, -06, -07, -10 |
| Price, cost/km | **Derived** by repricing (except invoiced) | RES-02, -10, -15 |
| `tariff_version_id`, `tariff_breakdown_json` | **Not refreshed** by phase changes | RES-10 |

---

## 6. Browser behavior — test log

### Test data

| Parent | Type / route | Phases |
|---|---|---|
| **#795** | Distribuție, ButanGas, B 605 NET, BGR Navodari→Moldova, 10/12/2026 | 4 |
| **#796** | P+D, ButanGas, B 605 NET, Contesti→Oradea, 13/12/2026, tariff version 120 | up to 3, then deleted back to 1 |

Both were created through the Add form with observations "TEST AUDIT RESUME BRT …".

### RES-01 — Parent baseline (#795)
| | |
|---|---|
| **Parent before** | 10/12 08:00–18:00; km 200; qty 10; delivered 8; clients 3; total **680,00** (8 × 85, rule 72, legacy); `tariff_version_id` NULL |
| **Odometer** | vehicle 28 +200 |

### RES-02 — Basic resume: phase 2
**Phase entered:** end 11/12 12:00; km 150; qty 6; delivered 5; clients 2; plus **Tip marfă changed to Butan** and **Data încărcare changed to 11/12**.

| Item | Result |
|---|---|
| Phase 1 | auto-created from the parent (id 194: 200 km / 10 / 8 / 3) |
| Phase 2 | stored as entered (id 195) |
| Parent end / duration | 11/12 12:00; duration 1680 min |
| Parent km / qty / delivered / clients | 350 / 16 / 13 / 5 |
| Parent total | **680 → 1.105,00** (13 × 85) |
| Tip marfă | **Propan (Butan discarded)** |
| Data încărcare | **09/12 (change discarded)** |
| Vehicle, driver, beneficiary, route | unchanged |
| Odometer | vehicle 28 +150 |
| Flash | "…fara tarif suplimentar." **Contradicted** (+425 lei) |
| Phase form estimate before save | 425,00 lei (the increment, not the resulting total) |

Matches `refreshRaceFromSegments` (M:5919) and `sumSegmentTotals` (M:5895) exactly.

### RES-03 — Parent view with phases
- Header: two unlabeled arrow buttons (‹ disabled, ›) and the title "Date cursa". **No list of phases, no count badge.**
- Read-only: Cantitate, Nr clienți, Cantitate livrată.
- The **"Se calculează din faze (umblă la ele cu săgețile din antet)." note exists only as a `title` tooltip**; no visible text.
- **Km efectuați is NOT read-only.** The server rendered it read-only, but `syncTransportMode` resets `readOnly` for Distribuție.
- "Reia cursa" is no longer offered on the parent; it only appears on the last phase.
- Evidence: 04-parent-with-phases-arrows-km-editable.jpg

### RES-04 — Editing the parent's km on a multi-phase trip
**Steps:** parent km 350 → 999, Salvează cursa (incomplete confirmation for capacity), saved.

**Stored state after the edit:**

| Item | Value |
|---|---|
| Parent `km_cursa` | 999, while the phases sum to 350 |
| Cost/km | 1,11 (computed from 999) |
| Odometer (vehicle 28) | **+649** |

**After the next phase edit (RES-05):** the parent km became 380 **without notice** and the odometer was corrected.

**Classification:** DATA INTEGRITY RISK + BUG. Severity HIGH.

### RES-05 — Phase editing
**Steps:**
1. Arrows → "Faza 1 din 2", which offers Salvează faza / Șterge faza / Înapoi la cursă. Its estimate shows **680 lei** (phase-only figure labelled "Total facturare").
2. "Faza 2 din 2" additionally offers "Reia cursa".
3. Changed phase 2: end 15:00, km 180, qty 7, delivered 6. Saved, then redirected to the **list**.

**Result:**

| Item | Value |
|---|---|
| Parent end | 15:00 |
| Parent km | 380 (overwrote the manual 999) |
| Parent qty / delivered | 17 / 14 |
| Parent total | **1.190,00** |
| Odometer | consistent (baseline + 380) |
| Stale totals | none |

**Difficulty:** the only way to know which phase is open is the small title ("Faza 2 din 2"), which the anchor scroll hides under the top bar.

### RES-06 — Overlapping phase, other vehicle, inactive driver, other beneficiary
**Phase 3 entered:**
- Beneficiar changed to Forvest;
- vehicle B 375 NET (16);
- driver **Bodiu Sorin (inactive: ADR expired)**;
- Forvest Tileagd→Bihor;
- Autogaz; loading date 12/12;
- interval **11/12 10:00–20:00**, overlapping phase 2 (10/12 18:00–11/12 15:00);
- km 90; qty 3; delivered 2,5; clients 1.

**Result:**
- Saved **without any warning**. No overlap check between phases, no inactive modal, no approval record.
- The phase stored vehicle 16, driver 28 and Forvest Loc/Zonă (80/82).
- The parent stayed ButanGas, BGR Navodari→Moldova, Propan, vehicle 28, driver 41.
- The parent was repriced on the **parent** route: 16,5 × 85 = **1.402,50**.
- Vehicle 16 odometer +90; vehicle 28 untouched.

### RES-07 — Compresor-type phase on a Distribuție trip, "2h" format
- **First attempt:** Tip transport → Compresor, Ore aspirare "2h". Rejected: "Faza nu a fost salvata: Orele de functionare trebuie sa fie un numar pozitiv." The Add form accepts "2h". **All 9 typed values were lost**, including the type switch.
- **Second attempt:** "2" with km dislocare 40, delivered 1, four location texts. Saved. Results:
  - type discarded;
  - phase km = 40, from `km_dislocare`;
  - parent km 510;
  - **parent `ore_functionare` = 2.00 on a Distribuție trip**;
  - total 1.487,50;
  - `loc_aspirare`, `loc_livrare_cursa` and the liquid/gas tons were discarded.
- **Revision km:** 2 h × 40 = 80 revision-km were split by km share. **Vehicle 28 lost 60 revision-km for hours worked by vehicle 16.** Classification: DATA INTEGRITY RISK, MEDIUM.

### RES-08 — Phase without end / end < start / before parent / missing vehicle / negative km
| Case | Frontend | Backend |
|---|---|---|
| Empty end | **blocked**: red border, focus, no message. The server would accept an open-ended phase. | — |
| End < start | not checked | rejected |
| Start 01/12 (< parent start 10/12) | not checked | "Faza nu poate incepe inainte de inceputul cursei (10.12.2026)." |
| Vehicle cleared | — | "Alege vehiculul fazei." (the driver was kept, so no driver error) |
| km −5 | — | "Km fazei trebuie sa fie un numar pozitiv." |
| **Presentation** | — | **one combined flash** "Faza nu a fost salvata: …"; **no inline errors** |
| **After the error** | — | page reopened on the new phase; **all input lost** (form reset to prefilled defaults); the page scrolls so that **the flash is off-screen** and the card title is under the top bar (05-phase-error-flash-offscreen-input-lost.jpg) |

### RES-09 — Phase starting exactly at the previous end
The default prefill. Accepted. The parent interval becomes continuous.

### RES-10 — P+D parent (#796), km precedence and traceability
**Parent:** 2.464,50; version 120; `tariff_breakdown_json.total` 2.464,5; km_cursa 1.350 (agreed), km_totali 1.500.

**Phase 2 (Km efectuați left empty, qty 5):**
- The form showed **Km agreați 1.350 read-only** and an estimate of **1.714,50 lei before any input**.
- Posted `km_cursa=1350`, `km_totali=''`, so the **phase km = 1.350**.
- Parent km_totali 2.850, qty 15, total **2.839,50**.
- Odometer **+1.350**, although the operator typed no km.
- `tariff_version_id` 120 (unchanged, still valid), but **`tariff_breakdown_json.total_facturare` still 2.464,5**. Traceability is stale.

**Phase 3 (Km efectuați 300, qty 2):**
- Posted `km_cursa=1350`, `km_totali=300`, so **phase km = 300**.
- km_totali 3.150, total 2.989,50.
- The phase view later showed an estimate of **1.864,50 lei** for this 300 km / 2 t phase (agreed km re-priced).

**Precedence confirmed:**
1. `km` (not posted by any form)
2. `km_dislocare`
3. `km_totali`
4. `km_cursa`

The UI shows two km fields (one read-only) with no hint that only one is used.

**Classification:** DATA INTEGRITY / AUDITABILITY RISK (stale breakdown) and CRITICAL UX (phantom km).

### RES-11 — Phase deletion
- **"Șterge faza"** uses `data-confirm` → native `window.confirm()` (app.js:9). In this embedded browser the dialog is blocked, so **the click does nothing**. In a normal browser a native dialog appears. The deletion was then tested by submitting the same form the button triggers.
- **Delete phase 3 of 3:** the row is **hard-deleted**. Parent re-rolled to 2 phases (km 2.850, total 2.839,50), odometer −300.
- **Delete phase 2 of 2:** **all phase rows removed** and the trip is a normal single trip again. Parent values identical to the original: total 2.464,50, km_totali 1.500, same duplicate key. Odometer consistent.
- Breakdown JSON was stale until the trip returned to its original total.

### RES-12 — Vehicle / driver rules
- **Vehicle list in phase mode:** vehicles configured for the beneficiary × type currently selected in the (discarded) beneficiary/type fields, plus stored out-of-scope values. **No "Alt vehicul"** and no route-decision modal.
- **Server check:** `vehicle_id > 0` only. No existence, configuration or inactive check (C:1805).
- **Driver:** assigned drivers plus "Alt șofer". Server check: `driver_id > 0` only.
- **Inactive checks:** none. The form has no `data-inactive-resource-status-url` in phase mode (EDT:560).
- **Parent vehicle/driver:** never changed by phases.
- **Odometer:** split across vehicles by phase km share (RES-06/07).
- **Pricing:** unaffected by the phase vehicle. The parent is repriced with the parent vehicle (configured check passes for it).

### RES-13 — Transport-type-specific phase forms
| Parent type | Phase km field(s) shown | Field that wins | Notes |
|---|---|---|---|
| Distribuție | Km efectuați (`km_cursa`) | km_cursa | quantities and delivered tons summed; price follows delivered tons |
| P+D | Km agreați (RO, auto = route km) + Km efectuați (`km_totali`) | km_totali if > 0, **else the route km** | RES-10 |
| Primar km / tone | same pair as P+D (km_cursa RO auto + km_totali) | same rule | expected to add to km_totali only; price unchanged, since it uses agreed km. **NOT SAVED in this run** (code-consistent with P+D behavior) |
| Compresor | Km efectuați (`km_dislocare`), Ore aspirare | km_dislocare | "2h" rejected; liquid/gas tons and 2 of 4 locations discarded |
| Switching type inside a phase | the field set changes | — | type discarded; the visible fields of the *new* type decide what is saved (RES-07) |

### RES-14 — Refresh / back / cancel
- **Refresh** (real reload) keeps the mode ("Faza 5 (nouă)") and **silently drops unsaved input**. No `beforeunload` guard.
- **Navigating to the same URL with `#race-form`** does not reload, so the values stay. Inconsistent.
- **"Renunță"** links to the parent edit view, with no confirmation and no warning.
- **Back:** not separately tested; with no guard, unsaved data is lost the same way (NOT EXECUTED in isolation).

### RES-15 — Invoiced trip
**Setup:** #796 temporarily set to "facturat" (test trip, reverted afterwards).

**Result:**
- "Reia cursa" is **still offered** on the trip card.
- The phase form showed the warning "Cursa este facturata. Valorile comerciale sunt imutabile si nu se recalculeaza la salvare." **rendered twice**.
- A phase (km 100, qty 3) was **saved**. Parent: end date → 14/12, qty **10 → 13**, km_totali **1.500 → 1.600**. Total **frozen at 2.464,50**; cost/km unchanged (now inconsistent).
- Success flash as usual.

**Classification:** DATA INTEGRITY / BILLING RISK. Operational data of an invoiced trip diverges from its invoice.

### RES-16 — Keyboard
- On arrival focus is on `<body>`. **58 Tab stops** precede the first field.
- **20 stops** inside the phase form. 6 of them are parent/discarded fields: Beneficiar, Tip, Data încărcare + calendar button, Tip marfă, read-only Capacitate.
- The operator really needs about 9 stops: Vehicul, Șofer, Start, End, Km, Cantitate, Livrată, Clienți, Observații.
- Arrows between phases are links reachable by Tab, but have no visible label (titles only).
- The same keyboard issues as Add apply (shared JS): the ↓ key commits options; focus is lost after the goods dropdown.

### RES-17 — Responsive
| Viewport | Grid (cols per row) | Phase card height | Card header after anchor | Submit visible after anchor? |
|---|---|---|---|---|
| 1920×1080 | 4-4-3-3-3-1 | 676 | hidden under the 71 px top bar | yes |
| 1536×864 | 4-4-3-3-3-1 | 705 | hidden | yes |
| 1366×768 | 4-4-3-3-3-1 | 705 | hidden | yes (evidence 07) |
| 1280×720 | 4-4-3-3-3-1 | 705 | hidden (header at 0–37 px) | yes, barely (bottom 690/720) |

The layout shifts by transport type described for Add apply here too (shared markup and JS).

---

## 7. Validation

| Rule | Add trip | Phase (`validateRaceSegmentInput`, C:1793) |
|---|---|---|
| Vehicle | exists + configured for beneficiary × type (or decision) + inactive approval | `> 0` only |
| Driver | exists (SOFT if empty) + inactive approval | `> 0` only (HARD) |
| Start | required, valid | required |
| End | required in the UI; time optional | server: optional; **UI: blocks empty end** |
| End ≥ start | yes | yes |
| Start ≥ parent start | — | yes |
| Overlap with other trips | yes (client + server) | client AJAX only (parent excluded); **no server check** |
| Overlap between phases | n/a | **none** |
| Similar / duplicate | yes | none |
| Location / zone belong to the beneficiary | yes (HARD) | none |
| Goods type, loading date, beneficiary, type | validated | **ignored** |
| Hours format | "2", "2h", "2 ore" | numbers only |
| Integers | silent truncation | clients must be digits; km rounded |
| Soft "incomplete" confirmation | yes | none |
| Error display | inline per field, values kept | **one flash, off-screen; input lost** |

---

## 8. Vehicle / driver rules

See RES-12. Summary:
- A phase may use any vehicle in the shown list and any driver, including inactive ones, with no approval.
- The parent's vehicle and driver never change.
- km are attributed to each phase's vehicle; hours-based revision km are split by km share.

---

## 9. Transport-type behavior

See RES-13. The phase form keeps the parent's transport-type layout, but the operator can switch type. The visible fields of the switched type are then the ones saved, while the type itself is discarded.

---

## 10. Roll-up rules (confirmed)

| Parent field | Rule | Confirmed |
|---|---|---|
| `data_inceput` / `ora_inceput` | first phase (by order) | ✅ |
| `data_sfarsit` / `ora_sfarsit` | **last phase's** end (by order, not the maximum end) | ✅ (with the overlapping phase 3 the end came from phase 3; an earlier-ordered later-ending phase was not tested) |
| `durata_cursa_minute` | first start → last end | ✅ |
| km | Σ phase km → `km_totali` for Primar/P+D, `km_cursa` otherwise | ✅ |
| `cantitate_incarcata` | Σ | ✅ |
| `tona_livrata` | Σ (Compresor, Distribuție, P+D only) | ✅ |
| `nr_clienti` | Σ | ✅ |
| `ore_functionare` | Σ (any type) | ✅ (on Distribuție) |
| vehicle, driver, beneficiary, route, goods, loading date, type, status | **not** rolled up | ✅ |
| `duplicate_key` | rebuilt | ✅ |

---

## 11. Repricing behavior

| Case | Before | After | Tariff version | Breakdown JSON |
|---|---|---|---|---|
| Distribuție + phase (legacy rule) | 680,00 | 1.105,00 → 1.190 → 1.402,50 → 1.487,50 | NULL | n/a |
| P+D + phase (versioned) | 2.464,50 | 2.839,50 → 2.989,50 | 120 (unchanged) | **stale: 2.464,5** |
| P+D after deleting phases | 2.989,50 | 2.839,50 → 2.464,50 | 120 | stale until back to the original |
| Invoiced | 2.464,50 | **2.464,50 (frozen)** | — | — |

- Reprice abort when the parent's vehicle is "doar pentru această cursă" (audit §9.3): **NOT EXECUTED**. Such a parent cannot be created through the UI (see the browser test report, B1).

---

## 12. Multi-phase behavior

- Navigation is **by arrows only**: Cursa → Faza 1 din N → … → Faza N din N. There is no list, no summary of phases with their vehicle/driver/km, and no direct link per phase in the edit page (the trip card lists phases with links).
- "Reia cursa" appears only on the last phase.
- Each phase view shows a **phase-only** "Total facturare (estimare)" (e.g. 680 on phase 1 of a 1.105 trip).
- The parent's "computed from phases" note is tooltip-only, and km stays editable (RES-03/04).

---

## 13. Edit / delete phase behavior

- **Edit:** works; the parent re-rolls and reprices; the odometer is consistent; redirects to the list.
- **Delete:**
  - native `confirm()` (blocked in the embedded browser);
  - hard delete;
  - re-roll + reprice;
  - when ≤ 1 phase remains, all phase rows are removed and the trip becomes normal again.

---

## 14. Error recovery

| Situation | Experience |
|---|---|
| Server validation error | flash at page top (scrolled out of view); form reset to the prefilled defaults; **all typed values lost**; the phase title is hidden |
| Empty end | red border only; no explanation that a phase may not be open-ended in the UI |
| Wrong hours format ("2h") | same as server error; the Add form accepts that format |
| Overlap between phases | not detected |
| Mistaken phase saved | must find it with the arrows, then delete via a native confirm |

---

## 15. Keyboard UX

See RES-16. It is worse than Add for the target task:
- 6 irrelevant tab stops;
- no focus placement on the first phase field;
- phase navigation through unlabeled icon links.

---

## 16. Responsive behavior

See RES-17. The submit button stays reachable thanks to the anchor scroll, but the **phase identity (title) is always hidden** after landing.

---

## 17. Add vs Resume comparison

| Behavior | Add trip | Reia cursa / phase | Looks same, behaves differently? |
|---|---|---|---|
| Page / heading | "Dispecer curse" → "Adaugă Cursă" | "Editeaza cursa" → "Faza N (nouă)" (hidden) | — |
| Fields shown | trip fields per type | **the same trip fields** | ⚠️ yes |
| Fields saved | all visible | vehicle, driver, interval, Loc/Zonă, km, qty, delivered, clients, hours, loc_plecare/livrare, observation | ⚠️ **yes: beneficiary, type, goods, loading date, compressor tons, 2 locations discarded** |
| Validation | full HARD/SOFT (audit §7) | minimal (§7 above) | ⚠️ yes |
| Inactive-resource checks | yes, with approvals | **none** | ⚠️ yes |
| Configuration check (vehicle × beneficiary × type) | yes + "Alt vehicul" decision | none (but the list is filtered) | ⚠️ yes |
| Overlap with other trips | client + server | client only | ⚠️ yes |
| Overlap between phases | n/a | none | — |
| Similar / duplicate | yes | none | ⚠️ yes |
| Pricing preview | local + server versioned preview | local only, **phase-only figure** | ⚠️ yes |
| Tariff resolution on save | versioned + traceability written | versioned; **traceability not updated** | ⚠️ yes |
| Vehicle handling | eligible + "Alt vehicul" + modal | eligible + stored; no modal | ⚠️ yes |
| Driver handling | assigned + "Alt șofer" + inactive modal | assigned + "Alt șofer", no inactive modal | ⚠️ yes |
| Hours format | "2h" accepted | "2h" rejected | ⚠️ yes |
| End time | optional (end date required) | UI requires end; server optional | ⚠️ yes |
| Errors | inline, values kept | one off-screen flash, values lost | ⚠️ yes |
| State restoration after error | most values (see B1) | **none** | ⚠️ yes |
| Success | list + flash + expense prompt | list + flash ("fără tarif suplimentar") | — |
| Odometer | `km_totali` or `km_cursa` of the trip | per phase vehicle, by km share; hours share across vehicles | ⚠️ yes |
| Invoiced trips | n/a | allowed; operational data changes, price frozen | — |

---

## 18. Confirmed bugs

| ID | Bug | Class | Severity |
|---|---|---|---|
| RB1 | Editable parent-level fields silently discarded (Beneficiar, Tip, Tip marfă, Data încărcare, 2 compressor locations, liquid/gas tons) | BUG (misleading controls) | CRITICAL |
| RB2 | P+D/Primar phase with empty "Km efectuați" takes the route's agreed km as phase km, inflating km_totali and the odometer | BUG + DATA INTEGRITY | CRITICAL |
| RB3 | Inactive drivers/vehicles usable in phases with no approval and no warning | BUSINESS LOGIC RISK | CRITICAL |
| RB4 | Phases can be added to **invoiced** trips; operational data changes while the price is frozen | DATA INTEGRITY / BILLING | CRITICAL |
| RB5 | Phase validation errors lose all input; one off-screen flash, no inline errors | BUG / UX | HIGH |
| RB6 | Overlapping phases accepted | DATA INTEGRITY | HIGH |
| RB7 | Repricing leaves `tariff_breakdown_json` stale | AUDITABILITY RISK | HIGH |
| RB8 | Parent km editable on a multi-phase Distribuție trip (JS overrides read-only); edit moves the odometer, then is silently overwritten | BUG + DATA INTEGRITY | HIGH |
| RB9 | Phase can store another beneficiary's Loc/Zonă; pricing uses the parent route | DATA INTEGRITY | HIGH |
| RB10 | "fără tarif suplimentar" message false for tonnage-billed trips | BUSINESS LOGIC RISK (communication) | HIGH |
| RB11 | Hours-based revision km split across vehicles by km share (wrong vehicle charged) | DATA INTEGRITY | MEDIUM |
| RB12 | Compresor hours roll into `ore_functionare` of non-compressor parents | DATA INTEGRITY | MEDIUM |
| RB13 | "2h" accepted in Add, rejected in phase | BUG | MEDIUM |
| RB14 | UI forbids open-ended phases that the server allows | BUG | MEDIUM |
| RB15 | Phase/card header hidden under the top bar after the `#race-form` anchor | VISUAL | MEDIUM |
| RB16 | "Se calculează din faze" note tooltip-only | VISUAL/UX | MEDIUM |
| RB17 | Invoiced warning rendered twice | VISUAL | LOW |
| RB18 | "Șterge faza" depends on native `confirm()` (fails in embedded browsers) | BUG (environment) | MEDIUM |

---

## 19. Misleading / editable-but-unused fields

| Control | Classification | Rating |
|---|---|---|
| Beneficiar | DISCARDED SILENTLY (but drives the vehicle/location filters) | CRITICAL UX MISLEADING CONTROL |
| Tip Transport | DISCARDED SILENTLY (but decides which km/qty fields are posted) | CRITICAL UX MISLEADING CONTROL |
| Km agreați (read-only, auto) in P+D/Primar | **USED as phase km** when the visible "Km efectuați" is empty | CRITICAL UX MISLEADING CONTROL |
| Total facturare (estimare) | shows the phase increment, not the trip total | CRITICAL UX MISLEADING CONTROL |
| Tip marfă | DISCARDED SILENTLY | HIGH |
| Data încărcare | DISCARDED SILENTLY | HIGH |
| Loc încărcare / Zonă | SAVED TO PHASE, **not used for pricing** | HIGH |
| Loc aspirare, Loc închidere cursă | DISCARDED SILENTLY | HIGH |
| Tonă lichidă / gazoasă aspirată | DISCARDED SILENTLY | HIGH |
| Loc întoarcere (garaj) | DISCARDED SILENTLY (code; no circuit trip tested) | MEDIUM |
| Capacitate transport reală | display only | LOW |
| Observații | SAVED TO PHASE (not the trip note) | LOW |

---

## 20. Data integrity risks

1. **Phantom km** from the auto-filled agreed km (RB2), feeding the odometer and the cost/km.
2. **Invoiced trips mutated** by phases (RB4).
3. **Overlapping phases** (RB6) and cross-beneficiary Loc/Zonă (RB9) stored without validation.
4. **Stale tariff traceability** after repricing (RB7).
5. **Parent km editable** while derived (RB8); odometer swings.
6. **Hours on the wrong vehicle / wrong trip type** (RB11, RB12).
7. Phase deletion is a **hard delete** (no audit beyond `cursa_audit_log` `segment_sters`).

---

## 21. UX friction

| ID | Friction | Severity |
|---|---|---|
| RU1 | The full trip form is reused; nothing distinguishes "add a phase" from "edit the trip" | CRITICAL |
| RU2 | Phase identity hidden (title under the top bar; page heading "Editeaza cursa") | HIGH |
| RU3 | Original trip silently becomes "Faza 1" | HIGH |
| RU4 | No phase overview (list/summary); navigation only by unlabeled arrows | HIGH |
| RU5 | Phase estimate vs trip total confusion | CRITICAL |
| RU6 | Two km fields, unclear precedence | HIGH |
| RU7 | All input lost on error; error message off-screen | HIGH |
| RU8 | Inheritance chain (vehicle/driver from the previous phase, Loc/Zonă from the parent if the phase has none) is invisible | MEDIUM |
| RU9 | Expense forms below the phase form | MEDIUM |
| RU10 | After saving, redirect to the list (not back to the trip/phases) | MEDIUM |
| RU11 | No unsaved-changes guard | MEDIUM |
| RU12 | Discoverability: "Reia cursa" only in the trip card / edit page, not in the list row | LOW |

---

## 22. Remaining unverified scenarios

| Scenario | Status |
|---|---|
| Primar km/tone phase saved (expected: km_totali sum only, price unchanged) | NOT EXECUTED (code-consistent with P+D) |
| Primar circuit (garage) phase | NOT EXECUTED |
| Reprice abort for a parent with "doar pentru această cursă" vehicle | NOT EXECUTABLE via UI (such parents cannot be saved, browser report B1) |
| Back button | NOT EXECUTED in isolation |
| Order vs latest end when an earlier-ordered phase ends later | NOT EXECUTED |
| Phase deletion via the real `confirm()` in a normal browser | NOT TESTABLE here (embedded browser blocks native dialogs) |
| Inactive *vehicle* in a phase | not separately tested; same code path as the inactive driver (no check) |
| Edit form (parent) behavior for Primar with phases | NOT EXECUTED |

---

## 23. Screenshots

Folder: `docs/trip-resume-browser-test/`

| File | Shows |
|---|---|
| 01-trip-card-reia-cursa.jpg | trip card overlay with Editează / Reia cursa / Șterge |
| 02-resume-landing-header-hidden.jpg | landing after "Reia cursa": phase title hidden, form starts at the fields |
| 03-faza-2-noua-full-form.jpg | the full form in "Faza 2 (nouă)" with parent fields editable |
| 04-parent-with-phases-arrows-km-editable.jpg | parent with phases: arrows, read-only fields, editable km |
| 05-phase-error-flash-offscreen-input-lost.jpg | after a validation error: clean form, no visible error |
| 06-phase-3-view-estimate-includes-agreed-km.jpg | phase view with a 1.864,50 lei estimate for a 300 km / 2 t phase |
| 07-phase-form-1366x768.jpg | phase form at 1366×768 |

---

## 24. Redesign constraints

1. A phase screen must make **trip context read-only** and **phase inputs editable**. Fields that the backend discards must not be editable.
2. The phase km input must be **one explicit field per type**. Read-only derived km must never be posted as phase km.
3. The estimate must show **trip total before → after** (and the increment), and must not claim "no extra tariff" when the billing quantity grows.
4. Validation must be done (or at least shown) **before** losing input, inline, with the phase context visible.
5. The phase list must be visible: number, interval, vehicle, driver, km and quantities per phase, with edit/delete per row.
6. Invoiced trips need an explicit rule (see Requires decision).
7. Phase saves must refresh tariff traceability, or the redesign must not display it as current.
8. Parent derived fields must be visibly locked and labelled, with a link to the phases.
9. Keep the shared JS hooks (audit §12) or redesign Add, Edit and phase together.

---

# RESUME TRIP REDESIGN BASELINE

## Must preserve exactly

1. A resumed trip stays **one** `curse_dispecer` record. Phases are `curse_segmente` rows; the original trip becomes phase 1 on the first resume.
2. **Roll-up:** start = first phase start; end = last phase end; duration; Σ km (→ `km_totali` for Primar/P+D, `km_cursa` otherwise); Σ quantity; Σ delivered tons (Compresor/Distribuție/P+D); Σ clients; Σ hours; `duplicate_key` rebuilt (RES-02/05).
3. Parent beneficiary, type, goods, loading date, route used for pricing, vehicle, driver and billing status are not changed by phases.
4. **Reprice after every phase add/edit/delete** for non-invoiced trips, using the trip's tariff resolution (results observed: 680 → 1.105 → 1.190 → 1.402,50 → 1.487,50; 2.464,50 → 2.839,50 → 2.989,50 and back).
5. Phase start ≥ trip start; end ≥ start.
6. The odometer follows each phase's vehicle by km; deleting phases or the trip reverts it exactly.
7. Deleting phases down to one turns the trip back into a normal trip with its original values.
8. Phase defaults: start = previous end; vehicle/driver/locations from the previous phase (else the parent); measures empty.

## Must preserve semantically

1. "Reia cursa" = add a continuation (another driver/vehicle/period) to the same trip.
2. The operator can review, edit and delete individual phases.
3. Totals are derived from phases and cannot drift from them.
4. The trip is billed once. The rule for how phase quantities affect billing must remain as today (tons summed → billed) unless decided otherwise.
5. Phase vehicle and driver may differ from the parent's.

## Parent trip context only
*Shown for reference; not editable in phase mode.*

- Trip number, beneficiary, transport type, goods type, loading date, billing status.
- Route used for pricing (Loc → Zonă, garages).
- Current trip interval and totals: km, quantity, delivered, clients, hours, total facturare, cost/km.
- Tariff source.
- Existing phases (list).

## Phase inputs operator actually needs

- Vehicle (inherited default; changeable).
- Driver (inherited default; changeable).
- Start date-time (default = previous end).
- End date-time (optional or required: see decision).
- **One** km field (per type: km for Distribuție, real km for Primar/P+D, relocation km for Compresor).
- Loaded quantity, delivered tons, clients (by type).
- Hours (Compresor).
- Phase note.
- Optionally the phase's own load/unload points (see decision).

## Fields that should not be editable in phase mode

Beneficiar, Tip transport, Tip marfă, Data încărcare, Capacitate (display only), Km agreați (route km), Loc întoarcere (garaj), Loc aspirare / Loc închidere cursă, liquid/gas aspirated tons, Total/cost-km (display only), and the trip-level expenses forms (move out of the phase screen).

## Existing bugs — DO NOT PRESERVE

RB1–RB18 above, in particular:
- discarded editable fields;
- phantom agreed-km;
- missing inactive and overlap checks;
- phases on invoiced trips without a rule;
- input loss and off-screen errors;
- stale tariff breakdown;
- editable derived parent km;
- cross-beneficiary Loc/Zonă;
- hours misattribution;
- "2h" inconsistency;
- hidden phase title;
- tooltip-only notes;
- `confirm()`-based delete.

## Requires business decision

1. **Billing of phases:** should adding phase quantities/km increase the trip total (today: yes for tonnage/km-based billing), or should "Reia cursa" never change the price (as the UI promises)?
2. **Invoiced trips:** forbid phases, allow with an explicit warning (operational data only), or require un-invoicing first?
3. **Inactive driver/vehicle in a phase:** apply the same approval flow as Add?
4. **Phase overlap:** forbid, warn, or allow (handover periods)?
5. **Phase Loc/Zonă:** do phases have their own route (and should it affect pricing), or should they inherit the trip route read-only?
6. **Open-ended phase (no end):** allowed (server today) or not (UI today)?
7. **Hours on non-compressor trips** and revision-km attribution per vehicle: which vehicle carries the hours-based revision km?
8. **Primar/P+D phase km:** is the phase km always the real km (Km efectuați) and never the agreed route km?
9. **Parent km on multi-phase trips:** always derived (locked), or may an operator override it?
10. **Tariff traceability:** refresh on every reprice (one breakdown per trip state) or keep the creation-time snapshot (then label it as such)?
11. **After saving a phase:** return to the list (today) or stay on the trip with its phase list?
