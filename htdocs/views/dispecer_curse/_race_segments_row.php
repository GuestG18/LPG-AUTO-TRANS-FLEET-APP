<?php
/**
 * Fazele unei curse reluate, afisate SUB randul cursei, pe aceleasi coloane si in
 * acelasi stil: interval, traseu si activitate se citesc din aceleasi butoane
 * "N detalii" ca la cursa, ca randul desfacut sa arate ca randul de deasupra.
 *
 * Coloanele care apartin cursei intregi — tip transport este inlocuit cu tipul
 * fazei, iar beneficiarul, tipul de marfa si tariful raman goale: cursa se
 * factureaza o singura data.
 *
 * Variabile asteptate:
 *   $inlineRaceId       - id-ul cursei
 *   $inlineRace         - randul cursei din listare
 *   $inlineSegments     - fazele cursei (minim 2, altfel nu se randeaza nimic)
 *   $inlineLoadLocations / $inlineZones - optiuni, pentru numele traseului
 *   $renderDispatcherSummaryDetails     - closure-ul de randare din listare
 */

$inlineSegments = isset($inlineSegments) && is_array($inlineSegments) ? $inlineSegments : [];
if (count($inlineSegments) < 2) {
    return;
}

$inlineRace = isset($inlineRace) && is_array($inlineRace) ? $inlineRace : [];
$inlineRaceId = (int) ($inlineRaceId ?? 0);
$inlineLoadLocations = isset($inlineLoadLocations) && is_array($inlineLoadLocations) ? $inlineLoadLocations : [];
$inlineZones = isset($inlineZones) && is_array($inlineZones) ? $inlineZones : [];
$inlineRender = isset($renderDispatcherSummaryDetails) && is_callable($renderDispatcherSummaryDetails)
    ? $renderDispatcherSummaryDetails
    : null;

// Diurnele cursei, impartite pe soferii fazelor dupa timpul petrecut pe drum.
$inlineDiurnaDays = (int) (dispatcher_diurna_for_interval($inlineRace)['diurne'] ?? 0);
$inlineDiurnaByDriver = [];
foreach (dispatcher_diurna_split($inlineDiurnaDays, $inlineSegments) as $inlineDiurnaRow) {
    $inlineDiurnaByDriver[(int) $inlineDiurnaRow['driver_id']] = (int) $inlineDiurnaRow['zile'];
}
$inlineDiurnaPrinted = [];

