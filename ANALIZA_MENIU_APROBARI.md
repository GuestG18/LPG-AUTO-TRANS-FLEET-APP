# ANALIZA_MENIU_APROBARI — Read-only audit of the approval & notification drawer

**Date:** 2026-11-16 · **Scope:** the global right-side drawer "Solicitari aprobare in asteptare" (admin mode) / "Solicitarile mele de aprobare" (user mode), plus the live-approval popup and the fee alarm that share its counters.
**Method:** full code reading (PHP views, controllers, models, JS, CSS, ACL registry) and browser measurements in the built-in browser pane (logged in as admin on `localhost:8000`, dashboard page).

Evidence labels used throughout:
- **[TESTED]**: measured or observed in the browser.
- **[CODE]**: concluded from reading code, not reproduced in the browser.
- **[NOT TESTED]**: could not be exercised (user/operator mode, real approve/reject clicks, real fee actions). I deliberately did not click Aproba / Respinge / Da / Nu / Nu se aplica / file upload, so I made no business decisions.

---

## 1. Executive summary

The "menu" is one server-rendered partial (`views/partials/inactive_approval_drawer.php`) included by the global layout on **every authenticated page**. It is fed by **four independent JavaScript subsystems in `assets/js/app.js`** plus a separate **live popup script (`assets/js/approval-live.js`)**. Together they poll **five JSON endpoints**. It serves two different audiences with different markup (admin vs. requester), and it mixes three unrelated concerns under one title and one counter:

1. **Approval requests** (vehicle / driver / repair / diurna / route config): a real approve/reject workflow, server-rendered once per page load.
2. **Operator activity**: trips added/closed per operator per day, a monitoring dashboard that lives in a tab called "Operatori".
3. **Rebilling-fee control**: "Taxe de refacturat lipsa" (missing fees) plus "Treceri refacturate – confirma achizitia" (purchase confirmation), a financial work queue that also drives a **flashing red alarm on every page for every user**.

Main conclusions:

- **The layout problems have concrete, identifiable root causes.** These were confirmed in the browser:
  - a fixed 420 px panel at a hard-coded `top: 88px` overlaps a topbar that is really 104 px tall;
  - six tabs are forced into one row of 49–63 px columns, so every label wraps to 2 lines, and at 375–430 px "Operatori" is clipped (and at 375 px also "Vehicule" and "Reparatii");
  - two `position: sticky` bars (tabs at the top, fee-purchase at the bottom) eat the scroll area. At 375 px with both fee sections expanded only **~110 px** remain for the main content, and at 150 % zoom on a 1366×768 screen only **~209 px** remain even when collapsed;
  - there is a 3–4 px horizontal overflow on mobile.
- **The counters are semantically misleading.** "N total" under the title "Solicitari aprobare in asteptare" adds missing fees and unconfirmed purchases to the approval count. In the test session the header showed **"5 total" with 0 pending approvals**: all 5 were fee items. The "Operatori (N)" tab badge counts fees, not operators. The operator stats "Adaugate / Inchise / Inca deschise" do not add up, because "Inchise" counts a different population (trips closed that day, whatever day they were created).
- **The drawer is not live for approvals.** Approval cards are rendered once by PHP. Requests that arrive later appear only in the live popup, and decisions taken in the live popup (or by email / WhatsApp / another admin) do not remove the drawer cards or update its counters.
- **Business rules differ between entry points.** Rejecting from the drawer needs no reason (an automatic note is written). Rejecting from the live popup requires one.
- **Architecture risk.** The drawer's JS uses global `document.querySelector(All)` selectors and shared `data-*` hooks that are also used by `views/inactive_approvals/user_index.php` and the (currently disabled) dashboard panel. Every page load runs schema-ensure DDL plus approval-summary queries in the layout, plus the heavy `missing_fees` computation for every user, even with the drawer closed. Polling reloads immediately on every visibility change with no throttle.

---

## 2. Complete file and component inventory

### 2.1 Server side

