// Functional regression checks for phase IV (nothing is saved):
//  1. tooltip: hovering a marker shows its name right above it (page Auto, assembly)
//  2. drag editor: on a general configuration, "Editează schema", drag a marker -> it moves; then the page is
//     closed without saving (no write)
//  3. Detalii Șofer: the compact scheme renders the grayscale art without errors
const { open, BASE } = require('./lib');
const path = require('path');
(async () => {
    const out = {};
    const o = await open({ viewport: { width: 1600, height: 1000 } });
    try {
        // 1. tooltip
        {
            const page = await o.ctx.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
            await page.waitForSelector('.fx-explorer .fx-svg');
            await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
            await page.waitForTimeout(800);
            const hot = (await page.$$('.fx-hot:not(.is-hidden):not(.is-filter-only)'))[3];
            const hb = await hot.boundingBox();
            await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2, { steps: 4 });
            await page.waitForTimeout(300);
            out.tooltip = await page.evaluate((h) => {
                const tip = document.querySelector('.fx-tip');
                const tr = tip.getBoundingClientRect();
                const hotEl = document.elementFromPoint(h.x, h.y).closest('.fx-hot');
                return { visible: !tip.hidden, text: tip.textContent, expected: hotEl ? hotEl.dataset.fxTip : null, dxCentre: Math.round(tr.left + tr.width / 2 - h.x), aboveMarker: tr.bottom <= h.y };
            }, { x: hb.x + hb.width / 2, y: hb.y + hb.height / 2 });
            out.tooltip.errors = errors;
            await page.close();
        }
        // 2. drag editor on a general configuration (structura 18 = Cap tractor 2 axe); closed without saving
        {
            const page = await o.ctx.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            page.on('dialog', (d) => d.dismiss().catch(() => {}));
            await page.goto(BASE + '?page=mentenanta&action=auto&structura=18', { waitUntil: 'load' });
            await page.waitForSelector('.fx-explorer .fx-svg');
            const editBtn = await page.$('.as-scheme-edit-btn');
            out.editor = { editButton: !!editBtn };
            if (editBtn) {
                await editBtn.evaluate((b) => b.scrollIntoView({ block: 'center' }));
                await editBtn.click();
                await page.waitForTimeout(600);
                await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
                await page.waitForTimeout(400);
                const hot = (await page.$$('.fx-hot:not(.is-hidden)'))[2];
                const before = await hot.evaluate((n) => ({ left: n.style.left, top: n.style.top, slot: n.dataset.fxSlot }));
                const b = await hot.boundingBox();
                await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
                await page.mouse.down();
                await page.mouse.move(b.x + b.width / 2 + 40, b.y + b.height / 2 + 15, { steps: 8 });
                await page.mouse.up();
                await page.waitForTimeout(300);
                const after = await page.$eval('.fx-hot[data-fx-slot="' + before.slot + '"]', (n) => ({ left: n.style.left, top: n.style.top, moved: n.classList.contains('is-moved') }));
                const b2 = await (await page.$('.fx-hot[data-fx-slot="' + before.slot + '"]')).boundingBox();
                out.editor = Object.assign(out.editor, { editing: await page.evaluate(() => document.querySelector('.fx-explorer').classList.contains('is-editing')), before: before, after: after, pixelsMoved: [Math.round(b2.x - b.x), Math.round(b2.y - b.y)] });
            }
            out.editor.errors = errors;
            await page.close(); // no save
        }
        // 3. Detalii Șofer compact scheme
        {
            const page = await o.ctx.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            let found = null;
            for (const id of [13, 14, 15, 16, 17, 18, 19, 20]) {
                await page.goto(BASE + '?page=soferi&action=show&id=' + id, { waitUntil: 'load' });
                const ok = await page.waitForSelector('.fx-explorer .fx-svg', { timeout: 8000 }).then(() => true, () => false);
                if (ok) { found = id; break; }
            }
            out.driver = { driverId: found };
            if (found) {
                await page.waitForTimeout(1200);
                out.driver.art = await page.$$eval('.fx-trace-base', (n) => n.map((i) => (i.getAttribute('href') || '').split('/').pop().split('?')[0]));
                out.driver.silhouettes = await page.$$eval('.fx-trace-sil', (n) => n.length);
                const st = await page.$('.fx-stage');
                if (st) { await st.screenshot({ path: path.join(__dirname, 'ivc-driver.png') }); }
            }
            out.driver.errors = errors;
            await page.close();
        }
    } finally {
        await o.close();
    }
    console.log(JSON.stringify(out, null, 1));
})();
