import { api } from './api.js';
import { esc, fmt } from './util.js';
import { t } from './i18n.js';
import { values, multiKeys, exactKeys, quoteExact, unquoteExact } from './filterState.js';

/* The one facet component: a searchable, sortable, paginated checkbox list for EVERY facet
   (movies: genre maker series label year source; performers: cup blood_type location source status).
   Inside one list the values are OR; lists are AND with each other. Genre also has an "all of" toggle. */

export const PER_PAGE = 20;
export const MIN_COUNT = 3;
export const SEARCH_DELAY = 250;

const valueOf = x => String(x.id ?? x.name);
const display = (group, name) => (group === 'status' ? ({ active: t('filter.status.active'), retired: t('filter.status.retired') }[name] || name) : name);

/* the values ticked for this list, mirrored from the page query (a year is one released_from = released_to pair) */
export const selectedOf = (filterKey, query = {}) => {
  if (filterKey !== 'year') return exactKeys.includes(filterKey) ? values(query[filterKey]).map(unquoteExact) : values(query[filterKey]);
  const from = String(query.released_from || ''), to = String(query.released_to || '');
  if (/^\d{4}$/.test(from) && from === to) return [from];
  const year = /^(\d{4})-01-01$/.exec(from)?.[1];
  return year && to === `${year}-12-31` ? [year] : [];
};

/* the query change one tick makes; a year ticks both ends, a single-value filter replaces its value */
export function patchFor(filterKey, query, value, checked) {
  if (filterKey === 'year') return checked ? { released_from: value, released_to: value } : { released_from: '', released_to: '' };
  if (multiKeys.includes(filterKey)) {
    // the same value may already be in the URL typed (substring) or ticked (quoted): either form is replaced or removed
    const bare = v => (exactKeys.includes(filterKey) ? unquoteExact(v) : v);
    const rest = values(query[filterKey]).filter(v => bare(v) !== value);
    return { [filterKey]: (checked ? [...rest, quoteExact(filterKey, value)] : rest).join(',') };
  }
  return { [filterKey]: checked ? quoteExact(filterKey, value) : '' };
}

/* The facet request: the result scope without this list's own filter (so the user can add more values),
   the movie/performer keyword mapped to `keyword`, and the list's own search/page/sort/min_count. */
export function facetParams({ facetGroup, filterKey, scope = {}, q = '', page = 1, sort = 'count', min = true }) {
  const own = filterKey === 'year' ? ['released_from', 'released_to'] : filterKey === 'genre' ? (scope.genre_all === '1' ? [] : ['genre', 'genre_all']) : [filterKey];
  const skip = new Set(['sort', 'per_page', 'page', 'cursor', 'q', ...own]);
  const params = Object.fromEntries(Object.entries(scope).filter(([k, v]) => !skip.has(k) && v !== '' && v !== undefined && v !== null));
  const keyword = String(scope.q ?? '').trim();
  if (keyword.length >= 2) params.keyword = keyword;
  return { ...params, facet: facetGroup, q: q.trim(), page, per_page: PER_PAGE, sort, min_count: min ? MIN_COUNT : 0 };
}

export const optionHtml = (item, group, key, checked) => `<label class="fl-opt"><input type="checkbox" data-choice="${esc(key)}" data-fl-value value="${esc(valueOf(item))}"${checked ? ' checked' : ''}><span class="t">${esc(display(group, item.name))}</span><span class="n">${item.count === undefined ? '' : esc(fmt(item.count))}</span></label>`;

const minText = hidden => t('facetlist.hide_below', { min: MIN_COUNT, hidden: fmt(hidden) });
const rangeText = (page, total) => t('facetlist.range', { from: fmt(total ? (page - 1) * PER_PAGE + 1 : 0), to: fmt(Math.min(page * PER_PAGE, total)), total: fmt(total) });

