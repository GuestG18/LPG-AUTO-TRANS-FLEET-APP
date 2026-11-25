# AUDIT_DETALII_SOFER — Technical audit of the "Detalii Șofer" page

- **Route audited:** `index.php?page=soferi&action=show&id={driver_id}`
- **Audit date:** 2026-11-23
- **Branch / commit:** `main` @ `8dd05a4` (working tree has unrelated uncommitted changes; none touch the files audited here except shared layout `views/layout/header.php`)
- **Scope:** read-only. No application file, schema or data was modified. Two throw-away read-only PHP scripts were run against the local DB (schema introspection + status comparison); they live outside the project.
- **Paths** below are relative to `htdocs/` unless stated otherwise. Line numbers refer to the audited commit plus working tree and may drift.

---

## 1. Executive Summary

The Detalii Șofer page is **not a dedicated page**. It is the generic module "show" screen (`ModuleController::showAction` → `views/module/show.php`) with three driver-only blocks switched on by `$moduleKey === 'soferi'`:

1. **"Status calculat automat" panel** (top) — inline PHP + inline `<script>` in `show.php` (lines 27–541). It shows the status computed by `EntityStatusService::evaluateDriverStatus()`, a "De ce este șoferul inactiv" callout, a "Doar problemele / Toate documentele" filter and two collapsible groups of expandable document rows.
2. **Generic detail table** (middle) — a `<table>` driven by `$modules['soferi']['detail_fields']` from `config/modules.php` (photo, name, birth date, phone, allocated vehicles, status, notes, timestamps).
3. **"Documente asociate șoferului" card** (bottom) — a plain table of all `documente_soferi` rows for the driver, with links to preview/detail/edit/create on the `documente_soferi` module.

Key facts a redesign must respect:

- The driver status (`soferi.status`, `activ`/`inactiv`) is **derived, never edited manually** (the `status` form field is removed in `index.php:318`). It is computed from the mandatory driver document types in `configurare_documente_obligatorii_soferi`, with special cases for terminated drivers and collaborators.
- **The show page evaluates the status but does NOT persist it** (`evaluateDriverStatus`, not `syncDriverStatus`). Persistence happens on the list page, the edit page, after any driver/document/config mutation. The panel and the "Status" row in the detail table therefore read from two different sources (see §4.6, §12).
- Driver ↔ vehicle is **many-to-many** via `soferi_vehicule` (with `is_primary`), plus the legacy `soferi.vehicle_id` kept as the primary vehicle. There is **no history**: assignments are replaced wholesale (DELETE + INSERT) on each save. Assignments are **not editable from this page** (only via Edit).
- Every allowed driver document type is a **mandatory** type: document creation only accepts types listed in `configurare_documente_obligatorii_soferi`, and DB enforces one document per (driver, type).
- The page has **no AJAX**, no modals, no forms and no POST actions. All actions are navigation links. All behaviour is server-rendered plus one inline JS IIFE for the collapsible status board.
- **Former drivers** (terminated) cannot open this page — they are redirected to `page=fosti_angajati`.
- Buttons on the page are **not permission-gated in the view**; access is enforced only when the target URL is requested (central route guard). A redesign should decide whether to hide buttons the user cannot use — this is a proposed improvement, not current behaviour.

Live DB snapshot at audit time (aggregates only): 36 driver rows, 8 mandatory document types configured, 12 drivers with more than one assigned vehicle, 0 duplicate document types per driver, **0 mismatches** between stored `soferi.status` and the freshly evaluated status.

---

## 2. File and Component Architecture

### 2.1 Request flow

```
index.php
 ├─ require_route_access('soferi', 'show')         includes/access.php:249  (central guard, index.php:577)
 └─ switch ($page) case 'soferi':                   index.php:~682
      require_auth();
      (new ModuleController($db, $modules))->handle('soferi', 'show')
         └─ ModuleController::showAction()          controllers/ModuleController.php:5338
              ├─ ModuleModel::findById($module, $id)            models/ModuleModel.php:283
              ├─ isFormerDriverRecord() → redirectFormerDriverToFormerEmployees()   :5222 / :5240
              ├─ buildModuleBackUrl()                           :7019
              ├─ DocumentModel::getDocumentsForDriver($id)      models/DocumentModel.php:1911
              ├─ buildDriverDocumentCustomFieldDisplayRows($doc)  (per document)
              ├─ EntityStatusService::evaluateDriverStatus($id) services/EntityStatusService.php:85
              └─ render('module/show.php', $viewData)
                    └─ layout header/footer (views/layout/header.php, footer.php)
```

### 2.2 Files

| Role | File | Notes |
|---|---|---|
| Front controller / router | `index.php` | Module overrides (`$modules[...]` lines ~65–160), removes `soferi.form_fields.status` (line 318), route guard (577), dispatch (`case 'soferi'`, ~682). |
| Module definition | `config/modules.php:324–455` | `'soferi'` block: `select`, `joins`, `base_conditions`, `list_columns`, `detail_fields`, `form_fields`, `filters`. |
| Related module definition | `config/modules.php:756–826` + overrides `index.php:65–160` | `'documente_soferi'` (document CRUD that this page links to). |
| Controller | `controllers/ModuleController.php` | Generic controller for `vehicule`, `soferi`, `documente`, `documente_soferi`, cost configs, `utilizatori`. |
| Model (generic) | `models/ModuleModel.php` | `findById`, `getDriverVehicleIds` (418), `getDriverVehicleIdsMap` (455), `findInvalidDriverAssignmentVehicleIds` (521), `syncDriverVehicleAssignments` (565), `isDriverAssignedToVehicle` (~389). |
| Model (base) | `models/BaseModel.php:71` | `ensureDriverVehicleAssignmentsSchema()` — creates `soferi_vehicule` if missing and back-fills from `soferi.vehicle_id` (`INSERT IGNORE`). |
| Model (documents) | `models/DocumentModel.php` | `getDocumentsForDriver` (1911), `getRequiredDriverDocumentTypeOptions` (275), `driverDocumentTypeExists` (814), `getDriverDocumentExpiryRequirementByType`, `getDriverDocumentCustomFieldConfigsByType`. |
| Status service | `services/EntityStatusService.php` | All automatic status logic (§4). |
| View | `views/module/show.php` | Shared show view for all ModuleController modules. Driver blocks: 27–541 (status panel + inline JS), 543–565 (generic detail table), 1768–1866 (documents card). |
| Vehicle detail partial (reference) | `views/module/_vehicle_detail.php` | Only used when `moduleKey === 'vehicule'`. Candidate source of reusable UI (§11). |
| Document preview view | `views/module/document_preview.php` | Target of "Vezi în aplicație"; has a back link "Soferul asociat" to this page (line 34). |
| Helpers | `includes/helpers.php` | `format_value_html` (927), `status_badge_html` (319), `expiry_badge_html` (573), `document_file_url` / `document_file_link_html` (~600 / 612), `document_preview_available` / `document_preview_html`, `driver_image_url` (668), `driver_image_thumb_html`, `driver_image_preview_html` (819), `format_date_ro`, `format_datetime_ro`, `e`, `build_query_url`, `url`. |
| Access control | `includes/access.php`, `services/PermissionRegistry.php`, `permissions/modules/soferi.php`, `permissions/modules/documente_soferi.php` | §9. |
| CSS | `assets/css/style.css` | `.entity-status-*` (~114 rules from line ~3318; driver-specific `.entity-status-panel-driver` from ~3570; doc toolbar ~3604; mobile overrides in `@media (max-width: 767.98px)` from 3925). `.vehicle-photo-card` / `.vehicle-photo-empty` reused for the driver photo. Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 from CDN (`views/layout/header.php:68–69`). |
| JS | inline `<script>` in `show.php:404–540` | Status board only. Global `assets/js/app.js` and `bootstrap.bundle` are loaded in the footer (`views/layout/footer.php:12–14`); no page-specific JS file. |
| Uploaded files | `uploads/soferi/` (photos), `uploads/documente/` (driver **and** vehicle documents) | `uploads/.htaccess` = `Options -Indexes` + denies PHP execution only. |

