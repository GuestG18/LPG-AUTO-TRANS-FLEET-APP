<?php
/**
 * Fisa cursei, doar citire (?page=dispecer_curse&action=view&id=N).
 * Nu incarca cursa in formular: arata datele salvate, fazele si cheltuielile.
 */
$raceId = (int) ($race['id'] ?? 0);
$dispCanEdit = !function_exists('can') || can('dispecer_curse', 'edit');

$viewText = static function (mixed $value): string {
    $text = trim((string) ($value ?? ''));
    return $text;
};
$viewNumber = static function (mixed $value, int $decimals = 2, string $unit = ''): string {
    if ($value === null || $value === '' || (float) $value == 0.0) {
        return '';
    }
    return format_number_ro((float) $value, $decimals) . ($unit !== '' ? ' ' . $unit : '');
};
$viewDateTime = static function (mixed $date, mixed $time = null): string {
    $date = trim((string) ($date ?? ''));
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $out = format_date_ro($date);
    $time = trim((string) ($time ?? ''));
    if ($time !== '') {
        $out .= ' ' . substr($time, 0, 5);
    }
    return $out;
};

$durationMinutes = (int) ($race['durata_cursa_minute'] ?? 0);
$durationText = $durationMinutes > 0
    ? intdiv($durationMinutes, 60) . 'h ' . ($durationMinutes % 60) . 'm'
    : '';

$goodsLabels = [];
foreach ((array) ($goodsSelected ?? []) as $goodsKey) {
    $goodsLabels[] = (string) ($goodsTypeOptions[$goodsKey] ?? $goodsKey);
}

$vehicleText = trim((string) ($race['nr_inmatriculare'] ?? ''));
$vehicleModel = trim(((string) ($race['marca'] ?? '')) . ' ' . ((string) ($race['model'] ?? '')));
if ($vehicleModel !== '') {
    $vehicleText .= ($vehicleText !== '' ? ' — ' : '') . $vehicleModel;
}

$billingKey = (string) ($race['status_facturare'] ?? '');
$billingBadge = [
    'facturat' => 'bg-success-subtle text-success-emphasis',
    'nefacturat' => 'bg-danger-subtle text-danger-emphasis',
    'in_curs_facturare' => 'bg-warning-subtle text-warning-emphasis',
][$billingKey] ?? 'bg-secondary-subtle text-secondary-emphasis';