export function facetListInner({ facetGroup, filterKey, query = {}, genreAllToggle = false }) {
  const title = t('facet.group.' + facetGroup);
  const pinned = selectedOf(filterKey, query).map(v => optionHtml({ name: filterKey === 'actress_id' ? '#' + v : v, id: v }, facetGroup, filterKey, true)).join('');
  return `<legend>${esc(title)}<small data-fl-total></small></legend>
<div class="fl-tools"><input type="search" data-fl-search data-choice-search="${esc(filterKey)}" placeholder="${esc(t('facetlist.search_placeholder', { title }))}" aria-label="${esc(t('facetlist.search_label', { title }))}" autocomplete="off"><select data-fl-sort aria-label="${esc(t('facetlist.sort_label', { title }))}"><option value="count">${t('facetlist.sort.count')}</option><option value="name">${t('facetlist.sort.name')}</option></select></div>
<div class="fl-error" data-fl-error role="alert" hidden></div>
<div class="fl-list" data-fl-list aria-busy="false"><div class="fl-pin" data-fl-pin${pinned ? '' : ' hidden'}>${pinned}</div><div data-fl-rest></div><p class="fl-empty" data-fl-empty hidden>${t('facetlist.empty')}</p></div>
<div class="fl-pager"><button type="button" data-fl-prev disabled aria-label="${t('facetlist.prev')}">‹</button><span data-fl-range role="status" aria-live="polite"></span><button type="button" data-fl-next disabled aria-label="${t('facetlist.next')}">›</button></div>
<label class="fl-min"><input type="checkbox" data-fl-min checked> <span data-fl-min-text>${minText(0)}</span></label>${genreAllToggle ? `<label class="fl-min"><input type="checkbox" data-fl-all${query.genre_all === '1' ? ' checked' : ''}> <span>${t('facetlist.all_of')}</span></label>` : ''}`;
}

export const facetListHtml = opts => `<fieldset class="filter-set facet-list" data-facet-list="${esc(opts.facetGroup)}" data-key="${esc(opts.filterKey)}" data-kind="${esc(opts.kind || 'movies')}">${facetListInner(opts)}</fieldset>`;

/* host: the <fieldset data-facet-list> made by facetListHtml (its inner markup is added when it is empty).
   Returns { update({query, scopeParams}), destroy() }. State (typed search, page, sort, min toggle, last result) lives here, so the
   parent can refresh its results any number of times without this list losing its place. */