### 2.3 Database tables (verified against the live local DB)

| Table | Relevant columns | Keys / constraints |
|---|---|---|
| `soferi` | `id`, `nume` (varchar 100, NOT NULL), `data_nasterii` (date), `data_angajare`, `data_incetare`, `employment_status` enum(`active`,`temporarily_inactive`,`suspended`,`leave`,`terminated`), `termination_date`, `termination_*`, `last_working_day`, `rehire_eligible`, `terminated_by`, `terminated_at`, `poza_original`, `poza_stocata`, `telefon` (varchar 20, NOT NULL), `salariu`, `vehicle_id` (legacy primary vehicle), `permis_expira_la` (date NOT NULL, legacy), `status` enum(`activ`,`inactiv`), `tip_colaborare` enum(`angajat`,`colaborator`), `regim_lucru`, `regim_lucru_detalii`, `observatii`, `created_at`, `updated_at` | PK `id`; idx `status`; idx `vehicle_id`; FK `fk_soferi_vehicle` → `vehicule` (`ON DELETE SET NULL` per `database/database.sql`). Note: the baseline DDL also declares `idx_soferi_employment_status`, which is **absent** in the live DB. |
| `soferi_vehicule` | `driver_id`, `vehicle_id`, `is_primary`, `created_at`, `updated_at` | PK (`driver_id`,`vehicle_id`); FKs → `soferi`, `vehicule` (both `ON DELETE CASCADE`). Migration `database/update_soferi_vehicule_multiple.sql`. |
| `documente_soferi` | `id`, `driver_id`, `tip_document`, `numar_document`, `data_emitere`, `data_expirare`, `fisier_original`, `fisier_stocat`, `observatii`, `custom_fields_json`, `created_at`, `updated_at` | **UNIQUE (`driver_id`,`tip_document`)**; FK → `soferi` `ON DELETE CASCADE`. |
| `configurare_documente_obligatorii_soferi` | `document_type` (UNIQUE), `requires_expiry`, `custom_fields_json` | Drives both the allowed document types and the mandatory set. |
| `vehicule`, `vehicule_cuplaje` | used by the "Vehicule alocate" label join | `vehicule_cuplaje.activ = 1` = current coupling. |
| `audit_log` | written by `DocumentModel::logAudit` on document mutations | Not displayed on this page (only on `documente` show). |

### 2.4 API endpoints / AJAX

**None on this page.** The page performs no `fetch`/XHR. All interactions are full-page GET navigations. The module list page's live search (`assets/js/module-live-search.js`, `?live=1`) is a *list* feature, not part of the show page.

### 2.5 Shared components (used by other pages)

- `views/module/show.php` — shared by every ModuleController module (`documente`, `documente_soferi`, `mentenanta`, `utilizatori`, cost configs, …). Driver blocks are guarded by `$moduleKey === 'soferi'`.
- `.entity-status-*` CSS — originally a shared vehicle/driver status component; the vehicle page now uses its own `vd-*` design, so the driver page is the main remaining consumer (grep of `views/` and `assets/js/` finds only `show.php`).
- `EntityStatusService` — used by list pages (vehicles, drivers), dashboard inactive panels, edit/store/update/delete flows.
- `expiry_badge_html`, `document_file_link_html`, `driver_image_preview_html`, `status_badge_html` — global helpers used across many views.

---

## 3. Complete Field Inventory

Order below = actual DOM order on the page.

### 3.1 Page header (generic, `show.php:18–24`)

| UI label | Source | Type | Editable | Condition | Notes |
|---|---|---|---|---|---|
| "Detalii șofer" (h2) | `'Detalii ' . $module['singular']` | text | — | always (non-vehicle) | Browser title `pageTitle` = `'Detalii ' . ucfirst('șofer')`. |
| Button "Editează" | link `?page=soferi&action=edit&id={id}` | link | — | always | Not permission-gated in view. |
| Button "Înapoi" | `$backUrl` = `buildModuleBackUrl()` → `?page=soferi` | link | — | always | |

### 3.2 "Status calculat automat" panel (`show.php:347–399`, only when `$statusContext` is an array)

| UI label / element | Source | Type | Editable | Condition |
|---|---|---|---|---|
| Kicker "Status calculat automat" | static | text | — | always |
| Title "Activ" / "Inactiv" | `$statusContext['status']` (evaluated, not DB) | enum | Read-only | always |
| Counter "N problemă/probleme" | `count($driverInactiveReasonRows)` (checks with service `state !== 'valid'`) | int | Read-only | status inactive **and** ≥1 reason |
| Badge "ACTIV"/"INACTIV" | same | enum | Read-only | always |
| Explanatory copy "Statusul nu mai este setat manual…" | static | text | — | always |
| Callout "De ce este șoferul inactiv" + summary | `$driverStatusSummary()` | text | Read-only | inactive + reasons |
| Problem pills (one per reason, icon + document label) | inactive reason rows | list | Read-only | inactive + reasons |
| Toolbar "Doar problemele" / "Toate documentele" | client-side filter | toggle | UI only | ≥1 check row |
| Group "Documente cu probleme · N" (open by default) | rows with UI state ≠ `valid` | collapsible | UI only | ≥1 check row |
| Group "Documente valabile · N" (closed by default) | rows with UI state `valid` | collapsible | UI only | ≥1 check row |

Each **document row** (button `[data-status-doc-row]`):

| UI element | Source | Notes |
|---|---|---|
| Icon | `meta.icon` (`bi-file-earmark-x`, `bi-calendar-x`, `bi-exclamation-triangle`, `bi-check-circle`) | by UI state |
| Name | `check.label` = mandatory document type name | |
| State label | `LIPSA` / `EXPIRAT` / `EXPIRA CURAND` / `OK` / `ATENTIE` | §4.5 |
| Date | `check.date` formatted `format_date_ro`, else `Lipsa` / `Atentie` / `Fara expirare` | |

Expanded **row detail** (`[data-status-doc-detail]`, hidden by default):

