<?php
/**
 * Pagina "Situație eToll": filtrele (perioadă, capacitate, categorie, beneficiari, tip transport,
 * vehicule, status), cardurile, verificarea perioadei si a soldului, sesiunile (inchise / in curs /
 * fara documente) cu documentele din ETOLL_SITUATIE_DOCUMENTE (implicit factura CC-P + anexa TA_10),
 * alimentarile portofelului cu facturile lor si exportul.
 *
 * Filtrele se aplica pe server (GET, acelasi URL pentru Excel/ZIP); tab-urile, sortarea, paginarea
 * si selectia randurilor ruleaza in browser pe sesiunile deja incarcate (assets/js/etoll.js).
 */
$period = is_array($period ?? null) ? $period : EtollStatementModel::period([]);
$summary = is_array($summary ?? null) ? $summary : [];
$sessions = is_array($sessions ?? null) ? $sessions : [];
$openSessions = is_array($openSessions ?? null) ? $openSessions : [];
$totals = is_array($totals ?? null) ? $totals : EtollStatementModel::sessionTotals($sessions);
$topups = is_array($topups ?? null) ? $topups : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$beneficiaries = is_array($beneficiaries ?? null) ? $beneficiaries : [];
$filters = is_array($filters ?? null) ? $filters : [];
foreach (['nr', 'cap', 'cat', 'ben', 'tip', 'status', 'inchisa_de', 'inchisa_pana'] as $filterKey) {
    $filters[$filterKey] = array_map('strval', (array) ($filters[$filterKey] ?? []));
}
$selectedPlates = $filters['nr'];
// "Închisă la" in formatul <input type="datetime-local"> (2026-11-05T14:00).
$closedFrom = $filters['inchisa_de'] !== [] ? date('Y-m-d\TH:i', strtotime($filters['inchisa_de'][0])) : '';
$closedTo = $filters['inchisa_pana'] !== [] ? date('Y-m-d\TH:i', strtotime($filters['inchisa_pana'][0])) : '';
$hasFilters = array_filter($filters) !== [];
require_once BASE_PATH . '/includes/vehicle_capacity_groups.php';
// Camioanele perioadei grupate pe categoria de capacitate, ca la Carburanti (valoarea = nr. auto eToll).
$vehicleGroups = build_vehicle_capacity_groups($vehicles, ['id_key' => 'etoll_plate', 'fallback_label' => 'Fără categorie']);
$balance = is_array($balance ?? null) ? $balance : null;
$quietBalance = is_array($quietBalance ?? null) ? $quietBalance : null;
$canExport = (bool) ($canExport ?? false);
$lastSyncAt = isset($lastSyncAt) ? (string) $lastSyncAt : null;
$kinds = EtollStatementModel::documentKinds();
$kindTitles = ['factura' => 'Factura CC-P (Plăți)', 'anexa' => 'Anexa facturii TA_10 (Plăți)', 'desfasurator' => 'Desfășurătorul sesiunii (Servicii)'];
$plural = ['factura' => 'facturi', 'anexa' => 'anexe', 'desfasurator' => 'desfășurătoare'];
$none = EtollStatementModel::NONE;

$money = static fn(mixed $value): string => $value === null || $value === '' ? '—' : format_number_ro((float) $value, 2) . ' lei';
$at = static fn(?string $value): string => $value !== null && $value !== '' ? date('d.m.Y H:i', strtotime($value)) : '—';
/**
 * Intervalul sesiunii, scurt: "01.11 08:07 → 10:28"; peste miezul noptii doar ora de sfarsit + zilele
 * in plus ("05.11 14:28 → 00:59", days = 1), data completa in title.
 *
 * @return array{text: string, days: int, title: string}
 */
$span = static function (?string $start, ?string $end): array {
    if ($start === null || $end === null) {
        return ['text' => $start !== null ? date('d.m H:i', strtotime($start)) . ' → …' : '—', 'days' => 0, 'title' => $start !== null ? 'Început ' . date('d.m.Y H:i', strtotime($start)) : ''];
    }
    $from = strtotime($start);
    $to = strtotime($end);
    $days = (int) (new DateTimeImmutable(date('Y-m-d', $from)))->diff(new DateTimeImmutable(date('Y-m-d', $to)))->format('%r%a');

    return [
        'text' => date('d.m H:i', $from) . ' → ' . date('H:i', $to),
        'days' => $days,
        'title' => date('d.m.Y H:i', $from) . ' → ' . date('d.m.Y H:i', $to),
    ];
};
/** Primele doua parti ale adresei eToll ("Ghiorac, Ciumeghiu, Bihor, 417197, România" -> "Ghiorac, Ciumeghiu"). */
$place = static fn(?string $address): string => $address === null || trim($address) === '' ? '—' : implode(', ', array_slice(array_map('trim', explode(',', $address)), 0, 2));
$query = array_filter([
    'page' => 'etoll',
    'perioada' => $period['tip'],
    'luna' => $period['tip'] === 'luna' ? $period['luna'] : '',
    'an' => $period['tip'] === 'an' ? $period['an'] : '',
    'de_la' => $period['tip'] === 'interval' ? $period['de_la'] : '',
    'pana_la' => $period['tip'] === 'interval' ? $period['pana_la'] : '',
    'nr' => $filters['nr'],
    'cap' => $filters['cap'],
    'cat' => $filters['cat'],
    'ben' => $filters['ben'],
    'tip' => $filters['tip'],
    'status' => $filters['status'],
    'inchisa_de' => $closedFrom,
    'inchisa_pana' => $closedTo,
], static fn($value): bool => $value !== '' && $value !== []);
$docUrl = static fn(string $sessionId, string $kind): string => build_query_url(['page' => 'etoll', 'action' => 'document', 'sesiune' => $sessionId, 'tip' => $kind]);
$years = range((int) date('Y'), 2026);
$staleSync = $lastSyncAt === null || strtotime((string) $lastSyncAt) < time() - 3 * 3600;
$fmtDate = static fn(string $date): string => date('d.m.Y', strtotime($date));
$months = ['', 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];

