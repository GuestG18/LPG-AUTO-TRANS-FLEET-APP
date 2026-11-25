'use strict';
/**
 * LPG Connect — server multiplayer pentru pagina „Chat Interactiv”.
 *
 * Server WebSocket autoritar, ușor (doar `ws`), fără GPU, fără acces la baza de date:
 *   - identitatea vine dintr-un token scurt semnat HMAC-SHA256 de PHP (CHAT_INTERACTIV_SECRET);
 *   - poziția fiecărui jucător e validată: viteză maximă, limitele camerei, obstacolele din world.json
 *     (același fișier pe care îl folosește clientul pentru coliziuni);
 *   - mesajele de chat se salvează prin API-ul intern PHP (/api/chat-interactiv/v1/messages), apoi se
 *     difuzează; serverul nu vede niciodată credențialele bazei de date sau sesiunile aplicației;
 *   - schimbările de pe panoul informativ se anunță ca „board” → clienții recitesc din PHP (cu ACL).
 *
 * Pornire:  cd chat_interactiv_server && npm install && npm start
 * Config:   .env din rădăcina proiectului (CHAT_INTERACTIV_SECRET, CHAT_INTERACTIV_WS_PORT, CHAT_INTERACTIV_PHP_URL)
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');

const ROOT = path.resolve(__dirname, '..');
const env = loadEnv(path.join(ROOT, '.env'));
const SECRET = (process.env.CHAT_INTERACTIV_SECRET || env.CHAT_INTERACTIV_SECRET || '').trim();
const PORT = parseInt(process.env.CHAT_INTERACTIV_WS_PORT || env.CHAT_INTERACTIV_WS_PORT || '3011', 10);
const PHP_URL = (process.env.CHAT_INTERACTIV_PHP_URL || env.CHAT_INTERACTIV_PHP_URL || 'http://127.0.0.1/htdocs').replace(/\/+$/, '');
const WORLD = scaleWorld(JSON.parse(fs.readFileSync(path.join(ROOT, 'htdocs', 'assets', 'js', 'chat-interactiv', 'world.json'), 'utf8')));

/** Identică cu scaleWorld din htdocs/assets/js/chat-interactiv/world.js (coordonate model → mărime reală). */
function scaleWorld(world) {
  const s = Number(world.scale) || 1;
  const KEYS = new Set(['minX', 'maxX', 'minY', 'maxY', 'minZ', 'maxZ', 'height', 'x', 'y', 'z', 'width', 'radius', 'h']);
  const walk = (v, key) => {
    if (Array.isArray(v)) return v.map((x) => walk(x, null));
    if (v && typeof v === 'object') {
      if (key === 'normal' || key === 'player') return v;
      const o = {};
      for (const [k, x] of Object.entries(v)) o[k] = typeof x === 'number' && KEYS.has(k) ? x * s : walk(x, k);
      return o;
    }
    return v;
  };
  return { ...walk(world, null), scale: s };
}

if (!SECRET) {
  console.error('[lpg-connect] CHAT_INTERACTIV_SECRET lipsește din .env — serverul nu poate verifica identitatea.');
  process.exit(1);
}

const TICK_MS = 100;                    // 10 Hz difuzare poziții
const MAX_SPEED = WORLD.player.maxServerSpeed || 4.2; // m/s (clientul merge 1.7, aleargă 3.4)
const RADIUS = WORLD.player.radius || 0.22;
const MAX_STATE_PER_SEC = 25;
const CHAT_WINDOW_MS = 10000;
const CHAT_MAX_IN_WINDOW = 6;
const CHAT_MAX_LEN = 1000;
const LAST_POS_TTL_MS = 15 * 60 * 1000; // reconectare: poziția anterioară se păstrează 15 min
const MAX_PLAYERS = WORLD.maxPlayers || 16;

/** @type {Map<number, Player>} */
const players = new Map();
/** uid -> {x,z,ry,t} */
const lastKnown = new Map();
/** nonce -> exp (tokenurile sunt de unică folosință) */
const usedNonces = new Map();
let dirty = new Set();
/** Prezentarea curentă pe ecranul din cameră: un singur prezentator odată. */
let share = null; // {id, name, since}
const RTC_MAX_PER_SEC = 60;

