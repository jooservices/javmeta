import { $, $$, esc, fmt, debounce, copyText, titleOf } from './util.js';
import { ic } from './icons.js';
import { api } from './api.js';
import { initTheme, toggleTheme } from './theme.js';
import { errorBox, loadingBox, performerName, href, avatar } from './components.js';
import home from './views/home.js';
import movies from './views/movies.js';
import movie from './views/movie.js';
import performers from './views/performers.js';
import performer from './views/performer.js';
import facet from './views/facet.js';
import crawl from './views/crawl.js';
import search from './views/search.js';
import settings from './views/settings.js';
import catalog from './views/catalog.js';
import calendar from './views/calendar.js';
import adminView from './views/admin.js';

const ROUTES = [
  [/^$/, home, 'home'], [/^home$/, home, 'home'],
  [/^search$/, search, 'search'],
  [/^movies$/, movies, 'movies'],
  [/^movie\/(.+)$/, movie, 'movies', ['code']],
  [/^performers$/, performers, 'performers'],
  [/^performer\/(.+)$/, performer, 'performers', ['id']],
  [/^facet\/([a-z]+)\/(.+)$/, facet, 'movies', ['kind', 'value']],
  [/^catalog\/([a-z]+)$/, catalog, 'catalog', ['kind']],
  [/^calendar$/, calendar, 'calendar'],
  [/^admin$/, adminView, 'admin'],
  [/^crawl$/, crawl, 'crawl'],
  [/^settings$/, settings, 'settings'],
];

const NAV = [
  ['Khám phá', [['home', 'Discover', 'home', '#/'], ['search', 'Tìm kiếm', 'search', '#/search']]],
  ['Danh mục', [['movies', 'Phim', 'film', '#/movies'], ['performers', 'Diễn viên', 'user', '#/performers'], ['catalog-genres', 'Thể loại', 'tag', '#/catalog/genres'], ['catalog-makers', 'Maker', 'building', '#/catalog/makers'], ['catalog-series', 'Series', 'folder', '#/catalog/series'], ['catalog-labels', 'Label', 'layers', '#/catalog/labels'], ['catalog-years', 'Năm', 'cal', '#/catalog/years'], ['calendar', 'Lịch phát hành', 'cal', '#/calendar']]],
  ['Công cụ', [['crawl', 'Crawl & nguồn', 'spider', '#/crawl'], ['ops', 'Vận hành (/ops)', 'chart', '/ops'], ['admin', 'Quản trị', 'gear', '#/admin'], ['settings', 'Cài đặt', 'gear', '#/settings']]],
];

let cleanup = null;
let current = null;

function parse() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, qs = ''] = raw.split('?');
  const query = Object.fromEntries(new URLSearchParams(qs).entries());
  for (const [re, view, top, names] of ROUTES) {
    const m = path.match(re);
    if (m) {
      const params = {};
      (names || []).forEach((n, i) => { params[n] = decodeURIComponent(m[i + 1]); });
      return { view, top: top === 'catalog' ? 'catalog-' + params.kind : top, params, query };
    }
  }
  return { view: null, top: '', params: {}, query };
}

function drawShell(top) {
  $('#side').innerHTML = '<div class="brand"><i>X</i>XCrawler</div>' + NAV.map(g => `<div class="nav-h">${g[0]}</div>` + g[1].map(n => `<a class="nav ${top === n[0] ? 'on' : ''}" href="${n[3]}" style="text-decoration:none">${ic(n[2])}<span>${n[1]}</span></a>`).join('')).join('') + '<div class="side-foot">JavMeta API · dữ liệu thật</div>';
  const bn = [['home', 'Discover', 'home', '#/'], ['search', 'Tìm', 'search', '#/search'], ['movies', 'Phim', 'film', '#/movies'], ['performers', 'Diễn viên', 'user', '#/performers'], ['crawl', 'Crawl', 'spider', '#/crawl']];
  $('#bnav').innerHTML = bn.map(n => `<a class="${top === n[0] ? 'on' : ''}" href="${n[3]}" style="flex:1;text-decoration:none;color:inherit;display:flex;flex-direction:column;align-items:center;gap:2px;padding:7px 0;font-size:10px">${ic(n[2])}${n[1]}</a>`).join('');
  $('.top .menu').innerHTML = ic('menu');
}

async function render() {
  if (cleanup) { try { cleanup(); } catch { /* ignore */ } cleanup = null; }
  const r = parse();
  const view = $('#view');
  drawShell(r.top);
  $('#side').classList.remove('open');
  if (!r.view) { view.innerHTML = '<div class="wrap"><div class="empty">Trang không tồn tại.<a class="btn" href="#/">Về Discover</a></div></div>'; return; }
  const token = current = {};
  view.innerHTML = loadingBox();
  try {
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
  drop.innerHTML = '<div class="og">Đang tìm…</div>';
  try {
    const { data: sg } = await api('suggest', { q, limit: 5 });
    if ($('#omq').value.trim() !== q) return;
    OM.items = [
      ...(sg.movies || []).map(x => ({ g: 'Phim', href: href.movie(x.code), label: `${x.code} · ${x.title || ''}`, sub: x.maker || '' })),
      ...(sg.performers || []).map(x => ({ g: 'Diễn viên', href: href.performer(x.id), label: x.name || `#${x.id}`, sub: `#${x.id}`, p: { id: x.id, name_romaji: x.name } })),
      ...(sg.makers || []).map(x => ({ g: 'Maker', href: href.facet('maker', x.name), label: x.name, sub: `${fmt(x.movie_count)} phim` })),
      ...(sg.series || []).map(x => ({ g: 'Series', href: href.facet('series', x.name), label: x.name, sub: `${fmt(x.movie_count)} phim` })),
      { g: 'Tìm kiếm', href: href.search(q), label: `Tìm “${q}” trong mọi thứ`, sub: 'Enter' },
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
    $('#livetxt').textContent = `${fmt(s.data.counts.movies)} phim · ${fmt(s.data.counts.performers)} diễn viên · ${ok}/${rows.length} nguồn ổn`;
  } catch { $('#livetxt').textContent = 'mất kết nối'; }
}

/* ---------- boot ---------- */
initTheme();
window.addEventListener('hashchange', render);
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
  const t = e.target.closest('[data-act]'); if (!t) return;
  const act = t.dataset.act;
  if (act === 'theme') toggleTheme();
  if (act === 'menu') $('#side').classList.toggle('open');
  if (act === 'reload') render();
  if (act === 'copy') copyText(t.dataset.v);
  if (act === 'help') { $('#dlg').innerHTML = '<h2 style="margin-bottom:8px">Phím tắt</h2><dl class="kv" style="grid-template-columns:120px 1fr"><dt><kbd>⌘K</kbd> <kbd>/</kbd></dt><dd>Tìm kiếm</dd><dt><kbd>t</kbd></dt><dd>Đổi sáng/tối</dd><dt><kbd>Esc</kbd></dt><dd>Đóng</dd></dl>'; $('#ov').classList.add('on'); }
});
$('#ov').addEventListener('click', e => { if (e.target.id === 'ov') $('#ov').classList.remove('on'); });
document.addEventListener('keydown', e => {
  const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#omq').focus(); $('#omq').select(); }
  else if (!typing && e.key === '/') { e.preventDefault(); $('#omq').focus(); }
  else if (!typing && e.key === 't') toggleTheme();
  else if (!typing && e.key === '?') document.querySelector('[data-act=help]').click();
  else if (e.key === 'Escape') { $('#ov').classList.remove('on'); }
});
render();
live();
setInterval(live, 30000);
