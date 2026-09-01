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

$modules['documente']['title'] = 'Documente Vehicule';
$modules['documente']['singular'] = 'document vehicul';
$modules['documente']['nav_parent'] = 'vehicule';

$modules['documente_soferi']['title'] = 'Documente Soferi';
$modules['documente_soferi']['singular'] = 'document sofer';
$modules['documente_soferi']['select'] = 't.*, DATEDIFF(t.data_expirare, CURDATE()) AS zile_expirare, s.nume AS sofer_label, s.telefon AS sofer_telefon, v.nr_inmatriculare AS vehicul_label';
$modules['documente_soferi']['search_fields'] = ['s.nume', 's.telefon', 'v.nr_inmatriculare', 't.tip_document', 't.numar_document', 't.observatii', 't.fisier_original'];
$modules['documente_soferi']['list_columns'] = [
    'sofer_label' => ['label' => 'Sofer'],
    'vehicul_label' => ['label' => 'Vehicul alocat'],
    'tip_document' => ['label' => 'Tip document'],
    'numar_document' => ['label' => 'Serie / numar'],
    'fisier_original' => ['label' => 'Fisier', 'type' => 'document_file'],
    'data_expirare' => ['label' => 'Data expirare', 'type' => 'expiry'],
    'zile_expirare' => ['label' => 'Zile expirare', 'type' => 'integer'],
    'updated_at' => ['label' => 'Actualizat la', 'type' => 'datetime'],
];
$modules['documente_soferi']['detail_fields']['sofer_label']['label'] = 'Sofer';
$modules['documente_soferi']['detail_fields']['sofer_telefon']['label'] = 'Telefon sofer';
$modules['documente_soferi']['detail_fields']['numar_document']['label'] = 'Serie / numar document';
$modules['documente_soferi']['detail_fields']['fisier_original']['label'] = 'Fisier atasat';
$modules['documente_soferi']['form_fields']['driver_id']['label'] = 'Sofer';
$modules['documente_soferi']['form_fields']['numar_document']['label'] = 'Serie / numar document (optional)';
$modules['documente_soferi']['form_fields']['numar_document']['placeholder'] = 'Ex: serie permis, serie atestat, numar aviz';
$modules['documente_soferi']['form_fields']['numar_document']['help'] = 'Completeaza doar daca documentul soferului are serie sau numar util pentru identificare.';
$modules['documente_soferi']['form_fields']['fisier_upload']['label'] = 'Fisier document sofer';
$modules['documente_soferi']['filters'] = [
    'driver_id' => [
        'label' => 'Sofer',
        'type' => 'select',
        'column' => 't.driver_id',
        'operator' => '=',
        'source' => [
            'table' => 'soferi',
            'value' => 'id',
            'label' => "CONCAT(nume, ' - ', telefon)",
            'order' => 'nume ASC',
        ],
    ],
    'vehicle_id' => [
        'label' => 'Vehicul alocat',
        'type' => 'select',
        'column' => 'v.id',
        'operator' => '=',
        'source' => [
            'table' => 'vehicule',
            'value' => 'id',
            'label' => "CONCAT(nr_inmatriculare, ' - ', marca, ' ', model)",
            'order' => 'nr_inmatriculare ASC',
        ],
    ],
    'tip_document' => [
        'label' => 'Tip document',
        'type' => 'select',
        'column' => 't.tip_document',
        'operator' => '=',
        'source' => [
            'table' => "(SELECT DISTINCT tip_document FROM documente_soferi WHERE COALESCE(TRIM(tip_document), '') <> '') doc_tipuri",
            'value' => 'tip_document',
            'label' => 'tip_document',
            'order' => 'tip_document ASC',
        ],
    ],
    'data_start' => ['label' => 'Expira de la', 'type' => 'date', 'column' => 't.data_expirare', 'operator' => '>='],
    'data_end' => ['label' => 'Expira pana la', 'type' => 'date', 'column' => 't.data_expirare', 'operator' => '<='],
    'stare_expirare' => [
        'label' => 'Stare expirare',
        'type' => 'select',
        'options' => [
            'expirate' => 'Expirate',
            'expira_7_zile' => 'Expira in 7 zile',
            'expira_30_zile' => 'Expira in 30 zile',
            'valabile' => 'Valabile peste 30 zile',
            'fara_expirare' => 'Fara expirare',
        ],
        'custom_conditions' => [
            'expirate' => ['sql' => 't.data_expirare < CURDATE()'],
            'expira_7_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'],
            'expira_30_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
            'valabile' => ['sql' => 't.data_expirare > DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
            'fara_expirare' => ['sql' => 't.data_expirare IS NULL'],
        ],
    ],
    'are_fisier' => [
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
    ],
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
        'fara_expirare' => 'Fara expirare',
    ],
    'custom_conditions' => [
        'expirate' => ['sql' => 't.data_expirare < CURDATE()'],
        'expira_7_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'],
        'expira_30_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
        'valabile' => ['sql' => 't.data_expirare > DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
        'fara_expirare' => ['sql' => 't.data_expirare IS NULL'],
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
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/profile_helpers.php';

require_once __DIR__ . '/models/BaseModel.php';
require_once __DIR__ . '/models/AccessRightsModel.php';
require_once __DIR__ . '/models/UserModel.php';
require_once __DIR__ . '/models/LoginEmailCodeModel.php';
require_once __DIR__ . '/models/PasskeyModel.php';
require_once __DIR__ . '/models/NotificationDeliveryModel.php';
require_once __DIR__ . '/models/ModuleModel.php';
require_once __DIR__ . '/models/VehicleCouplingModel.php';
require_once __DIR__ . '/models/TireModel.php';
require_once __DIR__ . '/models/DashboardModel.php';
require_once __DIR__ . '/models/DocumentModel.php';
require_once __DIR__ . '/models/InactiveResourceApprovalModel.php';
require_once __DIR__ . '/models/ApprovalEmailActionModel.php';
require_once __DIR__ . '/models/VehicleEquipmentInventoryModel.php';
require_once __DIR__ . '/models/VehicleAuthorizationModel.php';
require_once __DIR__ . '/models/DispecerCurseModel.php';
require_once __DIR__ . '/models/ProgramareConcediiModel.php';
require_once __DIR__ . '/models/NotificationRuleModel.php';
require_once __DIR__ . '/models/StaffAccountancyModel.php';
require_once __DIR__ . '/models/ExpenseModel.php';
require_once __DIR__ . '/models/MaintenanceModel.php';
require_once __DIR__ . '/models/TechnicalHealthModel.php';
require_once __DIR__ . '/models/DriverActivityHistoryModel.php';
require_once __DIR__ . '/models/FuelModel.php';
require_once __DIR__ . '/models/UserActivityModel.php';
require_once __DIR__ . '/models/LeasingSchedulerModel.php';
require_once __DIR__ . '/models/TransportTariffModel.php';
require_once __DIR__ . '/models/OperationalCostModel.php';

require_once __DIR__ . '/services/EntityStatusService.php';
require_once __DIR__ . '/services/InactiveResourceStatusService.php';
require_once __DIR__ . '/services/CentralizatorFacturareService.php';
require_once __DIR__ . '/services/EmailService.php';
require_once __DIR__ . '/services/CardOilApiClient.php';
require_once __DIR__ . '/services/SasFleetClient.php';
require_once __DIR__ . '/services/FleetLivePositionService.php';
require_once __DIR__ . '/services/SasTripPrefillService.php';
require_once __DIR__ . '/services/SasDashboardService.php';
require_once __DIR__ . '/services/WebAuthnService.php';
require_once __DIR__ . '/services/UserAvatarService.php';
require_once __DIR__ . '/services/FuelPriceIndexService.php';
require_once __DIR__ . '/services/TransportPricingService.php';
require_once __DIR__ . '/services/TariffReviewService.php';
require_once __DIR__ . '/services/TariffRepriceService.php';
require_once __DIR__ . '/services/CostNormalizationService.php';
require_once __DIR__ . '/services/CostBreakEvenService.php';
require_once __DIR__ . '/services/OperationalCostService.php';
require_once __DIR__ . '/services/OcrSpaceService.php';
require_once __DIR__ . '/services/OcrInvoiceHeuristics.php';
require_once __DIR__ . '/services/OcrPartsLineExtractor.php';
require_once __DIR__ . '/models/OcrPartsModel.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/DashboardAnaliticController.php';
require_once __DIR__ . '/controllers/InactiveResourceApprovalController.php';
require_once __DIR__ . '/controllers/EmailApprovalController.php';
require_once __DIR__ . '/controllers/ModuleController.php';
require_once __DIR__ . '/controllers/VehicleEquipmentInventoryController.php';
require_once __DIR__ . '/controllers/VehicleAuthorizationController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/DispecerCurseController.php';
require_once __DIR__ . '/controllers/FleetMapController.php';
require_once __DIR__ . '/controllers/DispecerSasSandboxController.php';
require_once __DIR__ . '/controllers/SasDashboardSandboxController.php';
require_once __DIR__ . '/controllers/CourseExpenseHistoryController.php';
require_once __DIR__ . '/controllers/CentralizatorFacturareController.php';
require_once __DIR__ . '/controllers/ProgramareConcediiController.php';
require_once __DIR__ . '/controllers/NotificationRuleController.php';
require_once __DIR__ . '/controllers/StaffAccountancyController.php';
require_once __DIR__ . '/controllers/ExpenseController.php';
require_once __DIR__ . '/controllers/MaintenanceController.php';
require_once __DIR__ . '/controllers/TechnicalHealthController.php';
require_once __DIR__ . '/controllers/DriverActivityHistoryController.php';
require_once __DIR__ . '/controllers/FuelController.php';
require_once __DIR__ . '/controllers/AccessRightsController.php';
require_once __DIR__ . '/controllers/UserActivityController.php';
require_once __DIR__ . '/controllers/LeasingSchedulerController.php';
require_once __DIR__ . '/controllers/TransportTariffController.php';
require_once __DIR__ . '/controllers/OperationalCostController.php';
require_once __DIR__ . '/controllers/DevOcrTestController.php';
require_once __DIR__ . '/controllers/OcrPartsController.php';

$db = get_pdo();

header('Content-Type: text/html; charset=UTF-8');

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');
$action = $_GET['action'] ?? 'index';
$modulePage = $page;

if (in_array($page, ['vehicule_usoare', 'vehicule_grele'], true) && isset($modules['vehicule'])) {
    $vehicleBaseConditions = $modules['vehicule']['base_conditions'] ?? [];
    if (is_string($vehicleBaseConditions)) {
        $vehicleBaseConditions = [$vehicleBaseConditions];
    }
    if (!is_array($vehicleBaseConditions)) {
        $vehicleBaseConditions = [];
    }

    $lightVehicleTypes = ['autovehicul' => 'Autoturism', 'autoutilitara' => 'Autoutilitara'];
    $heavyVehicleTypes = [
        'cap_tractor' => 'Cap tractor',
        'semiremorca_primar' => 'Semi-remorca primar',
        'semiremorca_distributie' => 'Semi-remorca distributie',
        'camion' => 'Camion',
    ];

    if ($page === 'vehicule_usoare') {
        $modules['vehicule']['title'] = 'Vehicule Usoare';
        $modules['vehicule']['base_conditions'] = array_merge($vehicleBaseConditions, [
            "t.tip_vehicul IN ('autovehicul', 'autoutilitara')",
        ]);
        $modules['vehicule']['filters']['tip_vehicul']['options'] = $lightVehicleTypes;
        $modules['vehicule']['form_fields']['tip_vehicul']['options'] = $lightVehicleTypes;
        $modules['vehicule']['form_fields']['tip_vehicul']['default'] = 'autovehicul';
    } else {
        $modules['vehicule']['title'] = 'Vehicule Grele';
        $modules['vehicule']['base_conditions'] = array_merge($vehicleBaseConditions, [
            "t.tip_vehicul NOT IN ('autovehicul', 'autoutilitara')",
        ]);
        $modules['vehicule']['filters']['tip_vehicul']['options'] = $heavyVehicleTypes;
        $modules['vehicule']['form_fields']['tip_vehicul']['options'] = $heavyVehicleTypes;
        $modules['vehicule']['form_fields']['tip_vehicul']['default'] = 'cap_tractor';
    }

    $modules['vehicule']['route_page'] = $page;
    $modules['vehicule']['nav_parent'] = $page;
    $modulePage = 'vehicule';
}

try {
    // Ruta publica: decizia se ia dintr-un link primit pe email, fara autentificare.
    // Token-ul din URL tine loc de sesiune, deci trece inaintea garzii de login.
    if ($page === 'aprobare_email') {
        (new EmailApprovalController($db))->handle($action);
        exit;
    }

    if (!is_logged_in() && $page !== 'login') {
        flash_set('warning', "Te rug\u{0103}m s\u{0103} te autentifici pentru a continua.");
        redirect(url('index.php?page=login'));
    }

    if (is_logged_in() && $page === 'login') {
        redirect(url('index.php?page=dashboard'));
    }

    // Garda centrala de acces per-pagina (drepturi de acces).
    // Adminul trece mereu; utilizatorii neconfigurati pastreaza accesul implicit al rolului.
    if (is_logged_in() && function_exists('require_route_access')) {
        require_route_access($page);
    }

    switch ($page) {
        case 'login':
            $authController = new AuthController($db);
            if ($action === 'passkey_options') {
                $authController->passkeyLoginOptions();
                break;
            }
            if ($action === 'passkey_verify') {
                $authController->passkeyLoginVerify();
                break;
            }
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

        case 'inactive_approvals':
            require_auth();
            (new InactiveResourceApprovalController($db))->handle($action);
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
            if ($action === 'passkey_options') {
                $profileController->passkeyRegisterOptions();
            } elseif ($action === 'passkey_register') {
                $profileController->passkeyRegisterVerify();
            } elseif ($action === 'passkey_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $profileController->passkeyDelete();
            } elseif ($action === 'avatar_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $profileController->avatarUpload();
            } elseif ($action === 'avatar_emoji' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $profileController->avatarEmoji();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'actualizeaza') {
                $profileController->actualizeaza();
            } else {
                $profileController->index();
            }
            break;

        case 'vehicule':
        case 'vehicule_usoare':
        case 'vehicule_grele':
        case 'soferi':
        case 'documente':
        case 'documente_soferi':
        case 'configurare_costuri_documente_vehicule_override':
        case 'configurare_costuri_documente_soferi':
        case 'utilizatori':
            require_auth();
            (new ModuleController($db, $modules))->handle($modulePage, $action);
            break;

        case 'istoric_activitati_sofer':
            require_auth();
            (new DriverActivityHistoryController($db))->handle($action);
            break;

        case 'stare_tehnica':
            require_auth();
            (new TechnicalHealthController($db))->handle($action);
            break;

        case 'mentenanta':
            require_auth();
            (new MaintenanceController($db, $modules))->handle($action);
            break;

        case 'inventar_dotari_vehicule':
            require_auth();
            (new VehicleEquipmentInventoryController($db))->handle($action);
            break;

        case 'autorizatii_vehicule':
            require_auth();
            (new VehicleAuthorizationController($db))->handle($action);
            break;

        case 'scadentar_leasing':
            require_auth();
            (new LeasingSchedulerController($db))->handle($action);
            break;

        case 'dispecer_curse':
            require_auth();
            (new DispecerCurseController($db))->handle($action);
            break;

        case 'harta_flota':
            require_auth();
            (new FleetMapController($db))->handle($action);
            break;

        case 'dispecer_sandbox':
            require_auth();
            (new DispecerSasSandboxController($db))->handle($action);
            break;

        case 'sas_dashboard_sandbox':
            require_auth();
            (new SasDashboardSandboxController($db))->handle($action);
            break;

        case 'tarife_transport':
            require_auth();
            (new TransportTariffController($db))->handle($action);
            break;

        case 'carburanti':
            require_auth();
            (new FuelController($db))->handle($action);
            break;

        case 'istoric_cheltuieli_curse':
            require_auth();
            (new CourseExpenseHistoryController($db))->handle($action);
            break;

        case 'istoric_activitate':
            require_auth();
            (new CentralizatorFacturareController($db, 'istoric_activitate'))->handle($action);
            break;

        case 'centralizator_facturare':
            require_auth();
            (new CentralizatorFacturareController($db, 'centralizator_facturare'))->handle($action);
            break;

        case 'programare_concedii':
            require_auth();
            (new ProgramareConcediiController($db))->handle($action);
            break;

        case 'contabilitate_personal':
            require_auth();
            (new StaffAccountancyController($db))->handle($action);
            break;

        case 'fosti_angajati':
            require_auth();
            (new StaffAccountancyController($db))->handleFormerEmployees($action);
            break;

        case 'cheltuieli':
            require_auth();
            (new ExpenseController($db))->handle($action);
            break;

        // Rutele legacy (Cheltuieli Birou / Administrative) redirectioneaza
        // catre pagina unificata Cheltuieli.
        case 'cheltuieli_birou':
        case 'cheltuieli_administrative':
            require_auth();
            redirect(build_query_url(['page' => 'cheltuieli']));
            break;

        case 'cost_operational':
            require_auth();
            (new OperationalCostController($db))->handle($action);
            break;

        case 'notificari':
            require_auth();
            (new NotificationRuleController($db))->handle($action);
            break;

        case 'drepturi_acces':
            require_auth();
            (new AccessRightsController($db))->handle($action);
            break;

        case 'activitate_utilizatori':
            require_auth();
            (new UserActivityController($db))->handle($action);
            break;

        // Sandbox experimental OCR (doar admin, nu apare in meniu, nu scrie in DB).
        case 'dev_ocr_test':
            require_auth();
            if (!is_admin()) {
                access_deny_403();
            }
            (new DevOcrTestController($db))->handle($action);
            break;

        // Tracker experimental piese din facturi OCR (doar admin, tabele separate).
        case 'ocr_piese':
            require_auth();
            if (!is_admin()) {
                access_deny_403();
            }
            (new OcrPartsController($db))->handle($action);
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

