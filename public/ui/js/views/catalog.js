import { esc, fmt } from '../util.js';
import { api, pagination } from '../api.js';
import { head, a, href, emptyBox, infiniteList } from '../components.js';
import { t } from '../i18n.js';
import { softNavigate, bindQuerySoftUpdate, refreshResultsRegion } from '../resultsRefresh.js';

const n = x => x.movie_count ?? x.performer_count ?? 0;
export const MIN_COUNT = 3;
export const SCRIPTS = ['all', 'latin', 'ja', 'zh', 'other'];
/* script: show the script index; min: hide entries below MIN_COUNT movies unless the user turns it off (genres hide the long tail by default) */
export const KINDS = {
  genres: { title: 'catalog.kind.genres', facet: 'genre', unit: 'catalog.unit.movies', script: true, min: MIN_COUNT },
  makers: { title: 'catalog.kind.makers', facet: 'maker', unit: 'catalog.unit.movies', script: true, min: 0 },
  series: { title: 'catalog.kind.series', facet: 'series', unit: 'catalog.unit.movies', script: true, min: 0 },
  labels: { title: 'catalog.kind.labels', facet: 'label', unit: 'catalog.unit.movies', script: true, min: 0 },
  years: { title: 'catalog.kind.years', facet: 'year', unit: 'catalog.unit.movies', min: 0 },
  cups: { title: 'catalog.kind.cups', perf: 'cup', unit: 'catalog.unit.performers', min: 0 },
  blood_types: { title: 'catalog.kind.blood_types', perf: 'blood_type', unit: 'catalog.unit.performers', min: 0 },
  locations: { title: 'catalog.kind.locations', perf: 'location', unit: 'catalog.unit.performers', min: 0 },
};

/* the page state read from the address; defaults are left out of the address again by toQuery */
export const stateOf = (kind, k, query = {}) => ({
  q: query.q || '',
  sort: query.sort || (kind === 'years' ? 'name' : 'movie_count'),
  script: k.script && SCRIPTS.includes(query.script) ? query.script : 'all',
  min: query.min_count !== undefined && query.min_count !== '' ? Math.max(0, Number(query.min_count) || 0) : k.min,
});
export const toQuery = (k, st) => ({ q: st.q, sort: st.sort, ...(st.script !== 'all' ? { script: st.script } : {}), ...(st.min !== k.min ? { min_count: st.min } : {}) });

const scriptBar = st => `<div class="seg catalog-script" role="group" aria-label="${t('catalog.script.label')}">${SCRIPTS.map(s => `<button type="button" data-script="${s}" class="${st.script === s ? 'on' : ''}" aria-pressed="${st.script === s}">${t('catalog.script.' + s)}</button>`).join('')}</div>`;

/* a list of every genre, maker, series, label or year with its number of movies; each value is a link to its page */
export default {
  async render({ params, query }) {
    const k = KINDS[params.kind];
    if (!k) return { html: `<div class="wrap">${emptyBox(t('catalog.unknown'), esc(params.kind), a('#/movies', t('common.back_to_movies'), 'btn'))}</div>` };
    const st = stateOf(params.kind, k, query);
    const html = `<div class="wrap">${head(t(k.title), t('catalog.sub', { unit: t(k.unit) }))}
      <div class="brbar" style="margin-bottom:8px"><form id="cf" class="omni" style="max-width:360px;flex:1"><input type="text" name="q" value="${esc(st.q)}" placeholder="${t('catalog.search_in', { title: t(k.title).toLowerCase() })}" aria-label="${t('catalog.search_in', { title: t(k.title).toLowerCase() })}" autocomplete="off" style="padding-left:12px"></form><b class="mono" data-count></b> <span class="muted">${t('catalog.items')}</span><span class="sp"></span>
        <label class="muted">${t('browser.sort_by')} <select id="csort"><option value="movie_count" ${st.sort === 'movie_count' ? 'selected' : ''}>${t('catalog.sort.movie_count')}</option><option value="name" ${st.sort === 'name' ? 'selected' : ''}>${t('catalog.sort.name')}</option></select></label></div>
      <div class="catalog-tools">${k.script ? scriptBar(st) : ''}<label class="fl-min"><input type="checkbox" id="cmin" ${st.min > 0 ? 'checked' : ''}> <span data-min-text>${t('catalog.min_count', { min: st.min > 0 ? st.min : MIN_COUNT, unit: t(k.unit), hidden: fmt(0) })}</span></label></div>
      <div class="search-results" data-results aria-busy="false"><div class="results-error" data-results-error role="alert" hidden></div><div class="panel" data-list></div></div></div>`;
    return { html, mount: root => mount(root, params.kind, k, st) };
  },
};

