// Functional checks for the phase III changes on the assembly (real mouse input):
//  1. DPR 1: zooms never request @2 art (the @1 bitmap is already screen-sharp there)
//  2. DPR 2: a full zoom swaps @2 in at rest; a zoom cancelled by "back" right away leaves no stale swap
//  3. rapid selection: marker A then marker B 80 ms later -> everything ends on B
const { open, BASE } = require('./lib');
const Q = '?page=mentenanta&action=auto&vehicle_id=31';

const prep = async (o) => {
    const page = await o.ctx.newPage();
    const hd = [];
    page.on('request', (r) => { if (/@2\.webp/.test(r.url()) && !/parts\//.test(r.url())) { hd.push(r.url().split('/').pop().split('?')[0]); } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + Q, { waitUntil: 'load' });
    await page.waitForSelector('.fx-explorer .fx-svg');
    await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(1200);
    const hots = await page.$$eval('.fx-hot:not(.is-hidden):not(.is-filter-only)', (n) => n.filter((h) => {
        const r = h.getBoundingClientRect();
        const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return el && el.closest('.fx-hot') === h;
    }).map((h) => ({ slot: h.dataset.fxSlot, member: h.dataset.fxMember, cat: h.dataset.fxCat })));
    const clickHot = async (h) => {
        await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
        const b = await (await page.$('.fx-hot[data-fx-slot="' + h.slot + '"][data-fx-member="' + h.member + '"]')).boundingBox();
        await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2);
    };
    const back = async () => {
        const btn = await page.$('[data-fx-zoom-back]:not([hidden])');
        if (btn) { const b = await btn.boundingBox(); await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2); }
    };
    const art = () => page.$$eval('.fx-trace-base', (n) => n.map((i) => ({ href: i.getAttribute('href').split('/').pop().split('?')[0], hd: !!i.getAttribute('data-hd') })));
    return { page, hd, errors, hots, clickHot, back, art };
};

(async () => {
    const out = {};
    // 1. DPR 1
    {
        const o = await open({ dsf: 1 });
        try {
            const t = await prep(o);
            for (const i of [0, 4, 8, 12]) { if (!t.hots[i]) { break; } await t.clickHot(t.hots[i]); await t.page.waitForTimeout(900); await t.back(); await t.page.waitForTimeout(700); }
            out.dpr1 = { hdRequests: t.hd, art: await t.art(), errors: t.errors };
        } finally { await o.close(); }
    }
    // 2. DPR 2: full zoom, then a fresh page with a zoom cancelled immediately
    {
        const o = await open({ dsf: 2 });
        try {
            const t = await prep(o);
            await t.clickHot(t.hots[4]);
            await t.page.waitForTimeout(2500);
            out.dpr2FullZoom = { hdRequests: t.hd.slice(), artWhileZoomed: await t.art(), errors: t.errors };
        } finally { await o.close(); }
    }
    {
        const o = await open({ dsf: 2 });
        try {
            const t = await prep(o);
            await t.clickHot(t.hots[4]);
            await t.page.waitForTimeout(60);
            await t.page.keyboard.press('Escape');
            await t.page.waitForTimeout(2500);
            out.dpr2Cancelled = { hdRequests: t.hd.slice(), artAtOverview: await t.art(), zoomed: await t.page.evaluate(() => document.querySelector('.fx-explorer').classList.contains('is-zoomed')), errors: t.errors };
        } finally { await o.close(); }
    }
    // 3. rapid selection A -> B (different systems)
    {
        const o = await open({ dsf: 1 });
        try {
            const t = await prep(o);
            // A and B: the closest pair of markers of the same system (selecting a system hides the others'
            // markers), so B is still on the stage while the camera starts moving to A
            const pos = await t.page.$$eval('.fx-hot', (n) => n.map((h) => { const r = h.getBoundingClientRect(); return [h.dataset.fxSlot + '|' + h.dataset.fxMember, r.left + r.width / 2, r.top + r.height / 2]; }));
            const at = (h) => pos.find((p) => p[0] === h.slot + '|' + h.member);
            let a = null;
            let b = null;
            let best = Infinity;
            t.hots.forEach((x) => t.hots.forEach((y) => {
                if (x === y || x.cat !== y.cat) { return; }
                const d = Math.hypot(at(x)[1] - at(y)[1], at(x)[2] - at(y)[2]);
                if (d > 20 && d < best) { best = d; a = x; b = y; }
            }));
            await t.clickHot(a);
            await t.page.waitForTimeout(80);
            // B where it is now (mid-camera), clicked only if it is on the stage
            const bBox = await (await t.page.$('.fx-hot[data-fx-slot="' + b.slot + '"][data-fx-member="' + b.member + '"]')).boundingBox();
            const stage = await (await t.page.$('.fx-stage')).boundingBox();
            out.bOnStageAt80ms = bBox.y > stage.y && bBox.y < stage.y + stage.height && bBox.x > stage.x && bBox.x < stage.x + stage.width;
            if (process.env.DIRECT) { await t.page.evaluate((B) => document.querySelector('.fx-hot[data-fx-slot="' + B.slot + '"][data-fx-member="' + B.member + '"]').click(), b); } else { await t.page.mouse.click(bBox.x + bBox.width / 2, bBox.y + bBox.height / 2); }
            await t.page.waitForTimeout(2500);
            out.rapid = await t.page.evaluate(([A, B]) => {
                const sel = document.querySelector('.fx-hot.is-selected');
                const docked = document.querySelector('.is-docked');
                const panelHead = document.querySelector('[data-fx-panel] .fx-panel-name, .fx-panel .fx-panel-name');
                const view = (document.querySelector('.fx-svg').getAttribute('viewBox') || '').split(' ').map(Number);
                const hot = document.querySelector('.fx-hot[data-fx-slot="' + B.slot + '"][data-fx-member="' + B.member + '"]');
                const r = hot.getBoundingClientRect();
                const st = document.querySelector('.fx-stage').getBoundingClientRect();
                return {
                    a: A.slot, b: B.slot,
                    selected: sel ? sel.dataset.fxSlot + ' @ vehicle ' + sel.dataset.fxMember : null,
                    aMember: A.member, bMember: B.member,
                    panelVehicle: (document.querySelector('.fx-panel-head') || {}).textContent ? document.querySelector('.fx-panel-head').textContent.replace(/s+/g, ' ').trim().slice(0, 90) : null,
                    footer: (document.querySelector('[data-fx-footer] .fx-card.is-strong') || {}).textContent,
                    dockedSection: docked ? docked.id : null,
                    panel: panelHead ? panelHead.textContent.trim() : null,
                    zoomed: document.querySelector('.fx-explorer').classList.contains('is-zoomed'),
                    markerBNearStageCentre: Math.round(Math.abs((r.left + r.width / 2) - (st.left + st.width / 2)) / st.width * 100) + '% / ' + Math.round(Math.abs((r.top + r.height / 2) - (st.top + st.height / 2)) / st.height * 100) + '%',
                };
            }, [a, b]);
            out.rapid.categoryA = a.cat;
            out.rapid.categoryB = b.cat;
            out.rapid.errors = t.errors;
        } finally { await o.close(); }
    }
    console.log(JSON.stringify(out, null, 1));
})();
