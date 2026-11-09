import assert from 'node:assert/strict';
import { api } from '../public/ui/js/api.js';
import { movieKeys, performerKeys, movieParams, performerParams, toggleValue, removeFilter, chipsHtml, activeFilters } from '../public/ui/js/filterState.js';
import { relaxationSuggestions, suggestionsHtml } from '../public/ui/js/facets.js';
import { facetListHtml, mountFacetList, facetParams, patchFor, selectedOf } from '../public/ui/js/facetList.js';
import { softNavigate, bindQuerySoftUpdate, refreshResultsRegion, markRendered, trySoftUpdate } from '../public/ui/js/resultsRefresh.js';
import { optionsHtml, performerNames, mountPicker } from '../public/ui/js/pickers.js';
import { savedItemsHtml, parseSaved, mountSavedSearches } from '../public/ui/js/savedSearches.js';
import { searchBrowser } from '../public/ui/js/searchBrowser.js';
import { initI18n } from '../public/ui/js/i18n.js';

await initI18n('vi'); // the shell loads the catalog before rendering

const movieQuery = Object.fromEntries(movieKeys.map(k => [k, '1']));
Object.assign(movieQuery, { q: 'Movie', actress_id: '1,2', genre: 'Drama,School', source: 'javdb,javbus', has_cover: '0', released_from: '2020', released_to: '2025' });
const mp = movieParams(movieQuery);
assert.equal(mp.released_from, '2020-01-01'); assert.equal(mp.released_to, '2025-12-31');
for (const k of movieKeys.filter(k => !k.startsWith('released_'))) assert.equal(mp[k], movieQuery[k], k + ' forwarded');
const performerQuery = Object.fromEntries(performerKeys.map(k => [k, '1']));
Object.assign(performerQuery, { q: 'Name', cup: 'D,E', blood_type: 'O,A', debut_from: '2020', debut_to: '2026', has_bio: '0' });
assert.deepEqual(performerParams(performerQuery), performerQuery);
assert.equal(toggleValue('1,2', '1'), '2'); assert.equal(toggleValue('1,2', '3'), '1,2,3');
assert.deepEqual(removeFilter({ genre: 'A,B', genre_all: '1' }, 'genre', 'A'), { genre: 'B', genre_all: '1' });
assert.deepEqual(removeFilter({ genre: 'A', genre_all: '1' }, 'genre', 'A'), { genre: '' });
assert.equal(activeFilters({ has_cover: '0', score_min: '0' }).length, 2);
assert.match(chipsHtml({ actress_id: '92,93' }, { 92: 'Eimi <b>x</b>' }), /Diễn viên: Eimi &lt;b&gt;x&lt;\/b&gt;/);
assert.match(chipsHtml({ actress_id: '92,93' }), /data-value="93"/);
// FacetList: one component for every facet. The static shell is a labelled fieldset with search, sort, pager and the min-count toggle.
const genreShell = facetListHtml({ kind: 'movies', facetGroup: 'genres', filterKey: 'genre', query: { genre: 'Drama,Missing', genre_all: '1' }, genreAllToggle: true });
assert.match(genreShell, /<fieldset[^>]*data-facet-list="genres"[^>]*>\s*<legend>Thể loại/); assert.match(genreShell, /value="Drama" checked/); assert.match(genreShell, /value="Missing" checked/);
for (const hook of ['data-fl-search', 'data-fl-sort', 'data-fl-prev', 'data-fl-next', 'data-fl-min', 'data-fl-all checked', 'aria-live="polite"', 'role="status"']) assert.ok(genreShell.includes(hook), hook);
assert.ok(!facetListHtml({ kind: 'movies', facetGroup: 'makers', filterKey: 'maker', query: {} }).includes('data-fl-all'), 'only genre has the all-of toggle');
assert.ok(!facetListHtml({ kind: 'movies', facetGroup: 'genres', filterKey: 'genre', query: { genre: evilName() } }).includes('<img src=x'));
function evilName() { return '<img src=x onerror=alert(1)>'; }
assert.deepEqual(selectedOf('year', { released_from: '2025', released_to: '2025' }), ['2025']); assert.deepEqual(selectedOf('year', { released_from: '2024', released_to: '2025' }), []);
assert.deepEqual(selectedOf('year', { released_from: '2025-01-01', released_to: '2025-12-31' }), ['2025'], 'canonical year bounds remain checked');
// A tick sends the value in double quotes (exact match); a typed keyword stays as it is (substring search).
assert.deepEqual(patchFor('genre', { genre: 'A' }, 'B', true), { genre: 'A,"B"' }); assert.deepEqual(patchFor('genre', { genre: '"A","B"' }, 'A', false), { genre: '"B"' });
assert.deepEqual(patchFor('maker', { maker: '"M"' }, 'N', true), { maker: '"N"' }); assert.deepEqual(patchFor('maker', { maker: '"M"' }, 'M', false), { maker: '' });
assert.deepEqual(selectedOf('genre', { genre: '"Anal","Anal Toy"' }), ['Anal', 'Anal Toy'], 'quotes are not shown as part of the value');
assert.deepEqual(selectedOf('maker', { maker: 'Anal' }), ['Anal'], 'a typed keyword is read as it is');
assert.match(activeFilters({ genre: '"Anal"' })[0].label, /: Anal$/, 'chips show the value without quotes');
assert.deepEqual(patchFor('year', {}, '2025', true), { released_from: '2025', released_to: '2025' });
assert.deepEqual(patchFor('location', { location: 'Tokyo' }, 'Osaka', true), { location: 'Tokyo,Osaka' }, 'location accepts several values');
// the request: scope without the list's own filter, keyword for the page search, never the page sort/paging
assert.deepEqual(facetParams({ facetGroup: 'genres', filterKey: 'genre', scope: { genre: 'A', genre_all: '1', maker: 'M', q: 'night', sort: 'rating', per_page: 24, cursor: 'x' }, q: ' ab ', page: 2, sort: 'name', min: false }),
  { genre: 'A', genre_all: '1', maker: 'M', keyword: 'night', facet: 'genres', q: 'ab', page: 2, per_page: 20, sort: 'name', min_count: 0 });
