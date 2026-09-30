<?php
/*
 * Fetele "pe tip de vehicul" ale cardului Status vehicule:
 *  - 'active': unitatile active pe tip (click pe "Active");
 *  - 'total':  toate unitatile pe tip, cu impartirea activ / inactiv (click pe "Total vehicule").
 * Ansamblul cap tractor + semiremorca = 1, ca in contoare. Fiecare tip se desface la
 * click in numerele de inmatriculare (assets/js/dashboard-live.js).
 *
 * Asteapta: $vehicleStatus, $typeFaceMode ('active' | 'total').
 */
$isTotalFace = $typeFaceMode === 'total';
$typeRows = is_array($vehicleStatus[$isTotalFace ? 'total_breakdown' : 'active_breakdown'] ?? null)
    ? $vehicleStatus[$isTotalFace ? 'total_breakdown' : 'active_breakdown']
    : [];
$typeTotal = (int) ($vehicleStatus[$isTotalFace ? 'total' : 'active'] ?? 0);
$typeFaceIdPrefix = 'dashboard_' . $typeFaceMode . '_type_';

// Click pe un numar deschide lista de vehicule cu exact unitatile numarate aici
// (filtrul ascuns "ids"; pentru ansamblu se trimite capul tractor, semiremorca apare
// pe randul lui). $onlyActive: null = toate, true / false = doar active / inactive.
$typeUnitsUrl = static function (array $units, string $label, ?bool $onlyActive = null): string {
    $ids = [];
    foreach ($units as $typeUnit) {
        if ($onlyActive !== null && !empty($typeUnit['active']) !== $onlyActive) {
            continue;
        }
        $firstId = (int) ($typeUnit['members'][0]['id'] ?? 0);
        if ($firstId > 0) {
            $ids[] = $firstId;
        }
    }

    return build_query_url(['page' => 'vehicule', 'ids' => $ids === [] ? [0] : $ids, 'selectie' => $label]);
};
$allTypeUnits = [];
foreach ($typeRows as $type) {
    foreach ((array) ($type['units'] ?? []) as $typeUnit) {
        $allTypeUnits[] = $typeUnit;
    }
}
$scopeLabel = $isTotalFace ? 'toate' : 'active';
?>
<div class="dashboard-card-face dashboard-active-face<?= $isTotalFace ? ' is-total' : '' ?>" data-dashboard-face="<?= e($typeFaceMode) ?>" hidden>
    <div class="dashboard-inactive-head">
        <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la sumar">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </button>
        <h3><?= $isTotalFace ? 'Toate vehiculele pe tip' : 'Vehicule active pe tip' ?></h3>
        <a class="dashboard-inactive-total dashboard-type-count-link <?= $isTotalFace ? 'is-blue' : 'is-green' ?>" href="<?= e($typeUnitsUrl($allTypeUnits, $isTotalFace ? 'toate vehiculele' : 'vehicule active', $isTotalFace ? null : true)) ?>" title="Deschide lista cu aceste <?= e((string) $typeTotal) ?> vehicule"><?= e((string) $typeTotal) ?></a>
    </div>

    <?php if ($isTotalFace && $typeRows !== []): ?>
        <div class="dashboard-type-legend">
            <a href="<?= e($typeUnitsUrl($allTypeUnits, 'vehicule active', true)) ?>"><i class="is-active"></i> Active <?= e((string) ((int) ($vehicleStatus['active'] ?? 0))) ?></a>
            <a href="<?= e($typeUnitsUrl($allTypeUnits, 'vehicule inactive', false)) ?>"><i class="is-inactive"></i> Inactive <?= e((string) ((int) ($vehicleStatus['inactive'] ?? 0))) ?></a>
        </div>
    <?php endif; ?>

    <?php if ($typeRows === []): ?>
        <p class="dashboard-inactive-empty is-muted">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <?= $isTotalFace ? 'Nu există vehicule pentru filtrele selectate.' : 'Nu există vehicule active pentru filtrele selectate.' ?>
        </p>
    <?php else: ?>
        <ul class="dashboard-active-types">
            <?php foreach ($typeRows as $index => $type): ?>
                <?php
                $typeCount = (int) ($type['count'] ?? 0);
                $typeActive = (int) ($type['active'] ?? $typeCount);
                $typeInactive = (int) ($type['inactive'] ?? 0);
                $share = $typeTotal > 0 ? round($typeCount * 100 / $typeTotal, 1) : 0;
                $activeShare = $typeCount > 0 ? round($typeActive * 100 / $typeCount, 1) : 0;
                $platesId = $typeFaceIdPrefix . preg_replace('/[^a-z0-9_]+/i', '_', (string) ($type['key'] ?? $index));
                $typeLabel = (string) ($type['label'] ?? '');
                $typeUnits = (array) ($type['units'] ?? []);
                ?>
                <li class="dashboard-active-type" style="--share: <?= e((string) $share) ?>%; --active-share: <?= e((string) $activeShare) ?>%; --delay: <?= e((string) ($index * 60)) ?>ms">
                    <div class="dashboard-active-type-row">
                    <button class="dashboard-active-type-main" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($platesId) ?>">
                        <span class="dashboard-active-type-icon"><i class="bi <?= e((string) ($type['icon'] ?? 'bi-truck')) ?>" aria-hidden="true"></i></span>
                        <span class="dashboard-active-type-label">
                            <strong><?= e((string) ($type['label'] ?? '')) ?></strong>
                            <span class="dashboard-active-type-bar" aria-hidden="true"><span></span></span>
                        </span>
                    </button>
                    <a class="dashboard-active-type-count" href="<?= e($typeUnitsUrl($typeUnits, $typeLabel . ' · ' . $scopeLabel)) ?>" title="Deschide lista cu aceste <?= e((string) $typeCount) ?> vehicule"><?= e((string) $typeCount) ?></a>
                    <button class="dashboard-active-type-chevron-btn" type="button" data-dashboard-type-toggle aria-expanded="false" aria-controls="<?= e($platesId) ?>" aria-label="Arată numerele de înmatriculare">
                        <i class="bi bi-chevron-down dashboard-active-type-chevron" aria-hidden="true"></i>
                    </button>
                    </div>
                    <?php if ($isTotalFace): ?>
                        <small class="dashboard-type-split">
                            <a class="is-active" href="<?= e($typeUnitsUrl($typeUnits, $typeLabel . ' · active', true)) ?>"><?= e((string) $typeActive) ?> active</a>
                            <?php if ($typeInactive > 0): ?>
                                · <a class="is-inactive" href="<?= e($typeUnitsUrl($typeUnits, $typeLabel . ' · inactive', false)) ?>"><?= e((string) $typeInactive) ?> inactive</a>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                    <?php
                    $typeCapacities = (array) ($type['capacities'] ?? []);
                    $hasCapacityFilter = count($typeCapacities) > 1
                        || ($typeCapacities !== [] && (string) ($typeCapacities[0]['key'] ?? '0') !== '0');
                    $typeListLabel = $typeLabel . ' · ' . $scopeLabel;
                    $typeListUrl = $typeUnitsUrl($typeUnits, $typeListLabel);
                    ?>
                    <div class="dashboard-active-type-plates" id="<?= e($platesId) ?>" hidden>
                        <?php if ($hasCapacityFilter): ?>
                            <div class="dashboard-capacity-bar">
                                <span class="dashboard-capacity-title"><i class="bi bi-box-seam" aria-hidden="true"></i> Capacitate</span>
                                <div class="dashboard-capacity-filters" role="group" aria-label="Filtreaza <?= e($typeLabel) ?> dupa categoria de capacitate">
                                    <button class="dashboard-capacity-filter is-active" type="button" aria-pressed="true"
                                        data-dashboard-capacity-filter="all"
                                        data-list-url="<?= e($typeListUrl) ?>"
                                        data-list-count="<?= e((string) $typeCount) ?>">
                                        Toate <span><?= e((string) $typeCount) ?></span>
                                    </button>
                                    <?php foreach ($typeCapacities as $capacity): ?>
                                        <?php
                                        $capacityKey = (string) ($capacity['key'] ?? '0');
                                        $capacityLabel = (string) ($capacity['label'] ?? '');
                                        $capacityUnits = array_values(array_filter(
                                            $typeUnits,
                                            static fn(array $typeUnit): bool => (string) ($typeUnit['capacity_key'] ?? '0') === $capacityKey
                                        ));
                                        ?>
                                        <button class="dashboard-capacity-filter" type="button" aria-pressed="false"
                                            data-dashboard-capacity-filter="<?= e($capacityKey) ?>"
                                            data-list-url="<?= e($typeUnitsUrl($capacityUnits, $typeListLabel . ' · ' . $capacityLabel)) ?>"
                                            data-list-count="<?= e((string) count($capacityUnits)) ?>">
                                            <?= e($capacityLabel) ?> <span><?= e((string) ((int) ($capacity['count'] ?? 0))) ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="dashboard-plate-grid">
                            <?php foreach ($typeUnits as $typeUnit): ?>
                                <?php
                                $unitIsActive = !empty($typeUnit['active']);
                                $unitCapacityLabel = (string) ($typeUnit['capacity_label'] ?? '');
                                $unitRealCapacity = $typeUnit['real_capacity'] ?? null;
                                $unitTitle = trim(
                                    ($unitIsActive ? '' : 'Inactiv · ')
                                    . $unitCapacityLabel
                                    . ($unitRealCapacity !== null ? ' · capacitate reală ' . format_number_ro((float) $unitRealCapacity, abs($unitRealCapacity - round($unitRealCapacity)) < 0.01 ? 0 : 1) . ' t' : '')
                                );
                                ?>
                                <span class="dashboard-vehicle-assembly<?= $unitIsActive ? '' : ' is-inactive' ?>"
                                    data-capacity="<?= e((string) ($typeUnit['capacity_key'] ?? '0')) ?>"
                                    title="<?= e($unitTitle) ?>">
                                    <?php foreach ((array) ($typeUnit['members'] ?? []) as $memberIndex => $member): ?>
                                        <?php if ($memberIndex > 0): ?><span class="dashboard-vehicle-assembly-plus" aria-hidden="true">+</span><?php endif; ?>
                                        <a class="dashboard-vehicle-pill" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) ($member['id'] ?? 0)])) ?>">
                                            <?php if (!$unitIsActive && $memberIndex === 0): ?>
                                                <i class="bi bi-circle-fill dashboard-plate-inactive-dot" aria-label="Inactiv"></i>
                                            <?php endif; ?>
                                            <strong><?= e((string) ($member['nr_inmatriculare'] ?? '')) ?></strong>
                                        </a>
                                    <?php endforeach; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <a class="dashboard-capacity-list-link" href="<?= e($typeListUrl) ?>" data-dashboard-capacity-list>
                            <span>Deschide în lista de vehicule (<span data-dashboard-capacity-list-count><?= e((string) $typeCount) ?></span>)</span>
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
