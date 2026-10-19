# Facturi — Existing Workflow & WhatsApp (Baileys) Integration Audit

*Audit date: 2026-10-16 · Scope: read-only static analysis of the repository at commit `0fb7770` (branch `main`).*
*No source file, database, cron job or service was changed. No scripts, tests, OCR calls or external API calls were run. No secrets are reproduced; only environment variable **names** are mentioned.*

**Evidence labels used throughout**

| Label | Meaning |
|---|---|
| **VERIFIED** | Confirmed by reading the code (file + line given). |
| **INFERRED** | Suggested by code structure, comments or deployment notes, but not proven by code alone (e.g. depends on server configuration that is not in the repo). |
| **PROPOSED** | A recommendation for the future integration. Not implemented. |

---

## 1. Executive Summary

- **VERIFIED.** Invoice intake is a **two-stage, file-based, scheduled pipeline**, not an event-driven or HTTP one:
  1. `scripts/fetch_invoice_emails.py` (Python, stdlib only) logs into Gmail over **IMAP**, picks scanner emails, and writes each PDF/JPG/PNG attachment plus a `.json` sidecar into the local queue folder `storage/invoices/inbox/`. It does **not** touch the database.
  2. `scripts/process_invoice_inbox.php` (PHP CLI) ingests each file+json pair into the `facturi` table (`status = in_procesare`), then sends pending rows to **Anthropic Claude** (`InvoiceOcrService`, default model `claude-sonnet-5-5`, structured JSON output). The extracted data fills the row and triggers **automatic trip matching** (`InvoiceTripMatcher`). An associated invoice is mirrored as one row in `curse_cheltuieli` (the trip-expense table).
  3. Both run from the cron wrapper `scripts/run_facturi_email.sh` (suggested every 5 minutes, with `flock`).
- **VERIFIED.** The PHP part is **not coupled to Gmail**. It only reads the queue folder and five fields of the sidecar JSON (`fisier`, `nume_original`, `email_subiect`, `email_message_id`, `email_primit_la`). Any producer that writes the same file+json pair is processed the same way.
- **VERIFIED.** The email subject is a **category override, not the only classifier**. Claude always classifies each document by content into 9 hardcoded types. If the subject names exactly one type, that type wins for all documents of the scan. If the subject names the `service` type ("piese", "reparatii", "service", "revizie"…), the scan is sent to a **different module** (the parts registry `?page=ocr_piese`) and never enters Facturi.
- **VERIFIED.** Trip matching is **day-granular only** (no times), uses the **document issue date** (`data_document`), and picks the trip by date first; plate/driver are used **only to narrow when more than one trip covers that date**. Two rules can produce wrong automatic associations (see §6.6 and §12, findings R1 and R2).
- **VERIFIED.** There is **no HTTP/JSON API** for submitting invoices. The only upload path is the session-authenticated, CSRF-protected browser form (`?page=facturi&action=store`), and it does **not** run OCR. The only server-to-server API (`/api/assistant/v1/*`) is read-only and has no upload route.
- **Recommendation (PROPOSED).** The safest first integration point for Baileys is the **existing queue-folder contract** (`storage/invoices/inbox/` file + `.json` sidecar), written by a small adapter running on the same host. That reuses intake, deduplication, OCR, classification, matching and mirroring with **zero PHP changes**. Its limits: WhatsApp provenance would have to go into the `email_*` fields or be lost, and the group allow-list must be enforced on the Node side. A later phase should add explicit channel columns and an authenticated ingestion endpoint (§10, §11).

---

## 2. Existing System Architecture

### 2.1 Components

| Component | Type | File | Role |
|---|---|---|---|
| Gmail fetcher | Python 3 CLI (stdlib: `imaplib`, `email`) | `scripts/fetch_invoice_emails.py` | IMAP search, attachment extraction, writes queue files, Gmail labels |
| Queue folder | Filesystem | `storage/invoices/inbox/` | Hand-off between Python and PHP (`<base>.<ext>` + `<base>.json`) |
| Inbox processor | PHP CLI | `scripts/process_invoice_inbox.php` | Ingest → `facturi`, OCR, matching, parts routing |
| Cron wrapper | POSIX sh | `scripts/run_facturi_email.sh` | Runs both steps, `flock`, log rotation |
| Rematch cron (optional) | PHP CLI | `scripts/rematch_facturi.php` | Re-runs matching for pending invoices (suggested hourly) |
| File storage | PHP service | `htdocs/services/InvoiceStorageService.php` | Validation, SHA-256 content-addressed storage outside the web root |
| OCR/AI | PHP service | `htdocs/services/InvoiceOcrService.php` | Claude Messages API call, prompt, JSON schema, normalization |
| Parts OCR | PHP service (subclass) | `htdocs/services/PartsInvoiceOcrService.php` | Different prompt/schema with line items, for `?page=ocr_piese` |
| Parts routing | PHP service | `htdocs/services/OcrPartsScanService.php` | Subject-based routing into the parts registry |
| Domain model | PHP model | `htdocs/models/InvoiceModel.php` | Types, statuses, CRUD, OCR result application, dedup heuristics, mirroring |
| Matcher | PHP service | `htdocs/services/InvoiceTripMatcher.php` | Expense-to-trip matching rules |
| Mirror writer | PHP service | `htdocs/services/TripExpenseMirrorService.php` | Upserts/deletes `curse_cheltuieli` (+ documents) |
| Web UI | PHP controller + views | `htdocs/controllers/InvoiceController.php`, `htdocs/views/facturi/*` | List, detail, manual upload, link/unlink/reject/delete/purge |
| ACL | Permission module | `htdocs/permissions/modules/facturi.php` | `view/create/edit/link/delete/purge` actions |
| Legacy accommodation | PHP model | `htdocs/models/AccommodationExpenseModel.php` | Owns `cheltuieli_cazare`; projected read-only into `facturi` |

### 2.2 Runtime and deployment facts

- **VERIFIED.** Both scripts refuse to run outside CLI (`process_invoice_inbox.php:27-30`); the Python script reads `.env` itself (`fetch_invoice_emails.py:56-69`).
- **VERIFIED.** The cron line suggested in the wrapper is `*/5 * * * * /bin/sh …/scripts/run_facturi_email.sh` (`run_facturi_email.sh:4-5`). Step 2 runs even if step 1 failed (`run_facturi_email.sh:39-47`).
- **INFERRED.** Whether this cron is installed on the VPS cannot be checked from the repo. Project notes from 2026-10-02 say it was **not installed** then (see §13).
- **INFERRED.** The VPS serves the app with `php -S` from `htdocs` (comment in `InvoiceStorageService.php:7-10`, and README `php -S 127.0.0.1:8000 -t htdocs`). That is why invoice files are kept under `storage/`, outside `htdocs`.

---

## 3. Complete Gmail-to-Facturi Workflow

### 3.1 Verified workflow diagram

```mermaid
flowchart TD
    A["Office MFP scanner<br/>emails scan to Gmail"] --> B["cron */5: run_facturi_email.sh<br/>(flock, log storage/logs/facturi_email.log)"]
    B --> C["fetch_invoice_emails.py<br/>IMAP login, X-GM-RAW search:<br/>from:SENDER, marker/subject,<br/>has:attachment, -label:Procesat, -label:Eroare,<br/>newer_than:N d"]
    C --> D{"is_scanner_email?<br/>sender == SENDER AND<br/>(body marker OR subject prefix)"}
    D -- no --> D1["skip (no label)"]
    D -- yes --> E["For each PDF/JPG/PNG attachment<br/>≤10 MB, non-empty:<br/>sha256, write file then .json<br/>(atomic .part → rename)"]
    E --> F{"≥1 file saved?"}
    F -- yes --> G["Gmail label Facturi/Procesat"]
    F -- no --> H["Gmail label Facturi/Eroare"]
    E --> Q[("storage/invoices/inbox/<br/>&lt;base&gt;.pdf + &lt;base&gt;.json")]
    Q --> P["process_invoice_inbox.php<br/>step 1: ingest"]
    P --> R{"typeFromSubject(subject) == 'service'?<br/>(piese / reparatii / service / revizie)"}
    R -- yes --> S["OcrPartsScanService::ingest<br/>copy to htdocs/uploads/ocr_piese,<br/>ocr_piese_facturi (sursa_key scan:sha)"]
    R -- no --> T["InvoiceStorageService::storeFromPath<br/>ext + finfo MIME check, sha256,<br/>storage/invoices/YYYY/MM/&lt;sha&gt;.ext"]
    T --> U{"facturi.sursa_key 'scan:&lt;sha&gt;' exists?"}
    U -- yes --> U1["count duplicate"]
    U -- no --> V["INSERT facturi<br/>tip=alte, status=in_procesare, sursa=scan,<br/>email_message_id / email_subiect / email_primit_la"]
    U1 --> W["delete inbox pair"]
    V --> W
    V --> X["step 2: getPendingOcr(limit 10)<br/>status=in_procesare AND ocr_incercari &lt; 3"]
    X --> Y["InvoiceOcrService::extract<br/>Claude Messages API, document/image block,<br/>json_schema output"]
    Y -- "InvoiceOcrException" --> Y1["recordOcrFailure:<br/>retry ≤3, then de_verificat"]
    Y -- ok --> Z["applyOcrResult<br/>doc 1 → same row; doc n → new row 'scan:&lt;sha&gt;:n'<br/>tip = subject type ?? OCR type"]
    Z -- "0 documents" --> Z0["status respinsa"]
    Z --> M["update() → runMatching()"]
    M --> MT["InvoiceTripMatcher::match<br/>resolve plate/driver → load trips by date<br/>→ decide()"]
    MT --> MB{"mirrorBlocker?<br/>(no value, not RON, no date, no category)"}
    MB -- blocked --> DV["de_verificat"]
    MB -- ok --> DUP{"findPossibleDuplicate?<br/>(same tip + date + value)"}
    DUP -- yes --> DV
    DUP -- no --> ST["asociata_auto / de_verificat / neasociata<br/>+ match_candidates JSON + match_reason"]
    ST --> MI["syncMirror → TripExpenseMirrorService::upsert<br/>curse_cheltuieli + curse_cheltuieli_documente<br/>(file_path 'facturi:&lt;id&gt;')"]
    S --> S2["step 3: processPending with PartsInvoiceOcrService<br/>→ parts registry, 'De verificat'"]
    UI["Facturi page load (GET ?page=facturi)"] --> RM["rematchPending(50) + Cazare sync"]
    CR["optional cron: rematch_facturi.php (hourly)"] --> RM
    RM --> M
```

