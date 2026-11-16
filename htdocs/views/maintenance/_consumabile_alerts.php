<?php
declare(strict_types=1);

/**
 * Cardul „Alerte active” din Consumabile & fluide. Folosit de tabul Consumabile (Prezentare) și
 * de Tabloul de bord.
 * 
 * @var array    $cons        ConsumabileController::viewData() / dashboardAlerts()
 * @var array    $ov          $cons['overview'] (recipientele care expiră)
 * @var callable $consUrl, $fmtDate
 * @var bool     $canOperate  butonul de închidere (cere assets/js/consumabile.js)
 */
?>
<article class="cons-card">
    <?php
    $alertsOpen = (array) ($cons['alerts'] ?? []);
    $alertsResolved = (array) ($cons['alerts_resolved'] ?? []);
    $sevClass = ['critical' => 'is-expired', 'warn' => 'is-low', 'info' => 'is-info'];
    $sevIcon = ['critical' => 'bi-exclamation-octagon', 'warn' => 'bi-exclamation-triangle', 'info' => 'bi-info-circle'];
    ?>
    <header><h2><i class="bi bi-bell"></i> Alerte active</h2><span><?= e((string) count($alertsOpen)) ?> deschise · se închid automat la rezolvare</span></header>
    <ul class="cons-alerts">
        <?php foreach ($alertsOpen as $alert): ?>
            <li class="<?= e($sevClass[$alert['severitate']] ?? 'is-low') ?>">
                <i class="bi <?= e($sevIcon[$alert['severitate']] ?? 'bi-bell') ?>"></i>
                <span class="cons-alert-body">
                    <strong><?= e((string) $alert['titlu']) ?></strong> · <a href="<?= e($consUrl(['sub' => (string) $alert['link_sub']])) ?>"><?= e((string) $alert['subiect']) ?></a>
                    <small class="d-block"><?= e((string) ($alert['detalii'] ?? '')) ?></small>
                    <?php if (!empty($alert['recomandare'])): ?><small class="d-block cons-alert-action"><i class="bi bi-arrow-return-right"></i> <?= e((string) $alert['recomandare']) ?></small><?php endif; ?>
                    <small class="d-block text-muted">deschisă <?= e($fmtDate((string) $alert['deschisa_la'], true)) ?></small>
                </span>
                <?php if ($canOperate): ?>
                    <button type="button" class="btn btn-sm btn-light cons-alert-close" data-cons-alert-close="<?= e((string) $alert['id']) ?>" title="Închide după verificare (se redeschide doar la date noi)"><i class="bi bi-check2"></i></button>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($alertsOpen === []): ?>
            <li class="is-ok"><i class="bi bi-check-circle"></i><span>Nicio alertă deschisă pentru consumabile și fluide.</span></li>
        <?php endif; ?>
    </ul>
    <?php if (!empty($ov['expiring_containers'])): ?>
        <ul class="cons-alerts pt-0">
            <?php foreach ((array) $ov['expiring_containers'] as $c): ?>
                <li class="<?= $c['stare_expirare'] === 'expirat' ? 'is-expired' : 'is-soon' ?>"><i class="bi bi-calendar-x"></i><span><strong><?= e((string) $c['cod']) ?></strong> lot <?= e((string) ($c['lot'] ?? '—')) ?> <?= $c['stare_expirare'] === 'expirat' ? 'expirat la' : 'expiră la' ?> <?= e($fmtDate((string) $c['data_expirare'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($alertsResolved !== []): ?>
        <details class="cons-resolved">
            <summary>Rezolvate în ultimele 7 zile (<?= e((string) count($alertsResolved)) ?>)</summary>
            <ul>
                <?php foreach ($alertsResolved as $alert): ?>
                    <li><strong><?= e((string) $alert['titlu']) ?></strong> · <?= e((string) $alert['subiect']) ?>
                        <small class="d-block text-muted"><?= e($fmtDate((string) $alert['rezolvata_la'], true)) ?> · <?= $alert['rezolvare'] === 'manual' ? 'închisă de ' . e((string) ($alert['rezolvata_de_nume'] ?? '')) . ': ' . e((string) $alert['motiv_rezolvare']) : 'rezolvată (verificat pe date)' ?></small></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
    <p class="cons-note"><i class="bi bi-info-circle"></i> Notificările (email / SMS) pentru aceste alerte se configurează în Notificări → „Consumabile & fluide”. O alertă se trimite o singură dată cât rămâne deschisă; reamintirile urmează opțiunea regulii.</p>
</article>
