# Fleet Management MVP

Aplicatie web MVP pentru management de flota, construita in PHP 8+, MySQL si Bootstrap 5.

## Status deploy

- Target curent de deploy: VPS propriu
- Expunere publica recomandata: subdomeniu dedicat (ex: `flota.domeniul-tau.ro`)
- Web root recomandat: `htdocs/`

## Stack tehnic

- PHP 8+
- MySQL / MariaDB
- Bootstrap 5
- PDO prepared statements
- Sesiuni PHP
- Apache sau Nginx + PHP-FPM

## Structura proiectului

```text
/aplicatie_fleet
  /htdocs
    index.php
    .htaccess
    /assets
    /config
    /controllers
    /includes
    /models
    /uploads
    /views
  /database
    database.sql
    remove_notifications_legacy.sql
    update_documente_improvements.sql
    update_driver_documents.sql
    update_mentenanta_invoice_and_suppliers.sql
    update_vehicle_camion_km.sql
    update_dispecer_curse_module.sql
    update_vehicle_additional_details.sql
    update_vehicle_chassis_photo.sql
    update_vehicle_tractor_trailer_links.sql
  /reset_database.sql
  /README.md
  /STARE_PROIECT.md
```

## Functionalitati implementate

- Login / logout cu sesiuni
- Roluri: `admin`, `utilizator`
- Dashboard principal cu KPI
- Dashboard analitic (mock UI, date statice)
- CRUD complet pentru:
  - Dispecer curse (curse, filtre, total facturare calculat, cheltuieli, documente cheltuieli, configurare transport)
  - Vehicule
  - Soferi
  - Documente vehicule
  - Documente soferi
  - Alimentari
  - Mentenanta
  - Utilizatori
- Upload + preview fisiere pentru:
  - Cheltuieli cursa (documente justificative)
  - Documente vehicule
  - Documente soferi
  - Mentenanta (facturi)
  - Vehicule (poza)
- Cuplare `Cap tractor` <-> `Semiremorca`
- Tip vehicul extins cu categoria `Camion`
- Camp obligatoriu `Km bord` la vehicul (kilometraj initial)
- Status automat pentru vehicule si soferi pe baza documentelor obligatorii
- Export CSV pe modulele principale
- Profil utilizator
- Audit log pentru modulul `Documente`

## Notificari

- Sistemul vechi de notificari a fost eliminat complet din backend, UI si schema SQL.
- Modulul va fi reconstruit de la zero intr-o etapa separata.
- Daca ai o baza existenta, ruleaza `database/remove_notifications_legacy.sql` pentru curatare fara reset total.

## Rulare locala

1. Creeaza baza de date local.
2. Importa `database/database.sql`.
3. Creeaza fisierul `.env` in radacina proiectului si configureaza variabilele necesare (vezi `.env.example`).
4. Ruleaza serverul local:

```powershell
cd C:\laragon\www\aplicatie_fleet
php -S 127.0.0.1:8000 -t htdocs
```

5. Deschide in browser:

```text
http://127.0.0.1:8000/
```

## Configurare email verificare

- Configureaza SMTP in `.env` folosind `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`.
- `MAIL_CONNECT_TIMEOUT` controleaza timeout-ul de conectare SMTP (secunde).
- Recomandat pentru stabilitate: `MAIL_CONNECT_TIMEOUT=15`, `MAIL_TIMEOUT=45`.
- `MAIL_RETRY_ATTEMPTS` si `MAIL_RETRY_DELAY_MS` permit retry automat la erori temporare de conectare SMTP.
- `AUTH_VERIFY_RESEND_COOLDOWN_SECONDS` controleaza dupa cate secunde utilizatorul poate cere retrimiterea codului.

## Migrare baza existenta (CAMION + Km bord)

Daca ai deja o baza populata, ruleaza:

```text
database/update_vehicle_camion_km.sql
```

## Migrare baza existenta (Dispecer curse)

Pentru activarea modulului nou `Dispecer curse` pe o baza existenta, ruleaza:

```text
database/update_dispecer_curse_module.sql
```

Pentru coloanele noi de cost per km (`cost_km_primar`, `cost_km_distributie`, `cost_km_mixt`) ruleaza si:

```text
database/update_dispecer_curse_cost_km.sql
```

## Seed demo pentru Dispecer curse (20 curse)

Pentru test rapid pe listare, filtre si paginare:

```text
database/seed_dispecer_curse_demo_20.sql
```

## Conturi demo

- Admin
  - Email: `admin@example.com`
  - Parola: `Admin123!`
- Utilizator
  - Email: `user@example.com`
  - Parola: `Admin123!`

## Deploy pe VPS cu subdomeniu

### Varianta Apache

1. Creeaza subdomeniul (ex: `flota.domeniul-tau.ro`).
2. Configureaza VirtualHost catre:

```text
/cale/catre/aplicatie_fleet/htdocs
```

3. Activeaza `mod_rewrite` daca folosesti `.htaccess`.
4. Configureaza PHP 8+.
5. Creeaza baza de date si user MySQL.
6. Importa `database/database.sql`.
7. Actualizeaza `htdocs/config/config.php`.

### Varianta Nginx

1. Creeaza blocul de server pentru subdomeniu.
2. Seteaza `root` catre:

```text
/cale/catre/aplicatie_fleet/htdocs
```

3. Configureaza `index index.php;`
4. Configureaza executia PHP prin PHP-FPM.
5. Ruleaza test de acces pe subdomeniu.

## Observatii

- Previzualizarea inline functioneaza direct pentru PDF si imagini.
- Pentru `.doc` / `.docx`, browserul poate forta descarcarea in loc de preview.
- Starea curenta de implementare si roadmap-ul sunt in `STARE_PROIECT.md`.
