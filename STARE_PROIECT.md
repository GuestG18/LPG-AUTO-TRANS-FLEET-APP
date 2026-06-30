# STARE_PROIECT

## Scop
Acest fisier este jurnalul tehnic al proiectului. El este separat de `README.md` si se foloseste pentru a urmari:

- ce este implementat
- ce s-a modificat la fiecare iteratie
- ce a fost revizuit
- ce probleme sunt cunoscute
- care sunt urmatorii pasi recomandati

## Regula de lucru
La fiecare cerere noua care schimba aplicatia, acest fisier trebuie actualizat.

## Ultimul update

- Data: `2026-06-22`
- Tip interventie: `Dispecer curse` - filtrare stricta `Nr. Inmatriculare` dupa beneficiar + tip transport configurat
- Status general: `functional local`

## Stare curenta a aplicatiei

- Aplicatia ruleaza local cu `php -S 127.0.0.1:8000 -t htdocs`
- Structura este pregatita pentru deploy pe VPS
- Entry point public: `htdocs/index.php`
- Deploy target curent: VPS propriu, pe un subdomeniu dedicat
- Domeniul principal trebuie sa ramana separat de aplicatie
- Baza de date folosita: MySQL / MariaDB
- Arhitectura: PHP simplu, MVC-like, fara framework greu
- Autentificarea este bazata pe sesiuni
- Parolele folosesc `password_hash()` si `password_verify()`
- Formurile folosesc protectie CSRF
- Interogarile DB folosesc PDO + prepared statements

## Module existente

- Autentificare: login, logout, protectie sesiune
- Dashboard: KPI cards, activitate recenta, documente apropiate de expirare
- Dashboard Analitic: pagina separata de test cu layout complet KPI + grafice mock + tabel analiza vehicule
- Dispecer curse: CRUD operational pentru curse + cheltuieli + documente justificative + configurare transport
- Vehicule: CRUD complet + status calculat automat din documente + serie sasiu + poza vehicul + tip vehicul (inclusiv CAMION) + Km bord + Km revizie + Garaj + cuplaje tractor-semiremorca
- Soferi: CRUD complet + documente asociate vizibile in pagina de detalii + status calculat automat din documente
- Alimentari: CRUD complet
- Mentenanta: CRUD complet + furnizor manopera + furnizor piesa + upload/preview factura
- Documente: CRUD complet + upload fisier + audit log
- Utilizatori: CRUD complet, restrictionat pentru `admin`
- Profil utilizator: pagina de profil disponibila
- Export CSV: disponibil in modulele de listare
- Notificari: dezactivat complet (urmeaza redesign de la zero)

## Ce a fost facut pana acum

### 2026-06-22

- `Dispecer curse -> Adauga/Editare cursa`: dropdown-ul `Nr. Inmatriculare` foloseste strict vehiculele configurate pentru beneficiarul si tipul de transport selectat; au fost eliminate fallback-urile intre tipuri.
- Mapare aplicata:
  - `Primar km` / `Primar tone` -> vehiculele rutelor Primar active
  - `Distributie` -> vehiculele rutelor Distributie active
  - `Primar+Distributie` -> vehiculele rutelor Primar+Distributie active
  - `Compresor` -> alocarea dedicata `Vehicule Compresor`
- `Configurare transport -> Setari primare`: adaugata selectie multipla obligatorie `Vehicule`, persistata in `configurare_rute_primar.vehicle_ids`.
- Validarea backend respinge vehiculele care nu apartin configurarii beneficiar + tip transport, inclusiv daca sunt trimise manual.
- Schema si migrarile au fost sincronizate in `database/database.sql`, `database/update_dispecer_curse_module.sql` si `database/update_dispecer_primar_routes.sql`; migrarea punctuala a fost rulata pe baza locala.
- `Dispecer curse -> Adauga cursa`: `Data incarcare`, `Data inceput` si `Data sfarsit` sunt afisate si introduse fortat in format `dd/mm/yyyy`, cu calendar nativ pastrat si conversie backend sigura la `yyyy-mm-dd` pentru persistenta.
- `Configurare transport -> Setari primare`: adaugat switch-ul `Km agreati - Introducere manuala in cursa` pe fiecare ruta Primar. Cand este activ, `Km tarifare` se goleste si se dezactiveaza, iar ruta salveaza `km_agreati_manual = 1`.
- `Dispecer curse -> Adauga/Editare cursa`: pentru o ruta Primar cu switch-ul activ, campul `Km agreati` devine editabil si obligatoriu; pentru o ruta cu switch-ul oprit ramane read-only si se completeaza automat din `Km tarifare`.

### 1. Structura pentru deploy

- Proiectul a fost reorganizat cu `htdocs/` ca web root public
- Structura este compatibila cu deploy pe VPS, inclusiv pe subdomeniu separat
- Schimbarea de la InfinityFree la VPS permite integrare mai buna pentru cron, SMTP si apeluri API externe
- Nu exista dependinte pe Node.js, Laravel, Docker sau arhitectura complexa inutila pentru acest MVP

### 2. Baza aplicatiei

- Configurare PHP + MySQL simpla
- Sistem de autentificare cu roluri `admin` si `utilizator`
- CRUD pentru toate modulele principale din cerinta
- Tabele cu cautare, filtre, paginare si export CSV
- Mesaje flash si confirmare la stergere

### 3. Dashboard

- KPI cards cu design imbunatatit
- KPI pentru total vehicule
- KPI pentru vehicule active
- KPI pentru cost combustibil
- KPI pentru cost mentenanta
- KPI pentru documente care expira in 30 zile
- Sectiune pentru activitate recenta
- Sectiune pentru documente cu expirare apropiata

### 4. Imbunatatiri recente pe dashboard

- S-a adaugat o bara de filtre deasupra KPI-urilor
- Filtru perioada: `luna_curenta`, `ultimele_30_zile`, `an_curent`
- Filtru vehicul: selectie din lista vehiculelor
- KPI-urile se recalculeaza real in backend pe baza filtrelor
- Activitatea recenta se filtreaza pe aceeasi logica
- Documentele din dashboard sunt aliniate cu KPI-ul de expirare in 30 de zile

### 5. Curatare si corectii

- Au fost corectate mai multe texte din interfata principala
- Au fost reduse problemele de encoding in fisierele din `htdocs`
- Logica pentru mentenanta din dashboard a fost clarificata
- S-a evitat confuzia dintre "luna curenta" si "ultimele 30 de zile"

## Modificari recente

### 2026-05-07

- `Dispecer curse` - interval de date in formularul `Adauga cursa` / `Editare cursa`:
  - inlocuit campul unic `Data` cu doua campuri obligatorii: `Data inceput` si `Data sfarsit`
  - validare backend noua: `Data sfarsit` nu poate fi mai mica decat `Data inceput`
  - persistenta actualizata in `curse_dispecer` cu noile coloane `data_inceput` si `data_sfarsit` (in paralel cu `data_cursa`, setat egal cu `data_inceput` pentru compatibilitate)
  - lista `Lista Curse` afiseaza acum separat `Data inceput` si `Data sfarsit`
  - filtrele de perioada folosesc intervalul noilor campuri (`data_inceput` / `data_sfarsit`)
- `Dispecer curse` - `Zona distributie` disponibila si pentru `Primar km` + `Primar tone`:
  - campul ramane vizibil/selectabil in formular (nu doar la `Distributie` / `Primar+Distributie`)
  - valoarea selectata este pastrata la salvare si pentru tipurile primare
- SQL/migrari:
  - schema de baza actualizata: `database/database.sql` (`data_inceput`, `data_sfarsit`, indexuri dedicate)
  - migrare modul generala actualizata: `database/update_dispecer_curse_module.sql`
  - migrare noua dedicata bazei existente: `database/update_dispecer_curse_date_interval.sql`

- `Dispecer curse` - split tip transport `Primar`:
  - eticheta veche `Primar` a devenit `Primar km` (valoare interna ramane `primar`)
  - adaugat tip nou `Primar tone` (valoare interna `primar_tona`)
- Reguli noi de calcul in formularul `Adauga cursa` / `Editare cursa`:
  - `Primar km` factureaza doar `Km cursa`
  - `Primar tone` factureaza doar `Cantitate incarcata`
  - `Km cursa` ramane editabil si la `Primar tone`, dar nu este inclus in calculul totalului
  - logica este aplicata identic in preview frontend (`dispecer-curse.js`) si la salvare backend (`validateRaceInput`)
- SQL/migrari:
  - enum `curse_dispecer.tip_transport` extins cu `primar_tona` in scripturile de schema/migrare
  - adaugata migrare noua `database/update_dispecer_transport_type_primar_tona.sql`

- `Dispecer curse` - adaugat tip nou in dropdown-ul `Tip transport`: `Primar+Distributie` (valoare interna: `primar_distributie`) in formularele de adaugare/editare + filtre/listare.
- `Dispecer curse` - schimbata formula de calcul:
  - pentru `Distributie`: `Km cursa` nu mai factureaza (se calculeaza doar componenta pe tona).
  - pentru `Primar+Distributie`: se aplica formula completa `Cantitate × Tarif tona + Km × Cost extra/km`.
- Backend + frontend sincronizate pentru acelasi comportament:
  - `validateRaceInput` foloseste logica noua la salvare (create/update), inclusiv validare de rute.
  - `htdocs/assets/js/dispecer-curse.js` foloseste aceeasi logica la preview (`Total Facturare estimare`).
- SQL/migrari:
  - actualizat enum-ul `curse_dispecer.tip_transport` in `database/database.sql` si `database/update_dispecer_curse_module.sql`.
  - adaugata migrare dedicata: `database/update_dispecer_transport_type_primar_distributie.sql`.
  - migrarea a fost rulata local: enum curent `('primar','distributie','primar_distributie','compresor')`.