function log(...a) { console.log(new Date().toISOString(), '[lpg-connect]', ...a); }

// ------------------------------------------------------------------ token

function b64urlDecode(s) {
  return Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

function verifyToken(token) {
  if (typeof token !== 'string' || token.length > 2048 || token.indexOf('.') < 0) return null;
  const [body, sig] = token.split('.');
  const expected = crypto.createHmac('sha256', SECRET).update(body).digest();
  const given = b64urlDecode(sig || '');
  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return null;
  let p;
  try { p = JSON.parse(b64urlDecode(body).toString('utf8')); } catch { return null; }
  const now = Math.floor(Date.now() / 1000);
  if (!p || typeof p.uid !== 'number' || p.uid <= 0 || typeof p.exp !== 'number' || p.exp < now) return null;
  if (p.room !== WORLD.room) return null;
  if (!WORLD.avatars.includes(p.avatar)) return null;
  if (usedNonces.has(p.nonce)) return null;
  usedNonces.set(p.nonce, p.exp);
  return { uid: p.uid, name: String(p.name || '').slice(0, 80), avatar: p.avatar, canShare: p.share === true };
}

// ------------------------------------------------------------------ lume / coliziuni

function clampToWalkable(x, z) {
  const w = WORLD.walkable;
  return [Math.min(w.maxX - RADIUS, Math.max(w.minX + RADIUS, x)), Math.min(w.maxZ - RADIUS, Math.max(w.minZ + RADIUS, z))];
}

// Locuri (scaune, canapea, fotolii): un singur ocupant; poziția de ridicare o alege serverul.
const SEATS = new Map((WORLD.seats || []).map((s, i) => [s.id, { ...s, index: i + 1 }]));
const seatOwner = new Map(); // id loc -> uid
const SIT_REACH = 1.9;       // m: cât de departe poate fi jucătorul când cere locul

function freeSpot(x, z) {
  const w = WORLD.walkable;
  if (x < w.minX + RADIUS || x > w.maxX - RADIUS || z < w.minZ + RADIUS || z > w.maxZ - RADIUS) return false;
  for (const o of WORLD.obstacles) {
    const cx = Math.max(o.minX, Math.min(x, o.maxX)), cz = Math.max(o.minZ, Math.min(z, o.maxZ));
    if ((x - cx) ** 2 + (z - cz) ** 2 < (RADIUS + 0.03) ** 2) return false;
  }
  return true;
}

/** Punctul liber cel mai apropiat de loc: de unde a venit jucătorul, altfel în spate, lateral, în față. */
function exitPoint(seat, prefer) {
  if (prefer && Math.hypot(prefer.x - seat.x, prefer.z - seat.z) < 1.6 && freeSpot(prefer.x, prefer.z)) return prefer;
  const dirs = [Math.PI, Math.PI / 2, -Math.PI / 2, 0, (3 * Math.PI) / 4, -(3 * Math.PI) / 4, Math.PI / 4, -Math.PI / 4];
  for (const d of [0.6, 0.8, 1.0, 1.25]) {
    for (const a of dirs) {
      const ang = seat.ry + a;
      const x = seat.x + Math.sin(ang) * d, z = seat.z + Math.cos(ang) * d;
      if (freeSpot(x, z)) return { x, z };
    }
  }
  return WORLD.spawns[0];
}

function leaveSeat(player, broadcastIt = true) {
  if (!player.seat) return null;
  const seat = SEATS.get(player.seat);
  seatOwner.delete(player.seat);
  player.seat = null;
  const out = exitPoint(seat, player.sitFrom);
  player.x = out.x; player.z = out.z; player.anim = 0;
  player.lastStateAt = Date.now();
  if (broadcastIt) dirty.add(player.id);
  return out;
}

function hitsObstacle(x, z) {
  for (const o of WORLD.obstacles) {
    const cx = Math.max(o.minX, Math.min(x, o.maxX));
    const cz = Math.max(o.minZ, Math.min(z, o.maxZ));
    const dx = x - cx, dz = z - cz;
    if (dx * dx + dz * dz < (RADIUS - 0.04) * (RADIUS - 0.04)) return true; // mică toleranță la margine
  }
  return false;
}

function pickSpawn() {
  // Primul punct de apariție liber (fără alt jucător la < 0.8 m); altfel primul.
  for (const s of WORLD.spawns) {
    let free = true;
    for (const p of players.values()) if (Math.hypot(p.x - s.x, p.z - s.z) < 0.8) { free = false; break; }
    if (free) return s;
  }
  return WORLD.spawns[0];
}

// ------------------------------------------------------------------ PHP

async function persistMessage(uid, text, attempt = 1) {
  let res;
  try {
    res = await fetch(PHP_URL + '/api/chat-interactiv/v1/messages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Chat-Token': SECRET },
      body: JSON.stringify({ user_id: uid, text }),
      signal: AbortSignal.timeout(25000), // PHP poate fi încetinit de alte module sub sarcină
    });
  } catch (e) {
    // Eroare de rețea / PHP ocupat: o singură reîncercare (fără dublură: nimic nu s-a salvat).
    if (attempt < 2 && e.name !== 'AbortError' && e.name !== 'TimeoutError') return persistMessage(uid, text, attempt + 1);
    throw e;
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.ok) throw new Error('php ' + res.status + ' ' + (data.error || ''));
  return data.message;
}

