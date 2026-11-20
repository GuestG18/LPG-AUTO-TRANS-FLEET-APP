# AUDIT — Investments in vehicles for GPL transport (adaptations, equipment, modernization)

- **Date:** 2026-11-18
- **Type:** READ-ONLY audit. No code, schema, data or UI was changed. The only database access was `SELECT` on the local copy and on `information_schema`.
- **Audited codebase:** `C:\laragon\www\aplicatie_fleet\htdocs` (git HEAD `d1df8ab`, 2026-11-18).
- **Audited database:** local copy `if0_41456552_aplicatie_flota` (MySQL 8.4, 240 tables).
- **Pages it prepares:** **Istoric Financiar Vehicul** and **Rentabilitate Operațională**.

Labels used throughout:

| Label | Meaning |
|---|---|
| **EXISTS** | Verified in code and/or schema, and it works. |
| **PARTIAL** | It exists but does not fully cover the requirement. |
| **MISSING** | Not implemented. |
| **UNVERIFIED** | Cannot be confirmed from the available evidence. |

Paths are relative to `htdocs/` unless stated otherwise. Line numbers are those at the date of the audit.

> **Limits of the evidence.** The local database is a development copy:
> - `mentenanta_piese_utilizari` has 0 rows.
> - All 141 parts in `mentenanta_piese` have `stoc_curent = 0` and `pret_achizitie = 0`.
> - `mentenanta` has 24 rows.
>
> Every workflow below was therefore verified **in code**, not in production data from the VPS. The column structure was verified on the local database. Columns created at runtime by `ensure*Schema` / `addColumnIfMissing` match the local schema, but the VPS schema was not inspected (**UNVERIFIED** for production).

---

## 1. Executive summary

**Short answer: no.** Fleet App cannot yet manage an investment that adapts a tractor, truck or semitrailer for GPL transport as a single financial entity. However, about 80% of the building blocks exist and are reusable:

- Parts and labour are recorded per vehicle. Sources are `mentenanta`, `mentenanta_piese_utilizari` and `ocr_reparatii_articole`.
- Interventions support several operations, several documents and several costs. Sources are `mentenanta_interventii_programate`, `_lucrari` and `_documente`.
- Costs stay attached to the physical vehicle (`vehicle_id`), not to the coupling. So a compressor fitted on semitrailer A stays on A.
- Cost/km already exists (`OperationalCostService`), has an amortization mechanism (configuration only), and already combines tractor + semitrailer.

**What is structurally missing:**

1. **No "investment / modernization / initial equipment" classification.**
   - `mentenanta.record_type` only has `intretinere | reparatie`.
   - The OCR register has `tip_lucrare = 'imbunatatire'`, but it is **lost** when synced into `mentenanta`: it becomes `reparatie`.
   - Result: an investment today ends up in **operating costs** (the `reparatii` element of cost/km).
2. **No project-level grouping above interventions.** There is no "Adaptare semiremorcă GPL" entity with a budget, a status, a commissioning date and several interventions, invoices and services under it.
3. **No purchase ledger for parts.**
   - `mentenanta_piese.pret_achizitie` is a single value, overwritten on every purchase.
   - The supplier and invoice are stored per part, not per receipt.
   - So the original cost of the 8,000 lei pump is not guaranteed to survive until it is fitted.
4. **No acquisition value or depreciation per vehicle.**
   - `vehicule` has no value column.
   - The `amortizare` element in cost/km is a single manual value with `valoare_config = NULL`.
5. **Vehicle documents (`documente`) can only link to a vehicle.** They have no type class (invoice / technical / ADR / approval / periodic inspection).

**Minimum recommended architectural change (Option B):**

- A **light header table** `investitii_proiecte`.
- A **cost-nature classification** (`natura_cost`) carried from the source line all the way into `mentenanta`.
- **Nullable `proiect_investitie_id` FKs** on the existing tables (`mentenanta_interventii_programate`, `mentenanta`, `ocr_reparatii`, `cheltuieli`, `documente`).
- The transactions stay in Intervenții V2, Registru piese & lucrări and Cheltuieli.
- **Istoric Financiar Vehicul** becomes a read-only consumer, built as a `UNION` over the existing sources.

A dedicated module (Option C) is not justified.

---

## 2. Existing relevant modules

