// Shared helpers for the perf scripts: throwaway admin session (deleted afterwards) + GPU headless Chromium.
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const BASE = 'http://aplicatie_fleet.test/htdocs/index.php';
const GPU_ARGS = ['--enable-precise-memory-info', '--enable-gpu', '--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist'];

async function open(options) {
    const opts = options || {};
    const PHP = fs.readdirSync('C:/laragon/bin/php').map((d) => 'C:/laragon/bin/php/' + d + '/php.exe').filter(fs.existsSync).pop();
    const mk = path.join(process.env.TEMP || 'C:/Windows/Temp', 'fleet-perf-mksess-' + process.pid + '.php');
    fs.writeFileSync(mk, "<?php $s=['session_initialized'=>time(),'auth_user'=>['id'=>1,'nume'=>'Administrator Sistem','email'=>'admin@test.loc','rol'=>'admin']];"
        + "$o='';foreach($s as $k=>$v){$o.=$k.'|'.serialize($v);}file_put_contents('C:/laragon/tmp/sess_'.$argv[1],$o);");
    const sid = 'perf' + crypto.randomBytes(8).toString('hex');
    execFileSync(PHP, [mk, sid]);
    fs.unlinkSync(mk);
    const browser = await chromium.launch({ args: GPU_ARGS });
    const ctx = await browser.newContext({ viewport: opts.viewport || { width: 1600, height: 1000 }, deviceScaleFactor: opts.dsf || 1 });
    await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: 'aplicatie_fleet.test', path: '/htdocs' }]);
    const close = async () => {
        await browser.close();
        try { fs.unlinkSync('C:/laragon/tmp/sess_' + sid); } catch (e) { /* already gone */ }
    };
    return { browser, ctx, close };
}

module.exports = { open, BASE };
