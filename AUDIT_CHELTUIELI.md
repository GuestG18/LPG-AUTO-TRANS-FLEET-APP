# Audit date — modul Cheltuieli

Data: 07.09.2026 · Scop: inventarul complet al datelor financiare din modulul **Cheltuieli**, **înainte** de reproiectarea fluxului către Dashboard Analitic.
Audit read-only: nicio linie de cod, schemă, interogare sau configurare nu a fost modificată. Toate câmpurile și relațiile de mai jos sunt verificate pe schema live (`SHOW FULL COLUMNS`, `INFORMATION_SCHEMA.KEY_COLUMN_USAGE`) și pe cod — niciunul nu este dedus.

> **Volum la momentul auditului (bază locală):** `cheltuieli` — 1 rând, `cheltuieli_alocari` — 1 rând, `cheltuieli_documente` — 0, `curse_cheltuieli` — 11 rânduri. Tabelele legacy (`office_expenses`, `administrative_expenses` + documentele lor) sunt goale — migrarea s-a făcut. Auditul descrie deci **schema și codul**; volumele reale sunt pe VPS, unde structura este identică.

---

## Constatarea principală

În aplicație există **două sisteme de cheltuieli complet separate**, fără nicio legătură între ele. Dashboard Analitic citește exclusiv al doilea.

| | `cheltuieli` (modulul auditat) | `curse_cheltuieli` (sistemul paralel) |
|---|---|---|
| Intrare | formular manual, pagina Cheltuieli | tab-ul Cheltuieli din Dispecer curse |
| Unitate | un document (factură / bon / chitanță) | o linie de cheltuială pe o cursă |
| Legătură cu flota | prin `cheltuieli_alocari` | indirect, prin `cursa_id` |
| Legătură cu cursa | **nu există** | directă |
| Citit de | pagina însăși + Cost operațional/km (doar categoria administrativă) | Dashboard Analitic, Cost/km, Istoric cheltuieli, Centralizator |
| Dashboard Analitic | **nu** | **da — singura sursă** |

---

## A. Arhitectura actuală

Modulul este un CRUD clasic, fără AJAX: formular POST → controller → tranzacție SQL → redirect.
Un *document* de cheltuială este un rând în `cheltuieli`, iar banii din el sunt împărțiți în 1..n rânduri de `cheltuieli_alocari`, a căror sumă trebuie să fie exact valoarea documentului. Alocarea este singurul mecanism prin care o cheltuială ajunge să aibă vehicul sau șofer.

Nu există migrări SQL separate: schema este creată și extinsă din `ExpenseModel::ensureSchema()`, apelat în constructor la fiecare încărcare de pagină (`createTables()`, `ensureDocumentColumns()`, `ensureResponsibleDriverColumn()`, `seedOperationalTypes()`, `importLegacyData()`).

---

## B. Inventar fișiere și tabele implicate

| Rol | Fișier / tabelă | Observații |
|---|---|---|
| Rutare | `htdocs/index.php:647` | `case 'cheltuieli'` → `ExpenseController::handle($action)` |
| Rute legacy | `cheltuieli_birou`, `cheltuieli_administrative` | `index.php:654-657` — redirect 302 către pagina unificată |
| Controller | `htdocs/controllers/ExpenseController.php` (796 l.) | `index`, `export`, `store`, `update`, `delete`, `download_document` |
| Model | `htdocs/models/ExpenseModel.php` (1096 l.) | schemă auto-migrată, interogări, CRUD tranzacțional |
| View | `htdocs/views/cheltuieli/index.php` (98 KB) | listă + KPI + formular modal |
| Permisiuni | `htdocs/config/permissions.php:260` | scope `accountancy`; `view` / `create` / `edit` / `delete` / `export` |
| Tabelă document | `cheltuieli` | 29 coloane — antetul documentului |
| Tabelă alocări | `cheltuieli_alocari` | împărțirea sumei pe vehicul / șofer / companie |
| Nomenclator | `cheltuieli_tipuri` | 35 tipuri, în 2 categorii |
| Documente | `cheltuieli_documente` | fișiere atașate |
| Consumator extern | `htdocs/models/OperationalCostModel.php:512` | **singurul** cod din afara modulului care citește `cheltuieli` |

