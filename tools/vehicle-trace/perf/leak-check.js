// Do listeners / DOM nodes / heap grow with repeated use? Assembly, current code, real mouse:
// counters after 0, 5, 10 and 15 cycles of (marker zoom in -> back) + a tyre click.
const { open, BASE } = require('./lib');
(async () => {
    const o = await open();
    try {
        const page = await o.ctx.newPage();
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        const cdp = await o.ctx.newCDPSession(page);
        const counters = async (cycles) => {
            await cdp.send('HeapProfiler.collectGarbage');
            const m = await cdp.send('Memory.getDOMCounters');
            const h = await cdp.send('Runtime.getHeapUsage');
            return { cycles, listeners: m.jsEventListeners, nodes: m.nodes, documents: m.documents, heapMB: Math.round(h.usedSize / 1048576 * 10) / 10 };
        };
        const rows = [await counters(0)];
        let done = 0;
        for (const target of [5, 10, 15]) {
            for (; done < target; done++) {
                await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
                const hots = await page.$$('.fx-hot:not(.is-hidden):not(.is-filter-only)');
                const h = hots[(done * 3) % hots.length];
                const b = await h.boundingBox();
                await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2);
                await page.waitForTimeout(700);
                await page.keyboard.press('Escape');
                await page.waitForTimeout(500);
                const tyre = (await page.$$('.fx-tire .fx-hit'))[done % 6];
                await tyre.evaluate((n) => n.dispatchEvent(new MouseEvent('click', { bubbles: true })));
                await page.waitForTimeout(200);
            }
            rows.push(await counters(target));
        }
        rows.forEach((r) => console.log(JSON.stringify(r)));
    } finally {
        await o.close();
    }
})();
