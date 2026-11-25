// Phase V benchmark: radial callouts (state:phase4) vs component explorer (files on disk), assembly vehicle 31.
// Interleaved runs; each run selects systems with the real mouse and goes back to the vehicle between them.
//   node xp-bench.js [rounds=2]            real catalog, 4 systems per run
//   FIXTURE=200 node xp-bench.js [rounds]  Motor gets N synthetic components (in memory only), selected 3x per run
// Per run: elements drawn for the components, DOM size, style / layout counts + durations (CDP metrics),
// paints, invalidations, GPU-thread time and frame intervals (trace + rAF) in the 1.3 s after each click,
// time from pointerdown to the component list being in the DOM, JS heap after GC.
const { open, BASE } = require('./lib');
const fs = require('fs');
const path = require('path');
const Q = '?page=mentenanta&action=auto&vehicle_id=31';
const ROUNDS = Number(process.argv[2] || 2);
const FIXTURE = Number(process.env.FIXTURE || 0);
const CATS = ['devtools.timeline', 'disabled-by-default-devtools.timeline.invalidationTracking', 'disabled-by-default-devtools.timeline', 'blink', 'cc', 'gpu'];
const STATE = path.join(__dirname, 'states', 'phase4');
const TYPES = { '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp' };

const stats = (d) => {
    if (!d.length) { return null; }
    const s = d.slice().sort((a, b) => a - b);
    const q = (p) => Math.round(s[Math.min(s.length - 1, Math.floor(s.length * p))] * 10) / 10;
    return { frames: d.length, p50: q(0.5), p95: q(0.95), p99: q(0.99), max: q(1), dropped: d.reduce((n, x) => n + Math.max(0, Math.round(x / 16.67) - 1), 0) };
};