| Label | Source | Condition |
|---|---|---|
| Stare | state label | always |
| Data | date label | always |
| Serie / numar | `documente_soferi.numar_document` or `-` | document exists |
| Fisier | `document_file_link_html(fisier_original, fisier_stocat)` → direct link to `uploads/documente/{stored}` or badge "Fără fișier" | document exists |
| Actualizat la | `documente_soferi.updated_at` (`format_datetime_ro`) | document exists |
| Document: "Necesita incarcare" | static | document missing |
| Custom field chips/pairs | `custom_field_display_rows` (checkbox = chip if checked; date formatted; text/number raw) | document exists and has custom fields |
| Action "Vezi in aplicatie" | `?page=documente_soferi&action=preview&id={docId}` | document exists **and** `fisier_stocat` non-empty |
| Action "Detalii" | `?page=documente_soferi&action=show&id={docId}` | document exists |
| Action "Editeaza / inlocuieste" | `?page=documente_soferi&action=edit&id={docId}` | document exists |
| Action "Adauga document" | `?page=documente_soferi&action=create&driver_id={driverId}` | document missing |

The document shown in a row is matched by **case-insensitive, trimmed type name** (`mb_strtolower(trim())`) against `$driverDocuments` (`show.php:69–85`, 170–173).

### 3.3 Generic detail table (`show.php:543–565`, fields from `config/modules.php` `soferi.detail_fields`)

Fields with `store === false` are skipped (none of the detail fields have it).

| UI label | Column / source | Data type | Rendering | Editable (via Edit page) | Validation / rules (Edit form) |
|---|---|---|---|---|---|
| Poza sofer | `soferi.poza_original` + `poza_stocata` | file ref | `driver_image_preview_html` → `<img>` from `uploads/soferi/{poza_stocata}` + "open" link; or "Nu există poză încărcată pentru acest șofer." | Yes (`poza_upload`, remove via `sterge_poza`) | jpg/jpeg/png/webp, max 5 MB (`max_size` 5242880), stored by `storeUploadedAssetFile()` as `sofer_YmdHis_{16 hex}.{ext}` |
| Nume | `soferi.nume` | varchar(100) | text | Yes | required, maxlength 100 |
| Data nasterii | `soferi.data_nasterii` | date | `format_date_ro` or `-` | Yes | optional, nullable |
| Telefon | `soferi.telefon` | varchar(20) | text | Yes | required, maxlength 20 |
| Vehicule alocate | computed `vehicul_label` (join on `soferi_vehicule` + `vehicule` + latest active `vehicule_cuplaje`) | string, comma-separated | e.g. `B-01-ABC + B-02-SEM, B-03-XYZ`; `-` if none | Yes via `vehicle_ids` multiselect (`store=false`) | Only `vehicule.status='activ'` and `tip_vehicul NOT IN (semiremorca, semiremorca_primar, semiremorca_distributie)`; error "Selectia contine vehicule inactive sau neeligibile pentru alocare la sofer." |
| Status | `soferi.status` (**stored DB value**) | enum activ/inactiv | `status_badge_html` (+ assembly/uncoupled badges, empty for drivers) | **No** (removed from form, `index.php:318`) | Computed (§4) |
| Observații | `soferi.observatii` | text | `format_value_html` type `textarea` | Yes | optional, nullable |
| Creat la | `soferi.created_at` | datetime | `format_datetime_ro` | No | set on insert |
| Actualizat la | `soferi.updated_at` | datetime | `format_datetime_ro` | No | set on insert/update; **also bumped by `EntityStatusService::updateStatus()` whenever the computed status changes** |

Order of vehicles in "Vehicule alocate": `is_primary DESC, nr_inmatriculare ASC`.

### 3.4 Columns that exist in `soferi` but are NOT displayed on this page

`data_angajare`, `data_incetare`, `employment_status`, `termination_*`, `last_working_day`, `rehire_eligible`, `terminated_by`, `terminated_at`, `salariu`, `vehicle_id` (raw), `permis_expira_la` (legacy; set to `9999-12-31` on create, `ModuleController.php:1443`), `tip_colaborare`, `regim_lucru`, `regim_lucru_detalii`. These are managed elsewhere (Contabilitate Personal / `StaffAccountancyController`, Fosti angajati). `tip_colaborare` silently changes the status logic (§4.2) but is not visible on the page.

### 3.5 "Documente asociate șoferului" card (`show.php:1768–1866`)

Header: title, subtitle, button **"Adaugă document"** → `?page=documente_soferi&action=create&driver_id={id}` (always shown).

Empty state: "Nu există documente asociate acestui șofer."

| Column | Source | Rendering |
|---|---|---|
| Tip document | `tip_document` + custom field rows (checkbox → badge when checked; date → `format_date_ro`; others raw; empty values hidden) | |
| Serie / număr | `numar_document` or `-` | |
| Fișier | `document_file_link_html()` | direct public link, opens in new tab, label = original filename |
| Expirare | `expiry_badge_html(date)`; date = `data_expirare`, or — if empty — the **earliest non-empty custom `date` field** (label shown under the badge) | `Fără expirare` (grey) / `Expirat: d.m.Y` (red) / `Expiră în N zile` ≤7 (red), ≤30 (yellow) / `Valabil până la` (green) |
| Acțiuni | "Vezi în aplicație" (only if `fisier_stocat`), "Detalii", "Editează" | links to `documente_soferi` |

Row order: `data_expirare IS NULL ASC, data_expirare ASC, id DESC` (`DocumentModel::getDocumentsForDriver`). No sorting, searching, filtering or pagination on this card.

---

## 4. Automatic Status Calculation

### 4.1 Entry points (`services/EntityStatusService.php`)

| Method | Line | Behaviour |
|---|---|---|
| `evaluateDriverStatus(int $driverId): ?array` | 85 | Pure computation. Returns `['status' => 'activ'|'inactiv', 'checks' => [...], 'issues' => [...]]` or `null` if driver missing. |
| `syncDriverStatus(int $driverId): ?array` | 23 | Evaluate + `updateStatus('soferi', …)`. |
| `syncAllDriverStatuses(): void` | 78 | Loops **all** `soferi` ids (including terminated). |
| `updateStatus()` (private) | ~733 | `UPDATE soferi SET status=:new, updated_at=now WHERE id=:id AND status <> :new` — writes only on change; bumps `updated_at`. |

### 4.2 Decision order in `evaluateDriverStatus`

1. Driver does not exist → `null` (panel not rendered).
2. **Terminated** (`isTerminatedDriver`, `employment_status = 'terminated'`, only if the column exists) → `inactiv` with one synthetic check `{label: 'Colaborare incheiata', state: 'other'}`. (In practice the show page redirects terminated drivers before this, §4.7.)
3. **Collaborator** (`isCollaboratorDriver`, `tip_colaborare = 'colaborator'`, only if the column exists) → `buildEvaluation([])` → **always `activ`, zero checks**. Comment in code: collaborators' documents are held by their employer. On the page this means the panel shows "Activ" and **no toolbar/groups** (rendered only when `$driverStatusRows !== []`).
4. Otherwise → `buildDocumentChecks('documente_soferi', 'driver_id', $id, getRequiredDriverDocumentTypes($id))` → `buildEvaluation($checks)`.

`buildEvaluation`: status = `activ` iff **no check has `state !== 'valid'`**; `issues` = messages of non-valid checks.