Nu există API JSON, endpoint REST sau cereri JavaScript pentru acest modul — nici `fetch`, nici XHR în view. Nu există repository/service layer: toată logica de date este în `ExpenseModel`.

---

## C. Inventarul câmpurilor financiare

### C.1 `cheltuieli` — antetul documentului

| Câmp | Tip DB | Etichetă UI | Oblig. | Înțeles |
|---|---|---|---|---|
| `id` | `int unsigned` PK | — | auto | Cheia documentului |
| `categorie` | `enum(administrativa, operationala)` | Categorie | **da** | Cele două familii mari de cheltuieli |
| `tip_id` | `int unsigned` FK | Subcategorie | **da** | Tipul concret (Motorină, Chirie birou…) |
| `tip_document` | `enum(factura, bon_fiscal, chitanta, alt_document)` | Tip document | **da** | Natura actului justificativ |
| `data_cheltuiala` | `date` | Data documentului | **da** | **Data după care se filtrează luna** |
| `furnizor` | `varchar(190)` | Furnizor / Comerciant | **da** | Text liber — nu există entitate furnizor |
| `descriere` | `varchar(255)` | Descriere | nu | Ce s-a cumpărat |
| `cui` | `varchar(20)` | CUI/CIF | nu | Codul fiscal al furnizorului, text liber |
| `valoare` | `decimal(12,2)` | Total | **da** | Totalul documentului (cu TVA). Baza alocării |
| `valoare_neta` | `decimal(12,2)` NULL | Valoare fără TVA | nu | Baza fără TVA |
| `tva` | `decimal(12,2)` NULL | TVA | nu | Valoarea TVA |
| `moneda` | `char(3)` = `RON` | Monedă | implicit | RON / EUR / USD / HUF — **fără curs valutar** |
| `modalitate_plata` | `enum(cash, card, transfer_bancar, alte)` | Modalitate plată | nu | Cum s-a plătit |
| `status_plata` | `enum(platita, neplatita, partial)` | Status plată | nu | Doar pentru `tip_document = factura` |
| `data_platii` | `date` NULL | Data plății | nu | Doar factură; golită dacă status = neplătită |
| `scadenta` | `date` NULL | Scadență | nu | Doar factură |
| `sursa` | `enum(manual, spv, ocr, import)` | — | auto | Origine; controllerul scrie **mereu** `manual` |
| `numar_document` | `varchar(120)` | Număr document | nu | Serie/număr, text liber — nu este cheie |
| `observatii` | `text` | Observații | nu | Note libere |
| `beneficiar_id` | `int unsigned` FK NULL | Beneficiar / Client | condiț. | Un singur client per document; activat prin bifă |
| `sofer_responsabil_id` | `int unsigned` FK NULL | Șofer responsabil | nu | **Informativ** — nu preia bani din valoare |
| `alocare_tip` | `enum(vehicul, sofer, companie, mixt)` | Alocată către | implicit | Rezumat al alocării; implicit `companie` |
| `distribuire` | `enum(egal, manual)` | Mod distribuire | implicit | Cum se împarte suma între entități |
| `legacy_source` | `varchar(40)` NULL | — | auto | `office` / `administrative` — import idempotent |
| `legacy_id` | `int unsigned` NULL | — | auto | ID-ul din tabela legacy |
| `added_by` | `int unsigned` FK NULL | Adăugat de | auto | Utilizatorul care a creat |
| `updated_by` | `int unsigned` FK NULL | — | auto | Ultimul utilizator care a modificat |
| `created_at` | `datetime` | — | auto | Momentul creării |
| `updated_at` | `datetime` | — | auto | Momentul ultimei modificări |

### C.2 `cheltuieli_alocari` — împărțirea sumei

