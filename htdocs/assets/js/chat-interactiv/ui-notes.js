// Interfața panoului informativ (HTML/CSS) — deschisă din panoul 3D după apropierea camerei.
// Trei categorii: Noutăți generale · Notele mele · Termene limită. Toate verificările de drept sunt
// pe server; aici doar ascundem butoanele pe care serverul oricum le-ar respinge.
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = (s, time = true) => {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  return d.toLocaleString('ro-RO', time ? { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: 'short', year: 'numeric' });
};
const LABEL = {
  importanta: { normala: 'Normală', ridicata: 'Ridicată', critica: 'Critică' },
  prioritate: { scazuta: 'Scăzută', normala: 'Normală', ridicata: 'Ridicată', urgenta: 'Urgentă' },
  status: { activ: 'Activ', in_lucru: 'În lucru', finalizat: 'Finalizat', arhivat: 'Arhivat' },
  stare: { urmator: 'Următor', depasit: 'Depășit', finalizat: 'Finalizat', arhivat: 'Arhivat' },
};
const TABS = {
  noutate: { title: 'Noutăți generale', states: [['', 'Active'], ['arhivate', 'Arhivă']] },
  personal: { title: 'Notele mele', states: [['', 'Active'], ['finalizate', 'Finalizate'], ['arhivate', 'Arhivă']] },
  termen: { title: 'Termene limită', states: [['', 'Active'], ['urmatoare', 'Următoare'], ['depasite', 'Depășite'], ['finalizate', 'Finalizate'], ['arhivate', 'Arhivă']] },
};
const REMINDERS = [['', 'Fără memento'], ['15', '15 minute înainte'], ['60', '1 oră înainte'], ['1440', '1 zi înainte'], ['4320', '3 zile înainte']];

export class NotesUI {
  constructor({ root, api, me, perms, users, onChanged, onClose }) {
    this.root = root;
    this.api = api;
    this.me = me;
    this.perms = perms;
    this.users = users;
    this.onChanged = onChanged;   // după o modificare: panoul 3D + ceilalți jucători
    this.onClose = onClose;
    this.cat = 'noutate';
    this.state = '';
    this.counts = {};
    this.render();
  }

