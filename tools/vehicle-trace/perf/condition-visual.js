// Visual + functional check of the condition colours with injected test values (in memory only: the
// page's explorer JSON is patched before the explorer reads it; nothing is written anywhere).
// Brakes 15 %, suspension 50 %, engine 82 %, cooling 100 %; then a live update of the engine to 30 %,
// a system selection (outline must not replace the fill) and a redraw (tractor-only view).
const { open, BASE } = require('./lib');
const path = require('path');
const TEST = { franare: 15, suspensie: 50, motor: 82, racire: 100 };
(async () => {
    const o = await open({ viewport: { width: 1700, height: 1100 } });
    try {
        await o.ctx.addInitScript((test) => {
            new MutationObserver((records, observer) => {
                records.forEach((r) => r.addedNodes.forEach((n) => {
                    if (n.tagName === 'SCRIPT' && n.type === 'application/json' && /"members"/.test(n.textContent || '')) {
                        const parsed = JSON.parse(n.textContent);
                        const unit = Array.isArray(parsed) ? parsed[0] : (parsed.unit || parsed);
                        unit.members.forEach((m) => { m.condition = {}; Object.keys(test).forEach((k) => { m.condition[k] = { wear: test[k], components: 1 }; }); });
                        n.textContent = JSON.stringify(parsed);
                    }
                }));
            }).observe(document, { childList: true, subtree: true });
        }, TEST);
        const page = await o.ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
        await page.waitForTimeout(1200);
        const fills = () => page.evaluate(() => {
            const out = {};
            document.querySelectorAll('.fx-trace-part').forEach((g) => {
                const p = g.querySelector('.fx-trace-sil');
                const k = g.dataset.fxCat + '@' + g.dataset.fxMember;
                const cs = getComputedStyle(p);
                out[k] = out[k] || { fill: cs.fill, opacity: cs.fillOpacity, stroke: cs.stroke, n: 0 };
                out[k].n += 1;
            });
            return out;
        });
        const res = { initial: await fills() };
        const stage = await page.$('.fx-stage');
        await stage.screenshot({ path: path.join(__dirname, 'ivc-1-conditions.png') });
        // system selected: outline on top, fill kept
        await page.evaluate(() => document.querySelector('.fx-cat[data-fx-cat="motor"]').click());
        await page.waitForTimeout(500);
        res.motorSelected = (await fills())['motor@31'];
        await stage.screenshot({ path: path.join(__dirname, 'ivc-2-selected.png') });
        res.errors = errors;
        console.log(JSON.stringify(res, null, 1));
    } finally {
        await o.close();
    }
})();
