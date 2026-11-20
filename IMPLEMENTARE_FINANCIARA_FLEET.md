# IMPLEMENTATION — Financial foundation, inventory integrity and GPL investment projects

- **Date:** 2026-11-18
- **Reference:** `AUDIT_INVESTITII_VEHICULE_GPL.md`
- **Scope:** backend, schema and tests only. No UI was redesigned. Nothing was deployed and no production data was touched.
- **Databases touched:**
  - `if0_41456552_aplicatie_flota` (local development): migration applied **after** a full backup.
  - `fleet_fin_test`: a disposable copy restored from the same backup. All tests that write data run only here.

---

## 1. Summary

| Phase | Result |
|---|---|
| 1. Financial classification | `natura_cost` (operational) and `tratament_contabil` (accounting) exist end to end. `imbunatatire` is no longer lost. Mixed invoices and mixed interventions are split per nature. Only **confirmed capitalizable** investments leave the ordinary cost/km. |
| 2. Inventory integrity | Stock receipt ledger with the original cost per receipt. FIFO consumption is atomic, idempotent, and cannot go negative. All 6 installation paths use it. V2 finalize now decrements stock when the part comes from stock. Transfers keep their historical cost. |
| 3. Acquisition dossier | Tables and service for the acquisition dossier: lines, payments, transport (driven or carrier), separate events, documents, and computed outputs. |
| 4. GPL investment projects | `investitii_proiecte` on the physical vehicle, with nullable links from 5 existing sources plus line level. The total is derived from the linked sources, never typed in. GPL readiness stages are kept separate from financial completion. |
| 5. Integration contracts | `VehicleFinancialHistoryService` (deduplicated read model) and `VehicleCouplingPeriodService` (couplings per `data_start`/`data_end`). |
| 6. Tests | 130/130 new tests pass, plus 7 existing regression suites. The only failure is one pre-existing case in `test_consumabile` that is unrelated to this work: it fails the same way with these changes stashed. |

---

## 2. Database migration

**Files**
- Forward: `database/migrations/2026_11_18_000001_fundatie_financiara.sql`. This is the single source of the schema.
- Rollback: `database/migrations/2026_11_18_000001_fundatie_financiara.rollback.sql`.

**How it is applied**
- By `FinancialFoundationSchema::ensure()`, which `MaintenanceModel`'s existing `ensureSchema()` calls. This follows the project's runtime-migration convention.
- Or explicitly with `php scripts/migrate_financial_foundation.php`. Add `--check` to only list what is missing.

**How it runs**
- Each statement runs only if its object is missing. This is checked in `information_schema`.
- Data back-fills marked `-- @once:<key>` run once and are recorded in `financiar_migrari`.
- It never runs inside an open transaction, because DDL commits implicitly.
- After a complete pass it records `schema_v1_complet`. From then on, each request costs one `SELECT`.

**Strictly additive.** The migration only does `CREATE TABLE`, `ADD COLUMN`, `ADD INDEX` and `ADD CONSTRAINT`. Its `UPDATE` statements write **only new columns**. No enum was changed and no existing column was modified.

### 2.1 New tables

| Table | Purpose |
|---|---|
| `financiar_migrari` | Markers for one-time back-fills |
| `financiar_audit` | Append-only history: entity, field, old/new value, reason, user, time |
| `mentenanta_piese_receptii` | Stock receipts. Each has the original unit cost (NULL = unknown), a frozen reference price, currency/rate, VAT, supplier, invoice, OCR invoice and line, source, valuation status, remaining quantity and idempotency key |
| `mentenanta_piese_consumuri` | Which receipt or receipts each mounting consumed: quantity, cost, returned quantity |
| `achizitii_vehicule` | Acquisition dossier header (`cod_dosar`, nullable `vehicle_id`, VIN, …) |
| `achizitii_categorii_cheltuieli` | Configurable cost categories: default classification, delivery context, import only. 16 are seeded. |
| `achizitii_vehicule_cheltuieli` | Cost lines: source, classification, accounting treatment, verification status, VAT, document, `cheltuiala_id` link, `inclus_in_linie_id` |
| `achizitii_vehicule_plati` | Payments |
| `achizitii_vehicule_transport` | Delivery: driven (driver, temporary plates, insurance) or carrier (order, places, included services) |
| `achizitii_vehicule_evenimente` | Separate events: purchase, ownership transfer, pickup, arrival in RO, technical reception, registration, adaptation, commissioning |
| `achizitii_vehicule_documente` | Dossier documents. They exist before registration and can later link to `documente`. |
| `investitii_proiecte` | Investment project on the **physical** vehicle: category, nature, treatment, budget, status, responsible person, commissioning date, approved useful life (with who and when), optional acquisition link |
| `investitii_proiecte_stadii` | GPL readiness stages: works done, equipment fitted, inspection, documents, approved for activity, commissioned |