// ---- Optiunile filtrelor (din flota / configurare, nu din macheta) ----
$capacityOptions = [];
$categoryOptions = [];
foreach ($vehicles as $vehicle) {
    $capKey = EtollStatementModel::capacityKey($vehicle['capacitate_transport'] ?? null);
    $capacityOptions[$capKey] = $capKey === $none ? 'Capacitate necunoscută' : (string) vehicle_capacity_format_tons($vehicle['capacitate_transport']);
    $catKey = (string) ($vehicle['categorie_capacitate_id'] ?? '') ?: $none;
    $categoryOptions[$catKey] = [
        'label' => $catKey === $none ? 'Fără categorie' : (string) ($vehicle['categorie_capacitate'] ?? ('#' . $catKey)),
        'order' => $catKey === $none ? PHP_INT_MAX : (int) ($vehicle['categorie_capacitate_ordine'] ?? 0),
    ];
}
uksort($capacityOptions, static fn($a, $b): int => ((string) $a === $none ? PHP_INT_MAX : (float) $a) <=> ((string) $b === $none ? PHP_INT_MAX : (float) $b));
uasort($categoryOptions, static fn(array $a, array $b): int => ($a['order'] <=> $b['order']) ?: strnatcasecmp($a['label'], $b['label']));
$categoryOptions = array_map(static fn(array $c): string => $c['label'], $categoryOptions);
$beneficiaryOptions = $beneficiaries + [$none => 'Fără beneficiar (neasociată)'];
$transportOptions = EtollStatementModel::TRANSPORT_TYPES + [$none => 'Fără cursă / Km service asociat'];
$statusOptions = EtollStatementModel::STATUSES;

/**
 * Un filtru cu lista de bife (cautare, Selecteaza tot / Goleste). $groups: [eticheta grupa => [valoare => eticheta]].
 */
$renderFilter = static function (string $name, string $label, string $icon, array $groups, array $selected, array $searchText = [], array $hints = []): void {
    $flat = array_replace(...array_values($groups ?: [[]]));
    // O valoare aleasa care nu mai apare in perioada (alt interval) ramane vizibila si debifabila:
    // altfel ar filtra pe ascuns.
    foreach ($selected as $orphan) {
        if (!array_key_exists($orphan, $flat)) {
            $groups['Alese, fără sesiuni în perioadă'][$orphan] = $orphan;
            $flat[$orphan] = $orphan;
        }
    }
    $chosen = array_values(array_intersect($selected, array_map('strval', array_keys($flat))));
    $count = count($chosen);
    $value = $count === 0 ? 'Toate' : ($count === 1 ? (string) $flat[$chosen[0]] : $count . ' selectate');
    $id = 'etollFilter_' . $name;
    ?>
    <div class="etoll-filter<?= $count > 0 ? ' is-active' : '' ?>" data-ms data-name="<?= e($name) ?>">
        <span class="etoll-filter__label" id="<?= e($id) ?>Label"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i><?= e($label) ?></span>
        <button type="button" class="etoll-filter__box" aria-haspopup="true" aria-expanded="false" aria-controls="<?= e($id) ?>Pop" aria-labelledby="<?= e($id) ?>Label <?= e($id) ?>Value" data-ms-trigger>
            <?php if ($name === 'nr'): ?><i class="bi bi-search etoll-filter__lead" aria-hidden="true"></i><?php endif; ?>
            <span class="etoll-filter__value" id="<?= e($id) ?>Value" data-ms-value><?= e($value) ?></span>
            <i class="bi bi-chevron-down etoll-filter__chev" aria-hidden="true"></i>
        </button>
        <div class="etoll-filter__pop" id="<?= e($id) ?>Pop" hidden data-ms-pop>
            <div class="etoll-filter__search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Caută..." aria-label="Caută în <?= e(mb_strtolower($label)) ?>" autocomplete="off" data-ms-search>
            </div>
            <div class="etoll-filter__actions">
                <button type="button" data-ms-all>Selectează tot</button><span aria-hidden="true">|</span><button type="button" data-ms-clear>Golește</button>
            </div>
            <div class="etoll-filter__list" role="group" aria-labelledby="<?= e($id) ?>Label">
                <?php foreach ($groups as $groupLabel => $options): ?>
                    <?php if (is_string($groupLabel) && $groupLabel !== ''): ?><div class="etoll-filter__group" data-ms-group><?= e($groupLabel) ?></div><?php endif; ?>
                    <?php foreach ($options as $optValue => $optLabel): ?>
                        <?php $optValue = (string) $optValue; ?>
                        <label class="etoll-filter__opt" data-search="<?= e(mb_strtolower(($searchText[$optValue] ?? '') . ' ' . $optLabel . ' ' . (is_string($groupLabel) ? $groupLabel : ''))) ?>">
                            <input type="checkbox" class="form-check-input" name="<?= e($name) ?>[]" value="<?= e($optValue) ?>" data-label="<?= e((string) $optLabel) ?>" <?= in_array($optValue, $selected, true) ? 'checked' : '' ?>>
                            <span class="etoll-filter__opt-text"><?= e((string) $optLabel) ?></span>
                            <?php if (!empty($hints[$optValue])): ?><span class="etoll-filter__opt-hint"><?= e((string) $hints[$optValue]) ?></span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="etoll-filter__empty" hidden data-ms-empty>Niciun rezultat.</div>
                <?php if ($flat === []): ?><div class="etoll-filter__empty">Nicio opțiune în perioadă.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <?php
};

