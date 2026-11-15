import { $, $$, esc, fmt, debounce, copyText, titleOf, codeLabel } from './util.js';
import { ic } from './icons.js';
import { initLightbox } from './lightbox.js';
import { api } from './api.js';
import { initTheme, toggleTheme } from './theme.js';
import { errorBox, loadingBox, performerName, href, avatar, disk, expandGenres } from './components.js';
import { trySoftUpdate, markRendered } from './resultsRefresh.js';
import home from './views/home.js';
import movies from './views/movies.js';
import movie from './views/movie.js';
import performers from './views/performers.js';
import performer from './views/performer.js';
import facet from './views/facet.js';
import crawl from './views/crawl.js';
import search from './views/search.js';
import settings from './views/settings.js';
import notifications from './views/notifications.js';
import report from './views/report.js';
import catalog from './views/catalog.js';
import calendar from './views/calendar.js';
import adminView from './views/admin.js';
import library from './views/library.js';
import feed from './views/feed.js';
import bio from './views/bio.js';
import stats from './views/stats.js';
import { toggleMark, loadDisk } from './marks.js';
import library2 from './views/libraryPage.js';
import downloads from './views/downloads.js';
import media from './views/media.js';
import player from './views/player.js';
import { send } from './api.js';
import { t, html as tHtml, initI18n, onLocaleChange, currentLocale } from './i18n.js';
import { switchLocale } from './locale.js';

const ROUTES = [
  [/^$/, home, 'home'], [/^home$/, home, 'home'],
  [/^search$/, search, 'search'],
  [/^movies$/, movies, 'movies'],
  [/^movie\/(.+)$/, movie, 'movies', ['code']],
  [/^performers$/, performers, 'performers'],
  [/^performer\/(.+)$/, performer, 'performers', ['id']],
  [/^facet\/([a-z]+)\/(.+)$/, facet, 'movies', ['kind', 'value']],
  [/^catalog\/([a-z_]+)$/, catalog, 'catalog', ['kind']],
  [/^calendar$/, calendar, 'calendar'],
  [/^admin$/, adminView, 'admin'],
  [/^me\/([a-z_]+)$/, library, 'me', ['kind']],
  [/^feed$/, feed, 'feed'],
  [/^library$/, library2, 'library'],
  [/^downloads$/, downloads, 'downloads'],
  [/^media$/, media, 'media'],
  [/^player\/(.+)$/, player, 'media', ['code']],
  [/^bio$/, bio, 'bio'],
  [/^stats$/, stats, 'stats'],
  [/^crawl$/, crawl, 'crawl'],
  [/^settings\/notifications$/, notifications, 'settings'],
  [/^settings$/, settings, 'settings'],
  [/^settings\/report$/, report, 'settings'],
];

/* [group key, [[id, label key, icon, href]]]: labels are translated when the shell is drawn */
const NAV = [
  ['nav.group.discover', [['home', 'nav.discover', 'home', '#/'], ['search', 'nav.search', 'search', '#/search'], ['stats', 'nav.stats', 'chart', '#/stats']]],
  ['nav.group.catalog', [['movies', 'nav.movies', 'film', '#/movies'], ['performers', 'nav.performers', 'user', '#/performers'], ['bio', 'nav.bio', 'user', '#/bio'], ['smart-new', 'nav.new', 'film', '#/movies?sort=update_date'], ['catalog-genres', 'nav.genres', 'tag', '#/catalog/genres'], ['catalog-makers', 'nav.makers', 'building', '#/catalog/makers'], ['catalog-series', 'nav.series', 'folder', '#/catalog/series'], ['catalog-labels', 'nav.labels', 'layers', '#/catalog/labels'], ['catalog-years', 'nav.years', 'cal', '#/catalog/years'], ['calendar', 'nav.calendar', 'cal', '#/calendar']]],
  ['nav.group.mine', [['me-favourite', 'nav.favourite', 'tag', '#/me/favourite'], ['me-watch_later', 'nav.watch_later', 'cal', '#/me/watch_later'], ['me-seen', 'nav.seen', 'film', '#/me/seen'], ['feed', 'nav.feed', 'user', '#/feed'], ['library', 'nav.library', 'layers', '#/library'], ['downloads', 'nav.downloads', 'disk', '#/downloads'], ['media', 'nav.media', 'film', '#/media']]],
  ['nav.group.tools', [['crawl', 'nav.crawl', 'spider', '#/crawl'], ['ops', 'nav.ops', 'chart', document.querySelector('meta[name=command-center-url]')?.content || '/ops'], ['admin', 'nav.admin', 'gear', '#/admin'], ['settings', 'nav.settings', 'gear', '#/settings']]],
];

