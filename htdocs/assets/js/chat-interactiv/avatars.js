// Avatarele Avaturn (modelele reale descărcate) + animații.
//
//  Avatar A (avatar-a.glb): are clipul de mers „mixamo.com”; Idle vine din avatar-a-idle.anim.glb
//          (același schelet, extras din „model 1 animated.glb”).
//  Avatar B (avatar-b.glb): schelet Avaturn diferit (73 de oase: Neck1/Neck2, ForeArm1/2, ochi) și
//          fără animații → clipurile lui A sunt RETARGETATE o singură dată la încărcare, os cu os,
//          prin diferența de rotație în spațiul lumii față de poza de repaus a fiecărui schelet.
//
// Fiecare instanță e parsată separat din același ArrayBuffer (fără descărcări repetate).
import * as THREE from 'three';
import { loader } from 'ci/world.js';

const buffers = new Map();   // url -> Promise<ArrayBuffer>
const clipCache = new Map(); // avatarKey -> Promise<{idle, walk}>

function fetchBuffer(url, onProgress) {
  if (!buffers.has(url)) {
    buffers.set(url, (async () => {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error(`${url}: HTTP ${res.status}`);
      const total = Number(res.headers.get('content-length')) || 0;
      if (!res.body || !total || !onProgress) return res.arrayBuffer();
      const reader = res.body.getReader();
      const chunks = []; let got = 0;
      for (;;) { const { done, value } = await reader.read(); if (done) break; chunks.push(value); got += value.length; onProgress(got / total); }
      const out = new Uint8Array(got); let o = 0; for (const c of chunks) { out.set(c, o); o += c.length; }
      return out.buffer;
    })());
  }
  return buffers.get(url);
}

function parse(buffer) {
  return new Promise((resolve, reject) => loader.parse(buffer.slice(0), '', resolve, reject));
}

/** Scoate deplasarea orizontală a bazinului (root motion): controlerul mută personajul, nu clipul. */
function stripRootMotion(clip) {
  for (const t of clip.tracks) {
    if (/Hips\.position$/.test(t.name)) {
      const v = t.values; const x0 = v[0], z0 = v[2];
      for (let i = 0; i < v.length; i += 3) { v[i] = x0; v[i + 2] = z0; }
    }
  }
  return clip;
}

function bonesByName(root) {
  const map = new Map();
  root.traverse((o) => { if (o.isBone && !map.has(o.name)) map.set(o.name, o); });
  return map;
}

/** Clipurile lui A, încărcate o dată. */
async function sourceClips(cfg) {
  const [walkGltf, idleGltf] = await Promise.all([parse(await fetchBuffer(cfg.avaturn_a.model)), parse(await fetchBuffer(cfg.avaturn_a.idle))]);
  const walk = stripRootMotion(walkGltf.animations.find((c) => c.name === 'mixamo.com') || walkGltf.animations[0]);
  const idle = stripRootMotion(idleGltf.animations[0]);
  walk.name = 'walk'; idle.name = 'idle';
  return { walk, idle, rig: walkGltf.scene };
}

/**
 * Retargetare prin copiere de poză: clipul sursă rulează pe scheletul lui A (invizibil); pentru
 * fiecare cadru, rotația fiecărui os în spațiul lumii, relativă la repaus, se aplică osului cu
 * același nume din B peste poza lui de repaus. Oasele fără corespondent (Neck1, ForeArm1/2...)
 * rămân în repaus local. Bazinul primește și înălțimea, scalată cu raportul înălțimilor.
 */
