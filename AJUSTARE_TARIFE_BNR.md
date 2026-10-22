# Centru ajustare tarife combustibil & curs BNR

Pagina `?page=ajustare_tarife` (meniu: **Ajustare tarife**, după *Administrare tarife*).
Implementat 21.10.2026. Indicatorii și simulările se actualizează automat; tarifele
contractuale se schimbă **doar** prin aprobare manuală.

## 1. Evaluare de compatibilitate (faza 1)

| Ce există | Cum e folosit |
|---|---|
| `transport_tariff_versions` — tarife versionate pe `rule_signature` (beneficiar × componentă × rută), `valid_from/valid_to`, `fuel_weight`, `reference_fuel_price` | Sursa tarifului în vigoare; aprobarea creează versiuni noi prin `TransportTariffModel::createVersion()` (tranzacțional, închide versiunea precedentă, nu atinge `curse_dispecer`). |
| Configurare transport (`configurare_beneficiari_transport`, `configurare_rute_primar`, `configurare_rute_distributie`) | Valoarea tarifului când componenta nu are încă versiune (aceeași ordine ca `TransportPricingService`). La prima versionare, `createVersion()` păstrează automat valoarea veche ca baseline. |
| `transport_tariff_history`, `transport_tariff_reviews` | Fiecare tarif aplicat scrie în istoric (`recordHistory`) și închide recomandarea de revizuire existentă. |
| `transport_tariff_settings.fuel_review_threshold_percent` (= 2) | **Același prag** de ±2% — nu există un al doilea prag. Setările noi (`fx_*`) stau în același tabel. |
| CardOil `fuel_fillups` + `FuelPriceIndexService` | Aceleași filtre: doar motorină, doar `source_type='api'`, litri și valoare > 0; medie ponderată cu volumul. |
| Preț mic / mare Distribuție (`CentralizatorFacturareService::distributionPriceBuckets`) | Aceeași regulă: tarifele distincte ale beneficiarului sortate crescător → unic / mic / mare / intermediar. |
| ACL: `permissions/modules/*.php` + garda din router | Modul nou `ajustare_tarife` cu acțiunile `view`, `sync`, `configure`, `apply`. |
| Cron: wrapper-e `run_sync_*.sh` cu `flock` | `scripts/run_sync_bnr.sh` după același model. |
| Chart.js 4.4.3, Bootstrap 5.3, `bootstrap-icons` | Aceleași biblioteci. Meniul și antetul aplicației nu au fost atinse (doar un link nou în meniu). |

**Conflicte / diferențe față de cerință (documentate, nu ascunse):**

1. **Toate tarifele existente sunt în lei** (`lei/km`, `lei/tona`, `lei/cursa`, `lei/ora`). Nu există tarife contractuale în EUR, deși macheta afișează „€/km”. Pagina afișează moneda reală; în detalii apare moneda contractului. Nu se face nicio conversie a tarifului — ajustarea e doar `tarif × (1 + variație × pondere)` în moneda proprie, deci un tarif EUR adăugat ulterior nu ar primi o a doua conversie FX.
2. **Formula tarif ← motorină nu este confirmată contractual.** Nu am inventat-o: ponderea motorinei (0–1) e configurabilă, iar aplicarea cere o **pondere confirmată pe tarif** (vezi §8). Azi nicio versiune nu are `fuel_weight` setat, deci aplicarea e blocată până la confirmare.
3. Monitorizarea existentă din *Administrare tarife* compară motorina **în lei, pe 30 de zile**, cu referința capturată la crearea versiunii. Noul modul compară motorina **în EUR (configurabil: lei), pe fereastră glisantă de 3 luni**, cu o referință salvată explicit. Cele două coexistă; la aplicare, versiunea nouă primește și referința de 30 de zile a mecanismului vechi, ca acesta să rămână coerent.
4. `TransportTariffModel::resolveVersionsAt()` (nefolosită până acum) repeta placeholder-ele PDO și dădea HY093 cu `EMULATE_PREPARES=false`. Reparat (placeholder-e distincte), fără altă schimbare de comportament.
5. Prețul motorinei: datele CardOil locale se opresc la 30.09.2026 (ultima sincronizare reușită). Pagina le marchează „neactualizat”, iar aplicarea e blocată până la o sincronizare CardOil.

