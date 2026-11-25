// Phase V functional checks (nothing is saved): component explorer on the assembly with real mouse input.
//  - select each visible marker: explorer opens with the right system / plate / count, no callouts in the stage
//  - search filters, groups expand, a row opens the inspector and is highlighted; closing the inspector clears it
//  - close button returns to the whole vehicle; rapid A->B ends on B; Detalii Șofer compact does not mount it
const { open, BASE } = require('./lib');
const path = require('path');
const Q = '?page=mentenanta&action=auto&vehicle_id=31';
(async () => {
    const out = { systems: [] };
    const o = await open({ viewport: { width: 1600, height: 1000 } });
    try {
        const page = await o.ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + Q, { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        const centre = () => page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center', behavior: 'instant' })).then(() => page.waitForTimeout(120));
        await centre();
        await page.waitForTimeout(1000);
        const hots = await page.$$eval('.fx-hot:not(.is-hidden):not(.is-filter-only)', (n) => n.filter((h) => {
            const r = h.getBoundingClientRect();
            const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return el && el.closest('.fx-hot') === h && !h._slot?.part;
        }).map((h) => ({ slot: h.dataset.fxSlot, member: h.dataset.fxMember, cat: h.dataset.fxCat })));
        const clickSel = async (sel) => {
            const el = await page.$(sel);
            await el.evaluate((n) => n.scrollIntoView({ block: 'center', behavior: 'instant' }));
            await page.waitForTimeout(120);
            const b = await el.boundingBox();
            await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2);
        };
        const toOverview = async () => {
            const was = await page.evaluate(() => { const b = document.querySelector('[data-fx-xp]:not([hidden]) [data-fx-xp-close]'); if (b) { b.click(); } return !!b; });
            if (was) { await page.waitForTimeout(900); }
        };
        const clickHot = async (h) => { await centre(); await clickSel('.fx-hot[data-fx-slot="' + h.slot + '"][data-fx-member="' + h.member + '"]'); };
        const xpState = () => page.evaluate(() => {
            const xp = document.querySelector('[data-fx-xp]');
            return {
                open: !xp.hidden, name: xp.querySelector('.fx-xp-name')?.textContent, sub: xp.querySelector('.fx-xp-sub')?.textContent,
                count: xp.querySelector('.fx-xp-meta strong')?.textContent, rows: xp.querySelectorAll('.fx-xp-row').length,
                groups: xp.querySelectorAll('.fx-xp-group').length, search: !!xp.querySelector('[data-fx-xp-search]'),
                selected: Array.from(xp.querySelectorAll('.fx-xp-row.is-selected')).map((r) => r.dataset.fxSub),
                legendVisible: getComputedStyle(document.querySelector('.fx-legend')).display !== 'none',
                stageCallouts: document.querySelectorAll('.fx-stage .fx-callout, .fx-stage [data-fx-lines], .fx-stage [data-fx-callouts]').length,
                zoomed: document.querySelector('.fx-explorer').classList.contains('is-zoomed'),
                xpRect: (() => { const r = xp.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; })(),
                stageH: Math.round(document.querySelector('.fx-stage').getBoundingClientRect().height),
            };
        });
        for (const h of hots.slice(0, 4)) {
            await toOverview();
            await centre();
            const el = await page.$('.fx-hot[data-fx-slot="' + h.slot + '"][data-fx-member="' + h.member + '"]');
            const bb = await el.boundingBox();
            const hit = await page.evaluate((p) => { const e = document.elementFromPoint(p.x, p.y); const t = e && e.closest('.fx-hot'); return t ? t.dataset.fxCat + '/' + t.dataset.fxSlot : (e ? e.className + ' xpOpen=' + !document.querySelector('[data-fx-xp]').hidden + ' zoom=' + document.querySelector('.fx-explorer').classList.contains('is-zoomed') : 'none'); }, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 });
            await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2);
            await page.waitForTimeout(900);
            out.systems.push(Object.assign({ cat: h.cat, member: h.member, hitUnderMouse: hit }, await xpState()));
        }
        // pick the system with the most rows for interaction checks
        const best = out.systems.slice().sort((a, b) => Number(b.count) - Number(a.count))[0];
        const bh = hots.find((h) => h.cat === best.cat && h.member === best.member);
        await toOverview();
        await clickHot(bh);
        await page.waitForTimeout(1200);
        out.interact = { system: best.cat, count: best.count };
        out.interact.wearShown = await page.$$eval('.fx-xp-dot', (n) => ({ total: n.length, none: n.filter((d) => d.classList.contains('is-none')).length }));
        if (await page.$('[data-fx-xp-search]')) {
            const first = await page.$eval('.fx-xp-row .fx-xp-label', (n) => n.firstChild.textContent);
            const q = first.slice(0, 5);
            await clickSel('[data-fx-xp-search]');
            await page.keyboard.type(q, { delay: 30 });
            await page.waitForTimeout(200);
            out.interact.search = { q, rows: await page.$$eval('.fx-xp-row', (n) => n.length), allMatch: await page.$$eval('.fx-xp-row .fx-xp-label', (n, q) => n.every((x) => x.textContent.toLowerCase().includes(q.toLowerCase())), q) };
            await page.keyboard.press('Control+A'); await page.keyboard.press('Backspace');
            await page.waitForTimeout(200);
        }
        const closedGroup = await page.$('.fx-xp-group-head[aria-expanded="false"]');
        if (closedGroup) {
            const before = await page.$$eval('.fx-xp-row', (n) => n.length);
            await clickSel('.fx-xp-group-head[aria-expanded="false"]');
            await page.waitForTimeout(150);
            out.interact.groupExpand = { before, after: await page.$$eval('.fx-xp-row', (n) => n.length) };
        }
        const rowKey = await page.$eval('.fx-xp-row', (n) => n.dataset.fxSub);
        let t0 = Date.now();
        await clickSel('.fx-xp-row[data-fx-sub="' + rowKey + '"]');
        await page.waitForSelector('.fx-body.is-inspecting');
        out.interact.inspectorOpenMs = Date.now() - t0;
        await page.waitForTimeout(800);
        out.interact.afterRow = await xpState();
        out.interact.inspectorTitle = await page.$eval('[data-fx-inspector]', (n) => (n.querySelector('.fi-name, h3, h2') || n).textContent.trim().slice(0, 60));
        await page.screenshot({ path: path.join(__dirname, 'v-inspecting.png') });
        // another row while inspecting
        const rows = await page.$$eval('.fx-xp-row', (n) => n.map((r) => r.dataset.fxSub));
        if (rows.length > 1) {
            await clickSel('.fx-xp-row[data-fx-sub="' + rows[1] + '"]');
            await page.waitForTimeout(700);
            out.interact.secondRow = { want: rows[1], selected: (await xpState()).selected };
        }
        const closeInsp = page.locator('[data-fx-inspector] .fi-close:visible').first();
        if (await closeInsp.count()) { await closeInsp.click(); await page.waitForTimeout(500); out.interact.afterInspectorClose = await xpState(); }
        await clickSel('[data-fx-xp-close]');
        await page.waitForTimeout(900);
        out.interact.afterClose = await xpState();
        // rapid A -> B
        const a = hots[0], b = hots.find((h) => h.cat === a.cat && h.slot !== a.slot) || hots[1];
        await toOverview();
        await clickHot(a); await page.waitForTimeout(80);
        out.rapidTarget = await page.evaluate((h) => { const n = document.querySelector('.fx-hot[data-fx-slot="' + h.slot + '"][data-fx-member="' + h.member + '"]'); const r = n.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { hidden: n.className, under: e && (e.closest('.fx-hot') ? e.closest('.fx-hot').dataset.fxSlot : e.className) }; }, b);
        await clickHot(b);
        await page.waitForTimeout(1200);
        const r = await xpState();
        out.rapid = { want: b.slot, got: r.sub, open: r.open, selectedHot: await page.$eval('.fx-hot.is-selected', (n) => n.dataset.fxSlot) };
        const trailer = hots.find((h) => h.member !== a.member);
        if (trailer) { await toOverview(); await clickHot(trailer); await page.waitForTimeout(1000); const t = await xpState(); out.trailer = { member: trailer.member, cat: trailer.cat, name: t.name, sub: t.sub, count: t.count }; }
        await page.screenshot({ path: path.join(__dirname, 'v-explorer.png') });
        out.errors = errors;
        await page.close();

        // Detalii Șofer compact: explorer must not mount
        const dp = await o.ctx.newPage();
        const derr = [];
        dp.on('pageerror', (e) => derr.push(e.message));
        let ok = false;
        for (const id of [13, 14, 15, 16, 17, 18, 19, 20]) {
            await dp.goto(BASE + '?page=soferi&action=show&id=' + id, { waitUntil: 'load' });
            ok = await dp.waitForSelector('.fx-explorer .fx-svg', { timeout: 8000 }).then(() => true, () => false);
            if (ok) { out.driver = { id }; break; }
        }
        out.driver = Object.assign(out.driver || {}, { mounted: ok });
        if (ok) {
            await dp.waitForTimeout(1000);
            out.driver.xpOpen = await dp.$$eval('[data-fx-xp]', (n) => n.filter((x) => !x.hidden).length);
            const hot = await dp.$('.fx-hot:not(.is-hidden)');
            if (hot) { await hot.click({ force: true }).catch(() => {}); await dp.waitForTimeout(800); out.driver.xpOpenAfterClick = await dp.$$eval('[data-fx-xp]', (n) => n.filter((x) => !x.hidden).length); }
        }
        out.driver.errors = derr;
        await dp.close();
    } catch (e) { out.fail = String(e.message).slice(0, 200); } finally {
        await o.close();
    }
    console.log(JSON.stringify(out, null, 1));
})();
