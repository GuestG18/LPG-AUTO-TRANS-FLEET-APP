// Tyre-selection latency on one configuration, for several variants of the scheme's front-end files,
// served through request interception (the files on disk are not read or touched):
//   node select-ab.js [query]       default: &vehicle_id=31 (tractor + trailer assembly)
// Variant = a snapshot dir in perf/states + optional in-memory CSS/JS patches.
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const QUERY = process.argv[2] || '&vehicle_id=31';
const BASE = 'http://aplicatie_fleet.test/htdocs/index.php?page=mentenanta&action=auto';
const STATES = path.join(__dirname, 'states');
const FILES = {
    'assets/js/vehicle-xray.js': 'assets/js/vehicle-xray.js', 'assets/js/vehicle-xray-art.js': 'assets/js/vehicle-xray-art.js',
    'assets/css/vehicle-xray.css': 'assets/css/vehicle-xray.css', 'assets/js/vehicle-xray-traced-data.js': 'assets/js/vehicle-xray-traced-data.js',
};
const LAYERS = '.fx-svg-host, .fx-hots { will-change: transform; }';
const CONTAIN = '.fx-hots { contain: layout style size; }';
const VARIANTS = [
    ['before', 'before', {}],
    ['after', 'after', {}],
    ['after, no xmas scripts', 'after', { noXmas: true }],
];
const ARGS = ['--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'];
const PHP = fs.readdirSync('C:/laragon/bin/php').map((d) => 'C:/laragon/bin/php/' + d + '/php.exe').filter(fs.existsSync).pop();
const MKSESS = path.join(process.env.TEMP || 'C:/Windows/Temp', 'fleet-selab-mksess.php');
fs.writeFileSync(MKSESS, "<?php $s=['session_initialized'=>time(),'auth_user'=>['id'=>1,'nume'=>'Administrator Sistem','email'=>'admin@test.loc','rol'=>'admin']];"
    + "$o='';foreach($s as $k=>$v){$o.=$k.'|'.serialize($v);}file_put_contents('C:/laragon/tmp/sess_'.$argv[1],$o);");

const TYPES = { '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' };

(async () => {
    const sid = 'selab' + crypto.randomBytes(8).toString('hex');
    execFileSync(PHP, [MKSESS, sid]);
    const browser = await chromium.launch({ args: ARGS });
    const out = [];
    try {
        for (let round = 0; round < 3; round++) {
            for (const [label, state, patch] of VARIANTS) {
                const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
                await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: 'aplicatie_fleet.test', path: '/htdocs' }]);
                await ctx.route(/\/htdocs\/assets\/(js\/vehicle-xray(-art|-traced-data)?\.js|css\/vehicle-xray\.css|img\/xray-traced\/.*)(\?.*)?$/, (route) => {
                    const rel = new URL(route.request().url()).pathname.replace(/^.*\/htdocs\//, '');
                    const file = rel.indexOf('assets/img/xray-traced/') === 0
                        ? path.join(STATES, state, 'img', rel.slice('assets/img/xray-traced/'.length))
                        : path.join(STATES, state, FILES[rel]);
                    if (!fs.existsSync(file)) { return route.fulfill({ status: 404, body: '' }); }
                    let body = fs.readFileSync(file);
                    if (rel.endsWith('.css') && patch.css) {
                        let text = body.toString('utf8');
                        patch.css.forEach(([a, b]) => { if (text.indexOf(a) === -1) { throw new Error('patch miss ' + a); } text = text.replace(a, b); });
                        body = Buffer.from(text);
                    }
                    route.fulfill({ status: 200, body: body, headers: { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream', 'cache-control': 'no-store' } });
                });
                if (patch.noXmas) { await ctx.route(/xmas-(topbar|sidebar).js/, (r) => r.fulfill({ status: 200, body: '', headers: { 'content-type': 'application/javascript' } })); }
                const page = await ctx.newPage();
                await page.goto(BASE + QUERY, { waitUntil: 'load' });
                await page.waitForSelector('.fx-explorer .fx-svg');
                await page.waitForTimeout(1500);
                await page.locator('.fx-explorer').scrollIntoViewIfNeeded();
                const tyres = await page.$$('.fx-tire .fx-hit');
                // warm: one click each (first selection may fetch / build panels)
                for (const t of tyres.slice(0, 4)) { await t.click({ force: true }); }
                await page.waitForTimeout(500);
                const lat = [];
                for (let i = 0; i < 12; i++) {
                    const ms = await tyres[i % tyres.length].evaluate(async (el) => {
                        const t = performance.now();
                        el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                        const sync = performance.now() - t;
                        await new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)));
                        window.__sync = (window.__sync || []).concat([sync]);
                        return performance.now() - t;
                    });
                    lat.push(ms);
                }
                lat.sort((a, b) => a - b);
                const sync = (await page.evaluate(() => window.__sync)).sort((a, b) => a - b);
                out.push({ round: round, label: label, median: Math.round(lat[6] * 10) / 10, avg: Math.round(lat.reduce((a, b) => a + b, 0) / lat.length * 10) / 10, worst: Math.round(lat[lat.length - 1] * 10) / 10, syncMedian: Math.round(sync[6] * 10) / 10 });
                console.log(round, label.padEnd(26), 'median', out[out.length - 1].median, 'avg', out[out.length - 1].avg, 'worst', out[out.length - 1].worst, '| click handlers (sync) median', out[out.length - 1].syncMedian);
                await ctx.close();
            }
        }
    } finally {
        await browser.close();
        try { fs.unlinkSync('C:/laragon/tmp/sess_' + sid); } catch (e) { /* */ }
        try { fs.unlinkSync(MKSESS); } catch (e) { /* */ }
    }
    fs.writeFileSync(path.join(__dirname, 'select-ab-' + QUERY.replace(/\W+/g, '') + '.json'), JSON.stringify(out, null, 1));
})();