$inlineMoment = static function (?string $date, ?string $time): string {
    $date = trim((string) $date);
    if ($date === '') {
        return '-';
    }

    $time = substr(trim((string) $time), 0, 5);

    return format_date_ro($date) . ($time !== '' ? ' ' . $time : '');
};
$inlineTon = static function ($value): ?string {
    if ($value === null || trim((string) $value) === '' || (float) $value <= 0) {
        return null;
    }

    return format_number_ro((float) $value, 2) . ' t';
};
$inlineOptionName = static function (array $options, int $id): string {
    foreach ($options as $option) {
        if ((int) ($option['id'] ?? 0) === $id) {
            return trim((string) ($option['nume'] ?? ''));
        }
    }

    return '';
};
$inlineAddPart = static function (array &$parts, string $label, ?string $value): void {
    $value = trim((string) $value);
    if ($value === '') {
        return;
    }

    $parts[] = ['label' => $label, 'value' => $value];
};
// Fara closure-ul din listare (de exemplu la include din alt context) afisam
// acelasi continut ca text, ca randul sa ramana lizibil.
$inlineFallback = static function (array $parts): string {
    $pieces = [];
    foreach ($parts as $part) {
        $pieces[] = rtrim((string) $part['label'], ':') . ': ' . (string) $part['value'];
    }

    return $pieces === [] ? '<span class="text-muted">—</span>' : e(implode(' | ', $pieces));
};
$inlineSummary = static function (array $parts, string $key, string $label) use ($inlineRender, $inlineFallback): string {
    if ($inlineRender === null) {
        return $inlineFallback($parts);
    }

    return $inlineRender($parts, $key, $label);
};
?>
<?php foreach ($inlineSegments as $inlineIndex => $inlineSegment): ?>
    <?php
    $inlineOrder = (int) ($inlineSegment['ordine'] ?? ($inlineIndex + 1));
    $inlineSegmentId = (int) ($inlineSegment['id'] ?? $inlineOrder);
    $inlineKey = (string) $inlineRaceId . '-faza-' . $inlineSegmentId;
    $inlineLabel = 'faza ' . $inlineOrder . ' a cursei #' . $inlineRaceId;
    $inlineDriverId = (int) ($inlineSegment['driver_id'] ?? 0);
    $inlineDiurnaShown = isset($inlineDiurnaByDriver[$inlineDriverId]) && !isset($inlineDiurnaPrinted[$inlineDriverId]);
    $inlineDiurnaPrinted[$inlineDriverId] = true;

    $inlineStartLabel = $inlineMoment($inlineSegment['data_inceput'] ?? null, $inlineSegment['ora_inceput'] ?? null);
    $inlineEndLabel = $inlineMoment($inlineSegment['data_sfarsit'] ?? null, $inlineSegment['ora_sfarsit'] ?? null);
    $inlineIntervalParts = [
        ['label' => 'Start', 'value' => $inlineStartLabel],
        ['label' => 'Sfarsit', 'value' => $inlineEndLabel],
    ];

    $inlineRouteParts = [];
    $inlineAddPart($inlineRouteParts, 'Loc incarcare', $inlineOptionName($inlineLoadLocations, (int) ($inlineSegment['loc_incarcare_id'] ?? 0)));
    $inlineAddPart($inlineRouteParts, 'Zona distributie', $inlineOptionName($inlineZones, (int) ($inlineSegment['zona_distributie_id'] ?? 0)));
    $inlineAddPart($inlineRouteParts, 'Loc plecare', (string) ($inlineSegment['loc_plecare'] ?? ''));
    $inlineAddPart($inlineRouteParts, 'Loc livrare', (string) ($inlineSegment['loc_livrare'] ?? ''));
    $inlineRouteTitle = [];
    foreach ($inlineRouteParts as $inlineRoutePart) {
        $inlineRouteTitle[] = $inlineRoutePart['label'] . ': ' . $inlineRoutePart['value'];
    }

    $inlineLoaded = $inlineTon($inlineSegment['cantitate_incarcata'] ?? null);
    $inlineDelivered = $inlineTon($inlineSegment['tona_livrata'] ?? null);
    $inlineKm = ($inlineSegment['km'] ?? null) !== null && $inlineSegment['km'] !== '' ? (int) $inlineSegment['km'] : null;
    // Zero clienti pe o faza de incarcare nu spune nimic: nu il mai afisam.
    $inlineClients = ($inlineSegment['nr_clienti'] ?? null) !== null && (int) $inlineSegment['nr_clienti'] > 0
        ? (int) $inlineSegment['nr_clienti']
        : null;
    $inlineHours = ($inlineSegment['ore_functionare'] ?? null) !== null && trim((string) $inlineSegment['ore_functionare']) !== ''
        ? format_number_ro((float) $inlineSegment['ore_functionare'], 2) . 'h'
        : null;

    $inlineActivityParts = [];
    $inlineAddPart($inlineActivityParts, 'INCARCAT:', $inlineLoaded);
    $inlineAddPart($inlineActivityParts, 'LIVRAT:', $inlineDelivered);
    $inlineAddPart($inlineActivityParts, 'CLIENTI:', $inlineClients !== null ? (string) $inlineClients : null);
    $inlineAddPart($inlineActivityParts, 'KM:', $inlineKm !== null ? number_format($inlineKm, 0, ',', '.') : null);
    $inlineAddPart($inlineActivityParts, 'ORE:', $inlineHours);
    $inlineActivityTitle = [];
    foreach ($inlineActivityParts as $inlineActivityPart) {
        $inlineActivityTitle[] = rtrim($inlineActivityPart['label'], ':') . ': ' . $inlineActivityPart['value'];
    }

    // Cantitatea din rand: ce s-a incarcat in faza, altfel ce s-a livrat in ea.
    $inlineQuantityLabel = $inlineLoaded ?? $inlineDelivered ?? '-';
    ?>
    <tr class="dispatcher-segment-line" data-segments-for="<?= e((string) $inlineRaceId) ?>" hidden>
        <td class="col-plate">
            <div class="cell-content">
                <div class="dispatcher-segment-line-plate">
                    <span class="dispatcher-segment-line-order">Faza <?= e((string) $inlineOrder) ?></span>
                    <strong class="dispatcher-cell-text dispatcher-cell-nowrap dispatcher-plate-value vehicle-cell nr-auto-cell"><?= e(trim((string) ($inlineSegment['nr_inmatriculare'] ?? '')) !== '' ? (string) $inlineSegment['nr_inmatriculare'] : '-') ?></strong>
                </div>
            </div>
        </td>
        <td class="col-registration-details">
            <div class="cell-content">
                <span class="dispatcher-registration-details-line text-muted">—</span>
            </div>
        </td>
        <td class="col-driver">
            <div class="cell-content">
                <?php $inlineDriverName = trim((string) ($inlineSegment['sofer_nume'] ?? '')); ?>
                <span class="dispatcher-cell-text driver-cell" title="<?= e($inlineDriverName) ?>"><?= e($inlineDriverName !== '' ? $inlineDriverName : '-') ?></span>
            </div>
        </td>
        <td class="col-type">
            <div class="cell-content">
                <span class="badge rounded-pill dispatcher-transport-badge transport-badge transport-cell dispatcher-phase-badge"><?= e(DispecerCurseModel::segmentPhaseLabel($inlineSegment)) ?></span>
            </div>
        </td>
        <td class="col-loading-date text-center-cell">
            <div class="cell-content center">
                <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e($inlineLoaded !== null && ($inlineSegment['data_inceput'] ?? '') !== '' ? format_date_ro((string) $inlineSegment['data_inceput']) : '-') ?></span>
            </div>
        </td>
        <td class="col-interval">
            <div class="cell-content center dispatcher-summary-cell-content" title="<?= e($inlineStartLabel . ' - ' . $inlineEndLabel) ?>">
                <?= $inlineSummary($inlineIntervalParts, 'interval-' . $inlineKey, 'interval ' . $inlineLabel) ?>
            </div>
        </td>
        <td class="col-duration text-center-cell">
            <div class="cell-content center">
                <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(dispatcher_segment_duration_label($inlineSegment)) ?></span>
            </div>
        </td>
        <td class="col-diurna text-center-cell">
            <div class="cell-content center">
                <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e($inlineDiurnaShown ? (string) $inlineDiurnaByDriver[$inlineDriverId] : '-') ?></span>
            </div>
        </td>
        <td class="col-route">
            <div class="cell-content center dispatcher-summary-cell-content" title="<?= e(implode(' | ', $inlineRouteTitle)) ?>">
                <?= $inlineSummary($inlineRouteParts, 'route-' . $inlineKey, 'traseu ' . $inlineLabel) ?>
            </div>
        </td>
        <td class="col-beneficiary">
            <div class="cell-content">
                <span class="dispatcher-cell-text text-muted">—</span>
            </div>
        </td>
        <td class="col-goods-type">
            <div class="cell-content">
                <span class="dispatcher-cell-text text-muted">—</span>
            </div>
        </td>
        <td class="col-quantity text-center-cell">
            <div class="cell-content center">
                <span class="dispatcher-cell-text dispatcher-cell-nowrap" title="<?= e($inlineLoaded !== null ? 'Încărcat în această fază' : ($inlineDelivered !== null ? 'Livrat în această fază' : '')) ?>"><?= e($inlineQuantityLabel) ?></span>
            </div>
        </td>
        <td class="col-activity">
            <div class="cell-content center dispatcher-summary-cell-content" title="<?= e(implode(' | ', $inlineActivityTitle)) ?>">
                <?= $inlineSummary($inlineActivityParts, 'activity-' . $inlineKey, 'activitate ' . $inlineLabel) ?>
            </div>
        </td>
        <td class="col-financial">
            <div class="cell-content center dispatcher-summary-cell-content" title="Tariful este al cursei întregi, nu al fazei.">
                <?= $inlineSummary([], 'financial-' . $inlineKey, 'financiar ' . $inlineLabel) ?>
            </div>
        </td>
        <td class="col-expenses text-center-cell">
            <div class="cell-content center">
                <span class="dispatcher-cell-text text-muted">—</span>
            </div>
        </td>
        <td class="col-beneficiary">
            <div class="cell-content">
                <?php $inlineObservatii = trim((string) ($inlineSegment['observatii'] ?? '')); ?>
                <span class="dispatcher-cell-text" title="<?= e($inlineObservatii) ?>"><?= e($inlineObservatii !== '' ? $inlineObservatii : '-') ?></span>
            </div>
        </td>
        <td class="col-actions text-center-cell">
            <div class="cell-content center">
                <a class="dispatcher-race-actions-btn" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $inlineRaceId])) ?>#race-segments" title="Editează fazele cursei #<?= e((string) $inlineRaceId) ?>" aria-label="Editează fazele cursei #<?= e((string) $inlineRaceId) ?>">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
