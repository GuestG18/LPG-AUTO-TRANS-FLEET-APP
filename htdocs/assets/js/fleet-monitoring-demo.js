/*
 * Monitorizare flotă - DEMO cu UN camion de test (etapa 2).
 *
 * DATE DE TEST, doar in browser: nu exista in PHP, in baza de date sau printre
 * vehiculele aplicatiei. La integrarea SAS se sterg: acest fisier, tag-ul lui
 * <script> si grupul "Camion test" din views/monitorizare_flota/index.php.
 * Stratul de vehicule si motorul de redare (fleet-monitoring-map.js) raman.
 */
(function () {
    'use strict';

    var TEST_VEHICLE = { id: 'TEST-001', registration: 'B-123-LPG', status: 'moving' };

    // Bucuresti: Calea Victoriei (de la Palatul Regal spre nord) -> Piata Victoriei ->
    // Bd. Lascar Catargiu -> Piata Romana. Puncte luate din geometria drumurilor OpenFreeMap.
    var TEST_ROUTE = [
        [26.09777, 44.43329], [26.09811, 44.43388], [26.09826, 44.43422], [26.09831, 44.43448],
        [26.09830, 44.43456], [26.09797, 44.43527], [26.09790, 44.43556], [26.09787, 44.43582],
        [26.09789, 44.43637], [26.09784, 44.43661], [26.09766, 44.43702], [26.09763, 44.43722],
        [26.09749, 44.43768], [26.09729, 44.43807], [26.09718, 44.43839], [26.09713, 44.43879],
        [26.09700, 44.43923], [26.09687, 44.43951], [26.09662, 44.43990], [26.09612, 44.44038],
        [26.09557, 44.44080], [26.09458, 44.44164], [26.09390, 44.44233], [26.09304, 44.44329],
        [26.09201, 44.44431], [26.09102, 44.44549], [26.09017, 44.44681], [26.08954, 44.44777],
        [26.08913, 44.44834], [26.08792, 44.44996], [26.08748, 44.45062], [26.08640, 44.45177],
        [26.08717, 44.45171], [26.08759, 44.45151], [26.08902, 44.45078], [26.09149, 44.44952],
        [26.09609, 44.44720]
    ];

    // Timestamp-uri de test calculate la o viteza constanta, apoi redate accelerat:
    // ~4 km la 40 km/h = ~6 min reale, redate de 12 ori mai repede (~30 s).
    var TEST_SPEED_KMH = 40;
    var TEST_PLAYBACK_RATE = 12;
    var TEST_START_TIMESTAMP = Date.parse('2026-01-01T08:00:00Z');

    var ROUTE_SOURCE_ID = 'test-vehicle-route';
    var ROUTE_LAYER_ID = 'test-vehicle-route-line';

    function distanceMeters(a, b) {
        var toRad = Math.PI / 180;
        var dLat = (b[1] - a[1]) * toRad;
        var dLng = (b[0] - a[0]) * toRad;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(a[1] * toRad) * Math.cos(b[1] * toRad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 6371008.8 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    // Acelasi format pe care il vor avea pozitiile SAS: {lng, lat, t}.
    function buildTestSamples() {
        var metersPerMs = TEST_SPEED_KMH * 1000 / 3600000;
        var t = TEST_START_TIMESTAMP;
        return TEST_ROUTE.map(function (point, index) {
            if (index > 0) {
                t += distanceMeters(TEST_ROUTE[index - 1], point) / metersPerMs;
            }
            return { lng: point[0], lat: point[1], t: Math.round(t) };
        });
    }

    function addTestRouteLayer(api) {
        var map = api.map;
        if (!map.getSource(ROUTE_SOURCE_ID)) {
            map.addSource(ROUTE_SOURCE_ID, {
                type: 'geojson',
                data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: TEST_ROUTE } }
            });
        }
        if (!map.getLayer(ROUTE_LAYER_ID)) {
            // Sub stratul de vehicule: camionul ramane dominant.
            map.addLayer({
                id: ROUTE_LAYER_ID,
                type: 'line',
                source: ROUTE_SOURCE_ID,
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    // Saturata intentionat: filtrul intunecat al hartii o atenueaza.
                    'line-color': '#1ec8ff',
                    'line-opacity': 0.75,
                    'line-width': ['interpolate', ['linear'], ['zoom'], 10, 2, 16, 5],
                    'line-dasharray': [2, 1.5]
                }
            }, api.vehicleLayerId);
        }
    }

    function initializeDemoControls(api, root) {
        var buttons = {};
        root.querySelectorAll('[data-demo]').forEach(function (button) {
            buttons[button.getAttribute('data-demo')] = button;
        });
        if (!buttons.start) { return; }
        var startLabel = buttons.start.querySelector('[data-label]');

        buttons.start.addEventListener('click', api.playback.start);
        buttons.pause.addEventListener('click', api.playback.pause);
        buttons.reset.addEventListener('click', api.playback.reset);
        buttons.focus.addEventListener('click', function () { api.focusVehicle(TEST_VEHICLE.id); });

        function sync(playbackState) {
            buttons.start.disabled = playbackState.running;
            buttons.pause.disabled = !playbackState.running;
            buttons.reset.disabled = false;
            buttons.focus.disabled = false;
            startLabel.textContent = (!playbackState.running && !playbackState.atStart && !playbackState.finished)
                ? 'Continuă'
                : 'Start demo';
        }
        api.playback.onChange(sync);
        sync(api.playback.getState());
    }

    function startDemo(root) {
        var api = window.FleetMonitoring;
        if (!api || !api.vehiclesAvailable) { return; }

        addTestRouteLayer(api);
        initializeDemoControls(api, root);
        api.playback.setTracks(
            [{ vehicle: TEST_VEHICLE, samples: buildTestSamples() }],
            { rate: TEST_PLAYBACK_RATE }
        );
    }

    function boot() {
        var root = document.querySelector('.fleet-monitoring');
        if (!root || root.getAttribute('data-demo-initialized') === '1') { return; }
        root.setAttribute('data-demo-initialized', '1');

        if (root.getAttribute('data-map-ready') === '1') {
            startDemo(root);
        } else {
            root.addEventListener('fleet-monitoring:ready', function () { startDemo(root); }, { once: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