## 2. Fișiere

**Noi**
- `htdocs/services/FuelFxCalculator.php` — matematica pură (medii, fereastră, conversie, descompunere, prag, ajustare, preț mic/mare).
- `htdocs/services/BnrExchangeRateService.php` — descărcare + parsare XML BNR, sincronizare idempotentă cu lock și reîncercări.
- `htdocs/services/FuelFxAdjustmentService.php` — indicatori, catalogul tarifelor, simulare, instantanee, referințe, ponderi, aprobare.
- `htdocs/models/FuelFxAdjustmentModel.php` — tabelele noi.
- `htdocs/controllers/FuelFxAdjustmentController.php` — rutele paginii.
- `htdocs/views/ajustare_tarife/index.php`, `htdocs/assets/css/ajustare-tarife.css`, `htdocs/assets/js/ajustare-tarife.js`.
- `htdocs/permissions/modules/ajustare_tarife.php`.
- `scripts/sync_bnr_curs.php`, `scripts/run_sync_bnr.sh`, `scripts/test_ajustare_tarife.php`.
- `database/migrations/2026_10_21_000001_ajustare_tarife_bnr.sql`.

**Modificate**
- `htdocs/index.php` — `require` + `case 'ajustare_tarife'`.
- `htdocs/views/layout/header.php` — un link în meniu (cu `$navAttrs`, deci ordonabil drag & drop).
- `htdocs/models/TransportTariffModel.php` — fixul HY093 din `resolveVersionsAt()`.

## 3. Schema (toate tabelele noi, nimic modificat în tabelele existente)

| Tabel | Rol |
|---|---|
| `bnr_exchange_rates` | un curs / monedă / zi publicată; `UNIQUE(currency, rate_date)`; `DECIMAL(14,6)` (BNR publică 4 zecimale), `multiplier` păstrat |
| `bnr_sync_log` | fiecare încercare: `running/success/error/skipped`, surse, primite/inserate/conflicte, ultimul curs — ultima reușită e separată de ultima încercare |
| `fx_tariff_baselines` | referințe salvate (F0, E0, P0, fereastră, nr. observații, litri), `manual` sau `approval`; o singură implicită; nu se modifică |
| `fx_tariff_rule_baselines` | referința proprie a unei reguli tarifare (după aprobare) |
| `fx_tariff_rule_weights` | ponderi confirmate cu temei contractual; doar adăugare, ultima câștigă |
| `fx_tariff_snapshots` | instantanee `auto` (zilnice) și `saved`; `UNIQUE(kind, input_hash)` — aceleași intrări nu se dublează |
| `fx_tariff_approvals`, `fx_tariff_approval_items` | aprobarea + fiecare tarif vechi → nou, versiunea veche și cea nouă, referința folosită |

FK: regulile → referințe, aprobări → referințe, elemente → aprobare. Retenție: instantaneele automate > 400 de zile se șterg (nu și cele legate de o aprobare); cele salvate manual rămân.

## 4. Sincronizarea BNR

- Surse: `nbrfxrates10days.xml` (zilnic) și `files/xml/years/nbrfxrates{AN}.xml` (prima rulare: ultimele 13 luni, deci și anul precedent; după o pauză > 12 zile: anii lipsă). Structura verificată la 21.10.2026 (`<DataSet xmlns="https://www.bnr.ro/xsd">…<Cube date><Rate currency="EUR">`); parserul ignoră namespace-ul.
- `INSERT IGNORE` pe `(currency, rate_date)`: o zi salvată nu se suprascrie; o valoare diferită pentru aceeași zi e numărată ca „conflict” în jurnal.
- `GET_LOCK('fleet_bnr_sync')` — rulările suprapuse sunt sărite (și `flock` în wrapper). 3 încercări HTTP cu pauză crescătoare. La eșec datele existente rămân, pagina afișează avertismentul și data cursului folosit.
- Zilnic: `scripts/run_sync_bnr.sh` (sincronizare + instantaneu de simulare). La deschiderea paginii, dacă cursul e mai vechi decât ziua lucrătoare precedentă, pagina cere o actualizare (limitată la o încercare la 2 ore). Butonul ⟳ din antet = sincronizare manuală (drept `sync`).
- Prima rulare locală: 449 cursuri (03.01.2025 – 20.10.2026); a doua: 0 noi.

