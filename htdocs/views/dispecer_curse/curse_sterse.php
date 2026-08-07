<?php
$deletedRows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$filterOptions = is_array($filterOptions ?? null) ? $filterOptions : [];
$summary = is_array($summary ?? null) ? $summary : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$transportTypes = is_array($transportTypes ?? null) ? $transportTypes : [];
$currentPageIndex = max(1, (int) ($pagination['page'] ?? 1));
$perPage = max(10, (int) ($pagination['per_page'] ?? 10));
$totalRows = max(0, (int) ($pagination['total_rows'] ?? 0));
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$returnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse']));

$filterBase = array_merge(
    ['page' => 'dispecer_curse', 'action' => 'curse_sterse'],
    $filters,
    ['per_page' => $perPage]
);

$monthInterval = trim(format_date_ro((string) ($summary['month_start'] ?? '')) . ' - ' . format_date_ro((string) ($summary['month_end'] ?? '')));
$rangeStart = $totalRows === 0 ? 0 : (($currentPageIndex - 1) * $perPage) + 1;
$rangeEnd = min($totalRows, $currentPageIndex * $perPage);

$transportBadgeClass = static function (string $type): string {
    return match ($type) {
        'primar', 'primar_tona' => 'is-primary',
        'distributie' => 'is-distribution',
        'primar_distributie' => 'is-mixed',
        'compresor' => 'is-compressor',
        default => 'is-muted',
    };
};

$buildRouteLabel = static function (array $row): string {
    $start = trim((string) ($row['loc_plecare'] ?? ''));
    if ($start === '') {
        $start = trim((string) ($row['loc_incarcare_nume'] ?? ''));
    }
    if ($start === '') {
        $start = trim((string) ($row['loc_aspirare'] ?? ''));
    }

    $end = trim((string) ($row['loc_livrare'] ?? ''));
    if ($end === '') {
        $end = trim((string) ($row['loc_livrare_cursa'] ?? ''));
    }
    if ($end === '') {
        $end = trim((string) ($row['zona_distributie_nume'] ?? ''));
    }

    if ($start !== '' && $end !== '' && mb_strtolower($start) !== mb_strtolower($end)) {
        return $start . ' → ' . $end;
    }

    if ($start !== '') {
        return $start;
    }

    return $end !== '' ? $end : '-';
};
?>

