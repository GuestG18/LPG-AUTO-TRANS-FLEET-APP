// LPG Connect — pagina „Chat Interactiv”: orchestrarea lumii 3D, a jucătorilor și a interfețelor.
import * as THREE from 'three';
import { loadWorld, Collider, scaleWorld } from 'ci/world.js';
import { AvatarInstance } from 'ci/avatars.js';
import { PlayerController, angleDiff } from 'ci/player.js';
import { Net } from 'ci/net.js';
import { InteractionRegistry } from 'ci/interactions.js';
import { NoticeBoard } from 'ci/board.js';
import { NotesUI } from 'ci/ui-notes.js';
import { ChatUI } from 'ci/ui-chat.js';
import { ScreenShare } from 'ci/screenshare.js';
import { PresentationScreen } from 'ci/presentation.js';

const root = document.querySelector('[data-ci-root]');
const CFG = JSON.parse(document.getElementById('ci-config').textContent);
const $ = (sel) => root.querySelector(sel);
const DEBUG = Number(new URLSearchParams(location.search).get('debug') || 0); // 1 = cârlige de test (window.ci), 2 = + coliziuni desenate
const diag = []; // diagnosticul încărcării asset-urilor (afișat în panoul Setări)

// ------------------------------------------------------------------ API PHP