| File | Role |
|---|---|
| `htdocs/views/layout/header.php:21-54` | Decides whether to show the drawer (`$showSidebar && is_logged_in()`), resolves admin vs. user mode via `can('inactive_approvals','review')`, and loads the summary (`getPendingSummary(5)` or `getRequesterSummary($uid, 5, true)`). |
| `htdocs/views/layout/header.php:338-344` | Includes the partial right after the topbar, before `<main>`. |
| `htdocs/views/partials/inactive_approval_drawer.php` (598 lines) | All drawer markup: toggle button, panel, header, tabs, approval cards (admin: Aproba/Respinge forms; user: status + "Vezi detalii" + "Anuleaza solicitarea"), Operatori tab (missing fees, date toolbar, totals, list, note, fee-purchase bar), user "Taxe lipsa" tab, "Vezi toate solicitarile" link. |
| `htdocs/views/layout/footer.php:18-35` | Config `<div data-approval-live>` and the `approval-live.js` include (all logged-in, non-embedded pages). |
| `htdocs/controllers/InactiveResourceApprovalController.php` | Endpoints: `index`, `show`, `approve`, `reject`, `reopen`, `mark_seen`, `live`, `operator_activity`, `missing_fees`, `fee_purchase_alarm`, `dismiss_missing_fee`, `fee_not_bought`, `fee_no_invoice_needed`, `fee_attach_invoice`. |
| `htdocs/controllers/DispecerCurseController.php:298-302` | `page=dispecer_curse&action=approvals_live` proxies to `live` (so operators without the approvals module can poll). |
| `htdocs/controllers/DispecerCurseController.php:2724` | `applyApprovedRouteConfigRequests()`: applies approved "add permanently to route" requests. It is called on every approve and on **every live poll**. |
| `htdocs/models/InactiveResourceApprovalModel.php` (2186 lines) | Table `inactive_resource_approvals` (+ `_documents`). `ensureSchema()` (DDL), `getPendingSummary`, `getRequesterSummary`, `review` (only from `pending`), `reopen`, `markDecisionsSeen`, `getPendingForLiveReview`, `getTripsAwaitingApproval`, `getUnseenDecisionsForRequester`, diurna and route-config creation. |
| `htdocs/models/OperatorActivityModel.php` | `reconcile()` (writes `curse_inchidere_operator`) and `getDailyActivity()`. |
| `htdocs/models/ReinvoiceFeeExpectationModel.php` | Rules (`reguli_taxe_refacturare`), `getMissingFees`, `dismiss` (→ `curse_taxe_refacturare_ignorate`), `getPurchaseChecks`, `markNotBought` / `markNoInvoiceNeeded` / `clearNotBought` (→ `curse_taxe_refacturare_achizitie`). |
| `htdocs/services/RaceCompletenessService.php` | Defines when a trip is "complete" (shared with the Dispecer "curse cu informatii lipsa" popup). |
| `htdocs/services/ApprovalWhatsAppService.php`, `htdocs/controllers/EmailApprovalController.php` | Alternative decision channels on the same records. |
| `htdocs/permissions/modules/inactive_approvals.php` | ACL: `view` (scope `all`), `review` (`default_admin`, sensitive). Endpoints mapped: only `approve`, `reject`, `reopen` → `review`. |
| `htdocs/includes/access.php:243` | `require_route_access()`: the router guard; every `page=inactive_approvals` request needs `inactive_approvals.view`. |
| `htdocs/views/inactive_approvals/index.php`, `user_index.php`, `show.php` | The "Vezi toate solicitarile (mele)" target pages. |
| `htdocs/views/dashboard/index.php:14, 428-700` | A second, older approval panel with the same `data-dashboard-approval-*` hooks, currently disabled (`$showDashboardApprovalPanel = false`). |
| `htdocs/views/reguli_taxe_refacturare/index.php`, `controllers/ReinvoiceFeeRulesController.php` | The rules that produce "Taxe de refacturat lipsa" (linked from the drawer if `can('reguli_taxe_refacturare')`). |

### 2.2 Client side

| File / function | Role |
|---|---|
| `assets/js/app.js:1303` `initDashboardApprovalTabs()` | Tab switching for **all** `[data-dashboard-approval-tab]` on the page (global selector), plus the close buttons of non-drawer panels. |
| `assets/js/app.js:1361` `initGlobalApprovalDrawer()` | Open/close, `aria-expanded`, chevron, `body.global-approval-drawer-open`, Escape, click-outside, `mark_seen` POST (user mode). |
| `assets/js/app.js:1473` `initOperatorActivity()` | Operatori tab: date nav, 30 s refresh (today only, only while visible), renders cards and race lists, keeps `<details>` open across refreshes, flashes changed totals. |
| `assets/js/app.js:1743` `initMissingFees()` | Loads `missing_fees` (**on every page load**, then every 60 s while visible). Renders missing fees (grouped by operator for admin) and purchase checks with a multi-step mini-form (Da → Ataseaza factura / Nu e cazul; Nu → reason). |
| `assets/js/app.js:2207-2250` `initFeePurchaseAlarm()` | Polls `fee_purchase_alarm` every 15 s on every page and toggles `.is-fee-alarm` (flashing red toggle). |
| `assets/js/app.js:2253-2300` | Counter helpers: `parseApprovalCount`, `setApprovalCount`, `setApprovalExtraCount` (adds fee counts onto the approval total), `decrementApprovalCounter`. |
| `assets/js/app.js:2302-2550` | `refreshApprovalEmptyState`, `removeCancelledApprovalFromUi`, `removeResolvedApprovalFromUi`, `submitApprovalReviewForm` (AJAX approve/reject), `initApprovalReviewActions` (delegated submit). |
| `assets/js/app.js:2552-2700, 2819` | User-mode cancellation (`initUserApprovalCancellation`), `syncApprovalCountersFromSummary`, `applyUserApprovalStatusToHost`. |
| `assets/js/app.js:2917-2924` | Init order. |
| `assets/js/approval-live.js` (673 lines) | Polls `approvals_live` every 5 s (30 s when hidden). Admin: Bootstrap modal queue with Aproba/Respinge, postpone, reject reason required. Everyone: decision toasts (z-index 1090) and the Dispecer "In asteptare aprobare" tab. It also rewrites `document.title`. |

### 2.3 CSS (`assets/css/style.css`)

| Lines | Content |
|---|---|
| 19640-19760 | Base `.dashboard-approval-panel` (sticky dashboard version), header, close, **tabs grid `repeat(3, …)`**, tab, card. |
| 19760-20030 | Card internals (operation box, details, scope, feedback, reason chips/tones, `dl` grid, actions, all-link). Shared with `inactive_approvals` pages via combined selectors. |
| 20939-20975 | `@media (max-width: 1199.98px)`: makes `.dashboard-approval-panel` static/`max-height:none` (also matches the drawer panel and is partly overridden later). |
| 20977-21010 | `@media (max-width: 575.98px)`: stacks actions, `dl` becomes 1 column. |
| 21093-21208 | Global drawer: CSS vars (`--fleet-approval-drawer-width: min(420px, 100vw - 54px)`), fixed panel **`top: 88px`**, toggle (fixed, `top: 50%`), badge, user-mode overrides, mobile media (width `100vw - 50px`, `top: 76px`). |
| 24918-24940 | `> * {flex-shrink:0}`, **sticky tabs `top:-18px`**, single-row tabs (`grid-auto-flow: column`), all-link hidden on Operatori via `:has()`. |
| 24940-25195 | Operator activity styles. |
| 25195-25430 | Missing fees styles. |
| 25434-25530 | Fee-purchase **sticky bottom bar** (`bottom:-18px; margin: auto -18px -18px`), panel `max-height: 55vh`. |
| 26352-26385 | `.is-fee-alarm` flashing animation (0.7 s infinite), reduced-motion fallback. |
| `assets/css/dashboard-grid.css:604-605` | Page-specific patch that hides the drawer toggle while the dashboard's own offcanvas is open. |

