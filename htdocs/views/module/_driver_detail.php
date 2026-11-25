<?php
/**
 * Fișa „Detalii șofer” (page=soferi&action=show).
 *
 * Doar prezentare. Statusul vine din EntityStatusService::evaluateDriverStatus (controller), documentele
 * din DocumentModel::getDocumentsForDriver, vehiculele din soferi_vehicule (+ cuplajul activ), schema
 * vehiculului din exploratorul paginii Auto (ModuleController::fleetExplorerData + vehicle-xray.js),
 * echipamentele din DriverEquipmentModel::getAllocations, activitatea din curse_dispecer.
 * Aici nu se recalculează nicio regulă de business și nu se scrie nimic.
 *
 * Variabile din show.php: $record, $module, $routePage, $backUrl, $statusContext, $driverDocuments, $driverDetail.
 */

$ddId = (int) ($record['id'] ?? 0);
$ddDetailFields = $module['detail_fields'] ?? $module['form_fields'];
$ddContext = is_array($driverDetail ?? null) ? $driverDetail : [];
$ddToday = new DateTimeImmutable('today');

$ddCanEdit = can('soferi', 'edit');
$ddCanDocView = can('documente_soferi', 'view');
$ddCanDocCreate = can('documente_soferi', 'create');
$ddCanDocEdit = can('documente_soferi', 'edit');
$ddCanVehicleView = can('vehicule', 'view');

$ddNormalize = static function (string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

    return preg_replace('/\s+/', ' ', $value) ?? $value;
};
$ddDaysUntil = static function (string $date) use ($ddToday): ?int {
    if (trim($date) === '') {
        return null;
    }
    try {
        return (int) $ddToday->diff(new DateTimeImmutable($date))->format('%r%a');
    } catch (Throwable) {
        return null;
    }
};

// ---- Identitate ---------------------------------------------------------------
$ddName = trim((string) ($record['nume'] ?? '')) !== '' ? (string) $record['nume'] : '-';
$ddPhotoUrl = driver_image_url(isset($record['poza_stocata']) ? (string) $record['poza_stocata'] : null);
$ddPhotoThumb = upload_image_thumb_url('soferi', isset($record['poza_stocata']) ? (string) $record['poza_stocata'] : null, 320) ?? $ddPhotoUrl;
$ddBirth = trim((string) ($record['data_nasterii'] ?? ''));
$ddAge = null;
if ($ddBirth !== '') {
    try {
        $ddAge = (new DateTimeImmutable($ddBirth))->diff($ddToday)->y;
    } catch (Throwable) {
        $ddAge = null;
    }
}
$ddPhone = trim((string) ($record['telefon'] ?? ''));
$ddIsCollaborator = (string) ($record['tip_colaborare'] ?? '') === 'colaborator';
$ddInitials = '';
foreach (array_slice(preg_split('/[\s-]+/u', $ddName) ?: [], 0, 2) as $ddPart) {
    $ddInitials .= mb_strtoupper(mb_substr($ddPart, 0, 1, 'UTF-8'), 'UTF-8');
}

// ---- Status calculat automat (doar citit) ------------------------------------
$ddStatusContext = is_array($statusContext ?? null) ? $statusContext : [];
$ddStatusValue = (string) ($ddStatusContext['status'] ?? 'inactiv');
$ddIsActive = $ddStatusValue === 'activ';
$ddChecks = is_array($ddStatusContext['checks'] ?? null) ? $ddStatusContext['checks'] : [];
$ddStoredStatus = (string) ($record['status'] ?? '');

$ddDocuments = is_array($driverDocuments ?? null) ? $driverDocuments : [];
$ddDocumentsByType = [];
foreach ($ddDocuments as $ddDocument) {
    $key = mb_strtolower(trim((string) ($ddDocument['tip_document'] ?? '')), 'UTF-8');
    if ($key !== '' && !isset($ddDocumentsByType[$key])) {
        $ddDocumentsByType[$key] = $ddDocument;
    }
}

// Aceeași stare UI ca panoul vechi: „soon” (≤30 zile) e doar o nuanță, NU un motiv de inactivare.
$ddCheckUiState = static function (array $check) use ($ddDaysUntil): string {
    $state = (string) ($check['state'] ?? 'valid');
    if ($state !== 'valid') {
        return in_array($state, ['missing', 'expired'], true) ? $state : 'warning';
    }
    $days = $ddDaysUntil(trim((string) ($check['date'] ?? '')));

    return $days !== null && $days >= 0 && $days <= 30 ? 'soon' : 'valid';
};
$ddCheckMeta = static fn (string $uiState): array => match ($uiState) {
    'missing' => ['class' => 'is-missing', 'label' => 'LIPSA', 'icon' => 'bi-file-earmark-x'],
    'expired' => ['class' => 'is-expired', 'label' => 'EXPIRAT', 'icon' => 'bi-calendar-x'],
    'soon' => ['class' => 'is-warning', 'label' => 'EXPIRA CURAND', 'icon' => 'bi-exclamation-triangle'],
    'valid' => ['class' => 'is-valid', 'label' => 'OK', 'icon' => 'bi-check-circle'],
    default => ['class' => 'is-warning', 'label' => 'ATENTIE', 'icon' => 'bi-exclamation-triangle'],
};
$ddCheckDateLabel = static function (array $check, string $uiState): string {
    $rawDate = trim((string) ($check['date'] ?? ''));
    if ($rawDate !== '') {
        return format_date_ro($rawDate);
    }

    return match ($uiState) {
        'missing' => 'Lipsa',
        'warning' => 'Atentie',
        default => 'Fara expirare',
    };
};

$ddStatusRows = [];
foreach ($ddChecks as $ddIndex => $ddCheck) {
    $label = trim((string) ($ddCheck['label'] ?? '-'));
    $uiState = $ddCheckUiState($ddCheck);
    $ddStatusRows[] = [
        'id' => 'driver-status-doc-' . (int) $ddIndex,
        'check' => $ddCheck,
        'document' => $ddDocumentsByType[mb_strtolower($label, 'UTF-8')] ?? null,
        'label' => $label !== '' ? $label : '-',
        'state' => $uiState,
        'is_problem' => $uiState !== 'valid',
        'is_inactive_reason' => (string) ($ddCheck['state'] ?? 'valid') !== 'valid',
        'date_label' => $ddCheckDateLabel($ddCheck, $uiState),
        'meta' => $ddCheckMeta($uiState),
    ];
}
$ddProblemRows = array_values(array_filter($ddStatusRows, static fn (array $row): bool => $row['is_problem']));
$ddValidRows = array_values(array_filter($ddStatusRows, static fn (array $row): bool => !$row['is_problem']));
$ddBlockingRows = array_values(array_filter($ddStatusRows, static fn (array $row): bool => $row['is_inactive_reason']));
$ddSoonCount = count(array_filter($ddStatusRows, static fn (array $row): bool => $row['state'] === 'soon'));
$ddBlockingCount = count($ddBlockingRows);
// Tipurile obligatorii (din configurare) care au un document încărcat / valabil / cu probleme blocante.
$ddRequiredTotal = count(array_filter($ddChecks, static fn (array $c): bool => (string) ($c['state'] ?? '') !== 'other'));
$ddRequiredUploaded = count(array_filter($ddStatusRows, static fn (array $row): bool => $row['document'] !== null && (string) ($row['check']['state'] ?? '') !== 'other'));
$ddRequiredValid = count(array_filter($ddChecks, static fn (array $c): bool => (string) ($c['state'] ?? '') === 'valid'));

