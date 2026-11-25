// Switch the app between the pre-optimisation and optimised rendering, for an A/B benchmark under
// identical conditions:  node state.js save-after | before | after
// "before" = vector SVG art (<image> of the traced SVGs), box-shadow pulse, no layer split, no
// animation cancel. Only touches the files below; everything else stays as is.
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..', '..');
const H = (p) => path.join(ROOT, 'htdocs', p);
const STATES = path.join(__dirname, 'states');
const FILES = ['assets/js/vehicle-xray.js', 'assets/js/vehicle-xray-art.js', 'assets/css/vehicle-xray.css', 'assets/js/vehicle-xray-traced-data.js'];
const OUT = path.join(__dirname, '..', 'trace', 'out');
const mode = process.argv[2];

const copyDir = (src, dst) => {
    fs.rmSync(dst, { recursive: true, force: true });
    fs.cpSync(src, dst, { recursive: true });
};

if (mode === 'save-after') {
    FILES.forEach((f) => { const d = path.join(STATES, 'after', f); fs.mkdirSync(path.dirname(d), { recursive: true }); fs.copyFileSync(H(f), d); });
    copyDir(H('assets/img/xray-traced'), path.join(STATES, 'after', 'img'));
    // build the "before" variant from the optimised files by undoing exactly the optimisation edits
    const B = (f) => path.join(STATES, 'before', f);
    FILES.forEach((f) => { fs.mkdirSync(path.dirname(B(f)), { recursive: true }); });
    const undo = (text, pairs, file) => pairs.reduce((t, [a, b]) => {
        if (t.indexOf(a) === -1) { throw new Error('missing in ' + file + ': ' + a.slice(0, 60)); }
        return t.replace(a, b);
    }, text);
    let js = fs.readFileSync(H(FILES[0]), 'utf8');
    js = undo(js, [
        ["        // Marire: desenul la rezolutie dubla se incarca acum, in paralel cu animatia.\n        if (this.fullView && this.fullView.w / target.w > 1.4) { this.upgradeArt(); }\n        // O noua tranzitie o opreste pe cea in curs (clicuri rapide: o singura bucla rAF, nu mai multe).\n        var token = this.viewAnim = (this.viewAnim || 0) + 1;\n", ''],
        ["            if (token !== self.viewAnim) { return; }\n", ''],
    ], FILES[0]);
    fs.writeFileSync(B(FILES[0]), js);
    let art = fs.readFileSync(H(FILES[1]), 'utf8');
    art = undo(art, [[", 'data-hd': geo.traced.tpl.hd ? tracedUrl(geo.traced.tpl.hd) : null,\n                    preserveAspectRatio: 'none', decoding: 'async',", ", preserveAspectRatio: 'none',"]], FILES[1]);
    fs.writeFileSync(B(FILES[1]), art);
    let css = fs.readFileSync(H(FILES[2]), 'utf8');
    const pulseStart = css.indexOf('.fx-explorer .fx-hot.is-selected { transform');
    const pulseEnd = css.indexOf('\n', css.indexOf('@media (prefers-reduced-motion: reduce) { .fx-explorer .fx-hot.is-selected::before'));
    css = css.slice(0, pulseStart)
        + '.fx-explorer .fx-hot.is-selected { transform: translate(-50%, -50%) scale(1.25); z-index: 6; animation: fxPulse 1.8s ease-out infinite; }\n'
        + '@keyframes fxPulse { 0% { box-shadow: 0 0 0 2px #fff, 0 0 0 4px color-mix(in srgb, var(--fx-accent) 70%, transparent), 0 0 18px 4px color-mix(in srgb, var(--fx-accent) 75%, transparent); } 100% { box-shadow: 0 0 0 2px #fff, 0 0 0 16px transparent, 0 0 18px 4px color-mix(in srgb, var(--fx-accent) 75%, transparent); } }'
        + css.slice(pulseEnd);
    const layerStart = css.indexOf('/* Straturi separate:');
    const layerEnd = css.indexOf('.fx-hots { contain: layout style size; }') + '.fx-hots { contain: layout style size; }'.length + 1;
    css = css.slice(0, layerStart) + css.slice(layerEnd);
    fs.writeFileSync(B(FILES[2]), css);
    // vector art: the traced SVGs, data pointing at them
    const data = JSON.parse(fs.readFileSync(H(FILES[3]), 'utf8').replace(/^[\s\S]*?window\.FleetXrayTracedData = /, '').replace(/;\s*$/, ''));
    const img = path.join(STATES, 'before', 'img');
    fs.rmSync(img, { recursive: true, force: true });
    fs.mkdirSync(path.join(img, 'parts'), { recursive: true });
    Object.values(data.templates).forEach((t) => {
        fs.copyFileSync(path.join(OUT, t.name + '.svg'), path.join(img, t.name + '.svg'));
        t.file = t.name + '.svg';
        delete t.hd;
        t.parts.forEach((p) => {
            const svg = p.file.replace('@2.webp', '.svg');
            fs.copyFileSync(path.join(OUT, svg), path.join(img, svg));
            p.file = svg;
        });
    });
    fs.writeFileSync(B(FILES[3]), 'window.FleetXrayTracedData = ' + JSON.stringify(data) + ';\n');
    console.log('saved after + built before');
} else if (mode === 'before' || mode === 'after') {
    FILES.forEach((f) => { fs.copyFileSync(path.join(STATES, mode, f), H(f)); });
    copyDir(path.join(STATES, mode, 'img'), H('assets/img/xray-traced'));
    console.log('app now in state', mode);
} else {
    console.log('usage: node state.js save-after | before | after');
}
