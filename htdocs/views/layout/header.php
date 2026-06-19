<?php
$user = function_exists('current_user') ? current_user() : null;
$alerts = flash_messages();
$styleVersion = (string) @filemtime(BASE_PATH . '/assets/css/style.css');
$currentAction = (string) ($_GET['action'] ?? 'index');
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
<body>
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
                <a class="nav-link <?= $currentPage === 'dispecer_curse' && $currentAction === 'refacturari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari'])) ?>"><i class="bi bi-receipt" aria-hidden="true"></i><span>Refacturari curse</span></a>
                <a class="nav-link <?= $currentPage === 'centralizator_facturare' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'centralizator_facturare'])) ?>"><i class="bi bi-calendar-range" aria-hidden="true"></i><span>Centralizator Facturare</span></a>
                <a class="nav-link <?= $currentPage === 'programare_concedii' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'programare_concedii'])) ?>"><i class="bi bi-calendar2-week" aria-hidden="true"></i><span>Programare concedii</span></a>
                <a class="nav-link <?= $currentPage === 'vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'vehicule'])) ?>"><i class="bi bi-car-front" aria-hidden="true"></i><span>Vehicule</span></a>
                <a class="nav-link <?= $currentPage === 'autorizatii_vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'autorizatii_vehicule'])) ?>"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Autorizații</span></a>
                <a class="nav-link <?= $currentPage === 'soferi' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'soferi'])) ?>"><i class="bi bi-fuel-pump" aria-hidden="true"></i><span>&#536;oferi</span></a>
                <?php if (function_exists('is_accountancy_user') && is_accountancy_user()): ?>
                    <a class="nav-link <?= $currentPage === 'contabilitate_personal' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'contabilitate_personal'])) ?>"><i class="bi bi-person-badge" aria-hidden="true"></i><span>Contabilitate Personal</span></a>
                    <a class="nav-link <?= $currentPage === 'cheltuieli_birou' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'cheltuieli_birou'])) ?>"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Cheltuieli Birou</span></a>
                <?php endif; ?>
                <a class="nav-link <?= $currentPage === 'alimentari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'alimentari'])) ?>"><i class="bi bi-fuel-pump-diesel" aria-hidden="true"></i><span>Aliment&#259;ri</span></a>
                <a class="nav-link <?= $currentPage === 'mentenanta' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta'])) ?>"><i class="bi bi-calculator" aria-hidden="true"></i><span>Mentenan&#539;&#259;</span></a>
                <a class="nav-link <?= $currentPage === 'documente' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente'])) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>Documente</span></a>
                <a class="nav-link <?= $currentPage === 'inventar_dotari_vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>"><i class="bi bi-book" aria-hidden="true"></i><span>Inventar Dot&#259;ri</span></a>
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
