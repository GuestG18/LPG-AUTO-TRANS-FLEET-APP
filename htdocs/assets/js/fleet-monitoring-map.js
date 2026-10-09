/*
 * Monitorizare flotă - etapa 1: fundatia hartii 3D.
 *
 * MapLibre GL JS + stilul OpenFreeMap "bright", cu cladiri extrudate
 * (fill-extrusion) din stratul vectorial "building" - acelasi principiu ca in
 * implementarea de referinta. NU este relief 3D (teren DEM): doar cladiri.
 *
 * Vehiculele: o sursa GeoJSON + strat symbol (iconul plat) si, de aproape in 3D,
 * modelul GLB din Stare tehnica (three.js, strat custom), miscate de un motor de redare in
 * timp (requestAnimationFrame, doar cat ruleaza). Datele vin prin
 * window.FleetMonitoring.playback.setTracks(): acum din fleet-monitoring-demo.js
 * (date de test), ulterior dintr-un endpoint al aplicatiei (proxy SAS pe backend),
 * niciodata direct din browser. Fara polling: cand redarea e oprita, nimic in fundal.
 */
(function () {
    'use strict';

    var STYLE_URL = 'https://tiles.openfreemap.org/styles/bright';
    var OPENFREEMAP_TILES_URL = 'https://tiles.openfreemap.org/planet';
    var BUILDINGS_SOURCE_ID = 'openfreemap-3d';
    var BUILDINGS_LAYER_ID = 'fleet-3d-buildings';

    // Romania (vest-est, sud-nord); camera de ansamblu se calculeaza din ele,
    // ca sa arate toata tara indiferent de rezolutia monitorului.
    var ROMANIA_BOUNDS = [[20.26, 43.62], [29.76, 48.27]];
    var ROMANIA_CENTER = [24.97, 45.94];
    // zoomBoost: cameraForBounds nu tine cont de inclinare, iar in perspectiva
    // tara apare mai mica decat incadrarea calculata; corectia o readuce in cadru.
    var VIEW_3D = { pitch: 50, bearing: -15, zoomBoost: 0.45 };
    var VIEW_2D = { pitch: 0, bearing: 0, zoomBoost: 0 };
    var MAX_PITCH_3D = 70;
    var ROTATE_STEP = 20;

    // Stratul de vehicule: o singura sursa GeoJSON (toata flota), actualizata cu setData.
    var VEHICLE_SOURCE_ID = 'fleet-vehicles';
    var VEHICLE_LAYER_ID = 'fleet-vehicles-symbol';
    var VEHICLE_ICON_ID = 'fleet-truck';
    var VEHICLE_ICON_PIXEL_RATIO = 2; // SVG-ul are 96px pentru 48px CSS: clar pe ecrane HiDPI
    var VEHICLE_LABEL_LAYER_ID = 'fleet-vehicles-label';

    // Modelul 3D (acelasi GLB ca in Stare tehnica), desenat cu three.js intr-un strat custom.
    var VEHICLE_MODEL_LAYER_ID = 'fleet-vehicles-3d';
    var MODEL_MIN_ZOOM = 16;        // sub acest zoom (sau in 2D) ramane iconul plat
    var MODEL_LENGTH_METERS = 6.2;  // lungimea reala a unui cap tractor
    // La scara reala un camion are ~2 px la zoom 16; modelul e marit pana la cel putin
    // atatia pixeli lungime si revine la scara reala cand zoom-ul e destul de mare.
    var MODEL_MIN_LENGTH_PX = 56;

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var state = {
        map: null,
        loaded: false,
        is3d: true
    };

    var dom = {};

    // Ultimele pozitii desenate, dupa id (pentru "Focus camion" si etapele urmatoare).
    var vehiclesById = {};

    /*
     * Redare in timp a pozitiilor. Fiecare traseu are esantioane {lng, lat, t}
     * (t = timestamp in ms); pozitia se calculeaza pentru "timpul de redare",
     * care avanseaza cu timpul real scurs (nu cu numarul de cadre) inmultit cu rate.
     * Acum esantioanele vin din fisierul demo; ulterior vor fi istoricul SAS din
     * backend, cu timpul de redare = acum - 60 minute.
     */
    var playback = {
        tracks: [],
        startTime: 0,
        endTime: 0,
        time: 0,
        rate: 1,
        running: false,
        rafId: 0,
        lastFrame: null,
        listeners: []
    };

    function duration(ms) {
        return reducedMotion ? 0 : ms;
    }

    function setStatus(message, isError) {
        if (!dom.status) { return; }
        if (!message) {
            dom.status.hidden = true;
            return;
        }
        dom.status.hidden = false;
        dom.status.textContent = message;
        dom.status.classList.toggle('is-error', !!isError);
    }

    /* ---------- Dimensionare: harta ocupa restul ferestrei ---------- */

    function fitStageHeight() {
        var top = dom.stage.getBoundingClientRect().top + window.scrollY;
        var available = Math.floor(window.innerHeight - top - 24);
        dom.stage.style.setProperty('--fleet-monitoring-height', Math.max(420, available) + 'px');
    }

    function initializeResizeHandling() {
        var frame = 0;
        window.addEventListener('resize', function () {
            if (frame) { return; }
            frame = window.requestAnimationFrame(function () {
                frame = 0;
                fitStageHeight();
            });
        });

        // Orice schimbare a containerului (fereastra, meniul lateral, alt monitor)
        // ajunge aici; MapLibre redimensioneaza canvasul o singura data per cadru.
        if ('ResizeObserver' in window) {
            var resizeFrame = 0;
            new ResizeObserver(function () {
                if (resizeFrame || !state.map) { return; }
                resizeFrame = window.requestAnimationFrame(function () {
                    resizeFrame = 0;
                    state.map.resize();
                });
            }).observe(dom.stage);
        }
    }

    /* ---------- Camera ---------- */

    function overviewCamera() {
        var view = state.is3d ? VIEW_3D : VIEW_2D;
        var camera = null;
        if (state.map) {
            camera = state.map.cameraForBounds(ROMANIA_BOUNDS, { padding: 40, bearing: view.bearing });
        }
        return {
            center: camera ? camera.center : ROMANIA_CENTER,
            zoom: camera ? camera.zoom + view.zoomBoost : 6.3,
            pitch: view.pitch,
            bearing: view.bearing
        };
    }

    function resetMapView() {
        if (!state.map) { return; }
        var camera = overviewCamera();
        camera.duration = duration(1200);
        state.map.flyTo(camera);
    }

    /* ---------- Cladiri 3D (fill-extrusion), ca in referinta ---------- */

    function findVectorSourceId(style, url) {
        var sources = style.sources || {};
        for (var id in sources) {
            if (Object.prototype.hasOwnProperty.call(sources, id) && sources[id].type === 'vector' && sources[id].url === url) {
                return id;
            }
        }
        return null;
    }

    function add3DBuildings() {
        var map = state.map;
        if (map.getLayer(BUILDINGS_LAYER_ID)) { return; }

        var style = map.getStyle();
        var layers = style.layers || [];

        // Primul strat de etichete: cladirile se pun sub el, ca textul sa ramana lizibil.
        var labelLayerId;
        for (var i = 0; i < layers.length; i++) {
            if (layers[i].type === 'symbol' && layers[i].layout && layers[i].layout['text-field']) {
                labelLayerId = layers[i].id;
                break;
            }
        }

        // Stilul "bright" are deja sursa OpenFreeMap; o refolosim ca sa nu descarcam
        // aceleasi tile-uri de doua ori. Altfel o adaugam o singura data.
        var sourceId = findVectorSourceId(style, OPENFREEMAP_TILES_URL);
        if (!sourceId) {
            sourceId = BUILDINGS_SOURCE_ID;
            if (!map.getSource(sourceId)) {
                map.addSource(sourceId, { type: 'vector', url: OPENFREEMAP_TILES_URL });
            }
        }

        var height = ['coalesce', ['get', 'render_height'], 12];
        map.addLayer({
            id: BUILDINGS_LAYER_ID,
            source: sourceId,
            'source-layer': 'building',
            type: 'fill-extrusion',
            minzoom: 14.5,
            layout: { visibility: state.is3d ? 'visible' : 'none' },
            paint: {
                'fill-extrusion-color': ['interpolate', ['linear'], height, 0, '#1c2424', 80, '#4f5c5c', 250, '#9fb3b3'],
                'fill-extrusion-height': height,
                'fill-extrusion-base': ['coalesce', ['get', 'render_min_height'], 0],
                'fill-extrusion-opacity': 0.75
            }
        }, labelLayerId);
    }

    /* ---------- Mod 3D ON / OFF (fara recrearea hartii) ---------- */

    function set3DMode(on) {
        var map = state.map;
        if (!map) { return; }
        state.is3d = !!on;

        if (state.is3d) {
            map.setMaxPitch(MAX_PITCH_3D);
        }
        if (map.getLayer(BUILDINGS_LAYER_ID)) {
            map.setLayoutProperty(BUILDINGS_LAYER_ID, 'visibility', state.is3d ? 'visible' : 'none');
        }
        syncVehicleIconRange();
        maybeLoadVehicleModel();

        var view = state.is3d ? VIEW_3D : VIEW_2D;
        map.easeTo({ pitch: view.pitch, bearing: view.bearing, duration: duration(900) });

        if (!state.is3d) {
            // In 2D harta ramane plana si la tragerea cu click dreapta.
            map.once('moveend', function () {
                if (!state.is3d) { map.setMaxPitch(0); }
            });
        }

        dom.toggle3d.setAttribute('aria-pressed', state.is3d ? 'true' : 'false');
        dom.toggle3d.classList.toggle('btn-dark', state.is3d);
        dom.toggle3d.classList.toggle('btn-outline-secondary', !state.is3d);
        dom.toggle3dLabel.textContent = state.is3d ? '3D ON' : '3D OFF';
    }

    /* ---------- Geometrie (izolata: strategia de interpolare se poate inlocui) ---------- */

    function clamp(value, min, max) {
        return value < min ? min : (value > max ? max : value);
    }

    // Interpolare liniara lng/lat: suficient de exacta pe segmente scurte (zeci-sute de metri).
    function interpolatePosition(pointA, pointB, progress) {
        return [
            pointA.lng + (pointB.lng - pointA.lng) * progress,
            pointA.lat + (pointB.lat - pointA.lat) * progress
        ];
    }

    // Azimutul geografic initial A -> B, in grade fata de nordul geografic (0-360, sens orar).
    function calculateBearing(pointA, pointB) {
        var toRad = Math.PI / 180;
        var lat1 = pointA.lat * toRad;
        var lat2 = pointB.lat * toRad;
        var deltaLng = (pointB.lng - pointA.lng) * toRad;
        var y = Math.sin(deltaLng) * Math.cos(lat2);
        var x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(deltaLng);
        return (Math.atan2(y, x) / toRad + 360) % 360;
    }

    /* ---------- Stratul de vehicule (sursa GeoJSON + strat symbol) ---------- */

    function loadVehicleIcon() {
        return new Promise(function (resolve, reject) {
            var size = 48 * VEHICLE_ICON_PIXEL_RATIO;
            var image = new Image(size, size);
            image.onload = function () { resolve(image); };
            image.onerror = function () { reject(new Error('Iconul vehiculului nu s-a putut încărca.')); };
            image.src = dom.root.getAttribute('data-truck-icon');
        });
    }

    function initializeVehicleLayer() {
        var map = state.map;
        return loadVehicleIcon().then(function (image) {
            if (!map.hasImage(VEHICLE_ICON_ID)) {
                map.addImage(VEHICLE_ICON_ID, image, { pixelRatio: VEHICLE_ICON_PIXEL_RATIO });
            }
            if (!map.getSource(VEHICLE_SOURCE_ID)) {
                map.addSource(VEHICLE_SOURCE_ID, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            }
            if (map.getLayer(VEHICLE_LAYER_ID)) { return; }

            // Adaugat ultimul: vehiculele stau deasupra etichetelor si a cladirilor.
            map.addLayer({
                id: VEHICLE_LAYER_ID,
                type: 'symbol',
                source: VEHICLE_SOURCE_ID,
                layout: {
                    'icon-image': VEHICLE_ICON_ID,
                    'icon-size': ['interpolate', ['linear'], ['zoom'], 6, 0.45, 12, 0.7, 16, 0.95, 19, 1.2],
                    // Directia geografica: 0 = nord. Cu alinierea 'map', unghiul se masoara
                    // fata de nordul hartii, deci rotirea hartii roteste si camionul odata
                    // cu strada (directia ramane corecta pe teren).
                    'icon-rotate': ['get', 'heading'],
                    'icon-rotation-alignment': 'map',
                    // Culcat pe sol in 3D, ca vehiculul sa stea pe drum, nu "in picioare".
                    'icon-pitch-alignment': 'map',
                    'icon-allow-overlap': true,
                    'icon-ignore-placement': true
                }
            });

            // Eticheta separat de icon: ramane si cand de aproape camionul e desenat 3D.
            map.addLayer({
                id: VEHICLE_LABEL_LAYER_ID,
                type: 'symbol',
                source: VEHICLE_SOURCE_ID,
                minzoom: 12,
                layout: {
                    'text-field': ['get', 'registration'],
                    'text-font': ['Noto Sans Bold'],
                    'text-size': 11,
                    'text-offset': [0, 2.4],
                    'text-anchor': 'top',
                    'text-optional': true,
                    'text-allow-overlap': true,
                    'text-rotation-alignment': 'viewport',
                    'text-pitch-alignment': 'viewport'
                },
                paint: {
                    'text-color': '#ffffff',
                    'text-halo-color': '#0b0f0f',
                    'text-halo-width': 1.6
                }
            });
        });
    }

    function vehicleFeature(vehicle) {
        return {
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [vehicle.lng, vehicle.lat] },
            properties: {
                id: vehicle.id,
                registration: vehicle.registration,
                status: vehicle.status,
                heading: vehicle.heading
            }
        };
    }

    // Un singur setData pentru toata flota; sursa si stratul nu se recreeaza niciodata.
    function renderVehicles(vehicles) {
        var source = state.map && state.map.getSource(VEHICLE_SOURCE_ID);
        if (!source) { return; }
        vehiclesById = {};
        vehicles.forEach(function (vehicle) { vehiclesById[vehicle.id] = vehicle; });
        source.setData({ type: 'FeatureCollection', features: vehicles.map(vehicleFeature) });
        if (modelsActive()) {
            state.map.triggerRepaint(); // stratul 3D citeste vehiclesById la urmatorul cadru
        }
    }

    function focusVehicle(id) {
        var vehicle = vehiclesById[id];
        if (!vehicle || !state.map) { return; }
        state.map.easeTo({
            center: [vehicle.lng, vehicle.lat],
            zoom: Math.max(state.map.getZoom(), 15.5),
            duration: duration(900)
        });
    }

    /* ---------- Model 3D al vehiculelor (three.js, strat custom MapLibre) ---------- */

    var model = {
        status: 'idle',   // idle | loading | ready | failed
        THREE: null,
        template: null,   // modelul normalizat; fiecare vehicul primeste un clone (geometrie comuna)
        layer: null,
        instances: {}     // id vehicul -> THREE.Group
    };

    function modelsActive() {
        return model.status === 'ready' && state.is3d;
    }

    // Iconul plat dispare exact de unde incepe modelul 3D (si revine in 2D / la zoom mic).
    function syncVehicleIconRange() {
        var map = state.map;
        if (!map || !map.getLayer(VEHICLE_LAYER_ID)) { return; }
        map.setLayerZoomRange(VEHICLE_LAYER_ID, 0, modelsActive() ? MODEL_MIN_ZOOM : 24);
    }

    /*
     * Aduce modelul la: lungime 1 m pe axa "inainte", baza pe sol, centrat, cu Z in sus
     * si fata spre SUD (dupa rotateX, +Z al glTF devine -Y local = sud in cadrul
     * est-nord-sus folosit la desenare). Masurat in Stare tehnica: Y = sus, axa lunga = Z,
     * cabina (jumatatea inalta) spre +Z.
     */
    /*
     * GLB-ul are un singur material "XRay_Blue" transparent (Stare tehnica il coloreaza
     * pe sisteme). Pe harta camionul trebuie sa fie opac: numele pieselor pastreaza rolul
     * materialului original (…_Material, _Grey, _Glass, _Tyre_tread…), dupa care il vopsim.
     * Fisierul GLB nu se modifica.
     */
    var MODEL_PAINT = [
        { match: /tyre/i, color: 0x1b1d1f, roughness: 0.95 },
        { match: /rims|metalic/i, color: 0xc3c9cf, metalness: 0.75, roughness: 0.35 },
        { match: /hubs/i, color: 0x8d949b, metalness: 0.6, roughness: 0.45 },
        { match: /glass/i, color: 0x1c2b38, metalness: 0.3, roughness: 0.15 },
        { match: /red_light/i, color: 0xd42a2a, emissive: 0x7a0f0f },
        { match: /white_light/i, color: 0xf4f6f8, emissive: 0x9aa0a6 },
        { match: /yellow_loght|yellow_light/i, color: 0xf5b700, emissive: 0x6b4f00 },
        { match: /darker_most/i, color: 0x16191c, roughness: 0.8 },
        { match: /back_trailor/i, color: 0x3a3f45, roughness: 0.7 },
        { match: /grey/i, color: 0x8a9199, roughness: 0.6 },
        { match: /dark/i, color: 0x2b3035, roughness: 0.7 },
        { match: /_material$/i, color: 0xf0a42a, metalness: 0.25, roughness: 0.45 } // caroseria
    ];
    var MODEL_PAINT_DEFAULT = { color: 0x2b3035, roughness: 0.7 };

    function paintModel(THREE, scene) {
        var cache = new Map();
        scene.traverse(function (object) {
            if (!object.isMesh) { return; }
            var role = object.name.replace(/_\d+$/, '');
            var paint = MODEL_PAINT.find(function (entry) { return entry.match.test(role); }) || MODEL_PAINT_DEFAULT;
            if (!cache.has(paint)) {
                cache.set(paint, new THREE.MeshStandardMaterial({
                    color: paint.color,
                    emissive: paint.emissive || 0x000000,
                    metalness: paint.metalness || 0.1,
                    roughness: paint.roughness === undefined ? 0.6 : paint.roughness,
                    side: THREE.DoubleSide
                }));
            }
            object.material = cache.get(paint);
        });
    }

    function normalizeModel(THREE, scene) {
        paintModel(THREE, scene);

        var box = new THREE.Box3().setFromObject(scene);
        var size = box.getSize(new THREE.Vector3());
        var center = box.getCenter(new THREE.Vector3());
        var unit = 1 / Math.max(size.z, 0.0001);

        var inner = new THREE.Group();
        scene.position.set(-center.x, -box.min.y, -center.z);
        inner.add(scene);
        inner.scale.setScalar(unit);
        inner.rotation.x = Math.PI / 2;

        var holder = new THREE.Group();
        holder.add(inner);
        return holder;
    }

    function createVehicleModelLayer(THREE) {
        var camera = new THREE.Camera();
        var scene = new THREE.Scene();
        var renderer = null;

        scene.add(new THREE.HemisphereLight(0xffffff, 0x4b5563, 2.2));
        var sun = new THREE.DirectionalLight(0xffffff, 2.4);
        sun.position.set(-0.5, -1, 2).normalize(); // dinspre sud-vest, sus
        scene.add(sun);

        var projection = new THREE.Matrix4();
        var local = new THREE.Matrix4();
        var flip = new THREE.Vector3();

        function syncInstances() {
            var seen = {};
            Object.keys(vehiclesById).forEach(function (id) {
                seen[id] = true;
                if (!model.instances[id]) {
                    var instance = model.template.clone(); // geometria si materialele raman comune
                    model.instances[id] = instance;
                    scene.add(instance);
                }
            });
            Object.keys(model.instances).forEach(function (id) {
                if (!seen[id]) {
                    scene.remove(model.instances[id]);
                    delete model.instances[id];
                }
            });
        }

        return {
            id: VEHICLE_MODEL_LAYER_ID,
            type: 'custom',
            renderingMode: '3d', // impart bufferul de adancime cu cladirile 3D (ocluziune corecta)

            onAdd: function (map, gl) {
                renderer = new THREE.WebGLRenderer({ canvas: map.getCanvas(), context: gl, antialias: true });
                renderer.autoClear = false;
            },

            render: function (gl, args) {
                var map = state.map;
                if (!modelsActive() || map.getZoom() < MODEL_MIN_ZOOM) { return; }
                syncInstances();

                // Originea = centrul hartii; vehiculele se pun in metri fata de ea
                // (cadru est-nord-sus), deci numerele raman mici si precise in float32.
                var center = map.getCenter();
                var origin = maplibregl.MercatorCoordinate.fromLngLat(center, 0);
                var metersToMercator = origin.meterInMercatorCoordinateUnits();
                var worldSize = 512 * Math.pow(2, map.getZoom());
                var metersPerPixel = 1 / (worldSize * metersToMercator);
                var length = Math.max(MODEL_LENGTH_METERS, MODEL_MIN_LENGTH_PX * metersPerPixel);

                Object.keys(model.instances).forEach(function (id) {
                    var vehicle = vehiclesById[id];
                    var instance = model.instances[id];
                    var position = maplibregl.MercatorCoordinate.fromLngLat([vehicle.lng, vehicle.lat], 0);
                    instance.position.set(
                        (position.x - origin.x) / metersToMercator,
                        -(position.y - origin.y) / metersToMercator, // mercator y creste spre sud
                        0
                    );
                    // Fata modelului e spre sud; directia geografica h (din nord, orar)
                    // inseamna o rotatie in sens trigonometric de (180 - h) grade.
                    instance.rotation.z = Math.PI - vehicle.heading * Math.PI / 180;
                    instance.scale.setScalar(length);
                });

                local.makeTranslation(origin.x, origin.y, origin.z)
                    .scale(flip.set(metersToMercator, -metersToMercator, metersToMercator));
                camera.projectionMatrix = projection.fromArray(args.defaultProjectionData.mainMatrix).multiply(local);

                renderer.resetState();
                renderer.render(scene, camera);
            }
        };
    }

    function loadVehicleModel() {
        if (model.status !== 'idle') { return; }
        model.status = 'loading';

        Promise.all([
            import(dom.root.getAttribute('data-three-module')),
            import(dom.root.getAttribute('data-gltf-loader'))
        ]).then(function (modules) {
            var THREE = modules[0];
            return new modules[1].GLTFLoader().loadAsync(dom.root.getAttribute('data-truck-model')).then(function (gltf) {
                model.THREE = THREE;
                model.template = normalizeModel(THREE, gltf.scene);
                model.layer = createVehicleModelLayer(THREE);
                // Sub etichete, ca numarul de inmatriculare sa ramana lizibil.
                state.map.addLayer(model.layer, VEHICLE_LABEL_LAYER_ID);
                model.status = 'ready';
                syncVehicleIconRange();
                state.map.triggerRepaint();
            });
        }).catch(function (error) {
            // Fara model ramane iconul plat la orice zoom: harta functioneaza normal.
            model.status = 'failed';
            console.error('[monitorizare_flota] model 3D', error);
        });
    }

    // Modelul (≈2,5 MB) se descarca doar cand e nevoie de el: 3D ON si zoom apropiat.
    function maybeLoadVehicleModel() {
        if (model.status === 'idle' && state.is3d && state.map.getZoom() >= MODEL_MIN_ZOOM - 1) {
            loadVehicleModel();
        }
    }

    /* ---------- Redare in timp (un singur requestAnimationFrame) ---------- */

    // Ultimul esantion cu t <= time (cautare binara), limitat la un segment valid.
    function findSegmentIndex(samples, time) {
        var low = 0;
        var high = samples.length - 1;
        while (low < high) {
            var mid = (low + high + 1) >> 1;
            if (samples[mid].t <= time) { low = mid; } else { high = mid - 1; }
        }
        return Math.min(low, Math.max(0, samples.length - 2));
    }

    function prepareTrack(track) {
        var samples = track.samples.slice().sort(function (a, b) { return a.t - b.t; });
        // Directia per segment. Un segment fara deplasare (vehicul oprit) nu are directie
        // proprie: o preia pe cea anterioara (sau, la inceput, pe prima directie reala).
        var headings = [];
        for (var i = 0; i < samples.length - 1; i++) {
            var a = samples[i];
            var b = samples[i + 1];
            headings.push(a.lng !== b.lng || a.lat !== b.lat ? calculateBearing(a, b) : null);
        }
        var known = headings.find(function (heading) { return heading !== null; });
        var previous = known === undefined ? 0 : known;
        headings = headings.map(function (heading) {
            previous = heading === null ? previous : heading;
            return previous;
        });
        if (headings.length === 0) { headings.push(0); }
        return { vehicle: track.vehicle, samples: samples, headings: headings };
    }

    function vehicleAt(track, time) {
        var samples = track.samples;
        var index = findSegmentIndex(samples, time);
        var pointA = samples[index];
        var pointB = samples[Math.min(index + 1, samples.length - 1)];
        var span = pointB.t - pointA.t;
        var progress = span > 0 ? clamp((time - pointA.t) / span, 0, 1) : 1;
        var position = interpolatePosition(pointA, pointB, progress);

        return {
            id: track.vehicle.id,
            registration: track.vehicle.registration,
            status: track.vehicle.status,
            lng: position[0],
            lat: position[1],
            heading: track.headings[index],
            timestamp: time
        };
    }

    function updateVehicleFrame() {
        renderVehicles(playback.tracks.map(function (track) {
            return vehicleAt(track, playback.time);
        }));
    }

    function playbackState() {
        return {
            running: playback.running,
            atStart: playback.time <= playback.startTime,
            finished: playback.tracks.length > 0 && playback.time >= playback.endTime,
            time: playback.time,
            startTime: playback.startTime,
            endTime: playback.endTime
        };
    }

    function notifyPlayback() {
        var snapshot = playbackState();
        playback.listeners.forEach(function (listener) { listener(snapshot); });
    }

    function playbackFrame(now) {
        playback.rafId = 0;
        if (!playback.running) { return; }

        // Timp real scurs intre cadre (ms), nu numar de cadre: viteza nu depinde de FPS.
        // Primul cadru dupa Start/Continua are delta 0, deci nu exista salt la reluare.
        if (playback.lastFrame !== null) {
            playback.time = Math.min(playback.endTime, playback.time + (now - playback.lastFrame) * playback.rate);
        }
        playback.lastFrame = now;
        updateVehicleFrame();

        if (playback.time >= playback.endTime) {
            playback.running = false;
            notifyPlayback();
            return;
        }
        playback.rafId = window.requestAnimationFrame(playbackFrame);
    }

    function startVehiclePlayback() {
        if (playback.running || playback.tracks.length === 0) { return; }
        if (playback.time >= playback.endTime) {
            playback.time = playback.startTime; // redare terminata: Start o ia de la capat
        }
        playback.running = true;
        playback.lastFrame = null;
        playback.rafId = window.requestAnimationFrame(playbackFrame);
        notifyPlayback();
    }

    function stopVehiclePlayback() {
        if (playback.rafId) {
            window.cancelAnimationFrame(playback.rafId);
            playback.rafId = 0;
        }
        var wasRunning = playback.running;
        playback.running = false;
        playback.lastFrame = null;
        return wasRunning;
    }

    function pauseVehiclePlayback() {
        if (stopVehiclePlayback()) {
            notifyPlayback();
        }
    }

    function resetVehiclePlayback() {
        stopVehiclePlayback();
        playback.time = playback.startTime;
        updateVehicleFrame();
        notifyPlayback();
    }

    /**
     * tracks: [{vehicle: {id, registration, status}, samples: [{lng, lat, t}]}]
     * options.rate: viteza redarii fata de timpul real (1 = timp real).
     */
    function setVehicleTracks(tracks, options) {
        stopVehiclePlayback();
        playback.tracks = (tracks || []).filter(function (track) {
            return track && track.vehicle && Array.isArray(track.samples) && track.samples.length > 0;
        }).map(prepareTrack);
        playback.rate = (options && options.rate > 0) ? options.rate : 1;
        playback.startTime = Infinity;
        playback.endTime = -Infinity;
        playback.tracks.forEach(function (track) {
            playback.startTime = Math.min(playback.startTime, track.samples[0].t);
            playback.endTime = Math.max(playback.endTime, track.samples[track.samples.length - 1].t);
        });
        if (playback.tracks.length === 0) {
            playback.startTime = playback.endTime = 0;
        }
        playback.time = playback.startTime;
        updateVehicleFrame();
        notifyPlayback();
    }

    /* ---------- Comenzi ---------- */

    function initializeMapControls() {
        var map = state.map;

        dom.toggle3d.addEventListener('click', function () { set3DMode(!state.is3d); });
        dom.reset.addEventListener('click', resetMapView);

        var actions = {
            'zoom-in': function () { map.zoomIn({ duration: duration(300) }); },
            'zoom-out': function () { map.zoomOut({ duration: duration(300) }); },
            'rotate-left': function () { map.rotateTo(map.getBearing() - ROTATE_STEP, { duration: duration(350) }); },
            'rotate-right': function () { map.rotateTo(map.getBearing() + ROTATE_STEP, { duration: duration(350) }); }
        };
        dom.root.querySelectorAll('[data-camera]').forEach(function (button) {
            var action = actions[button.getAttribute('data-camera')];
            if (action) {
                button.addEventListener('click', action);
            }
        });

        // Butoanele demo le activeaza fisierul demo, dupa ce are date.
        dom.root.querySelectorAll('button[disabled]:not([data-demo])').forEach(function (button) {
            button.disabled = false;
        });
    }

    /* ---------- Initializare ---------- */

    function initializeMap() {
        if (!window.maplibregl) {
            setStatus('Biblioteca hărții (MapLibre) nu s-a putut încărca. Verifică conexiunea și reîncarcă pagina.', true);
            return;
        }

        var map;
        try {
            map = new maplibregl.Map({
                container: dom.mapContainer,
                style: STYLE_URL,
                center: ROMANIA_CENTER,
                zoom: 6.3,
                pitch: VIEW_3D.pitch,
                bearing: VIEW_3D.bearing,
                maxPitch: MAX_PITCH_3D,
                canvasContextAttributes: { antialias: true },
                attributionControl: { compact: true }
            });
        } catch (error) {
            // De obicei WebGL indisponibil (driver / accelerare hardware oprita).
            console.error('[monitorizare_flota]', error);
            setStatus('Harta 3D nu poate porni în acest browser (WebGL indisponibil).', true);
            return;
        }
        state.map = map;
        window.FleetMonitoringMap = map; // punct de acces pentru straturile viitoare / depanare

        // Camera de ansamblu pe dimensiunea reala a containerului (inainte de primul cadru).
        map.jumpTo(overviewCamera());

        map.on('load', function () {
            state.loaded = true;
            try {
                add3DBuildings();
            } catch (error) {
                console.error('[monitorizare_flota] cladiri 3D', error);
            }
            initializeMapControls();
            setStatus(null);

            initializeVehicleLayer().then(function () {
                map.on('zoomend', maybeLoadVehicleModel);
                maybeLoadVehicleModel();
                publishApi(true);
            }, function (error) {
                console.error('[monitorizare_flota] strat vehicule', error);
                publishApi(false);
            });
        });

        map.on('error', function (event) {
            // Erorile de tile izolate sunt normale; doar esecul stilului blocheaza harta.
            if (!state.loaded) {
                console.error('[monitorizare_flota]', event && event.error);
                setStatus('Harta nu s-a putut încărca de la OpenFreeMap. Reîncarcă pagina.', true);
            }
        });
    }

    /*
     * API pentru sursa de date a vehiculelor (acum: fisierul demo; ulterior: datele
     * SAS venite din backend). Sursa de date nu atinge direct MapLibre.
     */
    function publishApi(vehiclesAvailable) {
        window.FleetMonitoring = {
            map: state.map,
            vehiclesAvailable: vehiclesAvailable,
            vehicleLayerId: VEHICLE_LAYER_ID,
            focusVehicle: focusVehicle,
            playback: {
                setTracks: setVehicleTracks,
                start: startVehiclePlayback,
                pause: pauseVehiclePlayback,
                reset: resetVehiclePlayback,
                getState: playbackState,
                onChange: function (listener) { playback.listeners.push(listener); }
            }
        };
        dom.root.setAttribute('data-map-ready', '1');
        dom.root.dispatchEvent(new CustomEvent('fleet-monitoring:ready'));
    }

    function boot() {
        dom.root = document.querySelector('.fleet-monitoring');
        if (!dom.root || dom.root.getAttribute('data-map-initialized') === '1') { return; }
        dom.root.setAttribute('data-map-initialized', '1');

        dom.stage = document.getElementById('fleet-monitoring-stage');
        dom.mapContainer = document.getElementById('fleet-monitoring-map');
        dom.status = document.getElementById('fleet-monitoring-status');
        dom.toggle3d = document.getElementById('fleet-monitoring-3d');
        dom.toggle3dLabel = dom.toggle3d.querySelector('[data-label]');
        dom.reset = document.getElementById('fleet-monitoring-reset');

        fitStageHeight();
        initializeResizeHandling();
        initializeMap();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
