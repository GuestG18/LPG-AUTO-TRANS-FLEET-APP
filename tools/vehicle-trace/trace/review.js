// Builds out/review.html: original vs traced (each vehicle + coupled), the part gallery, and a
// hover check that outlines each traced part on top of the traced vehicle.
const fs = require('fs');
const path = require('path');
const OUT = path.join(__dirname, 'out');
const man = JSON.parse(fs.readFileSync(path.join(OUT, 'parts.json'), 'utf8'));
const kb = (f) => Math.round(fs.statSync(path.join(OUT, f)).size / 1024);

// mockup pixel crops (x0,y0,x1,y1) of each vehicle, for the side-by-side
const CROP = { tractor: [14, 146, 703, 472], trailer: [699, 150, 1702, 466] };
const ORDER = ['tractor', 'trailer', 'camion-2', 'camion-3', 'camion-4', 'semi-2', 'semi-4', 'semi-6'];
const TITLE = {
  tractor: 'Cap tractor 4x2', trailer: 'Semiremorcă cisternă GPL (3 axe)',
  'camion-2': 'Camion 2 axe (4x2)', 'camion-3': 'Camion 3 axe (6x2)', 'camion-4': 'Camion 4 axe (8x2)',
  'semi-2': 'Semiremorcă 2 axe', 'semi-4': 'Semiremorcă 4 axe', 'semi-6': 'Semiremorcă 6 axe',
};
const orig = (veh, width) => {
  const [x0, y0, x1, y1] = CROP[veh];
  const s = width / (x1 - x0);
  return `<div class="win" style="width:${width}px;height:${((y1 - y0) * s).toFixed(0)}px"><img src="../../images/2.webp" style="position:absolute;left:${(-x0 * s).toFixed(1)}px;top:${(-y0 * s).toFixed(1)}px;width:${(2000 * s).toFixed(1)}px"></div>`;
};

const hoverView = (veh) => {
  const m = man[veh];
  const boxes = m.parts.map((p) => `<g class="pp" data-label="${p.label}"><image href="${p.file}" x="${p.x}" y="${p.y}" width="${p.w}" height="${p.h}"/><rect x="${p.x}" y="${p.y}" width="${p.w}" height="${p.h}" rx="6"/></g>`).join('');
  return `<svg class="hv" viewBox="0 0 ${m.width} ${m.height}"><image href="${veh}.svg" x="0" y="0" width="${m.width}" height="${m.height}"/>${boxes}</svg>`;
};

const gallery = (veh) => man[veh].parts.map((p) => `<figure class="part"><div class="chk"><img src="${p.file}" style="max-width:100%;max-height:150px"></div><figcaption><b>${p.label}</b><br><code>${p.id}</code> · ${p.category} · ${p.kb} KB</figcaption></figure>`).join('');

// coupled: both vehicles at mockup scale (2 units per px), trailer offset by its crop position
const [tx0, ty0] = CROP.tractor;
const [rx0, ry0] = CROP.trailer;
const coupled = `<svg viewBox="0 0 ${(CROP.trailer[2] - tx0) * 2} ${(Math.max(CROP.tractor[3], CROP.trailer[3]) - Math.min(ty0, ry0)) * 2}" class="cpl">
<image href="tractor.svg" x="0" y="${(ty0 - Math.min(ty0, ry0)) * 2}" width="${man.tractor.width}" height="${man.tractor.height}"/>
<image href="trailer.svg" x="${(rx0 - tx0) * 2}" y="${(ry0 - Math.min(ty0, ry0)) * 2}" width="${man.trailer.width}" height="${man.trailer.height}"/></svg>`;