---

## 3. Current architecture and dependencies

```
header.php ──(PHP, every page)──► InactiveResourceApprovalModel::getPendingSummary(5) / getRequesterSummary(uid,5,true)
     │                                   └─ ensureSchema(): CREATE TABLE IF NOT EXISTS ×2 + column checks/migrations
     └─ include inactive_approval_drawer.php  (cards rendered once, never re-fetched)

footer.php ─► app.js
               ├─ initDashboardApprovalTabs  (global selectors!)
               ├─ initGlobalApprovalDrawer ──POST mark_seen (user mode)
               ├─ initOperatorActivity ─────GET operator_activity (30 s, visible only) ─► OperatorActivityModel::reconcile() [WRITES]
               ├─ initMissingFees ──────────GET missing_fees (page load + 60 s visible) ─► getMissingFees + getPurchaseChecks
               │                            POST dismiss_missing_fee / fee_not_bought / fee_no_invoice_needed / fee_attach_invoice
               ├─ initFeePurchaseAlarm ─────GET fee_purchase_alarm (15 s, always, all users)
               └─ initApprovalReviewActions POST approve / reject (AJAX) → summary JSON → syncApprovalCountersFromSummary
          ─► approval-live.js ─────────────GET dispecer_curse&action=approvals_live (5 s / 30 s)
                                            └─ applyApprovedRouteConfigRequests() [WRITES] + pending + awaiting trips + decisions
                                            POST approve / reject (modal, reason required for reject)
```

Shared state between subsystems happens only through the **DOM text of counters**: `setApprovalExtraCount()` adds a delta to `[data-approval-total-count]` and `[data-approval-total-badge]`, and the approval code decrements the same elements. `approval-live.js` shares no state with the drawer at all.

Coupling to other pages:
- `views/inactive_approvals/user_index.php` uses the same `data-approval-card` and `data-user-approval-tab-count` hooks. `removeCancelledApprovalFromUi()` and `removeResolvedApprovalFromUi()` operate on both the page and the drawer at once.
- `views/dashboard/index.php` uses the same `data-dashboard-approval-tab/panel` hooks (disabled today).
- The Dispecer curse page: `approval-live.js` renders its "In asteptare aprobare" tab. The cancel action posts to `dispecer_curse&action=cancel_inactive_vehicle_approval`. The fee links go to `dispecer_curse&action=edit&focus=refacturare`.
- `RaceCompletenessService` is shared with the Dispecer "curse cu informatii lipsa" popup.
- `dashboard-grid.css` contains a workaround for the toggle colliding with the dashboard offcanvas.

---

## 4. Full functionality inventory

### 4.1 Toggle button (right edge)
- **Purpose:** open/close the drawer and show the total badge. It flashes red when fee purchases are unconfirmed.
- **Files:** partial L193-204, `app.js:1361`, CSS 21129-21180, 26352.
- **Data:** the server total plus the client-side fee extra (missing fees + pending purchase checks).
- **Interaction:** click toggles; Escape and click-outside close. `aria-expanded` and `aria-label` update. The chevron flips.
- **Permissions:** shown to every logged-in user on non-embedded pages with a sidebar.
- **Shared:** hidden via a CSS patch when the dashboard offcanvas is open.

### 4.2 Header ("Solicitari aprobare in asteptare" / "Solicitarile mele de aprobare", "N total", close ×)
- In user mode the close button is absolutely positioned outside the header (partial L207-211).
- "N total" = approvals + fee extras ([TESTED]: "5 total" with all approval tabs at 0).

### 4.3 Approval category tabs (admin): Vehicule, Soferi, Reparatii, Diurne, Rute
- **Data:** `getPendingSummary(5)`: counts from `GROUP BY resource_type WHERE status='pending'`, with at most **5 rows per type** rendered.
- **Default tab:** the first tab with count > 0, otherwise "Vehicule" (never "Operatori", even when only fees exist).
- **Card content:** a reason chip with tone/icon (`$drawerTone`, `$drawerIcon`), relative time, `primary_label`, `problem_title`, optional operation box with a link (e.g. to the trip), summary rows (or fallback Documente/Detaliu/Inactiv din), "Vezi detalii" `<details>`, scope message, and the **Respinge / Aproba** forms.
- **Approve/Reject:** a POST form with CSRF, `return_url` and `decision_source=popup`. JS intercepts it (`submitApprovalReviewForm`) and sends it as AJAX. The server `review()` only changes rows that are still `pending` (409 if not). On approval it also runs `applyApprovedRouteConfigRequests()`. The response includes a fresh summary, then the card is removed and the counters are re-synced. **There is no confirmation dialog and no reason field. A default note "Aprobat/Respins din popup-ul de aprobari." is written.**
- **Without JS:** a normal POST plus a redirect to `return_url` (validated to `index.php?…`).
- **Permissions:** `inactive_approvals.review` (router endpoint map plus `requireReviewAccess()`).

