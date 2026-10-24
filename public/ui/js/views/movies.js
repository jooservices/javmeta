import { esc, fmt, queryString, $, debounce } from '../util.js';
import { head, crumbs, emptyBox, movieCard, movieRow, movieHead, infiniteList, moviePage, ic } from '../components.js';

export const SORTS = [['release_date', 'Mới phát hành'], ['update_date', 'Mới cập nhật'], ['rating', 'Điểm cao'], ['relevance', 'Liên quan (cần từ khoá)']];
const FIELDS = ['q', 'genre', 'actress', 'maker', 'series', 'label', 'year', 'from', 'to', 'rmin', 'rmax', 'censored', 'sort'];

/* the URL uses friendly names; the API gets its own parameter names */
export function toApi(query) {
  const p = {};
  ['q', 'genre', 'actress', 'maker', 'series', 'label', 'censored', 'sort'].forEach(k => { if (query[k]) p[k] = query[k]; });
  if (query.year) { p.released_from = query.year + '-01-01'; p.released_to = query.year + '-12-31'; }
  else {
    if (query.from) p.released_from = query.from + '-01-01';
    if (query.to) p.released_to = query.to + '-12-31';
  }
  if (query.rmin) p.runtime_min = query.rmin;
  if (query.rmax) p.runtime_max = query.rmax;
  return p;
}

const prefs = () => { try { return JSON.parse(localStorage.getItem('xc-list') || '{}'); } catch { return {}; } };
const savePrefs = p => { try { localStorage.setItem('xc-list', JSON.stringify(p)); } catch { /* ignore */ } };

const field = (name, label, value, ph = '', type = 'text') => `<label class="f">${label}<input type="${type}" name="${name}" value="${esc(value || '')}" placeholder="${esc(ph)}"></label>`;

/* A filter panel + list of movies; `fixed` filters (from a facet or a performer page) are applied and hidden. */
export function moviesBrowser(query, { fixed = {}, apiExtra = {}, closed = false } = {}) {
  const pr = prefs();
  const mode = pr.mode || 'grid';
  const size = pr.size || 'M';
  const open = !closed && innerWidth > 900;
  const sort = query.sort || (query.q ? 'relevance' : 'release_date');
  const side = `<form id="mf" autocomplete="off">
    <details open><summary>Từ khoá</summary><div class="fb">${field('q', 'Tên, mã, diễn viên', query.q, 'ít nhất 2 ký tự')}</div></details>
    <details open><summary>Phân loại</summary><div class="fb">
      ${fixed.genre ? '' : field('genre', 'Thể loại', query.genre, 'ví dụ Drama')}
      ${fixed.maker ? '' : field('maker', 'Maker (hãng)', query.maker)}
      ${fixed.series ? '' : field('series', 'Series', query.series)}
      ${fixed.label ? '' : field('label', 'Label', query.label)}
      ${fixed.actress ? '' : field('actress', 'Diễn viên', query.actress)}</div></details>
    <details><summary>Thời gian</summary><div class="fb">
      ${fixed.year ? '' : `<div class="two">${field('from', 'Từ năm', query.from, '2020', 'number')}${field('to', 'Đến năm', query.to, '2026', 'number')}</div>`}
      <div class="two">${field('rmin', 'Dài ≥ (phút)', query.rmin, '', 'number')}${field('rmax', 'Dài ≤', query.rmax, '', 'number')}</div>
      <label class="f">Kiểm duyệt<select name="censored"><option value="">Tất cả</option><option value="censored" ${query.censored === 'censored' ? 'selected' : ''}>Có che</option><option value="uncensored" ${query.censored === 'uncensored' ? 'selected' : ''}>Không che</option></select></label></div></details>
    <div class="fb"><button class="btn sm" type="reset" data-act="clearfilters">Xoá bộ lọc</button></div></form>`;
  const chips = FIELDS.filter(k => k !== 'sort' && query[k] && !fixed[k]).map(k => `<button class="chip on" data-act="dropfilter" data-k="${k}">${esc(k)}: <b>${esc(query[k])}</b><span class="x">×</span></button>`).join('');
  const html = `<div class="br" data-key="movies"><div class="brbar"><button class="btn ${open ? 'on' : ''}" data-act="toggleside">${ic('filter')} Bộ lọc</button><b class="mono" data-count></b> <span class="muted">phim</span>
    <span class="sp"></span><label class="muted">Xếp theo <select id="sortsel">${SORTS.map(s => `<option value="${s[0]}" ${sort === s[0] ? 'selected' : ''}>${s[1]}</option>`).join('')}</select></label>
    <div class="seg">${mode === 'grid' ? ['S', 'M', 'L'].map(s => `<button class="${size === s ? 'on' : ''}" data-act="size" data-v="${s}">${s}</button>`).join('') : ''}<button class="${mode === 'grid' ? 'on' : ''}" data-act="mode" data-v="grid" title="Lưới">${ic('grid')}</button><button class="${mode === 'table' ? 'on' : ''}" data-act="mode" data-v="table" title="Bảng">${ic('list')}</button></div></div>
    <div class="parsed">${chips ? '<span class="dim" style="font-size:11px">Đang lọc</span>' + chips : ''}</div>
    <div class="brmain ${open ? '' : 'nof'}"><div class="brscrim" data-act="toggleside"></div><aside class="brside panel"><button class="btn sm brclose" data-act="toggleside">Đóng bộ lọc</button>${side}</aside><div data-list></div></div></div>`;
  return { html, mount: root => mountBrowser(root, query, fixed, apiExtra, sort, mode, size) };
}

