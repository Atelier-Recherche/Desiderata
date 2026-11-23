import { ChatPanel } from './chat.js';

const cfg = window.DESIDERATA || {};
const app = document.getElementById('app');

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function claimKey(itemId) {
  return `desiderata_claim_${itemId}`;
}

function metaLine(meta) {
  if (!meta) return '';
  const bits = [];
  if (meta.authors?.length) bits.push(escapeHtml(meta.authors.join(', ')));
  if (meta.isbn) bits.push(`ISBN ${escapeHtml(meta.isbn)}`);
  if (meta.publisher) bits.push(escapeHtml(meta.publisher));
  if (meta.published_date) bits.push(escapeHtml(meta.published_date));
  if (meta.zotero_key) bits.push(`Zotero ${escapeHtml(meta.zotero_key)}`);
  if (!bits.length) return '';
  return `<p class="item-meta">${bits.join(' · ')}</p>`;
}

async function fetchWishlist() {
  const url = `${cfg.apiBase}/wishlists/${encodeURIComponent(cfg.slug)}`;
  const res = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    throw new Error(res.status === 404 ? 'Liste introuvable.' : 'Erreur de chargement.');
  }
  return res.json();
}

function render(data) {
  document.title = `${data.title} — Desiderata`;
  const exportUrl = `${cfg.apiBase}/wishlists/${encodeURIComponent(cfg.slug)}/export/zotero`;

  const itemsHtml = (data.items || []).map((item) => {
    const secret = localStorage.getItem(claimKey(item.id));
    const mine = Boolean(secret) && !item.available && item.claimed_by;
    const status = item.available
      ? '<p class="item-status">Disponible</p>'
      : `<p class="item-status taken">Pris par ${escapeHtml(item.claimed_by || 'quelqu’un')}</p>`;

    let actions = '';
    if (item.available) {
      actions = `<button type="button" data-claim="${item.id}">Moi je l’ai</button>`;
    } else if (mine) {
      actions = `<button type="button" class="secondary" data-release="${item.id}">Libérer</button>`;
    }

    const cover = item.meta?.cover_url
      ? `<img class="item-cover" src="${escapeHtml(item.meta.cover_url)}" alt="" loading="lazy" width="56" height="84" />`
      : '';

    return `
      <li class="item" data-item="${item.id}">
        <div class="item-main">
          ${cover}
          <div>
            <p class="item-title"><a href="${escapeHtml(item.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.title)}</a></p>
            ${metaLine(item.meta)}
            ${status}
            <div class="claim-slot" data-slot="${item.id}"></div>
          </div>
        </div>
        <div class="item-actions">${actions}</div>
      </li>
    `;
  }).join('');

  app.innerHTML = `
    <h1 class="wishlist-title">${escapeHtml(data.title)}</h1>
    <p class="meta">
      ${(data.items || []).length} document(s) · mise à jour ${escapeHtml(data.updated_at || '')}
      · <a href="${escapeHtml(exportUrl)}" download>Export Zotero (CSL-JSON)</a>
    </p>
    <ul class="item-list">${itemsHtml || '<li class="meta">Aucun item pour le moment.</li>'}</ul>
    <div id="chat-root"></div>
  `;

  app.querySelectorAll('[data-claim]').forEach((btn) => {
    btn.addEventListener('click', () => beginClaim(Number(btn.getAttribute('data-claim'))));
  });
  app.querySelectorAll('[data-release]').forEach((btn) => {
    btn.addEventListener('click', () => releaseItem(Number(btn.getAttribute('data-release'))));
  });

  const chatRoot = document.getElementById('chat-root');
  if (chatRoot) {
    new ChatPanel({ root: chatRoot, apiBase: cfg.apiBase, slug: cfg.slug });
  }
}

function beginClaim(itemId) {
  const slot = app.querySelector(`[data-slot="${itemId}"]`);
  if (!slot) return;
  slot.innerHTML = `
    <form class="claim-form" data-claim-form="${itemId}">
      <input type="text" maxlength="120" placeholder="Prénom / pseudo (optionnel)" data-name />
      <button type="submit">Confirmer</button>
      <button type="button" class="secondary" data-cancel>Annuler</button>
    </form>
  `;
  slot.querySelector('[data-cancel]')?.addEventListener('click', () => { slot.innerHTML = ''; });
  slot.querySelector('form')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const nameInput = slot.querySelector('[data-name]');
    const name = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';
    await claimItem(itemId, name);
  });
}

async function claimItem(itemId, name) {
  const url = `${cfg.apiBase}/wishlists/${encodeURIComponent(cfg.slug)}/items/${itemId}/claim`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  });
  if (res.status === 409) {
    alert('Déjà réservé.');
    await boot();
    return;
  }
  if (!res.ok) {
    alert('Réservation impossible.');
    return;
  }
  const data = await res.json();
  if (data.claim_secret) {
    localStorage.setItem(claimKey(itemId), data.claim_secret);
  }
  await boot();
}

async function releaseItem(itemId) {
  const secret = localStorage.getItem(claimKey(itemId)) || '';
  const url = `${cfg.apiBase}/wishlists/${encodeURIComponent(cfg.slug)}/items/${itemId}/claim`;
  const res = await fetch(url, {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ claim_secret: secret }),
  });
  if (!res.ok) {
    alert('Libération impossible (secret manquant ou invalide).');
    return;
  }
  localStorage.removeItem(claimKey(itemId));
  await boot();
}

async function boot() {
  if (!cfg.slug) {
    app.innerHTML = '<p class="error">Slug manquant.</p>';
    return;
  }
  try {
    const data = await fetchWishlist();
    render(data);
  } catch (err) {
    app.innerHTML = `<p class="error">${escapeHtml(err.message || 'Erreur')}</p>`;
  }
}

if ('serviceWorker' in navigator) {
  const swUrl = new URL('../sw.js', import.meta.url);
  navigator.serviceWorker.register(swUrl.href).catch(() => { /* ignore */ });
}

boot();
