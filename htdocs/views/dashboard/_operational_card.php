<?php
/*
 * Cardul "Cost total operațional" din Dashboard. Fețele se deschid pe loc
 * (assets/js/dashboard-live.js):
 *   sumar --(click pe total)--> "costs": fixe vs variabile
 *         --(click pe fixe / variabile)--> "cost_fix" / "cost_variabil": pe categorii de
 *           vehicul, fiecare desfăcută în elementele de cost (ca în Cost operațional / km)
 *   sumar --(Carburant / Mentenanță)--> "fuel" / "maintenance"
 *   sumar --(Cheltuieli)--> "expenses": administrative vs operaționale
 *         --> "exp_administrativa" / "exp_operationala": pe subcategorii (logica paginii Cheltuieli)
 *   sumar --(Documente mașini)--> "doc_vehicles": tip de vehicul (după capacitate) -> tip de document
 *   sumar --(Documente șoferi)--> "doc_drivers": pe tip de document + pe fiecare șofer
 *   sumar --(Dotări)--> "equipment": dotările montate pe categorie din catalog -> produs
 *
 * Cu $operationalCosts (DashboardOperationalCostService) toate valorile sunt din modelul
 * Cost / km, fără TVA. Fără el (drept lipsă / motor indisponibil) cardul rămâne pe
 * carburant + mentenanță, ca înainte.
 *
 * Așteaptă: $operationalCosts, $fuelCost, $maintenanceCost, $operationalRows, $operationalTotal,
 * $periodRangeLabel, $formatCurrency, $formatLiters, $fuelDetailsUrl, $maintenanceDetailsUrl,
 * $expenseBreakdown (ExpenseModel::getCategoryTypeBreakdown sau null), $dateStart, $dateEnd,
 * $selectedVehicleId, $selectedVehicleCategory, $documentCosts (DashboardModel::getDocumentCostBreakdown
 * sau null), $initials, $equipmentCosts (DashboardModel::getEquipmentCostBreakdown sau null).
 */
$costModel = is_array($operationalCosts ?? null) ? $operationalCosts : null;
$hasCostModel = $costModel !== null;
$netSuffix = $hasCostModel ? ' · fără TVA' : '';

if ($hasCostModel) {
    $cardTotal = (float) $costModel['total'];
    $fuelValues = [
        'motorina' => DashboardOperationalCostService::elementValue($costModel, 'carburant'),
        'adblue' => DashboardOperationalCostService::elementValue($costModel, 'adblue'),
    ];
    $maintenanceValues = [
        'intretinere' => DashboardOperationalCostService::elementValue($costModel, 'revizii'),
        'reparatie' => DashboardOperationalCostService::elementValue($costModel, 'reparatii'),
    ];
} else {
    $cardTotal = (float) $operationalTotal;
    $fuelValues = [];
    foreach ((array) ($fuelCost['rows'] ?? []) as $key => $row) {
        $fuelValues[$key] = (float) ($row['value'] ?? 0);
    }
    $maintenanceValues = [];
    foreach ((array) ($maintenanceCost['rows'] ?? []) as $key => $row) {
        $maintenanceValues[$key] = (float) ($row['value'] ?? 0);
    }
}
$fuelTotalValue = array_sum($fuelValues);
$maintenanceTotalValue = array_sum($maintenanceValues);
$fuelHasData = $fuelTotalValue > 0 || (float) ($fuelCost['total_quantity'] ?? 0) > 0;
$maintenanceHasData = $maintenanceTotalValue > 0;

$summaryRows = [
    ['label' => 'Carburant', 'tone' => 'orange', 'value' => $fuelTotalValue, 'face' => 'fuel'],
    ['label' => 'Mentenanță', 'tone' => 'purple', 'value' => $maintenanceTotalValue, 'face' => 'maintenance'],
];
// Restul costurilor (salarii, documente, management...) se văd la click pe total -> fixe / variabile.

$expenses = is_array($expenseBreakdown ?? null) ? $expenseBreakdown : null;
$expenseSummary = $expenses !== null ? (array) ($expenses['summary'] ?? []) : [];
$expenseTotal = (float) ($expenseSummary['total'] ?? 0);
$expenseCategories = [
    'administrativa' => ['face' => 'exp_administrativa', 'label' => 'Cheltuieli administrative', 'icon' => 'bi-building', 'tone' => 'admin'],
    'operationala' => ['face' => 'exp_operationala', 'label' => 'Cheltuieli operaționale', 'icon' => 'bi-truck', 'tone' => 'oper'],
];
$expensePageUrl = static fn(array $extra = []): string => build_query_url(array_merge([
    'page' => 'cheltuieli',
    'date_start' => $dateStart,
    'date_end' => $dateEnd,
    'vehicul_id' => $selectedVehicleId,
], $extra));