$ddStatusSummary = static function (array $rows): string {
    $count = count($rows);
    if ($count === 0) {
        return 'Nicio problema';
    }
    $states = array_count_values(array_map(static fn (array $row): string => (string) $row['state'], $rows));
    if (count($states) === 1) {
        return match ((string) array_key_first($states)) {
            'expired' => $count . ' ' . ($count === 1 ? 'document expirat' : 'documente expirate'),
            'missing' => $count . ' ' . ($count === 1 ? 'document lipsa' : 'documente lipsa'),
            'soon' => $count . ' ' . ($count === 1 ? 'document expira curand' : 'documente expira curand'),
            default => $count . ' ' . ($count === 1 ? 'document cu atentie' : 'documente cu atentie'),
        };
    }

    return $count . ' ' . ($count === 1 ? 'document cu probleme' : 'documente cu probleme');
};
$ddCustomValue = static function (array $row): string {
    $type = strtolower(trim((string) ($row['type'] ?? 'text')));
    $value = trim((string) ($row['value'] ?? ''));
    if ($type === 'checkbox') {
        return $value === '1' ? 'Da' : 'Nu';
    }
    if ($type === 'date') {
        return $value !== '' ? format_date_ro($value) : '';
    }

    return $value;
};

// ---- Documente: data afișată (aceeași regulă ca tabelul vechi) -------------------
$ddDocRows = [];
$ddDocAttention = 0;
foreach ($ddDocuments as $ddDocument) {
    $customRows = is_array($ddDocument['custom_field_display_rows'] ?? null) ? $ddDocument['custom_field_display_rows'] : [];
    $expiryDate = trim((string) ($ddDocument['data_expirare'] ?? ''));
    $expiryLabel = '';
    if ($expiryDate === '' && $customRows !== []) {
        $dateRows = array_values(array_filter($customRows, static fn (array $row): bool => strtolower(trim((string) ($row['type'] ?? 'text'))) === 'date' && trim((string) ($row['value'] ?? '')) !== ''));
        usort($dateRows, static fn (array $a, array $b): int => strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? '')));
        if ($dateRows !== []) {
            $expiryDate = (string) ($dateRows[0]['value'] ?? '');
            $expiryLabel = (string) ($dateRows[0]['label'] ?? '');
        }
    }
    $days = $ddDaysUntil($expiryDate);
    if ($days !== null && $days <= 30) {
        $ddDocAttention++;
    }
    $ddDocRows[] = [
        'document' => $ddDocument,
        'custom_fields' => $customRows,
        'expiry_date' => $expiryDate,
        'expiry_label' => $expiryLabel,
        'search' => $ddNormalize(implode(' ', [
            (string) ($ddDocument['tip_document'] ?? ''),
            (string) ($ddDocument['numar_document'] ?? ''),
            (string) ($ddDocument['fisier_original'] ?? ''),
            $expiryLabel,
        ])),
    ];
}

// ---- Vehicule ----------------------------------------------------------------
$ddVehicles = is_array($ddContext['vehicles'] ?? null) ? $ddContext['vehicles'] : [];
$ddPrimary = $ddVehicles[0] ?? null;
$ddScheme = is_array($ddContext['scheme'] ?? null) ? $ddContext['scheme'] : null;
$ddSchemeUnit = is_array($ddScheme['unit'] ?? null) ? $ddScheme['unit'] : null;
$ddVehicleStatusLabel = static fn (string $status): string => match ($status) {
    'activ' => 'Activ',
    'inactiv' => 'Inactiv',
    '' => '-',
    default => ucfirst($status),
};
$ddVehicleTypeIcon = static fn (string $type): string => match ($type) {
    'cap_tractor' => 'bi-truck-front',
    'semiremorca' => 'bi-truck-flatbed',
    'camion' => 'bi-truck',
    default => 'bi-car-front',
};

// ---- Echipamente ----------------------------------------------------------------
$ddEquipment = is_array($ddContext['equipment'] ?? null) ? $ddContext['equipment'] : ['allowed' => false, 'items' => []];
$ddEquipItems = is_array($ddEquipment['items'] ?? null) ? $ddEquipment['items'] : [];
$ddEquipKpis = is_array($ddEquipment['kpis'] ?? null) ? $ddEquipment['kpis'] : ['total' => 0, 'in_posesie' => 0, 'de_inlocuit' => 0, 'returnate' => 0];
$ddEquipZones = [];
foreach ($ddEquipItems as $ddItem) {
    if (!$ddItem['active']) {
        continue; // pe figură apar doar articolele aflate acum la șofer
    }
    $zone = (string) $ddItem['anchor'];
    $ddEquipZones[$zone] ??= ['zone' => $zone, 'icon' => $ddItem['icon'], 'count' => 0, 'categories' => [], 'attention' => false];
    $ddEquipZones[$zone]['count'] += (int) $ddItem['cantitate'];
    $ddEquipZones[$zone]['categories'][$ddItem['categorie']] = true;
    $ddEquipZones[$zone]['attention'] = $ddEquipZones[$zone]['attention'] || $ddItem['replacement'];
}
if (!empty($ddEquipment['allowed'])) {
    include __DIR__ . '/../echipamente_soferi/_silhouette_assets.php';
}
$ddEquipTone = static fn (array $item): string => match (true) {
    $item['status'] === 'returnat' || $item['status'] === 'inlocuit' => 'muted',
    $item['status'] === 'pierdut' => 'danger',
    $item['replacement'] => 'danger',
    default => in_array($item['condition_tone'], ['success', 'warning', 'danger', 'muted'], true) ? $item['condition_tone'] : 'success',
};
$ddEquipStatusText = static function (array $item): string {
    if ($item['active'] && !$item['replacement']) {
        return 'În posesie';
    }

    return (string) $item['status_label'];
};
$ddEquipAttention = (int) ($ddEquipKpis['de_inlocuit'] ?? 0);

// ---- Activitate ----------------------------------------------------------------
$ddActivity = is_array($ddContext['activity'] ?? null) ? $ddContext['activity'] : ['allowed' => false];
$ddTransportLabel = static fn (string $type): string => match ($type) {
    'primar' => 'Primar',
    'distributie' => 'Distribuție',
    'primar_distributie', 'p+d' => 'Primar + Distribuție',
    '' => '-',
    default => ucfirst(str_replace('_', ' ', $type)),
};

// ---- Navigare ------------------------------------------------------------------
$ddTabs = [
    'info' => ['label' => 'Informații generale', 'icon' => 'bi-person', 'badge' => 0, 'tone' => ''],
    'status' => ['label' => 'Status și conformitate', 'icon' => 'bi-shield-check', 'badge' => $ddBlockingCount, 'tone' => 'danger'],
    'documente' => ['label' => 'Documente', 'icon' => 'bi-file-earmark-text', 'badge' => $ddDocAttention, 'tone' => 'danger'],
    'vehicule' => ['label' => 'Vehicule alocate', 'icon' => 'bi-truck', 'badge' => 0, 'tone' => ''],
    'echipamente' => ['label' => 'Echipamente', 'icon' => 'bi-person-badge', 'badge' => $ddEquipAttention, 'tone' => 'danger'],
    'activitate' => ['label' => 'Istoric și activitate', 'icon' => 'bi-clock-history', 'badge' => 0, 'tone' => ''],
];
$ddInitialTab = (string) ($_GET['tab'] ?? '');
if (!isset($ddTabs[$ddInitialTab])) {
    $ddInitialTab = 'info';
}

$ddEditUrl = build_query_url(['page' => $routePage, 'action' => 'edit', 'id' => $ddId]);
$ddListUrl = build_query_url(['page' => $routePage]);
$ddAddDocUrl = build_query_url(['page' => 'documente_soferi', 'action' => 'create', 'driver_id' => $ddId]);
$ddAsset = static fn (string $path): string => url($path . '?v=' . (string) @filemtime(BASE_PATH . '/' . $path));

