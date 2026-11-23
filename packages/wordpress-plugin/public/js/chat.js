import { RtcMesh } from './webrtc.js';

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function participantKey(slug) {
  return `desiderata_participant_${slug}`;
}

function getOrCreateParticipantId(slug) {
  const key = participantKey(slug);
  let id = localStorage.getItem(key);
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem(key, id);
  }
  return id;
}

export class ChatPanel {
  /**
   * @param {{
   *   root: HTMLElement,
   *   apiBase: string,
   *   slug: string,
   * }} opts
   */
  constructor(opts) {
    this.opts = opts;
    this.roomToken = null;
    this.participantId = getOrCreateParticipantId(opts.slug);
    this.displayName = localStorage.getItem(`desiderata_chat_name_${opts.slug}`) || '';
    this.lastMessageId = 0;
    this.pollTimer = null;
    this.rtc = null;
    this.knownPeers = new Set();
    this.seenFingerprints = new Set();
    this.renderIdle();
  }

  renderIdle() {
    this.opts.root.innerHTML = `
      <section class="chat-panel" aria-label="Chat">
        <h2>Chat</h2>
        <p class="meta">Optionnel — messages éphémères, purgés à la clôture.</p>
        <button type="button" data-action="join">Rejoindre le chat</button>
      </section>
    `;
    this.opts.root.querySelector('[data-action="join"]')?.addEventListener('click', () => this.promptJoin());
  }

  promptJoin() {
    const name = window.prompt('Votre prénom / pseudo pour le chat', this.displayName || '');
    if (name === null) return;
    this.displayName = (name.trim() || 'Anonyme').slice(0, 120);
    localStorage.setItem(`desiderata_chat_name_${this.opts.slug}`, this.displayName);
    this.join();
  }

