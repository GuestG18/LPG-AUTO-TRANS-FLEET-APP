// Rendering benchmark of the X-ray scheme on page Auto (headless Chromium + DevTools protocol).
//   node tools/vehicle-trace/perf/bench.js <label>      -> perf/results-<label>.json
// Same steps for every configuration: load, 6 tyre selections, 8 system switches, 3 zoom in/out
// round trips on component markers, hover over 12 markers; then 3 more full cycles for memory.
// Per step: Chrome trace totals (scripting / style+layout / paint / raster / image decode), frame
// intervals (rAF), long tasks, DOM + listener counters, JS heap after forced GC.
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const LABEL = process.argv[2] || 'run';
const BASE = 'http://aplicatie_fleet.test/htdocs/index.php?page=mentenanta&action=auto';
const CONFIGS = [
    ['Cap tractor 2 axe', '&structura=18'], ['Camion 2 axe', '&structura=15'], ['Camion 3 axe', '&structura=16'],
    ['Camion 4 axe', '&structura=19'], ['Semiremorcă 2 axe', '&structura=20'], ['Semiremorcă 3 axe', '&structura=17'],
    ['Semiremorcă 4 axe', '&structura=21'], ['Semiremorcă 6 axe', '&structura=22'], ['Ansamblu B 105 NET + B 935 NET', '&vehicle_id=31'],
];
const PHP = fs.readdirSync('C:/laragon/bin/php').map((d) => 'C:/laragon/bin/php/' + d + '/php.exe').filter(fs.existsSync).pop();
const MKSESS = path.join(process.env.TEMP || 'C:/Windows/Temp', 'fleet-bench-mksess.php');
fs.writeFileSync(MKSESS, "<?php $s=['session_initialized'=>time(),'auth_user'=>['id'=>1,'nume'=>'Administrator Sistem','email'=>'admin@test.loc','rol'=>'admin']];"
    + "$o='';foreach($s as $k=>$v){$o.=$k.'|'.serialize($v);}file_put_contents('C:/laragon/tmp/sess_'.$argv[1],$o);");

const CATS = ['devtools.timeline', 'disabled-by-default-devtools.timeline', 'disabled-by-default-devtools.timeline.frame', 'v8.execute', 'blink', 'cc', 'gpu'];
const BUCKET = (name) => {
    if (/^(FunctionCall|EvaluateScript|v8\.compile|v8\.run|TimerFire|FireAnimationFrame|EventDispatch|RunMicrotasks|v8\.callFunction)$/.test(name)) { return 'scripting'; }
    if (/^(UpdateLayoutTree|Layout|RecalculateStyles|ScheduleStyleRecalculation|UpdateLayerTree|PrePaint|Layerize)$/.test(name)) { return 'styleLayout'; }
    if (/^(Paint|PaintImage|CompositeLayers|Commit)$/.test(name)) { return 'paint'; }
    if (/^(RasterTask|RasterizerTaskImpl::RunOnWorkerThread|GPUTask)$/.test(name)) { return 'raster'; }
    if (/^(Decode Image|ImageDecodeTask|Decode LazyPixelRef|ResizeImage|SVGImage::dataChanged|SVGImage::updateUseCounters)$/.test(name) || /SVGImage/.test(name)) { return 'imageDecode'; }
    if (/^(ParseHTML|ParseAuthorStyleSheet)$/.test(name)) { return 'parse'; }
    return null;
};

const summarise = (traceJson) => {
    const events = JSON.parse(traceJson).traceEvents || [];
    const out = { scripting: 0, styleLayout: 0, paint: 0, raster: 0, imageDecode: 0, parse: 0, layoutCount: 0, longestLayout: 0 };
    // only top-level slices of each bucket (nested FunctionCall inside EventDispatch would double count)
    const open = {};
    events.filter((e) => e.ph === 'X' && e.dur).sort((a, b) => a.ts - b.ts).forEach((e) => {
        const bucket = BUCKET(e.name);
        if (!bucket) { return; }
        const key = bucket + ':' + e.tid;
        if (open[key] && e.ts < open[key]) { return; }
        open[key] = e.ts + e.dur;
        out[bucket] += e.dur / 1000;
        if (e.name === 'Layout') { out.layoutCount += 1; out.longestLayout = Math.max(out.longestLayout, e.dur / 1000); }
    });
    Object.keys(out).forEach((k) => { out[k] = Math.round(out[k] * 10) / 10; });
    return out;
};

