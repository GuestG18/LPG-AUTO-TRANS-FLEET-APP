// Functional check of the Christmas "scheme hold": decorations animate, freeze during interaction in the
// vehicle scheme, and resume ~1 s after the last interaction. Real mouse input, headless GPU.
const { chromium } = require(process.env.USERPROFILE + '/AppData/Local/npm-cache/_npx/420ff84f11983ee5/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const PHP = fs.readdirSync('C:/laragon/bin/php').map((d) => 'C:/laragon/bin/php/' + d + '/php.exe').filter(fs.existsSync).pop();
const MKSESS = path.join(process.env.TEMP || 'C:/Windows/Temp', 'fleet-xmascheck-mksess.php');
fs.writeFileSync(MKSESS, "<?php $s=['session_initialized'=>time(),'auth_user'=>['id'=>1,'nume'=>'Administrator Sistem','email'=>'admin@test.loc','rol'=>'admin']];"
    + "$o='';foreach($s as $k=>$v){$o.=$k.'|'.serialize($v);}file_put_contents('C:/laragon/tmp/sess_'.$argv[1],$o);");
(async () => {
    const sid = 'xmaschk' + crypto.randomBytes(8).toString('hex');
    execFileSync(PHP, [MKSESS, sid]);
    const browser = await chromium.launch({ args: ['--enable-gpu', '--use-angle=d3d11', '--ignore-gpu-blocklist'] });
    try {
        // tall viewport: top bar, sidebar and scheme all on screen at once
        const ctx = await browser.newContext({ viewport: { width: 1600, height: 2200 } });
        await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: 'aplicatie_fleet.test', path: '/htdocs' }]);
        await ctx.addInitScript(() => { window.__draws = []; const orig = CanvasRenderingContext2D.prototype.clearRect; CanvasRenderingContext2D.prototype.clearRect = function () { if (this.canvas.parentElement && this.canvas.parentElement.className === 'xs-lights') { window.__draws.push(Math.round(performance.now())); } return orig.apply(this, arguments); }; document.addEventListener('pointermove', (e) => { window.__lastMove = Math.round(performance.now()); window.__moveIn = !!(e.target.closest && e.target.closest('.fx-explorer')); }, true); });
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto('http://aplicatie_fleet.test/htdocs/index.php?page=mentenanta&action=auto&vehicle_id=31', { waitUntil: 'load' });
        await page.waitForSelector('.fx-explorer .fx-stage');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.mouse.move(5, 5);
        await page.waitForTimeout(3000);
        const sig = () => page.evaluate(() => {
            const s = document.getElementById('xtSnow');
            const l = document.querySelector('.xs-lights canvas');
            return { snow: s ? s.toDataURL().slice(-300) : '', lights: l ? l.toDataURL().slice(-300) : '' };
        });
        const changed = async (ms) => { const a = await sig(); await page.waitForTimeout(ms); const b = await sig(); return { snow: a.snow !== b.snow, lights: a.lights !== b.lights }; };
        const res = { before: await changed(400) };
        const box = await (await page.$('.fx-explorer .fx-stage')).boundingBox();
        // hovering inside the scheme (pointermove) starts the hold; keep moving there while sampling
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 3 });
        await page.waitForTimeout(300);
        const a = await sig();
        for (let i = 0; i < 5; i++) { await page.mouse.move(box.x + box.width / 2 + i * 7, box.y + box.height / 2, { steps: 2 }); await page.waitForTimeout(100); }
        const b = await sig();
        res.drawsDuringHold = await page.evaluate(() => ({ lastMove: window.__lastMove, moveIn: window.__moveIn, draws: window.__draws.slice(-12) }));
        res.duringHold = { snow: a.snow !== b.snow, lights: a.lights !== b.lights };
        await page.mouse.move(5, 5);
        await page.waitForTimeout(1400);
        res.after = await changed(400);
        res.errors = errors;
        res.sidebarCanvases = await page.evaluate(() => Array.from(document.querySelectorAll('.sidebar canvas')).map((c) => (c.parentElement.className || c.parentElement.tagName) + ' ' + c.width + 'x' + c.height));
        console.log(JSON.stringify(res));
    } finally {
        await browser.close();
        try { fs.unlinkSync('C:/laragon/tmp/sess_' + sid); } catch (e) { /* */ }
        try { fs.unlinkSync(MKSESS); } catch (e) { /* */ }
    }
})();