let cleanup = null;
let current = null;
const guestMode = document.body.dataset.guestMode === 'true';
const OWNER_ONLY_NAV_IDS = new Set(['me-favourite', 'me-watch_later', 'me-seen', 'feed', 'library', 'downloads', 'media', 'ops', 'admin', 'settings']);

function guestLoginPrompt(page) {
  const pageNames = { admin: 'nav.admin', settings: 'nav.settings', library: 'nav.library', downloads: 'nav.downloads', media: 'nav.media', feed: 'nav.feed', personal: 'guest.page.personal' };
  const label = pageNames[page] ? t(pageNames[page]) : page;
  return `<div class="wrap"><section class="panel guest-login-prompt"><div class="bd"><h1>${t('guest.prompt.title')}</h1><p>${t('guest.prompt.body', { page: label })}</p><a class="btn pri" href="/login">${t('guest.prompt.login')}</a></div></section></div>`;
}

function isOwnerPage(route) {
  return ['admin', 'settings', 'library', 'downloads', 'media', 'feed'].includes(route.top)
    || route.top.startsWith('me-');
}

function parse() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, qs = ''] = raw.split('?');
  const query = Object.fromEntries(new URLSearchParams(qs).entries());
  for (const [re, view, top, names] of ROUTES) {
    const m = path.match(re);
    if (m) {
      const params = {};
      (names || []).forEach((n, i) => { params[n] = decodeURIComponent(m[i + 1]); });
      return { view, top: top === 'catalog' ? 'catalog-' + params.kind : top === 'me' ? 'me-' + params.kind : top, params, query };
    }
  }
  return { view: null, top: '', params: {}, query };
}

function drawShell(top) {
  const groups = guestMode
    ? NAV.map(([label, items]) => [label, items.filter(item => !OWNER_ONLY_NAV_IDS.has(item[0]))])
      .filter(group => group[1].length)
    : NAV;
  $('#side').innerHTML = '<div class="brand"><i>X</i>XCrawler</div>' + groups.map(g => `<div class="nav-h">${t(g[0])}</div>` + g[1].map(n => `<a class="nav ${top === n[0] ? 'on' : ''}" href="${n[3]}" style="text-decoration:none">${ic(n[2])}<span>${t(n[1])}</span></a>`).join('')).join('') + `<div class="side-foot">${t('nav.footer')}</div>`;
  const bn = guestMode
    ? [['home', 'nav.discover', 'home', '#/'], ['search', 'nav.search_short', 'search', '#/search'], ['movies', 'nav.movies', 'film', '#/movies'], ['performers', 'nav.performers', 'user', '#/performers']]
    : [['home', 'nav.discover', 'home', '#/'], ['search', 'nav.search_short', 'search', '#/search'], ['movies', 'nav.movies', 'film', '#/movies'], ['me-favourite', 'nav.favourite', 'tag', '#/me/favourite'], ['settings', 'nav.settings', 'gear', '#/settings']];
  $('#bnav').innerHTML = bn.map(n => `<a class="${top === n[0] ? 'on' : ''}" href="${n[3]}">${ic(n[2])}${t(n[1])}</a>`).join('');
  $('.top .menu').innerHTML = ic('menu');
  drawLangSwitch();
}

/* the VI | EN switch in the top bar */
function drawLangSwitch() {
  const host = $('#langsw');
  if (!host) return;
  host.innerHTML = ['vi', 'en'].map(code => `<button type="button" class="${currentLocale() === code ? 'on' : ''}" data-act="locale" data-v="${code}" aria-pressed="${currentLocale() === code}" lang="${code}" title="${t('locale.name.' + code)}">${code.toUpperCase()}</button>`).join('');
}

