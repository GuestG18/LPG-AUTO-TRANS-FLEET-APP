// CPU profile of zoom clicks on the assembly (real mouse): which JS functions run in the click and the
// first animation frames, plus the requests and image loads they start.
const { open, BASE } = require('./lib');
const path = require('path');
(async () => {
    const { ctx, close } = await open();
    try {
        const page = await ctx.newPage();
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
        await page.waitForTimeout(2000);
        const hots = await page.$$eval('.fx-hot:not(.is-hidden):not(.is-filter-only)', (n) => n.filter((h) => {
            const r = h.getBoundingClientRect();
            const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return el && el.closest('.fx-hot') === h;
        }).map((h) => h.dataset.fxSlot + '|' + h.dataset.fxMember));
        const requests = [];
        page.on('request', (r) => requests.push(r.resourceType() + ' ' + r.url().replace(/^.*\/htdocs\//, '').slice(0, 90)));
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Profiler.enable');
        await cdp.send('Profiler.setSamplingInterval', { interval: 200 });
        await cdp.send('Profiler.start');
        for (const pick of [hots[0], hots[5], hots[11]]) {
            const [slot, member] = pick.split('|');
            await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
            const hb = await (await page.$('.fx-hot[data-fx-slot="' + slot + '"][data-fx-member="' + member + '"]')).boundingBox();
            await page.mouse.click(hb.x + hb.width / 2, hb.y + hb.height / 2);
            await page.waitForTimeout(800);
            const back = await page.$('[data-fx-zoom-back]:not([hidden])');
            if (back) { const bb = await back.boundingBox(); await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2); }
            await page.waitForTimeout(800);
        }
        const { profile } = await cdp.send('Profiler.stop');
        const byId = {};
        profile.nodes.forEach((n) => { byId[n.id] = n; });
        const parent = {};
        profile.nodes.forEach((n) => (n.children || []).forEach((c) => { parent[c] = n.id; }));
        const self = {};
        const total = {};
        profile.samples.forEach((id, i) => {
            const dt = (profile.timeDeltas[i] || 0) / 1000;
            const n = byId[id];
            const key = (n.callFrame.functionName || '(anon)') + ' ' + path.basename(n.callFrame.url.split('?')[0]) + ':' + (n.callFrame.lineNumber + 1);
            self[key] = (self[key] || 0) + dt;
            const seen = new Set();
            for (let p = id; p; p = parent[p]) {
                const pn = byId[p];
                const k = (pn.callFrame.functionName || '(anon)') + ' ' + path.basename(pn.callFrame.url.split('?')[0]) + ':' + (pn.callFrame.lineNumber + 1);
                if (seen.has(k)) { continue; }
                seen.add(k);
                total[k] = (total[k] || 0) + dt;
            }
        });
        const show = (o, n) => Object.entries(o).filter(([k]) => !/^\((idle|program|root|garbage collector)\)/.test(k)).sort((a, b) => b[1] - a[1]).slice(0, n).forEach(([k, v]) => console.log(v.toFixed(1).padStart(8), k));
        console.log('== total time (incl. callees), 3 zoom in/out round trips');
        show(total, 22);
        console.log('== self time');
        show(self, 14);
        console.log('== requests during zooms', requests.length);
        requests.slice(0, 20).forEach((r) => console.log('  ' + r));
    } finally {
        await close();
    }
})();
