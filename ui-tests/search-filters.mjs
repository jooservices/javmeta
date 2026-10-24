import assert from 'node:assert/strict';
import { api } from '../public/ui/js/api.js';
import { movieKeys, performerKeys, movieParams, performerParams, toggleValue, removeFilter, chipsHtml, activeFilters } from '../public/ui/js/filterState.js';
import { facetHtml, choiceHtml, relaxationSuggestions, suggestionsHtml, mountFacets } from '../public/ui/js/facets.js';
import { optionsHtml, performerNames, mountPicker } from '../public/ui/js/pickers.js';
import { savedItemsHtml, parseSaved, mountSavedSearches } from '../public/ui/js/savedSearches.js';
import { searchBrowser } from '../public/ui/js/searchBrowser.js';

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
const movieFacets = (await api('movies/facets')).data;
const fh = facetHtml('movies', movieFacets, { actress_id: '92' });
for (const value of ['Drama', 'Maker One', 'Series One', 'Label One', '2025', 'javdb', 'Eimi &lt;b&gt;x&lt;/b&gt;']) assert.ok(fh.includes(value), value);
assert.match(fh, /aria-pressed="true"/); assert.ok(!fh.includes('<b>x</b>'));
const genreHtml = choiceHtml(movieFacets.genres, 'genre', { genre: 'Drama,Missing' });
assert.match(genreHtml, /value="Drama" checked/); assert.match(genreHtml, /\(12\)/); assert.match(genreHtml, /value="Missing" checked/);
assert.ok(!genreHtml.includes('<b>School</b>'));
assert.ok(!choiceHtml(movieFacets.genres, 'genre', {}, 'school').includes('Drama'));
const pf = facetHtml('performers', (await api('performers/facets')).data, { cup: 'D' });
for (const value of ['Cup', 'Nhóm máu', 'Tokyo', 'Đang hoạt động', 'Có ảnh: 6', 'Có tiểu sử: 4']) assert.ok(pf.includes(value));
const browser = searchBrowser('movies', movieQuery);
for (const key of movieKeys.filter(k => !['sort', 'genre', 'actress_id', 'source'].includes(k))) assert.ok(browser.html.includes('name="' + key + '"'), key);
for (const text of ['role="combobox"', 'aria-expanded="false"', 'role="listbox"', 'đóng chung', 'Thu hẹp kết quả', 'Lưu bộ lọc', 'Bộ lọc đã lưu', 'data-page-size', 'data-act="mode"', 'data-act="size"']) assert.ok(browser.html.includes(text), text);
const performerBrowser = searchBrowser('performers', performerQuery);
for (const key of performerKeys.filter(k => !['sort', 'cup', 'blood_type', 'source'].includes(k))) assert.ok(performerBrowser.html.includes('name="' + key + '"'), key);
assert.match(performerBrowser.html, /Số đo/); assert.match(performerBrowser.html, /data-choices="blood_types"/);
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

