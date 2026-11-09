import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { a, href, stat, movieCard, performerCard, errorBox } from '../components.js';
import { t } from '../i18n.js';

/* Discover for people who browse the catalog: collection numbers, what is new, who is new, what is popular, what releases today.
   Queue, worker and source-health panels live in the Command Center, not here. */
const prefersReducedMotion = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
const countUp = root => {
  if (prefersReducedMotion()) return;
  root.querySelectorAll('.cu').forEach(e => {
    const to = +e.dataset.to, t0 = performance.now();
    e.textContent = fmt(0);
    const f = now => { const k = Math.min(1, (now - t0) / 700); e.textContent = fmt(Math.round(to * (1 - Math.pow(1 - k, 3)))); if (k < 1 && document.body.contains(e)) requestAnimationFrame(f); };
    requestAnimationFrame(f);
  });
};
const cu = n => `<span class="cu" data-to="${n}">${fmt(n)}</span>`;
const suggestionChips = genres => (genres || []).filter(g => g.movie_count > 0).slice(0, 4);

export default {
  async render() {
    let summary, latest, people, genres, today;
    try {
      [summary, latest, people, genres, today] = await Promise.all([
        api('status/summary'),
        api('movies', { sort: 'update_date', per_page: 12 }),
        api('performers', { sort: 'updated_at', per_page: 6 }),
        api('meta/catalog/genres', { per_page: 12 }, { ttl: 120000 }),
        api('movies', { sort: 'release_date', per_page: 5, released_to: new Date().toISOString().slice(0, 10) }),
      ]);
    } catch (e) { return { html: `<div class="wrap">${errorBox(e)}</div>` }; }
    const s = summary.data;
    const chips = suggestionChips(genres.data);
    const html = `<div class="wrap">
      <div class="hero"><h1>${t('home.title')}</h1><form class="bigq" id="hsearch"><input type="text" id="hsq" placeholder="${t('search.placeholder')}" autocomplete="off" aria-label="${t('home.search_label')}"><button class="btn pri" type="submit">${t('common.search')}</button></form>
        ${chips.length ? `<div class="chips"><span class="dim" style="line-height:20px">${t('home.suggestions')}</span>${chips.map(g => a(href.search(g.name), esc(g.name), 'chip')).join('')}</div>` : ''}</div>
      <div class="panel"><div class="grid g6" style="gap:0">
        <div class="stat"><b>${cu(s.counts.movies)}</b><span>${t('home.stat.movies')}</span><em>${t('home.in_24h', { n: fmt(s.ingest.movies_24h) })}</em></div>
        <div class="stat"><b>${cu(s.counts.performers)}</b><span>${t('home.stat.performers')}</span><em>${t('home.in_24h', { n: fmt(s.ingest.performers_24h) })}</em></div>
        <div class="stat"><b>${cu(s.ingest.movies_1h)}</b><span>${t('home.stat.movies_hour')}</span><em>${t('home.stat.live')}</em></div></div></div>
      <div class="cols"><div class="col">
        <div class="panel"><div class="hd"><h2>${t('home.recently_updated')}</h2><span class="sp"></span>${a(href.movies({ sort: 'update_date' }), t('common.view_all'), 'btn sm ghost')}</div><div class="bd"><div class="strip">${(latest.data || []).map((m, i) => movieCard(m, i)).join('')}</div></div></div>
        <div class="panel"><div class="hd"><h2>${t('home.performers_updated')}</h2><span class="sp"></span>${a(href.performers({ sort: 'updated_at' }), t('common.view_all'), 'btn sm ghost')}</div><div class="bd"><div class="agrid S">${(people.data || []).map((p, i) => performerCard(p, i)).join('')}</div></div></div>
      </div><div class="col">
        <div class="panel"><div class="hd"><h2>${t('home.recent_releases')}</h2><span class="sp"></span>${a('#/calendar', t('home.calendar_link'), 'btn sm ghost')}</div>${(today.data || []).map(m => `<a class="row-i" href="${href.movie(m.code)}" style="text-decoration:none;color:inherit"><div class="grow ell mono">${esc(m.code)}</div><span class="muted">${esc(m.release_date || '')}</span></a>`).join('')}</div>
        <div class="panel"><div class="hd"><h2>${t('home.popular_genres')}</h2><span class="sp"></span>${a('#/catalog/genres', t('common.all_link'), 'btn sm ghost')}</div><div class="bd chips">${(genres.data || []).map(g => a(href.facet('genre', g.name), esc(g.name) + ' <small class="dim">' + fmt(g.movie_count) + '</small>', 'chip')).join('')}</div></div>
      </div></div></div>`;
    return {
      html,
      mount: root => {
        countUp(root);
        root.querySelector('#hsearch').addEventListener('submit', e => { e.preventDefault(); const q = root.querySelector('#hsq').value.trim(); if (q) location.hash = href.search(q); });
      },
    };
  },
};
