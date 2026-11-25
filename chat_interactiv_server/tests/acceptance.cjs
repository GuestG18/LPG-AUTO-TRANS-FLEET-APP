'use strict';
/**
 * Test de acceptanță LPG Connect cu DOUĂ conturi reale în două browsere izolate (Playwright).
 *
 *   1) php make-test-sessions.php <id_A> <id_B>   -> {"a": sid, "b": sid}
 *   2) CI_SID_A=... CI_SID_B=... PW=<cale playwright> node acceptance.cjs
 *
 * Variabile: CI_BASE (implicit http://aplicatie_fleet.test/htdocs/index.php), CI_OUT (capturi),
 *            PW (modulul playwright; implicit require('playwright')).
 * Contul A trebuie să aibă dreptul chat_interactiv.publish_news (ex. administrator); contul B poate fi
 * un utilizator obișnuit (verifică și restricțiile).
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(process.env.PW || 'playwright');

const BASE = process.env.CI_BASE || 'http://aplicatie_fleet.test/htdocs/index.php';
const OUT = process.env.CI_OUT || path.join(__dirname, 'out');
const SID_A = process.env.CI_SID_A, SID_B = process.env.CI_SID_B;
if (!SID_A || !SID_B) { console.error('Lipsesc CI_SID_A / CI_SID_B'); process.exit(2); }
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const stamp = Date.now().toString(36);
function check(n, name, ok, detail = '') { results.push({ n, name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${String(n).padStart(2)}. ${name}${detail ? ' — ' + detail : ''}`); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Fiecare utilizator în propriul proces de browser (identități și randare complet separate).
// CI_LIGHT=1: randare ~5 cadre/s într-o fereastră mică (lasă procesorul pentru PHP/MySQL).
// CI_FROM=11: sare peste verificările de mers/sincronizare/coliziuni (4–6); pașii necesari rămân.
const LIGHT = !!process.env.CI_LIGHT;
const LIGHT_MS = Math.max(50, Number(process.env.CI_LIGHT) > 1 ? Number(process.env.CI_LIGHT) : 180); // CI_LIGHT=1000 → ~1 cadru/s
const FROM = Number(process.env.CI_FROM || 1);
const LAUNCH = { args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] };
async function openUser(sid, label) {
  const host = new URL(BASE).hostname;
  const browser = await chromium.launch(LAUNCH);
  const ctx = await browser.newContext({ viewport: LIGHT ? { width: 640, height: 420 } : { width: 800, height: 520 } });
  // Grafică redusă: randarea software din testul automat rămâne fluidă.
  await ctx.addInitScript(() => { try { localStorage.setItem('ci.quality', 'low'); } catch (e) {} });
  if (LIGHT) await ctx.addInitScript((ms) => { const raf = window.requestAnimationFrame.bind(window); window.requestAnimationFrame = (cb) => setTimeout(() => raf(cb), ms); }, LIGHT_MS);
  await ctx.addCookies([{ name: 'fleet_mvp_session', value: sid, domain: host, path: '/', httpOnly: true }]);
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log(`[${label}] pageerror`, e.message));
  page.on('console', (m) => { if (m.type() === 'error') console.log(`[${label}] console`, m.text()); });
  page.setDefaultTimeout(90000);
  await page.goto(`${BASE}?page=chat_interactiv&debug=1`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  return { browser, ctx, page, label };
}

async function waitOnline(page, timeout = 240000) {
  try {
    await page.waitForFunction(() => window.ci && document.querySelector('[data-net-status]')?.dataset.state === 'online', null, { timeout });
  } catch (e) {
    const st = await page.evaluate(() => ({ loading: document.querySelector('[data-loading-text]')?.textContent, net: document.querySelector('[data-net-status]')?.dataset.state, picker: !document.querySelector('[data-avatar-picker]')?.hidden, ci: !!window.ci })).catch(() => null);
    throw new Error('nu a ajuns online: ' + JSON.stringify(st));
  }
}

/** Mers „cu tastele” spre (x,z): orientează camera și ține W apăsat (controlerul real + coliziuni). */
/** Scara lumii (world.json → scale): țintele de mai jos sunt în unitățile modelului. */
let S = 1;
async function walkTo(page, x, z, maxMs = 20000) {
  x *= S; z *= S;
  return page.evaluate(async ({ x, z, maxMs }) => {
    const p = window.ci.player; const t0 = performance.now();
    p.keys.add('KeyW');
    while (performance.now() - t0 < maxMs) {
      const dx = x - p.x, dz = z - p.z;
      if (Math.hypot(dx, dz) < 0.15) break;
      p.yaw = Math.atan2(-dx, -dz);
      p.keys.add('KeyW'); // re-apăsat la fiecare pas (un eveniment blur golește tastele)
      await new Promise((r) => setTimeout(r, 50));
    }
    p.keys.delete('KeyW');
    return { x: p.x, z: p.z };
  }, { x, z, maxMs });
}

