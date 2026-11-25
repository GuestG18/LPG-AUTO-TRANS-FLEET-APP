// Controlerul personajului local + camera la persoana a treia.
//   WASD mișcare (Shift = alergare) · mouse (trage) rotește camera · rotița = distanța camerei.
// Camera e independentă în fiecare browser; evită pereții (limitele camerei) și mobilierul înalt.
import * as THREE from 'three';

const UP = new THREE.Vector3(0, 1, 0);
const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
const angleDiff = (a, b) => { let d = (b - a) % (Math.PI * 2); if (d > Math.PI) d -= Math.PI * 2; if (d < -Math.PI) d += Math.PI * 2; return d; };

export class PlayerController {
  constructor({ camera, dom, collider, world, roomBounds }) {
    this.camera = camera;
    this.dom = dom;
    this.collider = collider;
    this.walkSpeed = world.player.walkSpeed;
    this.runSpeed = world.player.runSpeed;
    this.x = 0; this.z = 0; this.ry = 0;
    this.anim = 0;
    this.turnRate = 0;
    this.keys = new Set();
    this.enabled = true;           // false: în timpul tastării / interacțiunilor pe tot ecranul
    this.yaw = Math.PI;            // camera în spatele personajului (personajul privește +Z)
    this.pitch = 0.32;
    this.distance = 3.0;
    this.minDist = 1.2; this.maxDist = 5.5;
    this.camPos = new THREE.Vector3();
    this.camTarget = new THREE.Vector3();
    this.camBoxes = collider.cameraBoxes();
    // Limitele interioare pentru cameră (world.json → camera). Cutia GLB-ului include grosimea
    // pereților exteriori, deci e folosită doar ca rezervă.
    const b = roomBounds;
    this.camLimits = world.camera || { minX: b.min.x + 0.4, maxX: b.max.x - 0.4, minY: b.min.y + 0.3, maxY: b.max.y - 0.15, minZ: b.min.z + 0.4, maxZ: b.max.z - 0.4 };
    this.focus = null;             // animația camerei spre un obiect (panou)
    this.bindInput();
  }

  bindInput() {
    const typing = (e) => { const t = e.target; return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); };
    window.addEventListener('keydown', (e) => {
      if (typing(e)) return;
      const k = e.code;
      if (['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ShiftLeft', 'ShiftRight', 'Space'].includes(k)) {
        if (k === 'Space') e.preventDefault(); // fără derularea paginii
        // Așezat: doar o apăsare NOUĂ ridică personajul. Tasta W ținută din mers (auto-repeat) sau
        // apăsată în prima jumătate de secundă după așezare nu mai ridică imediat personajul.
        if (this.seated && (e.repeat || performance.now() - this.satAt < 500)) return;
        // Ridicarea pornește direct din apăsare (nu din bucla de randare): o atingere scurtă nu se
        // pierde nici pe un PC lent, unde apăsare + eliberare pot cădea între două cadre.
        if (this.seated && this.enabled && !this.focus && k !== 'ShiftLeft' && k !== 'ShiftRight' && !this.standRequested) {
          this.standRequested = true;
          this.onStandRequest?.();
          return;
        }
        this.keys.add(k);
        if (k.startsWith('Arrow') && this.dom.contains(document.activeElement || document.body)) e.preventDefault();
      }
    });
    window.addEventListener('keyup', (e) => this.keys.delete(e.code));
    window.addEventListener('blur', () => this.keys.clear());