| Module (UI) | Route | Controller / Model / Service | Main tables | Status for investments |
|---|---|---|---|---|
| **Stoc Piese** | `page=mentenanta&action=stock` (`index.php:690` → `MaintenanceController.php:41`, `showSection('stock')` :645) | `MaintenanceModel::getStockParts` (:885), `savePart` (:1391), `updateCatalogPart` (:1561); view `views/maintenance/index.php` | `mentenanta_piese` | PARTIAL: stock without a receipt ledger |
| **Catalog Componente / Reparații Auto** | Auto page / explorer | `services/AutoComponentCatalogService.php` (:249), `VehicleComponentRegistry.php`, `MaintenanceModel::syncAutoComponentsToStock` (:990) | `mentenanta_auto_componente` (`component_key` UNIQUE), `mentenanta_auto_categorii/subcategorii/ramuri`, `mentenanta_auto_structuri_*` | EXISTS as a catalog of types. No unit identity. |
| **Registru piese & lucrări (OCR)** | `page=ocr_piese` (admin, `index.php:885`); send at `OcrPartsController.php:597` | `OcrPartsModel`, `services/PartsInvoiceOcrService.php`, `services/OcrPartsMaintenanceSyncService.php` | `ocr_piese_facturi`, `ocr_reparatii`, `ocr_reparatii_articole`, `ocr_reparatii_vehicule`, `ocr_piese_registru` | PARTIAL: has `imbunatatire` but loses it in the sync |
| **Intervenții V2** | intervention page (dispatcher/coordinator) | `models/InterventionWorkflowModel.php` (`costs` :1243, `finalize` :1795, `addDocument` :1174) | `mentenanta_interventii_programate`, `_lucrari`, `_documente`, `_istoric` | PARTIAL: best candidate for reuse |
| **Mentenanță / Reparații (records)** | `page=mentenanta` (`action=repairs` MC:676) | `MaintenanceModel::saveRecord` (:376-457), `getOverview` (:97) | `mentenanta`, `mentenanta_piese_utilizari` | EXISTS as the vehicle cost ledger |
| **Mounted components** | route `component_mount` (`ModuleController.php:3879`) | `models/VehicleComponentModel.php` (`mount` :203, `mountMany` :220, `unmount` :344) | `mentenanta_piese_utilizari` | PARTIAL: no `mentenanta` cost |
| **Revizii V2** | `page=revizii` | `services/ReviziiV2Service.php`, `models/ReviziiV2Model.php` | `revizii_v2_lucrari` (`cost_manopera`, `cost_materiale`, `cost_total`), `revizii_v2_executii`, `revizii_v2_documente` | EXISTS for maintenance. Not relevant for investments. |
| **Anvelope** | `tire_stock`, mount/swap | `models/TireModel.php` | `anvelope` (`purchase_price`, `supplier`, `invoice_number`, `serial_number`), `anvelope_alocari`, `anvelope_istoric` | EXISTS: **the only model with unit identity + purchase cost + transfer history** |
| **Vehicule** | `page=vehicule_usoare / vehicule_grele`, `action=show` | `ModuleController.php:5376-5400`, `views/module/_vehicle_detail.php`, `models/VehicleCouplingModel.php` | `vehicule`, `vehicule_cuplaje` | PARTIAL: no financial data |
| **Documente** | `page=documente` | `config/modules.php:527-589`, `models/DocumentModel.php` | `documente`, `configurare_costuri_documente_vehicule(_override)` | PARTIAL: vehicle only, untyped |
| **Cheltuieli (unified)** | `page=cheltuieli` | `controllers/ExpenseController.php`, `models/ExpenseModel.php` | `cheltuieli`, `cheltuieli_alocari`, `cheltuieli_documente`, `cheltuieli_tipuri`, `cheltuieli_furnizori` | PARTIAL: allocation per vehicle, no link to maintenance |
| **Inventar dotări** | equipment per vehicle | `models/VehicleEquipmentInventoryModel.php` | `inventar_dotari_catalog`, `_reguli`, `_vehicule` | Small on-board equipment (extinguisher, ADR kit). Not suited to investments. |
| **Cost operațional / km** | cost_operational page | `services/OperationalCostService.php`, `models/OperationalCostModel.php`, `CostNormalizationService.php`, `DashboardOperationalCostService.php` | `cost_operational_elemente`, `cost_operational_settings` | PARTIAL: consumer; amortization by configuration only |
| **Leasing** | leasing scheduler | `models/LeasingSchedulerModel.php` | `leasing_contracts` (`initial_value`), `leasing_installments` | EXISTS but isolated (0 rows locally, not read by cost/km) |
| **Legacy / derived** | Reparații tab | `MaintenanceModel::backfillCorrectiveInvoicesFromRecords` (:2213) | `supplier_invoices`, `invoice_vehicle_repairs`, `invoice_repair_parts` | **Derived mirror** of `mentenanta` (synthetic invoice `FAV-BU-<id>`), not a source of truth |
| **Legacy** | `stare_tehnica` | `TechnicalHealthModel.php:581-590` | `repair_interventions` (no writer found), `technical_components` | Not relevant |
| `configurare_compresor_vehicule` | Tarife transport, compressor tab | `DispecerCurseModel.php:827-877` | (`beneficiar_id`, `vehicle_id`) | **Tariff eligibility**, not equipment/cost. Do not confuse with the compressor as an investment. |

---

## 3. Verified database structures

These are extracted from `information_schema` on the local database. Only the columns that matter for investments are listed.

### 3.1 Parts and stock

**`mentenanta_piese`** is the part master. **There is no movement table.**

| Column(s) | Note |
|---|---|
| `cod_piesa` (UNIQUE), `denumire`, `categorie`, `producator`, `cod_oem`, `unitate_masura` | Part identity (type level) |
| `mod_utilizare` `ENUM('stoc','direct')` | |
| `stoc_curent`, `stoc_minim` | |
| **`pret_achizitie`** | **single value** |
| `furnizor`, `factura_original`, `factura_stocata` | **one per part** |
| `fisa_stocata`, `imagine_stocata`, `garantie_piesa`, `garantie_manopera` | |
| `sisteme_componente`, `tipuri_vehicul` | |
| `status_piesa`, `arhivat_la` | |

**`mentenanta_piese_utilizari`** records parts mounted or used.

| Column | Meaning / FK |
|---|---|
| `part_id` | FK → `mentenanta_piese` |
| `maintenance_id` | FK → `mentenanta` |
| `scheduled_intervention_id` | FK → `mentenanta_interventii_programate` |
| `vehicle_id` | FK → `vehicule` |
| `cantitate`, **`cost_unitar`** | |
| `data_montare`, `km_montare`, `montata_de` | |
| `direct_mount` | |
| `component_key`, `locatie`, `axle_no`, `parte` | the slot |
| `demontat_la`, `km_demontare`, `motiv_demontare` | |
| `inlocuita_de_id` | |

### 3.2 Interventions and the cost ledger

**`mentenanta`** is the per-vehicle cost ledger.

| Column(s) | Note |
|---|---|
| `vehicle_id` | FK |
| **`record_type ENUM('intretinere','reparatie')`** | |
| `tip_interventie`, `centru_cost` | |
| `cost`, `cost_piese`, `cost_manopera` | |
| `atelier`, `furnizor_piesa` | |
| `source_intervention_id` | **no FK** |
| `technical_component_id`, `zile_imobilizare` | |
| `fisier_stocat` | **one file** |

**`mentenanta_interventii_programate`**

