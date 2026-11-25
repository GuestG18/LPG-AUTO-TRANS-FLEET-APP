// Read-only DOM census of page Auto: where the nodes are, which are hidden, the document click
// listeners (with their scripts) and what Bootstrap's delegated selectors cost per click.
//   node census.js [query]
const { open, BASE } = require('./lib');
const QUERY = process.argv[2] || '?page=mentenanta&action=auto&vehicle_id=31';

(async () => {
    const { ctx, close } = await open();
    try {
        const page = await ctx.newPage();
        await page.goto(BASE + QUERY, { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.waitForTimeout(1500);
        const census = await page.evaluate(() => {
            const all = document.getElementsByTagName('*');
            const total = all.length;
            const hidden = (el) => {
                for (let n = el; n && n !== document.body; n = n.parentElement) {
                    if (n.hidden || getComputedStyle(n).display === 'none') { return n; }
                }
                return null;
            };
            // biggest subtrees two levels under each landmark container
            const describe = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '')
                + Array.from(el.attributes).filter((a) => /^data-/.test(a.name)).slice(0, 2).map((a) => '[' + a.name + ']').join('');
            const big = [];
            const walk = (el, depth) => {
                const n = el.getElementsByTagName('*').length;
                if (n < 600) { return; }
                const kids = Array.from(el.children).map((c) => [c, c.getElementsByTagName('*').length]).sort((a, b) => b[1] - a[1]);
                const top = kids[0];
                if (depth > 14 || !top || top[1] < n * 0.55) { big.push({ sel: describe(el), nodes: n, depth: depth, children: kids.slice(0, 6).map(([c, k]) => describe(c) + ' ' + k) }); return; }
                walk(top[0], depth + 1);
                kids.slice(1, 4).forEach(([c]) => walk(c, depth + 1));
            };
            walk(document.body, 0);
            // hidden subtrees: count nodes under the topmost hidden ancestors
            const hiddenRoots = new Map();
            for (const el of all) {
                const h = hidden(el);
                if (h) { hiddenRoots.set(h, (hiddenRoots.get(h) || 0) + 1); }
            }
            const hiddenList = Array.from(hiddenRoots.entries()).map(([el, n]) => [describe(el), n]);
            const groupedHidden = {};
            hiddenList.forEach(([d, n]) => { const k = d.replace(/#[\w-]+/, '#…').replace(/\d+/g, 'N'); groupedHidden[k] = groupedHidden[k] || { roots: 0, nodes: 0 }; groupedHidden[k].roots += 1; groupedHidden[k].nodes += n; });
            const byTag = {};
            for (const el of all) { byTag[el.tagName.toLowerCase()] = (byTag[el.tagName.toLowerCase()] || 0) + 1; }
            return {
                total: total,
                hiddenNodes: hiddenList.reduce((s, [, n]) => s + n, 0),
                hiddenGroups: Object.entries(groupedHidden).sort((a, b) => b[1].nodes - a[1].nodes).slice(0, 15),
                big: big.sort((a, b) => b.nodes - a.nodes).slice(0, 10),
                topTags: Object.entries(byTag).sort((a, b) => b[1] - a[1]).slice(0, 12),
                forms: document.forms.length, selects: document.querySelectorAll('select').length, options: document.querySelectorAll('option').length,
                bootstrap: window.bootstrap && window.bootstrap.Tooltip ? window.bootstrap.Tooltip.VERSION : null,
            };
        });
        console.log(JSON.stringify(census, null, 1));
        // document / window click listeners with their source
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('DOMDebugger.enable').catch(() => {});
        const { result } = await cdp.send('Runtime.evaluate', { expression: 'document' });
        const { listeners } = await cdp.send('DOMDebugger.getEventListeners', { objectId: result.objectId });
        const scripts = {};
        cdp.on('Debugger.scriptParsed', (s) => { scripts[s.scriptId] = s.url; });
        await cdp.send('Debugger.enable');
        await page.waitForTimeout(300);
        const clicks = listeners.filter((l) => l.type === 'click').map((l) => (scripts[l.scriptId] || l.scriptId).replace(/^.*\//, '').replace(/\?.*$/, '') + ':' + (l.lineNumber + 1) + (l.useCapture ? ' (capture)' : ''));
        console.log('document click listeners:', clicks.length);
        const byScript = {};
        clicks.forEach((c) => { const k = c.split(':')[0]; byScript[k] = (byScript[k] || 0) + 1; });
        console.log(JSON.stringify(byScript));
        // cost of Bootstrap 5 data-api delegated selectors over this document
        const cost = await page.evaluate(() => {
            const selectors = ['[data-bs-toggle="button"]', '[data-bs-dismiss="alert"]', '[data-bs-slide], [data-bs-slide-to]', '[data-bs-toggle="collapse"]',
                '[data-bs-toggle="dropdown"]:not(.disabled):not(:disabled)', '[data-bs-toggle="modal"]', '[data-bs-dismiss="modal"]', '[data-bs-toggle="offcanvas"]',
                '[data-bs-dismiss="offcanvas"]', '[data-bs-toggle="tab"], [data-bs-toggle="pill"], [data-bs-toggle="list"]', '.dropdown-menu .dropdown-menu', '.dropdown form', '[data-bs-toggle="popover"]'];
            return selectors.map((s) => {
                const t = performance.now();
                let n = 0;
                for (let i = 0; i < 20; i++) { n = document.querySelectorAll(s).length; }
                return [s, n, Math.round((performance.now() - t) / 20 * 100) / 100];
            });
        });
        console.log('querySelectorAll per call (ms):');
        cost.forEach((c) => console.log('  ' + String(c[2]).padStart(6) + ' ms  matches ' + String(c[1]).padStart(4) + '  ' + c[0]));
    } finally {
        await close();
    }
})();
