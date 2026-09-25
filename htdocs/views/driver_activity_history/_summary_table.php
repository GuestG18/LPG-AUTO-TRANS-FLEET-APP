<?php
declare(strict_types=1);

/**
 * Tabelul "Comparatie pe sofer": un rand per sofer, cu randul desfasurat al curselor.
 * Folosit si la comparatie, si la un singur sofer; datele vin in $comparisonData
 * (rezultatul getComparison), iar formatarile din index.php
 * ($fmtNumber, $fmtMoney, $fmtDuration, $fmtDate, $isDeliveryTrip, $queryBase).
 */

$compareDrivers = (array) ($comparisonData['drivers'] ?? []);
$compareTrips = (array) ($comparisonData['trips'] ?? []);
$compareFuel = (array) ($comparisonData['fuelRows'] ?? []);
$compareDiurne = (array) ($comparisonData['diurneRows'] ?? []);
$periodLabel = $fmtDate($filters['date_start'] ?? null) . ' - ' . $fmtDate($filters['date_end'] ?? null);

$singleDriverUrl = static fn (int $id): string => build_query_url(array_merge($queryBase, ['driver_ids' => [$id]]));

// Cursele fiecarui sofer, pentru randul lui desfasurat din tabelul de comparatie.
// Sunt aceleasi curse ca in tabul "Curse", deja incarcate cu pagina.
$tripsByDriver = [];
foreach ($compareTrips as $compareTrip) {
    $tripsByDriver[(int) ($compareTrip['driver_id_compare'] ?? 0)][] = $compareTrip;
}

/*
 * Coloanele de sumar (Vehicul, Beneficiar): in tabel apare doar numarul, ca in
 * Desfasurator curse ("3 detalii"), iar lista se vede in popover. Valorile intra in
 * data-filter-values, ca filtrul din antet sa ofere vehiculele / beneficiarii, nu
 * numarul: un sofer ramane afisat daca are macar una dintre valorile bifate.
 */
