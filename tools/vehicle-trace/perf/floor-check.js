// Environment floor: frame intervals of a trivial page (one box animated with a transform via rAF, no
// app code) in the same GPU headless browser, under the same background load. Any long frames here
// come from the machine, not from the app.
const { open } = require('./lib');
(async () => {
    const o = await open();
    try {
        const page = await o.ctx.newPage();
        await page.setContent('<div id="b" style="width:200px;height:120px;background:#3b82f6;will-change:transform"></div>');
        const res = await page.evaluate(() => new Promise((ok) => {
            const b = document.getElementById('b');
            const d = [];
            let last = performance.now();
            const t0 = last;
            const step = (t) => {
                d.push(t - last);
                last = t;
                b.style.transform = 'translate(' + ((t - t0) / 5 % 800) + 'px,0) scale(' + (1 + Math.sin(t / 300) * 0.3) + ')';
                if (t - t0 < 8000) { requestAnimationFrame(step); } else { ok(d.slice(1)); }
            };
            requestAnimationFrame(step);
        }));
        const s = res.slice().sort((a, b) => a - b);
        const q = (p) => Math.round(s[Math.min(s.length - 1, Math.floor(s.length * p))] * 10) / 10;
        console.log(JSON.stringify({ frames: res.length, p50: q(0.5), p95: q(0.95), p99: q(0.99), max: Math.round(Math.max(...res)), over33pct: Math.round(res.filter((x) => x > 33.4).length / res.length * 1000) / 10, over50pct: Math.round(res.filter((x) => x > 50).length / res.length * 1000) / 10 }));
    } finally {
        await o.close();
    }
})();
