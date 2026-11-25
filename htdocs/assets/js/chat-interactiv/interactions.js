// Registrul obiectelor interactive. Fiecare obiect are: id stabil, tip, rază de interacțiune,
// permisiune cerută, handler și (opțional) o componentă UI. Acum este înregistrat doar panoul
// informativ; laptopul (consola șoferilor), masa de ședințe, ecranul de prezentare sau raftul din
// depozit se vor adăuga tot aici, cu handler-ul lor, fără schimbări în motor.
import * as THREE from 'three';

export class InteractionRegistry {
  constructor({ promptEl, perms }) {
    this.items = new Map();
    this.promptEl = promptEl;
    this.perms = perms;         // {view: true, publish_news: bool, ...}
    this.current = null;
    this.active = null;         // interacțiunea deschisă acum
  }

  /**
   * @param {{id:string,type:string,radius:number,permission?:string,position:THREE.Vector3,normal?:THREE.Vector3,
   *          prompt:string,handler:{open:Function,close:Function},ui?:string}} item
   */
  register(item) { this.items.set(item.id, item); }

  allowed(item) {
    if (item.available && !item.available()) return false; // ex. loc ocupat / deja așezat
    return !item.permission || item.permission === 'view' || !!this.perms[item.permission];
  }

  /** Cel mai apropiat obiect în rază, în fața personajului (sau cel puțin nu în spate). */
  update(px, pz, ry) {
    if (this.active) { this.show(null); return; }
    let best = null, bestD = Infinity;
    for (const it of this.items.values()) {
      if (!this.allowed(it)) continue;
      const dx = it.position.x - px, dz = it.position.z - pz;
      const d = Math.hypot(dx, dz);
      if (d > it.radius) continue;
      if (it.normal) {
        // Doar din fața obiectului (nu prin perete).
        const side = (px - it.position.x) * it.normal.x + (pz - it.position.z) * it.normal.z;
        if (side < 0.05) continue;
      }
      if (d < bestD) { best = it; bestD = d; }
    }
    this.show(best);
  }

  show(item) {
    if (this.current === item) return;
    this.current = item;
    if (item) {
      this.promptEl.querySelector('[data-prompt-text]').textContent = item.prompt;
      this.promptEl.hidden = false;
    } else {
      this.promptEl.hidden = true;
    }
  }

  async trigger() {
    if (this.active || !this.current) return false;
    // Acțiune instantanee (ex. așezarea): fără panou deschis, registrul rămâne liber.
    if (this.current.instant) { const it = this.current; this.show(null); await it.handler.open(it); return true; }
    this.active = this.current;
    this.show(null);
    try { await this.active.handler.open(this.active); } catch (e) { console.error(e); this.active = null; }
    return true;
  }

  async close() {
    if (!this.active) return false;
    const it = this.active;
    try { await it.handler.close(it); } finally { this.active = null; }
    return true;
  }
}
