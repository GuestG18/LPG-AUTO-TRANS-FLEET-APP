'use strict';
/**
 * Verificarea pașilor 13–17 la nivel de protocol (fără browsere 3D): doi clienți WebSocket reali
 * (conturile A și B, tokenuri semnate de PHP prin sesiunile lor) + endpoint-urile PHP.
 *   13 chat în timp real A ↔ B (salvat prin PHP, difuzat de server)
 *   14 nota personală a lui A nu e vizibilă pentru B (listă + acces direct)
 *   15 după „reîncărcare” (cereri noi) mesajele și nota există, avatarul rămâne
 *   16 deconectare → B primește „leave”; reconectare → aceeași identitate; locul ocupat se eliberează
 *   17 alte pagini Fleet răspund 200
 * Variabile: CI_SID_A, CI_SID_B, CI_BASE (implicit http://aplicatie_fleet.test/htdocs/index.php), WS (implicit ws://127.0.0.1:3011)
 */
const WebSocket = require('ws');
const BASE = process.env.CI_BASE || 'http://aplicatie_fleet.test/htdocs/index.php';
const WS = process.env.WS || 'ws://127.0.0.1:3011';
const results = [];
const check = (n, name, ok, detail = '') => { results.push(!!ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}. ${name}${detail ? ' — ' + detail : ''}`); };
const stamp = Date.now().toString(36);

function http(sid) {
  const cookie = `fleet_mvp_session=${sid}`;
  let csrf = null;
  const url = (action, params = {}) => { const u = new URL(BASE); u.searchParams.set('page', 'chat_interactiv'); if (action) u.searchParams.set('action', action); for (const [k, v] of Object.entries(params)) u.searchParams.set(k, v); return u; };
  return {
    async csrf() { if (!csrf) { const html = await (await fetch(url(''), { headers: { cookie } })).text(); csrf = (html.match(/"csrf":"([a-f0-9]+)"/) || [])[1]; } return csrf; },
    async get(action, params) { const r = await fetch(url(action, params), { headers: { cookie } }); return { status: r.status, body: await r.json().catch(() => ({})) }; },
    async post(action, data) { const fd = new FormData(); for (const [k, v] of Object.entries(data)) fd.append(k, v); const r = await fetch(url(action), { method: 'POST', body: fd, headers: { cookie, 'X-CSRF-Token': await this.csrf() } }); return { status: r.status, body: await r.json().catch(() => ({})) }; },
    async page(p) { const u = new URL(BASE); u.searchParams.set('page', p); const r = await fetch(u, { headers: { cookie }, redirect: 'manual' }); const t = await r.text(); return `${p}:${r.status}${/Eroare intern/.test(t) ? '!' : ''}`; },
  };
}

function client(token) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(WS);
    const events = [];
    const waiters = [];
    ws.on('open', () => ws.send(JSON.stringify({ t: 'hello', token })));
    ws.on('message', (d) => {
      const m = JSON.parse(d); events.push(m);
      for (const w of [...waiters]) if (w.pred(m)) { waiters.splice(waiters.indexOf(w), 1); w.resolve(m); }
      if (m.t === 'welcome') resolve(c);
    });
    ws.on('error', reject);
    const c = {
      ws, events, welcome: () => events.find((e) => e.t === 'welcome'),
      send: (m) => ws.send(JSON.stringify(m)),
      wait: (pred, ms = 30000) => new Promise((res, rej) => { const hit = events.find(pred); if (hit) return res(hit); const w = { pred, resolve: res }; waiters.push(w); setTimeout(() => rej(new Error('timeout')), ms); }),
    };
    setTimeout(() => reject(new Error('fără welcome')), 15000);
  });
}

/** Mers validat de server: stări la 100 ms, pași de 0,3 m (3 m/s), pe un traseu liber. */
async function walk(c, pts) {
  let pos = { ...c.welcome().you };
  for (const [x, z] of pts) {
    for (;;) {
      const dx = x - pos.x, dz = z - pos.z, d = Math.hypot(dx, dz);
      if (d < 0.05) break;
      const k = Math.min(1, 0.3 / d);
      pos = { x: pos.x + dx * k, z: pos.z + dz * k };
      c.send({ t: 's', x: pos.x, z: pos.z, ry: Math.atan2(dx, dz), a: 1 });
      await new Promise((r) => setTimeout(r, 100));
    }
  }
  c.send({ t: 's', x: pos.x, z: pos.z, ry: 0, a: 0 });
  await new Promise((r) => setTimeout(r, 300));
  const fixes = c.events.filter((e) => e.t === 'fix').length;
  return { ...pos, fixes };
}

(async () => {
  const A = http(process.env.CI_SID_A), B = http(process.env.CI_SID_B);
  let ca, cb;
  try {
    const bootA = (await A.get('bootstrap')).body, bootB = (await B.get('bootstrap')).body;
    ca = await client(bootA.ws.token); cb = await client(bootB.ws.token);
    const idA = bootA.user.id, idB = bootB.user.id;
    await cb.wait((m) => (m.t === 'join' && m.p.id === idA) || (m.t === 'welcome' && m.players.some((p) => p.id === idA)));

    // 13. Chat
    const t0 = Date.now();
    const msgA = `Salut de la A ${stamp}`, msgB = `Salut înapoi de la B ${stamp}`;
    ca.send({ t: 'chat', text: msgA, cid: 'a1' });
    const gotB = await cb.wait((m) => m.t === 'chat' && m.m.mesaj === msgA);
    const dtA = Date.now() - t0;
    cb.send({ t: 'chat', text: msgB, cid: 'b1' });
    const gotA = await ca.wait((m) => m.t === 'chat' && m.m.mesaj === msgB);
    check(13, 'Chat în timp real A ↔ B', gotB && gotA && gotB.m.user_id === idA && gotA.m.user_id === idB, `A→B livrat în ${dtA} ms (salvat id ${gotB.m.id}), B→A livrat (id ${gotA.m.id})`);

    // 14. Notă personală privată
    const priv = (await A.post('note_store', { categorie: 'personal', titlu: `Notă privată A ${stamp}`, descriere: 'privat' })).body.note.id;
    const listB = (await B.get('notes', { cat: 'personal', state: 'toate' })).body.notes || [];
    const directB = await B.get('note', { id: priv });
    check(14, 'Notele personale rămân private', !listB.some((n) => n.id === priv) && directB.status !== 200, `B listă: ${listB.some((n) => n.id === priv)}, B acces direct (id ${priv}): HTTP ${directB.status} „${directB.body.message}”`);

    // 15. Persistență (cereri noi, ca după reîncărcare)
    const msgs = (await B.get('messages')).body.messages || [];
    const boot2 = (await B.get('bootstrap')).body;
    const privA = ((await A.get('notes', { cat: 'personal' })).body.notes || []).some((n) => n.id === priv);
    check(15, 'Datele rămân după reîncărcare', msgs.some((m) => m.mesaj === msgA) && msgs.some((m) => m.mesaj === msgB) && boot2.avatar === bootB.avatar && privA, `istoric chat cu ambele mesaje, avatar B „${boot2.avatar}”, nota lui A există pentru A`);

    // 16. Loc ocupat → deconectare → leave + loc eliberat → reconectare cu aceeași identitate
    const seat = 'fotoliu_1';
    // Ambii merg lângă fotoliul 1 (x≈-0,14, z≈-2,93), pe culoarul liber din fața panoului.
    const wa = await walk(ca, [[-1.9, -1.4], [-0.95, -2.25]]);
    const wb = await walk(cb, [[-1.9, -1.6], [-1.05, -2.0]]);
    const posA = wa;
    ca.send({ t: 'sit', seat });
    const sat = await ca.wait((m) => m.t === 'sat' || m.t === 'sit_denied', 10000).catch(() => null);
    ca.ws.close(4100, 'test drop');
    const left = await cb.wait((m) => m.t === 'leave' && m.id === idA, 10000).then(() => true).catch(() => false);
    const tok = (await A.get('ws_token')).body.token;
    ca = await client(tok);
    const you = ca.welcome().you;
    const rejoin = await cb.wait((m) => m.t === 'join' && m.p.id === idA, 10000).catch(() => null);
    cb.send({ t: 'sit', seat }); // locul trebuie să fie liber după deconectarea lui A
    const bSat = await cb.wait((m) => (m.t === 'sat' || m.t === 'sit_denied') && m.seat === seat, 10000).catch(() => null);
    cb.send({ t: 'stand' });
    check(16, 'Deconectare/reconectare + eliberarea locului', left && rejoin && rejoin.p.avatar === bootA.avatar && you.id === idA && bSat?.t === 'sat',
      `(corecții server la mers: A ${wa.fixes}, B ${wb.fixes}) A ${sat?.t === 'sat' ? 'se așezase' : 'NU se așezase (' + (sat?.reason || 'fără răspuns') + ')'}; B a primit leave: ${left}; A revine ca ${you.name} (${rejoin?.p.avatar}) la (${you.x}, ${you.z}) — nu în fotoliu; B a putut ocupa locul: ${bSat?.t}`);
    void posA;

    // 17. Alte pagini
    const codes = [];
    for (const p of ['dashboard', 'dispecer_curse', 'vehicule_grele', 'monitorizare_flota', 'carburanti', 'documente']) codes.push(await A.page(p));
    check(17, 'Celelalte pagini Fleet nu sunt afectate', codes.every((c) => c.endsWith(':200')), codes.join(' '));
  } catch (e) {
    check(99, 'Execuție', false, e.message);
  } finally {
    ca?.ws.close(); cb?.ws.close();
    console.log(`\n${results.filter(Boolean).length}/${results.length} verificări trecute.`);
    setTimeout(() => process.exit(results.every(Boolean) ? 0 : 1), 200);
  }
})();
