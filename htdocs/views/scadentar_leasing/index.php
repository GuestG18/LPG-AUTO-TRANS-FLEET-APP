<?php
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$kpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$contracts = is_array($dashboard['contracts'] ?? null) ? $dashboard['contracts'] : [];
$filters = is_array($dashboard['filters'] ?? null) ? $dashboard['filters'] : [];
$filterOptions = is_array($dashboard['filterOptions'] ?? null) ? $dashboard['filterOptions'] : ['financiers' => []];
$vehicleOptions = is_array($dashboard['vehicleOptions'] ?? null) ? $dashboard['vehicleOptions'] : [];
$statusOptions = is_array($dashboard['statusOptions'] ?? null) ? $dashboard['statusOptions'] : LeasingSchedulerModel::STATUS_LABELS;
$frequencyOptions = is_array($dashboard['frequencyOptions'] ?? null) ? $dashboard['frequencyOptions'] : LeasingSchedulerModel::FREQUENCIES;
$documentTypes = is_array($dashboard['documentTypes'] ?? null) ? $dashboard['documentTypes'] : LeasingSchedulerModel::DOCUMENT_TYPES;
$selectedId = (int) ($filters['selected_id'] ?? 0);
if ($selectedId <= 0 && $contracts !== []) {
    $selectedId = (int) ($contracts[0]['id'] ?? 0);
}