| Column(s) | Note |
|---|---|
| `vehicle_id` | FK |
| `tip_interventie ENUM('intretinere','reparatie')` | |
| `cost_estimat`, `cost_suplimentar` | |
| `furnizor` | |
| `responsabil_user_id` | FK |
| `driver_id` | FK |
| `status_interventie` | 7 values |
| `converted_maintenance_id` | FK → `mentenanta` |
| `interventie_origine_id` | follow-up, **not** a project |
| `service_km_id` | |
| `data_executie`, `finalizat_la`, `finalizat_de` | |

**`mentenanta_interventii_lucrari`** holds the operations inside an intervention.

| Column(s) | Note |
|---|---|
| `intervention_id` | FK |
| `vehicle_id` | can be the coupled trailer |
| `component_key` | FK → `mentenanta_auto_componente` |
| `operatiune ENUM('verificare','diagnosticare','reglare','reparatie','inlocuire')` | |
| `rezultat` | |
| `piesa_montata`, `piesa_demontata` | **free text** |
| `data_montare`, `km_montare` | |
| `cost_piese`, `cost_manopera` | |
| `garantie_luni`, `garantie_pana_la` | |
| `maintenance_id`, `usage_id` | UNIQUE |

**`mentenanta_interventii_documente`**: `intervention_id` (FK), `tip ENUM('deviz','factura','comanda','foto','raport','altul')`, `fisier_stocat`, `uploaded_by`.

### 3.3 OCR invoices (Registru piese & lucrări)

**`ocr_piese_facturi`**: `numar_factura`, `data_facturii`, `furnizor`, `cui_furnizor`, `moneda`, `total_factura`, `fisier_stocat`, `sursa`, `sursa_key`.

**`ocr_reparatii`**:
- `vehicle_id` (FK)
- `factura_id` (FK → `ocr_piese_facturi`)
- `tip_lucrare`
- `furnizor`
- `km_bord`

**`ocr_reparatii_articole`**:

| Column(s) | Note |
|---|---|
| `tip ENUM('piesa','manopera')` | |
| **`tip_lucrare`** | `reparatie / inlocuire / intretinere / imbunatatire` per `PartsInvoiceOcrService::WORK_TYPES` :24 |
| `destinatie ENUM('vehicul','stoc')` | |
| `vehicle_id` | |
| `cantitate`, `pret_unitar` | |
| `garantie_*` | |
| `auto_component_key` | |
| `mentenanta_id`, `mentenanta_utilizare_id`, `mentenanta_piesa_id`, `mentenanta_stoc_cant`, `mentenanta_trimis_la` | |

**`ocr_piese_registru`** has the text columns `reparatii`, `inlocuiri`, `imbunatatiri` (not read by the sync).

### 3.4 Vehicles and couplings

**`vehicule`**:
- `tip_vehicul ENUM('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie')`
- Local counts: 15 cap_tractor, 24 camion, 8 semiremorca_primar, 7 semiremorca_distributie.
- **No acquisition value, acquisition date or commissioning date.**

**`vehicule_cuplaje`**:
- `tractor_id` (FK), `semiremorca_id` (FK), `activ`, `data_start`, `data_end`.
- Local: 15 active + 18 historical rows.

### 3.5 Documents and expenses

**`documente`**:
- `vehicle_id` **NOT NULL** (FK, the only link)
- `tip_document varchar(100)` (free)
- `numar_document`, `data_expirare`, `fisier_stocat`, `custom_fields_json`

Types present locally: CASCO, RCA, ITP, Carte, Adr, Rovinieta, ORGANISM NOTIFICAT, IPROCHIM, Copie conforma, Tahograf, METROLOGIE, Tuv.

**`configurare_costuri_documente_vehicule`**: (`vehicle_type`, `document_type`, `document_cost`, `validity_days`, `requires_expiry`, `obligatoriu`, `custom_fields_json`).

**`cheltuieli`**:
- `categorie ENUM('administrativa','operationala')`, `tip_id`
- `tip_document ENUM('factura','bon_fiscal','chitanta','alt_document')`
- `furnizor`, `cui`, `valoare`, `valoare_neta`, `tva`
- `sursa`
- `alocare_tip ENUM('vehicul','sofer','companie','mixt')`

**`cheltuieli_alocari`**: `vehicul_id` (FK), `suma`. Operational types include **Reparații, Piese auto, Anvelope**.

### 3.6 Costs and capital

**`cost_operational_elemente`** (30 elements). Relevant elements:

| Element | `sursa_referinta` | `activ` | Note |
|---|---|---|---|
| `reparatii` | `mentenanta_reparatii` | 1 | |
| `revizii` | `mentenanta_intretinere` | 1 | |
| `piese_ocr` | `ocr_piese` | **0** | disabled on purpose: "risc dublă numărare" |
| `anvelope` | | | |
| `dotari_vehicule` | | | |
| `amortizare` | `manual` | 1 | `amortizare_ani = 6`, `valoare_config NULL`; note: "Nu există valoare de achiziție pe vehicule; leasing_contracts gol" |

**`leasing_contracts`**: `vehicle_id`, `initial_value`, `advance_amount`, … (0 rows locally).

**`inventar_dotari_vehicule`**: `vehicle_id`, `catalog_id`, `cost`, `data_achizitiei`, inspection dates, `serie_cod_produs`.

### 3.7 Missing structures

There are no tables or columns for:
- investment projects;
- cost nature (capex/opex);
- planned budget;
- commissioning date;
- stock receipts;
- unit identity for non-tyre components;
- vehicle acquisition value.

The `investi|moderniz|capitaliz` grep over `htdocs` returns no business concept.

---

## 4. Existing component purchase lifecycle

Worked example: *a hydraulic pump bought for 8,000 lei, entered in Stoc Piese.*

