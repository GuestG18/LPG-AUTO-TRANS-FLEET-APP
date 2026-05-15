<?php
$tabBaseQuery = [
    'page' => 'programare_concedii',
    'calendar_view' => $calendarView,
    'calendar_month' => $calendarMonthValue,
    'focus_date' => $calendarFocusDate,
    'driver_q' => $driverSearch,
    'table_q' => $tableSearch,
    'status_filter' => $statusFilter,
    'tip_filter' => $tipFilter,
];

$currentListBaseQuery = array_merge($tabBaseQuery, ['tab' => $activeTab]);
$statusBadgeClassMap = [
    'aprobat' => 'leave-status-badge leave-status-aprobat',
    'respins' => 'leave-status-badge leave-status-respins',
    'in_asteptare' => 'leave-status-badge leave-status-asteptare',
    'in_asteptare_aprobare' => 'leave-status-badge leave-status-asteptare-aprobare',
];

$calendarTitleRo = '';
if (is_string($calendarMonthStart) && $calendarMonthStart !== '') {
    try {
        $calendarTitleRo = format_date_ro($calendarMonthStart);
    } catch (Throwable) {
        $calendarTitleRo = $calendarMonthStart;
    }
}
if ($calendarTitleRo === '') {
    $calendarTitleRo = current_month_ro();
}
?>

