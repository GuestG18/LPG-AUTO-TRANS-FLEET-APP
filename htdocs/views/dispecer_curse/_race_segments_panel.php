<?php
/**
 * Fazele unei curse (reluarea cursei din pauza).
 *
 * O cursa oprita si reluata ramane O SINGURA cursa: un singur tarif, un singur
 * rand in centralizator. Fazele spun cum s-a desfasurat: mersul la incarcare,
 * livrarea la clienti a doua zi, eventual alte zile.
 *
 * Fiecare faza se completeaza cu ACELASI formular ca o cursa: acelasi partial de
 * campuri (`_race_form_fields.php`), aceleasi atribute de configurare si aceeasi
 * clasa `.dispatcher-race-form`, ca `initRaceForm()` din dispecer-curse.js sa se
 * lege si de ea. Asa se comporta identic: data si ora intr-un singur camp, locurile
 * si zonele filtrate dupa beneficiar si vehicul, campurile potrivite tipului de
 * transport, soferii dupa vehicul.
 *
 * Variabile asteptate:
 *   $segmentsRace          - randul cursei
 *   $segmentsList          - fazele existente (poate fi gol)
 *   $segmentsOrigin        - 'list' | 'edit' (unde se intoarce dupa salvare)
 *   plus listele paginii: $beneficiaries, $transportTypes, $loadLocations,
 *   $distributionZones, $goodsTypeOptions, $driversByVehicle si optiunile de vehicul.
 */

$segmentsRace = isset($segmentsRace) && is_array($segmentsRace) ? $segmentsRace : [];
$segmentsList = isset($segmentsList) && is_array($segmentsList) ? $segmentsList : [];
$segmentsVehicles = isset($segmentsVehicles) && is_array($segmentsVehicles) ? $segmentsVehicles : [];
$segmentsDrivers = isset($segmentsDrivers) && is_array($segmentsDrivers) ? $segmentsDrivers : [];
$segmentsOrigin = isset($segmentsOrigin) ? (string) $segmentsOrigin : 'list';

$segmentsRaceId = (int) ($segmentsRace['id'] ?? 0);
if ($segmentsRaceId <= 0) {
    return;
}

// Faza noua incepe acolo unde s-a oprit ultima (sau cursa).
$segmentsLast = $segmentsList !== [] ? $segmentsList[count($segmentsList) - 1] : null;
$segmentsLastEndDate = trim((string) ($segmentsLast['data_sfarsit'] ?? ($segmentsRace['data_sfarsit'] ?? '')));
$segmentsLastEndTime = substr(trim((string) ($segmentsLast['ora_sfarsit'] ?? ($segmentsRace['ora_sfarsit'] ?? ''))), 0, 5);

// Diurnele cursei, impartite pe soferii fazelor dupa timpul petrecut pe drum.
$segmentsDiurnaDays = (int) (dispatcher_diurna_for_interval($segmentsRace)['diurne'] ?? 0);
$segmentsDiurnaByKey = [];
foreach (dispatcher_diurna_split($segmentsDiurnaDays, $segmentsList) as $segmentsDiurnaRow) {
    $segmentsDiurnaByKey[(int) $segmentsDiurnaRow['driver_id']] = $segmentsDiurnaRow;
}
$segmentsDiurnaPrinted = [];
$segmentsTotals = DispecerCurseModel::sumSegmentTotals($segmentsList);

// Campurile fazei se randeaza intr-un scope propriu: partialul isi calculeaza
// variabilele derivate din $formData, iar asa nu le amesteca intre faze si nici
// cu formularul de cursa din pagina.
$segmentsRenderFields = static function (array $vars): void {
    extract($vars, EXTR_SKIP);
    include __DIR__ . '/_race_form_fields.php';
};

