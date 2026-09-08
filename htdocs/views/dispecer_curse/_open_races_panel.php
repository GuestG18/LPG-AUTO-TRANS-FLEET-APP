<?php
/**
 * Panoul "curse cu informatii lipsa", partajat de lista de curse si de pagina de
 * editare. Pe lista arata toate cursele deschise; pe editare, doar cursele
 * vehiculului editat, ca operatorul sa poata completa lipsurile fara sa piarda
 * contextul. Sursa datelor este aceeasi ($openRacesOverview), doar filtrata
 * diferit in controller.
 *
 * Asteapta: $openRacesOverview, $goodsTypeOptions, $transportTypes si, optional,
 * $openRacesPanelLabel (textul butonului) si $openRacesPanelIntro (subtitlul).
 */
$openRacesCount = (int) ($openRacesOverview['count'] ?? 0);
$openRacesRows = is_array($openRacesOverview['rows'] ?? null) ? $openRacesOverview['rows'] : [];
$openRacesSeverityCounts = is_array($openRacesOverview['severity_counts'] ?? null)
    ? $openRacesOverview['severity_counts']
    : ['critical' => 0, 'important' => 0, 'minor' => 0];
$openRacesPlates = is_array($openRacesOverview['plates'] ?? null) ? $openRacesOverview['plates'] : [];
$openRacesPanelLabel = trim((string) ($openRacesPanelLabel ?? '')) !== ''
    ? trim((string) $openRacesPanelLabel)
    : 'Atentie: curse cu informatii lipsa';
$openRacesPanelIntro = trim((string) ($openRacesPanelIntro ?? '')) !== ''
    ? trim((string) $openRacesPanelIntro)
    : 'Completeaza informatiile lipsa pentru a putea continua.';
