import { browser } from '../shared/browser-polyfill.js';
import { apiRequest, getSettings, normalizeApiBase, saveSettings } from '../shared/api.js';

const form = document.getElementById('form');
const siteUrl = document.getElementById('siteUrl');
const tokenInput = document.getElementById('token');
const wishlistSelect = document.getElementById('wishlistId');
const folderSelect = document.getElementById('folderId');
const messageEl = document.getElementById('message');
const accountPanel = document.getElementById('account-panel');
const registrationHint = document.getElementById('registration-hint');
const btnRegister = document.getElementById('btn-register');
const btnLogin = document.getElementById('btn-login');
const accountEmail = document.getElementById('accountEmail');
const accountPassword = document.getElementById('accountPassword');
const accountDisplay = document.getElementById('accountDisplay');
const turnstileWrap = document.getElementById('turnstile-wrap');
const turnstileFrame = document.getElementById('turnstile-frame');
const turnstileTokenInput = document.getElementById('turnstileToken');
const turnstileStatus = document.getElementById('turnstile-status');

/** @type {{ required: boolean, url: string, siteOrigin: string }} */
let turnstileState = { required: false, url: '', siteOrigin: '' };

function showMessage(text, isError = false) {
  messageEl.hidden = false;
  messageEl.textContent = text;
  messageEl.classList.toggle('is-error', isError);
  messageEl.classList.toggle('is-ok', !isError);
}

function hostOriginPattern(apiBase) {
  return `${new URL(apiBase).origin}/*`;
}

/** Check only — never prompts (safe outside click handlers). */
async function hasHostPermission(apiBase) {
  if (!browser.permissions?.contains) return true;
  try {
    return browser.permissions.contains({ origins: [hostOriginPattern(apiBase)] });
  } catch {
    return false;
  }
}

/**
 * MUST be called synchronously inside a click/submit handler (Firefox).
 * Returns a Promise; do not await anything before calling this.
 */
function requestHostPermissionFromUserGesture(apiBase) {
  if (!browser.permissions?.request) {
    return Promise.resolve(true);
  }
  try {
    return Promise.resolve(
      browser.permissions.request({ origins: [hostOriginPattern(apiBase)] })
    );
  } catch (err) {
    return Promise.reject(err);
  }
}

function fillWishlists(lists, selectedId) {
  wishlistSelect.innerHTML = '';
  if (!lists.length) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'Aucune liste — créez-en une';
    wishlistSelect.appendChild(opt);
    return;
  }
  for (const list of lists) {
    const opt = document.createElement('option');
    opt.value = String(list.id);
    opt.textContent = `${list.title} (${list.slug})`;
    opt.dataset.slug = list.slug;
    opt.dataset.title = list.title;
    opt.dataset.url = list.public_url || '';
    if (String(list.id) === String(selectedId)) {
      opt.selected = true;
    }
    wishlistSelect.appendChild(opt);
  }
}

async function loadFolders(selectedId) {
  const res = await browser.runtime.sendMessage({ type: 'list-folders' });
  folderSelect.innerHTML = '';
  if (!res?.ok) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'Impossible de lire les favoris';
    folderSelect.appendChild(opt);
    return;
  }
  for (const folder of res.folders) {
    const opt = document.createElement('option');
    opt.value = folder.id;
    opt.textContent = folder.path;
    if (String(folder.id) === String(selectedId)) {
      opt.selected = true;
    }
    folderSelect.appendChild(opt);
  }
}

async function fetchLists(apiBase, token) {
  const data = await apiRequest(apiBase, token, '/me/wishlists');
  fillWishlists(data.wishlists || [], wishlistSelect.value);
  return data;
}

async function runSync() {
  const res = await browser.runtime.sendMessage({ type: 'sync-now' });
  if (!res?.ok) {
    throw new Error(res?.error || 'Sync échouée');
  }
  if (res.result?.skipped) {
    throw new Error(`Sync ignorée (${res.result.reason})`);
  }
  return res.result;
}

