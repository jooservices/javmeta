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
const known = x => x !== null && x !== undefined && x !== '';
/* Profile rows for the values we know; an unknown value is left out (no placeholder row, no raw field key, no crawl status). */
const rowsOf = defs => defs.filter(([, html]) => html !== null);
const text = x => (known(x) ? esc(x) : null);
const measurements = p => {
  const parts = [['B', p.bust], ['W', p.waist], ['H', p.hip]];
  const some = parts.filter(([, x]) => known(x));
  if (some.length === 3) return `<span class="mono">${some.map(([, x]) => esc(fmt(x))).join('-')}</span>`;
  return some.length ? `<span class="mono">${some.map(([k, x]) => `${k}${esc(fmt(x))}`).join(' ')}</span>` : null;
};
const ageOf = d => (known(d) && Number.isFinite(age(d)) ? age(d) : null);
const sideRows = p => rowsOf([
  [t('performer.age'), ageOf(p.birth_date) !== null ? `<span class="mono">${ageOf(p.birth_date)}</span>` : null],
  [t('performer.blood_type'), text(p.blood_type)], [t('performer.location'), text(p.location)],
  [t('performer.height'), known(p.height_cm) ? `<span class="mono">${esc(p.height_cm)} cm</span>` : null],
  [t('performer.measurements'), measurements(p)],
  [t('performer.cup'), text(p.cup)], [t('performer.debut'), text(p.debut_date)],
]);
const bioRows = p => rowsOf([
  [t('performer.bio.romaji'), text(p.name_romaji)], [t('performer.bio.kanji'), text(p.name_kanji)], [t('performer.bio.kana'), text(p.name_kana)],
  [t('performer.head.aliases'), (p.aliases || []).length ? esc(p.aliases.join(', ')) : null],
  [t('performer.birth_date'), known(p.birth_date) ? `<span class="mono">${esc(p.birth_date)}</span>${ageOf(p.birth_date) !== null ? ` <span class="dim">· ${t('performer.age_years', { count: ageOf(p.birth_date), n: ageOf(p.birth_date) })}</span>` : ''}` : null],
  [t('performer.height'), known(p.height_cm) ? `<span class="mono">${esc(p.height_cm)} cm</span>` : null],
  [t('performer.measurements'), measurements(p)],
  [t('performer.cup'), text(p.cup)], [t('performer.blood_type'), text(p.blood_type)], [t('performer.location'), text(p.location)],
  [t('performer.debut'), text(p.debut_date)], [t('performer.retired'), text(p.retired_date)],
  [t('performer.bio_text'), text(p.bio_text)],
]);
const emptyProfile = () => `<div class="dim">${t('performer.profile_empty')}</div>`;

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
      const rows = bioRows(p);
      bodyHtml = `<div class="panel"><div class="bd">${rows.length ? kv(rows) : emptyProfile()}</div></div>`;
    }
    const html = `<div class="wrap">${crumbs([[t('performers.title'), '#/performers'], [name]])}
      <div class="cols" style="grid-template-columns:minmax(0,320px) minmax(0,1fr)"><div class="col">
        <div class="panel"><div class="bd" style="display:flex;flex-direction:column;gap:12px"><div style="display:flex;gap:14px;align-items:center">${avatar(p, 'lg')}<div><h1 style="font-size:20px">${esc(name)}</h1><div style="margin-top:4px">${markBtn(marks, 'follow', 'performer', String(p.id), t('common.following'), t('common.follow'))}</div><div class="muted">${esc(p.name_kanji || '')}</div>${(p.aliases || []).length ? `<div class="dim">${t('performer.aka')} ${esc(p.aliases.slice(0, 3).join(', '))}</div>` : ''}</div></div>
          ${sideRows(p).length ? '' : emptyProfile()}${kv([...sideRows(p), [t('performer.movie_total'), `<span class="mono">${fmt(p.linked_title_count)}</span>`]])}
        </div></div></div></div>
      <div class="col">${tabsHtml}${bodyHtml}</div></div></div>`;
    return { html, mount };
  },
};