| Câmp | Tip DB | Etichetă UI | Oblig. | Înțeles |
|---|---|---|---|---|
| `cheltuiala_id` | `int unsigned` FK | — | **da** | Documentul părinte |
| `tip_alocare` | `enum(vehicul, sofer, companie)` | Alocată către | **da** | Dimensiunea pe care cade suma |
| `vehicul_id` | `int unsigned` FK NULL | Selectează vehicul(e) | condiț. | Completat doar la alocare pe vehicul |
| `sofer_id` | `int unsigned` FK NULL | Selectează șofer(i) | condiț. | Completat doar la alocare pe șofer |
| `eticheta` | `varchar(150)` | — | auto | Denormalizare: nr. înmatriculare / nume șofer / „Companie" |
| `suma` | `decimal(12,2)` | Sumă alocată | **da** | Partea din total care cade pe această entitate |

### C.3 `cheltuieli_documente`

`cheltuiala_id` (FK, CASCADE) · `original_name` · `stored_name` · `uploaded_by` (FK) · `legacy_source` · `legacy_id` · `created_at` · `updated_at`.

### C.4 Câmpuri care NU există

Verificate explicit în schema live și **absente** din `cheltuieli`:

`cursa_id` · `factura_id` · `centru_cost_id` · `categorie_id` · `furnizor_id` · cantitate · preț unitar · curs valutar · perioadă contabilă distinctă de `data_cheltuiala`.

Furnizorul (`furnizor`, `cui`) și numărul documentului (`numar_document`) sunt **text liber**, nu chei străine.

---

## D. Relațiile existente

Chei străine confirmate în `INFORMATION_SCHEMA`:

```
cheltuieli.tip_id                 -> cheltuieli_tipuri.id                      (RESTRICT)
cheltuieli.beneficiar_id          -> configurare_beneficiari_transport.id      (SET NULL)
cheltuieli.sofer_responsabil_id   -> soferi.id                                 (SET NULL)
cheltuieli.added_by               -> utilizatori.id                            (SET NULL)
cheltuieli.updated_by             -> utilizatori.id                            (SET NULL)
cheltuieli_alocari.cheltuiala_id  -> cheltuieli.id                             (CASCADE)
cheltuieli_alocari.vehicul_id     -> vehicule.id                               (SET NULL)
cheltuieli_alocari.sofer_id       -> soferi.id                                 (SET NULL)
cheltuieli_documente.cheltuiala_id-> cheltuieli.id                             (CASCADE)
cheltuieli_documente.uploaded_by  -> utilizatori.id                            (SET NULL)
```

### Ce se poate lega, pe entitate

| Entitate | Suportat | Prin ce câmp | Observație |
|---|---|---|---|
| Vehicul | **da** | `cheltuieli_alocari.vehicul_id` | 1..n vehicule per document, cu sumă per vehicul |
| Șofer | **da** | `cheltuieli_alocari.sofer_id` | 1..n șoferi; alternativ `sofer_responsabil_id` fără bani |
| Companie / firmă | **da** | `tip_alocare = 'companie'` | Starea implicită, fără vehicul sau șofer |
| Client / beneficiar | parțial | `cheltuieli.beneficiar_id` | Un singur client per document, fără sumă per client |
| Cursă | **nu** | — | Nu există `cursa_id` nicăieri în modul |
| Factură (entitate) | **nu** | — | `numar_document` e text liber, nu grupează linii |
| Furnizor (entitate) | **nu** | — | `furnizor` + `cui` sunt text liber |
| Centru de cost | **nu** | — | Conceptul nu există în schemă |
| Categorie / subcategorie | **da** | `categorie` + `tip_id` | Două niveluri, nomenclator în DB |
| Perioadă / lună | parțial | `data_cheltuiala` | Derivată din dată; fără câmp de perioadă contabilă |
| Data plății | **da** | `data_platii`, `scadenta` | Doar pentru facturi; nefolosite în filtrare |
| Utilizator | **da** | `added_by`, `updated_by` | Audit trail minimal |

---

## E. Categoriile de cheltuieli

Două niveluri: **categoria** (enum fix, 2 valori) și **tipul** (nomenclator `cheltuieli_tipuri`, 35 rânduri). Alocarea pe vehicul/șofer/companie este *independentă* de categorie: orice tip poate merge oriunde.

