// Which condition values does the scheme receive? (real data, page Auto, a few vehicles)
const { open, BASE } = require('./lib');
const IDS = process.argv.slice(2).length ? process.argv.slice(2) : ['31', '21', '1', '48'];
(async () => {
    const o = await open();
    try {
        const page = await o.ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        for (const id of IDS) {
            await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=' + id, { waitUntil: 'load' });
            const data = await page.evaluate(() => {
                const el = Array.from(document.querySelectorAll('script[type="application/json"]')).find((s) => /"members"/.test(s.textContent));
                if (!el) { return null; }
                const parsed = JSON.parse(el.textContent);
                const unit = Array.isArray(parsed) ? parsed[0] : (parsed.unit || parsed);
                return (unit.members || []).map((m) => ({ plate: m.plate, type: m.type, condition: m.condition, slots: (m.slots || []).length }));
            });
            console.log('vehicle', id, JSON.stringify(data));
        }
        console.log('errors', JSON.stringify(errors));
    } finally {
        await o.close();
    }
})();
