<?php
/**
 * Schema vehiculului (exploratorul de componente) pe pagina Auto: arata ce e in configuratia aleasa
 * in pagina (vehicul real, din ModuleController::fleetExplorerData, sau configuratie generala).
 *
 * tire-fleet.js e inclus doar pentru geometria comuna (window.FleetVehicleGeometry): are nevoie de
 * #ftUnitsData si [data-ft-config], dar fara lista de vehicule nu leaga nimic in pagina.
 *
 * Intrare: $vehicleExplorer (['unit' => ..., 'categories' => ...] sau null).
 */
include __DIR__ . '/../module/tire_stock/_fleet_icons.php';

$explorerUnit = is_array($vehicleExplorer['unit'] ?? null) ? $vehicleExplorer['unit'] : null;
$explorerAsset = static fn (string $path): string => url($path . '?v=' . (string) @filemtime(BASE_PATH . '/' . $path));
?>
<section class="repair-auto-xray" data-auto-xray-section aria-label="Schema vehiculului">
    <header class="repair-auto-xray-head">
        <span class="repair-auto-panel-title">SCHEMA VEHICUL</span>
        <?php if ($explorerUnit !== null): ?>
            <button type="button" class="repair-auto-xray-toggle" data-auto-xray-toggle aria-expanded="true">
                <i class="bi bi-chevron-up" aria-hidden="true"></i><span data-auto-xray-toggle-label>Ascunde schema</span>
            </button>
        <?php endif; ?>
    </header>
    <?php if ($explorerUnit === null): ?>
        <p class="repair-auto-xray-empty">Schema nu este disponibilă pentru selecția curentă.</p>
    <?php else: ?>
        <div
            data-auto-xray
            data-ft-unit="<?= e((string) $explorerUnit['key']) ?>"
            data-tire-url="<?= can('mentenanta', 'tire_stock') ? e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock'])) : '' ?>"
        >
            <span class="ft-vehicle-icon" hidden><?= $fleetVehicleIcon((string) ($explorerUnit['kind'] ?? 'camion')) ?></span>
            <div class="fx-explorer" data-fx-explorer></div>
            <div class="fx-panel" data-fx-panel hidden aria-live="polite"></div>
            <div data-fx-tire-area hidden></div>
        </div>

        <script type="application/json" id="ftUnitsData"><?= json_encode([$explorerUnit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <div hidden data-ft-config
             data-component-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_fleet_component'])) ?>"
             data-component-write-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'component_mount'])) ?>"
             data-can-manage="<?= can('mentenanta', 'components_manage') ? '1' : '0' ?>"
             data-csrf="<?= e(csrf_token()) ?>"></div>
        <script type="application/json" id="fxUnitIcons"><?= json_encode(['tractor' => $fleetTractorIcon, 'trailer' => $fleetTrailerIcon, 'assembly' => $fleetVehicleIcon('ansamblu')], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script type="application/json" id="fxCategories"><?= json_encode($vehicleExplorer['categories'] ?? VehicleComponentRegistry::clientCategories(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <link rel="stylesheet" href="<?= e($explorerAsset('assets/css/vehicle-xray.css')) ?>">
        <script src="<?= e($explorerAsset('assets/js/tire-fleet.js')) ?>"></script>
        <script src="<?= e($explorerAsset('assets/js/vehicle-xray-engine.js')) ?>"></script>
        <script src="<?= e($explorerAsset('assets/js/vehicle-xray-art.js')) ?>"></script>
        <script src="<?= e($explorerAsset('assets/js/vehicle-xray.js')) ?>"></script>
        <script src="<?= e($explorerAsset('assets/js/auto-vehicle-explorer.js')) ?>"></script>
    <?php endif; ?>
</section>
