// Before/after summary from results-final-{before,before2,now,now2}.json (same harness, GPU, same session).
// Each figure = mean of the two runs; "±" = half the difference between the runs (noise indicator).
const fs = require('fs');
const path = require('path');
const load = (n) => { const f = path.join(__dirname, 'results-' + n + '.json'); return fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : null; };
const B = [load('final-before'), load('final-before2')].filter(Boolean);
const A = [load('final-now'), load('final-now2')].filter(Boolean);
const STEPS = ['selectTyre', 'switchSystem', 'zoom', 'hover'];
const r1 = (x) => Math.round(x * 10) / 10;
const pair = (runs, i, get) => {
    const v = runs.map((run) => { try { return get(run.configs[i]); } catch (e) { return NaN; } }).filter((x) => !isNaN(x));
    if (!v.length) { return null; }
    const m = v.reduce((a, b) => a + b, 0) / v.length;
    return { m: r1(m), pm: v.length > 1 ? r1(Math.abs(v[0] - v[1]) / 2) : 0 };
};
const fmt = (p) => (p ? p.m + (p.pm ? ' ±' + p.pm : '') : '—');
const sumSteps = (c, f) => STEPS.reduce((s, k) => s + c.steps[k][f], 0);
const maxSteps = (c, f) => Math.max(...STEPS.map((k) => c.steps[k][f]));
const allFrames = (c) => { const n = STEPS.reduce((s, k) => s + c.steps[k].frames, 0); return STEPS.reduce((s, k) => s + c.steps[k].avg * c.steps[k].frames, 0) / n; };
const METRICS = [
    ['SVG DOM nodes (scheme)', (c) => c.afterLoad.svgNodes],
    ['Scheme DOM nodes (all)', (c) => c.afterLoad.explorerNodes],
    ['Load: wall to art painted (ms)', (c) => c.steps.load.wallMs],
    ['Load: raster (ms)', (c) => c.steps.load.raster],
    ['Load: image decode (ms)', (c) => c.steps.load.imageDecode],
    ['Tyre selection median (ms)', (c) => c.selectionLatency.median],
    ['Tyre selection worst (ms)', (c) => c.selectionLatency.worst],
    ['Avg frame, all interactions (ms)', allFrames],
    ['Worst frame, all interactions (ms)', (c) => maxSteps(c, 'worst')],
    ['Dropped frames, all interactions', (c) => sumSteps(c, 'dropped')],
    ['Zoom: avg frame (ms)', (c) => c.steps.zoom.avg],
    ['Zoom: worst frame (ms)', (c) => c.steps.zoom.worst],
    ['Zoom: dropped frames', (c) => c.steps.zoom.dropped],
    ['Hover: dropped frames', (c) => c.steps.hover.dropped],
    ['JS scripting, interactions (ms)', (c) => sumSteps(c, 'scripting')],
    ['Style + layout, interactions (ms)', (c) => sumSteps(c, 'styleLayout')],
    ['Paint, interactions (ms)', (c) => sumSteps(c, 'paint')],
    ['Raster, interactions (ms)', (c) => sumSteps(c, 'raster')],
    ['Long tasks >50 ms, interactions', (c) => sumSteps(c, 'longTasks')],
    ['Longest task (ms)', (c) => maxSteps(c, 'longestTask')],
    ['JS heap after load (MB)', (c) => c.afterLoad.heapMB],
    ['JS heap after 4 cycles (MB)', (c) => c.afterCycles.heapMB],
    ['Documents after cycles', (c) => c.afterCycles.documents],
    ['JS event listeners after cycles', (c) => c.afterCycles.jsEventListeners],
];
const out = { configs: [], totals: {} };
B[0].configs.forEach((cfg, i) => {
    const row = { name: cfg.name, metrics: {} };
    METRICS.forEach(([label, get]) => { row.metrics[label] = { before: pair(B, i, get), now: pair(A, i, get) }; });
    out.configs.push(row);
});
// fleet-wide: mean over the 9 configurations
METRICS.forEach(([label]) => {
    const b = out.configs.map((c) => c.metrics[label].before && c.metrics[label].before.m).filter((x) => x !== null && !isNaN(x));
    const a = out.configs.map((c) => c.metrics[label].now && c.metrics[label].now.m).filter((x) => x !== null && !isNaN(x));
    out.totals[label] = { before: r1(b.reduce((s, x) => s + x, 0) / b.length), now: r1(a.reduce((s, x) => s + x, 0) / a.length) };
});
fs.writeFileSync(path.join(__dirname, 'report-final.json'), JSON.stringify(out, null, 1));
if (process.argv[2] === 'print') {
    console.log('== mean over all configurations (before -> now)');
    Object.entries(out.totals).forEach(([k, v]) => console.log(k.padEnd(38), String(v.before).padStart(8), '->', String(v.now).padStart(8)));
    out.configs.forEach((c) => {
        console.log('== ' + c.name);
        ['Load: wall to art painted (ms)', 'Tyre selection median (ms)', 'Tyre selection worst (ms)', 'Zoom: worst frame (ms)', 'Dropped frames, all interactions', 'Raster, interactions (ms)', 'Long tasks >50 ms, interactions', 'JS heap after 4 cycles (MB)'].forEach((k) => {
            console.log('  ' + k.padEnd(36), fmt(c.metrics[k].before).padStart(14), '->', fmt(c.metrics[k].now).padStart(14));
        });
    });
}
