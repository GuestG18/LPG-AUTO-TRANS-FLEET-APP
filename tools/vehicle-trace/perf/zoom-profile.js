// Zoom spikes on one configuration: per-zoom frame intervals (rAF) + what ran during each frame > 33 ms
// (Chrome trace, main thread + GPU + image decode). Zoom 1 is cold (sharp @2 art not yet loaded).
//   node zoom-profile.js [query] [variant]
// variant "nohd" disables the @2 swap in memory (vehicle-xray.js served patched), "asis" = files on disk.
const { open, BASE } = require('./lib');
const fs = require('fs');
const path = require('path');
const QUERY = process.argv[2] || '?page=mentenanta&action=auto&vehicle_id=31';
const VARIANT = process.argv[3] || 'asis';
const HT = path.join(__dirname, '..', '..', '..', 'htdocs');
const CATS = ['devtools.timeline', 'disabled-by-default-devtools.timeline.invalidationTracking', 'disabled-by-default-devtools.timeline', 'disabled-by-default-devtools.timeline.frame', 'blink', 'cc', 'gpu', 'v8.execute'];

(async () => {
    const { browser, ctx, close } = await open();
    try {
        if (VARIANT === 'oldswap') {
            // the previous behaviour: swap the sharp image as soon as it is decoded, even mid-animation
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                const now = 'var ready = function () { self.pendingArt.push([node, url]); if (!self.animating) { self.flushArt(); } };';
                if (text.indexOf(now) === -1) { throw new Error('ready() not found'); }
                const old = "var ready = function () { node.setAttribute('href', url); };";
                route.fulfill({ status: 200, body: text.replace(now, old), headers: { 'content-type': 'application/javascript' } });
            });
        }
        if (VARIANT === 'olddock') {
            // the previous behaviour: the host page docks the system section (and scrolls) in the zoom click
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                const now = 'this.whenSettled(function () {\n                if (self.slotId === slotId) { self.hooks.afterSlot(slot, slotId); }\n            });';
                if (text.indexOf(now) === -1) { throw new Error('whenSettled block not found'); }
                route.fulfill({ status: 200, body: text.replace(now, 'self.hooks.afterSlot(slot, slotId);'), headers: { 'content-type': 'application/javascript' } });
            });
        }
        if (VARIANT === 'tmplayer') {
            // the previous behaviour: the camera layer is created only while a zoom animates
            await ctx.route(/\/assets\/css\/vehicle-xray\.css(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'css', 'vehicle-xray.css'), 'utf8');
                const rule = '.fx-svg-host { will-change: transform; transform-origin: 0 0; }';
                if (text.indexOf(rule) === -1) { throw new Error('permanent layer rule not found'); }
                route.fulfill({ status: 200, body: text.replace(rule, ''), headers: { 'content-type': 'text/css' } });
            });
        }
        if (VARIANT === 'fadehl') {
            // the previous behaviour: highlight image fades in (opacity + filter transition, 300 ms)
            await ctx.route(/\/assets\/css\/vehicle-xray\.css(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'css', 'vehicle-xray.css'), 'utf8');
                const rule = '.fx-trace-part .fx-trace-img { opacity: 0; transition: none; }';
                if (text.indexOf(rule) === -1) { throw new Error('highlight rule not found'); }
                route.fulfill({ status: 200, body: text.replace(rule, '.fx-trace-part .fx-trace-img { opacity: 0; }'), headers: { 'content-type': 'text/css' } });
            });
        }
        if (VARIANT === 'condtest') {
            // stage C with every traced system coloured (test values injected in memory): the cost of the fills
            await ctx.addInitScript(() => {
                const values = { franare: 15, suspensie: 50, motor: 82, racire: 100, transmisie: 35, diferential: 65, rulare: 90, directie: 20, pneumatica: 45, electrica: 70, evacuare: 55, alte: 30, rezervor_combustibil: 40 };
                new MutationObserver((records) => records.forEach((r) => r.addedNodes.forEach((n) => {
                    if (n.tagName === 'SCRIPT' && n.type === 'application/json' && /"members"/.test(n.textContent || '')) {
                        const parsed = JSON.parse(n.textContent);
                        const unit = Array.isArray(parsed) ? parsed[0] : (parsed.unit || parsed);
                        unit.members.forEach((m) => { m.condition = {}; Object.keys(values).forEach((k) => { m.condition[k] = { wear: values[k], components: 1 }; }); });
                        n.textContent = JSON.stringify(parsed);
                    }
                }))).observe(document, { childList: true, subtree: true });
            });
        }
        if (VARIANT === 'nomovinglayer') {
            // stage B without the temporary marker layer (translate offsets only)
            await ctx.route(/\/assets\/css\/vehicle-xray\.css(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'css', 'vehicle-xray.css'), 'utf8');
                const rule = '.fx-stage.is-moving .fx-hots { will-change: transform; }';
                if (text.indexOf(rule) === -1) { throw new Error('moving-layer rule not found'); }
                route.fulfill({ status: 200, body: text.replace(rule, ''), headers: { 'content-type': 'text/css' } });
            });
        }
        if (/^state:/.test(VARIANT)) {
            // a frozen snapshot (perf/states/<name>): scheme JS + CSS + data + art served from memory
            const STATE = path.join(__dirname, 'states', VARIANT.slice(6));
            const TYPES = { '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp' };
            await ctx.route(/\/htdocs\/assets\/(js\/vehicle-xray(-art|-traced-data)?\.js|css\/vehicle-xray\.css|img\/xray-traced\/.*)(\?.*)?$/, (route) => {
                const rel = new URL(route.request().url()).pathname.replace(/^.*\/htdocs\//, '');
                const file = rel.indexOf('assets/img/xray-traced/') === 0 ? path.join(STATE, 'img', rel.slice('assets/img/xray-traced/'.length)) : path.join(STATE, rel);
                if (!fs.existsSync(file)) { return route.fulfill({ status: 404, body: '' }); }
                route.fulfill({ status: 200, body: fs.readFileSync(file), headers: { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' } });
            });
        }
        if (VARIANT === 'camquiet' || VARIANT === 'camquiet-css') {
            // candidate: while the camera moves, no CSS transitions inside the camera layer (they repaint it every
            // frame) and, for 'camquiet', only visible markers are repositioned per frame
            await ctx.route(/\/assets\/css\/vehicle-xray\.css(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'css', 'vehicle-xray.css'), 'utf8');
                route.fulfill({ status: 200, body: text + '\n.fx-svg-host.is-camera * { transition: none !important; }\n', headers: { 'content-type': 'text/css' } });
            });
            if (VARIANT === 'camquiet') {
                await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                    const text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                    const frame = "        this.svgHost.style.transform = 'translate(' + tx.toFixed(2) + 'px,' + ty.toFixed(2) + 'px) scale(' + k.toFixed(5) + ')';\n        this.placeOverlay();";
                    if (text.indexOf(frame) === -1) { throw new Error('cameraFrame not found'); }
                    route.fulfill({ status: 200, body: text.replace(frame, "        this.svgHost.style.transform = 'translate(' + tx.toFixed(2) + 'px,' + ty.toFixed(2) + 'px) scale(' + k.toFixed(5) + ')';\n        this.placeOverlay(true);")
                        .replace("    Explorer.prototype.placeOverlay = function () {\n        var view = this.shown || this.view;\n        this.anchors.forEach(function (anchor) {", "    Explorer.prototype.placeOverlay = function (visibleOnly) {\n        var view = this.shown || this.view;\n        this.anchors.forEach(function (anchor) {\n            if (visibleOnly && anchor.node.classList.contains('is-hidden')) { return; }"), headers: { 'content-type': 'application/javascript' } });
                });
            }
        }
        if (VARIANT === 'noalarm') {
            // diagnostic: the approvals drawer alarm (infinite box-shadow / background animation) switched off
            await ctx.addInitScript(() => {
                document.addEventListener('DOMContentLoaded', () => {
                    const s = document.createElement('style');
                    s.textContent = '.is-fee-alarm { animation: none !important; }';
                    document.head.appendChild(s);
                });
            });
        }
        if (VARIANT === 'hotslayer') {
            // candidate: the HTML marker overlay as its own composited layer (moves no longer dirty the page layer)
            await ctx.route(/\/assets\/css\/vehicle-xray\.css(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'css', 'vehicle-xray.css'), 'utf8');
                route.fulfill({ status: 200, body: text + '\n.fx-hots { will-change: transform; contain: layout style size; }\n', headers: { 'content-type': 'text/css' } });
            });
        }
        if (VARIANT === 'nolod') {
            // phase II behaviour: every zoom past 1.4x loads @2 for every vehicle, needed or not
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                const call = 'if (this.fullView && this.fullView.w / target.w > 1.4) { this.upgradeArt(target); }';
                const need = 'if (self.needsHd(node, self.view)) {';
                if (text.indexOf(call) === -1 || text.indexOf(need) === -1) { throw new Error('LOD code not found'); }
                route.fulfill({ status: 200, body: text.replace(call, 'if (this.fullView && this.fullView.w / target.w > 1.4) { this.upgradeArt(); }').replace(need, 'if (true) {'), headers: { 'content-type': 'application/javascript' } });
            });
        }
        if (VARIANT === 'nomorph') {
            // diagnostic: the detail panel changes without a View Transition
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                const text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                const line = "if (reducedMotion || typeof document.startViewTransition !== 'function') { update(); return; }";
                if (text.indexOf(line) === -1) { throw new Error('morph guard not found'); }
                route.fulfill({ status: 200, body: text.replace(line, 'update(); return;'), headers: { 'content-type': 'application/javascript' } });
            });
        }
        // diagnostic upper bounds (not shippable): markers not repositioned during camera frames / no part highlight images
        if (VARIANT === 'nooverlay' || VARIANT === 'noparthl') {
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                let text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                if (VARIANT === 'nooverlay') {
                    const call = "this.svgHost.style.transform = 'translate(' + tx.toFixed(2) + 'px,' + ty.toFixed(2) + 'px) scale(' + k.toFixed(5) + ')';\n        this.placeOverlay();";
                    if (text.indexOf(call) === -1) { throw new Error('cameraFrame overlay call not found'); }
                    text = text.replace(call, "this.svgHost.style.transform = 'translate(' + tx.toFixed(2) + 'px,' + ty.toFixed(2) + 'px) scale(' + k.toFixed(5) + ')';");
                } else {
                    const lazy = "img.setAttribute('href', img.getAttribute('data-href'));";
                    if (text.indexOf(lazy) === -1) { throw new Error('part href line not found'); }
                    text = text.replace(lazy, '');
                }
                route.fulfill({ status: 200, body: text, headers: { 'content-type': 'application/javascript' } });
            });
        }
        if (VARIANT === 'nohd') {
            await ctx.route(/\/assets\/js\/vehicle-xray\.js(\?.*)?$/, (route) => {
                let text = fs.readFileSync(path.join(HT, 'assets', 'js', 'vehicle-xray.js'), 'utf8');
                const call = 'if (this.fullView && this.fullView.w / target.w > 1.4) { this.upgradeArt(); }';
                if (text.indexOf(call) === -1) { throw new Error('upgrade call not found'); }
                route.fulfill({ status: 200, body: text.replace(call, ''), headers: { 'content-type': 'application/javascript' } });
            });
        }
        await ctx.addInitScript(() => {
            window.__frames = [];
            const tick = (t) => { window.__frames.push(t); requestAnimationFrame(tick); };
            requestAnimationFrame(tick);
        });
        const page = await ctx.newPage();
        await page.goto(BASE + QUERY, { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
        await page.mouse.move(5, 300);
        await page.waitForTimeout(2500);
        // only markers whose centre really is the marker (nothing drawn over it)
        const hots = await page.$$eval('.fx-hot:not(.is-hidden):not(.is-filter-only)', (n) => n.filter((h) => {
            const r = h.getBoundingClientRect();
            const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return el && el.closest('.fx-hot') === h;
        }).map((h) => h.dataset.fxSlot + '|' + h.dataset.fxMember));
        const picks = [0, 5, 11, 17].map((i) => hots[i % hots.length]);
        page.on('framenavigated', (f) => { if (f === page.mainFrame()) { console.error('navigated to', f.url()); } });
        await browser.startTracing(page, { categories: CATS });
        const marks = [];
        for (let z = 0; z < picks.length; z++) {
            const [slot, member] = picks[z].split('|');
            // real mouse click on the marker (the Christmas pause reacts to real pointer input, as for a user)
            await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
            const hot = await page.$('.fx-hot[data-fx-slot="' + slot + '"][data-fx-member="' + member + '"]');
            const hb = await hot.boundingBox();
            await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
            await page.evaluate(() => { document.addEventListener('pointerdown', () => { window.__down = performance.now(); console.timeStamp('zoom-sync'); window.__sync = window.__sync || window.__down; }, { capture: true, once: true }); });
            await page.mouse.click(hb.x + hb.width / 2, hb.y + hb.height / 2);
            const t0 = await page.evaluate(() => window.__down);
            await page.waitForTimeout(1300);
            const back = await page.$('[data-fx-zoom-back]:not([hidden])');
            let t1 = await page.evaluate(() => performance.now());
            if (back) {
                const bb = await back.boundingBox();
                await page.evaluate(() => { document.addEventListener('pointerdown', () => { window.__down = performance.now(); }, { capture: true, once: true }); });
                await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2);
                t1 = await page.evaluate(() => window.__down);
            }
            await page.waitForTimeout(1300);
            marks.push({ zoomIn: t0, zoomOut: t1 });
        }
        const trace = JSON.parse((await browser.stopTracing()).toString()).traceEvents;
        const frames = await page.evaluate(() => window.__frames);
        // performance.now() <-> trace clock: the first console.timeStamp('zoom-sync') was emitted at window.__sync
        const sync = trace.find((e) => e.name === 'TimeStamp' && e.args && e.args.data && e.args.data.message === 'zoom-sync');
        const syncPerf = await page.evaluate(() => window.__sync);
        const tsOf = (perfMs) => (sync ? sync.ts : 0) + (perfMs - syncPerf) * 1000;
        const win = (from, to) => frames.filter((t) => t >= from && t <= to).map((t, i, arr) => (i ? t - arr[i - 1] : null)).filter((d) => d !== null);
        const stats = (d) => {
            if (!d.length) { return null; }
            const s = d.slice().sort((a, b) => a - b);
            const q = (p) => Math.round(s[Math.min(s.length - 1, Math.floor(s.length * p))] * 10) / 10;
            return { frames: d.length, median: q(0.5), p95: q(0.95), p99: q(0.99), max: q(1), over33: d.filter((x) => x > 33.4).length, over50: d.filter((x) => x > 50).length, dropped: d.reduce((n, x) => n + Math.max(0, Math.round(x / 16.67) - 1), 0) };
        };
        const out = { variant: VARIANT, zooms: [] };
        marks.forEach((m, i) => {
            out.zooms.push({ zoom: i + 1, in: stats(win(m.zoomIn, m.zoomIn + 700)), out: stats(win(m.zoomOut, m.zoomOut + 700)) });
        });
        // pipeline stages inside the long frames of the camera animations (first 450 ms after each click)
        if (process.env.STAGES) {
            const STAGE = {
                FunctionCall: 'script', EventDispatch: 'script', FireAnimationFrame: 'rAF callbacks', TimerFire: 'script', 'v8.callFunction': 'script',
                UpdateLayoutTree: 'style', Layout: 'layout', PrePaint: 'prepaint', Paint: 'paint', Layerize: 'layerize', Commit: 'commit',
                'LayoutView::HitTest': 'hit-test (mouse)', 'ProxyImpl::NotifyReadyToActivate': 'activate',
                RasterTask: 'raster (worker)', GPUTask: 'gpu', ImageDecodeTask: 'image decode', 'Decode LazyPixelRef': 'image decode',
            };
            const tid = {};
            trace.filter((e) => e.ph === 'M' && e.name === 'thread_name').forEach((e) => { tid[e.pid + ':' + e.tid] = e.args.name; });
            const animLong = [];
            frames.forEach((t, i) => {
                if (!i) { return; }
                const d = t - frames[i - 1];
                if (d > 33.4 && marks.some((m) => (t >= m.zoomIn && t <= m.zoomIn + 450) || (t >= m.zoomOut && t <= m.zoomOut + 450))) { animLong.push([frames[i - 1], t]); }
            });
            const totals = {};
            animLong.forEach(([a, b]) => {
                const A = tsOf(a);
                const B = tsOf(b);
                trace.filter((e) => e.ph === 'X' && e.dur && STAGE[e.name] && e.ts < B && e.ts + e.dur > A).forEach((e) => {
                    const thread = (tid[e.pid + ':' + e.tid] || '').replace(/\d+$/, '');
                    const k = STAGE[e.name] + ' [' + thread + ']';
                    totals[k] = (totals[k] || 0) + (Math.min(B, e.ts + e.dur) - Math.max(A, e.ts)) / 1000;
                });
            });
            console.log('long animation frames:', animLong.length, 'total', Math.round(animLong.reduce((s, [a, b]) => s + b - a, 0)), 'ms');
            // what gets painted during the camera animations: Paint events with their layer / node / area
            const paints = {};
            marks.forEach((m) => [m.zoomIn, m.zoomOut].forEach((t0) => {
                const A = tsOf(t0);
                const B = tsOf(t0 + 450);
                trace.filter((e) => e.name === 'Paint' && e.ph === 'X' && e.ts >= A && e.ts <= B).forEach((e) => {
                    const d = (e.args && e.args.data) || {};
                    const clip = d.clip || [];
                    const w = clip.length === 8 ? Math.round(Math.abs(clip[2] - clip[0])) : '?';
                    const h = clip.length === 8 ? Math.round(Math.abs(clip[5] - clip[1])) : '?';
                    const k = 'layer ' + (d.layerId || '?') + ' node ' + (d.nodeId || '?') + ' ' + w + 'x' + h;
                    paints[k] = paints[k] || { n: 0, ms: 0, node: d.nodeId };
                    paints[k].n += 1;
                    paints[k].ms += e.dur / 1000;
                });
            }));
            const top = Object.entries(paints).sort((x, y) => y[1].ms - x[1].ms).slice(0, 8);
            const nodeIds = top.map(([, v]) => v.node).filter(Boolean);
            const cdp = await ctx.newCDPSession(page);
            await cdp.send('DOM.getDocument', { depth: -1 });
            const names = {};
            const { nodeIds: backendIds } = await cdp.send('DOM.pushNodesByBackendIdsToFrontend', { backendNodeIds: nodeIds }).catch(() => ({ nodeIds: [] }));
            for (let i = 0; i < backendIds.length; i++) {
                const d = await cdp.send('DOM.describeNode', { nodeId: backendIds[i] }).catch(() => null);
                if (d) { const n = d.node; const attrs = {}; for (let j = 0; j < (n.attributes || []).length; j += 2) { attrs[n.attributes[j]] = n.attributes[j + 1]; } names[nodeIds[i]] = n.localName + (attrs.class ? '.' + attrs.class.split(' ').slice(0, 3).join('.') : '') + (attrs.id ? '#' + attrs.id : ''); }
            }
            // invalidation sources during the camera animations (needs the invalidationTracking category)
            const inv = {};
            marks.forEach((m) => [m.zoomIn, m.zoomOut].forEach((t0) => {
                const A = tsOf(t0);
                const B = tsOf(t0 + 450);
                trace.filter((e) => /Invalidation|StyleRecalcInvalidationTracking|LayoutInvalidationTracking/.test(e.name) && e.ts >= A && e.ts <= B).forEach((e) => {
                    const d = (e.args && e.args.data) || {};
                    const k = e.name.replace('InvalidationTracking', '') + ' · ' + (d.nodeName || '?') + ' · ' + (d.reason || d.changedAttribute || d.changedClass || d.changedPseudo || '');
                    inv[k] = (inv[k] || 0) + 1;
                });
            }));
            console.log('invalidations during camera animations:');
            Object.entries(inv).sort((x, y) => y[1] - x[1]).slice(0, 14).forEach(([k, n]) => console.log('  ' + String(n).padStart(5) + 'x  ' + k.slice(0, 140)));
            console.log('paints during camera animations (count, total ms, element):');
            top.forEach(([k, v]) => console.log('  ' + String(v.n).padStart(4) + 'x ' + v.ms.toFixed(1).padStart(7) + ' ms  ' + k + '  ' + (names[v.node] || '')));
            Object.entries(totals).sort((x, y) => y[1] - x[1]).slice(0, 16).forEach(([k, v]) => console.log('  ' + v.toFixed(0).padStart(6) + ' ms  ' + k));
        }
        // long frames: which trace events (>4 ms) overlap them
        const long = [];
        frames.forEach((t, i) => {
            if (!i) { return; }
            const d = t - frames[i - 1];
            const inWindow = marks.some((m) => (t >= m.zoomIn && t <= m.zoomIn + 700) || (t >= m.zoomOut && t <= m.zoomOut + 700));
            if (d > 33.4 && inWindow) { long.push([frames[i - 1], t]); }
        });
        const tid = {};
        trace.filter((e) => e.ph === 'M' && e.name === 'thread_name').forEach((e) => { tid[e.pid + ':' + e.tid] = e.args.name; });
        out.longFrames = long.slice(0, 12).map(([a, b]) => {
            const A = tsOf(a);
            const B = tsOf(b);
            const ev = {};
            trace.filter((e) => e.ph === 'X' && e.dur > 4000 && e.ts < B && e.ts + e.dur > A).forEach((e) => {
                const k = (tid[e.pid + ':' + e.tid] || 'tid' + e.tid).replace(/\d+$/, '') + ' · ' + e.name;
                ev[k] = Math.max(ev[k] || 0, Math.round(e.dur / 100) / 10);
            });
            return { ms: Math.round((b - a) * 10) / 10, top: Object.entries(ev).sort((x, y) => y[1] - x[1]).slice(0, 6).map(([k, v]) => k + ' ' + v) };
        });
        out.decodes = trace.filter((e) => e.ph === 'X' && /Decode|ImageDecodeTask|ResizeImage/.test(e.name)).map((e) => e.name + ' ' + Math.round(e.dur / 100) / 10).slice(0, 10);
        const pool = (zs) => { const d = []; zs.forEach((m) => { d.push(...win(m.zoomIn, m.zoomIn + 700)); d.push(...win(m.zoomOut, m.zoomOut + 700)); }); return stats(d); };
        out.summary = { cold: pool(marks.slice(0, 2)), warm: pool(marks.slice(2)) };
        // camera animation (first 450 ms after the click) vs settling (450-1000 ms: final sharp raster)
        const phase = (a, b) => { const d = []; marks.forEach((m) => { d.push(...win(m.zoomIn + a, m.zoomIn + b)); d.push(...win(m.zoomOut + a, m.zoomOut + b)); }); return stats(d); };
        out.summary.animation = phase(0, 450);
        out.summary.settle = phase(450, 1000);
        if (process.env.SUMMARY) { console.log(JSON.stringify({ variant: VARIANT, summary: out.summary })); } else { console.log(JSON.stringify(out, null, 1)); }
    } finally {
        await close();
    }
})();
