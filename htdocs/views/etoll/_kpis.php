<?php
declare(strict_types=1);

/**
 * Totalurile Situație eToll (fără / cu TVA, sesiuni, vehicule, alimentări). Folosite de pagina
 * eToll și de Tabloul de bord.
 * 
 * @var array    $totals, $summary, $openSessions, $selectedPlates
 * @var int      $topupCount
 * @var callable $money
 * @var string   $etollTopupsHref (opțional) — implicit panoul alimentărilor din pagină
 */
$etollTopupsHref = (string) ($etollTopupsHref ?? '#etollTopups');
?>
<section class="etoll-kpis" aria-label="Totaluri">
    <div class="etoll-kpi etoll-kpi--blue" title="<?= (int) $totals['fara_tva_lipsa'] > 0 ? e('Fără ' . $totals['fara_tva_lipsa'] . ' sesiuni al căror TVA nu a fost citit încă din documentul eToll') : 'Din documentele eToll ale sesiunilor închise' ?>">
        <span class="etoll-kpi__icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
        <div>
            <div class="etoll-kpi__label">Suma fără TVA</div>
            <div class="etoll-kpi__value"><?= e($money($totals['fara_tva'])) ?></div>
            <?php if ((int) $totals['fara_tva_lipsa'] > 0): ?><div class="etoll-kpi__hint is-warn"><?= (int) $totals['fara_tva_lipsa'] ?> sesiuni fără TVA citit încă</div><?php endif; ?>
        </div>
    </div>
    <div class="etoll-kpi etoll-kpi--green">
        <span class="etoll-kpi__icon" aria-hidden="true"><i class="bi bi-coin"></i></span>
        <div>
            <div class="etoll-kpi__label">Suma cu TVA</div>
            <div class="etoll-kpi__value"><?= e($money($totals['suma'])) ?></div>
            <?php if ((float) $totals['tva'] > 0): ?><div class="etoll-kpi__hint">TVA citit: <?= e($money($totals['tva'])) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="etoll-kpi etoll-kpi--purple">
        <span class="etoll-kpi__icon" aria-hidden="true"><i class="bi bi-signpost-split"></i></span>
        <div>
            <div class="etoll-kpi__label">Nr. sesiuni</div>
            <div class="etoll-kpi__value"><?= (int) $totals['n'] ?></div>
            <div class="etoll-kpi__hint">închise<?= $openSessions !== [] ? ' · ' . count($openSessions) . ' în curs' : '' ?></div>
        </div>
    </div>
    <div class="etoll-kpi etoll-kpi--slate">
        <span class="etoll-kpi__icon" aria-hidden="true"><i class="bi bi-truck"></i></span>
        <div>
            <div class="etoll-kpi__label">Vehicule (selectate)</div>
            <?php if ($selectedPlates !== []): ?>
                <div class="etoll-kpi__value"><?= count($selectedPlates) ?></div>
                <div class="etoll-kpi__hint"><?= (int) $totals['vehicule'] ?> cu sesiuni închise</div>
            <?php else: ?>
                <div class="etoll-kpi__value">Toate</div>
                <div class="etoll-kpi__hint"><?= (int) $totals['vehicule'] ?> cu sesiuni închise</div>
            <?php endif; ?>
        </div>
    </div>
    <a class="etoll-kpi etoll-kpi--cyan etoll-kpi--link" href="<?= e($etollTopupsHref) ?>"<?= $etollTopupsHref === '#etollTopups' ? ' data-open-topups' : '' ?> title="Alimentările portofelului (toate camioanele, nu depind de filtre)">
        <span class="etoll-kpi__icon" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
        <div>
            <div class="etoll-kpi__label">Alimentări</div>
            <div class="etoll-kpi__value"><?= $topupCount ?></div>
            <div class="etoll-kpi__hint">portofel · <?= e($money($summary['alimentari']['suma'] ?? 0)) ?></div>
        </div>
        <i class="bi bi-chevron-right etoll-kpi__chev" aria-hidden="true"></i>
    </a>
</section>
