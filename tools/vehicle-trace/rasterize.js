// Pre-renders the traced SVGs to bitmaps with Chromium itself (same renderer as the page, so the
// pixels are what the vector looked like), transparent background:
//   trace/out/raster/<vehicle>@1.png  - SVG native size (2 px per mockup pixel): overview
//   trace/out/raster/<vehicle>@2.png  - double size (4 px per mockup pixel): zoomed views
//   trace/out/raster/parts/<part>@2.png
// export.js turns them into WebP. Why: a 3-6 MB vector <image> is re-rasterised on every repaint
// over it (hover, selection, each zoom frame); a decoded bitmap is only resampled by the GPU.
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, 'trace', 'out');
const RASTER = path.join(OUT, 'raster');
const man = JSON.parse(fs.readFileSync(path.join(OUT, 'parts.json'), 'utf8'));
const only = process.argv.slice(2);

const svgSize = (file) => {
    const head = fs.readFileSync(file, 'utf8').slice(0, 400);
    return [Number(head.match(/width="(\d+)"/)[1]), Number(head.match(/height="(\d+)"/)[1])];
};

(async () => {
    fs.mkdirSync(path.join(RASTER, 'parts'), { recursive: true });
    const browser = await chromium.launch();
    const pages = {};
    for (const scale of [1, 2]) {
        const ctx = await browser.newContext({ viewport: { width: 800, height: 600 }, deviceScaleFactor: scale });
        pages[scale] = await ctx.newPage();
    }
    // the SVG opened as its own document, at its native size; deviceScaleFactor gives the 2x render
    const render = async (svgFile, scale, png) => {
        const [w, h] = svgSize(svgFile);
        const page = pages[scale];
        await page.setViewportSize({ width: w, height: h });
        await page.goto('file:///' + svgFile.split(path.sep).join('/'), { waitUntil: 'load', timeout: 120000 });
        await page.screenshot({ path: png, omitBackground: true, clip: { x: 0, y: 0, width: w, height: h } });
    };
    for (const veh of Object.keys(man)) {
        if (only.length && only.indexOf(veh) === -1) { continue; }
        const svg = path.join(OUT, veh + '.svg');
        for (const scale of [1, 2]) { await render(svg, scale, path.join(RASTER, veh + '@' + scale + '.png')); }
        for (const p of man[veh].parts) {
            if (/^tyre-/.test(p.id)) { continue; } // tyres are not drawn separately (only their boxes are used)
            await render(path.join(OUT, p.file), 2, path.join(RASTER, 'parts', path.basename(p.file, '.svg') + '@2.png'));
        }
        console.log(veh, 'ok');
    }
    await browser.close();
})();
