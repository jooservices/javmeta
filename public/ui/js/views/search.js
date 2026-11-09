import { esc, fmt, queryString } from '../util.js';
import { api, pagination } from '../api.js';
import { head, a, href, movieCard, performerCard, emptyBox, errorBox } from '../components.js';
import { t } from '../i18n.js';

export default {
  async render({ query }) {
    const q = (query.q || '').trim();
    const guestMode = document.body.dataset.guestMode === 'true';
    const semantic = query.mode === 'meaning';
    const modeLinks = `<div class="seg"><a class="${semantic ? '' : 'on'}" style="padding:3px 9px" href="${href.search(q)}">${t('search.mode.keyword')}</a><a class="${semantic ? 'on' : ''}" style="padding:3px 9px" href="#/search${queryString({ q, mode: 'meaning' })}">${t('search.mode.meaning')}</a></div>`;
    const form = `<form class="bigq" id="sform"><input type="text" id="sq" value="${esc(q)}" placeholder="${t('search.placeholder_describe')}" autocomplete="off" aria-label="${t('search.input_label')}"><button class="btn pri" type="submit">${t('common.search')}</button></form>`;
    if (q.length < 2) {
      let chips = [];
      try { chips = ((await api('meta/catalog/genres', { per_page: 8 }, { ttl: 120000 })).data || []).filter(g => g.movie_count > 0).slice(0, 4); } catch { /* keep fallback examples */ }
      // Prefer a recognisable Drama example when the catalog has it; otherwise keep the top genres.
      const drama = chips.find(g => String(g.name).toLowerCase() === 'drama');
      if (drama) chips = [drama, ...chips.filter(g => g !== drama)].slice(0, 4);
      else chips = [{ name: 'Drama', movie_count: 1 }, ...chips].slice(0, 4);
      const examples = `<div class="chips"><span class="dim">${t('search.examples')}</span>${chips.map(g => a(href.search(g.name), esc(g.name), 'chip')).join('')}</div>`;
      const hint = q.length === 1 ? `<p class="muted" role="status">${t('search.min_chars')}</p>` : '';
      return { html: `<div class="wrap">${head(t('search.title'), t('search.sub'))}<div class="hero">${form}${modeLinks}${examples}${hint}</div></div>`, mount: bind };
    }
    let movies, people, sg = {}, hy, scenes = [], sceneError = null;
    try {
      [hy, sg, scenes] = await Promise.all([
        api('search', { q, per_page: 18, ...(semantic ? {} : { understand: 0 }) }),
        api('suggest', { q, limit: 8 }).then(r => r.data || {}).catch(() => ({})),
        !guestMode && q.length >= 3 ? api('scenes/search', { q, per_page: 12 }).then(r => r.data || []).catch(e => { sceneError = e; return []; }) : Promise.resolve([]),
      ]);
      const movieTotal = hy.data.totals?.movies ?? (hy.data.movies || []).length;
      const performerTotal = hy.data.totals?.performers ?? (hy.data.performers || []).length;
      movies = { data: hy.data.movies, meta: { pagination: { total: movieTotal } } };
      people = { data: hy.data.performers, meta: { pagination: { total: performerTotal } } };
    } catch (e) { return { html: `<div class="wrap">${head(t('search.title'), esc(q))}${errorBox(e)}</div>` }; }
    const it = hy.data.interpretation || { filters: {}, keywords: '', by: 'none' };
    const FILTER_LABEL = ['maker', 'series', 'genre', 'actress', 'code', 'label', 'released_from', 'released_to', 'runtime_min', 'runtime_max'];
    const fallback = semantic && it.used_vectors !== true ? `<p class="muted" role="status" data-search-fallback>${t('search.fallback')}</p>` : '';
    const understood = semantic && (Object.keys(it.filters).length || it.keywords !== q) ? `<div class="chips" style="margin:6px 0"><span class="dim">${t(it.by === 'ollama' ? 'search.understood_ollama' : 'search.understood')}</span>${Object.entries(it.filters).map(([k, v]) => `<span class="chip on">${esc(FILTER_LABEL.includes(k) ? t('search.filter.' + k) : k)}: <b>${esc(v)}</b></span>`).join('')}${it.keywords ? `<span class="chip">${t('search.keywords')}: <b>${esc(it.keywords)}</b></span>` : ''}</div>` : '';
    const mt = pagination(movies).total ?? (movies.data || []).length;
    const pt = pagination(people).total ?? (people.data || []).length;
    const groups = [[t('facet.kind.genre'), 'genre', sg.genres], [t('facet.kind.maker'), 'maker', sg.makers], [t('facet.kind.series'), 'series', sg.series]].filter(g => (g[2] || []).length);
    const facets = groups.length ? `<div class="panel"><div class="hd"><h2>${t('search.matching_categories')}</h2></div><div class="bd">${groups.map(g => `<div class="chips" style="margin-bottom:6px"><span class="dim" style="width:70px">${g[0]}</span>${g[2].map(x => a(href.facet(g[1], x.name), `${esc(x.name)} <span class="mono dim">${fmt(x.movie_count)}</span>`, 'chip')).join('')}</div>`).join('')}</div></div>` : '';
    const html = `<div class="wrap">${head(t('search.results'), `“${esc(q)}”`, modeLinks)}<div class="hero">${form}${fallback}${understood}</div>${facets}
      <div class="panel"><div class="hd"><h2>${t('movies.title')}</h2><span class="chip mono" data-search-movie-total>${fmt(mt)}</span><span class="sp"></span>${mt > 18 ? a(href.movies({ q: it.keywords || q }), t('common.view_all'), 'btn sm ghost') : ''}</div><div class="bd">${(movies.data || []).length ? `<div class="mgrid">${movies.data.map((m, i) => movieCard(m, i)).join('')}</div>` : emptyBox(t('browser.empty.movies'), t(semantic ? 'search.empty_semantic' : 'catalog.empty_hint'))}</div></div>
      <div class="panel"><div class="hd"><h2>${t('performers.title')}</h2><span class="chip mono">${fmt(pt)}</span><span class="sp"></span>${pt > 8 ? a(href.performers({ q: it.keywords || q }), t('common.view_all'), 'btn sm ghost') : ''}</div><div class="bd">${(people.data || []).length ? `<div class="agrid">${people.data.map((p, i) => performerCard(p, i)).join('')}</div>` : `<span class="muted">${t('browser.empty.performers')}</span>`}</div></div></div>`;
    const sceneHtml = guestMode ? '' : `<section class="panel"><div class="hd"><h2>${t('search.scenes')}</h2><span class="chip">${scenes.length}</span></div><div class="bd">${sceneError ? errorBox(sceneError) : scenes.map(s => `<a class="row-i" href="#/player/${encodeURIComponent(s.movie_code || 'file-' + s.library_file_id)}?file=${s.library_file_id}&t=${Number(s.start_sec)}" style="overflow-wrap:anywhere">${s.thumb_url ? `<img src="${esc(s.thumb_url)}" alt="" loading="lazy" width="96">` : ''}<span>${esc(s.movie_code || t('search.file_n', { id: s.library_file_id }))} · ${Math.floor(s.start_sec / 60)}:${String(Math.floor(s.start_sec % 60)).padStart(2, '0')}<br>${esc(s.description)}</span></a>`).join('') || t('search.no_scenes')}</div></section>`;
    return { html: html.replace(/<\/div>$/, () => sceneHtml + '</div>'), mount: bind };
  },
};

function bind(root) {
  root.querySelector('#sform').addEventListener('submit', e => { e.preventDefault(); const q = root.querySelector('#sq').value.trim(); if (q) location.hash = href.search(q); });
  const input = root.querySelector('#sq'); if (input && !input.value) input.focus();
}
