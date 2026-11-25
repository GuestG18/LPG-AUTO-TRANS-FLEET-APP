// Panoul informativ fizic din cameră: bilețelele noutăților comune se desenează pe o textură
// (un singur plan, nu câte un obiect 3D pe notă) peste suprafața panoului original.
//   normală = galben · importanță ridicată = portocaliu + bandă · critică = roșu + pioneză + „!”
//   finalizată = estompată cu bifă verde · arhivată = dispare · „+N” când sunt mai multe.
import * as THREE from 'three';

const COLORS = {
  normala: { bg: '#fde68a', edge: '#f59e0b', text: '#3f2d05' },
  ridicata: { bg: '#fdba74', edge: '#ea580c', text: '#431407' },
  critica: { bg: '#fca5a5', edge: '#dc2626', text: '#450a0a' },
};

export class NoticeBoard {
  /** @param {THREE.Object3D} node nodul panoului din scena biroului */
  constructor(node, roomCenter) {
    this.node = node;
    const box = new THREE.Box3().setFromObject(node);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    // Panoul e plat: axa cea mai subțire e normala; sensul = spre centrul camerei.
    const axis = size.x < size.z ? (size.x < size.y ? 'x' : 'y') : (size.z < size.y ? 'z' : 'y');
    const normal = new THREE.Vector3();
    normal[axis] = Math.sign(roomCenter[axis] - center[axis]) || 1;
    this.normal = normal;
    this.center = center;
    const faceW = axis === 'x' ? size.z : size.x;
    const faceH = size.y;
    this.faceW = faceW; this.faceH = faceH;
    // Suprafața din față (+ 6 mm ca să nu pâlpâie).
    this.surface = center.clone().addScaledVector(normal, size[axis] / 2 + 0.006);

    this.canvas = document.createElement('canvas');
    this.canvas.width = 1024;
    this.canvas.height = Math.round(1024 * (faceH / faceW));
    this.texture = new THREE.CanvasTexture(this.canvas);
    this.texture.colorSpace = THREE.SRGBColorSpace;
    this.texture.anisotropy = 8;
    const mat = new THREE.MeshStandardMaterial({ map: this.texture, transparent: true, roughness: 0.85, metalness: 0, depthWrite: false });
    this.mesh = new THREE.Mesh(new THREE.PlaneGeometry(faceW * 0.94, faceH * 0.92), mat);
    this.mesh.position.copy(this.surface);
    this.mesh.lookAt(this.surface.clone().add(normal));
    this.mesh.renderOrder = 2;
    this.mesh.name = 'panou_informativ_bilete';
    this.draw({ items: [], total: 0 });
  }

  /** Poziția camerei pentru „apropierea” de panou. */
  focusPose(aspect) {
    const vfov = THREE.MathUtils.degToRad(50);
    const needH = this.faceH * 1.25;
    const needW = this.faceW * 1.2;
    const distH = needH / 2 / Math.tan(vfov / 2);
    const distW = needW / 2 / (Math.tan(vfov / 2) * aspect);
    const d = Math.max(distH, distW, 0.9);
    return { position: this.surface.clone().addScaledVector(this.normal, d), lookAt: this.surface.clone() };
  }

  /** Punctul de interacțiune (în fața panoului, la podea). */
  interactionPoint() { return new THREE.Vector3(this.surface.x, 0, this.surface.z); }

  /** Dreptunghiul panoului pe ecran (pentru tranziția vizuală spre interfața HTML). */
  screenRect(camera, el) {
    const r = el.getBoundingClientRect();
    const right = new THREE.Vector3().crossVectors(new THREE.Vector3(0, 1, 0), this.normal).normalize();
    const pts = [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([sx, sy]) => this.surface.clone()
      .addScaledVector(right, sx * this.faceW / 2).add(new THREE.Vector3(0, sy * this.faceH / 2, 0)).project(camera));
    const xs = pts.map((p) => (p.x + 1) / 2 * r.width), ys = pts.map((p) => (1 - p.y) / 2 * r.height);
    return { left: Math.min(...xs), top: Math.min(...ys), width: Math.max(...xs) - Math.min(...xs), height: Math.max(...ys) - Math.min(...ys) };
  }

