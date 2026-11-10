import { admin, send } from './api.js';
import { esc, queryString, toast } from './util.js';
import { askConfirm } from './confirm.js';
import { t, html as tHtml } from './i18n.js';

export const savedSearchesHtml = () => document.body.dataset.guestMode === 'true'
  ? `<p class="guest-login-hint"><a href="/login">${t('saved.login_hint')}</a></p>`
  : `<section class="saved-searches"><button type="button" class="btn sm" data-save-search>${t('saved.save')}</button><details><summary>${t('saved.title')}</summary><div data-saved-list role="status">${t('common.loading')}</div></details><form data-save-form hidden><label class="f">${t('saved.name')}<input name="search_name" maxlength="80" required></label><button class="btn sm" type="submit">${t('common.save')}</button><button class="btn sm" type="button" data-save-cancel>${t('common.cancel')}</button></form><p data-saved-error role="alert"></p></section>`;
export function parseSaved(rows, kind) {
  return rows.filter(r => r.kind === 'saved_search' && r.subject === kind).flatMap(r => {
    try { const value = JSON.parse(r.key); return typeof value.name === 'string' && typeof value.query === 'string' ? [{ ...value, key: r.key }] : []; } catch { return []; }
  });
}
export const savedItemsHtml = (rows, kind) => rows.map((r, i) => `<div class="saved-item"><a href="#/${kind}${r.query ? '?' + esc(r.query) : ''}">${esc(r.name)}</a><button type="button" class="btn sm" data-delete-search="${i}" aria-label="${t('saved.delete_aria', { name: r.name })}">×</button></div>`).join('') || `<p class="dim">${t('saved.empty')}</p>`;
export function mountSavedSearches(root, kind, query, signal) {
  if (document.body.dataset.guestMode === 'true') return;
  const host = root.querySelector('[data-saved-list]'), form = root.querySelector('[data-save-form]'), error = root.querySelector('[data-saved-error]');
  let rows = [];
  const load = async () => { try { const body = await admin('/ui-me/marks?kind=saved_search&subject=' + kind); if (!signal.aborted) { rows = parseSaved(body.data || [], kind); host.innerHTML = savedItemsHtml(rows, kind); } } catch (e) { if (!signal.aborted) { host.innerHTML = `<button class="btn sm" type="button" data-saved-retry>${t('saved.reload')}</button>`; error.textContent = e.message; } } };
  root.querySelector('[data-save-search]').addEventListener('click', () => { form.hidden = false; form.elements.search_name.focus(); }, { signal });
  root.querySelector('[data-save-cancel]').addEventListener('click', () => { form.hidden = true; root.querySelector('[data-save-search]').focus(); }, { signal });
  form.addEventListener('submit', async e => {
    e.preventDefault(); const name = form.elements.search_name.value.trim(); if (!name) return;
    const qs = queryString(typeof query === 'function' ? query() : query).replace(/^\?/, '');
    if (qs.length > 1500) { error.textContent = t('saved.too_long', { max: 1500 }); return; }
    const button = form.querySelector('[type=submit]'); button.disabled = true; error.textContent = '';
    try { await send('POST', '/ui-me/marks', { kind: 'saved_search', subject: kind, key: JSON.stringify({ name, query: qs }), on: true }); if (!signal.aborted) { form.hidden = true; const details = root.querySelector('details'); if (details) details.open = true; await load(); toast(t('saved.saved_toast')); root.querySelector('[data-save-search]').focus(); } }
    catch (e) { if (!signal.aborted) error.textContent = e.message; }
    finally { button.disabled = false; }
  }, { signal });
  host.addEventListener('click', async e => {
    if (e.target.closest('[data-saved-retry]')) { error.textContent = ''; load(); return; }
    const button = e.target.closest('[data-delete-search]'); if (!button) return;
    const row = rows[Number(button.dataset.deleteSearch)];
    if (!row) return;
    if (!await askConfirm({ title: t('saved.delete_title'), body: tHtml('saved.delete_body', { name: row.name }), confirmLabel: t('common.delete'), danger: true })) return;
    button.disabled = true; error.textContent = '';
    try { await send('POST', '/ui-me/marks', { kind: 'saved_search', subject: kind, key: row.key, on: false }); await load(); toast(t('saved.deleted_toast')); }
    catch (e) { if (!signal.aborted) error.textContent = e.message; }
    finally { button.disabled = false; }
  }, { signal });
  load();
}
