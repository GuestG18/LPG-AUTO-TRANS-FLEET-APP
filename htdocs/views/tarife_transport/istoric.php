<?php
/**
 * Istoric modificări tarife — full audit trail.
 *
 * Records who changed what, when, from which value to which, from when it is
 * effective, and the fuel context observed at decision time. The fuel snapshot
 * is preserved verbatim so an old commercial decision stays auditable even if
 * CardOil later corrects a transaction.
 *
 * @var array $beneficiaries
 * @var int   $selectedBeneficiaryId
 * @var array $history
 */

$dateRo = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Throwable) {
        return $date;
    }
};

$dateTimeRo = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

$actionBadge = static function (string $action): string {
    return match ($action) {
        'created' => '<span class="tt-badge tt-badge-ok">Activat</span>',
        'scheduled' => '<span class="tt-badge tt-badge-info">Programat</span>',
        'superseded' => '<span class="tt-badge tt-badge-muted">Înlocuit</span>',
        'dismissed' => '<span class="tt-badge tt-badge-muted">Amânat</span>',
        'reviewed' => '<span class="tt-badge tt-badge-ok">Revizuit</span>',
        'deleted' => '<span class="tt-badge tt-badge-warn">Șters</span>',
        default => '<span class="tt-badge tt-badge-muted">' . e($action) . '</span>',
    };
};