### 2.2 New columns on existing tables (all nullable or defaulted)

| Table | Columns |
|---|---|
| `mentenanta` | `natura_cost`, `natura_cost_origine` (`explicit`/`derivat`/`istoric`), `natura_cost_de_revizuit`, `natura_cost_motiv_revizuire`, `tratament_contabil`, `proiect_investitie_id` (FK), `sursa_tip`, `sursa_id` + 3 indexes |
| `mentenanta_interventii_programate` | `natura_cost`, `tratament_contabil`, `proiect_investitie_id` (FK) |
| `mentenanta_interventii_lucrari` | `natura_cost` (NULL = inherit), `proiect_investitie_id`, `sursa_piesa`, `stock_part_id`, `cantitate_piesa`, `transfer_usage_id`, `cost_piese_evaluat` |
| `ocr_reparatii` | `proiect_investitie_id` (FK) |
| `ocr_reparatii_articole` | `natura_cost` (NULL = derived from `tip_lucrare`), `proiect_investitie_id`, `mentenanta_receptie_id` |
| `cheltuieli` | `proiect_investitie_id` (FK), `natura_cost` |
| `documente` | `proiect_investitie_id` (FK). `vehicle_id` stays NOT NULL. |
| `mentenanta_piese_utilizari` | `sursa_piesa`, `receptie_id`, `valoare_neverificata`, `transfer_din_utilizare_id`, `idempotency_key` (UNIQUE) |

### 2.3 Historical back-fill (one time, flagged and not silent)

- **`mentenanta.natura_cost`**
  - Derived from `record_type`: `intretinere` → `mentenanta`, everything else → `reparatie`.
  - Origin is set to `istoric`.
  - Treatment is set to `cheltuiala`, which is exactly how these rows are treated today, so there is no effect on cost/km.
- **Rows from OCR invoices that contain `imbunatatire` lines**
  - Marked `natura_cost_de_revizuit = 1`, with a reason.
  - Treatment is reset to `de_confirmat`.
  - They are **not** declared as investments or capitalizable. A person reviews them through `FinancialClassificationService::pendingReview()`.
- **`mentenanta.sursa_tip` / `sursa_id`**
  - `interventie` from `source_intervention_id`.
  - `ocr_reparatie` from the OCR lines.
  - `anvelope` for `'Anvelopa - %'`.
- **`mentenanta_piese_utilizari.sursa_piesa`**: derived from `direct_mount`. The existing `direct_mount` value is kept.
- **Opening stock**
  - Every part with `stoc_curent > 0` gets one `stoc_initial` receipt.
  - The receipt has `cost_unitar = NULL` and status `neverificat_istoric`. The catalog price is stored only as a frozen `cost_unitar_referinta`.
  - No invoice, supplier or price is invented.
  - Locally there are 0 such parts.

### 2.4 Verification on the local development database

- `migrate_financial_foundation.php` result:
  - 68 statements applied, 0 blocked, schema complete.
  - Parts whose stock differs from their receipts: **0**.
  - Rows without a nature: **0**.
  - Running it a second time applies 0 statements (idempotent).
- **Historical values unchanged.** I compared CRC sums of the original columns against a fresh restore of the backup. They are identical for all of these tables:
  - `mentenanta`, `mentenanta_piese`, `mentenanta_piese_utilizari`, `documente`, `cheltuieli`
  - `ocr_reparatii_articole`, `mentenanta_interventii_programate`, `vehicule_cuplaje`
- **Cost/km.** The `reparatii` and `revizii` elements over the whole local history are **identical** to the old query (10 + 1 vehicles, 1,090 and 780 lei).

---

## 3. Phase 1 — Classification

### 3.1 Concepts (`services/CostNature.php`)

**Nature of cost (`natura_cost`)**
- Values: `reparatie | mentenanta | investitie | echipare_initiala`.
- Set by the operator, or derived.

**Accounting treatment (`tratament_contabil`)**
- Values: `de_confirmat | capitalizabil | cheltuiala`.
- Default for `reparatie`/`mentenanta`: `cheltuiala`.
- Default for `investitie`/`echipare_initiala`: `de_confirmat`.
- **`capitalizabil` is never inferred.**

**`record_type` is kept for compatibility**
- `mentenanta` → `intretinere`.
- Everything else → `reparatie`, which is where investments were already being written.

**OCR mapping**

| OCR `tip_lucrare` | `natura_cost` |
|---|---|
| `reparatie`, `inlocuire` | `reparatie` |
| `intretinere` | `mentenanta` |
| `imbunatatire` | `investitie` |

### 3.2 Corrected defects

