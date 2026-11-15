import { esc, fmt, hueOf, initials, titleOf, queryString, codeLabel, $ } from './util.js';
import { ic } from './icons.js';
import { api, pagination } from './api.js';
import { t } from './i18n.js';

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
export const facetLink = (kind, value, cls = 'lnk') => value ? a(href.facet(kind, value), esc(value), cls) : `<span class="dim">${t('common.none_yet')}</span>`;
export const facetChip = (kind, value, extra = '') => value ? a(href.facet(kind, value), esc(value), 'chip' + (extra ? ' ' + extra : '')) : '';
export const notKnown = () => `<span class="dim">${t('common.none_yet')}</span>`;

export const head = (title, sub = '', right = '') => `<div class="ph"><h1>${title}</h1><span class="sub">${sub}</span><span class="sp"></span>${right}</div>`;
export const crumbs = list => `<div class="crumbs">${list.map((c, i) => (i ? '<span>›</span>' : '') + (c[1] ? a(c[1], esc(c[0]), '') : `<span>${esc(c[0])}</span>`)).join('')}</div>`;
export const emptyBox = (title, sub = '', btn = '') => `<div class="empty"><div style="font-size:15px;color:var(--fg)">${title}</div><div>${sub}</div>${btn}</div>`;
export const errorBox = e => emptyBox(t('common.load_failed'), `<span class="err">${esc(e.message || e)}</span>`, `<button class="btn" data-act="reload">${t('common.retry')}</button>`);
export const loadingBox = () => '<div class="empty"><span class="loader"><i></i><i></i><i></i></span></div>';

/* ---------- covers and avatars ---------- */
export function cover(m, { dur = false, zoom = false, alt = '' } = {}) {
  const h = hueOf(m.code);
  const zoomable = zoom && /^https?:\/\//i.test(m.cover_url || '');
  const img = m.cover_url ? `<img loading="lazy" referrerpolicy="no-referrer" alt="${esc(alt || titleOf(m))}" src="${esc(m.cover_url)}" onerror="this.remove()">` : '';
  return `<div class="th${zoomable ? ' big' : ''}" style="--h:${h}"${zoomable ? ` data-zoom="${esc(m.cover_url)}" data-zoom-label="${esc(codeLabel(m.code))}" tabindex="0" role="button" aria-label="${t('cover.zoom_aria', { code: codeLabel(m.code) })}"` : ''}>${img}<span class="code" title="${esc(m.code)}">${esc(codeLabel(m.code))}</span>${dur && m.runtime_minutes ? `<span class="dur">${m.runtime_minutes}′</span>` : ''}${m.needs_review ? `<div class="bdg"><span>${t('cover.needs_review')}</span></div>` : ''}</div>`;
}
export function avatar(p, cls = '') {
  const name = p.name_romaji || p.name_kanji || p.name_kana || '?';
  const img = p.image_url ? `<img loading="lazy" referrerpolicy="no-referrer" alt="" src="${esc(p.image_url)}" onerror="this.remove()">` : '';
  return `<div class="avatar ${cls}" style="--h:${hueOf(name)}">${initials(name)}${img}</div>`;
}
export const performerName = p => p.name_romaji || p.name_kanji || p.name_kana || t('performer.unnamed');

