// Adaptive LOD evidence: at the overview and at each zoom, how many device pixels does one pixel of
// the overview bitmap (@1) cover? <= 1: @1 is already as sharp as the screen; > 1: @2 adds detail.
// Zooms on markers spread over the vehicle; deviceScaleFactor 1 and 2 (retina).
const { open, BASE } = require('./lib');
const QUERIES = { assembly: '?page=mentenanta&action=auto&vehicle_id=31', tractor: '?page=mentenanta&action=auto&structura=18', camion4: '?page=mentenanta&action=auto&structura=19', semi6: '?page=mentenanta&action=auto&structura=22' };
(async () => {
    for (const dsf of [1, 2]) {
        for (const [name, q] of Object.entries(QUERIES)) {
            const o = await open({ dsf: dsf });
            try {
                const page = await o.ctx.newPage();
                await page.goto(BASE + q, { waitUntil: 'load' });
                await page.waitForSelector('.fx-explorer .fx-svg');
                await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
                await page.waitForTimeout(1200);
                // natural width of each overview bitmap
                const naturals = await page.evaluate(() => Promise.all(Array.from(document.querySelectorAll('.fx-trace-base')).map((img) => new Promise((ok) => {
                    const p = new Image();
                    p.onload = () => ok(p.naturalWidth);
                    p.src = img.getAttribute('href');
                }))));
                const ratio = () => page.evaluate(([d, nat]) => {
                    const stage = document.querySelector('.fx-stage').getBoundingClientRect();
                    return Array.from(document.querySelectorAll('.fx-trace-base')).map((img, i) => {
                        const vb = img.ownerSVGElement.viewBox.baseVal;
                        const cssW = img.getBBox().width * stage.width / vb.width;
                        return Math.round(cssW * d / nat[i] * 100) / 100;
                    });
                }, [dsf, naturals]);
                const res = { dsf: dsf, config: name, overview: await ratio(), zooms: [] };
                const count = await page.$$eval('.fx-hot:not(.is-hidden):not(.is-filter-only)', (n) => n.length);
                for (let i = 0; i < count; i += Math.max(1, Math.floor(count / 8))) {
                    const hots = await page.$$('.fx-hot:not(.is-hidden):not(.is-filter-only)');
                    if (!hots[i]) { break; }
                    await hots[i].evaluate((n) => n.click());
                    await page.waitForTimeout(700);
                    res.zooms.push(await ratio());
                    const back = await page.$('[data-fx-zoom-back]:not([hidden])');
                    if (back) { await back.evaluate((n) => n.click()); }
                    await page.waitForTimeout(600);
                }
                res.maxZoomed = res.zooms.reduce((m, z) => Math.max(m, ...z), 0);
                console.log(JSON.stringify(res));
            } finally {
                await o.close();
            }
        }
    }
})();
