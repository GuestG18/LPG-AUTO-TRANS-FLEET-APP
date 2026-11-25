// Which CSS animations / transitions run on page Auto while idle? (they can repaint the page every frame)
const { open, BASE } = require('./lib');
(async () => {
    const o = await open();
    try {
        const page = await o.ctx.newPage();
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.waitForTimeout(3000);
        const list = await page.evaluate(() => document.getAnimations().map((a) => {
            const t = a.effect && a.effect.target;
            const kf = a.effect && a.effect.getKeyframes ? a.effect.getKeyframes() : [];
            const props = Array.from(new Set(kf.flatMap((k) => Object.keys(k).filter((p) => !['offset', 'computedOffset', 'easing', 'composite'].includes(p)))));
            const el = t ? (t.tagName ? t.tagName.toLowerCase() : 'pseudo') + (t.id ? '#' + t.id : '') + (t.className && typeof t.className === 'string' ? '.' + t.className.trim().split(/\s+/).slice(0, 3).join('.') : '') : '?';
            return { name: a.animationName || a.transitionProperty || a.constructor.name, el: el, props: props.join(','), iterations: a.effect && a.effect.getTiming ? a.effect.getTiming().iterations : null, state: a.playState, pseudo: a.effect && a.effect.pseudoElement };
        }));
        const grouped = {};
        list.forEach((a) => { const k = a.name + ' | ' + a.props + ' | ' + a.el + (a.pseudo ? ' ' + a.pseudo : '') + ' | ' + a.state + ' | iter ' + a.iterations; grouped[k] = (grouped[k] || 0) + 1; });
        console.log('running animations:', list.length);
        Object.entries(grouped).sort((x, y) => y[1] - x[1]).forEach(([k, n]) => console.log('  ' + String(n).padStart(3) + 'x  ' + k));
    } finally {
        await o.close();
    }
})();
