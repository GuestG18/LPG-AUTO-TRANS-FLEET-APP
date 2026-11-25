// Ecranul de prezentare din cameră (televizorul de pe comodă): afișează ecranul partajat ca
// VideoTexture, vizibil pentru toți; în repaus arată un mesaj discret. Dreptunghiul ecranului vine
// din world.json („screen”), deci televizorul se poate muta / înlocui fără cod nou.
import * as THREE from 'three';

export class PresentationScreen {
  constructor(def) {
    this.def = def;
    this.center = new THREE.Vector3(def.center.x, def.center.y, def.center.z);
    this.normal = new THREE.Vector3(def.normal.x, def.normal.y, def.normal.z).normalize();
    this.faceW = def.width; this.faceH = def.height;
    this.surface = this.center.clone().addScaledVector(this.normal, 0.004);

    this.idleCanvas = document.createElement('canvas');
    this.idleCanvas.width = 1024; this.idleCanvas.height = Math.round(1024 * def.height / def.width);
    this.idleTexture = new THREE.CanvasTexture(this.idleCanvas);
    this.idleTexture.colorSpace = THREE.SRGBColorSpace;
    this.drawIdle();

    this.material = new THREE.MeshBasicMaterial({ map: this.idleTexture, toneMapped: false });
    this.mesh = new THREE.Mesh(new THREE.PlaneGeometry(def.width, def.height), this.material);
    this.mesh.position.copy(this.surface);
    this.mesh.lookAt(this.surface.clone().add(this.normal));
    this.mesh.name = 'ecran_prezentare';

    this.video = document.createElement('video');
    this.video.muted = true; this.video.playsInline = true; this.video.autoplay = true;
    // Un <video> în afara documentului poate fi pus pe pauză de browser (textura ar îngheța pe un cadru):
    // îl atașăm invizibil.
    this.video.setAttribute('aria-hidden', 'true');
    Object.assign(this.video.style, { position: 'fixed', width: '2px', height: '2px', opacity: '0', pointerEvents: 'none', left: '0', bottom: '0' });
    document.body.appendChild(this.video);
    this.videoTexture = null;
    this.label = null;
  }

  drawIdle(text = 'Ecran de prezentare', sub = 'Partajează ecranul de la un laptop (E)') {
    const c = this.idleCanvas, ctx = c.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, c.width, c.height);
    g.addColorStop(0, '#0f172a'); g.addColorStop(1, '#1e293b');
    ctx.fillStyle = g; ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#e2e8f0'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = `700 ${Math.round(c.height * 0.09)}px system-ui, "Segoe UI", sans-serif`;
    ctx.fillText(text, c.width / 2, c.height * 0.44);
    ctx.fillStyle = '#94a3b8';
    ctx.font = `500 ${Math.round(c.height * 0.055)}px system-ui, "Segoe UI", sans-serif`;
    ctx.fillText(sub, c.width / 2, c.height * 0.6);
    ctx.fillStyle = '#3b82f6'; ctx.fillRect(c.width * 0.42, c.height * 0.7, c.width * 0.16, c.height * 0.012);
    this.idleTexture.needsUpdate = true;
  }

  /** @param {MediaStream|null} stream  @param {string} presenter */
  setStream(stream, presenter) {
    if (this.videoTexture) { this.videoTexture.dispose(); this.videoTexture = null; }
    this.setLabel(stream || presenter ? presenter : null);
    if (!stream) {
      this.video.srcObject = null;
      this.drawIdle(...(presenter ? [`${presenter} pornește prezentarea…`, 'Se conectează la ecranul partajat'] : []));
      this.material.map = this.idleTexture;
      this.material.needsUpdate = true;
      return;
    }
    this.video.srcObject = stream;
    this.video.play().catch(() => {});
    this.videoTexture = new THREE.VideoTexture(this.video);
    this.videoTexture.colorSpace = THREE.SRGBColorSpace;
    this.videoTexture.minFilter = THREE.LinearFilter;
    this.videoTexture.generateMipmaps = false;
    this.material.map = this.videoTexture;
    this.material.needsUpdate = true;
  }

  /** Eticheta „▶ Nume prezintă” deasupra televizorului. */
  setLabel(name) {
    if (this.label) { this.label.removeFromParent(); this.label.material.map.dispose(); this.label.material.dispose(); this.label = null; }
    if (!name) return;
    const text = `● ${name} prezintă`;
    const c = document.createElement('canvas'); const ctx = c.getContext('2d');
    ctx.font = '600 40px system-ui, "Segoe UI", sans-serif';
    c.width = Math.ceil(ctx.measureText(text).width) + 48; c.height = 64;
    ctx.font = '600 40px system-ui, "Segoe UI", sans-serif';
    ctx.fillStyle = 'rgba(185, 28, 28, 0.9)'; ctx.beginPath(); ctx.roundRect(0, 0, c.width, 64, 32); ctx.fill();
    ctx.fillStyle = '#fff'; ctx.textBaseline = 'middle'; ctx.fillText(text, 24, 34);
    const tex = new THREE.CanvasTexture(c); tex.colorSpace = THREE.SRGBColorSpace;
    this.label = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, depthWrite: false, toneMapped: false }));
    const h = 0.09; this.label.scale.set(h * c.width / 64, h, 1);
    this.label.position.copy(this.surface).add(new THREE.Vector3(0, this.faceH / 2 + 0.1, 0));
    this.mesh.parent?.add(this.label);
  }

  interactionPoint() { return new THREE.Vector3(this.surface.x, 0, this.surface.z); }

  focusPose(aspect) {
    const vfov = THREE.MathUtils.degToRad(50);
    const d = Math.max(this.faceH * 1.2 / 2 / Math.tan(vfov / 2), this.faceW * 1.15 / 2 / (Math.tan(vfov / 2) * aspect), 0.6);
    return { position: this.surface.clone().addScaledVector(this.normal, d), lookAt: this.surface.clone() };
  }
}