function retarget(srcRig, clip, tgtRoot, fps = 30) {
  const src = bonesByName(srcRig);
  const tgt = bonesByName(tgtRoot);
  srcRig.updateMatrixWorld(true);
  tgtRoot.updateMatrixWorld(true);
  const q = () => new THREE.Quaternion();
  const srcRest = new Map(), tgtRest = new Map(), tgtRestLocal = new Map();
  for (const [n, b] of src) srcRest.set(n, b.getWorldQuaternion(q()));
  for (const [n, b] of tgt) { tgtRest.set(n, b.getWorldQuaternion(q())); tgtRestLocal.set(n, b.quaternion.clone()); }
  const srcHips = src.get('Hips'), tgtHips = tgt.get('Hips');
  const srcHipsRest = srcHips.position.clone(), tgtHipsRest = tgtHips.position.clone();
  const hipScale = tgtHips.getWorldPosition(new THREE.Vector3()).y / Math.max(0.01, srcHips.getWorldPosition(new THREE.Vector3()).y);
  // Ordinea ierarhică (părinții înaintea copiilor).
  const order = []; tgtHips.traverse((o) => { if (o.isBone) order.push(o); });
  const hipsParentWorld = tgtHips.parent.getWorldQuaternion(q());

  const mixer = new THREE.AnimationMixer(srcRig);
  mixer.clipAction(clip).play();
  const frames = Math.max(2, Math.round(clip.duration * fps) + 1);
  const times = new Float32Array(frames);
  const values = new Map(order.map((b) => [b.name, new Float32Array(frames * 4)]));
  const hipsPos = new Float32Array(frames * 3);
  const world = new Map();
  const tmp = q(), inv = q(), delta = q();

  for (let f = 0; f < frames; f++) {
    const t = Math.min(clip.duration, f / fps);
    times[f] = t;
    mixer.setTime(t);
    srcRig.updateMatrixWorld(true);
    for (const b of order) {
      const parentW = b === tgtHips ? hipsParentWorld : world.get(b.parent.name);
      const s = src.get(b.name);
      let gw;
      if (s) {
        s.getWorldQuaternion(tmp);
        delta.copy(tmp).multiply(inv.copy(srcRest.get(b.name)).invert());
        gw = delta.clone().multiply(tgtRest.get(b.name));
      } else {
        gw = parentW.clone().multiply(tgtRestLocal.get(b.name));
      }
      world.set(b.name, gw);
      const local = inv.copy(parentW).invert().multiply(gw);
      local.toArray(values.get(b.name), f * 4);
    }
    const d = srcHips.position.clone().sub(srcHipsRest).multiplyScalar(hipScale);
    tgtHipsRest.clone().add(d).toArray(hipsPos, f * 3);
  }
  mixer.stopAllAction();
  mixer.uncacheRoot(srcRig);
  const tracks = [new THREE.VectorKeyframeTrack(`${tgtHips.name}.position`, times, hipsPos)];
  for (const b of order) tracks.push(new THREE.QuaternionKeyframeTrack(`${b.name}.quaternion`, times, values.get(b.name)));
  const out = new THREE.AnimationClip(clip.name, clip.duration, tracks);
  out.optimize();
  return out;
}

async function clipsFor(key, cfg, tgtRoot) {
  if (!clipCache.has(key)) {
    clipCache.set(key, (async () => {
      const s = await sourceClips(cfg);
      if (key === 'avaturn_a') return { idle: s.idle, walk: s.walk };
      const t0 = performance.now();
      const res = { idle: retarget(s.rig, s.idle, tgtRoot), walk: retarget(s.rig, s.walk, tgtRoot) };
      console.info(`[LPG Connect] retargetare ${key}: ${(performance.now() - t0).toFixed(0)} ms`);
      return res;
    })());
  }
  return clipCache.get(key);
}

function nameSprite(text) {
  const c = document.createElement('canvas');
  const ctx = c.getContext('2d');
  const font = '600 44px system-ui, "Segoe UI", sans-serif';
  ctx.font = font;
  const w = Math.ceil(ctx.measureText(text).width) + 56;
  c.width = w; c.height = 76;
  ctx.font = font;
  ctx.fillStyle = 'rgba(17, 24, 39, 0.78)';
  const r = 30; ctx.beginPath(); ctx.roundRect(2, 4, w - 4, 68, r); ctx.fill();
  ctx.fillStyle = '#fff'; ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
  ctx.fillText(text, w / 2, 40);
  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.anisotropy = 4;
  const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, depthWrite: false, transparent: true }));
  const h = 0.13;
  sp.scale.set(h * (w / 76), h, 1);
  sp.renderOrder = 10;
  return sp;
}

/**
 * Un personaj din cameră (local sau la distanță).
 * Stări: idle / mers / alergare; întoarcerea pe loc folosește mersul încetinit cu pondere mică.
 */
export class AvatarInstance {
  static async create({ key, cfg, name, quality, onProgress }) {
    const url = cfg[key].model;
    const gltf = await parse(await fetchBuffer(url, onProgress));
    // Retargetarea citește poza de repaus: se face înainte de normalizarea modelului.
    const clips = await clipsFor(key, cfg, gltf.scene);
    const inst = new AvatarInstance(gltf.scene, name, quality);
    inst.setClips(clips);
    inst.key = key;
    return inst;
  }