$money = static function (mixed $value, string $currency = 'lei'): string {
    $amount = (float) ($value ?? 0);
    $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    return format_number_ro($amount, $decimals) . ' ' . $currency;
};
$date = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? format_date_ro((string) $value) : '-';
$show = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '-';
$statusClass = static fn(string $status): string => match ($status) {
    'restant' => 'is-danger',
    'in_asteptare' => 'is-warning',
    'la_zi', 'finalizat' => 'is-success',
    default => 'is-muted',
};
$installmentStatusLabel = static function (string $status, string $dueDate = ''): string {
    if ($status === 'paid') {
        return 'Achitat';
    }
    if ($status === 'partial') {
        return 'Partial';
    }
    if ($status === 'cancelled') {
        return 'Anulat';
    }
    if ($dueDate !== '' && $dueDate < date('Y-m-d')) {
        return 'Restant';
    }
    if ($dueDate !== '' && $dueDate <= date('Y-m-d', strtotime('+30 days'))) {
        return 'In asteptare';
    }

    return 'Viitoare';
};
$installmentStatusClass = static function (string $status, string $dueDate = ''): string {
    if ($status === 'paid') {
        return 'is-success';
    }
    if ($status === 'partial') {
        return 'is-warning';
    }
    if ($status === 'cancelled') {
        return 'is-muted';
    }
    if ($dueDate !== '' && $dueDate < date('Y-m-d')) {
        return 'is-danger';
    }
    if ($dueDate !== '' && $dueDate <= date('Y-m-d', strtotime('+30 days'))) {
        return 'is-warning';
    }

    return 'is-muted';
};
$installmentDelayInfo = static function (array $installment): array {
    $dueDate = trim((string) ($installment['due_date'] ?? ''));
    $status = (string) ($installment['status'] ?? 'unpaid');
    if ($dueDate === '') {
        return ['-', ''];
    }

    try {
        $targetDate = $status === 'paid' && !empty($installment['payment_date'])
            ? new DateTimeImmutable((string) $installment['payment_date'])
            : new DateTimeImmutable('today');
        $delayDays = (int) (new DateTimeImmutable($dueDate))->diff($targetDate)->format('%r%a');
    } catch (Throwable) {
        return ['-', ''];
    }

    if ($status !== 'paid' && $delayDays <= 0) {
        return ['-', ''];
    }

    $text = ($delayDays > 0 ? '+' : '') . $delayDays . ' zile';
    return [$text, $delayDays <= 0 ? 'is-success' : 'is-danger'];
};
$payButton = static function (array $installment, int $contractId): string {
    if ((string) ($installment['status'] ?? 'unpaid') === 'paid') {
        return '-';
    }

    return '<button type="button" class="leasing-mini-action" data-bs-toggle="modal" data-bs-target="#payLeasingInstallmentModal' . e((string) $contractId) . '" data-leasing-pay-installment data-installment-id="' . e((string) (int) ($installment['id'] ?? 0)) . '" data-installment-number="' . e((string) (int) ($installment['installment_number'] ?? 0)) . '" data-installment-amount="' . e((string) ($installment['amount'] ?? '')) . '" aria-label="Marcheaza rata ca platita"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></button>';
};
$daysText = static function (?int $days): array {
    if ($days === null) {
        return ['', ''];
    }
    if ($days < 0) {
        return ['(restanta)', 'is-danger'];
    }
    if ($days === 0) {
        return ['(azi)', 'is-warning'];
    }
    return ['(in ' . $days . ' zile)', $days <= 7 ? 'is-warning' : 'is-success'];
};
$recipientsText = static function (array $recipients): string {
    $emails = [];
    foreach ($recipients as $recipient) {
        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    return implode(', ', $emails);
};
$vehicleThumb = static function (array $contract): string {
    $url = vehicle_image_url((string) ($contract['poza_stocata'] ?? ''));
    if ($url !== null) {
        return '<span class="leasing-vehicle-thumb"><img src="' . e($url) . '" alt="' . e((string) ($contract['nr_inmatriculare'] ?? 'Vehicul')) . '" loading="lazy"></span>';
    }
    return '<span class="leasing-vehicle-thumb is-empty"><i class="bi bi-car-front-fill" aria-hidden="true"></i></span>';
};
$downloadDocumentUrl = static fn(array $document): string => build_query_url([
    'page' => 'scadentar_leasing',
    'action' => 'download_document',
    'document_id' => (int) ($document['id'] ?? 0),
]);

$contractForm = function (string $mode, ?array $contract = null) use ($vehicleOptions, $frequencyOptions, $documentTypes, $recipientsText): void {
    $isEdit = $mode === 'edit';
    $id = (int) ($contract['id'] ?? 0);
    $modalId = $isEdit ? 'editLeasingContractModal' . $id : 'addLeasingContractModal';
    $action = $isEdit ? 'update' : 'store';
    $title = $isEdit ? 'Editeaza contract leasing' : 'Adauga contract';
    $selectedVehicle = (int) ($contract['vehicle_id'] ?? 0);
    $notificationIntervals = is_array($contract['notification_intervals'] ?? null) ? $contract['notification_intervals'] : [7, 3, 1];
    $recipientValue = $isEdit ? $recipientsText((array) ($contract['recipients'] ?? [])) : '';
    ?>
    <div class="modal fade leasing-modal" id="<?= e($modalId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => $action])) ?>">
                    <?= csrf_field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h3 class="modal-title fs-5"><?= e($title) ?></h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Vehicul</label>
                                <select class="form-select" name="vehicle_id" required>
                                    <option value="">-- Selecteaza vehiculul --</option>
                                    <?php foreach ($vehicleOptions as $vehicle): ?>
                                        <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                        <option value="<?= e((string) $vehicleId) ?>" <?= $selectedVehicle === $vehicleId ? 'selected' : '' ?>>
                                            <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?> - <?= e(trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Finantator</label>
                                <input class="form-control" type="text" name="financier" value="<?= e((string) ($contract['financier'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Numar contract</label>
                                <input class="form-control" type="text" name="contract_number" value="<?= e((string) ($contract['contract_number'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data inceput</label>
                                <input class="form-control" type="date" name="start_date" value="<?= e((string) ($contract['start_date'] ?? date('Y-m-d'))) ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data final</label>
                                <input class="form-control" type="date" name="end_date" value="<?= e((string) ($contract['end_date'] ?? date('Y-m-d', strtotime('+48 months')))) ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Periodicitate</label>
                                <select class="form-select" name="frequency" required>
                                    <?php foreach ($frequencyOptions as $value => $label): ?>
                                        <option value="<?= e((string) $value) ?>" <?= (string) ($contract['frequency'] ?? 'monthly') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Zi scadenta</label>
                                <input class="form-control" type="number" min="1" max="31" name="due_day" value="<?= e((string) ($contract['due_day'] ?? 15)) ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Valoare initiala</label>
                                <input class="form-control" type="number" min="0" step="0.01" name="initial_value" value="<?= e((string) ($contract['initial_value'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Avans</label>
                                <input class="form-control" type="number" min="0" step="0.01" name="advance_amount" value="<?= e((string) ($contract['advance_amount'] ?? '0')) ?>">
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label">Nr. rate</label>
                                <input class="form-control" type="number" min="1" name="total_installments" value="<?= e((string) ($contract['total_installments'] ?? 48)) ?>" required>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label">Rata implicita</label>
                                <input class="form-control" type="number" min="0" step="0.01" name="default_installment_amount" value="<?= e((string) ($contract['default_installment_amount'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label">Moneda</label>
                                <input class="form-control" type="text" name="currency" value="<?= e((string) ($contract['currency'] ?? 'lei')) ?>" maxlength="12" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notificari email</label>
                                <div class="leasing-modal-inline">
                                    <label><input type="checkbox" name="notifications_enabled" value="1" <?= (int) ($contract['notifications_enabled'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label>
                                    <label><input type="checkbox" name="notification_intervals[]" value="7" <?= in_array(7, $notificationIntervals, true) ? 'checked' : '' ?>> 7 zile inainte</label>
                                    <label><input type="checkbox" name="notification_intervals[]" value="3" <?= in_array(3, $notificationIntervals, true) ? 'checked' : '' ?>> 3 zile inainte</label>
                                    <label><input type="checkbox" name="notification_intervals[]" value="1" <?= in_array(1, $notificationIntervals, true) ? 'checked' : '' ?>> 1 zi inainte</label>
                                </div>
                            </div>
                            <div class="col-12 col-lg-8">
                                <label class="form-label">Destinatari email</label>
                                <input class="form-control" type="text" name="recipients_text" value="<?= e($recipientValue) ?>" placeholder="financiar@companie.ro, manager@companie.ro">
                            </div>
                            <?php if (!$isEdit): ?>
                                <div class="col-12 col-lg-4">
                                    <label class="form-label">Document initial</label>
                                    <select class="form-select mb-2" name="document_type">
                                        <?php foreach ($documentTypes as $value => $label): ?>
                                            <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="form-control" type="file" name="contract_document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                                </div>
                            <?php else: ?>
                                <div class="col-12">
                                    <label class="leasing-check-row">
                                        <input type="checkbox" name="regenerate_schedule" value="1">
                                        Regenereaza ratele neplatite dupa noile valori
                                    </label>
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label">Observatii</label>
                                <textarea class="form-control" name="notes" rows="3"><?= e((string) ($contract['notes'] ?? '')) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunta</button>
                        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salveaza modificarile' : 'Adauga contract' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="leasing-page">
    <div class="leasing-page-header">
        <div class="leasing-title-wrap">
            <button class="leasing-menu-button" type="button" data-sidebar-toggle aria-label="Meniu" aria-expanded="false">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <div>
                <h1>Scaden&#539;ar Leasing Auto</h1>
                <p>Eviden&#539;&#259; contracte de leasing &#537;i scaden&#539;e rate</p>
            </div>
        </div>
        <div class="leasing-header-actions">
            <a class="leasing-action-button is-secondary" href="<?= e(build_query_url(array_merge(['page' => 'scadentar_leasing', 'action' => 'export'], $filters))) ?>">
                <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>
                <span>Export Excel</span>
            </a>
            <button class="leasing-action-button is-primary" type="button" data-bs-toggle="modal" data-bs-target="#addLeasingContractModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span>Adaug&#259; contract</span>
            </button>
        </div>
    </div>

    <div class="leasing-kpi-grid" aria-label="Indicatori leasing">
        <div class="leasing-kpi-card is-blue">
            <div>
                <span>Leasinguri active</span>
                <strong><?= e((string) (int) ($kpis['active_contracts'] ?? 0)) ?></strong>
                <small>contracte active</small>
            </div>
            <i class="bi bi-car-front" aria-hidden="true"></i>
        </div>
        <div class="leasing-kpi-card is-green">
            <div>
                <span>Total rate luna curent&#259;</span>
                <strong><?= e($money($kpis['current_month_total'] ?? 0)) ?></strong>
                <small><?= e((string) (int) ($kpis['current_month_count'] ?? 0)) ?> scaden&#539;e &#238;n <?= e((string) ($kpis['month_label'] ?? current_month_ro())) ?></small>
            </div>
            <i class="bi bi-wallet2" aria-hidden="true"></i>
        </div>
        <div class="leasing-kpi-card is-orange">
            <div>
                <span>Scadente &#238;n urm&#259;toarele 30 zile</span>
                <strong><?= e((string) (int) ($kpis['upcoming_count'] ?? 0)) ?></strong>
                <small>&#238;n valoare de <?= e($money($kpis['upcoming_total'] ?? 0)) ?></small>
            </div>
            <i class="bi bi-calendar3" aria-hidden="true"></i>
        </div>
        <div class="leasing-kpi-card is-red">
            <div>
                <span>Restan&#539;e</span>
                <strong><?= e((string) (int) ($kpis['overdue_count'] ?? 0)) ?></strong>
                <small>&#238;n valoare de <?= e($money($kpis['overdue_total'] ?? 0)) ?></small>
            </div>
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        </div>
    </div>

    <section class="leasing-contract-shell">
        <form class="leasing-filter-toolbar" method="get" action="<?= e(url('index.php')) ?>">
            <input type="hidden" name="page" value="scadentar_leasing">
            <select class="form-select" name="financier" aria-label="Finantator">
                <option value="">Toate finan&#539;&#259;rile</option>
                <?php foreach ((array) ($filterOptions['financiers'] ?? []) as $financier): ?>
                    <option value="<?= e((string) $financier) ?>" <?= (string) ($filters['financier'] ?? '') === (string) $financier ? 'selected' : '' ?>><?= e((string) $financier) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="status" aria-label="Status">
                <option value="">Toate statusurile</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= (string) ($filters['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="vehicle_id" aria-label="Vehicul">
                <option value="0">Toate vehiculele</option>
                <?php foreach ($vehicleOptions as $vehicle): ?>
                    <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                    <option value="<?= e((string) $vehicleId) ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === $vehicleId ? 'selected' : '' ?>><?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="leasing-date-filter">
                <input class="form-control" type="date" name="due_date" value="<?= e((string) ($filters['due_date'] ?? '')) ?>" aria-label="Data urm&#259;toarei scaden&#539;e">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
            </div>
            <div class="leasing-search-filter">
                <input class="form-control" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caut&#259;...">
                <i class="bi bi-search" aria-hidden="true"></i>
            </div>
            <a class="leasing-reset-button" href="<?= e(build_query_url(['page' => 'scadentar_leasing'])) ?>">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                <span>Reseteaz&#259; filtre</span>
            </a>
        </form>

        <div class="leasing-table-wrap">
            <table class="leasing-contract-table">
                <thead>
                    <tr>
                        <th>Vehicul</th>
                        <th>Finan&#539;ator</th>
                        <th>Contract</th>
                        <th>Perioad&#259;</th>
                        <th>Rat&#259; lunar&#259;</th>
                        <th>Urm&#259;toarea scaden&#539;&#259;</th>
                        <th>Status</th>
                        <th>Sold r&#259;mas</th>
                        <th>Ac&#539;iuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($contracts === []): ?>
                    <tr>
                        <td colspan="9">
                            <div class="leasing-empty-state">
                                <strong>Nu exist&#259; contracte de leasing &#238;nc&#259;.</strong>
                                <span>Adaug&#259; primul contract pentru a genera automat scaden&#539;arul de rate.</span>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($contracts as $contract): ?>
                    <?php
                    $contractId = (int) ($contract['id'] ?? 0);
                    $isSelected = $contractId === $selectedId;
                    [$dueHelp, $dueHelpClass] = $daysText(isset($contract['days_until_next_due']) ? (int) $contract['days_until_next_due'] : null);
                    $currency = (string) ($contract['currency'] ?? 'lei');
                    $installments = (array) ($contract['installments'] ?? []);
                    $previewInstallments = array_slice($installments, 0, 4);
                    $hiddenInstallments = array_slice($installments, 4);
                    $recipients = (array) ($contract['recipients'] ?? []);
                    $recipientLine = $recipientsText($recipients);
                    $progress = (float) ($contract['progress_percent'] ?? 0);
                    $progressDeg = max(0, min(360, $progress * 3.6));
                    $nextInstallmentId = (int) ($contract['next_installment_id'] ?? 0);
                    ?>
                    <tr class="leasing-contract-row <?= $isSelected ? 'is-selected' : '' ?>" data-leasing-contract-row data-contract-id="<?= e((string) $contractId) ?>">
                        <td class="leasing-vehicle-cell">
                            <button type="button" class="leasing-row-toggle" data-leasing-expand="<?= e((string) $contractId) ?>" aria-expanded="<?= $isSelected ? 'true' : 'false' ?>">
                                <i class="bi <?= $isSelected ? 'bi-chevron-down' : 'bi-chevron-right' ?>" aria-hidden="true"></i>
                            </button>
                            <?= $vehicleThumb($contract) ?>
                            <span>
                                <strong><?= e((string) ($contract['nr_inmatriculare'] ?? '-')) ?></strong>
                                <small><?= e(trim((string) ($contract['marca'] ?? '') . ' ' . (string) ($contract['model'] ?? ''))) ?></small>
                            </span>
                        </td>
                        <td><?= e((string) ($contract['financier'] ?? '-')) ?></td>
                        <td><?= e((string) ($contract['contract_number'] ?? '-')) ?></td>
                        <td>
                            <strong><?= e($date($contract['start_date'] ?? '')) ?> - <?= e($date($contract['end_date'] ?? '')) ?></strong>
                            <small><?= e((string) (int) ($contract['total_installments'] ?? 0)) ?> luni</small>
                        </td>
                        <td><strong><?= e($money($contract['default_installment_amount'] ?? 0, $currency)) ?></strong></td>
                        <td>
                            <strong><?= e($date($contract['next_due_date'] ?? '')) ?></strong>
                            <?php if ($dueHelp !== ''): ?><small class="<?= e($dueHelpClass) ?>"><?= e($dueHelp) ?></small><?php endif; ?>
                        </td>
                        <td><span class="leasing-status-badge <?= e($statusClass((string) ($contract['calculated_status'] ?? ''))) ?>"><?= e((string) ($contract['calculated_status_label'] ?? '-')) ?></span></td>
                        <td><strong><?= e($money($contract['remaining_balance'] ?? 0, $currency)) ?></strong></td>
                        <td>
                            <div class="dropdown leasing-actions">
                                <button class="leasing-icon-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actiuni">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" type="button" data-leasing-open-tab="<?= e((string) $contractId) ?>" data-tab="details">Vezi detalii</button></li>
                                    <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#editLeasingContractModal<?= e((string) $contractId) ?>">Editeaza contract</button></li>
                                    <li>
                                        <button class="dropdown-item" type="button" <?= $nextInstallmentId > 0 ? 'data-bs-toggle="modal" data-bs-target="#payLeasingInstallmentModal' . e((string) $contractId) . '"' : 'disabled' ?>>
                                            Marcheaza rata curenta ca achitata
                                        </button>
                                    </li>
                                    <li><button class="dropdown-item" type="button" data-leasing-open-tab="<?= e((string) $contractId) ?>" data-tab="documents">Vezi documente</button></li>
                                    <li><button class="dropdown-item" type="button" data-leasing-open-tab="<?= e((string) $contractId) ?>" data-tab="alerts">Setari notificari</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => 'close'])) ?>">
                                            <?= csrf_field() ?><input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                            <button class="dropdown-item" type="submit">Inchide contract</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => 'archive'])) ?>">
                                            <?= csrf_field() ?><input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                            <button class="dropdown-item text-danger" type="submit" data-confirm="Arhivezi contractul de leasing?">Arhiveaza</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <tr class="leasing-expanded-row <?= $isSelected ? '' : 'd-none' ?>" data-leasing-expanded-row data-contract-id="<?= e((string) $contractId) ?>">
                        <td colspan="9">
                            <div class="leasing-expanded-panel">
                                <div class="leasing-tabs" role="tablist">
                                    <button type="button" class="leasing-tab is-active" data-leasing-tab="<?= e((string) $contractId) ?>" data-tab="details">Detalii contract</button>
                                    <button type="button" class="leasing-tab" data-leasing-tab="<?= e((string) $contractId) ?>" data-tab="schedule">Scaden&#539;ar rate</button>
                                    <button type="button" class="leasing-tab" data-leasing-tab="<?= e((string) $contractId) ?>" data-tab="payments">Pl&#259;&#539;i efectuate</button>
                                    <button type="button" class="leasing-tab" data-leasing-tab="<?= e((string) $contractId) ?>" data-tab="documents">Documente</button>
                                    <button type="button" class="leasing-tab" data-leasing-tab="<?= e((string) $contractId) ?>" data-tab="alerts">Set&#259;ri alerte</button>
                                </div>

                                <div class="leasing-tab-panel" data-leasing-panel="<?= e((string) $contractId) ?>" data-panel="details">
                                    <div class="leasing-expanded-layout">
                                        <div class="leasing-details-card">
                                            <div class="leasing-details-grid">
                                                <dl>
                                                    <div><dt>Finan&#539;ator:</dt><dd><?= e((string) ($contract['financier'] ?? '-')) ?></dd></div>
                                                    <div><dt>Num&#259;r contract:</dt><dd><?= e((string) ($contract['contract_number'] ?? '-')) ?></dd></div>
                                                    <div><dt>Data &#238;nceput:</dt><dd><?= e($date($contract['start_date'] ?? '')) ?></dd></div>
                                                    <div><dt>Data final:</dt><dd><?= e($date($contract['end_date'] ?? '')) ?></dd></div>
                                                    <div><dt>Valoare ini&#539;ial&#259;:</dt><dd><?= e($money($contract['initial_value'] ?? 0, $currency)) ?></dd></div>
                                                    <div><dt>Avans:</dt><dd><?= e($money($contract['advance_amount'] ?? 0, $currency)) ?> (<?= e(format_number_ro((float) ($contract['advance_percent'] ?? 0), 1)) ?>%)</dd></div>
                                                    <div><dt>Num&#259;r total de rate:</dt><dd><?= e((string) (int) ($contract['total_installments'] ?? 0)) ?></dd></div>
                                                    <div><dt>Rat&#259; lunar&#259;:</dt><dd><?= e($money($contract['default_installment_amount'] ?? 0, $currency)) ?></dd></div>
                                                </dl>
                                                <dl>
                                                    <div><dt>Rate achitate:</dt><dd><?= e((string) (int) ($contract['paid_installments'] ?? 0)) ?></dd></div>
                                                    <div><dt>Rate r&#259;mase:</dt><dd><?= e((string) (int) ($contract['unpaid_installments'] ?? 0)) ?></dd></div>
                                                    <div><dt>Total achitat:</dt><dd><?= e($money($contract['total_paid'] ?? 0, $currency)) ?></dd></div>
                                                    <div><dt>Sold r&#259;mas:</dt><dd class="is-blue"><?= e($money($contract['remaining_balance'] ?? 0, $currency)) ?></dd></div>
                                                    <div><dt>Urm&#259;toarea scaden&#539;&#259;:</dt><dd class="is-orange"><?= e($date($contract['next_due_date'] ?? '')) ?></dd></div>
                                                    <div><dt>Valoare urm&#259;toare rat&#259;:</dt><dd><?= e($money($contract['next_installment_amount'] ?? 0, $currency)) ?></dd></div>
                                                    <div><dt>Periodicitate:</dt><dd><?= e((string) ($frequencyOptions[(string) ($contract['frequency'] ?? 'monthly')] ?? '-')) ?></dd></div>
                                                    <div><dt>Zi scaden&#539;&#259;:</dt><dd><?= e((string) (int) ($contract['due_day'] ?? 0)) ?></dd></div>
                                                </dl>
                                                <div class="leasing-progress-zone">
                                                    <div class="leasing-progress-ring" style="--leasing-progress-deg: <?= e((string) $progressDeg) ?>deg;">
                                                        <strong><?= e(format_number_ro($progress, 1)) ?>%</strong>
                                                        <span>achitat</span>
                                                    </div>
                                                    <small>Progres contract</small>
                                                </div>
                                            </div>
                                            <div class="leasing-installments-block">
                                                <h3>Scaden&#539;ar rate</h3>
                                                <table class="leasing-installment-table">
                                                    <thead>
                                                        <tr><th>Nr. rat&#259;</th><th>Scaden&#539;&#259;</th><th>Valoare</th><th>Status</th><th>Data pl&#259;&#539;ii</th><th>Zile &#238;nt&#226;rziere</th><th>Ac&#539;iuni</th></tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($previewInstallments as $installment): ?>
                                                        <?php
                                                        $iStatus = (string) ($installment['status'] ?? 'unpaid');
                                                        [$delay, $delayClass] = $installmentDelayInfo($installment);
                                                        ?>
                                                        <tr>
                                                            <td><?= e((string) (int) ($installment['installment_number'] ?? 0)) ?></td>
                                                            <td><?= e($date($installment['due_date'] ?? '')) ?></td>
                                                            <td><?= e($money($installment['amount'] ?? 0, (string) ($installment['currency'] ?? $currency))) ?></td>
                                                            <td><span class="leasing-status-badge <?= e($installmentStatusClass($iStatus, (string) ($installment['due_date'] ?? ''))) ?>"><?= e($installmentStatusLabel($iStatus, (string) ($installment['due_date'] ?? ''))) ?></span></td>
                                                            <td><?= e($date($installment['payment_date'] ?? '')) ?></td>
                                                            <td class="<?= e($delayClass) ?>"><?= e($delay) ?></td>
                                                            <td><?= $payButton($installment, $contractId) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                <?php if (count($installments) > 4): ?>
                                                    <button type="button" class="leasing-view-all-rates" data-leasing-open-tab="<?= e((string) $contractId) ?>" data-tab="schedule">
                                                        Vezi toate ratele (<?= e((string) count($installments)) ?>) <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <form method="post" class="leasing-alert-panel" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => 'update_notifications'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                            <div class="leasing-alert-title">
                                                <strong>Notific&#259;ri email</strong>
                                                <span class="leasing-alert-state <?= (int) ($contract['notifications_enabled'] ?? 1) === 1 ? 'is-active' : 'is-inactive' ?>"><?= (int) ($contract['notifications_enabled'] ?? 1) === 1 ? 'ACTIVE' : 'INACTIVE' ?></span>
                                            </div>
                                            <span>Trimite alert&#259; cu:</span>
                                            <label><input type="checkbox" name="notification_intervals[]" value="7" <?= in_array(7, (array) ($contract['notification_intervals'] ?? []), true) ? 'checked' : '' ?>> 7 zile &#238;nainte</label>
                                            <label><input type="checkbox" name="notification_intervals[]" value="3" <?= in_array(3, (array) ($contract['notification_intervals'] ?? []), true) ? 'checked' : '' ?>> 3 zile &#238;nainte</label>
                                            <label><input type="checkbox" name="notification_intervals[]" value="1" <?= in_array(1, (array) ($contract['notification_intervals'] ?? []), true) ? 'checked' : '' ?>> 1 zi &#238;nainte</label>
                                            <input type="hidden" name="notifications_enabled" value="<?= (int) ($contract['notifications_enabled'] ?? 1) === 1 ? '1' : '0' ?>">
                                            <span>Destinatari:</span>
                                            <div class="leasing-recipient-field">
                                                <?php if ($recipientLine === ''): ?>
                                                    <span class="leasing-recipient-placeholder">financiar@companie.ro</span>
                                                <?php else: ?>
                                                    <?php foreach ($recipients as $recipient): ?>
                                                        <span class="leasing-recipient-chip"><?= e((string) ($recipient['email'] ?? '')) ?> ×</span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <input type="text" name="recipients_text" value="<?= e($recipientLine) ?>" aria-label="Destinatari email">
                                            </div>
                                            <button class="leasing-settings-button" type="submit"><i class="bi bi-gear" aria-hidden="true"></i> Editeaz&#259; set&#259;ri</button>
                                        </form>
                                    </div>
                                </div>

                                <div class="leasing-tab-panel d-none" data-leasing-panel="<?= e((string) $contractId) ?>" data-panel="schedule">
                                    <table class="leasing-installment-table is-full">
                                        <thead>
                                            <tr><th>Nr. rat&#259;</th><th>Scaden&#539;&#259;</th><th>Valoare</th><th>Status</th><th>Data pl&#259;&#539;ii</th><th>Zile &#238;nt&#226;rziere</th><th>Ac&#539;iuni</th></tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($installments as $installment): ?>
                                            <?php
                                            $iStatus = (string) ($installment['status'] ?? 'unpaid');
                                            [$delay, $delayClass] = $installmentDelayInfo($installment);
                                            ?>
                                            <tr>
                                                <td><?= e((string) (int) ($installment['installment_number'] ?? 0)) ?></td>
                                                <td><?= e($date($installment['due_date'] ?? '')) ?></td>
                                                <td><?= e($money($installment['amount'] ?? 0, (string) ($installment['currency'] ?? $currency))) ?></td>
                                                <td><span class="leasing-status-badge <?= e($installmentStatusClass($iStatus, (string) ($installment['due_date'] ?? ''))) ?>"><?= e($installmentStatusLabel($iStatus, (string) ($installment['due_date'] ?? ''))) ?></span></td>
                                                <td><?= e($date($installment['payment_date'] ?? '')) ?></td>
                                                <td class="<?= e($delayClass) ?>"><?= e($delay) ?></td>
                                                <td><?= $payButton($installment, $contractId) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="leasing-tab-panel d-none" data-leasing-panel="<?= e((string) $contractId) ?>" data-panel="payments">
                                    <table class="leasing-installment-table is-full">
                                        <thead><tr><th>Rat&#259;</th><th>Scaden&#539;&#259;</th><th>Data plat&#259;</th><th>Valoare</th><th>Zile &#238;nt&#226;rziere</th><th>Document</th><th>&#206;nregistrat de</th><th>Creat la</th></tr></thead>
                                        <tbody>
                                        <?php foreach ((array) ($contract['payment_history'] ?? []) as $payment): ?>
                                            <?php
                                            $delayDays = isset($payment['delay_days']) ? (int) $payment['delay_days'] : null;
                                            $delayText = $delayDays === null ? '-' : (($delayDays > 0 ? '+' : '') . $delayDays . ' zile');
                                            $delayClass = $delayDays !== null && $delayDays <= 0 ? 'is-success' : ($delayDays !== null ? 'is-danger' : '');
                                            ?>
                                            <tr>
                                                <td>#<?= e((string) (int) ($payment['installment_number'] ?? 0)) ?></td>
                                                <td><?= e($date($payment['due_date'] ?? '')) ?></td>
                                                <td><?= e($date($payment['payment_date'] ?? '')) ?></td>
                                                <td><?= e($money($payment['amount_paid'] ?? 0, $currency)) ?></td>
                                                <td class="<?= e($delayClass) ?>"><?= e($delayText) ?></td>
                                                <td><?= trim((string) ($payment['proof_original'] ?? '')) !== '' ? e((string) $payment['proof_original']) : '-' ?></td>
                                                <td><?= e($show($payment['registered_by_name'] ?? '')) ?></td>
                                                <td><?= e(format_datetime_ro((string) ($payment['created_at'] ?? ''))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($contract['payment_history'])): ?><tr><td colspan="8">Nu exista plati inregistrate.</td></tr><?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="leasing-tab-panel d-none" data-leasing-panel="<?= e((string) $contractId) ?>" data-panel="documents">
                                    <div class="leasing-documents-grid">
                                        <div>
                                            <table class="leasing-installment-table is-full">
                                                <thead><tr><th>Tip</th><th>Fisier</th><th>Incarcat la</th><th>Actiuni</th></tr></thead>
                                                <tbody>
                                                <?php foreach ((array) ($contract['documents'] ?? []) as $document): ?>
                                                    <tr>
                                                        <td><?= e((string) ($documentTypes[(string) ($document['document_type'] ?? '')] ?? $document['document_type'] ?? '-')) ?></td>
                                                        <td><?= e($show($document['original_name'] ?? '')) ?></td>
                                                        <td><?= e(format_datetime_ro((string) ($document['created_at'] ?? ''))) ?></td>
                                                <td><a class="leasing-mini-action" href="<?= e($downloadDocumentUrl($document)) ?>"><i class="bi bi-download" aria-hidden="true"></i></a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($contract['documents'])): ?><tr><td colspan="4">Nu exista documente atasate.</td></tr><?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => 'upload_document'])) ?>" class="leasing-document-upload">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                            <label>Tip document</label>
                                            <select class="form-select" name="document_type">
                                                <?php foreach ($documentTypes as $value => $label): ?>
                                                    <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label>Fi&#537;ier</label>
                                            <input class="form-control" type="file" name="document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" required>
                                            <label>Observa&#539;ii</label>
                                            <textarea class="form-control" name="notes" rows="2"></textarea>
                                            <button class="leasing-settings-button" type="submit"><i class="bi bi-upload" aria-hidden="true"></i> &#206;ncarc&#259; document</button>
                                        </form>
                                    </div>
                                </div>

                                <div class="leasing-tab-panel d-none" data-leasing-panel="<?= e((string) $contractId) ?>" data-panel="alerts">
                                    <form method="post" class="leasing-alert-settings-wide" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => 'update_notifications'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                        <label class="leasing-check-row"><input type="checkbox" name="notifications_enabled" value="1" <?= (int) ($contract['notifications_enabled'] ?? 1) === 1 ? 'checked' : '' ?>> Notific&#259;ri active</label>
                                        <label class="leasing-check-row"><input type="checkbox" name="notification_intervals[]" value="7" <?= in_array(7, (array) ($contract['notification_intervals'] ?? []), true) ? 'checked' : '' ?>> 7 zile &#238;nainte</label>
                                        <label class="leasing-check-row"><input type="checkbox" name="notification_intervals[]" value="3" <?= in_array(3, (array) ($contract['notification_intervals'] ?? []), true) ? 'checked' : '' ?>> 3 zile &#238;nainte</label>
                                        <label class="leasing-check-row"><input type="checkbox" name="notification_intervals[]" value="1" <?= in_array(1, (array) ($contract['notification_intervals'] ?? []), true) ? 'checked' : '' ?>> 1 zi &#238;nainte</label>
                                        <label>Destinatari email</label>
                                        <input class="form-control" type="text" name="recipients_text" value="<?= e($recipientLine) ?>">
                                        <button class="leasing-settings-button" type="submit"><i class="bi bi-gear" aria-hidden="true"></i> Salveaz&#259; notific&#259;ri</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php foreach ($contracts as $contract): ?>
    <?php
    $contractId = (int) ($contract['id'] ?? 0);
    $nextInstallmentId = (int) ($contract['next_installment_id'] ?? 0);
    ?>
    <?php $contractForm('edit', $contract); ?>
    <div class="modal fade leasing-modal" id="payLeasingInstallmentModal<?= e((string) $contractId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'scadentar_leasing', 'action' => 'mark_paid'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="installment_id" value="<?= e((string) $nextInstallmentId) ?>">
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" data-leasing-pay-title>Marcheaz&#259; rata ca pl&#259;tit&#259;</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Data pl&#259;&#539;ii</label>
                                <input class="form-control" type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Suma pl&#259;tit&#259;</label>
                                <input class="form-control" type="number" min="0" step="0.01" name="amount_paid" value="<?= e((string) ($contract['next_installment_amount'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Dovad&#259; plat&#259;</label>
                                <input class="form-control" type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nota</label>
                                <textarea class="form-control" name="notes" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunta</button>
                        <button type="submit" class="btn btn-primary" <?= $nextInstallmentId <= 0 ? 'disabled' : '' ?>>Confirm&#259; plata</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php $contractForm('create'); ?>

<script src="<?= e(url('assets/js/scadentar-leasing.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/scadentar-leasing.js'))) ?>"></script>