$vehicleGroupOptions = [];
$vehicleSearch = [];
$vehicleHints = [];
foreach ($vehicleGroups as $capacityGroup) {
    $options = [];
    foreach ($capacityGroup['vehicles'] as $vehicle) {
        $plateValue = (string) $vehicle['etoll_plate'];
        $options[$plateValue] = $plateValue;
        $vehicleSearch[$plateValue] = vehicle_capacity_group_search_text($vehicle + ['vehicle_registration' => $plateValue], (string) $capacityGroup['label']);
        $vehicleHints[$plateValue] = trim(trim((string) ($vehicle['marca'] ?? '')) . ' ' . (vehicle_capacity_format_tons($vehicle['capacitate_transport'] ?? null) ?? ''));
    }
    $vehicleGroupOptions[(string) $capacityGroup['label']] = $options;
}

// ---- Randurile tabelului (JSON pentru assets/js/etoll.js) ----
$checkClass = ['ok' => 'is-ok', 'lipsa' => 'is-warn', 'diferenta' => 'is-danger', 'in_curs' => 'is-info'];
$tableRow = static function (array $row, bool $open) use ($at, $span, $place, $kinds, $kindTitles, $docUrl, $checkClass, $fmtDate): array {
    $documents = [];
    foreach ($kinds as $kind => [$label]) {
        $documents[] = [
            'label' => $label,
            'title' => $kindTitles[$kind] ?? $label,
            'url' => !$open && !empty($row[$kind . '_path']) ? $docUrl((string) $row['session_id'], $kind) : null,
        ];
    }
    $start = $row['inceput'] ?? ($open ? ($row['data'] ?? null) : null);
    $closedAt = $open ? null : ($row['inchisa_la'] ?? null);
    $origin = $row['origine'] ?? null;
    $destination = $row['destinatie'] ?? null;
    $hasRoute = ($origin !== null && trim((string) $origin) !== '') || ($destination !== null && trim((string) $destination) !== '');

    return [
        'id' => (string) $row['session_id'],
        'open' => $open,
        'closed' => $open ? 'În curs' : $at($closedAt),
        'closedTs' => $closedAt !== null ? strtotime((string) $closedAt) : null,
        'startTs' => $start !== null ? strtotime((string) $start) : null,
        'plate' => (string) ($row['nr_inmatriculare'] ?? ''),
        'trailer' => (string) ($row['remorci'] ?? ''),
        'ben' => $row['cursa_id'] !== null ? (string) ($row['beneficiar'] ?? '') : '',
        'cursaId' => $row['cursa_id'] !== null ? (int) $row['cursa_id'] : null,
        'cursaUrl' => $row['cursa_id'] !== null ? build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['cursa_id']]) : null,
        // Sesiune pe drumul la / de la service: link spre fisa intrarii Km service din Dispecer.
        'serviceKmId' => $row['cursa_id'] === null && !empty($row['service_km_id']) ? (int) $row['service_km_id'] : null,
        'serviceKmUrl' => $row['cursa_id'] === null && !empty($row['service_km_id'])
            ? build_query_url(['page' => 'dispecer_curse', 'service_km_view' => (int) $row['service_km_id']]) . '#service-km-panel'
            : null,
        'serviceKmTitle' => $row['cursa_id'] === null && !empty($row['service_km_id'])
            ? trim('Km service ' . $fmtDate((string) $row['service_km_plecare']) . ' → '
                . (!empty($row['service_km_intoarcere']) ? $fmtDate((string) $row['service_km_intoarcere']) : 'încă în service')
                . (!empty($row['service_km_nume']) ? ' · ' . $row['service_km_nume'] : ''))
            : '',
        ...(static function (array $s): array {
            return ['span' => $s['text'], 'spanDays' => $s['days'], 'spanTitle' => $s['title']];
        })($open ? $span($start, $row['sfarsit'] ?? null) : $span($row['inceput'] ?? null, $row['sfarsit'] ?? null)),
        'route' => $hasRoute ? $place($origin) . ' → ' . $place($destination) : '—',
        'routeFull' => $hasRoute ? trim(($origin ?? '') . ' → ' . ($destination ?? '')) : '',
        'km' => ($row['km_tarifabil'] ?? null) !== null ? (float) $row['km_tarifabil'] : null,
        'kmText' => ($row['km_tarifabil'] ?? null) !== null ? format_number_ro((float) $row['km_tarifabil'], 0) : '—',
        'net' => ($row['fara_tva'] ?? null) !== null ? (float) $row['fara_tva'] : null,
        'vat' => ($row['tva'] ?? null) !== null ? (float) $row['tva'] : null,
        'gross' => (float) $row['suma'],
        'netText' => ($row['fara_tva'] ?? null) !== null ? format_number_ro((float) $row['fara_tva'], 2) . ' lei' : '—',
        'vatText' => ($row['tva'] ?? null) !== null ? format_number_ro((float) $row['tva'], 2) . ' lei' : '—',
        'grossText' => format_number_ro((float) $row['suma'], 2) . ' lei',
        'vatRate' => ($row['cota_tva'] ?? null) !== null ? format_number_ro((float) $row['cota_tva'], 0) : null,
        'doc' => (string) ($row['numar_document'] ?? ''),
        'docs' => $documents,
        'check' => (string) $row['verificare']['cod'],
        'checkText' => (string) $row['verificare']['text'],
        'checkClass' => $checkClass[$row['verificare']['cod']] ?? 'is-muted',
        'error' => (string) ($row['eroare'] ?? ''),
    ];
};
$tableRows = array_merge(
    array_map(static fn(array $row): array => $tableRow($row, false), $sessions),
    array_map(static fn(array $row): array => $tableRow($row, true), $openSessions)
);
$missingCount = count(array_filter($sessions, static fn(array $row): bool => $row['verificare']['cod'] === 'lipsa'));
$topupCount = count(array_filter($topups, static fn(array $r): bool => $r['tx_id'] !== null));
$missingParts = [];
foreach ($kinds as $kind => $info) {
    if ((int) ($totals['fara_' . $kind] ?? 0) > 0) {
        $missingParts[] = (int) $totals['fara_' . $kind] . ' ' . $plural[$kind];
    }
}
$periodLabel = $period['tip'] === 'luna'
    ? $fmtDate($period['de_la']) . ' – ' . $fmtDate($period['pana_la'])
    : ($period['tip'] === 'an' ? 'Anul ' . $period['an'] : $fmtDate($period['de_la']) . ' – ' . $fmtDate($period['pana_la']));
