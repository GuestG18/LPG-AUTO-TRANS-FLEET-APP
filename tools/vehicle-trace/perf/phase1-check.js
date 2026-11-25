// Debug: does the phase-I HTML rewrite (edit forms out of their <template>) render the Auto page?
const { open, BASE } = require('./lib');
(async () => {
    const { ctx, close } = await open();
    try {
        let info = {};
        if (!process.env.NOROUTE) await ctx.route(/\/htdocs\/index\.php\?page=mentenanta&action=auto(&|$)/, async (route) => {
            const response = await route.fetch();
            const text = await response.text();
            const html = process.env.IDENTITY ? text : text.replace(/<template data-as-panel-template="[^"]*">\s*(<form class="as-edit"[\s\S]*?<\/form>)\s*<\/template>/g, '$1');
            info = { status: response.status(), headers: response.headers(), before: text.length, after: html.length, templatesLeft: (html.match(/data-as-panel-template/g) || []).length };
            const h = Object.assign({}, response.headers()); delete h['content-encoding']; delete h['transfer-encoding']; delete h['content-length'];
            route.fulfill({ status: response.status(), headers: h, body: html });
        });
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + '?page=mentenanta&action=auto&structura=18', { waitUntil: 'load' });
        await page.waitForTimeout(2000);
        info.holderHidden = await page.evaluate(() => { const h = document.querySelector('[data-auto-xray], .repair-auto-xray, [data-fx-explorer]'); return h ? (h.hidden + ' ' + h.className) : 'no holder'; });
        info.toggle = await page.evaluate(() => { const t = document.querySelector('[data-auto-xray-toggle]'); return t ? t.getAttribute('aria-expanded') : null; });
        console.log(JSON.stringify(Object.assign(info, {
            title: await page.title(), explorer: !!(await page.$('.fx-explorer .fx-svg')), forms: await page.$$eval('form.as-edit', (n) => n.length), errors: errors,
        }), null, 1));
    } finally {
        await close();
    }
})();
