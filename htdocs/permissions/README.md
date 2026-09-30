# Registrul de permisiuni (ACL)

**Regula proiectului:** orice pagină nouă controlată prin drepturi se înregistrează
aici, iar orice acțiune protejată nouă primește o cheie stabilă aici.
Pagina **Drepturi de acces** nu se editează niciodată ca să afișeze un modul
sau o acțiune nouă: citește totul din acest registru.

```
htdocs/permissions/
  sections.php          secțiunile (Operațional, Vehicule, …): etichetă, iconiță, ordine
  modules/<cheie>.php   câte un fișier per modul/pagină (îl descoperă automat PermissionRegistry)
```

Clasa: `htdocs/services/PermissionRegistry.php`. Autorizarea: `htdocs/includes/access.php`
(`can()`, `user_can()`, garda din router `require_route_access()`).

## Pagină nouă

Creezi `permissions/modules/contracte_clienti.php`:

```php
<?php
declare(strict_types=1);

return [
    'key'         => 'contracte_clienti',          // = ?page=contracte_clienti — STABIL
    'label'       => 'Contracte clienți',
    'description' => 'Contractele cadru cu beneficiarii',
    'section'     => 'operational',                // cheie din sections.php (una nouă apare automat)
    'icon'        => 'bi-file-earmark-text',       // Bootstrap Icons
    'order'       => 300,                          // ordinea în secțiune
    'scope'       => 'all',                        // acces implicit al rolurilor: all | accountancy | admin
    'routes'      => ['contracte_clienti_export'], // (opțional) alte rute ?page=… guvernate de aceeași cheie
    'groups'      => [                             // (opțional) ordinea și numele coloanelor de acțiuni
        'operare'  => 'Operare',
        'stergere' => 'Ștergere',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare'],    // accesul la pagină (comutatorul mare)
        'create' => ['label' => 'Adăugare contract', 'group' => 'operare'],
        'edit'   => ['label' => 'Editare contract',  'group' => 'operare'],
        'delete' => ['label' => 'Ștergere contract', 'group' => 'stergere', 'sensitive' => true],
        'export' => ['label' => 'Export CSV'],     // fără 'group' -> coloana „Acțiuni”
    ],
    'endpoints'   => [                             // ?action=… -> acțiunea cerută (verificată în router)
        'store'  => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        'export' => 'export',
    ],
];
```

La următoarea încărcare, „Contracte clienți” apare în Drepturi de acces, cu toate acțiunile.
Nu trebuie modificat nimic altundeva pentru ACL (doar ruta din `index.php` și meniul, ca pentru orice pagină).

## Acțiune nouă

Adaugi o intrare în `actions` (și, dacă are un endpoint propriu, în `endpoints`).
În cod verifici cu `can('contracte_clienti', 'export')`.

## Flag-uri pe acțiune

| flag | efect |
|---|---|
| `admin_only` | **Doar administrator.** Nu se poate acorda nimănui, nici prin POST modificat; `can()` întoarce `false` pentru non-admin. Folosește-l când controllerul cere oricum `require_admin_or_403()`. Pus la nivel de modul, blochează toată pagina. |
| `default_admin` | Un utilizator nepersonalizat nu o are (doar adminul), dar se poate acorda explicit. |
| `default_accountancy` | Implicit doar pentru admin / contabilitate; se poate acorda explicit. |
| `sensitive` | Doar evidențiere în UI. |

## Ce e stabil și ce nu

- **Stabil:** cheia modulului și cheia acțiunii. Împreună formează cheia permisiunii
  `modul.actiune`, salvată în `access_permissions`. Nu le redenumi.
- **Liber de schimbat:** `label`, `description`, `section`, `group`, `icon`, `order`.
  Drepturile salvate nu sunt afectate.

## Permisiuni scoase din cod

Nu șterge nimic manual. La sincronizare (automat la deschiderea paginii Drepturi de acces,
sau `php scripts/sync_permissions.php`) permisiunea devine `is_active = 0` în
`access_permission_catalog`. Drepturile acordate rămân în `access_permissions` ca istoric,
nu mai sunt afișate/editate și nu sunt atinse la salvare. Dacă readaugi cheia, se reactivează.

Validare rapidă a registrului: `php scripts/sync_permissions.php --check`.

## Moștenire din rol

- Un utilizator **nepersonalizat** moștenește drepturile implicite ale rolului
  (`scope` + `default_*`), calculate de `access_role_defaults()`.
- La prima salvare devine **personalizat**: contează strict ce e bifat.
  UI-ul marchează „Personalizat” / „Retras față de rol” diferențele față de rol.
- **Resetează la rol** șterge personalizarea (rolul și șabloanele nu se schimbă).
- O acțiune cere și accesul la pagină: cu pagina oprită, acțiunile rămân salvate dar inactive.
