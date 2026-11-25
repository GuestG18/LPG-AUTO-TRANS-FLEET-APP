// Phase II report: results-p2-phase1-{a,b} (optimised version before phase II, rebuilt in memory) vs
// results-p2-now-{a,b} (files on disk). Frame percentiles from the pooled raw frame intervals of both runs.
const fs = require('fs');
const path = require('path');
const load = (n) => { const f = path.join(__dirname, 'results-' + n + '.json'); return fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : null; };
// usage: node report-p2.js [beforePrefix] [afterPrefix]   default p2-phase1 / p2-now (files <prefix>-a / -b)
const BEFORE = process.argv[2] || 'p2-phase1';
const AFTER = process.argv[3] || 'p2-now';
const P1 = [BEFORE + '-a', BEFORE + '-b'].map(load).filter(Boolean);
const NOW = [AFTER + '-a', AFTER + '-b'].map(load).filter(Boolean);
const STEPS = ['selectTyre', 'switchSystem', 'zoom', 'hover'];
const r1 = (x) => Math.round(x * 10) / 10;
const pct = (arr, p) => { const s = arr.slice().sort((a, b) => a - b); return s.length ? r1(s[Math.min(s.length - 1, Math.floor(s.length * p))]) : 0; };
const mean = (arr) => (arr.length ? r1(arr.reduce((a, b) => a + b, 0) / arr.length) : 0);

const side = (runs, name, steps) => {
    const cfgs = runs.map((r) => r.configs.find((c) => c.name === name)).filter((c) => c && c.steps && c.steps.zoom);
    const frames = [];
    let dropped = 0;
    cfgs.forEach((c) => steps.forEach((k) => { frames.push(...(c.steps[k].raw || [])); dropped += c.steps[k].dropped; }));
    const sel = [];
    cfgs.forEach((c) => { if (c.selectionLatency) { sel.push(c.selectionLatency.median); } });
    const sum = (f) => mean(cfgs.map((c) => steps.reduce((s, k) => s + c.steps[k][f], 0)));
    return {
        runs: cfgs.length,
        elements: mean(cfgs.map((c) => c.elements || 0)),
        loadCold: mean(cfgs.map((c) => c.steps.load.wallMs)), loadWarm: mean(cfgs.map((c) => (c.steps.loadWarm || {}).wallMs || 0)),
        frames: frames.length, p50: pct(frames, 0.5), p95: pct(frames, 0.95), p99: pct(frames, 0.99), max: r1(Math.max(0, ...frames)), avg: mean(frames),
        over16: r1(frames.filter((d) => d > 17.5).length / (frames.length || 1) * 100), over33: r1(frames.filter((d) => d > 33.4).length / (frames.length || 1) * 100), over50: r1(frames.filter((d) => d > 50).length / (frames.length || 1) * 100),
        droppedRate: r1(dropped / ((frames.length + dropped) || 1) * 100),
        selMedian: mean(sel), selWorst: r1(Math.max(0, ...cfgs.map((c) => (c.selectionLatency || {}).worst || 0))),
        scripting: sum('scripting'), styleLayout: sum('styleLayout'), paint: sum('paint'), raster: sum('raster'), longTasks: sum('longTasks'),
        heapAfter: mean(cfgs.map((c) => c.afterCycles.heapMB)), docs: mean(cfgs.map((c) => c.afterCycles.documents)), listeners: mean(cfgs.map((c) => c.afterCycles.jsEventListeners)),
    };
};
const names = (P1[0] || NOW[0]).configs.map((c) => c.name);
const out = names.map((n) => ({ name: n, all: { phase1: side(P1, n, STEPS), now: side(NOW, n, STEPS) }, zoom: { phase1: side(P1, n, ['zoom']), now: side(NOW, n, ['zoom']) } }));
fs.writeFileSync(path.join(__dirname, 'report-' + AFTER + '.json'), JSON.stringify(out, null, 1));
const KEYS = ['elements', 'loadCold', 'loadWarm', 'selMedian', 'selWorst', 'p50', 'p95', 'p99', 'max', 'avg', 'over16', 'over33', 'over50', 'droppedRate', 'scripting', 'styleLayout', 'paint', 'raster', 'longTasks', 'heapAfter', 'docs', 'listeners'];
out.forEach((c) => {
    console.log('== ' + c.name + '  (runs ' + c.all.phase1.runs + '/' + c.all.now.runs + ')');
    KEYS.forEach((k) => console.log('  ' + k.padEnd(12), String(c.all.phase1[k]).padStart(8), '->', String(c.all.now[k]).padStart(8), '   zoom only:', String(c.zoom.phase1[k]).padStart(7), '->', String(c.zoom.now[k]).padStart(7)));
});