### 4.3 Required document types

`getRequiredDriverDocumentTypes()` (line 185): **every row** of `configurare_documente_obligatorii_soferi` (ordered by `document_type`). There is no per-driver or per-category filter (the `$driverId` argument is only checked `> 0`). Fallback if the query throws: constant `DRIVER_REQUIRED_DOCUMENT_TYPES` (UNVERIFIED whether defined).

Live configuration at audit time (8 types): BULETIN (C.I.) [expiry, custom fields], PERMIS [no expiry flag, custom fields], MEDICINA MUNCII, AVIZ MEDICAL, AVIZ PSIHOLOGIC, ADR, CARTELA CONDUCATOR AUTO, CERTIFICAT COMPETENTA PROFESIONALA [all with expiry]. These values are configuration, not code — a redesign must not hardcode them.

These types are managed by admin-only endpoints on `configurare_costuri_documente_soferi` (`add_/manage_/update_…expiry/delete_driver_document_type_config`, `add_/delete_driver_document_custom_field_config`); each of these calls `syncAllDriverStatuses()` (`ModuleController.php` ~4837, 4926, 4958, 5045, 5107).

### 4.4 Per-type check (`buildDocumentChecks`, line ~271)

Documents are loaded by `getLatestDocumentsByType()`: `SELECT tip_document, data_expirare, custom_fields_json FROM documente_soferi WHERE driver_id=? ORDER BY data_expirare DESC, id DESC`, keyed by `mb_strtolower(trim(tip_document))`, first row per key wins. (Given the UNIQUE (`driver_id`,`tip_document`) constraint with a case-insensitive collation, there is effectively one document per type.)

For each required type:

| # | Condition | Resulting check |
|---|---|---|
| 1 | No document of that type | `state: 'missing'`, message "Lipseste documentul obligatoriu: X." |
| 2 | `requires_expiry = 0` for the type **and** custom-field expiry applies (`buildDriverDocumentCustomExpiryCheck` returns non-null) | that check (see 4.4.1) |
| 3 | `data_expirare` empty **and** `requires_expiry = 0` | `valid`, "X nu necesita data de expirare.", `date: null` |
| 4 | `data_expirare` set and `>= today` (`isDateValid`) | `valid`, date = expiry |
| 5 | anything else (expired, or empty while `requires_expiry = 1`, or unparsable) | `expired`, date = expiry |

`documentRequiresExpiry()` for drivers reads `configurare_documente_obligatorii_soferi.requires_expiry` by exact `document_type`; missing row or DB error → treated as **requires expiry**.

Note on rule 5: a required-expiry document saved with no date is classified `expired` (not `missing`). The UI then shows state `EXPIRAT` together with the date label "Fara expirare" (the default branch of `$statusCheckDateLabel`). This contradictory label is existing behaviour for an edge case that the form normally prevents (it requires `data_expirare` when `requires_expiry = 1`).

#### 4.4.1 Custom-field expiry (`buildDriverDocumentCustomExpiryCheck`, line 396)

Only for driver documents whose type has `requires_expiry = 0` and custom-field config (`custom_fields_json` on the type) that contains at least one `date` field:

- Field configs: `{key, label, type ∈ text|number|date|checkbox, show_when_checked}`. Values are read from `documente_soferi.custom_fields_json` (snapshot `{key: {key,label,type,value}}`).
- A `date` field with `show_when_checked = <checkboxKey>` is active only if that checkbox is checked (`1/true/on/yes/da`); its label becomes the checkbox's label (e.g. a permit category).
- No active date fields: if there were conditional date fields → `missing` ("selecteaza cel putin o categorie si completeaza data ei de expirare"); otherwise → `null` (fall back to rules 3–5).
- Any active date empty → `missing` ("lipseste data de expirare pentru {label}").
- Any active date unparsable → `expired` ("data … este invalida"). Parse formats: `Y-m-d`, `d/m/Y`, `d.m.Y`, then free-form.
- Any active date `< today` → `expired`.
- All valid → `valid`, `date` = **earliest** active date, message names the closest category.

This is how e.g. a driving licence with per-category expiry dates is validated.

### 4.5 Severity levels and counters (UI layer, `show.php:87–222`)

Service states: `missing`, `expired`, `valid`, `other` (terminated only).

UI states (`$statusCheckUiState`):

| UI state | From | Label | CSS class | Icon | Counts as inactive reason? | In "problems" group? |
|---|---|---|---|---|---|---|
| `missing` | service `missing` | LIPSA | `is-missing` | `bi-file-earmark-x` | Yes | Yes |
| `expired` | service `expired` | EXPIRAT | `is-expired` | `bi-calendar-x` | Yes | Yes |
| `warning` | any other non-valid (e.g. `other`) | ATENTIE | `is-warning` | `bi-exclamation-triangle` | Yes | Yes |
| `soon` | service `valid` with date 0–30 days ahead | EXPIRA CURAND | `is-warning` | `bi-exclamation-triangle` | **No** | **Yes** |
| `valid` | service `valid`, no date or > 30 days | OK | `is-valid` | `bi-check-circle` | No | No |

- "N probleme" in the title and the pills = **inactive reasons** only (service state ≠ valid).
- "Documente cu probleme · N" = inactive reasons **plus** "expiră curând" rows. So the two counters can legitimately differ; a driver can be **Activ** with a non-empty "problems" group.
- `$driverStatusSummary()` text: single-state → "N document(e) expirat(e) / lipsă / expiră curând / cu atenție"; mixed → "N documente cu probleme".
- The 30-day "soon" threshold is UI-only (it does not affect status). The documents card uses `expiry_badge_html` with thresholds 7 (red) / 30 (yellow) days — the two blocks use different visual scales.
- Unused leftovers in `show.php:33–65`: `$statusIssueChecks`, `$statusValidChecks`, `$orderedStatusChecks`, `$statusCheckMeta` are computed but not rendered (dead code; harmless).

### 4.6 Relationship between calculated status and the driver record

- Stored value: `soferi.status` (enum). Read by list filters, dashboards and many modules (Dispecer, payroll, equipment, notifications, WhatsApp, etc. — `grep "status = 'activ'"` hits ~17 models/services).
- **Show page:** computes `evaluateDriverStatus()` only — **it does not write**. The panel shows the fresh evaluation; the "Status" row in the detail table shows the **stored** `soferi.status`. If a document expired since the last sync, the two can disagree on this page until another trigger runs. (Verified 0 mismatches at audit time; the divergence is time-dependent.)
- No scheduled/cron recalculation was found (`grep syncAllDriverStatuses|syncDriverStatus` across `htdocs/` and `scripts/` returns only `ModuleController`). UNVERIFIED for `worker.py`/external cron (no reference found).

### 4.7 Recalculation triggers (persisting)

