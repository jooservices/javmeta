import { setLocale, currentLocale } from './i18n.js';
import { send } from './api.js';

const guestMode = () => document.body?.dataset.guestMode === 'true';

/**
 * Switch the UI language without a reload.
 * setLocale() persists the choice in localStorage and notifies the listeners registered by app.js,
 * which redraw the shell and the current view. The signed-in owner's choice is also stored as the
 * `ui.locale` setting; guests, and servers that do not know the setting yet, are unaffected.
 */
export async function switchLocale(locale) {
  if (locale === currentLocale()) return;
  await setLocale(locale);
  if (guestMode()) return;
  send('PUT', '/ui-admin/settings', { settings: { 'ui.locale': locale } }).catch(() => { /* the local choice already applies */ });
}
