<?php
$user = function_exists('current_user') ? current_user() : null;
$alerts = flash_messages();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<?php if ($showSidebar && is_logged_in()): ?>
    <div class="app-shell">
        <aside class="sidebar p-3">
            <div class="sidebar-brand mb-4">
                <div class="fw-bold fs-5">Fleet Management</div>
                <div class="text-muted small">MVP trial</div>
            </div>

            <nav class="nav flex-column gap-1">
                <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">Tablou de bord</a>
                <a class="nav-link <?= $currentPage === 'dashboard_analitic' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dashboard_analitic'])) ?>">Dashboard Analitic</a>
                <a class="nav-link <?= $currentPage === 'dispecer_curse' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Dispecer curse</a>
                <a class="nav-link <?= $currentPage === 'programare_concedii' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'programare_concedii'])) ?>">Programare concedii</a>
                <a class="nav-link <?= $currentPage === 'vehicule' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'vehicule'])) ?>">Vehicule</a>
                <a class="nav-link <?= $currentPage === 'soferi' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'soferi'])) ?>">&#536;oferi</a>
                <a class="nav-link <?= $currentPage === 'alimentari' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'alimentari'])) ?>">Aliment&#259;ri</a>
                <a class="nav-link <?= $currentPage === 'mentenanta' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'mentenanta'])) ?>">Mentenan&#539;&#259;</a>
                <a class="nav-link <?= $currentPage === 'documente' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente'])) ?>">Documente</a>
                <?php if (is_admin()): ?>
                    <a class="nav-link <?= $currentPage === 'utilizatori' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'utilizatori'])) ?>">Utilizatori</a>
                <?php endif; ?>
                <hr class="my-3">
                <a class="nav-link text-danger" href="<?= e(build_query_url(['page' => 'logout'])) ?>">Deconectare</a>
            </nav>
        </aside>

        <div class="app-content">
            <header class="topbar d-flex justify-content-between align-items-center px-4 py-3 border-bottom bg-white">
                <div>
                    <h1 class="h5 mb-0"><?= e($pageTitle) ?></h1>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="small text-muted">
                        <?= e($user['nume'] ?? '') ?>
                        (<?= e(function_exists('role_display_name') ? role_display_name((string) ($user['rol'] ?? '')) : (string) ($user['rol'] ?? '')) ?>)
                    </span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'profil'])) ?>">Profilul meu</a>
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