// Setat doar de pagina de editare: cursa deschisa acum, evidentiata in lista.
$openRacesPanelCurrentRaceId = (int) ($openRacesPanelCurrentRaceId ?? 0);
?>
<?php if ($openRacesCount > 0): ?>
    <div class="dispatcher-open-races-alert-container">
        <button
            type="button"
            class="dispatcher-open-races-toggle"
            data-open-races-toggle
            aria-expanded="false"
            aria-haspopup="dialog"
            aria-controls="open-races-panel"
        >
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span><?= e($openRacesPanelLabel) ?> (<?= e((string) $openRacesCount) ?>)</span>
        </button>
    </div>

    <div class="dispatcher-open-races-modal-overlay d-none" id="open-races-panel" data-open-races-panel role="dialog" aria-modal="true" aria-labelledby="open-races-modal-title">
        <section class="dispatcher-open-races-modal orx-modal" role="document">
            <header class="orx-header">
                <div class="orx-header-icon" aria-hidden="true">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div class="orx-header-titles">
                    <h3 id="open-races-modal-title"><?= e($openRacesPanelLabel) ?> (<span data-orx-header-count data-orx-total="<?= e((string) $openRacesCount) ?>"><?= e((string) $openRacesCount) ?></span>)</h3>
                    <p><?= e($openRacesPanelIntro) ?></p>
                </div>
                <button type="button" class="orx-close" data-open-races-close aria-label="Închide">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <div class="orx-toolbar">
                <div class="orx-tabs" role="tablist" aria-label="Filtrare după severitate">
                    <button type="button" class="orx-tab is-active" data-orx-severity-tab="" aria-pressed="true">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                        <span>Toate (<?= e((string) $openRacesCount) ?>)</span>
                    </button>
                    <button type="button" class="orx-tab orx-tab-critical" data-orx-severity-tab="critical" aria-pressed="false">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span>Critice (<?= e((string) ($openRacesSeverityCounts['critical'] ?? 0)) ?>)</span>
                    </button>
                    <button type="button" class="orx-tab orx-tab-important" data-orx-severity-tab="important" aria-pressed="false">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span>Importante (<?= e((string) ($openRacesSeverityCounts['important'] ?? 0)) ?>)</span>
                    </button>
                    <button type="button" class="orx-tab orx-tab-minor" data-orx-severity-tab="minor" aria-pressed="false">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                        <span>Minore (<?= e((string) ($openRacesSeverityCounts['minor'] ?? 0)) ?>)</span>
                    </button>
                </div>
                <div class="orx-filters">
                    <div class="orx-select-wrap">
                        <i class="bi bi-sort-down" aria-hidden="true"></i>
                        <select class="orx-transport-select" data-orx-sort aria-label="Ordonare curse">
                            <option value="created_desc">Cele mai nou adăugate</option>
                            <option value="created_asc">Cele mai vechi adăugate</option>
                            <option value="start_desc">Data cursei: descrescător</option>
                            <option value="start_asc">Data cursei: crescător</option>
                        </select>
                    </div>
                    <div class="orx-select-wrap">
                        <i class="bi bi-truck" aria-hidden="true"></i>
                        <select class="orx-transport-select" data-orx-transport aria-label="Filtrare după tip transport">
                            <option value="">Tip transport</option>
                            <?php foreach ($transportTypes as $transportTypeValue => $transportTypeLabel): ?>
                                <option value="<?= e((string) $transportTypeValue) ?>"><?= e((string) $transportTypeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="orx-plates" data-orx-plates>
                        <button type="button" class="orx-plates-toggle" data-orx-plates-toggle aria-haspopup="listbox" aria-expanded="false">
                            <i class="bi bi-truck-front" aria-hidden="true"></i>
                            <span data-orx-plates-label>Nr. înmatriculare</span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="orx-plates-menu" data-orx-plates-menu hidden>
                            <div class="orx-plates-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" data-orx-plates-search placeholder="Caută nr. înmatriculare..." aria-label="Caută număr de înmatriculare">
                            </div>
                            <label class="orx-plates-option orx-plates-selectall">
                                <input type="checkbox" data-orx-plates-all>
                                <span>Selectează toate</span>
                            </label>
                            <div class="orx-plates-list" data-orx-plates-list>
                                <?php foreach ($openRacesPlates as $openRacesPlate): ?>
                                    <label class="orx-plates-option">
                                        <input type="checkbox" value="<?= e((string) $openRacesPlate) ?>" data-orx-plate-option>
                                        <span><?= e((string) $openRacesPlate) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="orx-plates-footer">
                                <span class="orx-plates-count" data-orx-plates-count>0 vehicule selectate</span>
                                <a href="#" data-orx-plates-clear>Șterge selecția</a>
                            </div>
                        </div>
                    </div>
                    <div class="orx-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" data-orx-search placeholder="Caută cursă, șofer, locație..." aria-label="Caută cursă, șofer sau locație">
                    </div>
                </div>
            </div>

            <p class="orx-subnote">Cursele de mai jos conțin informații incomplete care ar putea afecta facturarea sau raportarea.</p>

            <div class="orx-body dispatcher-open-races-modal-body">
                <?php
                $orxSeverityMeta = [
                    'critical' => ['summary' => 'critice', 'icon' => 'bi-exclamation-circle-fill'],
                    'important' => ['summary' => 'importante', 'icon' => 'bi-exclamation-circle-fill'],
                    'minor' => ['summary' => 'minore', 'icon' => 'bi-info-circle-fill'],
                ];
                ?>
                <div class="orx-list-head" aria-hidden="true">
                    <span>Cursă</span>
                    <span>Detalii</span>
                    <span>Informații lipsă</span>
                    <span class="orx-col-action">Acțiune</span>
                </div>

                <?php foreach ($openRacesRows as $openRace): ?>
                    <?php
                        $openRaceId = (int) ($openRace['id'] ?? 0);
                        $openPlate = trim((string) ($openRace['nr_inmatriculare'] ?? ''));
                        $openDriver = trim((string) ($openRace['sofer_nume'] ?? ''));
                        $openBeneficiar = trim((string) ($openRace['beneficiar_nume'] ?? ''));
                        $openTransportType = (string) ($openRace['tip_transport'] ?? '');
                        $openTransportLabel = $transportTypes[$openTransportType] ?? '-';
                        $openSeverity = (string) ($openRace['missing_severity'] ?? 'minor');
                        $openSeverityMeta = $orxSeverityMeta[$openSeverity] ?? $orxSeverityMeta['minor'];
                        $openMissing = is_array($openRace['missing_information'] ?? null) ? $openRace['missing_information'] : [];
                        $openStartDate = trim((string) ($openRace['data_inceput'] ?? ''));
                        $openStartTime = trim((string) ($openRace['ora_inceput'] ?? ''));
                        $openCreatedAt = trim((string) ($openRace['created_at'] ?? ''));
                        $openDetailsUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $openRaceId]);
                        $openMissingCount = count($openMissing);

                        // Cheile de focus grupate pe severitate: butonul "Deschide cursa"
                        // trimite spre editare exact lipsurile din tab-ul activ (cerinta 9).
                        $orxFocusBySeverity = ['' => [], 'critical' => [], 'important' => [], 'minor' => []];
                        foreach ($openMissing as $openMissingItem) {
                            $orxItemFocus = trim((string) ($openMissingItem['focus'] ?? ''));
                            if ($orxItemFocus === '') {
                                continue;
                            }
                            $orxItemSeverity = (string) ($openMissingItem['severity'] ?? 'minor');
                            $orxFocusBySeverity[''][] = $orxItemFocus;
                            if (isset($orxFocusBySeverity[$orxItemSeverity])) {
                                $orxFocusBySeverity[$orxItemSeverity][] = $orxItemFocus;
                            }
                        }
                        $orxFocusBySeverity = array_map(
                            static fn (array $keys): string => implode(',', array_values(array_unique($keys))),
                            $orxFocusBySeverity
                        );

                        // Text pentru cautarea libera: cursa, sofer, beneficiar, locatii.
                        $orxSearchValue = mb_strtolower(trim(implode(' ', array_filter([
                            $openPlate,
                            $openDriver,
                            $openBeneficiar,
                            (string) $openTransportLabel,
                            trim((string) ($openRace['loc_incarcare_nume'] ?? '')),
                            trim((string) ($openRace['zona_distributie_nume'] ?? '')),
                            trim((string) ($openRace['loc_plecare'] ?? '')),
                            trim((string) ($openRace['loc_livrare'] ?? '')),
                            '#' . $openRaceId,
                        ]))));
                    ?>
                    <?php $orxIsCurrentRace = $openRacesPanelCurrentRaceId > 0 && $openRacesPanelCurrentRaceId === $openRaceId; ?>
                    <article
                        class="orx-row<?= $orxIsCurrentRace ? ' is-current' : '' ?>"
                        data-open-race-card
                        data-orx-severity-value="<?= e($openSeverity) ?>"
                        data-orx-transport-value="<?= e($openTransportType) ?>"
                        data-orx-plate-value="<?= e($openPlate) ?>"
                        data-orx-created-value="<?= e($openCreatedAt) ?>"
                        data-orx-start-value="<?= e($openStartDate) ?>"
                        data-orx-id-value="<?= e((string) $openRaceId) ?>"
                        data-orx-search-value="<?= e($orxSearchValue) ?>"
                        data-orx-open-base="<?= e($openDetailsUrl) ?>"
                        data-orx-focus-all="<?= e($orxFocusBySeverity['']) ?>"
                        data-orx-focus-critical="<?= e($orxFocusBySeverity['critical']) ?>"
                        data-orx-focus-important="<?= e($orxFocusBySeverity['important']) ?>"
                        data-orx-focus-minor="<?= e($orxFocusBySeverity['minor']) ?>"
                    >
                        <div class="orx-row-trip">
                            <span class="orx-row-icon" aria-hidden="true"><i class="bi bi-truck"></i></span>
                            <div class="orx-row-trip-text">
                                <strong class="orx-row-plate">
                                    <?= e($openPlate !== '' ? $openPlate : ('Cursa #' . $openRaceId)) ?>
                                    <?php if ($orxIsCurrentRace): ?>
                                        <span class="orx-current-tag">cursa deschisă</span>
                                    <?php endif; ?>
                                </strong>
                                <span class="orx-row-driver"><?= e($openDriver !== '' ? $openDriver : 'Șofer neasignat') ?></span>
                                <span class="orx-row-when">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <?= e($openStartDate !== '' ? format_date_ro($openStartDate) : '-') ?>
                                    <i class="bi bi-clock" aria-hidden="true"></i>
                                    <?= e($openStartTime !== '' ? substr($openStartTime, 0, 5) : '-') ?>
                                </span>
                            </div>
                        </div>

                        <div class="orx-row-details">
                            <span><i class="bi bi-diagram-3" aria-hidden="true"></i><?= e((string) $openTransportLabel) ?></span>
                            <span><i class="bi bi-box-seam" aria-hidden="true"></i><?= e($openBeneficiar !== '' ? $openBeneficiar : '-') ?></span>
                        </div>

                        <?php /* Cutie cu inaltime fixa: lipsurile se insiruie vertical si
                                 deruleaza in interior, ca randul sa ramana la fel de inalt. */ ?>
                        <div class="orx-row-missing orx-missing-<?= e($openSeverity) ?>">
                            <div class="orx-missing-head">
                                <i class="bi <?= e($openSeverityMeta['icon']) ?>" aria-hidden="true"></i>
                                <?= e($openMissingCount === 1 ? '1 informație lipsă' : ($openMissingCount . ' informații lipsă')) ?>
                            </div>
                            <ul class="orx-missing-list">
                                <?php foreach ($openMissing as $openMissingItem): ?>
                                    <?php
                                        $orxItemFocus = trim((string) ($openMissingItem['focus'] ?? ''));
                                        $orxItemSeverity = (string) ($openMissingItem['severity'] ?? 'minor');
                                        $orxItemUrl = $orxItemFocus !== ''
                                            ? build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $openRaceId, 'focus' => $orxItemFocus])
                                            : $openDetailsUrl;
                                    ?>
                                    <li class="orx-missing-item orx-missing-item-<?= e($orxItemSeverity) ?>">
                                        <a
                                            href="<?= e($orxItemUrl) ?>"
                                            title="<?= e((string) ($openMissingItem['explanation'] ?? '')) ?>"
                                        ><?= e((string) ($openMissingItem['label'] ?? '')) ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="orx-row-action">
                            <a class="orx-open-btn" data-orx-open href="<?= e($openDetailsUrl) ?>">
                                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                <span>Deschide cursa</span>
                                <i class="bi bi-chevron-right orx-open-chevron" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="orx-empty" data-orx-empty hidden>
                    <i class="bi bi-filter-circle" aria-hidden="true"></i>
                    Nicio cursă nu corespunde filtrelor selectate.
                </div>
            </div>

            <footer class="orx-footer">
                <div class="orx-footer-info">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    <div>
                        <strong>Completează informațiile lipsă pentru a finaliza facturarea curselor sau corectează erorile de raportare.</strong>
                        <span>Poți deschide cursa pentru editare și completa doar câmpurile marcate.</span>
                    </div>
                </div>
                <button type="button" class="orx-footer-close" data-open-races-close>Închide</button>
            </footer>
        </section>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var openRacesToggleEl = document.querySelector('[data-open-races-toggle]');
    var openRacesModalEl = document.querySelector('[data-open-races-panel]');
    var openRacesCloseEls = document.querySelectorAll('[data-open-races-close]');
    var openRacesFilterEls = document.querySelectorAll('[data-open-races-filter]');
    var lastOpenRacesFocusEl = null;
    if (
        openRacesToggleEl instanceof HTMLButtonElement
        && openRacesModalEl instanceof HTMLElement
    ) {
        var isOpenRacesOpen = function () {
            return !openRacesModalEl.classList.contains('d-none');
        };

        // Randurile sunt compacte si nu se mai extind: lipsurile se vad direct,
        // in cutia lor cu derulare proprie.

        // Filtrare combinata: tab severitate + tip transport + numere de inmatriculare (multi-select).
        var orxActiveSeverity = '';
        var orxSeverityTabEls = openRacesModalEl.querySelectorAll('[data-orx-severity-tab]');
        var orxTransportSelectEl = openRacesModalEl.querySelector('[data-orx-transport]');
        var orxPlatesWrapEl = openRacesModalEl.querySelector('[data-orx-plates]');
        var orxPlatesToggleEl = openRacesModalEl.querySelector('[data-orx-plates-toggle]');
        var orxPlatesMenuEl = openRacesModalEl.querySelector('[data-orx-plates-menu]');
        var orxPlatesSearchEl = openRacesModalEl.querySelector('[data-orx-plates-search]');
        var orxPlatesAllEl = openRacesModalEl.querySelector('[data-orx-plates-all]');
        var orxPlatesLabelEl = openRacesModalEl.querySelector('[data-orx-plates-label]');
        var orxPlatesCountEl = openRacesModalEl.querySelector('[data-orx-plates-count]');
        var orxPlatesClearEl = openRacesModalEl.querySelector('[data-orx-plates-clear]');
        var orxPlateOptionEls = openRacesModalEl.querySelectorAll('[data-orx-plate-option]');
        var orxEmptyEl = openRacesModalEl.querySelector('[data-orx-empty]');
        var orxSearchEl = openRacesModalEl.querySelector('[data-orx-search]');

        var orxSelectedPlates = function () {
            var plates = [];
            orxPlateOptionEls.forEach(function (optionEl) {
                if (optionEl instanceof HTMLInputElement && optionEl.checked) {
                    plates.push(optionEl.value);
                }
            });
            return plates;
        };

        var orxSyncPlatesUi = function () {
            var selected = orxSelectedPlates();
            if (orxPlatesLabelEl instanceof HTMLElement) {
                orxPlatesLabelEl.textContent = selected.length > 0
                    ? 'Nr. înmatriculare (' + selected.length + ')'
                    : 'Nr. înmatriculare';
            }
            if (orxPlatesCountEl instanceof HTMLElement) {
                orxPlatesCountEl.textContent = selected.length === 1
                    ? '1 vehicul selectat'
                    : selected.length + ' vehicule selectate';
            }
            if (orxPlatesAllEl instanceof HTMLInputElement) {
                var total = orxPlateOptionEls.length;
                orxPlatesAllEl.checked = total > 0 && selected.length === total;
                orxPlatesAllEl.indeterminate = selected.length > 0 && selected.length < total;
            }
        };

        var orxSortSelectEl = openRacesModalEl.querySelector('[data-orx-sort]');

        /**
         * Reordoneaza cardurile in DOM dupa criteriul ales.
         * Cardurile fara data folosita la sortare raman la coada, ca sa nu sara in fata.
         */
        var applyOpenRacesSort = function () {
            var mode = orxSortSelectEl instanceof HTMLSelectElement ? orxSortSelectEl.value : 'created_desc';
            var cards = Array.prototype.slice.call(openRacesModalEl.querySelectorAll('[data-open-race-card]'));
            if (cards.length === 0) {
                return;
            }

            var container = cards[0].parentNode;
            if (!container) {
                return;
            }

            var attr = mode.indexOf('start') === 0 ? 'data-orx-start-value' : 'data-orx-created-value';
            var descending = mode.indexOf('_desc') !== -1;

            cards.sort(function (a, b) {
                var aValue = String(a.getAttribute(attr) || '');
                var bValue = String(b.getAttribute(attr) || '');
                if (aValue === '' && bValue !== '') {
                    return 1;
                }
                if (bValue === '' && aValue !== '') {
                    return -1;
                }
                if (aValue !== bValue) {
                    return descending ? (aValue < bValue ? 1 : -1) : (aValue > bValue ? 1 : -1);
                }

                var aId = parseInt(a.getAttribute('data-orx-id-value') || '0', 10);
                var bId = parseInt(b.getAttribute('data-orx-id-value') || '0', 10);
                return descending ? bId - aId : aId - bId;
            });

            cards.forEach(function (cardEl) {
                container.appendChild(cardEl);
            });
        };

        if (orxSortSelectEl instanceof HTMLSelectElement) {
            orxSortSelectEl.addEventListener('change', function () {
                applyOpenRacesSort();
            });
        }

        // "Deschide cursa" duce in editare lipsurile din tab-ul activ: in "Toate"
        // toate campurile, in "Critice" doar cele critice s.a.m.d.
        var orxSyncOpenLink = function (cardEl) {
            var linkEl = cardEl.querySelector('[data-orx-open]');
            if (!(linkEl instanceof HTMLAnchorElement)) {
                return;
            }

            var base = cardEl.getAttribute('data-orx-open-base') || '';
            var focusKeys = cardEl.getAttribute(
                'data-orx-focus-' + (orxActiveSeverity === '' ? 'all' : orxActiveSeverity)
            ) || '';

            linkEl.setAttribute('href', focusKeys !== ''
                ? base + '&focus=' + encodeURIComponent(focusKeys)
                : base);
        };

        var applyOpenRacesFilters = function () {
            var transport = orxTransportSelectEl instanceof HTMLSelectElement ? orxTransportSelectEl.value : '';
            var plates = orxSelectedPlates();
            var search = orxSearchEl instanceof HTMLInputElement
                ? orxSearchEl.value.trim().toLowerCase()
                : '';
            var visibleCount = 0;

            openRacesModalEl.querySelectorAll('[data-open-race-card]').forEach(function (cardEl) {
                if (!(cardEl instanceof HTMLElement)) {
                    return;
                }

                var matches = (orxActiveSeverity === '' || cardEl.getAttribute('data-orx-severity-value') === orxActiveSeverity)
                    && (transport === '' || cardEl.getAttribute('data-orx-transport-value') === transport)
                    && (plates.length === 0 || plates.indexOf(cardEl.getAttribute('data-orx-plate-value') || '') !== -1)
                    && (search === '' || (cardEl.getAttribute('data-orx-search-value') || '').indexOf(search) !== -1);

                cardEl.hidden = !matches;
                if (matches) {
                    visibleCount++;
                    orxSyncOpenLink(cardEl);
                }
            });

            if (orxEmptyEl instanceof HTMLElement) {
                orxEmptyEl.hidden = visibleCount > 0;
            }

            // Numarul din titlu reflecta cursele care corespund filtrelor active:
            // fara filtre -> totalul; cu filtre -> "N din total".
            var orxHeaderCountEl = openRacesModalEl.querySelector('[data-orx-header-count]');
            if (orxHeaderCountEl instanceof HTMLElement) {
                var orxTotalCount = orxHeaderCountEl.getAttribute('data-orx-total') || '0';
                var orxHasActiveFilters = orxActiveSeverity !== ''
                    || transport !== ''
                    || plates.length > 0
                    || search !== '';
                orxHeaderCountEl.textContent = orxHasActiveFilters
                    ? visibleCount + ' din ' + orxTotalCount
                    : orxTotalCount;
            }

            orxSeverityTabEls.forEach(function (tabEl) {
                if (!(tabEl instanceof HTMLButtonElement)) {
                    return;
                }
                var isActive = (tabEl.getAttribute('data-orx-severity-tab') || '') === orxActiveSeverity;
                tabEl.classList.toggle('is-active', isActive);
                tabEl.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            orxSyncPlatesUi();
        };

        var setOpenRacesFilter = function () {
            // Reset complet al filtrelor (la deschiderea popup-ului).
            orxActiveSeverity = '';
            if (orxTransportSelectEl instanceof HTMLSelectElement) {
                orxTransportSelectEl.value = '';
            }
            if (orxSearchEl instanceof HTMLInputElement) {
                orxSearchEl.value = '';
            }
            orxPlateOptionEls.forEach(function (optionEl) {
                if (optionEl instanceof HTMLInputElement) {
                    optionEl.checked = false;
                }
            });
            if (orxPlatesSearchEl instanceof HTMLInputElement) {
                orxPlatesSearchEl.value = '';
                orxPlateOptionEls.forEach(function (optionEl) {
                    var labelEl = optionEl instanceof HTMLElement ? optionEl.closest('.orx-plates-option') : null;
                    if (labelEl instanceof HTMLElement) {
                        labelEl.classList.remove('d-none');
                    }
                });
            }
            if (orxPlatesMenuEl instanceof HTMLElement) {
                orxPlatesMenuEl.hidden = true;
            }
            applyOpenRacesFilters();
        };

        orxSeverityTabEls.forEach(function (tabEl) {
            if (!(tabEl instanceof HTMLButtonElement)) {
                return;
            }
            tabEl.addEventListener('click', function () {
                orxActiveSeverity = tabEl.getAttribute('data-orx-severity-tab') || '';
                applyOpenRacesFilters();
            });
        });

        if (orxTransportSelectEl instanceof HTMLSelectElement) {
            orxTransportSelectEl.addEventListener('change', applyOpenRacesFilters);
        }

        if (orxSearchEl instanceof HTMLInputElement) {
            orxSearchEl.addEventListener('input', applyOpenRacesFilters);
            orxSearchEl.addEventListener('search', applyOpenRacesFilters);
        }

        if (orxPlatesToggleEl instanceof HTMLButtonElement && orxPlatesMenuEl instanceof HTMLElement) {
            orxPlatesToggleEl.addEventListener('click', function () {
                var willOpen = orxPlatesMenuEl.hidden;
                orxPlatesMenuEl.hidden = !willOpen;
                orxPlatesToggleEl.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen && orxPlatesSearchEl instanceof HTMLInputElement) {
                    orxPlatesSearchEl.focus();
                }
            });
            document.addEventListener('click', function (event) {
                if (orxPlatesMenuEl.hidden) {
                    return;
                }
                if (event.target instanceof Node && orxPlatesWrapEl instanceof HTMLElement && !orxPlatesWrapEl.contains(event.target)) {
                    orxPlatesMenuEl.hidden = true;
                    orxPlatesToggleEl.setAttribute('aria-expanded', 'false');
                }
            });
        }

        orxPlateOptionEls.forEach(function (optionEl) {
            if (optionEl instanceof HTMLInputElement) {
                optionEl.addEventListener('change', applyOpenRacesFilters);
            }
        });

        if (orxPlatesAllEl instanceof HTMLInputElement) {
            orxPlatesAllEl.addEventListener('change', function () {
                var check = orxPlatesAllEl.checked;
                orxPlateOptionEls.forEach(function (optionEl) {
                    var labelEl = optionEl instanceof HTMLElement ? optionEl.closest('.orx-plates-option') : null;
                    var isVisible = !(labelEl instanceof HTMLElement) || !labelEl.classList.contains('d-none');
                    if (optionEl instanceof HTMLInputElement && isVisible) {
                        optionEl.checked = check;
                    }
                });
                applyOpenRacesFilters();
            });
        }

        if (orxPlatesSearchEl instanceof HTMLInputElement) {
            orxPlatesSearchEl.addEventListener('input', function () {
                var needle = orxPlatesSearchEl.value.toLowerCase();
                orxPlateOptionEls.forEach(function (optionEl) {
                    var labelEl = optionEl instanceof HTMLElement ? optionEl.closest('.orx-plates-option') : null;
                    if (labelEl instanceof HTMLElement) {
                        labelEl.classList.toggle('d-none', labelEl.textContent.toLowerCase().indexOf(needle) === -1);
                    }
                });
            });
        }

        if (orxPlatesClearEl instanceof HTMLElement) {
            orxPlatesClearEl.addEventListener('click', function (event) {
                event.preventDefault();
                orxPlateOptionEls.forEach(function (optionEl) {
                    if (optionEl instanceof HTMLInputElement) {
                        optionEl.checked = false;
                    }
                });
                applyOpenRacesFilters();
            });
        }

        var setOpenRacesOpen = function (open) {
            if (open === isOpenRacesOpen()) {
                return;
            }

            openRacesToggleEl.setAttribute('aria-expanded', open ? 'true' : 'false');
            openRacesModalEl.classList.toggle('d-none', !open);
            document.body.classList.toggle('dispatcher-open-races-modal-open', open);

            if (open) {
                lastOpenRacesFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                setOpenRacesFilter('');
                var closeEl = openRacesModalEl.querySelector('[data-open-races-close]');
                if (closeEl instanceof HTMLElement) {
                    closeEl.focus();
                }
                return;
            }

            if (lastOpenRacesFocusEl instanceof HTMLElement) {
                lastOpenRacesFocusEl.focus();
            }
        };

        openRacesToggleEl.addEventListener('click', function () {
            setOpenRacesOpen(true);
        });

        openRacesCloseEls.forEach(function (closeEl) {
            if (!(closeEl instanceof HTMLButtonElement)) {
                return;
            }

            closeEl.addEventListener('click', function () {
                setOpenRacesOpen(false);
            });
        });

        openRacesFilterEls.forEach(function (filterEl) {
            if (!(filterEl instanceof HTMLButtonElement)) {
                return;
            }

            filterEl.addEventListener('click', function () {
                if (filterEl.disabled) {
                    return;
                }

                setOpenRacesFilter(filterEl.getAttribute('data-open-races-filter') || '');
            });
        });

        document.addEventListener('click', function (event) {
            if (!isOpenRacesOpen()) {
                return;
            }

            var targetEl = event.target;
            if (!(targetEl instanceof Node)) {
                return;
            }

            if (targetEl === openRacesModalEl) {
                setOpenRacesOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpenRacesOpen()) {
                setOpenRacesOpen(false);
            }
        });
    }
});
</script>
