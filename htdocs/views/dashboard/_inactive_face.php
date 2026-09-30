<?php
/*
 * Fata a doua a cardurilor "Status vehicule" / "Status soferi": lista completa a
 * vehiculelor (ansamblurilor) sau soferilor inactivi, cu documentul / motivul concret.
 * Se deschide in acelasi card la click pe "Inactive" sau pe un motiv
 * (assets/js/dashboard-live.js).
 *
 * Asteapta: $faceKind ('vehicles' | 'drivers'), $faceStatus (vehicle_status / driver_status),
 * $formatDate, $initials.
 */
$isDriverFace = $faceKind === 'drivers';
$inactiveUnits = is_array($faceStatus['inactive_details'] ?? null) ? $faceStatus['inactive_details'] : [];
$inactiveReasonFilters = array_values(array_filter(
    (array) ($faceStatus['reasons'] ?? []),
    static fn(array $reason): bool => (int) ($reason['count'] ?? 0) > 0
));
$today = new DateTimeImmutable('today');

$issueTitle = static function (array $issue) use ($isDriverFace): string {
    $document = trim((string) ($issue['document'] ?? ''));

    return match ((string) ($issue['key'] ?? '')) {
        'expired_documents' => ($document !== '' ? $document : 'Document') . ' expirat',
        'missing_documents' => ($document !== '' ? $document : 'Document') . ' lipsă',
        'manual_inactive' => $isDriverFace ? 'Marcat inactiv' : (string) ($issue['reason'] ?? 'Dezactivat manual'),
        default => (string) ($issue['reason'] ?? 'Alt motiv'),
    };
};
$issueMeta = static function (array $issue) use ($formatDate, $today): string {
    $date = trim((string) ($issue['date'] ?? ''));

    switch ((string) ($issue['key'] ?? '')) {
        case 'expired_documents':
            if ($date === '') {
                return 'fără dată de expirare';
            }
            $days = (int) (new DateTimeImmutable($date))->diff($today)->format('%a');

            return 'a expirat la ' . $formatDate($date) . ($days > 0 ? ' · de ' . $days . ($days === 1 ? ' zi' : ' zile') : ' · azi');
        case 'missing_documents':
            return 'nu este încărcat';
        case 'repair':
            return $date !== '' ? 'din ' . $formatDate($date) : 'în lucru';
        default:
            return $date !== '' ? 'din ' . $formatDate($date) : '';
    }
};
$issueLink = static function (array $issue, array $unit) use ($isDriverFace): array {
    $key = (string) ($issue['key'] ?? '');
    $isDocument = in_array($key, ['expired_documents', 'missing_documents'], true);

    if ($isDriverFace) {
        $driverId = (int) ($unit['id'] ?? 0);

        return match (true) {
            $isDocument => ['Documente', build_query_url(['page' => 'documente_soferi', 'q' => (string) ($unit['nume'] ?? '')])],
            in_array($key, ['leave', 'medical_leave'], true) => ['Concedii', build_query_url(['page' => 'programare_concedii'])],
            default => ['Șofer', build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => $driverId])],
        };
    }

    $vehicleId = (int) ($issue['vehicle_id'] ?? 0);

    return match (true) {
        $isDocument => ['Documente', build_query_url(['page' => 'documente', 'q' => (string) ($issue['vehicle_plate'] ?? '')])],
        $key === 'repair' => ['Reparație', build_query_url(['page' => 'mentenanta', 'action' => 'repairs', 'status' => 'in_lucru', 'vehicle_id' => $vehicleId])],
        default => ['Vehicul', build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId])],
    };
};
?>
<div class="dashboard-card-face dashboard-inactive-face" data-dashboard-face="inactive" data-dashboard-inactive-face hidden>
    <div class="dashboard-inactive-head">
        <button class="dashboard-inactive-back" type="button" data-dashboard-face-close aria-label="Înapoi la sumar">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </button>
        <h3><?= $isDriverFace ? 'Șoferi inactivi' : 'Vehicule inactive' ?></h3>
        <span class="dashboard-inactive-total" data-dashboard-inactive-visible><?= e((string) count($inactiveUnits)) ?></span>
    </div>

    <?php if ($inactiveReasonFilters !== []): ?>
        <div class="dashboard-inactive-filters" role="group" aria-label="Filtreaza dupa motiv">
            <button class="dashboard-inactive-filter is-active" type="button" data-dashboard-inactive-filter="all">
                Toate <span><?= e((string) count($inactiveUnits)) ?></span>
            </button>
            <?php foreach ($inactiveReasonFilters as $reason): ?>
                <button class="dashboard-inactive-filter tone-<?= e((string) ($reason['tone'] ?? 'muted')) ?>" type="button" data-dashboard-inactive-filter="<?= e((string) ($reason['key'] ?? 'other')) ?>">
                    <i class="bi <?= e((string) ($reason['icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i>
                    <?= e((string) ($reason['label'] ?? '')) ?> <span><?= e((string) ((int) ($reason['count'] ?? 0))) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($inactiveUnits === []): ?>
        <p class="dashboard-inactive-empty">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <?= $isDriverFace ? 'Toți șoferii sunt activi pentru filtrele selectate.' : 'Toate vehiculele sunt active pentru filtrele selectate.' ?>
        </p>
    <?php else: ?>
        <ul class="dashboard-inactive-list">
            <?php foreach ($inactiveUnits as $unit): ?>
                <?php
                $unitIssues = is_array($unit['issues'] ?? null) ? $unit['issues'] : [];
                $unitReasonKeys = array_values(array_unique(array_map(static fn(array $issue): string => (string) ($issue['key'] ?? ''), $unitIssues)));
                $unitMembers = is_array($unit['members'] ?? null) && $unit['members'] !== []
                    ? $unit['members']
                    : [['id' => (int) ($unit['id'] ?? 0), 'nr_inmatriculare' => (string) ($unit['nr_inmatriculare'] ?? ''), 'tip_vehicul' => '']];
                $isAssembly = !$isDriverFace && count($unitMembers) > 1;
                ?>
                <li class="dashboard-inactive-unit" data-dashboard-inactive-reasons="<?= e(implode(' ', $unitReasonKeys)) ?>">
                    <div class="dashboard-inactive-unit-plates">
                        <?php if ($isDriverFace): ?>
                            <?php $driverName = (string) ($unit['nume'] ?? ''); ?>
                            <a class="dashboard-driver-cell dashboard-inactive-driver" href="<?= e(build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => (int) ($unit['id'] ?? 0)])) ?>">
                                <span class="dashboard-driver-avatar" aria-hidden="true"><?= e($initials($driverName)) ?></span>
                                <strong><?= e($driverName !== '' ? $driverName : ('Șofer #' . (int) ($unit['id'] ?? 0))) ?></strong>
                            </a>
                        <?php else: ?>
                            <?php foreach ($unitMembers as $memberIndex => $member): ?>
                                <?php if ($memberIndex > 0): ?><span class="dashboard-vehicle-assembly-plus" aria-hidden="true">+</span><?php endif; ?>
                                <a class="dashboard-vehicle-pill" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) ($member['id'] ?? 0)])) ?>">
                                    <i class="bi <?= e(str_starts_with((string) ($member['tip_vehicul'] ?? ''), 'semiremorca') ? 'bi-truck-flatbed' : 'bi-truck-front') ?>" aria-hidden="true"></i>
                                    <strong><?= e((string) ($member['nr_inmatriculare'] ?? '')) ?></strong>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <ul class="dashboard-inactive-issues">
                        <?php foreach ($unitIssues as $issue): ?>
                            <?php [$linkLabel, $linkUrl] = $issueLink($issue, $unit); ?>
                            <li class="dashboard-inactive-issue tone-<?= e((string) ($issue['tone'] ?? 'muted')) ?>" data-dashboard-inactive-reason="<?= e((string) ($issue['key'] ?? '')) ?>">
                                <span class="dashboard-inactive-issue-icon"><i class="bi <?= e((string) ($issue['icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i></span>
                                <span class="dashboard-inactive-issue-text">
                                    <strong><?= e($issueTitle($issue)) ?></strong>
                                    <small>
                                        <?= e($issueMeta($issue)) ?>
                                        <?php if ($isAssembly && (string) ($issue['vehicle_plate'] ?? '') !== ''): ?>
                                            · <?= e((string) $issue['vehicle_plate']) ?>
                                        <?php endif; ?>
                                    </small>
                                </span>
                                <a class="dashboard-inactive-issue-link" href="<?= e($linkUrl) ?>">
                                    <?= e($linkLabel) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