const frameStats = (intervals) => {
    if (!intervals.length) { return { frames: 0, avg: 0, worst: 0, dropped: 0, p50: 0, p95: 0, p99: 0, over33: 0, over50: 0, raw: [] }; }
    const sum = intervals.reduce((a, b) => a + b, 0);
    const dropped = intervals.reduce((n, d) => n + Math.max(0, Math.round(d / 16.67) - 1), 0);
    const s = intervals.slice().sort((a, b) => a - b);
    const q = (p) => Math.round(s[Math.min(s.length - 1, Math.floor(s.length * p))] * 10) / 10;
    return {
        frames: intervals.length, avg: Math.round(sum / intervals.length * 10) / 10, worst: Math.round(Math.max(...intervals) * 10) / 10, dropped,
        p50: q(0.5), p95: q(0.95), p99: q(0.99), over33: intervals.filter((d) => d > 33.4).length, over50: intervals.filter((d) => d > 50).length,
        raw: intervals.map((d) => Math.round(d * 10) / 10),
    };
};

(async () => {
    const sid = 'bench' + crypto.randomBytes(8).toString('hex');
    execFileSync(PHP, [MKSESS, sid]);
    const sessFile = 'C:/laragon/tmp/sess_' + sid;
    const browser = await chromium.launch({ args: ['--enable-precise-memory-info', '--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'] });
    const results = { label: LABEL, date: new Date().toISOString(), configs: [] };
    try {
        const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1 });
        await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: 'aplicatie_fleet.test', path: '/htdocs' }]);
        await ctx.addInitScript(() => {
            window.__long = [];
            try { new PerformanceObserver((list) => { list.getEntries().forEach((e) => window.__long.push(Math.round(e.duration))); }).observe({ type: 'longtask', buffered: true }); } catch (e) { /* */ }
            window.__frames = null;
            window.__startFrames = () => { window.__frames = []; let last = performance.now(); const tick = (t) => { if (!window.__frames) { return; } window.__frames.push(t - last); last = t; requestAnimationFrame(tick); }; requestAnimationFrame(tick); };
            window.__stopFrames = () => { const f = window.__frames || []; window.__frames = null; return f.slice(1); };
        });
        // BENCH_STATE=before: the pre-optimisation scheme (perf/states/before: vector SVG art, old renderer)
        // is served through request interception, and the Christmas scheme hold is switched off in memory.
        // Nothing on disk changes, so other sessions working in the app are not affected.
        if (process.env.BENCH_STATE === 'before') {
            const STATES = path.join(__dirname, 'states', 'before');
            const TYPES = { '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' };
            await ctx.route(/\/htdocs\/assets\/(js\/vehicle-xray(-art|-traced-data)?\.js|css\/vehicle-xray\.css|img\/xray-traced\/.*)(\?.*)?$/, (route) => {
                const rel = new URL(route.request().url()).pathname.replace(/^.*\/htdocs\//, '');
                const file = rel.indexOf('assets/img/xray-traced/') === 0
                    ? path.join(STATES, 'img', rel.slice('assets/img/xray-traced/'.length)) : path.join(STATES, rel);
                if (!fs.existsSync(file)) { return route.fulfill({ status: 404, body: '' }); }
                route.fulfill({ status: 200, body: fs.readFileSync(file), headers: { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' } });
            });
            await ctx.route(/\/assets\/js\/xmas-(topbar|sidebar)\.js(\?.*)?$/, (route) => {
                const name = new URL(route.request().url()).pathname.split('/').pop();
                const text = fs.readFileSync(path.join(__dirname, '..', '..', '..', 'htdocs', 'assets', 'js', name), 'utf8');
                route.fulfill({ status: 200, body: text.replace('t.closest && t.closest(".fx-explorer")', 'false'), headers: { 'content-type': 'application/javascript' } });
            });
        }
        // BENCH_STATE=phase1: the optimised version as it was before phase II, rebuilt in memory from the files
        // on disk by undoing exactly the phase II edits (forms back out of their <template>, immediate dock,
        // immediate sharp-art swap, fading part highlight). Nothing on disk changes.
        if (process.env.BENCH_STATE === 'phase1') {
            const HT = path.join(__dirname, '..', '..', '..', 'htdocs');
            const undo = (text, pairs, what) => pairs.reduce((t, [a, b]) => {
                if (t.indexOf(a) === -1) { throw new Error('phase1 undo: not found in ' + what + ': ' + a.slice(0, 70)); }
                return t.split(a).join(b);
            }, text);
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                route.fulfill({ status: 200, headers: { 'content-type': 'application/javascript' }, body: undo(text, [
                    ['this.whenSettled(function () {\n                if (self.slotId === slotId) { self.hooks.afterSlot(slot, slotId); }\n            });', 'self.hooks.afterSlot(slot, slotId);'],
                    ['var ready = function () { self.pendingArt.push([node, url]); if (!self.animating) { self.flushArt(); } };', "var ready = function () { node.setAttribute('href', url); };"],
                ], 'vehicle-xray.js') });
            });
            await ctx.route(/\/assets\/css\/vehicle-xray\.css(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'css', 'vehicle-xray.css'), 'utf8');
                route.fulfill({ status: 200, headers: { 'content-type': 'text/css' }, body: undo(text, [
                    ['.fx-trace-part .fx-trace-img { opacity: 0; transition: none; }', '.fx-trace-part .fx-trace-img { opacity: 0; }'],
                ], 'vehicle-xray.css') });
            });
            // the edit forms back in the document, as the server sent them before (intercepting the page
            // document itself breaks the explorer mount, so the templates are unwrapped on DOMContentLoaded)
            await ctx.addInitScript(() => {
                document.addEventListener('DOMContentLoaded', () => {
                    document.querySelectorAll('template[data-as-panel-template]').forEach((t) => t.replaceWith(t.content));
                }, { capture: true, once: true });
            });
        }
        // BENCH_STATE=phase2: the version as Phase II ended, rebuilt in memory by undoing exactly the Phase III
        // edits (adaptive art resolution + re-check at swap time, hidden markers skipped during camera frames).
        if (process.env.BENCH_STATE === 'phase2') {
            const HT = path.join(__dirname, '..', '..', '..', 'htdocs');
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                let text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                [
                    ['if (this.fullView && this.fullView.w / target.w > 1.4) { this.upgradeArt(target); }', 'if (this.fullView && this.fullView.w / target.w > 1.4) { this.upgradeArt(); }'],
                    ['if (self.needsHd(node, self.view)) {', 'if (true) {'],
                    ['this.placeOverlay(true);', 'this.placeOverlay();'],
                ].forEach(([a, b]) => {
                    if (text.indexOf(a) === -1) { throw new Error('phase2 undo: not found: ' + a); }
                    text = text.split(a).join(b);
                });
                route.fulfill({ status: 200, headers: { 'content-type': 'application/javascript' }, body: text });
            });
        }
        // warm-up (opcache, Apache, disk cache), not measured
        { const w = await ctx.newPage(); await w.goto(BASE + CONFIGS[0][1], { waitUntil: 'load' }); await w.waitForTimeout(1500); await w.close(); }
        // BENCH_ONLY=name1|name2: only these configurations (substring match)
        const only = process.env.BENCH_ONLY ? process.env.BENCH_ONLY.split('|') : null;
        const safeClick = async (page, target) => {
            const el = typeof target === 'string' ? await page.$(target) : target;
            if (!el) { return; }
            await el.evaluate((n) => n.scrollIntoView({ block: 'center' })).catch(() => {});
            await el.click({ force: true, timeout: 5000 }).catch(() => {});
        };
        for (const [name, query] of CONFIGS.filter(([n]) => !only || only.some((o) => n.indexOf(o) !== -1))) {
            const page = await ctx.newPage();
            const cdp = await ctx.newCDPSession(page);
            await cdp.send('Performance.enable');
            const row = { name: name, steps: {} };
            const step = async (key, fn) => {
                await page.evaluate(() => { window.__long = []; window.__startFrames(); });
                await browser.startTracing(page, { categories: CATS });
                const t0 = Date.now();
                await fn();
                const wall = Date.now() - t0;
                const trace = await browser.stopTracing();
                const frames = await page.evaluate(() => window.__stopFrames());
                const long = await page.evaluate(() => window.__long.slice());
                row.steps[key] = Object.assign({ wallMs: wall, longTasks: long.length, longestTask: long.length ? Math.max(...long) : 0 }, frameStats(frames), summarise(trace.toString()));
            };
            // 1. load: navigation until the explorer and its traced artwork are painted
            await browser.startTracing(page, { categories: CATS });
            const t0 = Date.now();
            await page.goto(BASE + query, { waitUntil: 'load' });
            const hasExplorer = await page.waitForSelector('.fx-explorer .fx-svg', { timeout: 30000 }).then(() => true, () => false);
            if (!hasExplorer) { row.error = 'no explorer (login?)'; results.configs.push(row); await page.close(); continue; }
            await page.evaluate(() => Promise.all(Array.from(document.querySelectorAll('.fx-trace-base, .fx-art-base')).map((img) => new Promise((ok) => {
                const probe = new Image(); probe.onload = probe.onerror = ok; probe.src = img.getAttribute('href') || img.getAttribute('src');
            }))).then(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)))));
            const loadWall = Date.now() - t0;
            const loadTrace = summarise((await browser.stopTracing()).toString());
            const nav = await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n ? Math.round(n.domContentLoadedEventEnd) : 0; });
            row.steps.load = Object.assign({ wallMs: loadWall, domContentLoaded: nav }, loadTrace);
            await page.addStyleTag({ content: '.fx-tip{transition:none}' });
            await page.locator('.fx-explorer').scrollIntoViewIfNeeded();
            const counters = async () => {
                const m = await cdp.send('Memory.getDOMCounters');
                const svgNodes = await page.evaluate(() => document.querySelectorAll('.fx-explorer svg *').length);
                const allNodes = await page.evaluate(() => document.querySelectorAll('.fx-explorer *').length);
                await cdp.send('HeapProfiler.collectGarbage');
                const heap = await cdp.send('Runtime.getHeapUsage');
                return { svgNodes: svgNodes, explorerNodes: allNodes, documentNodes: m.nodes, jsEventListeners: m.jsEventListeners, documents: m.documents, heapMB: Math.round(heap.usedSize / 1048576 * 10) / 10 };
            };
            row.afterLoad = await counters();

            const tyres = await page.$$('.fx-tire .fx-hit');
            await step('selectTyre', async () => {
                for (let i = 0; i < Math.min(6, tyres.length); i++) {
                    await safeClick(page, tyres[i]);
                    await page.evaluate(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok))));
                }
            });
            // selection latency with real mouse input: press -> second painted frame, on a point of the
            // tyre that no marker / label covers; mouse rests outside the scheme between samples (1.3 s)
            const lat = [];
            await page.evaluate(() => { document.addEventListener('pointerdown', () => { window.__down = performance.now(); }, true); });
            for (let i = 0; i < Math.min(6, tyres.length); i++) {
                await page.mouse.move(5, 300);
                await page.waitForTimeout(1300);
                await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
                const pt = await tyres[i].evaluate((hit) => {
                    const tyre = hit.closest('.fx-tire');
                    const r = hit.getBoundingClientRect();
                    for (const fy of [0.5, 0.35, 0.65, 0.2, 0.8]) {
                        for (const fx of [0.5, 0.25, 0.75, 0.1, 0.9]) {
                            const el = document.elementFromPoint(r.left + r.width * fx, r.top + r.height * fy);
                            if (el && el.closest('.fx-tire') === tyre) { return [r.left + r.width * fx, r.top + r.height * fy]; }
                        }
                    }
                    return null;
                });
                if (!pt) { continue; }
                await page.mouse.click(pt[0], pt[1]);
                const ms = await page.evaluate(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(() => ok(performance.now() - window.__down)))));
                lat.push(Math.round(ms * 10) / 10);
            }
            const sortedLat = lat.slice().sort((a, b) => a - b);
            row.selectionLatency = { n: lat.length, median: sortedLat.length ? sortedLat[Math.floor(sortedLat.length / 2)] : 0, avg: Math.round(lat.reduce((a, b) => a + b, 0) / (lat.length || 1) * 10) / 10, worst: Math.max(0, ...lat) };

            const cats = await page.$$eval('.fx-cat[data-fx-cat]', (n) => n.map((x) => x.dataset.fxCat).filter((c) => c !== '').slice(0, 8));
            await step('switchSystem', async () => {
                for (const c of cats) {
                    await safeClick(page, '.fx-cat[data-fx-cat="' + c + '"]');
                    await page.waitForTimeout(350);
                }
                await safeClick(page, '.fx-cat[data-fx-cat=""]');
                await page.waitForTimeout(350);
            });
            await step('zoom', async () => {
                for (let i = 0; i < 3; i++) {
                    const hots = await page.$$('.fx-hot:not(.is-hidden):not(.is-filter-only)');
                    if (!hots[i * 3]) { break; }
                    await safeClick(page, hots[i * 3]);
                    await page.waitForTimeout(900);
                    const back = await page.$('[data-fx-zoom-back]:not([hidden])');
                    if (back) { await safeClick(page, back); } else { await page.keyboard.press('Escape'); }
                    await page.waitForTimeout(900);
                }
            });
            await step('hover', async () => {
                const hots = await page.$$('.fx-hot:not(.is-hidden)');
                for (let i = 0; i < Math.min(12, hots.length); i++) {
                    const b = await hots[i].boundingBox();
                    if (b) { await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 4 }); await page.waitForTimeout(60); }
                }
                await page.mouse.move(5, 5);
            });
            row.afterInteractions = await counters();
            // memory growth: three more full cycles
            for (let k = 0; k < 3; k++) {
                for (let i = 0; i < Math.min(6, tyres.length); i++) { await safeClick(page, tyres[i]); }
                for (const c of cats) { await safeClick(page, '.fx-cat[data-fx-cat="' + c + '"]'); }
                await safeClick(page, '.fx-cat[data-fx-cat=""]');
                const hots = await page.$$('.fx-hot:not(.is-hidden):not(.is-filter-only)');
                if (hots[0]) { await safeClick(page, hots[0]); await page.waitForTimeout(600); await page.keyboard.press('Escape'); await page.waitForTimeout(600); }
            }
            row.afterCycles = await counters();
            // warm load: the same page again with every asset in the browser cache
            {
                const w0 = Date.now();
                // the configuration's own URL (a forced click may have navigated elsewhere): assets now cached
                await page.goto(BASE + query, { waitUntil: 'load' });
                await page.waitForSelector('.fx-explorer .fx-svg', { timeout: 30000 });
                await page.evaluate(() => Promise.all(Array.from(document.querySelectorAll('.fx-trace-base')).map((img) => new Promise((ok) => {
                    const probe = new Image(); probe.onload = probe.onerror = ok; probe.src = img.getAttribute('href');
                }))).then(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)))));
                row.steps.loadWarm = { wallMs: Date.now() - w0, elements: await page.evaluate(() => document.getElementsByTagName('*').length) };
            }
            row.elements = await page.evaluate(() => document.getElementsByTagName('*').length);
            results.configs.push(row);
            fs.writeFileSync(path.join(__dirname, 'results-' + LABEL + '.json'), JSON.stringify(results, null, 1));
            console.log(name, JSON.stringify({ load: row.steps.load, sel: row.selectionLatency, zoom: row.steps.zoom && { avg: row.steps.zoom.avg, worst: row.steps.zoom.worst, dropped: row.steps.zoom.dropped }, mem: [row.afterLoad.heapMB, row.afterCycles.heapMB], nodes: row.afterLoad.svgNodes }));
            await page.close();
        }
    } finally {
        await browser.close();
        try { fs.unlinkSync(sessFile); } catch (e) { /* already gone */ }
        try { fs.unlinkSync(MKSESS); } catch (e) { /* */ }
    }
    fs.writeFileSync(path.join(__dirname, 'results-' + LABEL + '.json'), JSON.stringify(results, null, 1));
})();