| Step | Status | Evidence |
|---|---|---|
| Part identity (catalog type) | **EXISTS** | `mentenanta_piese.cod_piesa` UNIQUE; link to the catalog via `sisteme_componente` / `component_key` (`syncAutoComponentsToStock` MM:990) |
| Entry into stock | **EXISTS** (in-place) | New part: `MaintenanceModel::savePart` (:1391, INSERT :1405-1421). Restock: `updateCatalogPart` (:1561) → `stoc_curent = stoc_curent + :quantity` (:1579). OCR: `OcrPartsMaintenanceSyncService.php:96-113`. |
| Quantity received per receipt | **MISSING** | No movement table exists. Only `mentenanta_piese_utilizari` is referenced. Quantity is lost in the `UPDATE`. |
| Original purchase cost | **PARTIAL** | `pret_achizitie` is **overwritten** on every restock (MM:1585-1601) and on every OCR stock line (`OcrPartsMaintenanceSyncService.php:102-113`). There is no history, average or FIFO. Stock value is `stoc_curent * pret_achizitie` (MM:915, :1246). |
| Supplier | **PARTIAL** | `mentenanta_piese.furnizor`, one per part, overwritten. |
| Purchase date | **MISSING** for Stoc Piese | Only `created_at`/`updated_at`. **EXISTS** in OCR (`ocr_piese_facturi.data_facturii`), but `ocr_reparatii_articole` does not keep a durable FK into the stock movement, only `mentenanta_piesa_id` + `mentenanta_stoc_cant` on the invoice line. |
| Invoice | **PARTIAL** | Stock: one file per part (`factura_stocata`), overwritten (MM:1608-1615). OCR: full invoice (`ocr_piese_facturi`) with lines, so traceable **only if** the purchase came through OCR. |
| Tyres (for comparison) | **EXISTS** | `anvelope.purchase_date`, `purchase_price`, `supplier`, `invoice_number`, `invoice_document_path`, `serial_number` per unit. This is the target pattern. |

**Conclusion:** for a low-value consumable part the current model is acceptable. For an 8,000 lei pump it is **not**:
- if a second pump is bought at 9,000 lei before the first one is fitted, the first one will be costed at 9,000;
- the original invoice file is replaced.

---

## 5. Existing component installation lifecycle

There are **six** mount paths. They behave differently:

| Path | `cost_unitar` | Stock | Creates a `mentenanta` row (cost)? |
|---|---|---|---|
| `MaintenanceModel::saveRecord` → `decreaseStockAndCreateUsage` (MM:457, :1902) | `pret_achizitie` (:1933) | −qty (:1913) | **Yes**: `cost_piese += qty × pret_achizitie`; `cost = labour + parts` (MM:382-389) |
| `MaintenanceModel::savePart`, direct mode (MM:1483-1544) | form value | none | **Yes**: `cost = cost_piese` |
| `VehicleComponentModel::mount` / `mountMany` (VCM:203/220) | stock: `pret_achizitie`; direct: **0** (VCM:289) | −qty if from stock (VCM:275) | **No** (`maintenance_id` NULL) |
| `InterventionWorkflowModel::finalize` (IWM:1976) | lucrare `cost_piese` | **none**: always `direct_mount = 1`, even when the part was in stock | **Yes** (via the intervention) |
| `OcrPartsMaintenanceSyncService` (`destinatie = 'vehicul'`, :119-207) | `pret_unitar` from the invoice | none | **Yes**, one row per vehicle per invoice |
| `ReviziiV2Service` (:1705-1724) | `pret_achizitie` / 0 | −qty if from stock | **No**: cost goes into `revizii_v2_*` |

Coverage of the required data for the pump installation:

| Requirement | Status | Source |
|---|---|---|
| Vehicle receiving the component | **EXISTS** | `utilizari.vehicle_id`, `mentenanta.vehicle_id` |
| Installation date / km | **EXISTS** | `data_montare`, `km_montare` (km from SAS at mount, see the "Km bord la montare din SAS" memory) |
| Labour cost | **EXISTS** | `mentenanta.cost_manopera`, `lucrari.cost_manopera`, `ocr_reparatii_articole` with `tip = 'manopera'` |
| Additional installation costs | **PARTIAL** | `mentenanta_interventii_programate.cost_suplimentar` is one undetailed amount. External services can only be recorded as an OCR manopera line or as a separate expense in `cheltuieli` (unlinked). |
| Total installed cost | **PARTIAL** | `mentenanta.cost` per intervention. For mounts through `mountMany` / Revizii V2 it is **not** in `mentenanta`. There is no total across several interventions. |
| Workshop / responsible person | **EXISTS** | `mentenanta.atelier`, `programate.furnizor`, `responsabil_user_id`, `utilizari.montata_de` |
| Associated work order | **EXISTS** | `mentenanta.source_intervention_id`, `programate.converted_maintenance_id`, `lucrari.usage_id` / `maintenance_id` |
| Technical documents | **PARTIAL** | `mentenanta_interventii_documente` (deviz/factura/comanda/foto/raport/altul); part sheet in `mentenanta_piese.fisa_stocat` (per type, not per unit) |
| Link from mounted pump back to its purchase (invoice, date, supplier) | **MISSING** | `utilizari.part_id` points at the **SKU**, not at a receipt. Recoverable only for an OCR `destinatie = 'vehicul'` purchase (`ocr_reparatii_articole.mentenanta_utilizare_id`). |
| Unit identity (serial number) for the pump | **MISSING** | The only serial in the app is `anvelope.serial_number`. A usage row identifies a **slot** (`component_key`, `locatie`, `axle_no`, `parte`), not a physical unit. |

**Can the cost be counted twice (purchase + installation)?**
- In the main path (`saveRecord`, stock → `mentenanta`): **no**. The stock decreases and the cost moves into `mentenanta`, provided stock is not treated as an expense on purchase.
- In practice there are **real** risks. See section 12, risks R1–R4.

---

## 6. Repair vs maintenance vs investment