assert.deepEqual(facetParams({ facetGroup: 'genres', filterKey: 'genre', scope: { genre: 'A', maker: 'M' } }), { maker: 'M', facet: 'genres', q: '', page: 1, per_page: 20, sort: 'count', min_count: 3 });
assert.deepEqual(facetParams({ facetGroup: 'years', filterKey: 'year', scope: { released_from: '2025-01-01', released_to: '2025-12-31', genre: 'A', q: 'a' } }), { genre: 'A', facet: 'years', q: '', page: 1, per_page: 20, sort: 'count', min_count: 3 });
const browser = searchBrowser('movies', movieQuery);
const facetKeys = ['maker', 'series', 'label', 'genre_all'];
for (const key of movieKeys.filter(k => !['sort', 'genre', 'actress_id', 'source', ...facetKeys].includes(k))) assert.ok(browser.html.includes('name="' + key + '"'), key);
for (const group of ['genres', 'makers', 'series', 'labels', 'years', 'sources']) assert.ok(browser.html.includes('data-facet-list="' + group + '"'), group + ' is a FacetList');
for (const key of facetKeys) assert.ok(!browser.html.includes('name="' + key + '"'), key + ' is picked in a FacetList, not typed');
assert.ok(!browser.html.includes('data-top='), 'the old tag rows are gone'); assert.ok(browser.html.includes('data-fl-all'), 'genre has the all-of toggle');
for (const text of ['role="combobox"', 'aria-expanded="false"', 'role="listbox"', 'đóng chung', 'Đang lọc', 'data-results-error', 'Lưu bộ lọc', 'Bộ lọc đã lưu', 'data-page-size', 'data-act="mode"', 'data-act="size"']) assert.ok(browser.html.includes(text), text);
const performerBrowser = searchBrowser('performers', performerQuery);
for (const key of performerKeys.filter(k => !['sort', 'cup', 'blood_type', 'source', 'location', 'status'].includes(k))) assert.ok(performerBrowser.html.includes('name="' + key + '"'), key);
assert.match(performerBrowser.html, /Số đo/);
for (const group of ['cups', 'blood_types', 'locations', 'sources', 'status']) assert.ok(performerBrowser.html.includes('data-facet-list="' + group + '"'), group + ' is a FacetList');
for (const key of ['has_image', 'has_bio']) for (const value of ['', '0', '1']) assert.ok(performerBrowser.html.includes('value="' + value + '"'));
const evil = '<img src=x onerror=alert(1)>';
assert.ok(!searchBrowser('movies', { q: evil, genre: evil, actress: evil }).html.includes(evil));
assert.ok(!optionsHtml([{ id: 1, name: evil, movie_count: 4 }], 0).includes(evil));
assert.match(optionsHtml([{ id: 1, name: 'A', movie_count: 4 }], 0), /4 phim/);
assert.deepEqual(await performerNames('1'), { 1: 'Aaliyah Love' });
const rows = parseSaved([{ kind: 'saved_search', subject: 'movies', key: JSON.stringify({ name: evil, query: 'q=%3Cb%3E&genre=Drama' }) }, { kind: 'saved_search', subject: 'movies', key: 'broken' }, { kind: 'saved_search', subject: 'performers', key: '{}' }], 'movies');
assert.equal(rows.length, 1); assert.ok(!savedItemsHtml(rows, 'movies').includes(evil)); assert.match(savedItemsHtml(rows, 'movies'), /q=%3Cb%3E&amp;genre=Drama/);
const originalFetch = globalThis.fetch;
let requests = [];
globalThis.fetch = async (url, opt) => { requests.push({ url, opt }); return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ data: { total: url.includes('q=empty') ? 0 : 9 } }) }; };
const relaxed = await relaxationSuggestions('movies', { q: 'empty', genre: 'Drama', maker: 'M', has_cover: '1' }, movieParams, new AbortController().signal);
assert.equal(requests.length, 3); assert.equal(relaxed.length, 1); assert.equal(relaxed[0].key, 'q');
assert.match(suggestionsHtml(relaxed, 'movies'), /Thử bỏ: Từ khoá: empty \(\+9 phim\)/);
assert.ok(!suggestionsHtml([{ label: evil, total: 8 }], 'performers').includes(evil));

