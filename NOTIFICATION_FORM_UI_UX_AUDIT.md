# Fleet App — Notification Rule Form: UI/UX Audit

**Scope:** `?page=notificari&action=create` / `action=edit&id=…` (modal "Regulă nouă de notificare" / "Editează regula de notificare").
**Date:** 2026-10-14. **Codebase state:** commit `dd5070d` (the multi-event form introduced on 2026-10-14 is included).
**Nature:** audit only — no source, CSS, JS, schema or behavior was changed. The only artifact produced is this report.

> **Note on the screenshot:** the screenshot referenced in the brief was not attached. All layout findings below come from measuring the live form (`http://aplicatie_fleet.test/htdocs/index.php?page=notificari&action=create`) in the browser at 1920×1080, 1673×915, 1366×768, 1024×768 and 375×812, in both the empty state and a fully selected state. Pixel values quoted are measured `getBoundingClientRect()` values.

---

## Contents

1. [Implementation file map](#1-implementation-file-map)
2. [Full form inventory](#2-full-form-inventory)
3. [Business-logic documentation](#3-business-logic-documentation)
4. [Conditional-behavior matrix](#4-conditional-behavior-matrix)
5. [Layout root-cause analysis](#5-layout-root-cause-analysis)
6. [Backend / database dependencies (contracts)](#6-backend--database-dependencies-contracts)
7. [UI/UX redesign recommendations](#7-uiux-redesign-recommendations)
8. [Regression risks](#8-regression-risks)
9. [Acceptance criteria for a UI-only refactor](#9-acceptance-criteria-for-a-ui-only-refactor)
10. [Unresolved questions](#10-unresolved-questions)

---

## 1. Implementation file map

| Layer | File | Responsibility |
|---|---|---|
| Routing / ACL | `htdocs/permissions/modules/notificari.php` | Module registry entry. `admin_only => true`; endpoints `create/store/edit/update/delete → manage_rules`, `toggle → toggle`, `send_test/run_test/send_test_sms → send_test`. |
| Controller | `htdocs/controllers/NotificationRuleController.php` | All actions (`handle()` L142). `require_admin_or_403()` on every action. Label/option constants (L10–134), `EVENT_CONDITIONS` (L80), `EVENT_HINTS` (L97), hidden events/recipient modes (L115–116), `MAX_RULES_PER_GROUP = 60` (L118). `indexAction` (L182) renders list + form; `storeAction` (L286); `updateAction` (L308); `validateRuleInput` (L457) — validation **and** expansion of one form submit into N rule rows; `suggestRuleName` (L648); `defaultFormData` (L676); `mapRulesToFormData` (L714) — group → form state for edit. |
| Model | `htdocs/models/NotificationRuleModel.php` | Persistence. `getRules` (L13, list + `group_size`), `getRuleById` (L107), `getRuleGroupFor` (L130), `syncRuleGroup` (L163, transactional create/update/delete of a group), `ruleKey` (event\|document_type), `createRule` (L220), `updateRule` (L269), `setRuleEnabled` (L297), `deleteRule` (L310), recipient/doc-type lookups (L317–365), `replaceRuleRecipients` (L1176), `bindRuleData` (L1198), self-healing DDL `ensureRuleSchemaExtensions` (L1221; adds `rule_group` at L1248) and `ensureSmsDeliverySchema`. Also contains **unused legacy** private matching/email code (`getEnabledRules`, `fetchMatchesForRule`, `resolveRecipients`, `findExistingQueue`, `buildNotificationEmail` …, ~L829–1150) — not called anywhere. |
| View (page + modal markup) | `htdocs/views/notificari/index.php` | Page (KPIs, tabs, rules table with "Grup de N reguli" badge L359–361). Modal rendered server-side only when `$showForm` (L593–956): PHP prep block, `<link>` to CSS (L672), `<aside class="nrf-modal">` (L674), header (L675), JSON config `<script data-nrf-config>` (L694), name (L702), sections 1–4 (L708, L735, L848, L919), summary aside (L938), footer (L949), `<script src>` (L955). |
| Styles (modal) | `htdocs/assets/css/notificari-regula.css` | All `.nrf-*` styling: backdrop (L3), modal (L11), header (L27), form (L64), body grid (L71), main (L79), entities (L178/184), conditions (L292–420), channel (L423), recipients (L470/476), switches (L500), users list (L592), options (L680), summary (L688/696), footer (L788), breakpoints (L802 ≤991.98px, L819 ≤575.98px). |
| Styles (global, still loaded) | `htdocs/assets/css/style.css` | Legacy `.notification-rule-drawer/-grid/...` rules (L1054–1239) — **dead for the modal** now, still present. App chrome z-indexes (`.sidebar` 1090, `.fleet-sidebar-edge` 1080, `.fleet-approval-drawer-toggle` 1066). Bootstrap 5.3.3 loaded in `views/layout/header.php` L58. |
| Frontend behavior | `htdocs/assets/js/notificari-regula.js` | Reads JSON config; `buildItems` (L44, mirrors server expansion), `suggestName` (L58), `syncEntities` (L78), `syncConditions` (L89), `syncRecipients` (L137), `warningsFor` (L176), `syncSummary` (L224), `update` (L282); delegated `change` (L290), `input` (L300), `click` (L311), `keydown` (L335) listeners; `addDocType` (L342); `submit` guard (L363); `close` (L374) bound to backdrop + global `Escape` (L379–380). |
| Global JS interacting with the form | `htdocs/assets/js/app.js` L227–274 | Document-level `submit` listener: anti-double-submit guard (`data-submitting="1"` + disables submit buttons) for every POST form. Interacts badly with the form's own submit guard (see §5.11). |
| Layout | `htdocs/views/layout/header.php` | `<main class="p-4">` (L354) inside `.app-shell > .app-content`. No transformed ancestors → `position: fixed` on the modal is viewport-relative (verified). |
| Worker — rule engine | `notification_service/worker.py` | Reads `notification_rules WHERE enabled = 1` (L303), dispatches by `event_type` (L318–343), resolves recipients (L834), driver SMS recipients (L872), builds `context_id` (L901) and dedupe key (L916), checks existing deliveries (L923), enqueues (L1269–1333), sends email/SMS from `notification_queue`. Revizii via `scripts/revizii_alerts.php` (L451–476). |
| Worker — approvals (email) | `notification_service/approval_flow.py` | Reads enabled `inactive_approval_pending` rules (L236–279), falls back to a built-in default rule when **none** exist (L282–297, L997–1003), sends approval emails per `should_send` (L378–390), recipients (L409+). |
| Revizii bridge | `scripts/revizii_alerts.php` (+ `ReviziiService`) | Computes km-remaining alerts (status `se_apropie` / `necesita`, `baseline_at`, thresholds per component). |
| Schema reference | `database/rebuild_notifications_from_zero.sql`, `database/migrations/2026_10_14_000001_notificari_sms.sql` | Tables, FKs (`notification_rule_recipients` → rules/users `ON DELETE CASCADE`). `rule_group` column is added at runtime by the model (no migration file). |

---

## 2. Full form inventory

The modal is a **server-rendered page state**, not a client-side dialog: opening = navigating to `action=create|edit`, closing = navigating back to `?page=notificari`.

### 2.0 Shell

| Element | Create mode | Edit mode |
|---|---|---|
| Title | "Regulă nouă de notificare" | "Editează regula de notificare" |
| Subtitle | "Bifează unul sau mai multe evenimente; se creează câte o regulă pentru fiecare." | If group size > 1: "Grup de N reguli salvate împreună — modificările se aplică tuturor." else same as create. |
| Form action | `POST index.php?page=notificari&action=store` | `POST …&action=update` + hidden `id` (the clicked rule's id) |
| Hidden | `_csrf_token` (via `csrf_field()`) | `_csrf_token`, `id` |
| Top alert | Shown when any server error exists: "Verifică câmpurile marcate mai jos." | same |
| Close | ✕ link, backdrop click, `Escape` key → navigate to `?page=notificari` (no unsaved-changes prompt) | same |

### 2.1 Name

| Field | Control | Default | Required | Notes |
|---|---|---|---|---|
| `name` | text input, `maxlength=190` | `''` (edit: rule name, or `metadata.group_name` for groups) | **No** (server generates one if empty) | Placeholder is live-updated by JS with the suggested name (e.g. "Vehicul: Expirare document ITP, RCA + Sofer: Expirare document"). Error key `name` (>190 chars). |

### 2.2 Section 1 — "Ce urmărești?" (events)

`event_types[]` checkboxes grouped in one card per entity (7 cards). Each option shows label + hint.

| Entity card | Event value | Label | Hint |
|---|---|---|---|
| Vehicul | `vehicle_document_expiry` | Expirare document | RCA, ITP, rovinietă, CASCO… |
| Vehicul (hidden) | `document_missing` | Document lipsa | shown **only** if an edited rule already uses it |
| Sofer | `driver_document_expiry` | Expirare document | Permis, atestat, card tahograf… |
| Sofer | `driver_birthday` | Zi de nastere | |
| Anvelopa | `tire_km_limit` / `tire_tread_depth` / `tire_dot_expiry` | Limita km / Adancime profil / DOT expira | |
| Concediu | `leave_starts_soon` | Concediu incepe in curand | |
| Dotare / Inventar | `equipment_expiry` / `equipment_inspection` | Expirare dotare / Inspectie dotare | |
| Solicitare aprobare | `inactive_approval_pending` | Cerere aprobare in asteptare | |
| Revizie (km) | `revizie_schimb_ulei` / `revizie_placute_frana` / `revizie_anvelope` | Schimb ulei / Plăcuțe frână / Anvelope (revizie km) | |

- Default (create): none checked. Edit: union of the group's `event_type`s.
- Required: ≥ 1 (server error `event_types`: "Bifeaza cel putin un eveniment."; client blocks submit when 0 — see bug §5.11).
- Card header shows "N bifat(e)" counter; card gets `.is-active` border when ≥1 checked.
- Max expansion: 60 resulting rules (error `event_types`).

### 2.3 Section 2 — "Când se trimite?" (conditions)

Every block below is rendered always and toggled with the `hidden` attribute; **hidden blocks are not disabled and are still submitted** (server ignores irrelevant ones).

| Block (`data-nrf-cond`) | Fields | Default | Visible when | Required / validation |
|---|---|---|---|---|
| empty state | text | — | no condition block visible. Text: "Bifează un eveniment…" (0 events) or "Evenimentele alese nu au condiții suplimentare." | — |
| `days` | `days_before` number (0–365) + preset chips 7/15/30/60/90; helper "Se aplică pentru: …" when >1 event uses it | 30 (edit: clicked rule's value, else first member using days) | any selected event with `conditions.days` (vehicle/driver docs, birthday, leave, DOT, equipment ×2) | server: ≤365 (`days_before`). No client min/max enforcement beyond the input attrs. |
| `docs-vehicle` | radio `doc_all[vehicle]` = 1 "Toate tipurile" / 0 "Doar anumite"; chips `doc_types[vehicle][]` from `DISTINCT documente.tip_document`; "Alt tip…" input + Adaugă (Enter works) | "Toate" (edit: "Doar anumite" + checked types if any member has a document_type) | `vehicle_document_expiry` or `document_missing` selected | if "Doar anumite": ≥1 type (`doc_types_vehicle`), each ≤120 chars |
| `docs-driver` | same with `doc_types[driver][]` from `documente_soferi` | "Toate" | `driver_document_expiry` | same (`doc_types_driver`) |
| `docs-equipment` | same with `doc_types[equipment][]` from `inventar_dotari_catalog.nume` | "Toate" | `equipment_expiry` or `equipment_inspection` | same (`doc_types_equipment`) |
| `km` | `threshold_km` (km) | empty | `tire_km_limit` | required > 0 (`threshold_km`) |
| `tread` | `threshold_tread_depth` (mm, step 0.1, comma accepted server-side) | empty | `tire_tread_depth` | required > 0 (`threshold_tread_depth`) |
| `revizie` | radio `revizie_status`: `''` "Se apropie sau Necesită (prag din Revizii)" / `necesita` "Doar Necesită" + link to Revizii | `''` | any `revizie_*` event | none (invalid value → `''`) |
| `approval` | chips `approval_resource_types[]` (vehicle/driver/repair/diurna), chips `approval_reasons[]` (from `InactiveResourceApprovalModel::getReasonOptions()`, only if any), `approval_repeat_days` (1–30) | none / none / 1 | `inactive_approval_pending` | clamped 1–30 server-side; empty lists = "all" |

### 2.4 Section 3 — "Cine primește și cum?"

| Field | Control | Options | Default | Visible / behavior |
|---|---|---|---|---|
| `channel` | radio "segmented" buttons | `email` Email, `sms` SMS, `email_sms` Email + SMS | `email` | always; auto-set to `sms` by JS when recipient = `drivers` is chosen |
| `notify_driver` | switch "Trimite SMS și șoferului" | 0/1 | 0 | visible only if channel ∈ {sms, email_sms} **and** not (all selected events = approval) **and** recipient ≠ `drivers` |
| `recipient_mode` | radio cards | `admins` Administratori, `drivers` Doar soferul (SMS), `specific_users` Utilizatori selectati; `vehicle_responsible` hidden unless already used | `admins` | always |
| `recipient_user_ids[]` | checkbox list (avatar, name, admin badge, email, phone) with search, "Toți"/"Niciunul" (act on visible rows), "N selectați" counter | active users with email **or** phone, admins first | none | visible only when `specific_users`; missing email/phone highlighted red depending on channel |

Server validation: unknown channel (`channel`); unknown mode (`recipient_mode`); `specific_users` with none (`recipient_user_ids`); `drivers` + approval event selected (`recipient_mode`); `drivers` with channel ≠ `sms` exactly (`channel`; note `email_sms` is also rejected).

### 2.5 Section 4 — "Opțiuni"

| Field | Default (create) | Edit value | Meaning (see §3) |
|---|---|---|---|
| `enabled` "Activă" | checked | checked if **any** group member is enabled | rule is processed by workers |
| `repeat_until_resolved` "Repetă până la rezolvare" | checked | from clicked rule | daily (or every N days for approvals) re-send while condition persists |
| `daily_limit_enabled` "Maxim o notificare pe zi" | checked | from clicked rule | **stored but not used by any worker** (§3.10) |

### 2.6 Summary panel (right column, `aside.nrf-summary`)

Intentional, always rendered, populated entirely by JS:
- Sentence: "Trimite {canal} către {destinatari} pentru N evenimente, cu X zile înainte."
- Count line: "Se salvează N reguli:" or (edit, size changes) "Grupul va avea N reguli (acum M). Regulile debifate se șterg."
- Preview list of resulting rules (entity + event · doc type), max 12 + "+ încă K".
- Non-blocking warnings: >60 rules, drivers without SMS, drivers + approvals, no selected users, users without phone (SMS) / email (email), "Doar anumite" with nothing checked, missing tyre thresholds.
- Submit button label mirrors count: "Salvează N reguli".

### 2.7 Error behavior

- Server-side validation only (form has `novalidate`). On error: redirect back to `create` / `edit&id=` with `set_form_flash(old, errors)`; the form re-renders from `old` (all selections preserved), inline `.nrf-error` messages under each block, top alert, and JS scrolls the first error into view.
- Client: only zero-events blocks submit (shake animation on summary sentence). All other client checks are advisory warnings in the summary.

---

## 3. Business-logic documentation

### 3.1 Rule model and grouping
- One DB row = one `event_type` (+ optional `document_type`). The worker only understands this shape.
- The form expands the selection: **each event × each selected document type** (or one row with empty type when "Toate" / not applicable). Revizie rows carry the status filter in `document_type`. Approval rows carry their filters in `metadata_json`.
- More than one resulting row → all rows share a random `rule_group` (16 hex) and `metadata_json.group_name`; row names become `"{base} — {Entity}: {Event}[ · {doc}]"`.
- Fields shared by all rows of a submit: channel, recipient mode + users, notify_driver, enabled, repeat, daily limit, days_before (only on rows whose event uses days; others get 0), thresholds (only on the matching tyre event; others NULL).

### 3.2 Rule types and trigger conditions (worker.py)

| Event | Source | Fires when | Uses |
|---|---|---|---|
| `vehicle_document_expiry` | `documente` ⋈ active `vehicule` | `data_expirare <= today + days_before` (**no lower bound → already-expired docs keep matching**) | `days_before`, `document_type` = `tip_document` |
| `driver_document_expiry` | `documente_soferi` ⋈ active `soferi` | same | same |
| `driver_birthday` | active `soferi.data_nasterii` | next birthday within 0…days_before | `days_before` (default 7) |
| `leave_starts_soon` | `concedii` (status aprobat / in_asteptare*) | `data_inceput` between today and today+days_before | `days_before` (default 7) |
| `equipment_expiry` / `equipment_inspection` | `inventar_dotari_vehicule` ⋈ catalog ⋈ active vehicle | date column ≤ today+days_before | `days_before`, `document_type` = catalog `nume` |
| `tire_km_limit` | mounted tyres with `estimated_life_km` | km remaining ≤ `threshold_km` (rule ignored if threshold ≤ 0) | `threshold_km` |
| `tire_tread_depth` | mounted tyres | `tread_depth_mm <= COALESCE(NULLIF(threshold,0), tire.min_tread_depth_mm)` | `threshold_tread_depth` |
| `tire_dot_expiry` | mounted tyres with parsable DOT | DOT date + 5 years ≤ today+days_before | `days_before` (default 90) |
| `revizie_*` | `scripts/revizii_alerts.php` | alert in category; `document_type='necesita'` restricts to status `necesita`; empty = `se_apropie` + `necesita` | thresholds come from Revizii (per component), not from the rule |
| `inactive_approval_pending` | `inactive_resource_approvals` status pending | filtered by resource types / reasons in metadata (empty = all) | SMS by worker.py, **email by approval_flow.py** |
| `document_missing` | — | **never** (no handler; returns `[]`) | hidden in the form |

Every query is capped at 200 matches per rule per run.

**`days_before = 0` caveat:** the worker uses `int(rule.get("days_before") or DEFAULT)`, so a stored `0` is treated as falsy and replaced by the default (30 / 7 / 90). "0 days" therefore does **not** mean "on the day" — it silently means the default.

### 3.3 Revizii integration
Thresholds (km remaining) are configured per component on page Revizii, not in the rule. The rule only chooses the category (event) and status filter. `context_suffix = baseline_at:status`, so a new service cycle (after "Înregistrează înlocuire") or a transition Se apropie → Necesită produces a new notification even with repeat off.

### 3.4 Channels
- `notification_rules.channel` ∈ `email | sms | email_sms` → worker set `{email}`, `{sms}`, `{email, sms}` (`RULE_CHANNELS`).
- If SMS is not ready (`SMSALERT_ENABLED` false or credentials missing) the worker silently drops `sms` from the set (counter `sms_disabled`); an SMS-only rule then does nothing.
- `SMSALERT_FORCE_TO` reroutes all SMS (test mode). SMS rate limit ≈1 call / 2.1 s, `SMSALERT_MAX_SEND_PER_RUN` (25 default), text ≤300 chars.
- Approval rules: worker.py **removes email** (`rule_channels` L360–364); approval_flow.py sends email only for rules whose channel ∈ {email, email_sms} (L270, L1003).

### 3.5 Recipients
- `admins`: active users with `rol='admin'` (email and/or phone used per channel).
- `specific_users`: users in `notification_rule_recipients` that are still active.
- `drivers`: no users; only driver SMS targets (`driver_recipients`): for driver/leave entities the driver himself, otherwise drivers allocated to the vehicle (`owner_id`). Requires channel `sms`.
- `vehicle_responsible`: not implemented → falls into the `admins` branch (hidden in the form).
- `notify_driver` (metadata): adds driver SMS targets on top of user recipients when channel includes SMS. Not applicable to approvals (owner_id 0).
- Addresses are de-duplicated per (channel, address) within a match; users without the needed address are counted as `skipped`.
- approval_flow recipients: `specific_users` → listed users with email; anything else (including `drivers`) → admins.

### 3.6 Repetition (`repeat_until_resolved`)
- `context_id = rule:{id}:{event_type}:{entity_type}:{entity_id}[:{suffix}][:{bucket}]` (worker L901).
- Repeat **on** → bucket = today's date (`YYYY-MM-DD`) → at most one send per rule × entity × address per day, every day while the condition holds. For approvals the bucket is `r{epoch_day // repeat_days}` → every N days.
- Repeat **off** → no bucket → one send ever per rule × entity × address (except new suffix: new Revizii cycle/status, approval re-submitted).
- approval_flow emails: resend if the approval was updated since last email, else only if repeat on and `repeat_days` elapsed.

### 3.7 Deduplication
- Before enqueuing, `existing_queue(context_id, channel, address)` is checked: if found and its queue status is `failed` → requeued (attempts reset); otherwise skipped.
- `notification_queue.dedupe_key = sha256(context_id | address)` (`sms:+40…` for SMS). PHP `NotificationRuleModel::normalizePhone` mirrors the worker's normalization.
- Because the **rule id** is part of the context, a rule that is deleted and recreated (new id) re-sends today even if the old rule already did.

### 3.8 Activation / deactivation
- List-row switch → `POST action=toggle` → `setRuleEnabled` on **one** row (not the whole group).
- Form "Activă" applies to every row of the group on save; edit pre-fills it as "any member enabled" → saving a group re-enables members that were individually switched off.
- approval_flow: if **no enabled** approval rule exists it uses a built-in default (all admins, no repeat). Disabling all approval rules therefore does not stop approval emails; a SMS-only approval rule does not trigger the default either (L1002–1003).

### 3.9 Existing rule updates (edit)
- `getRuleGroupFor(id)` loads the group (or the single legacy rule).
- `syncRuleGroup`: keyed by `event_type|lower(document_type)` → matching rows **updated in place** (id kept → dedupe history kept), new keys **created**, missing keys **deleted** (recipients cascade). Runs in one transaction.
- A legacy ungrouped rule that grows to >1 row gets a new `rule_group`. A group reduced to 1 row keeps its `rule_group`.
- Flash messages: "Regula a fost adaugata." / "Au fost adaugate N reguli…" / "Grupul de reguli a fost salvat: X actualizate, Y adaugate, Z sterse."

### 3.10 Daily notification limit (`daily_limit_enabled`)
**Stored, edited, displayed — but never read by the sending logic.** worker.py does not reference it; approval_flow.py loads it into `rule["daily_limit"]` and never uses it. The effective "max one per day" behavior comes from the date bucket of *repeat on*. With repeat off the switch is meaningless too. The UI label therefore over-promises.

### 3.11 Other functionality found
- Name auto-generation (server `suggestRuleName`, mirrored client-side).
- Custom document types can be added client-side (persisted only as rule `document_type`).
- Rules list: filters (text, entity, single event, status), pagination 10/page, group badge, per-row edit/toggle/delete (delete is per row, not per group; `data-confirm` prompt).
- Test actions: `run_test` (queues test email to current user), `send_test_sms` (status tab).
- Self-healing DDL on every model construction (adds columns/enum values/`rule_group`).
- Dead code: legacy `.notification-rule-*` CSS in `style.css`; unused private matching/email methods in the PHP model.

---

## 4. Conditional-behavior matrix

### 4.1 Field visibility / requirement by event selection

| Selected events contain… | days block | doc block(s) | km | tread | revizie | approval | notify_driver eligible | `drivers` recipient allowed |
|---|---|---|---|---|---|---|---|---|
| nothing | – | – | – | – | – | – | (shown if SMS) | – (no events → error) |
| vehicle/driver doc expiry | ✔ (≤365) | vehicle / driver | – | – | – | – | ✔ | ✔ (SMS only) |
| birthday / leave | ✔ | – | – | – | – | – | ✔ | ✔ |
| equipment expiry/inspection | ✔ | equipment | – | – | – | – | ✔ | ✔ |
| tire_km_limit | – | – | ✔ **required >0** | – | – | – | ✔ | ✔ |
| tire_tread_depth | – | – | – | ✔ **required >0** | – | – | ✔ | ✔ |
| tire_dot_expiry | ✔ | – | – | – | – | – | ✔ | ✔ |
| revizie_* | – | – | – | – | ✔ | – | ✔ | ✔ |
| approval only | – | – | – | – | – | ✔ | ✖ (hidden) | ✖ (server error) |
| approval + others | per others | per others | … | … | … | ✔ | ✔ (applies to non-approval rows only) | ✖ (server error) |

### 4.2 Channel × recipient × switches → what actually happens

| Channel | Recipient | notify_driver | Result per matching entity (non-approval events) | Approval event |
|---|---|---|---|---|
| email | admins | n/a (hidden) | email to each admin with email | worker: nothing; approval_flow: email to admins |
| email | specific_users | n/a | email to selected users with email; users without email skipped | approval_flow: email to selected users |
| email | drivers | n/a | **rejected** ("alege canalul SMS") | **rejected** |
| sms | admins | off | SMS to admins with valid phone | worker: SMS to admins; approval_flow: no email |
| sms | admins | on | + SMS to driver(s) of the entity | driver flag ignored (no owner) |
| sms | specific_users | on/off | SMS to selected users (+ drivers if on) | SMS to selected users |
| sms | drivers | n/a (hidden) | SMS to driver(s) only | **rejected** |
| email_sms | admins / specific_users | on/off | email + SMS to users (+ driver SMS if on) | worker SMS + approval_flow email |
| email_sms | drivers | n/a | **rejected** (server requires exactly `sms`; client auto-switches to `sms`) | **rejected** |
| any with SMS | any | any | if SMS disabled/unconfigured → SMS part silently dropped | same |

| Activă | Repetă | Max/zi | Effect |
|---|---|---|---|
| off | * | * | rule ignored by both workers (approval_flow may fall back to default rule if no approval rule is enabled) |
| on | on | on/off | one notification per day per entity × address while condition holds (approvals: every `repeat_days`) — the daily-limit switch changes nothing |
| on | off | on/off | one notification per entity × address for the lifetime of the rule id (new Revizii cycle/status or re-submitted approval = new one) |

### 4.3 Create vs edit

| Aspect | Create | Edit |
|---|---|---|
| Endpoint | `store` | `update` + `id` |
| Initial state | `defaultFormData()` (no events, days 30, email, admins, all 3 switches on) | union of group's events/doc types; shared values from the clicked row; enabled = any member enabled |
| Rows written | N new rows (`rule_group` if N>1) | in-place update for unchanged keys, insert for new keys, delete for removed keys |
| Summary count text | "Se salvează N reguli:" | "Grupul va avea N reguli (acum M). Regulile debifate se șterg." when N≠M |
| Header subtitle | generic hint | "Grup de N reguli…" when M>1 |
| Error redirect | `action=create` | `action=edit&id=…` |
| Dedupe impact | n/a | kept rows keep history; added/re-keyed rows (e.g. changed document type) send again |

---

## 5. Layout root-cause analysis

### Measurements (empty create form)

| Viewport | Modal (w×h) | Header | Body | Footer | `.nrf-main` client / scroll | Summary column / content | Entity grid |
|---|---|---|---|---|---|---|---|
| 1920×1080 | 1180×1048 | 77 | 906 | 65 | 906 / **1304** | 300×906 / **77** | 3×267 px |
| 1673×915 | 1180×883 | 77 | 741 | 65 | 741 / **1304** | 300×741 / **77** | 3×267 px |
| 1366×768 | 1180×736 | 77 | 594 | 65 | 594 / **1304** | 300×594 / **77** | 3×267 px |
| 1024×768 | 992×736 | 77 | 594 | 65 | 594 / 1486 | 300×594 / 77 | 2×312 px |
| 375×812 | 375×812 | 97 | 651 | 65 | 549 / 2142 | **overlaps main** | 1×335 px |

Section heights at desktop (empty): S1 = **678 px**, S2 = 101, S3 = 239, S4 = 114. All events selected: S1 678, S2 **823**, S3 441, main scroll 2228, summary content 993 px (taller than its column).

### 5.1 Large unused right-side panel
- **Selector:** `.nrf-body { grid-template-columns: minmax(0,1fr) 300px }` (CSS L71) + `.nrf-summary` (L688).
- **Root cause:** the column is a fixed 300 px track that always stretches to the full body height (grid `align-items: stretch`), while its only content (`.nrf-summary-card`) is 77 px in the empty state → ~90 % blank (664 px of 741 at 1673×915). It is **intentional** (live summary/preview, populated by JS), **conditionally populated** (useful only after events are chosen), and at <1280 px it steals 25–30 % of the width (at 1024 px main shrinks to 677 px, entity grid drops to 2 columns, total scroll grows to 1486).
- Verdict: the *information* is valuable (rule count, preview, warnings); the *permanent full-height column* is unnecessary.

### 5.2 Excessive modal height
- **Selectors:** `.nrf-modal { max-height: calc(100vh - 2rem); display:flex; flex-direction:column }` (L11), `.nrf-body { flex:1 1 auto; overflow:hidden }`, `.nrf-main { overflow-y:auto }`.
- **Root cause:** height is content-driven, but the content is always taller than any common viewport: Section 1 alone is 678 px, total main content ≥1304 px. So the modal always grows to `100vh − 32px`. Section 1 is tall because:
  - `.nrf-entities { grid-template-columns: repeat(auto-fill, minmax(250px,1fr)) }` (L178) creates 7 cards in rows of 3 → 3 rows; the last row holds a single card ("Revizie (km)") next to two empty tracks.
  - Grid rows stretch each card to the tallest in its row (205 / 168 px) — e.g. "Concediu" (1 event) and "Solicitare aprobare" (1 event) are padded to 168 px.
  - Each event option is a two-line label (title + hint), 0.5 rem padding.
- Section 2 shows an empty-state box even when nothing applies; Section 4 adds another 114 px for three switches.

### 5.3 Unused white space below the footer
- **Not reproduced on desktop**: in every desktop measurement the footer's bottom equals the modal's bottom (`.nrf-footer` L788 is the last flex child; form height = body + footer). The 16 px band above/below the modal is the deliberate `calc(100vh − 2rem)` margin showing the backdrop.
- Likely sources of what was seen:
  1. **≤991.98 px breakpoint bug (confirmed, see 5.8):** `.nrf-main` gets `overflow: visible` while the grid track keeps `min-height:0`; main content overflows its track and paints over/under the summary row, producing blank areas and overlapping content in the body.
  2. **Mobile `height: 100vh`** (L819 block): on mobile browsers `100vh` ≥ visible viewport; the footer can end up below the visible area or leave a gap when the address bar collapses. Should be `100dvh`.
  3. The summary column (5.1) itself reads as "empty space" beside/below the content.
- If the referenced screenshot shows space *inside* the modal under the footer on desktop, that state could not be reproduced; please supply it (see §10).

### 5.4 Scrolling region inside the modal
- **Three independent scroll contexts:** `.nrf-main` (`overflow-y:auto`), `.nrf-summary` (`overflow-y:auto`, its card is `position: sticky; top:0` but becomes taller than the column with many events → sticky is ineffective and the summary scrolls separately), and the **page behind** (no body scroll lock; wheel over the backdrop scrolls the rules page).
- The visible scrollbar sits between the main and summary columns (main column's right edge), which looks like a layout seam.
- Users-list adds a 4th nested scroller (`.nrf-users-list { max-height:260px }`).

### 5.5 Inconsistent widths: channel buttons vs recipient cards
- **Selectors:** `.nrf-channel { grid-template-columns: repeat(3, minmax(0,1fr)); max-width: 520px }` (L423) vs `.nrf-recipients { grid-template-columns: repeat(auto-fill, minmax(190px,1fr)) }` (L470).
- **Measured:** channel buttons 168 px each (capped by 520 px), recipient cards 200 px each across 825 px → `auto-fill` creates **4 tracks for 3 cards** (empty 4th track), so the two rows have different widths and their columns don't align. `.nrf-name .form-control { max-width:520px }` (L129) introduces a third arbitrary width. Heights also differ (40 px buttons vs 88 px cards).

### 5.6 Alignment of options and toggle descriptions
- **Selectors:** `.nrf-options { grid-template-columns: repeat(auto-fill, minmax(220px,1fr)) }` (L680), `.nrf-switch` (L500, `align-items:flex-start`, `margin: .75rem 0 0`).
- Columns are 264 px with descriptions of different lengths wrapping to different line counts; `margin-top` on every switch (designed for stacked use in Section 3) adds uneven top spacing in the grid; the switch track uses a custom background-image re-implementation of Bootstrap's `.form-switch` (L500–560) — slightly different size/alignment than Bootstrap switches elsewhere in the app. The "Trimite SMS și șoferului" switch in Section 3 uses the same component with a long paragraph → visually heavier than the option switches.

### 5.7 Header, body and footer sizing
- Header 77 px (title + subtitle + 1 rem padding), footer 65 px — fine individually, but together with the 16 px margins they consume 174 px of a 768 px screen (23 %), leaving 594 px for content that needs 1304 px.
- Header and footer are flex items (not sticky) — correct; but the body is a 2-column grid with `overflow:hidden`, so content height never informs the modal height once >max.
- Footer only holds Cancel/Save; the useful "N reguli" info lives in the summary column instead of next to the Save button.

### 5.8 Responsive behavior
- **≥992 px:** 2-column body with fixed 300 px summary; 1024 px viewport = cramped main (677 px).
- **≤991.98 px (L802):** `.nrf-body { grid-template-columns:1fr; overflow-y:auto }` and `.nrf-main, .nrf-summary { overflow: visible }` — **bug:** `.nrf-main { min-height:0 }` (base rule) lets the implicit grid row shrink to the available height (549 px at 375×812) while the content is 2142 px; with `overflow: visible` the content paints over the summary row (measured: summary at y=646 overlapping the entity cards; visible in screenshot — "REZUMAT" text drawn over the Anvelopa card).
- **≤575.98 px (L819):** full-screen `height:100vh` (not `dvh`), channel buttons stack to 1 column (3×40 px rows).
- No breakpoint between 992 and 1280 px where the summary would collapse.

### 5.9 CSS inheritance / global conflicts
- **z-index stacking:** `.nrf-backdrop` 1040 / `.nrf-modal` 1041 are **below** app chrome: `.fleet-approval-drawer-toggle` 1066 (the blue "‹ 2" tab on the right edge is drawn **over** the modal/summary), `.fleet-sidebar-edge` 1080 and `.sidebar` 1090 (hovering the left edge opens the sidebar above the modal). Bootstrap's own modal uses 1055; the app chrome exceeds it.
- Semantic tags (`aside`, `header`, `footer`, `section`) have **no** global element selectors in `style.css` (verified) → no leakage from there.
- Bootstrap `.form-check-input` is restyled inside `.nrf-switch` (custom switch) and used raw in `.nrf-user` — two different checkbox looks in one modal.
- Legacy `.notification-rule-drawer/grid/checks` and `.notification-wide` rules in `style.css` (L1054–1239) are dead but still shipped.
- The modal CSS is loaded via a `<link>` inside `<main>` (view L672) → stylesheet is discovered late (possible flash of unstyled modal on slow connections).

### 5.10 Fixed heights, hardcoded widths, grids, overflow rules (inventory)
| Rule | Value | Problem |
|---|---|---|
| `.nrf-modal` width | `min(1180px, 100vw - 2rem)` | wide even when content is narrow |
| `.nrf-modal` max-height | `calc(100vh - 2rem)` | always reached; `vh` not `dvh` |
| `.nrf-body` columns | `minmax(0,1fr) 300px` | fixed summary track |
| `.nrf-body` overflow | `hidden` (desktop) / `auto` (≤991) | inconsistent scroll owner |
| `.nrf-main` | `min-height:0; overflow-y:auto` → `overflow: visible` ≤991 | overlap bug |
| `.nrf-entities` | `auto-fill minmax(250px,1fr)` + stretch | empty tracks, stretched cards |
| `.nrf-channel` | 3 cols, `max-width:520px` | mismatched with recipients |
| `.nrf-recipients` | `auto-fill minmax(190px,1fr)` | phantom 4th column |
| `.nrf-options` | `auto-fill minmax(220px,1fr)` | uneven wrap |
| `.nrf-users-list` | `max-height:260px`, `auto-fill minmax(260px,1fr)` | nested scroll |
| `.nrf-name .form-control`, `.nrf-number`, `.nrf-add-doc` | 520 px / 11 rem / 320 px | arbitrary widths |
| `.nrf-modal` ≤575 | `height:100vh` | mobile viewport issues |

### 5.11 Functional UI defects found during the audit (not layout, but must be handled in the refactor)
1. **Save button locks after an empty submit (confirmed).** `notificari-regula.js` L363 calls only `preventDefault()` when no event is checked; `app.js` L227–274 (document-level submit listener) still runs, sets `form.dataset.submitting = '1'` and disables the submit button. Result: after clicking Save with no events, the button stays disabled and every further submit is swallowed until reload. The project convention is to also call `stopPropagation()` (see app.js L250–253).
2. **`Escape` closes the modal from anywhere, including while typing in an input (search box, "Alt tip…"), and backdrop click closes — both without an unsaved-changes check** (full navigation, data lost).
3. No focus management: initial focus is not set, focus is not trapped in the dialog, focus is not restored on close.
4. Page behind is scrollable while the modal is open.

---

## 6. Backend / database dependencies (contracts)

### 6.1 Endpoints
| Action | Method | Params | Notes |
|---|---|---|---|
| `index.php?page=notificari&action=create` | GET | — | renders list + modal |
| `…&action=edit&id={ruleId}` | GET | `id` | modal for the rule's whole group |
| `…&action=store` | POST | form payload | CSRF; redirect to list or back to create with flash |
| `…&action=update` | POST | payload + `id` | CSRF; redirect to list or back to edit |
| `…&action=toggle` | POST | `id`, `enabled` (0/1) | single row |
| `…&action=delete` | POST | `id` | single row |
| `…&action=run_test` / `send_test` / `send_test_sms` | POST | `test_email` / `test_phone` | |

All: `require_admin_or_403()`; ACL module `notificari` is `admin_only`; `ensure_csrf_or_redirect()` on POSTs.

### 6.2 Request payload (must remain byte-compatible)
```
_csrf_token
id                                   (edit only)
name
event_types[]                        (one per checked event; legacy scalar event_type still accepted)
days_before
doc_all[vehicle|driver|equipment]    ("1" = Toate, "0" = Doar anumite)
doc_types[vehicle][] / [driver][] / [equipment][]
threshold_km
threshold_tread_depth                (comma or dot)
revizie_status                       ("" | "necesita")
approval_resource_types[]            (vehicle|driver|repair|diurna)
approval_reasons[]
approval_repeat_days
channel                              (email|sms|email_sms)
notify_driver                        ("1" when checked; absent = off)
recipient_mode                       (admins|drivers|specific_users[|vehicle_responsible])
recipient_user_ids[]
enabled / repeat_until_resolved / daily_limit_enabled   (present = 1, absent = 0)
```
Hidden (irrelevant) fields may be submitted; the server ignores them per event. Checkboxes rely on "absent = off" semantics — a redesign must not submit hidden `0` duplicates unless the server is adapted.

### 6.3 Server-rendered contract consumed by the JS
- `<script type="application/json" data-nrf-config>` with `eventLabels, entityLabels, eventEntity, conditions, smsChannels, approvalEvent, maxRules, isEditing, groupSize, closeUrl`.
- Data hooks: `data-nrf-form, -event, -entity, -entity-count, -cond="days|docs-vehicle|docs-driver|docs-equipment|km|tread|revizie|approval", -conditions-empty, -days, -days-preset, -days-for, -doc-all, -doc-picker, -doc-list, -doc-new, -doc-add, -channel, -driver-option, -recipient, -users, -user, -user-search, -user-count, -users-all, -users-none, -user-email, -user-phone, -name, -sentence, -count, -preview, -warnings, -submit, -submit-label, -close`; user rows carry `data-search, data-has-email, data-has-phone`.
- The client-side expansion (`buildItems`) must stay identical to `validateRuleInput` (preview = what gets saved).

### 6.4 Database
| Table | Relevant columns | Written by form |
|---|---|---|
| `notification_rules` | `id, rule_group (VARCHAR40, idx), name, event_type (VARCHAR80), entity_type, document_type, days_before, threshold_km, threshold_tread_depth, channel, recipient_mode, enabled, repeat_until_resolved, daily_limit_enabled, metadata_json (notify_driver, group_name, approval_resource_types, approval_reasons, repeat_days), created_at, updated_at` | yes |
| `notification_rule_recipients` | `rule_id → rules ON DELETE CASCADE, user_id → utilizatori ON DELETE CASCADE, created_at` | yes |
| `notification_deliveries` | `context ('fleet_rule'), context_id, channel (email|sms), recipient_email, recipient_phone, recipient_name, subject, message, status, provider, metadata_json, sent_at, error_message` | worker |
| `notification_queue` | `delivery_id, dedupe_key, status, attempts, max_attempts, scheduled_for, locked_at, last_error` | worker |
| Read sources | `utilizatori, documente, documente_soferi, soferi, vehicule, concedii, anvelope, anvelope_alocari, vehicule_anvelope_pozitii, inventar_dotari_vehicule, inventar_dotari_catalog, inactive_resource_approvals (+_documents)`, Revizii via `scripts/revizii_alerts.php` | — |

### 6.5 Worker dependencies that the UI must not break
- Exact `event_type` strings, `channel` values, `recipient_mode` values.
- `document_type` semantics per event (doc type / equipment catalog name / Revizii status).
- `metadata_json` keys: `notify_driver`, `approval_resource_types`, `approval_reasons`, `repeat_days`, `group_name`.
- Stable rule ids for unchanged rows on edit (dedupe context contains the id).

---

## 7. UI/UX redesign recommendations

All recommendations are presentation-only unless marked **(optional business change)**.

### 7.1 Modal shell
- Width by content: `width: min(960px, calc(100vw - 32px))`; height content-driven with `max-height: min(880px, calc(100dvh - 32px))`.
- **One scroll owner:** header and footer fixed in the flex column; a single `.nrf-scroll { overflow-y:auto; overscroll-behavior: contain }` for everything else. Remove `overflow:hidden` grid body and the independent summary scroller.
- Lock page scroll while open (`body` class with `overflow:hidden`), raise the stack above app chrome (backdrop/modal ≥ 1100, or hide `.fleet-approval-drawer-toggle` / `.fleet-sidebar-edge` while open).
- Load `notificari-regula.css` in `<head>` (or keep inline link but before markup).

### 7.2 Replace the summary column with a footer summary
- Footer left: compact status ("5 reguli · Email + SMS · 2 utilizatori"), warnings badge (count, amber), and a "Vezi regulile" toggle that expands an inline list above the footer (or a popover). Footer right: Anulează / Salvează N reguli.
- Keeps 100 % of the summary information, frees 300 px, removes the empty column problem in the empty state.
- Optional variant for ≥1440 px: keep a side column but only render it once ≥1 event is selected and size it `auto` (not stretched).

### 7.3 Section 1 — compact event picker
- Use a 12-column grid with `align-items: start` (no stretched cards) or CSS columns (`columns: 3 240px`) so cards pack without empty tracks.
- Make events compact chips/check rows (single line, 32–36 px); move hints to a muted second line only on hover/focus or to `title`/tooltip.
- Alternative (lowest height): two-pane picker — entity list on the left with counts, events of the selected entity on the right, plus a "selected" chip row. Target Section 1 height ≤ 320 px at 1366×768.

### 7.4 Section 2 — predictable conditional rendering
- Render a condition block **next to the event it affects** in a stable order (days → document filters → tyre thresholds → Revizii → approvals) with a small label of which events it applies to (already available as `data-nrf-days-for`).
- Collapse the empty state to a single muted line (≤ 24 px) or hide Section 2's header when nothing applies.
- Put `km` and `tread` side by side (already a row) with equal widths; standardize number inputs to one width token.

### 7.5 Section 3 — consistent selection controls
- One shared grid for channel and recipient choices: e.g. `grid-template-columns: repeat(3, 1fr)` for both rows (3 channels, 3 recipient modes — `vehicle_responsible` as 4th only when present), same height (≥ 56 px card with icon + label + 1-line description), same radius/border.
- "Trimite SMS și șoferului" as a compact inline switch under the channel row (one-line label + info tooltip).
- Users list: keep search + select all/none; fixed height that fits ~6 rows, no nested grid columns on narrow widths.

### 7.6 Section 4 — options
- Single row of three compact switches with one-line labels; descriptions as `title`/help icon, or a 3-column grid with `align-items:start` and no per-item top margin.
- Use Bootstrap's native `.form-check.form-switch` markup everywhere (consistency with the rest of the app).

### 7.7 Spacing and tokens
- Spacing scale 4/8/12/16/24 px; section gap 16 px; control height 36 px; card radius 8 px; one border color token. Remove arbitrary widths (520 / 320 / 11 rem) in favor of grid spans.

### 7.8 Small screens
- ≤ 991 px: single column, summary in the footer (7.2) — this also removes the overlap bug.
- ≤ 575 px: full-screen sheet using `100dvh`, sticky footer with safe-area padding, channel row as a 3-segment control (not stacked), entity cards as accordions.

### 7.9 Interaction / accessibility (UI-level)
- Set initial focus to the name field (create) or the dialog title (edit); trap focus; restore focus on close.
- `Escape`: close only when focus is not in a text input; ask for confirmation when the form is dirty; backdrop click should not discard a dirty form.
- Fix the submit guard: call `event.stopPropagation()` together with `preventDefault()` (convention already used in `app.js`).

### 7.10 Optional business improvements (not part of a UI-only refactor)
1. Implement or remove "Maxim o notificare pe zi" (`daily_limit_enabled` is a no-op).
2. Treat `days_before = 0` as "on the day" in the worker (currently replaced by the default), or set `min=1` in the UI.
3. Group-level toggle/delete in the rules list (today both act on a single row).
4. Edit-mode "Activă" semantics: "any member enabled" re-enables individually disabled members on save — consider a tri-state or per-row status display.
5. Show in the form that SMS is globally disabled (`SMSALERT_ENABLED=false`) when an SMS channel is chosen.
6. Remove `document_missing` / `vehicle_responsible` entirely or implement them.

---

## 8. Regression risks

| # | Risk | Why it matters |
|---|---|---|
| R1 | Renaming/removing any input `name` or changing checkbox "absent = off" semantics | breaks `validateRuleInput`, silently flips switches |
| R2 | Disabling hidden condition inputs differently (e.g. disabling `doc_all`) | server would read missing `doc_all[x]` as 0 ("Doar anumite") → validation error "Bifeaza cel putin un tip" |
| R3 | Diverging client preview from server expansion | user sees N rules, server saves M |
| R4 | Changing how edit identifies rows (event\|document_type key) | in-place update lost → new ids → **duplicate notifications** the same day |
| R5 | Removing data hooks / JSON config used by `notificari-regula.js` | conditional sections and summary stop working |
| R6 | Moving the modal to a client-only dialog without the `create/edit` GET states | breaks error redirect + `consume_form_flash` repopulation and deep links |
| R7 | Submit handler without `stopPropagation` (existing bug) | Save button locked |
| R8 | z-index changes | app sidebar / approval drawer must still work when the modal is closed |
| R9 | Changing `drivers` → channel auto-switch | server rejects `email`/`email_sms` with drivers |
| R10 | Hiding `vehicle_responsible` / `document_missing` for rules that use them | edit would drop the value → event/recipient mode change on save |
| R11 | Approval rules: altering channel options | approval email is driven by approval_flow.py (`email` / `email_sms`), SMS by worker.py |
| R12 | Global CSS edits in `style.css` | the same file styles every page; keep modal styles scoped under `.nrf-*` |
| R13 | Responsive refactor of users list | `data-search`/`data-has-*` attributes drive search and warnings |

---

## 9. Acceptance criteria for a UI-only refactor

**Functional parity**
1. Submitting identical selections before/after the refactor produces identical `notification_rules` / `notification_rule_recipients` rows (compare `event_type, document_type, days_before, threshold_*, channel, recipient_mode, enabled, repeat_until_resolved, daily_limit_enabled, metadata_json` keys, recipients).
2. Edit of an existing group keeps ids of unchanged rows; unchecked rows are deleted; new rows created (verified on a test group).
3. All server validation messages still appear inline next to their field, and the form is repopulated after an error in both create and edit.
4. Conditional visibility matches §4.1 for every event combination; `notify_driver` and users list visibility match §2.4.
5. Summary/preview count equals the number of rows saved (≤ 60 enforced).
6. Clicking Save with no events does **not** lock the Save button.

**Layout**
7. At 1366×768 with no events selected, the modal shows header, all four sections' headings and the footer without scrolling, or with ≤ 1 viewport of scroll; no blank region > 120 px inside the modal.
8. Exactly one scrollable region inside the modal; the page behind does not scroll.
9. Channel and recipient controls share the same column grid and height; no empty grid tracks in any row.
10. Footer is always visible and flush with the bottom of the modal at all tested sizes; no space below it.
11. Nothing overlaps at 1920×1080, 1366×768, 1024×768, 768×1024, 375×812 (including the ≤991 px breakpoint).
12. App chrome (approval drawer tab, sidebar edge) is not drawn over the modal and cannot be opened while the modal is open.

**Accessibility**
13. Initial focus set, focus trapped, `Escape` behavior per §7.9, all choices reachable and operable by keyboard with visible focus.

**Non-regression**
14. Worker run (`notification_service/worker.py`) on a test rule set yields the same enqueued deliveries before/after.
15. No changes to `worker.py`, `approval_flow.py`, schema, or controller validation semantics.

---

## 10. Unresolved questions

1. **Screenshot:** the screenshot referenced in the brief was not attached. In particular "unused white space below the footer" could not be reproduced on desktop — which browser/zoom/viewport was it taken at?
2. **Summary panel:** keep the live preview (recommended, moved to the footer) or drop it entirely?
3. **"Maxim o notificare pe zi":** should the redesign keep a control that currently has no effect, hide it, or should the worker implement it (business change)?
4. **`days_before = 0`:** intended meaning ("on expiry day") vs current worker behavior (default 30/7/90)?
5. **Group actions in the list:** should toggle/delete operate on the whole group?
6. **Edit "Activă" with partially disabled groups:** keep "any enabled" or show/keep per-row state?
7. **`vehicle_responsible` / `document_missing`:** remove, keep hidden, or implement?
8. **Modal vs page:** is a server-rendered modal (navigate to open/close) acceptable, or should the redesign open the form client-side (would require keeping the `create/edit` GET states for error redirects)?
9. **Target screens:** minimum supported desktop resolution (1366×768?) and whether phone usage is expected for this admin-only page.