| Categorie | Valoare stocată | Tipuri | Sursă | Vehicul? | Șofer? | Companie? |
|---|---|---|---|---|---|---|
| Operațională | `operationala` | 13 | seed în cod (`seedOperationalTypes`) | da | da | da |
| Administrativă | `administrativa` | 22 | import legacy `office_cat` + `admin_cat` | da | da | da |

**Tipuri operaționale (13):** Motorină · AdBlue · Taxe drum · Diurnă · Cazare · Reparații · Piese auto · Anvelope · Asigurări · Spălătorie · Parcare · Amenzi · Alte cheltuieli operaționale

**Tipuri administrative (22):** Chirie birou · Utilități · Internet / telefonie · Consumabile birou · Cafea / apă / protocol · Produse curățenie · IT și software · Servicii externe · Mobilier și echipamente · Comisioane bancare · Alte cheltuieli · Salarii birou *(inactiv)* · Taxe și impozite · Asigurări firmă · Contabilitate / Audit · Consultanță juridică · Licențe și autorizații · Deplasări / Protocol · Marketing / Publicitate · Comisioane bancare *(admin)* · Resurse umane / Training · Alte cheltuieli administrative

> „Comisioane bancare" și „Alte cheltuieli" apar **de două ori**, o dată din fiecare modul legacy (slug-uri `birou-…` și `admin-…`). Sunt tipuri distincte în DB, deci orice raport pe tip le va afișa separat. „Salarii birou" a fost dezactivat automat la import, fiind categorie calculată în modulul vechi.

### Categoriile celuilalt sistem, pentru comparație

`curse_cheltuieli` are propriul set, **incompatibil** cu cel de mai sus:
- enum `tip_cheltuiala`: `motorina`, `taxa_acces`, `port`, `trece`, `taxe_drum`, `diurna`, `service`, `alte`
- tabela `categorii_cheltuieli_curse` (7 rânduri): Taxe drum *(inactiv)*, Diurna, Reparatii, Alte cheltuieli, Taxa acces, Port, Trecere

Cele două nomenclatoare nu au nicio corespondență definită în cod.

---

## F. Sume, TVA și logica datelor

### F.1 Cum sunt stocate sumele

| Element | Există? | Detaliu |
|---|---|---|
| Valoare cu TVA (total) | **da** | `valoare`, obligatorie, > 0 — referința întregului modul |
| Valoare fără TVA | opțional | `valoare_neta`, poate rămâne NULL |
| TVA | opțional | `tva`, poate rămâne NULL; nu se calculează automat |
| Monedă | parțial | `moneda` acceptă RON/EUR/USD/HUF, dar **nu există curs** |
| Cantitate | **nu** | nu există câmp |
| Preț unitar | **nu** | nu există câmp |
| Total factură | parțial | `valoare` **este** totalul; nu există factură cu mai multe linii |
| Sumă alocată | **da** | `cheltuieli_alocari.suma`, per entitate |
| Mai multe linii într-o factură | **nu** | un document = un rând = un singur tip de cheltuială |
| O cheltuială pe mai multe vehicule | **da** | prin mai multe rânduri de alocare |

### F.2 Regulile de calcul, exact cum sunt implementate

```
// 1. Coerența TVA — verificată doar dacă ambele câmpuri sunt completate
if (net > 0 && tva > 0 && abs((net + tva) - total) > 0.01)
    -> eroare „Valoarea fără TVA + TVA diferă de total"

// 2. Împărțire egală (implicit) — pe TOTALUL CU TVA, nu pe net
base = floor((valoare / nr_entitati) * 100) / 100
fiecare rând = base, ULTIMUL rând = valoare - base * (nr_entitati - 1)

// 3. Împărțire manuală — suma trebuie să dea exact totalul
diff = valoare - SUM(sume_introduse)
if (abs(diff) > 0.01) -> eroare, salvarea este blocată
else                  -> diff se adaugă la ultimul rând

// 4. Fără vehicul și fără șofer selectat
-> un singur rând: tip_alocare = 'companie', suma = valoare (100%)
```

