// Optimizează asset-urile 3D pentru Chat Interactiv. Originalele rămân neatinse în
// storage/chat-interactiv/originals/ (backup local); rezultatul merge în
// htdocs/assets/models/chat-interactiv/.
//   - office.glb: texturi redimensionate (max 2048 px, 1024 px pentru normal/roughness) și
//     convertite în WebP; geometria rămâne aceeași (fără decimare).
//   - avatar-a.glb: modelul Avaturn A (cu animația de mers „mixamo.com”), texturi WebP max 1024.
//   - avatar-a-idle.anim.glb: DOAR animația Idle din „model 1 animated.glb” (fără mesh/texturi).
//   - avatar-b.glb: modelul Avaturn B (avatar.zip), texturi WebP max 1024.
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';
import { textureCompress, prune, dedup } from '@gltf-transform/functions';
import sharp from 'sharp';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const src = path.join(root, 'storage', 'chat-interactiv', 'originals');
const out = path.join(root, 'htdocs', 'assets', 'models', 'chat-interactiv');
fs.mkdirSync(out, { recursive: true });

const io = new NodeIO().registerExtensions(ALL_EXTENSIONS);
const kb = (f) => (fs.statSync(f).size / 1048576).toFixed(2) + ' MB';

async function office() {
  const doc = await io.read(path.join(src, 'office', 'source', 'office.glb'));
  await doc.transform(
    dedup(),
    textureCompress({ encoder: sharp, targetFormat: 'webp', slots: /^(normalTexture|metallicRoughnessTexture|occlusionTexture|transmissionTexture)$/, resize: [1024, 1024], quality: 82 }),
    textureCompress({ encoder: sharp, targetFormat: 'webp', resize: [2048, 2048], quality: 85 }),
    prune(),
  );
  const f = path.join(out, 'office.glb');
  await io.write(f, doc);
  console.log('office.glb', kb(f));
}

async function avatar(input, output, { stripMorphs = false } = {}) {
  const doc = await io.read(input);
  if (stripMorphs) {
    // Avatarul B are ~84 blendshape-uri faciale (ARKit) cu greutatea 0: nu schimbă aspectul în repaus,
    // dar triplează fișierul. Nu animăm fața în această etapă, deci se scot.
    doc.getRoot().listMeshes().forEach((m) => { m.setWeights([]); m.listPrimitives().forEach((p) => p.listTargets().forEach((t) => { p.removeTarget(t); t.dispose(); })); });
  }
  await doc.transform(dedup(), textureCompress({ encoder: sharp, targetFormat: 'webp', resize: [1024, 1024], quality: 85 }), prune());
  const f = path.join(out, output);
  await io.write(f, doc);
  console.log(output, kb(f));
}

async function animOnly(input, output) {
  const doc = await io.read(input);
  const r = doc.getRoot();
  // Nodurile rămân (scheletul, ținta animației); se scot mesh-urile, skin-urile și materialele.
  r.listNodes().forEach((n) => { n.setMesh(null); n.setSkin(null); });
  r.listMeshes().forEach((m) => m.dispose());
  r.listSkins().forEach((s) => s.dispose());
  await doc.transform(prune({ keepLeaves: true }));
  const f = path.join(out, output);
  await io.write(f, doc);
  console.log(output, kb(f));
}

await office();
await avatar(path.join(src, 'avatar-a-walk-original.glb'), 'avatar-a.glb');
await animOnly(path.join(src, 'avatar-a-idle-original.glb'), 'avatar-a-idle.anim.glb');
await avatar(path.join(src, 'avatar', 'model.glb'), 'avatar-b.glb', { stripMorphs: true });
