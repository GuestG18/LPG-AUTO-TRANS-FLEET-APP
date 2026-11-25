// Debug: silhouette paths present / active / positioned?
const { open, BASE } = require('./lib');
(async () => {
    const o = await open();
    try {
        const page = await o.ctx.newPage();
        const errs = []; page.on('pageerror', (e) => errs.push(e.message)); page.on('console', (m) => { if (m.type() === 'error') { errs.push('console: ' + m.text()); } });
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.evaluate(() => { const b = document.querySelector('.fx-cat[data-fx-cat="motor"]'); if (b) { b.click(); } });
        await page.waitForTimeout(500);
        console.log(JSON.stringify(await page.evaluate(() => {
            const sils = Array.from(document.querySelectorAll('.fx-trace-sil'));
            const active = sils.filter((p) => p.closest('.fx-trace-part').classList.contains('is-active'));
            const engine = document.querySelector('.fx-trace-part[data-fx-part="engine"] .fx-trace-sil');
            const base = document.querySelector('.fx-trace-base');
            const bb = engine ? engine.getBBox() : null;
            const cs = engine ? getComputedStyle(engine) : null;
            return {
                sils: sils.length, active: active.length,
                engine: engine ? { bbox: [bb.x, bb.y, bb.width, bb.height].map(Math.round), transform: engine.getAttribute('transform'), stroke: cs.stroke, strokeWidth: cs.strokeWidth, d: engine.getAttribute('d').slice(0, 80), groupOpacity: getComputedStyle(engine.closest('.fx-trace-part')).opacity, groupClass: engine.closest('.fx-trace-part').getAttribute('class'), parent: engine.closest('svg').getAttribute('class') } : null,
                base: base ? { x: base.getAttribute('x'), y: base.getAttribute('y'), w: base.getAttribute('width'), parentSvg: base.closest('svg').getAttribute('class') } : null,
            };
        }), null, 1));
        console.log('errors', JSON.stringify(errs));
        console.log(JSON.stringify(await page.evaluate(async () => {
            const src = Array.from(document.scripts).map((x) => x.src).find((u) => /vehicle-xray-art.js/.test(u));
            const text = src ? await (await fetch(src)).text() : '';
            const data = window.FleetXrayTracedData && window.FleetXrayTracedData.templates['cap_tractor|2'];
            return { src: src, servesSil: text.indexOf('fx-trace-sil') !== -1, parts: document.querySelectorAll('.fx-trace-part').length, dataEngineSil: !!(data && data.parts.find((p) => p.id === 'engine' && p.sil)), dataVersion: window.FleetXrayTracedData && window.FleetXrayTracedData.version };
        })));
    } finally {
        await o.close();
    }
})();
