/*
 * Pagina Anvelope, vederea pe vehicule (views/module/tire_stock/_fleet_overview.php).
 *
 * - Schema fiecarui rand: desen SVG vazut de sus, fata la stanga, din pozitiile reale ale
 *   vehiculului (vehicule_anvelope_pozitii). Culoarea fiecarei anvelope vine din server
 *   (TireModel::fleetTireStatus); aici nu se calculeaza nicio stare, uzura sau km.
 * - Ansamblul (cuplaj activ) = capul tractor + semiremorca desenate legat, prin sa.
 * - Click pe o anvelopa: se extinde randul, se incarca tabelul (action=tire_fleet_unit),
 *   se marcheaza randul anvelopei; click pe rand marcheaza anvelopa in schema.
 * - Partea stanga a vehiculului e jos in desen (privit de sus, cu fata spre stanga).
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('ftUnitsData');
    var configEl = document.querySelector('[data-ft-config]');
    if (!dataEl || !configEl) { return; }

    var units = {};
    try {
        JSON.parse(dataEl.textContent || '[]').forEach(function (unit) { units[unit.key] = unit; });
    } catch (error) {
        window.console.error(error);
    }

    var unitUrl = configEl.dataset.unitUrl;
    var candidatesUrl = configEl.dataset.candidatesUrl;
    var mountUrl = configEl.dataset.mountUrl;
    var listEl = document.querySelector('[data-ft-list]');
    var boardEl = document.querySelector('[data-ft-board]');
    var NS = 'http://www.w3.org/2000/svg';

    var escapeHtml = function (value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    };
    var formatNumber = function (value) {
        return value === null || value === undefined ? '—' : Number(value).toLocaleString('ro-RO', { maximumFractionDigits: 0 });
    };
    var formatKm = function (value) { return value === null || value === undefined ? '—' : formatNumber(value) + ' km'; };
    var formatDate = function (value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return match && match[1] !== '0000' ? match[3] + '.' + match[2] + '.' + match[1] : '—';
    };
    var formatDateTime = function (value) {
        var time = /\s(\d{2}:\d{2})/.exec(String(value || ''));
        return formatDate(value) + (time ? ' ' + time[1] : '');
    };
    var storage = {
        get: function (key) { try { return window.localStorage.getItem(key); } catch (error) { return null; } },
        set: function (key, value) { try { window.localStorage.setItem(key, value); } catch (error) { /* fara stocare */ } }
    };

    /* ------------------------------------------------------------------ Schema */

    var VB_W = 640;
    var VB_H = 150;
    var CY = 75;
    var PX = 32; // unitati SVG pe metru, la scara 1
    var MAX_SCALE = 1.45;
    var MAX_SCALE_CAR = 1.15; // autoturismul ramane mai mic decat un camion
    var STATUS_TEXT = { ok: 'OK', warn: 'Atenție', crit: 'Critic', empty: 'Poziție liberă' };
    var TIRE_FILL = { ok: '#2fae5c', warn: '#f5b301', crit: '#e5383b', empty: '#ffffff' };
    var TIRE_STROKE = { ok: '#1b7d3f', warn: '#b98400', crit: '#a51b1f', empty: '#9aa6b4' };

    var el = function (name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        if (parent) { parent.appendChild(node); }
        return node;
    };

    // Axele unui vehicul, din pozitiile lui (numar, tip, roti duble).
    var memberAxles = function (member) {
        var byNo = {};
        member.positions.forEach(function (position) {
            var axle = byNo[position.axle] || (byNo[position.axle] = { no: position.axle, type: position.axle_type, dual: false, positions: [] });
            axle.dual = axle.dual || position.dual;
            axle.positions.push(position);
        });
        return Object.keys(byNo).map(Number).sort(function (a, b) { return a - b; }).map(function (no) { return byNo[no]; });
    };

    // Geometria (in metri, de la fata vehiculului): lungime, latime, pozitia fiecarei axe.
    var memberGeometry = function (member) {
        var axles = memberAxles(member);
        var type = member.type;
        var geometry = { type: type, axles: axles, halfWidth: 1.25, length: 6 };
        // Fata = axele de directie de la inceput (A1, A2...); o axa de directie dupa puntea motoare
        // (autodirectoare spate) ramane in spate, in ordinea numerotarii.
        var front = [];
        if (type !== 'semiremorca') {
            for (var i = 0; i < axles.length && axles[i].type === 'steering'; i++) { front.push(axles[i]); }
            if (front.length === 0 && axles.length > 0) { front = [axles[0]]; }
            if (front.length === axles.length && axles.length > 1) { front = front.slice(0, axles.length - 1); }
        }
        var rear = axles.filter(function (axle) { return front.indexOf(axle) === -1; });
        var lastFront = 1.45;
        front.forEach(function (axle, index) { axle.x = 1.45 + index * 1.9; axle.front = true; lastFront = axle.x; });

        if (type === 'semiremorca') {
            geometry.halfWidth = 1.275;
            geometry.length = 13.6;
            geometry.kingpin = 1.2;
            var lastX = geometry.length - 1.75;
            rear.forEach(function (axle, index) { axle.x = lastX - (rear.length - 1 - index) * 1.36; });
        } else if (type === 'cap_tractor') {
            rear.forEach(function (axle, index) { axle.x = lastFront + 3.4 + index * 1.35; });
            var lastRear = rear.length ? rear[rear.length - 1].x : lastFront + 3.4;
            geometry.length = lastRear + 0.95;
            geometry.fifthWheel = rear.length > 1 ? (rear[0].x + lastRear) / 2 : lastRear - 0.45;
        } else if (type === 'camion') {
            rear.forEach(function (axle, index) { axle.x = lastFront + 4.4 + index * 1.35; });
            geometry.length = (rear.length ? rear[rear.length - 1].x : lastFront + 4.4) + 2.0;
        } else {
            geometry.halfWidth = 0.95;
            front.forEach(function (axle) { axle.x = 0.95; });
            rear.forEach(function (axle, index) { axle.x = 3.85 - (rear.length - 1 - index) * 0.9; });
            geometry.length = 4.8;
        }
        return geometry;
    };

    // Vehiculele unui rand asezate impreuna: semiremorca cuplata sta cu pivotul pe saua capului tractor.
    var layoutUnit = function (unit) {
        var members = unit.members.map(function (member) { return { data: member, geo: memberGeometry(member), offset: 0 }; });
        if (members.length > 1) {
            var tractor = members[0].geo;
            members[1].offset = (tractor.fifthWheel || tractor.length - 1.4) - (members[1].geo.kingpin || 1.2);
        }
        var totalLength = 0;
        var halfWidth = 0;
        members.forEach(function (member) {
            totalLength = Math.max(totalLength, member.offset + member.geo.length);
            halfWidth = Math.max(halfWidth, member.geo.halfWidth);
        });
        return { members: members, totalLength: totalLength, halfWidth: halfWidth };
    };

    var isCarType = function (type) { return type !== 'semiremorca' && type !== 'cap_tractor' && type !== 'camion'; };

    // Anvelopele unui vehicul ca dreptunghiuri (metri, fata vehiculului = 0, axul = 0; dreapta sus).
    // La rotile duble anvelopa interioara sta langa cea exterioara, spre axul vehiculului; zona de
    // click se imparte la jumatatea spatiului dintre ele (gapAtStart / gapAtEnd).
    var tireRects = function (geo) {
        var isCar = isCarType(geo.type);
        var tireLength = isCar ? 0.66 : 1.05;
        var singleWidth = isCar ? 0.24 : 0.36;
        var dualWidth = 0.3;
        var outer = geo.halfWidth + (isCar ? 0.02 : 0.08);
        var rects = [];
        geo.axles.forEach(function (axle) {
            axle.positions.forEach(function (position) {
                var side = String(position.side || '').toUpperCase();
                var isTop = side.charAt(0) === 'R';
                var isInner = side.length > 1 && side.charAt(1) === 'I';
                var width = axle.dual ? dualWidth : singleWidth;
                var edge = isInner ? outer - dualWidth - 0.05 : outer;
                var y0 = isTop ? -edge : edge - width;
                rects.push({
                    position: position, axle: axle, ax: axle.x, length: tireLength,
                    x0: axle.x - tireLength / 2, x1: axle.x + tireLength / 2, y0: y0, y1: y0 + width,
                    isTop: isTop, isInner: isInner,
                    gapAtEnd: axle.dual && (isTop !== isInner), gapAtStart: axle.dual && (isTop === isInner)
                });
            });
        });
        return { rects: rects, outer: outer };
    };

    // Ansamblurile mecanice ale unui vehicul (metri, fata = 0, axul = 0, dreapta sus): unde le
    // deseneaza exploratorul (vehicle-xray-art.js) si unde stau punctele componentelor. O singura
    // sursa: punctul unei componente cade mereu pe piesa desenata.
    var partLayout = function (geo) {
        var car = isCarType(geo.type);
        var hw = geo.halfWidth;
        var parts = {};
        var driven = geo.axles.filter(function (axle) { return axle.type === 'traction'; });
        var firstRear = geo.axles.filter(function (axle) { return !axle.front && axle.x > 2.6; })[0];
        var sideEnd = firstRear ? firstRear.x - 0.75 : geo.length - 0.6;
        // Rezervoarele laterale stau dupa ultima axa din fata (doua axe de directie le imping spre spate).
        var sideStart = geo.axles.reduce(function (start, axle) { return axle.front ? Math.max(start, axle.x + 0.85) : start; }, 2.5);
        if (geo.type === 'semiremorca') {
            // Relativ la prima axa: aceleasi locuri si pe semiremorca reala, si pe cea comprimata din explorator.
            var fa = geo.axles.length ? geo.axles[0].x : geo.length - 4;
            var free = Math.max(1.4, fa - 0.95 - 3.0);
            parts.kingpin_plate = { x0: 0.3, x1: 1.9, y0: -0.8, y1: 0.8 };
            parts.landing_gear = { x0: 2.45, x1: 2.75, y0: -1.02, y1: 1.02 };
            parts.air_tanks = { x0: 3.0 + free * 0.3, x1: 3.0 + free * 0.85, y0: -0.24, y1: 0.24 };
            parts.toolbox = { x0: 3.0, x1: 3.0 + free * 0.48, y0: 0.56, y1: 0.98 };
            parts.hydraulic_tank = { x0: 3.1 + free * 0.5, x1: 3.0 + free, y0: 0.56, y1: 0.98 };
            parts.trailer_ecu = { x0: 3.0 + free * 0.62, x1: 3.0 + free, y0: -0.9, y1: -0.52 };
        } else if (car) {
            parts.radiator = { x0: 0.08, x1: 0.2, y0: -0.6, y1: 0.6 };
            parts.engine = { x0: 0.3, x1: 1.12, y0: -0.45, y1: 0.45 };
            parts.gearbox = { x0: 1.14, x1: 1.62, y0: -0.22, y1: 0.22 };
            parts.steering = { x0: 1.0, x1: 1.25, y0: 0.42, y1: 0.66 };
            parts.battery = { x0: 0.32, x1: 0.62, y0: -0.78, y1: -0.52 };
            // Inaintea puntii spate (rezervor) si in spatele ei (toba), ca sa nu acopere axa.
            parts.fuel_tank = { x0: 2.45, x1: 3.15, y0: 0.08, y1: 0.62 };
            parts.exhaust = { x0: 4.15, x1: 4.72, y0: 0.28, y1: 0.56 };
        } else {
            parts.radiator = { x0: 0.1, x1: 0.32, y0: -0.82, y1: 0.82 };
            parts.engine = { x0: 0.45, x1: 1.72, y0: -0.5, y1: 0.5 };
            parts.gearbox = { x0: 1.74, x1: 2.52, y0: -0.32, y1: 0.32 };
            parts.steering = { x0: 0.98, x1: 1.34, y0: 0.6, y1: 0.98 };
            // Pe lateralele sasiului, intre cabina si prima axa din spate (stanga = jos, dreapta = sus).
            var span = Math.max(1.2, sideEnd - sideStart);
            parts.fuel_tank = { x0: sideStart, x1: sideStart + span * 0.62, y0: 0.6, y1: 1.12 };
            parts.battery = { x0: sideStart + 0.06 + span * 0.62, x1: sideEnd, y0: 0.62, y1: 1.06 };
            parts.exhaust = { x0: sideStart, x1: sideStart + span * 0.52, y0: -1.12, y1: -0.62 };
            parts.air_tanks = { x0: sideStart + 0.06 + span * 0.52, x1: sideStart + span * 0.82, y0: -1.08, y1: -0.62 };
            parts.hydraulic_tank = { x0: sideStart + 0.06 + span * 0.82, x1: sideEnd, y0: -1.06, y1: -0.64 };
            if (driven.length) { parts.driveshaft = { x0: 2.52, x1: driven[0].x - 0.36, y0: -0.07, y1: 0.07 }; }
            if (geo.type === 'cap_tractor') {
                parts.fifth_wheel = { cx: geo.fifthWheel, cy: 0, r: 0.7 };
                parts.fifth_wheel_lock = { x0: geo.fifthWheel - 0.42, x1: geo.fifthWheel - 0.06, y0: 0.5, y1: 0.78 };
            }
        }
        parts.lights_front = { x0: 0, x1: car ? 0.1 : 0.12, y0: -hw + 0.08, y1: -hw + 0.42 };
        parts.lights_rear = { x0: geo.length - 0.12, x1: geo.length, y0: hw - 0.42, y1: hw - 0.08 };
        return parts;
    };
    var boxCenter = function (box) {
        return box ? (box.cx !== undefined ? { x: box.cx, y: box.cy } : { x: (box.x0 + box.x1) / 2, y: (box.y0 + box.y1) / 2 }) : null;
    };

    // Punctul (metri, in sistemul vehiculului) al unui loc de componenta din registru
    // (VehicleComponentRegistry::memberSlots): pe ansamblul desenat (partLayout) sau pe roata lui.
    // Frana pe anvelopa exterioara, butucul langa ea (roti duble) sau spre ax, suspensia langa axa,
    // diferentialul pe axa motoare, saua in spatele cabinei, pivotul in fata semiremorcii.
    var slotAnchor = function (slot, geo) {
        var hw = geo.halfWidth;
        var axle = geo.axles.filter(function (item) { return item.no === slot.axle; })[0] || null;
        var ax = axle ? axle.x : 0;
        var sideSign = slot.side === 'L' ? 1 : -1;
        var car = isCarType(geo.type);
        var trailer = geo.type === 'semiremorca';
        var outer = hw + (car ? 0.02 : 0.08);
        var tireMid = outer - (axle && axle.dual ? 0.15 : (car ? 0.12 : 0.18));
        var parts = partLayout(geo);
        var zones = {
            engine: 'engine', gearbox: 'gearbox', radiator: 'radiator', steering: 'steering', exhaust: 'exhaust',
            air_tanks: 'air_tanks', fuel_tank: 'fuel_tank', battery: 'battery', hydraulic_tank: 'hydraulic_tank',
            trailer_ecu: 'trailer_ecu', fifth_wheel: 'fifth_wheel', fifth_wheel_lock: 'fifth_wheel_lock',
            lights_front: 'lights_front', lights_rear: 'lights_rear'
        };
        switch (slot.zone) {
            case 'wheel_end':
                if (slot.category === 'franare') { return { x: ax - (axle && axle.dual ? 0.2 : 0), y: sideSign * tireMid }; }
                if (axle && axle.dual) { return { x: ax + 0.46, y: sideSign * tireMid }; }
                return { x: ax, y: sideSign * (trailer ? 0.42 : outer - 0.62) };
            case 'axle_center':
                if (trailer) { return { x: ax, y: 0 }; }
                return axle && axle.type === 'traction' ? { x: ax - 0.3, y: -(outer - 0.86) } : { x: ax + 0.5, y: -(outer - 0.66) };
            case 'differential': return { x: ax, y: 0 };
            case 'fender': return { x: ax + (car ? 0.45 : 0.72), y: -(outer - 0.08) };
            case 'kingpin': return { x: geo.kingpin || 1.2, y: -0.55 };
            case 'body': return trailer ? boxCenter(parts.toolbox) : { x: car ? 2.6 : 2.1, y: car ? 0 : 0.42 };
            default:
                if (zones[slot.zone] && parts[zones[slot.zone]]) { return boxCenter(parts[zones[slot.zone]]); }
                return trailer ? { x: 3.3, y: 0 } : { x: car ? 2.6 : 1.75, y: 0 };
        }
    };

    window.FleetVehicleGeometry = { layoutUnit: layoutUnit, tireRects: tireRects, slotAnchor: slotAnchor, partLayout: partLayout, isCarType: isCarType, PX: PX };

    var renderSchema = function (container, unit) {
        var layout = layoutUnit(unit);
        var members = layout.members;
        var totalLength = layout.totalLength;
        var halfWidth = layout.halfWidth;
        var isCarUnit = ['cap_tractor', 'camion', 'semiremorca'].indexOf(members[0].geo.type) === -1;
        var scale = Math.min(isCarUnit ? MAX_SCALE_CAR : MAX_SCALE, (VB_W - 24) / (totalLength * PX), (VB_H / 2 - 24) / ((halfWidth + 0.1) * PX));
        var originX = (VB_W - totalLength * PX * scale) / 2;
        var X = function (meters) { return originX + meters * PX * scale; };
        var Y = function (meters) { return CY + meters * PX * scale; };
        var S = function (meters) { return meters * PX * scale; };

        var svg = el('svg', { viewBox: '0 0 ' + VB_W + ' ' + VB_H, class: 'ft-svg', role: 'group', 'aria-label': 'Schema anvelopelor, vedere de sus, fața vehiculului în stânga' });
        var body = el('g', { 'aria-hidden': 'true' }, svg);
        var axlesLayer = el('g', { 'aria-hidden': 'true' }, svg);
        var topLayer = el('g', { 'aria-hidden': 'true' }, svg);
        var tiresLayer = el('g', {}, svg);
        var pillsLayer = el('g', {}, svg);

        var rect = function (parent, x0, y0, x1, y1, attrs) {
            var r = el('rect', { x: X(x0).toFixed(1), y: Y(y0).toFixed(1), width: S(x1 - x0).toFixed(1), height: S(y1 - y0).toFixed(1) }, parent);
            Object.keys(attrs || {}).forEach(function (key) {
                r.setAttribute(key, key === 'rx' ? S(attrs[key]).toFixed(1) : attrs[key]);
            });
            return r;
        };
        var line = function (parent, x0, y0, x1, y1, attrs) {
            var l = el('line', { x1: X(x0).toFixed(1), y1: Y(y0).toFixed(1), x2: X(x1).toFixed(1), y2: Y(y1).toFixed(1) }, parent);
            Object.keys(attrs || {}).forEach(function (key) { l.setAttribute(key, attrs[key]); });
            return l;
        };

        var drawCab = function (x0, hw) {
            rect(topLayer, x0 + 0.02, -hw - 0.24, x0 + 0.3, -hw + 0.02, { fill: '#3a4553', rx: 0.06 });
            rect(topLayer, x0 + 0.02, hw - 0.02, x0 + 0.3, hw + 0.24, { fill: '#3a4553', rx: 0.06 });
            rect(topLayer, x0, -hw, x0 + 2.25, hw, { fill: '#ffffff', stroke: '#8995a3', 'stroke-width': 1.2, rx: 0.38 });
            rect(topLayer, x0 + 0.62, -hw + 0.2, x0 + 2.1, hw - 0.2, { fill: '#eef1f5', stroke: '#c9d1db', 'stroke-width': 0.8, rx: 0.22 });
            line(topLayer, x0 + 0.85, -0.35, x0 + 1.9, -0.35, { stroke: '#d3dae3', 'stroke-width': 1 });
            line(topLayer, x0 + 0.85, 0.35, x0 + 1.9, 0.35, { stroke: '#d3dae3', 'stroke-width': 1 });
            rect(topLayer, x0 + 0.1, -hw + 0.14, x0 + 0.5, hw - 0.14, { fill: '#2b3644', rx: 0.12 });
            rect(topLayer, x0 + 0.16, -hw + 0.3, x0 + 0.26, hw - 0.3, { fill: '#5b6b80', rx: 0.04, opacity: 0.6 });
        };
        var drawTractor = function (geo, x0) {
            var hw = geo.halfWidth;
            rect(body, x0 + 2.0, -0.56, x0 + geo.length - 0.12, -0.42, { fill: '#5d6774' });
            rect(body, x0 + 2.0, 0.42, x0 + geo.length - 0.12, 0.56, { fill: '#5d6774' });
            for (var cx = x0 + 2.6; cx < x0 + geo.length - 0.3; cx += 0.9) { rect(body, cx, -0.42, cx + 0.08, 0.42, { fill: '#8a949f' }); }
            rect(body, x0 + 2.35, -hw + 0.12, x0 + 3.55, -0.68, { fill: '#e2e7ed', stroke: '#a3adb9', 'stroke-width': 0.8, rx: 0.18 });
            rect(body, x0 + 2.35, 0.68, x0 + 3.55, hw - 0.12, { fill: '#e2e7ed', stroke: '#a3adb9', 'stroke-width': 0.8, rx: 0.18 });
            rect(body, x0 + geo.length - 0.22, -hw + 0.25, x0 + geo.length - 0.05, hw - 0.25, { fill: '#5d6774', rx: 0.04 });
            drawCab(x0, hw);
        };
        var drawFifthWheel = function (x, coupled) {
            var c = el('circle', { cx: X(x).toFixed(1), cy: Y(0).toFixed(1), r: S(0.55).toFixed(1), fill: '#46505c', stroke: '#2a313a', 'stroke-width': 1, opacity: coupled ? 0.85 : 1 }, topLayer);
            rect(topLayer, x, -0.09, x + 0.55, 0.09, { fill: '#1f252c' });
            if (coupled) {
                el('circle', { cx: X(x).toFixed(1), cy: Y(0).toFixed(1), r: S(0.17).toFixed(1), fill: '#c7ced6', stroke: '#1f252c', 'stroke-width': 1 }, topLayer);
                el('circle', { cx: X(x).toFixed(1), cy: Y(0).toFixed(1), r: S(0.8).toFixed(1), fill: 'none', stroke: '#7b8794', 'stroke-width': 1, 'stroke-dasharray': '3 2' }, topLayer);
            }
            return c;
        };
        var drawTruck = function (geo, x0) {
            var hw = geo.halfWidth;
            rect(body, x0 + 2.0, -0.56, x0 + geo.length - 0.3, -0.42, { fill: '#5d6774' });
            rect(body, x0 + 2.0, 0.42, x0 + geo.length - 0.3, 0.56, { fill: '#5d6774' });
            rect(topLayer, x0 + 2.4, -hw - 0.02, x0 + geo.length - 0.08, hw + 0.02, { fill: '#f6f8fa', stroke: '#97a3b0', 'stroke-width': 1.1, rx: 0.12 });
            [-0.75, 0, 0.75].forEach(function (y) { line(topLayer, x0 + 2.6, y, x0 + geo.length - 0.3, y, { stroke: '#dde3ea', 'stroke-width': 1 }); });
            line(topLayer, x0 + geo.length - 0.32, -hw + 0.1, x0 + geo.length - 0.32, hw - 0.1, { stroke: '#c3cbd5', 'stroke-width': 1 });
            drawCab(x0, hw);
        };
        var drawSemi = function (geo, x0) {
            var hw = geo.halfWidth;
            rect(body, x0, -hw, x0 + geo.length, hw, { fill: '#e8ecf1', stroke: '#8e99a6', 'stroke-width': 1.2, rx: 0.16 });
            rect(body, x0 + 0.25, -0.6, x0 + geo.length - 0.25, -0.46, { fill: '#a7b1bd' });
            rect(body, x0 + 0.25, 0.46, x0 + geo.length - 0.25, 0.6, { fill: '#a7b1bd' });
            for (var cx = x0 + 0.9; cx < x0 + geo.length - 0.4; cx += 1.15) { line(body, cx, -hw + 0.12, cx, hw - 0.12, { stroke: '#cfd6de', 'stroke-width': 1 }); }
            rect(body, x0 + 2.55, -0.98, x0 + 2.75, -0.7, { fill: '#7d8894' });
            rect(body, x0 + 2.55, 0.7, x0 + 2.75, 0.98, { fill: '#7d8894' });
            el('circle', { cx: X(x0 + geo.kingpin).toFixed(1), cy: Y(0).toFixed(1), r: S(0.16).toFixed(1), fill: '#6b7684' }, body);
        };
        var drawCar = function (geo, x0) {
            var hw = geo.halfWidth;
            rect(topLayer, x0, -hw, x0 + geo.length, hw, { fill: '#ffffff', stroke: '#8995a3', 'stroke-width': 1.2, rx: 0.55 });
            rect(topLayer, x0 + 1.95, -hw + 0.18, x0 + 3.85, hw - 0.18, { fill: '#eef1f5', stroke: '#c9d1db', 'stroke-width': 0.8, rx: 0.25 });
            rect(topLayer, x0 + 1.45, -hw + 0.18, x0 + 1.78, hw - 0.18, { fill: '#2b3644', rx: 0.12 });
            rect(topLayer, x0 + 3.98, -hw + 0.24, x0 + 4.2, hw - 0.24, { fill: '#2b3644', rx: 0.1 });
        };

        // Ordinea: semiremorca, sasiul / cabina capului, axele, anvelopele si etichetele deasupra.
        for (var m = members.length - 1; m >= 0; m--) {
            var member = members[m];
            var geo = member.geo;
            if (geo.type === 'semiremorca') { drawSemi(geo, member.offset); }
            else if (geo.type === 'cap_tractor') { drawTractor(geo, member.offset); }
            else if (geo.type === 'camion') { drawTruck(geo, member.offset); }
            else { drawCar(geo, member.offset); }
        }
        if (members[0].geo.type === 'cap_tractor') { drawFifthWheel(members[0].geo.fifthWheel, members.length > 1); }

        var tireNodes = [];
        var pillRows = { "top-out": [], "top-in": [], "bottom-in": [], "bottom-out": [] };
        members.forEach(function (member) {
            var placed = tireRects(member.geo);
            var outer = placed.outer;
            member.geo.axles.forEach(function (axle) {
                var ax = member.offset + axle.x;
                rect(axlesLayer, ax - 0.06, -outer + 0.2, ax + 0.06, outer - 0.2, { fill: '#46505c' });
            });
            placed.rects.forEach(function (item) {
                var position = item.position;
                var ax = member.offset + item.ax;
                var tireLength = item.length;
                var y0 = item.y0;
                var width = item.y1 - item.y0;
                var g = el('g', {
                    class: 'ft-tire',
                    tabindex: '0',
                    role: 'button',
                    'data-ft-pos': String(position.id),
                    'data-ft-member': String(member.data.vehicle_id),
                    'aria-label': position.code + ', ' + position.side_label + ', ' + (position.tire ? position.tire.label + ', ' + STATUS_TEXT[position.status] : 'poziție liberă')
                }, tiresLayer);
                var title = el('title', {}, g);
                title.textContent = member.data.plate + ' · ' + position.code + ' (' + position.side_label + ')\n' + (position.tire
                    ? position.tire.label + (position.tire.size ? ' · ' + position.tire.size : '') + '\nStatus: ' + STATUS_TEXT[position.status] + (position.tire.wear !== null ? ' · uzură ' + Math.round(position.tire.wear) + '%' : '')
                    : 'Poziție liberă');
                rect(g, ax - tireLength / 2 - 0.08, y0 - 0.08, ax + tireLength / 2 + 0.08, y0 + width + 0.08, { class: 'ft-tire-halo', rx: 0.14 });
                var tire = rect(g, ax - tireLength / 2, y0, ax + tireLength / 2, y0 + width, {
                    class: 'ft-tire-body',
                    fill: TIRE_FILL[position.status] || TIRE_FILL.empty,
                    stroke: TIRE_STROKE[position.status] || TIRE_STROKE.empty,
                    'stroke-width': 1.1,
                    rx: 0.08
                });
                if (position.status === 'empty') {
                    tire.setAttribute('stroke-dasharray', '3 2');
                } else {
                    [-0.3, 0, 0.3].forEach(function (dx) {
                        line(g, ax + dx * tireLength, y0 + width * 0.18, ax + dx * tireLength, y0 + width * 0.82, { stroke: 'rgba(0,0,0,.28)', 'stroke-width': 1, 'pointer-events': 'none' });
                    });
                }
                // Zona de click a anvelopei: la rotile duble se imparte la jumatatea spatiului dintre
                // cele doua anvelope, ca anvelopa interioara sa se poata alege separat de cea exterioara.
                rect(g, ax - tireLength / 2 - 0.1, y0 - (item.gapAtStart ? 0.025 : 0.12), ax + tireLength / 2 + 0.1, y0 + width + (item.gapAtEnd ? 0.025 : 0.12), { class: 'ft-tire-hit', fill: 'transparent' });
                tireNodes.push(g);

                // Eticheta proprie fiecarei anvelope: exterioara in afara vehiculului (ca la roata
                // simpla), interioara pe caroserie, langa anvelopa ei, spre axul vehiculului.
                var rowKey = (item.isTop ? 'top' : 'bottom') + (item.isInner ? '-in' : '-out');
                var edgePx = item.isTop ? (item.isInner ? Y(y0 + width) + 2 : Y(y0) - 4 - PILL_H) : (item.isInner ? Y(y0) - 2 - PILL_H : Y(y0 + width) + 4);
                pillRows[rowKey].push({ x: X(ax), y: edgePx, label: position.code, status: position.status, positionId: position.id, memberId: member.data.vehicle_id });
            });
        });

        Object.keys(pillRows).forEach(function (rowKey) { drawPillRow(pillsLayer, pillRows[rowKey]); });

        container.innerHTML = '';
        container.appendChild(svg);
        return tireNodes;
    };

    var PILL_H = 15;
    // Trei marimi de eticheta: normala (cu pictograma), compacta si mica. O eticheta coboara de
    // marime doar cand s-ar suprapune cu vecina din acelasi rand (ex. axe tandem cu roti duble).
    var PILL_SIZES = [
        { font: 9.6, char: 5.7, pad: 18, textX: 15.5, icon: true },
        { font: 8.4, char: 5.0, pad: 9, textX: 4.5, icon: false },
        { font: 7.2, char: 4.3, pad: 7, textX: 3.5, icon: false }
    ];
    var pillWidth = function (pill) { var size = PILL_SIZES[pill.size]; return size.pad + pill.label.length * size.char; };

    var drawPillRow = function (layer, pills) {
        pills.sort(function (a, b) { return a.x - b.x; });
        pills.forEach(function (pill) { pill.size = 0; });
        for (var pass = 0; pass < PILL_SIZES.length; pass++) {
            var changed = false;
            for (var i = 1; i < pills.length; i++) {
                var left = pills[i - 1];
                var right = pills[i];
                if (right.x - left.x < (pillWidth(left) + pillWidth(right)) / 2 + 3) {
                    if (left.size < PILL_SIZES.length - 1) { left.size++; changed = true; }
                    if (right.size < PILL_SIZES.length - 1) { right.size++; changed = true; }
                }
            }
            if (!changed) { break; }
        }
        pills.forEach(function (spec) {
            var size = PILL_SIZES[spec.size];
            var status = spec.status;
            var w = pillWidth(spec);
            var px = spec.x - w / 2;
            var py = spec.y;
            var pill = el('g', { class: 'ft-pill', 'data-ft-pos': String(spec.positionId), 'data-ft-member': String(spec.memberId), 'aria-hidden': 'true' }, layer);
            el('rect', { x: px.toFixed(1), y: py.toFixed(1), width: w.toFixed(1), height: PILL_H, rx: 7.5, fill: status === 'empty' ? '#f8fafc' : (status === 'crit' ? '#fff1f1' : status === 'warn' ? '#fff7e0' : '#effaf3'), stroke: status === 'empty' ? '#b8c2ce' : TIRE_STROKE[status], 'stroke-width': 1.1 }, pill);
            if (size.icon) {
                var iconX = px + 8.5;
                var iconY = py + PILL_H / 2;
                if (status === 'empty') {
                    el('circle', { cx: iconX.toFixed(1), cy: iconY.toFixed(1), r: 4, fill: '#fff', stroke: '#9aa6b4', 'stroke-width': 1.2 }, pill);
                } else {
                    el('circle', { cx: iconX.toFixed(1), cy: iconY.toFixed(1), r: 4.6, fill: TIRE_FILL[status] }, pill);
                    var mark = el('path', { fill: 'none', stroke: '#fff', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, pill);
                    mark.setAttribute('d', status === 'ok'
                        ? 'M' + (iconX - 2.1) + ' ' + iconY + 'l1.5 1.6l2.8-3.1'
                        : 'M' + iconX + ' ' + (iconY - 2.4) + 'v2.8M' + iconX + ' ' + (iconY + 2.1) + 'v.1');
                }
            }
            var text = el('text', { x: (px + size.textX).toFixed(1), y: (py + 7.5 + size.font * 0.35).toFixed(1), 'font-size': size.font, 'font-weight': 700, fill: '#1d2939' }, pill);
            text.textContent = spec.label;
        });
    };

    var markSchema = function (unitEl, positionId) {
        unitEl.querySelectorAll('.ft-svg .ft-tire, .ft-svg .ft-pill').forEach(function (node) {
            node.classList.toggle('is-selected', positionId !== null && node.getAttribute('data-ft-pos') === String(positionId));
        });
    };

    var drawUnit = function (unitEl) {
        var unit = units[unitEl.dataset.ftUnit];
        var container = unitEl.querySelector('[data-ft-schema]');
        if (!unit || !container) { return; }
        renderSchema(container, unit);
        if (selection.unit === unitEl.dataset.ftUnit) { markSchema(unitEl, selection.position); }
    };

    /* ------------------------------------------------------------------ Filtre, paginare */

    var unitEls = listEl ? Array.prototype.slice.call(listEl.querySelectorAll('[data-ft-unit]')) : [];
    var searchInput = document.querySelector('[data-ft-search]');
    var searchClear = document.querySelector('[data-ft-search-clear]');
    var kindSelect = document.querySelector('[data-ft-kind]');
    var fillSelect = document.querySelector('[data-ft-fill]');
    var resetBtn = document.querySelector('[data-ft-reset]');
    var perPageSelect = document.querySelector('[data-ft-per-page]');
    var pagesEl = document.querySelector('[data-ft-pages]');
    var rangeEl = document.querySelector('[data-ft-range]');
    var emptyEl = document.querySelector('[data-ft-empty]');
    var params = new URLSearchParams(window.location.search);
    var state = {
        // Tab-ul Vehicule grele / Vehicule ușoare (?mode=usoare); inventarul nu schimbă clasa.
        vclass: params.get('mode') === 'usoare' ? 'usoare' : 'grele',
        q: params.get('vq') || '',
        status: params.get('vst') || '',
        kind: params.get('vtip') || '',
        fill: params.get('vstare') || '',
        perPage: Number(params.get('vpp') || storage.get('ft.perPage') || 10),
        page: Math.max(1, Number(params.get('vpag') || 1)),
        // Fara preferinta salvata: grila pe ecrane inguste (schema ramane lizibila), lista in rest.
        layout: storage.get('ft.layout') ? (storage.get('ft.layout') === 'grid' ? 'grid' : 'list') : (window.matchMedia('(max-width: 700px)').matches ? 'grid' : 'list')
    };
    var selection = { unit: null, position: null, tire: null };

    var writeUrl = function () {
        var url = new URLSearchParams(window.location.search);
        [['vq', state.q], ['vst', state.status], ['vtip', state.kind], ['vstare', state.fill], ['vpp', state.perPage !== 10 ? String(state.perPage) : ''], ['vpag', state.page > 1 ? String(state.page) : '']].forEach(function (pair) {
            if (pair[1]) { url.set(pair[0], pair[1]); } else { url.delete(pair[0]); }
        });
        var query = url.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
        syncReturnQueries();
    };
    // Formularele (bara in masa, montaj, randurile incarcate) revin pe aceleasi filtre.
    var syncReturnQueries = function () {
        var query = window.location.search.replace(/^\?/, '');
        document.querySelectorAll('[data-ft-detail] input[name="return_query"], [data-ft-mount-form] input[name="return_query"], #tireBulkForm input[name="return_query"]').forEach(function (input) { input.value = query; });
    };

    var matches = function (unitEl, ignoreStatus) {
        var tokens = state.q.toLowerCase().split(/\s+/).filter(Boolean);
        if ((unitEl.dataset.class || 'grele') !== state.vclass) { return false; }
        var haystack = unitEl.dataset.search || '';
        if (!tokens.every(function (token) { return haystack.indexOf(token) !== -1; })) { return false; }
        if (state.kind && unitEl.dataset.kind !== state.kind) { return false; }
        if (state.fill && unitEl.dataset.fill !== state.fill) { return false; }
        return ignoreStatus || !state.status || unitEl.dataset.status === state.status;
    };

    var renderPages = function (current, total) {
        if (!pagesEl) { return; }
        var pages = [];
        for (var p = 1; p <= total; p++) {
            if (p === 1 || p === total || Math.abs(p - current) <= 1) { pages.push(p); } else if (pages[pages.length - 1] !== '…') { pages.push('…'); }
        }
        pagesEl.innerHTML = pages.map(function (p) {
            return p === '…' ? '<span class="px-1 align-self-center text-muted">…</span>'
                : '<button type="button" class="ft-page-btn' + (p === current ? ' is-active' : '') + '" data-ft-goto="' + p + '"' + (p === current ? ' aria-current="page"' : '') + '>' + p + '</button>';
        }).join('');
    };

    var apply = function (keepPage) {
        var counts = { all: 0, ok: 0, warn: 0, crit: 0 };
        var visible = [];
        unitEls.forEach(function (unitEl) {
            if (matches(unitEl, true)) {
                counts.all++;
                if (counts[unitEl.dataset.status] !== undefined) { counts[unitEl.dataset.status]++; }
            }
            if (matches(unitEl, false)) { visible.push(unitEl); }
        });
        Object.keys(counts).forEach(function (key) {
            var badge = document.querySelector('[data-ft-count="' + key + '"]');
            if (badge) { badge.textContent = String(counts[key]); }
        });
        var perPage = state.perPage > 0 ? state.perPage : Math.max(1, visible.length);
        var totalPages = Math.max(1, Math.ceil(visible.length / perPage));
        if (!keepPage) { state.page = 1; }
        state.page = Math.min(Math.max(1, state.page), totalPages);
        var start = (state.page - 1) * perPage;
        var shown = visible.slice(start, start + perPage);
        unitEls.forEach(function (unitEl) { unitEl.hidden = shown.indexOf(unitEl) === -1; });
        if (emptyEl && unitEls.length > 0) {
            emptyEl.hidden = visible.length > 0;
            var classHasUnits = unitEls.some(function (unitEl) { return (unitEl.dataset.class || 'grele') === state.vclass; });
            emptyEl.lastChild.textContent = classHasUnits ? 'Niciun vehicul nu corespunde filtrelor.'
                : (state.vclass === 'usoare' ? 'Nu există vehicule ușoare active.' : 'Nu există vehicule grele active.');
        }
        if (rangeEl) {
            rangeEl.textContent = visible.length === 0 ? 'Niciun vehicul afișat'
                : 'Afișare ' + (start + 1) + '–' + (start + shown.length) + ' din ' + visible.length + (visible.length === 1 ? ' vehicul' : ' vehicule');
        }
        renderPages(state.page, totalPages);
        var prev = document.querySelector('[data-ft-page="prev"]');
        var next = document.querySelector('[data-ft-page="next"]');
        if (prev) { prev.disabled = state.page <= 1; }
        if (next) { next.disabled = state.page >= totalPages; }
        document.querySelectorAll('[data-ft-status]').forEach(function (chip) {
            var active = chip.dataset.ftStatus === state.status;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (searchClear) { searchClear.hidden = state.q === ''; }
        if (resetBtn) { resetBtn.disabled = !state.q && !state.status && !state.kind && !state.fill; }
        writeUrl();
    };

    var setLayout = function (layout) {
        state.layout = layout;
        storage.set('ft.layout', layout);
        if (listEl) { listEl.classList.toggle('is-grid', layout === 'grid'); }
        if (boardEl) { boardEl.classList.toggle('is-grid', layout === 'grid'); }
        document.querySelectorAll('[data-ft-layout]').forEach(function (button) {
            var active = button.dataset.ftLayout === layout;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    };

    if (searchInput) {
        searchInput.value = state.q;
        var searchTimer = 0;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { state.q = searchInput.value.trim(); apply(false); }, 120);
        });
    }
    if (searchClear) {
        searchClear.addEventListener('click', function () { searchInput.value = ''; state.q = ''; apply(false); searchInput.focus(); });
    }
    if (kindSelect) {
        kindSelect.value = state.kind;
        kindSelect.addEventListener('change', function () { state.kind = kindSelect.value; apply(false); });
    }
    if (fillSelect) {
        fillSelect.value = state.fill;
        fillSelect.addEventListener('change', function () { state.fill = fillSelect.value; apply(false); });
    }
    if (perPageSelect) {
        if (![10, 25, 50, 0].includes(state.perPage)) { state.perPage = 10; }
        perPageSelect.value = String(state.perPage);
        perPageSelect.addEventListener('change', function () { state.perPage = Number(perPageSelect.value); storage.set('ft.perPage', String(state.perPage)); apply(false); });
    }
    document.querySelectorAll('[data-ft-status]').forEach(function (chip) {
        chip.addEventListener('click', function () { state.status = chip.dataset.ftStatus; apply(false); });
    });
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            state.q = ''; state.status = ''; state.kind = ''; state.fill = '';
            if (searchInput) { searchInput.value = ''; }
            if (kindSelect) { kindSelect.value = ''; }
            if (fillSelect) { fillSelect.value = ''; }
            apply(false);
        });
    }
    document.querySelectorAll('[data-ft-layout]').forEach(function (button) {
        button.addEventListener('click', function () { setLayout(button.dataset.ftLayout); });
    });
    document.addEventListener('click', function (event) {
        var go = event.target.closest('[data-ft-goto]');
        var step = event.target.closest('[data-ft-page]');
        if (go) { state.page = Number(go.dataset.ftGoto); apply(true); }
        else if (step && !step.disabled) { state.page += step.dataset.ftPage === 'next' ? 1 : -1; apply(true); }
        else { return; }
        if (boardEl) { boardEl.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    });

    /* ------------------------------------------------------------------ Vehicule / Inventar */

    // Clasa activa: randurile, optiunile din „Tip vehicul” si antetul schemei.
    var setVehicleClass = function (vclass) {
        var changed = state.vclass !== vclass;
        state.vclass = vclass;
        if (kindSelect) {
            Array.prototype.forEach.call(kindSelect.options, function (option) {
                if (!option.value) { return; }
                var other = (option.dataset.class || 'grele') !== vclass;
                option.hidden = other;
                option.disabled = other;
            });
            if (kindSelect.value && kindSelect.selectedOptions[0] && kindSelect.selectedOptions[0].disabled) {
                kindSelect.value = '';
                state.kind = '';
            }
        }
        var schemaHead = document.querySelector('[data-ft-schema-head]');
        if (schemaHead) { schemaHead.textContent = vclass === 'usoare' ? 'Schema anvelope' : 'Schema anvelope (cap tractor + semiremorcă)'; }
        if (changed) {
            unitEls.forEach(function (unitEl) { if (unitEl.classList.contains('is-open')) { setOpen(unitEl, false); } });
        }
    };

    document.querySelectorAll('[data-ft-mode]').forEach(function (button) {
        button.addEventListener('click', function () {
            var mode = button.dataset.ftMode;
            var pane = mode === 'inventar' ? 'inventar' : 'vehicule';
            document.querySelectorAll('[data-ft-mode]').forEach(function (other) {
                var active = other === button;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-ft-pane]').forEach(function (paneEl) { paneEl.hidden = paneEl.dataset.ftPane !== pane; });
            var url = new URLSearchParams(window.location.search);
            if (mode === 'grele') { url.delete('mode'); } else { url.set('mode', mode); }
            window.history.replaceState(null, '', window.location.pathname + '?' + url.toString() + window.location.hash);
            if (pane === 'vehicule') {
                setVehicleClass(mode);
                apply(false);
            } else {
                syncReturnQueries();
            }
        });
    });

    /* ------------------------------------------------------------------ Randuri extinse */

    var findUnitEl = function (key) { return listEl ? listEl.querySelector('[data-ft-unit="' + key + '"]') : null; };

    var loadDetail = function (unitEl) {
        var detail = unitEl.querySelector('[data-ft-detail]');
        if (unitEl._ftLoading) { return unitEl._ftLoading; }
        if (detail.dataset.loaded === '1') { return Promise.resolve(detail); }
        detail.innerHTML = '<div class="ft-detail-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă anvelopele...</div>';
        var url = unitUrl + (unitUrl.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(unitEl.dataset.ftUnit);
        unitEl._ftLoading = window.fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'Răspuns invalid de la server (' + response.status + ').' }; });
            })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error(data && data.error ? data.error : 'Detaliile nu au putut fi încărcate.'); }
                detail.innerHTML = data.html;
                detail.dataset.loaded = '1';
                if (data.unit) {
                    units[data.unit.key] = data.unit;
                    drawUnit(unitEl);
                }
                if (typeof window.fleetTireInitRows === 'function') { window.fleetTireInitRows(detail); }
                if (window.fleetTireSelection) { window.fleetTireSelection.rowsReplaced(); }
                syncReturnQueries();
                return detail;
            })
            .catch(function (error) {
                detail.innerHTML = '<div class="ft-detail-error"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>' + escapeHtml(error.message) + '</span> <button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-ft-retry>Reîncearcă</button></div>';
                throw error;
            })
            .finally(function () { unitEl._ftLoading = null; });
        return unitEl._ftLoading;
    };

    var setOpen = function (unitEl, open) {
        var detail = unitEl.querySelector('[data-ft-detail]');
        var toggle = unitEl.querySelector('[data-ft-toggle]');
        unitEl.classList.toggle('is-open', open);
        detail.hidden = !open;
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if (!open) {
            if (selection.unit === unitEl.dataset.ftUnit) { clearSelection(); }
            return Promise.resolve(detail);
        }
        return loadDetail(unitEl);
    };

    var showTab = function (detail, tab) {
        detail.querySelectorAll('[data-ft-tab]').forEach(function (button) {
            var active = button.dataset.ftTab === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        detail.querySelectorAll('[data-ft-panel]').forEach(function (panel) { panel.hidden = panel.dataset.ftPanel !== tab; });
    };

    var historyFor = function (detail, tireId) {
        var script = detail.querySelector('[data-ft-history]');
        if (!script) { return []; }
        if (!script._rows) {
            try { script._rows = JSON.parse(script.textContent || '[]'); } catch (error) { script._rows = []; }
        }
        return script._rows.filter(function (row) { return row.tire_id === tireId; });
    };

    var findPosition = function (unitKey, positionId) {
        var unit = units[unitKey];
        var found = null;
        (unit ? unit.members : []).forEach(function (member) {
            member.positions.forEach(function (position) {
                if (found === null && String(position.id) === String(positionId)) { found = { member: member, position: position }; }
            });
        });
        return found;
    };

    var renderSelectedPanel = function (unitEl, detail, found) {
        var panel = detail.querySelector('[data-ft-selected]');
        if (!panel) { return; }
        if (!found) { panel.hidden = true; panel.innerHTML = ''; return; }
        var position = found.position;
        var member = found.member;
        var tire = position.tire;
        var where = escapeHtml(member.plate) + ' · Axa ' + position.axle + ' – ' + escapeHtml(position.axle_label) + ' · ' + escapeHtml(position.side_label);
        var html;
        if (!tire) {
            html = '<div class="ft-selected-head"><div><div class="ft-selected-title">' + escapeHtml(position.code) + ' · Poziție liberă<small>' + where + '</small></div>'
                + '<div class="small text-muted mt-1">' + (position.id > 0 ? 'Nicio anvelopă montată pe această poziție.' : 'Pozițiile vehiculului nu sunt sincronizate; montajul se activează după salvarea configurației din Configurație Axe.') + '</div></div>'
                + '<div class="ft-selected-actions">' + (position.id > 0 ? '<button type="button" class="btn btn-sm btn-primary" data-ft-mount="' + position.id + '"><i class="bi bi-plus-lg me-1"></i>Montează anvelopă</button>' : '')
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ft-clear aria-label="Închide"><i class="bi bi-x-lg"></i></button></div></div>';
        } else {
            var statusClass = position.status;
            var history = historyFor(detail, tire.id);
            html = '<div class="ft-selected-head"><div class="ft-selected-title">' + escapeHtml(position.code) + ' · ' + escapeHtml(tire.label) + '<small>' + where + '</small></div>'
                + '<div class="ft-selected-actions">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ftTireDetails' + tire.id + '"><i class="bi bi-eye me-1"></i>Detalii</button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ftTireEdit' + tire.id + '"><i class="bi bi-pencil me-1"></i>Editează</button>'
                + '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ftTireMove' + tire.id + '"><i class="bi bi-arrow-left-right me-1"></i>Schimbă poziția / mută</button>'
                + (document.getElementById('ftTireMountDate' + tire.id) ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ftTireMountDate' + tire.id + '"><i class="bi bi-calendar-event me-1"></i>Modifică data montării</button>' : '')
                + '<button type="button" class="btn btn-sm btn-outline-danger" data-ft-unmount="' + tire.id + '"><i class="bi bi-box-arrow-down me-1"></i>Demontează</button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ft-clear aria-label="Închide"><i class="bi bi-x-lg"></i></button>'
                + '</div></div>'
                + '<div class="ft-selected-grid">'
                + [
                    ['Status', '<span class="ft-badge ft-badge-' + statusClass + '">' + STATUS_TEXT[statusClass] + '</span>'],
                    ['Stare', escapeHtml(tire.condition || '—')],
                    ['Serie', escapeHtml(tire.serial || '—')],
                    ['Dimensiune', escapeHtml(tire.size || '—')],
                    ['Tip anvelopă', escapeHtml(tire.type || '—')],
                    ['DOT', escapeHtml(tire.dot || '—')],
                    ['Uzură', tire.wear !== null ? Math.round(tire.wear) + '%' : 'Necunoscută'],
                    ['Km parcurși', formatKm(tire.used_km)],
                    ['Km rămași', formatKm(tire.remaining_km)],
                    ['Montată la', formatDate(tire.mounted_at)],
                    ['Km SAS de la', formatDateTime(tire.km_sas_from)],
                    ['Profil', tire.tread !== null ? String(tire.tread).replace('.', ',') + ' mm' : '—']
                ].map(function (item) { return '<div><span>' + item[0] + '</span><strong>' + item[1] + '</strong></div>'; }).join('')
                + '</div>'
                + '<div class="ft-selected-history"><strong>Istoric montări</strong>'
                + (history.length === 0 ? '<div class="text-muted">Fără montări înregistrate.</div>'
                    : '<ul>' + history.slice(0, 8).map(function (row) {
                        return '<li>' + formatDate(row.start) + ' → ' + (row.end ? formatDate(row.end) : '<strong>prezent</strong>') + ' · ' + escapeHtml(row.plate || '—') + ' · ' + escapeHtml(row.position || '—')
                            + (row.km !== null ? ' · ' + formatKm(row.km) : '') + '</li>';
                    }).join('') + '</ul>')
                + '</div>';
        }
        panel.innerHTML = html;
        panel.hidden = false;
    };

    var clearSelection = function () {
        if (selection.unit) {
            var unitEl = findUnitEl(selection.unit);
            if (unitEl) {
                markSchema(unitEl, null);
                unitEl.querySelectorAll('tr.ft-tire-row.is-selected').forEach(function (row) { row.classList.remove('is-selected'); });
                var detail = unitEl.querySelector('[data-ft-detail]');
                if (detail) {
                    renderSelectedPanel(unitEl, detail, null);
                }
            }
        }
        selection = { unit: null, position: null, tire: null };
        if (/^#anvelopa-/.test(window.location.hash)) { window.history.replaceState(null, '', window.location.pathname + window.location.search); }
    };

    // Selectia: aceeasi pozitie (id) in schema si in tabel.
    var selectPosition = function (unitEl, positionId, options) {
        options = options || {};
        if (selection.unit && selection.unit !== unitEl.dataset.ftUnit) { clearSelection(); }
        selection = { unit: unitEl.dataset.ftUnit, position: String(positionId), tire: null };
        markSchema(unitEl, String(positionId));
        if (Number(positionId) > 0) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search + '#anvelopa-p' + positionId);
        }
        return setOpen(unitEl, true).then(function (detail) {
            showTab(detail, 'tires');
            var found = findPosition(unitEl.dataset.ftUnit, positionId);
            unitEl.querySelectorAll('tr.ft-tire-row').forEach(function (row) {
                var hit = row.dataset.ftPosition === String(positionId) && Number(positionId) !== 0;
                row.classList.toggle('is-selected', hit);
                if (hit) {
                    // Randul ascuns intr-o axa restransa se deschide.
                    var axleToggle = detail.querySelector('[data-ft-axle-toggle="' + row.dataset.ftAxleOf + '"]');
                    if (axleToggle && axleToggle.getAttribute('aria-expanded') === 'false') { toggleAxle(detail, axleToggle); }
                    row.classList.remove('is-flash');
                    void row.offsetWidth;
                    row.classList.add('is-flash');
                    if (options.scroll) { row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
                }
            });
            renderSelectedPanel(unitEl, detail, found);
            if (options.scroll && !options.keepSchemaInView) {
                var panel = detail.querySelector('[data-ft-selected]');
                if (panel && !panel.hidden) { panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
            }
        }).catch(function () { /* mesajul e deja in rand */ });
    };

    var toggleAxle = function (detail, button) {
        var open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        detail.querySelectorAll('tr[data-ft-axle-of="' + button.dataset.ftAxleToggle + '"]').forEach(function (row) { row.hidden = !open; });
    };

    if (listEl) {
        listEl.addEventListener('click', function (event) {
            var unitEl = event.target.closest('[data-ft-unit]');
            if (!unitEl) { return; }
            var detail = unitEl.querySelector('[data-ft-detail]');

            var tireNode = event.target.closest('.ft-svg .ft-tire, .ft-svg .ft-pill');
            if (tireNode) {
                selectPosition(unitEl, tireNode.getAttribute('data-ft-pos'), { scroll: true, keepSchemaInView: false });
                return;
            }
            if (event.target.closest('[data-ft-toggle]')) {
                setOpen(unitEl, !unitEl.classList.contains('is-open')).catch(function () {});
                return;
            }
            if (event.target.closest('[data-ft-view]')) {
                setOpen(unitEl, true).then(function (loaded) { showTab(loaded, 'tires'); }).catch(function () {});
                unitEl.scrollIntoView({ block: 'start', behavior: 'smooth' });
                return;
            }
            var openTab = event.target.closest('[data-ft-open-tab]');
            if (openTab) {
                setOpen(unitEl, true).then(function (loaded) { showTab(loaded, openTab.dataset.ftOpenTab); }).catch(function () {});
                return;
            }
            if (event.target.closest('[data-ft-retry]')) {
                detail.dataset.loaded = '';
                loadDetail(unitEl).catch(function () {});
                return;
            }
            var tab = event.target.closest('[data-ft-tab]');
            if (tab) { showTab(detail, tab.dataset.ftTab); return; }
            var axleToggle = event.target.closest('[data-ft-axle-toggle]');
            if (axleToggle) { toggleAxle(detail, axleToggle); return; }
            var mountBtn = event.target.closest('[data-ft-mount]');
            if (mountBtn) { openMount(unitEl, mountBtn.dataset.ftMount); return; }
            if (event.target.closest('[data-ft-clear]')) { clearSelection(); return; }
            var unmountBtn = event.target.closest('[data-ft-unmount]');
            if (unmountBtn) {
                // Acelasi formular de demontare din meniul randului (cu confirmarea lui).
                var row = detail.querySelector('tr.ft-tire-row[data-ft-tire="' + unmountBtn.dataset.ftUnmount + '"]');
                var submit = row ? row.querySelector('form[action*="unmount_tire"] button[type="submit"]') : null;
                if (submit) { submit.click(); }
                return;
            }
            var tireRow = event.target.closest('tr.ft-tire-row');
            if (tireRow && !event.target.closest('button, a, input, select, label, form, .dropdown-menu, .modal')) {
                if (tireRow.dataset.ftPosition !== '0') {
                    selectPosition(unitEl, tireRow.dataset.ftPosition, { scroll: false });
                    var schema = unitEl.querySelector('[data-ft-schema]');
                    var rect = schema ? schema.getBoundingClientRect() : null;
                    if (rect && (rect.bottom < 80 || rect.top > window.innerHeight)) { schema.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                }
            }
        });

        listEl.addEventListener('keydown', function (event) {
            var tireNode = event.target.closest && event.target.closest('.ft-svg .ft-tire');
            if (tireNode && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                selectPosition(tireNode.closest('[data-ft-unit]'), tireNode.getAttribute('data-ft-pos'), { scroll: true });
            }
            if (event.key === 'Escape' && selection.unit) { clearSelection(); }
        });
    }

    /* ------------------------------------------------------------------ Montaj pe pozitie libera */

    var mountModalEl = document.getElementById('ftMountModal');
    var openMount = function (unitEl, positionId) {
        if (!mountModalEl || !window.bootstrap) { return; }
        var found = findPosition(unitEl.dataset.ftUnit, positionId);
        if (!found) { return; }
        var form = mountModalEl.querySelector('[data-ft-mount-form]');
        var select = form.querySelector('select[name="tire_id"]');
        var submit = form.querySelector('[data-ft-mount-submit]');
        var hint = form.querySelector('[data-ft-mount-hint]');
        form.action = mountUrl + '#anvelopa-p' + positionId;
        form.querySelector('input[name="vehicle_id"]').value = String(found.member.vehicle_id);
        form.querySelector('input[name="position_id"]').value = String(positionId);
        form.querySelector('[data-ft-mount-target]').textContent = found.member.plate + ' · ' + found.position.code + ' (Axa ' + found.position.axle + ' – ' + found.position.axle_label + ', ' + found.position.side_label + ')';
        select.innerHTML = '<option value="">Se încarcă...</option>';
        select.disabled = true;
        submit.disabled = true;
        hint.textContent = 'Doar anvelopele compatibile cu această poziție (tip vehicul, axă, formulă).';
        syncReturnQueries();
        window.bootstrap.Modal.getOrCreateInstance(mountModalEl).show();
        var url = candidatesUrl + (candidatesUrl.indexOf('?') === -1 ? '?' : '&') + 'position_id=' + encodeURIComponent(positionId);
        window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) { throw new Error(data.error || 'Eroare la citirea stocului.'); }
                if (!data.tires.length) {
                    select.innerHTML = '<option value="">Nicio anvelopă compatibilă în stoc</option>';
                    hint.textContent = 'Adaugă o anvelopă compatibilă (butonul „Adaugă anvelopă”) sau verifică tipul de axă / compatibilitatea anvelopelor din inventar.';
                    return;
                }
                select.innerHTML = '<option value="">Alege anvelopa</option>' + data.tires.map(function (tire) {
                    return '<option value="' + tire.id + '">' + escapeHtml([tire.label, tire.size, tire.serial ? 'serie ' + tire.serial : '', tire.status].filter(Boolean).join(' · ')) + '</option>';
                }).join('');
                select.disabled = false;
            })
            .catch(function (error) {
                select.innerHTML = '<option value="">—</option>';
                hint.textContent = error.message;
            });
    };
    if (mountModalEl) {
        var mountSelect = mountModalEl.querySelector('select[name="tire_id"]');
        mountSelect.addEventListener('change', function () { mountModalEl.querySelector('[data-ft-mount-submit]').disabled = mountSelect.value === ''; });
    }

    /* ------------------------------------------------------------------ Pornire */

    setLayout(state.layout);
    unitEls.forEach(drawUnit);
    apply(true);

    // Dupa o actiune (redirect cu #anvelopa-p<id> / #vehicul-<cheie>) se revine la aceeasi anvelopa.
    var openFromHash = function () {
        var hash = window.location.hash;
        var positionMatch = /^#anvelopa-p(\d+)$/.exec(hash);
        var unitMatch = /^#vehicul-([\d-]+)$/.exec(hash);
        var targetKey = null;
        if (positionMatch) {
            Object.keys(units).forEach(function (key) { if (targetKey === null && findPosition(key, positionMatch[1])) { targetKey = key; } });
        } else if (unitMatch && units[unitMatch[1]]) {
            targetKey = unitMatch[1];
        }
        var unitEl = targetKey ? findUnitEl(targetKey) : null;
        if (!unitEl) { return; }
        // Vehiculul e pe celalalt tab (grele / usoare): se comuta tab-ul.
        var unitClass = unitEl.dataset.class || 'grele';
        if (unitClass !== state.vclass) {
            var tabButton = document.querySelector('[data-ft-mode="' + unitClass + '"]');
            if (tabButton) { tabButton.click(); }
        }
        if (unitEl.hidden) {
            state.q = ''; state.status = ''; state.kind = ''; state.fill = '';
            if (searchInput) { searchInput.value = ''; }
            if (kindSelect) { kindSelect.value = ''; }
            if (fillSelect) { fillSelect.value = ''; }
            var visibleIndex = unitEls.filter(function (el) { return (el.dataset.class || 'grele') === state.vclass; }).indexOf(unitEl);
            state.page = state.perPage > 0 ? Math.floor(visibleIndex / state.perPage) + 1 : 1;
            apply(true);
        }
        if (positionMatch) {
            selectPosition(unitEl, positionMatch[1], { scroll: true });
        } else {
            setOpen(unitEl, true).catch(function () {});
        }
        unitEl.scrollIntoView({ block: 'start' });
    };
    // Dupa DOMContentLoaded: selectia comuna (fleetTireSelection) si formularele randurilor sunt gata.
    var openFromHashOnLoad = function () {
        var vehiclePane = document.querySelector('[data-ft-pane="vehicule"]');
        if (vehiclePane && !vehiclePane.hidden) { openFromHash(); }
    };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', openFromHashOnLoad); } else { openFromHashOnLoad(); }
    window.addEventListener('hashchange', openFromHash);
})();
