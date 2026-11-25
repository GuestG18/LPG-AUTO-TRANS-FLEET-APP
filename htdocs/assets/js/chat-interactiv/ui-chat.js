// Chat-ul general al camerei: compact, extensibil, mesaje persistente (PHP), livrare în timp real (WS).
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const time = (s) => new Date(String(s).replace(' ', 'T')).toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' });
const day = (s) => new Date(String(s).replace(' ', 'T')).toLocaleDateString('ro-RO', { day: 'numeric', month: 'long' });

export class ChatUI {
  constructor({ root, api, me, net }) {
    this.root = root;
    this.api = api;
    this.me = me;
    this.net = net;
    this.messages = [];
    this.unread = 0;
    this.lastReadId = 0;
    this.open = false;
    this.pending = new Map();
    this.root.innerHTML = `
      <button type="button" class="ci-chat-toggle" data-toggle aria-expanded="false">
        <i class="bi bi-chat-dots" aria-hidden="true"></i><span>Chat general</span>
        <span class="ci-online" data-online title="Participanți online"><i class="bi bi-circle-fill"></i> <b>0</b></span>
        <span class="ci-unread" data-unread hidden>0</span>
        <i class="bi bi-chevron-up ci-chev" aria-hidden="true"></i>
      </button>
      <div class="ci-chat-panel" data-panel hidden>
        <div class="ci-chat-people" data-people></div>
        <div class="ci-chat-offline" data-offline hidden><i class="bi bi-wifi-off"></i> Fără legătură live — mesajele noi se actualizează la câteva secunde; trimiterea revine la reconectare.</div>
        <div class="ci-chat-list" data-list role="log" aria-live="polite"></div>
        <form class="ci-chat-form" data-form autocomplete="off">
          <input class="form-control form-control-sm" data-input maxlength="1000" placeholder="Scrie un mesaj… (Enter trimite, Esc revine în lume)" aria-label="Mesaj">
          <button class="btn btn-sm btn-primary" type="submit" aria-label="Trimite"><i class="bi bi-send"></i></button>
        </form>
      </div>`;
    this.list = root.querySelector('[data-list]');
    this.input = root.querySelector('[data-input]');
    root.querySelector('[data-toggle]').addEventListener('click', () => this.setOpen(!this.open));
    root.querySelector('[data-form]').addEventListener('submit', (e) => { e.preventDefault(); e.stopPropagation(); this.send(); });
    this.input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.input.blur(); }
    });
    this.list.addEventListener('scroll', () => { if (this.list.scrollTop === 0 && this.messages.length) this.loadOlder(); });
  }

  setInitial(data) {
    this.messages = data.messages || [];
    this.lastReadId = data.last_read_id || 0;
    this.unread = data.unread || 0;
    this.renderAll();
    this.updateBadge();
  }

  setOpen(v, focus = false) {
    this.open = v;
    this.root.classList.toggle('is-open', v);
    this.root.querySelector('[data-panel]').hidden = !v;
    this.root.querySelector('[data-toggle]').setAttribute('aria-expanded', String(v));
    if (v) { this.scrollBottom(); this.markRead(); if (focus) this.input.focus(); }
  }

  focus() { this.setOpen(true, true); }

  setLive(live) {
    this.root.querySelector('[data-offline]').hidden = live;
    if (live) this.pollNew(); // după reconectare: recuperează ce s-a scris între timp
  }

  /** Mesajele apărute de la ultimul afișat (rezervă când legătura live lipsește). */
  async pollNew() {
    if (this.polling) return;
    this.polling = true;
    try {
      const lastId = this.messages.length ? this.messages[this.messages.length - 1].id : 0;
      const data = await this.api.get('messages');
      for (const m of data.messages) if (m.id > lastId) this.receive(m, null);
    } catch { /* se reîncearcă la următorul ciclu */ } finally { this.polling = false; }
  }

  setPeople(list) {
    this.root.querySelector('[data-online] b').textContent = String(list.length);
    this.root.querySelector('[data-people]').innerHTML = list.map((p) => `<span class="ci-person${p.id === this.me.id ? ' is-me' : ''}"><i class="bi bi-circle-fill"></i> ${esc(p.name)}${p.busy ? ` <small>(${({ 1: 'la panou', 2: 'la laptop', 3: 'privește ecranul' })[p.busy] || 'ocupat'})</small>` : ''}</span>`).join('');
  }

  renderAll() {
    this.list.innerHTML = this.messages.length ? '' : '<div class="ci-empty">Niciun mesaj încă. Salută-ți colegii!</div>';
    let prevDay = '';
    for (const m of this.messages) {
      const d = day(m.created_at);
      if (d !== prevDay) { this.list.insertAdjacentHTML('beforeend', `<div class="ci-day">${d}</div>`); prevDay = d; }
      this.list.insertAdjacentHTML('beforeend', this.row(m));
    }
    this.scrollBottom();
  }

  row(m, pending = false) {
    const mine = m.user_id === this.me.id;
    return `<div class="ci-msg${mine ? ' is-mine' : ''}${pending ? ' is-pending' : ''}" data-mid="${m.id || ''}" ${m.cid ? `data-cid="${m.cid}"` : ''}>
      <div class="ci-msg-meta"><b>${esc(mine ? 'Eu' : m.nume)}</b> <time>${m.created_at ? time(m.created_at) : '…'}</time></div>
      <div class="ci-msg-text">${esc(m.mesaj)}</div></div>`;
  }

  send() {
    const text = this.input.value.trim();
    if (!text) return;
    const cid = Math.random().toString(36).slice(2, 10);
    if (!this.net.send({ t: 'chat', text, cid })) { this.flash('Nu ești conectat la sediul virtual. Se reîncearcă automat.'); return; }
    this.input.value = '';
    this.list.querySelector('.ci-empty')?.remove();
    this.list.insertAdjacentHTML('beforeend', this.row({ user_id: this.me.id, nume: this.me.nume, mesaj: text, cid }, true));
    this.scrollBottom();
  }

  /** Mesaj primit de la server (inclusiv confirmarea propriului mesaj). */
  receive(m, cid) {
    if (cid) this.list.querySelector(`[data-cid="${cid}"]`)?.remove();
    if (this.messages.some((x) => x.id === m.id)) return;
    this.messages.push(m);
    this.list.querySelector('.ci-empty')?.remove();
    const prev = this.messages[this.messages.length - 2];
    if (!prev || day(prev.created_at) !== day(m.created_at)) this.list.insertAdjacentHTML('beforeend', `<div class="ci-day">${day(m.created_at)}</div>`);
    this.list.insertAdjacentHTML('beforeend', this.row(m));
    this.scrollBottom();
    if (m.user_id === this.me.id || (this.open && !document.hidden)) this.markRead();
    else { this.unread++; this.updateBadge(); }
  }

  error(cid, message) {
    const el = this.list.querySelector(`[data-cid="${cid}"]`);
    if (el) { el.classList.add('is-failed'); el.querySelector('time').textContent = 'netrimis'; }
    this.flash(message);
  }

  flash(text) {
    const t = document.createElement('div'); t.className = 'ci-chat-flash'; t.textContent = text;
    this.root.appendChild(t); setTimeout(() => t.remove(), 3500);
  }

  async loadOlder() {
    if (this.loadingOlder || this.noMore) return;
    this.loadingOlder = true;
    try {
      const data = await this.api.get('messages', { before: this.messages[0].id });
      if (!data.messages.length) { this.noMore = true; return; }
      const h = this.list.scrollHeight;
      this.messages = data.messages.concat(this.messages);
      this.renderAll();
      this.list.scrollTop = this.list.scrollHeight - h;
    } finally { this.loadingOlder = false; }
  }

  markRead() {
    const last = this.messages.length ? this.messages[this.messages.length - 1].id : 0;
    this.unread = 0; this.updateBadge();
    if (last > this.lastReadId) { this.lastReadId = last; this.api.post('chat_read', { last_id: last }).catch(() => {}); }
  }

  updateBadge() {
    const b = this.root.querySelector('[data-unread]');
    b.hidden = !this.unread; b.textContent = this.unread > 99 ? '99+' : String(this.unread);
  }

  scrollBottom() { requestAnimationFrame(() => { this.list.scrollTop = this.list.scrollHeight; }); }
}