const run = async (variant) => {
    const o = await open({ viewport: { width: 1600, height: 1000 } });
    const { browser, ctx } = o;
    try {
        if (variant === 'phase4') {
            await ctx.route(/\/htdocs\/assets\/(js\/vehicle-xray(-art|-traced-data)?\.js|css\/vehicle-xray\.css|img\/xray-traced\/.*)(\?.*)?$/, (route) => {
                const rel = new URL(route.request().url()).pathname.replace(/^.*\/htdocs\//, '');
                const file = rel.indexOf('assets/img/xray-traced/') === 0 ? path.join(STATE, 'img', rel.slice('assets/img/xray-traced/'.length)) : path.join(STATE, rel);
                if (!fs.existsSync(file)) { return route.fulfill({ status: 404, body: '' }); }
                route.fulfill({ status: 200, body: fs.readFileSync(file), headers: { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' } });
            });
        }
        await ctx.addInitScript((n) => {
            window.__frames = [];
            const tick = (t) => { window.__frames.push(t); requestAnimationFrame(tick); };
            requestAnimationFrame(tick);
            if (!n) { return; }
            // synthetic Motor components (memory only): 8 groups, names unique, nothing is sent to the server
            new MutationObserver((records, obs) => records.forEach((r) => r.addedNodes.forEach((node) => {
                if (node.id === 'fxCategories') {
                    const cats = JSON.parse(node.textContent);
                    const motor = cats.find((c) => c.key === 'motor');
                    const comps = [];
                    for (let i = 0; i < n; i++) { comps.push({ key: 'fixture-' + i, name: 'Componentă test ' + String(i + 1).padStart(3, '0'), group: 'Grup test ' + (1 + (i % 8)) }); }
                    motor.components = comps;
                    node.textContent = JSON.stringify(cats);
                }
                if (node.tagName === 'SCRIPT' && node.type === 'application/json' && /"slots"/.test(node.textContent || '')) {
                    // the slot's own component list (configuration) would filter the fixture out
                    node.textContent = node.textContent.replace(/("category":"motor"[^{}]*?)"components":\[[^\]]*\],?/g, '$1');
                }
            }))).observe(document, { childList: true, subtree: true });
        }, FIXTURE);
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + Q, { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        const centre = () => page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center', behavior: 'instant' }));
        await centre();
        await page.mouse.move(5, 300);
        await page.waitForTimeout(2000);
        const hots = await page.$$eval('.fx-hot:not(.is-hidden):not(.is-filter-only)', (n) => n.filter((h) => {
            const r = h.getBoundingClientRect();
            const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return el && el.closest('.fx-hot') === h && !(h._slot && h._slot.part);
        }).map((h) => ({ slot: h.dataset.fxSlot, member: h.dataset.fxMember, cat: h.dataset.fxCat })));
        const motor = hots.find((h) => h.cat === 'motor');
        const picks = FIXTURE ? [motor, motor, motor] : ['franare', 'suspensie', 'rulare', 'motor'].map((c) => hots.find((h) => h.cat === c));
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Performance.enable');
        const metrics = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
        await cdp.send('HeapProfiler.collectGarbage');
        const m0 = await metrics();
        await browser.startTracing(page, { categories: CATS });
        const marks = [];
        const sel = [];
        for (const h of picks) {
            await centre();
            await page.waitForTimeout(150);
            const hb = await (await page.$('.fx-hot[data-fx-slot="' + h.slot + '"][data-fx-member="' + h.member + '"]')).boundingBox();
            await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
            const under = await page.evaluate((p) => { const e = document.elementFromPoint(p.x, p.y); const t = e && e.closest('.fx-hot'); return t ? t.dataset.fxSlot : (e ? String(e.className.baseVal !== undefined ? e.className.baseVal : e.className) : 'none'); }, { x: hb.x + hb.width / 2, y: hb.y + hb.height / 2 });
            if (under !== h.slot) { errors.push('click target ' + h.slot + ' covered by ' + under + ' at y=' + Math.round(hb.y)); }
            await page.evaluate(() => {
                window.__list = null;
                const target = () => document.querySelector('[data-fx-xp-list], [data-fx-callouts]');
                const mo = new MutationObserver(() => {
                    const t = target();
                    if (t && t.children.length && window.__list === null) { window.__list = performance.now(); requestAnimationFrame(() => { window.__listFrame = performance.now(); }); }
                });
                mo.observe(document.querySelector('.fx-explorer'), { childList: true, subtree: true });
                window.__mo = mo;
                document.addEventListener('pointerdown', () => { window.__down = performance.now(); if (!window.__sync) { window.__sync = window.__down; console.timeStamp('xp-sync'); } }, { capture: true, once: true });
            });
            await page.mouse.click(hb.x + hb.width / 2, hb.y + hb.height / 2);
            await page.waitForTimeout(1300);
            const s = await page.evaluate(() => {
                window.__mo.disconnect();
                const explorer = document.querySelector('.fx-explorer');
                return {
                    down: window.__down, list: window.__list, listFrame: window.__listFrame,
                    componentEls: explorer.querySelectorAll('.fx-callout, .fx-callout *, [data-fx-lines] line, .fx-xp *').length,
                    stageComponentEls: explorer.querySelectorAll('.fx-stage .fx-callout, .fx-stage [data-fx-lines] line').length,
                    dom: document.getElementsByTagName('*').length,
                    count: (document.querySelector('.fx-xp-meta strong') || {}).textContent || explorer.querySelectorAll('.fx-callout[data-fx-sub]').length,
                    sel: (document.querySelector('.fx-hot.is-selected') || {dataset:{}}).dataset.fxSlot, xpHidden: (document.querySelector('[data-fx-xp]') || {}).hidden, tiresMode: explorer.classList.contains('is-tires'),
                };
            });
            sel.push(s);
            marks.push(s.down);
            // back to the whole vehicle (same control in both variants)
            await page.evaluate(() => { const b = document.querySelector('[data-fx-zoom-back]:not([hidden])'); if (b) { b.click(); } });
            await page.waitForTimeout(900);
        }
        const trace = JSON.parse((await browser.stopTracing()).toString()).traceEvents;
        const m1 = await metrics();
        await cdp.send('HeapProfiler.collectGarbage');
        const heap = (await metrics()).JSHeapUsedSize;
        const frames = await page.evaluate(() => window.__frames);
        const sync = trace.find((e) => e.name === 'TimeStamp' && e.args && e.args.data && e.args.data.message === 'xp-sync');
        const syncPerf = await page.evaluate(() => window.__sync);
        const tsOf = (ms) => (sync ? sync.ts : 0) + (ms - syncPerf) * 1000;
        const inWin = (ts) => marks.some((m) => ts >= tsOf(m) && ts <= tsOf(m + 1300));
        const tid = {};
        trace.filter((e) => e.ph === 'M' && e.name === 'thread_name').forEach((e) => { tid[e.pid + ':' + e.tid] = e.args.name; });
        const gpuMs = trace.filter((e) => e.ph === 'X' && e.dur && /CrGpuMain|VizCompositor/.test(tid[e.pid + ':' + e.tid] || '') && inWin(e.ts) && !trace.some(() => false))
            .reduce((n, e) => n + e.dur / 1000, 0);
        const count = (re) => trace.filter((e) => re.test(e.name) && inWin(e.ts)).length;
        const d = [];
        marks.forEach((m) => { const f = frames.filter((t) => t >= m && t <= m + 1300); for (let i = 1; i < f.length; i++) { d.push(f[i] - f[i - 1]); } });
        const avg = (k) => Math.round(sel.reduce((n, s) => n + s[k], 0) / sel.length);
        return {
            variant, errors,
            components: sel.map((s) => s.count).join('/'), picks: picks.map((p) => p.slot + '>' ), dbg: sel.map((s) => [s.sel, s.xpHidden, s.tiresMode]),
            componentEls: avg('componentEls'), stageComponentEls: avg('stageComponentEls'), dom: Math.max(...sel.map((s) => s.dom)),
            listMs: sel.map((s) => (s.list ? Math.round(s.list - s.down) : null)), listPaintMs: sel.map((s) => (s.listFrame ? Math.round(s.listFrame - s.down) : null)),
            styleRecalcs: m1.RecalcStyleCount - m0.RecalcStyleCount, layouts: m1.LayoutCount - m0.LayoutCount,
            styleMs: Math.round((m1.RecalcStyleDuration - m0.RecalcStyleDuration) * 1000), layoutMs: Math.round((m1.LayoutDuration - m0.LayoutDuration) * 1000),
            scriptMs: Math.round((m1.ScriptDuration - m0.ScriptDuration) * 1000), taskMs: Math.round((m1.TaskDuration - m0.TaskDuration) * 1000),
            paints: count(/^Paint$/), invalidations: count(/StyleRecalcInvalidationTracking|StyleInvalidatorInvalidationTracking|LayoutInvalidationTracking|PaintInvalidationTracking/),
            gpuMs: Math.round(gpuMs), frames: stats(d), heapMB: Math.round(heap / 1048576 * 10) / 10,
        };
    } finally {
        await o.close();
    }
};

(async () => {
    const results = [];
    const file = path.join(__dirname, 'xp-bench' + (FIXTURE ? '-' + FIXTURE : '') + '.json');
    for (let r = 0; r < ROUNDS; r++) {
        for (const v of (process.env.ONLY ? [process.env.ONLY] : (r % 2 ? ['current', 'phase4'] : ['phase4', 'current']))) {
            const res = await run(v);
            results.push(res);
            fs.writeFileSync(file, JSON.stringify(results, null, 1));
            console.log(JSON.stringify(res));
        }
    }
})();