?>
<link rel="stylesheet" href="<?= e(url('assets/css/etoll.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/etoll.css'))) ?>">

<div class="etoll-page" id="etollPage">
    <header class="etoll-head">
        <div class="etoll-head__title">
            <span class="etoll-head__icon" aria-hidden="true"><i class="bi bi-receipt-cutoff"></i></span>
            <div>
                <h1>Situație eToll</h1>
                <p>
                    Sesiunile pe km cu <?= e(mb_strtolower(implode(', ', array_map(static fn(array $k): string => $k[0] . ' (' . $k[2] . ')', $kinds)))) ?>, alimentările portofelului și verificarea soldului.
                    Date sincronizate: <strong class="<?= $staleSync ? 'text-danger' : '' ?>" <?= $staleSync ? 'title="Ultima sincronizare e mai veche de 3 ore"' : '' ?>><?= e($at($lastSyncAt ?? null)) ?></strong>.
                </p>
            </div>
        </div>
        <?php if ($canExport): ?>
            <div class="etoll-head__actions">
                <a class="etoll-btn etoll-btn--excel" href="<?= e(build_query_url($query + ['action' => 'export_xlsx'])) ?>">
                    <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Export Excel
                </a>
                <a class="etoll-btn etoll-btn--primary" href="<?= e(build_query_url($query + ['action' => 'export_zip'])) ?>" title="Excel-ul + <?= e(implode(' + ', array_column($kinds, 1))) ?> + facturile de alimentare">
                    <i class="bi bi-download" aria-hidden="true"></i> Export ZIP cu documente
                </a>
            </div>
        <?php endif; ?>
    </header>

    <form method="get" class="etoll-filters" id="etollFilterForm" autocomplete="off">
        <input type="hidden" name="page" value="etoll">
        <div class="etoll-filter etoll-filter--period<?= $closedFrom !== '' || $closedTo !== '' ? ' is-active' : '' ?>" data-period>
            <span class="etoll-filter__label" id="etollPeriodLabel">Perioadă</span>
            <button type="button" class="etoll-filter__box" aria-haspopup="true" aria-expanded="false" aria-controls="etollPeriodPop" aria-labelledby="etollPeriodLabel etollPeriodValue" data-ms-trigger>
                <i class="bi bi-calendar3 etoll-filter__lead" aria-hidden="true"></i>
                <span class="etoll-filter__value" id="etollPeriodValue" data-period-value><?= e($periodLabel) ?></span>
                <i class="bi bi-chevron-down etoll-filter__chev" aria-hidden="true"></i>
            </button>
            <div class="etoll-filter__pop etoll-filter__pop--period" id="etollPeriodPop" hidden data-ms-pop>
                <div class="etoll-seg" role="radiogroup" aria-label="Tipul perioadei">
                    <?php foreach (['luna' => 'Lună', 'an' => 'An', 'interval' => 'Interval'] as $type => $typeLabel): ?>
                        <label><input type="radio" name="perioada" value="<?= $type ?>" <?= $period['tip'] === $type ? 'checked' : '' ?>><span><?= e($typeLabel) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="etoll-period-field" data-perioada="luna">
                    <label for="etollLuna">Luna</label>
                    <input type="month" class="form-control form-control-sm" name="luna" id="etollLuna" value="<?= e($period['luna']) ?>">
                </div>
                <div class="etoll-period-field" data-perioada="an">
                    <label for="etollAn">Anul</label>
                    <select class="form-select form-select-sm" name="an" id="etollAn">
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= (string) $year === $period['an'] ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="etoll-period-field etoll-period-field--range" data-perioada="interval">
                    <div><label for="etollDeLa">De la</label><input type="date" class="form-control form-control-sm" name="de_la" id="etollDeLa" value="<?= e($period['de_la']) ?>"></div>
                    <div><label for="etollPanaLa">Până la</label><input type="date" class="form-control form-control-sm" name="pana_la" id="etollPanaLa" value="<?= e($period['pana_la']) ?>"></div>
                </div>
                <div class="etoll-period-closed" data-closed-range>
                    <div class="etoll-period-closed__head">
                        <span>Închisă la <small>(dată și oră, opțional)</small></span>
                        <button type="button" data-closed-clear>Golește</button>
                    </div>
                    <div class="etoll-closed-fields">
                        <div class="etoll-closed-field">
                            <label for="etollInchisaDe">De la</label>
                            <div class="dispatcher-datetime-field" data-datetime-picker data-closed-picker="inchisa_de">
                                <div class="input-group input-group-sm dispatcher-datetime-input-group">
                                    <input type="text" class="form-control" id="etollInchisaDe" placeholder="dd/mm/yyyy HH:mm" inputmode="numeric" autocomplete="off" maxlength="16" aria-label="Închisă la — de la" data-dtp-display>
                                    <button type="button" class="btn btn-outline-secondary" aria-label="Deschide calendarul: închisă la, de la" aria-expanded="false" data-dtp-toggle><i class="bi bi-calendar3" aria-hidden="true"></i></button>
                                </div>
                                <div class="dispatcher-datetime-popover" hidden data-dtp-popover></div>
                                <input type="hidden" value="<?= e(substr($closedFrom, 0, 10)) ?>" data-dtp-date>
                                <input type="hidden" value="<?= e(substr($closedFrom, 11, 5)) ?>" data-dtp-time>
                            </div>
                            <input type="hidden" name="inchisa_de" value="<?= e($closedFrom) ?>" data-closed-value="inchisa_de">
                        </div>
                        <div class="etoll-closed-field">
                            <label for="etollInchisaPana">Până la</label>
                            <div class="dispatcher-datetime-field" data-datetime-picker data-closed-picker="inchisa_pana">
                                <div class="input-group input-group-sm dispatcher-datetime-input-group">
                                    <input type="text" class="form-control" id="etollInchisaPana" placeholder="dd/mm/yyyy HH:mm" inputmode="numeric" autocomplete="off" maxlength="16" aria-label="Închisă la — până la" data-dtp-display>
                                    <button type="button" class="btn btn-outline-secondary" aria-label="Deschide calendarul: închisă la, până la" aria-expanded="false" data-dtp-toggle><i class="bi bi-calendar3" aria-hidden="true"></i></button>
                                </div>
                                <div class="dispatcher-datetime-popover" hidden data-dtp-popover></div>
                                <input type="hidden" value="<?= e(substr($closedTo, 0, 10)) ?>" data-dtp-date>
                                <input type="hidden" value="<?= e(substr($closedTo, 11, 5)) ?>" data-dtp-time>
                            </div>
                            <input type="hidden" name="inchisa_pana" value="<?= e($closedTo) ?>" data-closed-value="inchisa_pana">
                        </div>
                    </div>
                    <p class="etoll-period-hint">Fără oră: „De la” începe la 00:00, „Până la” ține până la 23:59.</p>
                </div>
                <p class="etoll-period-hint">Se aplică la „Aplică filtre”. Dacă intervalul „Închisă la” iese din perioadă, perioada devine Interval și îl cuprinde.</p>
            </div>
        </div>
        <?php
        $renderFilter('cap', 'Capacitate reală', 'bi-box-seam', ['' => $capacityOptions], $filters['cap']);
        $renderFilter('cat', 'Categorie capacitate', 'bi-tags', ['' => $categoryOptions], $filters['cat']);
        $renderFilter('ben', 'Beneficiari', 'bi-building', ['' => $beneficiaryOptions], $filters['ben']);
        $renderFilter('tip', 'Tip transport', 'bi-diagram-3', ['' => $transportOptions], $filters['tip']);
        $renderFilter('nr', 'Vehicule', 'bi-truck', $vehicleGroupOptions, $filters['nr'], $vehicleSearch, $vehicleHints);
        $renderFilter('status', 'Status', 'bi-list-check', ['' => $statusOptions], $filters['status']);
        ?>
        <div class="etoll-filters__buttons">
            <a class="etoll-btn etoll-btn--ghost" href="<?= e(build_query_url(['page' => 'etoll'])) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset</a>
            <button type="submit" class="etoll-btn etoll-btn--primary"><i class="bi bi-funnel" aria-hidden="true"></i> Aplică filtre</button>
        </div>
    </form>

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
        <a class="etoll-kpi etoll-kpi--cyan etoll-kpi--link" href="#etollTopups" data-open-topups title="Alimentările portofelului (toate camioanele, nu depind de filtre)">
            <span class="etoll-kpi__icon" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
            <div>
                <div class="etoll-kpi__label">Alimentări</div>
                <div class="etoll-kpi__value"><?= $topupCount ?></div>
                <div class="etoll-kpi__hint">portofel · <?= e($money($summary['alimentari']['suma'] ?? 0)) ?></div>
            </div>
            <i class="bi bi-chevron-right etoll-kpi__chev" aria-hidden="true"></i>
        </a>
    </section>

    <?php if (!empty($summary['date_din']) && $summary['date_din'] > '2026-10-02'): ?>
        <div class="alert alert-warning py-2 small mb-2">Registrul începe la <?= e($at($summary['date_din'])) ?>, nu de la deschiderea contului (01.10.2026): soldul de început nu e complet. Rulează o dată <code>php scripts/sync_etoll_situatie.php --days=60 --max-requests=4000</code>.</div>
    <?php endif; ?>

    <section class="etoll-checks">
        <div class="etoll-panel">
            <h2 class="etoll-panel__title"><i class="bi bi-patch-check" aria-hidden="true"></i> Verificarea perioadei<?php if ($hasFilters): ?> <span class="etoll-panel__scope">sesiunile filtrate</span><?php endif; ?></h2>
            <ul class="etoll-checklist">
                <li title="Facturile de alimentare ale portofelului (toate camioanele)">
                    <span>Facturi de alimentare: <strong><?= (int) ($summary['facturi_alimentare']['n'] ?? 0) ?>, <?= e($money($summary['facturi_alimentare']['suma'] ?? 0)) ?></strong></span>
                    <?php if (!empty($summary['alimentari_ok'])): ?>
                        <span class="etoll-badge is-ok">= alimentările</span>
                    <?php else: ?>
                        <span class="etoll-badge is-danger">≠ alimentările (<?= e($money($summary['alimentari']['suma'] ?? 0)) ?>)</span>
                    <?php endif; ?>
                </li>
                <li>
                    <strong>Sesiuni cu toate documentele (<?= e(mb_strtolower(implode(', ', array_column($kinds, 0)))) ?>)</strong>
                    <span class="etoll-badge <?= (int) $totals['documente_complete'] === (int) $totals['n'] ? 'is-ok' : 'is-warn' ?>"><?= (int) $totals['documente_complete'] ?> / <?= (int) $totals['n'] ?></span>
                </li>
                <?php if ($missingParts !== []): ?>
                    <li class="is-muted"><span>Lipsesc: <?= e(implode(', ', $missingParts)) ?> (se descarcă la sincronizările următoare).</span></li>
                <?php endif; ?>
                <li>
                    <strong>Suma din documente = suma sesiunii</strong>
                    <?php if ((int) $totals['suma_diferita'] === 0): ?>
                        <span class="etoll-badge is-ok">da</span>
                    <?php else: ?>
                        <span class="etoll-badge is-danger"><?= (int) $totals['suma_diferita'] ?> diferite</span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
        <div class="etoll-panel">
            <h2 class="etoll-panel__title">
                <i class="bi bi-shield-check" aria-hidden="true"></i> Soldul eToll față de alimentări − consum
                <span class="etoll-info" tabindex="0" role="note" aria-label="Soldul portofelului (toate camioanele). Cu sesiuni în curs diferența e normală: eToll scade consumul din sold înainte să-l arate pe sesiune. Verificarea exactă se face la o sincronizare fără sesiuni pornite." title="Soldul portofelului (toate camioanele, nu depinde de filtre). Cu sesiuni în curs diferența e normală: eToll scade consumul din sold înainte să-l arate pe sesiune. Verificarea exactă se face la o sincronizare fără sesiuni pornite."><i class="bi bi-info-circle" aria-hidden="true"></i></span>
            </h2>
            <div class="etoll-balance">
                <div class="etoll-balance__text">
                    <?php if ($quietBalance !== null): ?>
                        <?php $quietOk = abs((float) $quietBalance['diferenta']) < EtollStatementModel::TOLERANCE; ?>
                        <p>Fără sesiuni în curs (<?= e($at($quietBalance['verificat_la'])) ?>): eToll <?= e($money($quietBalance['sold_etoll'])) ?> · calculat <?= e($money($quietBalance['sold_calculat'])) ?> (alimentări <?= e($money($quietBalance['alimentari'])) ?> − consum <?= e($money($quietBalance['consum_inchis'])) ?>)
                            <span class="etoll-badge <?= $quietOk ? 'is-ok' : 'is-danger' ?>"><?= $quietOk ? 'egal' : 'diferență ' . e($money($quietBalance['diferenta'])) ?></span></p>
                    <?php else: ?>
                        <p>Încă nicio verificare fără sesiuni în curs. Verificarea exactă se face la o sincronizare în care niciun camion nu are sesiunea pornită (de obicei noaptea).</p>
                    <?php endif; ?>
                    <?php if ($balance !== null): ?>
                        <p class="etoll-balance__last"><strong>Ultima verificare: <?= e($at($balance['verificat_la'])) ?></strong><br>
                            eToll <?= e($money($balance['sold_etoll'])) ?> – calculat <?= e($money($balance['sold_calculat'])) ?> – diferență <?= e($money($balance['diferenta'])) ?>
                            <?php if ((int) $balance['sesiuni_in_curs'] > 0): ?><br><span class="etoll-muted">Cu sesiuni în curs diferența e normală: eToll scade consumul din sold înainte să-l arate pe sesiune.</span><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php if ($balance !== null): ?>
                    <?php $balanceOk = abs((float) $balance['diferenta']) < EtollStatementModel::TOLERANCE; ?>
                    <span class="etoll-badge etoll-badge--lg <?= (int) $balance['sesiuni_in_curs'] > 0 ? 'is-info' : ($balanceOk ? 'is-ok' : 'is-danger') ?>"><?= (int) $balance['sesiuni_in_curs'] > 0 ? (int) $balance['sesiuni_in_curs'] . ' sesiuni în curs' : ($balanceOk ? 'Sold egal' : 'Diferență ' . e($money($balance['diferenta']))) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="etoll-sessions" id="etollSessions">
        <div class="etoll-sessions__bar">
            <div class="etoll-tabs" role="tablist" aria-label="Sesiuni">
                <button type="button" class="etoll-tab is-active" role="tab" aria-selected="true" aria-controls="etollTable" data-tab="closed"><i class="bi bi-signpost-split" aria-hidden="true"></i><span>Sesiuni închise (<span data-tab-count="closed"><?= count($sessions) ?></span>)</span></button>
                <button type="button" class="etoll-tab" role="tab" aria-selected="false" aria-controls="etollTable" data-tab="open"><i class="bi bi-clock" aria-hidden="true"></i><span>Sesiuni în curs (<span data-tab-count="open"><?= count($openSessions) ?></span>)</span></button>
                <button type="button" class="etoll-tab etoll-tab--warn" role="tab" aria-selected="false" aria-controls="etollTable" data-tab="missing"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Lipsă documente (<span data-tab-count="missing"><?= $missingCount ?></span>)</span></button>
            </div>
            <div class="etoll-sessions__actions">
                <button type="button" class="etoll-btn etoll-btn--ghost" data-export-csv><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                <?php if ($canExport): ?>
                    <a class="etoll-btn etoll-btn--ghost" href="<?= e(build_query_url($query + ['action' => 'export_xlsx'])) ?>"><i class="bi bi-file-earmark-excel text-success" aria-hidden="true"></i> Export Excel</a>
                    <button type="button" class="etoll-btn etoll-btn--primary" data-download-selected disabled title="Bifează sesiuni cu documente"><i class="bi bi-download" aria-hidden="true"></i> Descarcă facturi (selectate)</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="etoll-card">
            <div class="etoll-table-wrap">
                <table class="etoll-table" id="etollTable">
                    <colgroup>
                        <col class="c-check"><col class="c-closed"><col class="c-plate"><col class="c-ben"><col class="c-span"><col class="c-route">
                        <col class="c-km"><col class="c-money"><col class="c-money"><col class="c-money"><col class="c-doc"><col class="c-pdf"><col class="c-check-status">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="t-center"><input type="checkbox" class="form-check-input" aria-label="Selectează toate sesiunile din tab" data-select-all></th>
                            <th><button type="button" class="etoll-sort" data-sort="closed">Închisă la</button><button type="button" class="etoll-th-filter<?= $closedFrom !== '' || $closedTo !== '' ? ' is-active' : '' ?>" data-closed-open title="Filtrează după data și ora închiderii" aria-label="Filtrează după Închisă la"><i class="bi bi-funnel<?= $closedFrom !== '' || $closedTo !== '' ? '-fill' : '' ?>" aria-hidden="true"></i></button></th>
                            <th><button type="button" class="etoll-sort" data-sort="plate">Nr. auto</button></th>
                            <th><button type="button" class="etoll-sort" data-sort="ben">Beneficiar</button></th>
                            <th><button type="button" class="etoll-sort" data-sort="start">Început → sfârșit</button></th>
                            <th>Traseu</th>
                            <th class="t-end"><button type="button" class="etoll-sort" data-sort="km">Km</button></th>
                            <th class="t-end"><button type="button" class="etoll-sort" data-sort="net">Fără TVA</button></th>
                            <th class="t-end">TVA</th>
                            <th class="t-end"><button type="button" class="etoll-sort" data-sort="gross">Cu TVA</button></th>
                            <th>Document</th>
                            <th>PDF</th>
                            <th>Verificare</th>
                        </tr>
                    </thead>
                    <tbody data-rows>
                        <tr><td colspan="13" class="etoll-empty">Se încarcă sesiunile…</td></tr>
                    </tbody>
                    <tfoot class="etoll-total" data-totals>
                        <tr>
                            <td></td>
                            <td colspan="6">
                                <div class="etoll-total__label">
                                    <span class="etoll-total__sigma" aria-hidden="true">Σ</span>
                                    <strong>TOTAL</strong>
                                    <span class="etoll-total__count" data-total-count></span>
                                    <span class="etoll-total__scope" data-total-scope-label></span>
                                </div>
                            </td>
                            <td class="t-end"><strong data-total-net>—</strong><small data-total-net-note></small></td>
                            <td class="t-end"><strong data-total-vat>—</strong><small data-total-vat-note></small></td>
                            <td class="t-end etoll-total__gross"><strong data-total-gross>—</strong><small>toate sesiunile</small></td>
                            <td colspan="3">
                                <div class="etoll-total__switch" role="radiogroup" aria-label="Ce sesiuni intră în total">
                                    <button type="button" role="radio" aria-checked="true" class="is-active" data-total-scope="filtrate" title="Sesiunile care respectă filtrele aplicate (perioadă, vehicule, beneficiari, tip transport, status...)">Filtrate</button>
                                    <button type="button" role="radio" aria-checked="false" data-total-scope="toate" title="Toate sesiunile perioadei, fără celelalte filtre">Toate</button>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="etoll-pager">
                <div class="etoll-pager__left">
                    <label for="etollPageSize">Afișează</label>
                    <select id="etollPageSize" class="form-select form-select-sm" data-page-size>
                        <?php foreach ([25, 50, 100, 200] as $size): ?><option value="<?= $size ?>" <?= $size === 50 ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                    </select>
                    <span>înregistrări</span>
                    <span class="etoll-selected" data-selected-count hidden></span>
                </div>
                <nav class="etoll-pager__pages" aria-label="Paginare" data-pages></nav>
                <div class="etoll-pager__range" data-range></div>
            </div>
        </div>
    </section>

    <section class="etoll-card etoll-topups" id="etollTopups">
        <button type="button" class="etoll-topups__head" aria-expanded="true" aria-controls="etollTopupsBody" data-topups-toggle>
            <span><i class="bi bi-fuel-pump" aria-hidden="true"></i> Alimentări (<?= $topupCount ?>)</span>
            <i class="bi bi-chevron-up etoll-topups__chev" aria-hidden="true"></i>
        </button>
        <div class="etoll-topups__body" id="etollTopupsBody">
            <div class="etoll-wallet" aria-label="Portofel eToll">
                <div class="etoll-wallet__title">Portofel eToll — <?= e($period['eticheta']) ?> <span>(toate camioanele, nu depinde de filtre)</span></div>
                <div class="etoll-wallet__grid">
                    <div><span>Sold la început</span><strong><?= e($money($summary['sold_inceput'] ?? null)) ?></strong></div>
                    <div><span>+ Alimentări</span><strong><?= e($money($summary['alimentari']['suma'] ?? null)) ?></strong><small><?= (int) ($summary['alimentari']['n'] ?? 0) ?> plăți · fără TVA <?= e($money($summary['alimentari_fara_tva'] ?? 0)) ?>, TVA <?= e($money($summary['alimentari_tva'] ?? 0)) ?></small></div>
                    <div><span>− Consum</span><strong><?= e($money($summary['consum']['suma'] ?? null)) ?></strong><small><?= (int) ($summary['consum']['n'] ?? 0) ?> sesiuni închise · fără TVA <?= e($money($summary['consum_fara_tva'] ?? 0)) ?>, TVA <?= e($money($summary['consum_tva'] ?? 0)) ?><?= (int) ($summary['consum_fara_tva_lipsa'] ?? 0) > 0 ? ' (' . (int) $summary['consum_fara_tva_lipsa'] . ' fără TVA citit încă)' : '' ?></small></div>
                    <div><span>= Sold la final</span><strong><?= e($money($summary['sold_final'] ?? null)) ?></strong></div>
                </div>
            </div>
            <div class="etoll-table-wrap">
                <table class="etoll-table etoll-table--topups">
                    <thead><tr><th><button type="button" class="etoll-sort is-static">Data plății <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th><th class="t-end">Sumă</th><th class="t-end">Fără TVA</th><th class="t-end">TVA</th><th>Factură</th><th>PDF</th><th>Verificare</th></tr></thead>
                    <tbody>
                        <?php if ($topups === []): ?>
                            <tr><td colspan="7" class="etoll-empty">Nicio alimentare în perioadă.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($topups as $row): $invoice = $row['factura']; ?>
                            <tr>
                                <td class="t-nowrap"><?= e($at($row['data'])) ?></td>
                                <td class="t-end t-nowrap"><?= e($money($row['suma'])) ?></td>
                                <td class="t-end t-nowrap"><?= e($money($invoice['fara_tva'] ?? null)) ?></td>
                                <td class="t-end t-nowrap"><?= e($money($invoice['tva'] ?? null)) ?></td>
                                <td><?= e((string) ($invoice['numar_document'] ?? '—')) ?></td>
                                <td><?php if ($invoice !== null && !empty($invoice['pdf_path'])): ?><a class="etoll-doc" href="<?= e(build_query_url(['page' => 'etoll', 'action' => 'alimentare', 'id' => (string) $invoice['invoice_id']])) ?>" target="_blank" rel="noopener" data-preview-title="Factura de alimentare <?= e((string) ($invoice['numar_document'] ?? '')) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Factură</a><?php else: ?><span class="etoll-muted">—</span><?php endif; ?></td>
                                <td>
                                    <?php if ($row['tx_id'] === null): ?><span class="etoll-badge is-danger">Factură fără alimentare</span>
                                    <?php elseif ($invoice === null): ?><span class="etoll-badge is-warn">Lipsește factura</span>
                                    <?php else: ?><span class="etoll-badge is-ok">OK</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($canExport): ?>
        <form method="post" action="<?= e(build_query_url(['page' => 'etoll', 'action' => 'export_selected'])) ?>" id="etollSelectedForm" hidden>
            <?= csrf_field() ?>
        </form>
    <?php endif; ?>
</div>

<script type="application/json" id="etollData"><?= json_encode([
    'rows' => $tableRows,
    'resetUrl' => build_query_url(['page' => 'etoll']),
    'hasFilters' => $hasFilters,
    // Totalurile vin calculate de server (aceleasi sume ca in sessionTotals, pe tab); textul e formatat aici.
    'totals' => array_map(static fn(array $tabs): array => array_map(static fn(array $t): array => $t + [
        'faraText' => format_number_ro($t['fara_tva'], 2) . ' lei',
        'tvaText' => format_number_ro($t['tva'], 2) . ' lei',
        'sumaText' => format_number_ro($t['suma'], 2) . ' lei',
    ], $tabs), is_array($tableTotals ?? null) ? $tableTotals : ['filtrate' => EtollStatementModel::tabTotals($sessions, $openSessions)]),
    'csvName' => 'Situatie_eToll_' . ($period['tip'] === 'an' ? $period['an'] : ($period['tip'] === 'interval' ? $period['de_la'] . '_' . $period['pana_la'] : $period['luna'])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="<?= e(url('assets/js/datetime-picker-field.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/datetime-picker-field.js'))) ?>"></script>
<script src="<?= e(url('assets/js/etoll.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/etoll.js'))) ?>"></script>