  async join() {
    const url = `${this.opts.apiBase}/wishlists/${encodeURIComponent(this.opts.slug)}/chat/join`;
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        participant_id: this.participantId,
        display_name: this.displayName,
      }),
    });
    if (!res.ok) {
      this.opts.root.innerHTML = `<section class="chat-panel"><p class="error">Impossible de rejoindre le chat.</p></section>`;
      return;
    }
    const data = await res.json();
    this.roomToken = data.room_token;
    this.participantId = data.participant_id;
    localStorage.setItem(participantKey(this.opts.slug), this.participantId);
    this.renderActive();
    this.appendMessages(data.messages || []);
    this.startPolling();
    this.startRtc(data.peer_count || 1);
  }

  renderActive() {
    this.opts.root.innerHTML = `
      <section class="chat-panel" aria-label="Chat">
        <h2>Chat</h2>
        <div class="chat-toolbar">
          <span class="rtc-badge" data-role="rtc-status"></span>
          <button type="button" class="secondary" data-action="close">Clore la discussion</button>
        </div>
        <div class="chat-log" data-role="log" aria-live="polite"></div>
        <form class="chat-form" data-role="form">
          <input type="text" maxlength="500" placeholder="Écrire un message…" required data-role="input" />
          <button type="submit">Envoyer</button>
        </form>
      </section>
    `;
    this.logEl = this.opts.root.querySelector('[data-role="log"]');
    this.statusEl = this.opts.root.querySelector('[data-role="rtc-status"]');
    this.opts.root.querySelector('[data-action="close"]')?.addEventListener('click', () => this.closeRoom());
    this.opts.root.querySelector('[data-role="form"]')?.addEventListener('submit', (ev) => {
      ev.preventDefault();
      this.send();
    });
  }

  appendMessages(messages) {
    if (!this.logEl) return;
    for (const msg of messages) {
      if (msg.id && msg.id <= this.lastMessageId) continue;
      if (msg.id) {
        this.lastMessageId = Math.max(this.lastMessageId, msg.id);
      }
      const fp = `${msg.participant_id}|${msg.body}`;
      if (this.seenFingerprints.has(fp)) {
        this.knownPeers.add(msg.participant_id);
        continue;
      }
      this.seenFingerprints.add(fp);
      this.appendLine(msg.display_name, msg.body, msg.participant_id === this.participantId);
      this.knownPeers.add(msg.participant_id);
    }
  }

  appendLine(who, body, mine = false) {
    if (!this.logEl) return;
    const p = document.createElement('p');
    p.className = 'chat-line';
    p.innerHTML = `<span class="who">${escapeHtml(who)}${mine ? ' (vous)' : ''}</span> — ${escapeHtml(body)}`;
    this.logEl.appendChild(p);
    this.logEl.scrollTop = this.logEl.scrollHeight;
  }

  startPolling() {
    this.stopPolling();
    this.pollTimer = setInterval(() => this.poll(), 2500);
    this.poll();
  }

  stopPolling() {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  async poll() {
    const qs = new URLSearchParams({
      since: String(this.lastMessageId),
      participant_id: this.participantId,
    });
    const url = `${this.opts.apiBase}/wishlists/${encodeURIComponent(this.opts.slug)}/chat/messages?${qs}`;
    try {
      const res = await fetch(url);
      if (!res.ok) return;
      const data = await res.json();
      if (data.status === 'closed') {
        this.teardown('Discussion close.');
        return;
      }
      this.appendMessages(data.messages || []);
      if (this.rtc && (data.peer_count || 0) >= 2) {
        // Soft invite: peers discovered via recent messages / count.
        this.rtc.invitePeers([...this.knownPeers]);
      }
    } catch (_) { /* ignore */ }
  }

  async send() {
    const input = this.opts.root.querySelector('[data-role="input"]');
    if (!(input instanceof HTMLInputElement)) return;
    const body = input.value.trim();
    if (!body || !this.roomToken) return;
    input.value = '';

    const url = `${this.opts.apiBase}/wishlists/${encodeURIComponent(this.opts.slug)}/chat/messages`;
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        room_token: this.roomToken,
        participant_id: this.participantId,
        body,
      }),
    });
    if (!res.ok) return;
    const msg = await res.json();
    this.appendMessages([msg]);
    this.rtc?.broadcast({
      body: msg.body,
      display_name: this.displayName,
      participant_id: this.participantId,
    });
  }

  startRtc(peerCount) {
    this.rtc?.stop();
    this.rtc = new RtcMesh({
      apiBase: this.opts.apiBase,
      slug: this.opts.slug,
      roomToken: this.roomToken,
      participantId: this.participantId,
      onMessage: (msg) => {
        if (msg.participant_id === this.participantId) return;
        this.appendMessages([{
          id: 0,
          participant_id: msg.participant_id,
          display_name: msg.display_name,
          body: msg.body,
        }]);
      },
      onStatus: (text) => {
        if (this.statusEl) this.statusEl.textContent = text;
      },
    });
    if (peerCount >= 2) {
      this.rtc.start();
    } else {
      this.rtc.start();
      if (this.statusEl) this.statusEl.textContent = 'WebRTC : en attente d’un pair…';
    }
  }

  async closeRoom() {
    if (!this.roomToken) return;
    const url = `${this.opts.apiBase}/wishlists/${encodeURIComponent(this.opts.slug)}/chat/close`;
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        room_token: this.roomToken,
        participant_id: this.participantId,
      }),
    });
    if (!res.ok) return;
    const data = await res.json();
    if (data.closed) {
      this.teardown('Discussion close et messages purgés.');
    } else {
      if (this.statusEl) this.statusEl.textContent = 'En attente que les autres closent…';
    }
  }

  teardown(message) {
    this.stopPolling();
    this.rtc?.stop();
    this.rtc = null;
    this.roomToken = null;
    this.opts.root.innerHTML = `
      <section class="chat-panel">
        <h2>Chat</h2>
        <p class="meta">${escapeHtml(message)}</p>
        <button type="button" data-action="join">Rejoindre le chat</button>
      </section>
    `;
    this.opts.root.querySelector('[data-action="join"]')?.addEventListener('click', () => this.promptJoin());
  }
}
