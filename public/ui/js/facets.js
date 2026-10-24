import { api } from './api.js';
import { esc, fold } from './util.js';
import { values, activeFilters, removeFilter } from './filterState.js';

const previous = new Map();
const groups = {
  movies: [['genres', 'Thể loại', 'genre'], ['makers', 'Hãng', 'maker'], ['series', 'Series', 'series'], ['labels', 'Nhãn', 'label'], ['years', 'Năm', 'year'], ['sources', 'Nguồn', 'source'], ['performers', 'Diễn viên', 'actress_id']],
  performers: [['cups', 'Cup', 'cup'], ['blood_types', 'Nhóm máu', 'blood_type'], ['locations', 'Nơi sống', 'location'], ['sources', 'Nguồn', 'source'], ['status', 'Hoạt động', 'status']],
};
const display = v => ({ active: 'Đang hoạt động', retired: 'Đã nghỉ' }[v] || v);
export const facetButtons = (rows, key, query) => (rows || []).map(x => `<button type="button" class="chip ${values(query[key]).includes(String(x.id ?? x.name)) ? 'on' : ''}" data-facet="${key}" data-value="${esc(x.id ?? x.name)}" aria-pressed="${values(query[key]).includes(String(x.id ?? x.name))}">${esc(display(x.name))} <small>(${esc(x.count)})</small></button>`).join('');
export function facetHtml(kind, data, query) {
  return groups[kind].map(([group, title, key]) => `<details open><summary>${title}</summary><div class="facet-values">${facetButtons(data[group], key, query) || '<span class="dim">Không có giá trị</span>'}</div></details>`).join('') + (kind === 'performers' ? `<p class="dim">Có ảnh: ${esc(data.with_image ?? 0)} · Có tiểu sử: ${esc(data.with_bio ?? 0)}</p>` : '');
}
export function choiceHtml(rows, key, query, search = '') {
  const byName = new Map((rows || []).map(x => [String(x.name), x]));
  values(query[key]).forEach(name => { if (!byName.has(name)) byName.set(name, { name, count: 0 }); });
  return [...byName.values()].filter(x => fold(x.name).includes(fold(search))).map(x => `<label class="filter-choice"><input type="checkbox" data-choice="${key}" value="${esc(x.name)}" ${values(query[key]).includes(String(x.name)) ? 'checked' : ''}><span>${esc(x.name)}</span><small>(${esc(x.count)})</small></label>`).join('') || '<p class="dim">Không có giá trị khớp.</p>';
}
export const facetShell = kind => `<section class="facet-panel" aria-label="Thu hẹp kết quả"><h3>Thu hẹp kết quả</h3><div data-facet-error role="alert"></div><div data-facets>${previous.has(kind) ? facetHtml(kind, previous.get(kind), {}) : ''}</div><div data-facet-loading class="facet-skeleton" role="status">Đang đếm kết quả…</div></section>`;
export async function relaxationSuggestions(kind, query, params, signal, names = {}) {
  // Bound expensive relaxed counts to three independent requests.
  const candidates = activeFilters(query, names).filter(f => f.key !== 'genre_all').slice(0, 3);
  const results = await Promise.all(candidates.map(async f => {
    const relaxed = removeFilter(query, f.key, f.value);
    try { const { data } = await api(kind + '/facets', params(relaxed), { signal }); return { ...f, total: data.total, query: relaxed }; }
    catch (e) { if (e.name === 'AbortError') throw e; return null; }
  }));
  return results.filter(r => r && r.total > 0).sort((a, b) => b.total - a.total);
}
export const suggestionsHtml = (rows, kind) => rows.map((f, i) => `<button type="button" class="chip" data-relax="${i}">Thử bỏ: ${esc(f.label)} (+${esc(f.total)} ${kind === 'movies' ? 'phim' : 'diễn viên'})</button>`).join('');
export function mountFacets(root, kind, query, params, onResults, signal) {
  const panel = root.querySelector('[data-facets]'), loading = root.querySelector('[data-facet-loading]');
  let data = previous.get(kind) || {}, request;
  const choices = () => root.querySelectorAll('[data-choices]').forEach(el => {
    const group = el.dataset.choices, key = el.dataset.key;
    el.innerHTML = choiceHtml(data[group], key, query, root.querySelector('[data-choice-search="' + key + '"]')?.value || '');
  });
  root.querySelectorAll('[data-choice-search]').forEach(input => input.addEventListener('input', choices, { signal }));
  async function refresh() {
    request?.abort(); request = new AbortController();
    const abort = () => request.abort(); signal.addEventListener('abort', abort, { once: true });
    panel.classList.add('dimmed'); panel.setAttribute('aria-busy', 'true'); loading.hidden = false;
    root.querySelector('[data-facet-error]').innerHTML = '';
    try {
      const body = await api(kind + '/facets', params(query), { signal: request.signal });
      if (signal.aborted) return;
      data = body.data; previous.set(kind, data); panel.innerHTML = facetHtml(kind, data, query); choices();
      root.querySelectorAll('[data-top]').forEach(el => { el.innerHTML = facetButtons((data[el.dataset.top] || []).slice(0, 5), el.dataset.key, query); });
      await onResults(data);
    } catch (e) { if (e.name !== 'AbortError' && !signal.aborted) root.querySelector('[data-facet-error]').innerHTML = `${esc(e.message)} <button type="button" data-facet-retry>Thử lại</button>`; }
    finally { signal.removeEventListener('abort', abort); if (!signal.aborted) { panel.classList.remove('dimmed'); panel.setAttribute('aria-busy', 'false'); loading.hidden = true; } }
  }
  root.querySelector('[data-facet-error]').addEventListener('click', e => { if (e.target.closest('[data-facet-retry]')) refresh(); }, { signal });
  choices(); refresh();
  return () => request?.abort();
}
