'use strict';
/**
 * Test partajare ecran (2 conturi reale, 2 browsere): A folosește un laptop și prezintă, B vede
 * ecranul pe televizorul din cameră și de aproape; B nu poate prezenta simultan; A oprește.
 *
 * getDisplayMedia este înlocuit cu un canvas animat (headless nu are ecran de ales); conexiunea
 * WebRTC dintre cele două browsere este cea reală. Aceleași variabile ca acceptance.cjs.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(process.env.PW || 'playwright');

const BASE = process.env.CI_BASE || 'http://aplicatie_fleet.test/htdocs/index.php';
const OUT = process.env.CI_OUT || path.join(__dirname, 'out');
fs.mkdirSync(OUT, { recursive: true });
const origin = new URL(BASE).origin;
const results = [];
const check = (n, name, ok, detail = '') => { results.push({ n, name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}. ${name}${detail ? ' — ' + detail : ''}`); };

// Ecran „fals”: canvas animat cu text (ca un slide), capturat ca MediaStream.
const FAKE_SCREEN = () => {
  navigator.mediaDevices.getDisplayMedia = async () => {
    const c = document.createElement('canvas'); c.width = 1280; c.height = 720;
    const ctx = c.getContext('2d'); let f = 0;
    setInterval(() => {
      f++;
      ctx.fillStyle = '#1d4ed8'; ctx.fillRect(0, 0, 1280, 720);
      ctx.fillStyle = '#fff'; ctx.font = 'bold 72px sans-serif'; ctx.fillText('LPG — Raport flotă', 80, 160);
      ctx.font = '48px sans-serif'; ctx.fillText('Prezentare de test · cadru ' + f, 80, 260);
      ctx.fillStyle = '#facc15'; ctx.fillRect(80 + (f * 12) % 1000, 400, 120, 120);
    }, 66);
    return c.captureStream(15);
  };
};

async function openUser(sid, label) {
  // Profil propriu: flag-ul „insecure origin as secure” (getDisplayMedia pe http local) cere un user-data-dir.
  const dir = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lpgshare-'));
  const ctx = await chromium.launchPersistentContext(dir, { viewport: { width: 800, height: 520 }, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', `--unsafely-treat-insecure-origin-as-secure=${origin}`] });
  const browser = { close: async () => { await ctx.close(); fs.rmSync(dir, { recursive: true, force: true }); } };
  await ctx.addInitScript(() => { try { localStorage.setItem('ci.quality', 'low'); } catch (e) {} });
  await ctx.addInitScript(FAKE_SCREEN);
  await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: new URL(BASE).hostname, path: '/', httpOnly: true }]);
  const page = ctx.pages()[0] || await ctx.newPage();
  page.setDefaultTimeout(90000);
  page.on('pageerror', (e) => console.log(`[${label}] pageerror`, e.message));
  await page.goto(`${BASE}?page=chat_interactiv&debug=1`, { waitUntil: 'domcontentloaded' });
  const picker = await page.waitForSelector(`[data-avatar-picker]:not([hidden]) [data-key="${label === 'A' ? 'avaturn_a' : 'avaturn_b'}"], [data-net-status][data-state="online"]`, { timeout: 90000 });
  if (await picker.getAttribute('data-key')) await picker.click();
  await page.waitForFunction(() => window.ci && document.querySelector('[data-net-status]').dataset.state === 'online', null, { timeout: 120000 });
  return { browser, page };
}

async function walkTo(page, x, z, maxMs = 25000) {
  return page.evaluate(async ({ x, z, maxMs }) => {
    const p = window.ci.player; const t0 = performance.now();
    while (performance.now() - t0 < maxMs) {
      const dx = x - p.x, dz = z - p.z;
      if (Math.hypot(dx, dz) < 0.12) break;
      p.yaw = Math.atan2(-dx, -dz); p.keys.add('KeyW');
      await new Promise((r) => setTimeout(r, 50));
    }
    p.keys.delete('KeyW');
    return { x: p.x, z: p.z };
  }, { x, z, maxMs });
}
const shot = (u, name) => u.page.screenshot({ path: path.join(OUT, `share-${name}.png`) });

(async () => {
  let A, B;
  try {
    A = await openUser(process.env.CI_SID_A, 'A');
    B = await openUser(process.env.CI_SID_B, 'B');
    const nameA = await A.page.evaluate(() => window.ci.chat.me.nume);

    // 1. A merge la laptop și deschide consola (E).
    await walkTo(A.page, 0.92, -1.18);
    await A.page.waitForFunction(() => window.ci.interactions.current?.type === 'laptop', null, { timeout: 25000 });
    await A.page.keyboard.press('KeyE');
    await A.page.waitForSelector('[data-laptop]:not([hidden])', { timeout: 25000 });
    check(1, 'A deschide laptopul (E) → consola de prezentare', true, await A.page.textContent('[data-laptop-status]'));

    // 2. A partajează ecranul.
    await A.page.waitForSelector('[data-share-start]:not([disabled])', { timeout: 15000 });
    await A.page.click('[data-share-start]');
    await A.page.waitForSelector('[data-share-stop]:not([hidden])', { timeout: 25000 });
    check(2, 'A pornește partajarea ecranului', true, await A.page.textContent('[data-laptop-status]'));
    await shot(A, '02-A-laptop');

    // 3. B primește fluxul pe televizor (WebRTC real, cadre video decodate).
    await B.page.waitForFunction(() => window.ci.tv.video.videoWidth > 0 && window.ci.tv.material.map === window.ci.tv.videoTexture, null, { timeout: 60000 });
    const t1 = await B.page.evaluate(() => window.ci.tv.video.currentTime);
    await new Promise((r) => setTimeout(r, 2500));
    const vb = await B.page.evaluate(() => ({ w: window.ci.tv.video.videoWidth, h: window.ci.tv.video.videoHeight, t: window.ci.tv.video.currentTime, sharer: window.ci.share.share?.name }));
    check(3, 'B vede ecranul lui A pe televizorul din cameră (video live)', vb.w > 0 && vb.t > t1 && vb.sharer === nameA, `${vb.w}×${vb.h}, timp video ${t1.toFixed(1)}s → ${vb.t.toFixed(1)}s, prezintă: ${vb.sharer}`);

    // 4. B merge la televizor și îl privește de aproape (E).
    await walkTo(B.page, -0.8, -1.9);
    await B.page.evaluate(() => { window.ci.player.yaw = Math.atan2(-(1.03 - window.ci.player.x), -(-3.36 - window.ci.player.z)); });
    await new Promise((r) => setTimeout(r, 1500));
    await shot(B, '04-B-vede-televizorul');
    await B.page.waitForFunction(() => window.ci.interactions.current?.type === 'screen', null, { timeout: 25000 });
    await B.page.keyboard.press('KeyE');
    await B.page.waitForSelector('[data-screen-view]:not([hidden])', { timeout: 25000 });
    await B.page.waitForFunction(() => document.querySelector('[data-screen-view] video').videoWidth > 0, null, { timeout: 25000 });
    await new Promise((r) => setTimeout(r, 1500));
    check(4, 'B privește ecranul de aproape (E pe televizor)', true, await B.page.textContent('[data-screen-title]'));
    await shot(B, '04b-B-ecran-aproape');

    // 5. Un singur prezentator: B nu poate porni a doua partajare.
    const bDenied = await B.page.evaluate(async () => { try { await window.ci.share.start(); return 'pornit'; } catch (e) { return e.message; } });
    check(5, 'B nu poate prezenta cât timp prezintă A', bDenied !== 'pornit', bDenied);
    await B.page.keyboard.press('Escape');

    // 6. A oprește → televizorul revine în repaus la B.
    await A.page.click('[data-share-stop]');
    await B.page.waitForFunction(() => !window.ci.share.share && window.ci.tv.material.map === window.ci.tv.idleTexture, null, { timeout: 25000 });
    check(6, 'A oprește partajarea → televizorul revine în repaus', true, '');

    // 7. A pornește iar și se deconectează brusc → prezentarea se oprește pentru B.
    await A.page.click('[data-share-start]');
    await B.page.waitForFunction(() => window.ci.tv.video.videoWidth > 0, null, { timeout: 60000 });
    await A.browser.close(); A = null;
    await B.page.waitForFunction(() => !window.ci.share.share, null, { timeout: 30000 });
    check(7, 'Prezentatorul iese din cameră → prezentarea se încheie la ceilalți', true, '');
  } catch (e) {
    console.error('EROARE', e.message);
    check(99, 'Execuție', false, e.message.split('\n')[0]);
    if (A) await shot(A, 'eroare-A').catch(() => {});
    if (B) await shot(B, 'eroare-B').catch(() => {});
  } finally {
    await A?.browser.close().catch(() => {});
    await B?.browser.close().catch(() => {});
    const failed = results.filter((r) => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} verificări trecute.`);
    process.exit(failed ? 1 : 0);
  }
})();