| Trigger | Code | Scope |
|---|---|---|
| Open drivers list (`page=soferi`, not `live=1`) | `indexAction` → `syncAllDriverStatuses()` (ModuleController ~240) | all drivers |
| Open driver edit form | `editAction` → `syncDriverStatus($id)` (~1560) | one driver |
| Create / update / delete of a driver | `syncStatusesAfterMutation('soferi', …)` (7459; called ~1487, ~1950, ~5324) | one driver |
| Create / update / delete of a driver document | `syncStatusesAfterMutation('documente_soferi', …)` | old and new `driver_id` |
| Create / update / delete of a driver document cost row | `syncStatusesAfterMutation('configurare_costuri_documente_soferi', …)` | old and new `driver_id` |
| Mandatory-type configuration changes | `syncAllDriverStatuses()` (~4837, 4926, 4958, 5045, 5107) | all drivers |
| Dashboard / list "inactive drivers" overview | `buildInactiveDriverOverview()` → `evaluateDriverStatus()` per inactive driver (~613) | read-only |

Former-driver guard: `showAction`, `editAction`, `deleteAction` call `isFormerDriverRecord()` (`employment_status='terminated'` OR `data_incetare` OR `termination_date` set) and redirect to `?page=fosti_angajati&q={name}` with flash "Soferul are colaborarea incheiata si este gestionat in Fosti angajati." The list query also excludes them (`base_conditions`).

### 4.8 Filters "Doar problemele" / "Toate documentele"

Pure client-side (inline JS, §7.2). Sets `data-filter="problems"|"all"` on `[data-status-doc-groups]`; CSS hides the "valid" group when `data-filter="problems"`. Default = `problems`. No URL/state persistence.

### 4.9 Expandable rows and their actions

- Clicking a row toggles `.is-expanded` and its `[data-status-doc-detail]`. **Accordion behaviour:** opening one row closes any other open row in the same board.
- Group headers toggle `.is-open` (problems group open by default, valid closed).
- Heights animated through CSS custom properties `--status-group-body-height` and `--status-doc-detail-height`, recalculated on toggle and `resize`.
- Actions inside rows: see §3.2 (preview / detail / edit-replace / add). All are links to `documente_soferi`.

---

## 5. Document Management

### 5.1 What lives on this page vs. elsewhere

The Detalii Șofer page only **lists and links**. All document create/edit/delete/preview logic belongs to the `documente_soferi` module (same `ModuleController`).

| Capability | Implemented? | Where |
|---|---|---|
| List all documents of the driver | Yes | documents card + status board |
| Add document (pre-selected driver) | Yes (link) | `documente_soferi&action=create&driver_id=` |
| Edit / replace document | Yes (link) | `documente_soferi&action=edit` |
| Document detail page | Yes (link) | `documente_soferi&action=show` (custom-field table + preview section) |
| In-app preview | Yes (link, only if file stored) | `documente_soferi&action=preview` → `previewDocumentAction` → `views/module/document_preview.php` (`document_preview_html`: PDF iframe, images inline; types pdf/jpg/jpeg/png/webp/gif) |
| Direct file download/open | Yes | `document_file_link_html` → `uploads/documente/{fisier_stocat}` (new tab) |
| Delete document | **Not on this page** | `documente_soferi&action=delete` (POST + CSRF) from the documents module |
| Sorting / searching / filtering / pagination on this page | **Absent** | (the `documente_soferi` list page has search + filters `driver_id`, `vehicle_id`, `tip_document`, `data_start`, `data_end`, `stare_expirare`) |
| Document audit log display | **Absent** for driver docs | only `documente` (vehicle) show page renders `documentAuditLogs` |
| `data_emitere` (issue date) | Column exists, **not in the form, not displayed** | — |
| `observatii` of a document | Not shown on this page | shown on `documente_soferi&action=show` |

### 5.2 Document categories / types

- Allowed types = rows of `configurare_documente_obligatorii_soferi` (`DocumentModel::getRequiredDriverDocumentTypeOptions`). `validateDriverDocumentTypeSelection()` (ModuleController:5991) rejects: types not in that list ("Tipul de document selectat nu este permis…"), empty config ("Nu exista tipuri de document configurate pentru soferi."), duplicates per driver ("Acest tip de document este deja adaugat pentru soferul selectat. Foloseste Editare pentru actualizare.").
- Consequence: **there are no optional driver document types** — every type a driver can have is mandatory for status.
- No category hierarchy for driver documents (flat list of type names).

### 5.3 Series / number, dates, custom fields

- `numar_document`: optional, maxlength 100, label overridden to "Serie / număr document (opțional)" (`index.php:70–74`, re-overridden ASCII at 111–116).
- `data_expirare`: required by default; made optional and blanked when the type's `requires_expiry = 0` (ModuleController ~5930).
- Custom fields per type (`custom_fields_json` on config, values snapshot in `documente_soferi.custom_fields_json`), types text/number/date/checkbox, conditional dates (`show_when_checked`). Validated by `validateDriverDocumentCustomFieldValues()`. Displayed via `buildDriverDocumentCustomFieldDisplayRows()`.

### 5.4 Files

- Upload field `fisier_upload` (`index.php:76–89`): `.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx`, max 5 MB, remove flag `sterge_fisier`.
- Storage: `storeUploadedDocumentFile()` → `storeUploadedAssetFile($file, 'documente', 'document', …)` → `BASE_PATH/uploads/documente/document_{YmdHis}_{16 hex}.{ext}`; original sanitized name in `fisier_original`. Same folder as vehicle documents.
- Physical delete helpers: `deletePhotoPhysicalFileForModule`, document file cleanup at ~6746.
- Access: files are served **statically** by Apache from `uploads/`; `uploads/.htaccess` only disables indexes and PHP execution. Anyone with the URL can fetch the file without authentication (names are random, not guessable — but they are not access-controlled). This is current behaviour, flagged in §12.

### 5.5 Expiry indicators

Two different systems on the same page (both must be preserved or deliberately unified):

1. Status board: service state + UI "soon" ≤ 30 days.
2. Documents card: `expiry_badge_html` (expired / ≤7 red / ≤30 yellow / valid green / "Fără expirare"), with fallback to the earliest custom date field.

Note: for custom-date documents the board uses the **earliest active (checked-category) date**, while the card uses the **earliest non-empty date field regardless of checkbox state**. They can differ.

### 5.6 Permissions (documents)

See §9; document actions are authorized by `documente_soferi` permissions when the link target is requested.

---

## 6. Vehicle Associations

