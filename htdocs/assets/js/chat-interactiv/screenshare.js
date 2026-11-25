// Partajarea ecranului (WebRTC). Prezentatorul trimite fluxul direct fiecărui privitor (o conexiune
// peer per privitor — potrivit pentru o cameră mică); serverul WS doar transmite semnalizarea
// (oferte/răspunsuri SDP + candidați ICE). Video-ul nu trece prin VPS.
//
// Evenimente: 'state' {share, mine, stream} — fluxul de afișat pe ecranul din cameră (sau null).
export class ScreenShare extends EventTarget {
  constructor({ net, me, iceServers }) {
    super();
    this.net = net;
    this.me = me;
    this.ice = iceServers || [];
    this.share = null;          // {id, name} — cine prezintă acum (după server)
    this.local = null;          // MediaStream propriu (când prezint)
    this.remote = null;         // MediaStream primit (când privesc)
    this.peers = new Map();     // prezentator: id privitor -> RTCPeerConnection
    this.viewerPc = null;       // privitor: conexiunea cu prezentatorul
    this.pendingIce = new Map();
    net.addEventListener('share', (e) => this.onShare(e.detail.share));
    net.addEventListener('welcome', (e) => this.onWelcome(e.detail.share));
    net.addEventListener('rtc', (e) => this.onRtc(e.detail.from, e.detail.data));
    net.addEventListener('leave', (e) => this.closePeer(e.detail.id));
    net.addEventListener('share_denied', (e) => this.fail(e.detail.reason));
    net.addEventListener('status', (e) => { if (e.detail.state !== 'online') this.resetConnections(); });
  }