### 2026-05-06

- `Configurare transport` - panelul `Distributie` a fost realiniat la fluxul operational de tip ruta:
  - formularul principal foloseste acum exact configurarea pe pereche `Loc incarcare + Zona descarcare + Pret tona + Pret km + Vehicule`
  - adaugare configuratie printr-un singur buton (`Adauga configuratie`) si lista dedicata `Configuratii existente`
  - adaugat endpoint de stergere pentru ruta (`config_delete_ruta`) cu actiune directa din tabel
  - adaugat buton `Salveaza setarile` in acelasi panel pentru confirmare UX
  - hotfix UX: in acelasi panel au fost reintroduse formularele de adaugare pentru `Loc incarcare` si `Zona descarcare`, astfel incat operatorul poate crea nomenclatorul direct in pagina inainte de a adauga rute
- `Distributie` - suport vehicule dedicate pe regula de ruta:
  - tabela `configurare_rute_distributie` include acum coloana `vehicle_ids` (CSV) pentru a limita regula la vehiculele selectate
  - calculul de pret in `Dispecer curse` cauta regula de ruta inclusiv pe vehiculul curent (nu doar pe perechea Loc/Zona)
  - preview-ul frontend foloseste aceeasi regula: daca vehiculul selectat nu este in lista rutei, regula de ruta nu se aplica
- SQL/migrari:
  - actualizate `database/database.sql` si `database/update_dispecer_curse_module.sql` pentru `vehicle_ids` in `configurare_rute_distributie` + compatibilitate pe baze existente (ALTER conditionat)

- `Configurare transport` - corectie flux distributie pe beneficiar:
  - la salvarea din formularul unificat (`config_store_distributie`), daca `Loc incarcare` sau `Zona distributie` exista deja pentru acelasi beneficiar (acelasi nume), sistemul refoloseste inregistrarea existenta in loc sa incerce creare duplicat
  - in acest scenariu, tarifele pot ramane necompletate in formular iar sistemul pastreaza valorile existente din configurarea deja salvata
  - pentru `Vehicule implicite`, cand se refoloseste `Loc`/`Zona` existente, selectia noua se adauga peste asignarile deja existente (nu se pierd alocarile vechi), astfel incat poti extinde regulile pe alte masini fara a recrea locatia/zona
  - adaugate metode noi in model pentru lookup dupa nume + beneficiar si pentru citirea asignarilor vehicul deja existente (`getLoadLocationByNameForBeneficiary`, `getDistributionZoneByNameForBeneficiary`, `getVehicleIdsForLoadLocation`, `getVehicleIdsForDistributionZone`)
- `Dispecer curse` - regula noua de tarifare pentru `Distributie`:
  - calculul NU mai aduna automat `Tarif loc + Tarif zona`
  - daca `Loc incarcare` si `Zona distributie` au acelasi nume, se aplica prioritar tariful din `Loc`
  - daca `Loc incarcare` si `Zona distributie` sunt diferite, se aplica prioritar tariful din `Zona`
  - fallback-ul ramane catre tariful de distributie de pe beneficiar daca lipsesc tarifele configurate pe loc/zona
  - regula a fost sincronizata identic in backend (`validateRaceInput`) si in preview-ul din frontend (`dispecer-curse.js`)
- `Configurare transport` / `Distributie` - suport tarif pe pereche `Loc -> Zona`:
  - a fost introdusa persistenta pentru regula de ruta in tabela noua `configurare_rute_distributie`
  - la salvarea configurarii distributiei (formular unificat), daca sunt completate simultan `Loc` si `Zona`, sistemul salveaza/actualizeaza automat regula de pereche (`beneficiar + loc + zona`) cu upsert
  - in formular au fost adaugate campuri dedicate: `Tarif ruta (Loc -> Zona)` si `Cost extra km ruta (optional)`
  - in calculul cursei (`Dispecer curse` add/edit), regula de ruta are prioritate fata de tariful generic din Loc/Zona:
    - prioritate 1: `configurare_rute_distributie`
    - prioritate 2: reguli Loc/Zona
    - prioritate 3: fallback beneficiar
- `Configurare transport` - mesaj succes simplificat:
  - eliminat mesajul de tip „... exista deja si a fost reutilizata fara duplicare”
  - dupa salvare, feedback-ul este unificat: `Configurarea de distributie a fost salvata.`
- `Dispecer curse` - actualizare lista `Tip marfa`:
  - in formularul de adaugare/editare cursa, lista de selectie pentru `Tip marfa` a fost restransa la 3 optiuni: `Butan`, `Propan`, `Autogaz`
  - validarea backend accepta acum exclusiv aceste 3 chei (`butan`, `propan`, `autogaz`) prin actualizarea constantei `GOODS_TYPES` din controller
- `Documente` - imbunatatiri in tabelul de listare:
  - adaugata coloana noua `Zile expirare`, calculata din diferenta dintre `data_expirare` si data curenta (`DATEDIFF(data_expirare, CURDATE())`)
  - valoarea este afisata per document si exportata in CSV in acelasi format numeric
  - primele 2 coloane (`Vehicul`, `Tip document`) sunt acum sticky la scroll orizontal pentru context permanent
  - stilul sticky este aplicat doar pe modulul `Documente`, fara impact pe celelalte tabele
  - rafinat aspectul vizual pentru un look mai profesionist: row spacing mai aerisit, aliniere coerenta intre header si celule, contrast/hover mai curate, aliniere centrata pentru coloanele tehnice (`Fisier`, `Data expirare`, `Zile expirare`)
  - ajustata alinierea pentru coloanele sticky (`Vehicul`, `Tip document`) astfel incat textul sa fie centrat la fel ca restul coloanelor centrate din tabel
- `Vehicule` - imbunatatiri UI pentru tabelul principal:
  - primele 2 coloane (`Foto`, `Nr. inmatriculare`) sunt sticky la scroll orizontal, pentru context permanent in lista lunga
  - tabelul foloseste layout de latime naturala + scroll orizontal controlat, fara comprimare agresiva de coloane
  - header-ele raman pe un singur rand (`white-space: nowrap`) pentru aspect consistent si lizibil
  - textul din celule a fost uniformizat (font-size, spacing, contrast, zebra + hover) pentru un aspect mai curat
  - aplicarea stilurilor este limitata strict la modulul `Vehicule`, fara impact pe celelalte liste (`list.php` + clase dedicate in `style.css`)
- `Dispecer curse` - imbunatatiri in `Lista Curse`:
  - coloana `Nr. Inmatriculare` este acum sticky la scroll orizontal, astfel incat numarul de inmatriculare ramane vizibil cand operatorul merge spre coloanele din dreapta (`Total facturare`, `Cheltuieli`, `Status`)
  - adaugata selectie multipla direct in coloana `Nr. Inmatriculare` (checkbox pe rand + `select all` in header)
  - adaugat buton `Sterge selectate` in cardul listei, cu confirmare si submit bulk
  - backend nou `delete_bulk` in `DispecerCurseController` (validare CSRF + sanitizare ID-uri + stergere iterativa + raportare pentru curse sterse/neexistente/esuate)
  - formularul de stergere individuala include acum si `return_url` pentru continuitatea contextului din lista curenta
- `Configurare transport` - `Reguli beneficiar configurate`:
  - adaugata selectie multipla pe randuri (checkbox per regula + `select all` in header)
  - adaugat buton nou `Sterge selectate` cu confirmare, pentru stergere bulk dintr-o singura actiune
  - backend nou `config_delete_beneficiari` in `DispecerCurseController` cu validare CSRF si sanitizare ID-uri
  - feedback UX la final de operatie: mesaje separate pentru reguli sterse, reguli blocate (ex: folosite in curse) si reguli inexistente
- `Documente` - imbunatatire flux redirect la `Adauga document` din fisa vehiculului:
  - la submit reusit din `index.php?page=documente&action=create&vehicle_id={id}`, redirect-ul revine acum in acelasi formular `create` cu acelasi `vehicle_id` (nu in lista generala `index.php?page=documente`)
  - la erori de validare sau erori de persistenta, contextul `vehicle_id` este pastrat in redirect, pentru continuitatea fluxului de operare
  - view-ul `module/form.php` trimite un marker de context (`keep_vehicle_context`) folosit strict pentru acest flux, fara impact pe crearile generale din modulul `Documente`

### 2026-05-05

- `Dispecer curse` - auto-completare `Loc incarcare` pentru `Tip transport = Distributie`:
  - la selectarea unui vehicul, sistemul cauta in `Loc incarcare` o valoare care coincide cu `Garaj` din modulul `Vehicule`
  - potrivirea se aplica cu prioritate pe beneficiarul selectat (`Garaj == Loc incarcare`), iar doar daca nu exista potrivire se folosesc regulile vechi (`Vehicul implicit`) sau selectie manuala
  - potrivirea este case-insensitive si ignora diferentele de diacritice in comparatie (`Garaj` vs `Loc incarcare`)
- Backend/view sincronizate pentru regula noua:
  - `DispecerCurseModel` expune maparea `vehicle_id -> garaj` pentru formularele `Adauga` / `Editare`
  - dataset-ul JS din view-uri include acum `data-vehicle-garages` pentru auto-select in frontend