const remoteOf = (page) => page.evaluate(() => {
  const out = [];
  for (const [id, r] of window.ci.remotes) { const s = r.snaps[r.snaps.length - 1]; out.push({ id, name: r.name, avatar: r.avatar, loaded: !!r.inst, x: s.x, z: s.z, a: s.a, busy: r.busy }); }
  return out;
});
const selfOf = (page) => page.evaluate(() => ({ x: window.ci.player.x, z: window.ci.player.z, avatar: window.ci.local.key }));
/** Așteaptă (max 6 s) ca jucătorul cu numele dat să fie văzut la (x,z) ± tol; întoarce eroarea finală. */
async function converge(page, name, x, z, tol = 0.15, ms = 6000) {
  const t0 = Date.now(); let err = Infinity;
  while (Date.now() - t0 < ms) {
    const r = (await remoteOf(page)).find((p) => p.name === name);
    if (r) { err = Math.hypot(r.x - x, r.z - z); if (err < tol) break; }
    await sleep(250);
  }
  return err;
}
/** Animația (0/1/2) cu care e văzut jucătorul, eșantionată până se termină `until`; întoarce maximul. */
async function maxAnimSeen(page, name, until) {
  let max = 0, done = false;
  until.then(() => { done = true; });
  while (!done) { const r = (await remoteOf(page)).find((p) => p.name === name); if (r) max = Math.max(max, r.a); await sleep(150); }
  return max;
}
const shot = (u, name) => u.page.screenshot({ path: path.join(OUT, `${name}.png`) });

