import { api } from './api.js';
import { esc } from './util.js';
import { values } from './filterState.js';

const names = new Map();
export const performerLabel = p => p.name || p.name_romaji || p.name_kanji || p.name_kana || '#' + p.id;
export async function performerNames(ids, signal) {
  await Promise.all(values(ids).map(async id => {
    if (names.has(id)) return;
    try { const { data } = await api('performers/' + encodeURIComponent(id), {}, { ttl: 120000, signal }); names.set(id, performerLabel(data)); } catch (e) { if (e.name === 'AbortError') throw e; }
  }));
  return Object.fromEntries(names);
}
export const pickerHtml = () => `<div class="performer-picker"><label class="f">Diễn viên<input data-performer-input role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="performer-options" autocomplete="off" placeholder="Nhập ít nhất 2 ký tự"></label><div id="performer-options" role="listbox" aria-label="Gợi ý diễn viên" hidden></div><div data-performer-chips></div><p class="dim">Chọn nhiều diễn viên để tìm phim họ đóng chung.</p></div>`;
export const optionsHtml = (items, active) => items.map((p, i) => `<button type="button" id="performer-option-${i}" role="option" aria-selected="${i === active}" data-pick="${i}" tabindex="-1">${esc(performerLabel(p))}${p.movie_count !== undefined || p.linked_title_count !== undefined ? ` <small>${esc(p.movie_count ?? p.linked_title_count)} phim</small>` : ''}</button>`).join('');
export function mountPicker(root, selected, onPick, signal) {
  const input = root.querySelector('[data-performer-input]');
  if (!input) return () => {};
  const box = root.querySelector('[role=listbox]');
  let timer, request, items = [], active = -1, generation = 0;
  const close = () => { box.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };
  const draw = () => { box.hidden = false; input.setAttribute('aria-expanded', 'true'); box.innerHTML = items.length ? optionsHtml(items, active) : '<p role="status">Không có diễn viên khớp.</p>'; if (active >= 0) input.setAttribute('aria-activedescendant', 'performer-option-' + active); };
  const pick = i => { const p = items[i]; if (!p) return; names.set(String(p.id), performerLabel(p)); close(); input.value = ''; input.focus(); onPick(String(p.id)); };
  input.addEventListener('input', () => {
    clearTimeout(timer); request?.abort(); const gen = ++generation;
    const q = input.value.trim();
    if (q.length < 2) { close(); return; }
    timer = setTimeout(async () => {
      request = new AbortController();
      box.hidden = false; input.setAttribute('aria-expanded', 'true'); box.innerHTML = '<p role="status">Đang tìm…</p>';
      try {
        const { data } = await api('suggest', { q }, { signal: request.signal });
        if (gen !== generation || signal.aborted) return;
        items = (data.performers || []).filter(p => !values(selected).includes(String(p.id))); active = -1; draw();
      } catch (e) { if (e.name !== 'AbortError' && gen === generation && !signal.aborted) box.innerHTML = `<p role="alert">${esc(e.message)}</p>`; }
    }, 250);
  }, { signal });
  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') { generation++; clearTimeout(timer); request?.abort(); close(); }
    if (box.hidden || !items.length) return;
    if (['ArrowDown', 'ArrowUp'].includes(e.key)) { e.preventDefault(); active = active < 0 ? (e.key === 'ArrowDown' ? 0 : items.length - 1) : (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; draw(); box.querySelector('[aria-selected=true]')?.scrollIntoView({ block: 'nearest' }); }
    if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) pick(active); }
  }, { signal });
  box.addEventListener('click', e => { const b = e.target.closest('[data-pick]'); if (b) pick(Number(b.dataset.pick)); }, { signal });
  root.addEventListener('focusin', e => { if (!e.target.closest('.performer-picker')) close(); }, { signal });
  return () => { clearTimeout(timer); request?.abort(); };
}
export async function fillCatalogs(root, signal) {
  await Promise.all([...root.querySelectorAll('[data-catalog]')].map(async input => {
    const list = root.querySelector('#' + input.getAttribute('list'));
    try {
      const { data } = await api('meta/catalog/' + input.dataset.catalog, { per_page: 200 }, { ttl: 120000, signal });
      if (!signal.aborted) list.innerHTML = (data || []).map(x => `<option value="${esc(x.name)}">${esc(x.movie_count ?? x.performer_count ?? '')}</option>`).join('');
    } catch (e) { if (e.name !== 'AbortError') input.title = 'Không tải được gợi ý; vẫn có thể nhập trực tiếp.'; }
  }));
}
