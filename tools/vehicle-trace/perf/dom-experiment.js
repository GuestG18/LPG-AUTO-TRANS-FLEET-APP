// What would lazy-mounting the hidden Auto-page markup save per click? In-page experiment only:
// time the synchronous click work (all listeners, Bootstrap included) on tyres, then detach the hidden
// edit forms / sections / dialog into fragments (nothing saved anywhere) and time again.
const { open, BASE } = require('./lib');

(async () => {
    const { ctx, close } = await open();
    try {
        const page = await ctx.newPage();
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.waitForTimeout(1500);
        const measure = () => page.evaluate(async () => {
            const tyres = Array.from(document.querySelectorAll('.fx-tire .fx-hit'));
            const t = [];
            for (let r = 0; r < 3; r++) {
                for (const hit of tyres.slice(0, 10)) {
                    const s = performance.now();
                    hit.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                    t.push(performance.now() - s);
                    await new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)));
                }
            }
            t.sort((a, b) => a - b);
            return { elements: document.getElementsByTagName('*').length, median: Math.round(t[Math.floor(t.length / 2)] * 100) / 100, p90: Math.round(t[Math.floor(t.length * 0.9)] * 100) / 100 };
        });
        const out = { baseline: await measure() };
        await page.evaluate(() => {
            window.__parked = [];
            const park = (sel) => document.querySelectorAll(sel).forEach((el) => {
                const marker = document.createComment('parked');
                el.replaceWith(marker);
                window.__parked.push([marker, el]);
            });
            park('form.as-edit[data-as-panel][hidden], form.as-edit[data-as-panel]:not([open])');
        });
        out.withoutHiddenEditForms = await measure();
        await page.evaluate(() => {
            const park = (sel) => document.querySelectorAll(sel).forEach((el) => {
                if (getComputedStyle(el).display !== 'none' && !el.hidden) { return; }
                const marker = document.createComment('parked');
                el.replaceWith(marker);
                window.__parked.push([marker, el]);
            });
            park('[data-as-sections]');
            park('dialog[data-scheme-dialog]');
        });
        out.alsoWithoutHiddenSectionsAndDialog = await measure();
        // the Bootstrap share: same clicks with the 12 delegated document handlers' selectors absent is not
        // testable without touching Bootstrap, so also report the raw scan cost of its toggle selectors now
        out.bootstrapScanNow = await page.evaluate(() => {
            const sel = ['[data-bs-toggle="button"]', '[data-bs-slide], [data-bs-slide-to]', '[data-bs-toggle="collapse"]', '[data-bs-toggle="dropdown"]:not(.disabled):not(:disabled)',
                '[data-bs-toggle="modal"]', '[data-bs-toggle="offcanvas"]', '[data-bs-toggle="tab"], [data-bs-toggle="pill"], [data-bs-toggle="list"]', '[data-bs-toggle="popover"]'];
            const s = performance.now();
            for (let i = 0; i < 20; i++) { sel.forEach((x) => document.querySelectorAll(x)); }
            return Math.round((performance.now() - s) / 20 * 100) / 100;
        });
        console.log(JSON.stringify(out, null, 1));
    } finally {
        await close();
    }
})();
