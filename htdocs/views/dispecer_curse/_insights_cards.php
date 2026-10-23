<?php
/**
 * Cardurile din dreapta formularului "Adauga cursa": doua semnale pentru dispecer, fiecare
 * se extinde (morph) intr-un panou de lucru.
 *  - Km pierduti (SAS): km GPS rulati fara cursa / service, cu alocare pe cursa noua sau Km service.
 *  - Alimentari neasociate (CardOil): alimentarile din API fara cursa, cu motivul si asociere manuala.
 * Datele se incarca din frontend (assets/js/dispatcher-insights.js), dupa randarea paginii.
 */
$insightsCanKm = !function_exists('can') || can('km_pierduti');
$insightsConfig = [
    'kmUrl' => build_query_url(['page' => 'km_pierduti']),
    'fuelUrl' => build_query_url(['page' => 'dispecer_curse', 'action' => 'insights_fuel']),
    'fuelDetailUrl' => build_query_url(['page' => 'dispecer_curse', 'action' => 'insights_fuel_detail']),
    'fuelLinkUrl' => build_query_url(['page' => 'dispecer_curse', 'action' => 'insights_fuel_link']),
    'dispatcherUrl' => build_query_url(['page' => 'dispecer_curse']),
    'tripUrl' => build_query_url(['page' => 'dispecer_curse', 'action' => 'view']),
    'csrf' => csrf_token(),
    'canKm' => $insightsCanKm,
    'canRace' => !function_exists('can') || can('dispecer_curse', 'create'),
    'canService' => !function_exists('can') || can('dispecer_curse', 'service_km'),
];
?>
<div class="dins-stack" data-dins='<?= e((string) json_encode($insightsConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
    <button type="button" class="dins-card dins-card--km" data-dins-card="km" aria-haspopup="dialog">
        <span class="dins-card-head">
            <span class="dins-chip"><i class="bi bi-graph-down-arrow" aria-hidden="true"></i></span>
            <span class="dins-card-title">Km pierduți <small>GPS SAS · luna aceasta</small></span>
            <i class="bi bi-arrows-angle-expand dins-card-expand" aria-hidden="true"></i>
        </span>
        <span class="dins-card-body" data-dins-body="km">
            <?php if ($insightsCanKm): ?>
                <span class="dins-muted"><span class="spinner-border spinner-border-sm me-1"></span>Se încarcă flota din SAS…</span>
            <?php else: ?>
                <span class="dins-muted">Nu ai acces la raportul Km pierduți.</span>
            <?php endif; ?>
        </span>
    </button>

    <div class="dins-splitter dins-splitter--row" data-dins-split="row" role="separator" tabindex="0"
         aria-orientation="horizontal" aria-label="Înălțimea cardurilor (săgeți sus/jos, dublu-click pentru implicit)"
         title="Trage pentru a redimensiona · dublu-click = implicit"><span class="dins-grip" aria-hidden="true"></span></div>

    <button type="button" class="dins-card dins-card--fuel" data-dins-card="fuel" aria-haspopup="dialog">
        <span class="dins-card-head">
            <span class="dins-chip"><i class="bi bi-fuel-pump" aria-hidden="true"></i></span>
            <span class="dins-card-title">Alimentări neasociate <small>CardOil · ultimele 30 de zile</small></span>
            <i class="bi bi-arrows-angle-expand dins-card-expand" aria-hidden="true"></i>
        </span>
        <span class="dins-card-body" data-dins-body="fuel">
            <span class="dins-muted"><span class="spinner-border spinner-border-sm me-1"></span>Se încarcă alimentările…</span>
        </span>
    </button>
</div>