$documentCostData = is_array($documentCosts ?? null) ? $documentCosts : null;
$vehicleDocuments = $documentCostData !== null && !empty($documentCostData['vehicles']['available']) ? $documentCostData['vehicles'] : null;
$driverDocuments = $documentCostData !== null && !empty($documentCostData['drivers']['available']) ? $documentCostData['drivers'] : null;
$documentDays = (int) ($documentCostData['days'] ?? 0);
$daysLabel = static fn(int $days): string => $days === 1 ? '1 zi' : $days . ' zile';
$documentRows = [];
if ($vehicleDocuments !== null) {
    $documentRows[] = ['face' => 'doc_vehicles', 'label' => 'Documente mașini', 'tone' => 'indigo', 'value' => (float) $vehicleDocuments['total']];
}
if ($driverDocuments !== null) {
    $documentRows[] = ['face' => 'doc_drivers', 'label' => 'Documente șoferi', 'tone' => 'pink', 'value' => (float) $driverDocuments['total']];
}
$equipment = is_array($equipmentCosts ?? null) && !empty($equipmentCosts['available']) ? $equipmentCosts : null;
if ($equipment !== null) {
    $documentRows[] = ['face' => 'equipment', 'label' => 'Dotări', 'tone' => 'amber', 'value' => (float) $equipment['period_total'],
        'note' => 'Inventar dotări · ' . $daysLabel((int) $equipment['days']) . ' · separat de total'];
}
$equipmentIcon = static fn(string $category): string => match (mb_strtoupper(trim($category), 'UTF-8')) {
    'ADR' => 'bi-exclamation-diamond',
    'PROTECȚIE', 'PROTECTIE' => 'bi-shield-check',
    'SIGURANȚĂ', 'SIGURANTA' => 'bi-life-preserver',
    'CONSUMABILE' => 'bi-battery-half',
    default => 'bi-box-seam',
};

// Cardul arată mereu și cât costă o zi de activitate: suma perioadei ÷ zilele filtrului.
$periodDays = 1;
try {
    $periodDays = max(1, (int) (new DateTimeImmutable($dateStart))->diff(new DateTimeImmutable($dateEnd))->days + 1);
} catch (Throwable) {
    $periodDays = max(1, $documentDays);
}
$perDayValue = static fn(float $value): float => $value / $periodDays;
$perDay = static fn(float $value): string => format_number_ro($value / $periodDays, 2) . ' lei/zi';