$summaryCounts = static function (array $trips, string $field): array {
    $counts = [];
    foreach ($trips as $trip) {
        $value = trim((string) ($trip[$field] ?? ''));
        if ($value === '' || $value === '-') {
            continue;
        }
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    // Cel mai folosit primul; la egalitate, alfabetic.
    uksort($counts, static fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));

    return $counts;
};
$summaryCell = static function (array $counts, string $label): string {
    $total = count($counts);
    $countLabel = $total === 1 ? '1 detaliu' : $total . ' detalii';
    if ($total === 0) {
        return '<td class="driver-history-summary-cell" data-value="0" data-filter-values="">'
            . '<button type="button" class="dispatcher-summary-count-btn is-empty" disabled aria-label="0 detalii ' . e($label) . '">0 detalii</button></td>';
    }

    $items = [];
    $titleParts = [];
    foreach ($counts as $value => $trips) {
        $tripsLabel = $trips === 1 ? '1 cursa' : $trips . ' curse';
        $items[] = ['l' => (string) $value, 'v' => $tripsLabel];
        $titleParts[] = $value . ': ' . $tripsLabel;
    }

    return '<td class="driver-history-summary-cell" data-value="' . e((string) $total) . '" data-filter-values="' . e(implode('|', array_keys($counts))) . '">'
        . '<div class="dispatcher-summary-list">'
        . '<button type="button" class="dispatcher-summary-count-btn" data-summary-toggle'
        . ' data-summary-label="' . e('Detalii ' . $label) . '"'
        . ' data-summary-items="' . e((string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
        . ' aria-haspopup="dialog" aria-expanded="false"'
        . ' aria-label="' . e('Afiseaza ' . $countLabel . ' ' . $label) . '" title="' . e(implode(' | ', $titleParts)) . '">'
        . '<span>' . e($countLabel) . '</span><i class="bi bi-chevron-down" aria-hidden="true"></i>'
        . '</button></div></td>';
};
$compareTripsUrl = static fn (array $ids, string $label): string => $ids === [] ? '' : build_query_url([
    'page' => 'dispecer_curse',
    'ids' => implode(',', $ids),
    'ids_label' => $label,
]);

// Totaluri. Curse, km, tone, ore, diurne si carburant sunt ale fiecarui sofer;
// reparatiile sunt ale vehiculelor folosite, deci un vehicul condus de doi soferi
// apare la amandoi.
$sum = static fn (string $key): float => array_sum(array_map(static fn (array $row): float => (float) ($row['kpis'][$key] ?? 0), $compareDrivers));
$totals = [
    'total_trips' => (int) $sum('total_trips'),
    'total_km' => $sum('total_km'),
    'total_transported_tons' => $sum('total_transported_tons'),
    'total_delivered_tons' => $sum('total_delivered_tons'),
    'driving_minutes' => (int) $sum('driving_minutes'),
    'diurne' => (int) $sum('diurne'),
    'total_fuel_liters' => $sum('total_fuel_liters'),
    'fuel_cost' => $sum('fuel_cost'),
    'repair_cost' => $sum('repair_cost'),
    'trip_cost' => $sum('trip_cost'),
    'salary_cost' => $sum('salary_cost'),
    'worked_days' => (int) $sum('worked_days'),
    'diurne_value' => $sum('diurne_value_in_total'),
    'total_costs' => $sum('total_costs'),
    'trip_value' => $sum('trip_value'),
    'refacturare_recovered' => $sum('refacturare_recovered'),
    'profit' => $sum('profit'),
];
// Tone pe client: totalul se calculeaza din sume (tone / clienti), nu ca medie
// a rapoartelor soferilor, ca un sofer cu putini clienti sa nu cantareasca la fel.
$totals['clients_total'] = (int) $sum('clients_total');
$totals['delivered_per_client'] = $sum('clients_delivered') > 0 ? $sum('tons_delivered_with_clients') / $sum('clients_delivered') : null;
$perClientLabel = static fn (?float $value): string => $value !== null ? format_number_ro($value, 2) . ' t' : '-';
$perClientTitle = static function (array $k, string $group): string {
    $clients = (int) ($k['clients_' . $group] ?? 0);
    $tons = (float) ($k['tons_' . $group . '_with_clients'] ?? 0);
    $missing = (int) ($k[$group . '_trips_without_clients'] ?? 0);
    $title = $clients > 0 ? format_number_ro($tons, 2) . ' t / ' . $clients . ' clienti' : 'Niciun client inregistrat pe curse';
    if ($missing > 0) {
        $title .= ' · ' . $missing . ' curse fara nr. clienti (neincluse)';
    }

    return ' title="' . e($title) . '"';
};
$perClientWarn = static fn (array $k, string $group): string => (int) ($k[$group . '_trips_without_clients'] ?? 0) > 0
    ? ' <i class="bi bi-exclamation-circle text-warning" aria-hidden="true"></i>'
    : '';
$totals['average_consumption'] = $totals['total_km'] > 0 ? $totals['total_fuel_liters'] / $totals['total_km'] * 100 : null;
$totals['cost_per_km'] = $totals['total_km'] > 0 ? $totals['total_costs'] / $totals['total_km'] : null;
$allTripIds = array_values(array_unique(array_merge(...array_map(static fn (array $row): array => (array) $row['trip_ids'], $compareDrivers ?: [['trip_ids' => []]]))));

// Cel mai bun / cel mai slab consum si cost pe km (mai mic = mai bine).
$extremes = static function (array $values): array {
    $values = array_filter($values, static fn ($value): bool => $value !== null);
    if (count($values) < 2) {
        return [null, null];
    }
    return [array_search(min($values), $values, true), array_search(max($values), $values, true)];
};
[$bestConsumption, $worstConsumption] = $extremes(array_map(static fn (array $row): ?float => ($row['kpis']['average_consumption'] ?? null) !== null ? (float) $row['kpis']['average_consumption'] : null, $compareDrivers));
[$bestCostKm, $worstCostKm] = $extremes(array_map(static fn (array $row): ?float => $row['cost_per_km'], $compareDrivers));
// Diurna in lei, dupa regula soferului din Contabilitate Personal.
$diurnaPolicyLabel = static fn (array $k): string => DriverDiurnaModel::label(
    (array) ($k['diurna_policy'] ?? []) + ['status' => DriverDiurnaModel::STATUS_UNSET, 'rate' => null]
);
$diurnaValueLabel = static function (array $k) use ($fmtMoney): string {
    if ((string) ($k['diurna_policy']['status'] ?? '') === DriverDiurnaModel::STATUS_NONE && (float) ($k['diurne_value'] ?? 0) <= 0) {
        return 'fara diurna';
    }
    if ((float) ($k['diurne_value'] ?? 0) <= 0 && (int) ($k['diurne_unvalued'] ?? 0) > 0) {
        return 'nesetat';
    }

    // Partea inclusa in costul total (fara diurna trecuta deja ca cheltuiala pe
    // cursa), ca sa se adune coloanele de cost la "Cost total".
    return $fmtMoney($k['diurne_value_in_total'] ?? 0);
};
// La un singur sofer randul lui e deja deschis: pagina arata direct cursele.
$expandTripsByDefault = count($compareDrivers) === 1;
$rankClass = static fn (int $index, $best, $worst): string => $index === $best ? ' is-best' : ($index === $worst ? ' is-worst' : '');
?>

<section class="driver-history-panel driver-history-compare-panel">
    <div class="driver-history-compare-head">
        <?php /* Cu un singur sofer nu e o comparatie, iar "cel mai bun / cel mai slab" nu are sens. */ ?>
        <h2><?= count($compareDrivers) > 1 ? 'Comparatie pe sofer' : 'Sumar activitate' ?></h2>
        <?php if (count($compareDrivers) > 1): ?>
            <span class="driver-history-compare-legend">
                <span class="is-best">cel mai bun</span>
                <span class="is-worst">cel mai slab</span>
                <?= $canFinancial ? 'consum si cost / km' : 'consum' ?>
            </span>
        <?php endif; ?>
        <div data-column-manager-slot="driver-compare"></div>
    </div>
    <div class="driver-history-table-wrap is-compact">
        <table class="table driver-history-table driver-history-compare-table mb-0" data-column-filter data-column-manager="driver-compare">
            <thead>
                <tr>
                    <th>Sofer</th><th>Curse</th><th title="Vehiculele conduse in perioada; filtrul din antet lasa doar soferii care au condus vehiculul ales">Vehicul</th><th title="Beneficiarii curselor din perioada; filtrul din antet lasa doar soferii care au lucrat pentru beneficiarul ales">Beneficiar</th><th>Zile lucrate</th><th>Km</th><th title="Primar si Compresor">Tone transportate</th><th title="Distributie si Primar + Distributie">Tone livrate</th><th title="Clientii curselor de Distributie si Primar + Distributie">Nr. clienti</th><th title="Tone livrate / clienti, doar din cursele cu numarul de clienti completat">T livr. / client</th><th>Ore condus</th><th>Diurne</th><?php if ($canFinancial): ?><th>Diurne (lei)</th><?php endif; ?>
                    <th>Motorina</th><th>L/100 km</th><th>Cost carburant</th><th>Cost reparatii</th><th title="Toate cheltuielile inregistrate pe curse, inclusiv cele de refacturat">Cost curse</th>
                    <?php if ($canFinancial): ?>
                    <th>Salariu</th><th>Cost total</th><th>Cost / km</th>
                    <th>Valoare curse</th><th title="Refacturari trecute in Refacturat (bani recuperati)">Refacturat</th><th>Profit curse</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($compareDrivers as $index => $row): ?>
                <?php
                $k = (array) $row['kpis'];
                $driverTripsUrl = $compareTripsUrl((array) $row['trip_ids'], 'cursele lui ' . $row['nume'] . ', ' . $periodLabel);
                // Valorile brute pentru sortare si pentru totalul recalculat la filtrare.
                $dv = static fn ($value): string => ' data-value="' . e((string) round((float) $value, 4)) . '"';
                $ratio = static fn ($num, $den): string => ' data-num="' . e((string) round((float) $num, 4)) . '" data-den="' . e((string) round((float) $den, 4)) . '"';
                $totalKm = (float) ($k['total_km'] ?? 0);
                $driverTrips = (array) ($tripsByDriver[(int) $row['id']] ?? []);
                $tripsDetailId = 'driver-trips-' . (int) $row['id'];
                ?>
                <tr<?= $driverTrips !== [] ? ' class="driver-history-trips-parent' . ($expandTripsByDefault ? ' is-expanded' : '') . '" data-trips-row' : '' ?>>
                    <td>
                        <?php if ($driverTrips !== []): ?>
                            <button class="driver-history-trips-toggle" type="button" aria-expanded="<?= $expandTripsByDefault ? 'true' : 'false' ?>" aria-controls="<?= e($tripsDetailId) ?>" aria-label="Cursele lui <?= e((string) $row['nume']) ?>" title="Cursele soferului" data-trips-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                        <?php endif; ?>
                        <a href="<?= e($singleDriverUrl((int) $row['id'])) ?>" title="Deschide istoricul soferului"><strong><?= e((string) $row['nume']) ?></strong></a>
                        <?php if (strtolower((string) $row['status']) !== 'activ'): ?><small class="text-muted">inactiv</small><?php endif; ?>
                    </td>
                    <td<?= $dv($k['total_trips'] ?? 0) ?> data-trip-ids="<?= e(implode(',', (array) $row['trip_ids'])) ?>"><?= $driverTripsUrl !== '' ? '<a href="' . e($driverTripsUrl) . '" title="Vezi in Desfasurator curse">' . e((string) (int) ($k['total_trips'] ?? 0)) . '</a>' : '0' ?></td>
                    <?= $summaryCell($summaryCounts($driverTrips, 'nr_inmatriculare'), 'vehicule') ?>
                    <?= $summaryCell($summaryCounts($driverTrips, 'beneficiary_label'), 'beneficiari') ?>
                    <td<?= $dv($k['worked_days'] ?? 0) ?>><?= e((string) (int) ($k['worked_days'] ?? 0)) ?></td>
                    <td<?= $dv($totalKm) ?>><?= e($fmtNumber($totalKm, 0)) ?></td>
                    <td<?= $dv($k['total_transported_tons'] ?? 0) ?>><?= e($fmtNumber($k['total_transported_tons'] ?? 0, 2)) ?> t</td>
                    <td<?= $dv($k['total_delivered_tons'] ?? 0) ?>><?= e($fmtNumber($k['total_delivered_tons'] ?? 0, 2)) ?> t</td>
                    <td<?= $dv($k['clients_total'] ?? 0) ?>><?= e((string) (int) ($k['clients_total'] ?? 0)) ?></td>
                    <td<?= $perClientTitle($k, 'delivered') ?><?= $ratio($k['tons_delivered_with_clients'] ?? 0, $k['clients_delivered'] ?? 0) ?><?= ($k['delivered_per_client'] ?? null) !== null ? $dv($k['delivered_per_client']) : '' ?>><?= e($perClientLabel($k['delivered_per_client'] ?? null)) ?><?= $perClientWarn($k, 'delivered') ?></td>
                    <td<?= $dv($k['driving_minutes'] ?? 0) ?>><?= e($fmtDuration($k['driving_minutes'] ?? 0)) ?></td>
                    <td<?= $dv($k['diurne'] ?? 0) ?><?= (int) ($k['diurne_missing'] ?? 0) > 0 ? ' title="' . e((int) $k['diurne_missing'] . ' curse fara data/ora completa') . '"' : '' ?>><?= e((string) (int) ($k['diurne'] ?? 0)) ?><?= (int) ($k['diurne_missing'] ?? 0) > 0 ? ' <i class="bi bi-exclamation-circle text-warning" aria-hidden="true"></i>' : '' ?></td>
                    <?php if ($canFinancial): ?><td<?= $dv($k['diurne_value_in_total'] ?? 0) ?> title="<?= e($diurnaPolicyLabel($k)) ?>"><?= e($diurnaValueLabel($k)) ?></td><?php endif; ?>
                    <td<?= $dv($k['total_fuel_liters'] ?? 0) ?>><?= e($fmtNumber($k['total_fuel_liters'] ?? 0, 0)) ?> L</td>
                    <td class="driver-history-rank<?= $rankClass($index, $bestConsumption, $worstConsumption) ?>"<?= $ratio((float) ($k['total_fuel_liters'] ?? 0) * 100, $totalKm) ?><?= ($k['average_consumption'] ?? null) !== null ? $dv($k['average_consumption']) : '' ?>><?= ($k['average_consumption'] ?? null) !== null ? e($fmtNumber($k['average_consumption'], 2)) : '-' ?></td>
                    <td<?= $dv($k['fuel_cost'] ?? 0) ?>><?= e($fmtMoney($k['fuel_cost'] ?? 0)) ?></td>
                    <td<?= $dv($k['repair_cost'] ?? 0) ?>><?= e($fmtMoney($k['repair_cost'] ?? 0)) ?></td>
                    <td<?= $dv($k['trip_cost'] ?? 0) ?>><?= e($fmtMoney($k['trip_cost'] ?? 0)) ?></td>
                    <?php if ($canFinancial): ?>
                    <td<?= $dv($k['salary_cost'] ?? 0) ?><?= !empty($k['salary_missing']) ? ' title="Salariul nu este completat in Contabilitate Personal"' : '' ?>><?= e($fmtMoney($k['salary_cost'] ?? 0)) ?><?= !empty($k['salary_missing']) ? ' <i class="bi bi-exclamation-circle text-warning" aria-hidden="true"></i>' : '' ?></td>
                    <td<?= $dv($k['total_costs'] ?? 0) ?>><strong><?= e($fmtMoney($k['total_costs'] ?? 0)) ?></strong></td>
                    <td class="driver-history-rank<?= $rankClass($index, $bestCostKm, $worstCostKm) ?>"<?= $ratio($k['total_costs'] ?? 0, $totalKm) ?><?= $row['cost_per_km'] !== null ? $dv($row['cost_per_km']) : '' ?>><?= $row['cost_per_km'] !== null ? e($fmtMoney($row['cost_per_km'])) : '-' ?></td>
                    <td<?= $dv($k['trip_value'] ?? 0) ?>><?= e($fmtMoney($k['trip_value'] ?? 0)) ?></td>
                    <td<?= $dv($k['refacturare_recovered'] ?? 0) ?>><?= e($fmtMoney($k['refacturare_recovered'] ?? 0)) ?></td>
                    <td<?= $dv($k['profit'] ?? 0) ?> class="<?= (float) ($k['profit'] ?? 0) < 0 ? 'text-danger' : 'text-success' ?>"><strong><?= e($fmtMoney($k['profit'] ?? 0)) ?></strong></td>
                    <?php endif; ?>
                </tr>
                <?php if ($driverTrips !== []): ?>
                    <?php
                    // Totalul curselor desfasurate, pe aceleasi coloane ca randurile.
                    $tripsTotals = ['km' => 0.0, 'transported' => 0.0, 'delivered' => 0.0, 'minutes' => 0, 'diurne' => 0, 'value' => 0.0, 'cost' => 0.0];
                    foreach ($driverTrips as $trip) {
                        $tripsTotals['km'] += (float) ($trip['effective_km'] ?? 0);
                        $tripsTotals[$isDeliveryTrip($trip) ? 'delivered' : 'transported'] += (float) ($isDeliveryTrip($trip) ? ($trip['delivered_tons'] ?? 0) : ($trip['transported_tons'] ?? 0));
                        $tripsTotals['minutes'] += (int) ($trip['duration_minutes_effective'] ?? 0);
                        $tripsTotals['diurne'] += (int) ($trip['diurne'] ?? 0);
                        $tripsTotals['value'] += (float) ($trip['total_facturare'] ?? 0);
                        $tripsTotals['cost'] += (float) ($trip['total_cheltuieli'] ?? 0);
                    }
                    ?>
                    <tr class="driver-history-trips-row" id="<?= e($tripsDetailId) ?>" data-trips-detail<?= $expandTripsByDefault ? '' : ' hidden' ?>>
                        <td colspan="99">
                            <div class="driver-history-trips">
                                <div class="driver-history-trips-wrap">
                                    <table class="driver-history-trips-table">
                                        <thead>
                                            <tr>
                                                <th>Vehicul</th><th>Tip transport</th><th>Beneficiar</th>
                                                <th class="is-number">Km efectuati</th><th class="is-number">Tone transportate</th><th class="is-number">Tone livrate</th>
                                                <th class="is-number">Durata cursa</th><th class="is-number">Diurne</th>
                                                <?php if ($canFinancial): ?><th class="is-number">Valoare cursa</th><?php endif; ?>
                                                <th class="is-number" title="Toate cheltuielile inregistrate pe cursa, inclusiv cele de refacturat">Cost cursa</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($driverTrips as $trip): ?>
                                            <tr>
                                                <td><?= e((string) ($trip['nr_inmatriculare'] ?? '-')) ?></td>
                                                <td><?= e((string) ($trip['transport_label'] ?? '-')) ?></td>
                                                <td><?= e((string) ($trip['beneficiary_label'] ?? '-')) ?></td>
                                                <td class="is-number"><?= e($fmtNumber($trip['effective_km'] ?? 0, 0)) ?></td>
                                                <td class="is-number"><?= $isDeliveryTrip($trip) ? '-' : e($fmtNumber($trip['transported_tons'] ?? 0, 2)) ?></td>
                                                <td class="is-number"><?= $isDeliveryTrip($trip) ? e($fmtNumber($trip['delivered_tons'] ?? 0, 2)) : '-' ?></td>
                                                <td class="is-number"><?= e($fmtDuration($trip['duration_minutes_effective'] ?? 0)) ?></td>
                                                <td class="is-number"><?= ($trip['diurne'] ?? null) === null ? '-' : e((string) (int) $trip['diurne']) ?></td>
                                                <?php if ($canFinancial): ?><td class="is-number"><?= e($fmtMoney($trip['total_facturare'] ?? 0)) ?></td><?php endif; ?>
                                                <td class="is-number"><?= e($fmtMoney($trip['total_cheltuieli'] ?? 0)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th>TOTAL</th>
                                                <th colspan="2"><?= e((string) count($driverTrips)) ?> <?= count($driverTrips) === 1 ? 'cursa' : 'curse' ?></th>
                                                <th class="is-number"><?= e($fmtNumber($tripsTotals['km'], 0)) ?></th>
                                                <th class="is-number"><?= e($fmtNumber($tripsTotals['transported'], 2)) ?> t</th>
                                                <th class="is-number"><?= e($fmtNumber($tripsTotals['delivered'], 2)) ?> t</th>
                                                <th class="is-number"><?= e($fmtDuration($tripsTotals['minutes'])) ?></th>
                                                <th class="is-number"><?= e((string) $tripsTotals['diurne']) ?></th>
                                                <?php if ($canFinancial): ?><th class="is-number"><?= e($fmtMoney($tripsTotals['value'])) ?></th><?php endif; ?>
                                                <th class="is-number"><?= e($fmtMoney($tripsTotals['cost'])) ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <?php /* La un singur sofer randul "Total" ar repeta exact randul lui, deci nu are rost. */ ?>
            <?php if (count($compareDrivers) > 1): ?>
            <tfoot>
                <?php $allTripsUrl = $compareTripsUrl($allTripIds, 'cursele soferilor comparati, ' . $periodLabel); ?>
                <tr>
                    <?php /* data-total: totalul se recalculeaza din randurile ramase vizibile dupa filtrare. */ ?>
                    <th data-total-label>Total</th>
                    <th data-total="sum" data-format="int"><?= $allTripsUrl !== '' ? '<a href="' . e($allTripsUrl) . '" title="Vezi in Desfasurator curse">' . e((string) $totals['total_trips']) . '</a>' : '0' ?></th>
                    <?php
                    // Totalul coloanelor de sumar: cate vehicule / cati beneficiari distincti,
                    // nu suma pe soferi (acelasi vehicul poate fi condus de mai multi).
                    $distinctCount = static function (string $field) use ($compareTrips): int {
                        $values = [];
                        foreach ($compareTrips as $trip) {
                            $value = trim((string) ($trip[$field] ?? ''));
                            if ($value !== '' && $value !== '-') {
                                $values[$value] = true;
                            }
                        }

                        return count($values);
                    };
                    ?>
                    <th data-total="distinct" data-format="int"><?= e((string) $distinctCount('nr_inmatriculare')) ?></th>
                    <th data-total="distinct" data-format="int"><?= e((string) $distinctCount('beneficiary_label')) ?></th>
                    <th data-total="sum" data-format="int"><?= e((string) $totals['worked_days']) ?></th>
                    <th data-total="sum" data-format="num0"><?= e($fmtNumber($totals['total_km'], 0)) ?></th>
                    <th data-total="sum" data-format="t"><?= e($fmtNumber($totals['total_transported_tons'], 2)) ?> t</th>
                    <th data-total="sum" data-format="t"><?= e($fmtNumber($totals['total_delivered_tons'], 2)) ?> t</th>
                    <th data-total="sum" data-format="int"><?= e((string) $totals['clients_total']) ?></th>
                    <th data-total="ratio" data-format="t"><?= e($perClientLabel($totals['delivered_per_client'])) ?></th>
                    <th data-total="sum" data-format="duration"><?= e($fmtDuration($totals['driving_minutes'])) ?></th>
                    <th data-total="sum" data-format="int"><?= e((string) $totals['diurne']) ?></th>
                    <?php if ($canFinancial): ?><th data-total="sum" data-format="money"><?= e($fmtMoney($totals['diurne_value'])) ?></th><?php endif; ?>
                    <th data-total="sum" data-format="L"><?= e($fmtNumber($totals['total_fuel_liters'], 0)) ?> L</th>
                    <th data-total="ratio" data-format="num2"><?= $totals['average_consumption'] !== null ? e($fmtNumber($totals['average_consumption'], 2)) : '-' ?></th>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['fuel_cost'])) ?></th>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['repair_cost'])) ?></th>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['trip_cost'])) ?></th>
                    <?php if ($canFinancial): ?>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['salary_cost'])) ?></th>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['total_costs'])) ?></th>
                    <th data-total="ratio" data-format="money"><?= $totals['cost_per_km'] !== null ? e($fmtMoney($totals['cost_per_km'])) : '-' ?></th>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['trip_value'])) ?></th>
                    <th data-total="sum" data-format="money"><?= e($fmtMoney($totals['refacturare_recovered'])) ?></th>
                    <th data-total="sum" data-format="money" data-signed class="<?= $totals['profit'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e($fmtMoney($totals['profit'])) ?></th>
                    <?php endif; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <p class="driver-history-compare-note">
        Reparatiile sunt ale vehiculelor folosite de fiecare sofer: un vehicul condus de mai multi soferi apare la fiecare dintre ei.
        Numele soferului deschide istoricul lui individual; numarul de curse le deschide in Desfasurator curse.
        Cost curse cuprinde toate cheltuielile inregistrate pe curse, inclusiv cele de refacturat<?php if ($canFinancial): ?>; cand o refacturare
        trece in „Refacturat” suma apare la Refacturat si intra in Profit curse (valoare curse + refacturat - cost total)<?php endif; ?>.
        Clientii si tonele livrate pe client vin doar din cursele de Distributie si Primar + Distributie (Primar si Compresor
        nu au clienti): tonele livrate / clientii curselor cu numarul de clienti completat; cursele fara nr. clienti sunt semnalate cu
        <i class="bi bi-exclamation-circle text-warning" aria-hidden="true"></i> si nu intra in raport.
    </p>
</section>
