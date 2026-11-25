// Copies the approved traced vehicles into the app and writes their data file:
//   htdocs/assets/img/xray-traced/{tractor,trailer}.svg + parts/*.svg
//   htdocs/assets/js/vehicle-xray-traced-data.js  (window.FleetXrayTracedData)
// Coordinates in the data file are mockup pixels (2000x667 image); vehicle-xray-art.js turns them
// into metres. Run after trace/parts.py:  node tools/vehicle-trace/export.js
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, 'trace', 'out');
const APP = path.join(__dirname, '..', '..', 'htdocs');
const IMG = path.join(APP, 'assets', 'img', 'xray-traced');
const man = JSON.parse(fs.readFileSync(path.join(OUT, 'parts.json'), 'utf8'));

// crop boxes of the two vehicles in the mockup (same as trace.py)
const CROP = { tractor: [14, 146, 703, 472], trailer: [699, 150, 1702, 466] };

// traced part id -> explorer category (VehicleComponentRegistry keys)
const CATEGORY = (id) => {
    if (/^tyre-/.test(id)) { return 'anvelope'; }
    if (/^(radiator|fan|cooling-hose)$/.test(id)) { return 'racire'; }
    if (/^(electrical|battery)$/.test(id)) { return 'electrica'; }
    if (id === 'steering') { return 'directie'; }
    if (id === 'engine') { return 'motor'; }
    if (id === 'exhaust') { return 'evacuare'; }
    if (/^(gearbox|driveshaft)$/.test(id)) { return 'transmisie'; }
    if (id === 'differential') { return 'diferential'; }
    if (/^air-spring/.test(id)) { return 'suspensie'; }
    if (/^(brake|hub)-/.test(id)) { return 'franare'; }
    if (/^(bearing|axle)-/.test(id)) { return 'rulare'; }
    if (id === 'fuel-tank') { return 'rezervor_combustibil'; }
    if (/^coupling/.test(id)) { return 'pneumatica'; }
    return 'alte'; // AdBlue + the LPG installation (no own system in the registry yet)
};

// The app ships grayscale bitmaps of the traced SVGs (rasterize.js renders them with Chromium; grayscale
// WebP here, offline): <veh>@1.webp for the overview, <veh>@2.webp only when a zoom needs it. Parts are
// not shipped as images: each part is an exact silhouette path (silhouettes.py), outlined when selected
// and filled with the component's condition colour.
fs.rmSync(IMG, { recursive: true, force: true });
fs.mkdirSync(IMG, { recursive: true });
require('child_process').execFileSync(path.join(__dirname, '.venv', 'Scripts', 'python.exe'),
    [path.join(__dirname, 'webp.py'), path.join(OUT, 'raster'), IMG, '92', '--gray', '--skip', 'parts'], { stdio: 'inherit' });
const SIL = JSON.parse(fs.readFileSync(path.join(OUT, 'silhouettes.json'), 'utf8'));
// every vehicle in the manifest -> the vehicle type + axle count it draws (page Auto configurations)
const VEHICLES = {
    tractor: ['cap_tractor', 2], trailer: ['semiremorca', 3],
    'camion-2': ['camion', 2], 'camion-3': ['camion', 3], 'camion-4': ['camion', 4],
    'semi-2': ['semiremorca', 2], 'semi-4': ['semiremorca', 4], 'semi-6': ['semiremorca', 6],
};
// pixel where the vehicle itself starts inside its crop (composed vehicles keep a 14 px margin)
const FRONT = { tractor: 14, trailer: 699 };
const templates = {};
Object.keys(VEHICLES).filter((veh) => man[veh]).forEach((veh) => {
    const crop = CROP[veh] || man[veh].crop;
    const [cx0, cy0] = crop;
    const tyres = {};
    const parts = [];
    man[veh].parts.forEach((p) => {
        // manifest boxes: 2 units per pixel, relative to the crop
        const box = [cx0 + p.x / 2, cy0 + p.y / 2, cx0 + (p.x + p.w) / 2, cy0 + (p.y + p.h) / 2];
        const tyre = p.id.match(/^tyre-a(\d)-(r|l)(?:-(out|in))?$/);
        if (tyre) {
            // key = axle + side code as in vehicule_anvelope_pozitii (R, L, RO, RI, LO, LI)
            const side = tyre[2].toUpperCase() + (tyre[3] ? (tyre[3] === 'out' ? 'O' : 'I') : '');
            tyres[tyre[1] + ':' + side] = box;
            return; // tyres are drawn from the full vehicle; only their boxes are needed
        }
        // sil: outline in quarter mockup pixels (integers), see silhouettes.py
        parts.push({ id: p.id, label: p.label, cat: CATEGORY(p.id), box: box, sil: (SIL[veh] || {})[p.id] || null });
    });
    const [type, axles] = VEHICLES[veh];
    templates[type + '|' + axles] = { name: veh, crop: crop, front: FRONT[veh] !== undefined ? FRONT[veh] : 14, file: veh + '@1.webp', hd: veh + '@2.webp', tyres: tyres, parts: parts };
});
const data = { templates: templates };

// cache-busting: the URLs carry a hash of everything exported, so browsers may cache them long
const hash = require('crypto').createHash('sha1');
Object.values(templates).forEach((t) => { hash.update(fs.readFileSync(path.join(IMG, t.file))); });
hash.update(JSON.stringify(data));
data.version = hash.digest('hex').slice(0, 10);

const js = '/* Generat de tools/vehicle-trace/export.js din macheta aprobata (vehiculele vectorizate 1:1).\n'
    + ' * Nu se editeaza de mana: coordonatele sunt pixeli (macheta 2000x667 sau vehiculul compus din bucatile ei). */\n'
    + 'window.FleetXrayTracedData = ' + JSON.stringify(data) + ';\n';
fs.writeFileSync(path.join(APP, 'assets', 'js', 'vehicle-xray-traced-data.js'), js);
console.log('ok', Object.keys(templates).map((k) => k + ' ' + Object.keys(templates[k].tyres).length + ' tyres/' + templates[k].parts.length + ' parts').join(', '));
