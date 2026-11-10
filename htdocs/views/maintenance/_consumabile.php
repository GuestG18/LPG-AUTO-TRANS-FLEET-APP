<?php
declare(strict_types=1);

/**
 * Stoc Piese → tab „Consumabile”. Inclus din views/maintenance/index.php.
 * Variabile: $consumabile (ConsumabileController::viewData()).
 */
$cons = is_array($consumabile ?? null) ? $consumabile : [];
$sub = (string) ($cons['sub'] ?? 'overview');
$consFilters = (array) ($cons['filters'] ?? []);
$products = (array) ($cons['products'] ?? []);
$containers = (array) ($cons['containers'] ?? []);
$consVehicles = (array) ($cons['vehicles'] ?? []);
$canOperate = !empty($cons['can_operate']);
$canManage = !empty($cons['can_manage']);
$fluidTypes = ConsumabileStockService::FLUID_TYPES;
$movementTypes = ConsumabileStockService::MOVEMENT_TYPES;
$containerTypes = ConsumabileStockService::CONTAINER_TYPES;

$qty = static function (mixed $value, string $unit = ''): string {
    $text = rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');

    return trim(($text === '' || $text === '-0' ? '0' : $text) . ' ' . $unit);
};
$consUrl = static function (array $extra = []): string {
    return build_query_url(array_merge(['page' => 'mentenanta', 'action' => 'stock', 'tab' => 'consumabile'], $extra));
};
$stateLabels = ['ok' => ['OK', 'is-ok'], 'stoc_scazut' => ['Stoc scăzut', 'is-low'], 'gol' => ['Gol', 'is-empty'], 'inactiv' => ['Inactiv', 'is-off']];
$typeIcons = ['butoi' => 'bi-database', 'bidon' => 'bi-cup-straw', 'ibc' => 'bi-box', 'rezervor' => 'bi-hdd-stack', 'altul' => 'bi-archive'];
$vehicleLabel = static fn (array $v): string => trim((string) ($v['nr_inmatriculare'] ?? '') . ' - ' . trim((string) ($v['marca'] ?? '') . ' ' . (string) ($v['model'] ?? '')), ' -');
$fmtDate = static fn (?string $value, bool $time = false): string => $value ? date($time ? 'd.m.Y H:i' : 'd.m.Y', (int) strtotime($value)) : '—';

$boot = [
    'endpoints' => [
        'op' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_op']),
        'container' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_container_save']),
        'product' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_product_save']),
        'compat' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_compat_save']),
        'system' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_system_save']),
        'event' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_system_event']),
        'event_cancel' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_event_cancel']),
        'adblue_sync' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_adblue_sync']),
        'alert_close' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_alert_close']),
    ],
    'containers' => array_map(static fn (array $c): array => [
        'id' => (int) $c['id'], 'cod' => (string) $c['cod'], 'tip' => (string) $c['tip'], 'tip_label' => (string) $c['tip_label'],
        'tip_personalizat' => (string) ($c['tip_personalizat'] ?? ''), 'produs_id' => (int) ($c['produs_id'] ?? 0), 'produs' => (string) ($c['produs'] ?? ''),
        'um' => (string) ($c['um'] ?? ''), 'cantitate' => (float) $c['cantitate'], 'capacitate' => (float) $c['capacitate'],
        'prag_minim' => $c['prag_minim'] !== null ? (float) $c['prag_minim'] : null, 'locatie' => (string) ($c['locatie'] ?? ''),
        'status' => (string) $c['status'], 'lot' => (string) ($c['lot'] ?? ''), 'data_expirare' => (string) ($c['data_expirare'] ?? ''),
        'observatii' => (string) ($c['observatii'] ?? ''), 'stare_expirare' => (string) $c['stare_expirare'],
    ], $containers),
    'products' => array_values(array_map(static fn (array $p): array => [
        'id' => (int) $p['id'], 'denumire' => (string) $p['denumire'], 'um' => (string) $p['um'], 'tip_articol' => (string) $p['tip_articol'],
        'tip_fluid' => (string) ($p['tip_fluid'] ?? ''), 'producator' => (string) ($p['producator'] ?? ''), 'cod_produs' => (string) ($p['cod_produs'] ?? ''),
        'vascozitate' => (string) ($p['vascozitate'] ?? ''), 'specificatie' => (string) ($p['specificatie'] ?? ''), 'aprobari' => (string) ($p['aprobari'] ?? ''),
        'sistem_mecanic' => (string) ($p['sistem_mecanic'] ?? ''), 'tipuri_vehicul' => $p['tipuri_vehicul_list'], 'compatibilitate_note' => (string) ($p['compatibilitate_note'] ?? ''),
        'interval_km' => $p['interval_km'], 'interval_luni' => $p['interval_luni'], 'interval_ore' => $p['interval_ore'],
        'sursa_monitorizare' => array_values(array_filter(explode(',', (string) ($p['sursa_monitorizare'] ?? '')))),
        'prag_alerta' => $p['prag_alerta'], 'critic' => (int) $p['critic'], 'circuit_inchis' => (int) $p['circuit_inchis'],
        'permite_retur' => (int) $p['permite_retur'], 'activ' => (int) $p['activ'], 'component_key' => (string) ($p['component_key'] ?? ''),
        'compatibile' => array_map('intval', $p['compatibile']),
    ], $products)),
    'vehicles' => array_map(static fn (array $v): array => [
        'id' => (int) $v['id'], 'label' => trim((string) $v['nr_inmatriculare'] . ' - ' . (string) ($v['marca'] ?? '') . ' ' . (string) ($v['model'] ?? '')),
        'type' => (string) $v['tip_vehicul'],
    ], $consVehicles),
    'dispenses' => array_map(static fn (array $m): array => [
        'id' => (int) $m['id'], 'label' => date('d.m.Y', (int) strtotime((string) $m['efectuat_la'])) . ' · ' . ($m['nr_inmatriculare'] ?? '') . ' · '
            . ($m['produs'] ?? '') . ' · ' . rtrim(rtrim((string) $m['cantitate'], '0'), '.') . ' ' . $m['um'],
        'produs_id' => (int) $m['produs_id'], 'returnabil' => (float) $m['returnabil'],
    ], (array) ($cons['recent_dispenses'] ?? [])),
    'movement_types' => $movementTypes,
    'systems' => (array) ($cons['systems'] ?? []),
    'presets' => array_map(static fn (array $p): array => ['label' => $p[0], 'tip_fluid' => $p[1], 'sursa_km' => $p[2], 'sursa_ore' => $p[3], 'critic' => $p[4], 'circuit_inchis' => $p[5], 'hint' => $p[6]], VehicleFluidService::SYSTEM_PRESETS),
    'history_url' => build_query_url(['page' => 'mentenanta', 'action' => 'consumabile_system_history']),
    'level_labels' => VehicleFluidService::LEVEL_STATUSES,
    'can_manage' => $canManage,
];
?>
<section class="cons-module" data-cons-module data-cons-sub="<?= e($sub) ?>">
    <nav class="cons-subtabs" aria-label="Secțiuni consumabile">
        <?php foreach (ConsumabileController::SUBTABS as $key => $label): ?>
            <a class="cons-subtab<?= $sub === $key ? ' is-active' : '' ?>" href="<?= e($consUrl(['sub' => $key])) ?>"<?= $sub === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <span class="cons-subtabs-actions">
            <?php if ($canOperate): ?>
                <button type="button" class="btn btn-sm btn-primary" data-cons-open-op="transfer"><i class="bi bi-arrow-left-right me-1"></i>Transfer</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-cons-open-op="completare"><i class="bi bi-droplet-half me-1"></i>Completare vehicul</button>
            <?php endif; ?>
        </span>
    </nav>

