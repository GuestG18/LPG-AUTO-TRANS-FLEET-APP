<?php
/**
 * Fisa unei inregistrari de cost din Rentabilitate Operationala (?page=rentabilitate&action=line_view&id=V&cat=C&i=N),
 * in acelasi panou ca fisa cursei din Dispecer curse: clic pe rand in Costuri → panoul creste din rand
 * (race-view-overlay.js, partial=1). Doar citire: suma vine din acelasi calcul ca grila (action=costs).
 *
 * Asteapta: $linie, $categorie, $subiect (Vehicul / Sofer), $km, $km_label, $total_categorie, $inregistrari_categorie, $from, $to, $viewPartial.
 */
$line = $linie;
$amount = (float) $line['suma'];
$kmBase = (float) $km;
$money = static fn (float $value): string => format_number_ro($value, 2) . ' lei';
$dateRo = static fn (?string $value): string => trim((string) $value) === '' ? '' : format_date_ro(substr((string) $value, 0, 10));

$sourceLinks = [
    'mentenanta' => ['Deschide în Reparații', 'bi-wrench', build_query_url(['page' => 'mentenanta', 'action' => 'repairs'])],
    'carburanti' => ['Deschide în Carburanți', 'bi-fuel-pump', build_query_url(['page' => 'carburanti'])],
    'contabilitate_personal' => ['Contabilitate personal', 'bi-person-badge', build_query_url(['page' => 'contabilitate_personal'])],
    'documente' => ['Pagina Documente', 'bi-file-earmark-text', build_query_url(['page' => 'documente'])],
];
$source = $sourceLinks[$line['link'] ?? ''] ?? null;
$fileUrl = ($line['link'] ?? '') === 'mentenanta' && !empty($line['are_fisier'])
    ? build_query_url(['page' => 'mentenanta', 'action' => 'intervention_document', 'sursa' => 'inregistrare', 'id' => (int) $line['source_id']])
    : '';
$documentText = match ($line['link'] ?? '') {
    'mentenanta' => (string) ($line['document'] ?? '') !== '' ? (string) $line['document'] : 'Fișa #' . (int) $line['source_id'],
    'carburanti' => 'CardOil #' . (int) $line['source_id'],
    default => '',
};

$sections = [
    'Date generale' => [
        (string) $subiect['label'] => (string) $subiect['text'],
        'Categorie' => (string) $categorie['label'] . (!empty($line['detalii']['Element']) ? ' › ' . $line['detalii']['Element'] : ''),
        'Data' => $dateRo($line['data']),
        'Descriere' => (string) $line['descriere'],
        'Furnizor / sursă' => (string) $line['sursa'],
        'Pe' => (string) $line['pe'],
        'Document' => $documentText,
    ],
    'Cost' => [
        'Cost (lei)' => $money($amount),
        'Cost / km' => $kmBase > 0 ? format_number_ro($amount / $kmBase, 3) . ' lei/km' : '',
        (string) $km_label => format_number_ro($kmBase, 0) . ' km',
        'Km la intervenție' => !empty($line['km']) ? format_number_ro((float) $line['km'], 0) . ' km' : '',
        'Pondere în categorie' => $total_categorie > 0 ? format_number_ro($amount / $total_categorie * 100, 1) . '%' : '',
        'Total categorie' => $money((float) $total_categorie) . ' (' . (int) $inregistrari_categorie . ' înregistrări)',
    ],
    'Detalii sursă' => array_map(static fn ($v): string => (string) $v, (array) ($line['detalii'] ?? [])),
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3<?= !empty($viewPartial) ? ' race-view-head' : '' ?>">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <?php /* descrierea incepe deja cu numele componentei (ex. „Salariu și contribuții — 2026-11”): nu-l repetam */ ?>
        <h2 class="h4 mb-0"><?= e(str_starts_with((string) $line['descriere'], (string) $categorie['label']) ? (string) $line['descriere'] : $categorie['label'] . ' — ' . $line['descriere']) ?></h2>
        <?php if (!empty($line['estimat'])): ?>
            <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis">estimat</span>
        <?php endif; ?>
        <?php if ($source === null): ?>
            <span class="badge rounded-pill bg-light text-secondary border" title="Valoare calculată de motorul Cost/km (configurare), fără document propriu">calcul</span>
        <?php endif; ?>
        <span class="badge rounded-pill bg-light text-secondary border"><i class="bi bi-eye me-1" aria-hidden="true"></i>doar vizualizare</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($fileUrl !== ''): ?>
            <a class="btn btn-outline-primary" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Document</a>
        <?php endif; ?>
        <?php if ($source !== null): ?>
            <a class="btn btn-outline-primary" href="<?= e($source[2]) ?>" target="_blank" rel="noopener"><i class="bi <?= e($source[1]) ?> me-1" aria-hidden="true"></i><?= e($source[0]) ?></a>
        <?php endif; ?>
        <?php if (!function_exists('can') || can('cost_operational')): ?>
            <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => 'cost_operational'])) ?>" target="_blank" rel="noopener"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Configurare cost/km</a>
        <?php endif; ?>
        <?php if (!empty($viewPartial)): ?>
            <button type="button" class="btn btn-outline-secondary" data-race-view-close aria-label="Închide fișa înregistrării">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'rentabilitate', 'from' => $from, 'to' => $to])) ?>">Înapoi la listă</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($sections as $sectionTitle => $fields): ?>
        <?php $fields = array_filter($fields, static fn ($value): bool => trim((string) $value) !== '' && trim((string) $value) !== '—'); ?>
        <?php if ($fields === []) { continue; } ?>
        <div class="col-12 col-lg-6 col-xxl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><?= e($sectionTitle) ?></div>
                <div class="card-body py-2">
                    <dl class="row mb-0 small">
                        <?php foreach ($fields as $label => $value): ?>
                            <dt class="col-5 text-muted fw-normal py-1"><?= e((string) $label) ?></dt>
                            <?php if ($label === 'Document' && $fileUrl !== ''): ?>
                                <dd class="col-7 mb-0 py-1 fw-semibold"><a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><?= e((string) $value) ?> <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></a></dd>
                            <?php else: ?>
                                <dd class="col-7 mb-0 py-1 fw-semibold"><?= e((string) $value) ?></dd>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Cum se calculează</div>
    <div class="card-body small text-muted">
        Perioada analizată: <b class="text-body"><?= e($dateRo($from)) ?> – <?= e($dateRo($to)) ?></b>.
        Cost / km = suma înregistrării ÷ km-ii reali ai <?= $subiect['label'] === 'Șofer' ? 'șoferului' : 'vehiculului' ?> în perioadă (<?= e(format_number_ro($kmBase, 0)) ?> km).<?php if ($subiect['label'] === 'Șofer'): ?> Costul șoferului se împarte vehiculelor după km-ii lui reali pe fiecare; lunile fără km rămân „cost neocupat”.<?php endif; ?>
        <?php if (!empty($categorie['lipsa'])): ?>
            <div class="text-warning-emphasis mt-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Elemente fără sursă în această categorie (nu sunt tratate ca 0): <?= e(implode(', ', (array) $categorie['lipsa'])) ?>.</div>
        <?php endif; ?>
    </div>
</div>
