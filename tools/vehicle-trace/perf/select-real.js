// Tyre selection with real mouse input (pointerdown -> pointerup -> click), timed from the press to the
// second painted frame. Scheme files served from states/after (disk copies untouched); the Christmas
// scripts come from disk, with the scheme hold either kept or neutralised in memory.
//   node select-real.js [query]
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const QUERY = process.argv[2] || '&vehicle_id=31';
const BASE = 'http://aplicatie_fleet.test/htdocs/index.php?page=mentenanta&action=auto';
const STATES = path.join(__dirname, 'states');
const HT = path.join(__dirname, '..', '..', '..', 'htdocs');
const PHP = fs.readdirSync('C:/laragon/bin/php').map((d) => 'C:/laragon/bin/php/' + d + '/php.exe').filter(fs.existsSync).pop();
const MKSESS = path.join(process.env.TEMP || 'C:/Windows/Temp', 'fleet-selreal-mksess.php');
fs.writeFileSync(MKSESS, "<?php $s=['session_initialized'=>time(),'auth_user'=>['id'=>1,'nume'=>'Administrator Sistem','email'=>'admin@test.loc','rol'=>'admin']];"
    + "$o='';foreach($s as $k=>$v){$o.=$k.'|'.serialize($v);}file_put_contents('C:/laragon/tmp/sess_'.$argv[1],$o);");
const TYPES = { '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' };
const VARIANTS = [['xmas, no hold', false], ['xmas + scheme hold', true], ['no xmas scripts', null]];

(async () => {
    const sid = 'selreal' + crypto.randomBytes(8).toString('hex');
    execFileSync(PHP, [MKSESS, sid]);
    const browser = await chromium.launch({ args: ['--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'] });
    const rows = [];
    try {
        for (let round = 0; round < 2; round++) {
            for (const [label, keepHold] of VARIANTS) {
                const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
                await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: 'aplicatie_fleet.test', path: '/htdocs' }]);
                await ctx.route(/\/htdocs\/assets\/(js\/vehicle-xray(-art|-traced-data)?\.js|css\/vehicle-xray\.css|img\/xray-traced\/.*)(\?.*)?$/, (route) => {
                    const rel = new URL(route.request().url()).pathname.replace(/^.*\/htdocs\//, '');
                    const file = rel.indexOf('assets/img/xray-traced/') === 0
                        ? path.join(STATES, 'after', 'img', rel.slice('assets/img/xray-traced/'.length)) : path.join(STATES, 'after', rel);
                    if (!fs.existsSync(file)) { return route.fulfill({ status: 404, body: '' }); }
                    route.fulfill({ status: 200, body: fs.readFileSync(file), headers: { 'content-type': TYPES[path.extname(file)] } });
                });
                await ctx.route(/\/assets\/js\/xmas-(topbar|sidebar)\.js(\?.*)?$/, (route) => {
                    const name = new URL(route.request().url()).pathname.split('/').pop();
                    if (keepHold === null) { return route.fulfill({ status: 200, body: '', headers: { 'content-type': 'application/javascript' } }); }
                    let text = fs.readFileSync(path.join(HT, 'assets', 'js', name), 'utf8');
                    if (!keepHold) {
                        const n = text.split('t.closest && t.closest(".fx-explorer")').length - 1;
                        if (n !== 1) { throw new Error('hold hook not found once in ' + name); }
                        text = text.replace('t.closest && t.closest(".fx-explorer")', 'false');
                    }
                    route.fulfill({ status: 200, body: text, headers: { 'content-type': 'application/javascript' } });
                });
                const page = await ctx.newPage();
                await page.goto(BASE + QUERY, { waitUntil: 'load' });
                await page.waitForSelector('.fx-explorer .fx-svg');
                await page.evaluate(() => {
                    window.__down = 0;
                    document.addEventListener('pointerdown', () => { window.__down = performance.now(); }, true);
                    document.querySelector('.fx-stage').scrollIntoView({ block: 'center' });
                });
                await page.mouse.move(5, 300);
                await page.waitForTimeout(2500); // decorations running again (any hold expired)
                const tyres = await page.$$('.fx-tire .fx-hit');
                const lat = [];
                for (let i = 0; i < 12; i++) {
                    // keep the decorations running between samples: wait past the 1 s hold, mouse outside the scheme
                    await page.mouse.move(5, 300);
                    await page.waitForTimeout(1300);
                    await page.evaluate(() => document.querySelector('.fx-stage').scrollIntoView({ block: 'center' }));
                    // a point of the tyre not covered by a component marker / label
                    const pt = await tyres[i % tyres.length].evaluate((hit) => {
                        const tyre = hit.closest('.fx-tire');
                        const r = hit.getBoundingClientRect();
                        for (let fy = 0.5; fy > 0.05; fy -= 0.15) {
                            for (const fx of [0.5, 0.25, 0.75, 0.1, 0.9]) {
                                for (const yy of [0.5 + (0.5 - fy), fy]) {
                                    const x = r.left + r.width * fx; const y = r.top + r.height * yy;
                                    const el = document.elementFromPoint(x, y);
                                    if (el && el.closest('.fx-tire') === tyre) { return [x, y]; }
                                }
                            }
                        }
                        return null;
                    });
                    if (!pt) { continue; }
                    await page.mouse.click(pt[0], pt[1]);
                    const ms = await page.evaluate(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(() => ok(performance.now() - window.__down)))));
                    lat.push(ms);
                }
                lat.sort((a, b) => a - b);
                const r = { round: round, label: label, n: lat.length, median: Math.round(lat[Math.floor(lat.length / 2)] * 10) / 10, avg: Math.round(lat.reduce((a, b) => a + b, 0) / lat.length * 10) / 10, worst: Math.round(lat[lat.length - 1] * 10) / 10 };
                rows.push(r);
                console.log(round, label.padEnd(20), 'n', r.n, 'median', r.median, 'avg', r.avg, 'worst', r.worst);
                await ctx.close();
            }
        }
    } finally {
        await browser.close();
        try { fs.unlinkSync('C:/laragon/tmp/sess_' + sid); } catch (e) { /* */ }
        try { fs.unlinkSync(MKSESS); } catch (e) { /* */ }
    }
    fs.writeFileSync(path.join(__dirname, 'select-real-' + QUERY.replace(/\W+/g, '') + '.json'), JSON.stringify(rows, null, 1));
})();
