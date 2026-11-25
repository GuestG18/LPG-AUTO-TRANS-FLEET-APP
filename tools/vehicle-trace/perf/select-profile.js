// Where does one tyre selection on the assembly spend its time? Serves the saved optimised files
// (states/after) through request interception like select-ab.js, then records a CPU profile and a
// trace over 8 selections: top JS functions by self time, and trace totals by event name.
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const QUERY = process.argv[2] || '&vehicle_id=31';
const STATE = process.argv[3] || 'after';
const BASE = 'http://aplicatie_fleet.test/htdocs/index.php?page=mentenanta&action=auto';
const STATES = path.join(__dirname, 'states');
const PHP = fs.readdirSync('C:/laragon/bin/php').map((d) => 'C:/laragon/bin/php/' + d + '/php.exe').filter(fs.existsSync).pop();
const MKSESS = path.join(process.env.TEMP || 'C:/Windows/Temp', 'fleet-selprof-mksess.php');
fs.writeFileSync(MKSESS, "<?php $s=['session_initialized'=>time(),'auth_user'=>['id'=>1,'nume'=>'Administrator Sistem','email'=>'admin@test.loc','rol'=>'admin']];"
    + "$o='';foreach($s as $k=>$v){$o.=$k.'|'.serialize($v);}file_put_contents('C:/laragon/tmp/sess_'.$argv[1],$o);");
const TYPES = { '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' };

(async () => {
    const sid = 'selprof' + crypto.randomBytes(8).toString('hex');
    execFileSync(PHP, [MKSESS, sid]);
    const browser = await chromium.launch({ args: ['--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'] });
    try {
        const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
        await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: 'aplicatie_fleet.test', path: '/htdocs' }]);
        await ctx.route(/\/htdocs\/assets\/(js\/vehicle-xray(-art|-traced-data)?\.js|css\/vehicle-xray\.css|img\/xray-traced\/.*)(\?.*)?$/, (route) => {
            const rel = new URL(route.request().url()).pathname.replace(/^.*\/htdocs\//, '');
            const file = rel.indexOf('assets/img/xray-traced/') === 0
                ? path.join(STATES, STATE, 'img', rel.slice('assets/img/xray-traced/'.length)) : path.join(STATES, STATE, rel);
            if (!fs.existsSync(file)) { return route.fulfill({ status: 404, body: '' }); }
            route.fulfill({ status: 200, body: fs.readFileSync(file), headers: { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' } });
        });
        const page = await ctx.newPage();
        const requests = [];
        page.on('request', (r) => { if (r.resourceType() === 'fetch' || r.resourceType() === 'xhr') { requests.push(r.url().replace(/^.*index\.php/, '')); } });
        await page.goto(BASE + QUERY, { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-svg');
        await page.waitForTimeout(1500);
        const tyres = await page.$$('.fx-tire .fx-hit');
        for (const t of tyres.slice(0, 4)) { await t.click({ force: true }); }
        await page.waitForTimeout(800);
        requests.length = 0;
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Profiler.enable');
        await cdp.send('Profiler.setSamplingInterval', { interval: 100 });
        await browser.startTracing(page, { categories: ['devtools.timeline', 'disabled-by-default-devtools.timeline', 'blink', 'v8.execute'] });
        await cdp.send('Profiler.start');
        for (let i = 0; i < 8; i++) {
            await tyres[i % tyres.length].evaluate(async (el) => {
                el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                await new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)));
            });
        }
        const { profile } = await cdp.send('Profiler.stop');
        const trace = JSON.parse((await browser.stopTracing()).toString()).traceEvents;
        // JS self time per function
        const byId = {};
        profile.nodes.forEach((n) => { byId[n.id] = n; });
        const self = {};
        const dt = profile.timeDeltas;
        profile.samples.forEach((id, i) => {
            const n = byId[id];
            const key = (n.callFrame.functionName || '(anon)') + ' ' + n.callFrame.url.replace(/^.*\//, '').replace(/\?.*$/, '') + ':' + (n.callFrame.lineNumber + 1);
            self[key] = (self[key] || 0) + (dt[i] || 0) / 1000;
        });
        const parent = {}; profile.nodes.forEach((n) => (n.children || []).forEach((c) => { parent[c] = n; }));
        const callers = {};
        profile.samples.forEach((id, i) => { const n = byId[id]; if (n.callFrame.functionName !== 'querySelectorAll') { return; } let chain = []; let p = parent[n.id]; while (p && chain.length < 4) { chain.push((p.callFrame.functionName || '(anon)') + ':' + (p.callFrame.lineNumber + 1) + ' ' + path.basename(p.callFrame.url.split('?')[0])); p = parent[p.id]; } const k = chain.join(' < '); callers[k] = (callers[k] || 0) + (dt[i] || 0) / 1000; });
        console.log('-- querySelectorAll callers'); Object.entries(callers).sort((a, b) => b[1] - a[1]).slice(0, 8).forEach(([k, v]) => console.log(v.toFixed(1).padStart(7), k));
        console.log('-- JS self time (ms, 8 selections)');
        Object.entries(self).sort((a, b) => b[1] - a[1]).slice(0, 22).forEach(([k, v]) => console.log(v.toFixed(1).padStart(7), k));
        // trace totals per event name (main thread complete events)
        const tot = {};
        trace.filter((e) => e.ph === 'X' && e.dur).forEach((e) => { tot[e.name] = (tot[e.name] || 0) + e.dur / 1000; });
        console.log('-- trace totals (ms)');
        Object.entries(tot).sort((a, b) => b[1] - a[1]).slice(0, 16).forEach(([k, v]) => console.log(v.toFixed(1).padStart(7), k));
        const layouts = trace.filter((e) => e.name === 'Layout' && e.dur).map((e) => (e.args && e.args.beginData && e.args.beginData.dirtyObjects) + '/' + (e.args && e.args.beginData && e.args.beginData.totalObjects) + ' ' + (e.dur / 1000).toFixed(1) + 'ms');
        console.log('-- layouts', layouts.length, layouts.slice(0, 12).join(' | '));
        const forced = trace.filter((e) => e.name === 'Layout' && e.args && e.args.beginData && e.args.beginData.stackTrace).map((e) => e.args.beginData.stackTrace.slice(0, 2).map((f) => f.functionName + ':' + f.lineNumber).join(' < '));
        console.log('-- forced layouts', forced.length, Array.from(new Set(forced)).slice(0, 8).join(' | '));
        console.log('-- requests during selection', requests.length, requests.slice(0, 6).join(' , '));
    } finally {
        await browser.close();
        try { fs.unlinkSync('C:/laragon/tmp/sess_' + sid); } catch (e) { /* */ }
        try { fs.unlinkSync(MKSESS); } catch (e) { /* */ }
    }
})();