$sections = [
    'Date generale' => [
        'Tip transport' => (string) ($transportTypes[(string) ($race['tip_transport'] ?? '')] ?? ($race['tip_transport'] ?? '')),
        'Beneficiar' => $viewText($race['beneficiar_nume'] ?? ''),
        'Vehicul' => $vehicleText,
        'Șofer' => $viewText($race['sofer_nume'] ?? ''),
        'Tip marfă' => implode(', ', $goodsLabels),
        'Capacitate transport' => $viewNumber($race['capacitate_transport'] ?? null, 2, 't'),
    ],
    'Interval' => [
        'Data cursei' => $viewDateTime($race['data_cursa'] ?? ''),
        'Data încărcării' => $viewDateTime($race['data_incarcare'] ?? ''),
        'Început' => $viewDateTime($race['data_inceput'] ?? '', $race['ora_inceput'] ?? ''),
        'Sfârșit' => $viewDateTime($race['data_sfarsit'] ?? '', $race['ora_sfarsit'] ?? ''),
        'Durată' => $durationText,
    ],
    'Traseu' => [
        'Loc plecare' => $viewText($race['loc_plecare'] ?? ''),
        'Loc încărcare' => $viewText($race['loc_incarcare_nume'] ?? ''),
        'Loc aspirare' => $viewText($race['loc_aspirare'] ?? ''),
        'Loc livrare' => $viewText($race['loc_livrare'] ?? ''),
        'Loc livrare cursă' => $viewText($race['loc_livrare_cursa'] ?? ''),
        'Zonă distribuție' => $viewText($race['zona_distributie_nume'] ?? ''),
        'Loc întoarcere' => $viewText($race['loc_intoarcere'] ?? ''),
    ],
    'Activitate' => [
        'Cantitate încărcată' => $viewNumber($race['cantitate_incarcata'] ?? null, 2, 't'),
        'Cantitate prelevată' => $viewNumber($race['cantitate_prelevata'] ?? null, 2, 't'),
        'Tone livrate' => $viewNumber($race['tona_livrata'] ?? null, 2, 't'),
        'Tone aspirate lichid' => $viewNumber($race['tona_aspirata_lichida'] ?? null, 2, 't'),
        'Tone aspirate gazos' => $viewNumber($race['tona_aspirata_gazoasa'] ?? null, 2, 't'),
        'Nr. clienți' => (int) ($race['nr_clienti'] ?? 0) > 0 ? (string) (int) $race['nr_clienti'] : '',
        'Km cursă' => $viewNumber($race['km_cursa'] ?? null, 0, 'km'),
        'Km totali' => $viewNumber($race['km_totali'] ?? null, 0, 'km'),
        'Km dislocare' => $viewNumber($race['km_dislocare'] ?? null, 2, 'km'),
        'Ore funcționare' => $viewNumber($race['ore_functionare'] ?? null, 2, 'h'),
        'Ore aspirare' => $viewNumber($race['ore_aspirare'] ?? null, 2, 'h'),
    ],
    'Financiar' => [
        'Preț tarifare' => $viewNumber($race['pret_tarifare'] ?? null, 2, 'lei'),
        'Total facturare' => $viewNumber($race['total_facturare'] ?? null, 2, 'lei'),
        'Cost/km Primar' => $viewNumber($race['cost_km_primar'] ?? null, 2, 'lei'),
        'Cost/km Distribuție' => $viewNumber($race['cost_km_distributie'] ?? null, 2, 'lei'),
        'Cost/km Mixt' => $viewNumber($race['cost_km_mixt'] ?? null, 2, 'lei'),
        'Cost/km Compresor' => $viewNumber($race['cost_km_compresor'] ?? null, 2, 'lei'),
        'Total cheltuieli' => $viewNumber($race['total_cheltuieli'] ?? null, 2, 'lei'),
        'Refacturare facturată' => $viewNumber($race['total_refacturare_facturata'] ?? null, 2, 'lei'),
        'Refacturare nefacturată' => $viewNumber($race['total_refacturare_pending'] ?? null, 2, 'lei'),
    ],
];
$observatii = trim((string) ($race['observatii'] ?? ''));
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h2 class="h4 mb-0">Cursa #<?= e((string) $raceId) ?></h2>
        <span class="badge rounded-pill <?= e($billingBadge) ?>"><?= e((string) ($billingStatuses[$billingKey] ?? $billingKey)) ?></span>
        <span class="badge rounded-pill bg-light text-secondary border"><i class="bi bi-eye me-1" aria-hidden="true"></i>doar vizualizare</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($dispCanEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Editează
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Înapoi la listă</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($sections as $sectionTitle => $fields): ?>
        <?php $fields = array_filter($fields, static fn ($value): bool => trim((string) $value) !== ''); ?>
        <?php if ($fields === []) { continue; } ?>
        <div class="col-12 col-lg-6 col-xxl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><?= e($sectionTitle) ?></div>
                <div class="card-body py-2">
                    <dl class="row mb-0 small">
                        <?php foreach ($fields as $label => $value): ?>
                            <dt class="col-5 text-muted fw-normal py-1"><?= e($label) ?></dt>
                            <dd class="col-7 mb-0 py-1 fw-semibold"><?= e((string) $value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="col-12 col-lg-6 col-xxl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Observații</div>
            <div class="card-body small">
                <?php if ($observatii !== ''): ?>
                    <?= nl2br(e($observatii)) ?>
                <?php else: ?>
                    <span class="text-muted">Fără observații.</span>
                <?php endif; ?>
                <hr class="my-2">
                <div class="text-muted">
                    Creată<?= trim((string) ($race['creat_de_nume'] ?? '')) !== '' ? ' de ' . e((string) $race['creat_de_nume']) : '' ?>
                    <?= e($viewDateTime(substr((string) ($race['created_at'] ?? ''), 0, 10), substr((string) ($race['created_at'] ?? ''), 11, 5))) ?>
                    <?php if (trim((string) ($race['updated_at'] ?? '')) !== ''): ?>
                        <br>Ultima modificare <?= e($viewDateTime(substr((string) $race['updated_at'], 0, 10), substr((string) $race['updated_at'], 11, 5))) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (count($raceSegments) > 0): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Faze (<?= e((string) count($raceSegments)) ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Vehicul</th>
                    <th>Șofer</th>
                    <th>Început</th>
                    <th>Sfârșit</th>
                    <th>Traseu</th>
                    <th class="text-end">Km</th>
                    <th class="text-end">Cantitate</th>
                    <th class="pe-3">Observații</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($raceSegments as $index => $segment): ?>
                    <?php
                    $segmentRoute = array_filter([
                        $viewText($segment['loc_plecare'] ?? ''),
                        (string) ($loadLocationNames[(int) ($segment['loc_incarcare_id'] ?? 0)] ?? ''),
                        $viewText($segment['loc_livrare'] ?? ''),
                        (string) ($zoneNames[(int) ($segment['zona_distributie_id'] ?? 0)] ?? ''),
                    ], static fn (string $part): bool => $part !== '');
                    ?>
                    <tr>
                        <td class="ps-3"><?= e((string) ($index + 1)) ?></td>
                        <td><?= e($viewText($segment['nr_inmatriculare'] ?? '') ?: '-') ?></td>
                        <td><?= e($viewText($segment['sofer_nume'] ?? '') ?: '-') ?></td>
                        <td><?= e($viewDateTime($segment['data_inceput'] ?? '', $segment['ora_inceput'] ?? '') ?: '-') ?></td>
                        <td><?= e($viewDateTime($segment['data_sfarsit'] ?? '', $segment['ora_sfarsit'] ?? '') ?: '-') ?></td>
                        <td><?= e($segmentRoute !== [] ? implode(' → ', $segmentRoute) : '-') ?></td>
                        <td class="text-end"><?= e($viewNumber($segment['km'] ?? null, 0) ?: '-') ?></td>
                        <td class="text-end"><?= e($viewNumber($segment['cantitate_incarcata'] ?? null, 2, 't') ?: '-') ?></td>
                        <td class="pe-3"><?= e($viewText($segment['observatii'] ?? '') ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Cheltuieli (<?= e((string) count($expenses)) ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th class="ps-3">Data</th>
                    <th>Tip</th>
                    <th class="text-end">Sumă</th>
                    <th class="text-end">Refacturare</th>
                    <th>Observații</th>
                    <th class="pe-3">Document</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($expenses === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nu există cheltuieli pentru această cursă.</td></tr>
                <?php else: ?>
                    <?php foreach ($expenses as $expense): ?>
                        <?php
                        $expenseTypeLabel = trim((string) ($expense['categorie_nume'] ?? ''));
                        if ($expenseTypeLabel === '') {
                            $expenseTypeLabel = (string) ($expenseTypes[(string) ($expense['tip_cheltuiala'] ?? '')] ?? '-');
                        }
                        $expenseLocation = trim((string) ($expense['locatie'] ?? ''));
                        $docPath = (string) ($expense['file_path'] ?? '');
                        $docName = (string) ($expense['original_name'] ?? '');
                        $docUrl = trip_expense_document_url($docPath);
                        $refacturareIsInvoiced = (int) ($expense['refacturare_facturata'] ?? 0) === 1;
                        $refacturareAmount = (float) ($expense['refacturare_suma'] ?? 0);
                        if ($refacturareAmount <= 0) {
                            $detailsRows = json_decode((string) ($expense['refacturare_detalii'] ?? ''), true);
                            if (is_array($detailsRows)) {
                                foreach ($detailsRows as $detailsRow) {
                                    if (is_array($detailsRow)) {
                                        $refacturareAmount += (float) ($detailsRow['total'] ?? 0);
                                    }
                                }
                            }
                        }
                        $refacturareDocPath = (string) ($expense['refacturare_document_path'] ?? '');
                        $refacturareDocName = (string) ($expense['refacturare_document_original_name'] ?? '');
                        $refacturareDocUrl = $refacturareDocPath !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($refacturareDocPath)) : null;
                        ?>
                        <tr>
                            <td class="ps-3"><?= e(format_date_ro((string) ($expense['data_cheltuiala'] ?? ''))) ?></td>
                            <td>
                                <?= e($expenseTypeLabel) ?>
                                <?php if ($expenseLocation !== ''): ?><div class="small text-muted"><?= e($expenseLocation) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end"><?= e($viewNumber($expense['suma'] ?? null, 2, 'lei') ?: '-') ?></td>
                            <td class="text-end">
                                <?= e($refacturareAmount > 0 ? format_number_ro($refacturareAmount, 2) . ' lei' : '-') ?>
                                <?php if ($refacturareAmount > 0 && $refacturareIsInvoiced): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">facturat</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e($viewText($expense['observatii'] ?? '') ?: '-') ?></td>
                            <td class="pe-3">
                                <?php if ($docUrl !== null): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($docUrl) ?>" target="_blank" rel="noopener"><?= e($docName !== '' ? $docName : basename($docPath)) ?></a>
                                <?php endif; ?>
                                <?php if ($refacturareDocUrl !== null): ?>
                                    <a class="btn btn-sm btn-outline-secondary mt-1" href="<?= e($refacturareDocUrl) ?>" target="_blank" rel="noopener">Refacturare: <?= e($refacturareDocName !== '' ? $refacturareDocName : basename($refacturareDocPath)) ?></a>
                                <?php endif; ?>
                                <?php if ($docUrl === null && $refacturareDocUrl === null): ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
