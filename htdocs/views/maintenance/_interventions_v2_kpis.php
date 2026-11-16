<?php
declare(strict_types=1);

/**
 * Cardurile de status ale Intervențiilor V2 (De verificat / Programate / În service /
 * În așteptare / Finalizate). Folosit de:
 *  - Mentenanță → Intervenții (_interventions_v2.php): butoane-filtru, valorile se
 *    actualizează din JSON de assets/js/interventii-v2.js (data-iv2-kpi*);
 *  - Tablou de bord (cardul „Intervenții pe status”): linkuri spre lista filtrată.
 *
 * @var array<string,int>  $ivKpiValues   status => număr (InterventionWorkflowModel::kpis)
 * @var string             $ivActiveStatus statusul filtrat acum ('' = niciunul)
 * @var callable|null      $ivKpiHref     fn(string $status): string — dacă e dat, cardurile sunt linkuri
 */
$ivKpiDefinitions = [
    ['status' => 'de_verificat', 'label' => 'De verificat', 'icon' => 'bi-exclamation-circle', 'tone' => 'blue'],
    ['status' => 'programata', 'label' => 'Programate', 'icon' => 'bi-calendar3', 'tone' => 'indigo'],
    ['status' => 'in_service', 'label' => 'În service', 'icon' => 'bi-tools', 'tone' => 'purple'],
    ['status' => 'in_asteptare', 'label' => 'În așteptare', 'icon' => 'bi-pause-circle', 'tone' => 'orange'],
    ['status' => 'finalizata', 'label' => 'Finalizate', 'icon' => 'bi-check-lg', 'tone' => 'green'],
];
$ivKpiValues = is_array($ivKpiValues ?? null) ? $ivKpiValues : [];
$ivActiveStatus = (string) ($ivActiveStatus ?? '');
$ivKpiHref = isset($ivKpiHref) && is_callable($ivKpiHref) ? $ivKpiHref : null;
?>
<div class="iv2-kpis" role="group" aria-label="Sumar intervenții pe status">
    <?php foreach ($ivKpiDefinitions as $kpi): ?>
        <?php $isActive = $ivActiveStatus === $kpi['status']; ?>
        <?php if ($ivKpiHref !== null): ?>
            <a class="iv2-kpi" href="<?= e($ivKpiHref($kpi['status'])) ?>" title="Deschide lista: <?= e($kpi['label']) ?>">
        <?php else: ?>
            <button class="iv2-kpi <?= $isActive ? 'is-active' : '' ?>" type="button"
                    data-iv2-kpi="<?= e($kpi['status']) ?>" aria-pressed="<?= $isActive ? 'true' : 'false' ?>"
                    title="Filtrează: <?= e($kpi['label']) ?>">
        <?php endif; ?>
                <span class="iv2-kpi-icon tone-<?= e($kpi['tone']) ?>"><i class="bi <?= e($kpi['icon']) ?>" aria-hidden="true"></i></span>
                <span>
                    <span class="iv2-kpi-value" data-iv2-kpi-value<?= $ivKpiHref !== null ? ' data-dashboard-count="iv2-' . e($kpi['status']) . '"' : '' ?>><?= e((string) (int) ($ivKpiValues[$kpi['status']] ?? 0)) ?></span>
                    <span class="iv2-kpi-label"><?= e($kpi['label']) ?></span>
                </span>
        <?= $ivKpiHref !== null ? '</a>' : '</button>' ?>
    <?php endforeach; ?>
</div>