## 5. Formule (implementate exact așa; procentele nerotunjite, rotunjire doar la afișare)

```
Fereastra       = [azi − N luni, azi] inclusiv (N = 3 implicit; ziua se limitează la finalul lunii)
Curs mediu      E = Σ cursuri EUR/RON publicate în fereastră / număr observații   (fără weekend/sărbători inventate)
Motorină medie  F = Σ valoare / Σ litri   (CardOil, motorină, doar API; ponderat cu volumul)
Motorină EUR    P = F / E                 (media cursului, niciodată cursul zilei)

Față de referința (F0, E0):
  Motorină lei %     = (F1/F0 − 1)×100
  Curs EUR/RON %     = (E1/E0 − 1)×100
  Efect curs (EUR) % = (E0/E1 − 1)×100     — EUR/RON ↑ ⇒ motorina în EUR ↓
  Combinat %         = ((F1/E1)/(F0/E0) − 1)×100
  Atribuire Shapley: motorină = d + d·f/2, curs = f + d·f/2, suma = combinat exact

Variație (prag)    = Combinat % (bază „motorină EUR”, implicit) sau Motorină lei % (bază „motorină lei”)
Prag               : variație ≥ +P ⇒ sus; ≤ −P ⇒ jos; altfel sub prag   (toleranță 1e-9 p.p. pentru ±P exact)
Tarif simulat      = tarif × (1 + variație/100 × pondere)   (4 zecimale, precizia versiunilor)
```

Graficul motorinei afișează prețul zilnic ponderat și echivalentul EUR la cursul BNR în vigoare în acea zi (doar vizualizare). Butoanele 1L/3L/6L/1A schimbă doar graficul, nu fereastra de calcul.

## 6. Referința de comparație

- Referință **salvată explicit** (Setări → *Referință nouă…*): media pe fereastra de N luni care se termină la data aleasă. Refuzată dacă are prea puține cursuri (< 20) sau prea puține date de motorină.
- Fiecare regulă se compară cu **referința ei proprie** (setată la aprobare) sau cu **referința implicită** — niciodată cu ziua precedentă.
- La aprobare se creează o referință nouă din indicatorii curenți și se atribuie regulilor aprobate. Opțional devine implicită; regulile care foloseau implicit vechea referință sunt mai întâi fixate pe ea, ca baza lor să nu se mute.
- Referințele nu se modifică și nu se șterg (audit).

## 7. Simularea pe tarife

- Granularitate reală: 50 de reguli azi (beneficiar × componentă × rută), din versiuni sau din Configurare transport; doar tarife > 0 ale beneficiarilor activi.
- Grupuri afișate: Primar (lei/km, cost/cursă, tone), Distribuție preț unic/mic/mare/alte + km + cost/cursă, P+D (tone, km, cost/cursă), Compresor (oră, km dislocare, tone). Grupul e doar afișare; „Vezi detalii” arată fiecare tarif, iar aplicarea se face tarif cu tarif.
- Statusuri: Simulat (eligibil) · Provizoriu · Sub prag · Regulă lipsă · Fără referință · Date insuficiente · Date neactualizate · Versiune programată.

## 8. Reguli care așteaptă confirmare de business

