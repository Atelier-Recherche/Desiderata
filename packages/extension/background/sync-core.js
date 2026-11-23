import { browser } from '../shared/browser-polyfill.js';
import { apiRequest, getSettings, saveSettings } from '../shared/api.js';

const DEBOUNCE_MS = 3000;
let debounceTimer = null;
let syncing = false;

export function initSyncListeners() {
  if (!browser.bookmarks) {
    console.warn('Desiderata: API bookmarks indisponible');
    return;
  }

  const schedule = () => scheduleSync('bookmark-change');
  browser.bookmarks.onCreated.addListener(schedule);
  browser.bookmarks.onRemoved.addListener(schedule);
  browser.bookmarks.onChanged.addListener(schedule);
  browser.bookmarks.onMoved.addListener(schedule);

  browser.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (!message || typeof message !== 'object') {
      return undefined;
    }

    const reply = (promise) => {
      promise.then(sendResponse).catch((err) => {
        sendResponse({ ok: false, error: err.message || String(err) });
      });
      return true;
    };

    if (message.type === 'sync-now') {
      return reply(runSync('manual').then((result) => ({ ok: true, result })));
    }
    if (message.type === 'list-folders') {
      return reply(listBookmarkFolders().then((folders) => ({ ok: true, folders })));
    }
    if (message.type === 'get-status') {
      return reply(getSettings().then((s) => ({ ok: true, settings: s })));
    }
    return undefined;
  });
}

export function scheduleSync(reason = 'debounce') {
  if (debounceTimer) {
    clearTimeout(debounceTimer);
  }
  debounceTimer = setTimeout(() => {
    runSync(reason).catch((err) => console.warn('Desiderata sync failed', err));
  }, DEBOUNCE_MS);
}

export async function runSync(reason = 'manual') {
  if (syncing) {
    return { skipped: true, reason: 'in-flight' };
  }
  syncing = true;
  try {
    const settings = await getSettings();
    if (!settings.apiBase || !settings.token || !settings.wishlistId || !settings.folderId) {
      await saveSettings({ lastSyncStatus: 'missing-config' });
      return { skipped: true, reason: 'missing-config' };
    }

    const items = await collectFolderBookmarks(String(settings.folderId));
    const data = await apiRequest(
      settings.apiBase,
      settings.token,
      `/wishlists/${settings.wishlistId}/sync`,
      {
        method: 'PUT',
        json: {
          title: settings.wishlistTitle || undefined,
          items,
        },
      }
    );

    const now = new Date().toISOString();
    await saveSettings({
      lastSyncAt: now,
      lastSyncStatus: 'ok',
      publicUrl: data.public_url || settings.publicUrl,
      wishlistSlug: data.slug || settings.wishlistSlug,
    });

    if (data.slug || settings.wishlistSlug) {
      try {
        const publicList = await apiRequest(
          settings.apiBase,
          '',
          `/wishlists/${data.slug || settings.wishlistSlug}`
        );
        await saveSettings({ lastClaims: summarizeClaims(publicList.items || []) });
      } catch (_) {
        /* optional */
      }
    }

    return { ok: true, reason, item_count: items.length, public_url: data.public_url };
  } catch (err) {
    await saveSettings({ lastSyncStatus: err.message || 'error' });
    throw err;
  } finally {
    syncing = false;
  }
}

function summarizeClaims(items) {
  return items
    .filter((i) => !i.available)
    .map((i) => ({
      bookmark_guid: i.bookmark_guid,
      title: i.title,
      claimed_by: i.claimed_by,
    }));
}

async function listBookmarkFolders() {
  const tree = await browser.bookmarks.getTree();
  const folders = [];

  function walk(nodes, path) {
    for (const node of nodes || []) {
      const isFolder = node.url === undefined;
      if (isFolder) {
        const nextPath = path ? `${path} / ${node.title || 'Sans titre'}` : (node.title || 'Racine');
        if (node.id !== '0') {
          folders.push({ id: node.id, title: node.title || 'Sans titre', path: nextPath });
        }
        if (node.children) {
          walk(node.children, nextPath);
        }
      }
    }
  }

  walk(tree, '');
  return folders;
}

async function collectFolderBookmarks(folderId) {
  const nodes = await browser.bookmarks.getSubTree(folderId);
  const root = nodes[0];
  const items = [];

  function walk(node) {
    if (!node) return;
    if (node.url) {
      if (/^https?:\/\//i.test(node.url)) {
        items.push({
          bookmark_guid: String(node.id),
          url: node.url,
          title: node.title || node.url,
          meta: null,
        });
      }
      return;
    }
    for (const child of node.children || []) {
      walk(child);
    }
  }

  walk(root);
  return items;
}
