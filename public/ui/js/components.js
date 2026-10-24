import { esc, fmt, hueOf, initials, titleOf, queryString, $ } from './util.js';
import { ic } from './icons.js';
import { api, pagination } from './api.js';

/* ---------- links: every value is a link ---------- */
export const href = {
  movies: q => '#/movies' + queryString(q),
  movie: code => '#/movie/' + encodeURIComponent(code),
  performers: q => '#/performers' + queryString(q),
  performer: id => '#/performer/' + encodeURIComponent(id),
  search: q => '#/search' + queryString({ q }),
  facet: (kind, value) => '#/facet/' + kind + '/' + encodeURIComponent(value),
  crawl: () => '#/crawl',
};
export const a = (h, label, cls = 'lnk') => `<a href="${h}" class="${cls}">${label}</a>`;
export const facetLink = (kind, value, cls = 'lnk') => value ? a(href.facet(kind, value), esc(value), cls) : '<span class="dim">chưa có</span>';
export const facetChip = (kind, value, extra = '') => value ? a(href.facet(kind, value), esc(value), 'chip' + (extra ? ' ' + extra : '')) : '';
export const notKnown = () => '<span class="dim">chưa có</span> <span class="chip warn">chờ crawl</span>';

export const head = (t, sub = '', right = '') => `<div class="ph"><h1>${t}</h1><span class="sub">${sub}</span><span class="sp"></span>${right}</div>`;
export const crumbs = list => `<div class="crumbs">${list.map((c, i) => (i ? '<span>›</span>' : '') + (c[1] ? a(c[1], esc(c[0]), '') : `<span>${esc(c[0])}</span>`)).join('')}</div>`;
export const emptyBox = (t, sub = '', btn = '') => `<div class="empty"><div style="font-size:15px;color:var(--fg)">${t}</div><div>${sub}</div>${btn}</div>`;
export const errorBox = e => emptyBox('Không tải được dữ liệu', `<span class="err">${esc(e.message || e)}</span>`, '<button class="btn" data-act="reload">Thử lại</button>');
export const loadingBox = () => '<div class="empty"><span class="loader"><i></i><i></i><i></i></span></div>';

/* ---------- covers and avatars ---------- */
export function cover(m, { dur = false } = {}) {
  const h = hueOf(m.code);
  const img = m.cover_url ? `<img loading="lazy" referrerpolicy="no-referrer" alt="" src="${esc(m.cover_url)}" onerror="this.remove()">` : '';
  return `<div class="th" style="--h:${h}">${img}<span class="code">${esc(m.code)}</span>${dur && m.runtime_minutes ? `<span class="dur">${m.runtime_minutes}′</span>` : ''}${m.needs_review ? '<div class="bdg"><span>XEM LẠI</span></div>' : ''}</div>`;
}
export function avatar(p, cls = '') {
  const name = p.name_romaji || p.name_kanji || p.name_kana || '?';
  const img = p.image_url ? `<img loading="lazy" referrerpolicy="no-referrer" alt="" src="${esc(p.image_url)}" onerror="this.remove()">` : '';
  return `<div class="avatar ${cls}" style="--h:${hueOf(name)}">${initials(name)}${img}</div>`;
}
export const performerName = p => p.name_romaji || p.name_kanji || p.name_kana || '(chưa có tên)';

/* ---------- cards and rows ---------- */
/* codes of the movies that have a file on disk (loaded from the library); the cards show a badge for them */
export const disk = { codes: new Set() };
export function movieCard(m, i = 0) {
  const performers = (m.performers || []).slice(0, 3).map(p => a(href.performer(p.id), esc(performerName(p)))).join(', ');
  const genres = (m.genres || []).slice(0, 2).map(g => facetChip('genre', g, 'g')).join('');
  return `<div class="mc" style="--i:${i % 14}"><a href="${href.movie(m.code)}" style="display:contents">${cover(m, { dur: true })}</a>
   <div class="t ell"><a href="${href.movie(m.code)}" class="lnk" title="${esc(titleOf(m))}">${esc(titleOf(m))}</a></div>
   <div class="m">${performers || '<span class="dim">chưa có diễn viên</span>'}</div>
   <div class="chips mini">${disk.codes.has(m.code) ? '<span class="chip ok">Trên máy</span>' : ''}${genres}${(m.genres || []).length > 2 ? `<span class="chip">+${m.genres.length - 2}</span>` : ''}</div></div>`;
}
export function movieRow(m) {
  return `<tr class="row"><td class="mono">${a(href.movie(m.code), esc(m.code))}</td><td>${esc(titleOf(m))}</td>
   <td class="muted">${(m.performers || []).slice(0, 3).map(p => a(href.performer(p.id), esc(performerName(p)))).join(', ')}</td>
   <td>${facetLink('maker', m.maker)}</td><td>${facetLink('series', m.series)}</td>
   <td class="mono">${esc(m.release_date || '—')}</td><td class="mono">${m.runtime_minutes ? m.runtime_minutes + '′' : '—'}</td>
   <td class="mono">${m.community_score ?? '—'}</td><td><div class="chips mini">${(m.genres || []).slice(0, 3).map(g => facetChip('genre', g)).join('')}</div></td></tr>`;
}
export const movieHead = '<tr><th>Mã</th><th>Tên</th><th>Diễn viên</th><th>Maker</th><th>Series</th><th>Phát hành</th><th>Dài</th><th>★</th><th>Thể loại</th></tr>';

