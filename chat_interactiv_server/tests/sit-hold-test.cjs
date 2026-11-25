'use strict';
/**
 * Regresie: utilizatorul merge spre scaun ȚINÂND W și apasă E fără să ridice degetul de pe W.
 * Personajul trebuie să rămână așezat (W ținut = auto-repeat, nu o apăsare nouă); doar o apăsare
 * nouă pe W îl ridică. Un singur cont (CI_SID_A).
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { chromium } = require(process.env.PW || 'playwright');
const BASE = process.env.CI_BASE || 'http://aplicatie_fleet.test/htdocs/index.php';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (n, name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}. ${name}${detail ? ' — ' + detail : ''}`); };

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lpghold-'));
  const ctx = await chromium.launchPersistentContext(dir, { viewport: { width: 900, height: 560 }, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] });
  try {
    await ctx.addInitScript(() => { try { localStorage.setItem('ci.quality', 'low'); } catch (e) {} });
    await ctx.addCookies([{ name: 'fleet_mvp_session', value: process.env.CI_SID_A, domain: new URL(BASE).hostname, path: '/', httpOnly: true }]);
    const page = ctx.pages()[0] || await ctx.newPage();
    page.setDefaultTimeout(240000);
    await page.goto(`${BASE}?page=chat_interactiv&debug=1`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.ci && document.querySelector('[data-net-status]').dataset.state === 'online');
    const seat = await page.evaluate(() => window.ci.world.seats.find((s) => s.id === 'scaun_1'));
    // Mers spre spatele scaunului cu W ținut (tastatura reală: keydown + auto-repeat).
    await page.evaluate(() => { const p = window.ci.player; p.yaw = Math.atan2(-(-0.2 * 1.17 - p.x), -(-1.38 * 1.17 - p.z)); });
    await page.keyboard.down('KeyW');
    for (const [x, z] of [[-0.2 * 1.17, -1.38 * 1.17], [seat.x, seat.z - 0.5]]) { // în spatele scaunului 1 (mai aproape de el decât de canapea)
      for (let i = 0; i < 200; i++) {
        const st = await page.evaluate(({ x, z }) => { const p = window.ci.player; const dx = x - p.x, dz = z - p.z; p.yaw = Math.atan2(-dx, -dz); return Math.hypot(dx, dz); }, { x, z });
        if (st < 0.15) break;
        await page.keyboard.down('KeyW'); // auto-repeat (repeat: true după prima apăsare)
        await sleep(60);
      }
    }
    await page.waitForFunction(() => window.ci.interactions.current?.type === 'seat', null, { timeout: 30000 });
    await page.keyboard.press('KeyE');                  // W încă ținut
    for (let i = 0; i < 20; i++) { await page.keyboard.down('KeyW'); await sleep(100); } // auto-repeat 2 s
    await page.waitForFunction(() => !!window.ci.player.seated, null, { timeout: 30000 }).catch(() => {});
    const held = await page.evaluate(() => ({ seated: window.ci.player.seated?.id || null, sit: window.ci.local.weights.sit.toFixed(2) }));
    check(1, 'Cu W ținut la apăsarea lui E, personajul rămâne așezat', held.seated === 'scaun_1', JSON.stringify(held));
    await page.keyboard.up('KeyW');
    await sleep(1500);
    const still = await page.evaluate(() => !!window.ci.player.seated);
    check(2, 'Rămâne așezat după ce W e eliberat', still, '');
    await page.keyboard.press('KeyW');                  // apăsare nouă → se ridică
    await page.waitForFunction(() => !window.ci.player.seated, null, { timeout: 30000 }).catch(() => {});
    const stood = await page.evaluate(() => !window.ci.player.seated);
    check(3, 'O apăsare nouă pe W îl ridică', stood, '');
  } catch (e) {
    check(99, 'Execuție', false, e.message.split('\n')[0]);
  } finally {
    await ctx.close(); fs.rmSync(dir, { recursive: true, force: true });
    console.log(`\n${results.filter(Boolean).length}/${results.length} verificări trecute.`);
    process.exit(results.every(Boolean) ? 0 : 1);
  }
})();
