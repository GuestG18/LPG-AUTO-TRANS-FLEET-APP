<?php
$user = function_exists('current_user') ? current_user() : null;
$alerts = flash_messages();
$currentAction = (string) ($_GET['action'] ?? 'index');
$currentRoutePage = (string) ($_GET['page'] ?? ($currentPage ?? ''));
$styleVersion = (string) @filemtime(BASE_PATH . '/assets/css/style.css');
$bodyClasses = [];
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
<script>
try {
    if (window.localStorage.getItem('fleet.sidebarCollapsed') === '1') {
        document.body.classList.add('sidebar-collapsed');
    }
} catch (error) {
}
</script>
<?php endif; ?>
<?php if ($showSidebar && is_logged_in()): ?>
    <div class="app-shell">
        <aside class="sidebar p-3">
            <div class="sidebar-brand mb-4">
                <div class="fw-bold fs-5">Fleet Management</div>
                <div class="text-muted small">MVP trial</div>
            </div>

            <nav class="nav flex-column gap-1">
                <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>"><i class="bi bi-house-door" aria-hidden="true"></i><span>Tablou de bord</span></a>
                <a class="nav-link <?= $currentPage === 'dashboard_analitic' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dashboard_analitic'])) ?>"><i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Dashboard Analitic</span></a>
                <a class="nav-link <?= $currentPage === 'dispecer_curse' && $currentAction !== 'refacturari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>"><i class="bi bi-truck" aria-hidden="true"></i><span>Dispecer curse</span></a>
                <a class="nav-link <?= $currentPage === 'istoric_cheltuieli_curse' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse'])) ?>"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>Istoric cheltuieli curse</span></a>
                <?php if ($currentPage !== 'istoric_cheltuieli_curse'): ?>
                    <a class="nav-link <?= $currentPage === 'dispecer_curse' && $currentAction === 'refacturari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari'])) ?>"><i class="bi bi-receipt" aria-hidden="true"></i><span>Refacturari curse</span></a>
                <?php endif; ?>
                <a class="nav-link <?= $currentPage === 'centralizator_facturare' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'centralizator_facturare'])) ?>"><i class="bi bi-calendar-range" aria-hidden="true"></i><span>Centralizator Facturare</span></a>
                <a class="nav-link <?= $currentPage === 'programare_concedii' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'programare_concedii'])) ?>"><i class="bi bi-calendar2-week" aria-hidden="true"></i><span>Programare concedii</span></a>
                <?php
                $isVehicleNavGroup = in_array($currentRoutePage, ['vehicule', 'documente', 'inventar_dotari_vehicule', 'stare_tehnica'], true) || $currentPage === 'vehicule';
                ?>
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
                            <a class="nav-link <?= $currentRoutePage === 'vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'vehicule'])) ?>">Lista vehicule</a>
                            <a class="nav-link <?= $currentRoutePage === 'documente' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente'])) ?>">Documente Vehicule</a>
                            <a class="nav-link <?= $currentRoutePage === 'inventar_dotari_vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">Inventar Dotari</a>
                            <a class="nav-link <?= $currentPage === 'stare_tehnica' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'stare_tehnica'])) ?>">Stare tehnic&#259;</a>
                        </div>
                    </div>
                </div>
                <a class="nav-link <?= $currentPage === 'autorizatii_vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'autorizatii_vehicule'])) ?>"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Autorizații</span></a>
                <?php
                $isDriverNavGroup = in_array($currentRoutePage, ['soferi', 'documente_soferi', 'istoric_activitati_sofer'], true) || $currentPage === 'soferi';
                ?>
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
                            <a class="nav-link <?= $currentRoutePage === 'soferi' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'soferi'])) ?>">Lista soferi</a>
                            <a class="nav-link <?= $currentRoutePage === 'documente_soferi' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente_soferi'])) ?>">Documente Soferi</a>
                            <a class="nav-link <?= $currentRoutePage === 'istoric_activitati_sofer' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'istoric_activitati_sofer'])) ?>">Istoric Activitati Soferi</a>
                        </div>
                    </div>
                </div>
                <?php if (function_exists('is_accountancy_user') && is_accountancy_user()): ?>
                    <a class="nav-link <?= $currentPage === 'contabilitate_personal' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'contabilitate_personal'])) ?>"><i class="bi bi-person-badge" aria-hidden="true"></i><span>Contabilitate Personal</span></a>
                    <a class="nav-link <?= $currentPage === 'cheltuieli_birou' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'cheltuieli_birou'])) ?>"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Cheltuieli Birou</span></a>
                <?php endif; ?>
                <?php
                $isTireModule = $currentPage === 'mentenanta' && in_array($currentAction, ['tire_stock', 'axis_config'], true);
                $isMaintenanceModule = $currentPage === 'mentenanta';
                $maintenanceAction = $currentAction === 'index' ? 'overview' : $currentAction;
                ?>
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
                <?php if (is_admin()): ?>
                    <a class="nav-link <?= $currentPage === 'configurare_costuri_documente_vehicule_override' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override'])) ?>"><i class="bi bi-gear" aria-hidden="true"></i><span>Configurare Costuri</span></a>
                    <a class="nav-link <?= $currentPage === 'notificari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'notificari'])) ?>"><i class="bi bi-bell" aria-hidden="true"></i><span>Notific&#259;ri</span></a>
                    <a class="nav-link <?= $currentPage === 'utilizatori' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'utilizatori'])) ?>"><i class="bi bi-gear-wide-connected" aria-hidden="true"></i><span>Set&#259;ri sistem</span></a>
                <?php endif; ?>
                <hr class="my-3">
                <a class="nav-link text-danger" href="<?= e(build_query_url(['page' => 'logout'])) ?>"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Deconectare</span></a>
            </nav>
        </aside>

        <div class="app-content">
            <header class="topbar d-flex justify-content-between align-items-center px-4 py-3 border-bottom bg-white">
                <div class="d-flex align-items-center gap-2">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary sidebar-toggle-btn"
                        data-sidebar-toggle
                        aria-label="Ascunde sau afiseaza meniul"
                        aria-expanded="true"
                        title="Ascunde sau afiseaza meniul"
                    >
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                </div>
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