  draw(summary) {
    const c = this.canvas, ctx = c.getContext('2d');
    const W = c.width, H = c.height;
    ctx.clearRect(0, 0, W, H);
    // Antet discret pe panou.
    ctx.fillStyle = 'rgba(15, 23, 42, 0.82)';
    ctx.beginPath(); ctx.roundRect(W * 0.03, H * 0.03, W * 0.94, H * 0.12, 14); ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.font = `700 ${Math.round(H * 0.065)}px system-ui, "Segoe UI", sans-serif`;
    ctx.textBaseline = 'middle';
    ctx.fillText('Noutăți LPG', W * 0.06, H * 0.09);
    ctx.font = `500 ${Math.round(H * 0.045)}px system-ui, "Segoe UI", sans-serif`;
    ctx.textAlign = 'right';
    ctx.fillText(summary.total ? `${summary.total} active` : 'nicio noutate', W * 0.94, H * 0.09);
    ctx.textAlign = 'left';

    const items = summary.items || [];
    const cols = 3, rows = 3;
    const gx = W * 0.05, gy = H * 0.2, gw = W * 0.9, gh = H * 0.76;
    const cw = gw / cols, ch = gh / rows;
    items.slice(0, cols * rows).forEach((it, i) => {
      const col = i % cols, row = Math.floor(i / cols);
      const pal = COLORS[it.importanta] || COLORS.normala;
      const done = it.status === 'finalizat';
      const x = gx + col * cw + cw * 0.06, y = gy + row * ch + ch * 0.06;
      const w = cw * 0.88, h = ch * 0.86;
      const tilt = ((it.id * 37) % 7 - 3) * 0.012;
      ctx.save();
      ctx.translate(x + w / 2, y + h / 2); ctx.rotate(tilt); ctx.translate(-w / 2, -h / 2);
      ctx.globalAlpha = done ? 0.55 : 1;
      ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = 10; ctx.shadowOffsetY = 4;
      ctx.fillStyle = pal.bg; ctx.fillRect(0, 0, w, h);
      ctx.shadowColor = 'transparent';
      if (it.importanta !== 'normala') { ctx.fillStyle = pal.edge; ctx.fillRect(0, 0, w, h * 0.12); }
      ctx.fillStyle = pal.text;
      const fs = Math.round(h * 0.15);
      ctx.font = `600 ${fs}px system-ui, "Segoe UI", sans-serif`;
      wrap(ctx, it.titlu, w * 0.08, h * 0.32, w * 0.84, fs * 1.18, 3);
      ctx.font = `500 ${Math.round(h * 0.1)}px system-ui, "Segoe UI", sans-serif`;
      ctx.globalAlpha = done ? 0.5 : 0.75;
      ctx.fillText(formatDate(it.publicat_la), w * 0.08, h * 0.88);
      ctx.globalAlpha = 1;
      if (it.importanta === 'critica') {
        ctx.fillStyle = '#b91c1c'; ctx.beginPath(); ctx.arc(w / 2, h * 0.06, h * 0.07, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#fff'; ctx.font = `800 ${Math.round(h * 0.1)}px system-ui`; ctx.textAlign = 'center'; ctx.fillText('!', w / 2, h * 0.065); ctx.textAlign = 'left';
      }
      if (done) {
        ctx.strokeStyle = '#15803d'; ctx.lineWidth = h * 0.05; ctx.lineCap = 'round';
        ctx.beginPath(); ctx.moveTo(w * 0.62, h * 0.66); ctx.lineTo(w * 0.72, h * 0.78); ctx.lineTo(w * 0.9, h * 0.52); ctx.stroke();
      }
      ctx.restore();
    });
    if ((summary.total || 0) > items.length) {
      ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
      ctx.beginPath(); ctx.arc(W * 0.93, H * 0.92, H * 0.055, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.font = `700 ${Math.round(H * 0.045)}px system-ui`; ctx.textAlign = 'center';
      ctx.fillText(`+${summary.total - items.length}`, W * 0.93, H * 0.922); ctx.textAlign = 'left';
    }
    this.texture.needsUpdate = true;
  }
}

function wrap(ctx, text, x, y, maxW, lh, maxLines) {
  const words = String(text).split(/\s+/); let line = ''; let n = 0;
  for (let i = 0; i < words.length; i++) {
    const test = line ? line + ' ' + words[i] : words[i];
    if (ctx.measureText(test).width > maxW && line) {
      n++;
      if (n === maxLines) { ctx.fillText(line.replace(/.{0,2}$/, '…'), x, y); return; }
      ctx.fillText(line, x, y); y += lh; line = words[i];
    } else line = test;
  }
  if (line) ctx.fillText(line, x, y);
}

function formatDate(s) {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  return d.toLocaleDateString('ro-RO', { day: '2-digit', month: 'short' });
}
