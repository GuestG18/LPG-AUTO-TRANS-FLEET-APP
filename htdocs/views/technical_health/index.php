<?php
declare(strict_types=1);

$data = is_array($technicalData ?? null) ? $technicalData : [];
$vehicle = is_array($data['vehicle'] ?? null) ? $data['vehicle'] : null;
$vehicles = is_array($data['vehicles'] ?? null) ? $data['vehicles'] : [];
$categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
$selectedCategoryId = (int) ($data['selectedCategoryId'] ?? 0);
$vehicleHealth = $data['vehicleHealth'] ?? null;
$vehicleStatus = is_array($data['vehicleStatus'] ?? null) ? $data['vehicleStatus'] : ['tone' => 'neutral', 'label' => 'N/A'];
$vehicleType = (string) ($data['vehicleType'] ?? 'camion');
$lastUpdated = (string) ($data['lastUpdated'] ?? '');
$evolution = is_array($data['evolution'] ?? null) ? $data['evolution'] : [];

$healthLabel = static fn (?int $value): string => $value === null ? 'N/A' : $value . '%';
$healthTone = static fn (string $tone): string => in_array($tone, ['green', 'yellow', 'orange', 'red', 'neutral'], true) ? $tone : 'neutral';
$formatDate = static fn (?string $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
$formatDateTime = static fn (?string $value): string => trim((string) $value) !== '' ? format_datetime_ro((string) $value) : '-';
$currency = static fn (float $value): string => format_number_ro($value, 2) . ' RON';

$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[(int) ($category['id'] ?? 0)] = $category;
}
$selectedCategory = $categoryMap[$selectedCategoryId] ?? ($categories[0] ?? null);
$selectedCategoryId = is_array($selectedCategory) ? (int) ($selectedCategory['id'] ?? 0) : 0;

$vehiclePhotoUrl = $vehicle !== null ? vehicle_image_url((string) ($vehicle['poza_stocata'] ?? '')) : null;
$technicalModelUrls = [
    'cap_tractor' => url('assets/models/technical-health/truck_edit_v1.glb'),
    'semiremorca' => url('assets/models/technical-health/semi_trailer_edit_v1.glb'),
    'camion' => url('assets/models/technical-health/Ansamblu_edit_v1.glb'),
    'ansamblu' => url('assets/models/technical-health/Ansamblu_edit_v1.glb'),
];
$technicalModelLabels = [
    'cap_tractor' => 'Cap tractor',
    'semiremorca' => 'Semi-remorca',
    'camion' => 'Camion',
    'ansamblu' => 'Ansamblu',
];
$activeTechnicalModelUrl = $technicalModelUrls[$vehicleType] ?? $technicalModelUrls['camion'];
$activeTechnicalModelLabel = $technicalModelLabels[$vehicleType] ?? $technicalModelLabels['camion'];
$payload = [
    'selectedCategoryId' => $selectedCategoryId,
    'vehicleType' => $vehicleType,
    'modelUrls' => $technicalModelUrls,
    'activeModelUrl' => $activeTechnicalModelUrl,
    'activeModelLabel' => $activeTechnicalModelLabel,
    'categories' => array_values($categories),
];
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$chartPoints = [];
$chartArea = [];
foreach ($evolution as $index => $point) {
    $x = 44 + ($index * 92);
    $y = 140 - ((int) ($point['value'] ?? 0) * 1.05);
    $chartPoints[] = $x . ',' . max(28, min(140, $y));
    $chartArea[] = $x . ',' . max(28, min(140, $y));
}
$chartPolyline = implode(' ', $chartPoints);
$chartAreaPath = $chartArea !== [] ? '44,140 ' . implode(' ', $chartArea) . ' ' . (44 + ((count($chartArea) - 1) * 92)) . ',140' : '';
?>

