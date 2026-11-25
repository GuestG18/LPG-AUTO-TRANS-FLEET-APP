// Screenshots of the scheme for visual checks: overview, a system selected, and a zoom on a component.
//   node shot-iv.js <prefix> [category-to-zoom]
const { open, BASE } = require('./lib');
const path = require('path');
const PREFIX = process.argv[2] || 'iv';
const CAT = process.argv[3] || 'motor';
(async () => {
    const o = await open({ viewport: { width: 1700, height: 1100 } });
    try {
        const page = await o.ctx.newPage();
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
        await page.waitForTimeout(1500);
        const stage = await page.$('.fx-stage');
        await stage.screenshot({ path: path.join(__dirname, PREFIX + '-1-overview.png') });
        await page.evaluate((c) => { const b = document.querySelector('.fx-cat[data-fx-cat="' + c + '"]'); if (b) { b.click(); } }, CAT);
        await page.waitForTimeout(700);
        await stage.screenshot({ path: path.join(__dirname, PREFIX + '-2-system.png') });
        await page.evaluate((c) => { const h = document.querySelector('.fx-hot[data-fx-cat="' + c + '"]:not(.is-hidden)'); if (h) { h.click(); } }, CAT);
        await page.waitForTimeout(2200);
        await stage.screenshot({ path: path.join(__dirname, PREFIX + '-3-zoom.png') });
    } finally {
        await o.close();
    }
})();