/* ---------- cards and rows ---------- */
/* codes of the movies that have a file on disk (loaded from the library); the cards show a badge for them */
export const disk = { codes: new Set() };
/* the first few genres, then a "+N" chip that shows every other genre of the movie when clicked (data-act expand-genres) */
export function genreChips(genres, keep = 3, cls = '') {
  const all = genres || [];
  const extra = all.slice(keep);
  return all.slice(0, keep).map(g => facetChip('genre', g, cls)).join('')
    + (extra.length ? `<span class="chips-extra" hidden>${extra.map(g => facetChip('genre', g, cls)).join('')}</span><button type="button" class="chip more" data-act="expand-genres" aria-expanded="false" aria-label="${t('genres.expand', { count: all.length })}">+${extra.length}</button>` : '');
}
export function expandGenres(button) {
  const extra = button.parentElement?.querySelector('.chips-extra');
  if (extra) extra.hidden = false;
  button.remove();
}
export function movieCard(m, i = 0) {
  const name = titleOf(m);
  const performers = (m.performers || []).slice(0, 3).map(p => a(href.performer(p.id), esc(performerName(p)))).join(', ');
  const genres = genreChips(m.genres, 3, 'g');
  return `<div class="mc" style="--i:${i % 14}"><a href="${href.movie(m.code)}" class="mc-main">${cover(m, { dur: true, alt: name })}<span class="t ell">${esc(name)}</span></a>
   <div class="m">${performers || `<span class="dim">${t('movie.no_performers')}</span>`}</div>
   <div class="chips mini">${disk.codes.has(m.code) ? `<span class="chip ok">${t('movie.on_disk')}</span>` : ''}${genres}</div></div>`;
}
export function movieRow(m) {
  return `<tr class="row"><td class="mono">${a(href.movie(m.code), esc(codeLabel(m.code)))}</td><td>${esc(titleOf(m))}</td>
   <td class="muted">${(m.performers || []).slice(0, 3).map(p => a(href.performer(p.id), esc(performerName(p)))).join(', ')}</td>
   <td>${facetLink('maker', m.maker)}</td><td>${facetLink('series', m.series)}</td>
   <td class="mono">${esc(m.release_date || '—')}</td><td class="mono">${m.runtime_minutes ? m.runtime_minutes + '′' : '—'}</td>
   <td class="mono">${m.community_score ?? '—'}</td><td><div class="chips mini">${genreChips(m.genres, 3)}</div></td></tr>`;
}
export const movieHead = () => `<tr><th>${t('movie.head.code')}</th><th>${t('movie.head.title')}</th><th>${t('movie.head.performers')}</th><th>${t('movie.head.maker')}</th><th>${t('movie.head.series')}</th><th>${t('movie.head.released')}</th><th>${t('movie.head.length')}</th><th>★</th><th>${t('movie.head.genres')}</th></tr>`;

export function performerCard(p, i = 0) {
  const src = (p.sources || []).length;
  return `<div class="ac" style="--i:${i % 14}">${avatar(p)}
   <div class="grow" style="min-width:0"><div class="ell"><b><a href="${href.performer(p.id)}" class="lnk">${esc(performerName(p))}</a></b></div>
   <div class="muted ell">${esc(p.name_kanji || '')}${(p.aliases || []).length ? ' · ' + esc(p.aliases.slice(0, 2).join(', ')) : ''}</div>
   <div class="dim mono ell">${t('performer.card.sources', { count: src, n: src })} · #${p.id}</div></div></div>`;
}
export function performerRow(p) {
  return `<tr class="row"><td><b>${a(href.performer(p.id), esc(performerName(p)))}</b></td><td class="muted">${esc(p.name_kanji || '')}</td>
   <td class="muted">${esc((p.aliases || []).slice(0, 3).join(', '))}</td><td class="mono">${(p.sources || []).map(s => esc(s.source_slug)).join(', ')}</td>
   <td class="mono">${p.linked_title_count ?? '—'}</td></tr>`;
}
export const performerHead = () => `<tr><th>${t('performer.head.name')}</th><th>${t('performer.head.kanji')}</th><th>${t('performer.head.aliases')}</th><th>${t('performer.head.sources')}</th><th>${t('performer.head.movies')}</th></tr>`;

export const stat = (value, label, note = '') => `<div class="stat"><b>${value}</b><span>${label}</span>${note ? `<em>${note}</em>` : ''}</div>`;
export const kv = rows => `<dl class="kv">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>`;

/* ---------- infinite list: fetch a page, append, load the next when the footer is visible ---------- */
export function infiniteList(root, { fetchPage, renderItems, wrapGrid, emptyHtml, countEl, first = null, onFirst = null }) {
  let preload = first; // a first page the caller already fetched: drawn at once, no blank list while it loads
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
      const r = preload || await fetchPage(state);
      preload = null;
      if (gen !== state.gen) return;
      if (state.shown === 0 && onFirst) onFirst(r);
      state.total = r.total ?? state.total;
      state.cursor = r.cursor ?? null;
      state.page = r.page ?? state.page + 1;
      state.done = !r.hasMore;
      if (r.items.length) host.insertAdjacentHTML('beforeend', renderItems(r.items, state.shown));
      state.shown += r.items.length;
      if (countEl) countEl.textContent = fmt(state.total ?? state.shown);
      if (!state.shown) { foot.innerHTML = ''; host.innerHTML = ''; root.querySelector('[data-e]').innerHTML = emptyHtml; }
      else foot.innerHTML = state.done ? `<span class="dim">${state.total ? t('list.shown_of', { shown: fmt(state.shown), total: fmt(state.total) }) : t('list.shown', { shown: fmt(state.shown) })}</span>` : '';
    } catch (e) {
      if (gen === state.gen) foot.innerHTML = `<span class="err">${esc(e.message || e)}</span> <button class="btn sm" data-act="more">${t('common.retry')}</button>`;
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