**Vehicul și șofer se exclud reciproc.** Controllerul respinge explicit alocarea simultană pe vehicule *și* pe șoferi (`ExpenseController::collectAllocations`, mesaj: „Alocarea se face fie pe vehicule, fie pe șoferi"). În consecință valoarea `alocare_tip = 'mixt'` există în enum, dar **nu poate fi produsă din interfață** — linia care o atribuie este inaccesibilă. Pentru a lega totuși un șofer de o cheltuială de vehicul există `sofer_responsabil_id`, care nu participă la sume.

**Alocarea se face pe suma cu TVA.** Sumele alocate se raportează la `valoare` (total cu TVA), în timp ce Cost operațional/km consumă `COALESCE(valoare_neta, valoare)` — adică netul. Cele două citiri ale aceleiași cheltuieli nu dau aceeași cifră când TVA e completat, iar dacă TVA lipsește se folosește totalul ca și cum ar fi net.

### F.3 Logica datelor

| Dată | Câmp | Obligatorie | Folosită la filtrare? |
|---|---|---|---|
| Data documentului | `data_cheltuiala` | **da** | **DA — singura** |
| Data plății | `data_platii` | nu | nu |
| Scadență | `scadenta` | nu | nu |
| Creare înregistrare | `created_at` | auto | nu |
| Modificare | `updated_at` | auto | nu |
| Perioadă contabilă | — | inexistentă | — |

**Răspunsul la întrebarea despre lună:** cheltuielile lunii septembrie 2026 sunt selectate exclusiv după `data_cheltuiala`. Perioada implicită la deschiderea paginii este prima → ultima zi a lunii curente. Data plății nu influențează în niciun fel raportarea lunară.

```php
// ExpenseController::collectFilters() — perioada implicită
$defaultStart = $today->modify('first day of this month')->format('Y-m-d');
$defaultEnd   = $today->modify('last day of this month')->format('Y-m-d');

// ExpenseModel::buildWhere() — filtrul aplicat
'e.data_cheltuiala >= :date_start'
'e.data_cheltuiala <= :date_end'

// OperationalCostModel::getManagementMonthlyCost() — aceeași coloană
WHERE c.categorie = "administrativa"
  AND c.data_cheltuiala BETWEEN :ms AND :me
```

---

## G. Calculele existente

| Calcul | Formulă / interogare | Tabele | Grupare |
|---|---|---|---|
| Total cheltuieli | `SUM(e.valoare)` | `cheltuieli` + `cheltuieli_tipuri` | niciuna (perioadă + filtre) |
| Split pe categorie | `SUM(e.valoare)`, `COUNT(*)` | `cheltuieli` | `GROUP BY e.categorie` |
| Procent adm. / oper. | `categorie ÷ total × 100` | — | în PHP |
| Distribuție pe alocare | `SUM(a.suma)` | `cheltuieli_alocari ⋈ cheltuieli` | `GROUP BY a.tip_alocare` |
| Top tipuri | `SUM(e.valoare)` | `cheltuieli ⋈ cheltuieli_tipuri` | `GROUP BY t.id` (top 4 + „Altele") |
| Listă paginată | `SELECT e.*` + nume tip/beneficiar/șofer + nr. documente | 4 tabele + `utilizatori` | `ORDER BY data DESC`, LIMIT/OFFSET |
| Cost management lunar | `SUM(COALESCE(valoare_neta, valoare))` | `cheltuieli` | lună, doar `administrativa` |

### Calcule care NU există

Modulul nu calculează nicăieri: **total pe vehicul**, **total pe șofer**, total pe furnizor, total TVA, medii, evoluție lunară, cost pe km, alocare pe client. Distribuția pe `tip_alocare` spune „cât s-a dus către vehicule" în total, dar niciodată *către care vehicul*. Datele există în `cheltuieli_alocari` — interogarea lipsește.

### Nuanță în KPI-urile paginii

Cardurile Total / Administrative / Operaționale ignoră deliberat filtrul de categorie (ele *sunt* defalcarea pe categorii), în timp ce distribuția pe alocare și topul tipurilor respectă toate filtrele active. E intenționat și documentat în cod, dar înseamnă că două cifre de pe același ecran răspund la filtre diferite.

---

## H. Integrarea cu Dashboard Analitic

> **NU EXISTĂ NICIUN FLUX DE DATE CHELTUIELI → DASHBOARD ANALITIC.**

Căutare pe tot proiectul după `FROM cheltuieli`, `JOIN cheltuieli`, `INTO cheltuieli`, `cheltuieli_alocari`: tabela este atinsă de exact două fișiere — `ExpenseModel.php` (modulul însuși) și `OperationalCostModel.php` (pagina Cost operațional/km). Modelele Dashboard Analitic (`DashboardAnaliticV2Model.php`, `DispecerCurseModel.php`) nu o referă deloc.

### Ce înseamnă „Cheltuieli" în Dashboard Analitic

KPI-ul Cheltuieli, profitul, marja, cost/km și profit/km se alimentează integral din `curse_cheltuieli`:

```sql
-- DashboardAnaliticV2Model::fromSql() — subinterogarea „exp"
LEFT JOIN (
    SELECT cursa_id,
        GREATEST(0,
            SUM(COALESCE(suma, 0)) - SUM(CASE WHEN refacturare_facturata = 1
                                              THEN COALESCE(refacturare_suma, 0) ELSE 0 END)
        ) AS total_cheltuieli,
        SUM(CASE WHEN refacturare_facturata = 1 THEN COALESCE(refacturare_suma,0) ELSE 0 END)
            AS total_refacturare_facturata,
        SUM(CASE WHEN refacturare_facturata = 1 THEN 0 ELSE COALESCE(refacturare_suma,0) END)
            AS total_refacturare_pending
    FROM curse_cheltuieli
    GROUP BY cursa_id
) exp ON exp.cursa_id = c.id

-- expresiile derivate
cheltuieli = COALESCE(exp.total_cheltuieli, 0)
facturare  = COALESCE(c.total_facturare, 0) + COALESCE(exp.total_refacturare_facturata, 0)
profit     = facturare - cheltuieli
```

### Singurul consumator al tabelei `cheltuieli`

| Sursă | Interogare | Destinație |
|---|---|---|
| `cheltuieli` (categorie = `administrativa`) | `SUM(COALESCE(valoare_neta, valoare))` pe lună | Cost operațional/km → componenta „Management / Office" |
| `cheltuieli` (categorie = `operationala`) | **nicio interogare** | **nimic — datele nu sunt citite nicăieri** |

### Risc de dublare la redesign

Aceeași motorină poate fi introdusă azi în **ambele** sisteme: ca tip operațional în `cheltuieli` (alocat pe vehicul) și ca linie `motorina` în `curse_cheltuieli` (pe cursă). Nimic în cod nu împiedică asta și nimic nu o detectează. Orice unificare viitoare trebuie să decidă întâi **care sistem este sursa de adevăr** pentru cheltuielile operaționale.

---

## I. Ce nu se poate atribui azi

| Tip de cheltuială | Asociere actuală | Asociere lipsă | Problema |
|---|---|---|---|
| Administrative alocate „companie" (chirie, contabilitate, utilități) | firmă (`tip_alocare = companie`) | vehicul, cursă, client | Nu pot fi repartizate pe km sau pe vehicul fără o cheie de repartizare, care nu există în schemă |
| Operaționale alocate pe vehicul (reparații, anvelope, spălătorie) | vehicul + sumă | cursă, client | Datele sunt corecte și complete, dar **nicio pagină nu le citește** — sunt orfane funcțional |
| Operaționale alocate pe șofer (diurnă, amenzi) | șofer + sumă | vehicul, cursă | Nu se pot lega de vehiculul condus în perioada respectivă; alocarea e exclusivă |
| Cheltuieli cu beneficiar setat | un client (fără sumă) | sumă per client | Un document = un client. O factură care servește doi clienți nu poate fi împărțită |
| Factură cu mai multe articole | un singur tip de cheltuială | linii de factură | Trebuie introdusă ca mai multe documente separate; legătura între ele se pierde |
| Cheltuieli în valută | monedă stocată | curs valutar | Un document în EUR se adună aritmetic peste cele în RON, fără conversie |
| Șofer responsabil pe cheltuială de vehicul | informativ | cotă din sumă | Apare în filtre, dar nu contribuie la niciun total pe șofer |
| **Orice cheltuială din modul** | vehicul / șofer / companie | `cursa_id` | Nu poate intra în profitul pe cursă, deci nici în KPI-urile Dashboard Analitic |

---

## J. Traseul unei cheltuieli, din formular în bază

Exemplu real din baza locală — cheltuiala `id = 72`:

```
Formular Cheltuieli  (views/cheltuieli/index.php, POST)
         |
         |  categorie=administrativa, tip_id=19 (Consumabile birou),
         |  tip_document=bon_fiscal, data_cheltuiala=2026-08-27,
         |  furnizor=STRESU, cui=RO13231, descriere=Cola,
         |  valoare=231.00, valoare_neta=210.00, tva=21.00, moneda=RON,
         |  modalitate_plata=cash, numar_document=3231
         |  (fără bifă vehicul, fără bifă șofer)
         v
ExpenseController::storeAction()
         |   requireAction('create') + requirePost() + CSRF
         |   collectAndValidateInput()  -> validează categorie, tip activ, TVA, furnizor
         |   collectAllocations()       -> nicio dimensiune bifată => 1 rând „companie"
         |   reconcileTotals()          -> SUM(alocări) == valoare (±0,01)
         v
ExpenseModel::createExpense()   — o singură tranzacție
         |
         +--> cheltuieli               (id=72, sursa='manual', alocare_tip='companie',
         |                              distribuire='egal', added_by=1)
         +--> cheltuieli_alocari       (id=151, cheltuiala_id=72, tip_alocare='companie',
         |                              vehicul_id=NULL, sofer_id=NULL,
         |                              eticheta='Companie', suma=231.00)
         +--> cheltuieli_documente     (doar dacă s-a încărcat fișier — aici, nu)
         v
   Regăsire ulterioară
         |
         +--> ExpenseModel::getPaginatedExpenses()  -> lista paginii (JOIN tip, beneficiar,
         |                                             șofer responsabil, utilizator, nr. documente)
         +--> ExpenseModel::getAllocationsForRows() -> alocările, într-o a doua interogare
         +--> ExpenseModel::getSummary()            -> KPI-urile de sus
         +--> OperationalCostModel::getManagementMonthlyCost()
                                                    -> intră în cost/km cu 210.00 (valoarea netă)
```

---

## K. Diagrama fluxului actual

```
Formular Cheltuieli
         |
         v
ExpenseController::storeAction()
         |
         v
ExpenseModel::createExpense()   (tranzacție)
         |
         +--> cheltuieli
         |      +-- categorie            administrativa | operationala
         |      +-- tip_id           ---> cheltuieli_tipuri.id
         |      +-- data_cheltuiala      <-- data după care se filtrează luna
         |      +-- valoare / valoare_neta / tva / moneda
         |      +-- furnizor, cui, numar_document   (text liber)
         |      +-- beneficiar_id    ---> configurare_beneficiari_transport.id
         |      +-- sofer_responsabil_id ---> soferi.id   (informativ, fără bani)
         |      +-- alocare_tip, distribuire
         |
         +--> cheltuieli_alocari         1..n rânduri, SUM(suma) == valoare
         |      +-- vehicul_id       ---> vehicule.id
         |      +-- sofer_id         ---> soferi.id
         |      +-- suma
         |
         +--> cheltuieli_documente       fișierul atașat
         |
         v
   Rapoarte actuale
         |
         +--> Pagina Cheltuieli        OK  total, split categorii, split alocare, top tipuri
         +--> Export CSV               OK  22 coloane, o linie per document
         +--> Cost operațional / km    OK  doar categorie=administrativa, pe valoarea netă
         +--> Dashboard Analitic       --  NICIO LEGĂTURĂ


---------- sistemul paralel, cel care alimentează Dashboard Analitic ----------

Formular cursă  (Dispecer curse -> tab Cheltuieli)
         |
         v
   curse_cheltuieli
         +-- cursa_id            ---> curse_dispecer.id
         +-- tip_cheltuiala          enum propriu, 8 valori
         +-- suma, data_cheltuiala
         +-- refacturare_suma, refacturare_facturata
         |
         v
   curse_dispecer               de aici vin vehicle_id, driver_id, beneficiar_id
         |
         v
   Dashboard Analitic           OK  Cheltuieli · Profit · Marjă · Cost/km · Profit/km
```

---

## L. Concluziile esențiale

**1. Ce date de cheltuieli avem acum?**
Documente complete la nivel contabil: categorie + tip, dată, furnizor cu CUI, număr document, total / net / TVA, monedă, modalitate și status de plată, scadență, observații și un fișier atașat. Peste ele, un strat de alocare care sparge suma pe vehicule sau pe șoferi. Financiar, structura este solidă; ce lipsește este legătura cu operațiunea care a generat cheltuiala.

**2. De unde vine fiecare tip de cheltuială?**
Totul este introdus **manual** în formularul paginii Cheltuieli — coloana `sursa` prevede manual/SPV/OCR/import, dar controllerul scrie mereu `manual`. Cele 22 de tipuri administrative provin din importul modulelor legacy Birou și Administrative (tabelele vechi sunt acum goale), iar cele 13 operaționale sunt generate prin seed în cod. Cheltuielile de cursă vin din alt formular, în Dispecer curse.

**3. Ce se poate asocia acum cu un vehicul?**
Orice cheltuială, prin `cheltuieli_alocari.vehicul_id`, cu sumă proprie per vehicul. Un document poate fi împărțit între oricâte vehicule, egal sau manual, iar suma alocărilor este forțată să fie exact valoarea documentului. Legătura este corectă și granulară — **dar nicio pagină din aplicație nu agregă azi aceste sume pe vehicul**.

**4. Ce se poate asocia acum cu un șofer?**
Două lucruri diferite: alocarea cu bani (`cheltuieli_alocari.sofer_id`), care **exclude** alocarea pe vehicul pentru același document, și șoferul responsabil (`sofer_responsabil_id`), pur informativ, care apare în filtre dar nu intră în niciun total. Nu se poate spune azi „această reparație este pe vehiculul X, condus de șoferul Y" cu bani pe ambele.

**5. Ce date din Cheltuieli ajung în Dashboard Analitic?**
**Niciunul.** Cheltuielile din Dashboard Analitic provin exclusiv din `curse_cheltuieli`, agregate pe cursă și nete de refacturările deja facturate. Singura punte între modulul Cheltuieli și restul rapoartelor este Cost operațional/km, care citește doar categoria administrativă și doar valoarea netă. Categoria operațională din modul nu este citită de nimic.

---

## M. De decis înainte de redesign

1. **Sursa de adevăr pentru cheltuielile operaționale** — `cheltuieli` (alocat pe vehicul) sau `curse_cheltuieli` (pe cursă)? Azi ambele acceptă aceeași motorină, fără detecție de dublare.
2. **Cu TVA sau net** — alocările se fac pe `valoare` (cu TVA), cost/km citește netul. Aceleași date, două cifre.
3. **Cheia de repartizare pentru administrative** — cheltuielile „companie" nu au și nu pot avea vehicul; repartizarea lor pe km cere o regulă care nu există în schemă.
4. **Legătura lipsă cu cursa** — fără `cursa_id` (sau o punte vehicul + interval de date), nimic din modul nu poate intra în profitul pe cursă.
5. **Unificarea nomenclatoarelor** — `cheltuieli_tipuri` (35) și `categorii_cheltuieli_curse` (7) + enum-ul `tip_cheltuiala` (8) nu au nicio corespondență definită.
