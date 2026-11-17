# Tablou de bord → Fleet Control Center — Faza 1 (audit) + Faza 2 (arhitectură)

Data: 2026-11-16 · Stare: **propunere, nimic implementat**. Căile sunt relative la `htdocs/`.

---

## 1. Dashboard-ul actual (`page=dashboard`)

| Element | Unde | Date | Semantică timp |
|---|---|---|---|
| Filtre (perioadă, categorie, vehicul) + chips | `views/dashboard/index.php:275-372` | `DashboardController::resolveFilters` | — |
| **Status vehicule** (flip: inactive pe motiv / pe tip / total) | `index.php:378-438`, `_inactive_face.php`, `_type_face.php` | `DashboardModel::getVehicleDashboardStatus` | stare curentă |
| **Status șoferi** (flip) | `index.php:440-490` | `DashboardModel::getDriverDashboardStatus` | stare curentă |
| **Cost total operațional** (perm `dashboard.view_financial` + `cost_operational`) | `_operational_card.php` | `DashboardOperationalCostService` → `OperationalCostService::compute` pe bucăți de lună; + `ExpenseModel::getCategoryTypeBreakdown`, `getDocumentCostBreakdown`, `getEquipmentCostBreakdown` | perioadă |
| Panou aprobări inactive | `index.php:~520-660` | `InactiveResourceApprovalModel::getPendingSummary` | stare curentă |

Reîmprospătare: `assets/js/dashboard-live.js` cere aceeași pagină și înlocuiește zonele `data-dashboard-live="<cheie>"` (morph + cifre animate). **Toate cardurile se calculează la fiecare încărcare**, nu există lazy loading.

Precedent util: Dashboard Analitic V2 salvează deja o configurație per utilizator (`utilizatori.dashboard_v2_carduri` JSON `{order, hidden}`, `DashboardAnaliticV2Controller::saveCards/normalizeCardLayout`) cu drag & drop HTML5 nativ, fără bibliotecă.

---

## 2. Inventar carduri existente pe module

Nivel de reutilizare:
- **A** = are deja endpoint JSON/HTML propriu; se montează cu o modificare minimă (init pe un root dat).
- **B** = datele vin dintr-o metodă de model/serviciu reutilizabilă, dar markup-ul e inline într-un view mare → se extrage un partial **fără schimbare vizuală**, folosit și de pagina sursă.
- **C** = nu merită / nu există card.

### Dispecer curse
| Card | Sursă | Endpoint | Timp | Perm | Nivel |
|---|---|---|---|---|---|
| Km pierduți | `dispecer_curse/_insights_cards.php:32` + `dispatcher-insights.js` | `km_pierduti&action=vehicles/gps_cached/gps` | luna curentă (1→azi), fix | `km_pierduti` | A |
| Km în desfășurare | `_insights_cards.php:47` | idem + `open_trip` | live | `km_pierduti` | A (depinde de Km pierduți) |
| Alimentări neasociate | `_insights_cards.php:68` | `dispecer_curse&action=insights_fuel` | rulant 30 zile | `dispecer_curse` (+`carburanti.link` la asociere) | A |
| Curse în desfășurare (GPS live) | `_live_gps_panel.php` + `dispatcher-live.js` (60 s) | `dispecer_curse&action=live_gps` | live | `dispecer_curse` | A |
| Curse cu informații lipsă | `_open_races_panel.php` | server, din variabile de pagină | curent | `dispecer_curse` | B |
| În așteptare aprobare | `index.php:592` + `approval-live.js` | `approvals_live` | live | `dispecer_curse` | A |
| Km service activ | `_service_km_panel.php` | server | curent | `dispecer_curse.service_km` | B |

