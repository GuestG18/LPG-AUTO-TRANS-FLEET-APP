// Static preview server for the review page: node serve.js  ->  http://localhost:8777/trace/out/review.html
const http = require('http');
const fs = require('fs');
const p = require('path');
const TYPES = { '.svg': 'image/svg+xml', '.html': 'text/html; charset=utf-8', '.webp': 'image/webp', '.png': 'image/png', '.json': 'application/json' };
const PORT = Number(process.env.PORT || 8777);
http.createServer((q, r) => {
  const f = p.join(__dirname, decodeURIComponent(q.url.split('?')[0]));
  if (!f.startsWith(__dirname)) { r.writeHead(403); r.end(); return; }
  fs.readFile(f, (err, b) => {
    if (err) { r.writeHead(404); r.end(); return; }
    r.writeHead(200, { 'Content-Type': TYPES[p.extname(f)] || 'application/octet-stream' });
    r.end(b);
  });
}).listen(PORT, () => console.log(`Review page: http://localhost:${PORT}/trace/out/review.html`));
