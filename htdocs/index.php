<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
$modules = require __DIR__ . '/config/modules.php';

$modules['documente']['detail_fields']['fisier_original'] = [
    'label' => 'Fisier atasat',
    'type' => 'document_file',
];

$modules['documente']['list_columns']['numar_document']['label'] = 'Serie / numar';
$modules['documente']['detail_fields']['numar_document']['label'] = 'Serie / numar document';
$modules['documente']['form_fields']['numar_document']['label'] = 'Serie / numar document (optional)';
$modules['documente']['form_fields']['numar_document']['required'] = false;
$modules['documente']['form_fields']['numar_document']['placeholder'] = 'Ex: seria politei RCA, seria ITP, numar rovinieta';
$modules['documente']['form_fields']['numar_document']['help'] = 'Completeaza doar daca documentul are o serie sau un numar util pentru identificare.';

$modules['documente']['form_fields']['fisier_upload'] = [
    'label' => 'Fisier document',
    'type' => 'file',
    'required' => false,
    'store' => false,
    'accept' => '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx',
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'],
    'max_size' => 5242880,
    'help' => 'Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.',
    'original_field' => 'fisier_original',
    'stored_field' => 'fisier_stocat',
    'remove_field' => 'sterge_fisier',
    'preview_type' => 'document',
];

$modules['documente_soferi']['detail_fields']['fisier_original'] = [
    'label' => 'Fișier atașat',
    'type' => 'document_file',
];

$modules['documente_soferi']['list_columns']['numar_document']['label'] = 'Serie / număr';
$modules['documente_soferi']['detail_fields']['numar_document']['label'] = 'Serie / număr document';
$modules['documente_soferi']['form_fields']['numar_document']['label'] = 'Serie / număr document (opțional)';
$modules['documente_soferi']['form_fields']['numar_document']['placeholder'] = 'Ex: serie permis, serie atestat, număr aviz';
$modules['documente_soferi']['form_fields']['numar_document']['help'] = 'Completează doar dacă documentul șoferului are serie sau număr util pentru identificare.';

$modules['documente_soferi']['form_fields']['fisier_upload'] = [
    'label' => 'Fișier document șofer',
    'type' => 'file',
    'required' => false,
    'store' => false,
    'accept' => '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx',
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'],
    'max_size' => 5242880,
    'help' => 'Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.',
    'original_field' => 'fisier_original',
    'stored_field' => 'fisier_stocat',
    'remove_field' => 'sterge_fisier',
    'preview_type' => 'document',
];

$modules['mentenanta']['search_fields'] = [
    'v.nr_inmatriculare',
    't.tip_interventie',
    't.atelier',
    't.furnizor_piesa',
    't.observatii',
    't.fisier_original',
];

$modules['mentenanta']['list_columns']['atelier']['label'] = 'Furnizor manopera';
$modules['mentenanta']['list_columns']['furnizor_piesa'] = [
    'label' => 'Furnizor piesa',
];
$modules['mentenanta']['list_columns']['fisier_original'] = [
    'label' => 'Factura',
    'type' => 'document_file',
];

$modules['mentenanta']['detail_fields']['atelier']['label'] = 'Furnizor manopera';
$modules['mentenanta']['detail_fields']['furnizor_piesa'] = [
    'label' => 'Furnizor piesa',
];
$modules['mentenanta']['detail_fields']['fisier_original'] = [
    'label' => 'Factura atasata',
    'type' => 'document_file',
];

$mentenantaDetailFields = $modules['mentenanta']['detail_fields'];
$modules['mentenanta']['detail_fields'] = [
    'vehicul_label' => $mentenantaDetailFields['vehicul_label'],
    'tip_interventie' => $mentenantaDetailFields['tip_interventie'],
    'data_interventie' => $mentenantaDetailFields['data_interventie'],
    'cost' => $mentenantaDetailFields['cost'],
    'atelier' => $mentenantaDetailFields['atelier'],
    'furnizor_piesa' => $mentenantaDetailFields['furnizor_piesa'],
    'fisier_original' => $mentenantaDetailFields['fisier_original'],
    'observatii' => $mentenantaDetailFields['observatii'],
    'created_at' => $mentenantaDetailFields['created_at'],
    'updated_at' => $mentenantaDetailFields['updated_at'],
];

$modules['mentenanta']['form_fields']['atelier']['label'] = 'Furnizor manopera';
$modules['mentenanta']['form_fields']['furnizor_piesa'] = [
    'label' => 'Furnizor piesa',
    'type' => 'text',
    'required' => false,
    'nullable' => true,
    'maxlength' => 120,
];
$modules['mentenanta']['form_fields']['fisier_upload'] = [
    'label' => 'Factura (upload)',
    'type' => 'file',
    'required' => false,
    'store' => false,
    'accept' => '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx',
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'],
    'max_size' => 5242880,
    'help' => 'Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.',
    'original_field' => 'fisier_original',
    'stored_field' => 'fisier_stocat',
    'remove_field' => 'sterge_fisier',
    'preview_type' => 'document',
];

$mentenantaFormFields = $modules['mentenanta']['form_fields'];
$modules['mentenanta']['form_fields'] = [
    'vehicle_id' => $mentenantaFormFields['vehicle_id'],
    'tip_interventie' => $mentenantaFormFields['tip_interventie'],
    'data_interventie' => $mentenantaFormFields['data_interventie'],
    'cost' => $mentenantaFormFields['cost'],
    'atelier' => $mentenantaFormFields['atelier'],
    'furnizor_piesa' => $mentenantaFormFields['furnizor_piesa'],
    'observatii' => $mentenantaFormFields['observatii'],
    'fisier_upload' => $mentenantaFormFields['fisier_upload'],
];

