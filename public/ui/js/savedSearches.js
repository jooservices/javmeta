import { admin, send } from './api.js';
import { esc, queryString } from './util.js';

export const savedSearchesHtml = () => `<section class="saved-searches"><button type="button" class="btn sm" data-save-search>Lưu bộ lọc</button><details><summary>Bộ lọc đã lưu</summary><div data-saved-list role="status">Đang tải…</div></details><form data-save-form hidden><label class="f">Tên bộ lọc<input name="search_name" maxlength="80" required></label><button class="btn sm" type="submit">Lưu</button><button class="btn sm" type="button" data-save-cancel>Huỷ</button></form><p data-saved-error role="alert"></p></section>`;
export function parseSaved(rows, kind) {
  return rows.filter(r => r.kind === 'saved_search' && r.subject === kind).flatMap(r => {
    try { const value = JSON.parse(r.key); return typeof value.name === 'string' && typeof value.query === 'string' ? [{ ...value, key: r.key }] : []; } catch { return []; }
  });
}
export const savedItemsHtml = (rows, kind) => rows.map((r, i) => `<div class="saved-item"><a href="#/${kind}${r.query ? '?' + esc(r.query) : ''}">${esc(r.name)}</a><button type="button" class="btn sm" data-delete-search="${i}" aria-label="Xoá ${esc(r.name)}">×</button></div>`).join('') || '<p class="dim">Chưa có bộ lọc đã lưu.</p>';
export function mountSavedSearches(root, kind, query, signal) {
  const host = root.querySelector('[data-saved-list]'), form = root.querySelector('[data-save-form]'), error = root.querySelector('[data-saved-error]');
  let rows = [];
  const load = async () => { try { const body = await admin('/ui-me/marks?kind=saved_search&subject=' + kind); if (!signal.aborted) { rows = parseSaved(body.data || [], kind); host.innerHTML = savedItemsHtml(rows, kind); } } catch (e) { if (!signal.aborted) { host.innerHTML = '<button class="btn sm" type="button" data-saved-retry>Thử tải lại</button>'; error.textContent = e.message; } } };
  root.querySelector('[data-save-search]').addEventListener('click', () => { form.hidden = false; form.elements.search_name.focus(); }, { signal });
  root.querySelector('[data-save-cancel]').addEventListener('click', () => { form.hidden = true; root.querySelector('[data-save-search]').focus(); }, { signal });
  form.addEventListener('submit', async e => {
    e.preventDefault(); const name = form.elements.search_name.value.trim(); if (!name) return;
    const qs = queryString(query).replace(/^\?/, '');
    if (qs.length > 1500) { error.textContent = 'Bộ lọc quá dài (tối đa 1500 ký tự).'; return; }
    const button = form.querySelector('[type=submit]'); button.disabled = true; error.textContent = '';
    try { await send('POST', '/ui-me/marks', { kind: 'saved_search', subject: kind, key: JSON.stringify({ name, query: qs }), on: true }); if (!signal.aborted) { form.hidden = true; await load(); root.querySelector('[data-save-search]').focus(); } }
    catch (e) { if (!signal.aborted) error.textContent = e.message; }
    finally { button.disabled = false; }
  }, { signal });
  host.addEventListener('click', async e => {
    if (e.target.closest('[data-saved-retry]')) { error.textContent = ''; load(); return; }
    const button = e.target.closest('[data-delete-search]'); if (!button) return;
    button.disabled = true; error.textContent = '';
    try { await send('POST', '/ui-me/marks', { kind: 'saved_search', subject: kind, key: rows[Number(button.dataset.deleteSearch)].key, on: false }); await load(); }
    catch (e) { if (!signal.aborted) error.textContent = e.message; }
    finally { button.disabled = false; }
  }, { signal });
  load();
}