const html = `<!doctype html><html><head><meta charset="utf-8"><title>Vehicle trace review</title><style>
body{font:14px system-ui;background:#eef3fb;margin:20px;color:#0f2a5c}h1{font-size:20px}h2{font-size:16px;margin:26px 0 8px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.win{position:relative;overflow:hidden;border-radius:10px;background:#e8eef9}
.cell{background:#fff;border:1px solid #dbe5f2;border-radius:12px;padding:8px}.cap{font-size:12px;color:#33476b;margin-top:4px}
.trc{width:100%;display:block;background:#e8eef9;border-radius:10px}.cpl{width:100%;background:#e8eef9;border-radius:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}.part{margin:0;background:#fff;border:1px solid #dbe5f2;border-radius:10px;padding:8px}
.chk{height:160px;display:grid;place-items:center;border-radius:8px;background:repeating-conic-gradient(#e9edf3 0 25%,#fff 0 50%) 0 0/16px 16px}
.part figcaption{font-size:12px;margin-top:6px}.hv{width:100%;background:#e8eef9;border-radius:12px}
.hv .pp image{opacity:0}.hv .pp rect{fill:transparent;stroke:transparent;stroke-width:4}.hv .pp:hover image{opacity:1}.hv .pp:hover rect{stroke:#f97316}
.toc{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:6px;padding:8px 0;background:#eef3fb}.toc a{padding:5px 10px;border-radius:999px;background:#fff;border:1px solid #dbe5f2;color:#0f2a5c;text-decoration:none;font-size:13px}.toc a:hover{border-color:#1d6ff2}
#tip{position:fixed;pointer-events:none;background:#0f1d36;color:#fff;padding:4px 8px;border-radius:6px;font-size:12px;display:none}
</style></head><body><h1>Vehicle artwork — the whole heavy fleet</h1>
<p>Vectorized directly from the mockup pixels (no redrawing). Only the axle label pills and leader lines that lay on top of the vehicles were removed. Full vehicles: tractor ${kb('tractor.svg')} KB, trailer ${kb('trailer.svg')} KB.</p>
<h2>Coupled (as in the mockup)</h2><div class="cell"><div class="win" style="width:100%;aspect-ratio:${(CROP.trailer[2] - tx0)}/${Math.max(CROP.tractor[3], CROP.trailer[3]) - Math.min(ty0, ry0)}"><img src="../../images/2.webp" style="position:absolute;left:${(-tx0 / (CROP.trailer[2] - tx0) * 100).toFixed(3)}%;top:${(-Math.min(ty0, ry0) / (Math.max(CROP.tractor[3], CROP.trailer[3]) - Math.min(ty0, ry0)) * 100).toFixed(3)}%;width:${(2000 / (CROP.trailer[2] - tx0) * 100).toFixed(3)}%"></div><div class="cap">Mockup</div></div>
<div class="cell" style="margin-top:10px">${coupled}<div class="cap">Traced SVG (labels removed)</div></div>
<nav class="toc">${ORDER.filter((v) => man[v]).map((v) => `<a href="#v-${v}">${TITLE[v]}</a>`).join('')}</nav>
${ORDER.filter((v) => man[v]).map((v) => `<section id="v-${v}"><h2>${TITLE[v]} — ${CROP[v] ? 'original vs traced' : 'composed from approved pieces vs traced'}</h2>
<div class="row"><div class="cell">${CROP[v] ? orig(v, 860) : `<img class="trc" src="composed/${v}.png">`}<div class="cap">${CROP[v] ? 'Mockup pixels' : 'Composed image (only approved pixels)'}</div></div><div class="cell"><img class="trc" src="${v}.svg"><div class="cap">Traced SVG · ${kb(v + '.svg')} KB</div></div></div>
<h2>${TITLE[v]}: parts — hover to see each part's cut-out</h2><div class="cell">${hoverView(v)}</div>
<h2>${TITLE[v]}: parts (${man[v].parts.length}), each a separate SVG</h2><div class="grid">${gallery(v)}</div></section>`).join('')}
<div id="tip"></div><script>
const tip=document.getElementById('tip');document.querySelectorAll('.pp').forEach(g=>{g.addEventListener('mousemove',e=>{tip.style.display='block';tip.style.left=e.clientX+12+'px';tip.style.top=e.clientY+12+'px';tip.textContent=g.dataset.label});g.addEventListener('mouseleave',()=>tip.style.display='none')});
</script></body></html>`;
fs.writeFileSync(path.join(OUT, 'review.html'), html);
console.log('ok');
