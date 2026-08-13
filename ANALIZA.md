# ANALIZĂ — Pagina „Configurare transport" (clona `config_v2`)

> Analiză tehnică read-only a paginii clonate `?page=dispecer_curse&action=config_v2`.
> Clona ([htdocs/views/dispecer_curse/config_v2.php](htdocs/views/dispecer_curse/config_v2.php)) este identică byte-cu-byte cu originalul ([htdocs/views/dispecer_curse/config.php](htdocs/views/dispecer_curse/config.php)), cu excepția celor 9 link-uri interne GET care indică `action=config_v2` în loc de `action=config`. **Formularele POST din clonă lovesc aceleași endpoint-uri reale (`config_store_*`, `config_delete_*`) și scriu în baza de date reală, iar după salvare redirecționează către pagina originală `action=config`.** Numerele de linie de mai jos se referă la `config.php` (identice în clonă).

**Fișiere implicate:**

| Rol | Fișier |
|---|---|
| View (1947 linii) | `htdocs/views/dispecer_curse/config.php` — prolog PHP (1–248), HTML (250–1088), `<style>` (1090–1320), `<script>` inline (1322–1947) |
| Controller | `htdocs/controllers/DispecerCurseController.php` — dispatch în `handle()` (85–203), `configAction()` (2395–2899), acțiuni `config_*` (2900–4429) |
| Model | `htdocs/models/DispecerCurseModel.php` |
| JS global | `htdocs/assets/js/app.js:1-15` — interceptorul `data-confirm` (window.confirm pe submit) |
| Auth/CSRF | `htdocs/includes/auth.php:54` (`require_admin_or_403`), `htdocs/includes/csrf.php:27` |

**Tabele DB atinse:** `configurare_beneficiari_transport`, `configurare_locuri_incarcare`, `configurare_locuri_incarcare_vehicule`, `configurare_zone_distributie`, `configurare_zone_distributie_vehicule`, `configurare_rute_distributie`, `configurare_rute_primar`, `configurare_compresor_vehicule`; plus `curse_dispecer` (dezlegare la ștergere loc/zonă) și `vehicule`/`vehicule_cuplaje` (doar citire).

---

## 1. Funcționalități — ce face fiecare buton, formular, interacțiune