| Category | Status | Evidence |
|---|---|---|
| A. **Reparație** | **EXISTS** | `mentenanta.record_type = 'reparatie'`, `programate.tip_interventie = 'reparatie'`, `lucrari.operatiune = 'reparatie'` |
| B. **Mentenanță** | **EXISTS** | `record_type = 'intretinere'`, plus the whole Revizii V2 module |
| C. **Investiție / Modernizare** | **PARTIAL → effectively MISSING** | Only `ocr_reparatii_articole.tip_lucrare = 'imbunatatire'` (`PartsInvoiceOcrService.php:24`, prompt :52) and the text column `ocr_piese_registru.imbunatatiri`. On sync, `OcrPartsMaintenanceSyncService.php:142-161` sets `$maintenanceOnly = false` for anything other than `intretinere` and writes `record_type = 'reparatie'`. **The classification is lost.** |
| D. **Echipare inițială** | **MISSING** | There is no concept of a commissioning date (`vehicule` has no such column) and no flag before / after commissioning. |

Direct financial effect today:
- An investment entered through any module ends up in `mentenanta` as `reparatie`.
- It is counted **in full, in its month**, as an operating cost through the `reparatii` element (`OperationalCostModel.php:714-736`).
- This inflates cost/km for that month and understates it for the following years.

**Accounting note.** Even after a "Investiție" management classification is introduced, it **is not automatically** a capitalizable fixed asset. Capitalization depends on the applicable accounting criteria (useful life, increase in future economic benefits, materiality threshold, documentation). The model should keep **two separate fields**:
- `natura_cost` (management view), set by the operator;
- `tratament_contabil` (`cheltuiala | capitalizabil | de_confirmat`), confirmed by accounting.

The audit proposes no accounting formula.

---

## 7. Tractor and trailer compatibility

| Requirement | Status | Evidence |
|---|---|---|
| Investments attachable independently to cap tractor / camion / semiremorcă / remorcă | **EXISTS** (as a key) | Every cost source uses its own `vehicle_id`. `tip_vehicul` covers cap_tractor, camion and three semitrailer variants. **"Remorcă" (drawbar trailer) is not a distinct type.** It would be `semiremorca` or `camion`, so **PARTIAL** for remorcă. |
| Compressor on semitrailer A stays on A when it is re-coupled to C | **EXISTS** | Costs are stored on the trailer's `vehicle_id`, not on the coupling. `VehicleCouplingModel::assignTrailerToTractor` (:335-400) only closes/opens rows in `vehicule_cuplaje` and moves no cost. Intervenții V2 correctly mounts trailer components **on the trailer** (`IWM.php:1976` comment: "Componenta de pe semiremorca cuplata se monteaza pe semiremorca"; `normalizeDispatcherComponents`). |
| Coupling history | **EXISTS** | `vehicule_cuplaje.data_start/data_end` (append-only), `getHistoryForVehicle` (:446); used historically for km (`TrailerSasKmService`, `ReviziiService:12-22`, `ReviziiIstoricModel::couplingAt` :576-590) |
| Combined tractor+trailer cost over time | **PARTIAL** | `OperationalCostModel::getActiveCouplings` (:205-215) reads only `activ = 1` (it ignores `data_start/data_end`). `OperationalCostService::buildUnits` (:285-300) moves the trailer's costs onto the **current** tractor for **any** period computed. `MaintenanceModel::buildActiveEnsembleCosts` (:1860-1888) and `VehicleEquipmentInventoryModel::buildAssemblySummary` (:444-535) also use the current coupling only. For a historical analysis (Rentabilitate) the trailer's costs (including investment depreciation) **would be attributed to the wrong tractor** after a re-coupling. |
| Component physically transferred between vehicles | **PARTIAL** | Tyres: full flow (`tire_fleet_move` MC:1529, `anvelope_istoric`). Other parts: only `unmount(reason = 'retur_stoc')` (VCM:344-347), which adds stock back, then a new mount. The remount from stock takes the **current** `pret_achizitie` (VCM:289), so the cost is counted again on the second vehicle. A direct remount records 0. Neither path creates `mentenanta`. `inventar_dotari_vehicule` allows overwriting `vehicle_id` (`updateAssignment` :240-269) **with no history**. |

The existing tractor–trailer model (`vehicule_cuplaje`) must be kept. It is correct: the asset stays the asset, and the coupling is only a time relationship.

---

## 8. GPL adaptation workflow coverage

Scenario: **"Adaptare semiremorcă pentru transport GPL"**.

| Requirement | Status | Comment / where it could live |
|---|---|---|
| Project name | **MISSING** | Closest is `programate.descriere` (one intervention) |
| Target vehicle | **EXISTS** | `vehicle_id` on every source |
| Investment category | **MISSING** | see §6 |
| Planned budget | **PARTIAL** | `programate.cost_estimat`, per intervention only, not aggregated |
| Actual costs | **PARTIAL** | per intervention (`IWM::costs` :1243 = Σ lucrari + `cost_suplimentar`) |
| Multiple parts | **EXISTS** | several `lucrari` per intervention, several lines per OCR invoice |
| Multiple labour operations | **EXISTS** | `lucrari.cost_manopera`, OCR `manopera` lines |
| Multiple invoices | **PARTIAL** | several `mentenanta_interventii_documente` with `tip = 'factura'` (file only, no amount or number). With OCR, several `ocr_piese_facturi`, each feeding its own `mentenanta` row. Nothing groups them. |
| External services (workshop, RAR/ISCIR, notified body, certification) | **PARTIAL** | as `manopera` or as a separate expense in `cheltuieli` (allocated to the vehicle, but with no link to the intervention) |
| Installation dates | **EXISTS** | `lucrari.data_montare`, `utilizari.data_montare` |
| Project status | **PARTIAL** | per intervention (`status_interventie`) |
| Final commissioning date | **MISSING** | |
| Supporting documents | **PARTIAL** | see §9 |
| Investment history | **MISSING** | |

**The key gap:** there is no grouping above the intervention. `interventie_origine_id` (IWM:2006-2036) only chains follow-ups created from postponed lucrari. It is not a project and has no budget or status.