- `Dispecer curse` - status facturare pe cursa:
  - adaugat camp `status_facturare` in `curse_dispecer` (enum: `in_curs_facturare`, `facturat`, `nefacturat`, default `in_curs_facturare`)
  - in `Adauga cursa` si `Editare cursa` exista acum dropdown de status
  - in `Lista Curse` a fost adaugata coloana `Status` la finalul randului, cu dropdown per cursa si salvare rapida la schimbare
  - randul cursei este colorat automat dupa status in lista:
    - `in curs de facturare` -> galben
    - `nefacturat` -> rosu
    - `facturat` -> verde
  - fix UX: colorarea se aplica pe toate celulele randului (`tr > td`) pentru compatibilitate cu stilurile Bootstrap de tabel, astfel highlight-ul este vizibil pe tot randul
  - tweak UI: nuantele au fost intarite (contrast mai mare) si s-a adaugat accent vertical pe primul `td` pentru identificare rapida fara hover
  - adaugata actiune noua `update_status` in controller + metoda model dedicata pentru update punctual status
  - la salvarea/modificarea unei cheltuieli, statusul cursei revine automat la `in_curs_facturare`
- `Dispecer curse` -> sincronizare Km cu `Vehicule` (tractor + semiremorca cuplata):
  - intarita logica de mapare pentru ansamblu in `DispecerCurseModel::getKmSyncVehicleIds`
  - sincronizarea Km include cuplajele active, dar si cuplajele deschise (`data_end IS NULL`) pentru compatibilitate cu date existente
  - selectie determinista a cuplajului curent (latest active/open pair, `LIMIT 1` pe directia tractor si pe directia semiremorca) pentru a evita rezultate ambigue cand exista istoric de cuplaje
  - la creare/editare/stergere cursa, delta de `km_cursa` se aplica pe ambele vehicule din ansamblu (tractor + semiremorca)
- `Vehicule` -> cuplaj tractor-semiremorca (dropdown disponibilitate):
  - lista `Selecteaza semiremorca` pentru un `Cap tractor` afiseaza acum doar semiremorci `active` si `necuplate`
  - elementele inactivate sau deja cuplate nu mai apar in dropdown-ul de alocare
  - validare backend intarita in `cupleaza`: semiremorca inactiva sau deja cuplata la alt tractor este respinsa cu mesaj clar
  - daca nu exista semiremorci disponibile, dropdown-ul este dezactivat si se afiseaza mesaj explicit in optiune
  - scripturile SQL au fost sincronizate: `database/database.sql`, `reset_database.sql`, `database/update_dispecer_curse_module.sql`, `database/seed_dispecer_curse_demo_20.sql`

### 2026-05-04

- Cleanup UX/logic `Tip marfa`:
  - eliminat complet `Tip marfa` din pagina `Configurare transport` (formular regula beneficiar + tabel reguli)
  - regula beneficiar nu mai valideaza / nu mai cere `tip_marfa` la salvare
- `Dispecer curse` actualizat pentru selectie multipla `Tip marfa`:
  - in `Adauga cursa` si `Editare cursa`, `Tip marfa` este acum dropdown cu checkbox-uri (`tip_marfa[]`)
  - validare backend actualizata: trebuie selectat cel putin un tip de marfa valid
  - persistenta curse: tipurile selectate se salveaza ca lista CSV in `curse_dispecer.tip_marfa`
  - `Lista Curse` afiseaza toate tipurile selectate (etichete concatenate)
- Compatibilitate date:
  - parse pentru inregistrari existente (valoare simpla) + noi (multi-select CSV)
  - script SQL actualizat: `database/update_dispecer_curse_module.sql` (coloana `curse_dispecer.tip_marfa` redimensionata la `VARCHAR(255)`)
  - schema seed actualizata: `database/database.sql` (`curse_dispecer.tip_marfa` = `VARCHAR(255)`)

- Refactor major `Configurare transport` (beneficiar-centric):
  - formular principal clar: `Beneficiar`, `Tip marfa`, `Status`, `Tipuri transport`
  - afisare pe carduri separate per tip transport (`Primar`, `Distributie`, `Compresor`)
  - eliminat layout-ul vechi cu tabel orizontal de tarife pe toate tipurile
- `Distributie` nu mai este tratata ca panel global separat:
  - setarile `Locuri incarcare` + `Zone distributie` sunt mutate in cardul `Distributie` al beneficiarului selectat
  - salvarile pentru loc/zona sunt validate strict pe beneficiar curent si pe suport `Distributie`
  - actiunile editare/stergere loc/zona pastreaza contextul beneficiarului
- Backend + calcul distributie:
  - adaugat suport pentru tarife dedicate distributiei in regula beneficiar (`pret_distributie_tona`, `pret_distributie_km`)
  - calculul de distributie foloseste prioritar `Loc + Zona + Cost extra km`, iar daca acestea lipsesc, foloseste fallback din tarifele dedicate distributiei
- Relatii DB aduse pe beneficiar:
  - `configurare_locuri_incarcare`, `configurare_zone_distributie`, `configurare_locuri_incarcare_vehicule`, `configurare_zone_distributie_vehicule` includ `beneficiar_id`
  - indexari/chei unice ajustate pentru alocari pe beneficiar
  - scripturi SQL sincronizate: `database/update_dispecer_curse_module.sql`, `database/database.sql`, `reset_database.sql`, `database/update_dispecer_vehicle_default_assignments.sql`, `database/seed_dispecer_curse_demo_20.sql`
- Migrare rulata local pe DB curenta:
  - `database/update_dispecer_curse_module.sql`
  - `database/update_dispecer_vehicle_default_assignments.sql`

- Stabilizare finala UX/HTML pe `Configurare transport`:
  - formularul principal `Regula tarifare beneficiar` este separat corect de formularele pentru `Locuri incarcare` si `Zone distributie`
  - eliminata structura cu formulare imbricate (nested forms) care genera submituri imprevizibile
  - `Setari distributie pentru acest beneficiar` ramane in acelasi flux vizual si este afisat doar cand `Distributie` este bifat la tipuri transport
  - scriptul UI de toggling a fost actualizat sa ascunda/afiseze toate sectiunile marcate pe tip transport (inclusiv setarile distributie)
  - endpoint-ul legacy `config_store_distributie` (flux vechi/global) a fost scos din controller, raman active doar endpoint-urile scoped pe beneficiar (`config_store_loc`, `config_store_zona`)
  - UX update: in `Setari distributie`, formularele pentru `Locuri incarcare` si `Zone distributie` sunt acum intr-un singur panel vizual (nu doua carduri separate), cu logica de salvare pastrata identic pe endpoint-urile existente
  - UX update: listele `Locuri incarcare` si `Zone distributie` au fost unite intr-un singur tabel (`Tip`, `Loc`, `Zona`, `Vehicule`, `Tarif`, `Cost extra km`, `Status`, `Actiuni`), cu actiuni separate pe rand in functie de tip (`Loc`/`Zona`)
  - UX update: in panelul unic de distributie exista acum un singur formular si un singur buton `Salveaza configurarea distributiei`; salvarea poate procesa intr-un singur submit atat datele de `Loc`, cat si de `Zona` (sau doar una dintre sectiuni)

- `Tip marfa` introdus in fluxul operational:
  - `Dispecer curse -> Adauga Cursa` include acum camp obligatoriu `Tip marfa` (dropdown), disponibil pentru toate tipurile de transport
  - `Dispecer curse -> Editare cursa` include acelasi camp `Tip marfa`
  - la selectarea beneficiarului, `Tip marfa` se completeaza automat din configurarea beneficiarului (daca exista), dar poate fi ajustat de operator
- `Configurare transport -> Adauga/Editeaza regula beneficiar`:
  - adaugat camp obligatoriu `Tip marfa` sub `Beneficiar`, cu selectie din dropdown
  - lista `Reguli beneficiar configurate` afiseaza acum si coloana `Tip marfa`
  - panoul `Detalii regula` include `Tip marfa`
- Persistenta backend/DB:
  - `configurare_beneficiari_transport.tip_marfa` (nou)
  - `curse_dispecer.tip_marfa` (nou)
  - model/controller actualizate pentru validare, create/update si afisare
  - scripturile SQL sincronizate: `database/update_dispecer_curse_module.sql`, `database/database.sql`, `reset_database.sql`, `database/seed_dispecer_curse_demo_20.sql`
- Ajustare UI lista curse:
  - in `Dispecer curse -> Lista Curse` a fost adaugata coloana `Tip marfa` (header + valori pe rand), cu fallback `-` pentru inregistrari fara valoare istorica.

- `Dispecer curse` (formular `Adauga Cursa` + `Editare cursa`):
  - cand `Tip transport = Distributie` si se selecteaza un vehicul, `Zona distributie` se completeaza automat din configurarea implicita vehicul-zona
  - comportamentul urmeaza aceeasi regula deja existenta pentru `Loc incarcare` (mapare implicita pe vehicul)
  - maparea este aplicata doar daca zona configurata exista in lista activa de zone din formular
- Implementare tehnica:
  - `DispecerCurseController` trimite acum si `vehicleDefaultDistributionZoneMap` catre view-urile `index` si `edit`
  - `dispecer-curse.js` citeste noul dataset `data-vehicle-default-distribution-zones` si aplica auto-select la schimbare vehicul/tip transport
- Hotfix aceeasi zi:
  - corectata functia JS de verificare optiuni zona (`hasZoneOption`) care fusese introdusa in bloc gresit; auto-select zona pentru distributie functioneaza din nou corect la schimbare vehicul/tip transport.

### 2026-04-30

- Review tehnic de reluare proiect pe baza starii curente din cod si jurnal
- Aliniat sectiunea `Ultimul update` la ultima interventie reala (`2026-04-28`)
- Confirmat status de lucru pentru continuare: baza functionala locala, urmatorul pas este selectarea urmatorului update de produs
- Extindere `Configurare transport`:
  - panel `Loc incarcare`: dropdown-ul `Vehicul implicit` afiseaza doar vehicule active
  - panel `Zone distributie si tarif`: adaugat dropdown nou `Vehicul implicit`
  - dropdown-ul de la `Zone distributie` afiseaza doar vehicule active