// Small event targets exercise mounted behavior without a DOM library or a browser login.
class Node {
  constructor() { this.listeners = {}; this.dataset = {}; this.attrs = {}; this.innerHTML = ''; this.textContent = ''; this.value = ''; this.hidden = true; this.classList = { add() {}, remove() {} }; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter(f => f !== fn); }
  emit(type, event = {}) { for (const fn of this.listeners[type] || []) fn({ target: this, preventDefault() {}, ...event }); }
  setAttribute(k, v) { this.attrs[k] = v; }
  removeAttribute(k) { delete this.attrs[k]; }
  querySelector() { return null; }
  focus() { this.focused = true; }
}
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const input = new Node(), box = new Node(), pickerRoot = new Node();
pickerRoot.querySelector = s => s === '[data-performer-input]' ? input : box;
const pickerLife = new AbortController(); let picked;
globalThis.fetch = originalFetch;
const stop = mountPicker(pickerRoot, '', id => { picked = id; }, pickerLife.signal);
requests = [];
globalThis.fetch = async (url, opt) => { requests.push({ url, opt }); return originalFetch(url, opt); };
input.value = 'E'; input.emit('input'); await delay(270); assert.equal(requests.length, 0, 'one character does not request');
input.value = 'Ei'; input.emit('input'); await delay(100); input.value = 'Eim'; input.emit('input'); await delay(270);
assert.equal(requests.length, 1, 'picker debounces'); assert.match(requests[0].url, /q=Eim/); assert.match(box.innerHTML, /Eimi &lt;b&gt;x&lt;\/b&gt;/);
input.emit('keydown', { key: 'ArrowDown' }); assert.equal(input.attrs['aria-activedescendant'], 'performer-option-0');
input.emit('keydown', { key: 'Enter' }); assert.equal(picked, '92'); assert.equal(input.focused, true); assert.equal(input.attrs['aria-expanded'], 'false');
input.value = 'Ei'; input.emit('input'); await delay(270); input.emit('keydown', { key: 'Escape' }); assert.equal(box.hidden, true);
input.value = 'Ei'; input.emit('input'); stop(); pickerLife.abort(); await delay(270); assert.equal(requests.length, 2, 'unmount cancels pending debounce');