**Reuse:**
- **Intervenții V2** is the right execution unit. It has lucrari on components (`component_key` from the catalog), parts/labour costs, documents, a responsible person, status, finalization with `mentenanta` creation and mounts on the trailer.
- **Registru piese & lucrări (OCR)** is the right invoice capture tool. It has per-line classification, already includes `imbunatatire`, and handles parts vs labour and vehicle vs stock.
- Both need to be **grouped** and to **carry the classification**. Neither needs to be duplicated.

---

## 9. Integration with Documente

| Requirement | Status | Evidence |
|---|---|---|
| Documents linked to a vehicle | **EXISTS** | `documente.vehicle_id NOT NULL`; `DocumentModel::getDocumentsForVehicle`; shown on the vehicle sheet (`_vehicle_detail.php:510-595`) |
| Documents linked to a component | **MISSING** | Only type-level files on `mentenanta_piese` (`fisa_stocata`, `factura_stocata`, `imagine_stocata`) |
| Documents linked to an intervention | **EXISTS** | `mentenanta_interventii_documente` (FK `intervention_id`, typed) |
| Documents linked to an expense | **EXISTS** | `cheltuieli_documente` (FK `cheltuiala_id`) |
| Documents linked to an investment project | **MISSING** | |
| Expiry dates | **EXISTS** | `documente.data_expirare`, `configurare_costuri_documente_vehicule.requires_expiry / validity_days`, notifications |
| Distinguishing **financial invoice** from other documents | **PARTIAL** | Yes on interventions (`tip = 'factura'`) and expenses (`cheltuieli.tip_document`). No in `documente`. |
| **Component technical document** | **PARTIAL** | interventions: `raport` / `altul`; part: `fisa_stocata` |
| **ADR certificate** | **PARTIAL** | Only as a free label `tip_document = 'Adr'` / 'ADR' (both spellings exist in the configuration) |
| **Vehicle approval** (RAR, notified body, IPROCHIM, metrology) | **PARTIAL** | Free labels ("ORGANISM NOTIFICAT", "IPROCHIM", "METROLOGIE"). **RAR and ISCIR do not appear.** |
| **Recurring inspection** | **PARTIAL** | Implicit through `validity_days` / `requires_expiry`. There is no `clasa_document` field. |

**Reuse instead of duplication:**
- Keep `documente` for certificates and approvals with expiry (ADR, notified body, IPROCHIM, metrology), because they already have notifications and cost/km.
- Keep `mentenanta_interventii_documente` for estimates, invoices and work reports.
- Minimum missing piece: a nullable FK to the project, plus a `clasa_document` classification on `configurare_costuri_documente_vehicule` (one value per configured document type, not per row).

---

## 10. Data flow toward Istoric Financiar Vehicul

| Required information | Available today? | Source / what is missing |
|---|---|---|
| Vehicle identifier | **EXISTS** | `vehicle_id` everywhere |
| Investment identifier | **MISSING** | needs `investitii_proiecte.id` + FKs |
| Investment type | **MISSING** | needs `natura_cost` |
| Investment description | **PARTIAL** | `mentenanta.descriere` / `programate.descriere` per intervention |
| Component costs | **EXISTS** | `mentenanta.cost_piese` (+ `utilizari.cost_unitar` for mounts outside `mentenanta`) |
| Labour costs | **EXISTS** | `mentenanta.cost_manopera` |
| External service costs | **PARTIAL** | `programate.cost_suplimentar` (undetailed) or `cheltuieli` (unlinked) |
| Associated documents | **PARTIAL** | via `source_intervention_id` → `mentenanta_interventii_documente` |
| Installation / commissioning dates | **PARTIAL** / **MISSING** | `data_interventie`, `data_montare` / commissioning missing |
| Total investment cost | **MISSING** | needs aggregation per project |
| Financial classification | **MISSING** | |
| Source module | **PARTIAL** | inferable: `source_intervention_id` → Intervenții; OCR rows → `ocr_reparatii_articole.mentenanta_id`; tyres → `'Anvelopa - %'`; there is no explicit `sursa` column on `mentenanta` |

**Existing pieces the page can build on:**
- There is **no** multi-source per-vehicle financial history today:
  - `DashboardModel::getRecentActivity` (:1133-1180) is a text feed only;
  - `DashboardAnaliticV2Model::getLightVehicleCosts` (:2273-2400) covers light vehicles only;
  - `OperationalCostService::computeDetails(scope = 'vehicle')` (:157-168) gives a per-element breakdown per period.
- The page should be a **read model** (a query / service with a `UNION ALL`) over:
  - `mentenanta` (excluding `'Anvelopa - %'`, which has cost 0 by design: `TireModel::buildMaintenancePayload` writes `'cost' => 0.00`);
  - `anvelope` (`purchase_price`);
  - `revizii_v2_lucrari.cost_total`;
  - `cheltuieli_alocari` (`vehicul`);
  - `documente` × configured cost;
  - `inventar_dotari_vehicule`;
  - `fuel_fillups`;
  - `leasing_installments`;
  - and, after Phase 1, `investitii_proiecte`.
- Each row should carry `sursa` + `sursa_id` so it is traceable and so double counting can be prevented (§12).

---

## 11. Integration with Rentabilitate Operațională

| Need | Status | Comment |
|---|---|---|
| Capital investment history | **MISSING** | depends on Phase 1 |
| Depreciation / capital-cost allocation | **PARTIAL** | Mechanism: `cost_operational_elemente.amortizare_ani` + `CostNormalizationService::amortizedEurToAnnualLei` (:64), but **one global value per element** (`valoare_config`), applied identically to every compatible vehicle (`OperationalCostService` :738-774). **No value per asset or per investment.** |
| Vehicle acquisition value | **MISSING** | not in `vehicule`. `leasing_contracts.initial_value` exists but is read only by `LeasingSchedulerModel` (0 rows locally). |
| Vehicle cost/km | **EXISTS** | `OperationalCostService` (sources in `resolveElement` :423-779) |
| Tractor+trailer combined cost | **PARTIAL** | exists (`$carrier`, `ct_sr` category :822), but with the **current** coupling (§7) |
| Investment recovery | **MISSING** | `CostBreakEvenService` is a pure helper with no investment input |
| Operational profitability | **PARTIAL** | `DashboardAnaliticV2Model` profit per trip = billing − (`curse_cheltuieli` + estimated fuel) (:1101, :1737-1790). It is not a full vehicle cost. |