- Backend sincronizat pentru alocari implicite:
  - validarea pe `config_store_loc` si `config_store_zona` accepta doar vehicule active
  - persistenta pentru `Zone distributie` foloseste tabela noua `configurare_zone_distributie_vehicule`
  - in listarea zonelor a fost adaugata coloana `Vehicul implicit`
- SQL sincronizat:
  - adaugate tabelele `configurare_locuri_incarcare_vehicule` si `configurare_zone_distributie_vehicule` in `database/database.sql`, `reset_database.sql`, `database/update_dispecer_curse_module.sql`
  - script nou de migrare: `database/update_dispecer_vehicle_default_assignments.sql`
- Extindere `Dispecer curse -> Adauga Cursa`:
  - dropdown-ul `Nr. Inmatriculare` afiseaza doar vehicule active
  - pentru listarea cursei au fost separate sursele de vehicule:
    - formular `Adauga Cursa` foloseste lista activa
    - panelul de `Filtre` pastreaza lista completa
  - validarea backend la creare cursa (`store`) respinge orice `vehicle_id` inactiv trimis manual
- Extindere `Configurare transport` pentru alocari multiple:
  - `Loc incarcare -> Vehicule implicite` permite selectie multipla
  - `Zone distributie si tarif -> Vehicule implicite` permite selectie multipla
  - UI rafinat: selectia multipla foloseste din nou dropdown, cu checkbox-uri in lista (nu box multi-select nativ)
  - la salvare, alocarile sunt sincronizate pe regula (setul selectat devine setul activ pentru acel loc / acea zona)
  - validarea backend accepta doar vehicule active si respinge selectii invalide

### 2026-04-28

- Update `Configurare transport` pentru panelurile:
  - `Loc incarcare`
  - `Zone distributie si tarif`
- A fost adaugat flux complet de editare (nu doar stergere):
  - buton nou `Editeaza` in coloana `Actiuni` pentru fiecare rand
  - click pe `Editeaza` incarca valorile in formularul de sus
  - formularul comuta in mod `edit` (buton `Actualizeaza ...`)
  - exista buton `Renunta` pentru iesire din edit mode
- Backend sincronizat:
  - `config_store_loc` face `update` cand exista `id`, altfel `create`
  - `config_store_zona` face `update` cand exista `id`, altfel `create`
  - validari dedicate pentru id inexistent la update
  - la eroare, formularul revine in contextul de edit (`loc_edit_id` / `zona_edit_id`)

### 2026-04-27

- Extindere `Configurare transport -> Beneficiar transport` pentru tipul `Compresor`:
  - campuri noi in regula beneficiar:
    - `Pret tona`
    - `Pret ora aspirare`
    - `Pret km dislocare`
    - `Pret tona livrata`
  - campurile noi sunt disponibile in:
    - formularul de adaugare/editare
    - tabelul de reguli configurate
    - pagina de detalii regula
  - validare backend actualizata:
    - valorile pentru cele 3 tarife noi (`ora aspirare`, `km dislocare`, `tona livrata`) sunt obligatorii doar cand este selectat tipul `Compresor`
    - pentru reguli fara `Compresor`, aceste valori sunt setate la `0` fara blocarea salvarii
- Baza de date sincronizata pentru noile tarife Compresor:
  - `database/update_dispecer_beneficiar_compresor.sql`
  - `database/update_dispecer_curse_module.sql`
  - `database/database.sql`
  - `reset_database.sql`

- Actualizare `Configurare transport -> Adauga / Editeaza regula`:
  - campul `Tip transport` nu mai foloseste checkbox-uri
  - a fost schimbat in dropdown multi-select (`tip_transporturi[]`)
  - se pot selecta simultan mai multe tipuri pentru acelasi beneficiar
- Tip nou adaugat pentru beneficiari:
  - `Compresor`
  - afisare completa in tabelul de reguli si in panoul `Detalii regula`
- Backend sincronizat pentru noul tip:
  - `DispecerCurseController` valideaza si salveaza acum trei tipuri (`primar`, `distributie`, `compresor`)
  - `DispecerCurseModel` foloseste coloana `suporta_compresor` la create/update/select
- Scripturi SQL sincronizate:
  - `database/database.sql` si `reset_database.sql` includ coloana `suporta_compresor`
  - `database/update_dispecer_curse_module.sql` include compatibilitate pentru adaugarea coloanei pe baze existente
  - script nou dedicat: `database/update_dispecer_beneficiar_compresor.sql` (migrare punctuala)
- Hotfix stabilitate dupa update:
  - `Configurare transport` nu mai cade cu eroare 500 daca schema nu este sincronizata complet; afiseaza mesaj clar de migrare
  - mesajul de eroare pentru schema `Dispecer curse` include acum si scriptul nou `database/update_dispecer_beneficiar_compresor.sql`
  - migrarea a fost rulata local pentru: `update_dispecer_curse_module.sql`, `update_dispecer_locuri_tarif.sql`, `update_dispecer_beneficiar_compresor.sql`

### 2026-04-23

- Rollback logica facturare custom in `Dispecer curse`:
  - checkbox-ul de override facturare a fost eliminat din `Adauga Cursa` si `Editare cursa`
  - logica de calcul a revenit la varianta standard:
    - `Primar` => `Km cursa * Pret tarifare`
    - `Distributie` => `Cantitate incarcata * Pret tarifare`
  - validarea backend si preview-ul din frontend au fost readuse la comportamentul initial

- Extindere `Configurare transport`:
  - sectiune noua `Beneficiari transport` (adaugare + listare + stergere + status activ/inactiv)
  - date persistate in tabela noua `configurare_beneficiari_transport`
- Extindere formular `Dispecer curse -> Adauga Cursa`:
  - camp nou obligatoriu `Beneficiar transport` (dropdown din configurari active)
  - validare backend pentru beneficiar valid
- Extindere `Dispecer curse -> Editare cursa`:
  - camp `Beneficiar transport` disponibil si la editare
- Extindere filtre + lista curse:
  - filtru nou dupa beneficiar
  - coloana noua `Beneficiar` in tabel
- Baza de date actualizata:
  - `database/update_dispecer_curse_module.sql` include tabela `configurare_beneficiari_transport`
  - `curse_dispecer` include `beneficiar_id` + index + foreign key
  - `database/database.sql` si `reset_database.sql` sincronizate cu noua structura
  - `database/seed_dispecer_curse_demo_20.sql` actualizat sa adauge beneficiari demo si sa insereze curse cu `beneficiar_id`
- Fix post-implementare:
  - migrarea `database/update_dispecer_curse_module.sql` a fost rulata pe baza locala pentru eliminarea erorii de structura la `Dispecer curse`
  - corectie globala pentru text mojibake in UI (ex: `Loc ÃŽncÄƒrcare` -> `Loc Încărcare`) prin normalizare finala la randare in `includes/helpers.php`

### 2026-04-22

- Fix logic km pentru vehicule cuplate in modulul `Dispecer curse`:
  - la creare/actualizare/stergere cursa, delta de `Km cursa` se aplica acum pe ambele vehicule din cuplajul activ (cap tractor + semiremorca), nu doar pe vehiculul selectat in formular
  - se sincronizeaza pentru ambele: `Km bord` (creste/scade) si `Km revizie` (scade/creste cu prag minim 0)
  - alerta de revizie la prag 0 se poate declansa si pentru vehiculul partener din cuplaj

### 2026-04-21

- Fix global pentru afisarea diacriticelor in interfata:
  - a fost adaugata normalizare centralizata in `htdocs/includes/helpers.php` (functia `normalize_romanian_text()`)
  - functia de escapare `e()` aplica acum automat corectii pentru secvente mojibake frecvente (`ÃƒË†Ã¢â€žÂ¢`, `ÃƒË†Ã¢â‚¬Âº`, `Ãƒâ€žÃ†â€™`, `ÃƒÆ’Ã‚Â®`, etc.)
  - scop: eliminarea textelor afisate gresit de tip `Editeaza ÃƒË†Ã¢â€žÂ¢ofer` / `ObservaÃƒË†Ã¢â‚¬Âºii` in paginile CRUD

- Update functional Dispecer curse pentru kilometraj vehicul:
  - campul Km cursa ramane editabil si la tip transport Distributie (nu mai este dezactivat din UI)
  - la creare/actualizare/stergere cursa, sistemul sincronizeaza automat valorile din Vehicule:
    - Km bord creste/scade pe baza kilometrilor cursei
    - Km revizie scade pe baza kilometrilor cursei (si ramane la 0 cand atinge pragul)
  - cand Km revizie ajunge la 0 in urma unei curse, administratorul primeste alerta tip popup in modulul Dispecer curse
  - au fost adaugate tranzactii in model pentru consistenta intre curse_dispecer si vehicule
### 2026-04-20 (Dispecer curse)

- Modul nou `Dispecer curse` adaugat in aplicatie cu ruta dedicata:
  - meniu lateral: `Dispecer curse`
  - acces: `?page=dispecer_curse`
- Au fost adaugate 3 sectiuni pe pagina principala:
  - `Adauga Cursa` (formular operational)
  - `Filtre` (cautare + filtre de lucru)
  - `Lista Curse` (tabel complet cu paginare)
- Logica de business implementata (frontend + backend):
  - `Primar`: `Total facturare = Km cursa * Pret tarifare`
  - `Distributie`: `Total facturare = Cantitate incarcata * Pret tarifare`
  - autotarifare la `Distributie` din zona selectata