  static supported() {
    return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && window.RTCPeerConnection);
  }

  get isPresenting() { return !!this.local; }

  emit() {
    const mine = !!(this.share && this.share.id === this.me.id);
    this.dispatchEvent(new CustomEvent('state', { detail: { share: this.share, mine, stream: mine ? this.local : this.remote } }));
  }

  fail(message) {
    this.dispatchEvent(new CustomEvent('error', { detail: { message } }));
    if (this.local && !(this.share && this.share.id === this.me.id)) this.stopLocal();
  }

  /** Alegerea ecranului / ferestrei / filei (dialogul browserului) și pornirea prezentării. */
  async start() {
    if (!ScreenShare.supported()) throw new Error('Partajarea ecranului cere HTTPS (sau localhost) și un browser recent.');
    if (this.share && this.share.id !== this.me.id) throw new Error(`${this.share.name} prezintă deja.`);
    const stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 15, max: 30 }, width: { max: 1920 }, height: { max: 1080 } }, audio: false });
    this.local = stream;
    const track = stream.getVideoTracks()[0];
    track.contentHint = 'detail'; // text lizibil (slide-uri, documente)
    track.addEventListener('ended', () => this.stop()); // „Oprește partajarea” din bara browserului
    if (!this.net.send({ t: 'share_start' })) { this.stopLocal(); throw new Error('Nu ești conectat la sediul virtual.'); }
  }

  stop() {
    this.net.send({ t: 'share_stop' });
    this.stopLocal();
  }

  stopLocal() {
    if (this.local) this.local.getTracks().forEach((t) => t.stop());
    this.local = null;
    for (const id of [...this.peers.keys()]) this.closePeer(id);
    this.emit();
  }

  onWelcome(share) {
    // Reconectare în timpul prezentării proprii: serverul a uitat-o — o reanunțăm.
    if (this.local && !share) { this.net.send({ t: 'share_start' }); return; }
    this.onShare(share);
  }

  onShare(share) {
    const prev = this.share;
    this.share = share ? { id: share.id, name: share.name } : null;
    if (!share) {
      this.closeViewer();
      if (this.local) this.stopLocal();
    } else if (share.id === this.me.id) {
      this.closeViewer();
    } else {
      if (this.local) this.stopLocal(); // altcineva a preluat (nu ar trebui să se întâmple)
      if (!prev || prev.id !== share.id || !this.viewerPc) {
        this.closeViewer();
        this.net.send({ t: 'rtc', to: share.id, data: { type: 'want' } }); // cer fluxul
      }
    }
    this.emit();
  }

  newPc(peerId) {
    const pc = new RTCPeerConnection({ iceServers: this.ice });
    pc.addEventListener('icecandidate', (e) => { if (e.candidate) this.net.send({ t: 'rtc', to: peerId, data: { type: 'ice', candidate: e.candidate.toJSON() } }); });
    return pc;
  }

  async onRtc(from, data) {
    try {
      if (this.local && this.share && this.share.id === this.me.id) {
        // Sunt prezentator.
        if (data.type === 'want') {
          this.closePeer(from);
          const pc = this.newPc(from);
          this.peers.set(from, pc);
          pc.addEventListener('connectionstatechange', () => { if (['failed', 'closed'].includes(pc.connectionState)) this.closePeer(from); });
          for (const t of this.local.getTracks()) pc.addTrack(t, this.local);
          await pc.setLocalDescription(await pc.createOffer());
          this.net.send({ t: 'rtc', to: from, data: { type: 'offer', sdp: pc.localDescription.sdp } });
        } else if (data.type === 'answer') {
          const pc = this.peers.get(from);
          if (pc) { await pc.setRemoteDescription({ type: 'answer', sdp: data.sdp }); await this.flushIce(from, pc); }
        } else if (data.type === 'ice') {
          await this.addIce(from, this.peers.get(from), data.candidate);
        }
        return;
      }
      if (!this.share || from !== this.share.id) return;
      // Sunt privitor.
      if (data.type === 'offer') {
        this.closeViewer();
        const pc = this.newPc(from);
        this.viewerPc = pc;
        pc.addEventListener('track', (e) => { this.remote = e.streams[0] || new MediaStream([e.track]); this.emit(); });
        pc.addEventListener('connectionstatechange', () => {
          if (pc.connectionState === 'failed' && this.viewerPc === pc) {
            this.fail('Conexiunea video cu prezentatorul a eșuat (rețea / NAT). Se reîncearcă…');
            this.closeViewer();
            setTimeout(() => { if (this.share && this.share.id === from) this.net.send({ t: 'rtc', to: from, data: { type: 'want' } }); }, 3000);
          }
        });
        await pc.setRemoteDescription({ type: 'offer', sdp: data.sdp });
        await pc.setLocalDescription(await pc.createAnswer());
        this.net.send({ t: 'rtc', to: from, data: { type: 'answer', sdp: pc.localDescription.sdp } });
        await this.flushIce(from, pc);
      } else if (data.type === 'ice') {
        await this.addIce(from, this.viewerPc, data.candidate);
      }
    } catch (e) {
      console.warn('[LPG Connect] WebRTC', e);
    }
  }

  async addIce(from, pc, candidate) {
    if (pc && pc.remoteDescription) { await pc.addIceCandidate(candidate).catch(() => {}); return; }
    if (!this.pendingIce.has(from)) this.pendingIce.set(from, []);
    this.pendingIce.get(from).push(candidate);
  }

  async flushIce(from, pc) {
    const list = this.pendingIce.get(from) || [];
    this.pendingIce.delete(from);
    for (const c of list) await pc.addIceCandidate(c).catch(() => {});
  }

  closePeer(id) {
    const pc = this.peers.get(id);
    if (pc) { pc.close(); this.peers.delete(id); }
    this.pendingIce.delete(id);
  }

  closeViewer() {
    if (this.viewerPc) { this.viewerPc.close(); this.viewerPc = null; }
    if (this.remote) { this.remote = null; }
    if (this.share) this.pendingIce.delete(this.share.id);
  }

  resetConnections() {
    for (const id of [...this.peers.keys()]) this.closePeer(id);
    this.closeViewer();
    this.share = this.local ? this.share : null;
    this.emit();
  }
}
