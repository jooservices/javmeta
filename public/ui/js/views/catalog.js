import { esc, fmt, debounce, queryString } from '../util.js';
import { api, pagination } from '../api.js';
import { head, a, href, emptyBox, infiniteList } from '../components.js';

export const KINDS = {
  genres: { title: 'Thể loại', facet: 'genre', unit: 'phim' },
  makers: { title: 'Maker (hãng)', facet: 'maker', unit: 'phim' },
  series: { title: 'Series', facet: 'series', unit: 'phim' },
  labels: { title: 'Label', facet: 'label', unit: 'phim' },
  years: { title: 'Năm phát hành', facet: 'year', unit: 'phim' },
};

/* a list of every genre, maker, series, label or year with its number of movies; each value is a link to its page */
export default {
  async render({ params, query }) {
    const k = KINDS[params.kind];
    if (!k) return { html: `<div class="wrap">${emptyBox('Danh mục không tồn tại', esc(params.kind), a('#/movies', 'Về danh sách phim', 'btn'))}</div>` };
    const sort = query.sort || (params.kind === 'years' ? 'name' : 'movie_count');
    const html = `<div class="wrap">${head(k.title, 'đếm theo số phim')}
      <div class="brbar" style="margin-bottom:8px"><form id="cf" class="omni" style="max-width:360px;flex:1"><input type="text" name="q" value="${esc(query.q || '')}" placeholder="Tìm trong ${esc(k.title.toLowerCase())}…" autocomplete="off" style="padding-left:12px"></form><b class="mono" data-count></b> <span class="muted">mục</span><span class="sp"></span>
        <label class="muted">Xếp theo <select id="csort"><option value="movie_count" ${sort === 'movie_count' ? 'selected' : ''}>Nhiều phim</option><option value="name" ${sort === 'name' ? 'selected' : ''}>Tên</option></select></label></div>
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
    if (state.page === 1 && body.data.length) max = Math.max(1, ...body.data.map(x => x.movie_count));
    return { items: body.data || [], total: pg.total, page: state.page + 1, hasMore: state.page < (pg.last_page || 1) };
  };
  const ctl = infiniteList(list, {
    fetchPage,
    renderItems: items => items.map(x => `<div class="row-i"><div class="grow ell">${a(href.facet(k.facet, x.name), esc(x.name))}</div><div class="bar" style="width:140px"><i style="width:${Math.max(2, x.movie_count / max * 100)}%"></i></div><span class="mono muted" style="width:70px;text-align:right">${fmt(x.movie_count)}</span></div>`).join(''),
    wrapGrid: '<div data-h></div><div data-e></div><div class="brfoot" data-f></div>',
    emptyHtml: emptyBox('Không có mục nào', 'Thử từ khoá khác.'),
    countEl: root.querySelector('[data-count]'),
  });
  const go = v => { location.hash = '#/catalog/' + kind + queryString(v); };
  root.querySelector('#cf').addEventListener('input', debounce(e => go({ q: e.target.value, sort }), 500));
  root.querySelector('#cf').addEventListener('submit', e => e.preventDefault());
  root.querySelector('#csort').addEventListener('change', e => go({ q: query.q || '', sort: e.target.value }));
  return () => ctl.destroy();
}