async function persistAndSyncAfterGrant(apiBase, { silentSaveMessage = false } = {}) {
  const token = tokenInput.value.trim();
  const wishlistId = wishlistSelect.value;
  const folderId = folderSelect.value;
  if (!token || !wishlistId || !folderId) {
    showMessage('Tous les champs sont requis (URL, jeton, liste, dossier)', true);
    return false;
  }

  const selected = wishlistSelect.selectedOptions[0];
  await saveSettings({
    apiBase,
    token,
    wishlistId,
    folderId,
    wishlistSlug: selected?.dataset.slug || '',
    wishlistTitle: selected?.dataset.title || '',
    publicUrl: selected?.dataset.url || '',
  });

  try {
    const result = await runSync();
    const count = result?.item_count ?? 0;
    const url = result?.public_url || selected?.dataset.url || '';
    showMessage(
      silentSaveMessage
        ? `Synchronisé — ${count} item(s)${url ? ` · ${url}` : ''}`
        : `Enregistré et synchronisé — ${count} item(s)${url ? ` · ${url}` : ''}`
    );
    return true;
  } catch (err) {
    showMessage(`Enregistré, mais sync échouée : ${err.message || err}`, true);
    return false;
  }
}

function resetTurnstileUi() {
  turnstileTokenInput.value = '';
  turnstileStatus.textContent = '';
  turnstileFrame.hidden = true;
  turnstileFrame.removeAttribute('src');
  turnstileWrap.hidden = !turnstileState.required;
}

/**
 * Ouvre le widget Turnstile (iframe sur le domaine WP) et attend le token.
 * Doit être lancé depuis un geste utilisateur (click).
 * @returns {Promise<string>}
 */
function obtainTurnstileToken() {
  if (!turnstileState.required) {
    return Promise.resolve('');
  }
  if (!turnstileState.url || !turnstileState.siteOrigin) {
    return Promise.reject(new Error('Turnstile requis mais URL challenge absente'));
  }

  resetTurnstileUi();
  turnstileWrap.hidden = false;
  turnstileStatus.textContent = 'Chargement du défi anti-bot…';

  const challengeUrl = new URL(turnstileState.url);
  challengeUrl.searchParams.set('origin', window.location.origin);

  return new Promise((resolve, reject) => {
    const timeout = window.setTimeout(() => {
      cleanup();
      reject(new Error('Délai dépassé pour Turnstile'));
    }, 120000);

    function onMessage(ev) {
      if (ev.origin !== turnstileState.siteOrigin) return;
      const data = ev.data;
      if (!data || data.type !== 'desiderata-turnstile') return;
      cleanup();
      if (data.ok && data.token) {
        turnstileTokenInput.value = data.token;
        turnstileStatus.textContent = 'Vérification OK.';
        resolve(String(data.token));
      } else {
        turnstileStatus.textContent = 'Échec du défi — réessayez.';
        reject(new Error(data.error || 'turnstile_failed'));
      }
    }

    function cleanup() {
      window.clearTimeout(timeout);
      window.removeEventListener('message', onMessage);
    }

    window.addEventListener('message', onMessage);
    turnstileFrame.hidden = false;
    turnstileFrame.src = challengeUrl.toString();

    // Fallback popup si l’iframe est bloquée.
    try {
      window.open(challengeUrl.toString(), 'desiderata-turnstile', 'width=420,height=560');
    } catch (_) {
      /* ignore */
    }
  });
}

async function refreshAuthStatus() {
  const apiBase = normalizeApiBase(siteUrl.value);
  if (!apiBase) {
    accountPanel.hidden = true;
    turnstileState = { required: false, url: '', siteOrigin: '' };
    resetTurnstileUi();
    return;
  }
  accountPanel.hidden = false;

  // Pas de permissions.request ici (pas de geste utilisateur) — fetch si déjà autorisé.
  const allowed = await hasHostPermission(apiBase);
  if (!allowed) {
    btnRegister.hidden = false;
    registrationHint.textContent =
      'Cliquez sur « Autoriser ce site » (ou Connexion / Créer un compte) pour autoriser l’accès réseau.';
    turnstileState = { required: false, url: '', siteOrigin: '' };
    resetTurnstileUi();
    return;
  }

  try {
    const status = await apiRequest(apiBase, '', '/auth/status');
    const open = Boolean(status.registration_open);
    btnRegister.hidden = !open;
    const siteOrigin = new URL(apiBase).origin;
    turnstileState = {
      required: Boolean(status.turnstile_required),
      url: typeof status.turnstile_url === 'string' ? status.turnstile_url : '',
      siteOrigin,
    };
    resetTurnstileUi();
    registrationHint.textContent = open
      ? turnstileState.required
        ? 'Création de compte ouverte — Turnstile requis à l’inscription.'
        : 'Création de compte ouverte sur ce serveur.'
      : 'Création de compte fermée — utilisez la connexion.';
  } catch (_) {
    btnRegister.hidden = false;
    turnstileState = { required: false, url: '', siteOrigin: '' };
    resetTurnstileUi();
    registrationHint.textContent =
      'Impossible de lire /auth/status. Connexion / inscription restent possibles après autorisation.';
  }
}