Pagina este un singur ecran cu 4 blocuri mari, toate în același card („Regula tarifare beneficiar"):

### 1.1 Secțiunea „Date beneficiar si tipuri transport" (mereu vizibilă, linii 267–424)

**FORM-1 — creare/editare beneficiar** → POST `action=config_store_beneficiar` (linia 275):
- **Beneficiar*** (text, `nume`, max 150).
- **Tipuri transport*** — dropdown Bootstrap multiselect cu 4 checkbox-uri `tip_transporturi[]`: `primar` („Primar km"), `distributie` („Distributie"), `primar_distributie` („Primar+Distributie"), `compresor` („Compresor"). Bifarea/debifarea arată/ascunde live sub-cardurile de preț și secțiunile de mai jos (vezi §4/§6).
- **Status** — switch `activ` (default bifat).
- **Card „Regula tarifare Primar"** (vizibil doar cu `primar` bifat): `pret_km`, `pret_tona`.
- **Card „Regula tarifare Compresor"** (doar cu `compresor` bifat): `pret_ora_aspirare`, `pret_km_dislocare`, `pret_tona_livrata`, `pret_tona_aspirata_lichida`, `pret_tona_aspirata_gazoasa` + multiselect vehicule `compresor_vehicle_ids[]` cu chips-uri (numere de înmatriculare).
- **Nu există card de preț pentru `distributie` sau `primar_distributie`** — prețurile lor stau exclusiv pe rute; FORM-1 doar trimite pass-through hidden `pret_tarifare`, `pret_distributie_tona`, `pret_distributie_km` (linii 278–280).
- Buton submit: „Salveaza regula beneficiar" / „Actualizeaza regula beneficiar" (în edit).
- Link „Reseteaza formular" (doar în edit, linia 271) → GET `action=config_v2` (curăță starea de editare).

### 1.2 Secțiunea „Catalog locatii si zone" (`data-transport-card="catalog"`, linii 426–806)

Vizibilă doar cu `primar`/`distributie`/`primar_distributie` bifat; **utilizabilă doar după ce beneficiarul e salvat și redeschis în modul edit** (`$catalogConfigReady = id > 0 && tip catalog bifat`, linia 36). Altfel afișează doar avertismentul „Salveaza mai intai regula beneficiarului...".

- **FORM-2 — catalog loc + zonă** → POST `action=config_store_catalog` (linia 446): `loc_nume` (max 120), `zona_nume` (max 120) + hidden `beneficiar_id`, `loc_id`, `zona_id`. Buton „Salveaza catalog". Poate salva doar locul, doar zona, sau ambele.
- **Sub-panel „Configuratii rute Distributie"** (doar cu `distributie` bifat):
  - **FORM-3** → POST `action=config_store_distributie` cu `route_scope=distributie` (linia 477): select Loc încărcare, select Zonă descărcare, **select mod tarifare** `route_tarif_mod` (`tona_km` / `tona` / `km`), `route_tarif_tona`, `route_cost_extra_km`, multiselect `route_vehicle_ids[]`. Buton „Adauga configuratie" / „Actualizeaza configuratia" (dezactivat dacă nu există cel puțin un loc ȘI o zonă).
  - **Tabel „Configuratii Distributie existente"** (7 coloane): pe fiecare rând buton „N vehicule" (popover cu căutare) și meniu kebab (⋮) cu „Editeaza" (GET `route_distributie_edit_id`) și „Sterge" (POST `action=config_delete_ruta`, confirm „Sigur doresti sa stergi aceasta configuratie Distributie?").
- **Sub-panel „Configuratii Primar+Distributie"** (doar cu `primar_distributie` bifat):
  - **FORM-4** → POST același `action=config_store_distributie` dar cu `route_scope=primar_distributie` (linia 639): Loc, Zonă, `route_tarif_tona`, `route_cost_extra_km`, `route_km_tarifare` (întreg ≥1), `route_cost_cursa` (opțional), switch `route_aplica_cost_cursa`, vehicule. **Fără selector de mod tarifare** — scope-ul acesta e blocat pe `tona_km`.
  - **Tabel „Configuratii Primar+Distributie existente"** (9 coloane) cu aceleași acțiuni pe rând (edit → `route_primar_distributie_edit_id`; delete → `config_delete_ruta` cu `route_scope=primar_distributie`).

### 1.3 Secțiunea „Setari primare" (`data-transport-card="primar"`, linii 808–988)

Vizibilă doar cu `primar` bifat; utilizabilă doar cu beneficiar salvat (`$primaryConfigReady`, linia 246).

- **FORM-5 — rută Primar** → POST `action=config_store_primar_ruta` (linia 821): Loc, Zonă, `route_primar_km_tarifare` (întreg ≥1), `route_primar_cost_cursa` (opțional), vehicule `route_primar_vehicle_ids[]`, switch-uri: `route_primar_km_agreati_manual` (dezactivează și golește câmpul Km), `route_primar_aplica_cost_cursa`, `route_primar_activ` (default on).
- **Tabel „Rute Primar existente"** (9 coloane) cu edit (`route_primar_edit_id`) și delete (`config_delete_ruta_primar`). ⚠️ **Defect structural:** tabelul este randat ÎN INTERIORUL FORM-5 (formularul se deschide la 821 și se închide la 986, tabelul e la 931–984). HTML5 elimină formularele imbricate, deci formularele de delete pe rând sunt „adoptate" de FORM-5 → butonul „Sterge" de pe un rând Primar face de fapt POST la `config_store_primar_ruta` (endpoint-ul de salvare), nu la delete. Tabelele Distributie și Primar+Distributie nu au problema (sunt în afara formularelor lor).

### 1.4 Secțiunea „Reguli beneficiar configurate" (mereu vizibilă, linii 990–1085)

- Tabel cu 13 coloane, toate regulile (inclusiv inactive): ID, Beneficiar, Tip transport (concatenare din flag-urile `suporta_*`), prețuri, status, acțiuni.
- Pe rând: „Detalii" (GET `beneficiar_view_id` — **vezi §6: nu face nimic vizibil**), „Editeaza" (GET `beneficiar_edit_id` — încarcă beneficiarul în FORM-1 și deblochează secțiunile 1.2/1.3), „Sterge" (FORM-7, POST `config_delete_beneficiar`, confirm).
- **Ștergere în masă:** checkbox pe fiecare rând (`ids[]`, legate prin atributul `form=` de FORM-6 ascuns `#bulk-beneficiary-delete-form`), checkbox „selectează tot" în header (doar UI, fără `name`), buton „Sterge selectate" (activat doar cu ≥1 selecție; confirm; POST `config_delete_beneficiari`).

### 1.5 Interacțiuni pur vizuale
- **Popover „N vehicule"** în tabele: buton cu număr → popover flotant (position:fixed, z-index 3060) cu input de căutare (filtrare locală după `data-vehicle-search`, lowercase ro-RO), listă plăcuțe + detalii, mesaj „Niciun vehicul gasit.", footer „Total N vehicule". 0 vehicule → buton gri dezactivat, fără popover.
- **Meniu kebab pe rând**: dropdown flotant cu „Editeaza"/„Sterge", navigabil din tastatură (săgeți/Home/End/Escape).
- **Link „Inapoi la curse"** (linia 257) → `?page=dispecer_curse`.

---

## 2. Fluxul de date — request-uri, endpoint-uri, parametri, răspunsuri

**Nu există niciun AJAX.** Zero `fetch`/`XMLHttpRequest` în pagină. Totul e clasic **POST → Redirect → GET** (pattern PRG): fiecare submit trimite formular normal, serverul răspunde `302` cu `Location`, iar mesajele și datele formularului „vechi" călătoresc prin sesiune (flash).

**Mecanismul de răspuns:** succes/eroare = `flash_set(tip, mesaj)` în `$_SESSION['_flash_messages']` (afișate ca alerte la următorul GET) + pentru erori de validare `setFormFlash(cheie, old, errors)` în `$_SESSION['_dispecer_form_<cheie>']`, consumat o singură dată la următorul render (`consumeFormFlash`). Chei flash: `config_beneficiar`, `config_loc`, `config_zona`, `config_primar_route`, `config_distributie_route_distributie`, `config_distributie_route_primar_distributie` (+ legacy `config_distributie_route`).

**Protecție comună pe toate POST-urile:** `require_admin_or_403()` → verificare metodă POST → `ensure_csrf_or_redirect()` (token `_token` din `csrf_field()`; invalid → flash danger „Token CSRF invalid..." + redirect).

### 2.1 GET-ul paginii — `action=config` / `config_v2` (`configAction()`, 2395–2899)

Parametri GET citiți: `beneficiar_view_id`, `beneficiar_edit_id`, `loc_edit_id`, `zona_edit_id`, `transport_focus` (=`compresor` forțează bifarea tipului), `route_distributie_edit_id`, `route_primar_distributie_edit_id`, `route_edit_id` (legacy, doar scope P+D), `route_primar_edit_id`.

Apeluri model la render: `getTransportBeneficiaries(false)` (toți, inclusiv inactivi), `getVehicleOptions(true)` (doar vehicule active, fără semiremorci) pentru pickere, `getVehicleOptions()` (cu inactivi) doar pentru etichete de alocări, iar când există `beneficiar_edit_id`: locuri, zone, reguli de rute pe fiecare scope, rute primar, alocări implicite vehicul→loc/zonă, `getVehicleIdsForCompressorBeneficiary`.

⚠️ **GET cu efecte de scriere:** dacă beneficiarul editat are `primar`/`primar_distributie`, fiecare încărcare de pagină rulează `syncPrimaryRouteBidirectionalCatalog()` (2547–2554) care poate face **INSERT-uri** în catalog (oglindire nume zonă→loc și loc→zonă, tarife 0, active). Erorile sunt doar logate, invizibile utilizatorului. În plus, mai multe metode `ensure*Table()` rulează **DDL** (CREATE/ALTER TABLE, DROP INDEX, chiar DELETE de dubluri — model 1720–1729) pe calea normală de request.

### 2.2 Endpoint-urile POST

| Endpoint (`action=`) | Sursă în UI | Parametri principali | Operație DB | Redirect după |
|---|---|---|---|---|
| `config_store_beneficiar` | FORM-1 | `id`, `nume`, `tip_transporturi[]` (whitelist 4 valori → flag-uri `suporta_*`), 10× prețuri (string-uri, virgulă acceptată), `compresor_vehicle_ids[]`, `activ` | INSERT/UPDATE `configurare_beneficiari_transport` + sync `configurare_compresor_vehicule` (delete-all + reinsert). ⚠️ `tip_marfa` e suprascris mereu cu NULL (4259+) | `action=config&beneficiar_edit_id=<id>` — după creare aterizezi direct în edit |
| `config_store_catalog` | FORM-2 | `beneficiar_id`, `loc_id`, `loc_nume`, `zona_id`, `zona_nume` (+ `loc_tarif`, `zona_tarif_distributie`, `zona_cost_extra_km`, `*_vehicle_ids[]`, `*_activ` — netrimise de acest formular) | INSERT/UPDATE `configurare_locuri_incarcare` și/sau `configurare_zone_distributie`; sync alocări doar dacă câmpul a fost postat. Fără tranzacție peste ambele (scriere parțială posibilă) | `action=config&beneficiar_edit_id=<id>` |
| `config_store_distributie` | FORM-3 (`route_scope=distributie`) și FORM-4 (`route_scope=primar_distributie`) | `beneficiar_id`, `route_id` (0=creare), `route_loc_id`, `route_zona_id`, `route_tarif_mod` (doar scope distributie; P+D forțat `tona_km`), `route_tarif_tona`, `route_cost_extra_km`, `route_km_tarifare` (doar P+D), `route_cost_cursa`+`route_aplica_cost_cursa` (doar P+D), `route_vehicle_ids[]`, `panel_action=add_route` (orice altă valoare → redirect silențios fără mesaj) | INSERT/UPDATE `configurare_rute_distributie`; `vehicle_ids` stocat CSV sortat sau NULL; unique key pe (beneficiar, loc, zonă, scope) | `action=config&beneficiar_edit_id=<id>` (fără păstrarea `route_*_edit_id` la eroare) |
| `config_store_primar_ruta` | FORM-5 | `beneficiar_id`, `route_primar_id`, `route_primar_loc_id`, `route_primar_zona_id`, `route_primar_km_tarifare`, `route_primar_cost_cursa`, `route_primar_aplica_cost_cursa`, `route_primar_vehicle_ids[]`, `route_primar_km_agreati_manual`, `route_primar_activ` | INSERT/UPDATE `configurare_rute_primar` (unique pe beneficiar+loc+zonă, fără scope); apoi **sincronizare bidirecțională catalog** (creează loc oglindit din numele zonei și invers) | `action=config&beneficiar_edit_id=<id>` |
| `config_delete_ruta` | kebab din tabelele Distributie/P+D | `id`, `beneficiar_id`, `route_scope` | DELETE `configurare_rute_distributie` (ownership + scope verificate în controller) | `action=config&beneficiar_edit_id=<id>` |
| `config_delete_ruta_primar` | kebab din tabelul Primar (⚠️ rupt de formularul imbricat, §1.3) | `id`, `beneficiar_id` | DELETE `configurare_rute_primar WHERE id AND beneficiar_id` (id inexistent → raportează totuși succes) | `action=config&beneficiar_edit_id=<id>` |
| `config_delete_beneficiar` | FORM-7 pe rând | `id` | DELETE `configurare_beneficiari_transport`; cascade DB pe locuri/zone/rute/compresor; `curse_dispecer.beneficiar_id` e ON DELETE RESTRICT → beneficiar folosit în curse NU poate fi șters (eroare prinsă → mesaj danger) | `action=config` |
| `config_delete_beneficiari` | FORM-6 bulk | `ids[]` | buclă de DELETE individuale, fără tranzacție globală → succes parțial posibil; raportare agregată (șterși / eșuați cu primele 5 ID-uri / inexistenți) | `action=config` |
| `config_store_loc`, `config_delete_loc`, `config_store_zona`, `config_delete_zona` | **ORFANE — niciun formular în UI** nu le folosește; accesibile doar prin POST direct | vezi §6 | INSERT/UPDATE/DELETE pe locuri/zone; delete-urile detașează cursele istorice (`curse_dispecer.loc_incarcare_id/zona_distributie_id=NULL`) și declanșează cascade pe rute | `action=config` (+`beneficiar_edit_id`) |

---

## 3. State-ul paginii — ce ține JS, ce vine din backend, ce depinde de ce

### 3.1 Server-side (adevărata sursă de stare)
Starea reală trăiește în **URL + sesiune + DB**; pagina se rerandează complet la fiecare acțiune:
- `beneficiar_edit_id` din URL → `$beneficiaryFormData` → `$distributionBeneficiaryId = (int)$beneficiaryFormData['id']` (2541). **Tot restul paginii depinde de acest id**: `$catalogConfigReady` (36), `$primaryConfigReady` (246), listele de locuri/zone/rute — toate goale la id=0.
- Flag-urile derivate din `tip_transporturi`: `$isPrimarSelected`, `$isDistributieSelected`, `$isPrimaryDistributionSelected`, `$isCompresorSelected`, `$isCatalogSelected` (28–32) → controlează atributul `hidden` la randare pe fiecare card/secțiune.
- `$canAddDistributionRoute` / `$canAddPrimaryRoute` (239, 247 — expresii identice): există cel puțin un loc ȘI o zonă → altfel butoanele de submit rute sunt `disabled`.
- Modurile de formular (`create`/`edit`) sunt derivate: `edit` ⇔ `id`/`route_id` non-gol în datele formularului (67, 73, 244, controller 2682+).
- Flash-ul din sesiune **bate DB-ul**: `array_merge($defaultBeneficiaryForm, $beneficiaryFlash['old'])` (2526) — după o validare eșuată, formularul arată datele introduse, nu cele salvate.

### 3.2 Client-side (JS — pur prezentațional, se pierde la reload)
- `activeVehicleState` / `activeActionsState` (1533–1534): care popover de vehicule / meniu kebab e deschis (`{button, popover/menu}`); mutual exclusive; una singură deschisă simultan.
- `syncingTonRate` (1461): guard de reintrare pentru oglindirea preț/tonă (cod mort — vezi §6).
- `bulkCheckboxEls` (1484): **snapshot static** la DOMContentLoaded al checkbox-urilor de bulk (fără MutationObserver — irelevant azi, pagina e integral server-rendered).
- `control.dataset.initialDisabled`: memorarea stării inițiale `disabled` a fiecărui control dintr-un card, ca ascunderea/reafișarea cardului să poată restaura corect (1336–1337).
- Închideri per-dropdown în `initVehicleMultiselectDropdown`: referințe la label, checkbox-uri, config `data-summary-*`, container chips.
- **Dublă mecanică de vizibilitate**: PHP randează `hidden` inițial după flag-uri; JS (`updateTransportTypeCards`) rescrie live `hidden` + enable/disable la fiecare schimbare de checkbox. Ascunderea unui card îi **dezactivează toate controalele** (deci câmpurile nu se mai trimit în POST).

---

## 4. Event listeners și selectorii de care depinde JS-ul

Un singur bloc `<script>` (1322–1947), totul într-un `DOMContentLoaded`. Inventar complet:

| # | Element țintă (selector) | Eveniment | Efect |
|---|---|---|---|
| 1 | `[data-role="transport-type-dropdown"] input[name="tip_transporturi[]"]` (fiecare) | `change` | `updateTransportTypeCards()` — arată/ascunde + enable/disable toate `[data-transport-card="<tip>"]`, cardul `catalog` (dacă e bifat oricare din primar/distributie/primar_distributie) și rescrie eticheta butonului dropdown |
| 2 | `#config_distribution_only_route_tarif_mod` | `change` | `updateDistributionTariffInputs()` — după mod: `tona_km`→ambele, `tona`→doar tonă, `km`→doar km; câmpul nefolosit devine `disabled` (nu se mai trimite) și ne-`required` |
| 3 | `#config_primary_route_km_agreati_manual` | `change` | `updatePrimaryKmInputMode()` — bifat: golește + dezactivează `#config_primary_route_km_tarifare`; debifat: reactivează + `required` |
| 4 | `#config_primar_pret_tona`, `#config_compresor_pret_tona` | `input` | oglindire bidirecțională a valorii — **COD MORT: `#config_compresor_pret_tona` nu există în DOM**, deci listener-ele nu se atașează niciodată (guard-ul de la 1473 pică) |
| 5 | `#bulk-beneficiary-select-all` | `change` | (de)bifează toate `.bulk-beneficiary-checkbox` + `refreshBulkDeleteState()` |
| 6 | fiecare `.bulk-beneficiary-checkbox` | `change` | `refreshBulkDeleteState()` — activează `#bulk-beneficiary-delete-btn` doar la ≥1 selecție; setează checked/indeterminate/disabled pe select-all |
| 7 | `document` (delegat) | `click` | deschide/închide popover vehicule (`[data-dispatcher-vehicle-toggle]` + `data-popover-id`) și meniu kebab (`[data-transport-actions-toggle]` + `data-menu-id`); click în afara `[data-dispatcher-vehicle-popover]`/`[data-transport-actions-menu]` → închidere |
| 8 | `document` (delegat) | `input` | căutare în popover: `[data-dispatcher-vehicle-search]` → filtrare `[data-dispatcher-vehicle-item]` după `data-vehicle-search` (lowercase ro-RO, substring); toggle `[data-dispatcher-vehicle-empty]` |
| 9 | `document` (delegat) | `keydown` | `Escape` închide layerele; `ArrowDown` pe toggle deschide; în meniul kebab: navigare roving-focus pe `[role="menuitem"]` cu `ArrowDown/ArrowUp/Home/End` (wrap), `Tab` închide |
| 10 | `document` (capture) / `window` | `scroll` / `resize` | repoziționează layerul flotant deschis (`positionFloatingLayer` — position:fixed, clamp la viewport, flip deasupra) |
| 11 | `[data-role="transport-type-dropdown"]` | `change` (al 2-lea listener) | la schimbarea unui tip transport închide popover/meniu deschis |
| 12 | fiecare checkbox din `.vehicle-multiselect-dropdown` | `change` | `refreshVehicleLabel` — rescrie eticheta butonului (listă completă sau mod `count`: „N vehicule selectate"), atributul `title` și chips-urile de plăcuțe (dedupe prin Set) |

**Contracte DOM de care depinde JS-ul** (redenumirea/ștergerea lor rupe funcționalitatea):
- ID-uri: `config_distribution_only_route_tarif_mod`, `config_distribution_only_route_tarif_tona`, `config_distribution_only_route_cost_extra_km`, `config_primary_route_km_agreati_manual`, `config_primary_route_km_tarifare`, `config_primar_pret_tona`, `bulk-beneficiary-select-all`, `bulk-beneficiary-delete-btn`, `bulk-beneficiary-delete-form` (via atribut `form=`), plus ID-uri dinamice `dispatcher_vehicle_popover_<key>`, `transport_row_actions_<key>`.
- Data-attributes: `data-role="transport-type-dropdown"`, `data-transport-card` (valori: `primar`, `compresor`, `catalog`, `distributie`, `primar_distributie`), `data-default-label`, `data-summary-mode/-singular/-plural`, `data-chips-target`, `data-vehicle-plate`, `data-popover-id`, `data-menu-id`, `data-dispatcher-vehicle-toggle/-popover/-search/-item/-empty`, `data-transport-actions-toggle/-menu`, `data-confirm` (consumat de `app.js`), `data-initial-disabled` (scris de JS).
- Clase: `.transport-multiselect-label`, `.vehicle-multiselect-dropdown`, `.vehicle-multiselect-label`, `.bulk-beneficiary-checkbox`, `.is-open`, `.bi-chevron-down/up`.
- Dropdown-urile multiselect folosesc Bootstrap (`data-bs-toggle="dropdown"`, `data-bs-auto-close="outside"` — rămân deschise la selecții multiple).

---

## 5. Validări

### 5.1 Client-side — practic inexistente
- **Toate cele 5 formulare mari au `novalidate`** (275, 446, 477, 639, 821) → fiecare atribut `required` din pagină e **inert**; browserul nu blochează nimic. Singura „validare" client e indirectă: JS face câmpurile neaplicabile `disabled` (nu se trimit) — modul de tarifare (FORM-3) și km manual (FORM-5).
- Constrângeri declarative pe inputuri (aplicate doar ca UI de tastare, nu ca blocaj): `min="0" step="0.01"` pe toate prețurile; `min="1" step="1"` pe km tarifare (696, 862); `maxlength` 150/120/120 pe nume.
- Incoerențe: `loc_nume`/`zona_nume` și câmpurile de tarif Distributie au `*` în label dar fără `required`; confirmările de ștergere sunt `window.confirm` prin `data-confirm` (app.js), nu în pagină.

### 5.2 Server-side — sursa reală de adevăr (mesajele exacte)

Erorile revin per-câmp prin flash (`.invalid-feedback` + `is-invalid` la rerandare).

**`config_store_beneficiar`:** „Numele beneficiarului este obligatoriu." / „...prea lung (maxim 150 caractere)." / „Beneficiarul selectat pentru actualizare nu exista." / „Selecteaza cel putin un tip de transport." / prețuri: „Pretul de cursa este invalid.", „Pretul per km este invalid.", „Pretul per tona este invalid.", „Pretul/km pentru distributie este invalid.", „Pretul/tona pentru distributie este invalid.", „Pretul per ora aspirare este invalid." etc. (goale ⇒ 0.0; negative ⇒ eroare) / „Selecteaza doar vehicule active valide pentru Compresor." **Fără verificare de unicitate a numelui în aplicație** — dublura lovește unique key-ul DB și apare doar ca mesaj generic „Nu s-a putut salva beneficiarul de transport."

**`config_store_catalog`:** „Completeaza cel putin un loc incarcare sau o zona descarcare pentru salvare." / „Numele locatiei este obligatoriu." / „...prea lung (maxim 120 caractere)." / „Tariful este invalid." / „Numele zonei este obligatoriu." / „Tariful de distributie este invalid." / „Costul extra per km este invalid." / „Locatia/Zona selectata pentru actualizare nu exista." Dublurile de nume (unique key pe beneficiar+nume) → doar mesaj generic danger.

**`config_store_distributie`** (ambele scope-uri): „Selecteaza un beneficiar valid pentru configurarea distributiei." / „Beneficiarul selectat nu este configurat pentru transport Distributie." sau „...Primar+Distributie." (verifică flag-ul `suporta_*` corespunzător scope-ului) / „Selecteaza locul de incarcare." / „Locul de incarcare selectat nu apartine beneficiarului curent." / idem zonă / „Pretul pe tona este invalid." (doar dacă modul folosește tona) / „Pretul pe km este invalid." (doar dacă modul folosește km) / „Km agreati este invalid." + „Km agreati trebuie sa fie mai mare ca 0." (doar scope P+D) / „Costul de cursa este invalid." / „Completeaza Cost cursa cu o valoare mai mare ca 0 pentru a activa regula pe ruta." / „Selecteaza cel putin un vehicul." / „Selecteaza doar vehicule active valide." / edit: „Configuratia selectata pentru editare nu mai exista." / „Configuratia selectata apartine altui panel de tarifare." Dublura (unique pe beneficiar+loc+zonă+scope) → mesaj per-câmp „Exista deja o configuratie pentru aceasta combinatie Loc incarcare -> Zona descarcare." + warning.

**`config_store_primar_ruta`:** analoage („Selecteaza un beneficiar valid pentru configurarea Primar.", „Beneficiarul selectat nu este configurat pentru transport Primar.", „Km tarifare este invalid." — sărită complet când `km_agreati_manual=1`), dublura → „Exista deja o configuratie Primar pentru aceasta combinatie Loc ↔ Zona."

**Delete-uri:** validări minime de id/ownership cu warning-uri („Beneficiar invalid.", „Configuratia selectata nu exista pentru beneficiarul curent.", „Configuratia selectata apartine altui panel de tarifare." etc.); bulk: „Selecteaza cel putin un beneficiar pentru stergere." + raportare agregată.

**Normalizare numerică:** `normalizeDecimal` acceptă virgulă ca separator zecimal și spații („1 234,56" → 1234.56); string ne-numeric → null → „invalid".

---

## 6. Edge case-uri și comportamente condiționale

### Fluxul impus utilizatorului (sursa confuziei actuale)
1. **Creare ≠ configurare.** La creare (id=0) secțiunile Catalog și Setări primare afișează doar „Salveaza mai intai regula beneficiarului...". Trebuie: salvezi beneficiarul → ești redirecționat automat în modul edit (`beneficiar_edit_id`) → abia acum apar formularele de catalog și rute. Regulile per tip de transport sunt deci invizibile din vederea implicită a paginii.
2. **Catalogul e precondiție pentru rute:** fără cel puțin un loc ȘI o zonă, butoanele „Adauga configuratie/ruta" sunt `disabled`. Panel-ul Distributie afișează un avertisment explicativ; **panel-ul Primar+Distributie NU** — butonul e pur și simplu gri, fără explicație (asimetrie, view 474 vs 740).
3. Aceeași pereche (loc, zonă) poate exista o dată per scope în `configurare_rute_distributie` și separat în `configurare_rute_primar` — trei „reguli" paralele pentru aceeași rută fizică, în două tabele.

### Comportamente condiționale cheie
- **Mod tarifare Distributie:** `tona_km`/`tona`/`km` — câmpul neaplicabil e dezactivat client-side și forțat la 0 server-side; în tabel coloana nefolosită arată „-"; moduri invalide stocate sunt coerse la `tona_km`. Scope-ul P+D e blocat pe `tona_km` la scriere (2947–2949) și la randare (2817).
- **Km agreați manual (Primar):** bifat → câmpul Km e golit și dezactivat (valoarea nu se trimite; validarea km e sărită); server-side `km_tarifare=0`.
- **`activ` absent din POST ⇒ true** la rute (`route_activ`, `route_primar_activ`) dar **⇒ false** la endpoint-urile orfane loc/zonă — semantici opuse.
- **Vehicule:** rutele cer ≥1 vehicul activ („Selecteaza cel putin un vehicul."); catalogul acceptă selecție goală. Pickerele listează doar vehicule active (fără semiremorci); un vehicul devenit inactiv rămâne în CSV-ul salvat, dar la orice re-salvare e eliminat silențios (poate face lista goală → eroare). `vehicle_ids=NULL` în DB e interpretat la potrivirea rutelor drept „orice vehicul".
- **Alocare implicită vehicul→loc/zonă:** unique per (beneficiar, vehicul) — realocarea unui vehicul la alt loc îl „fură" silențios de la locul anterior (delete+insert în sync).
- **Ștergerea unui beneficiar** cascadează în DB peste locuri/zone/rute/compresor, dar e blocată (RESTRICT) dacă beneficiarul are curse → mesaj „Verifica daca este folosit in curse." Ștergerea unui loc/zonă detașează cursele istorice (`loc_incarcare_id=NULL`) și șterge rutele dependente prin cascade.
- **Sincronizarea bidirecțională Primar:** la fiecare salvare de rută Primar și la fiecare GET al paginii în edit (cu primar bifat), numele zonei devine automat un loc de încărcare oglindit și invers (tarife 0, active), fără ștergere la delete și fără feedback — catalogul „crește singur".
- **Debifarea `compresor` la un beneficiar** șterge toate legăturile lui vehicul-compresor la salvare (delete-all + reinsert cu listă goală).

### Defecte / cod mort identificate (fapte, nu propuneri)
1. **Formular imbricat (view 821–986):** tabelul „Rute Primar existente" e în interiorul FORM-5 → butoanele „Sterge" de pe rândurile Primar postează la `config_store_primar_ruta` în loc de `config_delete_ruta_primar`; hidden-urile `id`/`beneficiar_id` ale fiecărui rând sunt absorbite în FORM-5.
2. **„Detalii" nu face nimic:** link-ul setează `beneficiar_view_id`, controller-ul încarcă `$viewBeneficiary` (2469–2474), dar view-ul nu îl folosește nicăieri — pagina doar se reîncarcă identic.
3. **Cod mort JS:** blocul de oglindire preț/tonă Primar↔Compresor (1459–1480) e inert — `#config_compresor_pret_tona` nu există (există doar `_pret_tona_livrata/_aspirata_*`).
4. **Variabile PHP moarte în view:** `$distributionConfigReady` (35), etichetele loc/zonă de vehicule (39–59), `[data-role="transport-type-cards"]` (326, nequeried).
5. **Endpoint-uri orfane:** `config_store_loc`, `config_store_zona`, `config_delete_loc`, `config_delete_zona` — niciun formular nu le folosește, dar rămân accesibile prin POST direct; la `config_delete_loc`/`_zona`, dacă `beneficiar_id` lipsește (0), **verificarea de ownership e sărită complet** — orice id poate fi șters de un admin.
6. **`panel_action` ≠ `add_route`** la `config_store_distributie` → redirect silențios, fără niciun mesaj (arată ca un submit pierdut).
7. **Scriere parțială fără tranzacție:** `config_store_catalog` (locul se salvează, zona poate eșua), `config_store_beneficiar` (beneficiar salvat, sync compresor poate eșua), bulk delete (succes parțial by design).
8. **`tip_marfa` șters la fiecare update de beneficiar** (suprascris cu NULL, controller 4259+).
9. **Update-urile de rute nu detectează 0 rânduri afectate** (`execute()` întoarce true oricum) — guard-urile `RuntimeException` de la 3120/3301 sunt practic de neatins.
10. **Erori parțial invizibile:** eșecul sincronizării bidirecționale și al DDL-urilor `ensure*` merge doar în `error_log`; la eșec generic în `config_store_catalog` flash-urile se rescriu cu `errors=[]` (formularul se repopulează fără marcaje de câmp).
11. **La eroare de validare pe editarea unei rute**, `route_*_edit_id` nu se păstrează în redirect — bannerul „Editezi o configuratie..." dispare, rămâne doar old input-ul din flash.
12. **`updateTransportTypeCards` procesează cardurile `primar_distributie` de două ori** (bucla generică 1368–1372 + pasul dedicat 1393–1398) — redundant, fără efect vizibil.
13. **Ordinea inițializării JS:** `updateDistributionTariffInputs`/`updatePrimaryKmInputMode` rulează după mass-disable-ul cardurilor ascunse și re-activează punctual inputurile lor (cosmetic azi, dar fragil).

---

*Document generat exclusiv prin analiză statică + verificare în browser pe clonă; niciun fișier al aplicației nu a fost modificat. Fără propuneri de UX — urmează separat.*