  render() {
    this.root.innerHTML = `
      <div class="ci-notes-head">
        <div class="ci-notes-title"><i class="bi bi-pin-angle" aria-hidden="true"></i> Panou informativ</div>
        <button type="button" class="ci-icon-btn" data-close title="Închide (Esc)" aria-label="Închide"><i class="bi bi-x-lg"></i></button>
      </div>
      <nav class="ci-notes-tabs" role="tablist">
        ${Object.entries(TABS).map(([k, t]) => `<button type="button" role="tab" data-tab="${k}">${t.title} <span class="ci-count" data-count="${k}"></span></button>`).join('')}
      </nav>
      <div class="ci-notes-bar">
        <div class="ci-chips" data-states></div>
        <button type="button" class="btn btn-sm btn-primary" data-new><i class="bi bi-plus-lg"></i> <span>Adaugă</span></button>
      </div>
      <div class="ci-notes-body">
        <div class="ci-notes-list" data-list aria-live="polite"></div>
        <aside class="ci-notes-detail" data-detail hidden></aside>
      </div>`;
    this.root.querySelector('[data-close]').addEventListener('click', () => this.onClose());
    this.root.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { this.cat = b.dataset.tab; this.state = ''; this.load(); }));
    this.root.querySelector('[data-new]').addEventListener('click', () => this.openForm());
  }

  async load() {
    this.root.querySelectorAll('[data-tab]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === this.cat)));
    const states = this.root.querySelector('[data-states]');
    states.innerHTML = TABS[this.cat].states.map(([v, l]) => `<button type="button" class="ci-chip${v === this.state ? ' is-active' : ''}" data-state="${v}">${l}</button>`).join('');
    states.querySelectorAll('[data-state]').forEach((b) => b.addEventListener('click', () => { this.state = b.dataset.state; this.load(); }));
    const newBtn = this.root.querySelector('[data-new]');
    newBtn.hidden = this.cat === 'noutate' && !this.perms.publish_news;
    newBtn.querySelector('span').textContent = this.cat === 'noutate' ? 'Publică noutate' : this.cat === 'personal' ? 'Notă nouă' : 'Termen nou';
    const list = this.root.querySelector('[data-list]');
    list.innerHTML = '<div class="ci-empty">Se încarcă…</div>';
    this.closeDetail();
    try {
      const data = await this.api.get('notes', { cat: this.cat, state: this.state });
      this.setCounts(data.counts);
      list.innerHTML = data.notes.length ? data.notes.map((n) => this.card(n)).join('') : `<div class="ci-empty">${this.emptyText()}</div>`;
      list.querySelectorAll('[data-note]').forEach((el) => el.addEventListener('click', () => this.openDetail(Number(el.dataset.note))));
    } catch (e) {
      list.innerHTML = `<div class="ci-empty ci-error">${esc(e.message)}</div>`;
    }
  }

  emptyText() {
    if (this.cat === 'noutate') return this.state ? 'Nicio noutate arhivată.' : 'Nicio noutate activă pe panou.';
    if (this.cat === 'personal') return 'Nu ai note în această listă.';
    return 'Niciun termen în această listă.';
  }

  setCounts(c) {
    if (!c) return;
    this.counts = c;
    const set = (k, v, alert) => { const el = this.root.querySelector(`[data-count="${k}"]`); if (el) { el.textContent = v ? String(v) : ''; el.classList.toggle('is-alert', !!alert); } };
    set('noutate', c.noutati_necitite || 0, (c.noutati_necitite || 0) > 0);
    set('personal', c.personale || 0);
    set('termen', (c.urmatoare || 0) + (c.depasite || 0), (c.depasite || 0) > 0);
  }

  card(n) {
    const badges = [];
    if (n.categorie === 'noutate') {
      if (n.importanta !== 'normala') badges.push(`<span class="ci-badge imp-${n.importanta}">${LABEL.importanta[n.importanta]}</span>`);
      if (!n.citit && n.autor_id !== this.me.id) badges.push('<span class="ci-badge is-new">Nou</span>');
      if (n.confirmat) badges.push('<span class="ci-badge is-ok"><i class="bi bi-check2"></i> Confirmat</span>');
      if (n.public_restrans) badges.push('<span class="ci-badge"><i class="bi bi-people"></i> Public restrâns</span>');
    } else {
      badges.push(`<span class="ci-badge pri-${n.prioritate}">${LABEL.prioritate[n.prioritate]}</span>`);
      badges.push(`<span class="ci-badge st-${n.stare}">${n.categorie === 'termen' ? LABEL.stare[n.stare] : LABEL.status[n.status]}</span>`);
    }
    if (n.atasament) badges.push('<span class="ci-badge"><i class="bi bi-paperclip"></i></span>');
    const meta = n.categorie === 'noutate'
      ? `${esc(n.autor)} · ${fmt(n.publicat_la)}`
      : `${n.termen ? `<i class="bi bi-clock"></i> ${fmt(n.termen)}` : 'Fără termen'} · ${n.owner_id === this.me.id ? (n.autor_id === this.me.id ? 'Eu' : `de la ${esc(n.autor)}`) : `pentru ${esc(n.owner)}`}`;
    return `<button type="button" class="ci-note-card cat-${n.categorie} imp-${n.importanta} st-${n.stare}" data-note="${n.id}">
      <span class="ci-note-card-title">${esc(n.titlu)}</span>
      <span class="ci-note-card-meta">${meta}</span>
      <span class="ci-note-card-badges">${badges.join('')}</span>
    </button>`;
  }

  closeDetail() { const d = this.root.querySelector('[data-detail]'); d.hidden = true; d.innerHTML = ''; this.root.classList.remove('has-detail'); }

  async openDetail(id) {
    const d = this.root.querySelector('[data-detail]');
    d.hidden = false; this.root.classList.add('has-detail');
    d.innerHTML = '<div class="ci-empty">Se încarcă…</div>';
    this.root.querySelectorAll('[data-note]').forEach((el) => el.classList.toggle('is-selected', Number(el.dataset.note) === id));
    try {
      const { note: n } = await this.api.get('note', { id });
      const rows = [];
      if (n.categorie === 'noutate') {
        rows.push(['Autor', esc(n.autor)], ['Publicat', fmt(n.publicat_la)], ['Importanță', LABEL.importanta[n.importanta]]);
      } else {
        rows.push([n.categorie === 'termen' ? 'Atribuit' : 'Proprietar', esc(n.owner)], ['Autor', esc(n.autor)], ['Termen', n.termen ? fmt(n.termen) : '—'],
          ['Prioritate', LABEL.prioritate[n.prioritate]], ['Stare', n.categorie === 'termen' ? LABEL.stare[n.stare] : LABEL.status[n.status]]);
        if (n.reminder_minute !== null) rows.push(['Memento', (REMINDERS.find(([v]) => v === String(n.reminder_minute)) || [0, `${n.reminder_minute} min înainte`])[1]]);
      }
      if (n.finalizat_la) rows.push(['Finalizat', fmt(n.finalizat_la)]);
      if (n.arhivat_la) rows.push(['Arhivat', fmt(n.arhivat_la)]);
      const actions = [];
      if (n.categorie === 'noutate' && !n.confirmat) actions.push('<button type="button" class="btn btn-sm btn-success" data-op="ack"><i class="bi bi-check2-circle"></i> Am luat la cunoștință</button>');
      if (n.poate.stare && n.status !== 'arhivat' && n.categorie !== 'noutate') {
        if (n.status !== 'finalizat') actions.push('<button type="button" class="btn btn-sm btn-success" data-op="status" data-status="finalizat"><i class="bi bi-check2"></i> Finalizează</button>');
        if (n.status === 'activ') actions.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-op="status" data-status="in_lucru">În lucru</button>');
        if (n.status === 'finalizat') actions.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-op="status" data-status="activ">Redeschide</button>');
      }
      if (n.poate.stare && n.categorie === 'noutate' && n.status !== 'arhivat') {
        actions.push(n.status === 'finalizat'
          ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-op="status" data-status="activ">Reactivează</button>'
          : '<button type="button" class="btn btn-sm btn-outline-secondary" data-op="status" data-status="finalizat">Marchează încheiată</button>');
      }
      if (n.poate.edita && n.status !== 'arhivat') actions.push('<button type="button" class="btn btn-sm btn-outline-primary" data-edit><i class="bi bi-pencil"></i> Editează</button>');
      if (n.poate.arhiva) actions.push(n.status === 'arhivat'
        ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-op="restore"><i class="bi bi-arrow-counterclockwise"></i> Restaurează</button>'
        : '<button type="button" class="btn btn-sm btn-outline-danger" data-op="archive"><i class="bi bi-archive"></i> Arhivează</button>');
      d.innerHTML = `
        <div class="ci-detail-head">
          <h3>${esc(n.titlu)}</h3>
          <button type="button" class="ci-icon-btn" data-back aria-label="Înapoi la listă"><i class="bi bi-arrow-left"></i></button>
        </div>
        <dl class="ci-detail-rows">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
        ${n.descriere ? `<div class="ci-detail-desc">${esc(n.descriere).replace(/\n/g, '<br>')}</div>` : ''}
        ${n.atasament ? `<a class="ci-attach" href="${esc(this.api.url('attachment', { id: n.id }))}"><i class="bi bi-paperclip"></i> ${esc(n.atasament)}</a>` : ''}
        <div class="ci-detail-actions">${actions.join('')}</div>
        ${n.istoric?.length ? `<details class="ci-history"><summary>Istoric (${n.istoric.length})</summary><ul>${n.istoric.map((h) => `<li><span>${fmt(h.created_at)}</span> ${esc(h.nume || 'Sistem')} — ${esc(h.actiune)}</li>`).join('')}</ul></details>` : ''}`;
      d.querySelector('[data-back]').addEventListener('click', () => this.closeDetail());
      d.querySelectorAll('[data-op]').forEach((b) => b.addEventListener('click', () => this.op(n, b.dataset.op, b.dataset.status)));
      d.querySelector('[data-edit]')?.addEventListener('click', () => this.openForm(n));
    } catch (e) {
      d.innerHTML = `<div class="ci-empty ci-error">${esc(e.message)}</div>`;
    }
  }

  async op(n, op, status) {
    try {
      await this.api.post('note_update', { id: n.id, op, status: status || '' });
      this.toast(op === 'ack' ? 'Confirmare salvată.' : op === 'archive' ? 'Nota a fost arhivată (rămâne în istoric).' : 'Salvat.');
      await this.load();
      if (op !== 'ack' || n.categorie === 'noutate') this.onChanged(n.categorie);
      if (op !== 'archive') this.openDetail(n.id);
    } catch (e) { this.toast(e.message, true); }
  }

  openForm(note = null) {
    const cat = note ? note.categorie : this.cat;
    const d = this.root.querySelector('[data-detail]');
    d.hidden = false; this.root.classList.add('has-detail');
    const toLocal = (s) => (s ? String(s).replace(' ', 'T').slice(0, 16) : '');
    const sel = (name, opts, cur) => `<select class="form-select form-select-sm" name="${name}">${opts.map(([v, l]) => `<option value="${v}"${String(cur ?? '') === v ? ' selected' : ''}>${l}</option>`).join('')}</select>`;
    const canAssign = cat !== 'noutate' && this.perms.assign_tasks && this.users.length && !note;
    d.innerHTML = `
      <form class="ci-form" novalidate>
        <div class="ci-detail-head"><h3>${note ? 'Editează' : cat === 'noutate' ? 'Noutate nouă' : cat === 'personal' ? 'Notă nouă' : 'Termen nou'}</h3>
          <button type="button" class="ci-icon-btn" data-back aria-label="Renunță"><i class="bi bi-x-lg"></i></button></div>
        <label>Titlu<input class="form-control form-control-sm" name="titlu" maxlength="200" required value="${esc(note?.titlu || '')}"></label>
        <label>Descriere<textarea class="form-control form-control-sm" name="descriere" rows="4" maxlength="5000">${esc(note?.descriere || '')}</textarea></label>
        ${cat === 'noutate' ? `<label>Importanță${sel('importanta', Object.entries(LABEL.importanta), note?.importanta || 'normala')}</label>
          ${!note ? `<fieldset class="ci-audience"><legend>Public</legend><span class="text-muted small">Nebifat = toți angajații cu acces</span>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="public_roluri[]" value="admin"> Administratori</label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="public_roluri[]" value="contabilitate"> Contabilitate</label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="public_roluri[]" value="utilizator"> Utilizatori</label></fieldset>` : ''}`
        : `<label>Prioritate${sel('prioritate', Object.entries(LABEL.prioritate), note?.prioritate || 'normala')}</label>
          <label>${cat === 'termen' ? 'Termen (data și ora) *' : 'Termen (opțional)'}<input class="form-control form-control-sm" type="datetime-local" name="termen" value="${toLocal(note?.termen)}" ${cat === 'termen' ? 'required' : ''}></label>
          <label>Memento${sel('reminder_minute', REMINDERS, note?.reminder_minute ?? '')}</label>
          ${canAssign ? `<label>${cat === 'termen' ? 'Angajat atribuit' : 'Pentru'}${sel('owner_id', this.users.map((u) => [String(u.id), u.id === this.me.id ? `${u.nume} (eu)` : u.nume]), String(this.me.id))}</label>` : ''}`}
        ${!note ? '<label>Atașament (opțional, max 5 MB)<input class="form-control form-control-sm" type="file" name="atasament" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt"></label>' : ''}
        <div class="ci-form-error" data-error hidden></div>
        <div class="ci-detail-actions"><button class="btn btn-sm btn-primary" type="submit">${note ? 'Salvează' : cat === 'noutate' ? 'Publică' : 'Adaugă'}</button>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-back>Renunță</button></div>
      </form>`;
    d.querySelectorAll('[data-back]').forEach((b) => b.addEventListener('click', () => (note ? this.openDetail(note.id) : this.closeDetail())));
    const form = d.querySelector('form');
    form.querySelector('[name="titlu"]').focus();
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      e.stopPropagation(); // garda globală anti-dublu-submit din app.js
      const err = form.querySelector('[data-error]');
      const fd = new FormData(form);
      if (!String(fd.get('titlu') || '').trim()) { err.textContent = 'Titlul este obligatoriu.'; err.hidden = false; return; }
      if (cat === 'termen' && !fd.get('termen')) { err.textContent = 'Termenul (data și ora) este obligatoriu.'; err.hidden = false; return; }
      const btn = form.querySelector('[type="submit"]'); btn.disabled = true;
      try {
        let saved;
        if (note) {
          fd.set('id', String(note.id)); fd.set('op', 'edit');
          saved = (await this.api.post('note_update', fd)).note;
        } else {
          fd.set('categorie', cat);
          saved = (await this.api.post('note_store', fd)).note;
        }
        this.toast(note ? 'Modificările au fost salvate.' : cat === 'noutate' ? 'Noutatea a fost publicată.' : 'Nota a fost adăugată.');
        this.cat = cat; this.state = '';
        await this.load();
        this.onChanged(cat);
        this.openDetail(saved.id);
      } catch (ex) { err.textContent = ex.message; err.hidden = false; btn.disabled = false; }
    });
  }

  toast(text, error = false) {
    const t = document.createElement('div');
    t.className = 'ci-toast' + (error ? ' is-error' : '');
    t.textContent = text;
    this.root.appendChild(t);
    setTimeout(() => t.classList.add('is-out'), 2600);
    setTimeout(() => t.remove(), 3100);
  }
}