- A fost adaugata editarea de cursa cu submodul de cheltuieli:
  - multiple cheltuieli per cursa
  - upload document justificativ per cheltuiala
  - editare/stergere cheltuiala
  - link de vizualizare/descarcare document
- A fost adaugata pagina `Configurare transport` (admin):
  - gestionare `Locuri incarcare`
  - gestionare `Zone distributie` + tarif
- A fost adaugata schema DB pentru modul:
  - `configurare_locuri_incarcare`
  - `configurare_zone_distributie`
  - `curse_dispecer`
  - `curse_cheltuieli`
  - `curse_cheltuieli_documente`
- Baza initiala a fost actualizata in:
  - `database/database.sql`
  - `reset_database.sql`
- Migrare dedicata pentru baze existente:
  - `database/update_dispecer_curse_module.sql`
- Fix de stabilitate pentru lansare modul:
  - eroarea `500` pe `?page=dispecer_curse` a fost diagnosticata ca lipsa tabelelor noi in baza de date
  - dupa rularea migrarii `database/update_dispecer_curse_module.sql`, pagina raspunde `200`
- A fost adaugat seed de volum pentru test operare:
  - `database/seed_dispecer_curse_demo_20.sql` (20 curse + cheltuieli demo)

### 2026-04-20

- Fix critic pe fluxul `Vehicule -> Cuplaj tractor-semiremorca`:
  - salvarea cuplajului esua cu `SQLSTATE[HY093]: Invalid parameter number`
  - cauza a fost reutilizarea aceluiasi placeholder PDO in update-urile din `VehicleCouplingModel`
  - au fost inlocuite placeholderele duplicate cu unele distincte in:
    - `assignTrailerToTractor()`
    - `detachByTractor()`
    - `detachByTrailer()`
  - rezultat: cuplarea si decuplarea se salveaza corect, fara mesajul generic `A aparut o eroare la actualizare.`
- Update UX pentru alocare sofer pe cap tractor:
  - in modulul `Soferi`, campul `Vehicul alocat` afiseaza acum si semiremorca activa pentru capul tractor (format: `TRACTOR + SEMIREMORCA`)
  - in lista si pagina de detalii `Soferi`, coloana `Vehicul alocat` arata tot formatul extins, nu doar numarul tractorului
  - selectia de vehicul pentru sofer exclude explicit vehiculele de tip `semiremorca` (soferul se aloca pe cap tractor/autovehicul)
- Extindere `Vehicule` cu campuri tehnice suplimentare (formular + detalii):
  - `Nr. fabricatie`
  - `Capacitate transport`
  - `Formula axelor`
  - `Capacitate rezervor`
  - `MMA`
  - `Organism notificat`
- Pentru baze existente a fost adaugat scriptul de migrare:
  - `database/update_vehicle_additional_details.sql`
- Schema de baza a fost actualizata si in:
  - `database/database.sql`
  - `reset_database.sql`
- Status operational nou pentru vehicule cuplate:
  - cand exista un cuplaj activ cap tractor + semiremorca, langa badge-ul existent `Status` apare automat un al doilea badge:
    - `ANSAMBLU ACTIV` daca ambele vehicule din cuplaj sunt active
    - `ANSAMBLU INACTIV` daca cel putin unul dintre cele doua vehicule este inactiv (de exemplu documente expirate/lipsa)
  - badge-ul ansamblu apare atat in `Vehicule -> Lista`, cat si in `Vehicule -> Detalii`
  - calculul este sincronizat cu statusul automat pe documente, astfel incat ansamblul se actualizeaza fara interventie manuala
- Clarificare operationala pentru cap tractor fara cuplaj:
  - daca `Tip vehicul = Cap tractor` si nu exista semiremorca activa cuplata, in campul `Status` apare badge-ul `NECUPLAT`
  - badge-ul `NECUPLAT` apare in acelasi loc cu statusurile de ansamblu (lista + detalii)

### 2026-04-17

- A fost adaugata pagina noua `Dashboard Analitic` ca ecran separat de test (fara integrare in baza de date)
- Ruta noua este accesibila din meniu prin `Dashboard Analitic` si prin `?page=dashboard_analitic`
- A fost creat controllerul `DashboardAnaliticController` cu date mock pentru KPI-uri, grafice si tabel
- A fost adaugat view-ul dedicat `views/dashboard/analytic.php` cu layout complet dupa referinta vizuala
- Au fost adaugate stiluri CSS dedicate (`da-*`) pentru carduri KPI, filtre, grafice si tabelul de analiza
- Pagina noua ramane intentionat in modul demo pentru validarea UI, inainte de conectarea la date reale
- A fost ajustata responsive-ul pentru `Dashboard Analitic`:
  - grid KPI fluid (`auto-fit`) pentru a elimina overflow-ul orizontal
  - fonturi reduse pe carduri si panouri pentru lizibilitate mai buna
  - dimensiuni grafice recalibrate pe rezolutii medii/mici
  - corectie layout flex prin `min-width: 0` pe containerul principal
  - optimizare pentru zoom browser, cu wrap mai bun al valorilor KPI
- Ajustare suplimentara pentru consistenta cu dashboard-ul principal:
  - KPI-urile din `Dashboard Analitic` folosesc acum tipografia de baza din dashboard-ul 1 (`kpi-label`, `kpi-value`, `kpi-sub`, `kpi-icon`)
  - au fost eliminate scalari dependente de viewport (`vw/clamp`) care mariau textul la zoom 80%
  - sectiunile grafice au primit protectii anti-overflow pentru a nu impinge continut in afara paginii
- A fost facuta o simplificare completa pentru responsive pe `Dashboard Analitic`:
  - au fost eliminate ajustarile responsive adaugate anterior care produceau layout imprevizibil la anumite rezolutii/zoom
  - grid-ul KPI este acum pe coloane Bootstrap (`col-sm`/`col-xl`/`col-xxl`) ca in dashboard-ul principal
  - dimensiunile tipografice au fost reduse in toate sectiunile (KPI, panouri, tabele, legende)
  - panourile de grafice au fost redistribuite pe coloane egale pentru a evita taierea textelor din zona `Structura Costuri`
- Decizie de produs: `Dashboard Analitic` ramane activ doar ca prototip UI pana stabilim sursele exacte de date si formulele KPI finale
- Prioritatea imediata urmatoare: creare pagina noua separata, fara blocare pe integrarea de date pentru dashboard-ul analitic
- Modulul `Mentenanta` a fost extins pentru lucru cu facturi:
  - eticheta `Atelier` a fost schimbata in `Furnizor manopera`
  - a fost adaugat camp nou `Furnizor piesa`
  - a fost adaugat upload optional de factura in formularul de creare/editare
  - in editare este disponibila previzualizare fisier + optiune de stergere la salvare
  - in lista `Mentenanta` apare buton `Vezi factura` cand exista fisier atasat
  - in pagina de detalii pentru interventie apare sectiune dedicata de previzualizare factura
  - a fost extinsa ruta de `preview` pentru modulul `mentenanta`
  - au fost adaugate coloanele DB `furnizor_piesa`, `fisier_original`, `fisier_stocat` in tabela `mentenanta`
  - au fost actualizate `database/database.sql`, `reset_database.sql` si a fost adaugat scriptul `database/update_mentenanta_invoice_and_suppliers.sql`
  - status cerere: implementat complet conform cerintei (UI + backend + schema DB + script de migrare)

- Modulul `Vehicule` a fost extins pentru scenariul cap tractor + semiremorca:
  - a fost adaugat campul `tip_vehicul` (`autovehicul`, `cap_tractor`, `semiremorca`) in formular, listare, detalii si filtre
  - a fost adaugata tabela noua `vehicule_cuplaje` pentru istoric de alocari tractor-semiremorca
  - in `Vehicule -> Detalii` exista acum card de cuplare/decuplare:
    - daca vehiculul este `cap_tractor`, se poate selecta semiremorca activa
    - daca vehiculul este `semiremorca`, se poate muta rapid pe alt tractor
  - la reasignare, sistemul inchide automat cuplajele active anterioare si creeaza unul nou (istoric pastrat)
  - in listarea `Vehicule` apare coloana `Cuplat cu` pentru vizibilitate rapida
  - a fost creat scriptul de migrare `database/update_vehicle_tractor_trailer_links.sql`
  - schema de baza a fost actualizata in `database/database.sql` si `reset_database.sql`

### 2026-04-16

- Formularul `Adauga vehicul` / `Editeaza vehicul` nu mai foloseste campul `Consum mediu (L/100km)`
- Campul a fost inlocuit in UI cu `Serie sasiu`
- `Serie sasiu` este acum obligatoriu la creare si editare
- Validarea backend normalizeaza valoarea prin eliminarea spatiilor si transformarea in majuscule
- `Serie sasiu` trebuie sa aiba exact `17` caractere; altfel salvarea este blocata cu mesaj de eroare
- A fost adaugat campul `Poza vehicul` in formularul modulului `Vehicule`
- Poza accepta fisiere `JPG`, `PNG` si `WEBP`, cu limita de `5 MB`
- In editare exista acum preview pentru poza existenta si optiune de stergere la salvare
- Lista principala `Vehicule` afiseaza acum o miniatura foto pentru fiecare vehicul
- Pagina de detalii a vehiculului afiseaza poza in format mai mare
- Au fost adaugate coloanele DB `serie_sasiu`, `poza_original` si `poza_stocata`
- Seed-urile SQL pentru vehicule au fost actualizate cu serii sasiu demo valide de 17 caractere
- A fost creat scriptul `database/update_vehicle_chassis_photo.sql` pentru bazele deja existente
- Baza locala a fost migrata si este compatibila cu noul formular `Vehicule`