<div class="leave-page">
    <div class="leave-page-shell card border-0 shadow-sm">
        <div class="leave-page-header">
            <div class="leave-page-header-main">
                <div>
                    <h2 class="leave-page-title">Programare Concedii</h2>
                    <p class="leave-page-subtitle mb-0">Gestionează și programează concediile șoferilor din flotă.</p>
                </div>

                <div class="leave-page-actions">
                    <form method="get" class="leave-inline-form leave-top-search-form">
                        <input type="hidden" name="page" value="programare_concedii">
                        <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                        <input type="hidden" name="calendar_view" value="<?= e($calendarView) ?>">
                        <input type="hidden" name="calendar_month" value="<?= e($calendarMonthValue) ?>">
                        <input type="hidden" name="focus_date" value="<?= e($calendarFocusDate) ?>">
                        <input type="hidden" name="table_q" value="<?= e($tableSearch) ?>">
                        <input type="hidden" name="status_filter" value="<?= e($statusFilter) ?>">
                        <input type="hidden" name="tip_filter" value="<?= e($tipFilter) ?>">
                        <div class="leave-search-input-wrap">
                            <i class="bi bi-search"></i>
                            <input
                                type="text"
                                class="form-control"
                                name="driver_q"
                                value="<?= e($driverSearch) ?>"
                                placeholder="Caută șofer..."
                                aria-label="Caută șofer"
                            >
                        </div>
                    </form>

                    <a href="#leave-request-form-card" class="btn btn-primary leave-new-button">
                        <i class="bi bi-plus-lg"></i>
                        <span>Concediu nou</span>
                    </a>
                </div>
            </div>

            <div class="leave-tab-row">
                <nav class="leave-tabs" aria-label="Navigare Programare concedii">
                    <a
                        class="leave-tab-link <?= $activeTab === 'calendar' ? 'active' : '' ?>"
                        href="<?= e(build_query_url(array_merge($tabBaseQuery, ['tab' => 'calendar', 'p' => 1]))) ?>"
                    >
                        Calendar
                    </a>
                    <a
                        class="leave-tab-link <?= $activeTab === 'cereri' ? 'active' : '' ?>"
                        href="<?= e(build_query_url(array_merge($tabBaseQuery, ['tab' => 'cereri', 'p' => 1]))) ?>"
                    >
                        Cereri
                    </a>
                    <a
                        class="leave-tab-link <?= $activeTab === 'aprobari' ? 'active' : '' ?>"
                        href="<?= e(build_query_url(array_merge($tabBaseQuery, ['tab' => 'aprobari', 'p' => 1]))) ?>"
                    >
                        Aprobările mele
                    </a>
                </nav>

                <form method="get" id="leave-calendar-controls" class="leave-inline-form leave-calendar-controls">
                    <input type="hidden" name="page" value="programare_concedii">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                    <input type="hidden" name="driver_q" value="<?= e($driverSearch) ?>">
                    <input type="hidden" name="table_q" value="<?= e($tableSearch) ?>">
                    <input type="hidden" name="status_filter" value="<?= e($statusFilter) ?>">
                    <input type="hidden" name="tip_filter" value="<?= e($tipFilter) ?>">
                    <input type="hidden" name="focus_date" value="<?= e($calendarFocusDate) ?>" data-role="focus-date">

                    <button type="button" class="btn btn-outline-secondary leave-month-nav-btn" data-role="month-prev" aria-label="Luna anterioară">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <input type="month" class="form-control leave-month-input" name="calendar_month" value="<?= e($calendarMonthValue) ?>" data-role="month-input">
                    <button type="button" class="btn btn-outline-secondary leave-month-nav-btn" data-role="month-next" aria-label="Luna următoare">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary leave-today-btn" data-role="calendar-today">Astăzi</button>
                    <select class="form-select leave-view-select" name="calendar_view" data-role="view-select">
                        <?php foreach ($vizualizariCalendar as $viewKey => $viewLabel): ?>
                            <option value="<?= e((string) $viewKey) ?>" <?= $calendarView === (string) $viewKey ? 'selected' : '' ?>>
                                <?= e((string) $viewLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <div class="leave-page-body">
            <div class="leave-main-grid">
                <section class="leave-panel leave-calendar-panel">
                    <div class="leave-panel-header">
                        <h3 class="leave-panel-title mb-0"><?= e(current_month_ro()) ?></h3>
                        <span class="leave-calendar-title-hint"><?= e($calendarTitleRo) ?></span>
                    </div>
                    <div class="leave-calendar-body">
                        <div class="leave-calendar-skeleton" data-role="calendar-skeleton">
                            <div class="leave-skeleton-line"></div>
                            <div class="leave-skeleton-grid"></div>
                        </div>
                        <div
                            id="leave-calendar-root"
                            class="leave-calendar-root d-none"
                            data-events='<?= e($calendarEventsJson) ?>'
                            data-focus-date="<?= e($calendarFocusDate) ?>"
                            data-month="<?= e($calendarMonthValue) ?>"
                            data-view="<?= e($calendarView) ?>"
                        ></div>
                        <div class="leave-calendar-empty d-none" data-role="calendar-empty">
                            Nu există concedii în perioada selectată.
                        </div>
                        <div class="leave-calendar-error d-none" data-role="calendar-error">
                            Nu am putut încărca calendarul. Reîncarcă pagina.
                        </div>
                    </div>
                </section>

                <section class="leave-panel leave-form-panel" id="leave-request-form-card">
                    <div class="leave-panel-header">
                        <h3 class="leave-panel-title mb-0">Formular cerere concediu</h3>
                    </div>
                    <div class="leave-form-body">
                        <form
                            method="post"
                            action="<?= e(build_query_url([
                                'page' => 'programare_concedii',
                                'action' => $isEditing ? 'update' : 'store',
                            ])) ?>"
                            id="leave-request-form"
                            novalidate
                        >
                            <?= csrf_field() ?>
                            <?php if ($isEditing): ?>
                                <input type="hidden" name="id" value="<?= e((string) $editingId) ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label" for="leave_driver_id">Șofer <span class="text-danger">*</span></label>
                                <input
                                    type="search"
                                    class="form-control leave-select-search mb-2"
                                    data-role="select-search"
                                    data-target="leave_driver_id"
                                    placeholder="Caută în listă..."
                                    aria-label="Caută șofer în listă"
                                >
                                <select class="form-select <?= isset($formErrors['driver_id']) ? 'is-invalid' : '' ?>" id="leave_driver_id" name="driver_id" required>
                                    <option value="">Selectează șofer</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <?php
                                        $driverId = (int) ($driver['id'] ?? 0);
                                        $driverVehicle = trim((string) ($driver['nr_inmatriculare'] ?? ''));
                                        $driverLabel = trim((string) ($driver['nume'] ?? ''));
                                        if ($driverVehicle !== '') {
                                            $driverLabel .= ' - ' . $driverVehicle;
                                        }
                                        $driverUnavailable = (int) ($driver['indisponibil_astazi'] ?? 0) === 1;
                                        ?>
                                        <option
                                            value="<?= e((string) $driverId) ?>"
                                            data-unavailable-today="<?= $driverUnavailable ? '1' : '0' ?>"
                                            <?= (string) ($formData['driver_id'] ?? '') === (string) $driverId ? 'selected' : '' ?>
                                        >
                                            <?= e($driverLabel) ?><?= $driverUnavailable ? ' (în concediu azi)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($formErrors['driver_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['driver_id']) ?></div><?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="leave_tip_concediu">Tip concediu <span class="text-danger">*</span></label>
                                <select class="form-select <?= isset($formErrors['tip_concediu']) ? 'is-invalid' : '' ?>" id="leave_tip_concediu" name="tip_concediu" required>
                                    <?php foreach ($tipuriConcediu as $tipKey => $tipLabel): ?>
                                        <option value="<?= e((string) $tipKey) ?>" <?= (string) ($formData['tip_concediu'] ?? '') === (string) $tipKey ? 'selected' : '' ?>>
                                            <?= e((string) $tipLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($formErrors['tip_concediu'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tip_concediu']) ?></div><?php endif; ?>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="leave_data_inceput">Data început <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control <?= isset($formErrors['data_inceput']) ? 'is-invalid' : '' ?>" id="leave_data_inceput" name="data_inceput" value="<?= e((string) ($formData['data_inceput'] ?? '')) ?>" required>
                                    <?php if (isset($formErrors['data_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_inceput']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="leave_data_sfarsit">Data sfârșit <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control <?= isset($formErrors['data_sfarsit']) ? 'is-invalid' : '' ?>" id="leave_data_sfarsit" name="data_sfarsit" value="<?= e((string) ($formData['data_sfarsit'] ?? '')) ?>" required>
                                    <?php if (isset($formErrors['data_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_sfarsit']) ?></div><?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-3 mb-3">
                                <label class="form-label" for="leave_inlocuitor_id">Înlocuitor (optional)</label>
                                <input
                                    type="search"
                                    class="form-control leave-select-search mb-2"
                                    data-role="select-search"
                                    data-target="leave_inlocuitor_id"
                                    placeholder="Caută în listă..."
                                    aria-label="Caută înlocuitor"
                                >
                                <select class="form-select <?= isset($formErrors['inlocuitor_id']) ? 'is-invalid' : '' ?>" id="leave_inlocuitor_id" name="inlocuitor_id">
                                    <option value="">Selectează înlocuitor</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <?php
                                        $driverId = (int) ($driver['id'] ?? 0);
                                        $driverVehicle = trim((string) ($driver['nr_inmatriculare'] ?? ''));
                                        $driverLabel = trim((string) ($driver['nume'] ?? ''));
                                        if ($driverVehicle !== '') {
                                            $driverLabel .= ' - ' . $driverVehicle;
                                        }
                                        ?>
                                        <option value="<?= e((string) $driverId) ?>" <?= (string) ($formData['inlocuitor_id'] ?? '') === (string) $driverId ? 'selected' : '' ?>>
                                            <?= e($driverLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($formErrors['inlocuitor_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['inlocuitor_id']) ?></div><?php endif; ?>
                            </div>

                            <?php if ($isEditing): ?>
                                <div class="mb-3">
                                    <label class="form-label" for="leave_status">Status</label>
                                    <select class="form-select" id="leave_status" name="status">
                                        <?php foreach ($statusuri as $statusKey => $statusLabel): ?>
                                            <option value="<?= e((string) $statusKey) ?>" <?= (string) ($formData['status'] ?? '') === (string) $statusKey ? 'selected' : '' ?>>
                                                <?= e((string) $statusLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label" for="leave_note">Note (optional)</label>
                                <textarea class="form-control <?= isset($formErrors['note']) ? 'is-invalid' : '' ?>" id="leave_note" name="note" rows="3" placeholder="Adaugă note..."><?= e((string) ($formData['note'] ?? '')) ?></textarea>
                                <?php if (isset($formErrors['note'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['note']) ?></div><?php endif; ?>
                            </div>

                            <div class="leave-form-actions">
                                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'programare_concedii', 'tab' => 'calendar'])) ?>">Anulează</a>
                                <button type="submit" class="btn btn-primary">
                                    <?= $isEditing ? 'Actualizează cererea' : 'Trimite cererea' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <aside class="leave-sidebar-panel">
                    <section class="leave-panel leave-summary-panel">
                        <div class="leave-panel-header">
                            <h3 class="leave-panel-title mb-0">Rezumat concedii</h3>
                        </div>
                        <div class="leave-summary-cards">
                            <article class="leave-summary-card leave-card-blue">
                                <div class="leave-summary-icon"><i class="bi bi-people-fill"></i></div>
                                <div>
                                    <div class="leave-summary-title">Șoferi disponibili</div>
                                    <div class="leave-summary-value"><?= e((string) ($stats['available_drivers'] ?? 0)) ?></div>
                                    <div class="leave-summary-subtitle"><?= e((string) ($stats['available_percentage'] ?? 0)) ?>% din total</div>
                                </div>
                            </article>
                            <article class="leave-summary-card leave-card-amber">
                                <div class="leave-summary-icon"><i class="bi bi-person-dash-fill"></i></div>
                                <div>
                                    <div class="leave-summary-title">În concediu</div>
                                    <div class="leave-summary-value"><?= e((string) ($stats['on_leave'] ?? 0)) ?></div>
                                    <div class="leave-summary-subtitle">Azi</div>
                                </div>
                            </article>
                            <article class="leave-summary-card leave-card-violet">
                                <div class="leave-summary-icon"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="leave-summary-title">Cereri în așteptare</div>
                                    <div class="leave-summary-value"><?= e((string) ($stats['pending'] ?? 0)) ?></div>
                                    <div class="leave-summary-subtitle">Necesită aprobare</div>
                                </div>
                            </article>
                            <article class="leave-summary-card leave-card-red">
                                <div class="leave-summary-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                                <div>
                                    <div class="leave-summary-title">Suprapuneri / Conflicte</div>
                                    <div class="leave-summary-value"><?= e((string) ($stats['conflicts'] ?? 0)) ?></div>
                                    <div class="leave-summary-subtitle">Necesită atenție</div>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="leave-panel leave-legend-panel">
                        <div class="leave-panel-header">
                            <h3 class="leave-panel-title mb-0">Legendă calendar</h3>
                        </div>
                        <ul class="leave-legend-list">
                            <li><span class="leave-legend-dot leave-type-odihna"></span>Concediu de odihnă</li>
                            <li><span class="leave-legend-dot leave-type-personal"></span>Concediu personal</li>
                            <li><span class="leave-legend-dot leave-type-medical"></span>Concediu medical</li>
                            <li><span class="leave-legend-dot leave-type-fara_plata"></span>Concediu fără plată</li>
                            <li><span class="leave-legend-dot leave-type-nelucrator"></span>Zile nelucrătoare</li>
                        </ul>
                    </section>

                    <section class="leave-panel leave-rules-panel">
                        <div class="leave-panel-header">
                            <h3 class="leave-panel-title mb-0">Reguli rapide</h3>
                        </div>
                        <ul class="leave-rules-list">
                            <li>Cererea trebuie trimisă cu minim 3 zile înainte.</li>
                            <li>Suprapunerile sunt evidențiate în roșu.</li>
                            <li>Daca modifici o cerere aprobata, aceasta revine in "In asteptare aprobare".</li>
                            <li>Toate datele sunt în fusul orar (<?= e($timezoneLabel) ?>).</li>
                        </ul>
                    </section>
                </aside>
            </div>

            <section class="leave-panel leave-table-panel mt-3">
                <div class="leave-panel-header leave-table-header">
                    <h3 class="leave-panel-title mb-0">Cereri concedii</h3>
                </div>

                <form method="get" class="leave-table-filters">
                    <input type="hidden" name="page" value="programare_concedii">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                    <input type="hidden" name="calendar_view" value="<?= e($calendarView) ?>">
                    <input type="hidden" name="calendar_month" value="<?= e($calendarMonthValue) ?>">
                    <input type="hidden" name="focus_date" value="<?= e($calendarFocusDate) ?>">
                    <input type="hidden" name="driver_q" value="<?= e($driverSearch) ?>">

                    <div class="leave-search-input-wrap leave-filter-search">
                        <i class="bi bi-search"></i>
                        <input
                            type="text"
                            class="form-control"
                            name="table_q"
                            value="<?= e($tableSearch) ?>"
                            placeholder="Caută după șofer..."
                            aria-label="Caută după șofer"
                        >
                    </div>

                    <select class="form-select leave-filter-select" name="status_filter" aria-label="Filtru status">
                        <option value="">Status: Toate</option>
                        <?php foreach ($statusuri as $statusKey => $statusLabel): ?>
                            <option value="<?= e((string) $statusKey) ?>" <?= $statusFilter === (string) $statusKey ? 'selected' : '' ?>>
                                <?= e((string) $statusLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="form-select leave-filter-select" name="tip_filter" aria-label="Filtru tip concediu">
                        <option value="">Tip: Toate</option>
                        <?php foreach ($tipuriConcediu as $tipKey => $tipLabel): ?>
                            <option value="<?= e((string) $tipKey) ?>" <?= $tipFilter === (string) $tipKey ? 'selected' : '' ?>>
                                <?= e((string) $tipLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="btn btn-outline-secondary" type="submit">Aplică</button>
                </form>

                <div class="leave-table-wrap table-responsive">
                    <table class="table leave-requests-table mb-0">
                        <thead>
                        <tr>
                            <th>Șofer</th>
                            <th>Perioadă</th>
                            <th>Tip concediu</th>
                            <th>Status</th>
                            <th>Înlocuitor</th>
                            <th>Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (($rows ?? []) === []): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Nu există cereri de concediu pentru filtrele selectate.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $rowId = (int) ($row['id'] ?? 0);
                                $statusKey = (string) ($row['status'] ?? 'in_asteptare');
                                $statusBadgeClass = $statusBadgeClassMap[$statusKey] ?? 'leave-status-badge leave-status-default';
                                $statusLabel = $statusuri[$statusKey] ?? $statusKey;
                                $tipKey = (string) ($row['tip_concediu'] ?? '');
                                $tipLabel = $tipuriConcediu[$tipKey] ?? $tipKey;
                                $periodLabel = format_date_ro((string) ($row['data_inceput'] ?? ''))
                                    . ' - '
                                    . format_date_ro((string) ($row['data_sfarsit'] ?? ''));
                                $driverLabel = trim((string) ($row['sofer_nume'] ?? '-'));
                                $driverPlate = trim((string) ($row['sofer_nr_inmatriculare'] ?? ''));
                                $replacementLabel = trim((string) ($row['inlocuitor_nume'] ?? ''));
                                if ($replacementLabel === '') {
                                    $replacementLabel = '-';
                                } else {
                                    $replacementPlate = trim((string) ($row['inlocuitor_nr_inmatriculare'] ?? ''));
                                    if ($replacementPlate !== '') {
                                        $replacementLabel .= ' - ' . $replacementPlate;
                                    }
                                }
                                $isDeleteAllowed = in_array($statusKey, ['in_asteptare', 'in_asteptare_aprobare', 'respins', 'aprobat'], true);
                                ?>
                                <tr>
                                    <td>
                                        <div class="leave-driver-cell">
                                            <div class="leave-driver-avatar"><?= e(mb_substr($driverLabel, 0, 1)) ?></div>
                                            <div>
                                                <div class="leave-driver-name"><?= e($driverLabel) ?></div>
                                                <div class="leave-driver-meta"><?= e($driverPlate !== '' ? $driverPlate : 'Fără vehicul asignat') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= e($periodLabel) ?></td>
                                    <td><?= e($tipLabel) ?></td>
                                    <td>
                                        <span class="<?= e($statusBadgeClass) ?>"><?= e($statusLabel) ?></span>
                                        <?php if ($activeTab === 'aprobari'): ?>
                                            <div class="leave-status-actions mt-2">
                                                <form method="post" action="<?= e(build_query_url(['page' => 'programare_concedii', 'action' => 'update_status'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $rowId) ?>">
                                                    <input type="hidden" name="status" value="aprobat">
                                                    <button type="submit" class="btn btn-sm btn-success" <?= $statusKey === 'aprobat' ? 'disabled' : '' ?>>Aprobă</button>
                                                </form>
                                                <form method="post" action="<?= e(build_query_url(['page' => 'programare_concedii', 'action' => 'update_status'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $rowId) ?>">
                                                    <input type="hidden" name="status" value="respins">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" <?= $statusKey === 'respins' ? 'disabled' : '' ?>>Respinge</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($replacementLabel) ?></td>
                                    <td>
                                        <div class="leave-row-actions">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary leave-view-btn"
                                                data-request-id="<?= e((string) $rowId) ?>"
                                                data-driver="<?= e($driverLabel) ?>"
                                                data-driver-plate="<?= e($driverPlate) ?>"
                                                data-period="<?= e($periodLabel) ?>"
                                                data-tip="<?= e($tipLabel) ?>"
                                                data-status="<?= e($statusLabel) ?>"
                                                data-replacement="<?= e($replacementLabel) ?>"
                                                data-note="<?= e((string) ($row['note'] ?? '-')) ?>"
                                            >
                                                View
                                            </button>
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="<?= e(build_query_url(array_merge($currentListBaseQuery, ['edit_id' => $rowId]))) ?>"
                                            >
                                                Edit
                                            </a>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger leave-delete-trigger <?= $isDeleteAllowed ? '' : 'leave-delete-locked' ?>"
                                                data-request-id="<?= e((string) $rowId) ?>"
                                                data-driver="<?= e($driverLabel) ?>"
                                                data-period="<?= e($periodLabel) ?>"
                                                data-delete-locked="<?= $isDeleteAllowed ? '0' : '1' ?>"
                                                data-delete-lock-message="Cererea nu poate fi stearsa in statusul curent."
                                                <?= $isDeleteAllowed ? '' : 'aria-disabled="true" title="Cererea nu poate fi stearsa in statusul curent."' ?>
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="leave-table-footer">
                    <div class="leave-table-count">
                        Se afișează
                        <?php
                        $totalRows = (int) ($pagination['total_rows'] ?? 0);
                        $perPage = max(1, (int) ($pagination['per_page'] ?? 1));
                        $currentPage = max(1, (int) ($pagination['page'] ?? 1));
                        $startRow = $totalRows > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
                        $endRow = min($totalRows, $currentPage * $perPage);
                        ?>
                        <?= e((string) $startRow) ?> - <?= e((string) $endRow) ?> din <?= e((string) $totalRows) ?> rezultate
                    </div>
                    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
                        <?php
                        $totalPages = (int) ($pagination['total_pages'] ?? 1);
                        $prevPage = max(1, $currentPage - 1);
                        $nextPage = min($totalPages, $currentPage + 1);
                        ?>
                        <nav aria-label="Paginare cereri concedii">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(build_query_url(array_merge($currentListBaseQuery, ['p' => $prevPage]))) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(build_query_url(array_merge($currentListBaseQuery, ['p' => $i]))) ?>">
                                            <?= e((string) $i) ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(build_query_url(array_merge($currentListBaseQuery, ['p' => $nextPage]))) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <form method="post" action="<?= e(build_query_url(['page' => 'programare_concedii', 'action' => 'delete'])) ?>" id="leave-delete-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="leave-delete-id" value="">
    </form>

    <div class="modal fade" id="leaveDetailsModal" tabindex="-1" aria-labelledby="leaveDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="leaveDetailsModalLabel">Detalii concediu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <dl class="leave-detail-list mb-0">
                        <dt>Șofer</dt>
                        <dd data-role="detail-driver">-</dd>
                        <dt>Vehicul</dt>
                        <dd data-role="detail-driver-plate">-</dd>
                        <dt>Perioadă</dt>
                        <dd data-role="detail-period">-</dd>
                        <dt>Tip concediu</dt>
                        <dd data-role="detail-tip">-</dd>
                        <dt>Status</dt>
                        <dd data-role="detail-status">-</dd>
                        <dt>Înlocuitor</dt>
                        <dd data-role="detail-replacement">-</dd>
                        <dt>Note</dt>
                        <dd data-role="detail-note">-</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="leaveDeleteModal" tabindex="-1" aria-labelledby="leaveDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="leaveDeleteModalLabel">Confirmare ștergere</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Sigur dorești să ștergi această cerere de concediu?</p>
                    <p class="text-muted mb-0" data-role="delete-context">-</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                    <button type="button" class="btn btn-danger" id="leave-delete-confirm-btn">Șterge</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed top-0 end-0 p-3 leave-toast-container">
        <div id="leaveActionToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" data-role="toast-message"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Închide"></button>
            </div>
        </div>
    </div>

    <?php if (is_array($toastFlash)): ?>
        <div
            id="leave-toast-flash"
            data-type="<?= e((string) ($toastFlash['type'] ?? 'info')) ?>"
            data-message="<?= e((string) ($toastFlash['message'] ?? '')) ?>"
            class="d-none"
        ></div>
    <?php endif; ?>
</div>

<script src="<?= e(url('assets/js/programare-concedii.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/programare-concedii.js'))) ?>"></script>