### 3.2 Step-by-step trace

#### Stage A — Email retrieval (Python)

| Item | Finding | Evidence |
|---|---|---|
| Protocol | **VERIFIED.** IMAP over SSL (`imaplib.IMAP4_SSL`, default `imap.gmail.com:993`), username/password login (a Google app password, per `.env.example`). Not the Gmail REST API, not OAuth. | `fetch_invoice_emails.py:228-230`, `:72-87` |
| Configuration | **VERIFIED.** `.env` names: `INVOICE_IMAP_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_FOLDER`, `_SENDER`, `_SUBJECT_PREFIX`, `_BODY_MARKER`, `_LABEL_DONE`, `_LABEL_ERROR`, `_LOOKBACK_DAYS`. Username, password, sender and subject prefix are required. | `fetch_invoice_emails.py:72-96`, `.env.example:111-125` |
| Search | **VERIFIED.** Gmail raw search (`X-GM-RAW`): `from:<sender>`, `{"<marker words>" subject:"<prefix words>"}`, `has:attachment`, `-label:Facturi-Procesat`, `-label:Facturi-Eroare`, `newer_than:<N>d`. | `build_query` `:127-149`, `:235-236` |
| Local check | **VERIFIED.** A second, exact check: sender must equal the configured sender **and** (body contains marker, default "Scanned from MFP", **or** subject starts with the prefix). This lets operators type a custom subject (e.g. "cazare") on the scanner. | `is_scanner_email` `:152-159`, `:274-278` |
| Fetch | **VERIFIED.** `BODY.PEEK[]` (does not change read/unread state); folder opened read-only in `--dry-run`. | `:260`, `:231` |
| Scheduling | **VERIFIED.** Run by cron through `run_facturi_email.sh`; `flock -n` prevents overlapping runs; log rotated at 5 MB. | `run_facturi_email.sh:28-64` |

#### Stage B — Attachment download and storage

| Item | Finding | Evidence |
|---|---|---|
| Accepted formats | **VERIFIED.** `application/pdf`, `image/jpeg`, `image/png`; `application/octet-stream` is accepted if the filename extension is `pdf/jpg/jpeg/png`. Everything else is ignored. | `fetch_invoice_emails.py:44-49`, `attachment_parts` `:188-197` |
| Size limit | **VERIFIED.** 10 MB per attachment (same as PHP `InvoiceStorageService::MAX_SIZE`). Empty files rejected. | `:42`, `:288-293`; `InvoiceStorageService.php:18`, `:92-98` |
| Queue file name | **VERIFIED.** `<YYYYmmddHHMMSS>_<imapUid>_<index>_<sha256[:12]>.<ext>` plus `<same>.json`. | `:295-297` |
| Sidecar JSON | **VERIFIED.** `fisier, nume_original, mime, marime, sha256, email_message_id, email_uid, email_de_la, email_subiect, email_primit_la, pagini_scanate, atasament, atasamente_in_email`. | `:298-312` |
| Atomicity | **VERIFIED.** Each file written to `*.part`, then `os.replace`. Data file first, `.json` second; PHP only picks up complete pairs (it globs `*.json`). | `write_atomic` `:204-207`, `:316-318`; `process_invoice_inbox.php:113-119` |
| Labelling | **VERIFIED.** Label `Facturi/Procesat` is added only **after** files are on disk; an email with no valid attachment gets `Facturi/Eroare`. On an exception the email stays unlabelled and is retried next run. | `:321-340` |
| Run status | **VERIFIED.** Summary written to `storage/invoices/inbox/.last_fetch.json`; exit code 2 if any error. | `:372-376` |

#### Stage C — Entry into Facturi (PHP step 1)

| Item | Finding | Evidence |
|---|---|---|
| Schema bootstrap | **VERIFIED.** `InvoiceModel::ensureFacturiSchema()` creates `facturi` if missing and adds later columns (runtime DDL). | `process_invoice_inbox.php:99`; `InvoiceModel.php:81-173` |
| Parts routing | **VERIFIED.** If `OcrPartsScanService::isPartsSubject($meta['email_subiect'])` (i.e. `typeFromSubject() === 'service'`), the scan goes to the parts registry and **skips Facturi**. | `process_invoice_inbox.php:122-135`; `OcrPartsScanService.php:26-29` |
| Permanent storage | **VERIFIED.** `storeFromPath` re-validates extension vs. `finfo` MIME, computes SHA-256, and copies to `storage/invoices/YYYY/MM/<sha256>.<ext>` (mode `0640`, folder `0750`, `.htaccess` "Require all denied" written as a second defence). Same content → same path → not written twice. | `InvoiceStorageService.php:78-144`, `:188-194` |
| Row creation | **VERIFIED.** `facturi` row with `tip='alte'` (placeholder), `status='in_procesare'`, `sursa='scan'`, `sursa_key='scan:<sha256>'`, document metadata, `email_message_id`, `email_subiect`, `email_primit_la`. `create()` skips matching while `in_procesare`. | `process_invoice_inbox.php:142-160`; `InvoiceModel.php:311-348` |
| Cleanup | **VERIFIED.** The inbox pair is deleted after a successful ingest **or** a duplicate. On an exception it stays and is retried next run (no quarantine). | `process_invoice_inbox.php:162-168` |

#### Stage D — Processing trigger and mode

- **VERIFIED.** Processing is **scheduled batch** (cron), synchronous inside each run: OCR is done for at most `--limit` (default **10**) pending rows per run, oldest first (`process_invoice_inbox.php:45`, `:172-211`; `InvoiceModel::getPendingOcr` `:992-1009`).
- **VERIFIED.** Matching is triggered:
  1. right after OCR (`applyOcrResult` → `update()` → `runMatching()`, `InvoiceModel.php:1083-1087`, `:457-463`);
  2. on every **GET of the Facturi list page**: `rematchPending(50)` plus the legacy accommodation sync (`InvoiceController.php:110-120`);
  3. by the optional hourly cron `scripts/rematch_facturi.php` (default limit 500, `rematch_facturi.php:4-19`, `:32`);
  4. by operator actions (edit, rematch, reopen; `InvoiceController.php:288-390`).
- **VERIFIED.** There is no message queue, no webhook and no event bus.

#### Stage E — Status tracking and failures