// ------------------------------------------------------------------ server

class Player {
  constructor(ws, ident) {
    this.ws = ws;
    this.id = ident.uid;
    this.name = ident.name;
    this.avatar = ident.avatar;
    const prev = lastKnown.get(this.id);
    const spawn = prev && Date.now() - prev.t < LAST_POS_TTL_MS ? prev : pickSpawn();
    this.x = spawn.x; this.z = spawn.z; this.ry = spawn.ry || 0;
    this.anim = 0;      // 0 = idle, 1 = mers, 2 = alergare, 3 = șezut
    this.seat = null;   // id-ul locului ocupat
    this.sitFrom = null;
    this.busy = 0;      // 0 liber, 1 la panou, 2 la laptop, 3 privește ecranul
    this.canShare = ident.canShare;
    this.rtcBudget = { t: Date.now(), n: 0 };
    this.lastStateAt = Date.now();
    this.stateBudget = { t: Date.now(), n: 0 };
    this.chatTimes = [];
  }
  pub() { return { id: this.id, name: this.name, avatar: this.avatar, x: r2(this.x), z: r2(this.z), ry: r2(this.ry), a: this.anim, b: this.busy, s: this.seat ? SEATS.get(this.seat).index : 0 }; }
}

function r2(v) { return Math.round(v * 100) / 100; }
function send(ws, msg) { if (ws.readyState === 1) ws.send(JSON.stringify(msg)); }
function broadcast(msg, exceptId = null) {
  const s = JSON.stringify(msg);
  for (const p of players.values()) if (p.id !== exceptId && p.ws.readyState === 1) p.ws.send(s);
}

const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, players: players.size, room: WORLD.room }));
    return;
  }
  res.writeHead(404); res.end();
});

const wss = new WebSocketServer({ server, maxPayload: 64 * 1024 }); // ofertele SDP (WebRTC) pot avea câțiva KB

