// Which rasteriser does each launch mode get? (headless software vs GPU)
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
(async () => {
    const modes = {
        headless: { args: [] },
        headlessGpu: { args: ['--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'] },
        newHeadlessGpu: { channel: 'chromium', args: ['--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'] },
    };
    for (const [name, opts] of Object.entries(modes)) {
        try {
            const b = await chromium.launch(opts);
            const p = await b.newPage();
            const info = await p.evaluate(() => {
                const gl = document.createElement('canvas').getContext('webgl');
                const ext = gl && gl.getExtension('WEBGL_debug_renderer_info');
                return gl ? gl.getParameter(ext ? ext.UNMASKED_RENDERER_WEBGL : gl.RENDERER) : 'no webgl';
            });
            const fps = await p.evaluate(() => new Promise((ok) => { const t = []; let l = performance.now(); const f = (n) => { t.push(n - l); l = n; if (t.length < 60) { requestAnimationFrame(f); } else { ok(t.slice(1).reduce((a, b) => a + b, 0) / 59); } }; requestAnimationFrame(f); }));
            console.log(name, '|', info, '| rAF interval', fps.toFixed(1), 'ms');
            await b.close();
        } catch (e) { console.log(name, 'failed', e.message.split('\n')[0]); }
    }
})();