function mount(root, kind, k, initial) {
  const list = root.querySelector('[data-list]');
  const path = 'catalog/' + kind;
  const lifetime = new AbortController(), signal = lifetime.signal;
  let st = initial, max = 1, typed = false, timer;
  let lastMin = st.min > 0 ? st.min : MIN_COUNT; // the box restores the minimum the user had (history included), not the default
  const input = root.querySelector('#cf input[name=q]'), sortSel = root.querySelector('#csort'), minBox = root.querySelector('#cmin');
  const pageOf = async (state, snap, sig) => {
    const body = await api('meta/catalog/' + kind, { q: snap.q, sort: snap.sort, per_page: 60, page: state.page, min_count: snap.min, ...(snap.script !== 'all' ? { script: snap.script } : {}) }, { signal: sig });
    const pg = pagination(body);
    if (state.page === 1) { max = body.data.length ? Math.max(1, ...body.data.map(n)) : 1; root.querySelector('[data-min-text]').textContent = t('catalog.min_count', { min: snap.min > 0 ? snap.min : MIN_COUNT, unit: t(k.unit), hidden: fmt(pg.hidden_below_min ?? 0) }); }
    return { items: body.data || [], total: pg.total, page: state.page + 1, hasMore: state.page < (pg.last_page || 1) };
  };
  const makeList = (snap, first = null) => infiniteList(list, {
    fetchPage: state => pageOf(state, snap, signal), first,
    renderItems: items => items.map(x => `<div class="row-i"><div class="grow ell">${a(k.perf ? href.performers({ [k.perf]: x.name }) : href.facet(k.facet, x.name), esc(x.name))}</div><div class="bar" style="width:140px"><i style="width:${Math.max(2, n(x) / max * 100)}%"></i></div><span class="mono muted cat-badge" style="width:70px;text-align:right">${fmt(n(x))}</span></div>`).join(''),
    wrapGrid: '<div data-h></div><div data-e></div><div class="brfoot" data-f></div>',
    emptyHtml: emptyBox(t('catalog.empty'), t('catalog.empty_hint')),
    countEl: root.querySelector('[data-count]'),
  });
  let ctl = makeList(st);
  const results = refreshResultsRegion(root.querySelector('[data-results]'), {
    signal,
    load: sig => { const snap = st; return pageOf({ page: 1 }, snap, sig).then(first => ({ first, snap })); },
    render: ({ first, snap }) => { ctl.destroy(); ctl = makeList(snap, first); },
  });
  const go = next => { clearTimeout(timer); softNavigate(path, toQuery(k, next)); };
  const apply = raw => {
    st = stateOf(kind, k, raw);
    if (st.min > 0) lastMin = st.min;
    if (input !== document.activeElement) input.value = st.q;
    sortSel.value = st.sort;
    minBox.checked = st.min > 0;
    root.querySelectorAll('[data-script]').forEach(b => { b.classList.toggle('on', b.dataset.script === st.script); b.setAttribute('aria-pressed', String(b.dataset.script === st.script)); });
    results.run({ immediate: typed });
    return true;
  };
  const unbind = bindQuerySoftUpdate(apply, ['q', 'sort', 'script', 'min_count']);
  const form = root.querySelector('#cf');
  form.addEventListener('input', e => { clearTimeout(timer); const q = e.target.value; timer = setTimeout(() => { typed = true; try { go({ ...st, q }); } finally { typed = false; } }, 250); }, { signal });
  form.addEventListener('submit', e => e.preventDefault(), { signal });
  sortSel.addEventListener('change', e => go({ ...st, sort: e.target.value }), { signal });
  minBox.addEventListener('change', e => go({ ...st, min: e.target.checked ? lastMin : 0 }), { signal });
  root.addEventListener('click', e => { const b = e.target.closest('[data-script]'); if (b) go({ ...st, script: b.dataset.script }); }, { signal });
  return () => { clearTimeout(timer); unbind(); results.destroy(); ctl.destroy(); lifetime.abort(); };
}