// FacetList mounted on small event targets: request shape, debounce, kept state, pinned selection, pager, error with kept list, deferral.
const jsonOk = body => ({ ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => body });
const facetHost = (group, key, details = null) => {
  const els = Object.fromEntries(['[data-fl-search]', '[data-fl-sort]', '[data-fl-error]', '[data-fl-list]', '[data-fl-pin]', '[data-fl-rest]', '[data-fl-empty]', '[data-fl-prev]', '[data-fl-next]', '[data-fl-range]', '[data-fl-total]', '[data-fl-min]', '[data-fl-min-text]'].map(k => [k, new Node()]));
  els['[data-fl-list]'].contains = () => false; els['[data-fl-min]'].checked = true;
  els['[data-fl-pin]'].contains = () => false; els['[data-fl-pin]'].appended = []; els['[data-fl-pin]'].append = l => els['[data-fl-pin]'].appended.push(l);
  const host = new Node(); host.dataset = { facetList: group, key }; host.els = els; host.ticked = []; host.writes = 0;
  Object.defineProperty(host, 'innerHTML', { get: () => '', set: () => { host.writes++; } });
  host.querySelector = sel => els[sel] || null; host.querySelectorAll = () => host.ticked; host.closest = () => details;
  return host;
};
let flRequests = [];
globalThis.fetch = (url, opt) => { flRequests.push({ url: decodeURIComponent(url), opt }); return originalFetch(url, opt); };
const flLife = new AbortController(), flChanges = [];
let flScope = { genre: 'Drama', q: 'night', sort: 'rating' };
const fl = facetHost('genres', 'genre');
const flList = mountFacetList(fl, { kind: 'movies', facetGroup: 'genres', filterKey: 'genre', query: { genre: 'Drama' }, scopeParams: () => flScope, onChange: p => flChanges.push(p), signal: flLife.signal });
await delay(0);
assert.equal(flRequests.length, 1);
for (const part of ['/ui-api/movies/facets/values?', 'facet=genres', 'page=1', 'per_page=20', 'sort=count', 'min_count=3', 'keyword=night']) assert.ok(flRequests[0].url.includes(part), part);
assert.ok(!flRequests[0].url.includes('genre='), 'a list never filters by its own value'); assert.ok(!flRequests[0].url.includes('sort=rating'), 'the movie sort is not the facet sort');
assert.match(fl.els['[data-fl-pin]'].innerHTML, /value="Drama" checked[\s\S]*12/); assert.equal(fl.els['[data-fl-pin]'].hidden, false);
assert.match(fl.els['[data-fl-rest]'].innerHTML, /&lt;b&gt;School&lt;\/b&gt;/); assert.ok(!fl.els['[data-fl-rest]'].innerHTML.includes('<b>School</b>')); assert.ok(!fl.els['[data-fl-rest]'].innerHTML.includes('value="Drama"'), 'a selected value is pinned, not repeated');
assert.match(fl.els['[data-fl-range]'].textContent, /1–2 \/ 2/); assert.match(fl.els['[data-fl-min-text]'].textContent, /1 mục/); assert.equal(fl.els['[data-fl-next]'].disabled, true); assert.equal(fl.els['[data-fl-prev]'].disabled, true);
assert.equal(fl.els['[data-fl-list]'].attrs['aria-busy'], 'false');
const flSearch = fl.els['[data-fl-search]'];
flSearch.value = 's'; flSearch.emit('input'); flSearch.value = 'sch'; flSearch.emit('input'); await delay(100);
assert.equal(flRequests.length, 1, 'typing waits 250 ms'); await delay(200);
assert.equal(flRequests.length, 2, 'typing is one request'); assert.ok(flRequests[1].url.includes('q=sch')); assert.match(fl.els['[data-fl-range]'].textContent, /1–1 \/ 1/);
fl.els['[data-fl-sort]'].value = 'name'; fl.els['[data-fl-sort]'].emit('change'); await delay(0);
assert.equal(flRequests.length, 3); assert.ok(flRequests[2].url.includes('sort=name') && flRequests[2].url.includes('q=sch'), 'sort keeps the typed search');
fl.els['[data-fl-min]'].checked = false; fl.els['[data-fl-min]'].emit('change'); await delay(0);
assert.ok(flRequests[3].url.includes('min_count=0') && flRequests[3].url.includes('sort=name') && flRequests[3].url.includes('q=sch'), 'the min-count toggle keeps the rest');
const clickLabel = {}; let stopped = false;
fl.els['[data-fl-list]'].emit('change', { target: { matches: sel => sel === '[data-fl-value]', closest: () => clickLabel, checked: true, value: 'School' }, stopPropagation() { stopped = true; } });
assert.deepEqual(flChanges, [{ genre: 'Drama,"School"' }], 'a tick is sent in quotes (exact match)'); assert.equal(fl.els['[data-fl-pin]'].appended[0], clickLabel, 'the clicked row moves into the pinned group'); assert.equal(stopped, true);
await delay(300); assert.equal(flRequests.length, 4, 'a tick does not reload the list it came from');
fl.ticked = [{ value: 'Drama' }, { value: 'School' }]; flList.update({ query: { genre: 'Drama,School' } }); await delay(300);
assert.equal(flRequests.length, 4, 'a parent refresh with the same scope does not reload'); assert.equal(fl.writes, 0, 'the fieldset itself is never re-rendered');
flScope = { ...flScope, maker: 'Maker One' }; flList.update({ query: { genre: 'Drama,School' } }); await delay(100);
assert.equal(flRequests.length, 4, 'a scope change is debounced'); await delay(200);
assert.equal(flRequests.length, 5); assert.ok(/maker=Maker(\+| )One/.test(flRequests[4].url) && flRequests[4].url.includes('q=sch') && flRequests[4].url.includes('sort=name'), 'a new scope keeps typed search, sort and toggle');
// an error keeps the last good list and offers a retry
const goodRest = fl.els['[data-fl-rest]'].innerHTML;
globalThis.fetch = async () => ({ ok: false, status: 500, redirected: false, headers: { get: () => 'application/json' }, json: async () => ({ detail: 'boom' }) });
flList.update({ scopeParams: { genre: 'Drama' } }); await delay(300);
assert.equal(fl.els['[data-fl-error]'].hidden, false); assert.match(fl.els['[data-fl-error]'].innerHTML, /boom[\s\S]*data-fl-retry/); assert.equal(fl.els['[data-fl-rest]'].innerHTML, goodRest, 'the last good list stays');
globalThis.fetch = (url, opt) => { flRequests.push({ url: decodeURIComponent(url), opt }); return originalFetch(url, opt); };
const beforeRetry = flRequests.length;
fl.els['[data-fl-error]'].emit('click', { target: { closest: sel => (sel === '[data-fl-retry]' ? {} : null) } }); await delay(0);
assert.equal(flRequests.length, beforeRetry + 1); assert.equal(fl.els['[data-fl-error]'].hidden, true);
// destroy aborts a pending request
let pendingSignal;
globalThis.fetch = (url, opt) => { pendingSignal = opt.signal; return new Promise((resolve, reject) => opt.signal.addEventListener('abort', () => reject(Object.assign(new Error('cancelled'), { name: 'AbortError' })))); };
fl.els['[data-fl-sort]'].value = 'count'; fl.els['[data-fl-sort]'].emit('change'); await delay(0);
assert.equal(fl.els['[data-fl-list]'].attrs['aria-busy'], 'true'); flList.destroy(); assert.equal(pendingSignal.aborted, true); await delay(0);
flLife.abort();
// pager: from-to / total, page 2 and 3, clamp when the scope shrinks
const many = total => async () => jsonOk({ data: { items: Array.from({ length: 20 }, (_, i) => ({ name: 'G' + i, count: 9 })), total, hidden_below_min: 0, facet: 'genres' } });
flRequests = [];
globalThis.fetch = (url, opt) => { flRequests.push({ url: decodeURIComponent(url), opt }); return many(45)(); };
const pg2 = facetHost('genres', 'genre'); const pgLife = new AbortController();
const pgList = mountFacetList(pg2, { facetGroup: 'genres', filterKey: 'genre', query: {}, scopeParams: () => ({}), signal: pgLife.signal }); await delay(0);
assert.match(pg2.els['[data-fl-range]'].textContent, /1–20 \/ 45/); assert.equal(pg2.els['[data-fl-prev]'].disabled, true); assert.equal(pg2.els['[data-fl-next]'].disabled, false);
pg2.els['[data-fl-next]'].emit('click'); await delay(0); assert.ok(flRequests.at(-1).url.includes('page=2')); assert.match(pg2.els['[data-fl-range]'].textContent, /21–40 \/ 45/);
pg2.els['[data-fl-next]'].emit('click'); await delay(0); assert.match(pg2.els['[data-fl-range]'].textContent, /41–45 \/ 45/); assert.equal(pg2.els['[data-fl-next]'].disabled, true);
pg2.els['[data-fl-next]'].emit('click'); await delay(0); assert.equal(flRequests.length, 3, 'next on the last page does nothing');
globalThis.fetch = (url, opt) => { flRequests.push({ url: decodeURIComponent(url), opt }); return many(5)(); };
pgList.update({ scopeParams: () => ({ maker: 'M' }) }); await delay(300);
assert.ok(flRequests.at(-2).url.includes('page=3') && flRequests.at(-1).url.includes('page=1'), 'a page past the new last page falls back to the last page');
assert.match(pg2.els['[data-fl-range]'].textContent, /1–5 \/ 5/); pgList.destroy(); pgLife.abort();
// a list inside a closed <details> loads when the section opens
globalThis.fetch = (url, opt) => { flRequests.push({ url: decodeURIComponent(url), opt }); return originalFetch(url, opt); };
let toggle; const section = { open: false, addEventListener: (type, fn) => { if (type === 'toggle') toggle = fn; } };
const closedHost = facetHost('years', 'year', section); const closedLife = new AbortController(); flRequests = [];
const closedList = mountFacetList(closedHost, { kind: 'movies', facetGroup: 'years', filterKey: 'year', query: { released_from: '2025', released_to: '2025' }, scopeParams: () => ({ released_from: '2025-01-01', released_to: '2025-12-31' }), signal: closedLife.signal }); await delay(20);
assert.equal(flRequests.length, 0, 'a closed section does not load'); assert.match(facetListHtml({ facetGroup: 'years', filterKey: 'year', query: { released_from: '2025', released_to: '2025' } }), /value="2025" checked/, 'the selection shows in the shell even before the list loads');
section.open = true; toggle(); await delay(0);
assert.equal(flRequests.length, 1); assert.ok(flRequests[0].url.includes('facet=years') && !flRequests[0].url.includes('released_from'), 'the year list ignores its own range'); closedList.destroy(); closedLife.abort();
globalThis.fetch = originalFetch;