**Data missing for future calculations (formulas not defined here):**
- acquisition value + date per vehicle;
- commissioning date;
- useful life per investment;
- accounting treatment;
- per-period coupling (`data_start/data_end` already exist, only the reading is missing);
- revenue per vehicle or ensemble over the period (exists in trips / Centralizator, not yet linked).

---

## 12. Risks of double counting

| # | Risk | Mechanism (evidence) | Severity |
|---|---|---|---|
| **R1** | Part bought into stock and then entered in Intervenții V2 | Stock rises through OCR `destinatie = 'stoc'` / `updateCatalogPart`. When the intervention is finalized, `IWM::finalize` (:1976) inserts the mount with `direct_mount = 1` and **does not decrement stock**; the cost is the manual `cost_piese`. The part therefore stays in stock (stock value inflated) **and** appears as cost in `mentenanta`. If Istoric Financiar also counted purchases into stock, it would be double. | **High** |
| **R2** | Investment counted as opex today and as depreciation later | Without `natura_cost`, the investment sits in `mentenanta.reparatie` → cost/km element `reparatii`. If capital cost is later introduced through `amortizare`, the same investment appears twice. | **High** (once depreciation is introduced) |
| **R3** | Expense in Cheltuieli (type Reparații / Piese auto / Anvelope) + a `mentenanta` row for the same invoice | No FK between `cheltuieli` and `mentenanta`/OCR. `DashboardAnaliticV2Model::getLightVehicleCosts` sums `cheltuieli_alocari` + `mentenanta.cost` (:2387). Cost/km does **not** read operational `cheltuieli` (only `administrativa`, `OperationalCostModel.php:575-579`), so it is protected there, but the new page would not be if it reads both. | **Medium** |
| **R4** | Component transfer | `unmount(retur_stoc)` + mount from stock on another vehicle prices the used part at the current `pret_achizitie` (VCM:289), so it is counted again on the second vehicle. | **Medium** |
| **R5** | OCR register vs maintenance | The `piese_ocr` element is **disabled** by default precisely for this reason (`cost_operational_elemente.observatii`). Any new consumer must keep that rule. | Mitigated |
| **R6** | Derived mirror `supplier_invoices` / `invoice_vehicle_repairs` / `invoice_repair_parts` | rebuilt from `mentenanta` by `backfillCorrectiveInvoicesFromRecords` (MM:2213). Summing it next to `mentenanta` double counts everything. | High if misused |
| **R7** | Tyres | `mentenanta` mirror row with cost 0 (`TireModel::buildMaintenancePayload`) + cost/km excludes `'Anvelopa - %'` (`OperationalCostModel.php:722`). | Mitigated; rule to keep |
| **R8** | Mounts outside `mentenanta` | `mountMany` and Revizii V2 put cost in `utilizari.cost_unitar` / `revizii_v2_*`, not in `mentenanta`. Adding both `mentenanta.cost_piese` and Σ `utilizari` double counts the mounts made via `saveRecord` / `finalize` / OCR. Adding only `mentenanta` leaves out `mountMany`. | **Medium** |
| **R9** | `inventar_dotari` used for GPL equipment | cost amortized per inspection interval (`OperationalCostModel.php:525-547`). If a pump or compressor were entered there as well as in an intervention, it would be counted twice. | Low (avoid by rule) |

**Recommended rule:**
- `mentenanta` (+ the future `investitii_proiecte`) is the **only ledger of costs attached to a vehicle**.
- Stock is **inventory**, not an expense, until it is consumed.
- `cheltuieli` carries only what has no `mentenanta` equivalent (for example certification fees), or it gets an explicit link.
- Every row of the new page must have `(sursa, sursa_id)` and be deduplicated on them.

---

## 13. Comparison of architecture options

| Criterion | **A. Reuse only (Stoc Piese + Intervenții + a few fields)** | **B. Extend: project header + classification in existing modules** | **C. Dedicated Investiții module** |
|---|---|---|---|
| Code reuse | Maximum | Very high: execution stays in Intervenții / OCR / Cheltuieli | Low: duplicates parts, labour, invoices, documents |
| Database changes | 1–2 columns | 1 small table + 1 enum column carried through + 5 nullable FKs | 4–6 new tables |
| Data consistency | Weak: no project total; budget / status / commissioning have nowhere to live | Good: one cost ledger (`mentenanta`), the project only groups | Risky: a second ledger parallel to `mentenanta` |
| Maintenance complexity | Low, but logic scattered across reports | Medium | High |
| Performance | No impact | Indexed FKs; aggregations per project are trivial | Extra joins / unions |
| Scalability | Limited (one intervention = one "investment") | Good (any number of interventions, invoices, services) | Good |
| Backward compatibility | Full | Full: nullable FKs, `natura_cost` default derived from `record_type` | Needs migrating existing records |
| Risk of duplicated expenses | Medium (R2 unsolved) | Low, if `natura_cost` is respected in cost/km | **High** (R1/R3 multiplied) |
| UI/UX consistency | Good, but incomplete | Good: the same Intervenții / OCR forms + a "Proiect" selector + a project sheet | Weak: a new workflow for the same work |

---

## 14. Recommended architecture

**Option B: extend the existing modules with a light investment-project header.**

### 14.1 Data model (minimum)

**1. New table `investitii_proiecte`** (header only, no amounts entered by hand except the budget):

