/*
 * Ilustratia X-ray (vedere de sus, fata la stanga) a exploratorului de componente din Stoc Anvelope.
 * Doar desenul: sabloane pentru cap tractor, camion, semiremorca si autoturism, construite din
 * geometria reala a vehiculului (window.FleetVehicleGeometry: axe, roti duble, ansambluri prin
 * partLayout). Logica de selectie / panouri e in vehicle-xray.js.
 *
 * Straturi: carcase roti -> silueta cu stralucire (albastru = vehicul cu motor, violet =
 * semiremorca) -> sasiu metalic -> ansambluri mecanice (pe categorii) -> axe -> anvelope ->
 * etichete cu linii de legatura. Punctele componentelor (HTML) se aseaza peste, cu project().
 */
(function () {
    'use strict';

    var Geometry = window.FleetVehicleGeometry;
    if (!Geometry) { return; }
    var NS = 'http://www.w3.org/2000/svg';
    var PX = Geometry.PX;
    var VIEW_W = 1700;
    var LABEL_BAND = 62; // spatiul etichetelor de deasupra / de sub vehicul
    var PILL_BAND = 54; // randul cu numele vehiculelor (cap tractor / semiremorca)
    var MAX_SCALE = 5.0;
    var STATUS_RING = { ok: '#22c55e', warn: '#f59e0b', crit: '#ef4444' };

    var el = function (name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { if (attrs[key] !== undefined && attrs[key] !== null) { node.setAttribute(key, attrs[key]); } });
        if (parent) { parent.appendChild(node); }
        return node;
    };
    var f1 = function (value) { return (Math.round(value * 10) / 10).toString(); };

    /* ------------------------------------------------------------ gradienti si filtre comune */

    var defsReady = false;
    var ensureDefs = function (categories) {
        if (defsReady) { return; }
        defsReady = true;
        var holder = el('svg', { width: 0, height: 0, 'aria-hidden': 'true', style: 'position:absolute;width:0;height:0;overflow:hidden' });
        var defs = el('defs', {}, holder);
        var linear = function (id, stops, vertical) {
            var g = el('linearGradient', { id: id, x1: 0, y1: 0, x2: vertical ? 0 : 1, y2: vertical ? 1 : 0 }, defs);
            stops.forEach(function (stop) { el('stop', { offset: stop[0], 'stop-color': stop[1], 'stop-opacity': stop[2] === undefined ? 1 : stop[2] }, g); });
        };
        var radial = function (id, stops) {
            var g = el('radialGradient', { id: id, cx: '50%', cy: '45%', r: '65%' }, defs);
            stops.forEach(function (stop) { el('stop', { offset: stop[0], 'stop-color': stop[1], 'stop-opacity': stop[2] === undefined ? 1 : stop[2] }, g); });
        };
        linear('fxRail', [[0, '#e2e8f0'], [0.35, '#94a3b8'], [0.55, '#64748b'], [1, '#cbd5e1']], true);
        linear('fxRailX', [[0, '#e2e8f0'], [0.4, '#94a3b8'], [0.6, '#6b7a90'], [1, '#d5dde8']], false);
        linear('fxMetal', [[0, '#f1f5f9'], [0.45, '#b8c3d2'], [1, '#e2e8f0']], true);
        linear('fxMetalDark', [[0, '#9aa7b9'], [0.5, '#4b5869'], [1, '#9aa7b9']], true);
        linear('fxCylinder', [[0, '#f8fafc'], [0.3, '#d6dee9'], [0.65, '#9fadc0'], [1, '#e7edf4']], true);
        linear('fxTire', [[0, '#4a5568'], [0.18, '#252d3a'], [0.5, '#10151d'], [0.82, '#252d3a'], [1, '#4a5568']], true);
        linear('fxTireX', [[0, '#4a5568'], [0.18, '#252d3a'], [0.5, '#10151d'], [0.82, '#252d3a'], [1, '#4a5568']], false);
        linear('fxGlass', [[0, '#64748b', 0.75], [1, '#1e293b', 0.85]], false);
        linear('fxPod', [[0, '#ffffff', 0.92], [1, '#e2e8f0', 0.7]], true);
        radial('fxCab', [[0, '#ffffff', 0.85], [1, '#cfe0fb', 0.55]]);
        linear('fxBody', [[0, '#eef5ff', 0.75], [1, '#d6e6fd', 0.5]], true);
        linear('fxTrailer', [[0, '#f6f2ff', 0.8], [1, '#e7defe', 0.55]], true);
        linear('fxEngine', [[0, '#eef2f7'], [0.5, '#c3ccd8'], [1, '#e3e8ef']], true);
        if (window.FleetXrayEngine) { defs.insertAdjacentHTML('beforeend', window.FleetXrayEngine.defs()); }
        (categories || []).forEach(function (category) {
            linear('fxAcc-' + category.key, [[0, '#ffffff'], [0.35, category.color, 0.55], [1, category.color]], true);
        });
        var glow = function (id, color, std, opacity) {
            var filter = el('filter', { id: id, x: '-20%', y: '-40%', width: '140%', height: '180%' }, defs);
            el('feDropShadow', { dx: 0, dy: 0, stdDeviation: std, 'flood-color': color, 'flood-opacity': opacity }, filter);
            el('feDropShadow', { dx: 0, dy: 0, stdDeviation: std / 3, 'flood-color': color, 'flood-opacity': opacity }, filter);
        };
        glow('fxGlowBlue', '#3b82f6', 7, 0.75);
        glow('fxGlowViolet', '#8b5cf6', 7, 0.75);
        var soft = el('filter', { id: 'fxSoft', x: '-20%', y: '-20%', width: '140%', height: '160%' }, defs);
        el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.8, 'flood-color': '#0f172a', 'flood-opacity': 0.28 }, soft);
        var partGlow = el('filter', { id: 'fxPartGlow', x: '-30%', y: '-30%', width: '160%', height: '160%' }, defs);
        el('feGaussianBlur', { in: 'SourceGraphic', stdDeviation: 3, result: 'b' }, partGlow);
        var merge = el('feMerge', {}, partGlow);
        el('feMergeNode', { in: 'b' }, merge);
        el('feMergeNode', { in: 'SourceGraphic' }, merge);
        document.body.appendChild(holder);
    };

    /* ------------------------------------------------------------ desen */

    var draw = function (host, unit) {
        var layout = Geometry.layoutUnit(unit);
        // Ansamblul se deseneaza „desfacut”, ca in macheta: semiremorca incepe imediat in spatele
        // capului tractor (conturul ei atinge conturul capului), nu peste sasiul lui, ca saua,
        // diferentialul si rezervoarele capului sa ramana vizibile. Cuplajul ramane aratat prin
        // contururile lipite; schema randului pastreaza suprapunerea reala (pivotul pe sa).
        // Semiremorca: puntea goala dintre pivot si grupul de axe se comprima (ca in macheta);
        // pivotul, picioarele de sprijin si distantele dintre axe raman reale.
        layout.members.forEach(function (member) {
            var geo = member.geo;
            if (geo.type !== 'semiremorca' || !geo.axles.length) { return; }
            var span = geo.axles[geo.axles.length - 1].x - geo.axles[0].x;
            var compact = Math.max(9.2, span + 6.4);
            if (compact >= geo.length) { return; }
            var shiftBy = compact - geo.length;
            geo.axles.forEach(function (axle) { axle.x += shiftBy; });
            geo.length = compact;
        });
        if (layout.members.length > 1) {
            var lead = layout.members[0];
            layout.members[1].offset = lead.offset + lead.geo.length + 0.06;
        }
        layout.totalLength = layout.members.reduce(function (max, member) { return Math.max(max, member.offset + member.geo.length); }, 0);
        var outerMax = layout.halfWidth + 0.38; // oglinzi / carcase roti
        var scale = Math.min(MAX_SCALE, (VIEW_W - 70) / ((layout.totalLength + 0.5) * PX));
        var bodyH = 2 * outerMax * PX * scale;
        var viewH = Math.round(bodyH + 2 * LABEL_BAND + PILL_BAND);
        var cy = LABEL_BAND + bodyH / 2;
        var originX = (VIEW_W - (layout.totalLength + 0.3) * PX * scale) / 2 + 0.3 * PX * scale;
        var X = function (m) { return originX + m * PX * scale; };
        var Y = function (m) { return cy + m * PX * scale; };
        var S = function (m) { return m * PX * scale; };

        var svg = el('svg', { viewBox: '0 0 ' + VIEW_W + ' ' + viewH, class: 'fx-svg', role: 'group', 'aria-label': 'Vedere de sus a vehiculului, fața în stânga' });
        var layers = {};
        // „engine”: desenul motorului sta peste grinda axei fata (vazut de sus, motorul e deasupra ei);
        // zona lui de click ramane in „parts”, sub axe, deci click-urile se impart ca inainte.
        ['pods', 'shell', 'frame', 'parts', 'axles', 'engine', 'tires', 'labels'].forEach(function (name) { layers[name] = el('g', { class: 'fx-l-' + name }, svg); });

        var rect = function (parent, x0, y0, x1, y1, attrs) {
            var r = el('rect', { x: f1(X(Math.min(x0, x1))), y: f1(Y(Math.min(y0, y1))), width: f1(S(Math.abs(x1 - x0))), height: f1(S(Math.abs(y1 - y0))) }, parent);
            Object.keys(attrs || {}).forEach(function (key) { r.setAttribute(key, key === 'rx' ? f1(S(attrs[key])) : attrs[key]); });
            return r;
        };
        var circle = function (parent, x, y, r, attrs) {
            var c = el('circle', { cx: f1(X(x)), cy: f1(Y(y)), r: f1(S(r)) }, parent);
            Object.keys(attrs || {}).forEach(function (key) { c.setAttribute(key, attrs[key]); });
            return c;
        };
        var line = function (parent, x0, y0, x1, y1, attrs) {
            return el('line', Object.assign({ x1: f1(X(x0)), y1: f1(Y(y0)), x2: f1(X(x1)), y2: f1(Y(y1)) }, attrs || {}), parent);
        };
        var path = function (parent, d, attrs) { return el('path', Object.assign({ d: d }, attrs || {}), parent); };
        var P = function (x, y) { return f1(X(x)) + ' ' + f1(Y(y)); };
        // Ansamblu mecanic: grup pe o categorie; se coloreaza cand categoria e activa.
        var part = function (category, memberId, box) {
            var g = el('g', { class: 'fx-part', 'data-fx-cat': category, 'data-fx-member': String(memberId) }, layers.parts);
            g.style.setProperty('--fx-acc-fill', 'url(#fxAcc-' + category + ')');
            if (box) {
                g.dataset.box = [X(box.x0), Y(box.y0), X(box.x1), Y(box.y1)].map(f1).join(',');
            }
            return g;
        };
        // Cilindru (rezervoare): corp cu gradient, capace rotunjite, linii de prindere.
        var cylinder = function (parent, box, cls) {
            rect(parent, box.x0, box.y0, box.x1, box.y1, { class: 'fx-p-fill ' + (cls || ''), fill: 'url(#fxCylinder)', rx: Math.min(0.2, (box.y1 - box.y0) / 2) });
            [0.22, 0.78].forEach(function (t) {
                var x = box.x0 + (box.x1 - box.x0) * t;
                line(parent, x, box.y0, x, box.y1, { class: 'fx-p-line' });
            });
        };

        layout.members.forEach(function (member) {
            var geo = member.geo;
            var o = member.offset;
            var id = member.data.vehicle_id;
            var hw = geo.halfWidth;
            var trailer = geo.type === 'semiremorca';
            var car = Geometry.isCarType(geo.type);
            var tone = trailer ? 'violet' : 'blue';
            var parts = Geometry.partLayout(geo);
            var shift = function (box) {
                if (!box) { return null; }
                return box.cx !== undefined ? { cx: box.cx + o, cy: box.cy, r: box.r } : { x0: box.x0 + o, x1: box.x1 + o, y0: box.y0, y1: box.y1 };
            };
            var placed = Geometry.tireRects(geo);
            var outer = placed.outer;
            var hasSlot = function (category) { return (member.data.slots || []).some(function (slot) { return slot.category === category; }); };

            // 1. Carcasele rotilor (aripile vazute de sus), sub silueta.
            geo.axles.forEach(function (axle) {
                var depth = axle.dual ? 0.78 : (car ? 0.4 : 0.52);
                [-1, 1].forEach(function (sign) {
                    var y0 = sign * (outer + 0.12);
                    var y1 = sign * (outer - depth);
                    rect(layers.pods, o + axle.x - (car ? 0.46 : 0.72), y0, o + axle.x + (car ? 0.46 : 0.72), y1, { class: 'fx-pod', fill: 'url(#fxPod)', rx: car ? 0.16 : 0.24, filter: 'url(#fxSoft)' });
                });
            });

            // 2. Silueta cu stralucire.
            var shell = el('g', { class: 'fx-shell fx-shell-' + tone }, layers.shell);
            if (trailer) {
                path(shell, 'M' + P(o + 0.25, -hw) + ' L' + P(o + geo.length - 0.12, -hw) + ' Q' + P(o + geo.length, -hw) + ' ' + P(o + geo.length, -hw + 0.12)
                    + ' L' + P(o + geo.length, hw - 0.12) + ' Q' + P(o + geo.length, hw) + ' ' + P(o + geo.length - 0.12, hw) + ' L' + P(o + 0.25, hw)
                    + ' Q' + P(o, hw) + ' ' + P(o, hw - 0.25) + ' L' + P(o, -hw + 0.25) + ' Q' + P(o, -hw) + ' ' + P(o + 0.25, -hw) + ' Z',
                    { class: 'fx-outline', fill: 'url(#fxTrailer)', filter: 'url(#fxGlowViolet)' });
            } else if (car) {
                path(shell, 'M' + P(o + 0.55, -hw) + ' L' + P(o + geo.length - 0.45, -hw) + ' Q' + P(o + geo.length, -hw) + ' ' + P(o + geo.length, -hw + 0.45)
                    + ' L' + P(o + geo.length, hw - 0.45) + ' Q' + P(o + geo.length, hw) + ' ' + P(o + geo.length - 0.45, hw) + ' L' + P(o + 0.55, hw)
                    + ' Q' + P(o, hw) + ' ' + P(o, hw - 0.55) + ' L' + P(o, -hw + 0.55) + ' Q' + P(o, -hw) + ' ' + P(o + 0.55, -hw) + ' Z',
                    { class: 'fx-outline', fill: 'url(#fxBody)', filter: 'url(#fxGlowBlue)' });
            } else {
                var cab = 2.3;
                var rear = o + geo.length;
                var bodyHw = geo.type === 'camion' ? hw : hw - 0.16;
                // Cabina (nas rotunjit) + sasiul / caroseria din spate, intr-un singur contur.
                path(shell, 'M' + P(o + 0.42, -hw) + ' L' + P(o + cab - 0.12, -hw) + ' Q' + P(o + cab, -hw) + ' ' + P(o + cab + 0.04, -bodyHw)
                    + ' L' + P(rear - 0.22, -bodyHw) + ' Q' + P(rear, -bodyHw) + ' ' + P(rear, -bodyHw + 0.22)
                    + ' L' + P(rear, bodyHw - 0.22) + ' Q' + P(rear, bodyHw) + ' ' + P(rear - 0.22, bodyHw)
                    + ' L' + P(o + cab + 0.04, bodyHw) + ' Q' + P(o + cab, hw) + ' ' + P(o + cab - 0.12, hw)
                    + ' L' + P(o + 0.42, hw) + ' Q' + P(o, hw) + ' ' + P(o, hw - 0.42) + ' L' + P(o, -hw + 0.42) + ' Q' + P(o, -hw) + ' ' + P(o + 0.42, -hw) + ' Z',
                    { class: 'fx-outline', fill: 'url(#fxBody)', filter: 'url(#fxGlowBlue)' });
                // Oglinzile.
                [-1, 1].forEach(function (sign) {
                    path(shell, 'M' + P(o + 0.62, sign * (hw - 0.02)) + ' Q' + P(o + 0.56, sign * (hw + 0.2)) + ' ' + P(o + 0.36, sign * (hw + 0.2)), { class: 'fx-mirror-arm' });
                    rect(shell, o + 0.12, sign * (hw + 0.08), o + 0.38, sign * (hw + 0.32), { class: 'fx-mirror', rx: 0.08 });
                });
                // Interiorul cabinei: parbriz, bord, scaune, cuseta.
                var cabin = el('g', { class: 'fx-cabin' }, shell);
                rect(cabin, o + 0.06, -hw + 0.12, o + cab - 0.05, hw - 0.12, { class: 'fx-cab-fill', fill: 'url(#fxCab)', rx: 0.36 });
                path(cabin, 'M' + P(o + 0.34, -hw + 0.16) + ' Q' + P(o + 0.1, 0) + ' ' + P(o + 0.34, hw - 0.16) + ' L' + P(o + 0.5, hw - 0.2) + ' Q' + P(o + 0.3, 0) + ' ' + P(o + 0.5, -hw + 0.2) + ' Z', { class: 'fx-glass', fill: 'url(#fxGlass)' });
                rect(cabin, o + 0.55, -hw + 0.22, o + 0.72, hw - 0.22, { class: 'fx-dash', rx: 0.05 });
                [-0.58, 0.58].forEach(function (y) { rect(cabin, o + 0.8, y - 0.26, o + 1.22, y + 0.26, { class: 'fx-seat', rx: 0.1 }); });
                rect(cabin, o + 1.5, -hw + 0.22, o + cab - 0.15, hw - 0.22, { class: 'fx-bunk', rx: 0.14 });
                if (geo.type === 'camion') {
                    rect(shell, o + cab + 0.12, -hw + 0.06, o + geo.length - 0.08, hw - 0.06, { class: 'fx-cargo', rx: 0.12 });
                }
            }

            // 3. Sasiul: lonjeroane metalice, traverse, buloane.
            var frame = el('g', { class: 'fx-frame' }, layers.frame);
            if (trailer) {
                [-0.5, 0.5].forEach(function (y) { rect(frame, o + 0.35, y - 0.08, o + geo.length - 0.2, y + 0.08, { class: 'fx-rail', fill: 'url(#fxRail)' }); });
                [-1, 1].forEach(function (sign) { rect(frame, o + 0.2, sign * (hw - 0.1), o + geo.length - 0.12, sign * (hw - 0.04), { class: 'fx-rail-thin', fill: 'url(#fxMetal)' }); });
                for (var tx = o + 0.75; tx < o + geo.length - 0.3; tx += 0.78) {
                    rect(frame, tx - 0.04, -hw + 0.12, tx + 0.04, hw - 0.12, { class: 'fx-cross', fill: 'url(#fxRailX)' });
                }
                var plate = shift(parts.kingpin_plate);
                rect(frame, plate.x0, plate.y0, plate.x1, plate.y1, { class: 'fx-plate', fill: 'url(#fxPod)', rx: 0.12 });
                var legs = shift(parts.landing_gear);
                [-1, 1].forEach(function (sign) {
                    rect(frame, legs.x0, sign * 0.72, legs.x1, sign * (hw - 0.18), { class: 'fx-leg', fill: 'url(#fxMetal)', rx: 0.04 });
                });
                line(frame, legs.x0 + 0.15, -0.72, legs.x0 + 0.15, 0.72, { class: 'fx-p-line' });
                var box = shift(parts.toolbox);
                rect(frame, box.x0, box.y0, box.x1, box.y1, { class: 'fx-box', fill: 'url(#fxCylinder)', rx: 0.06 });
            } else if (!car) {
                var railStart = o + 1.85;
                [-0.48, 0.48].forEach(function (y) { rect(frame, railStart, y - 0.08, o + geo.length - 0.04, y + 0.08, { class: 'fx-rail', fill: 'url(#fxRail)' }); });
                for (var cx = railStart + 0.5; cx < o + geo.length - 0.2; cx += 0.72) {
                    rect(frame, cx - 0.035, -0.4, cx + 0.035, 0.4, { class: 'fx-cross', fill: 'url(#fxRailX)' });
                    [-0.48, 0.48].forEach(function (y) { circle(frame, cx, y, 0.025, { class: 'fx-bolt' }); });
                }
                rect(frame, o + geo.length - 0.1, -bodyHwOf(geo), o + geo.length - 0.02, bodyHwOf(geo), { class: 'fx-cross', fill: 'url(#fxRailX)' });
            }

            // 4. Ansamblurile mecanice (pe categorii), in locurile din partLayout.
            if (!trailer) {
                var radiator = shift(parts.radiator);
                if (radiator && hasSlot('racire')) {
                    var rad = part('racire', id, radiator);
                    rect(rad, radiator.x0, radiator.y0, radiator.x1, radiator.y1, { class: 'fx-p-fill', fill: 'url(#fxMetal)', rx: 0.04 });
                    for (var ry = radiator.y0 + 0.1; ry < radiator.y1 - 0.05; ry += 0.1) { line(rad, radiator.x0 + 0.02, ry, radiator.x1 - 0.02, ry, { class: 'fx-p-line' }); }
                }
                var engine = shift(parts.engine);
                if (engine && hasSlot('motor')) {
                    // Separat: zona de click (in „parts”, cu caseta pentru marire) si desenul (in „engine”,
                    // fara evenimente): halo-ul colorat cand categoria e activa (singurul fx-p-fill) +
                    // desenul mecanic din vehicle-xray-engine.js.
                    var engHit = part('motor', id, engine);
                    rect(engHit, engine.x0, engine.y0, engine.x1, engine.y1, { class: 'fx-part-hit', fill: 'transparent' });
                    var eng = el('g', { class: 'fx-part fx-part-art', 'data-fx-cat': 'motor', 'data-fx-member': String(id) }, layers.engine);
                    eng.style.setProperty('--fx-acc-fill', 'url(#fxAcc-motor)');
                    rect(eng, engine.x0 - 0.05, engine.y0 - 0.05, engine.x1 + 0.03, engine.y1 + 0.05, { class: 'fx-p-fill fx-eng-halo', rx: 0.12 });
                    if (window.FleetXrayEngine) {
                        var engArt = el('g', { class: 'fx-eng-art', transform: 'translate(' + f1(X(engine.x0)) + ' ' + f1(Y(engine.y0)) + ') scale(' + (S(0.01)).toFixed(4) + ')' }, eng);
                        engArt.innerHTML = window.FleetXrayEngine.engine({
                            length: (engine.x1 - engine.x0) * 100,
                            width: (engine.y1 - engine.y0) * 100,
                            cylinders: car ? 4 : 6,
                            aircomp: !car,
                            outputHalf: parts.gearbox ? (parts.gearbox.y1 - parts.gearbox.y0) * 50 : undefined
                        });
                    } else {
                        rect(eng, engine.x0, engine.y0, engine.x1, engine.y1, { fill: 'url(#fxEngine)', stroke: '#7d8da3', rx: 0.12 });
                    }
                }
                var gearbox = shift(parts.gearbox);
                if (gearbox && hasSlot('transmisie')) {
                    var gb = part('transmisie', id, gearbox);
                    path(gb, 'M' + P(gearbox.x0, gearbox.y0) + ' L' + P(gearbox.x1, gearbox.y0 + 0.1) + ' L' + P(gearbox.x1, gearbox.y1 - 0.1) + ' L' + P(gearbox.x0, gearbox.y1) + ' Z', { class: 'fx-p-fill', fill: 'url(#fxEngine)' });
                    for (var gx = gearbox.x0 + 0.15; gx < gearbox.x1 - 0.05; gx += 0.16) { line(gb, gx, gearbox.y0 + 0.06, gx, gearbox.y1 - 0.06, { class: 'fx-p-line' }); }
                }
                var shaft = shift(parts.driveshaft);
                if (shaft) {
                    var sh = part(hasSlot('transmisie') ? 'transmisie' : 'motor', id, shaft);
                    rect(sh, shaft.x0, shaft.y0, shaft.x1, shaft.y1, { class: 'fx-p-fill', fill: 'url(#fxRail)', rx: 0.03 });
                    [shaft.x0 + 0.05, shaft.x1 - 0.05].forEach(function (jx) { rect(sh, jx - 0.06, -0.12, jx + 0.06, 0.12, { class: 'fx-p-fill', fill: 'url(#fxMetalDark)', rx: 0.03 }); });
                }
                var steering = shift(parts.steering);
                if (steering && hasSlot('directie')) {
                    var st = part('directie', id, steering);
                    rect(st, steering.x0, steering.y0, steering.x1, steering.y1, { class: 'fx-p-fill', fill: 'url(#fxMetal)', rx: 0.07 });
                    var steerAxle = geo.axles.filter(function (axle) { return axle.type === 'steering'; })[0] || geo.axles[0];
                    if (steerAxle) {
                        line(st, steering.x0, (steering.y0 + steering.y1) / 2, o + steerAxle.x - 0.3, outer - 0.55, { class: 'fx-p-rod' });
                        line(st, o + steerAxle.x - 0.3, -outer + 0.55, o + steerAxle.x - 0.3, outer - 0.55, { class: 'fx-p-rod' });
                    }
                }
                [['exhaust', 'evacuare'], ['fuel_tank', 'rezervor_combustibil'], ['air_tanks', 'pneumatica'], ['hydraulic_tank', 'hidraulic']].forEach(function (pair) {
                    var b = shift(parts[pair[0]]);
                    if (b && hasSlot(pair[1])) { cylinder(part(pair[1], id, b), b); }
                });
                var battery = shift(parts.battery);
                if (battery && hasSlot('electrica')) {
                    var bt = part('electrica', id, battery);
                    rect(bt, battery.x0, battery.y0, battery.x1, battery.y1, { class: 'fx-p-fill', fill: 'url(#fxMetal)', rx: 0.04 });
                    [0.3, 0.7].forEach(function (t) { circle(bt, battery.x0 + (battery.x1 - battery.x0) * t, (battery.y0 + battery.y1) / 2, 0.05, { class: 'fx-p-bolt' }); });
                }
                if (geo.type === 'cap_tractor' && parts.fifth_wheel) {
                    var fw = shift(parts.fifth_wheel);
                    var sa = part('cupla_sa', id, { x0: fw.cx - fw.r, x1: fw.cx + fw.r, y0: -fw.r, y1: fw.r });
                    circle(sa, fw.cx, 0, fw.r, { class: 'fx-p-fill', fill: 'url(#fxMetal)' });
                    circle(sa, fw.cx, 0, fw.r * 0.62, { class: 'fx-p-sub' });
                    for (var a = 0; a < 8; a++) {
                        var ang = a * Math.PI / 4;
                        line(sa, fw.cx + Math.cos(ang) * fw.r * 0.62, Math.sin(ang) * fw.r * 0.62, fw.cx + Math.cos(ang) * fw.r * 0.95, Math.sin(ang) * fw.r * 0.95, { class: 'fx-p-line' });
                    }
                    path(sa, 'M' + P(fw.cx, -0.12) + ' L' + P(fw.cx + fw.r, -0.26) + ' L' + P(fw.cx + fw.r, 0.26) + ' L' + P(fw.cx, 0.12) + ' Z', { class: 'fx-p-slot' });
                    if (hasSlot('blocare')) {
                        var lk = shift(parts.fifth_wheel_lock);
                        rect(part('blocare', id, lk), lk.x0, lk.y0, lk.x1, lk.y1, { class: 'fx-p-fill', fill: 'url(#fxMetalDark)', rx: 0.05 });
                    }
                }
            } else {
                var kp = part('kingpin', id, { x0: o + geo.kingpin - 0.3, x1: o + geo.kingpin + 0.3, y0: -0.3, y1: 0.3 });
                circle(kp, o + geo.kingpin, 0, 0.16, { class: 'fx-p-fill', fill: 'url(#fxMetal)' });
                circle(kp, o + geo.kingpin, 0, 0.1, { class: 'fx-p-bolt' });
                [['air_tanks', 'pneumatica'], ['hydraulic_tank', 'hidraulic']].forEach(function (pair) {
                    var b = shift(parts[pair[0]]);
                    if (b && hasSlot(pair[1])) { cylinder(part(pair[1], id, b), b); }
                });
                var ecu = shift(parts.trailer_ecu);
                if (ecu && hasSlot('electrica')) {
                    rect(part('electrica', id, ecu), ecu.x0, ecu.y0, ecu.x1, ecu.y1, { class: 'fx-p-fill', fill: 'url(#fxMetal)', rx: 0.05 });
                }
            }
            if (hasSlot('lumini')) {
                var lights = part('lumini', id);
                if (!trailer) {
                    var lf = parts.lights_front;
                    rect(lights, o + lf.x0, lf.y0, o + lf.x1, lf.y1, { class: 'fx-p-fill fx-light', rx: 0.04 });
                    rect(lights, o + lf.x0, -lf.y1, o + lf.x1, -lf.y0, { class: 'fx-p-fill fx-light', rx: 0.04 });
                }
                var lr = parts.lights_rear;
                rect(lights, o + lr.x0, lr.y0, o + lr.x1, lr.y1, { class: 'fx-p-fill fx-light fx-light-rear', rx: 0.03 });
                rect(lights, o + lr.x0, -lr.y1, o + lr.x1, -lr.y0, { class: 'fx-p-fill fx-light fx-light-rear', rx: 0.03 });
            }

            // 5. Axele: grinda, butuci, discuri de frana, suspensie, diferential, aripi.
            geo.axles.forEach(function (axle) {
                var ax = o + axle.x;
                var attrs = (member.data.axle_attrs || {})[axle.no] || {};
                var driven = axle.type === 'traction' && !trailer;
                var group = el('g', { class: 'fx-axle', 'data-fx-member': String(id), 'data-fx-axle': String(axle.no) }, layers.axles);
                rect(group, ax - 0.075, -outer + 0.3, ax + 0.075, outer - 0.3, { class: 'fx-axle-beam' + (attrs.liftable ? ' is-liftable' : ''), fill: 'url(#fxRailX)' });
                rect(group, ax - 0.4, -outer + 0.25, ax + 0.4, outer - 0.25, { class: 'fx-axle-hit', fill: 'transparent' });
                if (attrs.liftable) {
                    var lift = el('text', { class: 'fx-axle-flag', x: f1(X(ax)), y: f1(Y(0) + 6), 'text-anchor': 'middle' }, group);
                    lift.textContent = '⇡';
                    el('title', {}, group).textContent = 'Axă ridicabilă';
                }
                if (driven && hasSlot('diferential')) {
                    var diff = part('diferential', id, { x0: ax - 0.42, x1: ax + 0.42, y0: -0.42, y1: 0.42 });
                    circle(diff, ax, 0, 0.4, { class: 'fx-p-fill', fill: 'url(#fxEngine)' });
                    circle(diff, ax, 0, 0.24, { class: 'fx-p-sub' });
                    circle(diff, ax, 0, 0.08, { class: 'fx-p-bolt' });
                }
                [-1, 1].forEach(function (sign) {
                    var innerEdge = outer - (axle.dual ? 0.65 : (car ? 0.24 : 0.36));
                    if (hasSlot('rulare')) {
                        circle(part('rulare', id), ax, sign * (innerEdge - 0.13), 0.13, { class: 'fx-p-fill', fill: 'url(#fxMetal)' });
                    }
                    if (hasSlot('franare')) {
                        var br = part('franare', id);
                        rect(br, ax - 0.3, sign * (innerEdge - 0.02), ax + 0.3, sign * (innerEdge - 0.09), { class: 'fx-p-fill', fill: 'url(#fxMetalDark)', rx: 0.02 });
                        rect(br, ax + 0.18, sign * (innerEdge - 0.2), ax + 0.36, sign * (innerEdge - 0.06), { class: 'fx-p-fill', fill: 'url(#fxMetal)', rx: 0.03 });
                    }
                    if (hasSlot('suspensie')) {
                        var sus = part('suspensie', id);
                        if (trailer || driven) {
                            circle(sus, ax + (trailer ? 0.42 : -0.36), sign * 0.62, 0.15, { class: 'fx-p-fill', fill: 'url(#fxCylinder)' });
                            circle(sus, ax + (trailer ? 0.42 : -0.36), sign * 0.62, 0.08, { class: 'fx-p-sub' });
                        } else {
                            rect(sus, ax - 0.7, sign * 0.56, ax + 0.7, sign * 0.68, { class: 'fx-p-fill', fill: 'url(#fxMetal)', rx: 0.04 });
                        }
                    }
                    if (hasSlot('plastice')) {
                        var fender = part('plastice', id);
                        rect(fender, ax - 0.66, sign * (outer + 0.06), ax + 0.66, sign * (outer + 0.12), { class: 'fx-p-fill', fill: 'url(#fxPod)', rx: 0.03 });
                    }
                });
            });

            // 6. Anvelopele: banda de rulare cu canale, umbrire cilindrica, inel de stare.
            placed.rects.forEach(function (item) {
                var position = item.position;
                var ax = o + item.ax;
                var g = el('g', {
                    class: 'fx-tire is-' + position.status,
                    tabindex: '0',
                    role: 'button',
                    'data-fx-pos': String(position.id),
                    'data-fx-member': String(id),
                    'aria-label': position.code + ', ' + position.side_label + ', ' + (position.tire ? position.tire.label : 'poziție liberă')
                }, layers.tires);
                var x0 = ax - item.length / 2;
                var x1 = ax + item.length / 2;
                if (position.status === 'empty') {
                    rect(g, x0, item.y0, x1, item.y1, { class: 'fx-tire-ghost', rx: 0.1 });
                } else {
                    rect(g, x0, item.y0, x1, item.y1, { class: 'fx-tire-body', fill: 'url(#fxTire)', rx: 0.1, filter: 'url(#fxSoft)' });
                    for (var t = x0 + 0.07; t < x1 - 0.04; t += 0.085) {
                        line(g, t, item.y0 + 0.035, t + 0.03, item.y1 - 0.035, { class: 'fx-tread' });
                    }
                    [0.33, 0.67].forEach(function (k) { var yy = item.y0 + (item.y1 - item.y0) * k; line(g, x0 + 0.05, yy, x1 - 0.05, yy, { class: 'fx-groove' }); });
                    rect(g, x0 + 0.02, item.y0 + 0.02, x1 - 0.02, item.y0 + 0.05, { class: 'fx-tire-shine', rx: 0.02 });
                    if (STATUS_RING[position.status]) {
                        rect(g, x0 - 0.02, item.y0 - 0.02, x1 + 0.02, item.y1 + 0.02, { class: 'fx-tire-ring', stroke: STATUS_RING[position.status], rx: 0.11 });
                    }
                }
                rect(g, x0 - 0.1, item.y0 - (item.gapAtStart ? 0.025 : 0.12), x1 + 0.1, item.y1 + (item.gapAtEnd ? 0.025 : 0.12), { class: 'fx-hit', fill: 'transparent' });
            });
        });

        return {
            svg: svg,
            layout: layout,
            view: { x: 0, y: 0, w: VIEW_W, h: viewH },
            scale: scale,
            X: X,
            Y: Y,
            S: S,
            labelBand: LABEL_BAND,
            pillBand: PILL_BAND,
            labels: layers.labels
        };
    };

    // Jumatatea de latime a caroseriei din spatele cabinei (camion = toata latimea).
    var bodyHwOf = function (geo) { return geo.type === 'camion' ? geo.halfWidth - 0.06 : geo.halfWidth - 0.2; };

    window.FleetXrayArt = { ensureDefs: ensureDefs, draw: draw };
})();