| Question | Verified answer | Evidence |
|---|---|---|
| Can one driver have several vehicles? | **Yes.** Many-to-many; 12 drivers currently have >1. | `soferi_vehicule` PK (`driver_id`,`vehicle_id`); form field `vehicle_ids` multiselect |
| Can one vehicle have several drivers? | Yes (no uniqueness on `vehicle_id`). | schema |
| Primary vehicle | First selected id → `is_primary = 1`; also mirrored to legacy `soferi.vehicle_id`. | `syncDriverVehicleAssignments` (ModuleModel:565), `prepareDriverVehicleAssignmentsData` (`$data['vehicle_id'] = $vehicleIds[0] ?? null`) |
| Historical records? | **No.** Each save does `DELETE FROM soferi_vehicule WHERE driver_id=?` then re-inserts; `created_at` is reset. No history table. | ModuleModel:582 |
| How current assignments are read on this page | `soferi.select` join subquery → `vehicul_label` (registration + current coupled trailer from latest active `vehicule_cuplaje`) and `vehicul_model`; order `is_primary DESC, nr_inmatriculare ASC`. | `config/modules.php:328–352` |
| Eligibility rules | Only active, non-trailer vehicles. Checked in the form source (`where`) **and** server side (`findInvalidDriverAssignmentVehicleIds`). | ModuleModel:521 |
| Modifiable from this page? | **No.** Only through "Editează" (`soferi&action=edit`, permission `soferi.edit` — labelled "Editare șofer (+ alocare vehicule)"). | — |
| Legacy fallback | `getDriverVehicleIds()` returns `[soferi.vehicle_id]` if no `soferi_vehicule` rows; `ensureDriverVehicleAssignmentsSchema()` back-fills `soferi_vehicule` from `soferi.vehicle_id` (INSERT IGNORE, once per request). | BaseModel:71, ModuleModel:418 |
| Atomicity | Driver insert/update and `syncDriverVehicleAssignments` are **not wrapped in a transaction** (no `beginTransaction` around ModuleController ~1452–1456 / ~1904). | — |

Cross-module consumers of `soferi_vehicule` (verified by grep; behaviour inside them not audited): `models/DispecerCurseModel.php`, `DashboardModel.php`, `DriverActivityHistoryModel.php`, `DriverWhatsAppModel.php`, `OperationalCostModel.php`, `ProgramareConcediiModel.php`, `StaffAccountancyModel.php`, `services/DriverFirstMovementService.php`, `OperationalProfitabilityService.php`, `ProfitabilityRegisterService.php`, `scripts/test_rentabilitate.php`. `ModuleModel::isDriverAssignedToVehicle()` checks both `soferi.vehicle_id` and `soferi_vehicule` (and optionally `s.status='activ'`) — used for driver/vehicle consistency checks elsewhere (UNVERIFIED which callers).

The `documente_soferi` module's "Vehicul alocat" column uses **only the legacy `soferi.vehicle_id`** (join `v.id = s.vehicle_id`), not `soferi_vehicule`.

---

## 7. UI and JavaScript Interactions

### 7.1 Layout structure (DOM order)

```
<main> (layout: views/layout/header.php / footer.php — sidebar, topbar, flash messages)
 ├─ div.d-flex.justify-content-between.mb-3      header: h2 + [Editează] [Înapoi]
 ├─ div.entity-status-panel.entity-status-panel-driver.is-active|is-inactive.mb-3  [data-status-document-board]
 │   ├─ .entity-status-header (.entity-status-kicker, .entity-status-title, .entity-status-badge)
 │   ├─ p.entity-status-copy
 │   ├─ .entity-status-problem-callout (.entity-status-problem-head/-title/-summary, .entity-status-problem-pills > span.is-*)
 │   ├─ .entity-status-doc-toolbar[role=group] > button[data-status-doc-filter=problems|all]
 │   └─ .entity-status-doc-groups[data-status-doc-groups][data-filter]
 │        └─ section.entity-status-doc-group[data-status-doc-group=problems|valid](.is-open)
 │             ├─ button.entity-status-doc-group-toggle[data-status-doc-group-toggle]
 │             └─ .entity-status-doc-group-body[data-status-doc-group-body]
 │                  └─ .entity-status-doc-item.is-*[data-status-doc-item]
 │                       ├─ button.entity-status-doc-row[data-status-doc-row][aria-controls]
 │                       │    (.entity-status-doc-icon/-name/-state/-date/-chevron)
 │                       └─ .entity-status-doc-detail[data-status-doc-detail][hidden]
 │                            (.entity-status-doc-detail-grid, .entity-status-doc-custom(-chip|-pair), .entity-status-doc-actions)
 ├─ <script> inline IIFE (status board)
 ├─ div.card.border-0.shadow-sm > .card-body.p-0 > .table-responsive > table.table   (detail fields, th.w-25.bg-light)
 └─ div.card.border-0.shadow-sm.mt-4  (Documente asociate șoferului)
      ├─ .card-header (title, subtitle, [Adaugă document])
      └─ .card-body.p-0 > .table-responsive > table.table.table-hover
```

No tabs, no modals, no Bootstrap JS components on this page. Alerts/notifications = global flash messages from the layout (e.g. after redirects).

### 7.2 Inline JS (`show.php:404–540`) — selectors that MUST keep existing if the script is kept

`[data-status-document-board]`, `[data-status-doc-groups]` (+ attribute `data-filter`), `[data-status-doc-filter]`, `[data-status-doc-group]`, `[data-status-doc-group-toggle]`, `[data-status-doc-group-body]`, `[data-status-doc-item]`, `[data-status-doc-row]`, `[data-status-doc-detail]`; classes `is-open`, `is-expanded`, `is-active`; CSS vars `--status-group-body-height`, `--status-doc-detail-height`; `aria-expanded`, `aria-pressed`, `hidden`. Close animation relies on a 190 ms timeout matching the CSS transition.

Global scripts loaded on every page (footer): Bootstrap bundle, `assets/js/app.js` (contains a global anti-double-submit guard — relevant only if forms are added), `approval-live.js`, seasonal `xmas-*.js`. Bootstrap is loaded in the footer **after** inline view scripts — inline code must not depend on `bootstrap` at parse time.

### 7.3 Responsive behaviour

- Bootstrap grid/utilities; tables wrapped in `.table-responsive` (horizontal scroll on small screens).
- `.entity-status-*` mobile overrides at `@media (max-width: 767.98px)` (`style.css:3925+`): badge, problem head/summary, toolbar, row/icon/name/state/date/chevron sizing.
- Header buttons use `d-flex gap-2`; no explicit mobile stacking rules for the header.

### 7.4 What breaks if the DOM changes

- Status board interactions (filter, groups, accordion) — tied to the data attributes above.
- CSS hiding rule `.entity-status-doc-groups[data-filter="problems"] [data-status-doc-group="valid"]` (`style.css:3644`).
- Links from other pages target only the URL (`?page=soferi&action=show&id=`), not anchors or DOM ids — no deep-link anchors exist. Inbound links: `views/dashboard/_inactive_face.php:58,114`, `views/dashboard/_operational_card.php:590`, `views/module/document_preview.php:34`, `views/module/list.php:325`, `buildModuleBackUrl` for `documente_soferi` (ModuleController:7031).
- No automated UI tests reference this DOM (UNVERIFIED beyond grep of `scripts/`).

---

## 8. Backend and Database Dependencies

### 8.1 Queries executed for one page view (approx.)

1. Access checks (`AccessRightsModel`, cached per request).
2. `ModuleModel::findById` — `SELECT t.*, vehicul_label, vehicul_model FROM soferi t LEFT JOIN (aggregated soferi_vehicule/vehicule/vehicule_cuplaje) …`.
3. `DocumentModel::getDocumentsForDriver` — documents + `soferi` + legacy `vehicule`.
4. Per document: custom-field config lookup in `buildDriverDocumentCustomFieldDisplayRows` (UNVERIFIED whether cached).
5. `evaluateDriverStatus`: existence check, `information_schema` column checks (`employment_status`, `tip_colaborare`), required types, latest docs by type, and **per required type** a `requires_expiry` query plus (when no expiry) a custom-field config query. ≈ 2 × N small queries for N mandatory types.

