import { browser } from './browser-polyfill.js';

export async function getSettings() {
  return browser.storage.local.get([
    'apiBase',
    'token',
    'wishlistId',
    'wishlistSlug',
    'wishlistTitle',
    'folderId',
    'publicUrl',
    'lastSyncAt',
    'lastSyncStatus',
    'lastClaims',
  ]);
}

export async function saveSettings(partial) {
  await browser.storage.local.set(partial);
}

/**
 * @param {string} apiBase
 * @param {string} token
 * @param {string} path
 * @param {{ method?: string, json?: unknown, headers?: HeadersInit, body?: BodyInit }} options
 */
export async function apiRequest(apiBase, token, path, options = {}) {
  const base = apiBase.replace(/\/$/, '');
  const headers = new Headers(options.headers || {});
  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }
  let body = options.body;
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json');
    body = JSON.stringify(options.json);
  }
  headers.set('Accept', 'application/json');

  const res = await fetch(`${base}${path}`, {
    method: options.method || 'GET',
    headers,
    body,
  });

  const text = await res.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = { raw: text };
  }

  if (!res.ok) {
    const msg =
      (data && (data.message || data.error || data.code)) ||
      `HTTP ${res.status}`;
    const err = new Error(typeof msg === 'string' ? msg : `HTTP ${res.status}`);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

export function normalizeApiBase(input) {
  let url = String(input || '').trim().replace(/\/$/, '');
  if (!url) return '';
  if (!/^https?:\/\//i.test(url)) {
    url = `https://${url}`;
  }
  // Already a Desiderata API root (WP or standalone).
  if (/\/wp-json\/desiderata\/v1$/i.test(url) || /\/desiderata-api\/v1$/i.test(url) || /\/v1$/i.test(url)) {
    return url;
  }
  // Default: WordPress REST path on the given site origin.
  url = url.replace(/\/wp-json\/?$/i, '');
  return `${url.replace(/\/$/, '')}/wp-json/desiderata/v1`;
}
