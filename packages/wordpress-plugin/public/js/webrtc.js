const STUN = [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }];

export class RtcMesh {
  /**
   * @param {{
   *   apiBase: string,
   *   slug: string,
   *   roomToken: string,
   *   participantId: string,
   *   onMessage: (msg: {body: string, display_name: string, participant_id: string}) => void,
   *   onStatus: (text: string) => void,
   * }} opts
   */
  constructor(opts) {
    this.opts = opts;
    this.peers = new Map();
    this.channels = new Map();
    this.lastSignalId = 0;
    this.pollTimer = null;
    this.alive = false;
  }

  start() {
    if (this.alive) return;
    this.alive = true;
    this.opts.onStatus('WebRTC : découverte…');
    this.pollTimer = setInterval(() => this.pollSignals(), 2000);
    this.pollSignals();
  }

  stop() {
    this.alive = false;
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
    for (const pc of this.peers.values()) {
      try { pc.close(); } catch (_) { /* ignore */ }
    }
    this.peers.clear();
    this.channels.clear();
    this.opts.onStatus('');
  }

  /** @param {string} peerId */
  async ensurePeer(peerId, initiator) {
    if (!this.alive || peerId === this.opts.participantId || this.peers.has(peerId)) {
      return;
    }
    const pc = new RTCPeerConnection({ iceServers: STUN });
    this.peers.set(peerId, pc);

    pc.onicecandidate = (ev) => {
      if (!ev.candidate) return;
      this.postSignal(peerId, 'ice', ev.candidate.toJSON());
    };

    pc.onconnectionstatechange = () => {
      if (pc.connectionState === 'connected') {
        this.opts.onStatus('WebRTC : connecté');
      } else if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
        this.opts.onStatus('WebRTC : polling seul');
      }
    };

    if (initiator) {
      const channel = pc.createDataChannel('desiderata-chat');
      this.wireChannel(peerId, channel);
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      await this.postSignal(peerId, 'offer', pc.localDescription);
    } else {
      pc.ondatachannel = (ev) => this.wireChannel(peerId, ev.channel);
    }
  }

  /** @param {string} peerId @param {RTCDataChannel} channel */
  wireChannel(peerId, channel) {
    this.channels.set(peerId, channel);
    channel.onopen = () => this.opts.onStatus('WebRTC : canal ouvert');
    channel.onmessage = (ev) => {
      try {
        const data = JSON.parse(String(ev.data));
        if (data && typeof data.body === 'string') {
          this.opts.onMessage({
            body: data.body,
            display_name: data.display_name || 'Anonyme',
            participant_id: data.participant_id || peerId,
          });
        }
      } catch (_) { /* ignore */ }
    };
  }

  /** Broadcast locally over open data channels (server still authoritative). */
  broadcast(payload) {
    const raw = JSON.stringify(payload);
    for (const channel of this.channels.values()) {
      if (channel.readyState === 'open') {
        try { channel.send(raw); } catch (_) { /* ignore */ }
      }
    }
  }

  async postSignal(toId, type, payload) {
    const url = `${this.opts.apiBase}/wishlists/${encodeURIComponent(this.opts.slug)}/rtc/signals`;
    await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        room_token: this.opts.roomToken,
        from_id: this.opts.participantId,
        to_id: toId,
        type,
        payload,
      }),
    });
  }

  async pollSignals() {
    if (!this.alive) return;
    const qs = new URLSearchParams({
      room_token: this.opts.roomToken,
      participant_id: this.opts.participantId,
      since: String(this.lastSignalId),
    });
    const url = `${this.opts.apiBase}/wishlists/${encodeURIComponent(this.opts.slug)}/rtc/signals?${qs}`;
    try {
      const res = await fetch(url);
      if (!res.ok) return;
      const data = await res.json();
      for (const signal of data.signals || []) {
        this.lastSignalId = Math.max(this.lastSignalId, signal.id);
        await this.handleSignal(signal);
      }
    } catch (_) { /* ignore */ }
  }

  async handleSignal(signal) {
    const peerId = signal.from_id;
    if (signal.type === 'offer') {
      await this.ensurePeer(peerId, false);
      const pc = this.peers.get(peerId);
      if (!pc) return;
      await pc.setRemoteDescription(signal.payload);
      const answer = await pc.createAnswer();
      await pc.setLocalDescription(answer);
      await this.postSignal(peerId, 'answer', pc.localDescription);
    } else if (signal.type === 'answer') {
      const pc = this.peers.get(peerId);
      if (!pc) return;
      await pc.setRemoteDescription(signal.payload);
    } else if (signal.type === 'ice') {
      const pc = this.peers.get(peerId);
      if (!pc) {
        await this.ensurePeer(peerId, this.opts.participantId > peerId);
        return;
      }
      try {
        await pc.addIceCandidate(signal.payload);
      } catch (_) { /* ignore */ }
    }
  }

  /** Invite known peers (lexicographic initiator to avoid glare). */
  async invitePeers(peerIds) {
    for (const peerId of peerIds) {
      if (peerId === this.opts.participantId) continue;
      const initiator = this.opts.participantId < peerId;
      await this.ensurePeer(peerId, initiator);
    }
  }
}