### 2026-04-06

- Debug complet pentru esecul notificarilor email si SMS
- Confirmare separata a celor doua cauze:
  - SMTP Migadu nu este accesibil din mediul local curent
  - SMSAlert raspunde cu `401 Invalid authorization headers`
- Refactor major in `NotificationService`: trimitere agregata per destinatar
- Mai multe documente eligibile genereaza acum un singur email rezumat si un singur SMS rezumat per destinatar
- Imbunatatire `SimpleSmtpMailer` pentru diagnostic mai clar la esecul conexiunii
- Adaugare retry automat si fallback SMTP optional `465/ssl -> 587/tls`
- Extindere retry SMTP si pentru esecurile aparute dupa conectare
- Adaugare intarziere scurta intre emailuri pentru a reduce esecurile tranzitorii
- Reducere timeout-uri si retry-uri implicite pentru a evita asteptarea foarte lunga la esec
- Clarificare in cod si in UI: daca `SMSALERT_API_KEY` lipseste, pagina `Notificari` afiseaza explicit ce camp lipseste
- Clarificare in configuratie: `SMSALERT_API_PASSWORD` nu este folosit in modul `bearer`
- Adaugare script [htdocs/test_smsalert.php] pentru test direct SMSAlert din browser sau CLI
- Scriptul de test foloseste configuratia actuala a aplicatiei, afiseaza diagnosticul complet si este restrictionat la local sau admin
- Sanitizare configuratie SMTP in constructor prin `trim()`
- Imbunatatire `SmsAlertClient` pentru sanitizare URL / API key si mesaje de eroare mai explicite
- Adaugare autentificare configurabila pentru SMSAlert: `bearer`, `basic` sau header custom complet
- Corectie bug: in modul `basic`, SMSAlert foloseste acum `username + password`, nu `username + api key`
- Adaugare mod `auto` pentru SMSAlert, cu fallback rapid intre formatele de autentificare uzuale
- Simplificare integrare SMSAlert pentru a ramane aliniata cu API v2 bazat pe `API key`
- Adaugare setare `NOTIFICATIONS_DISABLE_DUPLICATE_PROTECTION` pentru testare repetata fara blocaj de duplicate
- Afisare in UI a erorii efective si a raspunsului furnizorului in istoricul de notificari
- Refactor logica pentru destinatarii email din modulul `Notificari`
- Adaugare configurare `EMAIL_NOTIFICATION_RECIPIENTS` pentru inbox-uri fixe de alerta
- Setare initiala pentru trimitere email catre `alarma@lpg-auto.ro`
- Fallback pastrat: daca lista fixa este goala, emailul merge catre utilizatorii activi cu opt-in email
- Clarificare functionala: `EMAIL_FROM_ADDRESS` este expeditorul, nu destinatarul
- Clarificare functionala: SMS-urile folosesc momentan utilizatorii activi cu opt-in SMS, nu un model dedicat de proprietar vehicul
- Imbunatatire UI in pagina `Notificari` pentru afisarea explicita a destinatarilor email si SMS
- Actualizare README cu noua configurare pentru destinatari email
- Implementare sistem de notificari pentru expirarea documentelor
- Canal email pregatit pentru Migadu prin SMTP
- Canal SMS pregatit pentru SMSAlert prin API REST
- Adaugare tabela `notificari_log`
- Adaugare script `database/update_notifications_system.sql`
- Adaugare pagina admin `Notificari`
- Adaugare buton de trimitere manuala din aplicatie
- Adaugare script CLI `scripts/trimite_notificari.php` pentru VPS / cron
- Adaugare preferinte de notificare pe utilizatori si in profil
- Aplicare migrare locala pentru sistemul de notificari
- Test de executie CLI pentru sistemul de notificari
- Sistemul evita duplicatele pe acelasi document, canal, destinatar si prag
- Schimbare oficiala de strategie deploy: de la shared hosting InfinityFree la VPS propriu
- Aplicatia va rula pe un subdomeniu dedicat, separat de domeniul principal
- README rescris pentru noul scenariu de deploy
- Context nou favorabil pentru viitorul sistem de notificari email + SMS
- Implementare imbunatatiri pentru modulul `Documente`
- Upload optional de fisiere pentru documente
- Adaugare folder securizat `htdocs/uploads/documente`
- Adaugare previzualizare fisier pentru documentele PDF si imagini in pagina de detalii si in formularul de editare
- Adaugare buton rapid `Previzualizeaza` in lista de documente pentru randurile care au fisier atasat
- Clarificare logica pentru campul `Serie / numar document`: camp optional, folosit doar daca documentul are identificator util
- Adaugare pagina dedicata `Previzualizare document` in interiorul aplicatiei
- Adaugare sectiune de documente atasate in pagina de detalii a fiecarui vehicul
- Precompletare automata a vehiculului atunci cand se adauga document din pagina unui vehicul
- Adaugare sumar notificari in pagina de listare pentru documente
- Filtre noi pentru stare expirare si existenta fisierului atasat
- Adaugare audit log pentru actiunile create / update / delete din Documente
- Adaugare script SQL de update: `database/update_documente_improvements.sql`
- Actualizare schema in `database/database.sql` si `reset_database.sql`
- Aplicare update local in baza de date pentru testare
- Test de integrare local pentru fluxul create / delete in Documente si verificare audit log
- Review al dashboard-ului din punct de vedere frontend + backend
- Refactor controller dashboard pentru a citi filtre din query string
- Refactor model dashboard pentru KPI-uri filtrabile
- Adaugare selector de vehicul si butoane pentru perioada
- Corectie logica documente apropiate de expirare
- Curatare header principal pentru meniul lateral
- Validare sintaxa pentru toate fisierele PHP

### 2026-04-07

- Scriptul `htdocs/test_smsalert.php` a fost actualizat pe formatul oficial SMSAlert bazat pe `basic auth`
- Test real reusit catre SMSAlert cu raspuns `HTTP 200` si `status: true`
- Confirmare practica: pentru contul curent SMSAlert functioneaza cu `username + API key` prin `CURLOPT_USERPWD`
- Clientul principal `SmsAlertClient` a fost aliniat la aceeasi metoda atunci cand `SMSALERT_AUTH_MODE = basic`
- Configuratia aplicatiei a fost schimbata pe `SMSALERT_AUTH_MODE = basic`
- Mesaj important: pentru integrarea curenta, `SMSALERT_API_PASSWORD` nu este folosit efectiv in fluxul functional validat; cheia API este folosita ca parola in basic auth
- OpenSSL este disponibil in PHP local
- Configuratia SMTP principala a fost mutata pe `smtp.migadu.com:587` cu `tls`
- Configuratia `465/ssl` a ramas doar ca fallback
- Protectia de duplicate a fost reactivata pentru modul normal de lucru
- Pagina `Notificari` marcheaza acum faza 1 ca stabilizata si mentioneaza explicit directia pentru faza 2
- Pregatirea pentru faza 2 este documentata in README si in prioritatile proiectului
- A fost implementata tabela `notificari_reguli_documente` pentru regula de start per tip de document
- A fost adaugat `soferi.vehicle_id` pentru alocarea soferului la vehicul
- A fost extins `notificari_log` cu `driver_id` si `document_data_expirare`
- Logica de notificare a fost schimbata:
  - documentul se declanseaza cand ajunge la ziua de start configurata pentru tipul lui
  - dupa declansare, documentul ramane in ciclu activ pana la modificarea datei de expirare
  - ciclul se reseteaza automat cand data expirarii se schimba
- Emailurile merg catre utilizatorii activi cu opt-in email, plus inbox-urile fixe din configuratie daca exista
- SMS-urile merg catre soferii activi care au vehicul alocat si telefon valid
- Tabul `Notificari` permite acum salvarea regulilor per tip de document
- Lista documentelor eligibile afiseaza starea ciclului: `Declansare noua` sau `Ciclu activ`
- A fost creat scriptul SQL `database/update_notifications_phase2.sql`
- Migrarea de faza 2 a fost aplicata local pentru testare


### 2026-04-15

- Statusul pentru `Vehicule` si `Soferi` nu mai este controlat manual din formular
- Formularul nu mai expune campul `Status` pentru aceste doua module
- A fost adaugata logica de recalcul automat pe baza documentelor obligatorii si a valabilitatii lor
- Pentru `Vehicule`, statusul devine `Activ` doar daca exista toate documentele obligatorii configurate si toate sunt valabile
- Pentru `Soferi`, statusul devine `Activ` doar daca permisul este valabil si toate documentele obligatorii ale soferului sunt valabile
- Daca lipseste un document obligatoriu sau unul dintre documente este expirat, statusul devine automat `Inactiv`
- Recalcularea statusului se face automat la creare, editare si stergere pentru vehicule, soferi si documentele asociate lor
- In pagina de detalii pentru `Vehicule` si `Soferi` apare acum un rezumat clar cu motivele pentru care cazul este `Activ` sau `Inactiv`
- A fost introdus serviciul `EntityStatusService` pentru centralizarea regulilor de status

### 2026-04-14

- Modulul `Vehicule` a fost corectat pentru afisarea campului `An fabricaÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âºie` fara separator de mii
- Anii se afiseaza acum corect ca `2021`, `2022`, nu ca `2.021`, `2.022`
- Corectia a fost aplicata atat in listare si detalii, cat si in exportul CSV
- A fost introdus un tip dedicat de afisare `year`, pentru a nu afecta alte campuri numerice unde separatorul de mii ramane util

### 2026-04-08

