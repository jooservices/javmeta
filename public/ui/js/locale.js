import { setLocale, currentLocale, t } from './i18n.js';
import { send } from './api.js';
import { toast } from './util.js';

const guestMode = () => document.body?.dataset.guestMode === 'true';

/* Saves run one after the other, so quick switches always end with the last choice stored. */
let saving = Promise.resolve();
let stored = null; // the last saved choice, or false after a failed save

/**
 * Switch the UI language without a reload.
 * setLocale() persists the choice in localStorage and notifies the listeners registered by app.js,
 * which redraw the shell and the current view. The signed-in owner's choice is also stored as the
 * `ui.locale` setting; guests are unaffected. A failed save is reported, and choosing the same
 * language again retries it.
 */
export async function switchLocale(locale) {
  const before = currentLocale();
  await setLocale(locale); // also cancels a switch that is still loading
  if (guestMode()) return;
  if (locale === before && stored !== false) return; // nothing changed and nothing failed before
  if (currentLocale() !== locale) return; // a newer selection superseded this one
  saving = saving
    .then(() => send('PUT', '/ui-admin/settings', { settings: { 'ui.locale': locale } }))
    .then(() => { stored = locale; })
    .catch(() => { stored = false; toast(t('locale.save_failed')); });
}
