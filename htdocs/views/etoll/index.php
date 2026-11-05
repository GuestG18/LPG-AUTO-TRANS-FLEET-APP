<?php
/**
 * Pagina "Situație eToll": perioada (luna / an / interval), soldul portofelului, verificarile,
 * sesiunile cu documentele din ETOLL_SITUATIE_DOCUMENTE (implicit factura CC-P + anexa TA_10),
 * alimentarile cu facturile lor si exportul.
 */
$period = is_array($period ?? null) ? $period : EtollStatementModel::period([]);
$summary = is_array($summary ?? null) ? $summary : [];
$sessions = is_array($sessions ?? null) ? $sessions : [];
$topups = is_array($topups ?? null) ? $topups : [];
$plates = is_array($plates ?? null) ? $plates : [];
$plate = (string) ($plate ?? '');
$balance = is_array($balance ?? null) ? $balance : null;
$quietBalance = is_array($quietBalance ?? null) ? $quietBalance : null;
$canExport = (bool) ($canExport ?? false);
$lastSyncAt = isset($lastSyncAt) ? (string) $lastSyncAt : null;
$kinds = EtollStatementModel::documentKinds();
$kindTitles = ['factura' => 'Factura CC-P (Plăți)', 'anexa' => 'Anexa facturii TA_10 (Plăți)', 'desfasurator' => 'Desfășurătorul sesiunii (Servicii)'];
$plural = ['factura' => 'facturi', 'anexa' => 'anexe', 'desfasurator' => 'desfășurătoare'];

