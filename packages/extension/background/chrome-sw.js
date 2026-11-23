import { browser } from '../shared/browser-polyfill.js';
import { initSyncListeners, scheduleSync } from './sync-core.js';

initSyncListeners();

browser.runtime.onInstalled.addListener(() => {
  scheduleSync('installed');
});

if (browser.runtime.onStartup) {
  browser.runtime.onStartup.addListener(() => {
    scheduleSync('startup');
  });
}
