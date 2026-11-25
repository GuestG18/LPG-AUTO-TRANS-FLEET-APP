// Lumea 3D: camera biroului (asset Sketchfab „XR test office room”, importat ca atare), lumini,
// mediu PBR, coliziuni. Componentele sunt separate (încărcare, lumini, obiecte interactive) ca
// recuzita să poată fi mutată/înlocuită fără rescrierea motorului.
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/GLTFLoader.js';

export const loader = new GLTFLoader();

/** Mediu PBR simplu (cameră luminoasă) generat local — fără fișiere HDR externe. */
function buildEnvironment(renderer) {
  const env = new THREE.Scene();
  const box = new THREE.Mesh(new THREE.BoxGeometry(12, 6, 12), new THREE.MeshBasicMaterial({ color: 0x6f6a64, side: THREE.BackSide }));
  box.position.y = 2.5;
  env.add(box);
  const panel = (w, h, x, y, z, ry, c, i) => {
    const m = new THREE.Mesh(new THREE.PlaneGeometry(w, h), new THREE.MeshBasicMaterial({ color: new THREE.Color(c).multiplyScalar(i), side: THREE.DoubleSide }));
    m.position.set(x, y, z); m.rotation.y = ry; env.add(m);
  };
  panel(6, 3, 5.9, 2.5, 0, -Math.PI / 2, 0xdfe9ff, 6);   // ferestre (dreapta)
  panel(6, 3, 0, 2.5, 5.9, Math.PI, 0xe8f0ff, 4);       // ferestre (față)
  const top = new THREE.Mesh(new THREE.PlaneGeometry(8, 8), new THREE.MeshBasicMaterial({ color: new THREE.Color(0xfff6e8).multiplyScalar(3) }));
  top.rotation.x = Math.PI / 2; top.position.y = 5.4; env.add(top);
  const pmrem = new THREE.PMREMGenerator(renderer);
  const rt = pmrem.fromScene(env, 0.04);
  pmrem.dispose();
  env.traverse((o) => { if (o.isMesh) { o.geometry.dispose(); o.material.dispose(); } });
  return rt.texture;
}

/**
 * Aduce coordonatele din world.json (unitățile modelului) la mărimea reală: înmulțește cu world.scale
 * toate lungimile (limite, obstacole, locuri, puncte de interacțiune, ecran). Nu atinge orientările
 * (ry, normal) și nici parametrii jucătorului (rază, viteze). Aceeași funcție există în server.js.
 */
export function scaleWorld(world) {
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
  return { ...walk(world, null), scale: s, unscaled: world };
}

export async function loadWorld({ renderer, scene, url, world, quality, onProgress }) {
  scene.environment = buildEnvironment(renderer);
  scene.background = new THREE.Color(0xdfe7ef);

  const gltf = await loader.loadAsync(url, (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); });
  const room = gltf.scene;
  room.name = 'office';
  // Mărime reală (vezi scaleWorld): modelul Sketchfab e la ~85% față de avatarurile Avaturn.
  room.scale.setScalar(world.scale || 1);

  const shadows = quality === 'high';
  room.traverse((o) => {
    if (!o.isMesh) return;
    o.castShadow = false;
    o.receiveShadow = shadows;
    const mats = Array.isArray(o.material) ? o.material : [o.material];
    for (const m of mats) {
      // Transmisia (sticlă / bidon) cere o randare suplimentară a scenei: pe PC-uri de birou
      // o înlocuim cu transparență simplă, aspectul rămâne apropiat.
      if (m.transmission > 0) { m.transmission = 0; m.transparent = true; m.opacity = 0.28; m.depthWrite = false; m.roughness = 0.05; }
      if (m.map) m.map.anisotropy = quality === 'high' ? 8 : 2;
      m.envMapIntensity = 0.55;
    }
  });
  scene.add(room);
  room.updateMatrixWorld(true);

  // Lumini: ambianță caldă + „soarele” din ferestre (singura care face umbre, doar la calitate înaltă).
  const hemi = new THREE.HemisphereLight(0xfff8f0, 0x8a8070, 0.9);
  scene.add(hemi);
  const sun = new THREE.DirectionalLight(0xfff1dc, 1.6);
  sun.position.set(6, 6.5, 4);
  sun.target.position.set(0, 0, 0);
  scene.add(sun, sun.target);
  if (shadows) {
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    const c = sun.shadow.camera;
    c.left = -6; c.right = 6; c.top = 6; c.bottom = -6; c.near = 1; c.far = 20;
    sun.shadow.bias = -0.0006;
    sun.shadow.normalBias = 0.02;
  }
  const fill = new THREE.PointLight(0xfff2e0, 6, 9, 1.6);
  fill.position.set(-1.5, 2.4, 0.2);
  scene.add(fill);

  const bounds = new THREE.Box3().setFromObject(room);
  // GLTFLoader curăță numele nodurilor (PropertyBinding.sanitizeNodeName): „Cube.036” devine „Cube036”.
  return { room, bounds, findNode: (name) => room.getObjectByName(THREE.PropertyBinding.sanitizeNodeName(name)) || room.getObjectByName(name) };
}

/**
 * Coliziuni 2D (cerc vs dreptunghiuri) pe datele din world.json — aceleași pe care le validează
 * serverul. Mișcarea alunecă de-a lungul obstacolelor (corecție pe axe separate).
 */
export class Collider {
  constructor(world) {
    this.w = world.walkable;
    this.obstacles = world.obstacles;
    this.r = world.player.radius;
  }
  blocked(x, z) {
    const r = this.r;
    if (x < this.w.minX + r || x > this.w.maxX - r || z < this.w.minZ + r || z > this.w.maxZ - r) return true;
    for (const o of this.obstacles) {
      const cx = Math.max(o.minX, Math.min(x, o.maxX));
      const cz = Math.max(o.minZ, Math.min(z, o.maxZ));
      const dx = x - cx, dz = z - cz;
      if (dx * dx + dz * dz < r * r) return true;
    }
    return false;
  }
  /** Mută (x,z) cu (dx,dz) cât se poate, alunecând pe margini. */
  move(x, z, dx, dz) {
    const steps = Math.max(1, Math.ceil(Math.hypot(dx, dz) / 0.05));
    const sx = dx / steps, sz = dz / steps;
    for (let i = 0; i < steps; i++) {
      if (!this.blocked(x + sx, z)) x += sx;
      if (!this.blocked(x, z + sz)) z += sz;
    }
    return [x, z];
  }
  /** Camera: intersecția razei cu obstacolele înalte (boxe 3D) — distanța maximă liberă. */
  cameraBoxes() {
    return this.obstacles.filter((o) => (o.height || 0) > 1.2).map((o) => new THREE.Box3(new THREE.Vector3(o.minX, 0, o.minZ), new THREE.Vector3(o.maxX, o.height, o.maxZ)));
  }
}
