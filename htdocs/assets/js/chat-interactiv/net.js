// Clientul WebSocket: intrare cu token semnat de PHP, reconectare automată cu token nou,
// trimiterea stării locale (≤10/s, doar la schimbare), evenimente pentru restul aplicației.
export class Net extends EventTarget {
  constructor({ url, getToken }) {
    super();
    this.url = url;
    this.getToken = getToken;     // async () => token nou (action=ws_token)
    this.ws = null;
    this.connected = false;
    this.stopped = false;
    this.retry = 0;
    this.lastSent = null;
    this.lastSentAt = 0;
  }

  emit(type, detail) { this.dispatchEvent(new CustomEvent(type, { detail })); }

  async connect(token) {
    if (this.stopped) return;
    this.emit('status', { state: 'connecting' });
    let ws;
    try {
      ws = new WebSocket(this.url);
    } catch (e) {
      this.scheduleReconnect();
      return;
    }
    this.ws = ws;
    ws.onopen = () => ws.send(JSON.stringify({ t: 'hello', token }));
    ws.onmessage = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.t === 'welcome') { this.connected = true; this.retry = 0; this.lastSent = null; this.emit('status', { state: 'online' }); }
      if (m.t === 'replaced') { this.stopped = true; }
      this.emit(m.t, m);
    };
    ws.onclose = (ev) => {
      const was = this.connected;
      this.connected = false;
      this.ws = null;
      if (ev.code === 4001 || this.stopped) { this.stopped = true; this.emit('status', { state: 'replaced' }); return; }
      if (ev.code === 4002) { this.emit('status', { state: 'full' }); }
      this.emit('status', { state: 'offline', wasConnected: was, code: ev.code });
      this.scheduleReconnect();
    };
    ws.onerror = () => { /* onclose urmează */ };
  }

  scheduleReconnect() {
    if (this.stopped) return;
    const delay = Math.min(15000, 800 * 2 ** this.retry) + Math.random() * 400;
    this.retry++;
    clearTimeout(this.timer);
    this.timer = setTimeout(async () => {
      try { await this.connect(await this.getToken()); } catch { this.scheduleReconnect(); }
    }, delay);
  }

  send(msg) {
    if (this.ws && this.connected && this.ws.readyState === 1) { this.ws.send(JSON.stringify(msg)); return true; }
    return false;
  }

  /** Starea locală: trimisă la cel mult 10 Hz și doar dacă s-a schimbat (plus un „keep-alive” la 2 s). */
  sendState(x, z, ry, a) {
    const now = performance.now();
    if (now - this.lastSentAt < 100) return;
    const l = this.lastSent;
    const changed = !l || Math.abs(l.x - x) > 0.01 || Math.abs(l.z - z) > 0.01 || Math.abs(l.ry - ry) > 0.02 || l.a !== a;
    if (!changed && now - this.lastSentAt < 2000) return;
    if (this.send({ t: 's', x: Math.round(x * 100) / 100, z: Math.round(z * 100) / 100, ry: Math.round(ry * 100) / 100, a })) {
      this.lastSent = { x, z, ry, a };
      this.lastSentAt = now;
    }
  }

  close() { this.stopped = true; clearTimeout(this.timer); this.ws?.close(1000); }
}
