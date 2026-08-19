<?php
$user = function_exists('current_user') ? current_user() : null;
$alerts = flash_messages();
$showSidebar = !empty($showSidebar);
$currentAction = (string) ($_GET['action'] ?? 'index');
$currentRoutePage = (string) ($_GET['page'] ?? ($currentPage ?? ''));
$styleVersion = (string) @filemtime(BASE_PATH . '/assets/css/style.css');
// Meniul lateral porneste ascuns (off-canvas) si apare cand mouse-ul ajunge la
// marginea din stanga. Butonul din bara de sus il poate fixa deschis.
$bodyClasses = ['sidebar-collapsed'];
$showGlobalApprovalDrawer = false;
$globalApprovalDrawerMode = 'user';
$canReviewInactiveApprovals = false;
$globalApprovalSummary = [
    'counts' => ['vehicle' => 0, 'driver' => 0, 'repair' => 0],
    'total' => 0,
    'vehicles' => [],
    'drivers' => [],
    'repairs' => [],
];
if (
    $showSidebar
    && function_exists('is_logged_in')
    && is_logged_in()
    && class_exists('InactiveResourceApprovalModel')
    && function_exists('get_pdo')
) {
    $showGlobalApprovalDrawer = true;
    $canReviewInactiveApprovals = (function_exists('can') && can('inactive_approvals', 'review'))
        || (!function_exists('can') && function_exists('is_admin') && is_admin());
    $globalApprovalDrawerMode = $canReviewInactiveApprovals ? 'admin' : 'user';

    try {
        $approvalModel = new InactiveResourceApprovalModel(get_pdo());
        if ($canReviewInactiveApprovals) {
            $globalApprovalSummary = $approvalModel->getPendingSummary(5);
        } else {
            $currentUserId = (int) ($user['id'] ?? 0);
            $globalApprovalSummary = $approvalModel->getRequesterSummary($currentUserId, 5);
        }
    } catch (Throwable $exception) {
        error_log('[layout][inactive_approvals] ' . $exception->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . $styleVersion)) ?>">
</head>
<body class="<?= e(implode(' ', $bodyClasses)) ?>">
<?php if ($showSidebar && is_logged_in()): ?>
    <div class="app-shell">
        <div class="fleet-sidebar-edge" data-sidebar-edge aria-hidden="true"></div>
        <aside class="sidebar p-3">
            <div class="sidebar-header">
                <div class="sidebar-brand mb-3">
                    <div class="fw-bold fs-5">Fleet Management</div>
                    <div class="text-muted small">MVP trial</div>
                </div>

                <div class="sidebar-search" data-sidebar-search>
                    <label class="visually-hidden" for="fleetSidebarSearch">Cauta pagina</label>
                    <div class="sidebar-search-control">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            class="form-control"
                            id="fleetSidebarSearch"
                            type="search"
                            autocomplete="off"
                            placeholder="Cauta pagina..."
                            data-sidebar-search-input
                            aria-controls="fleetSidebarNav"
                        >
                        <button class="sidebar-search-clear" type="button" aria-label="Sterge cautarea" data-sidebar-search-clear hidden>
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="sidebar-search-empty" data-sidebar-search-empty hidden>Nu am gasit nicio pagina.</div>
                </div>
            </div>

            <nav class="nav flex-column gap-1" id="fleetSidebarNav" data-sidebar-nav>
<?php $can = static fn(string $k, string $a = 'view'): bool => !function_exists('can') || can($k, $a); ?>
                <?php if ($can('dashboard')): ?><a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>"><i class="bi bi-house-door" aria-hidden="true"></i><span>Tablou de bord</span></a><?php endif; ?>
                <?php if ($can('dashboard_analitic')): ?><a class="nav-link <?= $currentPage === 'dashboard_analitic' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dashboard_analitic'])) ?>"><i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Dashboard Analitic</span></a><?php endif; ?>
                <?php if ($can('dispecer_curse')): ?><a class="nav-link <?= $currentPage === 'dispecer_curse' && !in_array($currentAction, ['refacturari', 'curse_sterse'], true) ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>"><i class="bi bi-truck" aria-hidden="true"></i><span>Dispecer curse</span></a><?php endif; ?>
                <?php if ($can('dispecer_curse', 'deleted_view')): ?>
                    <a class="nav-link <?= $currentPage === 'dispecer_curse' && $currentAction === 'curse_sterse' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse'])) ?>"><i class="bi bi-trash3" aria-hidden="true"></i><span>Curse șterse</span></a>
                <?php endif; ?>
                <?php if ($can('harta_flota')): ?><a class="nav-link <?= $currentPage === 'harta_flota' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'harta_flota'])) ?>"><i class="bi bi-map" aria-hidden="true"></i><span>Harta Flota</span></a><?php endif; ?>
                <?php if ($can('carburanti')): ?><a class="nav-link <?= $currentPage === 'carburanti' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'carburanti'])) ?>"><i class="bi bi-fuel-pump" aria-hidden="true"></i><span>Carburan&#539;i</span></a><?php endif; ?>
                <?php if ($can('istoric_cheltuieli_curse')): ?><a class="nav-link <?= $currentPage === 'istoric_cheltuieli_curse' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse'])) ?>"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>Istoric cheltuieli curse</span></a><?php endif; ?>
                <?php if ($currentPage !== 'istoric_cheltuieli_curse' && $can('dispecer_curse', 'refacturari_view')): ?>
                    <a class="nav-link <?= $currentPage === 'dispecer_curse' && $currentAction === 'refacturari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari'])) ?>"><i class="bi bi-receipt" aria-hidden="true"></i><span>Refacturari curse</span></a>
                <?php endif; ?>
                <?php if ($can('tarife_transport')): ?><a class="nav-link <?= $currentPage === 'tarife_transport' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'tarife_transport'])) ?>"><i class="bi bi-tags" aria-hidden="true"></i><span>Administrare tarife</span></a><?php endif; ?>
                <?php if ($can('centralizator_facturare')): ?><a class="nav-link <?= $currentPage === 'centralizator_facturare' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'centralizator_facturare'])) ?>"><i class="bi bi-calendar-range" aria-hidden="true"></i><span>Centralizator Facturare</span></a><?php endif; ?>
                <?php if ($can('centralizator_facturare')): ?><a class="nav-link <?= $currentPage === 'istoric_activitate' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'istoric_activitate'])) ?>"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Istoric activitate</span></a><?php endif; ?>
                <?php if ($can('programare_concedii')): ?><a class="nav-link <?= $currentPage === 'programare_concedii' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'programare_concedii'])) ?>"><i class="bi bi-calendar2-week" aria-hidden="true"></i><span>Programare concedii</span></a><?php endif; ?>
                <?php
                $isVehicleNavGroup = in_array($currentRoutePage, ['vehicule', 'vehicule_usoare', 'vehicule_grele', 'documente', 'inventar_dotari_vehicule', 'stare_tehnica'], true)
                    || in_array($currentPage, ['vehicule', 'vehicule_usoare', 'vehicule_grele'], true);
                $vehShow = $can('vehicule_usoare') || $can('vehicule_grele') || $can('documente') || $can('inventar_dotari_vehicule') || $can('stare_tehnica');
                ?>
                <?php if ($vehShow): ?>
                <div class="sidebar-nav-group">
                    <button
                        class="nav-link sidebar-parent-link <?= $isVehicleNavGroup ? 'active' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#vehiclesSidebarMenu"
                        aria-expanded="<?= $isVehicleNavGroup ? 'true' : 'false' ?>"
                        aria-controls="vehiclesSidebarMenu"
                    >
                        <i class="bi bi-car-front" aria-hidden="true"></i>
                        <span>Vehicule</span>
                        <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="collapse <?= $isVehicleNavGroup ? 'show' : '' ?>" id="vehiclesSidebarMenu">
                        <div class="sidebar-submenu">
                            <?php if ($can('vehicule_usoare')): ?><a class="nav-link <?= $currentRoutePage === 'vehicule_usoare' || $currentPage === 'vehicule_usoare' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'vehicule_usoare'])) ?>">Vehicule Usoare</a><?php endif; ?>
                            <?php if ($can('vehicule_grele')): ?><a class="nav-link <?= $currentRoutePage === 'vehicule_grele' || $currentPage === 'vehicule_grele' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'vehicule_grele'])) ?>">Vehicule Grele</a><?php endif; ?>
                            <?php if ($can('documente')): ?><a class="nav-link <?= $currentRoutePage === 'documente' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente'])) ?>">Documente Vehicule</a><?php endif; ?>
                            <?php if ($can('inventar_dotari_vehicule')): ?><a class="nav-link <?= $currentRoutePage === 'inventar_dotari_vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">Inventar Dotari</a><?php endif; ?>
                            <?php if ($can('stare_tehnica')): ?><a class="nav-link <?= $currentPage === 'stare_tehnica' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'stare_tehnica'])) ?>">Stare tehnic&#259;</a><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($can('autorizatii_vehicule')): ?><a class="nav-link <?= $currentPage === 'autorizatii_vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'autorizatii_vehicule'])) ?>"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Autorizații</span></a><?php endif; ?>
                <?php
                $isLeasingNavGroup = $currentPage === 'scadentar_leasing';
                $leasingShow = $can('scadentar_leasing');
                ?>
                <?php if ($leasingShow): ?>
                <div class="sidebar-nav-group">
                    <button
                        class="nav-link sidebar-parent-link <?= $isLeasingNavGroup ? 'active' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#leasingSidebarMenu"
                        aria-expanded="<?= $isLeasingNavGroup ? 'true' : 'false' ?>"
                        aria-controls="leasingSidebarMenu"
                    >
                        <i class="bi bi-calendar-check" aria-hidden="true"></i>
                        <span>Leasing</span>
                        <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="collapse <?= $isLeasingNavGroup ? 'show' : '' ?>" id="leasingSidebarMenu">
                        <div class="sidebar-submenu">
                            <a class="nav-link <?= $currentPage === 'scadentar_leasing' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'scadentar_leasing'])) ?>">Scaden&#539;ar Leasing</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php
                $isDriverNavGroup = in_array($currentRoutePage, ['soferi', 'documente_soferi', 'istoric_activitati_sofer'], true) || $currentPage === 'soferi';
                $drvShow = $can('soferi') || $can('documente_soferi') || $can('istoric_activitati_sofer');
                ?>
                <?php if ($drvShow): ?>
                <div class="sidebar-nav-group">
                    <button
                        class="nav-link sidebar-parent-link <?= $isDriverNavGroup ? 'active' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#driversSidebarMenu"
                        aria-expanded="<?= $isDriverNavGroup ? 'true' : 'false' ?>"
                        aria-controls="driversSidebarMenu"
                    >
                        <i class="bi bi-person-vcard" aria-hidden="true"></i>
                        <span>&#536;oferi</span>
                        <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="collapse <?= $isDriverNavGroup ? 'show' : '' ?>" id="driversSidebarMenu">
                        <div class="sidebar-submenu">
                            <?php if ($can('soferi')): ?><a class="nav-link <?= $currentRoutePage === 'soferi' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'soferi'])) ?>">Lista soferi</a><?php endif; ?>
                            <?php if ($can('documente_soferi')): ?><a class="nav-link <?= $currentRoutePage === 'documente_soferi' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente_soferi'])) ?>">Documente Soferi</a><?php endif; ?>
                            <?php if ($can('istoric_activitati_sofer')): ?><a class="nav-link <?= $currentRoutePage === 'istoric_activitati_sofer' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'istoric_activitati_sofer'])) ?>">Istoric Activitati Soferi</a><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($can('contabilitate_personal')): ?><a class="nav-link <?= $currentPage === 'contabilitate_personal' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'contabilitate_personal'])) ?>"><i class="bi bi-person-badge" aria-hidden="true"></i><span>Contabilitate Personal</span></a><?php endif; ?>
                <?php if ($can('cheltuieli_birou')): ?><a class="nav-link <?= $currentPage === 'cheltuieli_birou' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'cheltuieli_birou'])) ?>"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Cheltuieli Birou</span></a><?php endif; ?>
                <?php if ($can('cheltuieli_administrative')): ?><a class="nav-link <?= $currentPage === 'cheltuieli_administrative' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'cheltuieli_administrative'])) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>Cheltuieli Administrative</span></a><?php endif; ?>
                <?php
                $isTireModule = $currentPage === 'mentenanta' && in_array($currentAction, ['tire_stock', 'axis_config'], true);
                $isMaintenanceModule = $currentPage === 'mentenanta';
                $maintenanceAction = $currentAction === 'index' ? 'overview' : $currentAction;
                ?>
                <?php if ($can('mentenanta')): ?>
                <div class="sidebar-nav-group">
                    <button
                        class="nav-link sidebar-parent-link <?= $isMaintenanceModule ? 'active' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#maintenanceSidebarMenu"
                        aria-expanded="<?= $isMaintenanceModule ? 'true' : 'false' ?>"
                        aria-controls="maintenanceSidebarMenu"
                    >
                        <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
                        <span>Mentenan&#539;&#259;</span>
                        <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="collapse <?= $isMaintenanceModule ? 'show' : '' ?>" id="maintenanceSidebarMenu">
                        <div class="sidebar-submenu">
                            <a class="nav-link <?= $isMaintenanceModule && $maintenanceAction === 'overview' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'overview'])) ?>">Prezentare general&#259;</a>
                            <a class="nav-link <?= $isMaintenanceModule && $maintenanceAction === 'interventions' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions'])) ?>">Interven&#539;ii</a>
                            <a class="nav-link <?= $isMaintenanceModule && $maintenanceAction === 'maintenance' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'maintenance'])) ?>">&Icirc;ntre&#539;inere</a>
                            <a class="nav-link <?= $isMaintenanceModule && $maintenanceAction === 'repairs' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'repairs'])) ?>">Repara&#539;ii</a>
                            <a class="nav-link <?= $isMaintenanceModule && $maintenanceAction === 'auto' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'auto'])) ?>">Auto</a>
                            <a class="nav-link <?= $isMaintenanceModule && $maintenanceAction === 'stock' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'stock'])) ?>">Stoc</a>
                            <a class="nav-link <?= $isTireModule && $currentAction === 'tire_stock' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock'])) ?>">Stoc anvelope</a>
                            <a class="nav-link <?= $isTireModule && $currentAction === 'axis_config' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'axis_config'])) ?>">Configura&#539;ie Axe</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($can('configurare_costuri_documente_vehicule_override')): ?><a class="nav-link <?= $currentPage === 'configurare_costuri_documente_vehicule_override' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override'])) ?>"><i class="bi bi-gear" aria-hidden="true"></i><span>Configurare Costuri</span></a><?php endif; ?>
                <?php if ($can('notificari')): ?><a class="nav-link <?= $currentPage === 'notificari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'notificari'])) ?>"><i class="bi bi-bell" aria-hidden="true"></i><span>Notific&#259;ri</span></a><?php endif; ?>
                <?php if ($can('utilizatori')): ?><a class="nav-link <?= $currentPage === 'utilizatori' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'utilizatori'])) ?>"><i class="bi bi-gear-wide-connected" aria-hidden="true"></i><span>Set&#259;ri sistem</span></a><?php endif; ?>
                <?php if ($can('drepturi_acces')): ?><a class="nav-link <?= $currentPage === 'drepturi_acces' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'drepturi_acces'])) ?>"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Drepturi de acces</span></a><?php endif; ?>
                <?php if ($can('activitate_utilizatori')): ?><a class="nav-link <?= $currentPage === 'activitate_utilizatori' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'activitate_utilizatori'])) ?>"><i class="bi bi-person-lines-fill" aria-hidden="true"></i><span>Activitate utilizatori</span></a><?php endif; ?>
                <hr class="my-3" data-sidebar-search-static>
                <a class="nav-link text-danger" href="<?= e(build_query_url(['page' => 'logout'])) ?>" data-sidebar-search-static><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Deconectare</span></a>
            </nav>
        </aside>

        <div class="app-content">
            <header class="topbar d-flex justify-content-between align-items-center px-4 py-3 border-bottom bg-white">
                <div class="d-flex align-items-center gap-2"></div>
                <div class="topbar-user-area">
                    <a class="topbar-icon-button" href="<?= e(build_query_url(['page' => 'notificari'])) ?>" aria-label="Notificari">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                    </a>
                    <span class="topbar-divider"></span>
                    <a class="topbar-profile" href="<?= e(build_query_url(['page' => 'profil'])) ?>">
                        <span class="topbar-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                        <span class="topbar-profile-text">
                            <strong><?= e($user['nume'] ?? '') ?></strong>
                            <small><?= e(function_exists('role_display_name') ? role_display_name((string) ($user['rol'] ?? '')) : (string) ($user['rol'] ?? '')) ?></small>
                        </span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </a>
                </div>
            </header>

            <?php if ($showGlobalApprovalDrawer): ?>
                <?php
                $approvalSummary = $globalApprovalSummary;
                $approvalDrawerMode = $globalApprovalDrawerMode;
                include BASE_PATH . '/views/partials/inactive_approval_drawer.php';
                ?>
            <?php endif; ?>

            <main class="p-4">
                <?php foreach ($alerts as $type => $messages): ?>
                    <?php foreach ((array) $messages as $message): ?>
                        <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
                            <?= e($message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="&Icirc;nchide"></button>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
<?php else: ?>
    <div class="auth-wrapper">
        <div class="auth-container">
            <?php foreach ($alerts as $type => $messages): ?>
                <?php foreach ((array) $messages as $message): ?>
                    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
                        <?= e($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="&Icirc;nchide"></button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
<?php endif; ?>