| Column | Note |
|---|---|
| `id` | |
| `vehicle_id` | FK `vehicule`: the asset, never the coupling |
| `denumire` | |
| `categorie` | e.g. `adaptare_gpl`, `echipare_initiala`, `modernizare` |
| `natura_cost` | `investitie` / `echipare_initiala` |
| `tratament_contabil` | `de_confirmat` / `capitalizabil` / `cheltuiala` |
| `buget_planificat` | |
| `status` | `planificat` / `in_executie` / `finalizat` / `anulat` |
| `data_start` | |
| `data_punere_in_functiune` | |
| `durata_utila_luni` | NULL until accounting decides |
| `observatii`, `created_by`, timestamps | |

**2. Cost nature carried end to end:**
- `natura_cost ENUM('reparatie','mentenanta','investitie','echipare_initiala')` NULL on `mentenanta_interventii_lucrari` and `mentenanta`.
- `ocr_reparatii_articole.tip_lucrare` already exists. Map `imbunatatire` → `investitie`.
- Fix the point where the classification is lost: `OcrPartsMaintenanceSyncService.php:142-161` and `InterventionWorkflowModel::finalize` (:1921-1948) must write `natura_cost`. `record_type` stays as it is for compatibility.

**3. Nullable FKs `proiect_investitie_id`** on:
- `mentenanta_interventii_programate` (main execution);
- `mentenanta` (propagated on finalize / OCR sync);
- `ocr_reparatii` (invoice per vehicle);
- `cheltuieli` (external services, certification);
- `documente` (certificates / approvals resulting from the project).

**4. Cost/km:** the `reparatii` / `revizii` elements exclude `natura_cost IN ('investitie','echipare_initiala')` (this resolves R2). The capital cost enters later through a new resolver per project (Phase 4).

**5. Documents:** a `clasa_document` column on `configurare_costuri_documente_vehicule` (`financiar` / `tehnic` / `adr` / `omologare` / `inspectie_periodica`). Configure RAR / ISCIR as types if needed.

### 14.2 Rules that come with it (process, not code)

- High-value components for an investment are entered either through OCR with `destinatie = 'vehicul'` (keeps the invoice, price and supplier per line), or from stock through `saveRecord`, **not** as a manual `cost_piese` in V2 over a part that is in stock (R1).
- Istoric Financiar Vehicul reads **only**:
  - `mentenanta` (+ project);
  - `anvelope`;
  - `revizii_v2_lucrari`;
  - `cheltuieli_alocari` only where `proiect_investitie_id` is set or the type has no equivalent in maintenance;
  - documents (configured cost);
  - fuel;
  - leasing.
- It never reads `supplier_invoices` / `invoice_*` or `ocr_piese_registru` as cost.

### 14.3 What is explicitly NOT needed

- A separate Investiții module with its own parts, labour or invoices.
- Changes to the `vehicule_cuplaje` model.
- Changes to `inventar_dotari` (it stays for small on-board equipment).

---

## 15. Proposed implementation phases

| Phase | Content | Depends on | Result |
|---|---|---|---|
| **0. Hygiene (no schema change)** | Document the rules in §12/§14.2. Confirm the VPS schema = local schema. Agree with accounting the meaning of "capitalizabil" and the threshold. | none | Stable foundation |
| **1. Classification** | `natura_cost` on `mentenanta_interventii_lucrari` + `mentenanta`. Carry it through in `OcrPartsMaintenanceSyncService` and `IWM::finalize`. Exclude it from the cost/km `reparatii` / `revizii` elements. Selector in the V2 / OCR forms. | 0 | Investments no longer inflate opex (R2 resolved) |
| **2. Investment project** | `investitii_proiecte` + nullable FKs (§14.1.3). Selector "Proiect investiție" in Intervenții V2, OCR, Cheltuieli, Documente. Project sheet: budget vs actual, list of interventions / invoices / documents, status, commissioning date. | 1 | Full GPL scenario covered |
| **3. Istoric Financiar Vehicul** | Read-only service: `UNION ALL` over the sources in §10 with `(sursa, sursa_id, natura_cost, proiect_investitie_id)`. Per-vehicle timeline + filters. Deduplicated by source. | 1 (2 for grouping) | Page live, no new data entry |
| **4. Capital and coupling over time** | Acquisition value / date per vehicle (or a link to `leasing_contracts`). A per-project depreciation resolver in `OperationalCostService`. `getActiveCouplings` replaced by a per-period reading (`data_start/data_end`) for historical calculations. | 2 | Data for Rentabilitate Operațională |
| **5. Traceability of expensive parts (optional)** | Stock receipt ledger (`mentenanta_piese_receptii`: date, quantity, price, supplier, invoice) + optional serial number on the usage row, following the `anvelope` pattern. Fix R1 (V2 finalize decrements stock when the part comes from stock) and R4 (transfer keeps the original cost). | 0 | The full Purchase → Inventory → Installation lifecycle with original cost |
| **6. Rentabilitate Operațională** | Consumes 3 + 4 + revenue from trips / Centralizator. Formulas to be defined with accounting. | 3, 4 | |

---

## Final answer

**Can Fleet App already manage investments made to transform a tractor, truck or semitrailer for GPL transport?**

**Partly, at execution level only.** The app can record per vehicle (correctly on the semitrailer, independently of the coupled tractor) the parts, labour, workshops, dates and documents of each intervention, and it can compute the cost of that intervention.

It **cannot**:
- recognise them as an **investment** (the `imbunatatire` classification is lost and everything becomes `reparatie` / opex);
- **group** several interventions, invoices and services into one adaptation project with a budget, a status and a commissioning date;
- guarantee the **original purchase cost** of a component that passed through stock (`pret_achizitie` is overwritten);
- carry a **capital value** per vehicle or per investment.

**Minimum architectural change:**
1. A cost-nature field (`natura_cost`) carried from the source line into `mentenanta`, and excluded from the cost/km operating elements.
2. A light header table `investitii_proiecte`, linked through nullable FKs to Intervenții V2, `mentenanta`, the OCR register, Cheltuieli and Documente.

All transactions stay in the existing modules, and Istoric Financiar Vehicul becomes a read-only consumer over them. The stock receipt ledger and per-period capital/coupling reading are the next steps, needed for Rentabilitate Operațională.