async function applyToken(token, email) {
  tokenInput.value = token;
  const apiBase = normalizeApiBase(siteUrl.value);
  await saveSettings({ apiBase, token, accountEmail: email || '' });
  try {
    await fetchLists(apiBase, token);
  } catch (_) {
    /* liste vide tant que non chargée */
  }
}

document.getElementById('btn-authorize')?.addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  if (!apiBase) {
    showMessage('Indiquez d’abord l’URL du site', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission refusée', true);
        return;
      }
      showMessage('Site autorisé');
      await refreshAuthStatus();
    })
    .catch((err) => showMessage(err.message || String(err), true));
});

document.getElementById('btn-load-lists').addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  const token = tokenInput.value.trim();
  if (!apiBase || !token) {
    showMessage('URL et jeton requis', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      const data = await fetchLists(apiBase, token);
      showMessage(`${(data.wishlists || []).length} liste(s) chargée(s)`);
    })
    .catch((err) => showMessage(err.message || String(err), true));
});

document.getElementById('btn-create-list').addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  const token = tokenInput.value.trim();
  if (!apiBase || !token) {
    showMessage('URL et jeton requis', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      const title = window.prompt('Titre de la nouvelle liste', 'Wishlist Livres');
      if (!title) return;
      const created = await apiRequest(apiBase, token, '/wishlists', {
        method: 'POST',
        json: { title },
      });
      await fetchLists(apiBase, token);
      wishlistSelect.value = String(created.id);
      showMessage(`Liste créée : ${created.public_url}`);
    })
    .catch((err) => showMessage(err.message || String(err), true));
});

document.getElementById('btn-sync-now').addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  if (!apiBase) {
    showMessage('URL requise', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      await persistAndSyncAfterGrant(apiBase, { silentSaveMessage: true });
    })
    .catch((err) => showMessage(err.message || String(err), true));
});

form.addEventListener('submit', (ev) => {
  ev.preventDefault();
  const apiBase = normalizeApiBase(siteUrl.value);
  if (!apiBase) {
    showMessage('URL requise', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      await persistAndSyncAfterGrant(apiBase);
    })
    .catch((err) => showMessage(err.message || String(err), true));
});

btnRegister.addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  const email = accountEmail.value.trim();
  const password = accountPassword.value;
  const display_name = accountDisplay.value.trim();
  if (!apiBase) {
    showMessage('Indiquez d’abord l’URL du site', true);
    return;
  }
  if (!email || !email.includes('@')) {
    showMessage('E-mail invalide', true);
    return;
  }
  if (!password || password.length < 8) {
    showMessage('Mot de passe : 8 caractères minimum', true);
    return;
  }

  // Appel synchrone obligatoire (geste utilisateur Firefox).
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  btnRegister.disabled = true;
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      // Rafraîchir le statut (Turnstile) maintenant que le site est autorisé.
      await refreshAuthStatus();
      let turnstile_token = turnstileTokenInput.value.trim();
      if (turnstileState.required && !turnstile_token) {
        showMessage('Complétez le défi anti-bot…');
        turnstile_token = await obtainTurnstileToken();
      }
      const data = await apiRequest(apiBase, '', '/auth/register', {
        method: 'POST',
        json: { email, password, display_name, turnstile_token },
      });
      if (!data?.token) {
        throw new Error('Réponse sans jeton');
      }
      turnstileTokenInput.value = '';
      await applyToken(data.token, email);
      showMessage('Compte créé — jeton enregistré. Choisissez une liste / un dossier puis synchronisez.');
    })
    .catch((err) => {
      const detail = err?.data?.message || err.message || String(err);
      showMessage(`Inscription : ${detail}`, true);
    })
    .finally(() => {
      btnRegister.disabled = false;
    });
});