const nodes = Object.fromEntries(['[data-facets]', '[data-facet-loading]', '[data-facet-error]'].map(k => [k, new Node()]));
const root = new Node(); root.querySelector = s => nodes[s]; root.querySelectorAll = () => [];
const life = new AbortController(); let facetSignal;
globalThis.fetch = (url, opt) => { facetSignal = opt.signal; return new Promise((resolve, reject) => opt.signal.addEventListener('abort', () => reject(Object.assign(new Error('cancelled'), { name: 'AbortError' })))); };
const stopFacets = mountFacets(root, 'movies', {}, movieParams, () => {}, life.signal);
assert.equal(nodes['[data-facets]'].attrs['aria-busy'], 'true'); assert.equal(nodes['[data-facet-loading]'].hidden, false);
life.abort(); stopFacets(); assert.equal(facetSignal.aborted, true); await delay(0);

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
globalThis.fetch = async (url, opt = {}) => {
  if (opt.method === 'POST') { const body = JSON.parse(opt.body); writes.push({ body, headers: opt.headers }); savedRows = body.on ? [{ ...body }] : []; }
  return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ data: savedRows }) };
};
const savedLife = new AbortController(); mountSavedSearches(savedRoot, 'movies', { actress_id: '1,2', genre: 'Drama' }, savedLife.signal); await delay(0);
savedNodes['[data-save-search]'].emit('click'); assert.equal(savedNodes['[data-save-form]'].hidden, false);
savedNodes['[data-save-form]'].emit('submit'); await delay(0);
assert.equal(writes.length, 1); assert.equal(writes[0].headers['X-CSRF-TOKEN'], 'csrf-test');
assert.deepEqual(JSON.parse(writes[0].body.key), { name: 'My search', query: 'actress_id=1%2C2&genre=Drama' });
assert.match(savedNodes['[data-saved-list]'].innerHTML, /#\/movies\?actress_id=1%2C2&amp;genre=Drama/);
const deleteButton = Object.assign(new Node(), { dataset: { deleteSearch: '0' } });
savedNodes['[data-saved-list]'].emit('click', { target: { closest: s => s === '[data-delete-search]' ? deleteButton : null } }); await delay(0);
assert.equal(writes[1].body.on, false); assert.match(savedNodes['[data-saved-list]'].innerHTML, /Chưa có/);
savedLife.abort(); globalThis.document.querySelector = () => null; globalThis.fetch = originalFetch;
globalThis.innerWidth = 375;
assert.match(searchBrowser('movies', {}).html, /brmain nof/);
assert.match(searchBrowser('performers', {}).html, /brmain nof/);
globalThis.innerWidth = 1280;
console.log('Search filters: parameter, rendering, escaping, debounce, keyboard, cancellation, caching and saved-search interaction assertions passed');

// Mount a whole browser and drive its hash changes and list requests.
const RealFormData = globalThis.FormData;
const browserNodes = Object.fromEntries([
  '[data-list]', '[data-count]', '.brmain', '[data-filter-form]', '[data-sort]', '[data-page-size]',
  '[data-performer-input]', '[role=listbox]', '[data-active]', '[data-performer-chips]', '[data-score]',
  '[data-facets]', '[data-facet-loading]', '[data-facet-error]', '[data-relax]', '.brbar [data-act=toggleside]',
  '[data-saved-list]', '[data-save-form]', '[data-saved-error]', '[data-save-search]', '[data-save-cancel]',
].map(s => [s, new Node()]));
const listNodes = Object.fromEntries(['[data-h]', '[data-f]', '[data-e]'].map(s => [s, new Node()]));
listNodes['[data-h]'].insertAdjacentHTML = (where, html) => { listNodes['[data-h]'].innerHTML += html; };
browserNodes['[data-list]'].querySelector = s => listNodes[s];
const classes = new Set();
browserNodes['.brmain'].classList = { contains: k => classes.has(k), toggle: k => classes.has(k) ? classes.delete(k) : classes.add(k) };
browserNodes['.brbar [data-act=toggleside]'].classList.toggle = () => {};
browserNodes['[data-sort]'].value = 'release_date';
browserNodes['[data-save-form]'].elements = { search_name: new Node() };
browserNodes['[data-save-form]'].querySelector = () => new Node();
const browserRoot = new Node(); browserRoot.querySelector = s => browserNodes[s]; browserRoot.querySelectorAll = () => [];
let formValues = {};
globalThis.FormData = class { constructor() { return Object.entries(formValues)[Symbol.iterator](); } };
requests = [];
globalThis.fetch = (url, opt) => { requests.push({ url, opt }); return originalFetch(url, opt); };
location.hash = '#/movies?actress_id=1%2C2&genre=Drama%2CSchool&has_cover=0';
const dispose = searchBrowser('movies', { actress_id: '1,2', genre: 'Drama,School', has_cover: '0' }).mount(browserRoot);
await delay(0);
assert.match(listNodes['[data-h]'].innerHTML, /AAA-001/);
assert.match(browserNodes['[data-active]'].innerHTML, /Diễn viên: Aaliyah Love/);
assert.match(browserNodes['[data-performer-chips]'].innerHTML, /data-value="2"/);
assert.ok(requests.some(r => r.url.includes('movies?') && r.url.includes('actress_id=1%2C2') && r.url.includes('has_cover=0')));
assert.match(browserNodes['[data-facets]'].innerHTML, /Drama/);
assert.equal(browserNodes['[data-facet-loading]'].hidden, true);
const click = dataset => browserRoot.emit('click', { target: { closest: () => ({ dataset }) } });
click({ drop: 'actress_id', value: '1' });
assert.match(location.hash, /actress_id=2/); assert.match(location.hash, /genre=Drama%2CSchool/);
click({ facet: 'genre', value: 'Drama' }); assert.match(location.hash, /genre=School/);
click({ facet: 'year', value: '2025' }); assert.match(location.hash, /released_from=2025/); assert.match(location.hash, /released_to=2025/);
formValues = { actress: 'Alias', runtime_min: '90' };
browserNodes['[data-filter-form]'].emit('input', { target: { name: 'actress' } });
const beforeDebounce = location.hash; await delay(100); assert.equal(location.hash, beforeDebounce);
await delay(530); assert.match(location.hash, /actress=Alias/); assert.match(location.hash, /runtime_min=90/);
click({ act: 'clearfilters' }); assert.equal(location.hash, '#/movies');
dispose(); assert.ok(requests.filter(r => r.opt?.signal).every(r => r.opt.signal.aborted), 'navigation aborts all browser GETs');
globalThis.FormData = RealFormData; globalThis.fetch = originalFetch;
console.log('Search browser: mounted list, facet drill-down, resolved performer chips, hash state, clear and cleanup assertions passed');
