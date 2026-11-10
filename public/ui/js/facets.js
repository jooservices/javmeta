import { api } from './api.js';
import { t } from './i18n.js';
import { activeFilters, removeFilter } from './filterState.js';

/* "Try removing…" suggestions for an empty result. The facet value lists themselves live in facetList.js. */
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
export const suggestionsHtml = (rows, kind) => rows.map((f, i) => `<button type="button" class="chip" data-relax="${i}">${t(kind === 'movies' ? 'facet.relax.movies' : 'facet.relax.performers', { label: f.label, count: f.total, n: f.total })}</button>`).join('');