### 4.4 User-mode tabs: In asteptare, Aprobate, Respinse, Taxe lipsa
- **Data:** `getRequesterSummary(uid, 5, true)`: pending items plus decisions **not yet seen**.
- When the user opens the drawer, the visible approved/rejected IDs are POSTed to `mark_seen`. They stay visible until the next page load, then they leave the drawer (still listed in "Vezi toate solicitarile mele").
- Each card has a status chip, a "Vezi detalii" link (`inactive_approvals&action=show`), and for pending items "Anuleaza solicitarea" (POST to `dispecer_curse&action=cancel_inactive_vehicle_approval`).
- "Taxe lipsa": the same missing-fees component, scope `own` (only trips created by this user), plus purchase checks for **all** trips.
- **[NOT TESTED]** in the browser (I only had an admin session).

### 4.5 Operatori tab (admin only)
- **Badge:** `(N)` = missing fees + pending purchase checks (not operators).
- **4.5.1 Taxe de refacturat lipsa (collapsed by default):** rules from `reguli_taxe_refacturare` (active, most specific wins) matched against trips started in the last 45 days, or created in the last 45 days (future dates included), that have no expense of that fee type and were not dismissed. Grouped by operator for admin. Actions: "Adauga refacturare" (link to the trip edit page with `focus=refacturare`) and "Nu se aplica" (POST `dismiss_missing_fee`; irreversible from the UI; non-admins can only dismiss their own trips).
- **4.5.2 Date toolbar:** ‹ date › with `max` = today and future dates blocked. A "Live · HH:MM:SS" status shows for today, "Actualizat …" for past days, and errors in red.
- **4.5.3 Totals:** Adaugate (trips created that day), Inchise (trips whose completion was first detected and timestamped that day), Inca deschise (trips created that day that are still incomplete). Changed values flash yellow.
- **4.5.4 Per-operator cards:** initials avatar, last activity time, 3 stats, and `<details>` lists "Curse inca deschise" / "Curse inchise", each linking to the trip edit page and showing "Lipsesc: …" (up to 4 labels + count). Open lists survive refreshes.
- **4.5.5 Note:** a definition of "Inchisa" plus the overall count of active trips with missing information.
- **4.5.6 Treceri refacturate – confirma achizitia (sticky bottom bar, opens upward):** expenses created since 2026-09-17 with fee type taxa_acces / port / trece / taxe_drum / toll and no document, excluding those marked `fara_factura`. Flow: **Da** → "Ataseaza factura" (upload, 5 MB, PDF/image/Word, becomes the rebilling document) or "Nu e cazul" (leaves the list). **Nu** → optional reason; the item stays, marked "Necumparata", and can be changed later ("Am cumparat-o" / "Schimba motivul"). While a mini-form is open, refreshes do not redraw the list.
- **Refresh:** operator activity every 30 s (today only); fees every 60 s, only while visible; both reload immediately when they become visible.

### 4.6 "Vezi toate solicitarile (mele)" link
- Goes to `inactive_approvals` (paginated search page for admin, `user_index` for others). It is hidden by a CSS `:has()` rule while the Operatori tab is visible.

### 4.7 Global fee alarm
- Every page, every user, every 15 s. When at least 1 purchase check is unanswered, the toggle turns into a red pulsing button (with a reduced-motion fallback).

### 4.8 Live approval popup (`approval-live.js`, separate from the drawer but the same domain)
- Admin: a Bootstrap modal queue for new pending requests from other users, with postpone (local storage), approve, and reject (reason **required**). "Solicitare decisa in alta parte" toast. Tab title "(N) Aprobare ceruta".
- Requester: decision toasts and a live "In asteptare aprobare" tab on Dispecer curse.

### 4.9 Endpoints and side effects

| Endpoint | Method | ACL in practice | Side effects |
|---|---|---|---|
| `approve` / `reject` | POST + CSRF | view + review | updates status; approve → applies route configs |
| `reopen` | POST + CSRF | view + review | (index/show pages only) |
| `mark_seen` | POST + CSRF | view | sets `requester_seen_at` |
| `live` (via dispecer_curse) | GET | dispecer_curse.view | **applies approved route configs (writes)** |
| `operator_activity` | GET | view + review | **`reconcile()` inserts/deletes `curse_inchidere_operator` rows** |
| `missing_fees` | GET | view | `ensureSchema()` (DDL; may seed rules on first creation) |
| `fee_purchase_alarm` | GET | view | none (heavy query every 15 s) |
| `dismiss_missing_fee` | POST + CSRF | view; own trips unless reviewer | inserts an ignore row |
| `fee_not_bought` / `fee_no_invoice_needed` | POST + CSRF | view; **any user, any trip** (by design) | upsert purchase mark |
| `fee_attach_invoice` | POST + CSRF + file | view; any user | stores the file, sets the expense document, clears the mark |

---

## 5. Existing business rules and data flow