### 8.2 Write side effects of visiting the page

- `showAction` itself writes **nothing** to `soferi` status.
- `ensureDriverVehicleAssignmentsSchema()` may run `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE … FROM soferi` if any code path in the request calls it (not called by `findById`; UNVERIFIED for helpers during this request).

### 8.3 Upstream dependencies

`configurare_documente_obligatorii_soferi` (types, `requires_expiry`, custom fields), `documente_soferi`, `soferi`, `soferi_vehicule`, `vehicule`, `vehicule_cuplaje`.

### 8.4 Downstream consumers of the driver record / status

Dispecer curse (driver selection/approval of inactive resources — `InactiveResourceApprovalController` supports resource type `driver`), dashboard inactive panels, payroll/Contabilitate Personal, equipment (Echipamente șoferi), notifications rules, WhatsApp bot, leave scheduling, maintenance, tire, operational cost/profitability. All read `soferi.status` (stored) — not the evaluated value.

---

## 9. Permissions and Validation

### 9.1 Authentication / authorization

- `require_auth()` in the router (`index.php`, `case 'soferi'`).
- Central guard `require_route_access('soferi', 'show')` (`includes/access.php:249`, invoked at `index.php:577`): requires `can('soferi','view')`. `show` is not in `endpoints`, so `view` suffices. Admin bypasses everything. Unconfigured users inherit role defaults (`scope: 'all'`).
- `permissions/modules/soferi.php` actions: `view`, `create`, `edit` ("+ alocare vehicule"), `delete`, `end_employment` (sensitive, default for accountancy), `export`. Endpoints map `create/store→create`, `edit/update→edit`, `delete`, `export`, `end_employment`.
- `permissions/modules/documente_soferi.php` actions: `view`, `create`, `edit`, `delete`, `manage_types` (admin_only). `preview`/`show` require only `view`.
- **The view does not call `can()`** — "Editează", "Adaugă document", "Editeaza / inlocuieste" are shown to everybody who can view; unauthorized clicks end in the 403 page (`access_deny_403`).
- Static files in `uploads/` bypass all of the above.

### 9.2 Validation (relevant to data shown here; enforced on the Edit/Create screens)

| Field | Rule | Location |
|---|---|---|
| `nume` | required, ≤100 chars | module config + generic `validateInput` (~6148, 6355) |
| `telefon` | required, ≤20 chars (no format check) | same |
| `data_nasterii` | optional date | same |
| `vehicle_ids` | integers only; active, non-trailer vehicles | `normalizeDriverVehicleIdsFromInput`, `findInvalidDriverAssignmentVehicleIds` |
| `poza_upload` | jpg/jpeg/png/webp, ≤5 MB | generic file validation (~6172–6179) |
| `status` | not accepted from input (field removed) | `index.php:318` |
| Driver document type | must be configured; unique per driver | `validateDriverDocumentTypeSelection` |
| Driver document expiry | required unless type `requires_expiry = 0` | ~5920–5933 |
| Driver document custom fields | per config | `validateDriverDocumentCustomFieldValues` |
| Driver document file | pdf/jpg/jpeg/png/webp/doc/docx, ≤5 MB | `index.php:76–89` |
| Delete (driver/doc) | POST + CSRF (`ensure_csrf_or_redirect`) | `deleteAction` |

---

## 10. Business Rules That Must Be Preserved

1. Driver status is **computed**, never manually set; no status input may be reintroduced.
2. Status = `activ` iff every configured mandatory driver document type has a valid document (rules in §4.4, including custom-field expiry and `requires_expiry = 0`).
3. Collaborators (`tip_colaborare = 'colaborator'`) are always `activ` with no document checks.
4. Terminated drivers are `inactiv` and their detail page redirects to `fosti_angajati`.
5. Date validity: expiry date **today counts as valid** (`>= today`).
6. "Expiră curând" (≤30 days) is a visual warning only and must **not** make a driver inactive.
7. Inactive-reason counter counts only service-level problems; the "problems" group additionally contains "soon" rows.
8. Status must be persisted on every mutation that can change it (driver, document, document cost row, type config) and on list/edit page loads — redesigned flows must keep calling `syncStatusesAfterMutation` / `syncDriverStatus` / `syncAllDriverStatuses` where they are called today.
9. One document per (driver, type); only configured types are allowed.
10. Expiry display fallback: when `data_expirare` is empty, show the earliest custom date field with its label.
11. Vehicles: multi-assignment, primary = first selected, mirrored to `soferi.vehicle_id`; only active non-trailer vehicles; trailer shown next to the tractor via the current active coupling.
12. File links: "Vezi în aplicație" only when a stored file exists; direct link opens the original filename in a new tab.
13. Back navigation from document create/edit/show/preview returns to `soferi&action=show&id={driver_id}` (`buildModuleBackUrl` for `documente_soferi`).
14. The `?page=soferi&action=show&id=` URL contract must remain (five inbound link sites, §7.4).

---

## 11. Reusable Components from Detalii Vehicul

`views/module/_vehicle_detail.php` (+ `assets/css/vehicle-detail.css` 827 lines, `assets/js/vehicle-detail.js` 521 lines) is the recently redesigned vehicle page. Its header comment states it is presentation-only and does not recalculate business rules — the same contract a driver redesign should follow.

| Component | Vehicle implementation | Reuse safety for driver page |
|---|---|---|
| Overview hero (`section.vd-overview`, photo, KPIs `.vd-kpi`, meta items) | `_vehicle_detail.php:237–300` | **Safe structurally**; driver needs its own fields (photo, phone, vehicles, status). |
| Collapsible section card (`.vd-card.vd-section`, `[data-vd-section]`, `[data-vd-toggle]`, `.vd-chevron`, `.vd-collapse`) | 302–312, 465–497 | **Safe**; JS is generic, but… |
| Document chips for mandatory docs (`.vd-chip`, `+N` more, `data-vd-chip-search`) driven by `EntityStatusService` checks | ~70–110, 480–492 | **Good fit** — consumes the same `checks` structure (`label/state/message/date`). |
| Live document search + count + empty state + ~3-row scroll (`[data-vd-doc-search]`, `[data-vd-doc-row][data-search]`, `[data-vd-doc-count]`, `[data-vd-doc-empty]`) | 500–595 | **Good fit**, client-side only. |
| Documents expiry fallback logic (custom date fields) | 33–68 | Identical rule to the driver card — reusable as-is. |
| Category carousel / tabs (`[data-vd-carousel]`, `[data-vd-tab]`, `[data-vd-panel]`) | 315–345 | Optional; driver has few fields. |
| Coupling forms (`[data-vd-coupling-select]`, cupleaza/decupleaza POSTs) | 355–455 | **Not applicable** (vehicle-only). |

**Caveats for reuse (confirmed):**

