import { browser } from '../shared/browser-polyfill.js';
import { getSettings } from '../shared/api.js';

const syncStatus = document.getElementById('sync-status');
const claimsEl = document.getElementById('claims');
const messageEl = document.getElementById('message');
const btnSync = document.getElementById('btn-sync');
const btnCopy = document.getElementById('btn-copy');
const btnOpen = document.getElementById('btn-open');
const btnOptions = document.getElementById('btn-options');

function showMessage(text, isError = false) {
  messageEl.hidden = false;
  messageEl.textContent = text;
  messageEl.style.color = isError ? '#b55a3c' : '#1a3a2f';
}

async function refresh() {
  const settings = await getSettings();
  if (!settings.apiBase || !settings.token) {
    syncStatus.textContent = 'Configurez l’instance WordPress dans Options.';
  } else if (!settings.folderId || !settings.wishlistId) {
    syncStatus.textContent = 'Choisissez un dossier et une liste dans Options.';
  } else {
    const when = settings.lastSyncAt ? new Date(settings.lastSyncAt).toLocaleString() : 'jamais';
    syncStatus.textContent = `Dernière sync : ${when} (${settings.lastSyncStatus || '—'})`;
  }

  const url = settings.publicUrl || '';
  btnCopy.disabled = !url;
  btnOpen.disabled = !url;
  btnCopy.dataset.url = url;
  btnOpen.dataset.url = url;

  const claims = Array.isArray(settings.lastClaims) ? settings.lastClaims : [];
  if (claims.length) {
    claimsEl.hidden = false;
    claimsEl.textContent = claims
      .slice(0, 5)
      .map((c) => `« ${c.title} » → ${c.claimed_by}`)
      .join(' · ');
  } else {
    claimsEl.hidden = true;
  }
}

btnSync.addEventListener('click', async () => {
  btnSync.disabled = true;
  try {
    const res = await browser.runtime.sendMessage({ type: 'sync-now' });
    if (!res?.ok) {
      showMessage(res?.error || 'Sync échouée', true);
    } else if (res.result?.skipped) {
      showMessage(`Sync ignorée (${res.result.reason})`, true);
    } else {
      showMessage(`Sync OK — ${res.result?.item_count ?? 0} item(s)`);
    }
  } catch (err) {
    showMessage(err.message || String(err), true);
  } finally {
    btnSync.disabled = false;
    await refresh();
  }
});

btnCopy.addEventListener('click', async () => {
  const url = btnCopy.dataset.url;
  if (!url) return;
  try {
    await navigator.clipboard.writeText(url);
    showMessage('Lien copié');
  } catch {
    showMessage(url);
  }
});

btnOpen.addEventListener('click', async () => {
  const url = btnOpen.dataset.url;
  if (!url) return;
  await browser.tabs.create({ url });
});

btnOptions.addEventListener('click', async () => {
  const url = browser.runtime.getURL('options/options.html');
  try {
    if (browser.runtime.openOptionsPage) {
      await browser.runtime.openOptionsPage();
      return;
    }
  } catch (_) {
    /* fallback below */
  }
  await browser.tabs.create({ url });
});

refresh();