- Modulul `Soferi` a fost extins cu suport pentru documente dedicate ale soferului
- A fost adaugata tabela `documente_soferi`
- A fost adaugat scriptul `database/update_driver_documents.sql`
- Baza locala a fost actualizata si populata cu documente demo pentru soferi
- In pagina `Soferi -> Detalii` exista acum sectiune dedicata pentru documentele asociate soferului
- Din sectiunea soferului se poate intra in:
  - adaugare document
  - detalii document
  - editare document
  - previzualizare document in aplicatie
- Documentele soferilor folosesc acelasi mecanism de upload si previzualizare ca documentele vehiculelor
- Fluxul de navigare pentru documentele soferilor se intoarce natural in pagina soferului asociat
- Extinderea notificarilor pentru expirarea documentelor de sofer ramane urmatorul pas planificat
- A fost facut un review dedicat pentru textele din interfata care afisau diacritice corupte
- Au fost curatate textele sursa din configurarea modulelor, astfel incat titlurile, etichetele si butoanele sa se afiseze corect in romana
- A fost refacut formularul generic al modulelor in UTF-8 curat pentru a elimina texte stricate precum `Editeaza`, `Inapoi la lista`, `Salveaza` sau `Renunta`
- Titluri precum `ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œoferi`, `AlimentÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ri`, `MentenanÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂºÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢`, `NumÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢r document`, `ParolÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢` si campurile asociate se incarca acum corect din configurare
- Curatarea a fost aplicata la sursa, nu doar in pagini individuale, pentru a evita reaparitia textelor corupte in alte ecrane
- Pagina `Notificari` a fost simplificata: administratorul introduce acum un singur numar de zile inainte de expirare pentru fiecare tip de document
- A fost eliminata logica vizibila din UI bazata pe liste de praguri separate prin virgula
- Backend-ul de notificari porneste acum ciclul atunci cand documentul ajunge la ziua de start configurata
- Dupa pornire, documentul ramane in ciclu activ la fiecare rulare ulterioara pana cand un operator actualizeaza data de expirare
- Protectia de duplicate ramane activa pe aceeasi zi, canal, destinatar si acelasi ciclu al documentului
- Aplicatia citeste compatibil si valorile vechi din baza de date de forma `30,15,7,1,0`, dar le interpreteaza doar prin ziua de start
- La urmatoarea salvare din tabul `Notificari`, regulile sunt rescrise in formatul nou simplificat
- Seed-urile SQL au fost aliniate la regula unica de start
- A fost adaugat scriptul `database/update_notifications_single_start_day.sql` pentru normalizarea bazelor de date care folosesc inca formatul vechi

### 2026-04-03

- Reinitializare baza de date dupa stergerea tabelelor si seed-urilor
- Adaugare fisier `reset_database.sql`

### 2026-03-23

- MVP initial creat
- Structura compatibila InfinityFree
- SQL schema + seed
- CRUD + autentificare + dashboard de baza

## Review tehnic curent

### Ce functioneaza bine

- Aplicatia este simpla si usor de deployat
- Codul este potrivit pentru VPS si poate fi extins mai usor
- Separarea pe fisiere este suficient de clara pentru un MVP
- Filtrele si listarea modulelor sunt usor de inteles
- Statusul pentru Vehicule si Soferi este acum mai util operational, pentru ca reflecta direct validitatea documentelor
- Securitatea de baza este implementata corect pentru nivel MVP
- Mutarea pe VPS elimina o parte din limitarile de infrastructura pentru integrari externe
- Textele corupte din configurarea modulelor nu mai contamineaza listari, formulare si butoane in interfata
- Aplicatia poate gestiona acum documente atat pentru vehicule, cat si pentru soferi, cu previzualizare in aplicatie pentru ambele fluxuri
- Modulul `Vehicule` este acum mai util operational, deoarece include seria de sasiu si fotografia direct in fisa fiecarui vehicul
- Exista acum si un al doilea dashboard (`Dashboard Analitic`) pentru validare de design si KPI operationali extinsi
- Sistemul vechi de notificari a fost eliminat complet din cod, UI si schema SQL, pentru rebuild curat in etapa urmatoare
- In `Dispecer curse`, fluxul de distributie este mai rapid deoarece `Loc incarcare` se poate preselecta automat din `Garaj` vehicul cand nomenclatura coincide
- In `Dispecer curse`, operatorul poate urmari si actualiza rapid statusul de facturare direct din `Lista Curse`, fara a intra pe editare
- In `Dispecer curse`, la scroll orizontal in `Lista Curse` ramane fixata coloana `Nr. Inmatriculare`, iar stergerea multipla reduce operatiile repetitive pe seturi mari de curse
- In `Documente`, fluxul de adaugare din fisa vehiculului este mai fluid: dupa salvare se pastreaza acelasi context de vehicul in formularul de creare
- In `Configurare transport`, operatorul poate sterge mai multe reguli beneficiar dintr-un singur pas, fara iteratie manuala rand cu rand

### Ce mai trebuie imbunatatit

- Nu exista teste automate
- Dashboard-ul nu are inca interval personalizat `de la / pana la`
- README-ul vechi are inca probleme de encoding si merita curatat separat
- Ar fi util un changelog mai fin pe fiecare fisier important
- Ar fi utila o validare manuala completa pe toate fluxurile CRUD dupa ultimele schimbari
- Audit log exista momentan doar pentru modulul `Documente`
- Pentru fisiere `.doc` si `.docx` exista deschidere, dar nu previzualizare inline nativa in browser
- Modelul curent foloseste o singura alocare simpla sofer -> vehicul; daca un vehicul are mai multi soferi sau alocari in timp, va trebui extins
- Dashboard-ul analitic nou este inca mock; urmeaza maparea KPI-urilor si graficelor pe date reale din DB
- Noul sistem de notificari trebuie redesenat de la zero (reguli, destinatii, executie, logging)
- Ar fi utila o standardizare de nomenclator pentru `Garaj` si `Loc incarcare` (aceeasi denumire operationala), pentru acoperire maxima a auto-selectului

## Probleme cunoscute

- Unele fisiere vechi de documentatie au urme de encoding gresit
- Coloana veche `consum_mediu` a fost pastrata temporar in schema doar pentru compatibilitate cu bazele locale vechi si poate fi eliminata complet la o curatare ulterioara
- Modulul `Vehicule` mai poate fi imbunatatit ulterior cu validari UI dedicate pentru campuri precum `An fabricatie` si fostul camp `Consum mediu`
- Listele de documente obligatorii pentru Vehicule si Soferi sunt momentan configurate static in `config.php` si pot avea nevoie de ajustare dupa regulile reale ale companiei
- Nu exista inca audit log unificat pentru toate modulele
- Nu exista testare automata E2E sau unit tests
- Previzualizarea inline functioneaza direct pentru PDF si imagini; pentru alte formate utilizatorul trebuie sa deschida fisierul separat
- Mai exista documentatie veche in afara interfetei care poate avea urme de encoding imperfect si merita curatata separat
- Dashboard-ul analitic foloseste date statice de test si nu reflecta inca valori reale din exploatare
- Modulul de notificari este dezactivat complet pana la redesenare
- Auto-selectul `Garaj -> Loc incarcare` depinde de denumiri compatibile textual; denumiri diferite semantic, dar scrise diferit, nu se vor mapa automat
- Statusul de facturare este salvat prin submit instant la schimbarea dropdown-ului din lista; in retele lente pot aparea click-uri duble daca operatorul schimba rapid valoarea
- In stergerea bulk pentru `Reguli beneficiar configurate`, regulile deja folosite in curse nu pot fi sterse; sistemul continua cu restul selectiei si raporteaza ID-urile blocate
- In stergerea bulk pentru `Dispecer curse`, daca unele curse esueaza la stergere (ex: eroare DB/fisiere), sistemul continua cu restul selectiei si afiseaza sumarul ID-urilor esuate

## Prioritati recomandate

1. Redesemnare completa a noului sistem de notificari (fara reutilizarea logicii vechi)
2. Definire reguli de business pentru notificari (destinatari, praguri, canale, deduplicare)
3. Extindere model sofer-vehicul pentru alocari multiple sau istorice
4. Conectare `Dashboard Analitic` la DB si definire formule KPI finale (dupa definirea surselor de date)
5. Adaugare interval personalizat in dashboard
6. Review manual complet pentru toate modulele CRUD
7. Extindere audit log in celelalte module
8. Curatare definitiva a coloanei legacy `consum_mediu` dupa confirmarea ca toate bazele au fost migrate
9. Standardizare nomenclator operational `Garaj` / `Loc incarcare` pentru a reduce exceptiile manuale in fluxul de distributie
10. Adaugare filtre/rapoarte dupa `status_facturare` (ex: doar `facturat` / `nefacturat`) pentru inchidere operationala si contabilitate
11. Extindere operatiuni bulk in `Configurare transport` (ex: activare/dezactivare multipla reguli beneficiar)
12. Extindere operatiuni bulk in `Dispecer curse` (ex: update multiplu `status_facturare`, export selectie)

## Cum folosim acest fisier mai departe

La fiecare task nou, actualizam minim aceste sectiuni:

- `Ultimul update`
- `Modificari recente`
- `Review tehnic curent`
- `Probleme cunoscute`
- `Prioritati recomandate`

## Fisiere cheie in starea actuala

