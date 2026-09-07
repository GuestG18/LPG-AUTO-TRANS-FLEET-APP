<?php
/**
 * Panoul unui articol din catalog: ce bucăți există în spatele definiției.
 *
 * Catalogul spune CE ESTE articolul, dar coloana „Stoc” cere un răspuns
 * concret: care sunt bucățile, în ce gestiune și mărime stau, iar cele care nu
 * mai sunt în stoc — la ce șofer au ajuns.
 *
 * Se include din catalog.php cu $item, $breakdown, $panelId și helperii
 * $money / $dash din pagină.
 */

$stockLines = is_array($breakdown['stoc'] ?? null) ? $breakdown['stoc'] : [];
$units = is_array($breakdown['unitati'] ?? null) ? $breakdown['unitati'] : [];
$allocations = is_array($breakdown['alocari'] ?? null) ? $breakdown['alocari'] : [];
$totalStock = (int) ($breakdown['total_stoc'] ?? 0);
$totalAssigned = (int) ($breakdown['total_alocat'] ?? 0);
$isSerialized = (int) ($item['serializat'] ?? 0) === 1;
$needsReplacement = count(array_filter($allocations, static fn(array $a): bool => !empty($a['necesita_inlocuire'])));
?>
<div class="des-panel">
    <div class="des-panel-head">
        <div class="des-panel-title">
            <i class="bi bi-box-seam" aria-hidden="true"></i>
            <strong><?= e((string) $item['denumire']) ?></strong>
            <span>— <?= e((string) ($totalStock + $totalAssigned)) ?> bucăți în evidență</span>
        </div>
        <div class="des-chips">
            <span class="des-pill <?= $totalStock > 0 ? 'is-green' : 'is-red' ?>"><?= e((string) $totalStock) ?> în stoc</span>
            <span class="des-pill is-blue"><?= e((string) $totalAssigned) ?> la șoferi</span>
            <?php if ($needsReplacement > 0): ?>
                <span class="des-pill is-red"><?= e((string) $needsReplacement) ?> de înlocuit</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="des-subgrid">
        <!-- Ce avem disponibil -->
        <section class="des-subcard">
            <div class="des-subcard-head">
                <i class="bi <?= $isSerialized ? 'bi-upc-scan' : 'bi-boxes' ?>" aria-hidden="true"></i>
                <h3><?= $isSerialized ? 'Unități în evidență (' . e((string) count($units)) . ')' : 'Stoc pe gestiuni și mărimi' ?></h3>
            </div>
            <div class="des-table-wrap">
                <?php if ($isSerialized): ?>
                    <table class="des-subtable">
                        <thead>
                            <tr>
                                <th>Serie / identificare</th>
                                <th>Locație</th>
                                <th>Stare</th>
                                <th>Intrare</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($units === []): ?>
                            <tr><td colspan="5" class="des-empty">Nicio unitate înregistrată.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($units as $unit): ?>
                            <?php
                            $unitTone = match ((string) $unit['status']) {
                                'disponibil' => 'success',
                                'alocat', 'rezervat' => 'blue',
                                'deteriorat', 'pierdut' => 'danger',
                                default => 'muted',
                            };
                            $identifier = trim((string) ($unit['serie'] ?? '')) !== ''
                                ? (string) $unit['serie']
                                : (string) ($unit['numar_telefon'] ?? $unit['iccid'] ?? '');
                            ?>
                            <tr>
                                <td class="des-item-name">
                                    <?= e($dash($identifier)) ?>
                                    <?php if (trim((string) ($unit['operator'] ?? '')) !== ''): ?>
                                        <span class="des-note"><?= e((string) $unit['operator']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $unit['locatie']) ?></td>
                                <td><?= e((string) $unit['stare_label']) ?></td>
                                <td><?= $unit['data_intrarii'] !== null ? e(format_date_ro((string) $unit['data_intrarii'])) : '—' ?></td>
                                <td>
                                    <span class="des-badge is-<?= e($unitTone) ?>"><?= e((string) $unit['status_label']) ?></span>
                                    <?php if (trim((string) ($unit['sofer_nume'] ?? '')) !== ''): ?>
                                        <span class="des-note"><?= e((string) $unit['sofer_nume']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <table class="des-subtable">
                        <thead>
                            <tr>
                                <th>Locație / gestiune</th>
                                <th>Mărime</th>
                                <th class="des-col-num">Disponibil</th>
                                <th class="des-col-num">Rezervat</th>
                                <th class="des-col-num">Prag minim</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($stockLines === []): ?>
                            <tr><td colspan="6" class="des-empty">Articolul nu are încă linii de stoc.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($stockLines as $line): ?>
                            <tr class="<?= $line['status']['key'] === 'epuizat' ? 'is-critical' : ($line['status']['key'] === 'scazut' ? 'is-flagged' : '') ?>">
                                <td class="des-item-name"><?= e((string) $line['locatie']) ?></td>
                                <td><?= (string) $line['marime'] !== '' ? e((string) $line['marime']) : '—' ?></td>
                                <td class="des-col-num"><?= e((string) $line['disponibil']) ?></td>
                                <td class="des-col-num"><?= e((string) $line['rezervat']) ?></td>
                                <td class="des-col-num"><?= e((string) $line['prag_minim']) ?></td>
                                <td><span class="des-status is-<?= e((string) $line['status']['tone']) ?>"><?= e((string) $line['status']['label']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <!-- Unde se află restul bucăților -->
        <section class="des-subcard">
            <div class="des-subcard-head">
                <i class="bi bi-person-badge" aria-hidden="true"></i>
                <h3>Alocate șoferilor (<?= e((string) $totalAssigned) ?>)</h3>
            </div>
            <div class="des-table-wrap">
                <table class="des-subtable">
                    <thead>
                        <tr>
                            <th>Șofer</th>
                            <th class="des-col-num">Cant.</th>
                            <th>Mărime</th>
                            <th>Identificare</th>
                            <th>Data predării</th>
                            <th>Stare</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($allocations === []): ?>
                        <tr><td colspan="6" class="des-empty">Niciun șofer nu are acest articol.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($allocations as $allocation): ?>
                        <tr class="<?= !empty($allocation['necesita_inlocuire']) ? 'is-critical' : '' ?>">
                            <td class="des-item-name"><?= e((string) $allocation['sofer_nume']) ?></td>
                            <td class="des-col-num"><?= e((string) $allocation['cantitate']) ?></td>
                            <td><?= e($dash($allocation['marime'])) ?></td>
                            <td><?= e($dash($allocation['identificator'])) ?></td>
                            <td><?= e(format_date_ro((string) $allocation['data_predarii'])) ?></td>
                            <td>
                                <span class="des-status is-<?= !empty($allocation['necesita_inlocuire']) ? 'danger' : 'success' ?>">
                                    <?= e((string) $allocation['stare_label']) ?>
                                </span>
                                <?php if (!empty($allocation['necesita_inlocuire'])): ?>
                                    <span class="des-note"><?= e((string) $allocation['status_label']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="des-panel-foot">
        <a class="des-btn des-btn-sm" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc', 'q' => (string) $item['denumire']])) ?>">
            <i class="bi bi-boxes" aria-hidden="true"></i>Vezi în stoc
        </a>
        <a class="des-btn des-btn-sm" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'q' => (string) $item['denumire']])) ?>">
            <i class="bi bi-people" aria-hidden="true"></i>Vezi alocările
        </a>
    </div>
</div>