- `vehicle-detail.js` exits unless `[data-vehicle-detail]` exists and reads `data-vehicle-id`; it stores UI state in `sessionStorage` keyed by vehicle id. Reusing it as-is for drivers requires either a shared root attribute or a separate script — i.e. a code change; it cannot be dropped in unmodified without impersonating the vehicle root.
- `vehicle-detail.css` is scoped under `.vd-*`; classes are generic in shape but named for the vehicle page. Loading it on the driver page is technically safe (no global selectors verified by name pattern; UNVERIFIED that no rule targets elements outside `.vd-page`).
- The vehicle page does not show the expandable "Doar problemele / Toate documentele" board — the driver board's behaviour would have to be preserved separately or re-implemented in the new design.

---

## 12. Refactor Risks and Recommendations

### 12.1 Confirmed risks (verified in code)

| # | Risk | Impact | Evidence |
|---|---|---|---|
| C1 | `show.php` is shared by ~8 modules; driver blocks are interleaved with vehicle/document/maintenance blocks. | Editing it in place can break other modules' show pages. | `show.php` `if ($moduleKey === …)` branches |
| C2 | Show page displays two status sources: evaluated (panel) vs stored (`soferi.status` in table). Show page never persists. | Contradictory "Activ"/"Inactiv" on one page after an overnight expiry until a sync trigger runs; downstream modules use the stored value. | `showAction` uses `evaluateDriverStatus`; no cron found |
| C3 | Inline JS depends on the exact data-attribute contract (§7.2). | Changing markup silently disables filters/accordions. | `show.php:404–540` |
| C4 | Vehicle assignments are replace-all without history or transaction. | A failure between `UPDATE soferi` and `syncDriverVehicleAssignments` leaves partial state; no audit of past assignments exists to "preserve" in a redesign. | ModuleModel:582; no `beginTransaction` |
| C5 | Legacy `soferi.vehicle_id` must stay in sync with the first assigned vehicle; `ensureDriverVehicleAssignmentsSchema` re-inserts it into `soferi_vehicule` with `INSERT IGNORE`. | If a redesign writes `soferi_vehicule` without updating `soferi.vehicle_id`, a removed vehicle can be resurrected by the back-fill. | BaseModel:94–99 |
| C6 | Uploaded driver documents (ID card, licence, medical) are publicly reachable by URL. | Data-protection exposure if links leak. Out of scope for a UI redesign but must not be made worse (e.g. by exposing stored filenames more widely). | `uploads/.htaccess`, `document_file_url` |
| C7 | Buttons are not permission-aware in the view. | Users see actions that end in 403. | `show.php` (no `can()` calls) |
| C8 | Two expiry scales coexist (board: 30-day soon; card: 7/30 badges) and two custom-date rules (board: active categories only; card: earliest of any date field). | A unified redesign may unintentionally change what is flagged. | §4.5, §5.5 |
| C9 | Collaborators render an "Activ" panel with no document list in the board. | A redesign that assumes `checks` is non-empty can crash or show misleading empty states. | `evaluateDriverStatus` → `buildEvaluation([])` |
| C10 | Status evaluation issues ~2×N queries + `information_schema` lookups per view. | Adding the evaluation to more widgets/pages multiplies cost. | §8.1 |
| C11 | `documente_soferi` "Vehicul alocat" uses legacy `soferi.vehicle_id` only. | Inconsistent with "Vehicule alocate" on this page for multi-vehicle drivers. | `config/modules.php:760–763` |
| C12 | Live DB lacks index `idx_soferi_employment_status` present in baseline DDL. | Schema drift (not caused by this page). | live introspection vs `database/database.sql` |

### 12.2 Potential risks (plausible, not proven)

| # | Risk |
|---|---|
| P1 | Board row ↔ document matching uses `mb_strtolower(trim())` while the service keys documents the same way but orders `data_expirare DESC` and the view orders `ASC`. With the current UNIQUE constraint there is one document per type so they agree; if the constraint were ever dropped, the board could show a different document than the one that determined the status. |
| P2 | Type names with diacritics vs configuration names (`normalizeStatusDocumentTypeKey` is used only for vehicle employment-contract exclusion; driver matching is exact-lowercase). A renamed type in config would orphan existing documents → "LIPSA". |
| P3 | Global `app.js` anti-double-submit guard interacts with any new inline forms (see memory/notes); if a redesign adds forms (e.g. inline vehicle assignment), client validation must also `stopPropagation`. |
| P4 | Bootstrap JS is loaded after view scripts; new components that call `bootstrap.*` at parse time will fail. |
| P5 | Former-driver redirect happens before rendering; a redesign that loads data via a new endpoint must replicate `isFormerDriverRecord` gating. |

### 12.3 Recommendations for the redesign (proposals, not current functionality)

1. Build the driver page as its own partial (e.g. `views/module/_driver_detail.php`), mirroring how `_vehicle_detail.php` is included from `show.php`, so other modules are untouched (mitigates C1).
2. Keep the controller contract: `record`, `driverDocuments` (with `custom_field_display_rows`), `statusContext`. Keep all business logic in `EntityStatusService`; the view should only present `checks`.
3. Decide explicitly whether the show page should persist status (`syncDriverStatus`) like the vehicle show page does (`syncVehicleStatus`) — resolves C2. This is a behavioural change requiring sign-off.
4. Preserve or deliberately port the board's data-attribute contract; if replaced, port the filter default (`problems`), the accordion (single open row) and the "soon ≠ inactive" rule.
5. Gate buttons with `can('soferi','edit')`, `can('documente_soferi','create'|'edit')` (C7).
6. Handle the zero-checks case (collaborators) and the terminated case.
7. Consider surfacing hidden-but-relevant fields (`tip_colaborare`, `data_angajare`, `employment_status`) read-only, since they influence status.
8. Reuse `vd-*` patterns (sections, chips, doc search) via a shared root or a driver-specific script rather than reusing `vehicle-detail.js` unchanged.

---

## 13. Missing Information / Unverified Behavior

| Item | Status |
|---|---|
| Whether constants `DRIVER_REQUIRED_DOCUMENT_TYPES` / `VEHICLE_REQUIRED_DOCUMENT_TYPES` are defined (fallbacks on DB error) | UNVERIFIED |
| Any external scheduler (cron, `worker.py`) that recalculates driver status | UNVERIFIED — none found in `htdocs/` or `scripts/` |
| Whether any other view still renders `.entity-status-*` | Grep of `views/` and `assets/js/` finds only `show.php`; dynamic class construction elsewhere is UNVERIFIED |
| How each cross-module consumer of `soferi_vehicule` / `soferi.status` reacts to changes | Not audited (listed only) |
| Per-request caching inside `buildDriverDocumentCustomFieldDisplayRows` | UNVERIFIED |
| Whether `vehicle-detail.css` contains any selector outside `.vd-*` scope | UNVERIFIED |
| `employment_status` values other than `terminated` (`temporarily_inactive`, `suspended`, `leave`) | Verified to have **no effect** on the computed status or on page access (only `terminated` is checked) |
| Behaviour on the remote VPS (schema/config may differ) | UNVERIFIED — audit used the local Laragon DB |
| Visual appearance in a browser (screenshots) | Not captured; this audit is source-based |
| Automated tests covering the driver show page or status logic | None found by name in `scripts/` (UNVERIFIED for indirect coverage) |