// Valorile unei faze, in limbajul formularului de cursa.
$segmentsFormData = static function (array $segment) use ($segmentsRace): array {
    return [
        'beneficiar_id' => $segmentsRace['beneficiar_id'] ?? '',
        'tip_transport' => $segmentsRace['tip_transport'] ?? '',
        'tip_marfa' => [],
        'vehicle_id' => $segment['vehicle_id'] ?? '',
        'driver_id' => $segment['driver_id'] ?? '',
        'data_inceput' => $segment['data_inceput'] ?? '',
        'ora_inceput' => $segment['ora_inceput'] ?? '',
        'data_sfarsit' => $segment['data_sfarsit'] ?? '',
        'ora_sfarsit' => $segment['ora_sfarsit'] ?? '',
        'loc_incarcare_id' => $segment['loc_incarcare_id'] ?? '',
        'zona_distributie_id' => $segment['zona_distributie_id'] ?? '',
        'loc_plecare' => $segment['loc_plecare'] ?? '',
        'loc_livrare' => $segment['loc_livrare'] ?? '',
        'km_cursa' => $segment['km'] ?? '',
        'km_totali' => $segment['km'] ?? '',
        'km_dislocare' => $segment['km'] ?? '',
        'cantitate_incarcata' => $segment['cantitate_incarcata'] ?? '',
        'tona_livrata' => $segment['tona_livrata'] ?? '',
        'nr_clienti' => $segment['nr_clienti'] ?? '',
        'ore_aspirare' => $segment['ore_functionare'] ?? '',
        'ore_functionare' => $segment['ore_functionare'] ?? '',
        'capacitate_transport' => $segmentsRace['capacitate_transport'] ?? '',
        'observatii' => $segment['observatii'] ?? '',
    ];
};