$ddDocActions = static function (?array $document, bool $compact = false) use ($ddId, $ddCanDocView, $ddCanDocEdit, $ddCanDocCreate, $ddAddDocUrl): string {
    $documentId = $document !== null ? (int) ($document['id'] ?? 0) : 0;
    $html = '';
    if ($documentId > 0) {
        if ($ddCanDocView && !empty($document['fisier_stocat'])) {
            $html .= '<a class="dd-btn dd-btn-sm dd-btn-outline" href="' . e(build_query_url(['page' => 'documente_soferi', 'action' => 'preview', 'id' => $documentId])) . '"><i class="bi bi-eye" aria-hidden="true"></i>Vezi în aplicație</a>';
        }
        if ($ddCanDocView) {
            $html .= '<a class="dd-btn dd-btn-sm dd-btn-outline" href="' . e(build_query_url(['page' => 'documente_soferi', 'action' => 'show', 'id' => $documentId])) . '"><i class="bi bi-info-circle" aria-hidden="true"></i>Detalii</a>';
        }
        if ($ddCanDocEdit) {
            $html .= '<a class="dd-btn dd-btn-sm dd-btn-outline-primary" href="' . e(build_query_url(['page' => 'documente_soferi', 'action' => 'edit', 'id' => $documentId])) . '"><i class="bi bi-pencil" aria-hidden="true"></i>' . ($compact ? 'Editează' : 'Editează / înlocuiește') . '</a>';
        }
    } elseif ($ddCanDocCreate) {
        $html .= '<a class="dd-btn dd-btn-sm dd-btn-primary" href="' . e($ddAddDocUrl) . '"><i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă document</a>';
    }

    return $html;
};
?>
<link rel="stylesheet" href="<?= e($ddAsset('assets/css/driver-detail.css')) ?>">