// Catalogs are reused for two minutes; owner changes always use send() with CSRF.
globalThis.fetch = originalFetch;
const { fillCatalogs } = await import('../public/ui/js/pickers.js');
const catalogInput = new Node(), catalogList = new Node(); catalogInput.dataset.catalog = 'makers'; catalogInput.getAttribute = () => 'catalog-maker';
const catalogRoot = { querySelectorAll: () => [catalogInput], querySelector: () => catalogList };
let catalogCalls = 0;
globalThis.fetch = (...args) => { catalogCalls++; return originalFetch(...args); };
await fillCatalogs(catalogRoot, new AbortController().signal); await fillCatalogs(catalogRoot, new AbortController().signal);
assert.equal(catalogCalls, 1); assert.match(catalogList.innerHTML, /40/);
const savedNodes = Object.fromEntries(['[data-saved-list]', '[data-save-form]', '[data-saved-error]', '[data-save-search]', '[data-save-cancel]'].map(k => [k, new Node()]));
const submit = new Node(); savedNodes['[data-save-form]'].querySelector = () => submit;
savedNodes['[data-save-form]'].elements = { search_name: Object.assign(new Node(), { value: 'My search' }) };
const savedRoot = { querySelector: s => savedNodes[s] };
let savedRows = [], writes = [];
globalThis.document.querySelector = () => ({ content: 'csrf-test' });
globalThis.document.addEventListener ||= () => {};
globalThis.document.removeEventListener ||= () => {};
globalThis.fetch = async (url, opt = {}) => {
  if (opt.method === 'POST') { const body = JSON.parse(opt.body); writes.push({ body, headers: opt.headers }); savedRows = body.on ? [{ ...body }] : []; }
  return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ data: savedRows }) };
};
const confirmYes = new Node(), confirmNo = new Node(), confirmDlg = new Node(), confirmOv = new Node();
confirmDlg.querySelector = sel => sel.includes('confirm-yes') ? confirmYes : confirmNo;
confirmDlg.getAttribute = () => null; confirmDlg.removeAttribute = () => {};
globalThis.document.getElementById = id => (id === 'dlg' ? confirmDlg : confirmOv);
const savedLife = new AbortController(); mountSavedSearches(savedRoot, 'movies', { actress_id: '1,2', genre: 'Drama' }, savedLife.signal); await delay(0);
savedNodes['[data-save-search]'].emit('click'); assert.equal(savedNodes['[data-save-form]'].hidden, false);
savedNodes['[data-save-form]'].emit('submit'); await delay(0);
assert.equal(writes.length, 1); assert.equal(writes[0].headers['X-CSRF-TOKEN'], 'csrf-test');
assert.deepEqual(JSON.parse(writes[0].body.key), { name: 'My search', query: 'actress_id=1%2C2&genre=Drama' });
assert.match(savedNodes['[data-saved-list]'].innerHTML, /#\/movies\?actress_id=1%2C2&amp;genre=Drama/);
const deleteButton = Object.assign(new Node(), { dataset: { deleteSearch: '0' } });
savedNodes['[data-saved-list]'].emit('click', { target: { closest: s => s === '[data-delete-search]' ? deleteButton : null } });
confirmYes.emit('click'); await delay(0);
assert.equal(writes[1].body.on, false); assert.match(savedNodes['[data-saved-list]'].innerHTML, /Chưa có/);
savedLife.abort(); globalThis.document.querySelector = () => null; globalThis.document.getElementById = () => null; globalThis.fetch = originalFetch;
globalThis.innerWidth = 375;
assert.match(searchBrowser('movies', {}).html, /brmain nof/);
assert.match(searchBrowser('performers', {}).html, /brmain nof/);
globalThis.innerWidth = 1280;
console.log('Search filters: parameter, rendering, escaping, debounce, keyboard, cancellation, caching and saved-search interaction assertions passed');

// Mount a whole browser and drive its soft address changes, facet lists and result refreshes (no route render, no panel re-render).
const RealFormData = globalThis.FormData;
const browserNodes = Object.fromEntries([
  '[data-list]', '[data-relax]', '[data-results]', '[data-results-error]', '[data-count]', '.brmain', '[data-filter-form]', '[data-sort]', '[data-page-size]',
  '[data-performer-input]', '[role=listbox]', '[data-active]', '[data-performer-chips]', '[data-score]', '.brbar [data-act=toggleside]',
  '[data-saved-list]', '[data-save-form]', '[data-saved-error]', '[data-save-search]', '[data-save-cancel]',
].map(s => [s, new Node()]));
const listNodes = Object.fromEntries(['[data-h]', '[data-f]', '[data-e]'].map(s => [s, new Node()]));
listNodes['[data-h]'].insertAdjacentHTML = (where, html) => { listNodes['[data-h]'].innerHTML += html; };
browserNodes['[data-list]'].querySelector = s => listNodes[s];
const classes = new Set();
browserNodes['.brmain'].classList = { contains: k => classes.has(k), toggle: k => classes.has(k) ? classes.delete(k) : classes.add(k) };
browserNodes['.brbar [data-act=toggleside]'].classList.toggle = () => {};
const regionClasses = new Set(), region = browserNodes['[data-results]'];
region.style = {}; region.classList = { toggle: (k, on) => (on ? regionClasses.add(k) : regionClasses.delete(k)), add() {}, remove() {} };
region.querySelector = () => browserNodes['[data-results-error]'];
browserNodes['[data-sort]'].value = 'release_date';
browserNodes['[data-save-form]'].elements = { search_name: new Node() };
browserNodes['[data-save-form]'].querySelector = () => new Node();
const genreHost = facetHost('genres', 'genre'), yearHost = facetHost('years', 'year');
const browserRoot = new Node(); browserRoot.querySelector = s => browserNodes[s]; browserRoot.querySelectorAll = s => (s === '[data-facet-list]' ? [genreHost, yearHost] : []);
let formValues = {};
globalThis.FormData = class { constructor() { return Object.entries(formValues)[Symbol.iterator](); } };
const pushes = [];
globalThis.history = { state: null, pushState: (_s, _t, url) => { pushes.push(['push', url]); location.hash = url.slice(url.indexOf('#')); }, replaceState: (_s, _t, url) => { pushes.push(['replace', url]); location.hash = url.slice(url.indexOf('#')); } };
requests = [];
globalThis.fetch = (url, opt) => { const entry = { url: decodeURIComponent(url), opt }; requests.push(entry); return originalFetch(url, opt).then(r => { entry.done = true; return r; }); };
location.hash = '#/movies?actress_id=1%2C2&genre=Drama%2CSchool&has_cover=0'; markRendered();
const dispose = searchBrowser('movies', { actress_id: '1,2', genre: 'Drama,School', has_cover: '0' }).mount(browserRoot);
await delay(0);
assert.match(listNodes['[data-h]'].innerHTML, /AAA-001/);
assert.match(browserNodes['[data-active]'].innerHTML, /Diễn viên: Aaliyah Love/);
assert.match(browserNodes['[data-performer-chips]'].innerHTML, /data-value="2"/);
assert.ok(requests.some(r => r.url.includes('/ui-api/movies?') && r.url.includes('actress_id=1,2') && r.url.includes('has_cover=0')));
const facetRequest = group => requests.filter(r => r.url.includes('/ui-api/movies/facets/values?') && r.url.includes('facet=' + group));
assert.equal(facetRequest('genres').length, 1); assert.equal(facetRequest('years').length, 1);
assert.ok(facetRequest('genres')[0].url.includes('actress_id=1,2') && !facetRequest('genres')[0].url.includes('genre=Drama'), 'genre counts ignore the genre filter itself');
assert.ok(facetRequest('years')[0].url.includes('genre=Drama,School'), 'other lists count inside the genre filter');
assert.equal(regionClasses.has('is-refreshing'), false);
const click = dataset => browserRoot.emit('click', { target: { closest: () => ({ dataset }) } });
const tick = (host, value, checked) => host.els['[data-fl-list]'].emit('change', { target: { matches: s => s === '[data-fl-value]', closest: () => ({}), checked, value }, stopPropagation() {} });
const listRequests = () => requests.filter(r => r.url.includes('/ui-api/movies?'));
const before = listRequests().length;
listNodes['[data-h]'].innerHTML = '';
click({ drop: 'actress_id', value: '1' });
assert.match(location.hash, /actress_id=2/); assert.match(location.hash, /genre=Drama%2CSchool/); assert.equal(pushes.length, 1, 'one history entry, no route render');
assert.equal(trySoftUpdate(), true, 'the soft update is already applied: app.js does not render the route');
assert.equal(regionClasses.has('is-refreshing'), true); assert.equal(region.attrs['aria-busy'], 'true', 'results are dimmed while they load');
await delay(120); assert.equal(listRequests().length, before, 'the refresh waits 250 ms before it asks');
await delay(300); assert.equal(listRequests().length, before + 1); assert.ok(listRequests().at(-1).url.includes('actress_id=2'));
assert.match(listNodes['[data-h]'].innerHTML, /AAA-001/, 'the list is drawn again');
assert.equal(regionClasses.has('is-refreshing'), false); assert.equal(region.attrs['aria-busy'], 'false');
assert.equal(genreHost.writes, 0); assert.equal(yearHost.writes, 0); assert.equal(browserNodes['[data-filter-form]'].innerHTML, '', 'the facet panel and the form are not re-rendered');
assert.ok(facetRequest('genres').length >= 2 && facetRequest('genres').at(-1).url.includes('actress_id=2'), 'lists recount for the new scope');
tick(genreHost, 'Drama', false); assert.match(location.hash, /genre=School/); assert.ok(!/genre=Drama/.test(location.hash));
tick(yearHost, '2025', true); assert.match(location.hash, /released_from=2025/); assert.match(location.hash, /released_to=2025/);
assert.equal(pushes.length, 3);
formValues = { actress: 'Alias', runtime_min: '90' };
browserNodes['[data-filter-form]'].emit('input', { target: { name: 'actress' } });
const beforeDebounce = location.hash; await delay(100); assert.equal(location.hash, beforeDebounce);
await delay(200); assert.match(location.hash, /actress=Alias/); assert.match(location.hash, /runtime_min=90/);
await delay(40); assert.ok(listRequests().at(-1).url.includes('actress=Alias'), 'a typed filter refreshes at once after its 250 ms pause');
click({ act: 'clearfilters' }); assert.equal(location.hash, '#/movies');
// browser back: same route, other query -> handled in place; another route -> the caller renders it
location.hash = '#/movies?genre=Drama'; assert.equal(trySoftUpdate(), true); await delay(300);
assert.ok(listRequests().at(-1).url.includes('genre=Drama')); assert.match(genreHost.els['[data-fl-pin]'].innerHTML, /value="Drama" checked/, 'the lists mirror the address');
location.hash = '#/performers'; assert.equal(trySoftUpdate(), false);
markRendered('#/movies?genre=Drama'); location.hash = '#/movies?genre=Drama';
dispose(); assert.ok(requests.filter(r => r.opt?.signal && !r.done).every(r => r.opt.signal.aborted), 'navigation aborts every pending GET');
location.hash = '#/movies?genre=Other'; assert.equal(trySoftUpdate(), false, 'a closed view has no soft handler');
globalThis.FormData = RealFormData; globalThis.fetch = originalFetch;
console.log('Search browser: soft filter changes, results-only refresh, surviving facet panel, back/forward, clear and cleanup assertions passed');

// resultsRefresh helper
const seen = [];
const unbind = bindQuerySoftUpdate(q => seen.push(q));
location.hash = '#/movies'; markRendered(); pushes.length = 0;
assert.equal(softNavigate('movies', { genre: 'A' }), true); assert.equal(location.hash, '#/movies?genre=A'); assert.deepEqual(seen, [{ genre: 'A' }]); assert.equal(pushes.at(-1)[0], 'push');
assert.equal(softNavigate('movies', { genre: 'A' }), false, 'the same address does nothing'); assert.equal(seen.length, 1);
softNavigate('movies', { genre: 'B' }, { replace: true }); assert.equal(pushes.at(-1)[0], 'replace');
assert.equal(softNavigate('performers', {}), false); assert.equal(location.hash, '#/performers', 'another route falls back to a normal address change'); assert.equal(seen.length, 2);
unbind(); markRendered('#/movies'); location.hash = '#/movies'; assert.equal(softNavigate('movies', { genre: 'C' }), false); assert.equal(location.hash, '#/movies?genre=C', 'without a handler the route renders');
location.hash = '#/performer/1?tab=movies'; markRendered();
const detailQueries = [];
const unbindDetail = bindQuerySoftUpdate(q => detailQueries.push(q), ['genre', 'actress']);
location.hash = '#/performer/1?tab=bio';
assert.equal(trySoftUpdate(), false, 'an embedded movie list does not swallow a performer tab change');
assert.deepEqual(detailQueries, []);
unbindDetail();
const area = new Node(), slot = new Node(), areaClasses = new Set(); area.style = {}; area.offsetHeight = 321; area.classList = { toggle: (k, on) => (on ? areaClasses.add(k) : areaClasses.delete(k)), add() {}, remove() {} }; area.querySelector = () => slot;
let loads = 0, failNext = false; const drawn = [], pinned = [], loadSignals = [];
const abortError = () => Object.assign(new Error('cancelled'), { name: 'AbortError' });
const refresher = refreshResultsRegion(area, { delay: 30, load: async sig => { loads++; loadSignals.push(sig); pinned.push(area.style.minHeight); await delay(25); if (sig.aborted) throw abortError(); if (failNext) throw new Error('offline'); return loads; }, render: d => drawn.push(d) });
refresher.run(); refresher.run(); refresher.run();
assert.equal(area.attrs['aria-busy'], 'true'); assert.equal(areaClasses.has('is-refreshing'), true); await delay(120);
assert.equal(loads, 1, 'three quick changes are one request'); assert.deepEqual(drawn, [1]); assert.equal(area.attrs['aria-busy'], 'false'); assert.equal(area.style.minHeight, '', 'the pinned height is released'); assert.deepEqual(pinned, ['321px'], 'the height is pinned while loading');
refresher.run({ immediate: true }); await delay(10); refresher.run({ immediate: true }); await delay(80);
assert.equal(loadSignals[1].aborted, true, 'a newer run aborts the older request'); assert.deepEqual(drawn, [1, 3], 'only the newest result is drawn');
failNext = true; refresher.run({ immediate: true }); await delay(60);
assert.equal(slot.hidden, false); assert.match(slot.innerHTML, /offline[\s\S]*data-results-retry/); assert.deepEqual(drawn, [1, 3], 'an error keeps the last good result'); assert.equal(area.attrs['aria-busy'], 'false');
failNext = false; area.emit('click', { target: { closest: s => (s === '[data-results-retry]' ? {} : null) } }); await delay(60);
assert.deepEqual(drawn, [1, 3, 5]); assert.equal(slot.hidden, true);
refresher.run({ immediate: true }); refresher.destroy(); await delay(60); assert.deepEqual(drawn, [1, 3, 5], 'destroy stops a pending refresh');

// movie genres: three, then "+N" that expands to all of them
const { movieCard, movieRow, expandGenres } = await import('../public/ui/js/components.js');
const many5 = { code: 'G-1', title_en: 'T', genres: ['A', 'B', 'C', 'D', 'E'], performers: [] };
const cardHtml = movieCard(many5);
assert.match(cardHtml, /genre\/A[\s\S]*genre\/B[\s\S]*genre\/C[\s\S]*<span class="chips-extra" hidden>[\s\S]*genre\/D[\s\S]*genre\/E[\s\S]*data-act="expand-genres"[^>]*>\+2</);
assert.ok(!/data-act="expand-genres"/.test(movieCard({ ...many5, genres: ['A', 'B', 'C'] })), 'three genres need no "+N"');
assert.match(movieRow(many5), /data-act="expand-genres"[^>]*>\+2</);
const extra = { hidden: true }; let removed = false;
expandGenres({ parentElement: { querySelector: s => (s === '.chips-extra' ? extra : null) }, remove: () => { removed = true; } });
assert.equal(extra.hidden, false); assert.equal(removed, true);

// catalog: search as you type, sort, script index, min-count toggle, soft refresh
const catalogView = (await import('../public/ui/js/views/catalog.js')).default;
const genresPage = await catalogView.render({ params: { kind: 'genres' }, query: {} });
for (const script of ['all', 'latin', 'ja', 'zh', 'other']) assert.ok(genresPage.html.includes(`data-script="${script}"`), script);
assert.match(genresPage.html, /id="cmin" checked/, 'genres hide the long tail by default');
assert.ok(!(await catalogView.render({ params: { kind: 'makers' }, query: {} })).html.includes('id="cmin" checked'));
assert.ok(!(await catalogView.render({ params: { kind: 'years' }, query: {} })).html.includes('data-script'), 'years have no script index');
const zhPage = (await catalogView.render({ params: { kind: 'genres' }, query: { script: 'zh', min_count: '0', sort: 'name' } })).html;
assert.match(zhPage, /data-script="zh" class="on" aria-pressed="true"/); assert.ok(!zhPage.includes('id="cmin" checked')); assert.match(zhPage, /<option value="name" selected>/);
const catNodes = Object.fromEntries(['[data-list]', '[data-results]', '[data-results-error]', '#cf input[name=q]', '#csort', '#cmin', '[data-min-text]', '[data-count]', '#cf'].map(k => [k, new Node()]));
catNodes['[data-results]'].style = {}; catNodes['[data-results]'].classList = { toggle() {}, add() {}, remove() {} }; catNodes['[data-results]'].querySelector = () => catNodes['[data-results-error]'];
catNodes['[data-list]'].querySelector = () => new Node(); catNodes['#csort'].value = 'movie_count';
const scriptBtn = Object.assign(new Node(), { dataset: { script: 'ja' }, classList: { toggle() {} } });
const catRoot = new Node(); catRoot.querySelector = s => catNodes[s]; catRoot.querySelectorAll = s => (s === '[data-script]' ? [scriptBtn] : []);
requests = []; pushes.length = 0; location.hash = '#/catalog/genres'; markRendered();
globalThis.fetch = (url, opt) => { requests.push({ url: decodeURIComponent(url), opt }); return originalFetch(url, opt); };
const stopCatalog = genresPage.mount(catRoot); await delay(20);
for (const part of ['/ui-api/meta/catalog/genres?', 'min_count=3', 'per_page=60', 'page=1', 'sort=movie_count']) assert.ok(requests[0].url.includes(part), part);
catRoot.emit('click', { target: { closest: s => (s === '[data-script]' ? { dataset: { script: 'ja' } } : null) } });
assert.match(location.hash, /script=ja/); assert.equal(pushes.length, 1); await delay(300);
assert.ok(requests.at(-1).url.includes('script=ja') && requests.at(-1).url.includes('min_count=3'), 'the script index refreshes the list in place');
catNodes['#cf'].emit('input', { target: { value: 'sch' } }); await delay(300); assert.match(location.hash, /q=sch/); assert.ok(requests.at(-1).url.includes('q=sch') && requests.at(-1).url.includes('script=ja'));
catNodes['#cmin'].checked = false; catNodes['#cmin'].emit('change', { target: catNodes['#cmin'] }); assert.match(location.hash, /min_count=0/); await delay(300); assert.ok(requests.at(-1).url.includes('min_count=0'));
stopCatalog(); globalThis.fetch = originalFetch; delete globalThis.history;
location.hash = '#/';
console.log('Results refresh, facet genres expansion and catalog assertions passed');