$segmentsFieldVars = [
    'fieldMode' => 'phase',
    'formErrors' => [],
    'beneficiaries' => $beneficiaries ?? [],
    'transportTypes' => $transportTypes ?? [],
    'raceVehicles' => $segmentsVehicles,
    'driversByVehicle' => $driversByVehicle ?? [],
    'loadLocations' => $loadLocations ?? [],
    'distributionZones' => $distributionZones ?? [],
    'goodsTypeOptions' => $goodsTypeOptions ?? [],
];
?>
<div class="card border-0 shadow-sm mb-3" id="race-segments">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h3 class="h6 mb-0">
            <i class="bi bi-signpost-split me-1" aria-hidden="true"></i>
            Fazele cursei #<?= e((string) $segmentsRaceId) ?>
        </h3>
        <span class="small text-muted">
            <?= e(trim((string) ($segmentsRace['nr_inmatriculare'] ?? '-'))) ?> ·
            <?= e(trim((string) ($segmentsRace['sofer_nume'] ?? '-'))) ?> ·
            <?= e(($segmentsRace['data_inceput'] ?? null) ? format_date_ro((string) $segmentsRace['data_inceput']) : '-') ?>
        </span>
    </div>
    <div class="card-body">
        <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
            <div class="small">
                Cursa rămâne <strong>una singură</strong> și se facturează o singură dată. Fiecare fază se completează
                ca o cursă &mdash; aceleași câmpuri și același comportament &mdash; dar pentru porțiunea ei:
                mersul la încărcare, livrarea la clienți în ziua următoare, restul mărfii a treia zi.
                <strong>Totalurile cursei (km, cantitate, tone livrate, clienți, ore) sunt suma fazelor</strong>,
                iar tariful se recalculează din ele după fiecare salvare.
            </div>
        </div>

        <?php if ($segmentsList !== []): ?>
            <?php foreach ($segmentsList as $segmentRow): ?>
                <?php
                $segmentId = (int) ($segmentRow['id'] ?? 0);
                $segmentFormId = 'phase_' . $segmentsRaceId . '_' . $segmentId;
                $segmentsDriverKey = (int) ($segmentRow['driver_id'] ?? 0);
                $segmentsDiurnaRow = $segmentsDiurnaByKey[$segmentsDriverKey] ?? null;
                $segmentsDiurnaShown = $segmentsDiurnaRow !== null && !isset($segmentsDiurnaPrinted[$segmentsDriverKey]);
                $segmentsDiurnaPrinted[$segmentsDriverKey] = true;
                ?>
                <div class="dispatcher-segment-card">
                    <div class="dispatcher-segment-card-head">
                        <strong>
                            Faza <?= e((string) ($segmentRow['ordine'] ?? '')) ?>
                            <span class="badge bg-light text-dark border"><?= e(DispecerCurseModel::segmentPhaseLabel($segmentRow)) ?></span>
                        </strong>
                        <span class="small text-muted">
                            <?= e(dispatcher_segment_duration_label($segmentRow)) ?>
                            <?php if ($segmentsDiurnaShown): ?>
                                · diurne șofer: <?= e((string) $segmentsDiurnaRow['zile']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <form
                        method="post"
                        action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'segment_update'])) ?>"
                        class="dispatcher-race-form dispatcher-phase-form"
                        <?php $attrsMode = 'phase'; include __DIR__ . '/_race_form_attrs.php'; ?>
                        data-inactive-trip-id="<?= e((string) $segmentsRaceId) ?>"
                        novalidate
                    >
                        <?= csrf_field() ?>
                        <input type="hidden" name="segment_id" value="<?= e((string) $segmentId) ?>">
                        <input type="hidden" name="segment_origin" value="<?= e($segmentsOrigin) ?>">
                        <div class="row g-3">
                            <?php
                            $segmentsRenderFields($segmentsFieldVars + [
                                'fieldPrefix' => $segmentFormId,
                                'formData' => $segmentsFormData($segmentRow),
                            ]);
                            ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Salvează faza</button>
                        </div>
                    </form>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'segment_delete'])) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="segment_id" value="<?= e((string) $segmentId) ?>">
                        <input type="hidden" name="segment_origin" value="<?= e($segmentsOrigin) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Ștergi faza <?= e((string) ($segmentRow['ordine'] ?? '')) ?> a cursei #<?= e((string) $segmentsRaceId) ?>?">
                            Șterge faza
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>

            <div class="dispatcher-segment-totals small">
                <strong>Total din faze:</strong>
                <?= e((string) (int) ($segmentsTotals['km'] ?? 0)) ?> km
                <?php if (isset($segmentsTotals['cantitate_incarcata'])): ?>
                    · încărcat <?= e(format_number_ro((float) $segmentsTotals['cantitate_incarcata'], 2)) ?> t
                <?php endif; ?>
                <?php if (isset($segmentsTotals['tona_livrata'])): ?>
                    · livrat <?= e(format_number_ro((float) $segmentsTotals['tona_livrata'], 2)) ?> t
                <?php endif; ?>
                <?php if (isset($segmentsTotals['nr_clienti'])): ?>
                    · <?= e((string) (int) $segmentsTotals['nr_clienti']) ?> clienți
                <?php endif; ?>
                <?php if (isset($segmentsTotals['ore_functionare'])): ?>
                    · <?= e(format_number_ro((float) $segmentsTotals['ore_functionare'], 2)) ?> ore
                <?php endif; ?>
                <?php
                $segmentsLoaded = (float) ($segmentsTotals['cantitate_incarcata'] ?? 0);
                $segmentsDelivered = (float) ($segmentsTotals['tona_livrata'] ?? 0);
                ?>
                <?php if ($segmentsLoaded > 0 && $segmentsDelivered > 0 && $segmentsDelivered + 0.001 < $segmentsLoaded): ?>
                    <span class="text-warning-emphasis">
                        · rămas nelivrat: <?= e(format_number_ro($segmentsLoaded - $segmentsDelivered, 2)) ?> t
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="dispatcher-segment-card dispatcher-segment-card-new">
            <div class="dispatcher-segment-card-head">
                <strong>
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>
                    <?= $segmentsList === [] ? 'Reia cursa — fază nouă' : 'Adaugă faza ' . e((string) (count($segmentsList) + 1)) ?>
                </strong>
                <span class="small text-muted">Cursa nu se dublează: se adaugă doar o porțiune nouă.</span>
            </div>
            <form
                method="post"
                action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'segment_store'])) ?>"
                class="dispatcher-race-form dispatcher-phase-form"
                id="race-resume-form"
                <?php $attrsMode = 'phase'; include __DIR__ . '/_race_form_attrs.php'; ?>
                data-inactive-trip-id="<?= e((string) $segmentsRaceId) ?>"
                novalidate
            >
                <?= csrf_field() ?>
                <input type="hidden" name="cursa_id" value="<?= e((string) $segmentsRaceId) ?>">
                <input type="hidden" name="segment_origin" value="<?= e($segmentsOrigin) ?>">
                <div class="row g-3">
                    <?php
                    $segmentsRenderFields($segmentsFieldVars + [
                        'fieldPrefix' => 'phase_new_' . $segmentsRaceId,
                        'formData' => [
                            'beneficiar_id' => $segmentsRace['beneficiar_id'] ?? '',
                            'tip_transport' => $segmentsRace['tip_transport'] ?? '',
                            'tip_marfa' => [],
                            'data_inceput' => $segmentsLastEndDate !== '' ? $segmentsLastEndDate : date('Y-m-d'),
                            'ora_inceput' => $segmentsLastEndTime,
                            'loc_incarcare_id' => $segmentsRace['loc_incarcare_id'] ?? '',
                            'zona_distributie_id' => $segmentsRace['zona_distributie_id'] ?? '',
                            'capacitate_transport' => $segmentsRace['capacitate_transport'] ?? '',
                        ],
                    ]);
                    ?>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Adaugă faza
                    </button>
                    <?php if ($segmentsOrigin === 'list'): ?>
                        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Renunță</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