$money = static fn(mixed $value): string => $value === null || $value === '' ? '—' : format_number_ro((float) $value, 2) . ' lei';
$at = static fn(?string $value): string => $value !== null && $value !== '' ? date('d.m.Y H:i', strtotime($value)) : '—';
$query = array_filter([
    'page' => 'etoll',
    'perioada' => $period['tip'],
    'luna' => $period['tip'] === 'luna' ? $period['luna'] : '',
    'an' => $period['tip'] === 'an' ? $period['an'] : '',
    'de_la' => $period['tip'] === 'interval' ? $period['de_la'] : '',
    'pana_la' => $period['tip'] === 'interval' ? $period['pana_la'] : '',
    'nr' => $plate,
], static fn($value): bool => $value !== '');
$docUrl = static fn(string $sessionId, string $kind): string => build_query_url(['page' => 'etoll', 'action' => 'document', 'sesiune' => $sessionId, 'tip' => $kind]);
$years = range((int) date('Y'), 2026);
$staleSync = $lastSyncAt === null || strtotime((string) $lastSyncAt) < time() - 3 * 3600;
$checkBadge = ['ok' => 'text-bg-success', 'lipsa' => 'text-bg-warning', 'diferenta' => 'text-bg-danger'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Situație eToll</h1>
        <p class="text-secondary mb-0 small">
            Sesiunile per km cu <?= e(mb_strtolower(implode(', ', array_map(static fn(array $k): string => $k[0] . ' (' . $k[2] . ')', $kinds)))) ?>, alimentările portofelului și verificarea soldului.
            Date sincronizate: <strong class="<?= $staleSync ? 'text-danger' : '' ?>"><?= e($at($lastSyncAt ?? null)) ?></strong>.
        </p>
    </div>
    <?php if ($canExport): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-success btn-sm" href="<?= e(build_query_url($query + ['action' => 'export_xlsx'])) ?>">
                <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Export Excel
            </a>
            <a class="btn btn-primary btn-sm" href="<?= e(build_query_url($query + ['action' => 'export_zip'])) ?>" title="Excel-ul + <?= e(implode(' + ', array_column($kinds, 1))) ?> + facturile de alimentare">
                <i class="bi bi-file-earmark-zip" aria-hidden="true"></i> Export ZIP cu documente
            </a>
        </div>
    <?php endif; ?>
</div>

<form method="get" class="card card-body py-2 mb-3" id="etollPeriodForm">
    <input type="hidden" name="page" value="etoll">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="etollPerioada">Perioadă</label>
            <select class="form-select form-select-sm" name="perioada" id="etollPerioada">
                <option value="luna" <?= $period['tip'] === 'luna' ? 'selected' : '' ?>>Lună</option>
                <option value="an" <?= $period['tip'] === 'an' ? 'selected' : '' ?>>An</option>
                <option value="interval" <?= $period['tip'] === 'interval' ? 'selected' : '' ?>>Interval</option>
            </select>
        </div>
        <div class="col-6 col-md-2" data-perioada="luna">
            <label class="form-label small mb-1" for="etollLuna">Luna</label>
            <input type="month" class="form-control form-control-sm" name="luna" id="etollLuna" value="<?= e($period['luna']) ?>">
        </div>
        <div class="col-6 col-md-2" data-perioada="an">
            <label class="form-label small mb-1" for="etollAn">Anul</label>
            <select class="form-select form-select-sm" name="an" id="etollAn">
                <?php foreach ($years as $year): ?>
                    <option value="<?= $year ?>" <?= (string) $year === $period['an'] ? 'selected' : '' ?>><?= $year ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2" data-perioada="interval">
            <label class="form-label small mb-1" for="etollDeLa">De la</label>
            <input type="date" class="form-control form-control-sm" name="de_la" id="etollDeLa" value="<?= e($period['de_la']) ?>">
        </div>
        <div class="col-6 col-md-2" data-perioada="interval">
            <label class="form-label small mb-1" for="etollPanaLa">Până la</label>
            <input type="date" class="form-control form-control-sm" name="pana_la" id="etollPanaLa" value="<?= e($period['pana_la']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="etollNr">Nr. auto</label>
            <select class="form-select form-select-sm" name="nr" id="etollNr">
                <option value="">Toate</option>
                <?php foreach ($plates as $option): ?>
                    <option value="<?= e($option) ?>" <?= $option === $plate ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-auto">
            <button type="submit" class="btn btn-secondary btn-sm w-100"><i class="bi bi-funnel" aria-hidden="true"></i> Afișează</button>
        </div>
    </div>
</form>

<h2 class="h6 text-secondary mb-2">Portofel eToll — <?= e($period['eticheta']) ?> <span class="fw-normal">(toate camioanele)</span></h2>
<div class="row g-2 mb-3">
    <?php foreach ([
        ['Sold la început', $summary['sold_inceput'] ?? null, '', 'bi-wallet2'],
        ['+ Alimentări', $summary['alimentari']['suma'] ?? null, ($summary['alimentari']['n'] ?? 0) . ' plăți', 'bi-plus-circle'],
        ['− Consum', $summary['consum']['suma'] ?? null, ($summary['consum']['n'] ?? 0) . ' sesiuni închise', 'bi-signpost-split'],
        ['= Sold la final', $summary['sold_final'] ?? null, '', 'bi-calculator'],
    ] as [$label, $value, $hint, $icon]): ?>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="small text-secondary"><i class="bi <?= $icon ?>" aria-hidden="true"></i> <?= e($label) ?></div>
                    <div class="fs-5 fw-semibold"><?= e($money($value)) ?></div>
                    <?php if ($hint !== ''): ?><div class="small text-secondary"><?= e($hint) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if (!empty($summary['date_din']) && $summary['date_din'] > '2026-10-02'): ?>
    <div class="alert alert-warning py-2 small">Registrul începe la <?= e($at($summary['date_din'])) ?>, nu de la deschiderea contului (01.10.2026): soldul de început nu e complet. Rulează o dată <code>php scripts/sync_etoll_situatie.php --days=60 --max-requests=4000</code>.</div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-2 small fw-semibold"><i class="bi bi-check2-square" aria-hidden="true"></i> Verificarea perioadei</div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>Facturi de alimentare: <?= (int) ($summary['facturi_alimentare']['n'] ?? 0) ?>, <?= e($money($summary['facturi_alimentare']['suma'] ?? 0)) ?></span>
                    <?php if (!empty($summary['alimentari_ok'])): ?>
                        <span class="badge text-bg-success">= alimentările</span>
                    <?php else: ?>
                        <span class="badge text-bg-danger">≠ alimentările (<?= e($money($summary['alimentari']['suma'] ?? 0)) ?>)</span>
                    <?php endif; ?>
                </li>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>Sesiuni cu toate documentele (<?= e(mb_strtolower(implode(', ', array_column($kinds, 0)))) ?>)</span>
                    <span class="badge <?= (int) ($summary['documente_complete'] ?? 0) === (int) ($summary['consum']['n'] ?? 0) ? 'text-bg-success' : 'text-bg-warning' ?>"><?= (int) ($summary['documente_complete'] ?? 0) ?> / <?= (int) ($summary['consum']['n'] ?? 0) ?></span>
                </li>
                <?php
                $missingParts = [];
                foreach ($kinds as $kind => $info) {
                    if ((int) ($summary['fara_' . $kind] ?? 0) > 0) {
                        $missingParts[] = (int) $summary['fara_' . $kind] . ' ' . $plural[$kind];
                    }
                }
                ?>
                <?php if ($missingParts !== []): ?>
                    <li class="list-group-item text-secondary">Lipsesc: <?= e(implode(', ', $missingParts)) ?> (se descarcă la sincronizările următoare).</li>
                <?php endif; ?>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>Suma din documente = suma sesiunii</span>
                    <?php if ((int) ($summary['suma_diferita'] ?? 0) === 0): ?>
                        <span class="badge text-bg-success">da</span>
                    <?php else: ?>
                        <span class="badge text-bg-danger"><?= (int) $summary['suma_diferita'] ?> diferite</span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-2 small fw-semibold"><i class="bi bi-bank" aria-hidden="true"></i> Soldul eToll față de alimentări − consum</div>
            <ul class="list-group list-group-flush small">
                <?php if ($quietBalance !== null): ?>
                    <?php $quietOk = abs((float) $quietBalance['diferenta']) < EtollStatementModel::TOLERANCE; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                            <span>Fără sesiuni în curs (<?= e($at($quietBalance['verificat_la'])) ?>)</span>
                            <span class="badge <?= $quietOk ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $quietOk ? 'egal' : 'diferență ' . e($money($quietBalance['diferenta'])) ?></span>
                        </div>
                        <div class="text-secondary">eToll <?= e($money($quietBalance['sold_etoll'])) ?> · calculat <?= e($money($quietBalance['sold_calculat'])) ?> (alimentări <?= e($money($quietBalance['alimentari'])) ?> − consum <?= e($money($quietBalance['consum_inchis'])) ?>)</div>
                    </li>
                <?php else: ?>
                    <li class="list-group-item text-secondary">Încă nicio verificare fără sesiuni în curs. Verificarea exactă se face la o sincronizare în care niciun camion nu are sesiunea pornită (de obicei noaptea).</li>
                <?php endif; ?>
                <?php if ($balance !== null): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                            <span>Ultima verificare (<?= e($at($balance['verificat_la'])) ?>)</span>
                            <span class="badge text-bg-light border"><?= (int) $balance['sesiuni_in_curs'] ?> sesiuni în curs</span>
                        </div>
                        <div class="text-secondary">
                            eToll <?= e($money($balance['sold_etoll'])) ?> · calculat <?= e($money($balance['sold_calculat'])) ?> · diferență <?= e($money($balance['diferenta'])) ?>
                            <?php if ((int) $balance['sesiuni_in_curs'] > 0): ?>
                                <br>Cu sesiuni în curs diferența e normală: eToll scade consumul din sold înainte să-l arate pe sesiune.
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-2 small fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-signpost-split" aria-hidden="true"></i> Sesiuni închise (<?= count($sessions) ?><?= $plate !== '' ? ', ' . e($plate) : '' ?>)</span>
        <span><?= e($money(array_sum(array_map(static fn(array $r): float => (float) $r['suma'], $sessions)))) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Închisă la</th><th>Nr. auto</th><th>Început → sfârșit</th><th>Traseu</th>
                    <th class="text-end">Km</th><th class="text-end">Sumă</th><th>Document</th><th>PDF</th><th>Verificare</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sessions === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-3">Nicio sesiune închisă în perioadă.</td></tr>
                <?php endif; ?>
                <?php foreach ($sessions as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= e($at($row['inchisa_la'])) ?></td>
                        <td class="text-nowrap"><?= e((string) $row['nr_inmatriculare']) ?><?php if (!empty($row['remorci'])): ?><div class="text-secondary"><?= e((string) $row['remorci']) ?></div><?php endif; ?></td>
                        <td class="text-nowrap"><?= e($at($row['inceput'])) ?><br><?= e($at($row['sfarsit'])) ?></td>
                        <td class="text-truncate" style="max-width: 260px;" title="<?= e(($row['origine'] ?? '') . ' → ' . ($row['destinatie'] ?? '')) ?>"><?= e((string) ($row['origine'] ?? '—')) ?><br><?= e((string) ($row['destinatie'] ?? '')) ?></td>
                        <td class="text-end"><?= $row['km_tarifabil'] !== null ? e(format_number_ro((float) $row['km_tarifabil'], 0)) : '—' ?></td>
                        <td class="text-end text-nowrap"><?= e($money($row['suma'])) ?></td>
                        <td class="text-nowrap"><?= e((string) ($row['numar_document'] ?? '—')) ?></td>
                        <td class="text-nowrap">
                            <?php foreach ($kinds as $kind => [$label]): ?>
                                <?php if (!empty($row[$kind . '_path'])): ?><a class="me-1" href="<?= e($docUrl((string) $row['session_id'], $kind)) ?>" target="_blank" rel="noopener" title="<?= e($kindTitles[$kind]) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> <?= e($label) ?></a><?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td><span class="badge <?= $checkBadge[$row['verificare']['cod']] ?? 'text-bg-secondary' ?>" <?= !empty($row['eroare']) ? 'title="' . e((string) $row['eroare']) . '"' : '' ?>><?= e($row['verificare']['text']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-2 small fw-semibold"><i class="bi bi-plus-circle" aria-hidden="true"></i> Alimentări (<?= count(array_filter($topups, static fn(array $r): bool => $r['tx_id'] !== null)) ?>)</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light"><tr><th>Data plății</th><th class="text-end">Sumă</th><th>Factură</th><th>PDF</th><th>Verificare</th></tr></thead>
            <tbody>
                <?php if ($topups === []): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-3">Nicio alimentare în perioadă.</td></tr>
                <?php endif; ?>
                <?php foreach ($topups as $row): $invoice = $row['factura']; ?>
                    <tr>
                        <td class="text-nowrap"><?= e($at($row['data'])) ?></td>
                        <td class="text-end text-nowrap"><?= e($money($row['suma'])) ?></td>
                        <td><?= e((string) ($invoice['numar_document'] ?? '—')) ?></td>
                        <td><?php if ($invoice !== null && !empty($invoice['pdf_path'])): ?><a href="<?= e(build_query_url(['page' => 'etoll', 'action' => 'alimentare', 'id' => (string) $invoice['invoice_id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Factură</a><?php endif; ?></td>
                        <td>
                            <?php if ($row['tx_id'] === null): ?><span class="badge text-bg-danger">Factură fără alimentare</span>
                            <?php elseif ($invoice === null): ?><span class="badge text-bg-warning">Lipsește factura</span>
                            <?php else: ?><span class="badge text-bg-success">OK</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
    const select = document.getElementById('etollPerioada');
    const sync = () => document.querySelectorAll('#etollPeriodForm [data-perioada]').forEach((el) => {
        const active = el.dataset.perioada === select.value;
        el.classList.toggle('d-none', !active);
        el.querySelectorAll('input, select').forEach((input) => { input.disabled = !active; });
    });
    select.addEventListener('change', sync);
    sync();
})();
</script>