1. **Ponderea motorinei în fiecare tarif** (0–1). Surse acceptate pentru aplicare: `fuel_weight` de pe versiunea tarifului (Administrare tarife) sau confirmarea din pagină (*Vezi detalii* → *Confirmă ponderea*, cu temei contractual obligatoriu). Ponderea globală „provizorie” produce doar simulări — **aplicarea e blocată**.
2. **Baza de ajustare pentru tarife în lei**: specificația cere motorina în EUR; pentru un contract în lei, motorina în lei ar putea fi baza corectă. Ambele sunt disponibile (*Regula de simulare*); implicit EUR, conform cerinței.
3. Ce se întâmplă după aprobare cu regulile neaprobate (păstrează vechea referință — implementat) și dacă noua referință devine implicită (opțiune la aprobare, bifată implicit).

## 9. Protecțiile aplicării manuale

- Drept ACL separat `ajustare_tarife.apply` (sensibil, implicit doar admin), verificat în router și în controller; POST + CSRF; confirmare în două pași (bifă „Am verificat” + tastarea „APLIC”).
- Serverul **recalculează tot** la aplicare: regula trebuie să fie eligibilă (prag atins, surse la zi, referință, pondere confirmată, fără versiune programată), iar valoarea propusă trebuie să coincidă cu ce a văzut utilizatorul (altfel: „Datele s-au actualizat între timp”).
- Data intrării în vigoare **strict în viitor**; refuz dacă există deja o versiune care începe la/după acea dată (nu se folosește înlocuirea pe aceeași dată din `createVersion`).
- O singură tranzacție (referință nouă + versiuni + istoric + elemente aprobare) și `GET_LOCK('fleet_fx_tariff_apply')` contra aprobărilor concurente.
- Nu se recalculează nicio cursă și nicio factură (fluxul de *reprice* din Administrare tarife nu este declanșat); versiunile anterioare doar se închid la `valid_from − 1`.
- Se salvează: cine, când, ce s-a schimbat (vechi → nou, %), referința folosită, regula (bază, prag, pondere), nota.

## 10. Teste

`php scripts/test_ajustare_tarife.php` — **60 trecute, 0 eșuate** (21.10.2026). Acoperă cele 24 de scenarii cerute, inclusiv: parsare XML, fără dubluri, medie, fereastră peste an, weekend, BNR indisponibil, observații insuficiente, motorină neactualizată, motorină ↑ / curs ↑ (10,50 lei, 5,28 → 5,33: motorina în EUR scade cu 0,938%) / ambele, prag exact +2% și −2%, sub prag, referința salvată, beneficiari diferiți, preț mic/mare, tarif EUR fără dublă conversie, versiuni noi la aprobare, curse și facturi neschimbate, ACL, sincronizări repetate, aprobări concurente. Fluxul de aprobare rulează într-o tranzacție anulată la final.

Regresie: `test_transport_tariffs.php` are 34/38 — **aceleași 4 eșecuri și fără modificarea mea** (verificat cu modificarea scoasă temporar). `test_drepturi_acces.php` eșua deja la „38 de module” (erau 42, acum 43).

Validare manuală în browser (1450 px, 768 px, 375 px): toate cele 10 secțiuni în ordinea machetei, fără scroll orizontal pe telefon, modal detalii, modal aplicare (blocat corect cu date neactualizate), grafice cu 1L/3L/6L/1A, endpoint-uri JSON; un utilizator fără drept primește 403 la pagină și la `action=apply`.

## 11. Limitări cunoscute

- Formatul numerelor urmează aplicația (virgulă zecimală), nu punctul din machetă.
- Setările au mai multe rânduri decât macheta (prag, pondere provizorie, referință), deci cardul de setări e mai înalt; graficele se întind la aceeași înălțime.
- Sărbătorile legale nu sunt cunoscute de verificarea „curs vechi”: în zilele fără publicare se mai face cel mult o încercare la 2 ore.
- Seria motorinei are puncte doar în zilele cu alimentări.

## 12. Punere în funcțiune pe VPS

```
git up                                         # cod + migrarea se aplică singură la prima deschidere
php scripts/sync_bnr_curs.php                  # prima încărcare (13 luni de cursuri)
crontab:  30 13 * * 1-5 /path/to/app/scripts/run_sync_bnr.sh
          30 18 * * *   /path/to/app/scripts/run_sync_bnr.sh
```

