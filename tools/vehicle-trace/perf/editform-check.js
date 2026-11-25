// Functional check of the lazily mounted edit forms on page Auto (nothing is submitted).
const { open, BASE } = require('./lib');
(async () => {
    const { ctx, close } = await open();
    try {
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + '?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('[data-as-page]');
        const res = await page.evaluate(() => ({
            elements: document.getElementsByTagName('*').length,
            templates: document.querySelectorAll('template[data-as-panel-template]').length,
            mountedForms: document.querySelectorAll('form.as-edit').length,
        }));
        // open the first system section if the page keeps sections collapsed, then press its first "Editează"
        const key = await page.evaluate(() => {
            const btn = document.querySelector('[data-as-toggle^="edit-"]');
            return btn ? btn.dataset.asToggle : null;
        });
        res.key = key;
        const clickToggle = () => page.evaluate((k) => { document.querySelector('.as-icon-btn[data-as-toggle="' + k + '"]').click(); }, key);
        await clickToggle();
        res.afterOpen = await page.evaluate((k) => {
            const form = document.querySelector('[data-as-panel="' + k + '"]');
            if (!form) { return 'no form'; }
            const data = new FormData(form);
            return {
                visible: !form.hidden, templateLeft: !!document.querySelector('template[data-as-panel-template="' + k + '"]'),
                fields: Array.from(data.keys()), op: data.get('op'), csrf: !!(data.get('csrf_token') || data.get('_csrf_token') || data.get('_token')),
                monitoring: data.get('monitoring_type'), interval: data.get('interval'),
                ariaExpanded: document.querySelector('.as-icon-btn[data-as-toggle="' + k + '"]').getAttribute('aria-expanded'),
                focused: document.activeElement && document.activeElement.closest('form') === form,
            };
        }, key);
        // unit label follows the monitoring type (change handler on the page)
        res.unitSwitch = await page.evaluate((k) => {
            const form = document.querySelector('[data-as-panel="' + k + '"]');
            const sel = form.querySelector('[data-as-monitoring]');
            const before = form.querySelector('[data-as-unit]').textContent;
            const other = Array.from(sel.options).find((o) => o.dataset.unit !== before);
            if (!other) { return 'only one unit'; }
            const old = sel.value;
            sel.value = other.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            const after = form.querySelector('[data-as-unit]').textContent;
            sel.value = old;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            return before + ' -> ' + after;
        }, key);
        // "Renunță" closes, "Editează" reopens the same (already mounted) form
        await page.evaluate((k) => { document.querySelector('form[data-as-panel="' + k + '"] [data-as-toggle]').click(); }, key);
        res.afterCancel = await page.evaluate((k) => document.querySelector('[data-as-panel="' + k + '"]').hidden, key);
        await clickToggle();
        res.reopened = await page.evaluate((k) => ({ visible: !document.querySelector('[data-as-panel="' + k + '"]').hidden, forms: document.querySelectorAll('[data-as-panel="' + k + '"]').length }), key);
        res.errors = errors;
        console.log(JSON.stringify(res, null, 1));
    } finally {
        await close();
    }
})();