$modules['utilizatori']['list_columns'] = [
    'nume' => ['label' => 'Nume'],
    'email' => ['label' => 'Email'],
    'telefon' => ['label' => 'Telefon'],
    'rol' => ['label' => 'Rol', 'type' => 'role'],
    'status' => ['label' => 'Status', 'type' => 'status'],
    'updated_at' => ['label' => 'Actualizat la', 'type' => 'datetime'],
];

$modules['utilizatori']['detail_fields']['telefon'] = ['label' => 'Telefon'];

$modules['utilizatori']['form_fields']['telefon'] = [
    'label' => 'Telefon',
    'type' => 'text',
    'required' => false,
    'nullable' => true,
    'maxlength' => 20,
    'placeholder' => 'Ex: 0722000000 sau +40722000000',
    'help' => 'Numar de contact utilizator.',
];

$modules['documente']['filters']['stare_expirare'] = [
    'label' => 'Stare expirare',
    'type' => 'select',
    'options' => [
        'expirate' => 'Expirate',
        'expira_7_zile' => 'Expira in 7 zile',
        'expira_30_zile' => 'Expira in 30 zile',
        'valabile' => 'Valabile peste 30 zile',
    ],
    'custom_conditions' => [
        'expirate' => ['sql' => 't.data_expirare < CURDATE()'],
        'expira_7_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'],
        'expira_30_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
        'valabile' => ['sql' => 't.data_expirare > DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
    ],
];

$modules['documente']['filters']['are_fisier'] = [
    'label' => 'Fisier atasat',
    'type' => 'select',
    'options' => [
        'da' => 'Da',
        'nu' => 'Nu',
    ],
    'custom_conditions' => [
        'da' => ['sql' => "COALESCE(t.fisier_stocat, '') <> ''"],
        'nu' => ['sql' => "COALESCE(t.fisier_stocat, '') = ''"],
    ],
];

unset($modules['vehicule']['form_fields']['status'], $modules['soferi']['form_fields']['status']);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/models/BaseModel.php';
require_once __DIR__ . '/models/UserModel.php';
require_once __DIR__ . '/models/LoginEmailCodeModel.php';
require_once __DIR__ . '/models/ModuleModel.php';
require_once __DIR__ . '/models/VehicleCouplingModel.php';
require_once __DIR__ . '/models/TireModel.php';
require_once __DIR__ . '/models/DashboardModel.php';
require_once __DIR__ . '/models/DocumentModel.php';
require_once __DIR__ . '/models/DispecerCurseModel.php';
require_once __DIR__ . '/models/ProgramareConcediiModel.php';

require_once __DIR__ . '/services/EntityStatusService.php';
require_once __DIR__ . '/services/EmailService.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/DashboardAnaliticController.php';
require_once __DIR__ . '/controllers/ModuleController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/DispecerCurseController.php';
require_once __DIR__ . '/controllers/CentralizatorFacturareController.php';
require_once __DIR__ . '/controllers/ProgramareConcediiController.php';

$db = get_pdo();

header('Content-Type: text/html; charset=UTF-8');

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');
$action = $_GET['action'] ?? 'index';

try {
    if (!is_logged_in() && !in_array($page, ['login'], true)) {
        flash_set('warning', "Te rug\u{0103}m s\u{0103} te autentifici pentru a continua.");
        redirect(url('index.php?page=login'));
    }

    if (is_logged_in() && $page === 'login') {
        redirect(url('index.php?page=dashboard'));
    }

    switch ($page) {
        case 'login':
            $authController = new AuthController($db);
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if ($action === 'verify_code') {
                    $authController->verificaCod();
                } elseif ($action === 'resend_code') {
                    $authController->retrimiteCod();
                } else {
                    $authController->autentificare();
                }
            } else {
                if ($action === 'verify') {
                    $authController->verifyCodePage();
                } else {
                    $authController->index();
                }
            }
            break;

        case 'logout':
            $authController = new AuthController($db);
            $authController->logout();
            break;

        case 'dashboard':
            require_auth();
            (new DashboardController($db))->index();
            break;

        case 'dashboard_analitic':
            require_auth();
            (new DashboardAnaliticController($db))->index();
            break;

        case 'dashboard_analytic_data':
            require_auth();
            (new DashboardAnaliticController($db))->data();
            break;

        case 'profil':
            require_auth();
            $profileController = new ProfileController($db);
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'actualizeaza') {
                $profileController->actualizeaza();
            } else {
                $profileController->index();
            }
            break;

        case 'vehicule':
        case 'soferi':
        case 'alimentari':
        case 'mentenanta':
        case 'documente':
        case 'documente_soferi':
        case 'utilizatori':
            require_auth();
            (new ModuleController($db, $modules))->handle($page, $action);
            break;

        case 'dispecer_curse':
            require_auth();
            (new DispecerCurseController($db))->handle($action);
            break;

        case 'centralizator_facturare':
            require_auth();
            (new CentralizatorFacturareController($db))->handle($action);
            break;

        case 'programare_concedii':
            require_auth();
            (new ProgramareConcediiController($db))->handle($action);
            break;

        default:
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => "Pagina nu exist\u{0103}",
                'currentPage' => '',
            ]);
            break;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    error_log($exception->getMessage());
    render('errors/500.php', [
        'pageTitle' => "Eroare intern\u{0103}",
        'currentPage' => '',
        'errorMessage' => APP_DEBUG ? $exception->getMessage() : null,
    ]);
}

