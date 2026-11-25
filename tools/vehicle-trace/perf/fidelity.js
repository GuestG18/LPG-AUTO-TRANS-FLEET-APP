// Visual check: vector SVG vs the shipped WebP bitmaps, same region, at the overview scale and at the
// deepest zoom the scheme reaches (~3.4 m across a 1100 px stage = ~2.8 CSS px per mockup pixel),
// with deviceScaleFactor 1 and 2 (retina). Writes perf/fidelity-<dsf>.png (vector | bitmap per row).
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const path = require('path');
const OUT = path.join(__dirname, '..', 'trace', 'out');
const IMG = path.join(__dirname, 'states', 'after', 'img');
const f = (p) => 'file:///' + p.split(path.sep).join('/');
(async () => {
    const b = await chromium.launch();
    for (const dsf of [1, 2]) {
        const p = await b.newPage({ viewport: { width: 1400, height: 900 }, deviceScaleFactor: dsf });
        // camion-4 engine + cab region, mockup px x 150..420, y 220..390 of the composed canvas
        const view = (src, scale) => {
            const w = 2780 * scale; // camion-4 width in mockup px * CSS px per mockup px
            return `<div style="width:600px;height:330px;overflow:hidden;position:relative;display:inline-block;margin:4px;border:1px solid #ccc">
                <img src="${src}" style="position:absolute;width:${w / 2}px;left:${-160 * scale}px;top:${-(230 - 146) * scale}px"></div>`;
        };
        const rows = [[1.1, 'overview (1.1 CSS px / mockup px)'], [2.8, 'deepest zoom (2.8 CSS px / mockup px)']].map(([s, label]) =>
            `<div style="font:13px system-ui">${label} · dsf ${dsf} · left: vector SVG · right: shipped bitmap (${s > 1.5 ? '@2' : '@1'})</div>`
            + view(f(path.join(OUT, 'camion-4.svg')), s) + view(f(path.join(IMG, s > 1.5 ? 'camion-4@2.webp' : 'camion-4@1.webp')), s)).join('');
        const html = path.join(__dirname, 'fidelity.html');
        require('fs').writeFileSync(html, '<body style="margin:6px;background:#eef3fb">' + rows + '</body>');
        await p.goto(f(html));
        await p.waitForTimeout(4000);
        await p.screenshot({ path: path.join(__dirname, 'fidelity-' + dsf + '.png'), fullPage: true });
        await p.close();
    }
    await b.close();
})();
