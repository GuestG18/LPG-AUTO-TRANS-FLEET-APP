// Interleaved A/B in one page (robust to background CPU load): click-processing time of a tyre click with
// the edit forms lazy (templates, as shipped) vs all mounted (= the page before the change). Alternates
// 5 times; each block = 20 tyre clicks; synchronous dispatch time (all listeners incl. Bootstrap).
const { open, BASE } = require('./lib');
(async () => {
    const { ctx, close } = await open();
    try {
        const page = await ctx.newPage();
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.waitForTimeout(1500);
        const res = await page.evaluate(async () => {
            const templates = Array.from(document.querySelectorAll('template[data-as-panel-template]'));
            const mounted = templates.map((t) => { const marker = document.createComment('m'); return { t: t, marker: marker, nodes: null }; });
            const mountAll = () => mounted.forEach((m) => {
                m.nodes = Array.from(m.t.content.cloneNode(true).childNodes);
                m.t.after(m.marker);
                m.marker.after(...m.nodes);
            });
            const unmountAll = () => mounted.forEach((m) => { m.nodes.forEach((n) => n.remove()); m.marker.remove(); m.nodes = null; });
            const tyres = Array.from(document.querySelectorAll('.fx-tire .fx-hit'));
            const block = async () => {
                const t = [];
                for (let i = 0; i < 20; i++) {
                    const s = performance.now();
                    tyres[i % tyres.length].dispatchEvent(new MouseEvent('click', { bubbles: true }));
                    t.push(performance.now() - s);
                    await new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)));
                }
                return t;
            };
            const lazy = [];
            const all = [];
            const counts = {};
            for (let r = 0; r < 5; r++) {
                counts.lazy = document.getElementsByTagName('*').length;
                lazy.push(...await block());
                mountAll();
                counts.all = document.getElementsByTagName('*').length;
                all.push(...await block());
                unmountAll();
            }
            const stat = (a) => { const s = a.slice().sort((x, y) => x - y); const q = (p) => Math.round(s[Math.min(s.length - 1, Math.floor(s.length * p))] * 100) / 100; return { n: s.length, median: q(0.5), p90: q(0.9), p99: q(0.99) }; };
            return { elements: counts, lazyForms: stat(lazy), allFormsMounted: stat(all) };
        });
        console.log(JSON.stringify(res, null, 1));
    } finally {
        await close();
    }
})();