<div class="dd-page" data-driver-detail data-driver-id="<?= e((string) $ddId) ?>" data-initial-tab="<?= e($ddInitialTab) ?>">
    <nav class="dd-breadcrumb" aria-label="Navigare">
        <a href="<?= e(url('index.php')) ?>" class="dd-crumb-home" aria-label="Acasă"><i class="bi bi-house" aria-hidden="true"></i></a>
        <i class="bi bi-chevron-right dd-crumb-sep" aria-hidden="true"></i>
        <a href="<?= e($ddListUrl) ?>">Șoferi</a>
        <i class="bi bi-chevron-right dd-crumb-sep" aria-hidden="true"></i>
        <span aria-current="page">Detalii șofer</span>
    </nav>

    <div class="dd-toolbar">
        <h2 class="dd-title"><i class="bi bi-person" aria-hidden="true"></i>Detalii șofer</h2>
        <div class="dd-toolbar-actions">
            <a class="dd-btn dd-btn-ghost" href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Înapoi la listă</span></a>
            <?php if ($ddCanEdit): ?>
                <a class="dd-btn dd-btn-violet" href="<?= e($ddEditUrl) ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span>Editează</span></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Antet: identitate | vehicul (schema din pagina Auto) | status -->
    <section class="dd-hero" aria-label="Prezentare șofer">
        <div class="dd-ident">
            <?php if ($ddPhotoUrl !== null): ?>
                <a class="dd-photo" href="<?= e($ddPhotoUrl) ?>" target="_blank" rel="noopener" title="Deschide poza">
                    <img src="<?= e((string) $ddPhotoThumb) ?>" alt="Poza șofer <?= e($ddName) ?>">
                </a>
            <?php else: ?>
                <a class="dd-photo dd-photo-empty" <?= $ddCanEdit ? 'href="' . e($ddEditUrl) . '" title="Încarcă poza din Editează"' : '' ?>>
                    <span class="dd-photo-initials" aria-hidden="true"><?= e($ddInitials !== '' ? $ddInitials : '?') ?></span>
                    <small><?= $ddCanEdit ? '<i class="bi bi-upload" aria-hidden="true"></i> Fără poză' : 'Fără poză' ?></small>
                </a>
            <?php endif; ?>
            <div class="dd-ident-text">
                <h3 class="dd-name"><?= e($ddName) ?></h3>
                <ul class="dd-ident-list">
                    <li><i class="bi bi-calendar3" aria-hidden="true"></i><span><?= $ddBirth !== '' ? e(format_date_ro($ddBirth)) . ($ddAge !== null ? ' <em>(' . e((string) $ddAge) . ' ani)</em>' : '') : '<span class="dd-muted">Data nașterii necompletată</span>' ?></span></li>
                    <li><i class="bi bi-telephone" aria-hidden="true"></i><span><?= $ddPhone !== '' ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $ddPhone) ?? '') . '">' . e($ddPhone) . '</a>' : '<span class="dd-muted">-</span>' ?></span></li>
                    <li><i class="bi bi-truck" aria-hidden="true"></i><span>Vehicule alocate: <button type="button" class="dd-count-pill" data-dd-goto="vehicule" title="Vezi vehiculele alocate"><?= e((string) count($ddVehicles)) ?></button></span></li>
                </ul>
            </div>
        </div>

        <div class="dd-vehicle" data-dd-vehicle-card>
            <div class="dd-vehicle-head">
                <span class="dd-vehicle-title"><i class="bi bi-truck" aria-hidden="true"></i>Vehicul asignat <span data-dd-vehicle-role><?= $ddPrimary !== null ? ($ddPrimary['is_primary'] ? '(principal)' : '') : '' ?></span></span>
                <?php if ($ddPrimary !== null): ?>
                    <span class="dd-chip-info" title="Schema este cea din pagina Auto (Mentenanță → Auto), pentru vehiculul selectat și semiremorca cuplată activ.">Preview configurație vehicul <i class="bi bi-info-circle" aria-hidden="true"></i></span>
                    <span class="dd-vstatus <?= $ddPrimary['status'] === 'activ' ? 'is-active' : 'is-inactive' ?>" data-dd-vehicle-status><?= e($ddVehicleStatusLabel((string) $ddPrimary['status'])) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($ddPrimary === null): ?>
                <div class="dd-vehicle-empty">
                    <i class="bi bi-truck" aria-hidden="true"></i>
                    <strong>Niciun vehicul alocat</strong>
                    <?php if ($ddCanEdit): ?><a href="<?= e($ddEditUrl) ?>">Alocarea se face din Editează</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="dd-vehicle-body">
                    <div class="dd-xray-slot" data-dd-xray-slot>
                        <div class="dd-xray is-compact" data-dd-xray data-ft-unit="">
                            <span class="ft-vehicle-icon" hidden data-dd-unit-icon></span>
                            <div class="fx-explorer" data-fx-explorer></div>
                            <div class="fx-panel" data-fx-panel hidden aria-live="polite"></div>
                            <div data-fx-tire-area hidden></div>
                        </div>
                        <div class="dd-xray-empty" data-dd-xray-empty hidden>
                            <i class="bi bi-diagram-3" aria-hidden="true"></i>
                            <span data-dd-xray-empty-text>Schema nu este disponibilă pentru acest vehicul.</span>
                        </div>
                        <button type="button" class="dd-xray-open" data-dd-xray-open hidden><i class="bi bi-hand-index" aria-hidden="true"></i>Click pentru detalii</button>
                    </div>
                    <ul class="dd-vehicle-facts" data-dd-vehicle-facts>
                        <li><i class="bi bi-card-text" aria-hidden="true"></i><strong data-dd-fact="plate"><?= e((string) $ddPrimary['plate']) ?></strong></li>
                        <li><i class="bi <?= e($ddVehicleTypeIcon((string) $ddPrimary['type'])) ?>" aria-hidden="true" data-dd-fact-icon></i><span data-dd-fact="make"><?= e($ddPrimary['make'] !== '' ? (string) $ddPrimary['make'] : (string) $ddPrimary['type_label']) ?></span></li>
                        <li class="dd-fact-partner" data-dd-fact-partner-row <?= $ddPrimary['partner'] === null ? 'hidden' : '' ?>><i class="bi bi-link-45deg" aria-hidden="true"></i><span data-dd-fact="partner"><?= $ddPrimary['partner'] !== null ? '+ ' . e((string) $ddPrimary['partner']['plate']) : '' ?></span></li>
                        <li class="dd-fact-type"><i class="bi bi-diagram-2" aria-hidden="true"></i><span data-dd-fact="type"><?= e((string) $ddPrimary['type_label']) ?><?= $ddPrimary['formula'] !== '' ? ' · ' . e((string) $ddPrimary['formula']) : '' ?></span></li>
                    </ul>
                </div>
                <?php if (count($ddVehicles) > 1): ?>
                    <div class="dd-vehicle-switch" role="group" aria-label="Alege vehiculul afișat">
                        <?php foreach ($ddVehicles as $ddIndex => $ddVehicle): ?>
                            <button type="button" class="dd-vswitch<?= $ddIndex === 0 ? ' is-active' : '' ?>" data-dd-select-vehicle="<?= e((string) $ddVehicle['id']) ?>" aria-pressed="<?= $ddIndex === 0 ? 'true' : 'false' ?>"><?= e((string) $ddVehicle['plate']) ?><?= $ddVehicle['is_primary'] ? ' <i class="bi bi-star-fill" aria-label="principal"></i>' : '' ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="dd-status <?= $ddIsActive ? 'is-active' : 'is-inactive' ?>">
            <div class="dd-status-head">
                <span class="dd-status-title">Status șofer</span>
                <button type="button" class="dd-status-badge <?= $ddIsActive ? 'is-active' : 'is-inactive' ?>" data-dd-goto="status" title="Status calculat automat din documentele obligatorii"><?= $ddIsActive ? 'ACTIV' : 'INACTIV' ?></button>
            </div>
            <div class="dd-status-line">
                <?php if ($ddIsCollaborator): ?>
                    <i class="bi bi-person-check-fill dd-ic-blue" aria-hidden="true"></i><span>Colaborator · documentele nu se verifică</span>
                <?php elseif ($ddBlockingCount > 0): ?>
                    <i class="bi bi-exclamation-triangle-fill dd-ic-red" aria-hidden="true"></i><span class="dd-red"><?= e((string) $ddBlockingCount) ?> <?= $ddBlockingCount === 1 ? 'problemă' : 'probleme' ?></span>
                <?php elseif ($ddSoonCount > 0): ?>
                    <i class="bi bi-hourglass-split dd-ic-amber" aria-hidden="true"></i><span class="dd-amber"><?= e((string) $ddSoonCount) ?> <?= $ddSoonCount === 1 ? 'document expiră' : 'documente expiră' ?> în 30 de zile</span>
                <?php else: ?>
                    <i class="bi bi-check-circle-fill dd-ic-green" aria-hidden="true"></i><span class="dd-green">Nicio problemă</span>
                <?php endif; ?>
            </div>
            <div class="dd-status-kpis">
                <button type="button" class="dd-skpi is-docs" data-dd-goto="documente">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span><small>Documente</small><strong><?= $ddIsCollaborator ? '—' : e((string) $ddRequiredUploaded) . ' / ' . e((string) $ddRequiredTotal) ?></strong></span>
                </button>
                <button type="button" class="dd-skpi is-valid" data-dd-goto="status" data-dd-status-filter="all">
                    <i class="bi bi-check-square" aria-hidden="true"></i>
                    <span><small>Valabile</small><strong><?= $ddIsCollaborator ? '—' : e((string) $ddRequiredValid) ?></strong></span>
                </button>
                <button type="button" class="dd-skpi <?= $ddBlockingCount > 0 ? 'is-problem' : 'is-zero' ?>" data-dd-goto="status" data-dd-status-filter="problems">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    <span><small title="Documente obligatorii lipsă sau expirate">Lipsă/exp.</small><strong><?= e((string) $ddBlockingCount) ?></strong></span>
                </button>
            </div>
        </div>
    </section>

    <!-- Navigarea pe secțiuni -->
    <div class="dd-nav" data-dd-nav>
        <button type="button" class="dd-nav-arrow" data-dd-prev aria-label="Secțiunea anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <div class="dd-tabs" role="tablist" aria-label="Secțiuni fișă șofer" data-dd-tabs>
            <?php foreach ($ddTabs as $ddKey => $ddTab): ?>
                <?php $ddActiveTab = $ddKey === $ddInitialTab; ?>
                <button type="button" class="dd-tab<?= $ddActiveTab ? ' is-active' : '' ?>" role="tab"
                        id="dd-tab-<?= e($ddKey) ?>" aria-controls="dd-panel-<?= e($ddKey) ?>"
                        aria-selected="<?= $ddActiveTab ? 'true' : 'false' ?>" tabindex="<?= $ddActiveTab ? '0' : '-1' ?>"
                        data-dd-tab="<?= e($ddKey) ?>">
                    <i class="bi <?= e($ddTab['icon']) ?>" aria-hidden="true"></i>
                    <span><?= e($ddTab['label']) ?></span>
                    <?php if ((int) $ddTab['badge'] > 0): ?>
                        <em class="dd-tab-badge is-<?= e($ddTab['tone']) ?>" aria-label="<?= e((string) $ddTab['badge']) ?> de verificat"><?= e((string) $ddTab['badge']) ?></em>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="dd-nav-arrow" data-dd-next aria-label="Secțiunea următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
    </div>

    <div class="dd-viewport" data-dd-viewport>
        <div class="dd-track" data-dd-track>

            <!-- 1. Informații generale -->
            <section class="dd-panel" id="dd-panel-info" role="tabpanel" aria-labelledby="dd-tab-info" data-dd-panel="info">
                <header class="dd-panel-head">
                    <i class="bi bi-person dd-panel-icon" aria-hidden="true"></i>
                    <div><h3>Informații generale</h3><p>Datele din fișa șoferului. Se modifică din Editează.</p></div>
                </header>
                <?php
                $ddInfoRows = [];
                foreach ($ddDetailFields as $ddField => $ddMeta) {
                    if (($ddMeta['store'] ?? true) === false) {
                        continue;
                    }
                    if (($ddMeta['type'] ?? '') === 'driver_photo_detail') {
                        $ddHtml = $ddPhotoUrl !== null
                            ? '<a href="' . e($ddPhotoUrl) . '" target="_blank" rel="noopener"><i class="bi bi-image me-1" aria-hidden="true"></i>' . e(trim((string) ($record['poza_original'] ?? '')) !== '' ? (string) $record['poza_original'] : 'Deschide imaginea') . '</a>'
                            : '<span class="dd-muted">Nu există poză încărcată</span>';
                    } else {
                        $ddHtml = format_value_html($record[$ddField] ?? null, $ddMeta, $record);
                    }
                    if ($ddField === 'status' && $ddStoredStatus !== '' && $ddStoredStatus !== $ddStatusValue) {
                        // Fișa doar evaluează statusul; valoarea salvată se actualizează la următoarea sincronizare.
                        $ddHtml .= ' <span class="dd-note-inline">calculat acum: <strong>' . e($ddIsActive ? 'activ' : 'inactiv') . '</strong></span>';
                    }
                    $ddInfoRows[] = ['field' => (string) $ddField, 'label' => (string) ($ddMeta['label'] ?? $ddField), 'html' => $ddHtml];
                }
                $ddInfoRows[] = ['field' => 'tip_colaborare', 'label' => 'Tip colaborare', 'html' => $ddIsCollaborator ? 'Colaborator <span class="dd-muted">(documentele nu se verifică)</span>' : 'Angajat'];
                $ddHalf = (int) ceil(count($ddInfoRows) / 2);
                ?>
                <div class="dd-info-columns">
                    <?php foreach ([array_slice($ddInfoRows, 0, $ddHalf), array_slice($ddInfoRows, $ddHalf)] as $ddColumn): ?>
                        <dl class="dd-info-list">
                            <?php foreach ($ddColumn as $ddRow): ?>
                                <div class="dd-info-row" data-field="<?= e($ddRow['field']) ?>"><dt><?= e($ddRow['label']) ?></dt><dd><?= $ddRow['html'] ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 2. Status și conformitate (panoul automat, aceleași reguli și aceleași atribute data-status-*) -->
            <section class="dd-panel" id="dd-panel-status" role="tabpanel" aria-labelledby="dd-tab-status" data-dd-panel="status">
                <div class="entity-status-panel entity-status-panel-driver dd-status-board <?= $ddIsActive ? 'is-active' : 'is-inactive' ?>" data-status-document-board>
                    <div class="entity-status-header">
                        <div>
                            <div class="entity-status-kicker">Status calculat automat</div>
                            <div class="entity-status-title">
                                <?= $ddIsActive ? 'Activ' : 'Inactiv' ?>
                                <?php if (!$ddIsActive && $ddBlockingRows !== []): ?>
                                    <span><?= e((string) $ddBlockingCount) ?> <?= $ddBlockingCount === 1 ? 'problema' : 'probleme' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="entity-status-badge <?= $ddIsActive ? 'is-active' : 'is-inactive' ?>"><?= $ddIsActive ? 'ACTIV' : 'INACTIV' ?></span>
                    </div>
                    <p class="entity-status-copy">
                        <?php if ($ddIsCollaborator): ?>
                            Șofer colaborator: documentele sunt la firma care îl angajează, deci nu se verifică aici. Statusul este activ automat.
                        <?php else: ?>
                            Statusul nu mai este setat manual. El se actualizeaza automat in functie de documentele obligatorii si de valabilitatea lor.
                        <?php endif; ?>
                    </p>

                    <?php if (!$ddIsActive && $ddBlockingRows !== []): ?>
                        <div class="entity-status-problem-callout">
                            <div class="entity-status-problem-head">
                                <div class="entity-status-problem-title">
                                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                    <span>De ce este soferul inactiv</span>
                                </div>
                                <div class="entity-status-problem-summary"><?= e($ddStatusSummary($ddBlockingRows)) ?></div>
                            </div>
                            <div class="entity-status-problem-pills">
                                <?php foreach ($ddBlockingRows as $ddIssue): ?>
                                    <span class="<?= e((string) $ddIssue['meta']['class']) ?>"><i class="bi <?= e((string) $ddIssue['meta']['icon']) ?>" aria-hidden="true"></i> <?= e((string) $ddIssue['label']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($ddStatusRows !== []): ?>
                        <div class="entity-status-doc-toolbar" role="group" aria-label="Filtru documente sofer">
                            <button type="button" class="is-active" data-status-doc-filter="problems" aria-pressed="true">Doar problemele</button>
                            <button type="button" data-status-doc-filter="all" aria-pressed="false">Toate documentele</button>
                        </div>
                        <div class="entity-status-doc-groups dd-scroll" data-status-doc-groups data-filter="problems">
                            <?php foreach ([['problems', 'Documente cu probleme', $ddProblemRows, true], ['valid', 'Documente valabile', $ddValidRows, false]] as [$ddGroupKey, $ddGroupTitle, $ddGroupRows, $ddGroupOpen]): ?>
                                <section class="entity-status-doc-group <?= $ddGroupOpen ? 'is-open' : '' ?>" data-status-doc-group="<?= e($ddGroupKey) ?>">
                                    <button type="button" class="entity-status-doc-group-toggle" data-status-doc-group-toggle aria-expanded="<?= $ddGroupOpen ? 'true' : 'false' ?>">
                                        <span class="entity-status-doc-group-title"><i class="bi bi-chevron-down" aria-hidden="true"></i> <?= e($ddGroupTitle) ?> &middot; <?= e((string) count($ddGroupRows)) ?></span>
                                    </button>
                                    <div class="entity-status-doc-group-body" data-status-doc-group-body>
                                        <?php if ($ddGroupRows === []): ?>
                                            <div class="entity-status-doc-empty">Nu exista documente in aceasta categorie.</div>
                                        <?php endif; ?>
                                        <?php foreach ($ddGroupRows as $ddRow): ?>
                                            <?php
                                            $ddDoc = is_array($ddRow['document']) ? $ddRow['document'] : null;
                                            $ddCustom = $ddDoc !== null && is_array($ddDoc['custom_field_display_rows'] ?? null) ? $ddDoc['custom_field_display_rows'] : [];
                                            ?>
                                            <div class="entity-status-doc-item <?= e((string) $ddRow['meta']['class']) ?>" data-status-doc-item>
                                                <button type="button" class="entity-status-doc-row" data-status-doc-row aria-expanded="false" aria-controls="<?= e($ddRow['id']) ?>-detail">
                                                    <span class="entity-status-doc-icon" aria-hidden="true"><i class="bi <?= e((string) $ddRow['meta']['icon']) ?>"></i></span>
                                                    <span class="entity-status-doc-name" title="<?= e((string) $ddRow['label']) ?>"><?= e((string) $ddRow['label']) ?></span>
                                                    <span class="entity-status-doc-state"><?= e((string) $ddRow['meta']['label']) ?></span>
                                                    <span class="entity-status-doc-date"><?= e((string) $ddRow['date_label']) ?></span>
                                                    <span class="entity-status-doc-chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                                                </button>
                                                <div class="entity-status-doc-detail" id="<?= e($ddRow['id']) ?>-detail" data-status-doc-detail hidden>
                                                    <div class="entity-status-doc-detail-grid">
                                                        <div><span>Stare</span><strong><?= e((string) $ddRow['meta']['label']) ?></strong></div>
                                                        <div><span>Data</span><strong><?= e((string) $ddRow['date_label']) ?></strong></div>
                                                        <?php if ($ddDoc !== null): ?>
                                                            <div><span>Serie / numar</span><strong><?= e((string) (($ddDoc['numar_document'] ?? '') !== '' ? $ddDoc['numar_document'] : '-')) ?></strong></div>
                                                            <div><span>Fisier</span><?= document_file_link_html((string) ($ddDoc['fisier_original'] ?? ''), (string) ($ddDoc['fisier_stocat'] ?? '')) ?></div>
                                                            <div><span>Actualizat la</span><strong><?= e(format_datetime_ro((string) ($ddDoc['updated_at'] ?? ''))) ?></strong></div>
                                                        <?php else: ?>
                                                            <div><span>Document</span><strong>Necesita incarcare</strong></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($ddCustom !== []): ?>
                                                        <div class="entity-status-doc-custom">
                                                            <?php foreach ($ddCustom as $ddCustomRow): ?>
                                                                <?php $ddType = strtolower(trim((string) ($ddCustomRow['type'] ?? 'text'))); $ddValue = $ddCustomValue($ddCustomRow); ?>
                                                                <?php if ($ddType === 'checkbox' && trim((string) ($ddCustomRow['value'] ?? '')) === '1'): ?>
                                                                    <span class="entity-status-doc-custom-chip"><?= e(trim((string) ($ddCustomRow['label'] ?? ''))) ?></span>
                                                                <?php elseif ($ddValue !== ''): ?>
                                                                    <span class="entity-status-doc-custom-pair"><span><?= e(trim((string) ($ddCustomRow['label'] ?? ''))) ?>:</span> <strong><?= e($ddValue) ?></strong></span>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="entity-status-doc-actions"><?= $ddDocActions($ddDoc) ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!$ddIsCollaborator && $ddIsActive): ?>
                        <div class="entity-status-doc-empty">Nu există tipuri de documente obligatorii configurate.</div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- 3. Documente -->
            <section class="dd-panel" id="dd-panel-documente" role="tabpanel" aria-labelledby="dd-tab-documente" data-dd-panel="documente" data-dd-docs>
                <header class="dd-panel-head">
                    <i class="bi bi-file-earmark-text dd-panel-icon" aria-hidden="true"></i>
                    <div><h3>Documente asociate șoferului</h3><p>Documentele încărcate pentru acest șofer, cu fișierele atașate.</p></div>
                    <div class="dd-panel-tools">
                        <?php if ($ddDocRows !== []): ?>
                            <label class="dd-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" placeholder="Caută document..." autocomplete="off" data-dd-doc-search aria-label="Caută în documentele șoferului">
                            </label>
                        <?php endif; ?>
                        <?php if ($ddCanDocCreate): ?>
                            <a class="dd-btn dd-btn-primary" href="<?= e($ddAddDocUrl) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>Adaugă document</span></a>
                        <?php endif; ?>
                    </div>
                </header>
                <?php if ($ddDocRows === []): ?>
                    <div class="dd-empty"><i class="bi bi-folder2-open" aria-hidden="true"></i><span>Nu există documente asociate acestui șofer.</span></div>
                <?php else: ?>
                    <div class="dd-doc-table" role="table" aria-label="Documente șofer">
                        <div class="dd-doc-row dd-doc-head" role="row">
                            <span role="columnheader">Tip document</span><span role="columnheader">Serie / număr</span><span role="columnheader">Fișier</span><span role="columnheader">Expirare</span><span role="columnheader" class="dd-right">Acțiuni</span>
                        </div>
                        <div class="dd-doc-body dd-scroll" data-dd-doc-list>
                            <?php foreach ($ddDocRows as $ddDocRow): ?>
                                <?php $ddDoc = $ddDocRow['document']; ?>
                                <div class="dd-doc-row" role="row" data-dd-doc-row data-search="<?= e($ddDocRow['search']) ?>">
                                    <span role="cell" class="dd-doc-type">
                                        <strong><?= e((string) ($ddDoc['tip_document'] ?? '-')) ?></strong>
                                        <?php if ($ddDocRow['custom_fields'] !== []): ?>
                                            <span class="dd-doc-custom">
                                                <?php foreach ($ddDocRow['custom_fields'] as $ddCustomRow): ?>
                                                    <?php $ddType = strtolower(trim((string) ($ddCustomRow['type'] ?? 'text'))); $ddValue = $ddCustomValue($ddCustomRow); ?>
                                                    <?php if ($ddType === 'checkbox' && trim((string) ($ddCustomRow['value'] ?? '')) === '1'): ?>
                                                        <em class="dd-tag"><?= e(trim((string) ($ddCustomRow['label'] ?? ''))) ?></em>
                                                    <?php elseif ($ddType !== 'checkbox' && $ddValue !== ''): ?>
                                                        <small><?= e(trim((string) ($ddCustomRow['label'] ?? ''))) ?>: <b><?= e($ddValue) ?></b></small>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <span role="cell"><?= e((string) (($ddDoc['numar_document'] ?? '') !== '' ? $ddDoc['numar_document'] : '-')) ?></span>
                                    <span role="cell" class="dd-doc-file"><?= document_file_link_html((string) ($ddDoc['fisier_original'] ?? ''), (string) ($ddDoc['fisier_stocat'] ?? '')) ?></span>
                                    <span role="cell"><?= expiry_badge_html($ddDocRow['expiry_date']) ?><?php if ($ddDocRow['expiry_label'] !== ''): ?><small class="dd-muted d-block"><?= e($ddDocRow['expiry_label']) ?></small><?php endif; ?></span>
                                    <span role="cell" class="dd-doc-actions"><?= $ddDocActions($ddDoc, true) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="dd-empty dd-empty-sm" data-dd-doc-noresults hidden><i class="bi bi-search" aria-hidden="true"></i><span>Niciun document nu corespunde căutării.</span></div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- 4. Vehicule alocate -->
            <section class="dd-panel" id="dd-panel-vehicule" role="tabpanel" aria-labelledby="dd-tab-vehicule" data-dd-panel="vehicule">
                <header class="dd-panel-head">
                    <i class="bi bi-truck dd-panel-icon" aria-hidden="true"></i>
                    <div><h3>Vehicule alocate</h3><p>Alocarea curentă (fără istoric). Se modifică din Editează șofer, cu aceleași reguli de eligibilitate.</p></div>
                    <?php if ($ddCanEdit): ?>
                        <div class="dd-panel-tools"><a class="dd-btn dd-btn-outline" href="<?= e($ddEditUrl) ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span>Modifică alocarea</span></a></div>
                    <?php endif; ?>
                </header>
                <?php if ($ddVehicles === []): ?>
                    <div class="dd-empty"><i class="bi bi-truck" aria-hidden="true"></i><span>Niciun vehicul alocat.</span></div>
                <?php else: ?>
                    <div class="dd-vcards dd-scroll">
                        <?php foreach ($ddVehicles as $ddIndex => $ddVehicle): ?>
                            <article class="dd-vcard<?= $ddIndex === 0 ? ' is-selected' : '' ?>" data-dd-vcard="<?= e((string) $ddVehicle['id']) ?>">
                                <div class="dd-vcard-head">
                                    <span class="dd-vcard-icon"><i class="bi <?= e($ddVehicleTypeIcon((string) $ddVehicle['type'])) ?>" aria-hidden="true"></i></span>
                                    <div class="dd-vcard-ident">
                                        <strong><?= e((string) $ddVehicle['plate']) ?></strong>
                                        <span><?= e($ddVehicle['make'] !== '' ? (string) $ddVehicle['make'] : '-') ?></span>
                                    </div>
                                    <span class="dd-role <?= $ddVehicle['is_primary'] ? 'is-primary' : '' ?>"><?= $ddVehicle['is_primary'] ? 'Principal' : 'Secundar' ?></span>
                                </div>
                                <dl class="dd-vcard-facts">
                                    <div><dt>Tip</dt><dd><?= e((string) $ddVehicle['type_label']) ?></dd></div>
                                    <div><dt>Formula axelor</dt><dd><?= e($ddVehicle['formula'] !== '' ? (string) $ddVehicle['formula'] : '-') ?></dd></div>
                                    <div><dt>An fabricație</dt><dd><?= ($ddVehicle['year'] ?? '') !== '' && $ddVehicle['year'] !== null ? e(format_year_ro($ddVehicle['year'])) : '-' ?></dd></div>
                                    <div><dt>Status vehicul</dt><dd><span class="dd-vstatus <?= $ddVehicle['status'] === 'activ' ? 'is-active' : 'is-inactive' ?>"><?= e($ddVehicleStatusLabel((string) $ddVehicle['status'])) ?></span></dd></div>
                                    <div class="dd-span-2"><dt><?= $ddVehicle['type'] === 'semiremorca' ? 'Cap tractor cuplat' : 'Semiremorcă cuplată' ?></dt><dd>
                                        <?php if ($ddVehicle['partner'] !== null): ?>
                                            <?php if ($ddCanVehicleView): ?><a href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $ddVehicle['partner']['id']])) ?>"><?= e((string) $ddVehicle['partner']['plate']) ?></a><?php else: ?><?= e((string) $ddVehicle['partner']['plate']) ?><?php endif; ?>
                                            <?php if ($ddVehicle['partner']['make'] !== ''): ?><span class="dd-muted"> · <?= e((string) $ddVehicle['partner']['make']) ?></span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="dd-muted"><?= in_array($ddVehicle['type'], ['cap_tractor', 'semiremorca'], true) ? 'Fără cuplaj activ' : 'Nu se aplică' ?></span>
                                        <?php endif; ?>
                                    </dd></div>
                                </dl>
                                <div class="dd-vcard-actions">
                                    <?php if ($ddVehicle['scheme_supported']): ?>
                                        <button type="button" class="dd-btn dd-btn-sm dd-btn-outline-primary" data-dd-select-vehicle="<?= e((string) $ddVehicle['id']) ?>"><i class="bi bi-diagram-3" aria-hidden="true"></i>Arată în schemă</button>
                                    <?php else: ?>
                                        <span class="dd-muted small">Schema nu este disponibilă pentru acest tip</span>
                                    <?php endif; ?>
                                    <?php if ($ddCanVehicleView): ?>
                                        <a class="dd-btn dd-btn-sm dd-btn-outline" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $ddVehicle['id']])) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Detalii vehicul</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- 5. Echipamente -->
            <section class="dd-panel" id="dd-panel-echipamente" role="tabpanel" aria-labelledby="dd-tab-echipamente" data-dd-panel="echipamente">
                <header class="dd-panel-head">
                    <i class="bi bi-person-badge dd-panel-icon" aria-hidden="true"></i>
                    <div><h3>Echipamente șofer</h3><p>Dotări alocate / emise către șofer. Evidență a bunurilor companiei aflate în posesia șoferului.</p></div>
                    <?php if (!empty($ddEquipment['allowed'])): ?>
                        <div class="dd-panel-tools">
                            <a class="dd-chip-link" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'driver_id' => $ddId])) ?>" title="Predarea, returnarea și înlocuirea se fac în modulul Echipamente șoferi"><i class="bi bi-building" aria-hidden="true"></i>Bunuri alocate companiei <i class="bi bi-info-circle" aria-hidden="true"></i></a>
                        </div>
                    <?php endif; ?>
                </header>
                <?php if (empty($ddEquipment['allowed'])): ?>
                    <div class="dd-empty"><i class="bi bi-lock" aria-hidden="true"></i><span>Nu ai acces la modulul Echipamente șoferi.</span></div>
                <?php elseif (!empty($ddEquipment['error'])): ?>
                    <div class="dd-empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Echipamentele nu au putut fi citite.</span></div>
                <?php else: ?>
                    <div class="dd-equip" data-dd-equip>
                        <div class="dd-avatar-card">
                            <div class="dd-avatar-head"><i class="bi bi-eye" aria-hidden="true"></i>Preview dotări șofer <i class="bi bi-info-circle dd-muted" title="Punctele arată categoriile de echipament aflate acum la șofer. Silueta nu înseamnă că un articol a fost predat." aria-hidden="true"></i></div>
                            <div class="dd-figure" data-dd-figure data-variant="m">
                                <img class="dd-figure-img is-active" data-variant="m" data-src="<?= e($img('sofer', 'm')) ?>" alt="Siluetă șofer — masculin" draggable="false">
                                <img class="dd-figure-img" data-variant="f" data-src="<?= e($img('sofer', 'f')) ?>" alt="Siluetă șofer — feminin" draggable="false">
                                <div class="dd-figure-dots" data-dd-dots>
                                    <?php foreach ($ddEquipZones as $ddZone): ?>
                                        <button type="button" class="dd-dot<?= $ddZone['attention'] ? ' is-attention' : '' ?>" data-dd-zone="<?= e($ddZone['zone']) ?>"
                                                data-pos-m="<?= e(implode(',', $silhouetteAnchors['sofer-m'][$ddZone['zone']] ?? [50, 50])) ?>"
                                                data-pos-f="<?= e(implode(',', $silhouetteAnchors['sofer-f'][$ddZone['zone']] ?? [50, 50])) ?>"
                                                title="<?= e(implode(', ', array_keys($ddZone['categories']))) ?> · <?= e((string) $ddZone['count']) ?> buc."
                                                aria-label="<?= e(implode(', ', array_keys($ddZone['categories']))) ?>: <?= e((string) $ddZone['count']) ?> bucăți">
                                            <i class="bi <?= e((string) $ddZone['icon']) ?>" aria-hidden="true"></i>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($ddEquipZones === []): ?>
                                    <div class="dd-figure-note">Niciun echipament în posesie</div>
                                <?php endif; ?>
                                <div class="dd-figure-floor" aria-hidden="true"></div>
                            </div>
                            <div class="dd-variant" role="group" aria-label="Siluetă">
                                <button type="button" class="is-active" data-dd-variant="m" aria-pressed="true" title="Masculin"><i class="bi bi-gender-male" aria-hidden="true"></i></button>
                                <button type="button" data-dd-variant="f" aria-pressed="false" title="Feminin"><i class="bi bi-gender-female" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <div class="dd-equip-main">
                            <div class="dd-equip-kpis">
                                <div class="dd-ekpi"><i class="bi bi-stack" aria-hidden="true"></i><span><small>Total echipamente</small><strong><?= e((string) $ddEquipKpis['total']) ?></strong></span></div>
                                <div class="dd-ekpi is-green"><i class="bi bi-check-square" aria-hidden="true"></i><span><small>Echipamente active<br>(în posesie)</small></span><strong><?= e((string) $ddEquipKpis['in_posesie']) ?></strong></div>
                                <div class="dd-ekpi is-red<?= (int) $ddEquipKpis['de_inlocuit'] === 0 ? ' is-zero' : '' ?>"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><small>De înlocuit / de returnat</small></span><strong><?= e((string) $ddEquipKpis['de_inlocuit']) ?></strong></div>
                            </div>
                            <div class="dd-banner"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Aceste echipamente reprezintă <b>dotări ale companiei</b>, alocate nominal șoferului <b><?= e($ddName) ?></b>.<?php if ((int) $ddEquipKpis['returnate'] > 0): ?> Returnate: <?= e((string) $ddEquipKpis['returnate']) ?>.<?php endif; ?></span></div>
                            <?php if ($ddEquipItems === []): ?>
                                <div class="dd-empty"><i class="bi bi-box-seam" aria-hidden="true"></i><span>Șoferul nu are echipamente alocate.</span></div>
                            <?php else: ?>
                                <div class="dd-equip-table" role="table" aria-label="Echipamente alocate">
                                    <div class="dd-equip-row dd-equip-head" role="row"><span role="columnheader">Echipament (dotare)</span><span role="columnheader">Status</span><span role="columnheader">Detalii</span><span></span></div>
                                    <div class="dd-equip-body dd-scroll" data-dd-equip-list>
                                        <?php foreach ($ddEquipItems as $ddItem): ?>
                                            <?php
                                            $ddTone = $ddEquipTone($ddItem);
                                            $ddDetails = [];
                                            if ($ddItem['marime'] !== '') { $ddDetails[] = 'Mărime: ' . $ddItem['marime']; }
                                            if ($ddItem['identificator'] !== '') { $ddDetails[] = 'Cod: ' . $ddItem['identificator']; }
                                            if ($ddItem['cantitate'] > 1) { $ddDetails[] = $ddItem['cantitate'] . ' buc.'; }
                                            if ($ddItem['data_returnarii'] !== '') { $ddDetails[] = 'Returnat: ' . format_date_ro($ddItem['data_returnarii']); }
                                            elseif ($ddItem['data_predarii'] !== '') { $ddDetails[] = 'Predat: ' . format_date_ro($ddItem['data_predarii']); }
                                            ?>
                                            <div class="dd-equip-item" data-dd-equip-item data-zone="<?= $ddItem['active'] ? e((string) $ddItem['anchor']) : '' ?>">
                                                <button type="button" class="dd-equip-row" role="row" data-dd-equip-row aria-expanded="false">
                                                    <span role="cell" class="dd-equip-name"><i class="bi <?= e((string) $ddItem['icon']) ?>" aria-hidden="true"></i><strong><?= e((string) $ddItem['denumire']) ?></strong></span>
                                                    <span role="cell"><span class="dd-estatus is-<?= e($ddTone) ?>"><i class="bi <?= $ddTone === 'success' ? 'bi-check-circle' : ($ddTone === 'muted' ? 'bi-arrow-return-left' : 'bi-exclamation-triangle') ?>" aria-hidden="true"></i><?= e($ddEquipStatusText($ddItem)) ?></span></span>
                                                    <span role="cell" class="dd-equip-details"><?= e($ddDetails !== [] ? implode('  |  ', $ddDetails) : '-') ?></span>
                                                    <span class="dd-equip-chev" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                                                </button>
                                                <div class="dd-equip-detail" hidden>
                                                    <dl>
                                                        <div><dt>Categorie</dt><dd><?= e((string) $ddItem['categorie']) ?><?= $ddItem['grupa'] !== '' ? ' · ' . e((string) $ddItem['grupa']) : '' ?></dd></div>
                                                        <div><dt>Status</dt><dd><?= e((string) $ddItem['status_label']) ?></dd></div>
                                                        <div><dt>Stare / termen</dt><dd><?= e($ddItem['condition_label'] !== '' ? (string) $ddItem['condition_label'] : '-') ?><?= $ddItem['condition_note'] !== '' ? ' <span class="dd-muted">(' . e((string) $ddItem['condition_note']) . ')</span>' : '' ?></dd></div>
                                                        <div><dt>Data predării</dt><dd><?= $ddItem['data_predarii'] !== '' ? e(format_date_ro($ddItem['data_predarii'])) : '-' ?></dd></div>
                                                        <?php if ($ddItem['data_returnarii'] !== ''): ?><div><dt>Data returnării</dt><dd><?= e(format_date_ro($ddItem['data_returnarii'])) ?></dd></div><?php endif; ?>
                                                        <?php if ($ddItem['termen'] !== ''): ?><div><dt>Termen</dt><dd><?= e(format_date_ro($ddItem['termen'])) ?></dd></div><?php endif; ?>
                                                    </dl>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- 6. Istoric și activitate -->
            <section class="dd-panel" id="dd-panel-activitate" role="tabpanel" aria-labelledby="dd-tab-activitate" data-dd-panel="activitate">
                <header class="dd-panel-head">
                    <i class="bi bi-clock-history dd-panel-icon" aria-hidden="true"></i>
                    <div><h3>Istoric și activitate</h3><p>Cursele înregistrate în Dispecer curse pentru acest șofer.</p></div>
                    <?php if (!empty($ddActivity['can_history'])): ?>
                        <div class="dd-panel-tools"><a class="dd-btn dd-btn-outline" href="<?= e(build_query_url(['page' => 'istoric_activitati_sofer', 'driver_id' => $ddId])) ?>"><i class="bi bi-graph-up" aria-hidden="true"></i><span>Istoric activități șofer</span></a></div>
                    <?php endif; ?>
                </header>
                <?php if (empty($ddActivity['allowed'])): ?>
                    <div class="dd-empty"><i class="bi bi-lock" aria-hidden="true"></i><span>Nu ai acces la cursele din Dispecer.</span></div>
                <?php elseif (!empty($ddActivity['error'])): ?>
                    <div class="dd-empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Activitatea nu a putut fi citită.</span></div>
                <?php else: ?>
                    <?php $ddStats = $ddActivity['stats']; ?>
                    <div class="dd-act-kpis">
                        <div class="dd-ekpi"><i class="bi bi-calendar-month" aria-hidden="true"></i><span><small>Curse în luna curentă</small><strong><?= e((string) $ddStats['luna']) ?></strong></span></div>
                        <div class="dd-ekpi"><i class="bi bi-signpost-split" aria-hidden="true"></i><span><small>Total curse înregistrate</small><strong><?= e((string) $ddStats['total']) ?></strong></span></div>
                        <div class="dd-ekpi"><i class="bi bi-calendar-check" aria-hidden="true"></i><span><small>Ultima cursă</small><strong><?= $ddStats['ultima'] !== '' ? e(format_date_ro($ddStats['ultima'])) : '-' ?></strong></span></div>
                        <div class="dd-ekpi<?= $ddStats['deschise'] > 0 ? ' is-blue' : '' ?>"><i class="bi bi-play-circle" aria-hidden="true"></i><span><small>Fără oră de sfârșit</small><strong><?= e((string) $ddStats['deschise']) ?></strong></span></div>
                    </div>
                    <?php if (($ddActivity['recent'] ?? []) === []): ?>
                        <div class="dd-empty"><i class="bi bi-signpost" aria-hidden="true"></i><span>Nu există curse înregistrate pentru acest șofer.</span></div>
                    <?php else: ?>
                        <div class="dd-act-table" role="table" aria-label="Ultimele curse">
                            <div class="dd-act-row dd-act-head" role="row"><span role="columnheader">Data</span><span role="columnheader">Interval</span><span role="columnheader">Vehicul</span><span role="columnheader">Tip transport</span><span role="columnheader" class="dd-right">Km cursă</span><span></span></div>
                            <div class="dd-act-body dd-scroll">
                                <?php foreach ($ddActivity['recent'] as $ddTrip): ?>
                                    <?php $ddOpen = ($ddTrip['ora_sfarsit'] ?? null) === null; ?>
                                    <div class="dd-act-row" role="row">
                                        <span role="cell"><strong><?= e(format_date_ro((string) $ddTrip['data_cursa'])) ?></strong></span>
                                        <span role="cell"><?= e(substr((string) ($ddTrip['ora_inceput'] ?? ''), 0, 5) ?: '-') ?> – <?= $ddOpen ? '<span class="dd-estatus is-blue">fără oră de sfârșit</span>' : e(substr((string) $ddTrip['ora_sfarsit'], 0, 5)) ?></span>
                                        <span role="cell"><?= e((string) ($ddTrip['nr_inmatriculare'] ?? '-')) ?></span>
                                        <span role="cell"><?= e($ddTransportLabel((string) ($ddTrip['tip_transport'] ?? ''))) ?></span>
                                        <span role="cell" class="dd-right"><?= $ddTrip['km_cursa'] !== null ? e(number_format((float) $ddTrip['km_cursa'], 0, ',', '.')) : '-' ?></span>
                                        <span role="cell" class="dd-right">
                                            <?php if (!empty($ddActivity['can_edit_trips'])): ?>
                                                <a class="dd-icon-link" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $ddTrip['id']])) ?>" title="Deschide cursa"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p class="dd-foot-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Ultimele <?= e((string) count($ddActivity['recent'])) ?> curse. Alocarea vehiculelor nu are istoric în aplicație, deci nu apare aici.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="dd-xray-dialog" data-dd-xray-dialog hidden role="dialog" aria-modal="true" aria-label="Schema vehiculului">
        <div class="dd-xray-dialog-backdrop" data-dd-xray-close></div>
        <div class="dd-xray-dialog-box">
            <div class="dd-xray-dialog-head">
                <strong><i class="bi bi-diagram-3" aria-hidden="true"></i> Configurație vehicul <span data-dd-xray-dialog-title></span></strong>
                <span class="dd-muted small">Aceeași schemă ca în Mentenanță → Auto</span>
                <button type="button" class="dd-btn dd-btn-ghost dd-btn-sm" data-dd-xray-close><i class="bi bi-x-lg" aria-hidden="true"></i>Închide</button>
            </div>
            <div class="dd-xray-dialog-body" data-dd-xray-dialog-body></div>
        </div>
    </div>
