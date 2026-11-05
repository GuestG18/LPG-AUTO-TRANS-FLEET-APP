/*
 * Motorul din exploratorul X-ray (vedere de sus, fata motorului la stanga), ca desen mecanic:
 * bloc, chiulasa, capac de culbutori pe cilindri, galerii de admisie / evacuare, turbo, curea
 * cu fulii in fata, alternator, filtre, compresor de aer, demaror, clopot spre cutia de viteze.
 *
 * Doar desenul, in coordonate locale (centimetri): x = 0..length de la fata motorului spre
 * cutie, y = 0..width, evacuarea sus (y mic). Fara evenimente si fara filtre (fara blur);
 * zona de click si marcajul portocaliu raman in vehicle-xray-art.js / vehicle-xray.js.
 * Folosit si in browser (window.FleetXrayEngine), si din Node pentru activul .svg de sine statator.
 */
(function (root) {
    'use strict';

    var n = function (v) { return (Math.round(v * 10) / 10).toString(); };
    var attrs = function (a) {
        return Object.keys(a || {}).map(function (k) { return a[k] === undefined || a[k] === null ? '' : ' ' + k + '="' + a[k] + '"'; }).join('');
    };
    var rect = function (x0, y0, x1, y1, a) {
        return '<rect x="' + n(Math.min(x0, x1)) + '" y="' + n(Math.min(y0, y1)) + '" width="' + n(Math.abs(x1 - x0)) + '" height="' + n(Math.abs(y1 - y0)) + '"' + attrs(a) + '/>';
    };
    var circle = function (cx, cy, r, a) { return '<circle cx="' + n(cx) + '" cy="' + n(cy) + '" r="' + n(r) + '"' + attrs(a) + '/>'; };
    var ellipse = function (cx, cy, rx, ry, a) { return '<ellipse cx="' + n(cx) + '" cy="' + n(cy) + '" rx="' + n(rx) + '" ry="' + n(ry) + '"' + attrs(a) + '/>'; };
    var line = function (x0, y0, x1, y1, a) { return '<line x1="' + n(x0) + '" y1="' + n(y0) + '" x2="' + n(x1) + '" y2="' + n(y1) + '"' + attrs(a) + '/>'; };
    var path = function (d, a) { return '<path d="' + d + '"' + attrs(a) + '/>'; };
    var P = function (x, y) { return n(x) + ' ' + n(y); };

    // Paleta: grafit (bloc), otel-albastru (chiulasa), gri metalic (capac, aluminiu), fonta (galerii).
    var INK = '#1d232c';
    var EDGE = '#2c3644';
    var SHINE = '#f3f6f9';

    var defs = function () {
        var lin = function (id, stops, horizontal) {
            return '<linearGradient id="' + id + '" x1="0" y1="0" x2="' + (horizontal ? 1 : 0) + '" y2="' + (horizontal ? 0 : 1) + '">'
                + stops.map(function (s) { return '<stop offset="' + s[0] + '" stop-color="' + s[1] + '"/>'; }).join('') + '</linearGradient>';
        };
        var rad = function (id, stops) {
            return '<radialGradient id="' + id + '" cx="38%" cy="34%" r="70%">'
                + stops.map(function (s) { return '<stop offset="' + s[0] + '" stop-color="' + s[1] + '"/>'; }).join('') + '</radialGradient>';
        };
        return [
            lin('fxEBlock', [[0, '#4a5462'], [0.45, '#2a313c'], [1, '#3d4654']]),
            lin('fxEHead', [[0, '#7d97b3'], [0.5, '#4f6886'], [1, '#6a84a1']]),
            lin('fxECover', [[0, '#f1f4f8'], [0.32, '#cdd5df'], [0.7, '#a3afbe'], [1, '#c5ced9']]),
            lin('fxEBoss', [[0, '#fafcfd'], [1, '#b4bfcc']]),
            lin('fxECast', [[0, '#7b8592'], [0.5, '#4d5663'], [1, '#68727f']]),
            lin('fxECastX', [[0, '#7b8592'], [0.5, '#4d5663'], [1, '#68727f']], true),
            lin('fxEAlu', [[0, '#e6ebf1'], [0.5, '#a9b5c3'], [1, '#d3dae3']]),
            lin('fxEAluX', [[0, '#e6ebf1'], [0.5, '#a9b5c3'], [1, '#d3dae3']], true),
            lin('fxEPulley', [[0, '#c9d1db'], [0.5, '#6f7b89'], [1, '#aab4c1']], true),
            lin('fxEBell', [[0, '#8592a2'], [0.5, '#56616f'], [1, '#778392']]),
            rad('fxETurbine', [[0, '#8b939d'], [0.7, '#4a525d'], [1, '#353c46']]),
            rad('fxECompressor', [[0, '#f4f6f9'], [0.65, '#b9c3cf'], [1, '#8c99a9']]),
            rad('fxEFilter', [[0, '#9fb6cf'], [0.7, '#4e6b8c'], [1, '#3a536f']])
        ].join('');
    };

    /*
     * opts: length, width (cm), cylinders (4 / 6), aircomp (compresor de aer, camioane),
     *       outputHalf (jumatatea latimii cutiei de viteze in cm, unde se termina clopotul).
     */
    var engine = function (opts) {
        var L = opts.length;
        var H = opts.width;
        var cyl = opts.cylinders || 6;
        var yc = H / 2;
        var out = [];
        var push = function (s) { out.push(s); };

        var fb = L * 0.12; // zona curelei (fata)
        var bx0 = fb - 1;
        var bx1 = L * 0.9; // blocul
        var hx0 = fb + 3;
        var hx1 = L * 0.875; // chiulasa
        var bY = H * 0.355;
        var hY = H * 0.235;
        var cY = H * 0.165;
        var cx0 = hx0 + 2.5;
        var cx1 = hx1 - 2.5; // capacul
        var p = (cx1 - cx0) / cyl;
        var mid = function (k) { return cx0 + p * (k + 0.5); };
        var bolt = function (x, y, r) { return circle(x, y, r, { fill: '#cbd3dc', stroke: EDGE, 'stroke-width': 0.35 }); };

        /* --- accesorii pe flancul admisiei (jos), sub galerie: filtre, compresor de aer, demaror --- */
        var accY = yc + H * 0.455;
        [L * 0.33, L * 0.43].forEach(function (x, i) {
            push(circle(x, accY, H * 0.052, { fill: 'url(#fxEFilter)', stroke: INK, 'stroke-width': 0.6 }));
            push(circle(x, accY, H * 0.022, { fill: i ? '#c9d3de' : '#e3e8ee', stroke: EDGE, 'stroke-width': 0.4 }));
        });
        if (opts.aircomp) {
            var ac0 = L * 0.565;
            var ac1 = L * 0.715;
            push(rect(ac0, yc + H * 0.395, ac1, yc + H * 0.515, { fill: 'url(#fxEAlu)', stroke: INK, 'stroke-width': 0.6, rx: 2 }));
            for (var f = 1; f < 5; f++) {
                var fx = ac0 + (ac1 - ac0) * (0.35 + f * 0.12);
                push(line(fx, yc + H * 0.405, fx, yc + H * 0.505, { stroke: '#7a8696', 'stroke-width': 0.5 }));
            }
            push(rect(ac0 + 1.5, yc + H * 0.42, ac0 + (ac1 - ac0) * 0.36, yc + H * 0.49, { fill: 'url(#fxECast)', stroke: INK, 'stroke-width': 0.4, rx: 1.2 }));
        }
        var st0 = L * 0.79;
        var st1 = L * 0.915;
        push(rect(st0, yc + H * 0.39, st1, yc + H * 0.49, { fill: 'url(#fxEAlu)', stroke: INK, 'stroke-width': 0.6, rx: 3 }));
        push(rect(st0 + (st1 - st0) * 0.3, yc + H * 0.455, st0 + (st1 - st0) * 0.85, yc + H * 0.515, { fill: 'url(#fxEBoss)', stroke: INK, 'stroke-width': 0.5, rx: 2.2 }));
        push(line(st0 + (st1 - st0) * 0.22, yc + H * 0.395, st0 + (st1 - st0) * 0.22, yc + H * 0.485, { stroke: '#6d7a8a', 'stroke-width': 0.5 }));

        /* --- clopotul spre cutia de viteze (spate) --- */
        var fl0 = L * 0.895;
        var fl1 = L * 0.93;
        var outHalf = Math.min(opts.outputHalf || H * 0.32, H * 0.36);
        push(path('M' + P(fl1, yc - H * 0.41) + ' L' + P(L, yc - outHalf) + ' L' + P(L, yc + outHalf) + ' L' + P(fl1, yc + H * 0.41) + ' Z',
            { fill: 'url(#fxEBell)', stroke: INK, 'stroke-width': 0.7, 'stroke-linejoin': 'round' }));
        [-0.6, 0, 0.6].forEach(function (k) {
            push(line(fl1 + 0.8, yc + k * H * 0.38, L - 0.8, yc + k * outHalf * 0.92, { stroke: '#8995a4', 'stroke-width': 0.6 }));
        });
        push(rect(fl0, yc - H * 0.44, fl1, yc + H * 0.44, { fill: 'url(#fxECastX)', stroke: INK, 'stroke-width': 0.7, rx: 1.6 }));
        for (var b = 0; b < 7; b++) {
            push(bolt((fl0 + fl1) / 2, yc - H * 0.39 + b * (H * 0.78 / 6), 0.8));
        }

        /* --- blocul motor (carterul vazut pe langa chiulasa), cu nervuri pe cilindri --- */
        push(rect(bx0, yc - bY, bx1, yc + bY, { fill: 'url(#fxEBlock)', stroke: INK, 'stroke-width': 0.8, rx: 3.5 }));
        for (var i = 0; i <= cyl; i++) {
            var rx = cx0 + i * p;
            [-1, 1].forEach(function (s) {
                push(line(rx, yc + s * (hY + 0.5), rx, yc + s * (bY - 1), { stroke: '#5d6877', 'stroke-width': 0.9 }));
            });
        }
        // Suporti de motor (fata), pe flancuri.
        [-1, 1].forEach(function (s) {
            push(rect(bx0 + 2, yc + s * (bY - 1), bx0 + 8, yc + s * (bY + 3.2), { fill: 'url(#fxECast)', stroke: INK, 'stroke-width': 0.5, rx: 0.8 }));
            push(circle(bx0 + 5, yc + s * (bY + 1.5), 0.9, { fill: '#cbd3dc' }));
        });

        /* --- fata motorului: capacul distributiei, fuliile si cureaua --- */
        push(rect(fb - 4, yc - H * 0.33, fb + 1.5, yc + H * 0.33, { fill: 'url(#fxECastX)', stroke: INK, 'stroke-width': 0.7, rx: 1.5 }));
        // Alternatorul (admisie, fata), cu aripioare de racire si fulia lui.
        var al0 = fb - 3;
        var al1 = fb + 10;
        push(rect(al0, yc + H * 0.385, al1, yc + H * 0.51, { fill: 'url(#fxEAlu)', stroke: INK, 'stroke-width': 0.6, rx: 3 }));
        for (var a = 1; a < 5; a++) {
            push(line(al0 + (al1 - al0) * a / 5, yc + H * 0.395, al0 + (al1 - al0) * a / 5, yc + H * 0.5, { stroke: '#7b8797', 'stroke-width': 0.5 }));
        }
        var pulleys = [
            { x: fb * 0.52, y: yc - H * 0.12, ry: H * 0.085, rx: fb * 0.19 }, // pompa de apa / ventilator
            { x: fb * 0.6, y: yc + H * 0.16, ry: H * 0.04, rx: fb * 0.13 }, // intinzator
            { x: fb * 0.48, y: yc + H * 0.06, ry: H * 0.14, rx: fb * 0.22 }, // arborele cotit (amortizor)
            { x: fb * 0.55, y: yc + H * 0.44, ry: H * 0.055, rx: fb * 0.15 } // alternator
        ];
        var crank = pulleys[2];
        var wp = pulleys[0];
        var alt = pulleys[3];
        // Cureaua: bucla in jurul fuliilor (fata, apoi inapoi pe spate).
        push(path('M' + P(wp.x, wp.y - wp.ry) + ' L' + P(wp.x - wp.rx, wp.y) + ' L' + P(crank.x - crank.rx, crank.y) + ' L' + P(alt.x - alt.rx, alt.y)
            + ' L' + P(alt.x, alt.y + alt.ry) + ' L' + P(alt.x + alt.rx, alt.y) + ' L' + P(pulleys[1].x + pulleys[1].rx, pulleys[1].y)
            + ' L' + P(crank.x + crank.rx * 0.6, crank.y - crank.ry * 0.7) + ' L' + P(wp.x + wp.rx, wp.y) + ' Z',
            { fill: 'none', stroke: '#161b22', 'stroke-width': 1.6, 'stroke-linejoin': 'round' }));
        pulleys.forEach(function (pl, k) {
            push(ellipse(pl.x, pl.y, pl.rx, pl.ry, { fill: 'url(#fxEPulley)', stroke: INK, 'stroke-width': 0.6 }));
            push(ellipse(pl.x, pl.y, pl.rx * 0.55, pl.ry * 0.62, { fill: 'none', stroke: '#4a5563', 'stroke-width': 0.5 }));
            push(ellipse(pl.x, pl.y, pl.rx * 0.3, pl.ry * 0.22, { fill: k === 2 ? '#2a313b' : '#3a4350' }));
        });
        // Furtunul superior de racire (termostat -> radiator).
        var th0 = hx0 - 4;
        push(path('M' + P(th0 + 2, yc - H * 0.04) + ' L' + P(-1, yc - H * 0.04), { stroke: '#232931', 'stroke-width': H * 0.055, 'stroke-linecap': 'round', fill: 'none' }));
        push(path('M' + P(th0 + 2, yc - H * 0.056) + ' L' + P(0, yc - H * 0.056), { stroke: '#4d5663', 'stroke-width': 0.6, fill: 'none' }));

        /* --- galeria de admisie (jos): canale pe cilindri + colector + intrarea aerului de supraalimentare --- */
        var pl0 = fb + 12;
        var pl1 = hx1 - 3;
        var plY0 = yc + H * 0.285;
        var plY1 = yc + H * 0.385;
        // Conducta de aer racit (intercooler -> admisie), in fata.
        push(path('M' + P(pl0 + 2, (plY0 + plY1) / 2) + ' L' + P(-1, (plY0 + plY1) / 2), { stroke: '#7f8da0', 'stroke-width': H * 0.07, 'stroke-linecap': 'round', fill: 'none' }));
        push(path('M' + P(pl0, plY0 + H * 0.022) + ' L' + P(0, plY0 + H * 0.022), { stroke: '#dfe5ec', 'stroke-width': 0.9, fill: 'none' }));
        push(rect(pl0 - 2, plY0 - 1.5, pl0 + 1, plY1 + 1.5, { fill: 'url(#fxECastX)', stroke: INK, 'stroke-width': 0.5, rx: 0.8 }));
        for (var k = 0; k < cyl; k++) {
            push(rect(mid(k) - p * 0.26, yc + hY - 1, mid(k) + p * 0.26, plY0 + 1, { fill: 'url(#fxEAluX)', stroke: INK, 'stroke-width': 0.5, rx: 1 }));
        }
        push(rect(pl0, plY0, pl1, plY1, { fill: 'url(#fxEAlu)', stroke: INK, 'stroke-width': 0.7, rx: (plY1 - plY0) / 2 }));
        push(line(pl0 + 2, plY0 + 1.6, pl1 - 2, plY0 + 1.6, { stroke: SHINE, 'stroke-width': 0.8, 'stroke-linecap': 'round' }));
        for (var k2 = 0; k2 <= cyl; k2++) { push(bolt(cx0 + k2 * p, yc + hY - 0.2, 0.7)); }

        /* --- galeria de evacuare (sus): canale de fonta + doua colectoare cu imbinare culisanta --- */
        var exY0 = yc - H * 0.4;
        var exY1 = yc - H * 0.305;
        for (var e = 0; e < cyl; e++) {
            push(path('M' + P(mid(e) - p * 0.3, yc - hY + 1) + ' L' + P(mid(e) - p * 0.22, exY1 - 1) + ' L' + P(mid(e) + p * 0.22, exY1 - 1) + ' L' + P(mid(e) + p * 0.3, yc - hY + 1) + ' Z',
                { fill: 'url(#fxECast)', stroke: INK, 'stroke-width': 0.5, 'stroke-linejoin': 'round' }));
        }
        var half = Math.floor(cyl / 2);
        var joint = cx0 + p * half;
        push(rect(cx0 + p * 0.15, exY0, joint - 0.8, exY1, { fill: 'url(#fxECast)', stroke: INK, 'stroke-width': 0.7, rx: (exY1 - exY0) / 2 }));
        push(rect(joint + 0.8, exY0, cx1 - p * 0.15, exY1, { fill: 'url(#fxECast)', stroke: INK, 'stroke-width': 0.7, rx: (exY1 - exY0) / 2 }));
        push(rect(joint - 2, exY0 - 0.8, joint + 2, exY1 + 0.8, { fill: 'url(#fxECastX)', stroke: INK, 'stroke-width': 0.5, rx: 0.8 }));
        for (var e2 = 0; e2 < cyl; e2++) {
            push(line(mid(e2) - p * 0.18, exY0 + 1.2, mid(e2) + p * 0.18, exY0 + 1.2, { stroke: '#8d97a3', 'stroke-width': 0.6, 'stroke-linecap': 'round' }));
        }

        /* --- turbocompresorul: carcasa turbinei (fonta) + compresorul (aluminiu) + conducta spre intercooler --- */
        var tx = joint;
        var ty = yc - H * 0.45;
        var tr = H * 0.075;
        var crr = H * 0.085;
        var cmpX = tx - tr - crr * 0.75;
        var turX = tx + tr * 0.55;
        // Conducta de aer comprimat, de la compresor in fata, spre intercooler.
        push(path('M' + P(cmpX - crr * 0.5, ty) + ' L' + P(-1, ty), { stroke: '#7f8da0', 'stroke-width': H * 0.058, 'stroke-linecap': 'round', fill: 'none' }));
        push(path('M' + P(cmpX - crr, ty - H * 0.018) + ' L' + P(0, ty - H * 0.018), { stroke: '#dfe5ec', 'stroke-width': 0.8, fill: 'none' }));
        // Iesirea turbinei (spre toba), in afara motorului.
        push(rect(turX - tr * 0.45, ty - tr - 4, turX + tr * 0.45, ty - tr * 0.4, { fill: 'url(#fxECastX)', stroke: INK, 'stroke-width': 0.5, rx: 1 }));
        // Gatul turbinei pe colector.
        push(rect(turX - tr * 0.5, ty, turX + tr * 0.5, exY0 + 1, { fill: 'url(#fxECastX)', stroke: INK, 'stroke-width': 0.5 }));
        push(circle(turX, ty, tr, { fill: 'url(#fxETurbine)', stroke: INK, 'stroke-width': 0.7 }));
        push(path('M' + P(turX + tr * 0.62, ty) + ' A' + n(tr * 0.62) + ' ' + n(tr * 0.62) + ' 0 1 1 ' + P(turX, ty - tr * 0.62) + ' A' + n(tr * 0.4) + ' ' + n(tr * 0.4) + ' 0 0 1 ' + P(turX + tr * 0.3, ty),
            { fill: 'none', stroke: '#9aa3ad', 'stroke-width': 0.6 }));
        push(rect(cmpX + crr * 0.6, ty - tr * 0.4, turX - tr * 0.75, ty + tr * 0.4, { fill: 'url(#fxEAlu)', stroke: INK, 'stroke-width': 0.5, rx: 0.6 }));
        push(circle(cmpX, ty, crr, { fill: 'url(#fxECompressor)', stroke: INK, 'stroke-width': 0.7 }));
        push(path('M' + P(cmpX - crr * 0.68, ty) + ' A' + n(crr * 0.68) + ' ' + n(crr * 0.68) + ' 0 1 0 ' + P(cmpX, ty - crr * 0.68) + ' A' + n(crr * 0.42) + ' ' + n(crr * 0.42) + ' 0 0 0 ' + P(cmpX - crr * 0.3, ty),
            { fill: 'none', stroke: '#6f7b8a', 'stroke-width': 0.6 }));
        push(circle(cmpX, ty, crr * 0.26, { fill: '#2b323c' }));

        /* --- chiulasa (otel-albastru) cu suruburile de prindere --- */
        push(rect(hx0, yc - hY, hx1, yc + hY, { fill: 'url(#fxEHead)', stroke: EDGE, 'stroke-width': 0.8, rx: 2.5 }));
        push(line(hx0 + 2, yc - hY + 0.9, hx1 - 2, yc - hY + 0.9, { stroke: '#a9bdd2', 'stroke-width': 0.7 }));
        for (var hb = 0; hb <= cyl; hb++) {
            [-1, 1].forEach(function (s) { push(bolt(cx0 + hb * p, yc + s * (hY - 2.6), 0.95)); });
        }
        // Carcasa termostatului / pompei de apa pe fata chiulasei.
        push(rect(th0, yc - H * 0.11, hx0 + 4, yc + H * 0.01, { fill: 'url(#fxEAlu)', stroke: INK, 'stroke-width': 0.6, rx: 1.5 }));
        push(bolt(th0 + 2, yc - H * 0.08, 0.6));
        push(bolt(th0 + 2, yc - H * 0.02, 0.6));

        /* --- capacul de culbutori: segmente pe cilindri, bose pe injectoare, suruburi pe margine --- */
        push(rect(cx0, yc - cY, cx1, yc + cY, { fill: 'url(#fxECover)', stroke: '#46525f', 'stroke-width': 0.8, rx: 4 }));
        push(rect(cx0 + 2.5, yc - cY + 1, cx1 - 2.5, yc - cY + 2.2, { fill: '#ffffff', opacity: 0.55, rx: 0.6 }));
        for (var sgi = 1; sgi < cyl; sgi++) {
            var sx = cx0 + sgi * p;
            push(line(sx, yc - cY + 2.6, sx, yc + cY - 2.6, { stroke: '#7b8898', 'stroke-width': 0.6 }));
            push(line(sx + 0.7, yc - cY + 2.6, sx + 0.7, yc + cY - 2.6, { stroke: SHINE, 'stroke-width': 0.5 }));
        }
        for (var cb = 0; cb < cyl; cb++) {
            var mx = mid(cb);
            push(rect(mx - p * 0.27, yc - H * 0.075, mx + p * 0.27, yc + H * 0.075, { fill: 'url(#fxEBoss)', stroke: '#5f6c7b', 'stroke-width': 0.55, rx: 1.6 }));
            push(circle(mx, yc, H * 0.022, { fill: '#56626f' }));
            [-1, 1].forEach(function (s) { push(circle(mx, yc + s * (cY - 1.5), 0.75, { fill: '#5a6676' })); });
        }
        // Gura de umplere ulei (fata) si aerisirea carterului (spate).
        push(circle(cx0 + p * 0.5, yc - cY * 0.62, H * 0.03, { fill: '#323a45', stroke: INK, 'stroke-width': 0.4 }));
        push(rect(cx1 - p * 0.7, yc + cY * 0.5, cx1 - p * 0.25, yc + cY * 0.85, { fill: 'url(#fxECast)', stroke: INK, 'stroke-width': 0.4, rx: 0.8 }));

        return '<g class="fx-eng">' + out.join('') + '</g>';
    };

    var api = { defs: defs, engine: engine };
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.FleetXrayEngine = api; }
})(typeof window !== 'undefined' ? window : null);