### Carburanți / CardOil (`fillup_datetime`, perioadă)
| Card | Sursă | Endpoint | Nivel |
|---|---|---|---|
| 5 KPI morph (Motorină, AdBlue, consum mediu ×2, cost total) | `carburanti/index.php:1326-1408`, `fuel-kpi-morph.js` | compact: `FuelModel::getDashboardData` + `getKpiSparklines` (server); extins: `action=kpi_card_data` | A pentru extins, B pentru compact |
| Split grele / ușoare / neînregistrate | `index.php:1412-1449` | `$kpis['split']` | B |
| Alimentări neasociate (scurt) | `index.php:1647` | `getUnassociatedFillups` | B (sau reutilizat cardul Dispecer) |
| Full / T0 / consum normat | `index.php:1511` | `$normative` | C (prea legat de selecția de vehicul) |

### Șoferi / personal
| Card | Sursă | Nivel |
|---|---|---|
| Status șoferi (deja pe dashboard) | `DashboardModel` | există |
| Șoferi inactivi (launcher + modal) | `module/list.php:195-300`, `ModuleController::buildInactiveDriverOverview` | B |
| KPI Contabilitate personal | `contabilitate_personal/index.php:254-325`, `StaffAccountancyModel::getSummary` | B (perm sensibilă – salarii) |

### Revizii V2 (stare curentă; „Costuri luna în curs” = luna calendaristică)
| Card | Sursă | Endpoint | Nivel |
|---|---|---|---|
| 6 tile-uri KPI (monitorizate, în termen, se apropie, scadente, X/Y, costuri luna) | `revizii/v2.php:73`, `revizii-v2.js:244-312` | `revizii&action=v2_data` (`ReviziiV2Service::workspace`) | A pentru date; JS cuplat la `state` paginii → extragere modul de randare |
| Revizii scadente — Detalii (donut, top componente, vehicule afectate) | `revizii-v2.js:828-902` | idem | A/B (aceeași extragere) |
| Necesar piese | `v2_necesar_preview` | JSON | A |

Notă: `revizii-v2.js` și `ReviziiV2Service.php` au **modificări necomise** în lucru acum — extragerea trebuie făcută după ce le comiți.

### Intervenții V2
| Card | Sursă | Endpoint | Nivel |
|---|---|---|---|
| Status: De verificat / Programate / În service / În așteptare / Finalizate | `_interventions_v2.php:58-70` | `mentenanta&action=intervention_list` (`kpis`) | A; click → `?page=mentenanta&action=interventions&status=…` (URL-ul e deja sincronizat) |

### Mentenanță / Stoc / Anvelope
| Card | Sursă | Nivel |
|---|---|---|
| Stoc piese: valoare, total, sub minim, fără mișcare | `MaintenanceModel::getStockKpis` | B |
| Consumabile: tile-uri stoc + 30 zile + fluide | `ConsumabileStockService::overview`, `VehicleFluidService::statusCounts` | B (link-urile au deja context) |
| Consumabile: Alerte active | `ConsumabileAlertService::openAlerts` | B |
| AdBlue: 4 KPI | `AdBlueService::analysis` | B |
| Anvelope: Vehicule / Montate / Uzură / Critice | `TireModel::buildFleetTireOverview` (prin metodă privată din `ModuleController`) | B (mutare metodă în serviciu) |
| **Mentenanță „Prezentare generală”** | `maintenance/index.php:296-315` | **C — trendurile „↗ 15%” etc. sunt hardcodate (`:298-302`)**; nu se reutilizează până nu se calculează real |

### Documente
| Card | Sursă | Nivel |
|---|---|---|
| Expirate / 7 zile / 30 zile / Fără fișier | `module/list.php:686-764`, `DocumentModel::getNotificationSummary` + `getUrgentDocuments` | B; link-urile `stare_expirare=…` există |
| Documente obligatorii — Necesită atenție | `required_documents&format=json` | A |