wss.on('connection', (ws, req) => {
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
  let player = null;
  const helloTimer = setTimeout(() => { if (!player) ws.close(4000, 'hello timeout'); }, 5000);

  ws.on('message', async (raw) => {
    let m;
    try { m = JSON.parse(raw.toString('utf8')); } catch { return; }
    if (!m || typeof m.t !== 'string') return;

    if (!player) {
      if (m.t !== 'hello') return;
      const ident = verifyToken(m.token);
      if (!ident) { ws.close(4003, 'token invalid'); return; }
      const old = players.get(ident.uid);
      if (old) { send(old.ws, { t: 'replaced' }); old.replaced = true; old.ws.close(4001, 'replaced'); players.delete(ident.uid); broadcast({ t: 'leave', id: ident.uid }); }
      if (players.size >= MAX_PLAYERS) { ws.close(4002, 'room full'); return; }
      clearTimeout(helloTimer);
      player = new Player(ws, ident);
      players.set(player.id, player);
      send(ws, { t: 'welcome', you: player.pub(), players: [...players.values()].filter((p) => p !== player).map((p) => p.pub()), tick: TICK_MS, share });
      broadcast({ t: 'join', p: player.pub() }, player.id);
      log('intrare', player.id, player.name, player.avatar, '· online', players.size);
      return;
    }

    switch (m.t) {
      case 's': { // stare locală: x, z, ry, a
        const now = Date.now();
        const b = player.stateBudget;
        if (now - b.t > 1000) { b.t = now; b.n = 0; }
        if (++b.n > MAX_STATE_PER_SEC) return;
        let x = Number(m.x), z = Number(m.z), ry = Number(m.ry);
        if (!Number.isFinite(x) || !Number.isFinite(z) || !Number.isFinite(ry)) return;
        if (player.seat) { player.lastStateAt = now; return; } // așezat: poziția o dă locul, nu clientul
        const dt = Math.max(0.05, Math.min(1.0, (now - player.lastStateAt) / 1000));
        const dist = Math.hypot(x - player.x, z - player.z);
        let rejected = false;
        if (dist > MAX_SPEED * dt + 0.3) { rejected = true; }
        [x, z] = clampToWalkable(x, z);
        if (!rejected && hitsObstacle(x, z)) rejected = true;
        if (rejected) {
          send(ws, { t: 'fix', x: r2(player.x), z: r2(player.z) });
        } else {
          player.x = x; player.z = z;
        }
        player.ry = ry;
        player.anim = [0, 1, 2].includes(m.a) ? m.a : 0;
        player.lastStateAt = now;
        dirty.add(player.id);
        break;
      }
      case 'sit': { // cere un loc liber, aflat la îndemână
        const seat = SEATS.get(String(m.seat || ''));
        const owner = seat ? seatOwner.get(seat.id) : null;
        if (!seat || (owner && owner !== player.id && players.has(owner))) { send(ws, { t: 'sit_denied', seat: m.seat, reason: 'Locul este ocupat.' }); log('loc refuzat (ocupat)', player.id, m.seat); break; }
        if (Math.hypot(seat.x - player.x, seat.z - player.z) > SIT_REACH) { send(ws, { t: 'sit_denied', seat: seat.id, reason: 'Apropie-te de loc.' }); log('loc refuzat (departe)', player.id, seat.id, Math.hypot(seat.x - player.x, seat.z - player.z).toFixed(2)); break; }
        if (player.seat && player.seat !== seat.id) leaveSeat(player, false);
        player.sitFrom = { x: player.x, z: player.z };
        seatOwner.set(seat.id, player.id);
        player.seat = seat.id; player.x = seat.x; player.z = seat.z; player.ry = seat.ry; player.anim = 3;
        dirty.add(player.id);
        send(ws, { t: 'sat', seat: seat.id, x: r2(seat.x), z: r2(seat.z), ry: r2(seat.ry) });
        log('așezat', player.id, player.name, seat.id);
        break;
      }
      case 'stand': {
        const out = leaveSeat(player);
        if (out) { send(ws, { t: 'stood', x: r2(out.x), z: r2(out.z) }); log('ridicat', player.id, player.name); }
        break;
      }
      case 'busy':
        player.busy = [0, 1, 2, 3].includes(m.v) ? m.v : (m.v ? 1 : 0);
        dirty.add(player.id);
        break;
      case 'chat': {
        const text = String(m.text || '').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim().slice(0, CHAT_MAX_LEN);
        if (!text) return;
        const now = Date.now();
        player.chatTimes = player.chatTimes.filter((t) => now - t < CHAT_WINDOW_MS);
        if (player.chatTimes.length >= CHAT_MAX_IN_WINDOW) { send(ws, { t: 'chat_err', cid: m.cid, message: 'Prea multe mesaje. Așteaptă câteva secunde.' }); return; }
        player.chatTimes.push(now);
        try {
          const saved = await persistMessage(player.id, text);
          broadcast({ t: 'chat', m: saved, cid: m.cid, from: player.id });
        } catch (e) {
          log('chat eșuat', player.id, e.message);
          send(ws, { t: 'chat_err', cid: m.cid, message: 'Mesajul nu a putut fi salvat.' });
        }
        break;
      }
      case 'share_start': // începe prezentarea pe ecranul din cameră
        if (!player.canShare) { send(ws, { t: 'share_denied', reason: 'Nu ai dreptul să partajezi ecranul.' }); break; }
        if (share && share.id !== player.id && players.has(share.id)) { send(ws, { t: 'share_denied', reason: `${share.name} prezintă deja.` }); break; }
        share = { id: player.id, name: player.name, since: Date.now() };
        broadcast({ t: 'share', share });
        log('prezentare pornită', player.id, player.name);
        break;
      case 'share_stop':
        if (share && share.id === player.id) { share = null; broadcast({ t: 'share', share: null }); log('prezentare oprită', player.id); }
        break;
      case 'rtc': { // semnalizare WebRTC: doar între prezentator și ceilalți (video-ul nu trece prin server)
        const to = Number(m.to);
        const target = players.get(to);
        if (!share || !target || !m.data || typeof m.data !== 'object') break;
        if (player.id !== share.id && to !== share.id) break;
        const now = Date.now(); const rb = player.rtcBudget;
        if (now - rb.t > 1000) { rb.t = now; rb.n = 0; }
        if (++rb.n > RTC_MAX_PER_SEC) break;
        send(target.ws, { t: 'rtc', from: player.id, data: m.data });
        break;
      }
      case 'board': // cineva a modificat panoul: ceilalți recitesc (prin PHP, cu ACL proprii)
        broadcast({ t: 'board', by: player.id }, player.id);
        break;
      default:
        break;
    }
  });

  ws.on('close', () => {
    clearTimeout(helloTimer);
    if (!player || player.replaced) return;
    if (players.get(player.id) === player) {
      if (share && share.id === player.id) { share = null; broadcast({ t: 'share', share: null }); }
      if (player.seat) leaveSeat(player, false); // poziția reținută pentru reconectare = punctul de ridicare, nu scaunul
      players.delete(player.id);
      lastKnown.set(player.id, { x: player.x, z: player.z, ry: player.ry, t: Date.now() });
      broadcast({ t: 'leave', id: player.id });
      log('ieșire', player.id, player.name, '· online', players.size);
    }
  });
});

// Difuzare la 10 Hz doar a jucătorilor modificați.
setInterval(() => {
  if (!dirty.size) return;
  const list = [];
  for (const id of dirty) { const p = players.get(id); if (p) list.push([p.id, r2(p.x), r2(p.z), r2(p.ry), p.anim, p.busy, p.seat ? SEATS.get(p.seat).index : 0]); }
  dirty = new Set();
  if (list.length) broadcast({ t: 'st', p: list });
}, TICK_MS);

// Conexiuni moarte + curățenie.
setInterval(() => {
  for (const ws of wss.clients) { if (!ws.isAlive) { ws.terminate(); continue; } ws.isAlive = false; ws.ping(); }
  const now = Date.now();
  for (const [n, exp] of usedNonces) if (exp * 1000 < now) usedNonces.delete(n);
  for (const [id, v] of lastKnown) if (now - v.t > LAST_POS_TTL_MS) lastKnown.delete(id);
}, 20000);

server.listen(PORT, () => log(`ascultă pe :${PORT} · cameră „${WORLD.room}” · PHP ${PHP_URL}`));

function loadEnv(file) {
  const out = {};
  try {
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
      if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, '');
    }
  } catch { /* fără .env: doar variabile de mediu */ }
  return out;
}