  constructor(model, name, quality) {
    this.group = new THREE.Group();
    this.model = model;
    model.traverse((o) => {
      if (o.isMesh) {
        o.castShadow = quality === 'high';
        o.receiveShadow = false;
        o.frustumCulled = false; // bounding box-ul skinned mesh nu urmează animația
      }
    });
    // Normalizare: tălpile pe podea (y=0), fața spre +Z (convenția Avaturn), scară reală (~1,8 m).
    const box = new THREE.Box3().setFromObject(model);
    const height = box.max.y - box.min.y;
    const scale = height > 2.4 || height < 1.2 ? 1.78 / height : 1;
    model.scale.setScalar(scale);
    model.position.y = -box.min.y * scale;
    this.height = height * scale;
    this.group.add(model);
    this.label = nameSprite(name);
    this.label.position.y = this.height + 0.2;
    this.group.add(this.label);
    this.mixer = new THREE.AnimationMixer(model);
    this.state = 'idle';
    this.turnWeight = 0;
  }

  setClips({ idle, walk }) {
    this.actions = {
      idle: this.mixer.clipAction(idle),
      walk: this.mixer.clipAction(walk),
    };
    this.actions.idle.play();
    this.actions.walk.play();
    this.actions.walk.setEffectiveWeight(0);
    this.weights = { idle: 1, walk: 0, sit: 0 };
    this.idleClip = idle;
    this.sitActions = new Map(); // înălțimea scaunului (cm) -> acțiunea „șezut”
    this.sitAction = null;
    this.restPose = this.captureRest(); // poza de repaus, înainte de prima actualizare a mixer-ului
  }

  captureRest() {
    this.model.updateMatrixWorld(true);
    const bones = bonesByName(this.model);
    const rest = new Map();
    for (const [n, b] of bones) rest.set(n, { b, wq: b.getWorldQuaternion(new THREE.Quaternion()), wp: b.getWorldPosition(new THREE.Vector3()), lp: b.position.clone() });
    return rest;
  }

  /**
   * Poza „șezut” generată din scheletul propriu (nu există clip de șezut în asset-uri): partea de sus
   * păstrează animația Idle (respirație, cap, brațe), bazinul coboară la înălțimea scaunului, coapsele
   * se rotesc înainte, iar unghiurile coapsă/gambă se calculează din lungimile oaselor ca tălpile să
   * atingă podeaua. Funcționează pentru ambele schelete (A și B), cu aceleași nume de oase.
   * @param {number} seatH înălțimea șezutului, în metri
   */
  sitFor(seatH) {
    const key = Math.round(seatH * 100);
    if (this.sitActions.has(key)) return this.sitActions.get(key);
    const R = this.restPose, idle = this.idleClip;
    const hips = R.get('Hips');
    if (!hips || !R.get('LeftUpLeg') || !R.get('LeftLeg') || !R.get('LeftFoot')) return null;
    const X = new THREE.Vector3(1, 0, 0);
    const tracks = [];
    const T = new Float32Array([0, idle.duration]);
    const quatTrack = (name, q) => tracks.push(new THREE.QuaternionKeyframeTrack(`${name}.quaternion`, T, [...q.toArray(), ...q.toArray()]));
    const s = this.model.scale.y;
    let hipJointWorldY = 0;
    for (const side of ['Left', 'Right']) {
      const up = R.get(`${side}UpLeg`), leg = R.get(`${side}Leg`), foot = R.get(`${side}Foot`);
      if (!up || !leg || !foot) continue;
      const Lt = up.wp.distanceTo(leg.wp), Ls = leg.wp.distanceTo(foot.wp);
      const ankleY = foot.wp.y;                          // gleznă în picioare (talpa la y = 0)
      const hipJ = seatH + 0.07;                         // articulația șoldului deasupra șezutului
      hipJointWorldY = hipJ;
      // Coapsa: rotită înainte cu θ (90° = orizontală); gamba: înclinată înainte cu φ dacă scaunul e jos.
      let theta = Math.PI / 2, phi = 0;
      const drop = hipJ - ankleY - Ls;                   // cât trebuie să coboare genunchiul sub șold
      if (drop > 0) theta = Math.acos(Math.min(1, drop / Lt));
      else phi = Math.acos(Math.max(-1, Math.min(1, (hipJ - ankleY) / Ls)));
      const thighW = new THREE.Quaternion().setFromAxisAngle(X, -theta).multiply(up.wq);
      const shinW = new THREE.Quaternion().setFromAxisAngle(X, -phi).multiply(leg.wq); // tălpile înainte
      const footW = foot.wq.clone();
      const inv = (q) => q.clone().invert();
      quatTrack(up.b.name, inv(hips.wq).multiply(thighW));
      quatTrack(leg.b.name, inv(thighW).multiply(shinW));
      quatTrack(foot.b.name, inv(shinW).multiply(footW));
    }
    // Bazinul: coborât astfel încât articulația șoldului să fie la hipJ.
    const upY = R.get('LeftUpLeg').wp.y;
    const hipsWorldY = hipJointWorldY + (hips.wp.y - upY);
    const lp = hips.lp.clone();
    lp.y = (hipsWorldY - this.model.position.y) / s;
    tracks.push(new THREE.VectorKeyframeTrack(`${hips.b.name}.position`, T, [...lp.toArray(), ...lp.toArray()]));
    const replaced = new Set(tracks.map((t) => t.name));
    for (const t of idle.tracks) if (!replaced.has(t.name)) tracks.push(t.clone());
    const clip = new THREE.AnimationClip(`sit_${key}`, idle.duration, tracks);
    const action = this.mixer.clipAction(clip);
    action.play();
    action.setEffectiveWeight(0);
    this.sitActions.set(key, action);
    return action;
  }