### Financiar
| Card | Sursă | Date | Nivel |
|---|---|---|---|
| Centralizator: Total km / curse / tone / valoare + refacturări | `centralizator_facturare/dashboard.php:2676-2730`, `CentralizatorFacturareService::buildKpis` | lună de facturare (`BillingMonthRule`) | B (raportul complet e greu: trebuie apelat doar `buildCore` pentru KPI) |
| Cheltuieli: Total / Administrative / Operaționale | `cheltuieli/index.php:343-378`, `ExpenseModel::getSummary` | `data_cheltuiala` + `fillup_datetime` | B (deja folosit parțial în cardul de cost) |
| Cost operațional / km: Costuri totale, Activitate, Cost/km, Rezultat | `cost_operational&action=data` | `data_inceput` | A |
| eToll: sumă fără/cu TVA, sesiuni | `EtollStatementModel::sessionTotals` | `t.data` | B |
| Facturi: Total, Asociate, De rezolvat, Respinse | `InvoiceModel::getSummary` | `data_document` | B |
| Analitic V2: 11 carduri (curse, facturare, cheltuieli, profit, km, tone, încărcare, folosință, medii, venit/km, clienți) | `dashboard_analytic_v2_data` | lună de facturare | A (endpoint), dar randarea e în `dashboard-analitic-v2.js` (6400 linii) → extragere `kpiCards()/renderKpis` |

### GPS / km
| Card | Endpoint | Nivel |
|---|---|---|
| Centru Kilometraj: Km oficial, monitorizate, cu/fără T0, neactualizate, anomalii | `centru_kilometraj&action=data` (60 s) | A |
| SAS sandbox: în mișcare / oprite / parcate / offline, km azi | `sas_dashboard_sandbox&action=data` | A, dar e sandbox → nu-l propun în registrul inițial |
| Harta flotă, Monitorizare 3D | — | C (nu au carduri) |

---

## 3. Probleme descoperite care afectează regulile dashboard-ului

1. **Nu există status „anulată” pe curse.** Singura excludere e ștergerea soft `curse_dispecer.deleted_at IS NULL` (scrisă inline peste tot, fără helper). Cursă deschisă = `ora_sfarsit IS NULL`. Faze: `curse_segmente.deleted_at`.
2. **Trei reguli de dată diferite pentru „cursele din perioadă”:**
   - Analitic V2 + Centralizator: luna de facturare per beneficiar (`BillingMonthRule::periodTripsSql`), împărțit pe faze;
   - Cost operațional / km (deci și cardul „Cost total operațional” actual): `c.data_inceput`;
   - Km pierduți: data închiderii `COALESCE(data_sfarsit, data_inceput, data_cursa)`.
   Dacă dashboard-ul pune venitul din V2 lângă costul din Cost/km pentru aceeași lună, cifrele nu sunt pe aceeași populație de curse. **Decizie necesară (vezi §5).**
3. **Vehicule fără curse:** doar `OperationalCostService::buildUnits` le include corect (status `fara_activitate` când km=0 și cost>0, costuri nealocate pe rând „companie”). Analitic V2 ignoră grelele fără curse și cheltuielile neatașate de curse. Centralizatorul nu are cheltuieli.
4. Mentenanță „Prezentare generală” are procente de trend hardcodate.
5. Analitic V2: textul cardului „Curse” spune „data închiderii”, codul folosește data de facturare; textul „Grad de încărcare” spune plafonat la 100%, modelul a scos plafonul.

---

## 4. Arhitectură propusă

### 4.1 Registru de widget-uri — `services/DashboardWidgetRegistry.php`
O singură listă PHP (ca `permissions/modules/*.php`), fiecare intrare:

```php
'dispecer.km_pierduti' => [
    'module'      => 'Dispecer curse',
    'title'       => 'Km pierduți',
    'source'      => 'views/dispecer_curse/_insights_cards.php#km',   // componenta reutilizată
    'requires'    => [['km_pierduti', 'view']],                      // toate trebuie să treacă can()
    'filters'     => ['vehicle_category'],                           // ce filtre globale acceptă
    'time'        => 'month_to_date',  // period | live | current | rolling_30 | month_to_date
    'sizes'       => ['s', 'm'],        // s=1 col, m=2, l=3, xl=lățime completă (grilă 12 col)
    'default_size'=> 's',
    'render'      => 'client',          // 'server' = fragment HTML; 'client' = modul JS existent montat pe root
    'assets'      => ['css/dispatcher-insights.css', 'js/dispatcher-insights.js'],
    'refresh'     => 'none',            // none | interval:60 | on_filter
    'link'        => ['page' => 'km_pierduti'],                      // + filtrele mapate
    'population'  => 'operational',     // operational | financial | fleet | none
],
```

