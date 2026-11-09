import { esc, fmt, debounce, queryString } from '../util.js';
import { api, pagination } from '../api.js';
import { head, a, href, emptyBox, infiniteList } from '../components.js';
import { t } from '../i18n.js';

const n = x => x.movie_count ?? x.performer_count ?? 0;
export const KINDS = {
  genres: { title: 'catalog.kind.genres', facet: 'genre', unit: 'catalog.unit.movies' },
  makers: { title: 'catalog.kind.makers', facet: 'maker', unit: 'catalog.unit.movies' },
  series: { title: 'catalog.kind.series', facet: 'series', unit: 'catalog.unit.movies' },
  labels: { title: 'catalog.kind.labels', facet: 'label', unit: 'catalog.unit.movies' },
  years: { title: 'catalog.kind.years', facet: 'year', unit: 'catalog.unit.movies' },
  cups: { title: 'catalog.kind.cups', perf: 'cup', unit: 'catalog.unit.performers' },
  blood_types: { title: 'catalog.kind.blood_types', perf: 'blood_type', unit: 'catalog.unit.performers' },
  locations: { title: 'catalog.kind.locations', perf: 'location', unit: 'catalog.unit.performers' },
};

/* a list of every genre, maker, series, label or year with its number of movies; each value is a link to its page */
export default {
  async render({ params, query }) {
    const k = KINDS[params.kind];
    if (!k) return { html: `<div class="wrap">${emptyBox(t('catalog.unknown'), esc(params.kind), a('#/movies', t('common.back_to_movies'), 'btn'))}</div>` };
    const sort = query.sort || (params.kind === 'years' ? 'name' : 'movie_count');
    const html = `<div class="wrap">${head(t(k.title), t('catalog.sub', { unit: t(k.unit) }))}
      <div class="brbar" style="margin-bottom:8px"><form id="cf" class="omni" style="max-width:360px;flex:1"><input type="text" name="q" value="${esc(query.q || '')}" placeholder="${t('catalog.search_in', { title: t(k.title).toLowerCase() })}" autocomplete="off" style="padding-left:12px"></form><b class="mono" data-count></b> <span class="muted">${t('catalog.items')}</span><span class="sp"></span>
        <label class="muted">${t('browser.sort_by')} <select id="csort"><option value="movie_count" ${sort === 'movie_count' ? 'selected' : ''}>${t('catalog.sort.movie_count')}</option><option value="name" ${sort === 'name' ? 'selected' : ''}>${t('catalog.sort.name')}</option></select></label></div>
      <div class="panel" data-list></div></div>`;
    return { html, mount: root => mount(root, params.kind, k, query, sort) };
  },
};

function mount(root, kind, k, query, sort) {
  const list = root.querySelector('[data-list]');
  let max = 1;
  const fetchPage = async state => {
    const body = await api('meta/catalog/' + kind, { q: query.q || '', sort, per_page: 60, page: state.page });
    const pg = pagination(body);
    if (state.page === 1 && body.data.length) max = Math.max(1, ...body.data.map(n));
    return { items: body.data || [], total: pg.total, page: state.page + 1, hasMore: state.page < (pg.last_page || 1) };
  };
  const ctl = infiniteList(list, {
    fetchPage,
    renderItems: items => items.map(x => `<div class="row-i"><div class="grow ell">${a(k.perf ? href.performers({ [k.perf]: x.name }) : href.facet(k.facet, x.name), esc(x.name))}</div><div class="bar" style="width:140px"><i style="width:${Math.max(2, n(x) / max * 100)}%"></i></div><span class="mono muted" style="width:70px;text-align:right">${fmt(n(x))}</span></div>`).join(''),
    wrapGrid: '<div data-h></div><div data-e></div><div class="brfoot" data-f></div>',
    emptyHtml: emptyBox(t('catalog.empty'), t('catalog.empty_hint')),
    countEl: root.querySelector('[data-count]'),
  });
  const go = v => { location.hash = '#/catalog/' + kind + queryString(v); };
  root.querySelector('#cf').addEventListener('input', debounce(e => go({ q: e.target.value, sort }), 500));
  root.querySelector('#cf').addEventListener('submit', e => e.preventDefault());
  root.querySelector('#csort').addEventListener('change', e => go({ q: query.q || '', sort: e.target.value }));
  return () => ctl.destroy();
}
