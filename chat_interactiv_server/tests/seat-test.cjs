'use strict';
/**
 * Test locuri + zona de prezentare (2 conturi reale, 2 browsere): A se așază pe un scaun de birou,
 * B îl vede așezat; A se ridică; B se așază pe canapea (vederea spre televizor) cât A prezintă;
 * A nu poate lua locul ocupat de B. Aceleași variabile ca acceptance.cjs; CI_BASE pe localhost
 * (context securizat) pentru partajarea ecranului.
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { chromium } = require(process.env.PW || 'playwright');

const BASE = process.env.CI_BASE || 'http://localhost/aplicatie_fleet/htdocs/index.php';
const OUT = process.env.CI_OUT || path.join(__dirname, 'out');
fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (n, name, ok, detail = '') => { results.push({ n, name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}. ${name}${detail ? ' — ' + detail : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const FAKE_SCREEN = () => {
  navigator.mediaDevices.getDisplayMedia = async () => {
    const c = document.createElement('canvas'); c.width = 1280; c.height = 720;
    const ctx = c.getContext('2d'); let f = 0;
    setInterval(() => { f++; ctx.fillStyle = '#1d4ed8'; ctx.fillRect(0, 0, 1280, 720); ctx.fillStyle = '#fff'; ctx.font = 'bold 80px sans-serif'; ctx.fillText('Ședință LPG', 80, 180); ctx.font = '54px sans-serif'; ctx.fillText('Slide ' + (1 + Math.floor(f / 60)), 80, 300); }, 66);
    return c.captureStream(15);
  };
};

async function openUser(sid, label) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lpgseat-'));
  const ctx = await chromium.launchPersistentContext(dir, { viewport: { width: 900, height: 560 }, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] });
  await ctx.addInitScript(() => { try { localStorage.setItem('ci.quality', 'low'); } catch (e) {} });
  await ctx.addInitScript(FAKE_SCREEN);
  await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: new URL(BASE).hostname, path: '/', httpOnly: true }]);
  const page = ctx.pages()[0] || await ctx.newPage();
  page.setDefaultTimeout(90000);
  page.on('pageerror', (e) => console.log(`[${label}] pageerror`, e.message));
  await page.goto(`${BASE}?page=chat_interactiv&debug=1`, { waitUntil: 'domcontentloaded' });
  try {
  const el = await page.waitForSelector(`[data-avatar-picker]:not([hidden]) [data-key="${label === 'A' ? 'avaturn_a' : 'avaturn_b'}"], [data-net-status][data-state="online"]`, { timeout: 240000 });
  if (await el.getAttribute('data-key')) await el.click();
  await page.waitForFunction(() => window.ci && document.querySelector('[data-net-status]').dataset.state === 'online', null, { timeout: 240000 });
  } catch (e) {
    await page.screenshot({ path: path.join(OUT, `seat-start-${label}.png`) }).catch(() => {});
    const st = await page.evaluate(() => ({ loading: document.querySelector('[data-loading-text]')?.textContent, net: document.querySelector('[data-net-status]')?.dataset.state })).catch(() => null);
    throw new Error(`${label} nu a pornit: ${JSON.stringify(st)} — ${e.message.split('\n')[0]}`);
  }
  return { close: async () => { await ctx.close(); fs.rmSync(dir, { recursive: true, force: true }); }, page };
}

async function walkPath(page, pts, maxMs = 25000) {
  return page.evaluate(async ({ pts, maxMs }) => {
    const p = window.ci.player;
    for (const [x, z] of pts) {
      const t0 = performance.now();
      while (performance.now() - t0 < maxMs) {
        const dx = x - p.x, dz = z - p.z;
        if (Math.hypot(dx, dz) < 0.1) break;
        p.yaw = Math.atan2(-dx, -dz); p.keys.add('KeyW');
        await new Promise((r) => setTimeout(r, 50));
      }
      p.keys.delete('KeyW');
    }
    return { x: p.x, z: p.z };
  }, { pts, maxMs });
}
const seatOf = (page, id) => page.evaluate((id) => window.ci.world.seats.find((s) => s.id === id), id);
const remoteSeat = (page, name) => page.evaluate((n) => { const r = [...window.ci.remotes.values()].find((x) => x.name === n); return r ? { seatIdx: r.seatIdx, x: r.snaps.at(-1).x, z: r.snaps.at(-1).z, sitW: r.inst?.weights.sit } : null; }, name);
const shot = (u, name) => u.page.screenshot({ path: path.join(OUT, `seat-${name}.png`) });

(async () => {
  let A, B;
  try {
    A = await openUser(process.env.CI_SID_A, 'A');
    B = await openUser(process.env.CI_SID_B, 'B');
    const nameA = await A.page.evaluate(() => window.ci.chat.me.nume);
    const nameB = await B.page.evaluate(() => window.ci.chat.me.nume);
    const scale = await A.page.evaluate(() => window.ci.world.scale);
    const bounds = await A.page.evaluate(() => { const b = window.ci.office.bounds; return [b.max.x - b.min.x, b.max.y - b.min.y, b.max.z - b.min.z].map((v) => v.toFixed(2)); });
    check(1, 'Biroul la mărime reală', scale > 1.1, `scară ${scale}, cameră ${bounds.join(' × ')} m`);

    // 2. A se așază pe scaunul 1 (birou).
    const s1 = await seatOf(A.page, 'scaun_1');
    await walkPath(A.page, [[-0.2, -1.38], [s1.x, -1.38]]);
    await A.page.waitForFunction(() => window.ci.interactions.current?.type === 'seat', null, { timeout: 25000 });
    await A.page.keyboard.press('KeyE');
    await A.page.waitForFunction(() => window.ci.player.seated?.id === 'scaun_1', null, { timeout: 20000 });
    await B.page.waitForFunction((n) => { const r = [...window.ci.remotes.values()].find((x) => x.name === n); return r && r.seatIdx === 1 && r.inst && r.inst.weights.sit > 0.9; }, nameA, { timeout: 30000 });
    const rs = await remoteSeat(B.page, nameA);
    check(2, 'A se așază pe scaun (E); B îl vede așezat', true, `poziție văzută de B (${rs.x.toFixed(2)}, ${rs.z.toFixed(2)}) = loc (${s1.x.toFixed(2)}, ${s1.z.toFixed(2)})`);
    // B se uită spre A pentru captură
    await B.page.evaluate(({ x, z }) => { const p = window.ci.player; p.yaw = Math.atan2(p.x - x, p.z - z); p.distance = 2.2; }, s1);
    await sleep(2500);
    await shot(A, '02-A-asezat-vedere-proprie');
    await shot(B, '02-B-il-vede-pe-A-asezat');

    // 3. A se ridică (Space) → punct liber.
    await A.page.keyboard.down('Space'); await sleep(400); await A.page.keyboard.up('Space');
    await A.page.waitForFunction(() => !window.ci.player.seated, null, { timeout: 20000 });
    const standA = await A.page.evaluate(() => ({ x: window.ci.player.x, z: window.ci.player.z, blocked: window.ci.collider.blocked(window.ci.player.x, window.ci.player.z) }));
    await B.page.waitForFunction((n) => { const r = [...window.ci.remotes.values()].find((x) => x.name === n); return r && !r.seatIdx; }, nameA, { timeout: 20000 });
    check(3, 'A se ridică (Space) într-un punct liber', !standA.blocked, `(${standA.x.toFixed(2)}, ${standA.z.toFixed(2)})`);

    // 4. A prezintă de la laptop; B se așază pe canapea → vederea spre televizor.
    await walkPath(A.page, [[1.1, -1.38]]);
    await A.page.evaluate(async () => { await window.ci.share.start(); });
    await A.page.waitForFunction(() => window.ci.share.share?.id === window.ci.chat.me.id, null, { timeout: 20000 });
    const c2 = await seatOf(B.page, 'canapea_2');
    await walkPath(B.page, [[-0.2, -1.38], [c2.x - 0.45, -1.38]]);
    await B.page.evaluate((c) => { window.ci.net.send({ t: 'sit', seat: c.id }); }, c2);
    await B.page.waitForFunction(() => window.ci.player.seated?.id === 'canapea_2', null, { timeout: 20000 });
    await B.page.waitForFunction(() => window.ci.tv.video.videoWidth > 0, null, { timeout: 60000 });
    await sleep(3000);
    const spect = await B.page.evaluate(() => ({ spectate: window.ci.player.spectate, hint: document.querySelector('[data-seated-text]').textContent }));
    check(4, 'B pe canapea: vedere de spectator spre televizorul cu prezentarea', spect.spectate, spect.hint);
    await shot(B, '04-B-canapea-priveste-prezentarea');

    // 5. Locul ocupat nu poate fi luat.
    const denied = await A.page.evaluate((c) => new Promise((res) => {
      const on = (e) => { window.ci.net.removeEventListener('sit_denied', on); res(e.detail.reason); };
      window.ci.net.addEventListener('sit_denied', on);
      window.ci.net.send({ t: 'sit', seat: c.id });
      setTimeout(() => res('fără răspuns'), 8000);
    }), c2);
    check(5, 'Locul ocupat de B nu poate fi luat de A', /ocupat|Apropie/.test(denied), denied);

    // 6. V → camera liberă; B se ridică.
    await B.page.keyboard.press('KeyV');
    await sleep(1500);
    await shot(B, '06-B-canapea-camera-libera');
    await B.page.keyboard.down('KeyW'); await sleep(400); await B.page.keyboard.up('KeyW');
    await B.page.waitForFunction(() => !window.ci.player.seated, null, { timeout: 20000 });
    const standB = await B.page.evaluate(() => window.ci.collider.blocked(window.ci.player.x, window.ci.player.z));
    check(6, 'B se ridică de pe canapea (W) într-un punct liber', !standB, '');
    await A.page.evaluate(() => window.ci.share.stop());
  } catch (e) {
    console.error('EROARE', e.message.split('\n')[0]);
    check(99, 'Execuție', false, e.message.split('\n')[0]);
    if (A) await shot(A, 'eroare-A').catch(() => {});
    if (B) await shot(B, 'eroare-B').catch(() => {});
  } finally {
    await A?.close().catch(() => {});
    await B?.close().catch(() => {});
    const failed = results.filter((r) => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} verificări trecute.`);
    process.exit(failed ? 1 : 0);
  }
})();