### 4.2 Randare și încărcare leneșă
- `page=dashboard` randează doar filtrele + scheletul grilei cu cardurile din layout-ul utilizatorului (placeholder cu titlu, fără date).
- Fiecare card de tip `server` se încarcă cu `?page=dashboard&action=widget&id=<id>&<filtre>` → controllerul verifică din nou `requires`, apelează **aceeași metodă model/serviciu** ca pagina sursă și randează **același partial** extras (pagina sursă îl include și ea).
- Cardurile de tip `client` primesc un root + config JSON și apelează endpoint-ul existent al modulului (fără endpoint nou).
- `IntersectionObserver`: se încarcă doar ce e vizibil; `AbortController` anulează cererile la schimbarea filtrelor; cardurile ascunse nu există în DOM → zero cereri.
- Interval de reîmprospătare doar pentru `live` (GPS live 60 s, Centru Kilometraj 60 s, aprobări) și oprit când tab-ul e ascuns.
- Cele 3 carduri actuale + panoul aprobări devin primele intrări din registru (randate ca acum; `dashboard-live.js` rămâne pentru morph).

### 4.3 Contract de filtre
- Filtrele globale: `period` (+`date_from/date_to`), `vehicle_category`, `vehicle_id`.
- Fiecare widget declară ce acceptă; restul nu i se trimit. Pe card apare un chip de semantică: **Live**, **Stare curentă**, **Ultimele 30 zile**, **Luna curentă**, sau perioada filtrată. Dacă un filtru global e activ dar nu se aplică cardului, chip-ul spune „ignoră vehiculul selectat”.
- Linkurile „Vezi detalii” traduc filtrele în parametrii paginii sursă (ex. Carburanți `date_from/date_to/vehicles[]/vehicle_class`, Intervenții `vehicle_id/date_from/date_to/status`, Documente `vehicle_id/stare_expirare`).

### 4.4 Persistență per utilizator
- Coloană nouă `utilizatori.dashboard_layout TEXT NULL` (migrare SQL + `ensure…` ca la `dashboard_v2_carduri`; adăugată la lista de excluderi din `ModuleController:6908`).
- JSON: `{"v":1,"items":[{"id":"dispecer.km_pierduti","size":"s"}, …]}` — ordinea = poziția în listă; ce nu e în listă e ascuns. NULL = layout implicit. Nu se salvează date.
- Endpoint `?page=dashboard&action=save_layout` (POST, CSRF), normalizează: elimină id-uri necunoscute, dubluri, dimensiuni nepermise și **widget-uri pentru care utilizatorul nu are drept**.
- Acțiunea nouă în `permissions/modules/dashboard.php` endpoints: `widget`, `save_layout` → `view`.

### 4.5 Interfață
- Buton **„Configurare panou”** → drawer dreapta (pe mobil ecran complet): căutare „Caută card...”, grupare pe modul, checkbox, drag & drop HTML5 + butoane ↑/↓ accesibile, selector dimensiune, „Aplică”, „Resetează la implicit”, secțiune „Carduri ascunse”.
- Dropdown **„Adaugă card”** în bara dashboard-ului pentru inserare rapidă.
- Pe fiecare card: meniu ⋯ (dimensiune, ascunde, deschide pagina sursă). Meniurile se poziționează `fixed` ca să nu fie tăiate de `overflow` al cardului.
- Grilă CSS 12 coloane: desktop s=3/m=6/l=9/xl=12, tabletă s=6/m,l,xl=12, mobil 12; `minmax(0,1fr)` + `min-width:0` pe carduri ca să nu apară overflow orizontal.