| Defect (audit) | Fix |
|---|---|
| `imbunatatire` became `reparatie` during OCR sync (`OcrPartsMaintenanceSyncService`) | The nature comes from each line (explicit `natura_cost`, else derived from `tip_lucrare`). One `mentenanta` row is created per **vehicle × nature × project**, so a mixed invoice is not forced into one classification. The original `tip_lucrare` is untouched. |
| `InterventionWorkflowModel::finalize` wrote a single row typed from `tip_interventie` | Each line has a nature (NULL = the intervention's nature). One row is created per nature × project. The main row, which also carries `cost_suplimentar`, stays in `converted_maintenance_id`. A treatment confirmed on the intervention passes only to the row of the same nature. |
| `MaintenanceModel::saveRecord` / `savePart` (direct) / `convertScheduledIntervention` had no classification | They accept `natura_cost` and `proiect_investitie_id`. Otherwise the nature is derived (`derivat`). The project must belong to the same vehicle. |

### 3.3 Changing the classification (`FinancialClassificationService`)

- `setNature(entity, id, …)` and `setTreatment(entity, id, …)`.
- Supported entities: `mentenanta`, `interventie`, `proiect_investitie`, `achizitie_cheltuiala`.
- Every change is written to `financiar_audit`.

**Rules**
- `capitalizabil` is allowed only for the `investitie`/`echipare_initiala` nature, and requires a reason.
- On acquisition lines, VAT and period costs cannot be capitalized.
- Moving the nature to `reparatie`/`mentenanta` resets `capitalizabil` to `de_confirmat`, and the reset is also audited.

**Endpoint**
- `page=mentenanta&action=financial_classify` (POST JSON: `entity`, `id`, `field`, `value`, `motiv`).
- It requires the new right `mentenanta.financial_classification`: admin-default, sensitive, group "Financiar".
- The catalog was synced with `scripts/sync_permissions.php`.

### 3.4 Cost/km (`OperationalCostModel::getMaintenancePeriodCosts`, `OperationalCostService`)

**Which records go where**
- `revizii` = the effective nature is `mentenanta`.
- `reparatii` = everything else, **except** `investitie`/`echipare_initiala` with `tratament_contabil = 'capitalizabil'`.
- Investments that are unconfirmed or treated as an expense **stay** in operating cost. They are not removed silently, and their amount appears in the quality note.
- The capitalized amount is reported separately in the quality note (`exclus … lei investiții capitalizabile … amortizare necalculată`) and through the new `getCapitalizedInvestmentCosts()`.
- **No depreciation is computed**, because no per-asset value or approved useful life exists yet.
- If the schema is not present, the code falls back to the old query unchanged.
- `CostNormalizationService` needed no change. It is a pure helper and receives no maintenance input.

---

## 4. Phase 2 — Inventory integrity

### 4.1 `services/PartsInventoryService.php`: the single stock service

**Invariant**
- For a part with `mod_utilizare = 'stoc'`: `stoc_curent = SUM(receptii.cantitate_ramasa)`.
- `reconcile()` restores the invariant if legacy code changed stock elsewhere. Missing quantity is added as an `ajustare` receipt with an unknown cost. Excess quantity is trimmed FIFO with a note.

**`receive()`**
- Locks the part with `FOR UPDATE` and inserts a receipt that keeps its original cost.
- Updates `pret_achizitie` only as "the last catalog price".
- Idempotent on `idempotency_key` and on `ocr_articol_id`.

**`consume()`**
- Locks the part with `FOR UPDATE`, checks stock (**no negative stock**), and consumes receipts FIFO, locked with `FOR UPDATE`.
- Writes the mounting row plus `mentenanta_piese_consumuri` rows.
- The cost is the receipts' cost, converted to RON with the receipt's own rate.
- If a receipt has no cost, it uses the frozen reference value and marks the mounting `valoare_neverificata = 1`.
- Idempotent on `idempotency_key`.

**`recordDirect()`**
- For a direct purchase (`achizitie_directa`) or a part supplied by the workshop (`atelier_extern`).
- **Never** touches stock.

**`transfer()`**
- Closes the source mounting: `motiv_demontare = 'transfer'`, `inlocuita_de_id`.
- Creates the target mounting with the same part, quantity, **historical `cost_unitar`** and original `receptie_id`, plus `sursa_piesa = 'transfer_vehicul'` and `transfer_din_utilizare_id`.
- Creates no receipt, no stock change and no `mentenanta` cost.

**`returnToStock()` / `reverse()`**
- The quantity goes back onto the **original receipts**, at the same cost.
- A direct mounting that is returned enters through a `retur_montaj` receipt at the mounting's cost.

**Transactions**
- Every method joins the caller's transaction, or opens its own.
- Every write that touches more than one row happens inside one transaction.

### 4.2 The six installation paths

| # | Path | Before | Now |
|---|---|---|---|
| 1 | `MaintenanceModel::saveRecord` | `cost = pret_achizitie` (current price) | `consume()` with key `mentenanta-<id>-piesa-<part>`. `cost_piese` and `cost` come from the receipts. |
| 2 | `MaintenanceModel::savePart` (direct) | mounting without a source | `sursa_piesa = 'achizitie_directa'` and a nature on the `mentenanta` row. Stock mode (new part / catalog restock) now enters through `receive()`: declared price, or `neverificat_istoric` with no price. |
| 3 | `VehicleComponentModel::mount/mountMany` | stock path at current price; direct path at 0 | Stock path → `consume()`, with an optional key from `idempotency_key`. New sources: `atelier` → `atelier_extern` and `transfer` → `transfer()`. `unmount(retur_stoc)` → `returnToStock()`. The controller accepts `source=stoc\|direct\|atelier\|transfer`, `transfer_usage_id` and `idempotency_key`. |
| 4 | `InterventionWorkflowModel::finalize` | always `direct_mount = 1`, **no stock decrement** (R1) | Per line `sursa_piesa`: `stoc` → `consume()` (key `interventie-lucrare-<id>`), and the part cost becomes the receipt valuation (`cost_piese_evaluat`), not the manually typed value. `transfer_vehicul` → `transfer()` with a part cost of 0. `achizitie_directa`/`atelier_extern`/NULL → direct mounting as before, with its source recorded. |
| 5 | `OcrPartsMaintenanceSyncService` | stock: overwrote `pret_achizitie`, no receipt | Stock line → `receive()` with the price, supplier, CUI, invoice number/date, OCR invoice and line, status `confirmat_document`. Vehicle line → direct mounting `achizitie_directa`, key `ocr-articol-<id>`. |
| 6 | `ReviziiV2Service` | stock: current price | Stock → `consume()` (key `rv2-piesa-<line>`). Direct → `achizitie_directa`. |

**Other stock writers now go through receipts**
- `deleteRecord` and `deleteCorrectiveInvoice` use `reverse()`.
- Transfers and direct mountings never put stock back.

**Stock now comes from four clearly separated sources:** inventory, direct purchase, workshop-supplied, and transfer.

### 4.3 Financial deduplication

- `mentenanta.sursa_tip`/`sursa_id`, OCR line references on receipts and mountings, `idempotency_key` on mountings and receipts, and `transfer_din_utilizare_id` keep the identity of every original transaction.
- The read model (§7) never reads the derived mirror (`supplier_invoices`, `invoice_vehicle_repairs`, `invoice_repair_parts`) or `ocr_piese_registru`.

---

## 5. Phase 3 — Acquisition dossier (`services/VehicleAcquisitionService.php`)

**Creating and linking the dossier**
- `createDossier()`:
  - Works without a registered vehicle, using a provisional `cod_dosar` `ACH-YYYY-NNNN` and the VIN.
  - Supports cap tractor, camion, the semitrailer variants, remorcă, autoutilitară, autovehicul and altul.
  - Allows one active dossier per vehicle.
- `linkVehicle()`: links the existing `vehicule.id` at registration. The VIN must match `serie_sasiu`.

**Cost lines**
- `categories($id)`: the categories that apply, filtered by delivery method and by the import regime.
- `addCost()`:
  - A line has: category, description, supplier, transaction date, net amount and currency, rate, VAT and deductibility, recoverable VAT, document, source, classification, **treatment `de_confirmat`**, verification status (`estimat`/`confirmat`/`verificat_contabil`).
  - The amount is required; nothing is estimated automatically.
  - `cheltuiala_id` takes over an invoice that already exists on the Cheltuieli page, with a unique link so it is not entered twice.
  - A category that does not fit the delivery context is rejected.
  - A service already included in the carrier's price can only be added as `inclus_in_linie_id`, and such lines are not summed.
- `setCostVat()` and `addPayment()` never change the classification or the treatment. Classification is independent of VAT and payment status.

**Transport and events**
- `saveTransport()`: driven (driver, temporary plates, insurance) or carrier (carrier, order, places, included services, delivery confirmation).
- `recordEvent()`:
  - Events are separate. **Reception is not commissioning.**
  - Commissioning cannot come before reception.
  - The `pus_in_functiune` status requires the commissioning event.

**`summary()` outputs**
- Purchase price in RON at the **original rate**.
- Associated costs.
- Initial capitalizable cost: only lines approved as capitalizable.
- Non-capitalizable costs.
- Unconfirmed treatment.
- Estimated vs confirmed amounts.
- Total VAT, recoverable VAT, non-recoverable VAT and unclarified VAT.
- Document total, amount paid and outstanding balance.
- An "incomplete" list, for example a foreign-currency line without a rate, which is not converted.
- **No market value is calculated.**

---

## 6. Phase 4 — Investment projects (`services/InvestmentProjectService.php`)

**Creating and editing a project**
- `create()`: on the physical vehicle.
  - Category: `adaptare_gpl`, `echipare_initiala`, `modernizare`, `extindere_capacitate` or `alte_investitii`.
  - Nature: `investitie`/`echipare_initiala`. Treatment: `de_confirmat`.
- `update()`:
  - Changes budget, status, dates and responsible person, with audit.
  - **The vehicle cannot be changed.**
  - There is no field for the actual cost.
- `approveUsefulLife()`: records the useful life only with a stated basis; who and when are saved.

**Linking sources: `link()` / `unlink()`**
- Sources: `interventie`, `mentenanta`, `ocr_reparatie`, `cheltuiala`, `documente`.
- Rules:
  - The source must be on the same vehicle. For an expense, it must be allocated to the project's vehicle.
  - An expense that is already in an acquisition dossier is refused.
  - Linking an intervention or an OCR invoice passes the project only to their **investment** rows. The repair part of a mixed intervention stays as operating cost.

**`totals()`**
- Sources: `mentenanta` rows (parts / labour / other) and expenses allocated to the vehicle (net, proportional to the allocation).
- Deduplicated on `(sursa, sursa_id)`.
- Interventions and OCR invoices that are linked but not yet recorded appear under `neinregistrate`. They are **not** in the total.
- Warnings:
  - an expense without a net value;
  - an expense of a repair, parts or tyre type, which could duplicate a maintenance row (R3).

**GPL readiness**
- `confirmStage()` / `readiness()`:
  - The six stages are kept separately.
  - Mandatory documents are read from **Documente** (the vehicle's `documente`, with validity).
  - `apt_legal` = approved for activity **and** all required documents are valid.
  - The `finalizat` financial status does not count toward it.

**Tractor / semitrailer.** The project stays on the semitrailer. `vehicule_cuplaje` is not modified. Tested: after a coupling change, the project's owner and total are unchanged, and the investment appears only in the semitrailer's history.

---

## 7. Phase 5 — Integration contracts

### 7.1 `VehicleCouplingPeriodService`

- `couplingsBetween(start, end, vehicle?)`: intervals clipped to the period, based on `data_start`/`data_end`, not only `activ`.
- `tractorAt(trailer, moment)`: which tractor the trailer was coupled to at a given moment.
- `tractorShares(trailer, start, end)`: each tractor's share of the period, by time coupled.

### 7.2 `VehicleFinancialHistoryService::forVehicle()` / `totals()`

A read-only UNION. Every row carries `(sursa, sursa_id)`, nature, treatment, project, and `clasa`: `operational | capital | achizitie | tva | finantare`.

**Source-of-truth rules (enforced in the service)**

| Source | Rule |
|---|---|
| `mentenanta` | The only ledger of works costs on a vehicle. `'Anvelopa - %'` rows are ignored. A confirmed capitalized investment goes to `clasa = capital`. |
| `mentenanta_piese_utilizari` | Only mountings **without** a `mentenanta` row and that are not transfers or Revizii V2 parts. This closes R8. |
| Receipts | Stock is inventory, not an expense. Receipts are **not** read. |
| `revizii_v2_lucrari.cost_total` | Read as recorded. |
| `cheltuieli_alocari` (vehicle) | Excludes expenses taken over by an acquisition dossier. |
| `achizitii_vehicule_cheltuieli` | Lines of the linked dossier, excluding included lines. Converted at the line's own rate; a missing rate is flagged. |
| `anvelope` | The purchase price is counted once, on the vehicle of the tyre's first allocation. |
| `leasing_installments` | Financing, not operating cost. |
| Never read | `supplier_invoices`, `invoice_vehicle_repairs`, `invoice_repair_parts`, `ocr_piese_registru`. |

---

## 8. Files

**New**
- `database/migrations/2026_11_18_000001_fundatie_financiara.sql` and `.rollback.sql`
- `htdocs/services/FinancialFoundationSchema.php`
- `htdocs/services/CostNature.php`
- `htdocs/services/FinancialAuditLog.php`
- `htdocs/services/FinancialClassificationService.php`
- `htdocs/services/PartsInventoryService.php`
- `htdocs/services/VehicleAcquisitionService.php`
- `htdocs/services/InvestmentProjectService.php`
- `htdocs/services/VehicleCouplingPeriodService.php`
- `htdocs/services/VehicleFinancialHistoryService.php`
- `scripts/migrate_financial_foundation.php`
- `scripts/test_fundatie_financiara.php` and `scripts/test_fundatie_financiara_worker.php`

**Modified**
- `htdocs/models/MaintenanceModel.php`
- `htdocs/models/InterventionWorkflowModel.php`
- `htdocs/models/VehicleComponentModel.php`
- `htdocs/models/OperationalCostModel.php`
- `htdocs/services/OcrPartsMaintenanceSyncService.php`
- `htdocs/services/OperationalCostService.php`
- `htdocs/services/ReviziiV2Service.php`
- `htdocs/controllers/MaintenanceController.php` (`financial_classify`)
- `htdocs/controllers/ModuleController.php` (mount `source`, `transfer_usage_id`, `idempotency_key`)
- `htdocs/permissions/modules/mentenanta.php` (`financial_classification`)

**Reused rather than duplicated:** `mentenanta` (the only cost ledger), Intervenții V2, the OCR register, Cheltuieli + `cheltuieli_alocari`, `cheltuieli_furnizori`, `documente`, `vehicule`/`vehicule_cuplaje`, `soferi`, `leasing_contracts`, the Reparații Auto catalog, the access registry, `can()`, and the existing transaction and `ensure*` patterns.

---

## 9. Tests

**Run**

```bash
DB_NAME=fleet_fin_test php scripts/test_fundatie_financiara.php
```

The script refuses to run against a database whose name does not contain "test". It commits real rows, using unique codes per run so it can be repeated. A concurrency test starts two PHP processes.

**Result: 130 ok, 0 failed.** Coverage, mapped to the required list:

| # | Requirement | Covered by |
|---|---|---|
| 1, 2 | Repairs and maintenance still work | `saveRecord` repair and maintenance: costs, `record_type`, nature, treatment |
| 3 | OCR keeps the classification | Mixed invoice → 3 rows (repair / maintenance / investment); original `tip_lucrare` unchanged; resending duplicates nothing |
| 4 | Investment is not an ordinary repair | 20,000 lei approved as capitalizable is excluded from `reparatii` and reported separately; before approval it stays operational |
| 5 | Receipts keep historical prices | Two pumps at 8,000 and 9,000; the first is mounted at 8,000; a later 9,500 restock does not change the history |
| 6, 7 | Atomic, idempotent, no negative stock | Retry with the same key; transaction rollback; insufficient stock refused; two parallel processes on the last unit → exactly one succeeds |
| 8 | Transfers do not duplicate the purchase | Historical cost kept; no receipt and no new cost; retry is idempotent |
| 9 | No double counting | V2 finalize twice → one consumption, the same `mentenanta` rows; history unique on `(sursa, sursa_id)`; consumption via `mentenanta` is not counted again as a mounting |
| 10 | Dossier for tractor and semitrailer | Tractor via carrier, EUR at the original rate, VIN, later registration; semitrailer driven, existing vehicle |
| 11 | Classification independent of VAT and payments | Changing VAT or paying leaves classification and treatment unchanged |
| 12 | GPL total reconciles | 34,000 work + 1,000 net certification = 35,000, deduplicated; mixed intervention adds only the investment part |
| 13 | Coupling does not move the investment | New historical couplings; project, total and history stay on the semitrailer |
| 14 | Documents still accessible | Every pre-existing row is identical (id, vehicle, type, number, expiry, file) |
| 15 | Cost/km for ordinary expenses | Repairs and maintenance counted; whole local history identical to the old calculation |

**Existing regression suites, run on `fleet_fin_test`**

| Suite | Result |
|---|---|
| `test_revizii_v2` | 97/97 |
| `test_cost_operational_km` | 42/42 |
| `test_parts_invoice_ocr` | 103/103 |
| `test_revizii_istoric` | 47/47 |
| `test_cheltuieli_unificat` | 43/43 |
| `test_revizii_stergere` | 18/18 |
| `test_consumabile` | 52/53. The one failure ("grupurile Sasiu / Hidraulic / Livrare Gaz identice") **also fails with these changes stashed**, so it is pre-existing and unrelated. |

**Pages checked over HTTP on the local app** (authenticated admin, HTTP 200, no PHP or SQL errors):
- Mentenanță: overview, Stoc piese, Reparații, Întreținere, Intervenții, intervention list (JSON), Auto
- Registru piese & lucrări
- Cost operațional, plus its `data` endpoint
- Revizii, Documente, Cheltuieli, Vehicule grele, Dashboard

---

## 10. Known limitations

1. **No UI yet** for the following. The backend and the classification endpoint exist.
   - Nature selectors in the forms.
   - Project and acquisition screens.
   - The receipt list.
   - Transfer and workshop options in the mount panel.
   - A "piesa din stoc" selector on V2 lines.
2. **Depreciation is not calculated.**
   - Capitalized items are reported separately with "amortizare necalculată".
   - Still needed from accounting:
     - the capitalization threshold and policy;
     - useful life per asset or investment;
     - the depreciation method;
     - the start date (commissioning);
     - whether the acquisition value of existing vehicles is entered here or comes from leasing.
3. **Existing stock** is valued at an unknown cost (`neverificat_istoric`). Consumption from it uses the frozen reference value and is flagged `valoare_neverificata`.
4. **The stock value shown in Stoc piese** (`stoc_curent × pret_achizitie`) is unchanged. `PartsInventoryService::stockValuation()` gives the value from receipts but is not yet displayed.
5. **Unit identity (serial number) for non-tyre components** was not introduced. A transfer follows the mounting row, which is enough for one unit per position.
6. **Fuel** (`fuel_fillups`) is not in the history read model. It is keyed by registration number, not `vehicle_id`. Tyres are attributed to the vehicle of their first allocation.
7. **`OperationalCostService::buildUnits` / `getActiveCouplings`** still use the current coupling. `VehicleCouplingPeriodService` is the replacement for historical analysis and has not been wired in yet, so as not to change the current cost/km page.
8. **Other `mentenanta` writers**, such as `TireModel` with cost 0, get a nature derived at read time (`COALESCE(natura_cost, record_type)`). They do not write `natura_cost` themselves.
9. **Production schema not inspected** (assumed identical to local). `ensure()` checks every object before creating it.

---

## 11. Rollback procedures

1. **Before any deployment:** take a `mysqldump` of the VPS database.
   - Local backup made for this task: `storage/backups/pre_financiar_20261118_124532.sql`, 13.5 MB. It is git-ignored and has been verified to restore.
2. **Code:** revert the commit. If the schema stays, the old code ignores the new columns, which are all nullable or defaulted.
3. **Schema:** run `2026_11_18_000001_fundatie_financiara.rollback.sql`.
   - It drops only the added objects.
   - Data created afterwards in the new tables (receipts, dossiers, projects, classifications) is lost.
   - Validated on `fleet_fin_test`: apply → rollback (back to 240 tables, 0 new columns) → re-apply.
   - Revert the code first, otherwise `ensure()` recreates the schema on the next request.
4. **Full restore:** load the backup (`mysql … < pre_financiar_*.sql`).

**Note on deployment.** By project convention, the schema is applied automatically on the first request that builds `MaintenanceModel`. On the VPS, first take the backup and then run `php scripts/migrate_financial_foundation.php` explicitly, to see the report before the application starts using the schema.

---

## 12. Remaining work for the frontend

1. **Intervenții V2**
   - Per line: Nature (inherit / repair / maintenance / investment / initial equipment), Project, and Part source (stock with part + quantity / direct purchase / workshop / transfer with the source mounting).
   - Show `cost_piese_evaluat` after finalization.
   - At intervention level: Nature and Project. `updateFromPanel` already accepts them.
2. **Registru piese & lucrări:** a nature column per line (default derived from `tip_lucrare`) and an invoice-level project.
3. **Reparații / Întreținere:** a nature selector, a "De revizuit" badge, a "Clasificare" button that calls `financial_classify` (with reason and history) when the user has the right.
4. **Stoc piese:** a receipts tab per part (date, quantity, remaining, cost, supplier, invoice, status). Stock value from receipts, with unvalued quantity shown separately. Invoice number and date fields when adding stock.
5. **Mount panel (vehicle schema):** "Adusă de atelier" and "Transfer de pe alt vehicul" options. Send `idempotency_key`.
6. **Dosar Achiziție Vehicul page**
   - header, cost lines grouped by category (with estimated/confirmed, VAT, treatment);
   - payments, transport, event timeline, documents;
   - the `summary()` panel.
7. **Proiect investiție sheet**
   - budget vs actual (`totals()`), sources;
   - unrecorded items and warnings;
   - GPL readiness stages with Documente;
   - useful-life approval.
8. **Istoric Financiar Vehicul:** a timeline over `VehicleFinancialHistoryService::forVehicle()` with filters by class, nature and project.
9. **Rentabilitate Operațională:** consume the history, `VehicleCouplingPeriodService::tractorShares()` and trip revenue. Formulas are to be defined with accounting.

---

## 13. Frontend — Istoric Financiar Vehicul (added 2026-11-18)

Implemented from the approved mockup (1648 × 928). The page is `?page=istoric_financiar`.

- **Navigation**
  - The existing Fleet App shell is reused unchanged: header, banner and sidebar.
  - One sidebar entry was added: "Istoric Financiar Vehicul", next to Cost operațional.
  - The mockup's global search bar became a page-level live search, in the header row next to "+ Dosar achiziție". `Ctrl + K` focuses it.
- **New permission module** `istoric_financiar`:
  - `view`;
  - `manage` (dossiers, costs, payments, events, documents);
  - `proiecte` (GPL projects);
  - `clasificare` (accounting treatment).
  - All except `view` are admin-default and sensitive. The registry was synced.

### 13.1 Files

| File | Role |
|---|---|
| `htdocs/services/VehicleFinancialRegisterService.php` | Page read model: list, cards, counters, expanded dossier. Every amount comes from `VehicleAcquisitionService::summary()`, `InvestmentProjectService::totals()/readiness()` and `VehicleFinancialHistoryService`. No calculation of its own. |
| `htdocs/controllers/VehicleFinancialHistoryController.php` | Routes:<br>• `index`<br>• `list` (JSON)<br>• `detail` (JSON)<br>• `file`: dossier and line documents, served only through the controller<br>• `op`: POST + CSRF + per-operation permission |
| `htdocs/views/istoric_financiar/index.php`<br>`htdocs/assets/css/istoric-financiar.css`<br>`htdocs/assets/js/istoric-financiar.js` | The UI |
| `htdocs/services/VehicleAcquisitionService.php` | Added `updateDossier()` and `updateCost()`, with per-field audit. Classification and treatment keep their own flow. |
| `database/migrations/…fundatie_financiara.sql` (v2) | Adds `achizitii_categorii_cheltuieli.grup` / `grup_ordine`, which drive the left category list. Completion marker: `schema_v2_complet`. |
| `scripts/seed_istoric_financiar_demo.php` | Demo dossiers created through the real services. `--remove` deletes them and their uploaded files. |

### 13.2 Behaviour

**List**
- Shows the acquisition dossiers in purchase-date order, then the fleet vehicles without a dossier ("Fără dosar").
- Tractor, truck, semitrailer and provisional (not yet registered) dossiers are all supported.

**Cards**
- Values come from the dossiers.
- They filter by tab when clicked.

**Filters**
- Tabs (Toate / În derulare / Finalizate), Status, Țară proveniență, Perioadă.
- Debounced live search over plate, VIN, seller, cost supplier, invoice number and dossier code.
- Pagination (10 / 25 / 50).
- The filter state is kept in the URL and survives expanding rows and switching tabs.

**Expanded dossier**
- Opens directly under its row, across the full table width, with an animated expand/collapse.
- The 7-step indicator is computed from records (vendor/VIN, contract + price line, payments covering the total, confirmed delivery, reception, registration, commissioning).
- Clicking a step opens the form for that step.

**Tabs:** Detalii, Costuri, Documente, Istoric, Valoare & Amortizare, Note, and **Investiții GPL**, added in the same tab style.

**Costuri workspace**
- Left: categories from the backend configuration. Selecting one filters the grid immediately.
- Right: compact grid. Lines expand to show the original currency and rate, classification, verification, VAT deductibility, payments and source, with their actions.
- Footer: Cost inițial total, Capitalizat, Cheltuieli necapitalizabile, and Valoare actuală.
- Valoare actuală shows **"Nedisponibilă — amortizare neaprobată"**. No market value is estimated.

**Forms (one modal)**
- Create or edit a dossier, add or edit a cost (with a file), payment, event, transport, status, link to a vehicle, document upload.
- Accounting treatment: "capitalizabil" requires a reason. The server refuses it otherwise, and the message appears in the form.
- Project: create, budget/status, link a source, stages, useful life.
- Documents preview in a modal.

**Investiții GPL**
- Projects of the physical vehicle with budget vs actual cost from linked sources.
- Linked but unrecorded sources and warnings.
- Readiness stages and mandatory documents from the Documente page (by vehicle type).
- Legal status is shown separately from financial completion.

### 13.3 Validation

**Visual check**
- Screenshots at 1648 × 928 (Playwright, Chromium) were compared with the mockup.
- Corrections made after comparing:
  - pill counter spacing;
  - tab widths ("Valoare & Amortizare" was wrapping);
  - row height;
  - category list spanning both rows, so the footer sits right under the grid as in the mockup;
  - the `Ctrl + K` badge;
  - a CSS specificity bug that overrode button font sizes;
  - the modal not inheriting the page colour variables.
- Remaining differences are intentional:
  - the app's own header and sidebar are kept;
  - search is at page level;
  - demo cost lines without an uploaded file show their document number in grey, not as a link.

**Browser interaction test: 26/26**

| Area | What was checked |
|---|---|
| Search | Supplier, VIN, invoice number |
| Tabs and filters | Tabs, country, status, period, pagination, URL state |
| Expand | Row opens directly under the vehicle; categories filter the grid |
| Costs | Adding a cost with a PDF |
| Treatment | Refused without a reason, accepted with one |
| Documents | Upload, preview, PDF served through the controller |
| History | The reason appears in the dossier journal |
| Create and edit | Creating a dossier for a vehicle without one, saving a note |
| Navigation | Collapse, `Ctrl + K` |
| Console | No JavaScript errors |

- **Backend suite rerun:** 130/130 on `fleet_fin_test`.
- **Defect found and fixed:** the alias `stored` is a MySQL 8 reserved word. It broke document serving with HTTP 500.

### 13.4 Local data created

- On the local development database, for review: 4 demo dossiers (and 1 GPL project) from `seed_istoric_financiar_demo.php`, plus dossiers, a cost line and documents created by the browser test.
- All are tagged `DEMO-IFV`.
- Remove them with `php scripts/seed_istoric_financiar_demo.php --remove`.
- No `mentenanta` rows were created, so cost/km is unaffected.