(async () => {
  let A, B;
  try {
    // 1. A intră cu avatarul A (persistat dinainte sau ales acum).
    A = await openUser(SID_A, 'A');
    const pickerA = await A.page.waitForSelector('[data-avatar-picker]:not([hidden]) [data-key="avaturn_a"], [data-net-status][data-state="online"]', { timeout: 240000 }).catch(() => null);
    if (pickerA && await pickerA.getAttribute('data-key')) await pickerA.click();
    await waitOnline(A.page);
    S = await A.page.evaluate(() => window.ci.world.scale || 1);
    const selfA = await selfOf(A.page);
    check(1, 'Utilizatorul A intră cu avatarul salvat în cont', ['avaturn_a', 'avaturn_b'].includes(selfA.avatar), `avatar=${selfA.avatar}`);

    // 2. B intră (alt cont, alt browser) și alege Avatar B.
    B = await openUser(SID_B, 'B');
    const pickerB = await B.page.waitForSelector('[data-avatar-picker]:not([hidden]) [data-key="avaturn_b"], [data-net-status][data-state="online"]', { timeout: 240000 }).catch(() => null);
    if (pickerB && await pickerB.getAttribute('data-key')) await pickerB.click();
    await waitOnline(B.page);
    const selfB = await selfOf(B.page);
    check(2, 'Utilizatorul B intră în aceeași cameră cu Avatar B', selfB.avatar === 'avaturn_b', `avatar=${selfB.avatar}`);

    // 3. Se văd reciproc, cu avatarul corect.
    await A.page.waitForFunction(() => [...window.ci.remotes.values()].some((r) => r.inst), null, { timeout: 60000 });
    await B.page.waitForFunction(() => [...window.ci.remotes.values()].some((r) => r.inst), null, { timeout: 60000 });
    const selfNameA = await A.page.evaluate(() => window.ci.chat.me.nume), selfNameB = await B.page.evaluate(() => window.ci.chat.me.nume);
    await A.page.waitForFunction((n) => [...window.ci.remotes.values()].some((r) => r.name === n && r.inst), selfNameB, { timeout: 60000 });
    await B.page.waitForFunction((n) => [...window.ci.remotes.values()].some((r) => r.name === n && r.inst), selfNameA, { timeout: 60000 });
    const ra = await remoteOf(A.page), rb = await remoteOf(B.page);
    check(3, 'Ambii se văd reciproc (avatar corect)', ra.some((r) => r.name === selfNameB && r.avatar === selfB.avatar && r.loaded) && rb.some((r) => r.name === selfNameA && r.avatar === selfA.avatar && r.loaded), `A vede ${JSON.stringify(ra.map((r) => [r.name, r.avatar]))}, B vede ${JSON.stringify(rb.map((r) => [r.name, r.avatar]))}`);
    await shot(A, '03-A-vede-B'); await shot(B, '03-B-vede-A');

    // Identificare după numele contului (în cameră pot fi și alți colegi reali).
    const nameA = await A.page.evaluate(() => window.ci.chat.me.nume);
    const nameB = await B.page.evaluate(() => window.ci.chat.me.nume);
    if (FROM <= 6) {
    // 4 + 5. Mers independent + sincronizare poziție și animație.
    const startA = await selfOf(A.page), startB = await selfOf(B.page);
    const far = (p, a, b) => (Math.hypot(p.x - a[0] * S, p.z - a[1] * S) >= Math.hypot(p.x - b[0] * S, p.z - b[1] * S) ? a : b); // ținta mai îndepărtată
    const tA = far(startA, [-1.7, -2.6], [-0.6, -1.0]), tB = far(startB, [0.0, -1.0], [-1.6, -2.4]);
    const walkAP = walkTo(A.page, tA[0], tA[1]);
    const animWhileWalking = await maxAnimSeen(B.page, nameA, walkAP); // B îl vede pe A mergând (pe toată durata)
    const endA = await walkAP;
    const endB = await walkTo(B.page, tB[0], tB[1]);
    const errA = await converge(B.page, nameA, endA.x, endA.z);
    const errB = await converge(A.page, nameB, endB.x, endB.z);
    await sleep(1200);
    const seenByB = (await remoteOf(B.page)).find((p) => p.name === nameA);
    const movedA = Math.hypot(endA.x - startA.x, endA.z - startA.z), movedB = Math.hypot(endB.x - startB.x, endB.z - startB.z);
    check(4, 'Ambii merg independent', movedA > 0.3 && movedB > 0.3 && Math.hypot(endA.x - tA[0] * S, endA.z - tA[1] * S) < 0.3 && Math.hypot(endB.x - tB[0] * S, endB.z - tB[1] * S) < 0.3, `A ${movedA.toFixed(2)} m, B ${movedB.toFixed(2)} m`);
    check(5, 'Poziție + animație sincronizate', errA < 0.15 && errB < 0.15 && animWhileWalking >= 1 && seenByB.a === 0, `eroare A ${errA.toFixed(3)} m, B ${errB.toFixed(3)} m; anim A văzută de B: în mers=${animWhileWalking}, oprit=${seenByB.a}`);
    await shot(B, '05-B-vede-A-dupa-mers');

    // 6. Coliziuni: client (mers spre birou) + server (stare falsă în birou respinsă).
    const intoDesk = await walkTo(A.page, 1.0, 0.0, 7000); // biroul 1: x -0.17..2.28, z -0.46..0.47
    const insideDesk = (p) => p.x > -0.17 * S && p.x < 2.28 * S && p.z > -0.46 * S && p.z < 0.47 * S;
    const blocked = await A.page.evaluate(({ x, z }) => window.ci.collider.blocked(x, z), intoDesk);
    await A.page.evaluate((S) => window.ci.net.ws.send(JSON.stringify({ t: 's', x: 1.0 * S, z: 0.0, ry: 0, a: 1 })), S);
    await sleep(600);
    const seenFake = (await remoteOf(B.page)).find((p) => p.name === nameA);
    check(6, 'Nu trece prin pereți / mobilier (client + server)', !insideDesk(intoDesk) && !blocked && !insideDesk(seenFake), `A s-a oprit la (${intoDesk.x.toFixed(2)}, ${intoDesk.z.toFixed(2)}); poziția falsă trimisă în birou → B o vede la (${seenFake.x}, ${seenFake.z})`);
    } // FROM <= 6

    // 7 + 8. A merge la panou și îl deschide (E).
    await walkTo(A.page, -1.5, -1.6); await walkTo(A.page, -2.3, -0.3);
    await A.page.waitForSelector('[data-prompt]:not([hidden])', { timeout: 25000 });
    const promptText = await A.page.textContent('[data-prompt]');
    check(7, 'A se apropie de panou → apare promptul', /Deschide panoul informativ/.test(promptText), promptText.trim().replace(/\s+/g, ' '));
    await A.page.keyboard.press('KeyE');
    await A.page.waitForSelector('[data-notes]:not([hidden])', { timeout: 25000 });
    await sleep(800);
    check(8, 'A deschide interfața notelor (tranziție cameră)', await A.page.evaluate(() => !!window.ci.interactions.active && !window.ci.player.enabled), '');
    await shot(A, '08-A-panou-deschis');

    // 9. B se mișcă normal cât timp A e la panou.
    const b0 = await selfOf(B.page);
    const b1 = await walkTo(B.page, -1.0, -1.9);
    const err9 = await converge(A.page, nameB, b1.x, b1.z);
    const aBusy = (await remoteOf(B.page)).find((p) => p.name === nameA)?.busy === 1; // 1 = la panou (2 laptop, 3 ecran)
    check(9, 'B se mișcă normal în timp ce A e la panou', Math.hypot(b1.x - b0.x, b1.z - b0.z) > 0.5 && err9 < 0.15 && aBusy, `B a mers ${Math.hypot(b1.x - b0.x, b1.z - b0.z).toFixed(2)} m (A îl vede cu eroare ${err9.toFixed(3)} m); B îl vede pe A „la panou”: ${aBusy}`);

    // 10. B deschide panoul independent (simultan cu A).
    await walkTo(B.page, -2.2, -1.25);
    await B.page.waitForSelector('[data-prompt]:not([hidden])', { timeout: 25000 });
    await B.page.keyboard.press('KeyE');
    await B.page.waitForSelector('[data-notes]:not([hidden])', { timeout: 25000 });
    await sleep(800);
    const bothOpen = await A.page.evaluate(() => !!window.ci.interactions.active) && await B.page.evaluate(() => !!window.ci.interactions.active);
    const bCanPublish = await B.page.isVisible('[data-notes] [data-new]');
    check(10, 'B deschide panoul independent (ambii simultan)', bothOpen && !bCanPublish, `B (fără drept publish_news) nu vede butonul „Publică noutate”: ${!bCanPublish}`);
    await shot(B, '10-B-panou-deschis');

    // 11. A publică o noutate generală (autorizat).
    const title = `Test noutate ${stamp}`;
    await A.page.click('[data-notes] [data-new]');
    await A.page.fill('[data-notes] form [name="titlu"]', title);
    await A.page.fill('[data-notes] form [name="descriere"]', 'Publicată de testul de acceptanță.');
    await A.page.selectOption('[data-notes] form [name="importanta"]', 'critica');
    await A.page.click('[data-notes] form [type="submit"]');
    await A.page.waitForSelector(`[data-notes] .ci-note-card:has-text("${title}")`, { timeout: 90000 }); // PHP poate fi lent sub sarcină
    check(11, 'A publică o noutate generală', true, title);

    // 12. B o vede automat (fără reîncărcare): în listă + pe panoul 3D.
    await B.page.waitForSelector(`[data-notes] .ci-note-card:has-text("${title}")`, { timeout: 90000 }).catch(() => null);
    const bList = await B.page.isVisible(`[data-notes] .ci-note-card:has-text("${title}")`);
    const bBoard = await B.page.evaluate(async (t) => (await window.ci.api.get('board')).items.some((i) => i.titlu === t), title);
    check(12, 'B vede noua noutate automat', bList && bBoard, `în lista deschisă: ${bList}, pe panoul 3D: ${bBoard}`);
    await shot(B, '12-B-vede-noutatea');

    // Închidere panouri (Esc) → camera revine.
    await A.page.keyboard.press('Escape'); await B.page.keyboard.press('Escape');
    const closed = await A.page.waitForFunction(() => !window.ci.interactions.active && window.ci.player.enabled && !window.ci.player.focus, null, { timeout: 25000 }).then(() => true).catch(() => false);
    await B.page.waitForFunction(() => !window.ci.interactions.active, null, { timeout: 25000 }).catch(() => {});
    check(12.5, 'Esc închide panoul și readuce camera la persoana a treia', closed, '');

    // 13. Chat în ambele sensuri.
    const msgA = `Salut de la A ${stamp}`, msgB = `Salut înapoi de la B ${stamp}`;
    await A.page.keyboard.press('Enter');
    await A.page.fill('[data-chat] [data-input]', msgA); await A.page.press('[data-chat] [data-input]', 'Enter');
    await B.page.waitForFunction((m) => window.ci.chat.messages.some((x) => x.mesaj === m), msgA, { timeout: 25000 });
    const unreadB = await B.page.textContent('[data-chat] [data-unread]');
    await B.page.keyboard.press('Enter');
    await B.page.fill('[data-chat] [data-input]', msgB); await B.page.press('[data-chat] [data-input]', 'Enter');
    await A.page.waitForFunction((m) => window.ci.chat.messages.some((x) => x.mesaj === m), msgB, { timeout: 25000 });
    check(13, 'Schimb de mesaje în chat (timp real)', true, `B a primit mesajul lui A (necitite înainte de deschidere: ${unreadB}); A a primit răspunsul.`);
    await shot(A, '13-A-chat');

    // 14. Notele personale rămân private.
    const priv = await A.page.evaluate(async (t) => {
      const fd = new FormData(); fd.append('categorie', 'personal'); fd.append('titlu', t); fd.append('descriere', 'privat');
      return (await window.ci.api.post('note_store', fd)).note.id;
    }, `Notă privată A ${stamp}`);
    const leak = await B.page.evaluate(async (id) => {
      const list = await window.ci.api.get('notes', { cat: 'personal', state: 'toate' });
      let detail; try { await window.ci.api.get('note', { id }); detail = 'VIZIBIL'; } catch (e) { detail = e.message; }
      return { inList: list.notes.some((n) => n.id === id), detail };
    }, priv);
    check(14, 'Notele personale rămân private', !leak.inList && leak.detail !== 'VIZIBIL', `B listă: ${leak.inList}, B detaliu direct (id ${priv}): „${leak.detail}”`);

    // 15. Reîncărcarea păstrează datele (avatar, chat, note).
    await B.page.reload({ waitUntil: 'domcontentloaded' });
    await waitOnline(B.page);
    const after = await B.page.evaluate(async (args) => ({
      avatar: window.ci.local.key,
      chat: window.ci.chat.messages.some((m) => m.mesaj === args.msgA) && window.ci.chat.messages.some((m) => m.mesaj === args.msgB),
      note: (await window.ci.api.get('notes', { cat: 'noutate' })).notes.some((n) => n.titlu === args.title),
      picker: !document.querySelector('[data-avatar-picker]').hidden,
    }), { msgA, msgB, title });
    check(15, 'Reîncărcarea păstrează datele', after.avatar === selfB.avatar && after.chat && after.note && !after.picker, JSON.stringify(after));

    // 16. Deconectare / reconectare.
    const aId = await A.page.evaluate(() => 0);
    await A.page.evaluate(() => window.ci.net.ws.close(4100, 'test drop'));
    await B.page.waitForFunction((n) => ![...window.ci.remotes.values()].some((r) => r.name === n), nameA, { timeout: 25000 });
    const leftOk = true;
    await A.page.waitForFunction(() => document.querySelector('[data-net-status]').dataset.state === 'online', null, { timeout: 20000 });
    await B.page.waitForFunction((n) => [...window.ci.remotes.values()].some((r) => r.name === n && r.inst), nameA, { timeout: 25000 });
    const back = (await remoteOf(B.page)).find((p) => p.name === nameA);
    const posA = await selfOf(A.page);
    check(16, 'Deconectare → dispare; reconectare → aceeași identitate', leftOk && back.avatar === selfA.avatar && Math.hypot(back.x - posA.x, back.z - posA.z) < 0.3, `B îl revede pe ${back.name} (${back.avatar}) la poziția de dinainte`);
    void aId;

    // 17. Celelalte pagini Fleet funcționează (aceeași sesiune A), verificate după închiderea lumii 3D.
    await B.browser.close(); B = null;
    await A.page.goto('about:blank');
    const pages = ['dashboard', 'dispecer_curse', 'vehicule_grele', 'monitorizare_flota', 'carburanti', 'documente'];
    const codes = [];
    for (const p of pages) {
      const r = await A.ctx.request.get(`${BASE}?page=${p}`, { timeout: 120000 });
      const html = await r.text();
      codes.push(`${p}:${r.status()}${/Eroare intern/.test(html) ? '!' : ''}`);
    }
    check(17, 'Celelalte pagini Fleet nu sunt afectate', codes.every((c) => c.endsWith(':200')), codes.join(' '));
  } catch (e) {
    console.error('EROARE', e);
    check(99, 'Execuție test', false, e.message);
    if (A) await shot(A, 'eroare-A').catch(() => {});
    if (B) await shot(B, 'eroare-B').catch(() => {});
  } finally {
    await A?.browser.close().catch(() => {});
    await B?.browser.close().catch(() => {});
    const failed = results.filter((r) => !r.ok);
    fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
    console.log(`\n${results.length - failed.length}/${results.length} verificări trecute. Capturi: ${OUT}`);
    process.exit(failed.length ? 1 : 0);
  }
})();
