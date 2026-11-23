/* Minimal Promise wrapper so shared code can use `browser.*` on Chrome. */

function promisify(fn, ctx) {
  return function promisified(...args) {
    return new Promise((resolve, reject) => {
      try {
        fn.call(ctx, ...args, (result) => {
          const err = chrome.runtime.lastError;
          if (err) {
            reject(new Error(err.message));
          } else {
            resolve(result);
          }
        });
      } catch (e) {
        reject(e);
      }
    });
  };
}

function buildFromChrome() {
  const chromeApi = globalThis.chrome;
  return {
    runtime: {
      sendMessage: promisify(chromeApi.runtime.sendMessage, chromeApi.runtime),
      onMessage: chromeApi.runtime.onMessage,
      onInstalled: chromeApi.runtime.onInstalled,
      onStartup: chromeApi.runtime.onStartup,
      getURL: (...a) => chromeApi.runtime.getURL(...a),
      openOptionsPage: chromeApi.runtime.openOptionsPage
        ? promisify(chromeApi.runtime.openOptionsPage, chromeApi.runtime)
        : async () => {},
    },
    storage: {
      local: {
        get: promisify(chromeApi.storage.local.get, chromeApi.storage.local),
        set: promisify(chromeApi.storage.local.set, chromeApi.storage.local),
        remove: promisify(chromeApi.storage.local.remove, chromeApi.storage.local),
      },
    },
    bookmarks: chromeApi.bookmarks
      ? {
          getTree: promisify(chromeApi.bookmarks.getTree, chromeApi.bookmarks),
          getSubTree: promisify(chromeApi.bookmarks.getSubTree, chromeApi.bookmarks),
          onCreated: chromeApi.bookmarks.onCreated,
          onRemoved: chromeApi.bookmarks.onRemoved,
          onChanged: chromeApi.bookmarks.onChanged,
          onMoved: chromeApi.bookmarks.onMoved,
        }
      : undefined,
    tabs: chromeApi.tabs
      ? { create: promisify(chromeApi.tabs.create, chromeApi.tabs) }
      : undefined,
    permissions: chromeApi.permissions
      ? {
          request: promisify(chromeApi.permissions.request, chromeApi.permissions),
          contains: promisify(chromeApi.permissions.contains, chromeApi.permissions),
        }
      : undefined,
  };
}

export const browser =
  globalThis.browser && globalThis.browser.runtime && globalThis.browser.bookmarks
    ? globalThis.browser
    : buildFromChrome();

globalThis.browser = browser;
