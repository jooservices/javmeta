import { emptyBox, movieCard, movieRow, movieHead, performerCard, performerRow, performerHead, infiniteList, ic } from './components.js';
import { api, pagination } from './api.js';
import { movieKeys, movieParams, performerKeys, performerParams, chipsHtml, removeFilter } from './filterState.js';
import { filterControls } from './filterControls.js';
import { mountPicker, performerNames } from './pickers.js';
import { mountFacetList } from './facetList.js';
import { relaxationSuggestions, suggestionsHtml } from './facets.js';
import { savedSearchesHtml, mountSavedSearches } from './savedSearches.js';
import { softNavigate, bindQuerySoftUpdate, refreshResultsRegion } from './resultsRefresh.js';
import { t } from './i18n.js';

/* [value, i18n key]: translated where rendered so a locale switch is picked up */
export const movieSorts = [['release_date', 'sort.movies.release_date'], ['update_date', 'sort.movies.update_date'], ['rating', 'sort.movies.rating'], ['relevance', 'sort.movies.relevance']];
const performerSorts = [['name', 'sort.performers.name'], ['updated_at', 'sort.performers.updated_at'], ['linked_title_count', 'sort.performers.linked_title_count']];
const prefs = key => { try { return JSON.parse(localStorage.getItem(key) || '{}'); } catch { return {}; } };
const writePrefs = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* Private mode may disable storage. */ } };
let focusPicker = false;
const drawerState = new Map();
export function searchBrowser(kind, inputQuery, { fixed = {}, apiExtra = {}, closed = false } = {}) {
  const movies = kind === 'movies', convert = movies ? movieParams : performerParams;
  /* Keep canonical ISO dates in query state and send full values to the API. Year-only copies are for form controls. */
  const yearFields = ['released_from', 'released_to', 'debut_from', 'debut_to'];
  const normalize = raw => {
    return { ...convert(raw) };
  };
  const formQueryOf = q => ({ ...q, ...Object.fromEntries(yearFields.filter(key => q[key]).map(key => [key, String(q[key]).slice(0, 4)])) });
  const sortOf = q => q.sort || (movies ? (q.q ? 'relevance' : 'release_date') : 'name');
  let query = normalize(inputQuery), sort = sortOf(query);
  const prefKey = movies ? 'xc-list' : 'xc-plist', pr = prefs(prefKey);
  const mode = pr.mode || 'grid', size = pr.size || 'M', perPage = [24, 48, 96].includes(Number(pr.perPage)) ? Number(pr.perPage) : 24;
  const baseParams = q => ({ ...convert(q), ...apiExtra, ...Object.fromEntries(Object.entries(fixed).filter(([k]) => k !== 'year')) });
  const open = drawerState.get(kind) ?? (!closed && innerWidth > 900);
  const activeHtml = (q, names) => chipsHtml(q, names) || `<span class="dim">${t('facetlist.none_active')}</span>`;
  const html = `<div class="br search-browser" data-key="${kind}"><div class="brbar"><button class="btn ${open ? 'on' : ''}" data-act="toggleside" aria-expanded="${open}">${ic('filter')} ${t('browser.filters')}</button><b class="mono" data-count></b><span class="muted">${t(movies ? 'browser.unit.movies' : 'browser.unit.performers')}</span><span class="sp"></span><label>${t('browser.sort_by')} <select data-sort>${(movies ? movieSorts : performerSorts).map(([v, l]) => `<option value="${v}" ${sort === v ? 'selected' : ''}>${t(l)}</option>`).join('')}</select></label><label>${t('browser.per_page')} <select data-page-size>${[24, 48, 96].map(n => `<option ${perPage === n ? 'selected' : ''}>${n}</option>`).join('')}</select></label><div class="seg">${movies && mode === 'grid' ? ['S', 'M', 'L'].map(s => `<button class="${size === s ? 'on' : ''}" data-act="size" data-v="${s}" aria-label="${t('browser.size_aria', { size: s })}">${s}</button>`).join('') : ''}${['grid', 'table'].map(m => `<button class="${mode === m ? 'on' : ''}" data-act="mode" data-v="${m}" aria-label="${t(m === 'grid' ? 'browser.mode.grid' : 'browser.mode.table')}">${ic(m === 'grid' ? 'grid' : 'list')}</button>`).join('')}</div></div><div class="parsed" aria-label="${t('browser.active_filters')}" aria-live="polite"><b class="fl-active-label">${t('facetlist.active')}</b><div data-active>${activeHtml(query)}</div><button class="btn sm" data-act="clearfilters">${t('browser.clear_all')}</button></div>${savedSearchesHtml()}<div class="brmain ${open ? '' : 'nof'}"><div class="brscrim" data-act="toggleside"></div><aside class="brside panel"><button class="btn sm brclose" data-act="toggleside">${t('browser.close_filters')}</button><form data-filter-form autocomplete="off">${filterControls(kind, formQueryOf(query), fixed)}<button type="submit" class="btn sm">${t('browser.apply')}</button></form></aside><div class="search-results" data-results aria-busy="false"><div class="results-error" data-results-error role="alert" hidden></div><div data-list></div><div data-relax role="status"></div></div></div></div>`;
  return { html, mount(root) {
    const lifetime = new AbortController(), signal = lifetime.signal;
    const list = root.querySelector('[data-list]'), relax = root.querySelector('[data-relax]');
    let timer, typed = false, names = {}, suggestions = [];
    const path = location.hash.slice(2).split('?')[0] || kind;
    /* every filter change goes through here: the address changes in place and applyQuery refreshes only the results */
    const go = (q, opt) => { clearTimeout(timer); drawerState.set(kind, !root.querySelector('.brmain').classList.contains('nof')); softNavigate(path, q, opt); };
    const grid = mode === 'grid' ? `<div class="${movies ? 'mgrid ' + size : 'agrid'}" data-h></div><div data-e></div><div class="brfoot" data-f></div>` : `<div class="panel tbl-wrap"><table><thead>${movies ? movieHead() : performerHead()}</thead><tbody data-h></tbody></table></div><div data-e></div><div class="brfoot" data-f></div>`;
    const pageOf = async (state, snap, sig) => {
      const body = await api(kind, { ...baseParams(snap.query), sort: snap.sort, per_page: perPage, ...(movies ? (state.cursor ? { cursor: state.cursor } : {}) : { page: state.page }) }, { signal: sig });
      const pg = pagination(body), items = body.data || [];
      return { items, total: pg.total, cursor: pg.next_cursor, page: state.page + 1, hasMore: items.length > 0 && (movies ? !!pg.has_more : state.page < (pg.last_page || 1)) };
    };
    const loadRelax = async snap => {
      relax.textContent = t('browser.relaxing');
      try {
        suggestions = await relaxationSuggestions(kind, snap.query, baseParams, signal, names);
        if (!signal.aborted && snap.query === query) relax.innerHTML = suggestionsHtml(suggestions, kind) || t('browser.relax_empty');
      } catch (e) { if (e.name !== 'AbortError') relax.textContent = ''; }
    };
    const makeList = (snap, first = null) => infiniteList(list, {
      fetchPage: state => pageOf(state, snap, signal), first,
      onFirst: r => { relax.textContent = ''; if (r.total === 0) loadRelax(snap); },
      renderItems: (items, off) => items.map((item, i) => movies ? (mode === 'grid' ? movieCard(item, off + i) : movieRow(item)) : (mode === 'grid' ? performerCard(item, off + i) : performerRow(item))).join(''),
      wrapGrid: grid, countEl: root.querySelector('[data-count]'),
      emptyHtml: emptyBox(t(movies ? 'browser.empty.movies' : 'browser.empty.performers'), t('browser.empty.hint'), `<button class="btn" data-act="clearfilters">${t('browser.clear_filters')}</button>`),
    });
    let ctl = makeList({ query, sort });
    /* only [data-list], [data-count], [data-relax] and [data-active] change; the header and the facet panel stay as they are */
    const results = refreshResultsRegion(root.querySelector('[data-results]'), {
      signal,
      load: sig => { const snap = { query, sort }; return pageOf({ cursor: null, page: 1 }, snap, sig).then(first => ({ first, snap })); },
      render: ({ first, snap }) => { ctl.destroy(); ctl = makeList(snap, first); },
    });
    const form = root.querySelector('[data-filter-form]');
    const sortSelect = root.querySelector('[data-sort]');
    const read = () => {
      const next = { ...query };
      for (const [k, v] of new FormData(form)) next[k] = v;
      if (next.score_min === '0' && !query.score_min) delete next.score_min;
      next.sort = sortSelect.value;
      return next;
    };
    /* Drop genre_all when every genre chip is gone, but keep an explicit genre_all toggle from the facet list. */
    const withPatch = (base, patch) => {
      const next = { ...base, ...patch };
      if (!next.genre && !Object.prototype.hasOwnProperty.call(patch, 'genre_all')) next.genre_all = '';
      return next;
    };
    const drawNames = () => {
      root.querySelector('[data-active]').innerHTML = activeHtml(query, names);
      const chips = root.querySelector('[data-performer-chips]'); if (chips) chips.innerHTML = chipsHtml({ actress_id: query.actress_id }, names);
    };
    const resolveNames = () => {
      drawNames();
      if (!movies || !query.actress_id) return;
      performerNames(query.actress_id, signal).then(result => { if (!signal.aborted) { names = result; drawNames(); } }).catch(() => {});
    };
    /* show the query in the form fields (needed after back/forward or a chip removal); never overwrite the field being typed in */
    const syncForm = () => {
      for (const el of [...(form.elements || [])]) {
        if (!el.name || el === document.activeElement) continue;
        const value = query[el.name] ?? (el.type === 'range' ? 0 : '');
        el.value = yearFields.includes(el.name) && value ? String(value).slice(0, 4) : value;
        if (el.name === 'score_min') root.querySelector('[data-score]').textContent = el.value;
      }
      sortSelect.value = sort;
    };
    const facetLists = [...root.querySelectorAll('[data-facet-list]')].map(host => mountFacetList(host, {
      kind, facetGroup: host.dataset.facetList, filterKey: host.dataset.key, query, scopeParams: () => baseParams(query),
      onChange: patch => go(withPatch(read(), patch)), signal,
    }));
    const applyQuery = raw => {
      query = normalize(raw); sort = sortOf(query);
      syncForm(); resolveNames();
      facetLists.forEach(f => f.update({ query }));
      results.run({ immediate: typed });
      return true;
    };
    const unbind = bindQuerySoftUpdate(applyQuery, movies ? movieKeys : performerKeys);
    form.addEventListener('input', e => {
      if (!e.target.name) return;
      if (e.target.name === 'score_min') root.querySelector('[data-score]').textContent = e.target.value;
      clearTimeout(timer); timer = setTimeout(() => { if (!signal.aborted) { typed = true; try { go(read()); } finally { typed = false; } } }, 250);
    }, { signal });
    form.addEventListener('change', e => { if (e.target.matches('select[name]')) go(read()); }, { signal });
    form.addEventListener('submit', e => { e.preventDefault(); go(read()); }, { signal });
    sortSelect.addEventListener('change', () => go(read()), { signal });
    root.querySelector('[data-page-size]').addEventListener('change', e => { writePrefs(prefKey, { ...pr, perPage: Number(e.target.value) }); location.reload(); }, { signal });
    root.addEventListener('click', e => {
      const button = e.target.closest('button, [data-act]'); if (!button) return;
      if (button.dataset.drop) go(removeFilter(read(), button.dataset.drop, button.dataset.value));
      if (button.dataset.relax !== undefined) go(suggestions[Number(button.dataset.relax)].query);
      const act = button.dataset.act;
      if (act === 'clearfilters') { e.preventDefault(); go({}); }
      if (act === 'toggleside') {
        const main = root.querySelector('.brmain'); main.classList.toggle('nof');
        const toggle = root.querySelector('.brbar [data-act=toggleside]'); toggle.classList.toggle('on'); toggle.setAttribute('aria-expanded', String(!main.classList.contains('nof')));
      }
      if (act === 'mode' || act === 'size') { writePrefs(prefKey, { ...pr, [act]: button.dataset.v }); location.reload(); }
    }, { signal });
    const stopPicker = mountPicker(root, () => query.actress_id, id => { focusPicker = true; go(withPatch(read(), { actress_id: [...new Set([...(query.actress_id ? query.actress_id.split(',') : []), id])].join(',') })); }, signal);
    if (movies && focusPicker) { root.querySelector('[data-performer-input]')?.focus(); focusPicker = false; }
    resolveNames();
    mountSavedSearches(root, kind, () => baseParams(query), signal);
    return () => { clearTimeout(timer); unbind(); results.destroy(); ctl.destroy(); lifetime.abort(); stopPicker(); facetLists.forEach(f => f.destroy()); };
  } };
}