export function performerCard(p, i = 0) {
  const src = (p.sources || []).length;
  return `<div class="ac" style="--i:${i % 14}">${avatar(p)}
   <div class="grow" style="min-width:0"><div class="ell"><b><a href="${href.performer(p.id)}" class="lnk">${esc(performerName(p))}</a></b></div>
   <div class="muted ell">${esc(p.name_kanji || '')}${(p.aliases || []).length ? ' · ' + esc(p.aliases.slice(0, 2).join(', ')) : ''}</div>
   <div class="dim mono ell">${src} nguồn · #${p.id}</div></div></div>`;
}
export function performerRow(p) {
  return `<tr class="row"><td><b>${a(href.performer(p.id), esc(performerName(p)))}</b></td><td class="muted">${esc(p.name_kanji || '')}</td>
   <td class="muted">${esc((p.aliases || []).slice(0, 3).join(', '))}</td><td class="mono">${(p.sources || []).map(s => esc(s.source_slug)).join(', ')}</td>
   <td class="mono">${p.linked_title_count ?? '—'}</td></tr>`;
}
export const performerHead = '<tr><th>Tên</th><th>Kanji</th><th>Biệt danh</th><th>Nguồn</th><th>Số phim</th></tr>';

export const stat = (value, label, note = '') => `<div class="stat"><b>${value}</b><span>${label}</span>${note ? `<em>${note}</em>` : ''}</div>`;
export const kv = rows => `<dl class="kv">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>`;

/* ---------- infinite list: fetch a page, append, load the next when the footer is visible ---------- */
export function infiniteList(root, { fetchPage, renderItems, wrapGrid, emptyHtml, countEl }) {
  let state = { cursor: null, page: 1, done: false, loading: false, shown: 0, total: null, gen: 0 };
  root.innerHTML = wrapGrid;
  const host = root.querySelector('[data-h]');
  const foot = root.querySelector('[data-f]');
  const observer = new IntersectionObserver(entries => { if (entries.some(e => e.isIntersecting)) more(); }, { root: $('#view'), rootMargin: '600px' });
  observer.observe(foot);

  async function more() {
    if (state.loading || state.done) return;
    state.loading = true;
    const gen = state.gen;
    foot.innerHTML = '<span class="loader"><i></i><i></i><i></i></span>';
    try {
      const r = await fetchPage(state);
      if (gen !== state.gen) return;
      state.total = r.total ?? state.total;
      state.cursor = r.cursor ?? null;
      state.page = r.page ?? state.page + 1;
      state.done = !r.hasMore;
      if (r.items.length) host.insertAdjacentHTML('beforeend', renderItems(r.items, state.shown));
      state.shown += r.items.length;
      if (countEl) countEl.textContent = fmt(state.total ?? state.shown);
      if (!state.shown) { foot.innerHTML = ''; host.innerHTML = ''; root.querySelector('[data-e]').innerHTML = emptyHtml; }
      else foot.innerHTML = state.done ? `<span class="dim">Đã hiển thị ${fmt(state.shown)}${state.total ? ' / ' + fmt(state.total) : ''}</span>` : '';
    } catch (e) {
      if (gen === state.gen) foot.innerHTML = `<span class="err">${esc(e.message || e)}</span> <button class="btn sm" data-act="more">Thử lại</button>`;
    } finally {
      if (gen === state.gen) state.loading = false;
    }
  }
  foot.addEventListener('click', ev => { if (ev.target.closest('[data-act=more]')) more(); });
  more();
  return { destroy() { state.gen++; observer.disconnect(); } };
}

/* shared fetchers for the two lists */
export const moviePage = params => async state => {
  const body = await api('movies', { ...params, per_page: 24, ...(state.cursor ? { cursor: state.cursor } : {}) });
  const pg = pagination(body);
  return { items: body.data || [], total: pg.total, cursor: pg.next_cursor, hasMore: !!pg.has_more && (body.data || []).length > 0 };
};
export const performerPage = params => async state => {
  const body = await api('performers', { ...params, per_page: 24, page: state.page });
  const pg = pagination(body);
  const last = pg.last_page ?? 1;
  return { items: body.data || [], total: pg.total, page: state.page + 1, hasMore: state.page < last && (body.data || []).length > 0 };
};
export { ic, titleOf };