### 4.6 Populații operațional vs financiar
Reguli implementate o singură dată într-un serviciu mic `DashboardPopulationService`, care **reutilizează SQL-ul existent**, nu inventează altul:
- **Operațional** = vehicule cu ≥1 cursă `deleted_at IS NULL` în perioadă, după regula de dată aleasă (§5). Cursele deschise sunt numărate separat („în desfășurare”), nu la km/venit.
- **Financiar** = toate vehiculele cu costuri în perioadă — exact unitățile din `OperationalCostService::buildUnits` (deja include `fara_activitate`) + vehiculele ușoare din `getLightVehicleCosts`.
- Card nou derivat (singurul „nou”, dar fără calcule noi): **„Activitate vs. cheltuieli”** = 3 contoare (doar curse / curse + costuri / doar costuri) + listă expandabilă cu costurile vehiculelor fără curse, din aceleași rânduri ale serviciului de cost. Mediile pe flotă afișate pe dashboard spun explicit „din N vehicule cu curse”.

---

## 5. Decizii necesare înainte de implementare

1. **Regula de dată pentru KPI-urile operaționale de pe dashboard** (curse, km, venit): luna de facturare (`BillingMonthRule`, ca Analitic V2 și Centralizator) — recomandat, pentru că venitul trebuie să bată cu facturarea; sau data începerii (ca Cost/km). Cardul de cost rămâne pe regula Cost/km și primește nota de semantică.
2. **Prima tranșă** (Faza 3): registru + layout + drawer + cele 4 elemente actuale + widget-urile de nivel A (Km pierduți, Km în desfășurare, Alimentări neasociate, Curse GPS live, Intervenții status, Documente obligatorii, Centru Kilometraj, Cost/km rezumat), apoi extrageri de nivel B pe loturi (Documente, Carburanți compact, Revizii, Stoc/Consumabile, Cheltuieli, Centralizator, eToll, Facturi).
3. Revizii V2 se extrage abia după ce modificările necomise din `revizii-v2.js` / `ReviziiV2Service.php` sunt comise.

---

## 6. Stare implementare — tranșa 1 (2026-11-16)

Decizie: KPI-urile operaționale noi folosesc **data începerii cursei** (`data_inceput`, regula Cost operațional / km).

Implementat:
- `services/DashboardWidgetRegistry.php` (registru), `utilizatori.dashboard_layout` (migrarea `2026_11_16_000002`), `?page=dashboard&action=widget|save_layout` (drepturi verificate la randare, la fragment și la salvare).
- `assets/js/dashboard-grid.js` + `assets/css/dashboard-grid.css`: grilă 12 coloane (s/m/l/xl), bara cardului (modul, semantica timpului, filtre ignorate, meniu ⋯), „Adaugă card”, „Configurare panou” (căutare, grupare pe modul, bife, drag & drop + săgeți, dimensiune, Aplică, Implicit), încărcare leneșă + reîncărcare la schimbarea filtrelor.
- Carduri: Status vehicule, Status șoferi, Cost total operațional (neschimbate); **Activitate vs. cheltuieli** (nou, din aceleași unități ale motorului de cost — totalul bate cu Cost total operațional); **Activitate șoferi** (cardurile din Istoric activități șofer, totalul comparației pe toți șoferii cu curse — aceleași cifre ca pagina); Intervenții pe status; Alerte documente; Km pierduți · în desfășurare · alimentări neasociate; Curse în desfășurare (GPS live).
- Extrase fără schimbare vizuală: `views/maintenance/_interventions_v2_kpis.php`, `views/module/_document_summary.php`, `views/driver_activity_history/_kpi_helpers.php`.

Următoarele tranșe (nivel B): Carburanți (KPI compacte + split), Revizii V2 (după commit-ul modificărilor în lucru), Stoc piese / Consumabile / AdBlue, Cheltuieli, Centralizator, eToll, Facturi, Centru Kilometraj, Șoferi inactivi.

## 7. Tranșa 2 (2026-11-16)

Carduri noi (toate „lazy”, reîncărcate doar când se schimbă filtrele pe care le folosesc):
- **Carburant** (`carburanti.kpis`): cele 5 carduri morph + Vehicule grele / ușoare / Neînregistrate; analiza extinsă (fuel-kpi-morph.js + Chart.js) funcționează în dashboard. Date: `FuelController::dashboardKpiData` (aceleași apeluri ca pagina).
- **Cheltuieli** (`cheltuieli.kpis`), **Facturi** (`facturi.summary`, fără reasocierea de la deschiderea paginii), **eToll** (`etoll.kpis`, `EtollStatementController::dashboardKpiData`), **Stoc piese** (`stoc.piese`), **Alerte consumabile & fluide** (`consumabile.alerts`, închiderea manuală rămâne în pagină).