- `htdocs/index.php`
- `htdocs/controllers/DashboardController.php`
- `htdocs/controllers/DashboardAnaliticController.php`
- `htdocs/controllers/DispecerCurseController.php`
- `htdocs/models/DashboardModel.php`
- `htdocs/models/DocumentModel.php`
- `htdocs/models/DispecerCurseModel.php`
- `htdocs/views/dashboard/index.php`
- `htdocs/views/dashboard/analytic.php`
- `htdocs/views/dispecer_curse/index.php`
- `htdocs/views/dispecer_curse/edit.php`
- `htdocs/views/dispecer_curse/config.php`
- `htdocs/views/module/list.php`
- `htdocs/views/module/form.php`
- `htdocs/views/module/show.php`
- `htdocs/views/layout/header.php`
- `htdocs/assets/css/style.css`
- `htdocs/assets/js/dispecer-curse.js`
- `htdocs/includes/helpers.php`
- `database/database.sql`
- `database/update_dispecer_curse_module.sql`
- `database/update_dispecer_vehicle_default_assignments.sql`
- `database/update_documente_improvements.sql`
- `database/update_vehicle_chassis_photo.sql`
- `reset_database.sql`

- Fix 2026-04-20: eroare 500 in Vehicule -> Detalii dupa cuplare rezolvata (PDO HY093 din placeholder duplicat in istoricul cuplajelor).
- Update 2026-04-20: sistemul vechi de notificari a fost eliminat complet din backend, UI, configurare si schema SQL pentru a permite rebuild de la zero.
- Update 2026-04-20: adaugat `database/remove_notifications_legacy.sql` pentru curatarea bazelor existente fara reset complet.
- Update 2026-04-20: adaugat tipul de vehicul `CAMION` si campul obligatoriu `Km bord` in modulul `Vehicule` (UI + validare + schema + migrare `database/update_vehicle_camion_km.sql`).
- Update 2026-04-21: adaugat campul obligatoriu `Km revizie` in modulul `Vehicule` (formular creare/editare + listare + detalii), cu suport schema/seed si migrare dedicata `database/update_vehicle_km_revizie.sql`.
- Update 2026-04-23: extins modulul `Configurare transport` pentru `Beneficiari transport` (pret tarifare, tip transport suportat, pret/km, pret/tona, plus actiuni `Detalii` si `Editeaza`) si conectata calculatia din `Dispecer curse` la tarifele beneficiarului; campul manual `Pret tarifare` a fost eliminat din formularul `Adauga/Editeaza cursa`.
- Update 2026-04-23: in `Dispecer curse`, `Cantitate incarcata` este acum disponibila si pentru curse `Primar`; daca exista cantitate (>0), totalul se calculeaza dupa `pret/tona` din beneficiar, iar pentru `Primar` fara cantitate se pastreaza calculul clasic pe `Km cursa`.
- Update 2026-04-23: ajustata formula pentru `Primar` in `Dispecer curse` astfel incat, daca sunt completate ambele campuri (`Km cursa` si `Cantitate incarcata`), `Total facturare` devine suma componentelor: `Km * pret/km` + `Cantitate * pret/tona`.
- Update 2026-04-23: optimizat UI tabel `Lista Curse` din `Dispecer curse` (layout coloane, aliniere numerica, butoane actiuni, spacing si responsive horizontal scroll) fara modificari de backend/query.
- Update 2026-04-23: compactat randurile din tabelul `Lista Curse` (fara wrapping pe mai multe linii, truncare cu ellipsis + tooltip, padding/font mai dens, butoane actiuni mai compacte) pentru aspect profesional in zona de operare desktop.
- Update 2026-04-23: ajustat din nou layout-ul tabelului `Lista Curse` pentru lizibilitate maxima (eliminata compresia agresiva, activat comportament de latime naturala a tabelului + scroll orizontal, marite min-width-urile pe coloane, header/celule fara coliziune text).
- Update 2026-04-24: pagina `Configurare transport` a fost refacuta complet in layout tip "single panel" (un card principal cu sectiuni interne pentru regula de tarifare, locuri de incarcare, zone de distributie si tabel reguli), pastrand aceeasi logica backend pentru create/edit/delete.
- Update 2026-04-27: in `Configurare transport` (sectiunea `Loc incarcare`) a fost adaugat campul nou `Tarif` in formular si coloana `Tarif` in tabelul de listare.
- Update 2026-04-27: fluxul de stergere pentru `Loc incarcare` a fost corectat astfel incat locatiile deja folosite in `Dispecer curse` pot fi sterse fara eroare (referintele din curse se decupleaza automat).
- Update 2026-04-27: adaugat scriptul de migrare `database/update_dispecer_locuri_tarif.sql` si actualizate scripturile SQL principale (`database.sql`, `reset_database.sql`, `update_dispecer_curse_module.sql`) pentru `tarif` la locuri de incarcare + `loc_incarcare_id` nullable in `curse_dispecer`.
- Update 2026-04-27: extins `Dispecer curse` cu campurile `Ore aspirare`, `Km dislocare`, `Tona livrata` (create/edit/listare), plus legatura la tarifele din `Configurare transport` pentru tipul `Compresor` (`pret_ora_aspirare`, `pret_km_dislocare`, `pret_tona_livrata`), inclusiv validare backend si calcul automat `Total facturare`.
- Update 2026-04-27: rulate local migrarile `database/update_dispecer_curse_module.sql`, `database/update_dispecer_locuri_tarif.sql` si `database/update_dispecer_beneficiar_compresor.sql` pe baza `if0_41456552_aplicatie_flota` pentru sincronizarea structurii cu noile campuri din `Dispecer curse` si `Configurare transport`.
- Update 2026-04-27: fix validare `Configurare transport` pentru `Compresor` - `Pret tarifare` si `Pret/km` nu mai sunt obligatorii cand este selectat doar tipul `Compresor`; validarea cere strict campurile specifice (`Pret/tona`, `Pret ora aspirare`, `Pret km dislocare`, `Pret tona livrata`).
- Update 2026-04-28: in `Configurare transport` (reguli beneficiar), eticheta din matrice a fost redenumita din `Pret tarifare (lei)` in `Pret cursa (lei)`.
- Update 2026-04-28: eliminate restrictiile de completare pe tip in configurarea beneficiarului (`Primar`/`Distributie`/`Compresor`); toate campurile de tarif sunt completabile liber, fara validari obligatorii pe tip (se valideaza doar format numeric >= 0).
- Update 2026-04-28: pentru cursele `Compresor`, `Pret cursa` este folosit ca tarif de fallback acolo unde nu exista tarif dedicat (`Pret/km` sau `Pret/tona`), iar calculul total foloseste componentele completate in cursa.
- Update 2026-04-28: corectat calculul pentru `Compresor` in `Dispecer curse` astfel incat `Km cursa` contribuie la `Total facturare` (frontend preview + backend la salvare), folosind tariful `Pret/km` configurat la beneficiar.
- Update 2026-04-28: adaugat cache-busting pe `assets/js/dispecer-curse.js` in paginile `Dispecer curse` (`index`/`edit`) pentru a evita rularea versiunilor vechi de script din browser cache.
- Update 2026-04-30: in `Configurare transport`, dropdown-ul `Vehicul implicit` din `Loc incarcare` afiseaza doar vehicule active.
- Update 2026-04-30: in `Configurare transport -> Zone distributie si tarif`, a fost adaugat dropdown-ul `Vehicul implicit` cu listare doar vehicule active.
- Update 2026-04-30: adaugata persistenta pentru alocarea implicita vehicul-zona in tabela noua `configurare_zone_distributie_vehicule`, cu afisare in coloana noua `Vehicul implicit` din tabelul zonelor.
- Update 2026-04-30: sincronizate scripturile SQL (`database.sql`, `reset_database.sql`, `update_dispecer_curse_module.sql`) si adaugat script nou `database/update_dispecer_vehicle_default_assignments.sql` pentru tabelele de alocare implicita vehicul.
- Update 2026-04-30: selectia multipla pentru `Vehicule implicite` (Loc incarcare / Zone distributie) foloseste dropdown cu checkbox-uri in lista, pentru UX unitar cu restul configuratorului.
- Update 2026-04-30: sectiunile `Loc incarcare` si `Zone distributie si tarif` au fost grupate intr-un bloc UX unic (`Configurare distributie (Loc + Zona)`), cu formula vizibila pentru operator: `Cantitate x (Tarif loc + Tarif zona) + Km cursa x Cost extra km`.
- Update 2026-04-30: adaugat camp nou `Cost extra km (lei/km)` pentru zonele de distributie, cu persistenta completa in model/controller/view si in scripturile SQL (`database.sql`, `reset_database.sql`, `update_dispecer_curse_module.sql`, `seed_dispecer_curse_demo_20.sql`).
- Update 2026-04-30: calculul `Distributie` in `Dispecer curse` foloseste acum explicit componentele `Tarif loc incarcare + Tarif zona + Cost extra/km`, atat in preview frontend (`dispecer-curse.js`), cat si la salvare backend (`validateRaceInput`).
- Update 2026-04-30: in formularul `Adauga cursa`, dropdown-ul `Nr. Inmatriculare` afiseaza doar vehicule active, iar pentru distributie sunt afisate indicii UX dedicate si campurile irelevante pe tip sunt ascunse pentru simplificarea fluxului.
- Update 2026-04-30: in `Configurare transport -> Configurare distributie (Loc + Zona)`, cele doua tabele separate (`Loc incarcare` si `Zone distributie`) au fost inlocuite cu o lista unica ce afiseaza intr-un singur tabel toate coloanele relevante (`Tip`, `Loc`, `Zona`, `Vehicule implicite`, `Tarif loc`, `Tarif zona`, `Cost extra km`, `Status`, `Actiuni`), cu editare/stergere pastrate pe fiecare tip de rand.
- Update 2026-04-30: in `Configurare transport -> Configurare distributie (Loc + Zona)`, formularele separate pentru `Loc` si `Zona` au fost inlocuite cu un formular unificat si o singura actiune de salvare (`config_store_distributie`): operatorul poate salva dintr-un singur click doar locul, doar zona sau ambele simultan.