btnLogin.addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  const email = accountEmail.value.trim();
  const password = accountPassword.value;
  if (!apiBase || !email || !password) {
    showMessage('URL, e-mail et mot de passe requis', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      const data = await apiRequest(apiBase, '', '/auth/login', {
        method: 'POST',
        json: { email, password },
      });
      await applyToken(data.token, email);
      showMessage('Connecté — jeton enregistré.');
    })
    .catch((err) => showMessage(err?.data?.message || err.message || String(err), true));
});

siteUrl.addEventListener('change', () => { refreshAuthStatus(); });
siteUrl.addEventListener('blur', () => { refreshAuthStatus(); });

document.getElementById('btn-zotero').addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  const token = tokenInput.value.trim();
  const wishlistId = wishlistSelect.value;
  const raw = document.getElementById('zoteroJson').value.trim();
  if (!apiBase || !token || !wishlistId || !raw) {
    showMessage('API, liste et JSON requis', true);
    return;
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch {
    showMessage('JSON invalide', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      const data = await apiRequest(apiBase, token, `/wishlists/${wishlistId}/import/zotero`, {
        method: 'POST',
        json: { items: parsed, mode: 'merge' },
      });
      showMessage(`Zotero : ${data.imported} ajouté(s), ${data.updated} mis à jour`);
    })
    .catch((err) => showMessage(err.message || String(err), true));
});

document.getElementById('btn-export-zotero').addEventListener('click', async () => {
  const settings = await getSettings();
  const slug = wishlistSelect.selectedOptions[0]?.dataset.slug || settings.wishlistSlug;
  const apiBase = normalizeApiBase(siteUrl.value) || settings.apiBase;
  if (!apiBase || !slug) {
    showMessage('Slug / API manquants', true);
    return;
  }
  await browser.tabs.create({ url: `${apiBase}/wishlists/${encodeURIComponent(slug)}/export/zotero` });
});

document.getElementById('btn-isbn').addEventListener('click', () => {
  const apiBase = normalizeApiBase(siteUrl.value);
  const isbn = document.getElementById('isbnInput').value.trim();
  const out = document.getElementById('isbnResult');
  if (!apiBase || !isbn) {
    showMessage('URL et ISBN requis', true);
    return;
  }
  const permissionP = requestHostPermissionFromUserGesture(apiBase);
  permissionP
    .then(async (granted) => {
      if (!granted) {
        showMessage('Permission d’accès au site refusée', true);
        return;
      }
      const data = await apiRequest(apiBase, '', `/meta/isbn/${encodeURIComponent(isbn)}`);
      out.hidden = false;
      out.textContent = JSON.stringify(data, null, 2);
      showMessage(data.title || 'ISBN trouvé');
    })
    .catch((err) => {
      out.hidden = true;
      showMessage(err.message || String(err), true);
    });
});

async function boot() {
  const settings = await getSettings();
  if (settings.apiBase) {
    siteUrl.value = settings.apiBase
      .replace(/\/wp-json\/desiderata\/v1$/i, '')
      .replace(/\/desiderata-api\/v1$/i, '')
      .replace(/\/v1$/i, '');
  }
  if (settings.token) {
    tokenInput.value = settings.token;
  }
  if (settings.accountEmail) {
    accountEmail.value = settings.accountEmail;
  }
  await loadFolders(settings.folderId);
  await refreshAuthStatus();
  if (settings.apiBase && settings.token && (await hasHostPermission(settings.apiBase))) {
    try {
      const data = await apiRequest(settings.apiBase, settings.token, '/me/wishlists');
      fillWishlists(data.wishlists || [], settings.wishlistId);
    } catch (_) {
      fillWishlists([], settings.wishlistId);
    }
  }
}

boot();