Apoi, în pagină: *Referință nouă…* (fereastra pe care s-au stabilit tarifele actuale) și confirmarea ponderilor pe tarife, după ce sunt stabilite contractual.

## 13. Mod test — prețuri motorină pe lună

Butonul **Simulare test** din antet (`?page=ajustare_tarife&test=1`) recalculează pagina cu prețuri de motorină introduse manual pe lună (tabelul `fx_test_diesel_months`, drept `configure` pentru salvare).

- Prețul unei luni = prețul de test; lună fără preț de test = media reală CardOil a părții din lună care intră în fereastră.
- Media ferestrei = Σ(preț lună × zile din fereastră) ÷ Σ zile (ex. 21.07–21.10: 11 + 31 + 30 + 21 = 93 zile).
- Cursul EUR/RON rămâne cel real BNR.
- Referința de test = aceeași medie pe fereastra care se termină la „Referința de test” (setarea `fx_test_reference_end`); nu se salvează ca referință reală. Fără dată, se folosește referința salvată.
- Panoul arată calculul pas cu pas (lunile, zilele, prețurile, mediile, motorina în EUR, variația, pragul).
- În test: nimic nu e eligibil, „Aplică” și „Salvează simulare” sunt dezactivate, iar serverul refuză aplicarea, salvarea referințelor și instantaneele. Vechimea datelor reale nu blochează simularea.
- Pentru tarife simulate în tabel e nevoie de o pondere (provizorie în Setări sau confirmată pe tarif).
- Teste: 10 cazuri noi în `scripts/test_ajustare_tarife.php` (70/70).

## 14. Tarifele în euro

- Comutatorul **lei | €** din antetul tabelului „Tarife simulate” arată tariful contractual și cel simulat în €/km, €/tonă, €/cursă, €/oră.
- Conversia: € = lei ÷ cursul mediu EUR/RON BNR al ferestrei de analiză (aceeași medie folosită pentru motorină, ex. 5,2705). Diferența % nu se schimbă (e un raport).
- Doar afișare: tarifele contractuale rămân și se aplică în lei; un tarif deja în EUR nu se reconvertește.
- „Vezi detalii” și confirmarea aplicării arată mereu ambele valori (lei + ≈ €); o aprobare din istoric arată € la cursul mediu de la momentul aprobării.
- Alegerea lei/€ se ține minte în browser (localStorage), per utilizator.

## 15. Regula confirmată (21.10.2026) și calculatorul de perioade

Beneficiarul aplicației a stabilit regula de ajustare, care **înlocuiește** secțiunile 8 și 9 privind ponderea:

- Dacă variația motorinei față de referință atinge **pragul (implicit 2%, modificabil din Setări)**, tariful se modifică cu **același procent**: `tarif nou = tarif × (1 + variație %)`.
- Nu mai există pondere: câmpul „Pondere motorină (provizoriu)” și confirmarea ponderii pe tarif au fost scoase. Tarifele cu prag atins și date la zi sunt eligibile direct pentru aplicare (rămân: drept `apply`, dată viitoare, confirmare „APLIC”).
- Fără prag setat se folosește 2% (`FuelFxAdjustmentService::DEFAULT_THRESHOLD`).
- Tabelul `fx_tariff_rule_weights` rămâne în schemă, dar nu mai e folosit.

**Calculator comparație perioade** (card sub KPI):
- Perioada comparată și perioada de referință: fiecare 1 lună sau 3 luni **calendaristice**, care se termină cu luna aleasă (luna curentă se oprește la azi).
- Afișează motorina medie (lei/l, ponderată cu litrii), cursul EUR/RON mediu, motorina în euro, variațiile, verdictul față de prag și cu cât se modifică tarifele.
- „Folosește … ca referință” salvează perioada de referință ca referință implicită (drept `configure`, nu în modul test).
- În modul test, calculatorul folosește prețurile de test.
- Exemplu real (21.10.2026): oct 2026 față de iul–sep 2026: motorină +5,24% lei, curs +1,61%, motorină în euro **+3,57%** → prag depășit → tarife +3,57%.

Teste: 79/79.