<?php if ($sub === 'overview'): ?>
    <?php
    $ov = (array) ($cons['overview'] ?? []);
    $unitLines = static function (array $values) use ($qty): string {
        if ($values === []) {
            return '0';
        }
        $parts = [];
        foreach ($values as $unit => $value) {
            $parts[] = '<span>' . e($qty($value, (string) $unit)) . '</span>';
        }

        return implode('', $parts);
    };
    $articles = (array) ($ov['catalog_articles'] ?? []);
    ?>
    <div class="cons-kpis">
        <a class="cons-kpi" href="<?= e($consUrl(['sub' => 'stocuri'])) ?>">
            <i class="bi bi-droplet tone-blue"></i>
            <div><small>Produse consumabile</small><strong><?= e((string) ($ov['products'] ?? 0)) ?></strong>
                <em><?= e((string) ($articles['fluid'] ?? 0)) ?> fluide · <?= e((string) ($articles['consumabil'] ?? 0)) ?> consumabile în catalog</em></div>
        </a>
        <a class="cons-kpi" href="<?= e($consUrl(['sub' => 'stocuri'])) ?>">
            <i class="bi bi-database tone-green"></i>
            <div><small>Cantitate în stoc</small><strong class="cons-kpi-units"><?= $unitLines((array) ($ov['stock_by_unit'] ?? [])) ?></strong>
                <em><?= e((string) ($ov['containers'] ?? 0)) ?> recipiente active</em></div>
        </a>
        <a class="cons-kpi<?= count((array) ($ov['low_containers'] ?? [])) > 0 ? ' is-alert' : '' ?>" href="<?= e($consUrl(['sub' => 'stocuri', 'state' => 'stoc_scazut'])) ?>">
            <i class="bi bi-stack tone-orange"></i>
            <div><small>Recipiente sub prag / goale</small><strong><?= e((string) count((array) ($ov['low_containers'] ?? []))) ?></strong>
                <em>prag minim atins</em></div>
        </a>
        <a class="cons-kpi" href="<?= e($consUrl(['sub' => 'miscari', 'tip' => 'completare', 'from' => date('Y-m-d', strtotime('-30 days'))])) ?>">
            <i class="bi bi-truck tone-purple"></i>
            <div><small>Consum pe vehicule (30 zile)</small><strong class="cons-kpi-units"><?= $unitLines((array) ($ov['consumption_30d'] ?? [])) ?></strong>
                <em><?= e((string) ($ov['vehicles_30d'] ?? 0)) ?> vehicule completate</em></div>
        </a>
        <a class="cons-kpi<?= !empty($ov['losses_30d']) ? ' is-alert' : '' ?>" href="<?= e($consUrl(['sub' => 'miscari', 'tip' => 'pierdere', 'from' => date('Y-m-d', strtotime('-30 days'))])) ?>">
            <i class="bi bi-exclamation-triangle tone-red"></i>
            <div><small>Pierderi / vărsări (30 zile)</small><strong class="cons-kpi-units"><?= $unitLines((array) ($ov['losses_30d'] ?? [])) ?></strong>
                <em>înregistrate manual</em></div>
        </a>
        <?php
        $fs = (array) ($ov['fluid_status'] ?? []);
        $fsVehicles = (array) ($fs['vehicles'] ?? []);
        $fsSystems = (array) ($fs['systems'] ?? []);
        ?>
        <a class="cons-kpi<?= ($fsVehicles['verificare_necesara'] ?? 0) > 0 ? ' is-alert' : '' ?>" href="<?= e($consUrl(['sub' => 'vehicule', 'status' => 'verificare_necesara'])) ?>">
            <i class="bi bi-clipboard-check tone-blue"></i>
            <div><small>Vehicule de verificat</small><strong><?= e((string) ($fsVehicles['verificare_necesara'] ?? 0)) ?></strong>
                <em><?= e((string) ($fsSystems['verificare_necesara'] ?? 0)) ?> sisteme · <?= e((string) ($fsSystems['date_insuficiente'] ?? 0)) ?> cu date insuficiente</em></div>
        </a>
        <a class="cons-kpi<?= ($fsVehicles['completare_necesara'] ?? 0) > 0 ? ' is-alert' : '' ?>" href="<?= e($consUrl(['sub' => 'vehicule', 'status' => 'completare_necesara'])) ?>">
            <i class="bi bi-droplet-half tone-orange"></i>
            <div><small>Completare necesară</small><strong><?= e((string) ($fsVehicles['completare_necesara'] ?? 0)) ?></strong>
                <em>nivel scăzut confirmat prin verificare</em></div>
        </a>
        <a class="cons-kpi<?= ($fsSystems['revizie_depasita'] ?? 0) > 0 ? ' is-alert' : '' ?>" href="<?= e($consUrl(['sub' => 'vehicule', 'status' => ($fsSystems['revizie_depasita'] ?? 0) > 0 ? 'revizie_depasita' : 'revizie_apropiata'])) ?>">
            <i class="bi bi-wrench-adjustable tone-purple"></i>
            <div><small>Revizii fluide apropiate</small><strong><?= e((string) ($fsSystems['revizie_apropiata'] ?? 0)) ?></strong>
                <em><?= e((string) ($fsSystems['revizie_depasita'] ?? 0)) ?> depășite</em></div>
        </a>
        <a class="cons-kpi<?= ($fsSystems['consum_anormal'] ?? 0) > 0 ? ' is-alert' : '' ?>" href="<?= e($consUrl(['sub' => 'vehicule', 'status' => 'consum_anormal'])) ?>">
            <i class="bi bi-activity tone-red"></i>
            <div><small>Consum anormal / pierderi</small><strong><?= e((string) ($fsSystems['consum_anormal'] ?? 0)) ?></strong>
                <em>sisteme cu pierdere suspectată</em></div>
        </a>
    </div>

    <div class="cons-grid-2">
        <article class="cons-card">
            <header><h2><i class="bi bi-bar-chart"></i> Stoc pe produse</h2><span>cantitățile nu se adună între unități de măsură</span></header>
            <div class="table-responsive"><table class="table cons-table align-middle mb-0">
                <thead><tr><th>Produs</th><th>Tip</th><th>Stoc / capacitate</th><th>Recipiente</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($ov['by_product'] ?? []) as $row): ?>
                    <?php $pct = (float) $row['capacitate'] > 0 ? min(100, (float) $row['cantitate'] * 100 / (float) $row['capacitate']) : 0; ?>
                    <tr>
                        <td><a href="<?= e($consUrl(['sub' => 'stocuri', 'produs_id' => (int) $row['id']])) ?>"><strong><?= e((string) $row['denumire']) ?></strong></a></td>
                        <td><?= e($fluidTypes[(string) $row['tip_fluid']] ?? '—') ?></td>
                        <td><div class="cons-fill"><span class="cons-fill-bar"><i style="width: <?= e((string) round($pct, 1)) ?>%"></i></span><b><?= e($qty($row['cantitate'], (string) $row['um'])) ?></b><small>/ <?= e($qty($row['capacitate'], (string) $row['um'])) ?></small></div></td>
                        <td><?= e((string) $row['recipiente']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($ov['by_product'])): ?><tr><td colspan="4" class="cons-empty">Niciun produs în catalog. Adaugă-l din „Stocuri și recipiente”.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </article>
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
    </div>