Partiale extrase (paginile-sursă randează identic, verificat prin diff HTML vechi/nou): `views/carburanti/_kpi_context.php`, `_kpi_cards.php`; `views/cheltuieli/_kpi_band.php`; `views/maintenance/_stock_kpis.php`, `_consumabile_alerts.php`; `views/facturi/_summary_cards.php`; `views/etoll/_kpis.php`.

Rămase: Centralizator facturare (raport greu, de făcut separat), Revizii V2 (după commit-ul modificărilor în lucru), Centru Kilometraj, Șoferi / vehicule inactive.

## 8. Centralizator facturare (2026-11-16)

- Card `centralizator.kpis` (lazy, ~1,4 s pe toată flota): cardurile KPI ale paginii (km / curse / tone sau valoare pe tip, defalcare la hover, comparație cu luna anterioară) + totalul refacturărilor (link spre tabelul Refacturări din pagină; panoul interactiv rămâne în pagină).
- Semantica timpului: **luna de facturare** (`BillingMonthRule`, regula fiecărui beneficiar) — luna în care se termină perioada dashboard-ului. Filtru vehicul suportat.
- `CentralizatorFacturareService::getKpiReport()` + `buildCoreWithPrevious()` (getReport folosește același pas, fără calcul dublat).
- Stilurile inline ale paginii mutate neschimbate în `assets/css/centralizator-facturare.css`; partiale `views/centralizator_facturare/_kpi_helpers.php`, `_kpi_cards.php`. Pagina randează identic (diff HTML), CSS-ul e copiat byte cu byte.

Rămase: Revizii V2 (după commit-ul modificărilor în lucru), Centru Kilometraj, Șoferi / vehicule inactive.

## 9. Mod „Pagini” (carusel) — 2026-11-16

- Implicit: cardurile se împart în pagini cât încap sub filtre (rândurile grilei măsurate în browser); navigare cu săgețile laterale (lipite la mijlocul ecranului), bara ‹ puncte › de sub filtre, tastele ← / → și swipe pe telefon. Comutator „Pagini / Toate” în bara de unelte, salvat în `utilizatori.dashboard_layout.view`.
- Pagina curentă e ancorată pe primul ei card: încărcarea / redimensionarea cardurilor nu mută utilizatorul pe altă pagină. Înălțimea ultimei încărcări a cardurilor lazy se ține în localStorage (estimare pentru schelet) ca paginile să fie stabile.
- Filtrele reîncarcă imediat cardurile paginii vizibile; cele de pe alte pagini sunt marcate „stale” și se reîncarcă abia când pagina lor se deschide.
- Filtre compacte în modul „Pagini” (clasa is-compact-filters pe rădăcina paginii, pusă de server și comutată de JS): un singur rând de ~54 px (era ~200 px), fără „Aplică filtre” (se aplică la schimbare) și fără rândul de chips; pe telefon etichetele stau deasupra câmpurilor. Modul „Toate” păstrează panoul complet. Pe desktop și laptop (1366×768) fiecare pagină încape fără scroll.

## 10. Filtrele paginii-sursă în card — 2026-11-16