1. **Status machine:** `pending → approved | rejected` only from `pending` (`review()` has `WHERE status='pending'`; a second decision returns 409). `reopen` sets `approved|rejected → pending` and wipes the reviewer (index/show pages only). The requester can cancel `pending`. A decision resets `requester_seen_at = NULL`, so the requester sees it once.
2. **Decision channels (6):** drawer form, live popup, index page, show page, email link (`aprobare_email`, public token route), and WhatsApp (`ApprovalWhatsAppService`). All of them end in `InactiveResourceApprovalModel::review()`. **The rules differ:** a reason is required only in the live popup.
3. **Route-config side effect:** approved "add permanently to route" requests are applied by `applyApprovedRouteConfigRequests()` (claim + apply + append note), triggered by approve and by every live poll, so email approvals propagate.
4. **Trip blocking:** trips with blocking approvals are hidden from the Desfășurător until decided (memory: aprobari-live-curse-asteptare). The drawer has no direct code for this, but its approve/reject buttons change trip visibility.
5. **Counters:**
   - Server total = sum of pending counts per type (admin) or pending + unseen decisions (user).
   - Client extra = missing fees + unanswered purchase checks, added to the same visible total and badge.
   - Tab counts come from the server, and `syncApprovalCountersFromSummary()` resets them after an AJAX decision.
   - The fee tab badge = missing + pending purchases.
6. **Open/closed trips:** "complete" = `RaceCompletenessService::missingInformation() === []` over `getActiveRacesForCompleteness()`. The closure moment is not stored in `curse_dispecer`. It is written into `curse_inchidere_operator` the first time a reconcile **run by an admin viewing the Operatori tab** finds the trip complete, using the last audit/expense event as the time and actor. If the trip becomes incomplete again, the row is deleted.
7. **Missing fees:** rule match (unloading zone required; loading place and transport type optional; most specific wins) on the 45-day window, minus existing fee expenses, minus dismissals.
8. **Purchase checks:** fee-type expenses since 2026-09-17 without a document, excluding `fara_factura`. Visible to every operator, because whoever is available attaches the invoice.
9. **Date filtering:** only the Operatori section. Approvals and fees are not date-filtered.

**Exclusive to this drawer:** operator activity (the only consumer of `OperatorActivityModel` and the only trigger of `reconcile()`), the missing-fees and purchase-check UI (the rules page manages only rules), the fee alarm, and `mark_seen`.
**Shared elsewhere:** approval records and decisions, card CSS, counter helpers, the completeness rules, route-config application, and the cancellation flow.

---

## 6. UI/UX assessment

### Works well (preserve)
- One persistent entry point on every page, with a badge.
- Approval cards show context (request type, entity, problem, operation link, details on demand, scope message). Tone and icon per reason.
- AJAX decisions with a busy state, inline success/error feedback, 409 handling ("nu mai este in asteptare") and a no-JS fallback.
- Operator activity: live indicator, change flash, open `<details>` preserved across refreshes, "Lipsesc: …" hints, direct links to trips.
- The purchase-check mini-flow avoids accidental loss (no redraw while a form is open) and keeps "not bought" items visible with who/when/why.
- Escape and click-outside close the drawer. A `prefers-reduced-motion` fallback exists for the alarm.
- Session-expired detection in the operator activity fetch.

### Usability problems
- **U1 Mixed concerns under one title and one number.** Approvals, monitoring and the finance queue all sit under "Solicitari aprobare in asteptare – N total". The admin cannot tell from the badge whether a decision is needed.
- **U2 The landing tab does not match the badge.** With only fee items pending, the drawer opens on an empty "Vehicule (0)" tab while the badge says 5.
- **U3 Tab labels are hard to read.** Each of the 6 tabs is 49–63 px wide; "Vehicule / (0)" etc. always wrap to 2 lines and some are clipped on phones ([TESTED]).
- **U4 Totals arithmetic confuses.** Adaugate 25 / Inchise 9 / Inca deschise 18 in the user's screenshot, and per operator 16/3/15: the three numbers measure different populations, and only a footnote explains it.
- **U5 Important financial items are hidden or stacked.** "Taxe de refacturat lipsa" is collapsed at the top, and "Treceri refacturate" is a separate collapsible sticky bar at the bottom. The two fee queues are split and both compete with the activity view.
- **U6 One-click irreversible actions.** Aproba/Respinge sit side by side with no confirmation or reason. "Nu se aplica" has no undo. Reopening is possible only from the index page.
- **U7 "Vezi toate solicitarile" disappears on the Operatori tab.**
- **U8 Large empty area on desktop.** At 1920×1080 the empty state is followed by a ~600 px blank gap before the bottom bar, because the tab panel is stretched to push the bar down.
- **U9 Language consistency.** The drawer strings have no diacritics ("Inchise", "Soferi", "Reparatii"), while much of the app uses them ("Șoferi", "Închise"). Mixed in the same view.

### Accessibility
- `role="tablist"` / `role="tab"` without arrow-key navigation or roving `tabindex` [CODE].
- When the drawer opens, focus is not moved into it and there is no focus trap. The panel has no `role="dialog"` / `aria-modal`, and focus is not returned to the toggle on close [CODE]. The closed panel uses `visibility:hidden`, so it is correctly not focusable.
- Counter changes are not announced (the `aria-live` regions exist only on lists).
- The flashing alarm (0.7 s cycle, strong glow) runs on every page. That is acceptable under WCAG 2.3.1, but it is distracting and color-only. The toggle's accessible name does not mention the alarm.
- The toggle has no visible text and sits at the vertical middle of the viewport, over page content.

### Feedback, loading, empty and error states
- Loading: the totals show "-" and the status shows "Se incarca..."; the lists show nothing (no skeleton).
- Empty states exist for all lists. Errors are shown for activity and fee lists. A **fee-alarm fetch failure is silent**.
- After the live popup decides, the drawer is not refreshed (stale cards until reload; see D5).

---

## 7. Responsive behavior assessment

**[TESTED]** in the built-in browser (Chromium, DPR 1), admin, dashboard page, Operatori tab, drawer open. The 1440 px width was not measured separately: the layout is width-invariant above 576 px (fixed 420 px panel), and the 1366 and 1920 results bracket it.

