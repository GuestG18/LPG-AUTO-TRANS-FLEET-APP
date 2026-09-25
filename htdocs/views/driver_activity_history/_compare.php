<?php
declare(strict_types=1);

/**
 * Istoric Activitati Sofer - modul comparatie (2+ soferi alesi in filtru).
 * Primeste din index.php: $dashboard (rezultatul getComparison), $filters,
 * $queryBase si functiile de formatare ($fmtNumber, $fmtMoney, $fmtDuration, $fmtDate).
 */

$comparisonData = $dashboard;
?>

<?php
/*
 * Doua moduri de afisare, ca in Istoric activitate: "Sumar soferi" (tabelul comparativ
 * si graficele) sau "Curse" - lista tuturor curselor soferilor alesi, cu sortare si
 * filtrare din antet. Alegerea se pastreaza in browser.
 */
?>
<div class="driver-history-compare-views" data-compare-view="summary">
    <div class="driver-history-view-switch" role="group" aria-label="Mod de afisare">
        <button type="button" data-compare-view-button="summary" aria-pressed="true">Sumar soferi</button>
        <button type="button" data-compare-view-button="trips" aria-pressed="false">Curse (<?= e((string) count((array) ($dashboard['trips'] ?? []))) ?>)</button>
    </div>

<?php /* Cardurile KPI ale selectiei (totalurile tuturor soferilor alesi), ca la un singur sofer. */ ?>
<section class="driver-history-kpi-shell is-compare">
    <?php include __DIR__ . '/_kpi_cards.php'; ?>
</section>

<?php include __DIR__ . '/_summary_table.php'; ?>


<section class="driver-history-chart-grid">
    <article class="driver-history-panel">
        <h2>Kilometri pe sofer</h2>
        <div class="driver-history-chart-wrap" data-chart-wrapper>
            <canvas id="driver_compare_km_chart"></canvas>
            <div class="driver-history-chart-empty">Nu exista date.</div>
        </div>
    </article>
    <article class="driver-history-panel">
        <h2>Evolutie kilometri</h2>
        <div class="driver-history-chart-wrap" data-chart-wrapper>
            <canvas id="driver_compare_timeline_chart"></canvas>
            <div class="driver-history-chart-empty">Nu exista date.</div>
        </div>
    </article>
    <article class="driver-history-panel">
        <h2>Consum mediu (L/100 km)</h2>
        <div class="driver-history-chart-wrap" data-chart-wrapper>
            <canvas id="driver_compare_consumption_chart"></canvas>
            <div class="driver-history-chart-empty">Nu exista date.</div>
        </div>
    </article>
    <article class="driver-history-panel">
        <h2>Costuri pe sofer</h2>
        <div class="driver-history-chart-wrap" data-chart-wrapper>
            <canvas id="driver_compare_cost_chart"></canvas>
            <div class="driver-history-chart-empty">Nu exista date.</div>
        </div>
    </article>
</section>

