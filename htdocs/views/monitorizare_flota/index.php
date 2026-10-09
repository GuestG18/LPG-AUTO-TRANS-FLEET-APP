<?php
/**
 * Monitorizare flotă - etapa 1: harta 3D MapLibre/OpenFreeMap, fara vehicule.
 * Logica hartii este in assets/js/fleet-monitoring-map.js; stilurile in
 * assets/css/fleet-monitoring.css.
 *
 * MapLibre GL JS: aceeasi versiune pentru CSS si JS (vezi $maplibreVersion).
 */
$maplibreVersion = '5.24.0';
$cssVersion = (string) @filemtime(BASE_PATH . '/assets/css/fleet-monitoring.css');
$jsVersion = (string) @filemtime(BASE_PATH . '/assets/js/fleet-monitoring-map.js');
$demoJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/fleet-monitoring-demo.js');
// Modelul 3D si three.js sunt aceleasi ca in Stare tehnica (vendor local, fara CDN).
// Importurile ES sunt supuse CORS: cale root-relative, ca in views/technical_health.
$threeVendorBase = '/' . ltrim(rtrim(url('assets/vendor/three'), '/'), '/');
$truckModelUrl = url('assets/models/technical-health/truck_edit_v1.glb');
?>
<script type="importmap">
    {"imports": {"three": "<?= e($threeVendorBase . '/three.module.js') ?>"}}
</script>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@<?= e($maplibreVersion) ?>/dist/maplibre-gl.css"
      integrity="sha384-uTttxo/aOKbdE5RlD/SPzSDoDmNvGlUYPjONi2MN/b7c9HPSvW07OIuyP7uL6jxK" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(url('assets/css/fleet-monitoring.css?v=' . $cssVersion)) ?>">

<div class="fleet-monitoring"
     data-truck-icon="<?= e(url('assets/img/fleet-truck-top.svg')) ?>"
     data-truck-model="<?= e($truckModelUrl) ?>"
     data-three-module="<?= e($threeVendorBase . '/three.module.js') ?>"
     data-gltf-loader="<?= e($threeVendorBase . '/GLTFLoader.js') ?>">
    <div class="fleet-monitoring-toolbar">
        <h1 class="h5 mb-0">Monitorizare flotă</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- DEMO (date de test): se sterge la integrarea SAS, impreuna cu fleet-monitoring-demo.js -->
            <div class="fleet-monitoring-demo" role="group" aria-label="Camion de test">
                <span class="badge text-bg-warning">TEST</span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" data-demo="start" disabled>
                        <i class="bi bi-play-fill" aria-hidden="true"></i> <span data-label>Start demo</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-demo="pause" disabled>
                        <i class="bi bi-pause-fill" aria-hidden="true"></i> Pauză
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-demo="reset" disabled>
                        <i class="bi bi-skip-backward-fill" aria-hidden="true"></i> Reset camion
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-demo="focus" disabled title="Centrează harta pe camion (o singură dată, fără urmărire)">
                        <i class="bi bi-crosshair" aria-hidden="true"></i> Focus camion
                    </button>
                </div>
            </div>
            <span class="fleet-monitoring-divider d-none d-md-inline" aria-hidden="true"></span>
            <span class="text-muted small d-none d-md-inline">Clădirile 3D apar de la zoom ~15</span>
            <button type="button" class="btn btn-sm btn-dark" id="fleet-monitoring-3d" aria-pressed="true" disabled>
                <i class="bi bi-badge-3d" aria-hidden="true"></i>
                <span data-label>3D ON</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="fleet-monitoring-reset" disabled>
                <i class="bi bi-bullseye" aria-hidden="true"></i>
                <span>Resetare vizualizare</span>
            </button>
        </div>
    </div>

    <div class="fleet-monitoring-stage" id="fleet-monitoring-stage">
        <div id="fleet-monitoring-map" class="fleet-monitoring-map" aria-label="Harta flotei"></div>

        <div class="fleet-monitoring-camera btn-group-vertical" role="group" aria-label="Comenzi cameră">
            <button type="button" class="btn btn-sm" data-camera="zoom-in" title="Mărire" disabled><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm" data-camera="zoom-out" title="Micșorare" disabled><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm" data-camera="rotate-left" title="Rotire stânga" disabled><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm" data-camera="rotate-right" title="Rotire dreapta" disabled><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
        </div>

        <div class="fleet-monitoring-status" id="fleet-monitoring-status" role="status">Se încarcă harta…</div>
    </div>
</div>

<script src="https://unpkg.com/maplibre-gl@<?= e($maplibreVersion) ?>/dist/maplibre-gl.js"
        integrity="sha384-5+cfbwT0iiub6VsQAdn6yz16nr6sDiQoHx6tm4O8OVYXHYOxcffFmCJBL0dgdvGp" crossorigin="anonymous" defer></script>
<script src="<?= e(url('assets/js/fleet-monitoring-map.js?v=' . $jsVersion)) ?>" defer></script>
<script src="<?= e(url('assets/js/fleet-monitoring-demo.js?v=' . $demoJsVersion)) ?>" defer></script>