| Viewport | Panel (x, y, w, h) | Topbar bottom | Tab width | Tab label lines | Clipped tabs | Horizontal overflow | Visible scroll area between sticky bars* |
|---|---|---|---|---|---|---|---|
| 1920×1080 | 1488, **88**, 420, 974 | **104** | 63 | 2,2,2,2,1,2 | none | none | ~790 px |
| 1366×768 | 919, 88, 420, 662 | 104 | 63 | 2,2,2,2,1,2 | none | none | 479 px |
| 1024×768 | 577, 88, 420, 662 | 104 | 63 | 2,2,2,2,1,2 | none | none | 479 px |
| 768×1024 | 336, 88, 420, 918 | 104 | 63 | 2,2,2,2,1,2 | none | none | 735 px |
| 430×932 | 44, **76**, 380, 846 | 104 | 58 | 2,2,2,2,1,2 | **Operatori** | **panel 382>378; fee bar −3 px left** | 657 px |
| 375×812 (mobile emulation) | 44, 76, 325, 726 | 104 | 49 | 2 on all | **Vehicule, Reparatii, Operatori** | **panel 327>323; tabs 306>293; fee bar −3 px** | 516 px; **~110 px with both fee sections open** |
| 911×512 (≈ 1366×768 at 150 % zoom) | 464, 88, 420, 406 | 104 | 63 | 2 | none | vertical scrollbar appears | **209 px** |

\* Distance between the bottom of the sticky tab bar and the top of the sticky fee-purchase bar.

Additional observations [TESTED]:
- At ≤ 1024 px the 420 px panel covers 41–55 % of the page, and the toggle sits in the middle of the page content.
- At 375 px the title wraps to 2 lines and the fee-purchase toggle text wraps to 2 lines ("Treceri refacturate - / confirma achizitia").
- The drawer's z-index (1065/1066) is above the topbar (1030) and above Bootstrap modals (1055) and their backdrop (1050). It is below the sidebar (1090) and the live toasts (1090).

---

## 8. Confirmed defects and root causes