  /** Așază personajul (înălțimea scaunului) sau îl ridică (null). */
  setSeat(seatH) {
    if (seatH === null || seatH === undefined) { this.seatH = null; return; }
    const a = this.sitFor(seatH);
    if (a && this.sitAction && this.sitAction !== a) this.sitAction.setEffectiveWeight(0);
    this.sitAction = a;
    this.seatH = seatH;
  }

  setName(name) {
    this.group.remove(this.label);
    this.label.material.map.dispose(); this.label.material.dispose();
    this.label = nameSprite(name);
    this.label.position.y = this.height + 0.2;
    this.group.add(this.label);
  }

  /**
   * @param {number} dt
   * @param {number} anim 0 idle, 1 mers, 2 alergare
   * @param {number} turnRate viteza de rotație (rad/s), pentru animația de întoarcere pe loc
   */
  update(dt, anim, turnRate = 0) {
    if (!this.actions) return;
    const sitting = anim === 3 && !!this.sitAction;
    const moving = anim === 1 || anim === 2;
    const turning = !moving && !sitting && Math.abs(turnRate) > 1.2;
    this.turnWeight += ((turning ? 0.55 : 0) - this.turnWeight) * Math.min(1, dt * 8);
    const targetWalk = moving ? 1 : this.turnWeight;
    // Tranziție lină (≈0,2 s) între stări; așezarea / ridicarea ≈0,35 s.
    const k = Math.min(1, dt * 7);
    this.weights.walk += (targetWalk - this.weights.walk) * k;
    this.weights.sit += ((sitting ? 1 : 0) - this.weights.sit) * Math.min(1, dt * 5);
    if (sitting) this.weights.walk *= 1 - this.weights.sit;
    this.weights.idle = Math.max(0, 1 - this.weights.walk - this.weights.sit);
    this.actions.walk.setEffectiveWeight(this.weights.walk);
    this.actions.idle.setEffectiveWeight(this.weights.idle);
    if (this.sitAction) this.sitAction.setEffectiveWeight(this.weights.sit);
    this.actions.walk.timeScale = anim === 2 ? 1.55 : moving ? 1.0 : 0.7;
    this.mixer.update(dt);
  }

  dispose() {
    this.mixer.stopAllAction();
    this.mixer.uncacheRoot(this.model);
    this.group.traverse((o) => {
      if (o.isMesh || o.isSprite) {
        o.geometry?.dispose();
        const mats = Array.isArray(o.material) ? o.material : [o.material];
        for (const m of mats) { for (const k in m) if (m[k] && m[k].isTexture) m[k].dispose(); m.dispose(); }
      }
    });
    this.group.removeFromParent();
  }
}