- Mecanism general: `config` în registru declară cheile și tipurile (int_list / int / date / enum); `DashboardWidgetRegistry::normalizeConfig` validează (interval cu ambele capete, inversat → îndreptat). Setările se salvează pe cardul din `utilizatori.dashboard_layout`, se trimit la `action=widget` și trec prin validarea paginii-sursă.
- **Activitate șoferi**: Șoferi (mai mulți, cu căutare; aleșii fără curse rămân bifați, marcați „fără curse”), Interval de timp (perioada panoului / lună cu ‹ › / interval personalizat), Tip transport, Beneficiar, Vehicul + Resetează filtrele cardului. „Grupare după” nu se aplică (afectează doar graficele paginii). Cifre identice cu pagina pe aceleași filtre (verificat: 2 șoferi + Primar + octombrie, 1 șofer, beneficiar).
- Meniul listei de șoferi se mută în `<body>` cât e deschis (cardurile au transform la hover → `position: fixed` ar fi relativ la card).
- Următoarele carduri pentru filtre proprii: Centralizator (beneficiar, tip activitate, marfă, rută...), Cheltuieli (categorie, alocare, beneficiar, șofer, furnizor), Facturi (tip, status, șofer, sursă), eToll (nr., capacitate, categorie, beneficiar, tip, status).

## 11. Filtrele paginii-sursă pe toate cardurile — 2026-11-16

- Bara de filtre comună `views/dashboard/_card_filters.php`, descrisă în registru (`filter_fields`: multi / select / period / month / text) și validată de `normalizeConfig` (tipuri: int, int_list, date, month, str, enum, enum_list, str_list cu tipar, pattern). Valorile alese fără date rămân vizibile („fără date”) ca să poată fi scoase.
- Carduri cu filtrele paginii: Activitate șoferi; Facturare (Centralizator: luna de facturare ‹ ›, beneficiari, tip activitate, marfă, loc încărcare, zonă, rută, vehicule); Cheltuieli (perioadă, categorie, tip, alocare, beneficiar, vehicul, șofer, furnizor); Facturi (dată document, tip, status, vehicul, șofer, sursă); eToll (perioadă, vehicule, capacitate, categorie, beneficiari, tip, status); Carburant (perioadă, vehicule, carburant, marcă, categorie, tip transport); Intervenții (data programării, tip, responsabil, sursă, vehicul; statusul e chiar cardul); Alerte documente (vehicul). Intervenții și Documente sunt acum lazy.
- Fără filtre proprii (nu au filtre în pagina-sursă sau sunt carduri native ale panoului): Status vehicule / șoferi, Cost total operațional, Activitate vs. cheltuieli, Stoc piese, Alerte consumabile; Dispecer (Km pierduți, GPS live) au deja filtrele lor în panourile proprii.
- Verificat: fiecare card cu filtre = pagina-sursă pe aceleași filtre (Cheltuieli, Facturi, eToll, Centralizator, Carburanți, Intervenții, Documente).
- Corectat în `ExpenseModel::getSummary` (2026-11-16): sumele benzii KPI ignorau filtrul de categorie (doar carburantul îl respecta), deci „Administrativă” arăta documentele operaționale la total. Acum sumele respectă categoria aleasă; numărătorile taburilor rămân fără filtru. Verificat pe iulie 2026: Administrativă = 581,07 lei, Operațională = 497.809,00 lei, Toate = 498.390,07 lei (pagină = card).

## 12. Secțiuni cu filtre proprii în bara de sus (mod „Pagini”) — 2026-11-17

- Secțiuni fixe (decizia utilizatorului: o secțiune per card cu filtre proprii): fiecare card cu `filter_fields` e o pagină proprie a caruselului; cardurile fără filtre proprii, consecutive, se împart pe pagini după înălțime, ca înainte (secțiuni comune, cu filtrele panoului).
- Bara de sus arată doar filtrele secțiunii active: formularul panoului în secțiunile comune, formularul cardului (mutat din card de `placeFilters()` în `[data-dash-section-filters]`) în secțiunea lui. Fără îmbinare de filtre, fără stare nouă: starea rămâne în config-ul cardului (layout), deci se păstrează la navigare și la reîncărcare. Același handler, aceleași cereri.
- Modul „Toate” nu are secțiuni: formularele revin în carduri (marcaj `data-dash-cf-slot`).
- Verificat (desktop + telefon): secțiunile comune → filtrele panoului; secțiunile-card → filtrele cardului, niciun formular rămas în card; schimbare filtru din bară → cardul se reîncarcă (Distribuție = 82 curse, ca în pagină); valoarea rămâne la revenire; comutare Toate/Pagini corectă; fără depășire orizontală, fără erori JS.