/* static markup in index.html: data-i18n (text) and data-i18n-placeholder / -aria-label / -title (attributes) */
function applyStaticI18n() {
  $$('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
  for (const attr of ['placeholder', 'aria-label', 'title']) {
    $$(`[data-i18n-${attr}]`).forEach(el => el.setAttribute(attr, t(el.getAttribute(`data-i18n-${attr}`))));
  }
}

async function render() {
  if (cleanup) { try { cleanup(); } catch { /* ignore */ } cleanup = null; }
  markRendered(); // soft query updates apply only to the route that is on screen
  const r = parse();
  const view = $('#view');
  drawShell(r.top);
  setSideOpen(false);
  const token = current = {};
  if (guestMode && isOwnerPage(r)) {
    const page = r.top.startsWith('me-') || r.top === 'feed' ? 'personal' : r.top;
    view.innerHTML = guestLoginPrompt(page);
    return;
  }
  if (!r.view) { view.innerHTML = `<div class="wrap"><div class="empty">${t('app.not_found')}<a class="btn" href="#/">${t('app.back_discover')}</a></div></div>`; return; }
  view.innerHTML = loadingBox();
  try {
    await loadDisk(disk);
    const out = await r.view.render({ params: r.params, query: r.query });
    if (token !== current) return;
    view.innerHTML = out.html;
    view.scrollTop = 0;
    if (out.mount) { const c = out.mount(view, r); if (typeof c === 'function') cleanup = c; }
  } catch (e) {
    if (token === current) view.innerHTML = `<div class="wrap">${errorBox(e)}</div>`;
  }
}

/* ---------- omnibox ---------- */
const OM = { items: [], i: 0 };
async function omniDraw() {
  const q = $('#omq').value.trim();
  const drop = $('#odrop');
  if (q.length < 2) { drop.hidden = true; return; }
  drop.hidden = false;
  drop.innerHTML = `<div class="og">${t('common.searching')}</div>`;
  try {
    const { data: sg } = await api('suggest', { q, limit: 5 });
    if ($('#omq').value.trim() !== q) return;
    OM.items = [
      ...(sg.movies || []).map(x => ({ g: t('omni.group.movies'), href: href.movie(x.code), label: `${codeLabel(x.code)} · ${x.title || ''}`, sub: x.maker || '' })),
      ...(sg.performers || []).map(x => ({ g: t('omni.group.performers'), href: href.performer(x.id), label: x.name || `#${x.id}`, sub: `#${x.id}`, p: { id: x.id, name_romaji: x.name } })),
      ...(sg.makers || []).map(x => ({ g: t('omni.group.makers'), href: href.facet('maker', x.name), label: x.name, sub: t('omni.movie_count', { count: x.movie_count, n: fmt(x.movie_count) }) })),
      ...(sg.series || []).map(x => ({ g: t('omni.group.series'), href: href.facet('series', x.name), label: x.name, sub: t('omni.movie_count', { count: x.movie_count, n: fmt(x.movie_count) }) })),
      { g: t('omni.group.search'), href: href.search(q), label: tHtml('omni.search_all', { q }), sub: 'Enter' },
    ];
    OM.i = 0;
    let last = '';
    drop.innerHTML = OM.items.map((x, i) => `${x.g !== last ? `<div class="og">${last = x.g}</div>` : ''}<a class="oi ${i === OM.i ? 'on' : ''}" href="${x.href}" data-i="${i}" style="text-decoration:none;color:inherit">${x.p ? avatar(x.p, 'sm') : ''}<span class="t">${esc(x.label)}</span><span class="sub">${esc(x.sub)}</span></a>`).join('');
  } catch (e) { drop.innerHTML = `<div class="og err">${esc(e.message)}</div>`; }
}
function omniClose() { $('#odrop').hidden = true; }
function omniMove(d) {
  if (!OM.items.length) return;
  OM.i = (OM.i + d + OM.items.length) % OM.items.length;
  $$('#odrop .oi').forEach((e, i) => e.classList.toggle('on', i === OM.i));
}

/* ---------- live pill ---------- */
async function live() {
  try {
    const [s, src] = await Promise.all([api('status/summary'), api('status/sources')]);
    const rows = src.data.filter(x => x.enabled);
    const ok = rows.filter(x => x.last_success_at && (Date.now() - new Date(x.last_success_at)) < 1800000).length;
    $('#livetxt').textContent = t('live.text', { movies: fmt(s.data.counts.movies), performers: fmt(s.data.counts.performers), ok, total: rows.length });
  } catch { $('#livetxt').textContent = t('live.offline'); }
}

/* ---------- boot ---------- */
initTheme();
await initI18n(); // before the first render: the catalogs and <html lang> are ready
applyStaticI18n();
onLocaleChange(() => { applyStaticI18n(); omniClose(); live(); render(); });
/* back/forward or a link to the same list with other filters: the list view refreshes its results in place;
   anything else (another route, a first load, a view without a soft handler) renders the route once */
const navigate = () => { if (!trySoftUpdate()) render(); };
window.addEventListener('hashchange', navigate);
window.addEventListener('popstate', navigate);
$('#omq').addEventListener('input', debounce(omniDraw, 250));
$('#omq').addEventListener('keydown', e => {
  if (e.key === 'ArrowDown') { e.preventDefault(); omniMove(1); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); omniMove(-1); }
  else if (e.key === 'Enter') {
    e.preventDefault();
    const open = !$('#odrop').hidden;
    const x = open ? OM.items[OM.i] : null;
    const q = $('#omq').value.trim();
    omniClose(); $('#omq').blur(); $('#omq').value = '';
    if (x) location.hash = x.href.replace(/^#/, ''); else if (q) location.hash = href.search(q).slice(1);
  }
  else if (e.key === 'Escape') { omniClose(); $('#omq').blur(); }
});
document.addEventListener('click', e => {
  if (!e.target.closest('#omni')) omniClose();
  const oi = e.target.closest('#odrop .oi'); if (oi) { omniClose(); $('#omq').value = ''; }
  const mk = e.target.closest('[data-mark]'); if (mk) { toggleMark(mk); return; }
  const dlb = e.target.closest('[data-dl]'); if (dlb) { dlb.disabled = true; send('POST', '/ui-me/downloads', { code: dlb.dataset.dl }).then(() => { dlb.textContent = t('movie.download_added'); }).catch(err => { dlb.textContent = err.message; dlb.disabled = false; }); return; }
  const actEl = e.target.closest('[data-act]'); if (!actEl) return;
  const act = actEl.dataset.act;
  if (act === 'theme') toggleTheme();
  if (act === 'menu') setSideOpen(!$('#side').classList.contains('open'));
  if (act === 'reload') render();
  if (act === 'copy') copyText(actEl.dataset.v);
  if (act === 'expand-genres') expandGenres(actEl);
  if (act === 'help') openHelp();
  if (act === 'locale') switchLocale(actEl.dataset.v);
});
$('#sidescrim')?.addEventListener('click', () => setSideOpen(false));
function setSideOpen(open) {
  $('#side')?.classList.toggle('open', open);
  const scrim = $('#sidescrim');
  if (scrim) scrim.hidden = !open;
}
$('#ov').addEventListener('click', e => {
  if (e.target.closest('[data-dlg-close]')) { $('#ov').classList.remove('on'); return; }
  if (e.target.id !== 'ov') return;
  const cancel = document.querySelector('#dlg [data-confirm-no], #dlg [data-dlg-close]');
  if (cancel) cancel.click();
  else $('#ov').classList.remove('on');
});
document.addEventListener('keydown', e => {
  const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#omq').focus(); $('#omq').select(); }
  else if (!typing && e.key === '/') { e.preventDefault(); $('#omq').focus(); }
  else if (!typing && e.key === 't') toggleTheme();
  else if (!typing && e.key === '?') document.querySelector('[data-act=help]').click();
  else if (e.key === 'Escape') { $('#ov').classList.remove('on'); }
});
function shortcutMod() {
  return /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '') ? '⌘' : 'Ctrl';
}

function openHelp() {
  const mod = shortcutMod();
  const dlg = $('#dlg');
  dlg.setAttribute('role', 'dialog');
  dlg.setAttribute('aria-modal', 'true');
  dlg.setAttribute('aria-labelledby', 'help-title');
  dlg.innerHTML = `<h2 id="help-title" style="margin-bottom:8px">${t('help.title')}</h2><dl class="kv" style="grid-template-columns:120px 1fr"><dt><kbd>${mod}+K</kbd> <kbd>/</kbd></dt><dd>${t('help.search')}</dd><dt><kbd>t</kbd></dt><dd>${t('help.theme')}</dd><dt><kbd>Esc</kbd></dt><dd>${t('help.close')}</dd><dt><kbd>?</kbd></dt><dd>${t('help.shortcuts')}</dd></dl><button type="button" class="btn" data-dlg-close>${t('common.close')}</button>`;
  $('#ov').classList.add('on');
  dlg.querySelector('[data-dlg-close]')?.focus();
}

const omniKbd = document.querySelector('.omni kbd');
if (omniKbd) omniKbd.textContent = shortcutMod() + 'K';

initLightbox();
render();
live();
setInterval(live, 30000);