<section class="driver-history-tabs">
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#driver-compare-trips" type="button" role="tab">Curse (<?= e((string) count($compareTrips)) ?>)</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-compare-diurne" type="button" role="tab">Diurne</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-compare-fuel" type="button" role="tab">Alimentari (<?= e((string) count($compareFuel)) ?>)</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="driver-compare-trips" role="tabpanel">
            <div class="driver-history-table-wrap">
                <table class="table driver-history-table mb-0" data-column-filter>
                    <thead><tr><th>Sofer</th><th>Data</th><th>Beneficiar</th><th>Vehicul</th><th>Tip transport</th><th>Total KM</th><th>Tone transportate</th><th>Tone livrate</th><th>Durata cursa</th><th>Diurne</th><?php if ($canFinancial): ?><th>Valoare cursa</th><?php endif; ?><th title="Toate cheltuielile inregistrate pe cursa, inclusiv cele de refacturat">Cost cursa</th><?php if ($canFinancial): ?><th title="Refacturari trecute in Refacturat (bani recuperati)">Refacturat</th><?php endif; ?><th data-no-filter>Actiuni</th></tr></thead>
                    <tbody>
                    <?php
                    // Randul intreg deschide formularul cursei in Dispecer curse (ca iconita "Cursa").
                    $canOpenTrip = can_route('dispecer_curse');
                    ?>
                    <?php foreach ($compareTrips as $row): ?>
                        <tr<?= $canOpenTrip ? ' class="driver-history-row-link" data-row-href="' . e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) . '" tabindex="0" title="Deschide cursa in Dispecer curse"' : '' ?>>
                            <td><strong><?= e((string) $row['driver_name_compare']) ?></strong></td>
                            <td><?= e($fmtDate($row['data_inceput'] ?? null)) ?></td>
                            <td><?= e((string) ($row['beneficiary_label'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                            <td><?= e($fmtNumber($row['effective_km'] ?? 0, 0)) ?></td>
                            <td><?= $isDeliveryTrip($row) ? '-' : e($fmtNumber($row['transported_tons'] ?? 0, 2)) ?></td>
                            <td><?= $isDeliveryTrip($row) ? e($fmtNumber($row['delivered_tons'] ?? 0, 2)) : '-' ?></td>
                            <td><?= e($fmtDuration($row['duration_minutes_effective'] ?? 0)) ?></td>
                            <td><?= ($row['diurne'] ?? null) === null ? '-' : e((string) (int) $row['diurne']) ?></td>
                            <?php if ($canFinancial): ?><td><?= e($fmtMoney($row['total_facturare'] ?? 0)) ?></td><?php endif; ?>
                            <td title="<?= e('Platite: ' . $fmtMoney((float) ($row['total_cheltuieli'] ?? 0) - (float) ($row['total_refacturare'] ?? 0)) . ' · de refacturat: ' . $fmtMoney($row['total_refacturare'] ?? 0)) ?>"><?= e($fmtMoney($row['total_cheltuieli'] ?? 0)) ?></td>
                            <?php if ($canFinancial): ?><td><?= e($fmtMoney($row['total_refacturare_facturata'] ?? 0)) ?></td><?php endif; ?>
                            <td>
                                <div class="driver-history-row-actions">
                                    <a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) ?>" title="Cursa"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($compareTrips === []): ?><tr><td colspan="<?= $canFinancial ? 14 : 12 ?>" class="text-center text-muted py-4">Nu exista curse pentru filtrele selectate.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="driver-compare-diurne" role="tabpanel">
            <p class="text-muted small mb-2">
                Regula din Dispecer curse: sub 12h = 0 diurne, 12h–35:59 = 1, apoi inca una la fiecare 24h;
                la cursele reluate cu alt sofer, diurnele se impart dupa timpul condus.
            </p>
            <div class="driver-history-table-wrap">
                <table class="table driver-history-table mb-0" data-column-filter>
                    <thead><tr><th>Sofer</th><th>Inceput</th><th>Sfarsit</th><th>Vehicul</th><th>Durata</th><th>Diurne cursa</th><th>Diurne sofer</th><th data-no-filter>Actiuni</th></tr></thead>
                    <tbody>
                    <?php foreach ($compareDiurne as $row): ?>
                        <?php $moment = static fn ($date, $time): string => ($date ? $fmtDate($date) : '-') . ($time ? ' ' . substr((string) $time, 0, 5) : ''); ?>
                        <tr>
                            <td><strong><?= e((string) $row['driver_name_compare']) ?></strong></td>
                            <td><?= e($moment($row['data_inceput'], $row['ora_inceput'])) ?></td>
                            <td><?= e($moment($row['data_sfarsit'], $row['ora_sfarsit'])) ?></td>
                            <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                            <td><?= $row['minutes'] !== null ? e($fmtDuration($row['minutes'])) : '-' ?></td>
                            <td><?= $row['trip_diurne'] !== null ? e((string) $row['trip_diurne']) : '-' ?></td>
                            <td><strong><?= $row['diurne'] !== null ? e((string) $row['diurne']) : '-' ?></strong></td>
                            <td>
                                <div class="driver-history-row-actions">
                                    <a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) ?>" title="Cursa"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($compareDiurne === []): ?><tr><td colspan="8" class="text-center text-muted py-4">Nu exista curse pentru filtrele selectate.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="driver-compare-fuel" role="tabpanel">
            <div class="driver-history-table-wrap">
                <table class="table driver-history-table mb-0" data-column-filter>
                    <thead><tr><th>Sofer</th><th>Data</th><th>Vehicul</th><th>Card</th><th>Litri</th><th>Pret</th><th>Cost</th><th>Kilometraj</th><th>Consum calculat</th><th>Statie</th></tr></thead>
                    <tbody>
                    <?php foreach ($compareFuel as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['driver_name_compare']) ?></strong></td>
                            <td><?= e(format_datetime_ro((string) ($row['fillup_datetime'] ?? ''))) ?></td>
                            <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                            <td><?= e((string) (($row['record_sofer_nume'] ?? '') !== '' ? $row['record_sofer_nume'] : '-')) ?></td>
                            <td><?= e($fmtNumber($row['litri'] ?? 0, 2)) ?> L</td>
                            <td><?= ($row['pret_litru_calculat'] ?? null) !== null ? e($fmtMoney($row['pret_litru_calculat'])) : '-' ?></td>
                            <td><?= e($fmtMoney($row['cost_total'] ?? 0)) ?></td>
                            <td><?= e($fmtNumber($row['km_bord'] ?? 0, 0)) ?></td>
                            <td><?= ($row['calculated_consumption'] ?? null) !== null ? e($fmtNumber($row['calculated_consumption'], 2)) . ' L/100km' : '-' ?></td>
                            <td><?= e((string) (($row['observatii'] ?? '') !== '' ? $row['observatii'] : '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($compareFuel === []): ?><tr><td colspan="10" class="text-center text-muted py-4">Nu exista alimentari pentru soferii selectati in perioada.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
</div>