| ID | Severity | Defect | Evidence | Root cause / location | Consequence |
|---|---|---|---|---|---|
| **D1** | High | The panel overlaps the topbar (16 px on desktop, 28 px on mobile) | [TESTED] panel y=88 or 76 vs topbar bottom 104 | `style.css:21106` `top: 88px` and mobile `top: 76px` are hard-coded; they ignore `--app-topbar-height` (computed 104 px). z-index 1065 > topbar 1030. | Covers part of the topbar (Christmas strip, profile pill area on mobile); looks broken; it gets worse if the topbar height changes. |
| **D2** | High | Tabs are clipped and wrap on narrow widths | [TESTED] 375 px: 3 labels clipped (scrollWidth 54/58/61 > 48); 430 px: "Operatori" shows as "Operator" | `style.css:24922-24930` forces all tabs into one row (`grid-auto-flow: column; grid-auto-columns: minmax(0,1fr)`) inside `overflow:hidden` (19695). No scroll, wrap or abbreviation. | Labels and counts are unreadable; the user cannot see that Operatori holds the pending items. |
| **D3** | High | Sticky bottom bar takes most of the panel | [TESTED] fee-purchase expanded: 486 px of a 726 px panel at 375 px (~110 px left); 209 px usable at 150 % zoom even when collapsed | `.fee-purchase { position: sticky; bottom:-18px }` (25440) plus `.fee-purchase-panel { max-height:55vh }` (25496) plus sticky tabs (24922). Nested scrolling (the panel and the purchase list both scroll). | Content between the bars is practically unusable on phones and zoomed laptops; scroll-inside-scroll. |
| **D4** | Medium | Horizontal overflow on mobile | [TESTED] panel scrollWidth 382/378 and 327/323; fee bar starts 3 px left of the panel | Mobile media (21186) sets `.fee-purchase { margin: auto -14px -14px }` while the panel padding is 14 px and the sticky offset stays at the desktop value `bottom:-18px`; tabs overflow by 13 px. The `-18px` sticky offsets assume 18 px padding. | Sideways wobble and a 4 px horizontal scroll inside the drawer. |
| **D5** | High (functional) | Drawer approval cards and counters are not live | [CODE] the cards come only from `header.php:45-50`; `approval-live.js` never calls `removeResolvedApprovalFromUi` / `syncApprovalCountersFromSummary`, and nothing inserts new cards | Two independent subsystems with no shared state. | After deciding in the live popup (or by email, WhatsApp or another admin), stale cards stay. Clicking them gives 409 "nu mai este in asteptare". New requests do not appear in the drawer until a reload. |
| **D6** | Medium | Misleading totals | [TESTED] "5 total" under "Solicitari aprobare in asteptare" with all approval tabs at 0 | `setApprovalExtraCount()` (`app.js:2280`) adds fee counts into `[data-approval-total-count]` and the badge. | Admins treat finance items as approvals and vice versa; the badge does not tell them what to act on. |
| **D7** | Medium | Inconsistent reject rule | [CODE] drawer form: no `review_note` field, server default "Respins din popup-ul de aprobari."; live popup: reason required (`approval-live.js:353`) | Business rule enforced only client-side in one channel. | The requester receives rejections with no reason when they come from the drawer. |
| **D8** | Medium | Operator totals do not reconcile | [TESTED in the user's screenshot] Ana: 16 added / 3 closed / 15 still open | `OperatorActivityModel::getDailyActivity()`: `closed` = trips closed that day (any creation date); `still_open` = only trips created that day. | Users read "added − closed = open" and see wrong numbers. |
| **D9** | Medium | Closures are recorded only when an admin watches | [CODE] `reconcile()` is called only from `operator_activity` (controller L298) | The closure time is derived lazily from the last audit/expense event at detection time. | If no admin opens the tab, a later edit after completion moves `inchisa_la` and the actor. A GET endpoint writes to the DB every 30 s per open admin drawer. |
| **D10** | Medium | Default tab ignores fee items | [CODE] partial L156-162 | Active-tab selection loops only over approval tabs. | The drawer opens on an empty tab while the badge shows N. |
| **D11** | Low | `body.global-approval-drawer-open` is set but unused | [CODE] `app.js:1376`, no CSS uses it | Missing scroll lock / overlay. | On mobile the page behind scrolls and stays interactive; there is no backdrop. |
| **D12** | Low | Global tab selectors | [CODE] `initDashboardApprovalTabs()` uses `document.querySelectorAll('[data-dashboard-approval-tab]')` | No scoping per container. | If the dashboard panel is ever re-enabled, the drawer tabs and the dashboard tabs toggle each other (they share the keys vehicle/driver/repair). |

---

## 9. Potential issues requiring verification

| ID | Severity | Issue | Basis | How to verify |
|---|---|---|---|---|
| P1 | Medium | Request bursts on visibility changes | [TESTED in pane]: ~1 request/s each to `missing_fees`, `operator_activity`, `approvals_live` over 62 s. The pane flipped `visibilityState` ~1/s, a **test-environment artifact**. But the code reloads on every hidden→visible transition with no throttle (`app.js` operator activity / missing-fees visibility handlers; `approval-live.js:667` `schedulePoll(200)`). | Switch tabs or windows rapidly in Chrome and Edge and watch the network panel; check behavior on the VPS (`php -S`, single-threaded per the comments). |
| P2 | Medium | Per-page server cost | [CODE] every page render runs `ensureSchema()` (2× `CREATE TABLE IF NOT EXISTS` + column checks) and 6 queries for the summary. `initMissingFees` calls `missing_fees` on every page load for every user (45-day rule match), even with the drawer closed. The alarm polls every 15 s on every tab. Measured: `missing_fees` avg 392 ms (max 1054), `fee_purchase_alarm` 363 ms, `operator_activity` 280 ms (max 1023) [TESTED, local]. | Profile on the VPS with several operators online. |
| P3 | Medium | Users without `inactive_approvals.view` | [CODE] the router guard requires `view` for every `page=inactive_approvals` action, while the drawer is rendered for all users. If an admin removes `view` from a custom user, `missing_fees`, `mark_seen`, the alarm and the fee actions all return 403. The fee tab shows "Nu ai dreptul…" and the alarm silently stops. | Test with a customized operator in Drepturi de acces. |
| P4 | Medium | Drawer above modals | [TESTED z-index values] drawer 1065/1066 > Bootstrap modal 1055 / backdrop 1050 | Open any Bootstrap modal (including the live review modal) while the drawer is open, or check whether the toggle stays clickable above a modal backdrop. |
| P5 | Low | 5-rows-per-type limit | [CODE] `getPendingSummary(5)` | With more than 5 pending of one type, approving one does not pull the 6th into the drawer, and the tab count is larger than the visible cards with no hint. |
| P6 | Low | Counter drift | [CODE] the relative delta (`setApprovalExtraCount`) combined with `Math.max(0, …)` clamping in `setApprovalCount` | A clamp at 0 followed by a positive delta could desync the total; edge case. |
| P7 | Low | Hard-coded 1199 px media rule hits the drawer | [CODE] `style.css:20939` restyles `.dashboard-approval-panel` (the drawer has this class); later rules override most of it, but `border-radius` and `background` precedence is fragile | Inspect computed styles at 1100 px. |
| P8 | Low | Sticky tab offset on mobile | [CODE] `top:-18px` assumes 18 px panel padding; mobile padding is 14 px, so 4 px of the tab bar can hide under the panel edge | Visual check while scrolling at 375 px. |
| P9 | — | User (operator) mode | [NOT TESTED] layout of status chips, cancel button and close button outside the header | Log in as an operator and repeat the measurements. |
| P10 | Low | Toggle covers right-edge controls | [TESTED] fixed at `top:50%` on the right edge; the existing patch only covers the dashboard offcanvas | Check pages with right-aligned table scrollbars and actions (Dispecer, Carburanti). |

---

## 10. Functional and technical regression risks

| Risk | What could break | Why it is fragile |
|---|---|---|
| R1 | Approve/reject AJAX | Delegated `submit` handler keyed on `data-approval-review-form`, `data-approval-decision`, and the hidden `id`, `return_url`, `decision_source` inputs; the response shape `{success, approval_id, summary}`; 409 handling. |
| R2 | Counters | Depend on the exact attributes `data-approval-total-count`, `data-approval-total-badge` (with `hidden` handling), `data-approval-tab-count="<key>"`, `data-missing-fees-count`, and on text content being a bare integer. |
| R3 | Requester "seen" | `data-approval-seen-url` and `-csrf` on the root, `data-approval-status` on cards, and the first open triggering `mark_seen`. Removing it makes decisions reappear forever. |
| R4 | Cancellation | `data-user-approval-cancel`, `data-cancel-url`, `data-csrf-token`; shared with `user_index.php`. |
| R5 | Operator activity | `data-operator-activity(-url, -date, -list, -status, -status-text, -total, -overall, -shift)`; the MutationObservers on the panel's `hidden` attribute and the drawer's `class`. Changing to CSS-only visibility stops refresh. **Removing the tab stops `reconcile()`, and with it all closure recording.** |
| R6 | Missing fees / purchases | `data-missing-fees*`, `data-fee-purchase*` URLs on the section; `purchaseRoot = section.closest('[data-dashboard-approval-panel]')` (the bar must stay in the same tab panel); `purchaseForms` state. |
| R7 | Fee alarm | `data-fee-alarm-url` on the root, `.is-fee-alarm` on the toggle; `setFeePurchaseAlarm` is also fed by `renderPurchases`. |
| R8 | Visibility logic | `isVisible()` relies on `drawer.classList.contains('is-open')` and `panel.hidden`. |
| R9 | Route config | Approve must keep calling `applyApprovedRouteConfigRequests()`, and the live poll keeps doing it too. |
| R10 | Shared CSS | `.dashboard-approval-*` classes are shared with `inactive_approvals/index.php`, `show.php`, `user_index.php` and the dashboard; changing them restyles those pages. |
| R11 | Global JS | `initDashboardApprovalTabs` and the counter helpers are global; `removeResolvedApprovalFromUi` affects every matching card in the document. |
| R12 | Layout include | The drawer is rendered only with `$showSidebar` and not in embed mode; `dashboard-grid.css:604` hides the toggle by class name. |
| R13 | Progressive enhancement | The forms work without JS (POST + redirect via `return_url` validation in `redirectAfterAction`). |

---

## 11. Components and behavior that must be preserved

1. All approval categories, including those at 0: **Vehicule, Soferi, Reparatii, Diurne, Rute** (admin), and **In asteptare, Aprobate, Respinse, Taxe lipsa** (requester).
2. Approve and reject with CSRF, the `pending`-only transition, 409 "already decided" handling, the route-config application on approve, and the default audit note (or an explicit reason).
3. The requester flow: unseen decisions shown once, then `mark_seen`; cancellation of pending requests; "Vezi detalii" links.
4. Operator activity: per-day view, date navigation (no future dates), live refresh only for today and only while visible, per-operator stats, open/closed race lists with missing-field labels and links, the overall incomplete count, and the side effect of recording closures.
5. Missing fees: rule-based detection, own vs. all scope, grouping by operator, "Adauga refacturare" deep link (`focus=refacturare`), "Nu se aplica", link to the rules page when permitted.
6. Purchase confirmation: the Da/Nu flow, invoice upload (5 MB, type checks), "Nu e cazul", "Necumparata" with reason/author/time, and visibility for all users.
7. The global alarm when purchases are unanswered (including reduced motion).
8. Escape and click-outside closing, `aria-expanded`, the no-JS fallback, and the session-expired message.
9. The ACL semantics (`view` vs. `review`) and the `approvals_live` proxy for operators.

---

## 12. Questions and architectural decisions for the future redesign

1. **Separation of concerns.** Should approvals, operator monitoring and the rebilling-fee queue remain in one drawer? Options include separate tabs with separate badges, separate entry points (e.g. a bell for approvals, a finance queue elsewhere), or moving Operator activity to its own page or dashboard widget.
2. **Counter semantics.** What should the toggle badge and "N total" mean: decisions required from me, everything actionable, or split badges? Should fees count at all for admins?
3. **Single source of truth for live data.** Should the drawer consume the same `approvals_live` payload (cards plus counts), so the popup and the drawer can never diverge? Should there be one poller instead of four?
4. **Decision rules.** Should a reject reason be mandatory in every channel (drawer, index, show, email, WhatsApp)? Should approve need a confirmation step, or an undo window?
5. **Operator activity definitions.** Should "Inchise" mean closed among trips added that day (so the arithmetic adds up), or stay "closed that day", with clearer labels? Should closure recording move off the admin-only GET path (e.g. on trip save or in a cron job)?
6. **Container model.** A fixed overlay vs. a full-height offcanvas vs. a full-screen sheet on phones. How should it relate to the topbar height variable, Bootstrap modals (z-index order) and other right-side offcanvases?
7. **Tab navigation at small widths.** Scrollable tabs, a select/segmented control, icons with counts, or grouping (Approvals ▸ type filter).
8. **Sticky regions.** Is the bottom "Treceri refacturate" bar needed as sticky? Should there be at most one sticky region and no nested scroll?
9. **Performance budget.** Should `missing_fees` load lazily (only when the drawer or tab is opened), with the alarm and counters served from a cheap combined endpoint? Should `ensureSchema()` DDL move out of request paths? Should visibility-triggered reloads be throttled?
10. **Permissions.** Should drawer endpoints keep requiring `inactive_approvals.view`, or move to an always-allowed module/proxy like `approvals_live`? Should "any user may confirm any purchase" stay?
11. **Language.** Should the drawer get diacritics, consistent with the rest of the UI?
12. **Scope of the 5-row limit.** Paginate in the drawer, or always hand off to "Vezi toate"?

---

### Audit hygiene
- **No application code, CSS, JS, PHP, schema or configuration was modified. No commits were made.** The only file created is this report.
- In the browser I only opened and closed the drawer, switched tabs, and expanded and collapsed the two fee sections. I did not click any approve/reject/fee action button or upload anything.
- Note: simply viewing pages as an admin makes the application run its own built-in writes. Opening the Operatori tab runs `OperatorActivityModel::reconcile()`, which records trip closures in `curse_inchidere_operator`. Every page's live poll runs `applyApprovedRouteConfigRequests()`, which applies only already-approved route requests. These ran during testing exactly as they do for any admin using the app; I issued no direct database commands.
- The test session used the local server at `localhost:8000` with the user's own admin login. The viewport emulation was reset to the default afterwards.