$costPercent = static fn(float $part, float $whole): string => $whole > 0 ? format_number_ro($part / $whole * 100, 1) . '%' : '0%';
$costPeriodKey = $hasCostModel && $costModel['months'] !== [] ? (string) end($costModel['months'])['key'] : date('Y-m');
$costPageUrl = build_query_url(['page' => 'cost_operational', 'period' => $costPeriodKey]);
?>
<article class="dashboard-metric-card dashboard-card-operational" data-dashboard-flip-card>
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
            <h2>Cost total operațional</h2>
        </div>
    </header>

    <div class="dashboard-card-face" data-dashboard-summary-face>
        <div class="dashboard-live-contents" data-dashboard-live="operational">
            <?php if ($hasCostModel): ?>
                <button class="dashboard-money-total is-orange dashboard-operational-total dashboard-cost-total-btn" type="button" data-dashboard-face-open="costs" title="Împarte pe costuri fixe și variabile">
                    <span data-dashboard-money="operational.total"><?= e(format_number_ro($cardTotal, 2)) ?></span> <small>lei</small>
                    <i class="bi bi-arrow-right-circle dashboard-cost-total-arrow" aria-hidden="true"></i>
                </button>
            <?php else: ?>
                <div class="dashboard-money-total is-orange dashboard-operational-total">
                    <span data-dashboard-money="operational.total"><?= e(format_number_ro($cardTotal, 2)) ?></span> <small>lei</small>
                </div>
            <?php endif; ?>
            <p class="dashboard-card-period">Total perioadă: <?= e($periodRangeLabel . $netSuffix) ?></p>
            <p class="dashboard-card-perday"><i class="bi bi-calendar-day" aria-hidden="true"></i> ≈ <strong><span data-dashboard-money="operational.perday"><?= e(format_number_ro($perDayValue($cardTotal), 2)) ?></span></strong> lei/zi · <?= e($daysLabel($periodDays)) ?></p>

            <div class="dashboard-operational-summary" aria-label="Defalcare cost operațional">
                <?php foreach ($summaryRows as $row): ?>
                    <button class="dashboard-operational-summary-row dashboard-operational-open tone-<?= e($row['tone']) ?>" type="button" data-dashboard-face-open="<?= e($row['face']) ?>" title="Vezi defalcarea: <?= e($row['label']) ?>">
                        <span>
                            <i class="dashboard-operational-dot tone-<?= e($row['tone']) ?>" aria-hidden="true"></i>
                            <?= e($row['label']) ?>
                        </span>
                        <strong>
                            <span class="dashboard-row-amount"><?= e($formatCurrency($row['value'])) ?><small><?= e($perDay($row['value'])) ?></small></span>
                            <i class="bi bi-chevron-right dashboard-operational-open-arrow" aria-hidden="true"></i>
                        </strong>
                    </button>
                <?php endforeach; ?>
                <?php if ($expenses !== null): ?>
                    <button class="dashboard-operational-summary-row dashboard-operational-open tone-teal is-separate" type="button" data-dashboard-face-open="expenses" title="Cheltuieli administrative și operaționale (registrul Cheltuieli)">
                        <span>
                            <i class="dashboard-operational-dot tone-teal" aria-hidden="true"></i>
                            Cheltuieli
                            <small class="dashboard-operational-row-note">registru · cu TVA · separat de total</small>
                        </span>
                        <strong>
                            <span class="dashboard-row-amount"><?= e($formatCurrency($expenseTotal)) ?><small><?= e($perDay($expenseTotal)) ?></small></span>
                            <i class="bi bi-chevron-right dashboard-operational-open-arrow" aria-hidden="true"></i>
                        </strong>
                    </button>
                <?php endif; ?>
                <?php foreach ($documentRows as $documentIndex => $row): ?>
                    <button class="dashboard-operational-summary-row dashboard-operational-open tone-<?= e($row['tone']) ?><?= $documentIndex === 0 && $expenses === null ? ' is-separate' : '' ?>" type="button" data-dashboard-face-open="<?= e($row['face']) ?>" title="<?= e($row['face'] === 'equipment' ? 'Dotările montate pe vehicule' : 'Costul documentelor din Configurare costuri') ?>">
                        <span>
                            <i class="dashboard-operational-dot tone-<?= e($row['tone']) ?>" aria-hidden="true"></i>
                            <?= e($row['label']) ?>
                            <small class="dashboard-operational-row-note"><?= e($row['note'] ?? ('Configurare costuri · ' . $daysLabel($documentDays) . ' · separat de total')) ?></small>
                        </span>
                        <strong>
                            <span class="dashboard-row-amount"><?= e($formatCurrency($row['value'])) ?><small><?= e($perDay($row['value'])) ?></small></span>
                            <i class="bi bi-chevron-right dashboard-operational-open-arrow" aria-hidden="true"></i>
                        </strong>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($hasCostModel): ?>
        <?php
        $fixedTotal = (float) $costModel['fixed_total'];
        $variableTotal = (float) $costModel['variable_total'];
        $fixedShare = $cardTotal > 0 ? $fixedTotal / $cardTotal * 100 : 0;
        ?>
        <div class="dashboard-card-face dashboard-cost-face is-split" data-dashboard-face="costs" data-dashboard-live="costs" hidden>
            <div class="dashboard-inactive-head">
                <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <h3>Fixe vs variabile</h3>
                <a class="dashboard-cost-face-link" href="<?= e($costPageUrl) ?>">
                    Cost / km <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="dashboard-money-total is-orange">
                <span data-dashboard-money="costs.total"><?= e(format_number_ro($cardTotal, 2)) ?></span> <small>lei</small>
            </div>
            <p class="dashboard-card-period">Total perioadă: <?= e($periodRangeLabel) ?> · fără TVA</p>
            <p class="dashboard-card-perday"><i class="bi bi-calendar-day" aria-hidden="true"></i> ≈ <strong><?= e(format_number_ro($perDayValue($cardTotal), 2)) ?></strong> lei/zi · <?= e($daysLabel($periodDays)) ?></p>

            <div class="dashboard-cost-split-bar" style="--fixed-share: <?= e((string) round($fixedShare, 2)) ?>%" aria-hidden="true">
                <span class="is-fixed"></span><span class="is-variable"></span>
            </div>

            <div class="dashboard-cost-split-tiles">
                <?php foreach ([
                    ['face' => 'cost_fix', 'tip' => 'fix', 'label' => 'Costuri fixe', 'icon' => 'bi-lock', 'value' => $fixedTotal, 'money' => 'costs.fixed'],
                    ['face' => 'cost_variabil', 'tip' => 'variabil', 'label' => 'Costuri variabile', 'icon' => 'bi-speedometer2', 'value' => $variableTotal, 'money' => 'costs.variable'],
                ] as $tile): ?>
                    <?php $tileMissing = count((array) ($costModel['missing'][$tile['tip']] ?? [])); ?>
                    <button class="dashboard-cost-split-tile is-<?= e($tile['tip']) ?>" type="button" data-dashboard-face-open="<?= e($tile['face']) ?>">
                        <span class="dashboard-cost-split-tile-head">
                            <i class="bi <?= e($tile['icon']) ?>" aria-hidden="true"></i>
                            <?= e($tile['label']) ?>
                            <span class="dashboard-cost-split-pct"><?= e($costPercent($tile['value'], $cardTotal)) ?></span>
                        </span>
                        <strong><span data-dashboard-money="<?= e($tile['money']) ?>"><?= e(format_number_ro($tile['value'], 2)) ?></span> <small>lei</small></strong>
                        <span class="dashboard-cost-split-perday">≈ <?= e($perDay($tile['value'])) ?></span>
                        <span class="dashboard-cost-split-tile-foot">
                            Pe categorii
                            <?php if ($tileMissing > 0): ?>
                                · <em title="Elemente fără valoare configurată (LIPSĂ, nu 0)"><?= e((string) $tileMissing) ?> lipsă</em>
                            <?php endif; ?>
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($costModel['prorated']) || !empty($costModel['light_only'])): ?>
                <p class="dashboard-cost-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <?php if (!empty($costModel['light_only'])): ?>
                        Modelul de cost acoperă flota grea; pentru vehiculele ușoare intră doar carburantul.
                    <?php else: ?>
                        Pe zi: variabilele exact din zilele alese; costurile cu perioadă (documente, asigurări, amortizare, dotări, salarii) împărțite pe zile.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <?php foreach ([
            ['face' => 'cost_fix', 'tip' => 'fix', 'title' => 'Costuri fixe', 'total' => $fixedTotal],
            ['face' => 'cost_variabil', 'tip' => 'variabil', 'title' => 'Costuri variabile', 'total' => $variableTotal],
        ] as $costFace): ?>
            <?php
            $costCategories = (array) ($costModel['tips'][$costFace['tip']] ?? []);
            $costMissing = (array) ($costModel['missing'][$costFace['tip']] ?? []);
            ?>
            <div class="dashboard-card-face dashboard-active-face dashboard-cost-face is-cost-<?= e($costFace['tip']) ?>" data-dashboard-face="<?= e($costFace['face']) ?>" data-dashboard-live="<?= e($costFace['face']) ?>" hidden>
                <div class="dashboard-inactive-head">
                    <button class="dashboard-inactive-back" type="button" data-dashboard-face-open="costs" aria-label="Înapoi la fixe / variabile">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </button>
                    <h3><?= e($costFace['title']) ?></h3>
                    <span class="dashboard-inactive-total is-cost"><?= e(format_number_ro($costFace['total'], 2)) ?> lei</span>
                </div>
                <p class="dashboard-card-perday"><i class="bi bi-calendar-day" aria-hidden="true"></i> ≈ <strong><?= e(format_number_ro($perDayValue((float) $costFace['total']), 2)) ?></strong> lei/zi · <?= e($daysLabel($periodDays)) ?></p>

                <?php if ($costCategories === []): ?>
                    <p class="dashboard-inactive-empty is-muted">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Nu există costuri de acest tip pentru filtrele selectate.
                    </p>
                <?php else: ?>
                    <ul class="dashboard-active-types">
                        <?php foreach ($costCategories as $index => $costCategory): ?>
                            <?php $costRowId = 'dashboard_' . $costFace['face'] . '_' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $costCategory['code']); ?>
                            <li class="dashboard-active-type" style="--share: <?= e((string) round((float) $costCategory['share'], 2)) ?>%; --delay: <?= e((string) ($index * 60)) ?>ms">
                                <div class="dashboard-active-type-row">
                                    <button class="dashboard-active-type-main" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($costRowId) ?>">
                                        <span class="dashboard-active-type-icon"><i class="bi <?= e((string) $costCategory['icon']) ?>" aria-hidden="true"></i></span>
                                        <span class="dashboard-active-type-label">
                                            <strong title="<?= e((string) $costCategory['label']) ?>"><?= e((string) $costCategory['label']) ?></strong>
                                            <span class="dashboard-active-type-bar" aria-hidden="true"><span></span></span>
                                        </span>
                                    </button>
                                    <span class="dashboard-cost-cat-value"><?= e(format_number_ro((float) $costCategory['total'], 2)) ?> <small>lei</small></span>
                                    <button class="dashboard-active-type-chevron-btn" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($costRowId) ?>" aria-label="Arată elementele de cost">
                                        <i class="bi bi-chevron-down dashboard-active-type-chevron" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <small class="dashboard-type-split">
                                    <?= e($costPercent((float) $costCategory['total'], (float) $costFace['total'])) ?> din total · <?= e($perDay((float) $costCategory['total'])) ?>
                                    <?php if ((int) $costCategory['vehicles'] > 0): ?>
                                        · <?= e((string) ((int) $costCategory['vehicles'])) ?> <?= (int) $costCategory['vehicles'] === 1 ? 'unitate' : 'unități' ?>
                                    <?php endif; ?>
                                </small>
                                <div class="dashboard-active-type-plates" id="<?= e($costRowId) ?>" hidden>
                                    <?php if ((array) $costCategory['elements'] === []): ?>
                                        <p class="dashboard-inactive-empty is-muted">Niciun cost cu valoare în perioadă.</p>
                                    <?php else: ?>
                                        <ul class="dashboard-cost-elements">
                                            <?php foreach ((array) $costCategory['elements'] as $element): ?>
                                                <li>
                                                    <span><?= e((string) $element['label']) ?></span>
                                                    <strong><?= e($formatCurrency((float) $element['value'])) ?></strong>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($costMissing !== []): ?>
                    <details class="dashboard-cost-missing">
                        <summary>
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <?= e((string) count($costMissing)) ?> elemente fără valoare (LIPSĂ, nu 0)
                        </summary>
                        <p><?= e(implode(', ', $costMissing)) ?>.</p>
                        <a href="<?= e($costPageUrl) ?>">Completează în Cost operațional / km <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($expenses !== null): ?>
        <?php $adminShare = $expenseTotal > 0 ? (float) ($expenseSummary['administrativa'] ?? 0) / $expenseTotal * 100 : 0; ?>
        <div class="dashboard-card-face dashboard-cost-face is-expenses" data-dashboard-face="expenses" data-dashboard-live="expenses" hidden>
            <div class="dashboard-inactive-head">
                <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <h3><i class="bi bi-receipt" aria-hidden="true"></i> Cheltuieli</h3>
                <a class="dashboard-cost-face-link" href="<?= e($expensePageUrl()) ?>">
                    Vezi pagina <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="dashboard-money-total is-teal">
                <span data-dashboard-money="expenses.total"><?= e(format_number_ro($expenseTotal, 2)) ?></span> <small>lei</small>
            </div>
            <p class="dashboard-card-period">Total perioadă: <?= e($periodRangeLabel) ?> · cu TVA</p>
            <p class="dashboard-card-perday"><i class="bi bi-calendar-day" aria-hidden="true"></i> ≈ <strong><?= e(format_number_ro($perDayValue($expenseTotal), 2)) ?></strong> lei/zi · <?= e($daysLabel($periodDays)) ?></p>

            <div class="dashboard-cost-split-bar is-expenses" style="--fixed-share: <?= e((string) round($adminShare, 2)) ?>%" aria-hidden="true">
                <span class="is-fixed"></span><span class="is-variable"></span>
            </div>

            <div class="dashboard-cost-split-tiles">
                <?php foreach ($expenseCategories as $categoryKey => $categoryMeta): ?>
                    <?php
                    $categoryValue = (float) ($expenseSummary[$categoryKey] ?? 0);
                    $categoryDocs = (int) ($expenseSummary['count_' . $categoryKey] ?? 0);
                    ?>
                    <button class="dashboard-cost-split-tile is-exp-<?= e($categoryMeta['tone']) ?>" type="button" data-dashboard-face-open="<?= e($categoryMeta['face']) ?>">
                        <span class="dashboard-cost-split-tile-head">
                            <i class="bi <?= e($categoryMeta['icon']) ?>" aria-hidden="true"></i>
                            <?= e($categoryKey === 'administrativa' ? 'Administrative' : 'Operaționale') ?>
                            <span class="dashboard-cost-split-pct"><?= e($costPercent($categoryValue, $expenseTotal)) ?></span>
                        </span>
                        <strong><span data-dashboard-money="expenses.<?= e($categoryKey) ?>"><?= e(format_number_ro($categoryValue, 2)) ?></span> <small>lei</small></strong>
                        <span class="dashboard-cost-split-perday">≈ <?= e($perDay($categoryValue)) ?></span>
                        <span class="dashboard-cost-split-tile-foot">
                            <?= e((string) $categoryDocs) ?> documente<?= $categoryKey === 'operationala' && !empty($expenseSummary['carburant']['aplicabil']) && (float) ($expenseSummary['carburant']['total'] ?? 0) > 0 ? ' + carburant' : '' ?>
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <p class="dashboard-cost-note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <?php if (($selectedVehicleCategory ?? 'toate') !== 'toate'): ?>
                    Categoria de vehicul nu se aplică aici (pagina Cheltuieli nu are acest filtru); se aplică perioada și vehiculul.
                <?php else: ?>
                    Ca pe pagina Cheltuieli: valoarea documentelor (cu TVA) + carburantul. Nu se adună la costul total.
                <?php endif; ?>
            </p>
        </div>

        <?php foreach ($expenseCategories as $categoryKey => $categoryMeta): ?>
            <?php
            $categoryValue = (float) ($expenseSummary[$categoryKey] ?? 0);
            $categoryTypes = (array) ($expenses['tipuri'][$categoryKey] ?? []);
            ?>
            <div class="dashboard-card-face dashboard-active-face dashboard-cost-face is-exp-<?= e($categoryMeta['tone']) ?>" data-dashboard-face="<?= e($categoryMeta['face']) ?>" data-dashboard-live="<?= e($categoryMeta['face']) ?>" hidden>
                <div class="dashboard-inactive-head">
                    <button class="dashboard-inactive-back" type="button" data-dashboard-face-open="expenses" aria-label="Înapoi la cheltuieli">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </button>
                    <h3><?= e($categoryMeta['label']) ?></h3>
                    <span class="dashboard-inactive-total is-cost"><?= e(format_number_ro($categoryValue, 2)) ?> lei</span>
                </div>

                <?php if ($categoryTypes === []): ?>
                    <p class="dashboard-inactive-empty is-muted">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Nu există cheltuieli în această categorie pentru perioada selectată.
                    </p>
                <?php else: ?>
                    <ul class="dashboard-active-types dashboard-expense-types">
                        <?php foreach ($categoryTypes as $index => $expenseType): ?>
                            <?php
                            $typeValue = (float) $expenseType['total'];
                            $typeShare = $categoryValue > 0 ? $typeValue / $categoryValue * 100 : 0;
                            $typeUrl = $expensePageUrl(array_filter([
                                'categorie' => $categoryKey,
                                'tip_id' => (int) $expenseType['tip_id'] > 0 ? (int) $expenseType['tip_id'] : null,
                            ]));
                            ?>
                            <li class="dashboard-active-type" style="--share: <?= e((string) round($typeShare, 2)) ?>%; --delay: <?= e((string) ($index * 50)) ?>ms">
                                <a class="dashboard-active-type-row dashboard-expense-type-link" href="<?= e($typeUrl) ?>" title="Deschide în pagina Cheltuieli">
                                    <span class="dashboard-active-type-label">
                                        <strong title="<?= e((string) $expenseType['nume']) ?>"><?= e((string) $expenseType['nume']) ?></strong>
                                        <span class="dashboard-active-type-bar" aria-hidden="true"><span></span></span>
                                        <small class="dashboard-expense-type-meta">
                                            <?= e($costPercent($typeValue, $categoryValue)) ?> ·
                                            <?php if ($expenseType['litri'] !== null): ?>
                                                <?= e((string) ((int) $expenseType['count'])) ?> alimentări, <?= e($formatLiters($expenseType['litri'])) ?>
                                            <?php else: ?>
                                                <?= e((string) ((int) $expenseType['count'])) ?> <?= (int) $expenseType['count'] === 1 ? 'document' : 'documente' ?>
                                            <?php endif; ?>
                                        </small>
                                    </span>
                                    <span class="dashboard-cost-cat-value"><?= e(format_number_ro($typeValue, 2)) ?> <small>lei</small></span>
                                    <i class="bi bi-box-arrow-up-right dashboard-expense-type-open" aria-hidden="true"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <a class="dashboard-capacity-list-link" href="<?= e($expensePageUrl(['categorie' => $categoryKey])) ?>">
                    <span>Deschide toate cheltuielile <?= $categoryKey === 'administrativa' ? 'administrative' : 'operaționale' ?></span>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($vehicleDocuments !== null): ?>
        <?php
        $vehicleDocTotal = (float) $vehicleDocuments['total'];
        $vehicleDocGroups = (array) $vehicleDocuments['groups'];
        ?>
        <div class="dashboard-card-face dashboard-active-face dashboard-cost-face is-docs is-doc-vehicles" data-dashboard-face="doc_vehicles" data-dashboard-live="doc_vehicles" hidden>
            <div class="dashboard-inactive-head">
                <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <h3><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Documente mașini</h3>
                <span class="dashboard-inactive-total is-cost"><span data-dashboard-money="docs.vehicles"><?= e(format_number_ro($vehicleDocTotal, 2)) ?></span> lei</span>
            </div>
            <p class="dashboard-cost-note is-top">
                <i class="bi bi-calculator" aria-hidden="true"></i>
                preț ÷ valabilitate × <?= e($daysLabel($documentDays)) ?> · ≈ <strong><?= e($perDay($vehicleDocTotal)) ?></strong> · override-ul pe vehicul are prioritate
            </p>

            <?php if ($vehicleDocGroups === []): ?>
                <p class="dashboard-inactive-empty is-muted">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Niciun vehicul activ cu documente configurate pentru filtrele selectate.
                </p>
            <?php else: ?>
                <ul class="dashboard-active-types">
                    <?php foreach ($vehicleDocGroups as $index => $docGroup): ?>
                        <?php
                        $groupShare = $vehicleDocTotal > 0 ? (float) $docGroup['total'] / $vehicleDocTotal * 100 : 0;
                        $groupRowId = 'dashboard_doc_vehicles_' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $docGroup['key']);
                        ?>
                        <li class="dashboard-active-type" style="--share: <?= e((string) round($groupShare, 2)) ?>%; --delay: <?= e((string) ($index * 50)) ?>ms">
                            <div class="dashboard-active-type-row">
                                <button class="dashboard-active-type-main" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($groupRowId) ?>">
                                    <span class="dashboard-active-type-icon"><i class="bi <?= e((string) $docGroup['icon']) ?>" aria-hidden="true"></i></span>
                                    <span class="dashboard-active-type-label">
                                        <strong title="<?= e((string) $docGroup['label']) ?>"><?= e((string) $docGroup['label']) ?></strong>
                                        <span class="dashboard-active-type-bar" aria-hidden="true"><span></span></span>
                                    </span>
                                </button>
                                <span class="dashboard-cost-cat-value"><?= e(format_number_ro((float) $docGroup['total'], 2)) ?> <small>lei</small></span>
                                <button class="dashboard-active-type-chevron-btn" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($groupRowId) ?>" aria-label="Arată documentele">
                                    <i class="bi bi-chevron-down dashboard-active-type-chevron" aria-hidden="true"></i>
                                </button>
                            </div>
                            <small class="dashboard-type-split">
                                <?= e((string) ((int) $docGroup['vehicles'])) ?> <?= (int) $docGroup['vehicles'] === 1 ? 'vehicul' : 'vehicule' ?>
                                · <?= e($costPercent((float) $docGroup['total'], $vehicleDocTotal)) ?> · <?= e($perDay((float) $docGroup['total'])) ?>
                                <?php if ((array) $docGroup['unpriced'] !== []): ?>
                                    · <em class="dashboard-doc-unpriced-count"><?= e((string) count((array) $docGroup['unpriced'])) ?> fără preț</em>
                                <?php endif; ?>
                            </small>
                            <div class="dashboard-active-type-plates" id="<?= e($groupRowId) ?>" hidden>
                                <?php if ((array) $docGroup['documents'] === []): ?>
                                    <p class="dashboard-inactive-empty is-muted">Niciun document cu preț pentru această grupă.</p>
                                <?php else: ?>
                                    <ul class="dashboard-cost-elements">
                                        <?php foreach ((array) $docGroup['documents'] as $document): ?>
                                            <li>
                                                <span><?= e((string) $document['label']) ?> <small>· <?= e((string) ((int) $document['vehicles'])) ?> veh.</small></span>
                                                <strong><?= e($formatCurrency((float) $document['total'])) ?></strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ((array) $docGroup['unpriced'] !== []): ?>
                                    <p class="dashboard-doc-unpriced">Fără preț (nu intră în sumă): <?= e(implode(', ', (array) $docGroup['unpriced'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <a class="dashboard-capacity-list-link" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule'])) ?>">
                <span>Configurare costuri documente vehicule</span>
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($driverDocuments !== null): ?>
        <?php
        $driverDocTotal = (float) $driverDocuments['total'];
        $driverDocList = (array) $driverDocuments['drivers'];
        ?>
        <div class="dashboard-card-face dashboard-active-face dashboard-cost-face is-docs is-doc-drivers" data-dashboard-face="doc_drivers" data-dashboard-live="doc_drivers" hidden>
            <div class="dashboard-inactive-head">
                <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <h3><i class="bi bi-person-vcard" aria-hidden="true"></i> Documente șoferi</h3>
                <span class="dashboard-inactive-total is-cost"><span data-dashboard-money="docs.drivers"><?= e(format_number_ro($driverDocTotal, 2)) ?></span> lei</span>
            </div>
            <p class="dashboard-cost-note is-top">
                <i class="bi bi-calculator" aria-hidden="true"></i>
                preț ÷ valabilitate × <?= e($daysLabel($documentDays)) ?> · ≈ <strong><?= e($perDay($driverDocTotal)) ?></strong>
            </p>

            <?php if ($driverDocList === []): ?>
                <p class="dashboard-inactive-empty is-muted">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Niciun șofer activ cu costuri de documente configurate.
                </p>
            <?php else: ?>
                <div class="dashboard-doc-type-summary">
                    <span class="dashboard-capacity-title"><i class="bi bi-files" aria-hidden="true"></i> Pe tip de document</span>
                    <ul class="dashboard-cost-elements">
                        <?php foreach ((array) $driverDocuments['documents'] as $document): ?>
                            <li>
                                <span><?= e((string) $document['label']) ?> <small>· <?= e((string) ((int) $document['drivers'])) ?> șof.</small></span>
                                <strong><?= e($formatCurrency((float) $document['total'])) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <span class="dashboard-capacity-title dashboard-doc-drivers-title"><i class="bi bi-people" aria-hidden="true"></i> Pe șofer</span>
                <ul class="dashboard-active-types">
                    <?php foreach ($driverDocList as $index => $docDriver): ?>
                        <?php
                        $driverShare = $driverDocTotal > 0 ? (float) $docDriver['total'] / $driverDocTotal * 100 : 0;
                        $driverRowId = 'dashboard_doc_driver_' . (int) $docDriver['id'];
                        $driverDocName = (string) $docDriver['nume'] !== '' ? (string) $docDriver['nume'] : 'Șofer #' . (int) $docDriver['id'];
                        ?>
                        <li class="dashboard-active-type" style="--share: <?= e((string) round($driverShare, 2)) ?>%; --delay: <?= e((string) (min($index, 10) * 40)) ?>ms">
                            <div class="dashboard-active-type-row">
                                <button class="dashboard-active-type-main" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($driverRowId) ?>">
                                    <span class="dashboard-active-type-icon dashboard-doc-avatar" aria-hidden="true"><?= e($initials($driverDocName)) ?></span>
                                    <span class="dashboard-active-type-label">
                                        <strong title="<?= e($driverDocName) ?>"><?= e($driverDocName) ?></strong>
                                        <span class="dashboard-active-type-bar" aria-hidden="true"><span></span></span>
                                    </span>
                                </button>
                                <span class="dashboard-cost-cat-value"><?= e(format_number_ro((float) $docDriver['total'], 2)) ?> <small>lei</small></span>
                                <button class="dashboard-active-type-chevron-btn" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($driverRowId) ?>" aria-label="Arată documentele șoferului">
                                    <i class="bi bi-chevron-down dashboard-active-type-chevron" aria-hidden="true"></i>
                                </button>
                            </div>
                            <small class="dashboard-type-split">
                                <?= e((string) count((array) $docDriver['documents'])) ?> <?= count((array) $docDriver['documents']) === 1 ? 'document' : 'documente' ?>
                                · <?= e($costPercent((float) $docDriver['total'], $driverDocTotal)) ?> · <?= e($perDay((float) $docDriver['total'])) ?>
                            </small>
                            <div class="dashboard-active-type-plates" id="<?= e($driverRowId) ?>" hidden>
                                <ul class="dashboard-cost-elements">
                                    <?php foreach ((array) $docDriver['documents'] as $document): ?>
                                        <li>
                                            <span><?= e((string) $document['label']) ?> <small>· <?= e(format_number_ro((float) $document['cost'], 2)) ?> lei / <?= e((string) ((int) $document['validity_days'])) ?> zile</small></span>
                                            <strong><?= e($formatCurrency((float) $document['total'])) ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <a class="dashboard-capacity-list-link" href="<?= e(build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => (int) $docDriver['id']])) ?>">
                                    <span>Fișa șoferului</span> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ((int) $driverDocuments['drivers_without_cost'] > 0): ?>
                <p class="dashboard-doc-unpriced">
                    <?= e((string) ((int) $driverDocuments['drivers_without_cost'])) ?> șoferi activi fără costuri de documente configurate (nu intră în sumă).
                </p>
            <?php endif; ?>
            <a class="dashboard-capacity-list-link" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi'])) ?>">
                <span>Configurare costuri documente șoferi</span>
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($equipment !== null): ?>
        <?php
        $equipmentTotal = (float) $equipment['period_total'];
        $equipmentCategories = (array) $equipment['categories'];
        ?>
        <div class="dashboard-card-face dashboard-active-face dashboard-cost-face is-docs is-equipment" data-dashboard-face="equipment" data-dashboard-live="equipment" hidden>
            <div class="dashboard-inactive-head">
                <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <h3><i class="bi bi-tools" aria-hidden="true"></i> Dotări</h3>
                <span class="dashboard-inactive-total is-cost"><span data-dashboard-money="equipment.total"><?= e(format_number_ro($equipmentTotal, 2)) ?></span> lei</span>
            </div>
            <p class="dashboard-cost-note is-top">
                <i class="bi bi-calculator" aria-hidden="true"></i>
                cost pe <?= e($daysLabel((int) $equipment['days'])) ?> (valoare ÷ interval inspecție) · ≈ <strong><?= e($perDay($equipmentTotal)) ?></strong> ·
                <?= e((string) ((int) $equipment['units'])) ?> buc. pe <?= e((string) ((int) $equipment['vehicles'])) ?> vehicule,
                valoare inventar <?= e(format_number_ro((float) $equipment['total'], 2)) ?> lei
            </p>

            <?php if ($equipmentCategories === []): ?>
                <p class="dashboard-inactive-empty is-muted">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Nicio dotare montată pe vehiculele din filtru.
                </p>
            <?php else: ?>
                <ul class="dashboard-active-types">
                    <?php foreach ($equipmentCategories as $index => $equipmentCategory): ?>
                        <?php
                        $categoryShare = $equipmentTotal > 0 ? (float) $equipmentCategory['period_total'] / $equipmentTotal * 100 : 0;
                        $categoryRowId = 'dashboard_equipment_' . $index;
                        ?>
                        <li class="dashboard-active-type" style="--share: <?= e((string) round($categoryShare, 2)) ?>%; --delay: <?= e((string) ($index * 50)) ?>ms">
                            <div class="dashboard-active-type-row">
                                <button class="dashboard-active-type-main" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($categoryRowId) ?>">
                                    <span class="dashboard-active-type-icon"><i class="bi <?= e($equipmentIcon((string) $equipmentCategory['label'])) ?>" aria-hidden="true"></i></span>
                                    <span class="dashboard-active-type-label">
                                        <strong title="<?= e((string) $equipmentCategory['label']) ?>"><?= e((string) $equipmentCategory['label']) ?></strong>
                                        <span class="dashboard-active-type-bar" aria-hidden="true"><span></span></span>
                                    </span>
                                </button>
                                <span class="dashboard-cost-cat-value"><?= e(format_number_ro((float) $equipmentCategory['period_total'], 2)) ?> <small>lei</small></span>
                                <button class="dashboard-active-type-chevron-btn" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($categoryRowId) ?>" aria-label="Arată dotările">
                                    <i class="bi bi-chevron-down dashboard-active-type-chevron" aria-hidden="true"></i>
                                </button>
                            </div>
                            <small class="dashboard-type-split">
                                <?= e((string) ((int) $equipmentCategory['units'])) ?> buc. · <?= e((string) ((int) $equipmentCategory['vehicles'])) ?> veh. · <?= e($perDay((float) $equipmentCategory['period_total'])) ?>
                                · valoare <?= e(format_number_ro((float) $equipmentCategory['total'], 2)) ?> lei
                            </small>
                            <div class="dashboard-active-type-plates" id="<?= e($categoryRowId) ?>" hidden>
                                <ul class="dashboard-cost-elements">
                                    <?php foreach ((array) $equipmentCategory['items'] as $item): ?>
                                        <li>
                                            <span><?= e((string) $item['label']) ?> <small>· <?= e((string) ((int) $item['units'])) ?> buc. / <?= e((string) ((int) $item['vehicles'])) ?> veh. · valoare <?= e(format_number_ro((float) $item['total'], 2)) ?> lei</small></span>
                                            <strong><?= e($formatCurrency((float) $item['period_total'])) ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <a class="dashboard-capacity-list-link" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">
                <span>Inventar dotări vehicule</span>
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    <?php endif; ?>

    <div class="dashboard-card-face dashboard-cost-face is-fuel" data-dashboard-face="fuel" data-dashboard-live="fuel" hidden>
        <div class="dashboard-inactive-head">
            <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </button>
            <h3><i class="bi bi-fuel-pump" aria-hidden="true"></i> Carburant</h3>
            <a class="dashboard-cost-face-link" href="<?= e($fuelDetailsUrl) ?>">
                Vezi detalii <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
        </div>

        <div class="dashboard-money-total is-orange">
            <span data-dashboard-money="fuel.total"><?= e(format_number_ro($fuelTotalValue, 2)) ?></span> <small>lei</small>
        </div>
        <p class="dashboard-card-period">Total perioadă: <?= e($periodRangeLabel . $netSuffix) ?></p>
        <p class="dashboard-card-perday"><i class="bi bi-calendar-day" aria-hidden="true"></i> ≈ <strong><?= e(format_number_ro($perDayValue($fuelTotalValue), 2)) ?></strong> lei/zi · <?= e($daysLabel($periodDays)) ?></p>

        <?php if (!$fuelHasData): ?>
            <p class="dashboard-operational-empty">Nu există alimentări în perioada selectată.</p>
        <?php endif; ?>

        <table class="dashboard-mini-table">
            <thead>
            <tr>
                <th>Produs</th>
                <th class="text-end">Cantitate</th>
                <th class="text-end">Valoare<?= $hasCostModel ? ' (fără TVA)' : '' ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array) ($fuelCost['rows'] ?? []) as $key => $row): ?>
                <tr>
                    <td>
                        <span class="dashboard-product-dot tone-<?= e((string) ($row['tone'] ?? 'blue')) ?>"></span>
                        <?= e((string) ($row['label'] ?? '')) ?>
                    </td>
                    <td class="text-end"><?= e($formatLiters($row['quantity'] ?? 0)) ?></td>
                    <td class="text-end"><?= e($formatCurrency($fuelValues[$key] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="dashboard-mini-total">
                <td>Total</td>
                <td class="text-end"><?= e($formatLiters($fuelCost['total_quantity'] ?? 0)) ?></td>
                <td class="text-end"><?= e($formatCurrency($fuelTotalValue)) ?></td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="dashboard-card-face dashboard-cost-face is-maintenance" data-dashboard-face="maintenance" data-dashboard-live="maintenance" hidden>
        <div class="dashboard-inactive-head">
            <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la costul total">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </button>
            <h3><i class="bi bi-tools" aria-hidden="true"></i> Mentenanță</h3>
            <a class="dashboard-cost-face-link" href="<?= e($maintenanceDetailsUrl) ?>">
                Vezi detalii <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
        </div>

        <div class="dashboard-money-total is-purple">
            <span data-dashboard-money="maintenance.total"><?= e(format_number_ro($maintenanceTotalValue, 2)) ?></span> <small>lei</small>
        </div>
        <p class="dashboard-card-period">Total perioadă: <?= e($periodRangeLabel . $netSuffix) ?></p>
        <p class="dashboard-card-perday"><i class="bi bi-calendar-day" aria-hidden="true"></i> ≈ <strong><?= e(format_number_ro($perDayValue($maintenanceTotalValue), 2)) ?></strong> lei/zi · <?= e($daysLabel($periodDays)) ?></p>

        <?php if (!$maintenanceHasData): ?>
            <p class="dashboard-operational-empty">Nu există costuri de mentenanță în perioada selectată.</p>
        <?php endif; ?>

        <table class="dashboard-mini-table">
            <thead>
            <tr>
                <th>Categorie</th>
                <th class="text-end">Valoare</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array) ($maintenanceCost['rows'] ?? []) as $key => $row): ?>
                <tr>
                    <td>
                        <i class="bi <?= e((string) ($row['icon'] ?? 'bi-wrench')) ?>" aria-hidden="true"></i>
                        <?= e((string) ($row['label'] ?? '')) ?>
                    </td>
                    <td class="text-end"><?= e($formatCurrency($maintenanceValues[$key] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="dashboard-mini-total">
                <td>Total</td>
                <td class="text-end"><?= e($formatCurrency($maintenanceTotalValue)) ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</article>
