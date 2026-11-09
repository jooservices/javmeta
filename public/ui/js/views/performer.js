import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { myMarks, markBtn } from '../marks.js';
import { crumbs, a, href, avatar, performerName, kv, notKnown, errorBox, emptyBox, facetLink } from '../components.js';
import { moviesBrowser } from './movies.js';
import { t } from '../i18n.js';

const age = d => {
  if (!d) return null;
  const b = new Date(d), n = new Date();
  let y = n.getFullYear() - b.getFullYear();
  if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) y--;
  return y;
};
const v = (x, fn = esc) => (x === null || x === undefined || x === '') ? notKnown() : fn(x);

export default {
  async render({ params }) {
    const body = await api('performers/' + encodeURIComponent(params.id)).catch(e => ({ error: e }));
    if (body.error) {
      if (body.error.status === 404) return { html: `<div class="wrap">${crumbs([[t('performers.title'), '#/performers'], [params.id]])}${emptyBox(t('performer.not_found'), esc(params.id), a('#/performers', t('performer.back'), 'btn'))}</div>` };
      return { html: `<div class="wrap">${errorBox(body.error)}</div>` };
    }
    const p = body.data;
    const marks = await myMarks();
    const name = performerName(p);
    const tab = new URLSearchParams(location.hash.split('?')[1] || '').get('tab') || 'movies';
    const fields = ['birth_date', 'height_cm', 'bust', 'waist', 'hip', 'cup', 'blood_type', 'bio_text', 'debut_date', 'location', 'image_url'];
    const missing = fields.filter(k => p[k] === null || p[k] === undefined || p[k] === '');
    const complete = Math.round(100 - missing.length / fields.length * 100);
    const tabs = [['movies', t('performer.tab.movies')], ['sources', t('performer.tab.sources')], ['bio', t('performer.tab.bio')]];
    const tabsHtml = `<div class="tabs">${tabs.map(tb => `<a class="tab ${tab === tb[0] ? 'on' : ''}" href="#/performer/${encodeURIComponent(p.id)}?tab=${tb[0]}">${tb[1]}${tb[0] === 'sources' ? `<i>${(p.sources || []).length}</i>` : ''}</a>`).join('')}</div>`;
    let bodyHtml = '';
    let mount = null;
    if (tab === 'movies') {
      const b = moviesBrowser({}, { fixed: { actress: name }, closed: true });
      bodyHtml = b.html; mount = b.mount;
    }
    if (tab === 'sources') {
      bodyHtml = `<div class="panel tbl-wrap"><table><thead><tr><th>${t('performer.head.sources')}</th><th>${t('performer.head.name')}</th><th>${t('performer.birth_date')}</th><th>${t('performer.source.status')}</th><th></th></tr></thead><tbody>${(p.sources || []).map(s => `<tr><td><b>${esc(s.source_slug)}</b><div class="dim mono">${esc(s.external_id || '')}</div></td><td>${esc(s.name_romaji || s.name_kanji || '')}</td><td class="mono">${esc(s.birth_date || '—')}</td><td>${s.active ? `<span class="chip ok">${t('performer.source.active')}</span>` : `<span class="chip warn">${t('performer.source.removed')}</span>`}</td><td>${s.source_url ? `<a class="btn sm" href="${esc(s.source_url)}" target="_blank" rel="noreferrer noopener">${t('common.open_page')}</a>` : ''}</td></tr>`).join('') || `<tr><td colspan="5" class="muted">${t('common.none_yet_cap')}</td></tr>`}</tbody></table></div>`;
    }
    if (tab === 'bio') {
      bodyHtml = `<div class="panel"><div class="bd">${kv([
        [t('performer.bio.romaji'), v(p.name_romaji)], [t('performer.bio.kanji'), v(p.name_kanji)], [t('performer.bio.kana'), v(p.name_kana)],
        [t('performer.head.aliases'), (p.aliases || []).length ? esc(p.aliases.join(', ')) : `<span class="dim">${t('performer.bio.no_aliases')}</span>`],
        [t('performer.birth_date'), v(p.birth_date, x => `<span class="mono">${esc(x)}</span>${age(x) !== null ? ` <span class="dim">· ${t('performer.age_years', { count: age(x), n: age(x) })}</span>` : ''}`)],
        [t('performer.height'), v(p.height_cm, x => `<span class="mono">${x} cm</span>`)],
        [t('performer.measurements'), (p.bust || p.waist || p.hip) ? `<span class="mono">${fmt(p.bust)}-${fmt(p.waist)}-${fmt(p.hip)}</span>` : notKnown()],
        [t('performer.cup'), v(p.cup)], [t('performer.blood_type'), v(p.blood_type)], [t('performer.location'), v(p.location)],
        [t('performer.debut'), v(p.debut_date)], [t('performer.retired'), p.retired_date ? esc(p.retired_date) : `<span class="dim">${t('performer.not_recorded')}</span>`],
        [t('performer.bio_text'), v(p.bio_text)],
        [t('performer.extra_attrs'), `<span class="mono" style="font-size:12px;white-space:pre-wrap">${esc(JSON.stringify(p.attrs || {}))}</span>`],
      ])}</div></div>`;
    }
    const html = `<div class="wrap">${crumbs([[t('performers.title'), '#/performers'], [name]])}
      <div class="cols" style="grid-template-columns:minmax(0,320px) minmax(0,1fr)"><div class="col">
        <div class="panel"><div class="bd" style="display:flex;flex-direction:column;gap:12px"><div style="display:flex;gap:14px;align-items:center">${avatar(p, 'lg')}<div><h1 style="font-size:20px">${esc(name)}</h1><div style="margin-top:4px">${markBtn(marks, 'follow', 'performer', String(p.id), t('common.following'), t('common.follow'))}</div><div class="muted">${esc(p.name_kanji || '')}</div>${(p.aliases || []).length ? `<div class="dim">${t('performer.aka')} ${esc(p.aliases.slice(0, 3).join(', '))}</div>` : ''}</div></div>
          ${kv([
            [t('performer.age'), p.birth_date ? `<span class="mono">${age(p.birth_date)}</span>` : notKnown()],
            [t('performer.blood_type'), v(p.blood_type)], [t('performer.location'), v(p.location)],
            [t('performer.height'), v(p.height_cm, x => `<span class="mono">${x} cm</span>`)],
            [t('performer.measurements'), (p.bust || p.waist || p.hip) ? `<span class="mono">${fmt(p.bust)}-${fmt(p.waist)}-${fmt(p.hip)}</span>` : notKnown()],
            [t('performer.cup'), v(p.cup)], [t('performer.debut'), v(p.debut_date)],
            [t('performer.movie_total'), `<span class="mono">${fmt(p.linked_title_count)}</span>`],
          ])}
          <div><div class="muted" style="display:flex;justify-content:space-between"><span>${t('performer.completeness')}</span><span class="mono">${complete}%</span></div><div class="bar ${complete < 60 ? 'warn' : 'ok'}"><i style="width:${complete}%"></i></div>${missing.length ? `<div class="dim" style="margin-top:4px">${t('performer.awaiting_crawl', { fields: missing.join(', ') })}</div>` : ''}</div></div></div></div>
      <div class="col">${tabsHtml}${bodyHtml}</div></div></div>`;
    return { html, mount };
  },
};
