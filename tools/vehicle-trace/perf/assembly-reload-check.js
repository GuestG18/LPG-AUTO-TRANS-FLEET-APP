// Why did the warm reload of the assembly not show the scheme? Fresh load, then the benchmark's
// interaction cycle, then reload: report the scheme state and the saved "hidden" preference.
const { open, BASE } = require('./lib');
(async () => {
    const o = await open();
    try {
        const page = await o.ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        const fresh = await page.waitForSelector('.fx-explorer .fx-svg', { timeout: 15000 }).then(() => true, () => false);
        const state = () => page.evaluate(() => ({
            explorerVisible: !!document.querySelector('.fx-explorer .fx-svg') && !!document.querySelector('.fx-explorer .fx-svg').getBoundingClientRect().width,
            holderHidden: (document.querySelector('.repair-auto-xray') || {}).hidden,
            storage: Object.keys(localStorage).filter((k) => /xray|schem|auto/i.test(k)).map((k) => k + '=' + localStorage.getItem(k)),
            url: location.href.replace(/^.*index\.php/, ''),
        }));
        const before = await state();
        // the benchmark's memory cycle: tyre clicks, every system, back to all, a marker + Escape (forced clicks)
        const safe = async (el) => { if (!el) { return; } await el.evaluate((n) => n.scrollIntoView({ block: 'center' })).catch(() => {}); await el.click({ force: true, timeout: 5000 }).catch(() => {}); };
        const clicked = [];
        for (let k = 0; k < 2; k++) {
            for (const t of (await page.$$('.fx-tire .fx-hit')).slice(0, 6)) { await safe(t); }
            for (const c of await page.$$('.fx-cat[data-fx-cat]')) { clicked.push(await c.getAttribute('data-fx-cat')); await safe(c); }
            const hot = (await page.$$('.fx-hot:not(.is-hidden):not(.is-filter-only)'))[0];
            await safe(hot);
            await page.waitForTimeout(600);
            await page.keyboard.press('Escape');
            await page.waitForTimeout(600);
        }
        const afterCycle = await state();
        await page.reload({ waitUntil: 'load' });
        const reloaded = await page.waitForSelector('.fx-explorer .fx-svg', { state: 'visible', timeout: 15000 }).then(() => true, () => false);
        console.log(JSON.stringify({ fresh, before, afterCycle, reloadedVisible: reloaded, afterReload: await state(), systemsClicked: clicked.length, errors }, null, 1));
    } finally {
        await o.close();
    }
})();