| Mechanism | Finding | Evidence |
|---|---|---|
| Invoice status | **VERIFIED.** `facturi.status` ENUM: `in_procesare`, `asociata_auto`, `asociata_manual`, `de_verificat`, `neasociata`, `respinsa`. | `InvoiceModel.php:55-62`, migration `:22` |
| Human-readable reason | **VERIFIED.** `facturi.match_reason` (VARCHAR 255) holds the latest decision or error text. | `applyAssociation` `:1252-1278`; `recordOcrFailure` `:1123-1147` |
| OCR attempts | **VERIFIED.** `facturi.ocr_incercari`; max `OCR_MAX_ATTEMPTS = 3`. Transient errors keep `in_procesare`; permanent errors or the 3rd failure → `de_verificat`. Note: a successful read also increments the counter (`saveOcrResult`). | `:985`, `:1012-1024`, `:1123-1147` |
| Run-level status | **VERIFIED.** `.last_fetch.json` and `.last_ocr.json` in the inbox folder, shown on the Facturi page (`scanStatus`). | `InvoiceController.php:463-490`; `process_invoice_inbox.php:220` |
| Logs | **VERIFIED.** Plain text `storage/logs/facturi_email.log` (both scripts' stdout/stderr); `error_log()` for controller errors. | `run_facturi_email.sh:25-26`, `:41-43` |

---

## 4. Email Subject Classification Rules

### 4.1 Recognized expense categories (hardcoded)

**VERIFIED** — `InvoiceModel::TYPES` (`InvoiceModel.php:37-47`). The same keys are the `enum` of the AI JSON schema (`InvoiceOcrService.php:221`).

| `facturi.tip` | Label | `curse_cheltuieli.tip_cheltuiala` (legacy ENUM) | `categorii_cheltuieli_curse.nume` |
|---|---|---|---|
| `cazare` | Cazare | `alte` | Cazare |
| `diurna` | Diurna | `diurna` | Diurna |
| `trece` | Trecere | `trece` | Trecere |
| `taxa_acces` | Taxa acces | `taxa_acces` | Taxa acces |
| `port` | Port | `port` | Port |
| `service` | Reparatii | `service` | Reparatii |
| `spalatorie` | Spalatorie | `alte` | Spalatorie |
| `vulcanizare` | Vulcanizare | `alte` | Vulcanizare |
| `alte` | Alte | `alte` | Alte cheltuieli |

- **VERIFIED.** The type list is **hardcoded in PHP**. The category **rows** live in the DB table `categorii_cheltuieli_curse`, but they are looked up **by name** (`getCategoryIdForType`, `:249-267`). Renaming or deactivating a row in the DB does not change the classifier; a missing name blocks mirroring ("Categoria de cheltuiala … lipseste", `mirrorBlocker` `:1655-1657`). `Spalatorie` and `Vulcanizare` are inserted by `ensureCategories()` (`:175-187`, migration `:62-64`).

### 4.2 Subject parsing — `InvoiceModel::typeFromSubject()` (`InvoiceModel.php:202-240`)

**VERIFIED** algorithm:

1. Lowercase and trim. Empty, or starting with `send data from` (the scanner's default subject) → `null`.
2. Transliterate to ASCII (`iconv … ASCII//TRANSLIT//IGNORE`), replace every non `a-z` run by one space, pad with spaces.
3. For each type, look for any keyword as a **whole word / whole phrase** (`' word '`):

| Type | Keywords (`SUBJECT_KEYWORDS`, `:202-212`) |
|---|---|
| cazare | cazare, cazari, hotel, hoteluri, pensiune, pensiuni, motel |
| diurna | diurna, diurne |
| trece | trecere, treceri, trece, pod, poduri, bac |
| taxa_acces | taxa acces, taxe acces, taxa de acces, taxe de acces, acces |
| port | port, porturi, taxa port, taxe port, taxe portuare |
| service | reparatii, reparatie, service, piese, revizie, revizii |
| spalatorie | spalatorie, spalatorii, spalare, spalari |
| vulcanizare | vulcanizare, vulcanizari, anvelope, cauciucuri |
| alte | alte, alte cheltuieli, altele, diverse |

4. Return the type **only if exactly one type** matched; otherwise `null`.

### 4.3 How the subject is used

| Case | Effect | Evidence |
|---|---|---|
| Subject names exactly `service` | Whole scan → **parts registry** (`ocr_piese_facturi`), not Facturi. | `process_invoice_inbox.php:123`; `OcrPartsScanService.php:26-29` |
| Subject names exactly one other type | Prompt tells Claude to use that type, **and** `applyOcrResult` forces it on **every** document of the scan (`tip = $subjectType ?? $ocrType`). The OCR's own proposal is kept in `observatii` if different. | `InvoiceOcrService.php:192-196`; `InvoiceModel.php:1058`, `:1063`, `:1075-1078` |
| Subject present but no / several types | Passed to Claude as a **hint only** (first 120 chars, newlines removed); the model decides the type. | `InvoiceOcrService.php:197-201` |
| Subject missing or scanner default (`Send data from…`) | No hint; Claude classifies by content. | `InvoiceOcrService.php:190-201`; `InvoiceModel.php:221-223` |
| Model returns an unknown type | Normalized to `alte`. | `InvoiceOcrService.php:271`; `InvoiceModel.php:1061` |

### 4.4 Concrete examples (derived from the code, not executed)

| Subject | `typeFromSubject` | Result |
|---|---|---|
| `Send data from MFP07552300` | `null` | Claude classifies each document by content. |
| `Cazări` | `cazare` | All documents in the scan become `cazare`, even a car-wash receipt in the same scan. |
| `hotel si pod` | `null` (cazare + trece) | Subject only sent as a hint. |
| `Taxa de acces` | `taxa_acces` | Forced type. |
| `piese B 400 NET` | `service` | Routed to the parts registry; plate hint passed to `PartsInvoiceOcrService` (`:78-82`). |
| `port Constanta` | `port` | Forced type. |
| `Factura` | `null` | Hint "Factura" only. |

### 4.5 Independent content classification and reusability

- **VERIFIED.** Claude classifies every document by content using the rules in the system prompt (`InvoiceOcrService.php:172-181`). The subject is optional.
- **VERIFIED.** `typeFromSubject()` is a **pure static function** with no email dependency; it can classify any free-text caption (e.g. a WhatsApp caption). `InvoiceOcrService::extract($path, $mime, ['email_subiect' => …])` takes the hint as a plain string. Both are reusable outside email without changes.
- **VERIFIED.** The naming is email-specific (`email_subiect` hint key and column), but the behavior is not.
- **VERIFIED, notable asymmetry.** A `service` invoice can reach Facturi (subject absent and Claude says `service`) **or** the parts registry (subject says "service"). The routing depends on the subject, not on the content.

---

## 5. OCR and AI Processing

### 5.1 Engine and provider

| Item | Finding | Evidence |
|---|---|---|
| Production OCR | **VERIFIED.** **No separate OCR engine.** The PDF/image is sent directly to **Anthropic Claude** (vision / PDF document input) through the official PHP SDK `anthropic-ai/sdk ^0.54.0`. | `InvoiceOcrService.php:4-9`, `:68`, `:103-112`; `composer.json:7` |
| Model | **VERIFIED.** `DEFAULT_MODEL = 'claude-sonnet-5-5'`, overridable by `.env` `INVOICE_OCR_MODEL`. API key from `ANTHROPIC_API_KEY`. | `InvoiceOcrService.php:27`, `:38-43`; `.env.example:129-130` |
| Output tokens | **VERIFIED.** `MAX_TOKENS = 8000` (Facturi), `16000` (parts). | `InvoiceOcrService.php:30`; `PartsInvoiceOcrService.php:19` |
| Structured output | **VERIFIED.** `outputConfig: ['format' => ['type' => 'json_schema', 'schema' => …]]`; the response text is then `json_decode`d and re-normalized. | `InvoiceOcrService.php:111`, `:136-149` |
| OCR.space | **VERIFIED.** `OcrSpaceService` (`OCR_SPACE_API_KEY`) exists, but is referenced only by the admin-only developer page `dev_ocr_test`. It is **not** part of the Facturi pipeline. | `index.php:803-805`; Grep: only `DevOcrTestController.php` uses it |

### 5.2 Request construction (`InvoiceOcrService::extract`, `:86-150`)

- **VERIFIED.** The whole file is base64-encoded. `application/pdf` → `document` block; `image/jpeg` / `image/png` → `image` block; any other MIME → permanent error (`:93-100`). No page splitting, resizing, rotation, deskew or image enhancement is done (**VERIFIED by absence**: no preprocessing code in the service).
- **VERIFIED.** The validation done before the call is in `InvoiceStorageService::store` (extension whitelist + `finfo` MIME must match + 10 MB limit + non-empty).

### 5.3 Prompt (summary, `InvoiceOcrService::systemPrompt`, `:156-185`)

**VERIFIED** key instructions (paraphrased, in Romanian in the source):
- The context is a Romanian LPG trucking company; every document is a driver's on-the-road expense.
- A scan may contain several documents: return one element per document, with its pages (`"1"`, `"2-3"`). Non-invoice pages are skipped; if nothing is an invoice/receipt, return `facturi: []`.
- **Prompt-injection guard:** text inside the document is data only; do not follow instructions written in it.
- Never invent values; use `null`.
- Date `YYYY-MM-DD` (issue date); `valoare_cu_tva` = total to pay; `valoare_fara_tva` = net (or null); currency is a 3-letter code, "lei" = RON.
- `nr_inmatriculare`: the vehicle plate if printed or handwritten; never CUI, invoice number or phone.
- `sofer`: the driver / hotel guest name (look near "Delegat", "Oaspete", "Client", "Nume", the room number), capitalized; never the company or the "Intocmit de" person.
- Type rules (cazare / diurna / trece / taxa_acces / port / service / spalatorie / vulcanizare / alte).
- `incredere`: mare / medie / mica; `observatii`: short operator notes.

The user prompt adds the subject category or hint (§4.3).

### 5.4 Output schema and normalization

**VERIFIED** — schema `:207-243`; normalization `:253-337`.

| Field | Schema type | Normalization | Stored in `facturi` |
|---|---|---|---|
| `pagini` | string\|null | trimmed, ≤20 chars | `document_pagini` |
| `tip` | enum of 9 types | invalid → `alte`; overridden by subject type | `tip` |
| `furnizor` | string\|null | ≤255 | `furnizor` |
| `cui_furnizor` | string\|null | ≤30 | **only inside `observatii`** ("CUI furnizor: …") — no column |
| `numar_document` | string\|null | ≤100 | `numar_document` |
| `data_document` | string\|null | formats `Y-m-d`, `d.m.Y`, `d/m/Y`, `d-m-Y`; year 2000..current+1, else null | `data_document` (DATE) |
| `valoare_fara_tva` | number\|null | RO/EN decimal parsing; must be `0 < x < 10,000,000`, else null (negatives / credit notes dropped) | `valoare_fara_tva` |
| `valoare_cu_tva` | number\|null | same | `valoare_cu_tva` |
| `moneda` | string\|null | `LEI` / empty → `RON`; must be `[A-Z]{3}` else `RON` | `moneda` |
| `nr_inmatriculare` | string\|null | ≤30 | `nr_inmatriculare_extras` |
| `sofer` | string\|null | ≤150 | `sofer_extras` |
| `incredere` | enum mare/medie/mica | invalid → `mica` | **only in `observatii`** ("Citire automata, incredere …") and `ocr_raw` |
| `observatii` | string\|null | ≤500 | merged into `observatii` |

- **VERIFIED.** The full normalized array, model name, token usage and timestamp are stored in `facturi.ocr_raw` (JSON) for **every** row of the scan (`applyOcrResult` `:1045`, `saveOcrResult` `:1012-1024`). The raw model text is **not** stored (only the normalized result).

### 5.5 Extracted vs. merely supported fields

| Field | Extracted by AI? | Column exists? | Notes |
|---|---|---|---|
| Vehicle plate | **Yes** (single string) | `nr_inmatriculare_extras` + resolved `vehicle_id` | **VERIFIED** |
| Driver name | **Yes** (single string) | `sofer_extras` + resolved `driver_id` | **VERIFIED** |
| Issue date | **Yes** | `data_document` (DATE) | **VERIFIED** |
| **Time** of transaction | **No** | **No** column | **VERIFIED**: not in schema, not in table |
| Transaction date (vs. issue date) | **No** distinction | — | **VERIFIED**: only "data emiterii documentului" is asked |
| Amounts (net / gross) | **Yes** | yes | **VERIFIED** |
| Currency | **Yes** | `moneda` | **VERIFIED** |
| Supplier name | **Yes** | `furnizor` | **VERIFIED** |
| Supplier CUI (tax ID) | **Yes** | **No column** (text in `observatii`) | **VERIFIED** |
| Invoice number | **Yes** | `numar_document` | **VERIFIED** |
| Confidence | **Yes** | **No column** (text + `ocr_raw`) | **VERIFIED** |
| Line items, km, warranty, multiple plates | Only in **parts** flow (`PartsInvoiceOcrService`) | in `ocr_piese_*` / `ocr_reparatii_articole` | **VERIFIED** (`PartsInvoiceOcrService.php:88-141`) |
| Email receipt time | Not AI — from the email `Date` header | `email_primit_la` | **VERIFIED**: stored but **never used** for matching |

### 5.6 Multiple documents / vehicles / operations

- **VERIFIED — multiple documents in one scan:** the first document fills the original row; each next one becomes a new `facturi` row with `sursa_key = scan:<sha>:<n>`, the **same file** and its own `document_pagini` (`InvoiceModel.php:1083-1114`). Re-running after a partial failure reuses existing rows (`:1091-1097`). Siblings are shown on the detail page (`getScanSiblings`, `:685-705`).
- **VERIFIED — multiple vehicles on one Facturi invoice:** **not supported.** The schema has one `nr_inmatriculare` string; the row has one `vehicle_id`, one `cursa_id` and one mirror row. A multi-truck invoice can only be linked to one trip; there is no split.
- **VERIFIED — multiple operations (line items):** not extracted in Facturi; only totals. The parts flow extracts line items and per-line plate/km (`PartsInvoiceOcrService.php:41-56`).

### 5.7 Error handling, retries and logs

**VERIFIED** (`InvoiceOcrService.php:113-127`, `:136-139`; `InvoiceModel::recordOcrFailure`):

| Condition | Retryable? | Outcome |
|---|---|---|
| Rate limit, 5xx, connection, timeout (incl. 529) | yes | stays `in_procesare`, `ocr_incercari+1`; after 3 → `de_verificat` |
| Other 4xx | no | `de_verificat` immediately |
| `stop_reason = refusal` | no | `de_verificat` |
| `stop_reason = max_tokens` (truncated) | no | `de_verificat` |
| Response not JSON | yes | retried |
| Missing API key | no | `de_verificat` |
| File missing on disk | no | `de_verificat` (`process_invoice_inbox.php:177-181`) |
| 0 documents found | — | `respinsa` (operator can reopen) (`InvoiceModel.php:1047-1052`) |

- **INFERRED.** The Anthropic PHP SDK may also retry internally by default; the code does not set or document it.
- **VERIFIED gap.** Step 2 catches only `InvoiceOcrException` (`process_invoice_inbox.php:184-191`). A `PDOException` or any other `Throwable` thrown by `applyOcrResult`/`runMatching` (or an SDK exception class outside the caught list) **aborts the whole run**, so the remaining rows and step 3 (parts) are skipped until the next run.
- **VERIFIED.** Per-run logging is printed to stdout/stderr → `facturi_email.log`; per-invoice outcome text goes to `match_reason`.

---

## 6. Expense-to-Trip Matching Logic

Main files: `htdocs/services/InvoiceTripMatcher.php`, `InvoiceModel::runMatching()` (`InvoiceModel.php:768-837`).

### 6.1 Inputs

**VERIFIED** (`runMatching` `:801-808`): `tip`, `data_document`, `vehicle_id`, `driver_id`, `nr_inmatriculare_extras`, `sofer_extras`. **Not used:** `email_primit_la`, `created_at`, amount, supplier, time of day.

### 6.2 Trip data model used by the matcher

- **VERIFIED.** `curse_dispecer.data_inceput` / `data_sfarsit` are `DATE NOT NULL`; `ora_inceput` / `ora_sfarsit` are separate `TIME NULL` columns (`database/database.sql:1010-1013`). The matcher **reads only the dates** and parses them with `!Y-m-d` (`parseDate`, `:328-338`). Times are ignored.
- **VERIFIED.** Resumed / multi-phase trips: one `curse_dispecer` row with child rows in `curse_segmente` (`ordine`, `vehicle_id`, `driver_id`, `data_inceput`, `ora_inceput`, `data_sfarsit`, `ora_sfarsit`) (`database/migrations/2026_09_18_000002_curse_segmente.sql:8-30`). The month-split script also creates **segments** ("faza #<segment id>", `scripts/split_curse_pe_luni.php:92`), so a split trip is still a single trip for matching.

### 6.3 Execution order

**`InvoiceModel::runMatching($id, $force)`**

1. Skip (return unchanged) if: deleted, `respinsa`, `in_procesare`, `legacy_cazare` (owned by the Cazare module), or `fara_asociere_auto = 1` without `$force` (`:787-792`).
2. If `asociata_manual` and the trip still exists → only re-sync the mirror; **manual choices are sticky** (`:794-798`).
3. Call `InvoiceTripMatcher::match()`.
4. **Mirror blocker** — if the result is `asociata_auto` but the value is missing/≤0, the currency is not `RON`, the date is missing, or the category row is missing → downgrade to `de_verificat` (`:815-820`, `mirrorBlocker` `:1643-1660`).
5. **Possible duplicate** — another non-rejected, non-deleted `facturi` row with the same `tip`, the same `data_document` and `|valoare_cu_tva| < 0.01`; skipped if both have different document numbers, or clearly different driver/vehicle → `de_verificat` with "Posibil duplicat al facturii #N" (`:824-832`, `findPossibleDuplicate` `:844-884`).
6. Persist `status`, `cursa_id`, `match_candidates` (JSON), `match_reason`, resolved `vehicle_id`/`driver_id`, then `syncMirror()` (`applyAssociation` `:1252-1278`).

**`InvoiceTripMatcher::match()`** (`:351-371`)

1. **Resolve the vehicle:** given `vehicle_id`, else exact match of the normalized plate (`[^A-Z0-9]` removed, upper-case) against **all** vehicles; active ones are indexed first (`resolveVehicleId` `:373-392`).
2. **Resolve the driver:** given `driver_id`, else exact match of the normalized name (lowercase, ASCII, letters and spaces), then the same with the word order reversed ("Ion Popescu" ↔ "Popescu Ion"), among non-terminated drivers (`resolveDriverId` `:395-426`).
3. **Load candidates:** non-deleted trips where `data_inceput <= date + before` and `data_sfarsit >= date − after`, with their non-deleted segments (`loadCandidateTrips` `:434-468`, `attachSegments` `:484-524`).
4. `decide()`.

**`InvoiceTripMatcher::decide()`** (`:107-194`)

1. Unknown type → `de_verificat`. Missing date → `de_verificat` "Lipseste data documentului."
2. **Tolerance window** per type: default `[0, 0]` days; `cazare` `[0, +1]` (a hotel invoice may carry the check-out date) (`TOLERANCES` `:28-31`).
3. Every trip whose window contains the date is a match. Each match records its **participants on that date** and whether it is **strict** (the date is inside the real trip dates).
4. No match → `neasociata` "Nicio cursa in perioada documentului."
5. **Strict preference:** if any strict match exists, all tolerance-only matches are dropped (`:150-154`). This is **global**, applied before any plate/driver check.
6. **Identity narrowing — only if more than one match remains** (`:159-179`), in this order:
   - a. **Plate:** keep the trips whose participant has the same `vehicle_id` or the same normalized plate.
   - b. **Driver:** keep the trips whose participant has the same `driver_id` or `namesMatch()` (every invoice word of ≥2 letters is found in the app name, any order, Levenshtein ≤1 for words ≥5 letters) (`:228-250`).
   - A filter that matches **none** of the trips is **ignored** (a note is added); a filter that would not reduce the set changes nothing.
7. Exactly 1 left → `asociata_auto` "Potrivire unica: …". 2+ → `de_verificat` with the candidate list. (There is no third path.)

**Participants on a date** (`participantsOn` `:261-292`):
- no segments → the trip's own vehicle/driver;
- date before the trip (tolerance zone) → first segment; after the trip → last segment;
- otherwise every segment whose `[data_inceput, data_sfarsit]` contains the date (several on a handover day); if the date falls in a gap between segments → **all** segments.

### 6.4 Ambiguity and confidence representation

- **VERIFIED.** No numeric score. Ambiguity is expressed by `status = de_verificat` + `match_candidates` (JSON list of `{id, data_inceput, data_sfarsit, nr_inmatriculare, sofer, motiv}`) + the free-text `match_reason` (`candidateSummary` `:306-320`). The AI `incredere` value does **not** influence matching.

### 6.5 Operator confirmation and correction

| Action | Endpoint | Effect | Evidence |
|---|---|---|---|
| Confirm a candidate / pick another trip | `POST action=confirm` / `choose` (`cursa_id`) | `asociata_manual`; trip must be active and the blocker clear | `InvoiceController.php:316-339`; `InvoiceModel::linkManually` `:887-922` |
| Pick list | detail page | Trips within ±7 days of `data_document` (`MANUAL_PICK_WINDOW_DAYS`) | `InvoiceController.php:166-173`; `InvoiceTripMatcher.php:34`, `:475-478` |
| Unlink | `POST action=unlink` | `neasociata`, `fara_asociere_auto = 1`, mirror row deleted | `InvoiceModel::unlink` `:929-956` |
| Rerun | `POST action=rematch` (id) | forced rematch, or reopen if `respinsa` | `:363-390` |
| Edit key fields | `POST action=update` | change of `tip`/date/plate/driver/vehicle/driver id resets a manual link and re-matches | `InvoiceModel::update` `:401`, `:430-465` |
| Reject | `POST action=reject` | `respinsa`, mirror removed | `:958-978` |

- **VERIFIED.** Automatic association is applied **without operator confirmation** when exactly one candidate remains.
- **VERIFIED.** When no trip is found, the invoice stays `neasociata` and is retried by `rematchPending` (on page load, by the cron, or by "Reverifica") until a trip appears (`:491-529`).

### 6.6 Illustrative input → output (traced through the code, not executed)

Assume these trips exist (dates inclusive):

| Trip | Dates | Vehicle | Driver | Segments |
|---|---|---|---|---|
| #101 | 2026-10-01 → 2026-10-03 | B 400 NET | Popescu Ion | none |
| #102 | 2026-10-02 → 2026-10-04 | B 500 ABC | Ionescu Dan | none |
| #103 | 2026-10-04 → 2026-10-06 | B 600 XYZ | Marin Vasile | seg 1: 10-04 → 10-05 Marin; seg 2: 10-05 → 10-06 Georgescu Ana |

| # | Invoice | Trace | Output |
|---|---|---|---|
| 1 | `taxa_acces`, 2026-10-03, plate "b-400 net" | #101 and #102 both strict → plate filter keeps #101 | `asociata_auto` → #101, "Potrivire unica: data documentului + nr. auto." |
| 2 | `taxa_acces`, 2026-10-03, no plate, no driver | 2 strict matches, no identity filters | `de_verificat`, candidates #101, #102 |
| 3 | `trece`, 2026-10-03, plate "CT 99 ZZZ" (not on any trip) | plate filter matches nothing → ignored → still 2 | `de_verificat` + note "nr. auto de pe factura nu apare pe nicio cursa din acea zi" |
| 4 | `cazare`, 2026-10-04, driver "popescu ion" | #101 via tolerance (+1), #102 strict, #103 strict → strict preference drops #101 → driver filter finds nothing among #102/#103 → ignored | `de_verificat` between #102/#103 — **Popescu's own trip #101 is not even a candidate** |
| 5 | `cazare`, 2026-10-07, driver "Georgescu Ana" | only #103 via tolerance → after trip → last segment (Georgescu) | `asociata_auto` → #103 |
| 6 | `service`, 2026-10-05, plate "B 600 XYZ", driver "Marin" | only #103 matches → single match | `asociata_auto` → #103 (handover day: both segment drivers count as participants) |
| 7 | `diurna`, 2026-10-01, plate "B 999 OTH" (vehicle with no trip) | only #101 covers 10-01 → **single match, no identity check** | `asociata_auto` → #101 (**wrong vehicle**) — see risk R1 |
| 8 | any type, value in EUR, clear single match | blocker | `de_verificat` "Valoare in EUR: … introdu valoarea in RON." |
| 9 | Same receipt scanned twice (new paper scan → different bytes) | second row: same tip + date + value | `de_verificat` "Posibil duplicat al facturii #N …" |
| 10 | No date read | — | `de_verificat` "Lipseste data documentului." |

### 6.7 Legacy accommodation matching (for completeness)

- **VERIFIED.** Rows projected from `cheltuieli_cazare` (`sursa = legacy_cazare`) are matched by the **Cazare module**, with a different, older rule: the driver's own trips where `date BETWEEN data_inceput AND data_sfarsit`, on `curse_dispecer.driver_id` only (**segments ignored**, no tolerance) (`AccommodationExpenseModel::findMatchingRaces` `:229-253`). Facturi only re-projects the result (`InvoiceModel::syncLegacyCazare` `:1311+`).

---

## 7. Database Schema and Relationships

### 7.1 `facturi` (invoice register) — **VERIFIED**

Defined in `database/migrations/2026_10_01_000001_facturi.sql:7-58` and at runtime in `InvoiceModel::ensureFacturiSchema()` / `ensureColumns()` (`InvoiceModel.php:89-173`).

| Column | Type | Purpose |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `tip` | VARCHAR(30) NOT NULL | one of the 9 types (validated in PHP, not by DB) |
| `furnizor`, `numar_document` | VARCHAR | supplier, invoice no. |
| `data_document` | DATE | issue date — the matching key |
| `valoare_fara_tva`, `valoare_cu_tva` | DECIMAL(12,2) | |
| `moneda` | CHAR(3) default RON | |
| `nr_inmatriculare_extras`, `sofer_extras` | VARCHAR | text read by AI / typed |
| `vehicle_id` → `vehicule.id` | FK, ON DELETE SET NULL | resolved vehicle |
| `driver_id` → `soferi.id` | FK, ON DELETE SET NULL | resolved driver |
| `cursa_id` → `curse_dispecer.id` | FK, ON DELETE SET NULL | associated trip |
| `curse_cheltuiala_id` → `curse_cheltuieli.id` | FK, ON DELETE SET NULL | mirror row |
| `status` | ENUM(6) | see §3.2-E |
| `match_candidates` | JSON | candidate list |
| `match_reason` | VARCHAR(255) | last decision / error |
| `fara_asociere_auto` | TINYINT | 1 = operator unlinked; skip auto matching |
| `sursa` | **ENUM('scan','manual','legacy_cazare')** | intake source |
| `sursa_key` | VARCHAR(120) NOT NULL, **UNIQUE** | dedup key: `scan:<sha>`, `scan:<sha>:<n>`, `manual:<sha>[:<ts>]`, `legacy_cazare:<id>` |
| `legacy_cazare_id` | INT, **UNIQUE** | link to `cheltuieli_cazare` (no FK) |
| `document_path`, `_original_name`, `_mime`, `_size`, `document_sha256` | | file metadata; path relative to project root |
| `document_pagini` | VARCHAR(20) | pages of this document in the scan *(added at runtime)* |
| `ocr_raw` | JSON | normalized AI output + model + usage + timestamp |
| `ocr_incercari` | TINYINT | OCR attempts *(runtime)* |
| `email_message_id`, `email_subiect`, `email_primit_la` | VARCHAR/DATETIME | email provenance *(runtime)* |
| `observatii` | TEXT | notes (incl. CUI, confidence) |
| `created_by` → `utilizatori.id` | FK SET NULL | NULL for scans |
| `created_at`, `updated_at`, `deleted_at` | DATETIME | soft delete |

Indexes: `uk_facturi_sursa_key`, `uk_facturi_legacy_cazare`, `idx_facturi_status (status, deleted_at)`, `idx_facturi_tip`, `idx_facturi_data`, `idx_facturi_cursa`, `idx_facturi_vehicle`, `idx_facturi_driver`, `idx_facturi_cheltuiala`, `idx_facturi_sha`, `idx_facturi_created_by`.

**VERIFIED gaps:** no unique index on `email_message_id`; no index on `(tip, data_document, valoare_cu_tva)` used by the duplicate heuristic (`idx_facturi_data` helps); no column for channel/sender/group, CUI, confidence or transaction time.

### 7.2 Related tables

| Table | Key columns / constraints | Relationship | Evidence |
|---|---|---|---|
| `curse_dispecer` (trips) | `id`; `vehicle_id` NOT NULL FK RESTRICT; `driver_id` FK SET NULL; `data_inceput`/`data_sfarsit` DATE; `ora_*` TIME; `deleted_at` | `facturi.cursa_id` | `database/database.sql:1003-1066` |
| `curse_segmente` | `cursa_id` FK CASCADE; `ordine` (UNIQUE with cursa_id); vehicle/driver FKs; dates + times | read by the matcher | migration `2026_09_18_000002`, `:8-30` |
| `curse_cheltuieli` (trip expenses) | `cursa_id` FK CASCADE; `tip_cheltuiala` ENUM; `categorie_id` FK SET NULL; `cazare_id` UNIQUE; `locatie`, `bucati`, `pret_unitar`; `refacturare_*`; `suma`; `data_cheltuiala` | mirror row; pointed to by `facturi.curse_cheltuiala_id` (**no reverse column** — the link is one-way) | `database.sql:1132-1172` |
| `curse_cheltuieli_documente` | `cheltuiala_id` FK CASCADE; `file_path` = `facturi:<id>` for Facturi rows | rewritten on every mirror sync | `database.sql:1174-1184`; `InvoiceModel.php:1623-1634`; `helpers.php:1046` |
| `categorii_cheltuieli_curse` | `nume` UNIQUE; `legacy_key` UNIQUE; `activ` | looked up by name from `TYPES` | `database.sql:1068-1089` |
| `vehicule`, `soferi`, `utilizatori` | | FK targets | |
| `cheltuieli_cazare` (+ `_documente`) | accommodation register | projected into `facturi` (`sursa=legacy_cazare`) | `database.sql:1093-1130` |
| `ocr_piese_facturi`, `ocr_reparatii`, `ocr_reparatii_articole`, `ocr_piese_clasificari` | parts registry; scan columns `sursa`, `sursa_key` UNIQUE, `status`, `ocr_incercari`, `ocr_eroare`, `email_subiect`, `email_primit_la` added at runtime | target of subject-routed scans | `OcrPartsModel.php:1789-1866` |
| `assistant_channels` | `provider`, `external_identifier`, `user_id`, `employee_id` | maps external chat identities (comment says "WhatsApp etc.") to app users; used only by the assistant API | `AssistantChannelModel.php:5-21` |

### 7.3 Relationship diagram (implemented FKs only)

```mermaid
erDiagram
    facturi }o--|| vehicule : "vehicle_id (SET NULL)"
    facturi }o--|| soferi : "driver_id (SET NULL)"
    facturi }o--|| curse_dispecer : "cursa_id (SET NULL)"
    facturi |o--o| curse_cheltuieli : "curse_cheltuiala_id (SET NULL)"
    facturi }o--|| utilizatori : "created_by (SET NULL)"
    curse_cheltuieli }o--|| curse_dispecer : "cursa_id (CASCADE)"
    curse_cheltuieli }o--|| categorii_cheltuieli_curse : "categorie_id (SET NULL)"
    curse_cheltuieli_documente }o--|| curse_cheltuieli : "cheltuiala_id (CASCADE)"
    curse_segmente }o--|| curse_dispecer : "cursa_id (CASCADE)"
```

`facturi.legacy_cazare_id` → `cheltuieli_cazare.id` is a **logical link only** (unique index, no FK). `curse_cheltuieli_documente.file_path = 'facturi:<id>'` is a **string convention**, not an FK.

### 7.4 Audit logs

- **VERIFIED by absence.** Neither `InvoiceModel` nor `InvoiceController` writes to `audit_log`, `cursa_audit_log` or any activity table (Grep for `audit_log|log_activity|AuditLog|activity_log` finds no Facturi file; `ActivityReportModel` has no `facturi` reference). The only traces are `created_by` (NULL for scans), `updated_at`, `match_reason` (overwritten each time), `ocr_raw`, and the text log file. Who confirmed, unlinked, rejected or edited an invoice, and what the previous values were, is **not recorded**.

---

## 8. Existing API Endpoints and Services

### 8.1 Facturi HTTP routes (session web app)

All under `index.php?page=facturi` → `require_auth()` → `InvoiceController::handle()` → `require_page_or_403('facturi')` (`index.php:772-775`; `InvoiceController.php:29-92`). Write actions are POST-only + CSRF (`ensure_csrf_or_redirect`) + per-action ACL (`requireAction`, `:639-644`; `permissions/modules/facturi.php:22-40`).

| Action | Method | Permission | Notes |
|---|---|---|---|
| `index` / `list` | GET | view | also runs rematch + Cazare sync on each load |
| `view` | GET | view | detail, candidates, OCR details |
| `document` | GET | view | streams the file, `Content-Disposition: inline`, `nosniff`, `no-store`; path checked against allowed roots |
| `store` | POST multipart `document_upload` | create | **manual upload** — see 8.2 |
| `update` | POST | edit | |
| `confirm`, `choose` | POST | link | manual association |
| `unlink`, `rematch`, `reject` | POST | link | |
| `delete` | POST | delete | soft delete |
| `purge` | POST | purge | hard delete incl. the file; frees `sursa_key` |

All responses are HTML redirects with flash messages; **there is no JSON variant** and no status endpoint.

### 8.2 Manual upload (`storeAction`, `InvoiceController.php:242-286`)

- **VERIFIED.** Multipart field `document_upload`, validated by `InvoiceStorageService::storeUpload` (`is_uploaded_file`, PHP upload errors, 10 MB, extension whitelist, `finfo` match) (`InvoiceStorageService.php:54-71`).
- **VERIFIED.** The operator must type `tip`, `data_document`, `valoare_cu_tva` (>0), the currency, and a vehicle **or** driver (`collectFormData` `:510-564`).
- **VERIFIED.** `sursa='manual'`, `sursa_key='manual:<sha>'` (if a soft-deleted row exists: `manual:<sha>:<timestamp>`), status `neasociata`, then matching runs immediately. **No OCR is called** for manual uploads.

### 8.3 Internal service methods reusable by another intake

| Method | Reusable without email? | Notes |
|---|---|---|
| `InvoiceStorageService::storeFromPath($path, $name)` | **Yes** | validation + content-addressed storage |
| `InvoiceModel::findBySourceKey($key)` | **Yes** | dedup |
| `InvoiceModel::create($data)` with `status='in_procesare'`, `sursa='scan'` | **Yes**, but `sursa` must be one of the 3 ENUM values | email columns are optional (nullable) |
| `InvoiceOcrService::extract($path, $mime, ['email_subiect' => $hint])` | **Yes** | the hint key is just a string |
| `InvoiceModel::applyOcrResult($id, $docs, $meta)` | **Yes** for `sursa='scan'` only (`:1041`) | reads the subject type from `facturi.email_subiect` |
| `InvoiceModel::runMatching`, `InvoiceTripMatcher::match/decide` | **Yes** | `decide()` is pure (tested offline by `scripts/test_invoice_matcher.php`) |
| `InvoiceModel::typeFromSubject($text)` | **Yes** | pure static |
| `OcrPartsScanService::ingest($path, $meta)` | **Yes** | expects the same meta keys |

### 8.4 Server-to-server API

- **VERIFIED.** `/api/assistant/v1/*` is detected before the session starts (`index.php:4-11`, `:430-436`). Routes: `GET v1/trips/today`, `GET v1/vehicle-document`, `GET|HEAD v1/download/{token}` (`AssistantApiController.php:39-74`).
- **VERIFIED.** Auth: shared secret header `X-Assistant-Token` compared with `hash_equals` to `FLEET_ASSISTANT_API_TOKEN` (an unset token always rejects) (`:346-355`), plus the user identity `X-Assistant-Provider` + `X-Assistant-User` → `assistant_channels` → active user (`:360-397`). The provider is a free `[a-z0-9_-]{1,30}` string, so `whatsapp` would fit.
- **VERIFIED.** **No upload / POST route exists**, and no Facturi functionality is exposed.

### 8.5 Can Baileys post to an existing endpoint?

- **VERIFIED: not safely.** `action=store` needs a browser session cookie (login has email 2FA per project notes), a CSRF token, manually typed fields, and it skips OCR. Automating it would mean impersonating a user session; that is **not recommended**.
- **Conclusion.** An ingestion adapter is required. Its least invasive form is the **queue-folder contract** (§11).

---

## 9. Error Handling and Duplicate Prevention

### 9.1 Duplicate layers (all VERIFIED)

| # | Layer | Mechanism | Catches | Misses |
|---|---|---|---|---|
| D1 | Gmail search | `-label:Facturi/Procesat`, `-label:Facturi/Eroare`, `newer_than:N d` (`fetch_invoice_emails.py:143-148`) | the same email on later runs | an email whose label was removed; emails older than the lookback window are simply never seen |
| D2 | Message-ID | stored in `facturi.email_message_id` | **nothing** — never checked, not unique | forwarded/re-sent emails |
| D3 | File hash | `sursa_key = scan:<sha256>` UNIQUE + `findBySourceKey` before insert (`process_invoice_inbox.php:142-146`) | byte-identical files (e.g. label failure → re-download) | a re-scan of the same paper (different bytes); a recompressed image |
| D4 | Storage | content-addressed path `<sha>.<ext>` (`InvoiceStorageService.php:125-135`) | duplicate disk writes | — |
| D5 | Cross-source heuristic | `findPossibleDuplicate`: same `tip` + `data_document` + `valoare_cu_tva` across **all** sources (scan, manual, legacy cazare) (`InvoiceModel.php:844-884`) | re-scans, scan + manual entry, scan + Cazare | different type read by OCR, wrong date/amount, an invoice with a missing value |
| D6 | Manual uploads | `manual:<sha>` + "already exists" warning (`InvoiceController.php:258-270`) | the same file uploaded twice | — |
| D7 | Multi-doc reruns | `scan:<sha>:<n>` reuse (`InvoiceModel.php:1091-1097`) | partial-failure reruns | — |
| D8 | Parts registry | `ocr_piese_facturi.sursa_key = scan:<sha>` UNIQUE (`OcrPartsScanService.php:41-48`) | identical files | same as D3 |

Note: `scan:<sha>` and `manual:<sha>` are different keys, so the same file arriving by scan and by manual upload produces two rows; only D5 catches it. D5 sets only the row currently being matched to `de_verificat`; the earlier row keeps its association (`InvoiceModel.php:824-832`).

### 9.2 Error-handling summary

| Stage | Failure | Behavior | Evidence |
|---|---|---|---|
| Fetch | IMAP login/connection | exit 1; step 2 still runs | `fetch_invoice_emails.py:363-370`; wrapper `:39-47` |
| Fetch | one email fails | stays unlabelled, retried next run | `:338-340` |
| Ingest | invalid MIME / storage error | logged; the pair stays in the inbox and is **retried every run forever** | `process_invoice_inbox.php:165-168` |
| Ingest | incomplete pair | skipped with an error line | `:116-119` |
| OCR | see §5.7 | retry ≤3 → `de_verificat` | |
| OCR/match | non-`InvoiceOcrException` | **aborts the run** | `:184-191` |
| Mirror | blocker (no value / not RON / no date / no category) | `de_verificat` | `InvoiceModel.php:1643-1660` |
| Mirror | trip deleted | `rematchPending` picks it up again | `:500-504` |

---

## 10. WhatsApp/Baileys Integration Feasibility

### 10.1 Verified facts relevant to the design

| # | Fact | Evidence |
|---|---|---|
| F1 | The PHP pipeline consumes **any** file+json pair in `storage/invoices/inbox/`; it does not check where it came from. | `process_invoice_inbox.php:113-169` |
| F2 | It reads only `fisier`, `nume_original`, `email_subiect`, `email_message_id`, `email_primit_la`; **other keys are ignored and lost**. | `:115`, `:123`, `:137`, `:147-156` |
| F3 | `fisier` is reduced to `basename()` (no path traversal); the file must exist. | `:115-116` |
| F4 | Every email column is nullable; processing works with no email at all (manual uploads prove it). | migration; `InvoiceController::storeAction` |
| F5 | `facturi.sursa` is an ENUM of 3 values; a new channel value needs DDL. | migration `:28` |
| F6 | OCR, classification and matching do not depend on email. | §4.5, §6.1 |
| F7 | Accepted formats are only PDF/JPEG/PNG ≤10 MB, with the extension matching the content. WhatsApp WebP stickers, HEIC or multi-image albums need handling upstream. | `InvoiceStorageService.php:26-31`, `:100-109` |
| F8 | An identity bridge for chat users already exists (`assistant_channels`, provider string). | `AssistantChannelModel.php` |
| F9 | Cross-channel dedup by hash works automatically **if** both channels use `sursa_key = scan:<sha>`. | `process_invoice_inbox.php:142` |

### 10.2 Answers to the feasibility questions

| Question | Answer |
|---|---|
| How can documents enter? | **PROPOSED, Phase 1:** the Node adapter writes `<base>.<ext>` then `<base>.json` atomically (temp name + rename, the JSON last) into `storage/invoices/inbox/`. The next cron run processes it. **Phase 2:** an authenticated ingestion endpoint or CLI that calls the same services, with explicit channel columns. |
| Required metadata | Minimum, for current code: `fisier`, `nume_original`, and optionally `email_subiect` (= the caption, used for classification) and `email_primit_la` (= the message timestamp). Recommended in addition (ignored today, needed for Phase 2): `canal: "whatsapp"`, `wa_message_id`, `wa_group_jid`, `wa_group_name`, `wa_sender_jid`, mapped `user_id`/`driver_id`, `sha256`, `mime`, `marime`. |
| Generalize subject classification | **VERIFIED reusable:** pass the caption as the hint; `typeFromSubject()` works on any text. **PROPOSED:** rename the concept to "operator hint", accept an explicit category (e.g. a reply-keyword or a per-group default category), and keep the content classification as the fallback. Caution: a caption containing "piese"/"service" routes the document to the parts registry (§4.3). |
| Run without an email record? | **VERIFIED yes.** |
| Agent and matching unchanged? | **VERIFIED yes.** But the sender identity (a known driver) is the strongest matching signal WhatsApp offers, and the current code cannot use it unless it is put into `driver_id`/`sofer_extras`. Phase 1 cannot do that without PHP changes, because `process_invoice_inbox.php` does not read such keys. |
| Avoid double processing across Gmail and WhatsApp | **VERIFIED partially:** byte-identical PDFs are caught by `scan:<sha>` if both channels use the `scan` prefix. Photos of a paper already scanned have different bytes → only the D5 heuristic catches them (→ `de_verificat`). **PROPOSED:** also dedup on the WhatsApp message id (a new unique column), and on supplier CUI + invoice number + date once these are stored as columns. |
| Authentication / authorization | **PROPOSED:** Phase 1 relies on filesystem permissions (the adapter runs on the same host as a dedicated OS user with write access only to the inbox folder). Phase 2: a dedicated service token (like `X-Assistant-Token`, a separate secret), localhost binding or mTLS, a per-request size limit, rate limiting, and an `ingestion_log` table. |
| Restrict to approved groups | **PROPOSED:** enforce on the Node side with an allow-list of group JIDs (config, not code) + an optional sender allow-list; drop everything else before download. In Phase 2 the PHP endpoint re-checks against a DB table (e.g. `facturi_canale_aprobate`). |
| Privacy and temporary files | **PROPOSED:** download media into a private temp dir (mode 0700) on the same filesystem as the inbox, verify magic bytes and size, compute SHA-256, then `rename()` into the inbox; never write media under `htdocs/`; delete temp files on any failure; do not log captions/phone numbers in plain text logs beyond what is needed; the Baileys auth state must be stored outside the repo with restricted permissions. Note that invoice images (which may contain driver names) are already sent to Anthropic today. |

### 10.3 Proposed WhatsApp intake path (PROPOSED — not implemented)

```mermaid
flowchart TD
    W1["WhatsApp group message<br/>(document PDF / image + optional caption)"] --> W2["Baileys Node service (local)"]
    W2 --> W3{"Group JID in allow-list?<br/>Sender allowed?"}
    W3 -- no --> W3x["ignore (no download)"]
    W3 -- yes --> W4{"MIME pdf/jpeg/png<br/>and size ≤10 MB?"}
    W4 -- no --> W4x["reply / log 'format neacceptat'"]
    W4 -- yes --> W5["download to private temp dir (0700)<br/>magic-byte check, sha256"]
    W5 --> W6{"wa_message_id already seen?<br/>(local adapter ledger)"}
    W6 -- yes --> W6x["skip"]
    W6 -- no --> W7["Phase 1: atomic write into<br/>storage/invoices/inbox/<br/>&lt;ts&gt;_wa_&lt;sha12&gt;.ext, then .json:<br/>fisier, nume_original,<br/>email_subiect = caption,<br/>email_message_id = 'wa:' + id,<br/>email_primit_la = message time,<br/>+ canal/group/sender (ignored today)"]
    W7 --> P1["UNCHANGED: process_invoice_inbox.php<br/>(cron) → facturi / ocr_piese<br/>→ Claude → matching → curse_cheltuieli"]
    W5 -. "Phase 2" .-> W8["POST /api/ingest/v1/invoices (new)<br/>service token + multipart + metadata"]
    W8 --> W9["new IngestionService:<br/>validate, storeFromPath, sursa='whatsapp',<br/>channel columns, ingestion_log"]
    W9 --> P1
    P1 --> W10["optional: status back to the group<br/>(polling a new status endpoint)"]
```

---

## 11. Recommended Integration Point

**PROPOSED — Phase 1 (no change to the existing PHP or Gmail logic):**
**The `storage/invoices/inbox/` queue contract**, written by a small adapter process on the same host.

Why this is the safest point:
1. It is the exact boundary the Gmail fetcher already uses (`fetch_invoice_emails.py` "does not touch the database", `:18-19`). Everything after it — validation, storage, dedup, parts routing, OCR, retries, classification, matching, mirroring, statuses — stays identical. **VERIFIED.**
2. No new network surface, no session impersonation, no DDL.
3. Gmail and WhatsApp share the `scan:<sha>` dedup namespace. **VERIFIED.**

Contract the adapter must respect (derived from the existing code):
- the data file is written first, then the `.json`; both via temp name + `rename` on the same filesystem (`write_atomic` pattern, `:204-207`);
- `fisier` = the basename of the data file; the extension is one of `pdf/jpg/jpeg/png` and **must match the real content** (`finfo`), otherwise the pair is retried forever (see R7);
- ≤10 MB, non-empty;
- `email_subiect` = the caption (or empty to let Claude decide);
- `email_message_id` = for example `wa:<messageId>` (max 255);
- `email_primit_la` = an ISO-8601 timestamp;
- folder ownership/permissions compatible with the PHP CLI user (the folder is created `0750`).

Known limitations of Phase 1 (to accept explicitly):
- the UI will show WhatsApp items with "Scanare" as the source and the caption as "subiect email";
- the group/sender metadata is not stored in the DB;
- the sender cannot be used as `driver_id` for matching;
- latency of up to one cron interval (5 min) plus the OCR batch limit (10 per run).

**PROPOSED — Phase 2 (when traceability is required):**
- additive DDL: extend `facturi.sursa` (e.g. `whatsapp`) or add `canal`, `canal_message_id` (UNIQUE), `canal_group_id`, `canal_sender_id`, `furnizor_cui`, `ocr_incredere`;
- a CLI/HTTP ingestion service reusing `InvoiceStorageService::storeFromPath`, `InvoiceModel::create(status=in_procesare)` and the existing OCR loop; keep `sursa_key = scan:<sha>` (or check both keys) to keep cross-channel dedup;
- an explicit sender → driver mapping (via `assistant_channels`) passed as `driver_id` before matching;
- generalize `email_subiect` into an "operator hint" column (keeping backward compatibility).

---

## 12. Technical Risks and Limitations

Severity scale: **Critical / High / Medium / Low**. Status of each finding: **VERIFIED** unless noted.

| ID | Finding | Severity | Affected files | Recommended resolution (PROPOSED) |
|---|---|---|---|---|
| R1 | **Single-trip auto-association ignores plate/driver.** If only one trip covers the date, the invoice is linked to it even when the plate/driver on the invoice clearly belong to another vehicle/driver (example 7, §6.6). | **High** | `InvoiceTripMatcher.php:159`, `:184-191` | Apply identity filters also when 1 match; if the invoice has a resolved vehicle/driver that is not a participant → `de_verificat`. |
| R2 | **Strict-date preference is global and runs before identity.** A tolerance-only match for the right driver (cazare on the check-out day) is discarded whenever *any* other trip strictly contains the date, which can end in a wrong `asociata_auto` or a misleading candidate list (example 4). | **High** | `InvoiceTripMatcher.php:150-154` | Apply the strict preference per identity group, or only after the identity narrowing. |
| R3 | **"Ignore non-matching identity" rule.** A plate/driver that matches no trip of the day is ignored instead of flagging the invoice. | Medium | `InvoiceTripMatcher.php:170-173` | Treat an unmatched *resolved* vehicle/driver as a reason for `de_verificat`. |
| R4 | **No time dimension.** Invoices have no time; trips' `ora_*` and segments' times are ignored, so same-day handovers and back-to-back trips are ambiguous. | Medium | `InvoiceOcrService.php:207-243`; `InvoiceTripMatcher.php:328-338` | Extract the transaction time when printed (receipts usually have it); use the trip/segment times when present. |
| R5 | **Issue date ≠ transaction date.** Only the issue date is extracted (e.g. hotel invoices dated at check-out; service invoices issued later). | Medium | `InvoiceOcrService.php:167` | Extract the service/stay period when present; keep the tolerance per type configurable. |
| R6 | **Subject override is scan-wide.** A subject category is forced on every document of a multi-document scan, overriding content classification. | Medium | `InvoiceModel.php:1058-1063` | Apply the override only when the scan has one document, or when the OCR type is `alte`/low confidence. |
| R7 | **Poison pairs in the inbox.** A pair that fails ingest (e.g. extension/MIME mismatch) is never moved and is retried every 5 minutes forever. | Medium | `process_invoice_inbox.php:165-168` | Move it to `inbox/failed/` after N attempts; surface it in `scanStatus`. |
| R8 | **A non-OCR exception aborts the batch** (DB error, unexpected SDK exception), skipping the remaining invoices and the parts step. | Medium | `process_invoice_inbox.php:184-211` | Catch `Throwable` per row; record it as a failure. |
| R9 | **Hardcoded categories.** The 9 types, keywords, prompt rules and mapping are in PHP constants; the DB category table is joined by name. | Medium | `InvoiceModel.php:37-47`, `:202-212`; `InvoiceOcrService.php:172-181` | Move to a config table (type, label, keywords, legacy ENUM, category id); generate the prompt/enum from it. |
| R10 | **Message-ID not used for dedup**; no unique key. Re-labelled or forwarded emails rely on the file hash only. | Low | `process_invoice_inbox.php:142-157` | Add a unique `(canal, message_id, attachment index)` key. |
| R11 | **Heuristic duplicates miss variations** (different OCR type, date or amount, or a missing value). | Medium | `InvoiceModel.php:844-884` | Store the supplier CUI + invoice number as columns; dedup on `(cui, numar_document)`. |
| R12 | **No audit trail** for association, unlink, reject, edit, delete or purge (who / when / before → after). `match_reason` is overwritten. | **High** (financial data) | `InvoiceModel.php`, `InvoiceController.php` | Add a `facturi_audit` table written by the model, or hook into the existing `audit_log` + `audit_context.php`. |
| R13 | **Multi-vehicle invoices** cannot be split across trips/vehicles in Facturi. | Medium | `InvoiceOcrService.php:229`; schema | Allow several allocations per invoice (child rows), as the parts registry already does per line item. |
| R14 | **Non-RON invoices** are always held for manual conversion. | Low | `InvoiceModel.php:1648-1651` | Optional exchange-rate conversion (BNR rate of `data_document`). |
| R15 | **Parts scans are stored under the web root** (`htdocs/uploads/ocr_piese`). With `php -S -t htdocs`, existing static files are served directly, so these scans are **INFERRED** to be downloadable by anyone who knows the file name (the name is `Ymd_His_<sha12>.ext`, hard to guess but not access-controlled). `InvoiceStorageService`'s own comment states this risk for `htdocs/uploads`. | Medium (**INFERRED**) | `OcrPartsScanService.php:17`, `:58-64`; `InvoiceStorageService.php:7-10` | Store in `storage/` and serve through an authenticated route, as Facturi does. |
| R16 | **Rematch runs on every GET of the list page** (up to 50 invoices + Cazare sync), synchronously, and can overlap with the cron processors; there is no row lock, and `curse_cheltuieli` has no unique key for the Facturi mirror, so two concurrent syncs could create two mirror rows (**INFERRED** race). | Low–Medium | `InvoiceController.php:110-120`; `InvoiceModel.php:1577-1640` | Advisory lock (`GET_LOCK`) around matching; a unique link column on the mirror. |
| R17 | **Runtime DDL** (`ensureFacturiSchema`, `ensureScanSchema`) on every run/page load; DDL commits implicitly. | Low | `InvoiceModel.php:81-173`; `OcrPartsModel.php:1789-1866` | Move to migrations once stable. |
| R18 | **OCR accuracy** has no automatic cross-check (e.g. net + VAT = gross, plate exists in the fleet, date within the trip range) beyond normalization; the confidence value is not used for routing. | Medium | `InvoiceOcrService.php:253-337` | Route `incredere = mica` or failed arithmetic checks to `de_verificat` even when the match is unique. |
| R19 | **No ingestion API** — any new channel needs an adapter. | Medium (design) | `InvoiceController.php`; `AssistantApiController.php` | §11 Phase 1 / Phase 2. |
| R20 | **Classification depends on the channel**: a "service" subject routes to another module, while the same invoice without a subject goes to Facturi. | Low | `process_invoice_inbox.php:123` | Decide on routing explicitly for WhatsApp (e.g. per-group routing). |
| R21 | **Legacy accommodation matching ignores segments** (driver changes mid-trip). | Low | `AccommodationExpenseModel.php:229-253` | Align with `InvoiceTripMatcher`. |
| R22 | **Lookback window**: emails older than `INVOICE_IMAP_LOOKBACK_DAYS` (example value 7 in `.env.example`, default 30 in code) are never fetched (e.g. after a long outage). | Low | `fetch_invoice_emails.py:86`, `:147` | Document a backfill procedure (`--lookback` flag). |
| R23 | **Personal data sent to a third party**: driver names and documents go to Anthropic; the parts flow also sends up to 300 fleet plates per call. | Low (compliance) | `InvoiceOcrService.php:93-112`; `PartsInvoiceOcrService.php:68-76` | Confirm the DPA / retention settings; mention it in the privacy notice. |

**Not found (VERIFIED):** insecure handling in the Facturi upload path itself — it validates `is_uploaded_file`, the size, the extension whitelist and the `finfo` MIME, sanitizes names, stores outside `htdocs`, serves only via an authenticated route with `nosniff`, and `resolveReadablePath` blocks `../` and absolute paths (`InvoiceStorageService.php:54-175`; `InvoiceController.php:209-235`).

---

## 13. Open Questions and Missing Information

1. **Is the `run_facturi_email.sh` cron installed on the VPS, and how often?** Project notes said it was not installed as of 2026-10-02. Is `rematch_facturi.php` scheduled? (Server state is not in the repo.)
2. **Will the Baileys service run on the same host** as the PHP app (needed for the Phase 1 folder contract), or remotely (then an HTTP endpoint is needed)?
3. **Who posts in the WhatsApp groups** — drivers, dispatchers, or both? Should the sender be trusted as the driver for matching?
4. **Should WhatsApp captions be free text, or should a fixed keyword convention be enforced** (the same keywords as the scanner subjects)?
5. **Should WhatsApp parts/service invoices go to the parts registry** (current subject rule) or to Facturi?
6. **Are photos of receipts expected** (lower OCR quality than MFP scans)? Should low-confidence reads always go to `de_verificat`?
7. **What should happen when the same invoice arrives via both Gmail and WhatsApp**: keep the first, keep the better quality one, or link both?
8. **Feedback to the group:** is a reply expected (accepted / duplicate / unreadable / associated with trip #N)? That requires a status endpoint, which does not exist.
9. **Retention policy** for documents in `storage/invoices/` and for the Baileys media cache.
10. **Anthropic data processing terms** (retention / zero-retention) — not visible in the code.
11. **Actual production data quality** — how many invoices end `de_verificat` / `neasociata` today, and how many automatic associations operators later corrected. Not measurable without querying the production DB (outside this read-only audit).
12. **Whether the offline test scripts pass** (`scripts/test_invoice_matcher.php`, `test_invoice_ocr.php`, `test_invoice_storage.php`, `test_facturi_association.php`, `test_parts_invoice_ocr.php`). They exist but were **not run** in this audit, by instruction.

---

## 14. Final Conclusions

1. **VERIFIED.** The Facturi pipeline is already **channel-agnostic after the queue folder**. Gmail-specific logic is fully contained in `scripts/fetch_invoice_emails.py`; the PHP side only uses the email subject as an optional classification hint and stores three email fields as metadata.
2. **VERIFIED.** Classification is primarily **content-based (Claude)**, with a subject keyword override over 9 hardcoded types and a subject-based routing of "service/piese" scans to the parts registry. The logic is pure and reusable for WhatsApp captions.
3. **VERIFIED.** Extraction is a single Claude call with a strict JSON schema: one row per document, one plate, one driver, the issue date, amounts, currency, supplier, invoice number and confidence. No time and no multi-vehicle split in Facturi; the CUI and confidence are not stored as columns.
4. **VERIFIED.** Matching is **date-first, day-granular, with identity narrowing only for ambiguous days**. It supports segments (resumed / multi-driver / month-split trips), sticky manual links, unlinking, candidate lists and automatic rematching. Two rules (R1, R2) can create **wrong automatic associations**, and there is **no audit trail** (R12). These are the most important issues to fix before adding a second, higher-volume intake channel.
5. **PROPOSED.** Integrate WhatsApp in two phases: (1) a Baileys adapter that writes the existing inbox file+json contract on the same host, with the group allow-list, validation and safe temp handling on the Node side — zero changes to business logic; (2) additive schema changes and an authenticated ingestion service for channel provenance, sender-to-driver mapping, message-id dedup and status feedback.

---

### Appendix A — Files reviewed

`scripts/fetch_invoice_emails.py`, `scripts/process_invoice_inbox.php`, `scripts/run_facturi_email.sh`, `scripts/rematch_facturi.php`, `htdocs/services/InvoiceOcrService.php`, `htdocs/services/PartsInvoiceOcrService.php`, `htdocs/services/OcrPartsScanService.php`, `htdocs/services/InvoiceStorageService.php`, `htdocs/services/InvoiceTripMatcher.php`, `htdocs/services/TripExpenseMirrorService.php`, `htdocs/models/InvoiceModel.php`, `htdocs/models/OcrPartsModel.php` (scan section), `htdocs/models/AccommodationExpenseModel.php` (matching section), `htdocs/models/AssistantChannelModel.php`, `htdocs/controllers/InvoiceController.php`, `htdocs/controllers/OcrPartsController.php` (upload/run), `htdocs/controllers/AssistantApiController.php`, `htdocs/permissions/modules/facturi.php`, `htdocs/index.php` (routing), `htdocs/includes/audit_context.php`, `database/migrations/2026_10_01_000001_facturi.sql`, `database/migrations/2026_09_18_000002_curse_segmente.sql`, `database/database.sql` (trip/expense tables), `.env.example` (variable names only), `composer.json`.