<div class="deleted-races-page" data-deleted-races-page>
    <div class="deleted-races-layout">
        <div class="deleted-races-main">
            <header class="deleted-races-header">
                <div>
                    <h1>Curse șterse</h1>
                    <p>Vizualizează, verifică și restaurează cursele eliminate din Dispecer curse.</p>
                </div>
            </header>

            <section class="deleted-races-kpi-grid" aria-label="Indicatori curse șterse">
                <article class="deleted-races-kpi-card is-blue">
                    <span class="deleted-races-kpi-icon"><i class="bi bi-trash3" aria-hidden="true"></i></span>
                    <div>
                        <p>Total curse șterse</p>
                        <strong><?= e((string) ((int) ($summary['total_deleted'] ?? 0))) ?></strong>
                        <span>din toate perioadele</span>
                    </div>
                </article>

                <article class="deleted-races-kpi-card is-orange">
                    <span class="deleted-races-kpi-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                    <div>
                        <p>Șterse luna aceasta</p>
                        <strong><?= e((string) ((int) ($summary['deleted_this_month'] ?? 0))) ?></strong>
                        <span>în perioada <?= e($monthInterval) ?></span>
                    </div>
                </article>

                <article class="deleted-races-kpi-card is-green">
                    <span class="deleted-races-kpi-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                    <div>
                        <p>Utilizatori implicați</p>
                        <strong><?= e((string) ((int) ($summary['users_involved'] ?? 0))) ?></strong>
                        <span>au șters curse în această perioadă</span>
                    </div>
                </article>
            </section>

            <section class="deleted-races-panel deleted-races-filter-panel">
                <form method="get" class="deleted-races-filter-form">
                    <input type="hidden" name="page" value="dispecer_curse">
                    <input type="hidden" name="action" value="curse_sterse">
                    <input type="hidden" name="p" value="1">
                    <input type="hidden" name="per_page" value="<?= e((string) $perPage) ?>">

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_vehicle">Nr. înmatriculare</label>
                        <select class="form-select" id="deleted_filter_vehicle" name="vehicle_id">
                            <option value="">Toate</option>
                            <?php foreach ((array) ($filterOptions['vehicles'] ?? []) as $vehicle): ?>
                                <?php
                                    $vehicleId = (int) ($vehicle['id'] ?? 0);
                                    $plate = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
                                    if ($vehicleId <= 0 || $plate === '') {
                                        continue;
                                    }
                                    $vehicleLabel = trim($plate . ' ' . trim((string) (($vehicle['marca'] ?? '') . ' ' . ($vehicle['model'] ?? ''))));
                                ?>
                                <option value="<?= e((string) $vehicleId) ?>" <?= (string) ($filters['vehicle_id'] ?? '') === (string) $vehicleId ? 'selected' : '' ?>>
                                    <?= e($vehicleLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_transport">Tip transport</label>
                        <select class="form-select" id="deleted_filter_transport" name="tip_transport">
                            <option value="">Toate</option>
                            <?php foreach ($transportTypes as $typeValue => $typeLabel): ?>
                                <option value="<?= e((string) $typeValue) ?>" <?= (string) ($filters['tip_transport'] ?? '') === (string) $typeValue ? 'selected' : '' ?>>
                                    <?= e((string) $typeLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_driver">Șofer</label>
                        <select class="form-select" id="deleted_filter_driver" name="driver_id">
                            <option value="">Toate</option>
                            <?php foreach ((array) ($filterOptions['drivers'] ?? []) as $driver): ?>
                                <?php $driverId = (int) ($driver['id'] ?? 0); ?>
                                <?php if ($driverId <= 0): continue; endif; ?>
                                <option value="<?= e((string) $driverId) ?>" <?= (string) ($filters['driver_id'] ?? '') === (string) $driverId ? 'selected' : '' ?>>
                                    <?= e((string) ($driver['nume'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_beneficiary">Beneficiar</label>
                        <select class="form-select" id="deleted_filter_beneficiary" name="beneficiar_id">
                            <option value="">Toți</option>
                            <?php foreach ((array) ($filterOptions['beneficiaries'] ?? []) as $beneficiary): ?>
                                <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                                <?php if ($beneficiaryId <= 0): continue; endif; ?>
                                <option value="<?= e((string) $beneficiaryId) ?>" <?= (string) ($filters['beneficiar_id'] ?? '') === (string) $beneficiaryId ? 'selected' : '' ?>>
                                    <?= e((string) ($beneficiary['nume'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_user">Șters de</label>
                        <select class="form-select" id="deleted_filter_user" name="deleted_by">
                            <option value="">Toți</option>
                            <?php foreach ((array) ($filterOptions['deleted_by_users'] ?? []) as $deletedByUser): ?>
                                <?php $deletedById = (int) ($deletedByUser['id'] ?? 0); ?>
                                <?php if ($deletedById <= 0): continue; endif; ?>
                                <option value="<?= e((string) $deletedById) ?>" <?= (string) ($filters['deleted_by'] ?? '') === (string) $deletedById ? 'selected' : '' ?>>
                                    <?= e((string) ($deletedByUser['nume'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_race_start">Data cursei de la</label>
                        <input class="form-control" type="date" id="deleted_filter_race_start" name="data_cursa_start" value="<?= e((string) ($filters['data_cursa_start'] ?? '')) ?>">
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_race_end">Data cursei până la</label>
                        <input class="form-control" type="date" id="deleted_filter_race_end" name="data_cursa_end" value="<?= e((string) ($filters['data_cursa_end'] ?? '')) ?>">
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_deleted_start">Data ștergerii de la</label>
                        <input class="form-control" type="date" id="deleted_filter_deleted_start" name="deleted_start" value="<?= e((string) ($filters['deleted_start'] ?? '')) ?>">
                    </div>

                    <div class="deleted-races-filter-field">
                        <label class="form-label" for="deleted_filter_deleted_end">Data ștergerii până la</label>
                        <input class="form-control" type="date" id="deleted_filter_deleted_end" name="deleted_end" value="<?= e((string) ($filters['deleted_end'] ?? '')) ?>">
                    </div>

                    <div class="deleted-races-filter-actions">
                        <button class="btn btn-primary" type="submit">Aplică filtre</button>
                        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse'])) ?>">Resetează</a>
                    </div>
                </form>
            </section>

            <section class="deleted-races-panel deleted-races-table-panel">
                <header class="deleted-races-table-header">
                    <h2>Listă curse șterse</h2>
                </header>

                <div class="deleted-races-table-wrap">
                    <table class="table deleted-races-table mb-0">
                        <thead>
                            <tr>
                                <th>Cursă</th>
                                <th>Nr. înmatriculare</th>
                                <th>Șofer</th>
                                <th>Tip transport</th>
                                <th>Rută</th>
                                <th>Data cursei</th>
                                <th>Data ștergerii</th>
                                <th>Șters de</th>
                                <th class="text-center deleted-races-actions-column">Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($deletedRows === []): ?>
                                <tr>
                                    <td colspan="9" class="deleted-races-empty">Nu există curse șterse pentru filtrele selectate.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($deletedRows as $row): ?>
                                    <?php
                                        $raceId = (int) ($row['id'] ?? 0);
                                        $transportType = (string) ($row['tip_transport'] ?? '');
                                        $transportLabel = $transportTypes[$transportType] ?? ($transportType !== '' ? $transportType : '-');
                                        $raceDate = (string) (($row['data_inceput'] ?? '') !== '' ? $row['data_inceput'] : ($row['data_cursa'] ?? ''));
                                        $routeLabel = $buildRouteLabel($row);
                                        $deletedByName = trim((string) ($row['deleted_by_nume'] ?? '')) ?: 'Necunoscut';
                                        $detailsUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse_details', 'id' => $raceId]);
                                        $actionsMenuId = 'deleted_race_actions_' . (string) $raceId;
                                    ?>
                                    <tr data-deleted-race-row="<?= e((string) $raceId) ?>">
                                        <td class="deleted-races-nowrap-cell"><strong>#<?= e((string) $raceId) ?></strong></td>
                                        <td class="deleted-races-nowrap-cell"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                        <td class="deleted-races-user-cell" title="<?= e(trim((string) ($row['sofer_nume'] ?? '')) ?: '-') ?>">
                                            <?= e(trim((string) ($row['sofer_nume'] ?? '')) ?: '-') ?>
                                        </td>
                                        <td>
                                            <span class="deleted-races-type-badge <?= e($transportBadgeClass($transportType)) ?>">
                                                <?= e($transportLabel) ?>
                                            </span>
                                        </td>
                                        <td class="deleted-races-route-cell" title="<?= e($routeLabel) ?>"><?= e($routeLabel) ?></td>
                                        <td class="deleted-races-nowrap-cell"><?= e(format_date_ro($raceDate)) ?></td>
                                        <td class="deleted-races-nowrap-cell"><?= e(format_datetime_ro((string) ($row['deleted_at'] ?? ''))) ?></td>
                                        <td class="deleted-races-user-cell" title="<?= e($deletedByName) ?>"><?= e($deletedByName) ?></td>
                                        <td class="deleted-races-actions-cell">
                                            <div class="deleted-races-actions">
                                                <button
                                                    type="button"
                                                    class="deleted-races-actions-toggle"
                                                    data-deleted-race-actions-toggle
                                                    data-menu-id="<?= e($actionsMenuId) ?>"
                                                    aria-haspopup="menu"
                                                    aria-expanded="false"
                                                    aria-controls="<?= e($actionsMenuId) ?>"
                                                    aria-label="Acțiuni cursa #<?= e((string) $raceId) ?>"
                                                    title="Acțiuni"
                                                >
                                                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                                                </button>
                                                <div class="deleted-races-actions-menu" id="<?= e($actionsMenuId) ?>" data-deleted-race-actions-menu role="menu" hidden>
                                                    <button
                                                        class="deleted-races-actions-item"
                                                        type="button"
                                                        role="menuitem"
                                                        data-deleted-race-details
                                                        data-url="<?= e($detailsUrl) ?>"
                                                        data-race-id="<?= e((string) $raceId) ?>"
                                                    >
                                                        Vezi detalii
                                                    </button>
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'restore_cursa'])) ?>" class="deleted-races-actions-form" role="none" data-deleted-race-restore>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                                                        <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                                        <button class="deleted-races-actions-item is-success" type="submit" role="menuitem" data-confirm="Restaurezi cursa #<?= e((string) $raceId) ?>? Cursa va reapărea în Dispecer curse și va reintra în calculele aplicației.">Restaurează</button>
                                                    </form>
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'delete_cursa_stearsa'])) ?>" class="deleted-races-actions-form" role="none" data-deleted-race-delete>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                                                        <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                                        <button class="deleted-races-actions-item is-danger" type="submit" role="menuitem" data-confirm="Ștergi definitiv cursa #<?= e((string) $raceId) ?>? Această acțiune nu poate fi anulată.">Șterge</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <footer class="deleted-races-table-footer">
                    <form method="get" class="deleted-races-page-size-form">
                        <?php foreach (array_merge($filterBase, ['p' => 1]) as $key => $value): ?>
                            <?php if ($key === 'per_page'): continue; endif; ?>
                            <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
                        <?php endforeach; ?>
                        <label for="deleted_per_page">Afișează</label>
                        <select class="form-select form-select-sm" id="deleted_per_page" name="per_page" onchange="this.form.submit()">
                            <?php foreach ([10, 25, 50, 100] as $perPageOption): ?>
                                <option value="<?= e((string) $perPageOption) ?>" <?= $perPage === $perPageOption ? 'selected' : '' ?>><?= e((string) $perPageOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span>rezultate</span>
                    </form>

                    <div class="deleted-races-range"><?= e((string) $rangeStart) ?> - <?= e((string) $rangeEnd) ?> din <?= e((string) $totalRows) ?></div>

                    <nav aria-label="Paginare curse șterse">
                        <ul class="pagination pagination-sm deleted-races-pagination mb-0">
                            <li class="page-item <?= $currentPageIndex <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => max(1, $currentPageIndex - 1)]))) ?>" aria-label="Pagina anterioară">
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                </a>
                            </li>
                            <?php for ($p = max(1, $currentPageIndex - 2); $p <= min($totalPages, $currentPageIndex + 2); $p++): ?>
                                <li class="page-item <?= $p === $currentPageIndex ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => $p]))) ?>"><?= e((string) $p) ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $currentPageIndex >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => min($totalPages, $currentPageIndex + 1)]))) ?>" aria-label="Pagina următoare">
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </footer>
            </section>
        </div>

        <aside class="deleted-races-details" data-deleted-race-details-panel>
            <header class="deleted-races-details-header">
                <h2>Detalii cursă ștearsă</h2>
                <button class="btn btn-sm btn-link deleted-races-details-close" type="button" data-deleted-race-details-close aria-label="Închide detaliile">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <div class="deleted-races-details-body" data-deleted-race-details-body>
                <div class="deleted-races-details-placeholder">
                    Selectează „Vezi detalii” pentru a consulta cursa ștearsă.
                </div>
            </div>

            <footer class="deleted-races-details-footer">
                <button class="btn btn-outline-secondary w-100" type="button" data-deleted-race-details-close>Închide</button>
            </footer>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var pageEl = document.querySelector('[data-deleted-races-page]');
    if (!pageEl) {
        return;
    }

    var detailsBodyEl = pageEl.querySelector('[data-deleted-race-details-body]');
    var detailsButtons = pageEl.querySelectorAll('[data-deleted-race-details]');

    var setDetailsLoading = function () {
        if (!detailsBodyEl) {
            return;
        }
        detailsBodyEl.innerHTML = '<div class="deleted-races-details-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Se încarcă detaliile...</span></div>';
    };

    var setDetailsPlaceholder = function () {
        if (!detailsBodyEl) {
            return;
        }
        detailsBodyEl.innerHTML = '<div class="deleted-races-details-placeholder">Selectează „Vezi detalii” pentru a consulta cursa ștearsă.</div>';
        pageEl.querySelectorAll('[data-deleted-race-row].is-selected').forEach(function (rowEl) {
            rowEl.classList.remove('is-selected');
        });
    };

    var createElement = function (tagName, className, text) {
        var element = document.createElement(tagName);
        if (className) {
            element.className = className;
        }
        if (typeof text === 'string') {
            element.textContent = text;
        }
        return element;
    };

    var showNotice = function (message, type) {
        var noticeEl = createElement('div', 'deleted-races-toast is-' + (type || 'info'), message);
        document.body.appendChild(noticeEl);
        window.setTimeout(function () {
            noticeEl.classList.add('is-visible');
        }, 10);
        window.setTimeout(function () {
            noticeEl.classList.remove('is-visible');
            window.setTimeout(function () {
                noticeEl.remove();
            }, 180);
        }, 2200);
    };

    var closestElement = function (target, selector) {
        if (!(target instanceof Element)) {
            return null;
        }

        return target.closest(selector);
    };

    var activeDeletedActionsState = null;

    var positionDeletedActionsMenu = function (triggerEl, menuEl) {
        if (!(triggerEl instanceof HTMLElement) || !(menuEl instanceof HTMLElement)) {
            return;
        }

        var margin = 12;
        var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        var viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        var width = Math.min(176, Math.max(148, viewportWidth - margin * 2));
        var maxHeight = Math.min(240, Math.max(140, viewportHeight - margin * 2));

        menuEl.style.width = width + 'px';
        menuEl.style.maxHeight = maxHeight + 'px';

        var triggerRect = triggerEl.getBoundingClientRect();
        var menuRect = menuEl.getBoundingClientRect();
        var menuHeight = Math.min(menuRect.height || menuEl.scrollHeight || maxHeight, maxHeight);
        var left = triggerRect.right - width;

        if (left < margin) {
            left = triggerRect.left;
        }
        left = Math.max(margin, Math.min(left, viewportWidth - margin - width));

        var top = triggerRect.bottom + 8;
        var topIfAbove = triggerRect.top - menuHeight - 8;
        if (top + menuHeight > viewportHeight - margin && topIfAbove >= margin) {
            top = topIfAbove;
        } else if (top + menuHeight > viewportHeight - margin) {
            top = Math.max(margin, viewportHeight - margin - menuHeight);
        }
        top = Math.max(margin, Math.min(top, viewportHeight - margin - menuHeight));

        menuEl.style.left = Math.round(left) + 'px';
        menuEl.style.top = Math.round(top) + 'px';
    };

    var closeDeletedActionsMenu = function (restoreFocus) {
        if (activeDeletedActionsState === null) {
            return;
        }

        var previousState = activeDeletedActionsState;
        activeDeletedActionsState = null;
        previousState.button.setAttribute('aria-expanded', 'false');
        previousState.button.classList.remove('is-open');
        previousState.menu.hidden = true;
        previousState.menu.style.left = '';
        previousState.menu.style.top = '';
        previousState.menu.style.visibility = '';

        if (restoreFocus) {
            previousState.button.focus({ preventScroll: true });
        }
    };

    var openDeletedActionsMenu = function (buttonEl, focusFirstItem) {
        if (!(buttonEl instanceof HTMLButtonElement)) {
            return;
        }

        var menuId = String(buttonEl.dataset.menuId || '');
        var menuEl = menuId !== '' ? document.getElementById(menuId) : null;
        if (!(menuEl instanceof HTMLElement)) {
            return;
        }

        closeDeletedActionsMenu(false);
        activeDeletedActionsState = {
            button: buttonEl,
            menu: menuEl
        };

        buttonEl.setAttribute('aria-expanded', 'true');
        buttonEl.classList.add('is-open');
        menuEl.style.visibility = 'hidden';
        menuEl.hidden = false;
        positionDeletedActionsMenu(buttonEl, menuEl);
        menuEl.style.visibility = '';

        if (focusFirstItem) {
            var firstItemEl = menuEl.querySelector('[role="menuitem"]');
            if (firstItemEl instanceof HTMLElement) {
                firstItemEl.focus({ preventScroll: true });
            }
        }
    };

    var repositionDeletedActionsMenu = function () {
        if (activeDeletedActionsState !== null) {
            positionDeletedActionsMenu(activeDeletedActionsState.button, activeDeletedActionsState.menu);
        }
    };

    var renderDetails = function (payload) {
        if (!detailsBodyEl) {
            return;
        }

        detailsBodyEl.innerHTML = '';

        var fieldsCard = createElement('section', 'deleted-races-detail-card');
        fieldsCard.appendChild(createElement('h3', '', 'Date cursă'));
        (payload.fields || []).forEach(function (field) {
            var rowEl = createElement('div', 'deleted-races-detail-row');
            rowEl.appendChild(createElement('span', '', field.label || '-'));
            var valueEl = createElement('strong', '', field.value || '-');
            rowEl.appendChild(valueEl);
            fieldsCard.appendChild(rowEl);
        });
        detailsBodyEl.appendChild(fieldsCard);

        var deletionCard = createElement('section', 'deleted-races-detail-card');
        deletionCard.appendChild(createElement('h3', '', 'Istoric ștergere'));
        [
            ['Ștearsă de', payload.deletion && payload.deletion.deleted_by ? payload.deletion.deleted_by : 'Necunoscut'],
            ['Rol', payload.deletion && payload.deletion.role ? payload.deletion.role : '-'],
            ['Data ștergerii', payload.deletion && payload.deletion.deleted_at ? payload.deletion.deleted_at : '-']
        ].forEach(function (item) {
            var rowEl = createElement('div', 'deleted-races-detail-row');
            rowEl.appendChild(createElement('span', '', item[0]));
            rowEl.appendChild(createElement('strong', '', item[1]));
            deletionCard.appendChild(rowEl);
        });
        detailsBodyEl.appendChild(deletionCard);

        var timelineCard = createElement('section', 'deleted-races-detail-card');
        timelineCard.appendChild(createElement('h3', '', 'Timeline audit'));
        var timelineEl = createElement('div', 'deleted-races-timeline');
        (payload.timeline || []).forEach(function (event) {
            var itemEl = createElement('div', 'deleted-races-timeline-item is-' + (event.action || 'updated'));
            itemEl.appendChild(createElement('span', 'deleted-races-timeline-dot', ''));
            var contentEl = createElement('div', 'deleted-races-timeline-content');
            var topEl = createElement('div', 'deleted-races-timeline-top');
            topEl.appendChild(createElement('strong', '', event.label || 'Actualizată'));
            topEl.appendChild(createElement('span', '', event.performed_at || '-'));
            contentEl.appendChild(topEl);
            contentEl.appendChild(createElement('small', '', 'De: ' + (event.user || 'Sistem')));
            itemEl.appendChild(contentEl);
            timelineEl.appendChild(itemEl);
        });
        if (!timelineEl.children.length) {
            timelineEl.appendChild(createElement('div', 'deleted-races-details-placeholder', 'Nu există evenimente audit pentru această cursă.'));
        }
        timelineCard.appendChild(timelineEl);
        detailsBodyEl.appendChild(timelineCard);
    };

    detailsButtons.forEach(function (buttonEl) {
        buttonEl.addEventListener('click', function () {
            var url = buttonEl.getAttribute('data-url');
            var raceId = buttonEl.getAttribute('data-race-id');
            if (!url) {
                return;
            }

            closeDeletedActionsMenu(false);
            setDetailsLoading();
            pageEl.querySelectorAll('[data-deleted-race-row].is-selected').forEach(function (rowEl) {
                rowEl.classList.remove('is-selected');
            });
            var rowEl = pageEl.querySelector('[data-deleted-race-row="' + raceId + '"]');
            if (rowEl) {
                rowEl.classList.add('is-selected');
            }

            fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Detaliile cursei nu au putut fi încărcate.');
                        }
                        return payload;
                    });
                })
                .then(function (payload) {
                    renderDetails(payload.details || {});
                })
                .catch(function (error) {
                    detailsBodyEl.innerHTML = '<div class="deleted-races-details-error">' + error.message + '</div>';
                });
        });
    });

    document.addEventListener('click', function (event) {
        var actionsButtonEl = closestElement(event.target, '[data-deleted-race-actions-toggle]');
        if (actionsButtonEl instanceof HTMLButtonElement) {
            event.preventDefault();
            if (activeDeletedActionsState !== null && activeDeletedActionsState.button === actionsButtonEl) {
                closeDeletedActionsMenu(false);
                return;
            }
            openDeletedActionsMenu(actionsButtonEl, false);
            return;
        }

        if (closestElement(event.target, '[data-deleted-race-actions-menu]') === null) {
            closeDeletedActionsMenu(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        var actionsButtonEl = closestElement(event.target, '[data-deleted-race-actions-toggle]');
        if (actionsButtonEl instanceof HTMLButtonElement && event.key === 'ArrowDown') {
            event.preventDefault();
            openDeletedActionsMenu(actionsButtonEl, true);
            return;
        }

        if (event.key === 'Escape' && activeDeletedActionsState !== null) {
            event.preventDefault();
            closeDeletedActionsMenu(true);
            return;
        }

        if (activeDeletedActionsState === null || !activeDeletedActionsState.menu.contains(event.target)) {
            return;
        }

        var menuItems = Array.prototype.slice.call(activeDeletedActionsState.menu.querySelectorAll('[role="menuitem"]'))
            .filter(function (itemEl) {
                return itemEl instanceof HTMLElement && !itemEl.hasAttribute('disabled');
            });
        if (menuItems.length === 0) {
            return;
        }

        var currentIndex = menuItems.indexOf(document.activeElement);
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            menuItems[(currentIndex + 1 + menuItems.length) % menuItems.length].focus({ preventScroll: true });
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            menuItems[(currentIndex - 1 + menuItems.length) % menuItems.length].focus({ preventScroll: true });
        } else if (event.key === 'Home') {
            event.preventDefault();
            menuItems[0].focus({ preventScroll: true });
        } else if (event.key === 'End') {
            event.preventDefault();
            menuItems[menuItems.length - 1].focus({ preventScroll: true });
        }
    });

    document.addEventListener('scroll', repositionDeletedActionsMenu, true);
    window.addEventListener('resize', repositionDeletedActionsMenu);

    pageEl.querySelectorAll('[data-deleted-race-details-close]').forEach(function (buttonEl) {
        buttonEl.addEventListener('click', setDetailsPlaceholder);
    });

    pageEl.querySelectorAll('[data-deleted-race-restore]').forEach(function (formEl) {
        formEl.addEventListener('submit', function (event) {
            var submitButton = formEl.querySelector('button[type="submit"]');

            if (!(window.fetch && formEl instanceof HTMLFormElement)) {
                return;
            }

            event.preventDefault();
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Se restaurează...';
            }

            fetch(formEl.action, {
                method: 'POST',
                body: new FormData(formEl),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Cursa nu a putut fi restaurată.');
                        }
                        return payload;
                    });
                })
                .then(function () {
                    showNotice('Cursa a fost restaurată.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 650);
                })
                .catch(function (error) {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Restaurează';
                    }
                    showNotice(error.message, 'error');
                });
        });
    });

    pageEl.querySelectorAll('[data-deleted-race-delete]').forEach(function (formEl) {
        formEl.addEventListener('submit', function (event) {
            var submitButton = formEl.querySelector('button[type="submit"]');

            if (!(window.fetch && formEl instanceof HTMLFormElement)) {
                return;
            }

            event.preventDefault();
            closeDeletedActionsMenu(false);
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Se șterge...';
            }

            fetch(formEl.action, {
                method: 'POST',
                body: new FormData(formEl),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Cursa nu a putut fi ștearsă definitiv.');
                        }
                        return payload;
                    });
                })
                .then(function () {
                    showNotice('Cursa a fost ștearsă definitiv.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 650);
                })
                .catch(function (error) {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Șterge';
                    }
                    showNotice(error.message, 'error');
                });
        });
    });

    if (detailsButtons.length > 0) {
        detailsButtons[0].click();
    }
});
</script>
