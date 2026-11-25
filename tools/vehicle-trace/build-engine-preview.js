// Builds the standalone engine SVG assets + an HTML preview at the real diagram sizes.
const fs = require('fs');
const path = require('path');
const E = require('C:/laragon/www/aplicatie_fleet/htdocs/assets/js/vehicle-xray-engine.js');
const out = __dirname;

const truck = { length: 127, width: 100, cylinders: 6, aircomp: true, outputHalf: 32 };
const car = { length: 82, width: 90, cylinders: 4, aircomp: false, outputHalf: 22 };

const standalone = (o) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="-6 -8 ${o.length + 10} ${o.width + 16}" width="${(o.length + 10) * 5}" height="${(o.width + 16) * 5}"><defs>${E.defs()}</defs>${E.engine(o)}</svg>`;
fs.writeFileSync(path.join(out, 'engine-truck.svg'), standalone(truck));
fs.writeFileSync(path.join(out, 'engine-car.svg'), standalone(car));

// Old engine (as drawn before), for side-by-side comparison, in cm.
const oldEngine = (o) => {
  const L = o.length, H = o.width, cyl = o.cylinders, car = cyl === 4;
  let s = `<rect x="0" y="0" width="${L}" height="${H}" rx="12" fill="#d5dbe3" stroke="#7d8da3" stroke-width="1.1"/>`;
  s += `<rect x="8" y="12" width="${L - 20}" height="${H - 24}" rx="8" fill="none" stroke="rgba(51,65,85,.45)" stroke-width="1.2"/>`;
  for (let c = 0; c < cyl; c++) { s += `<circle cx="${20 + c * ((L - 40) / (cyl - 1))}" cy="${H / 2}" r="${car ? 7 : 10}" fill="#64748b"/>`; }
  s += `<circle cx="-2" cy="${H / 2}" r="${car ? 16 : 26}" fill="#e2e8f0" stroke="#7d8da3"/>`;
  return s;
};

// Engine placed in a mini chassis context: radiator (left), gearbox + driveshaft (right), rails.
const context = (o, art, pxPerM, halo) => {
  const k = 1; // cm units inside, scaled by width attribute
  const L = o.length, H = o.width;
  const x0 = -40, w = L + 150, y0 = -25, h = H + 50;
  const gb = { x0: L + 2, x1: L + 80, half: o.outputHalf };
  let s = `<rect x="${-36}" y="${H / 2 - 82}" width="22" height="164" rx="4" fill="#e2e8f0" stroke="#7d8da3"/>`; // radiator
  for (let y = H / 2 - 74; y < H / 2 + 80; y += 10) s += `<line x1="-34" x2="-16" y1="${y}" y2="${y}" stroke="rgba(51,65,85,.45)"/>`;
  s += `<path d="M${gb.x0} ${H / 2 - gb.half} L${gb.x1} ${H / 2 - gb.half + 10} L${gb.x1} ${H / 2 + gb.half - 10} L${gb.x0} ${H / 2 + gb.half} Z" fill="#d5dbe3" stroke="#7d8da3"/>`;
  s += `<rect x="${gb.x1}" y="${H / 2 - 7}" width="60" height="14" fill="#94a3b8" stroke="#64748b"/>`;
  if (halo) s += `<rect x="-6" y="-6" width="${L + 12}" height="${H + 12}" rx="12" fill="#fb923c" stroke="#9a3412" stroke-width="1.1" opacity=".9"/>`;
  s += art;
  const vbW = w, vbH = h;
  const pxW = (vbW / 100) * pxPerM;
  return `<svg viewBox="${x0} ${y0} ${vbW} ${vbH}" width="${pxW.toFixed(0)}" height="${(pxW * vbH / vbW).toFixed(0)}">${s}</svg>`;
};

const row = (title, o, pxPerM) => `
<div class="row"><h3>${title} — ${pxPerM} px/m (engine ≈ ${(o.length / 100 * pxPerM).toFixed(0)}×${(o.width / 100 * pxPerM).toFixed(0)} px)</h3>
<div class="pair"><figure>${context(o, oldEngine(o), pxPerM)}<figcaption>before</figcaption></figure>
<figure>${context(o, E.engine(o), pxPerM)}<figcaption>new</figcaption></figure>
<figure>${context(o, E.engine(o), pxPerM, true)}<figcaption>new, category active</figcaption></figure>
<figure class="gray">${context(o, E.engine(o), pxPerM)}<figcaption>grayscale silhouette check</figcaption></figure></div></div>`;

const html = `<!doctype html><html><head><meta charset="utf-8"><title>Engine asset preview</title>
<style>body{font:14px system-ui;background:#f2f6fc;margin:20px;color:#0f2a5c}h3{margin:18px 0 6px;font-size:14px}
.pair{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end}figure{margin:0;background:radial-gradient(#fff,#e8eff8);padding:6px;border-radius:10px}
figcaption{font-size:12px;color:#33476b;text-align:center}.gray svg{filter:grayscale(1) contrast(1.1)}.big svg{background:#fff;border-radius:10px}</style>
<svg width="0" height="0" style="position:absolute"><defs>${E.defs()}</defs></svg></head><body>
<h2>Engine asset — top-down, front of vehicle on the left</h2>
${row('Truck, full tractor+trailer assembly (actual)', truck, 68)}
${row('Truck, inspector open (narrow stage)', truck, 45)}
${row('Truck, isolated tractor', truck, 104)}
${row('Car (actual)', car, 100)}
<div class="row big"><h3>Large (zoomed / drill-down)</h3><div class="pair">
<figure><svg viewBox="-6 -8 ${truck.length + 10} ${truck.width + 16}" width="760">${E.engine(truck)}</svg><figcaption>inline-6 truck diesel</figcaption></figure>
<figure><svg viewBox="-6 -8 ${car.length + 10} ${car.width + 16}" width="440">${E.engine(car)}</svg><figcaption>inline-4 car</figcaption></figure></div></div>
</body></html>`;
fs.writeFileSync(path.join(out, 'engine-preview.html'), html);
console.log('ok');