const api = {
  url(action, params = {}) {
    const u = new URL(CFG.endpoint, location.href);
    u.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, v);
    return u.toString();
  },
  async get(action, params) {
    const res = await fetch(this.url(action, params), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return this.parse(res);
  },
  async post(action, data) {
    const body = data instanceof FormData ? data : Object.entries(data).reduce((fd, [k, v]) => { fd.append(k, v); return fd; }, new FormData());
    const res = await fetch(this.url(action), { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': CFG.csrf, Accept: 'application/json' } });
    return this.parse(res);
  },
  async parse(res) {
    let data; try { data = await res.json(); } catch { throw new Error(`Răspuns invalid de la server (${res.status}).`); }
    if (!res.ok || !data.ok) throw new Error(data.message || `Eroare ${res.status}`);
    return data;
  },
};

// ------------------------------------------------------------------ setări

const settings = {
  get quality() { try { return localStorage.getItem('ci.quality') || 'high'; } catch { return 'high'; } },
  set quality(v) { try { localStorage.setItem('ci.quality', v); } catch { /* fără stocare */ } },
};

function setLoading(text, progress) {
  const el = $('[data-loading]');
  if (!el) return;
  if (text === null) { el.classList.add('is-done'); setTimeout(() => el.remove(), 500); return; }
  el.querySelector('[data-loading-text]').textContent = text;
  if (progress !== undefined) el.querySelector('[data-loading-bar]').style.width = `${Math.round(progress * 100)}%`;
}

function fatal(message) {
  setLoading(message);
  $('[data-loading]')?.classList.add('is-error');
}

// ------------------------------------------------------------------ alegerea avatarului

function chooseAvatar(avatars, current) {
  return new Promise((resolve) => {
    const ov = $('[data-avatar-picker]');
    ov.hidden = false;
    const grid = ov.querySelector('[data-avatar-grid]');
    grid.innerHTML = avatars.map((a) => `<button type="button" class="ci-avatar-card${a.key === current ? ' is-current' : ''}" data-key="${a.key}">
        <span class="ci-avatar-thumb" data-thumb="${a.key}"><span class="spinner-border spinner-border-sm"></span></span>
        <span class="ci-avatar-name">${a.label}</span></button>`).join('');
    ov.querySelector('[data-avatar-cancel]').hidden = !current;
    ov.querySelector('[data-avatar-cancel]').onclick = () => { ov.hidden = true; resolve(null); };
    grid.querySelectorAll('[data-key]').forEach((b) => b.addEventListener('click', async () => {
      grid.querySelectorAll('button').forEach((x) => { x.disabled = true; });
      try {
        const r = await api.post('set_avatar', { avatar: b.dataset.key });
        ov.hidden = true;
        resolve(r);
      } catch (e) { alert(e.message); grid.querySelectorAll('button').forEach((x) => { x.disabled = false; }); }
    }));
    renderThumbnails(avatars).catch(() => {});
  });
}

/** Miniaturi reale: fiecare avatar randat o dată într-un canvas mic (poza Idle). */
async function renderThumbnails(avatars) {
  const r = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
  r.setSize(220, 260); r.outputColorSpace = THREE.SRGBColorSpace;
  try {
    for (const a of avatars) {
      const inst = await AvatarInstance.create({ key: a.key, cfg: CFG.assets.avatars, name: a.label, quality: 'low' });
      inst.label.visible = false;
      inst.update(0.016, 0); inst.mixer.setTime(1.2);
      const s = new THREE.Scene();
      s.add(new THREE.HemisphereLight(0xffffff, 0x888888, 2.2));
      const d = new THREE.DirectionalLight(0xffffff, 1.6); d.position.set(1, 2, 3); s.add(d);
      s.add(inst.group);
      const cam = new THREE.PerspectiveCamera(30, 220 / 260, 0.1, 20);
      cam.position.set(0, inst.height * 0.62, 3.6); cam.lookAt(0, inst.height * 0.5, 0);
      r.render(s, cam);
      const el = root.querySelector(`[data-thumb="${a.key}"]`);
      if (el) el.innerHTML = `<img alt="" src="${r.domElement.toDataURL('image/png')}">`;
      inst.dispose();
    }
  } finally { r.dispose(); r.forceContextLoss(); }
}

// ------------------------------------------------------------------ pornire

async function main() {
  setLoading('Se pregătește sediul virtual…', 0.02);
  let boot;
  try { boot = await api.get('bootstrap'); } catch (e) { fatal(e.message); return; }
  const me = boot.user;
  let avatarKey = boot.avatar;
  let token = boot.ws.token;
  if (!avatarKey) {
    setLoading('Alege-ți avatarul', 0.05);
    const r = await chooseAvatar(boot.avatars, null);
    avatarKey = r.avatar; token = r.token;
  }

  // Randare (WebGL în browser; serverul nu are nevoie de GPU).
  const quality = settings.quality;
  const container = $('[data-ci-canvas]');
  const renderer = new THREE.WebGLRenderer({ antialias: quality === 'high', powerPreference: 'high-performance' });
  renderer.setPixelRatio(quality === 'high' ? Math.min(window.devicePixelRatio, 1.75) : 1);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.0;
  renderer.shadowMap.enabled = quality === 'high';
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  container.appendChild(renderer.domElement);
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(55, 1, 0.05, 60);

  const world = scaleWorld(await (await fetch(CFG.assets.world, { credentials: 'same-origin' })).json());
  let office;
  const t0 = performance.now();
  try {
    office = await loadWorld({ renderer, scene, url: CFG.assets.office, world, quality, onProgress: (p) => setLoading('Se încarcă biroul…', 0.08 + p * 0.6) });
    diag.push(['Cameră (office.glb)', 'ok', `${((performance.now() - t0) / 1000).toFixed(1)} s`]);
  } catch (e) {
    diag.push(['Cameră (office.glb)', 'eroare', e.message]);
    fatal('Camera 3D nu a putut fi încărcată: ' + e.message);
    return;
  }
  const roomCenter = office.bounds.getCenter(new THREE.Vector3());
  if (DEBUG >= 2) debugWorld(office, world, scene);

  const collider = new Collider(world);
  const player = new PlayerController({ camera, dom: container, collider, world, roomBounds: office.bounds });

  // Panoul informativ fizic + interacțiunea lui.
  const boardDef = world.interactables.find((i) => i.type === 'noticeboard');
  const boardNode = office.findNode(boardDef.node);
  let board = null;
  if (boardNode) {
    board = new NoticeBoard(boardNode, roomCenter);
    scene.add(board.mesh);
    board.draw(boot.board);
    diag.push(['Panou informativ', 'ok', `nod „${boardDef.node}”`]);
  } else {
    diag.push(['Panou informativ', 'eroare', `nodul „${boardDef.node}” lipsește din office.glb`]);
  }

  // Personajul local.
  setLoading('Se încarcă avatarul…', 0.72);
  let local;
  try {
    local = await AvatarInstance.create({ key: avatarKey, cfg: CFG.assets.avatars, name: me.nume, quality, onProgress: (p) => setLoading('Se încarcă avatarul…', 0.72 + p * 0.2) });
    diag.push([`Avatar local (${avatarKey})`, 'ok', `înălțime ${local.height.toFixed(2)} m`]);
  } catch (e) {
    diag.push([`Avatar local (${avatarKey})`, 'eroare', e.message]);
    fatal('Avatarul nu a putut fi încărcat: ' + e.message);
    return;
  }
  scene.add(local.group);
  local.label.visible = false; // propriul nume nu se afișează (ar acoperi camera); colegii îl văd
  const spawn = world.spawns[0];
  player.place(spawn.x, spawn.z, spawn.ry);

  // Rețea.
  const net = new Net({ url: boot.ws.url, getToken: async () => (await api.get('ws_token')).token });
  const remotes = new Map(); // id -> {inst, name, avatar, snaps:[], busy, loading}
  const chat = new ChatUI({ root: $('[data-chat]'), api, me, net });
  chat.setInitial(boot.chat);

  let myBusy = 0; // 0 liber, 1 panou, 2 laptop, 3 privește ecranul
  const people = () => {
    const list = [{ id: me.id, name: me.nume, busy: myBusy }];
    for (const [id, r] of remotes) list.push({ id, name: r.name, busy: r.busy });
    chat.setPeople(net.connected ? list : []);
  };

  // ---------------------------------------------------------------- locuri (scaune, canapea, fotolii)
  const seats = world.seats || [];
  const seatTaken = new Map(); // index loc (1..n) -> id jucător
  let mySeat = null;
  let sitWait = null;
  const setRemoteSeat = (id, r, idx) => {
    for (const [k, v] of seatTaken) if (v === id) seatTaken.delete(k);
    if (idx) seatTaken.set(idx, id);
    r.seatIdx = idx || 0;
    if (r.inst) r.inst.setSeat(idx ? seats[idx - 1].h : null);
  };

  async function addRemote(p) {
    if (p.id === me.id || remotes.has(p.id)) return;
    const r = { name: p.name, avatar: p.avatar, snaps: [{ t: performance.now(), x: p.x, z: p.z, ry: p.ry, a: p.a }], busy: p.b || 0, inst: null };
    remotes.set(p.id, r);
    setRemoteSeat(p.id, r, p.s || 0);
    people();
    try {
      const inst = await AvatarInstance.create({ key: p.avatar, cfg: CFG.assets.avatars, name: p.name, quality });
      if (remotes.get(p.id) !== r) { inst.dispose(); return; }
      r.inst = inst;
      inst.group.position.set(p.x, 0, p.z);
      inst.group.rotation.y = p.ry;
      scene.add(inst.group);
      setRemoteSeat(p.id, r, r.seatIdx);
      diag.push([`Avatar ${p.name} (${p.avatar})`, 'ok', '']);
    } catch (e) {
      diag.push([`Avatar ${p.name} (${p.avatar})`, 'eroare', e.message]);
    }
  }
  function removeRemote(id) {
    const r = remotes.get(id);
    if (!r) return;
    for (const [k, v] of seatTaken) if (v === id) seatTaken.delete(k);
    r.inst?.dispose();
    remotes.delete(id);
    people();
  }

  const statusEl = $('[data-net-status]');
  net.addEventListener('status', (e) => {
    const s = e.detail.state;
    statusEl.dataset.state = s;
    statusEl.querySelector('span').textContent = { connecting: 'Se conectează…', online: 'Conectat', offline: 'Deconectat — se reîncearcă', replaced: 'Deschis în altă fereastră', full: 'Camera este plină' }[s] || s;
    if (s !== 'online') { for (const id of [...remotes.keys()]) removeRemote(id); }
    people();
    chat.setLive(s === 'online');
    // Plasă de siguranță: fără legătura live (WS blocat de rețea / tunel greșit), mesajele noi se
    // citesc din PHP la 4 s, ca chatul să nu ceară niciodată reîncărcarea paginii.
    clearInterval(chatPoll);
    if (s !== 'online') chatPoll = setInterval(() => chat.pollNew(), 4000);
  });
  let chatPoll = null;
  net.addEventListener('welcome', (e) => {
    const m = e.detail;
    if (mySeat) { mySeat = null; player.stand(m.you.x, m.you.z); local.setSeat(null); updateSeatHint(); } // serverul eliberează locul la deconectare
    seatTaken.clear();
    player.place(m.you.x, m.you.z, m.you.ry);
    for (const p of m.players) addRemote(p);
    people();
    if (myBusy) net.send({ t: 'busy', v: myBusy });
  });
  net.addEventListener('join', (e) => addRemote(e.detail.p));
  net.addEventListener('leave', (e) => removeRemote(e.detail.id));
  net.addEventListener('st', (e) => {
    const now = performance.now();
    for (const [id, x, z, ry, a, b, st] of e.detail.p) {
      const r = remotes.get(id);
      if (!r) continue;
      if ((r.seatIdx || 0) !== (st || 0)) setRemoteSeat(id, r, st || 0);
      r.snaps.push({ t: now, x, z, ry, a });
      if (r.snaps.length > 12) r.snaps.shift();
      if (r.busy !== (b || 0)) { r.busy = b || 0; people(); }
    }
  });
  net.addEventListener('fix', (e) => { if (!player.seated) player.correct(e.detail.x, e.detail.z); });
  net.addEventListener('sat', (e) => {
    const seat = seats.find((x) => x.id === e.detail.seat);
    if (!seat) return;
    clearTimeout(sitWait);
    mySeat = seat;
    player.sit(seat);
    local.setSeat(seat.h);
    updateSeatHint();
  });
  net.addEventListener('stood', (e) => {
    mySeat = null;
    player.stand(e.detail.x, e.detail.z);
    local.setSeat(null);
    updateSeatHint();
  });
  net.addEventListener('sit_denied', (e) => { clearTimeout(sitWait); showToast(e.detail.reason || 'Locul nu este disponibil.'); });
  player.onStandRequest = () => { if (!net.send({ t: 'stand' })) player.standRequested = false; };
  function updateSeatHint() {
    const el = $('[data-seated]');
    el.hidden = !mySeat;
    root.classList.toggle('is-seated', !!mySeat); // promptul coboară, ca să nu acopere ecranul
    if (mySeat) el.querySelector('[data-seated-text]').textContent = mySeat.watch
      ? 'Stai în zona de prezentare · V — schimbă vederea (ecran / liberă) · Space sau W — te ridici'
      : 'Stai pe scaun · E — laptopul din față · Space sau W — te ridici';
  }
  net.addEventListener('chat', (e) => chat.receive(e.detail.m, e.detail.from === me.id ? e.detail.cid : null));
  net.addEventListener('chat_err', (e) => chat.error(e.detail.cid, e.detail.message));
  net.addEventListener('board', () => refreshBoard());
  net.addEventListener('replaced', () => { statusEl.dataset.state = 'replaced'; });

  // Panoul: date + interfața HTML.
  async function refreshBoard() {
    try {
      const b = await api.get('board');
      board?.draw(b);
      notes?.setCounts(b.counts);
      if (notesOpen) notes.load();
    } catch { /* se reîncearcă la următorul eveniment */ }
  }
  let notesOpen = false;
  const notesRoot = $('[data-notes]');
  const notes = new NotesUI({
    root: notesRoot, api, me, perms: boot.perms, users: boot.users,
    onChanged: (cat) => { refreshBoard(); if (cat === 'noutate') net.send({ t: 'board' }); else net.send({ t: 'board' }); },
    onClose: () => interactions.close(),
  });
  notes.setCounts(boot.board.counts);

  const interactions = new InteractionRegistry({ promptEl: $('[data-prompt]'), perms: { view: true, ...boot.perms } });
  if (board) {
    interactions.register({
      id: boardDef.id, type: boardDef.type, radius: boardDef.radius, permission: boardDef.permission,
      position: board.interactionPoint(), normal: board.normal, prompt: boardDef.prompt, ui: boardDef.ui,
      handler: {
        open: async () => {
          player.enabled = false;
          player.keys.clear();
          net.send({ t: 'busy', v: 1 }); myBusy = 1;
          people();
          root.classList.add('is-focusing');
          const pose = board.focusPose(camera.aspect);
          await player.focusOn(pose.position, pose.lookAt, 0.95);
          // Interfața „crește” din dreptunghiul panoului de pe ecran.
          const rect = board.screenRect(camera, container);
          notesRoot.hidden = false;
          const fin = notesRoot.getBoundingClientRect();
          const cr = container.getBoundingClientRect();
          const sx = rect.width / fin.width, sy = rect.height / fin.height;
          const tx = rect.left + cr.left - fin.left, ty = rect.top + cr.top - fin.top;
          notesRoot.animate([
            { transform: `translate(${tx}px, ${ty}px) scale(${sx}, ${sy})`, opacity: 0.2, transformOrigin: '0 0' },
            { transform: 'none', opacity: 1, transformOrigin: '0 0' },
          ], { duration: 420, easing: 'cubic-bezier(.2,.8,.2,1)' });
          notesOpen = true;
          root.classList.add('is-board-open');
          notes.load();
        },
        close: async () => {
          if (notesOpen) {
            const rect = board.screenRect(camera, container);
            const fin = notesRoot.getBoundingClientRect();
            const cr = container.getBoundingClientRect();
            const anim = notesRoot.animate([
              { transform: 'none', opacity: 1, transformOrigin: '0 0' },
              { transform: `translate(${rect.left + cr.left - fin.left}px, ${rect.top + cr.top - fin.top}px) scale(${rect.width / fin.width}, ${rect.height / fin.height})`, opacity: 0, transformOrigin: '0 0' },
            ], { duration: 300, easing: 'ease-in' });
            await anim.finished.catch(() => {});
            notesRoot.hidden = true;
            notesOpen = false;
          }
          root.classList.remove('is-board-open', 'is-focusing');
          await player.release(0.8);
          player.enabled = true;
          net.send({ t: 'busy', v: 0 }); myBusy = 0;
          people();
          container.focus({ preventScroll: true });
        },
      },
    });
  }

  // ---------------------------------------------------------------- prezentare (laptop → televizor)
  const share = new ScreenShare({ net, me, iceServers: boot.ice });
  const tv = world.screen ? new PresentationScreen(world.screen) : null;
  if (tv) { scene.add(tv.mesh); diag.push(['Ecran de prezentare', 'ok', 'televizor (world.json → screen)']); }
  const laptopEl = $('[data-laptop]');
  const screenEl = $('[data-screen-view]');
  const screenVideo = screenEl.querySelector('video');
  let lastShareId = null;
  share.addEventListener('state', (e) => {
    const { share: s, mine, stream } = e.detail;
    tv?.setStream(stream, s ? (mine ? `${me.nume} (tu)` : s.name) : null);
    screenVideo.srcObject = stream || null;
    screenEl.querySelector('[data-screen-empty]').hidden = !!stream;
    screenEl.querySelector('[data-screen-title]').textContent = s ? `Prezintă: ${mine ? 'tu' : s.name}` : 'Ecran de prezentare';
    renderLaptop();
    const id = s ? s.id : null;
    if (id !== lastShareId) {
      if (s && !mine) showToast(`🖥️ ${s.name} prezintă pe ecranul din cameră — apropie-te de televizor (E).`);
      lastShareId = id;
    }
  });
  share.addEventListener('error', (e) => showToast(`⚠️ ${e.detail.message}`));

  function renderLaptop() {
    const s = share.share, mine = !!(s && s.id === me.id);
    const status = laptopEl.querySelector('[data-laptop-status]');
    const startBtn = laptopEl.querySelector('[data-share-start]');
    const stopBtn = laptopEl.querySelector('[data-share-stop]');
    const preview = laptopEl.querySelector('video');
    const can = !!boot.perms.share_screen;
    startBtn.hidden = mine; stopBtn.hidden = !mine;
    startBtn.disabled = !can || !ScreenShare.supported() || (s && !mine) || !net.connected;
    preview.hidden = !mine;
    preview.srcObject = mine ? share.local : null;
    status.textContent = !can ? 'Nu ai dreptul să partajezi ecranul (Drepturi de acces → Chat Interactiv).'
      : !ScreenShare.supported() ? 'Partajarea ecranului cere HTTPS (sau localhost) și Chrome / Edge / Firefox recent.'
      : !net.connected ? 'Nu ești conectat la sediul virtual.'
      : mine ? 'Prezinți acum: colegii văd ecranul tău pe televizorul din cameră. Poți închide laptopul și te poți plimba.'
      : s ? `${s.name} prezintă acum. Poți prezenta după ce oprește.`
      : 'Alege un ecran, o fereastră sau o filă; apare pe televizorul din cameră pentru toți colegii.';
  }
  laptopEl.querySelector('[data-share-start]').addEventListener('click', async () => {
    try { await share.start(); } catch (err) { if (err.name !== 'NotAllowedError') showToast(`⚠️ ${err.message}`); }
    renderLaptop();
  });
  laptopEl.querySelector('[data-share-stop]').addEventListener('click', () => { share.stop(); renderLaptop(); });
  laptopEl.querySelector('[data-close]').addEventListener('click', () => interactions.close());
  screenEl.querySelector('[data-close]').addEventListener('click', () => interactions.close());
  screenEl.querySelector('[data-fullscreen]').addEventListener('click', () => screenVideo.requestFullscreen?.().catch(() => {}));
  net.addEventListener('status', () => renderLaptop());

  /** Panou HTML simplu peste lume, după apropierea camerei (laptop / televizor). */
  const panelHandler = (el, busyCode, pose) => ({
    open: async (item) => {
      player.enabled = false; player.keys.clear();
      net.send({ t: 'busy', v: busyCode }); myBusy = busyCode; people();
      root.classList.add('is-focusing');
      const p = pose(item);
      await player.focusOn(p.position, p.lookAt, 0.8);
      el.hidden = false;
      el.animate([{ opacity: 0, transform: 'translateY(12px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 260, easing: 'ease-out' });
      if (el === laptopEl) renderLaptop();
    },
    close: async () => {
      el.hidden = true;
      if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
      root.classList.remove('is-focusing');
      await player.release(0.7);
      player.enabled = true;
      net.send({ t: 'busy', v: 0 }); myBusy = 0; people();
      container.focus({ preventScroll: true });
    },
  });
  for (const def of world.interactables.filter((i) => i.type === 'laptop')) {
    const pos = new THREE.Vector3(def.position.x, def.position.y, def.position.z);
    interactions.register({
      id: def.id, type: def.type, radius: def.radius, permission: def.permission, prompt: def.prompt, ui: def.ui,
      position: pos,
      // Camera: de deasupra umărului, privind ecranul laptopului.
      handler: panelHandler(laptopEl, 2, () => {
        const from = new THREE.Vector3(player.x, 1.55, player.z);
        const dir = pos.clone().sub(from).setY(0).normalize();
        return { position: from.addScaledVector(dir, 0.25), lookAt: pos.clone().add(new THREE.Vector3(0, 0.12, 0)) };
      }),
    });
  }
  const screenDef = world.interactables.find((i) => i.type === 'screen');
  if (tv && screenDef) {
    interactions.register({
      id: screenDef.id, type: screenDef.type, radius: screenDef.radius, permission: screenDef.permission, prompt: screenDef.prompt, ui: screenDef.ui,
      position: tv.interactionPoint(), normal: tv.normal,
      handler: panelHandler(screenEl, 3, () => tv.focusPose(camera.aspect)),
    });
  }

  if (tv) player.tvTarget = tv.surface.clone();
  const SEAT_PROMPT = { scaun: 'Stai pe scaun', canapea: 'Stai pe canapea (zona de prezentare)', fotoliu: 'Stai în fotoliu (zona de prezentare)' };
  seats.forEach((seat, i) => {
    interactions.register({
      id: seat.id, type: 'seat', radius: 0.95, permission: 'view', instant: true,
      position: new THREE.Vector3(seat.x, 0, seat.z),
      prompt: SEAT_PROMPT[seat.kind] || 'Stai jos',
      available: () => !mySeat && !seatTaken.has(i + 1),
      handler: {
        open: () => {
          if (!net.send({ t: 'sit', seat: seat.id })) { showToast('Nu ești conectat la sediul virtual — locul nu poate fi ocupat acum.'); return; }
          // Fără răspuns de la server (rețea lentă / server oprit): spunem, nu rămânem tăcuți.
          clearTimeout(sitWait);
          sitWait = setTimeout(() => { if (!mySeat) showToast('Serverul nu a răspuns la cererea de așezare. Reîncearcă.'); }, 4000);
        },
      },
    });
  });

  // Taste globale: E (interacțiune), Enter (chat), Esc (închide).
  window.addEventListener('keydown', (e) => {
    const t = e.target;
    const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
    if (e.key === 'Escape') {
      if (!$('[data-settings]').hidden) { $('[data-settings]').hidden = true; return; }
      if (typing) return; // câmpul își tratează singur Esc
      if (interactions.active) { e.preventDefault(); interactions.close(); }
      return;
    }
    if (typing || e.ctrlKey || e.altKey || e.metaKey) return;
    if (e.code === 'KeyE' && !interactions.active) { if (interactions.current) { e.preventDefault(); interactions.trigger(); } return; }
    if (e.code === 'KeyV' && mySeat && mySeat.watch) { player.spectate = !player.spectate; if (!player.spectate) player.yaw = mySeat.ry + Math.PI; return; }
    if (e.key === 'Enter') { e.preventDefault(); chat.focus(); }
  });

  // Setări (calitate grafică, avatar, diagnostic).
  const settingsEl = $('[data-settings]');
  $('[data-open-settings]').addEventListener('click', () => {
    settingsEl.hidden = !settingsEl.hidden;
    settingsEl.querySelector(`[name="quality"][value="${settings.quality}"]`).checked = true;
    const info = renderer.info;
    settingsEl.querySelector('[data-diag]').innerHTML = diag.map(([k, s, d]) => `<li class="is-${s === 'ok' ? 'ok' : 'err'}"><b>${k}</b> — ${s}${d ? ` · ${d}` : ''}</li>`).join('')
      + `<li><b>Randare</b> — ${info.render.calls} apeluri, ${(info.render.triangles / 1000).toFixed(0)}k triunghiuri, ${info.memory.textures} texturi</li>`;
  });
  settingsEl.querySelectorAll('[name="quality"]').forEach((r) => r.addEventListener('change', () => { settings.quality = r.value; location.reload(); }));
  settingsEl.querySelector('[data-change-avatar]').addEventListener('click', async () => {
    settingsEl.hidden = true;
    const r = await chooseAvatar(boot.avatars, avatarKey);
    if (r && r.avatar !== avatarKey) location.reload();
  });
  settingsEl.querySelector('[data-close-settings]').addEventListener('click', () => { settingsEl.hidden = true; });

  // Memento-uri (termene) — verificate la fiecare minut, afișate ca notificare în lume.
  const reminderCheck = async () => {
    try {
      const { reminders } = await api.get('reminders');
      for (const r of reminders) showToast(`⏰ Memento: „${r.titlu}” — termen ${new Date(r.termen.replace(' ', 'T')).toLocaleString('ro-RO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}`);
    } catch { /* ignorat */ }
  };
  reminderCheck();
  setInterval(reminderCheck, 60000);

  // Dimensiuni: lumea ocupă aproape toată zona de conținut, bara de navigare rămâne accesibilă.
  const resize = () => {
    const top = root.getBoundingClientRect().top + window.scrollY;
    const h = Math.max(380, window.innerHeight - top - 12);
    root.style.height = `${h}px`;
    const w = container.clientWidth;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  };
  new ResizeObserver(resize).observe(root.parentElement);
  window.addEventListener('resize', resize);
  resize();

  setLoading('Se intră în cameră…', 0.97);
  net.connect(token);

  // Bucla de randare (oprită când fila e ascunsă).
  const clock = new THREE.Clock();
  const INTERP_DELAY = 140;
  const tmpHead = new THREE.Vector3();
  function frame() {
    const dt = Math.min(clock.getDelta(), 0.25); // PC-uri lente: mișcarea rămâne la viteza reală (coliziunile sunt sub-pășite)
    player.update(dt);
    local.group.position.set(player.x, 0, player.z);
    local.group.rotation.y = player.ry;
    local.update(dt, player.anim, player.turnRate);
    // Personajul propriu nu trebuie să acopere vederea: ascuns când camera trece prin / lângă el
    // (cameră lipită de perete sau apropierea de panou).
    const camNear = camera.position.distanceTo(tmpHead.set(player.x, 1.0, player.z)) < 0.95;
    local.group.visible = !player.nearHead && !(player.focus && camNear);
    if (!interactions.active && !mySeat) net.sendState(player.x, player.z, player.ry, player.anim);
    interactions.update(player.x, player.z, player.ry);

    const rt = performance.now() - INTERP_DELAY;
    for (const r of remotes.values()) {
      if (!r.inst) continue;
      const s = r.snaps;
      let a = s[0], b = s[s.length - 1];
      for (let i = 0; i < s.length - 1; i++) if (s[i].t <= rt && s[i + 1].t >= rt) { a = s[i]; b = s[i + 1]; break; }
      const k = b.t === a.t ? 1 : Math.max(0, Math.min(1, (rt - a.t) / (b.t - a.t)));
      const x = a.x + (b.x - a.x) * k, z = a.z + (b.z - a.z) * k;
      const ry = a.ry + angleDiff(a.ry, b.ry) * k;
      const g = r.inst.group;
      const prevRy = g.rotation.y;
      g.position.set(x, 0, z);
      g.rotation.y = ry;
      // Așezat: rămâne așezat (un jucător așezat nu mai trimite stări, ultima ar părea „veche”).
      const anim = r.seatIdx ? 3 : rt > b.t + 400 ? 0 : (k < 1 ? a.a : b.a) || b.a;
      r.inst.update(dt, anim, angleDiff(prevRy, ry) / Math.max(dt, 1e-4));
    }
    renderer.render(scene, camera);
  }
  const start = () => { clock.getDelta(); renderer.setAnimationLoop(frame); };
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { renderer.setAnimationLoop(null); player.keys.clear(); net.sendState(player.x, player.z, player.ry, 0); } else start();
  });
  start();
  setLoading(null);
  container.tabIndex = 0;
  container.focus({ preventScroll: true });
  $('[data-help]').hidden = false;
  setTimeout(() => $('[data-help]')?.classList.add('is-faded'), 9000);

  window.addEventListener('pagehide', () => { net.close(); });
  if (DEBUG) Object.assign(window, { ci: { scene, player, net, remotes, office, world, THREE, renderer, camera, board, interactions, collider, local, chat, notes, api, share, tv } });
}

function showToast(text) {
  const t = document.createElement('div');
  t.className = 'ci-world-toast';
  t.textContent = text;
  $('[data-toasts]').appendChild(t);
  setTimeout(() => t.classList.add('is-out'), 7000);
  setTimeout(() => t.remove(), 7600);
}

/** ?debug=1: cutiile mobilierului (pentru world.json) + obstacolele/limitele desenate. */
function debugWorld(office, world, scene) {
  const rows = [];
  office.room.traverse((o) => {
    if (!o.isMesh) return;
    const b = new THREE.Box3().setFromObject(o);
    rows.push({ name: o.name, parent: o.parent?.name, minX: +b.min.x.toFixed(2), maxX: +b.max.x.toFixed(2), minY: +b.min.y.toFixed(2), maxY: +b.max.y.toFixed(2), minZ: +b.min.z.toFixed(2), maxZ: +b.max.z.toFixed(2) });
  });
  console.table(rows);
  window.ciBoxes = rows;
  console.log('room bounds', office.bounds);
  for (const o of world.obstacles) {
    const h = o.height || 0.8;
    const m = new THREE.Mesh(new THREE.BoxGeometry(o.maxX - o.minX, h, o.maxZ - o.minZ), new THREE.MeshBasicMaterial({ color: 0xff0044, wireframe: true }));
    m.position.set((o.minX + o.maxX) / 2, h / 2, (o.minZ + o.maxZ) / 2);
    scene.add(m);
  }
  const w = world.walkable;
  const wm = new THREE.Mesh(new THREE.BoxGeometry(w.maxX - w.minX, 0.02, w.maxZ - w.minZ), new THREE.MeshBasicMaterial({ color: 0x00ff66, wireframe: true }));
  wm.position.set((w.minX + w.maxX) / 2, 0.05, (w.minZ + w.maxZ) / 2);
  scene.add(wm);
}

main().catch((e) => { console.error(e); fatal('Eroare: ' + e.message); });