function mountBrowser(root, query, fixed, apiExtra, sort, mode, size) {
  const apiFixed = Object.fromEntries(Object.entries(fixed).filter(([k]) => k !== 'year'));
  const base = { ...toApi(query), ...apiExtra, ...apiFixed, sort };
  const list = root.querySelector('[data-list]');
  const count = root.querySelector('[data-count]');
  const grid = mode === 'grid'
    ? `<div class="mgrid ${size}" data-h></div><div data-e></div><div class="brfoot" data-f></div>`
    : `<div class="panel tbl-wrap"><table><thead>${movieHead}</thead><tbody data-h></tbody></table></div><div data-e></div><div class="brfoot" data-f></div>`;
  const ctl = infiniteList(list, {
    fetchPage: moviePage(base),
    renderItems: (items, offset) => items.map((m, i) => mode === 'grid' ? movieCard(m, offset + i) : movieRow(m)).join(''),
    wrapGrid: grid,
    emptyHtml: emptyBox('Không có phim khớp', 'Nới điều kiện hoặc bỏ bớt bộ lọc.', '<button class="btn" data-act="clearfilters">Xoá bộ lọc</button>'),
    countEl: count,
  });

  const go = values => { location.hash = '#/' + (location.hash.slice(2).split('?')[0] || 'movies') + queryString(values); };
  const read = () => {
    const f = Object.fromEntries(new FormData(root.querySelector('#mf')).entries());
    const out = { ...query };
    ['q', 'genre', 'maker', 'series', 'label', 'actress', 'from', 'to', 'rmin', 'rmax', 'censored'].forEach(k => { if (k in f) out[k] = f[k]; });
    out.sort = root.querySelector('#sortsel').value;
    return out;
  };
  const form = root.querySelector('#mf');
  form.addEventListener('input', debounce(() => go(read()), 600));
  form.addEventListener('submit', e => { e.preventDefault(); go(read()); });
  root.querySelector('#sortsel').addEventListener('change', () => go(read()));
  root.addEventListener('click', e => {
    const t = e.target.closest('[data-act]'); if (!t) return;
    const act = t.dataset.act;
    if (act === 'toggleside') { root.querySelector('.brmain').classList.toggle('nof'); t.closest('.br').querySelector('.brbar .btn').classList.toggle('on'); }
    if (act === 'mode') { savePrefs({ ...prefs(), mode: t.dataset.v }); location.reload(); }
    if (act === 'size') { savePrefs({ ...prefs(), size: t.dataset.v }); location.reload(); }
    if (act === 'dropfilter') { const q = { ...query }; delete q[t.dataset.k]; go(q); }
    if (act === 'clearfilters') { e.preventDefault(); go({}); }
  });
  return () => ctl.destroy();
}

export default {
  async render({ query }) {
    const b = moviesBrowser(query);
    return { html: `<div class="wrap">${head('Phim', 'toàn bộ phim đã crawl, cuộn để tải thêm')}${b.html}</div>`, mount: b.mount };
  },
};