</div>

<?php if ($ddPrimary !== null): ?>
    <?php
    include __DIR__ . '/tire_stock/_fleet_icons.php';
    $ddVehiclesClient = array_map(static fn (array $v): array => [
        'id' => (int) $v['id'], 'plate' => (string) $v['plate'], 'make' => (string) $v['make'], 'type' => (string) $v['type'],
        'type_label' => (string) $v['type_label'], 'formula' => (string) $v['formula'], 'status' => (string) $v['status'],
        'status_label' => $ddVehicleStatusLabel((string) $v['status']), 'icon' => $ddVehicleTypeIcon((string) $v['type']),
        'is_primary' => (bool) $v['is_primary'], 'partner' => $v['partner'], 'scheme' => (bool) $v['scheme_supported'],
    ], $ddVehicles);
    ?>
    <script type="application/json" id="ddVehicleData"><?= json_encode([
        'vehicles' => $ddVehiclesClient,
        'unit' => $ddSchemeUnit,
        'unitVehicleId' => $ddSchemeUnit !== null ? (int) $ddPrimary['id'] : 0,
        'schemeUrl' => build_query_url(['page' => 'soferi', 'action' => 'driver_vehicle_scheme', 'id' => $ddId]),
        'scripts' => [
            $ddAsset('assets/js/tire-fleet.js'),
            $ddAsset('assets/js/datetime-picker-field.js'),
            $ddAsset('assets/js/vehicle-xray-engine.js'),
            $ddAsset('assets/js/vehicle-xray-traced-data.js'),
            $ddAsset('assets/js/vehicle-xray-art.js'),
            $ddAsset('assets/js/vehicle-xray.js'),
        ],
        'css' => $ddAsset('assets/css/vehicle-xray.css'),
        'unitIcons' => [
            'ansamblu' => $fleetVehicleIcon('ansamblu'),
            'cap_tractor' => $fleetVehicleIcon('cap_tractor'),
            'camion' => $fleetVehicleIcon('camion'),
            'semiremorca' => $fleetVehicleIcon('semiremorca'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php // Aceleași elemente ca în views/maintenance/_vehicle_explorer.php: vehicle-xray.js le citește la încărcare. ?>
    <script type="application/json" id="ftUnitsData"><?= json_encode($ddSchemeUnit !== null ? [$ddSchemeUnit] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <div hidden data-ft-config
         data-component-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_fleet_component'])) ?>"
         data-component-write-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'component_mount'])) ?>"
         data-can-manage="<?= can('mentenanta', 'components_manage') ? '1' : '0' ?>"
         data-csrf="<?= e(csrf_token()) ?>"></div>
    <script type="application/json" id="fxUnitIcons"><?= json_encode(['tractor' => $fleetTractorIcon, 'trailer' => $fleetTrailerIcon, 'assembly' => $fleetVehicleIcon('ansamblu')], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" id="fxCategories"><?= json_encode($ddScheme['categories'] ?? VehicleComponentRegistry::clientCategories(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>


<script src="<?= e($ddAsset('assets/js/driver-detail.js')) ?>"></script>