<?php elseif ($sub === 'stocuri'): ?>
    <form class="cons-filter" method="get">
        <input type="hidden" name="page" value="mentenanta"><input type="hidden" name="action" value="stock"><input type="hidden" name="tab" value="consumabile"><input type="hidden" name="sub" value="stocuri">
        <label>Produs<select class="form-select form-select-sm" name="produs_id"><option value="">Toate</option><?php foreach ($products as $p): ?><option value="<?= e((string) $p['id']) ?>"<?= (int) ($consFilters['produs_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e((string) $p['denumire']) ?></option><?php endforeach; ?></select></label>
        <label>Tip fluid<select class="form-select form-select-sm" name="tip_fluid"><option value="">Toate</option><?php foreach ($fluidTypes as $k => $l): ?><option value="<?= e($k) ?>"<?= ($consFilters['tip_fluid'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Stare<select class="form-select form-select-sm" name="state"><option value="">Toate</option><?php foreach ($stateLabels as $k => [$l]): ?><option value="<?= e($k) ?>"<?= ($consFilters['state'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="cons-check"><input type="checkbox" name="inactive" value="1"<?= !empty($_GET['inactive']) ? ' checked' : '' ?>> și inactive</label>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrează</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($consUrl(['sub' => 'stocuri'])) ?>">Resetează</a>
    </form>

    <article class="cons-card">
        <header>
            <h2><i class="bi bi-database"></i> Recipiente și stocuri</h2><span><?= e((string) count($containers)) ?> recipiente</span>
            <?php if ($canManage): ?><button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-cons-container-new><i class="bi bi-plus-lg me-1"></i>Adaugă recipient</button><?php endif; ?>
        </header>
        <div class="table-responsive"><table class="table cons-table align-middle mb-0">
            <thead><tr><th>Identificator</th><th>Tip recipient</th><th>Produs</th><th>Capacitate</th><th>Cantitate curentă</th><th>Locație</th><th>Lot / expirare</th><th>Status</th><th class="text-end">Acțiuni</th></tr></thead>
            <tbody>
            <?php foreach ($containers as $c): ?>
                <?php [$stateLabel, $stateClass] = $stateLabels[$c['stare']] ?? ['—', '']; ?>
                <tr data-cons-container-row="<?= e((string) $c['id']) ?>">
                    <td><strong><?= e((string) $c['cod']) ?></strong></td>
                    <td><i class="bi <?= e($typeIcons[(string) $c['tip']] ?? 'bi-archive') ?> cons-type-icon"></i> <?= e((string) $c['tip_label']) ?></td>
                    <td><?= $c['produs'] !== null ? e((string) $c['produs']) : '<span class="text-muted">—</span>' ?><?php if (!empty($c['vascozitate'])): ?><small class="d-block text-muted"><?= e((string) $c['vascozitate']) ?></small><?php endif; ?></td>
                    <td><?= e($qty($c['capacitate'], (string) ($c['um'] ?? ''))) ?></td>
                    <td><div class="cons-fill"><span class="cons-fill-bar <?= e($stateClass) ?>"><i style="width: <?= e((string) min(100, (float) $c['procent'])) ?>%"></i></span><b><?= e($qty($c['cantitate'], (string) ($c['um'] ?? ''))) ?></b><small><?= e((string) round((float) $c['procent'])) ?>%</small></div></td>
                    <td><?= e((string) ($c['locatie'] ?? '') ?: '—') ?></td>
                    <td><?= e((string) ($c['lot'] ?? '') ?: '—') ?><?php if (!empty($c['data_expirare'])): ?><small class="d-block cons-exp-<?= e((string) $c['stare_expirare']) ?>"><?= e($fmtDate((string) $c['data_expirare'])) ?></small><?php endif; ?></td>
                    <td><span class="cons-pill <?= e($stateClass) ?>"><?= e($stateLabel) ?></span><?php if ($c['status'] === 'in_uz'): ?> <span class="cons-pill is-use">În uz</span><?php endif; ?></td>
                    <td class="text-end cons-actions">
                        <a class="btn btn-sm btn-light" href="<?= e($consUrl(['sub' => 'miscari', 'recipient_id' => (int) $c['id']])) ?>" title="Istoricul recipientului"><i class="bi bi-clock-history"></i></a>
                        <?php if ($canOperate && $c['status'] !== 'inactiv'): ?>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" title="Operațiuni"><i class="bi bi-chevron-right"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" type="button" data-cons-open-op="intrare" data-dest="<?= e((string) $c['id']) ?>"><i class="bi bi-box-arrow-in-down me-2"></i>Intrare în stoc</button></li>
                                    <li><button class="dropdown-item" type="button" data-cons-open-op="transfer" data-source="<?= e((string) $c['id']) ?>"><i class="bi bi-arrow-left-right me-2"></i>Transfer din acest recipient</button></li>
                                    <li><button class="dropdown-item" type="button" data-cons-open-op="completare" data-source="<?= e((string) $c['id']) ?>"><i class="bi bi-droplet-half me-2"></i>Completare vehicul</button></li>
                                    <li><button class="dropdown-item" type="button" data-cons-open-op="schimb_complet" data-source="<?= e((string) $c['id']) ?>"><i class="bi bi-arrow-repeat me-2"></i>Schimb complet fluid</button></li>
                                    <li><button class="dropdown-item" type="button" data-cons-open-op="pierdere" data-source="<?= e((string) $c['id']) ?>"><i class="bi bi-exclamation-triangle me-2"></i>Pierdere / vărsare</button></li>
                                    <li><button class="dropdown-item" type="button" data-cons-open-op="ajustare" data-container="<?= e((string) $c['id']) ?>"><i class="bi bi-sliders me-2"></i>Ajustare inventar</button></li>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($canManage): ?><button class="btn btn-sm btn-light" type="button" data-cons-container-edit="<?= e((string) $c['id']) ?>" title="Editează recipientul"><i class="bi bi-pencil"></i></button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($containers === []): ?><tr><td colspan="9" class="cons-empty">Niciun recipient pentru filtrele alese.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </article>

    <article class="cons-card">
        <header>
            <h2><i class="bi bi-journal-text"></i> Catalog produse</h2><span>o singură identitate per produs, istoric separat per vehicul</span>
            <?php if ($canManage): ?><button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-cons-product-new><i class="bi bi-plus-lg me-1"></i>Adaugă produs</button><?php endif; ?>
        </header>
        <div class="table-responsive"><table class="table cons-table align-middle mb-0">
            <thead><tr><th>Produs</th><th>Tip</th><th>Vâscozitate / specificație</th><th>UM</th><th>Sistem</th><th>Componentă catalog</th><th>Compatibil cu</th><th>Stoc</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr class="<?= (int) $p['activ'] === 1 ? '' : 'is-inactive' ?>">
                    <td><strong><?= e((string) $p['denumire']) ?></strong><small class="d-block text-muted"><?= e(trim((string) ($p['producator'] ?? '') . ' ' . (string) ($p['cod_produs'] ?? ''))) ?></small></td>
                    <td><?= e($fluidTypes[(string) $p['tip_fluid']] ?? ConsumabileStockService::PRODUCT_KINDS[(string) $p['tip_articol']] ?? '') ?><?php if ((int) $p['critic'] === 1): ?> <span class="cons-pill is-low">critic</span><?php endif; ?></td>
                    <td><?= e((string) ($p['vascozitate'] ?? '') ?: '—') ?><small class="d-block text-muted"><?= e((string) ($p['specificatie'] ?? '')) ?></small></td>
                    <td><?= e((string) $p['um']) ?></td>
                    <td><?= e((string) ($p['sistem_mecanic'] ?? '') ?: '—') ?></td>
                    <td><?= $p['component'] !== null ? e((string) $p['component']['name'] . ' (' . $p['component']['category'] . ')') : '<span class="text-muted">—</span>' ?></td>
                    <td><?php $names = array_map(static fn (int $id): string => (string) ($products[$id]['denumire'] ?? ''), $p['compatibile']); ?><?= $names !== [] ? e(implode(', ', $names)) : '<span class="text-muted">doar același produs</span>' ?></td>
                    <td><?= e($qty($p['stoc_total'], (string) $p['um'])) ?><small class="d-block text-muted"><?= e((string) $p['recipiente']) ?> recipiente</small></td>
                    <td class="text-end"><?php if ($canManage): ?><button class="btn btn-sm btn-light" type="button" data-cons-product-edit="<?= e((string) $p['id']) ?>" title="Editează produsul"><i class="bi bi-pencil"></i></button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?><tr><td colspan="9" class="cons-empty">Catalogul de produse e gol.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </article>

<?php elseif ($sub === 'miscari'): ?>
    <?php $mv = (array) ($cons['movements'] ?? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]); ?>
    <form class="cons-filter" method="get">
        <input type="hidden" name="page" value="mentenanta"><input type="hidden" name="action" value="stock"><input type="hidden" name="tab" value="consumabile"><input type="hidden" name="sub" value="miscari">
        <?php if (!empty($consFilters['recipient_id'])): ?><input type="hidden" name="recipient_id" value="<?= e((string) $consFilters['recipient_id']) ?>"><?php endif; ?>
        <label>Operațiune<select class="form-select form-select-sm" name="tip"><option value="">Toate</option><?php foreach ($movementTypes as $k => $l): ?><option value="<?= e($k) ?>"<?= ($consFilters['tip'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Produs<select class="form-select form-select-sm" name="produs_id"><option value="">Toate</option><?php foreach ($products as $p): ?><option value="<?= e((string) $p['id']) ?>"<?= (int) ($consFilters['produs_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e((string) $p['denumire']) ?></option><?php endforeach; ?></select></label>
        <label>Vehicule<select class="form-select form-select-sm" name="vehicle_ids[]" multiple size="1" data-cons-multi><?php foreach ($consVehicles as $v): ?><option value="<?= e((string) $v['id']) ?>"<?= in_array((int) $v['id'], (array) ($consFilters['vehicle_ids'] ?? []), true) ? ' selected' : '' ?>><?= e((string) $v['nr_inmatriculare']) ?></option><?php endforeach; ?></select></label>
        <label>De la<input class="form-control form-control-sm" type="date" name="from" value="<?= e((string) ($consFilters['from'] ?? '')) ?>"></label>
        <label>Până la<input class="form-control form-control-sm" type="date" name="to" value="<?= e((string) ($consFilters['to'] ?? '')) ?>"></label>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrează</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($consUrl(['sub' => 'miscari'])) ?>">Resetează</a>
    </form>
    <?php if (!empty($consFilters['recipient_id'])): ?>
        <?php $filterContainer = array_values(array_filter($containers, static fn ($c) => (int) $c['id'] === (int) $consFilters['recipient_id']))[0] ?? null; ?>
        <div class="cons-note"><i class="bi bi-funnel"></i> Istoricul recipientului <strong><?= e((string) ($filterContainer['cod'] ?? ('#' . $consFilters['recipient_id']))) ?></strong>. <a href="<?= e($consUrl(['sub' => 'miscari'])) ?>">Toate operațiunile</a></div>
    <?php endif; ?>
    <article class="cons-card">
        <header><h2><i class="bi bi-arrow-left-right"></i> Transferuri și consum</h2><span><?= e((string) $mv['total']) ?> operațiuni · transferul între recipiente nu e consum</span></header>
        <div class="table-responsive"><table class="table cons-table cons-moves align-middle mb-0">
            <thead><tr><th>Data</th><th>Operațiune</th><th>Produs</th><th class="text-end">Cantitate</th><th>Sursă (înainte → după)</th><th>Destinație</th><th>Utilizator</th><th>Motiv / observații</th><th></th></tr></thead>
            <tbody>
            <?php foreach ((array) $mv['rows'] as $m): ?>
                <tr class="<?= $m['stornata'] ? 'is-reversed' : '' ?> cons-move-<?= e((string) $m['tip']) ?>">
                    <td><?= e($fmtDate((string) $m['efectuat_la'], true)) ?></td>
                    <td><span class="cons-move-type"><?= e((string) $m['tip_label']) ?></span><?php if ($m['este_consum']): ?> <span class="cons-pill is-use">consum</span><?php endif; ?><?php if ($m['stornata']): ?> <span class="cons-pill is-off">stornată</span><?php endif; ?></td>
                    <td><?= e((string) $m['produs']) ?></td>
                    <td class="text-end"><strong><?= e($qty($m['cantitate'], (string) $m['um'])) ?></strong><?php if ($m['cantitate_scoasa'] !== null): ?><small class="d-block text-muted">scos: <?= e($qty($m['cantitate_scoasa'], (string) $m['um'])) ?></small><?php endif; ?></td>
                    <td><?php if ($m['sursa_cod'] !== null): ?><strong><?= e((string) $m['sursa_cod']) ?></strong> <small><?= e($qty($m['sursa_inainte'])) ?> → <?= e($qty($m['sursa_dupa'])) ?></small><?php else: ?>—<?php endif; ?></td>
                    <td>
                        <?php if ($m['dest_cod'] !== null): ?><strong><?= e((string) $m['dest_cod']) ?></strong> <small><?= e($qty($m['dest_inainte'])) ?> → <?= e($qty($m['dest_dupa'])) ?></small>
                        <?php elseif ($m['nr_inmatriculare'] !== null): ?><i class="bi bi-truck"></i> <strong><?= e((string) $m['nr_inmatriculare']) ?></strong><?= $m['km_vehicul'] ? ' <small>' . e(format_number_ro((float) $m['km_vehicul'], 0)) . ' km</small>' : '' ?><?= $m['sistem'] ? ' <small class="d-block text-muted">' . e((string) $m['sistem']) . '</small>' : '' ?>
                        <?php else: ?>—<?php endif; ?>
                        <?php if ($m['tip'] === 'retur' && $m['nr_inmatriculare'] !== null): ?><small class="d-block text-muted">din <?= e((string) $m['nr_inmatriculare']) ?></small><?php endif; ?>
                    </td>
                    <td><?= e((string) ($m['utilizator'] ?? '—')) ?></td>
                    <td class="cons-reason"><?= e(trim((string) ($m['motiv'] ?? '') . ((string) ($m['observatii'] ?? '') !== '' ? ' · ' . $m['observatii'] : '')) ?: '—') ?><?php if (!empty($m['document'])): ?><small class="d-block text-muted">doc. <?= e((string) $m['document']) ?></small><?php endif; ?></td>
                    <td class="text-end"><?php if ($canManage && !$m['stornata'] && $m['tip'] !== 'stornare'): ?><button class="btn btn-sm btn-light" type="button" data-cons-open-op="stornare" data-ref="<?= e((string) $m['id']) ?>" data-ref-label="<?= e($fmtDate((string) $m['efectuat_la'], true) . ' · ' . $m['tip_label'] . ' · ' . $qty($m['cantitate'], (string) $m['um']) . ' ' . $m['produs']) ?>" title="Stornează (mișcare compensatorie)"><i class="bi bi-arrow-counterclockwise"></i></button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($mv['rows'])): ?><tr><td colspan="9" class="cons-empty">Nicio operațiune pentru filtrele alese.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ((int) $mv['pages'] > 1): ?>
            <nav class="cons-pager">
                <?php for ($i = 1; $i <= (int) $mv['pages']; $i++): ?>
                    <?php if ($i === 1 || $i === (int) $mv['pages'] || abs($i - (int) $mv['page']) <= 2): ?>
                        <a class="<?= $i === (int) $mv['page'] ? 'is-active' : '' ?>" href="<?= e(build_query_url(array_merge($_GET, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                    <?php elseif (abs($i - (int) $mv['page']) === 3): ?><span>…</span><?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </article>

<?php elseif ($sub === 'vehicule'): ?>
    <?php
    $vehicleRows = (array) ($cons['vehicle_rows'] ?? []);
    $unconfigured = (array) ($cons['unconfigured_vehicles'] ?? []);
    $levelLabels = VehicleFluidService::LEVEL_STATUSES;
    $teleLabels = ['actual' => ['actuală', 'is-ok'], 'invechit' => ['învechită', 'is-low'], 'indisponibil' => ['indisponibilă', 'is-empty'], 'nefolosit' => ['—', 'is-off']];
    $km = static fn (?float $v): string => $v === null ? '—' : format_number_ro($v, 0);
    ?>
    <form class="cons-filter" method="get">
        <input type="hidden" name="page" value="mentenanta"><input type="hidden" name="action" value="stock"><input type="hidden" name="tab" value="consumabile"><input type="hidden" name="sub" value="vehicule">
        <label>Vehicule<select class="form-select form-select-sm" name="vehicle_ids[]" multiple size="1" data-cons-multi><?php foreach ($consVehicles as $v): ?><option value="<?= e((string) $v['id']) ?>"<?= in_array((int) $v['id'], (array) ($consFilters['vehicle_ids'] ?? []), true) ? ' selected' : '' ?>><?= e((string) $v['nr_inmatriculare']) ?></option><?php endforeach; ?></select></label>
        <label>Tip fluid<select class="form-select form-select-sm" name="tip_fluid"><option value="">Toate</option><?php foreach ($fluidTypes as $k => $l): ?><option value="<?= e($k) ?>"<?= ($consFilters['tip_fluid'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Sistem<select class="form-select form-select-sm" name="sistem"><option value="">Toate</option><?php foreach (VehicleFluidService::SYSTEM_PRESETS as $k => $preset): ?><option value="<?= e($k) ?>"<?= ($consFilters['sistem'] ?? '') === $k ? ' selected' : '' ?>><?= e($preset[0]) ?></option><?php endforeach; ?></select></label>
        <label>Status<select class="form-select form-select-sm" name="status"><option value="">Toate</option><?php foreach (VehicleFluidService::STATUSES as $k => [$l]): ?><option value="<?= e($k) ?>"<?= ($consFilters['status'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Ultima înregistrare de la<input class="form-control form-control-sm" type="date" name="from" value="<?= e((string) ($consFilters['from'] ?? '')) ?>"></label>
        <label>până la<input class="form-control form-control-sm" type="date" name="to" value="<?= e((string) ($consFilters['to'] ?? '')) ?>"></label>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrează</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($consUrl(['sub' => 'vehicule'])) ?>">Resetează</a>
    </form>
    <article class="cons-card">
        <header>
            <h2><i class="bi bi-truck"></i> Situație generală vehicule</h2><span><?= e((string) count($vehicleRows)) ?> sisteme monitorizate</span>
            <span class="cons-legend"><span class="cons-level is-measured">măsurat</span><span class="cons-level is-estimated">estimat</span></span>
            <?php if ($canManage): ?><button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-cons-system-new><i class="bi bi-plus-lg me-1"></i>Configurează sistem</button><?php endif; ?>
        </header>
        <div class="table-responsive"><table class="table cons-table cons-vehicle-table align-middle mb-0">
            <thead><tr><th>Nr. înmatriculare</th><th>Sistem / fluid</th><th>Capacitate nominală</th><th>Nivel curent</th><th>Ultima verificare / completare</th><th>KM CAN / KM GPS</th><th>Ore funcționare</th><th>Până la revizie</th><th>Consum observat</th><th>Status</th><th>Acțiune recomandată</th></tr></thead>
            <tbody>
            <?php foreach ($vehicleRows as $row): ?>
                <?php
                $tele = (array) $row['telemetry'];
                [$teleLabel, $teleClass] = $teleLabels[$tele['state']] ?? ['—', 'is-off'];
                $level = (array) $row['level'];
                $rowCons = (array) $row['consumption'];
                $unit = (string) ($row['um'] ?? 'L');
                ?>
                <tr data-cons-system-row="<?= e((string) $row['id']) ?>">
                    <td><strong><?= e((string) $row['nr_inmatriculare']) ?></strong><small class="d-block text-muted"><?= e(trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? ''))) ?></small></td>
                    <td><strong><?= e((string) $row['denumire']) ?></strong><small class="d-block text-muted"><?= e($fluidTypes[(string) $row['tip_fluid']] ?? '') ?><?= !empty($row['produs']) ? ' · ' . e((string) $row['produs']) : '' ?></small>
                        <?php if ((int) $row['circuit_inchis'] === 1): ?><span class="cons-pill is-off">circuit închis</span><?php endif; ?><?php if ((int) $row['critic'] === 1): ?> <span class="cons-pill is-low">critic</span><?php endif; ?></td>
                    <td><?= $row['capacitate_nominala'] !== null ? e($qty($row['capacitate_nominala'], $unit)) . '<small class="d-block text-muted">nominal, nu disponibil</small>' : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($level['state'] === 'masurat'): ?>
                            <span class="cons-level is-measured is-<?= e((string) $level['status']) ?>" title="Măsurat la verificare"><?= e($levelLabels[$level['status']] ?? $level['status']) ?><?= $level['value'] !== null ? ' · ' . e(VehicleFluidService::fmt((float) $level['value'])) . ' ' . e((string) $level['unit']) : '' ?></span>
                            <small class="d-block text-muted">măsurat <?= e($fmtDate((string) $level['at'])) ?></small>
                        <?php elseif ($level['state'] === 'completat_la_reper'): ?>
                            <span class="cons-level is-measured is-la_maxim" title="Completat până la reper, confirmat de operator">completat la reper</span>
                            <small class="d-block text-muted"><?= e($fmtDate((string) $level['at'])) ?></small>
                        <?php else: ?>
                            <span class="cons-pill is-off" title="Nu există o măsurătoare după ultima operațiune">necunoscut</span>
                        <?php endif; ?>
                        <?php if (($rowCons['estimated_missing'] ?? null) !== null && (float) $rowCons['estimated_missing'] > 0): ?>
                            <span class="cons-level is-estimated d-block mt-1" title="Estimare din consumul observat, nu măsurătoare">lipsă estimată ≈ <?= e(VehicleFluidService::fmt((float) $rowCons['estimated_missing'])) ?> <?= e($unit) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['last_check'] !== null): ?><small class="d-block">verificat <?= e($fmtDate((string) $row['last_check']['efectuat_la'])) ?></small><?php endif; ?>
                        <?php if ($row['last_topup'] !== null): ?><small class="d-block"><?= e($row['last_topup']['tip'] === 'schimb_complet' ? 'schimb' : 'completare') ?> <?= e($fmtDate((string) $row['last_topup']['efectuat_la'])) ?> · <?= e($qty($row['last_topup']['cantitate'], $unit)) ?></small><?php endif; ?>
                        <?php if ($row['last_check'] === null && $row['last_topup'] === null): ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <small class="d-block">CAN <?= e($km($tele['km_can'] !== null ? (float) $tele['km_can'] : null)) ?></small>
                        <small class="d-block">GPS <?= e($km($tele['km_gps'] !== null ? (float) $tele['km_gps'] : null)) ?></small>
                        <?php if ($tele['state'] !== 'nefolosit'): ?><span class="cons-pill <?= e($teleClass) ?>" title="<?= e($tele['at'] ? 'Ultima citire: ' . $fmtDate((string) $tele['at'], true) : 'Fără citiri') ?>">telemetrie <?= e($teleLabel) ?></span><?php endif; ?>
                        <?php if (!empty($tele['fallback'])): ?><small class="d-block text-warning-emphasis">fallback GPS (CAN lipsă)</small><?php endif; ?>
                    </td>
                    <td>
                        <?php $hours = (array) $row['hours']; ?>
                        <?php if (($hours['source'] ?? 'none') === 'none'): ?><span class="text-muted">—</span>
                        <?php elseif ($hours['value'] !== null): ?><strong><?= e(VehicleFluidService::fmt((float) $hours['value'], 1)) ?> h</strong><small class="d-block text-muted"><?= e($hours['source'] === 'ore_pto' ? 'pompă / PTO' : 'motor') ?> de la schimb</small>
                        <?php else: ?><span class="cons-pill is-off" title="<?= e((string) $hours['note']) ?>">indisponibil</span><small class="d-block text-muted"><?= e((string) $hours['note']) ?></small><?php endif; ?>
                    </td>
                    <td class="cons-usage">
                        <?php $usage = (array) $row['usage']; $hasUsage = false; ?>
                        <?php foreach (['km' => 'km', 'months' => 'luni', 'hours' => 'h'] as $uk => $ul): ?>
                            <?php if (($usage[$uk] ?? null) === null) { continue; } $u = $usage[$uk]; $hasUsage = true; $pct = (float) $u['pct']; ?>
                            <div class="cons-usage-line" title="Interval de service parcurs <?= e(VehicleFluidService::fmt($pct)) ?>% (nu degradarea fizică a fluidului)">
                                <span class="cons-fill-bar <?= $pct >= 100 ? 'is-empty' : ($pct >= (int) $row['avertizare_procent'] ? 'is-low' : '') ?>"><i style="width: <?= e((string) min(100, $pct)) ?>%"></i></span>
                                <small><?= e(VehicleFluidService::fmt((float) $u['used'], 1)) ?> / <?= e(VehicleFluidService::fmt((float) $u['interval'], 0)) ?> <?= e($ul) ?> · <?= $u['remaining'] >= 0 ? 'rest ' . e(VehicleFluidService::fmt((float) $u['remaining'], 1)) : 'depășit cu ' . e(VehicleFluidService::fmt(abs((float) $u['remaining']), 1)) ?> <?= e($ul) ?> (<?= e(VehicleFluidService::fmt($pct)) ?>%)</small>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$hasUsage): ?>
                            <?php $hasInterval = $row['interval_km'] !== null || $row['interval_luni'] !== null || $row['interval_ore'] !== null; ?>
                            <span class="text-muted"><?= !$hasInterval ? 'fără interval' : ($row['reference']['date'] === null ? 'fără referință (ultimul schimb)' : e((string) ($row['km_note'] !== '' ? $row['km_note'] : 'lipsesc datele de km / ore'))) ?></span>
                        <?php endif; ?>
                        <?php if ($row['reference']['date'] !== null): ?><small class="d-block text-muted">ultimul schimb <?= e($fmtDate((string) $row['reference']['date'])) ?><?= $row['reference']['origin'] === 'manual' ? ' (manual)' : '' ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?php if (($rowCons['observed'] ?? null) !== null): ?>
                            <strong><?= e(VehicleFluidService::fmt((float) $rowCons['observed'], 3)) ?> <?= e($unit) ?>/1.000 km</strong>
                            <small class="d-block text-muted">observat pe <?= e(format_number_ro((float) $rowCons['span_km'], 0)) ?> km · încredere <?= e((string) $rowCons['confidence']) ?></small>
                        <?php else: ?><span class="text-muted" title="Necesită completări până la reper cu km cunoscuți">date insuficiente</span><?php endif; ?>
                    </td>
                    <td><span class="cons-pill <?= e((string) $row['status_class']) ?>"><?= e((string) $row['status_label']) ?></span><?php if (!empty($row['anomaly'])): ?><small class="d-block text-danger"><?= e((string) $row['anomaly']) ?></small><?php endif; ?></td>
                    <td class="cons-row-actions">
                        <small class="d-block mb-1"><?= e((string) $row['action']) ?></small>
                        <?php if ($canOperate): ?>
                            <button class="btn btn-sm btn-light" type="button" data-cons-event="<?= e((string) $row['id']) ?>" title="Verificare nivel"><i class="bi bi-clipboard-check"></i></button>
                            <button class="btn btn-sm btn-light" type="button" data-cons-open-op="completare" data-vehicle="<?= e((string) $row['vehicle_id']) ?>" data-system="<?= e((string) $row['id']) ?>" data-product="<?= e((string) ($row['produs_id'] ?? '')) ?>" title="Completare din stoc"><i class="bi bi-droplet-half"></i></button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-light" type="button" data-cons-history="<?= e((string) $row['id']) ?>" title="Istoric sistem"><i class="bi bi-clock-history"></i></button>
                        <?php if ($canManage): ?><button class="btn btn-sm btn-light" type="button" data-cons-system-edit="<?= e((string) $row['id']) ?>" title="Configurează"><i class="bi bi-gear"></i></button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($vehicleRows === []): ?><tr><td colspan="11" class="cons-empty">Niciun sistem de fluid configurat pentru filtrele alese.<?= $canManage ? ' Folosește „Configurează sistem”.' : '' ?></td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="cons-note"><i class="bi bi-info-circle"></i> Procentul arată intervalul de service parcurs (km / luni / ore de la ultimul schimb), nu degradarea fizică a fluidului. Nivelul se afișează doar dacă a fost măsurat la o verificare; „lipsă estimată” e calculată din consumul observat între completări până la reper. KM CAN vine din raportul SAS; când lipsește, se folosește KM GPS și se marchează „fallback”. Telemetria mai veche de <?= e((string) VehicleFluidService::STALE_HOURS) ?> h e „învechită”.</p>
    </article>
    <?php if ($unconfigured !== [] && empty($consFilters['vehicle_ids']) && empty($consFilters['status'])): ?>
        <details class="cons-card cons-unconfigured">
            <summary><i class="bi bi-exclamation-circle"></i> <?= e((string) count($unconfigured)) ?> vehicule fără sisteme de fluid configurate</summary>
            <div class="cons-unconfigured-list">
                <?php foreach ($unconfigured as $v): ?>
                    <span><?= e((string) $v['nr_inmatriculare']) ?><?php if ($canManage): ?> <button type="button" class="btn btn-sm btn-link p-0" data-cons-system-new data-vehicle="<?= e((string) $v['id']) ?>">configurează</button><?php endif; ?></span>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
<?php elseif ($sub === 'adblue'): ?>
    <?php
    $ab = (array) ($cons['adblue'] ?? []);
    $abRows = (array) ($ab['rows'] ?? []);
    $abUnmatched = (array) ($ab['unmatched'] ?? []);
    $canFix = !empty($cons['can_fix_fillups']);
    $confLabels = ['ridicat' => ['încredere ridicată', 'is-ok'], 'mediu' => ['încredere medie', 'is-use'], 'scazut' => ['încredere scăzută', 'is-low']];
    $abLiters = array_sum(array_map(static fn ($r) => (float) $r['liters'], $abRows));
    $abFills = array_sum(array_map(static fn ($r) => (int) $r['fills'], $abRows));
    $abAnomalies = count(array_filter($abRows, static fn ($r) => $r['anomalies'] !== []));
    $currentUrl = (string) ($_SERVER['REQUEST_URI'] ?? $consUrl(['sub' => 'adblue']));
    $plates = array_map(static fn (array $v): string => (string) $v['nr_inmatriculare'], $consVehicles);
    $fixForm = static function (array $fill) use ($canFix, $plates, $currentUrl): string {
        if (!$canFix) {
            return '';
        }
        $options = '<option value="">Mută pe vehiculul…</option>';
        foreach ($plates as $plate) {
            $options .= '<option value="' . e($plate) . '">' . e($plate) . '</option>';
        }

        return '<form method="post" class="cons-fix-form" action="' . e(build_query_url(['page' => 'carburanti', 'action' => 'set_vehicle'])) . '">'
            . csrf_field() . '<input type="hidden" name="return_url" value="' . e($currentUrl) . '"><input type="hidden" name="fillup_id" value="' . e((string) $fill['id']) . '">'
            . '<select name="vehicle_registration" class="form-select form-select-sm" required>' . $options . '</select>'
            . '<button class="btn btn-sm btn-outline-primary" type="submit" title="Corecția e protejată la resincronizarea CardOil">Mută</button>'
            . (!empty($fill['vehicle_registration_manual']) ? '<button class="btn btn-sm btn-link" type="submit" name="reset_api" value="1" formnovalidate title="Revine pe vehiculul de pe card">înapoi pe card</button>' : '')
            . '</form>';
    };
    ?>
    <form class="cons-filter" method="get">
        <input type="hidden" name="page" value="mentenanta"><input type="hidden" name="action" value="stock"><input type="hidden" name="tab" value="consumabile"><input type="hidden" name="sub" value="adblue">
        <label>Vehicule<select class="form-select form-select-sm" name="vehicle_ids[]" multiple size="1" data-cons-multi><?php foreach ($consVehicles as $v): ?><option value="<?= e((string) $v['id']) ?>"<?= in_array((int) $v['id'], (array) ($consFilters['vehicle_ids'] ?? []), true) ? ' selected' : '' ?>><?= e((string) $v['nr_inmatriculare']) ?></option><?php endforeach; ?></select></label>
        <label>De la<input class="form-control form-control-sm" type="date" name="from" value="<?= e((string) ($ab['from'] ?? '')) ?>"></label>
        <label>Până la<input class="form-control form-control-sm" type="date" name="to" value="<?= e((string) ($ab['to'] ?? '')) ?>"></label>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrează</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($consUrl(['sub' => 'adblue'])) ?>">Resetează</a>
        <?php if ($canOperate): ?>
            <button class="btn btn-sm btn-outline-primary ms-auto" type="button" data-cons-adblue-sync title="Cere din SAS doar intervalele care lipsesc (max. 30 de cereri pe apăsare)"><i class="bi bi-arrow-repeat"></i> Actualizează motorina CAN (SAS)</button>
        <?php endif; ?>
    </form>
    <div class="cons-kpis cons-kpis-4">
        <div class="cons-kpi"><i class="bi bi-droplet tone-blue"></i><div><small>AdBlue alimentat (CardOil)</small><strong><?= e($qty($abLiters, 'L')) ?></strong><em><?= e((string) $abFills) ?> alimentări · <?= e((string) count($abRows)) ?> vehicule</em></div></div>
        <div class="cons-kpi<?= $abUnmatched !== [] ? ' is-alert' : '' ?>"><i class="bi bi-question-diamond tone-orange"></i><div><small>Alimentări neasociate</small><strong><?= e((string) count($abUnmatched)) ?></strong><em>numărul de pe card nu există în flotă</em></div></div>
        <div class="cons-kpi<?= (int) ($ab['missing_can'] ?? 0) > 0 ? ' is-alert' : '' ?>"><i class="bi bi-cloud-download tone-purple"></i><div><small>Intervale fără motorină CAN</small><strong><?= e((string) (int) ($ab['missing_can'] ?? 0)) ?></strong><em>neactualizate din SAS</em></div></div>
        <div class="cons-kpi<?= $abAnomalies > 0 ? ' is-alert' : '' ?>"><i class="bi bi-activity tone-red"></i><div><small>Vehicule cu anomalii AdBlue</small><strong><?= e((string) $abAnomalies) ?></strong><em>față de istoricul propriu</em></div></div>
    </div>
    <article class="cons-card">
        <header><h2><i class="bi bi-droplet-half"></i> AdBlue pe vehicule</h2><span><?= e(date('d.m.Y', (int) strtotime((string) $ab['from']))) ?> – <?= e(date('d.m.Y', (int) strtotime((string) $ab['to']))) ?> · nivel AdBlue CAN: indisponibil în SAS</span></header>
        <div class="table-responsive"><table class="table cons-table cons-vehicle-table align-middle mb-0">
            <thead><tr><th>Nr. înmatriculare</th><th class="text-end">AdBlue alimentat</th><th>Km în fereastră</th><th class="text-end">Consum estimat</th><th class="text-end">Motorină CAN</th><th class="text-end">AdBlue / motorină</th><th>Rest estimat</th><th>Autonomie estimată</th><th>Ultima alimentare</th><th>Încredere</th><th>Anomalii</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($abRows as $r): ?>
                <?php [$confLabel, $confClass] = $confLabels[(string) $r['confidence']] ?? ['date insuficiente', 'is-off']; ?>
                <tr>
                    <td><strong><?= e((string) $r['nr_inmatriculare']) ?></strong><small class="d-block text-muted"><?= e((string) $r['vehicul']) ?></small><?php if (!empty($r['inactive'])): ?><span class="cons-pill is-off">vehicul inactiv</span> <?php endif; ?><?php if ($r['sas_car_id'] === null): ?><span class="cons-pill is-off">fără SAS</span><?php endif; ?></td>
                    <td class="text-end"><strong><?= e($qty($r['liters'], 'L')) ?></strong><small class="d-block text-muted"><?= e((string) $r['fills']) ?> alimentări</small></td>
                    <td><?= $r['km'] !== null ? e(format_number_ro((float) $r['km'], 0)) . ' km<small class="d-block text-muted">' . e((string) $r['km_source']) . '</small>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= $r['l100'] !== null ? '<strong>' . e(AdBlueService::fmt((float) $r['l100'])) . ' L/100 km</strong><small class="d-block text-muted">' . e($qty($r['consumed'], 'L')) . ' consumat</small>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= $r['diesel'] !== null ? e(format_number_ro((float) $r['diesel'], 0)) . ' L' : '<span class="text-muted" title="Motorina consumată din CAN (SAS), nu alimentările">' . ($r['can_missing'] > 0 ? e((string) $r['can_missing']) . ' intervale neactualizate' : 'indisponibil') . '</span>' ?></td>
                    <td class="text-end"><?= $r['ratio'] !== null ? '<strong>' . e(AdBlueService::fmt((float) $r['ratio'])) . '%</strong><small class="d-block text-muted">istoricul vehiculului</small>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $r['remaining'] !== null ? '<span class="cons-level is-estimated">≈ ' . e(AdBlueService::fmt((float) $r['remaining'], 1)) . ' L</span>' : '<span class="text-muted" title="' . e((string) $r['remaining_note']) . '">necunoscut</span><small class="d-block text-muted">' . e((string) $r['remaining_note']) . '</small>' ?></td>
                    <td><?= $r['autonomy_km'] !== null ? '<span class="cons-level is-estimated">≈ ' . e(format_number_ro((float) $r['autonomy_km'], 0)) . ' km</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?php if ($r['last_fill'] !== null): ?><?= e($fmtDate((string) $r['last_fill']['fillup_datetime'])) ?> · <?= e($qty($r['last_fill']['quantity_liters'], 'L')) ?><?= (int) ($r['last_fill']['is_full'] ?? 0) === 1 ? ' <span class="cons-pill is-ok">plin</span>' : '' ?><?php endif; ?></td>
                    <td><span class="cons-pill <?= e($confClass) ?>"><?= e($confLabel) ?></span></td>
                    <td class="cons-reason"><?php foreach ($r['anomalies'] as $anomaly): ?><small class="d-block text-danger"><i class="bi bi-exclamation-triangle"></i> <?= e((string) $anomaly) ?></small><?php endforeach; ?><?= $r['anomalies'] === [] ? '<span class="text-muted">—</span>' : '' ?></td>
                    <td><button class="btn btn-sm btn-light" type="button" data-cons-toggle="ab<?= e((string) $r['vehicle_id']) ?>" title="Istoricul alimentărilor"><i class="bi bi-list-ul"></i></button></td>
                </tr>
                <tr class="cons-detail-row" id="ab<?= e((string) $r['vehicle_id']) ?>" hidden>
                    <td colspan="12">
                        <div class="cons-detail-grid">
                            <div>
                                <h3>Alimentări AdBlue</h3>
                                <table class="table cons-table mb-0"><thead><tr><th>Data</th><th class="text-end">Litri</th><th>Plin</th><th>Odometru pompă</th><th>Stație / șofer</th><?= $canFix ? '<th>Corectare vehicul</th>' : '' ?></tr></thead><tbody>
                                <?php foreach (array_reverse((array) ($r['fills_list'] ?? [])) as $fill): ?>
                                    <tr><td><?= e($fmtDate((string) $fill['fillup_datetime'], true)) ?></td><td class="text-end"><?= e($qty($fill['quantity_liters'], 'L')) ?></td><td><?= (int) ($fill['is_full'] ?? 0) === 1 ? 'da' : '—' ?></td>
                                        <td><?= (float) ($fill['odometer_km'] ?? 0) > 0 ? e(format_number_ro((float) $fill['odometer_km'], 0)) : '—' ?></td>
                                        <td><small><?= e(trim((string) ($fill['station_name'] ?? '') . ' · ' . (string) ($fill['driver_name'] ?? ''), ' ·')) ?></small><?= !empty($fill['vehicle_registration_manual']) ? '<small class="d-block text-warning-emphasis">mutată manual (card: ' . e((string) $fill['vehicle_registration_api']) . ')</small>' : '' ?></td>
                                        <?= $canFix ? '<td>' . $fixForm($fill) . '</td>' : '' ?></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            </div>
                            <div>
                                <h3>Intervale între alimentări</h3>
                                <table class="table cons-table mb-0"><thead><tr><th>Interval</th><th class="text-end">Motorină CAN</th><th class="text-end">Km GPS</th><th>Stare</th></tr></thead><tbody>
                                <?php foreach (array_reverse($r['history']) as $h): ?>
                                    <tr><td><?= e($fmtDate($h['from'])) ?> – <?= e($fmtDate($h['to'])) ?><?= $h['open'] ? ' <span class="cons-pill is-use">deschis</span>' : '' ?></td>
                                        <td class="text-end"><?= $h['diesel'] !== null ? e(format_number_ro((float) $h['diesel'], 0)) . ' L' : '—' ?></td>
                                        <td class="text-end"><?= $h['km_gps'] !== null ? e(format_number_ro((float) $h['km_gps'], 0)) : '—' ?></td>
                                        <td><small><?= e(['ok' => 'CAN ok', 'fara_can' => 'fără date CAN', 'fara_sas' => 'fără SAS', 'eroare' => 'eroare SAS'][(string) $h['can_status']] ?? 'neactualizat') ?></small></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($abRows === []): ?><tr><td colspan="12" class="cons-empty">Nicio alimentare AdBlue asociată unui vehicul în perioada aleasă.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="cons-note"><i class="bi bi-info-circle"></i> AdBlue consumat între prima și ultima zi cu alimentare = suma alimentărilor de după prima zi (fiecare înlocuiește ce s-a consumat); eroarea e mărginită de capacitatea rezervorului, de aici gradul de încredere. Motorina este cea consumată din CAN (SAS) pe aceleași zile, nu alimentările cu motorină. Raportul AdBlue / motorină și anomaliile se raportează la istoricul fiecărui vehicul. Restul și autonomia se estimează doar cu capacitatea configurată în sistemul „AdBlue” al vehiculului și cu ultima alimentare la plin; nivelul AdBlue nu e transmis de SAS.</p>
    </article>
    <?php if ($abUnmatched !== []): ?>
        <article class="cons-card">
            <header><h2><i class="bi bi-question-diamond"></i> Alimentări AdBlue neasociate</h2><span>numărul de pe card nu corespunde niciunui vehicul din flotă</span></header>
            <div class="table-responsive"><table class="table cons-table align-middle mb-0">
                <thead><tr><th>Data</th><th>Număr pe card</th><th class="text-end">Litri</th><th>Stație / șofer</th><?= $canFix ? '<th>Asociază</th>' : '' ?></tr></thead>
                <tbody>
                <?php foreach ($abUnmatched as $fill): ?>
                    <tr><td><?= e($fmtDate((string) $fill['fillup_datetime'], true)) ?></td><td><strong><?= e((string) $fill['vehicle_registration']) ?></strong></td><td class="text-end"><?= e($qty($fill['quantity_liters'], 'L')) ?></td>
                        <td><small><?= e(trim((string) ($fill['station_name'] ?? '') . ' · ' . (string) ($fill['driver_name'] ?? ''), ' ·')) ?></small></td><?= $canFix ? '<td>' . $fixForm($fill) . '</td>' : '' ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </article>
    <?php endif; ?>
<?php endif; ?>
</section>

<?php require __DIR__ . '/_consumabile_panels.php'; ?>
<script type="application/json" id="consBoot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