    let drag = null;
    this.dom.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 && e.button !== 2) return;
      drag = { x: e.clientX, y: e.clientY, id: e.pointerId };
      this.dom.setPointerCapture(e.pointerId);
      this.dom.classList.add('is-dragging');
      // Clic pe lume = ieșire din câmpurile de text (tastele merg din nou la personaj).
      if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
    });
    this.dom.addEventListener('pointermove', (e) => {
      if (!drag || e.pointerId !== drag.id || this.focus) return;
      if (this.seated && this.spectate) {
        // Tragerea mouse-ului în vederea de spectator: trece la camera liberă, din unghiul curent.
        this.spectate = false;
        this.yaw = Math.atan2(this.camera.position.x - this.x, this.camera.position.z - this.z);
      }
      const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      drag.x = e.clientX; drag.y = e.clientY;
      this.yaw -= dx * 0.0055;
      this.pitch = Math.max(-0.25, Math.min(1.15, this.pitch + dy * 0.004));
    });
    const end = (e) => { if (drag && e.pointerId === drag.id) { drag = null; this.dom.classList.remove('is-dragging'); } };
    this.dom.addEventListener('pointerup', end);
    this.dom.addEventListener('pointercancel', end);
    this.dom.addEventListener('contextmenu', (e) => e.preventDefault());
    this.dom.addEventListener('wheel', (e) => {
      if (this.focus) return;
      e.preventDefault();
      this.distance = Math.max(this.minDist, Math.min(this.maxDist, this.distance * (1 + Math.sign(e.deltaY) * 0.1)));
    }, { passive: false });
  }

  place(x, z, ry) {
    this.x = x; this.z = z; this.ry = ry;
    this.yaw = ry + Math.PI;
    this.snapCamera = true;
  }

  /** Așezat pe un loc (confirmat de server). */
  sit(seat) {
    this.seated = seat;
    this.satAt = performance.now();
    this.x = seat.x; this.z = seat.z; this.ry = seat.ry;
    this.keys.clear();
    this.standRequested = false;
    this.spectate = !!seat.watch; // locurile din zona de prezentare pornesc cu vederea spre televizor
    if (!seat.watch) this.yaw = seat.ry + Math.PI;
  }

  /** Ridicat (punctul ales de server). */
  stand(x, z) {
    this.seated = null;
    this.standRequested = false;
    this.x = x; this.z = z;
  }

  /** Corecție de la server (poziție respinsă). */
  correct(x, z) { this.x = x; this.z = z; }

  update(dt) {
    if (this.seated) {
      // Așezat: orice tastă de mers (sau Space) ridică personajul; mișcarea o aprobă serverul.
      const k = this.keys;
      const wants = this.enabled && !this.focus && ['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Space'].some((c) => k.has(c));
      if (wants && !this.standRequested) { this.standRequested = true; this.onStandRequest?.(); }
      this.anim = 3;
      this.turnRate = 0;
      this.updateCamera(dt);
      return;
    }
    let ix = 0, iz = 0;
    if (this.enabled && !this.focus) {
      const k = this.keys;
      if (k.has('KeyW') || k.has('ArrowUp')) iz += 1;
      if (k.has('KeyS') || k.has('ArrowDown')) iz -= 1;
      if (k.has('KeyA') || k.has('ArrowLeft')) ix += 1;
      if (k.has('KeyD') || k.has('ArrowRight')) ix -= 1;
    }
    const prevRy = this.ry;
    if (ix || iz) {
      const len = Math.hypot(ix, iz); ix /= len; iz /= len;
      // Direcția relativă la cameră: înainte = de la cameră spre personaj.
      const fwdX = -Math.sin(this.yaw), fwdZ = -Math.cos(this.yaw);
      const rightX = -fwdZ, rightZ = fwdX;
      const dx = fwdX * iz - rightX * ix, dz = fwdZ * iz - rightZ * ix;
      const run = this.keys.has('ShiftLeft') || this.keys.has('ShiftRight');
      const speed = run ? this.runSpeed : this.walkSpeed;
      const targetRy = Math.atan2(dx, dz);
      this.ry += angleDiff(this.ry, targetRy) * Math.min(1, dt * 10);
      const [nx, nz] = this.collider.move(this.x, this.z, dx * speed * dt, dz * speed * dt);
      const moved = Math.hypot(nx - this.x, nz - this.z) / Math.max(dt, 1e-4);
      this.x = nx; this.z = nz;
      this.anim = moved > 0.15 ? (run ? 2 : 1) : 0;
    } else {
      this.anim = 0;
    }
    this.turnRate = angleDiff(prevRy, this.ry) / Math.max(dt, 1e-4);
    this.updateCamera(dt);
  }

  updateCamera(dt) {
    const cam = this.camera;
    if (this.focus) { this.updateFocus(dt); return; }
    const head = new THREE.Vector3(this.x, this.seated ? this.seated.h + 0.78 : 1.45, this.z);
    if (this.seated && this.spectate && this.tvTarget) {
      // Vederea de spectator: de la nivelul ochilor (persoana întâi), spre ecranul de prezentare —
      // nimic din spatele locului (colegi, mobilier) nu poate intra între cameră și ecran.
      const toTv = this.tvTarget.clone().sub(head).setY(0).normalize();
      const desired = head.clone().addScaledVector(toTv, 0.12).add(new THREE.Vector3(0, 0.06, 0));
      const k = 1 - Math.exp(-dt * 6);
      if (this.snapCamera) { this.camPos.copy(desired); this.camTarget.copy(this.tvTarget); this.snapCamera = false; }
      this.camPos.lerp(desired, k);
      this.camTarget.lerp(this.tvTarget, k);
      this.camera.position.copy(this.camPos);
      this.camera.lookAt(this.camTarget);
      this.nearHead = true; // capul propriu ar acoperi ecranul
      return;
    }
    // Poziția dorită pe sferă în jurul capului.
    const cp = Math.cos(this.pitch);
    const dir = new THREE.Vector3(Math.sin(this.yaw) * cp, Math.sin(this.pitch), Math.cos(this.yaw) * cp);
    let dist = this.distance;
    // Coliziunea camerei cu mobilierul înalt.
    const ray = new THREE.Ray(head, dir);
    const hit = new THREE.Vector3();
    for (const b of this.camBoxes) {
      if (b.containsPoint(head)) continue;
      if (ray.intersectBox(b, hit)) { const d = hit.distanceTo(head) - 0.15; if (d < dist) dist = Math.max(0.35, d); }
    }
    // Pereții / tavanul: scurtăm raza până intră în cameră.
    const L = this.camLimits;
    const tFor = (o, d, min, max) => (d > 0 ? (max - o) / d : d < 0 ? (min - o) / d : Infinity);
    dist = Math.min(dist, tFor(head.x, dir.x, L.minX, L.maxX), tFor(head.y, dir.y, L.minY, L.maxY), tFor(head.z, dir.z, L.minZ, L.maxZ));
    dist = Math.max(0.35, dist);
    const desired = head.clone().addScaledVector(dir, dist);
    const target = head.clone().add(new THREE.Vector3(0, 0.05, 0));
    if (this.snapCamera) { this.camPos.copy(desired); this.camTarget.copy(target); this.snapCamera = false; }
    // Urmărire lină; când camera s-ar apropia brusc (perete), reacționează imediat ca să nu treacă prin el.
    const k = 1 - Math.exp(-dt * 12);
    const curDist = this.camPos.distanceTo(head);
    if (dist < curDist - 0.05) this.camPos.lerp(desired, Math.max(k, 0.5)); else this.camPos.lerp(desired, k);
    this.camTarget.lerp(target, 1 - Math.exp(-dt * 16));
    cam.position.copy(this.camPos);
    cam.lookAt(this.camTarget);
    // Lângă cap, personajul devine semi-transparent ca să nu blocheze vederea.
    this.nearHead = dist < 0.8;
  }

  /**
   * Mișcare lină a camerei spre un obiect (ex. panoul informativ) și înapoi.
   * @returns {Promise<void>} se rezolvă la finalul animației
   */
  focusOn(position, lookAt, duration = 0.9) {
    return new Promise((resolve) => {
      this.focus = {
        fromPos: this.camera.position.clone(), fromLook: this.camTarget.clone(),
        toPos: position.clone(), toLook: lookAt.clone(), t: 0, duration, resolve, back: false,
      };
    });
  }

  release(duration = 0.8) {
    return new Promise((resolve) => {
      if (!this.focus) { resolve(); return; }
      const f = this.focus;
      this.focus = { fromPos: this.camera.position.clone(), fromLook: f.toLook.clone(), toPos: this.camPos.clone(), toLook: this.camTarget.clone(), t: 0, duration, resolve, back: true };
    });
  }

  updateFocus(dt) {
    const f = this.focus;
    f.t = Math.min(1, f.t + dt / f.duration);
    const e = ease(f.t);
    if (f.back) {
      // Ținta întoarcerii se recalculează (poziția normală a camerei), ca reîntoarcerea să fie continuă.
      this.focus = null; this.updateCamera(0); const toPos = this.camPos.clone(), toLook = this.camTarget.clone(); this.focus = f;
      f.toPos.copy(toPos); f.toLook.copy(toLook);
    }
    this.camera.position.lerpVectors(f.fromPos, f.toPos, e);
    const look = new THREE.Vector3().lerpVectors(f.fromLook, f.toLook, e);
    this.camera.lookAt(look);
    if (f.t >= 1) {
      const r = f.resolve;
      if (f.back) this.focus = null; else f.done = true;
      if (!f.resolved) { f.resolved = true; r(); }
    }
  }
}

export { angleDiff, UP };