export function mountFacetList(host, { kind = 'movies', facetGroup, filterKey, query = {}, scopeParams = {}, onChange = () => {}, signal, genreAllToggle = false } = {}) {
  if (!host.querySelector('[data-fl-list]')) host.innerHTML = facetListInner({ facetGroup, filterKey, query, genreAllToggle });
  const $ = s => host.querySelector(s);
  const [search, sortSel, errorEl, list, pin, rest, empty, prev, next, rangeEl, totalEl, minBox, minText$, allBox] = ['[data-fl-search]', '[data-fl-sort]', '[data-fl-error]', '[data-fl-list]', '[data-fl-pin]', '[data-fl-rest]', '[data-fl-empty]', '[data-fl-prev]', '[data-fl-next]', '[data-fl-range]', '[data-fl-total]', '[data-fl-min]', '[data-fl-min-text]', '[data-fl-all]'].map($);
  const state = { q: '', page: 1, sort: 'count', min: true, data: null, known: new Map(), loadedKey: null };
  let current = query, scope = scopeParams, timer, request, floor = 0;
  const scopeNow = () => (typeof scope === 'function' ? scope() : scope) || {};
  const keyOf = () => JSON.stringify(facetParams({ facetGroup, filterKey, scope: scopeNow(), q: '', page: 1, sort: 'count', min: true }));
  const details = host.closest?.('details') ?? null;
  const deferred = () => !!details && !details.open;
  const lastPage = () => Math.max(1, Math.ceil((state.data?.total ?? 0) / PER_PAGE));
  const selected = () => selectedOf(filterKey, current);
  const pinnedItem = v => state.known.get(v) || { name: filterKey === 'actress_id' ? '#' + v : v, id: v };

  function draw() {
    const sel = selected(), data = state.data;
    const items = (data?.items || []).filter(x => !sel.includes(valueOf(x)));
    pin.innerHTML = sel.map(v => optionHtml(pinnedItem(v), facetGroup, filterKey, true)).join('');
    pin.hidden = !sel.length;
    rest.innerHTML = items.map(x => optionHtml(x, facetGroup, filterKey, false)).join('');
    empty.hidden = !data || sel.length > 0 || items.length > 0;
    const total = data?.total ?? 0;
    rangeEl.textContent = data ? rangeText(state.page, total) : '';
    totalEl.textContent = data ? fmt(total) : '';
    prev.disabled = state.page <= 1;
    next.disabled = !data || state.page >= lastPage();
    minText$.textContent = minText(data?.hidden_below_min ?? 0);
    floor = Math.max(floor, list.offsetHeight || 0);
    if (floor) list.style.minHeight = floor + 'px';
  }

  /* the ticked boxes must equal the query: after an own click they already do; after a chip removal, "clear all" or back they do not */
  const ticked = () => [...host.querySelectorAll('[data-fl-value]')].filter(el => el.checked).map(el => el.value);
  function sync() {
    const want = selected(), have = ticked();
    if (want.length !== have.length || want.some(v => !have.includes(v))) draw();
    if (allBox) allBox.checked = current.genre_all === '1';
  }

  async function run() {
    request?.abort();
    const mine = request = new AbortController();
    const stop = () => mine.abort();
    signal?.addEventListener('abort', stop, { once: true });
    state.loadedKey = keyOf();
    list.setAttribute('aria-busy', 'true'); list.classList.add('is-busy'); errorEl.hidden = true;
    let again = false;
    try {
      const body = await api(`${kind}/facets/values`, facetParams({ facetGroup, filterKey, scope: scopeNow(), q: state.q, page: state.page, sort: state.sort, min: state.min }), { signal: mine.signal });
      if (mine.signal.aborted) return;
      state.data = body.data || { items: [], total: 0, hidden_below_min: 0 };
      state.data.items = state.data.items || [];
      state.data.items.forEach(x => state.known.set(valueOf(x), x));
      if (state.page > lastPage()) { state.page = lastPage(); again = true; }
      draw();
    } catch (e) {
      if (e.name === 'AbortError' || mine.signal.aborted) return;
      errorEl.hidden = false;
      errorEl.innerHTML = `${esc(e.message || e)} <button type="button" class="btn sm" data-fl-retry>${t('common.retry')}</button>`;
    } finally {
      signal?.removeEventListener('abort', stop);
      if (request === mine) { list.setAttribute('aria-busy', 'false'); list.classList.remove('is-busy'); }
    }
    if (again && !mine.signal.aborted) run();
  }
  /* typed search and parent refreshes are debounced; page, sort and min-count clicks are not */
  const load = ({ immediate = false } = {}) => {
    clearTimeout(timer);
    if (deferred()) return;
    if (immediate) run(); else timer = setTimeout(run, SEARCH_DELAY);
  };

  const opts = signal ? { signal } : undefined;
  search.addEventListener('input', () => { state.q = search.value; state.page = 1; load(); }, opts);
  search.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); }, opts);
  sortSel.addEventListener('change', () => { state.sort = sortSel.value; state.page = 1; load({ immediate: true }); }, opts);
  minBox.addEventListener('change', () => { state.min = minBox.checked; state.page = 1; load({ immediate: true }); }, opts);
  prev.addEventListener('click', () => { if (state.page > 1) { state.page--; load({ immediate: true }); } }, opts);
  next.addEventListener('click', () => { if (state.page < lastPage()) { state.page++; load({ immediate: true }); } }, opts);
  allBox?.addEventListener('change', () => onChange({ genre_all: allBox.checked ? '1' : '' }), opts);
  errorEl.addEventListener('click', e => { if (e.target.closest('[data-fl-retry]')) load({ immediate: true }); }, opts);
  list.addEventListener('change', e => {
    const input = e.target;
    if (!input.matches?.('[data-fl-value]')) return;
    e.stopPropagation?.();
    const label = input.closest('label');
    if (input.checked && label && !pin.contains(label)) { pin.hidden = false; pin.append(label); }
    onChange(patchFor(filterKey, current, input.value, input.checked));
  }, opts);
  details?.addEventListener('toggle', () => { if (details.open && state.loadedKey !== keyOf()) load({ immediate: true }); }, opts);

  sortSel.value = state.sort;
  state.loadedKey = null;
  load({ immediate: true });
  return {
    update(change = {}) {
      if (change.query) current = change.query;
      if (change.scopeParams) scope = change.scopeParams;
      sync();
      if (state.loadedKey !== keyOf()) load();
    },
    destroy() { clearTimeout(timer); request?.abort(); },
  };
}