/** "01.08.2026 – 31.08.2026" pentru intervale, "din 01.08.2026" pentru nelimitat. */
$periodLabel = static function (?string $from, ?string $to) use ($dateRo): string {
    if ($from === null || $from === '') {
        return '—';
    }
    if ($to !== null && $to !== '') {
        return $dateRo($from) . ' – ' . $dateRo($to);
    }

    return 'din ' . $dateRo($from);
};
?>
<div class="tt-page">

    <div class="tt-header">
        <div class="tt-header-titles">
            <h1>Istoric modificări tarife</h1>
            <p>Fiecare versiune comercială, cu autorul, motivul și contextul de combustibil de la momentul deciziei.</p>
        </div>

        <div class="tt-header-actions">
            <form method="get" class="tt-beneficiary-picker" id="tt-beneficiary-form">
                <input type="hidden" name="page" value="tarife_transport">
                <input type="hidden" name="action" value="istoric">
                <label for="tt-beneficiary-select">Beneficiar:</label>
                <select name="beneficiar_id" id="tt-beneficiary-select">
                    <option value="0">Toți beneficiarii</option>
                    <?php foreach ($beneficiaries as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $selectedBeneficiaryId ? 'selected' : '' ?>>
                            <?= e((string) $option['nume']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <a class="tt-btn" href="<?= e(build_query_url(['page' => 'tarife_transport', 'beneficiar_id' => $selectedBeneficiaryId])) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Înapoi la tarife
            </a>
        </div>
    </div>

    <section class="tt-card">
        <div class="tt-card-head">
            <h2 class="tt-card-title">Modificări înregistrate <small>(<?= count($history) ?>)</small></h2>
        </div>

        <div class="tt-table-wrap">
            <table class="tt-table">
                <thead>
                    <tr>
                        <th>Data modificării</th>
                        <th>Acțiune</th>
                        <th>Tip transport</th>
                        <th>Componentă</th>
                        <th>Rută</th>
                        <th>Valoare veche</th>
                        <th>Valoare nouă</th>
                        <th>Perioadă valabilitate</th>
                        <th>Referință motorină</th>
                        <th>Variație obs.</th>
                        <th>Autor</th>
                        <th>Motiv</th>
                        <?php if ($canManage): ?><th class="tt-col-actions">Acțiuni</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($history === []): ?>
                        <tr><td colspan="<?= $canManage ? 13 : 12 ?>" class="tt-empty-cell">
                            Nu există modificări de tarif înregistrate<?= $selectedBeneficiaryId > 0 ? ' pentru acest beneficiar' : '' ?>.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($history as $entry): ?>
                            <?php
                            $old = $entry['old_value'] !== null ? (float) $entry['old_value'] : null;
                            $new = $entry['new_value'] !== null ? (float) $entry['new_value'] : null;
                            $variation = $entry['fuel_variation_percent'] !== null ? (float) $entry['fuel_variation_percent'] : null;
                            ?>
                            <tr>
                                <td><?= e($dateTimeRo((string) $entry['changed_at'])) ?></td>
                                <td><?= $actionBadge((string) $entry['action']) ?></td>
                                <td><?= e(TransportTariffModel::TRANSPORT_TYPES[(string) $entry['transport_type']] ?? (string) $entry['transport_type']) ?></td>
                                <td><?= e(TransportTariffModel::componentLabel((string) $entry['component_key'])) ?></td>
                                <td><?= $entry['route_label'] ? e((string) $entry['route_label']) : '<span class="tt-dash">—</span>' ?></td>
                                <td class="tt-num"><?= $old !== null ? e(format_number_ro($old, 4)) : '<span class="tt-dash">—</span>' ?></td>
                                <td class="tt-num"><strong><?= $new !== null ? e(format_number_ro($new, 4)) : '—' ?></strong> <?= e((string) $entry['unit']) ?></td>
                                <td><?= e($periodLabel($entry['effective_from'] ?? null, $entry['effective_to'] ?? null)) ?></td>
                                <td class="tt-num">
                                    <?= $entry['reference_fuel_price'] !== null
                                        ? e(format_number_ro((float) $entry['reference_fuel_price'], 4)) . ' lei/L'
                                        : '<span class="tt-dash">—</span>' ?>
                                </td>
                                <td class="tt-num">
                                    <?php if ($variation === null): ?>
                                        <span class="tt-dash">—</span>
                                    <?php else: ?>
                                        <?= $variation >= 0 ? '+' : '−' ?><?= e(format_number_ro(abs($variation), 2)) ?> %
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($entry['user_nume'] ?? $entry['changed_by_name'] ?? 'Sistem')) ?></td>
                                <td style="white-space:normal;max-width:240px;">
                                    <?= $entry['reason'] ? e((string) $entry['reason']) : '<span class="tt-dash">—</span>' ?>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="tt-col-actions">
                                        <?php
                                        $entryVersionId = (int) ($entry['tariff_version_id'] ?? 0);
                                        $entryBeneficiaryId = (int) ($entry['beneficiar_id'] ?? 0);
                                        $entryTab = (string) $entry['transport_type'];
                                        $versionExists = $entryVersionId > 0 && !empty($existingVersions[$entryVersionId]);
                                        ?>
                                        <?php if ($versionExists): ?>
                                            <?php $versionDetails = $existingVersions[$entryVersionId]; ?>
                                            <span class="tt-actions">
                                                <a class="tt-btn tt-btn-icon" title="Corectează acest tarif (versiune nouă pe aceeași perioadă îl înlocuiește)"
                                                   href="<?= e(build_query_url([
                                                       'page' => 'tarife_transport',
                                                       'beneficiar_id' => $entryBeneficiaryId,
                                                       'tab' => $entryTab,
                                                       'edit_component' => (string) $entry['component_key'],
                                                       'edit_route' => (int) ($entry['route_ref_id'] ?? 0),
                                                       'edit_value' => (string) $versionDetails['value'],
                                                       'edit_from' => $versionDetails['valid_from'],
                                                       'edit_to' => $versionDetails['valid_to'],
                                                   ])) ?>">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                                </a>
                                                <?php if (!empty($deletableVersions[$entryVersionId])): ?>
                                                    <a class="tt-btn tt-btn-icon" title="Șterge versiunea și revino la tariful anterior (cu recalcularea curselor)"
                                                       href="<?= e(build_query_url([
                                                           'page' => 'tarife_transport',
                                                           'beneficiar_id' => $entryBeneficiaryId,
                                                           'tab' => $entryTab,
                                                           'delete_version_id' => $entryVersionId,
                                                       ])) ?>">
                                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="tt-dash" title="Versiunea nu mai există (ștearsă sau doar notă de istoric)">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="tt-note-strip">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <span>
                Contextul de combustibil este un <strong>snapshot de la momentul deciziei</strong>.
                Rămâne neschimbat chiar dacă CardOil corectează ulterior o tranzacție.
            </span>
        </div>
    </section>
</div>

<link rel="stylesheet" href="<?= e(url('assets/css/tarife-transport.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/tarife-transport.css'))) ?>">
<script src="<?= e(url('assets/js/tarife-transport.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/tarife-transport.js'))) ?>" defer></script>