<div class="technical-health-page">
    <?php if ($vehicle === null): ?>
        <div class="technical-empty-state">
            <i class="bi bi-truck"></i>
            <h1>Stare tehnica vehicul</h1>
            <p>Nu exista vehicule active pentru afisarea hartii tehnice.</p>
            <a class="btn btn-primary" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'create'])) ?>">Adauga vehicul</a>
        </div>
    <?php else: ?>
        <div class="technical-page-head">
            <div>
                <h1>Stare tehnic&#259; vehicul</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(build_query_url(['page' => 'stare_tehnica'])) ?>">Stare tehnic&#259;</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?></li>
                    </ol>
                </nav>
            </div>
            <form method="get" class="technical-vehicle-switch">
                <input type="hidden" name="page" value="stare_tehnica">
                <label for="technical-health-vehicle">Vehicul</label>
                <select class="form-select" id="technical-health-vehicle" name="vehicle_id" onchange="this.form.submit()">
                    <?php foreach ($vehicles as $vehicleOption): ?>
                        <option value="<?= e((string) $vehicleOption['id']) ?>" <?= (int) ($vehicleOption['id'] ?? 0) === (int) ($vehicle['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e(trim((string) ($vehicleOption['nr_inmatriculare'] ?? '') . ' - ' . (string) ($vehicleOption['marca'] ?? '') . ' ' . (string) ($vehicleOption['model'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <section class="technical-summary-card">
            <div class="technical-vehicle-photo">
                <?php if ($vehiclePhotoUrl !== null): ?>
                    <img src="<?= e($vehiclePhotoUrl) ?>" alt="<?= e((string) ($vehicle['nr_inmatriculare'] ?? 'Vehicul')) ?>" loading="lazy">
                <?php else: ?>
                    <div class="technical-vehicle-placeholder">
                        <i class="bi bi-truck-front"></i>
                        <span>F&#259;r&#259; fotografie</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="technical-summary-main">
                <strong><?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?></strong>
                <span><?= e(trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''))) ?></span>
                <small><?= e((string) ($vehicle['an_fabricatie'] ?? '-')) ?></small>
            </div>
            <div class="technical-summary-metric">
                <span>Kilometri</span>
                <strong><?= e(format_number_ro((float) ($vehicle['km_bord'] ?? 0), 0)) ?> km</strong>
            </div>
            <div class="technical-summary-metric">
                <span>Data ultimei actualiz&#259;ri</span>
                <strong><?= e($formatDateTime($lastUpdated)) ?></strong>
            </div>
            <div class="technical-summary-health">
                <span>Stare general&#259;</span>
                <div class="technical-health-ring technical-tone-<?= e($healthTone((string) ($vehicleStatus['tone'] ?? 'neutral'))) ?>" style="--health: <?= e((string) ($vehicleHealth ?? 0)) ?>">
                    <strong><?= e($healthLabel($vehicleHealth !== null ? (int) $vehicleHealth : null)) ?></strong>
                </div>
                <small><?= e((string) ($vehicleStatus['label'] ?? 'N/A')) ?></small>
            </div>
        </section>

        <div class="technical-health-layout is-detail-collapsed" data-technical-layout>
            <section class="technical-map-column">
                <article class="technical-card technical-systems-card">
                    <div class="technical-card-head">
                        <div>
                            <h2>Stare sisteme</h2>
                            <p>Harta tehnic&#259; a vehiculului selectat.</p>
                        </div>
                        <span class="technical-applicability"><?= e((string) count($categories)) ?> sisteme aplicabile</span>
                    </div>

                    <?php if ($categories === []): ?>
                        <div class="technical-empty-state compact">
                            <i class="bi bi-exclamation-circle"></i>
                            <p>Nu exista categorii tehnice aplicabile pentru acest tip de vehicul.</p>
                        </div>
                    <?php else: ?>
                        <div class="technical-map-stage" data-tech-map>
                            <div
                                class="technical-truck-visual"
                                data-technical-model-viewer
                                data-vehicle-type="<?= e($vehicleType) ?>"
                                data-model-url="<?= e($activeTechnicalModelUrl) ?>"
                            >
                                <canvas
                                    class="technical-model-canvas"
                                    data-technical-model-canvas
                                    role="img"
                                    aria-label="Model 3D <?= e($activeTechnicalModelLabel) ?>"
                                ></canvas>
                                <div class="technical-model-loader" data-technical-model-loader>
                                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                    <strong>Se incarc&#259; modelul 3D</strong>
                                    <small data-technical-model-progress><?= e($activeTechnicalModelLabel) ?></small>
                                </div>
                                <div class="technical-model-error d-none" data-technical-model-error>
                                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                    <span>Modelul 3D nu a putut fi inc&#259;rcat.</span>
                                </div>
                                <div class="technical-model-actions d-none" data-technical-model-actions aria-label="Comenzi model 3D">
                                    <button type="button" data-technical-model-action="zoom-in" aria-label="Apropie modelul" title="Apropie modelul">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" data-technical-model-action="zoom-out" aria-label="Departeaza modelul" title="Departeaza modelul">
                                        <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" data-technical-model-action="reset" aria-label="Reseteaza vederea" title="Reseteaza vederea">
                                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" data-technical-model-action="fullscreen" aria-label="Mareste modelul 3D" title="Mareste modelul 3D">
                                        <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="technical-model-fallback" data-technical-model-fallback aria-hidden="true">
                                <svg viewBox="0 0 720 360" role="img" focusable="false">
                                    <defs>
                                        <linearGradient id="truckGlass" x1="0%" y1="0%" x2="100%" y2="100%">
                                            <stop offset="0%" stop-color="#f8fafc" stop-opacity=".94" />
                                            <stop offset="100%" stop-color="#cbd5e1" stop-opacity=".68" />
                                        </linearGradient>
                                        <linearGradient id="truckSteel" x1="0%" y1="0%" x2="100%" y2="100%">
                                            <stop offset="0%" stop-color="#dbeafe" stop-opacity=".36" />
                                            <stop offset="100%" stop-color="#94a3b8" stop-opacity=".22" />
                                        </linearGradient>
                                    </defs>
                                    <path d="M88 228h402c21 0 40-12 49-31l39-81c5-10 15-17 26-17h37c15 0 27 12 27 27v102h28v46H86c-16 0-29-13-29-29v-17h31z" fill="url(#truckSteel)" stroke="#94a3b8" stroke-width="4" />
                                    <path d="M103 222V84c0-18 15-33 33-33h273c30 0 54 24 54 54v117H103z" fill="url(#truckGlass)" stroke="#64748b" stroke-width="3" />
                                    <path d="M138 88h110v84H121v-66c0-10 7-18 17-18zM270 88h122c19 0 35 16 35 35v49H270V88z" fill="#f8fafc" stroke="#94a3b8" stroke-width="2" />
                                    <path d="M116 244h527" stroke="#334155" stroke-width="8" stroke-linecap="round" opacity=".45" />
                                    <circle cx="181" cy="274" r="45" fill="#f8fafc" stroke="#334155" stroke-width="8" />
                                    <circle cx="181" cy="274" r="20" fill="#cbd5e1" />
                                    <circle cx="444" cy="274" r="45" fill="#f8fafc" stroke="#334155" stroke-width="8" />
                                    <circle cx="444" cy="274" r="20" fill="#cbd5e1" />
                                    <circle cx="568" cy="274" r="45" fill="#f8fafc" stroke="#334155" stroke-width="8" />
                                    <circle cx="568" cy="274" r="20" fill="#cbd5e1" />
                                    <path d="M176 199h93v33h-93z" fill="#22c55e" opacity=".34" />
                                    <path d="M271 197h86v37h-86z" fill="#0d6efd" opacity=".28" />
                                    <path d="M359 197h90v37h-90z" fill="#8b5cf6" opacity=".27" />
                                    <path d="M112 200h58v34h-58z" fill="#f97316" opacity=".3" />
                                    <path d="M457 190h118v45H457z" fill="#16a34a" opacity=".25" />
                                    <path d="M588 140h60v79h-60z" fill="#0ea5e9" opacity=".28" />
                                    <g stroke="#94a3b8" stroke-width="1.5" opacity=".9">
                                        <path d="M123 116h300M123 147h300M123 178h300M130 91v127M166 91v127M205 91v127M245 91v127M285 91v127M326 91v127M367 91v127M408 100v118" />
                                        <path d="M470 225c32-36 62-75 87-118M514 225l62-112M556 225l44-99" />
                                    </g>
                                </svg>
                                </div>
                            </div>

                            <?php foreach ($categories as $index => $category): ?>
                                <?php
                                $position = $index + 1;
                                $side = $position <= 6 ? 'left' : ($position <= 12 ? 'right' : 'bottom');
                                $isSelected = (int) ($category['id'] ?? 0) === $selectedCategoryId;
                                $tone = $healthTone((string) ($category['status_tone'] ?? 'neutral'));
                                $health = $category['health_percent'] === null ? null : (int) $category['health_percent'];
                                ?>
                                <button
                                    class="technical-category-card technical-pos-<?= e((string) $position) ?> technical-tone-<?= e($tone) ?> <?= $isSelected ? 'is-selected' : '' ?>"
                                    type="button"
                                    data-tech-category
                                    data-category-id="<?= e((string) $category['id']) ?>"
                                    data-side="<?= e($side) ?>"
                                    style="--health: <?= e((string) ($health ?? 0)) ?>"
                                >
                                    <span class="technical-card-icon"><i class="bi <?= e((string) ($category['icon'] ?? 'bi-gear')) ?>"></i></span>
                                    <span class="technical-card-text">
                                        <strong><?= e((string) ($category['sort_order'] ?? '')) ?>. <?= e((string) ($category['name'] ?? '')) ?></strong>
                                        <small><?= e($healthLabel($health)) ?> · <?= e((string) ($category['status_label'] ?? 'N/A')) ?></small>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                            <div class="technical-model-hint" data-technical-model-hint>
                                <i class="bi bi-cursor"></i>
                                Click pe zonele colorate ale modelului pentru detalii.
                            </div>
                            <div class="technical-context-card d-none" data-tech-context-card></div>
                        </div>

                        <div class="technical-legend">
                            <span><i class="technical-dot green"></i>80% - 100% Bun&#259;</span>
                            <span><i class="technical-dot yellow"></i>50% - 79% Aten&#539;ie</span>
                            <span><i class="technical-dot orange"></i>20% - 49% Risc</span>
                            <span><i class="technical-dot red"></i>0% - 19% Critic</span>
                            <span><i class="technical-dot neutral"></i>N/A</span>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="technical-card technical-chart-card">
                    <div class="technical-card-head">
                        <div>
                            <h2>Evolu&#539;ia st&#259;rii generale</h2>
                            <p>Sanatatea medie lunara pentru vehiculul selectat.</p>
                        </div>
                    </div>
                    <?php if ($evolution === []): ?>
                        <div class="technical-empty-state compact">
                            <i class="bi bi-graph-up"></i>
                            <p>Nu exista date suficiente pentru evolutie.</p>
                        </div>
                    <?php else: ?>
                        <svg class="technical-evolution-chart" viewBox="0 0 560 180" preserveAspectRatio="none" aria-label="Evolutia starii generale">
                            <line x1="34" y1="35" x2="532" y2="35" />
                            <line x1="34" y1="70" x2="532" y2="70" />
                            <line x1="34" y1="105" x2="532" y2="105" />
                            <line x1="34" y1="140" x2="532" y2="140" />
                            <polygon points="<?= e($chartAreaPath) ?>" />
                            <polyline points="<?= e($chartPolyline) ?>" />
                            <?php foreach ($evolution as $index => $point): ?>
                                <?php $x = 44 + ($index * 92); $y = 140 - ((int) ($point['value'] ?? 0) * 1.05); $y = max(28, min(140, $y)); ?>
                                <circle cx="<?= e((string) $x) ?>" cy="<?= e((string) $y) ?>" r="4" />
                                <text x="<?= e((string) $x) ?>" y="<?= e((string) ($y - 10)) ?>"><?= e((string) ($point['value'] ?? 0)) ?>%</text>
                                <text class="technical-chart-label" x="<?= e((string) $x) ?>" y="166"><?= e((string) ($point['label'] ?? '')) ?></text>
                            <?php endforeach; ?>
                        </svg>
                    <?php endif; ?>
                </article>
            </section>

            <button class="technical-panel-toggle" type="button" data-tech-panel-toggle aria-expanded="false" aria-label="Arata panoul de detalii" title="Arata detalii">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
            </button>

            <aside class="technical-detail-panel" data-tech-panel>
                <div class="technical-panel-loading">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    Se incarca detaliile sistemului...
                </div>
            </aside>
        </div>

        <script type="application/json" id="technicalHealthPayload"><?= $payloadJson ?></script>
        <script>
        (() => {
            const payloadEl = document.getElementById('technicalHealthPayload');
            const panel = document.querySelector('[data-tech-panel]');
            const layout = document.querySelector('[data-technical-layout]');
            const panelToggle = document.querySelector('[data-tech-panel-toggle]');
            const contextCard = document.querySelector('[data-tech-context-card]');
            const modelHint = document.querySelector('[data-technical-model-hint]');
            if (!payloadEl || !panel) {
                return;
            }

            const state = JSON.parse(payloadEl.textContent || '{}');
            const categories = Array.isArray(state.categories) ? state.categories : [];
            const categoryById = new Map(categories.map((category) => [String(category.id), category]));
            let selectedCategoryId = '';
            let selectedTab = 'components';
            let selectedComponentId = '';

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[char]));
            const formatDate = (value) => {
                if (!value) return '-';
                const parts = String(value).slice(0, 10).split('-');
                return parts.length === 3 ? `${parts[2]}.${parts[1]}.${parts[0]}` : String(value);
            };
            const money = (value) => new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0)) + ' RON';
            const percent = (value) => value === null || value === undefined ? 'N/A' : `${value}%`;
            const tone = (value) => ['green', 'yellow', 'orange', 'red', 'neutral'].includes(value) ? value : 'neutral';
            const setPanelOpen = (isOpen) => {
                if (!layout) {
                    return;
                }
                layout.classList.toggle('is-detail-collapsed', !isOpen);
                if (panelToggle) {
                    panelToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    panelToggle.setAttribute('aria-label', isOpen ? 'Ascunde panoul de detalii' : 'Arata panoul de detalii');
                    panelToggle.setAttribute('title', isOpen ? 'Ascunde detalii' : 'Arata detalii');
                    const icon = panelToggle.querySelector('i');
                    if (icon) {
                        icon.className = isOpen ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
                    }
                }
            };

            const renderContextCard = (category) => {
                if (!contextCard || !category) {
                    return;
                }
                const health = category.health_percent ?? null;
                contextCard.className = `technical-context-card technical-tone-${tone(category.status_tone)}`;
                contextCard.innerHTML = `
                    <div class="technical-context-card-head">
                        <span class="technical-card-icon"><i class="bi ${escapeHtml(category.icon || 'bi-gear')}"></i></span>
                        <div>
                            <strong>${escapeHtml(category.sort_order)}. ${escapeHtml(category.name)}</strong>
                            <small>${percent(health)} · ${escapeHtml(category.status_label || 'N/A')}</small>
                        </div>
                        <div class="technical-mini-ring" style="--health:${health ?? 0}">${percent(health)}</div>
                    </div>
                    <div class="technical-context-card-meta">
                        <span>Urmatoarea verificare</span>
                        <strong>${formatDate(category.next_verification_date)}</strong>
                    </div>
                    <button class="btn btn-primary btn-sm" type="button" data-tech-panel-open>
                        <i class="bi bi-layout-sidebar-inset-reverse"></i> Detalii
                    </button>
                `;
                if (modelHint) {
                    modelHint.classList.add('d-none');
                }
            };

            const renderComponentHighlight = (component) => {
                if (!component) {
                    return '<div class="technical-selected-component empty">Selecteaza o componenta din tabel pentru detalii rapide.</div>';
                }

                return `
                    <div class="technical-selected-component technical-tone-${tone(component.status_tone)}">
                        <div>
                            <span>Componenta selectata</span>
                            <strong>${escapeHtml(component.name)}</strong>
                        </div>
                        <div class="technical-mini-ring" style="--health:${component.health_percent ?? 0}">${percent(component.health_percent)}</div>
                        <small>Ultima interventie: ${formatDate(component.last_intervention_date)} · Urmatoarea: ${formatDate(component.next_verification_date)}</small>
                    </div>
                `;
            };

            const renderComponents = (category) => {
                const components = Array.isArray(category.components) ? category.components : [];
                if (components.length === 0) {
                    return '<div class="technical-empty-state compact"><i class="bi bi-inbox"></i><p>Nu exista componente active pentru aceasta categorie.</p></div>';
                }
                return `
                    <div class="technical-component-table">
                        <table>
                            <thead><tr><th>Componenta</th><th>Stare</th><th>Ultima interventie</th><th>Urmatoarea</th><th></th></tr></thead>
                            <tbody>
                                ${components.map((component) => `
                                    <tr class="${String(component.id) === selectedComponentId ? 'is-selected' : ''}">
                                        <td>
                                            <strong>${escapeHtml(component.name)}</strong>
                                            ${component.is_critical ? '<small>Critica</small>' : ''}
                                        </td>
                                        <td><span class="technical-status-pill technical-tone-${tone(component.status_tone)}">${percent(component.health_percent)}</span></td>
                                        <td>${formatDate(component.last_intervention_date)}</td>
                                        <td>${formatDate(component.next_verification_date)}</td>
                                        <td>
                                            <button class="technical-row-action" type="button" data-tech-component="${component.id}" title="Vezi detalii componenta">
                                                <i class="bi bi-eye"></i><i class="bi bi-chevron-right"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            };

            const renderHistory = (category) => {
                const history = Array.isArray(category.history) ? category.history : [];
                if (history.length === 0) {
                    return '<div class="technical-empty-state compact"><i class="bi bi-clock-history"></i><p>Nu exista interventii inregistrate pentru aceasta categorie.</p></div>';
                }
                return `
                    <div class="technical-history-list">
                        ${history.map((item) => `
                            <div class="technical-history-item">
                                <span>${formatDate(item.date)}</span>
                                <strong>${escapeHtml(item.description || '-')}</strong>
                                <small>${escapeHtml(item.supplier || '-')} · ${item.km ? `${new Intl.NumberFormat('ro-RO').format(item.km)} km` : 'km -'}</small>
                                <em>${money(item.cost)}</em>
                                ${item.invoice_file ? `<a href="${escapeHtml('uploads/mentenanta_piese/' + item.invoice_file)}" target="_blank" rel="noopener">Factura / PDF</a>` : ''}
                            </div>
                        `).join('')}
                    </div>
                `;
            };

            const renderDetails = (category) => {
                const details = category.details || {};
                const critical = Array.isArray(details.critical_components) ? details.critical_components : [];
                return `
                    <div class="technical-notes-grid">
                        <div><span>Observatii</span><p>${escapeHtml(details.observations || '-')}</p></div>
                        <div><span>Recomandari</span><p>${escapeHtml(details.recommendations || '-')}</p></div>
                        <div><span>Explicatie risc</span><p>${escapeHtml(details.risk_explanation || '-')}</p></div>
                        <div><span>Interventii asociate</span><p>${Number(details.related_records || 0)}</p></div>
                        <div class="wide"><span>Componente critice</span><p>${critical.length ? critical.map(escapeHtml).join(', ') : '-'}</p></div>
                    </div>
                `;
            };

            const renderPanel = () => {
                const category = categoryById.get(selectedCategoryId) || categories[0];
                if (!category) {
                    panel.innerHTML = '<div class="technical-empty-state compact"><i class="bi bi-exclamation-triangle"></i><p>Nu exista date pentru panoul tehnic.</p></div>';
                    return;
                }

                const components = Array.isArray(category.components) ? category.components : [];
                if (!selectedComponentId || !components.some((component) => String(component.id) === selectedComponentId)) {
                    const weakest = components.filter((component) => component.health_percent !== null)
                        .sort((a, b) => Number(a.health_percent) - Number(b.health_percent))[0] || components[0] || null;
                    selectedComponentId = weakest ? String(weakest.id) : '';
                }
                const selectedComponent = components.find((component) => String(component.id) === selectedComponentId) || null;
                const activeContent = selectedTab === 'history'
                    ? renderHistory(category)
                    : (selectedTab === 'details' ? renderDetails(category) : renderComponents(category));

                panel.innerHTML = `
                    <div class="technical-panel-header">
                        <div>
                            <h2>${escapeHtml(category.sort_order)}. ${escapeHtml(category.name)}</h2>
                            <span class="technical-status-pill technical-tone-${tone(category.status_tone)}">${escapeHtml(category.status_label)}</span>
                        </div>
                        <div class="technical-panel-header-actions">
                            <div class="technical-health-ring technical-tone-${tone(category.status_tone)}" style="--health:${category.health_percent ?? 0}">
                                <strong>${percent(category.health_percent)}</strong>
                            </div>
                            <button class="technical-panel-close" type="button" data-tech-panel-close aria-label="Ascunde panoul de detalii" title="Ascunde detalii">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="technical-panel-metrics">
                        <div><span>Ultima interventie</span><strong>${formatDate(category.last_intervention_date)}</strong></div>
                        <div><span>Urmatoarea verificare</span><strong>${formatDate(category.next_verification_date)}</strong></div>
                        <div><span>Cost total interventii</span><strong>${money(category.total_intervention_cost)}</strong></div>
                    </div>
                    ${renderComponentHighlight(selectedComponent)}
                    <div class="technical-tabs" role="tablist">
                        <button class="${selectedTab === 'components' ? 'active' : ''}" type="button" data-tech-tab="components">Componente</button>
                        <button class="${selectedTab === 'history' ? 'active' : ''}" type="button" data-tech-tab="history">Istoric</button>
                        <button class="${selectedTab === 'details' ? 'active' : ''}" type="button" data-tech-tab="details">Detalii</button>
                    </div>
                    <div class="technical-tab-body">${activeContent}</div>
                    <a class="btn btn-primary technical-all-repairs" href="${escapeHtml(category.repairs_url || '#')}">
                        <i class="bi bi-list-check"></i> Vezi toate interventiile
                    </a>
                `;
            };

            const selectCategory = (categoryId, openPanel = true) => {
                selectedCategoryId = String(categoryId || '');
                const category = categoryById.get(selectedCategoryId);
                if (!category) {
                    return;
                }
                selectedTab = 'components';
                selectedComponentId = '';
                renderContextCard(category);
                renderPanel();
                setPanelOpen(openPanel);
                window.dispatchEvent(new CustomEvent('technical-health-category-selected', {
                    detail: { categoryId: selectedCategoryId },
                }));
            };

            window.addEventListener('technical-health-model-category-selected', (event) => {
                selectCategory(String(event.detail && event.detail.categoryId ? event.detail.categoryId : ''), true);
            });

            if (panelToggle) {
                panelToggle.addEventListener('click', () => {
                    const isOpen = layout && !layout.classList.contains('is-detail-collapsed');
                    if (isOpen) {
                        setPanelOpen(false);
                        return;
                    }
                    if (!selectedCategoryId) {
                        selectedCategoryId = String(state.selectedCategoryId || (categories[0] ? categories[0].id : ''));
                    }
                    const category = categoryById.get(selectedCategoryId);
                    if (category) {
                        renderContextCard(category);
                        renderPanel();
                        window.dispatchEvent(new CustomEvent('technical-health-category-selected', {
                            detail: { categoryId: selectedCategoryId },
                        }));
                    }
                    setPanelOpen(true);
                });
            }

            panel.addEventListener('click', (event) => {
                if (event.target.closest('[data-tech-panel-close]')) {
                    setPanelOpen(false);
                    return;
                }

                const tab = event.target.closest('[data-tech-tab]');
                if (tab) {
                    selectedTab = tab.dataset.techTab || 'components';
                    renderPanel();
                    return;
                }

                const componentButton = event.target.closest('[data-tech-component]');
                if (componentButton) {
                    selectedComponentId = String(componentButton.dataset.techComponent || '');
                    renderPanel();
                }
            });

            if (contextCard) {
                contextCard.addEventListener('click', (event) => {
                    if (event.target.closest('[data-tech-panel-open]')) {
                        if (!selectedCategoryId) {
                            return;
                        }
                        renderPanel();
                        setPanelOpen(true);
                    }
                });
            }

            setPanelOpen(false);
        })();
        </script>
        <script type="importmap">
        {
            "imports": {
                "three": "<?= e(absolute_url('assets/vendor/three/three.module.js')) ?>"
            }
        }
        </script>
        <script type="module">
        (() => {
            const payloadEl = document.getElementById('technicalHealthPayload');
            const viewer = document.querySelector('[data-technical-model-viewer]');
            if (!payloadEl || !viewer) {
                return;
            }

            const canvas = viewer.querySelector('[data-technical-model-canvas]');
            const loaderEl = viewer.querySelector('[data-technical-model-loader]');
            const progressEl = viewer.querySelector('[data-technical-model-progress]');
            const errorEl = viewer.querySelector('[data-technical-model-error]');
            const fallbackEl = viewer.querySelector('[data-technical-model-fallback]');
            const actionsEl = viewer.querySelector('[data-technical-model-actions]');
            const state = JSON.parse(payloadEl.textContent || '{}');
            const vehicleType = state.vehicleType || viewer.dataset.vehicleType || 'camion';
            const modelUrls = state.modelUrls || {};
            const modelUrl = state.activeModelUrl || viewer.dataset.modelUrl || modelUrls[vehicleType] || modelUrls.camion;
            const modelLabel = state.activeModelLabel || vehicleType;
            const categories = Array.isArray(state.categories) ? state.categories : [];
            let selectedCategoryId = String(state.selectedCategoryId || '');

            const showFallback = (message = 'Modelul 3D nu a putut fi incarcat.') => {
                viewer.dataset.modelLoaded = '0';
                if (loaderEl) {
                    loaderEl.classList.add('d-none');
                }
                if (canvas) {
                    canvas.classList.add('d-none');
                }
                if (actionsEl) {
                    actionsEl.classList.add('d-none');
                }
                if (fallbackEl) {
                    fallbackEl.classList.add('is-visible');
                }
                if (errorEl) {
                    errorEl.classList.remove('d-none');
                    const errorText = errorEl.querySelector('span');
                    if (errorText) {
                        errorText.textContent = message;
                    }
                }
            };

            if (!canvas || !modelUrl) {
                showFallback('Lipseste modelul 3D pentru tipul de vehicul selectat.');
                return;
            }

            const threeUrl = 'three';
            const loaderUrl = <?= json_encode(absolute_url('assets/vendor/three/GLTFLoader.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const controlsUrl = <?= json_encode(absolute_url('assets/vendor/three/OrbitControls.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            Promise.all([import(threeUrl), import(loaderUrl), import(controlsUrl)])
                .then(([THREE, loaderModule, controlsModule]) => {
                    THREE.Cache.enabled = true;

                    const scene = new THREE.Scene();
                    const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 1000);
                    const renderer = new THREE.WebGLRenderer({
                        canvas,
                        antialias: true,
                        alpha: true,
                        preserveDrawingBuffer: true,
                        powerPreference: 'high-performance',
                    });
                    renderer.outputColorSpace = THREE.SRGBColorSpace;
                    renderer.toneMapping = THREE.ACESFilmicToneMapping;
                    renderer.toneMappingExposure = 0.82;
                    renderer.setClearColor(0x000000, 0);
                    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

                    const root = new THREE.Group();
                    const statusOverlayGroup = new THREE.Group();
                    statusOverlayGroup.name = 'technical-status-overlays';
                    scene.add(root);
                    scene.add(new THREE.HemisphereLight(0xe2e8f0, 0x1e293b, 1.15));
                    scene.add(new THREE.AmbientLight(0xffffff, 0.18));

                    const keyLight = new THREE.DirectionalLight(0xf8fafc, 1.65);
                    keyLight.position.set(4.5, 7, 6);
                    scene.add(keyLight);

                    const fillLight = new THREE.DirectionalLight(0x93c5fd, 0.55);
                    fillLight.position.set(-4, 2.5, -5);
                    scene.add(fillLight);

                    const rimLight = new THREE.DirectionalLight(0x1d4ed8, 1.15);
                    rimLight.position.set(-6, 4, 5);
                    scene.add(rimLight);

                    const loader = new loaderModule.GLTFLoader();
                    const controls = new controlsModule.OrbitControls(camera, canvas);
                    controls.enableDamping = true;
                    controls.dampingFactor = 0.08;
                    controls.enablePan = true;
                    controls.enableZoom = true;
                    controls.autoRotate = true;
                    controls.autoRotateSpeed = 0.45;
                    controls.screenSpacePanning = true;

                    let modelRoot = null;
                    let animationFrame = 0;
                    let baseRotation = 0;
                    let modelOriented = false;
                    let defaultCameraPosition = new THREE.Vector3(0, 1.5, 8);
                    let defaultTarget = new THREE.Vector3(0, 0, 0);
                    let defaultDistance = 8;
                    const toneColors = {
                        green: 0x16a34a,
                        yellow: 0xeab308,
                        orange: 0xf97316,
                        red: 0xef4444,
                        neutral: 0x64748b,
                    };
                    const overlayMaterialCache = new Map();
                    const overlayMeshes = new Map();

                    const categoryByOrder = new Map(categories.map((category) => [Number(category.sort_order || 0), category]));
                    const categoryById = new Map(categories.map((category) => [String(category.id || ''), category]));
                    const colorableMeshes = [];
                    const raycaster = new THREE.Raycaster();
                    const pointer = new THREE.Vector2();
                    const vertexWorldPosition = new THREE.Vector3();
                    const modelBox = new THREE.Box3();
                    const modelSize = new THREE.Vector3();
                    const normalizedPoint = new THREE.Vector3();
                    const getTone = (category) => {
                        const tone = String(category && category.status_tone ? category.status_tone : 'neutral');
                        return Object.prototype.hasOwnProperty.call(toneColors, tone) ? tone : 'neutral';
                    };

                    const getCategoryByOrder = (...orders) => {
                        for (const order of orders) {
                            if (categoryByOrder.has(order)) {
                                return categoryByOrder.get(order);
                            }
                        }
                        return categories[0] || null;
                    };

                    const normalizeModelPoint = (worldPoint) => {
                        modelBox.getSize(modelSize);
                        normalizedPoint.set(
                            modelSize.x > 0 ? ((worldPoint.x - modelBox.min.x) / modelSize.x) - 0.5 : 0,
                            modelSize.y > 0 ? ((worldPoint.y - modelBox.min.y) / modelSize.y) : 0,
                            modelSize.z > 0 ? ((worldPoint.z - modelBox.min.z) / modelSize.z) - 0.5 : 0
                        );
                        return normalizedPoint;
                    };

                    const categoryForNormalizedPoint = (point) => {
                        const absZ = Math.abs(point.z);
                        if (point.y < 0.26 && absZ > 0.22) {
                            return point.x < 0.02 ? getCategoryByOrder(3, 2, 1) : getCategoryByOrder(2, 3, 1);
                        }
                        if (point.y < 0.3 && absZ <= 0.22) {
                            return getCategoryByOrder(18, 10, 1);
                        }
                        if (categoryByOrder.has(17) && point.y > 0.44 && point.x < 0.2 && absZ < 0.34) {
                            return getCategoryByOrder(17);
                        }
                        if (categoryByOrder.has(11) && point.y > 0.34 && absZ >= 0.28) {
                            return getCategoryByOrder(11);
                        }
                        if (point.x > 0.24 && point.y > 0.55) {
                            return getCategoryByOrder(7, 12, 5);
                        }
                        if (point.x > 0.24 && point.y <= 0.55) {
                            return point.z > 0 ? getCategoryByOrder(9, 6, 4) : getCategoryByOrder(6, 4, 9);
                        }
                        if (point.x < -0.24 && point.y > 0.48) {
                            return getCategoryByOrder(8, 15, 17);
                        }
                        if (point.z > 0.28) {
                            if (point.x > 0.18) return getCategoryByOrder(12, 11, 10);
                            if (point.x > 0.04) return getCategoryByOrder(13, 14, 11);
                            if (point.x > -0.12) return getCategoryByOrder(14, 11, 10);
                            if (point.x > -0.24) return getCategoryByOrder(15, 16, 11);
                            return getCategoryByOrder(16, 15, 11);
                        }
                        if (point.y > 0.58) {
                            return getCategoryByOrder(5, 12, 7);
                        }
                        if (point.y > 0.42) {
                            return getCategoryByOrder(10, 11, 17);
                        }
                        return getCategoryByOrder(1, 10, 18);
                    };

                    const categoryForWorldPoint = (worldPoint) => categoryForNormalizedPoint(normalizeModelPoint(worldPoint));

                    const colorForCategory = (category, target) => {
                        const color = toneColors[getTone(category)] || toneColors.neutral;
                        target.setHex(color);
                        if (selectedCategoryId) {
                            if (category && String(category.id || '') === selectedCategoryId) {
                                target.lerp(new THREE.Color(0xffffff), 0.16);
                            } else {
                                target.lerp(new THREE.Color(0x94a3b8), 0.34);
                            }
                        }
                        return target;
                    };

                    const applyModelZoneColors = () => {
                        if (!modelRoot || colorableMeshes.length === 0) {
                            return;
                        }
                        modelRoot.updateMatrixWorld(true);
                        modelBox.setFromObject(modelRoot);

                        colorableMeshes.forEach((mesh) => {
                            const position = mesh.geometry && mesh.geometry.attributes ? mesh.geometry.attributes.position : null;
                            if (!position) {
                                return;
                            }
                            let colorAttribute = mesh.geometry.attributes.color;
                            if (!colorAttribute || colorAttribute.count !== position.count) {
                                colorAttribute = new THREE.BufferAttribute(new Float32Array(position.count * 3), 3);
                                mesh.geometry.setAttribute('color', colorAttribute);
                            }
                            const color = new THREE.Color();
                            for (let index = 0; index < position.count; index += 1) {
                                vertexWorldPosition.fromBufferAttribute(position, index).applyMatrix4(mesh.matrixWorld);
                                const category = categoryForWorldPoint(vertexWorldPosition);
                                colorForCategory(category, color);
                                colorAttribute.setXYZ(index, color.r, color.g, color.b);
                            }
                            colorAttribute.needsUpdate = true;
                        });
                    };

                    const getOverlayMaterial = (tone, selected = false) => {
                        const key = `${tone}:${selected ? 'selected' : 'normal'}`;
                        if (overlayMaterialCache.has(key)) {
                            return overlayMaterialCache.get(key);
                        }
                        const color = toneColors[tone] || toneColors.neutral;
                        const material = new THREE.MeshStandardMaterial({
                            color,
                            emissive: color,
                            emissiveIntensity: selected ? 0.5 : 0.26,
                            transparent: true,
                            opacity: selected ? 0.54 : 0.32,
                            roughness: 0.72,
                            metalness: 0.04,
                            depthTest: false,
                            depthWrite: false,
                        });
                        overlayMaterialCache.set(key, material);
                        return material;
                    };

                    const addOverlayMesh = (category, geometry, position, scale = [1, 1, 1], rotation = [0, 0, 0]) => {
                        if (!category) {
                            return null;
                        }
                        const tone = getTone(category);
                        const isSelected = String(category.id || '') === selectedCategoryId;
                        const mesh = new THREE.Mesh(geometry, getOverlayMaterial(tone, isSelected));
                        mesh.position.set(position[0], position[1], position[2]);
                        mesh.scale.set(scale[0], scale[1], scale[2]);
                        mesh.rotation.set(rotation[0], rotation[1], rotation[2]);
                        mesh.renderOrder = isSelected ? 22 : 20;
                        mesh.userData.categoryId = String(category.id || '');
                        mesh.userData.statusTone = tone;
                        mesh.userData.baseScale = [scale[0], scale[1], scale[2]];
                        statusOverlayGroup.add(mesh);
                        const list = overlayMeshes.get(mesh.userData.categoryId) || [];
                        list.push(mesh);
                        overlayMeshes.set(mesh.userData.categoryId, list);
                        return mesh;
                    };

                    const updateOverlaySelection = () => {
                        overlayMeshes.forEach((meshes, categoryId) => {
                            const category = categories.find((item) => String(item.id || '') === categoryId);
                            const selected = categoryId === selectedCategoryId;
                            const tone = getTone(category);
                            meshes.forEach((mesh) => {
                                const baseScale = Array.isArray(mesh.userData.baseScale) ? mesh.userData.baseScale : [1, 1, 1];
                                const scaleMultiplier = selected ? 1.08 : 1;
                                mesh.material = getOverlayMaterial(tone, selected);
                                mesh.renderOrder = selected ? 22 : 20;
                                mesh.scale.set(
                                    baseScale[0] * scaleMultiplier,
                                    baseScale[1] * scaleMultiplier,
                                    baseScale[2] * scaleMultiplier
                                );
                            });
                        });
                    };

                    const clearStatusOverlays = () => {
                        statusOverlayGroup.children.forEach((child) => {
                            if (child.geometry) {
                                child.geometry.dispose();
                            }
                        });
                        statusOverlayGroup.clear();
                        overlayMeshes.clear();
                    };

                    const buildStatusOverlays = () => {
                        if (!modelRoot) {
                            return;
                        }
                        applyModelZoneColors();
                        return;
                        clearStatusOverlays();

                        const box = new THREE.Box3().setFromObject(modelRoot);
                        const size = box.getSize(new THREE.Vector3());
                        const center = box.getCenter(new THREE.Vector3());
                        const length = Math.max(size.x, 0.1);
                        const height = Math.max(size.y, 0.1);
                        const depth = Math.max(size.z, 0.1);
                        const pos = (x = 0, y = 0.5, z = 0) => [
                            center.x + x * length,
                            box.min.y + y * height,
                            center.z + z * depth,
                        ];
                        const boxGeometry = (sx, sy, sz) => new THREE.BoxGeometry(length * sx, height * sy, depth * sz);
                        const sphereGeometry = (radius) => new THREE.SphereGeometry(Math.max(length, depth) * radius, 24, 14);
                        const wheelGeometry = new THREE.TorusGeometry(Math.max(length, depth) * 0.052, Math.max(length, depth) * 0.012, 10, 32);

                        const wheelPositions = [
                            pos(-0.34, 0.2, -0.36),
                            pos(-0.34, 0.2, 0.36),
                            pos(0.3, 0.2, -0.36),
                            pos(0.3, 0.2, 0.36),
                        ];
                        const addWheelSet = (category, selectedTorus = false) => {
                            wheelPositions.forEach((position) => {
                                addOverlayMesh(category, selectedTorus ? wheelGeometry.clone() : sphereGeometry(0.045), position, [1, 1, 1], [Math.PI / 2, 0, 0]);
                            });
                        };

                        addOverlayMesh(categoryByOrder.get(1), boxGeometry(0.72, 0.08, 0.78), pos(-0.03, 0.25, 0));
                        addWheelSet(categoryByOrder.get(2), false);
                        addWheelSet(categoryByOrder.get(3), true);
                        addOverlayMesh(categoryByOrder.get(4), boxGeometry(0.16, 0.2, 0.45), pos(0.43, 0.48, 0));
                        addOverlayMesh(categoryByOrder.get(5), boxGeometry(0.42, 0.08, 0.08), pos(0.08, 0.68, -0.43));
                        addOverlayMesh(categoryByOrder.get(6), boxGeometry(0.22, 0.26, 0.42), pos(0.36, 0.4, 0));
                        addOverlayMesh(categoryByOrder.get(7), boxGeometry(0.18, 0.28, 0.48), pos(0.47, 0.68, 0));
                        addOverlayMesh(categoryByOrder.get(8), boxGeometry(0.38, 0.08, 0.08), pos(0.05, 0.55, -0.54));
                        addOverlayMesh(categoryByOrder.get(9), sphereGeometry(0.06), pos(0.42, 0.28, 0.28));
                        addOverlayMesh(categoryByOrder.get(10), boxGeometry(0.3, 0.12, 0.48), pos(-0.14, 0.34, 0));
                        addOverlayMesh(categoryByOrder.get(11), boxGeometry(0.38, 0.12, 0.18), pos(-0.04, 0.52, 0.44));
                        addOverlayMesh(categoryByOrder.get(12), boxGeometry(0.08, 0.18, 0.12), pos(0.22, 0.58, 0.48));
                        addOverlayMesh(categoryByOrder.get(13), boxGeometry(0.08, 0.16, 0.1), pos(0.31, 0.56, 0.48));
                        addOverlayMesh(categoryByOrder.get(14), sphereGeometry(0.052), pos(0.02, 0.46, 0.48));
                        addOverlayMesh(categoryByOrder.get(15), sphereGeometry(0.048), pos(-0.1, 0.5, 0.48));
                        addOverlayMesh(categoryByOrder.get(16), sphereGeometry(0.045), pos(-0.22, 0.44, 0.48));
                        addOverlayMesh(categoryByOrder.get(17), boxGeometry(0.56, 0.26, 0.58), pos(-0.1, 0.58, 0));
                        addOverlayMesh(categoryByOrder.get(18), boxGeometry(0.38, 0.09, 0.14), pos(0.04, 0.25, 0));
                        wheelGeometry.dispose();

                        if (!root.children.includes(statusOverlayGroup)) {
                            root.add(statusOverlayGroup);
                        }
                        updateOverlaySelection();
                    };

                    controls.addEventListener('start', () => {
                        controls.autoRotate = false;
                        viewer.dataset.modelControlled = '1';
                    });

                    const resize = () => {
                        const bounds = viewer.getBoundingClientRect();
                        const width = Math.max(1, Math.floor(bounds.width));
                        const height = Math.max(1, Math.floor(bounds.height));
                        renderer.setSize(width, height, false);
                        camera.aspect = width / height;
                        camera.updateProjectionMatrix();
                        if (modelRoot) {
                            frameModel(false);
                        }
                    };

                    const frameModel = (resetView = true) => {
                        if (!modelRoot) {
                            return;
                        }

                        if (!modelOriented) {
                            const rawBox = new THREE.Box3().setFromObject(modelRoot);
                            const rawSize = rawBox.getSize(new THREE.Vector3());
                            if (rawSize.z > rawSize.x) {
                                modelRoot.rotation.y += Math.PI / 2;
                            }
                            modelOriented = true;
                        }

                        modelRoot.scale.setScalar(1);
                        modelRoot.position.set(0, 0, 0);

                        const orientedBox = new THREE.Box3().setFromObject(modelRoot);
                        const orientedSize = orientedBox.getSize(new THREE.Vector3());
                        const orientedCenter = orientedBox.getCenter(new THREE.Vector3());
                        const targetWidth = vehicleType === 'cap_tractor' ? 3.9 : (vehicleType === 'semiremorca' ? 5.4 : 4.9);
                        const modelWidth = Math.max(orientedSize.x, orientedSize.z, 0.001);
                        const scale = targetWidth / modelWidth;

                        modelRoot.scale.setScalar(scale);
                        modelRoot.position.set(
                            -orientedCenter.x * scale,
                            -orientedCenter.y * scale - orientedSize.y * scale * 0.16,
                            -orientedCenter.z * scale
                        );

                        const box = new THREE.Box3().setFromObject(modelRoot);
                        const size = box.getSize(new THREE.Vector3());
                        const center = box.getCenter(new THREE.Vector3());
                        const halfFov = THREE.MathUtils.degToRad(camera.fov * 0.5);
                        const fitHeightDistance = size.y / (2 * Math.tan(halfFov));
                        const fitWidthDistance = size.x / (2 * Math.tan(halfFov) * Math.max(camera.aspect, 0.1));
                        const distance = Math.max(fitHeightDistance, fitWidthDistance, size.z * 1.15, 2.4) * 1.12;
                        defaultDistance = distance;
                        defaultTarget.set(center.x, center.y + size.y * 0.08, center.z);
                        defaultCameraPosition.set(
                            center.x + distance * 0.08,
                            center.y + Math.max(0.8, size.y * 0.72),
                            center.z + distance
                        );
                        controls.minDistance = Math.max(1.2, distance * 0.42);
                        controls.maxDistance = Math.max(12, distance * 2.4);
                        controls.target.copy(defaultTarget);
                        if (resetView) {
                            camera.position.copy(defaultCameraPosition);
                            controls.update();
                        }
                        buildStatusOverlays();
                    };

                    const zoomCamera = (factor) => {
                        controls.autoRotate = false;
                        viewer.dataset.modelControlled = '1';
                        const offset = camera.position.clone().sub(controls.target);
                        const nextDistance = THREE.MathUtils.clamp(offset.length() * factor, controls.minDistance, controls.maxDistance);
                        offset.setLength(nextDistance);
                        camera.position.copy(controls.target).add(offset);
                        controls.update();
                    };

                    const resetCamera = () => {
                        camera.position.copy(defaultCameraPosition);
                        controls.target.copy(defaultTarget);
                        controls.autoRotate = true;
                        controls.update();
                    };

                    const resizeSoon = () => {
                        window.setTimeout(() => {
                            resize();
                            renderer.render(scene, camera);
                        }, 80);
                    };

                    const animate = (time) => {
                        if (modelRoot) {
                            root.rotation.y = baseRotation + Math.sin(time * 0.00055) * 0.035;
                        }
                        controls.update();
                        renderer.render(scene, camera);
                        animationFrame = window.requestAnimationFrame(animate);
                    };

                    const resizeObserver = new ResizeObserver(() => {
                        resize();
                        renderer.render(scene, camera);
                    });
                    resizeObserver.observe(viewer);
                    resize();

                    let pointerDown = null;
                    const updatePointer = (event) => {
                        const bounds = canvas.getBoundingClientRect();
                        pointer.x = ((event.clientX - bounds.left) / Math.max(bounds.width, 1)) * 2 - 1;
                        pointer.y = -(((event.clientY - bounds.top) / Math.max(bounds.height, 1)) * 2 - 1);
                    };
                    const selectCategoryFromPointer = (event) => {
                        if (!modelRoot || colorableMeshes.length === 0) {
                            return;
                        }
                        updatePointer(event);
                        raycaster.setFromCamera(pointer, camera);
                        const hits = raycaster.intersectObjects(colorableMeshes, true);
                        if (hits.length === 0) {
                            return;
                        }
                        modelBox.setFromObject(modelRoot);
                        const category = categoryForWorldPoint(hits[0].point);
                        if (!category) {
                            return;
                        }
                        selectedCategoryId = String(category.id || '');
                        applyModelZoneColors();
                        window.dispatchEvent(new CustomEvent('technical-health-model-category-selected', {
                            detail: { categoryId: selectedCategoryId },
                        }));
                    };
                    canvas.addEventListener('pointerdown', (event) => {
                        pointerDown = { x: event.clientX, y: event.clientY, time: Date.now() };
                    });
                    canvas.addEventListener('pointerup', (event) => {
                        if (!pointerDown) {
                            return;
                        }
                        const moved = Math.hypot(event.clientX - pointerDown.x, event.clientY - pointerDown.y);
                        const elapsed = Date.now() - pointerDown.time;
                        pointerDown = null;
                        if (moved <= 7 && elapsed < 650) {
                            selectCategoryFromPointer(event);
                        }
                    });

                    loader.load(
                        modelUrl,
                        (gltf) => {
                            modelRoot = gltf.scene;
                            modelRoot.traverse((child) => {
                                if (child.isMesh) {
                                    child.frustumCulled = false;
                                    if (child.geometry) {
                                        child.geometry = child.geometry.clone();
                                    }
                                    if (child.material) {
                                        const tintMaterial = (material) => {
                                            const cloned = material && material.clone ? material.clone() : new THREE.MeshStandardMaterial();
                                            cloned.vertexColors = true;
                                            if (cloned.color) {
                                                cloned.color.set(0xffffff);
                                            }
                                            if (cloned.emissive) {
                                                cloned.emissive.set(0x020617);
                                                cloned.emissiveIntensity = 0.08;
                                            }
                                            if ('roughness' in cloned) {
                                                cloned.roughness = Math.max(0.58, Number(cloned.roughness || 0.58));
                                            }
                                            if ('metalness' in cloned) {
                                                cloned.metalness = Math.min(0.22, Number(cloned.metalness || 0.08));
                                            }
                                            cloned.transparent = true;
                                            cloned.opacity = Math.max(0.68, Math.min(0.92, Number(cloned.opacity || 0.82)));
                                            cloned.needsUpdate = true;
                                            return cloned;
                                        };
                                        child.material = Array.isArray(child.material)
                                            ? child.material.map((material) => tintMaterial(material))
                                            : tintMaterial(child.material);
                                    }
                                    colorableMeshes.push(child);
                                }
                            });
                            root.add(modelRoot);
                            baseRotation = vehicleType === 'semiremorca' ? -0.03 : 0.02;
                            frameModel();

                            viewer.dataset.modelLoaded = '1';
                            viewer.dataset.loadedVehicleType = vehicleType;
                            if (loaderEl) {
                                loaderEl.classList.add('d-none');
                            }
                            if (fallbackEl) {
                                fallbackEl.classList.remove('is-visible');
                            }
                            canvas.classList.add('is-loaded');
                            canvas.classList.remove('d-none');
                            if (actionsEl) {
                                actionsEl.classList.remove('d-none');
                            }
                            window.dispatchEvent(new CustomEvent('technical-health-model-loaded', {
                                detail: { vehicleType, modelUrl },
                            }));
                            animate(0);
                        },
                        (event) => {
                            if (!progressEl) {
                                return;
                            }
                            if (event.lengthComputable && event.total > 0) {
                                const percentLoaded = Math.min(100, Math.round((event.loaded / event.total) * 100));
                                progressEl.textContent = `${modelLabel} - ${percentLoaded}%`;
                            } else {
                                progressEl.textContent = modelLabel;
                            }
                        },
                        (error) => {
                            console.error('[technical-health-3d] model load failed', error);
                            showFallback('Modelul 3D nu a putut fi incarcat din fisierele aplicatiei.');
                        }
                    );

                    if (actionsEl) {
                        actionsEl.addEventListener('click', (event) => {
                            const button = event.target.closest('[data-technical-model-action]');
                            if (!button) {
                                return;
                            }
                            event.preventDefault();
                            const action = button.dataset.technicalModelAction;
                            if (action === 'zoom-in') {
                                zoomCamera(0.78);
                            } else if (action === 'zoom-out') {
                                zoomCamera(1.22);
                            } else if (action === 'reset') {
                                resetCamera();
                            } else if (action === 'fullscreen') {
                                if (document.fullscreenElement === viewer) {
                                    document.exitFullscreen();
                                } else if (viewer.requestFullscreen) {
                                    viewer.requestFullscreen();
                                }
                            }
                        });
                    }

                    document.addEventListener('fullscreenchange', resizeSoon);

                    window.addEventListener('beforeunload', () => {
                        window.cancelAnimationFrame(animationFrame);
                        resizeObserver.disconnect();
                        document.removeEventListener('fullscreenchange', resizeSoon);
                        controls.dispose();
                        clearStatusOverlays();
                        overlayMaterialCache.forEach((material) => material.dispose());
                        renderer.dispose();
                    });

                    window.addEventListener('technical-health-category-selected', (event) => {
                        selectedCategoryId = String(event.detail && event.detail.categoryId ? event.detail.categoryId : '');
                        applyModelZoneColors();
                    });
                })
                .catch((error) => {
                    console.error('[technical-health-3d] library load failed', error);
                    showFallback('Biblioteca 3D nu a putut fi incarcata: ' + (error && error.message ? error.message : 'eroare necunoscuta'));
                });
        })();
        </script>
    <?php endif; ?>
</div>
