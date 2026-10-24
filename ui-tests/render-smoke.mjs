// Renders every UI view against sample API responses (no browser, no network): `node ui-tests/render-smoke.mjs`.
import assert from 'node:assert/strict';

const store = {};
globalThis.localStorage = { getItem: k => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); } };
globalThis.location = { hash: '#/', href: '', reload() {} };
globalThis.innerWidth = 1280;
globalThis.document = { querySelector: () => null, body: { contains: () => true }, documentElement: { dataset: {} }, addEventListener() {}, createElement: () => ({ append() {}, remove() {} }) };
globalThis.requestAnimationFrame = () => 0;
globalThis.IntersectionObserver = class { observe() {} disconnect() {} };

const movie = (code, extra = {}) => ({
  uuid: 'u-' + code, code, title_jp: null, title_en: 'Title of ' + code, cover_url: 'https://img.test/' + code + '.jpg', release_date: '2026-10-01', runtime_minutes: 120,
  maker: 'Mankitsu', label: null, series: 'Series One', genres: ['Drama', 'School'], censored: null, community_score: 4.2, completeness_tier: 2, needs_review: false,
  performers: [{ id: 92, name_romaji: 'Eimi Fukada', name_kanji: null, name_kana: null, aliases: [], sources: [{ source_slug: 'javbtc', external_id: 'x', source_url: 'https://javbtc.test/x', birth_date: null, active: true }], image_url: null, profile_url: null }],
  ...extra,
});
const performer = id => ({ uuid: 'p' + id, id, name_romaji: 'Aaliyah Love', name_kanji: 'アリア', name_kana: null, aliases: ['AL'], sources: [{ source_slug: 'javlibrary', external_id: 'a', source_url: 'https://jl.test/a', birth_date: '1994-05-02', name_romaji: 'Aaliyah Love', active: true }], image_url: null, profile_url: null, birth_date: '1994-05-02', height_cm: 160, bust: 85, waist: 58, hip: 86, cup: 'D', blood_type: 'O', bio_text: null, debut_date: null, retired_date: null, location: 'Tokyo', attrs: {}, linked_title_count: 7 });
const pg = (total, hasMore = false) => ({ meta: { pagination: { total, per_page: 24, sort: 'release_date', has_more: hasMore, next_cursor: null, last_page: 1 } } });
const summary = { counts: { movies: 4469, performers: 4015 }, ingest: { movies_1h: 1010, movies_24h: 3000, performers_1h: 800, performers_24h: 2000 }, queue: { by_status: { pending: 100, claimed: 5, done: 900, failed: 7 }, stuck_claimed: 0 }, workers: [{ instance: 'w1', stale: false, active_claims: 3 }], runs_24h: { runs: 400, pages_fetched: 0, movies_new: 0, movies_updated: 0, failures: 0 }, checked_at: new Date().toISOString() };
const sources = [
  { slug: 'javdb', name: 'JavDB', enabled: true, gap_seconds_current: 5, gap_seconds_min: 4, gap_seconds_max: 60, last_success_at: new Date().toISOString(), last_error_at: null, last_error: null, queue: { pending: 3, claimed: 1, done: 20, failed: 0 } },
  { slug: 'javbus', name: 'JavBus', enabled: true, gap_seconds_current: 600, gap_seconds_min: 4, gap_seconds_max: 60, last_success_at: null, last_error_at: new Date().toISOString(), last_error: 'All fetch methods exhausted', queue: { pending: 90, claimed: 0, done: 0, failed: 4 } },
  { slug: 'avfan', name: 'Avfan', enabled: false, gap_seconds_current: 5, gap_seconds_min: 2, gap_seconds_max: 30, last_success_at: null, last_error_at: null, last_error: null, queue: { pending: 0, claimed: 0, done: 0, failed: 0 } },
];

const route = url => {
  const u = new URL(url, 'https://x.test');
  const p = u.pathname.replace('/ui-api/', '');
  if (p === 'status/summary') return { data: summary };
  if (p === 'status/sources') return { data: sources };
  if (p === 'movies') return { data: [movie('AAA-001'), movie('BBB-002', { title_en: '<script>alert(1)</script>' })], ...pg(2) };
  if (p.startsWith('movies/') && !p.endsWith('/related') && p !== 'movies/calendar') return { data: { ...movie(decodeURIComponent(p.slice(7))), codes: [{ code: 'AAA-001', kind: 'display' }], directors: [], description: 'A story', attrs: { k: 'v' }, magnets: [{ url: 'magnet:?xt=1' }], hls_stream_urls: [], gallery: [], photos: {}, crawled_at: '2026-10-24T00:00:00Z', delisted_at: null } };
  if (p.startsWith('meta/catalog/')) return { data: [{ name: 'Drama', movie_count: 40 }, { name: 'School', movie_count: 12 }], meta: { pagination: { total: 2, per_page: 60, page: 1, last_page: 1, sort: 'movie_count' } } };
  if (p === 'suggest') return { data: { movies: [], performers: [], genres: [{ name: 'Drama', movie_count: 40 }], makers: [{ name: 'Mankitsu', movie_count: 9 }], series: [] } };
  if (p === 'movies/calendar') return { data: [{ date: '2026-10-05', count: 5, movies: [{ code: 'AAA-001', title: 'T', cover_url: null, maker: 'M' }] }], meta: { month: '2026-10', total: 5 } };
  if (p.endsWith('/related')) return { data: [movie('REL-001')] };
  if (p === 'performers') return { data: [performer(1), performer(2)], ...pg(2) };
  if (p.startsWith('performers/')) return { data: performer(1) };
  throw new Error('no sample for ' + p);
};
const adminSample = u => u.includes('report/quality') ? { data: { movies: 100, performers: 50, movies_by_source: { javdb: 60 }, core_fields: { code: { filled: 100, percent: 100 }, maker: { filled: 80, percent: 80 } }, movies_with_8_of_11: { count: 70, percent: 70 }, completeness_tiers: { 1: 20, 2: 80 }, needs_review: 3 } } : { data: [{ id: 1, prefix: 'jvm_abc', label: 'Key <b>one</b>', status: 'active', abuse_rpm: 60, scopes: ['catalog'], created_at: '2026-10-24T00:00:00Z' }] };
globalThis.fetch = async url => url.startsWith('/ui-admin/') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => adminSample(url) } : ({ ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => route(url) });

const load = async f => (await import('../public/ui/js/' + f)).default;
const out = {};
const run = async (name, view, ctx) => { const r = await view.render(ctx); assert.ok(r.html && r.html.length > 100, name + ' renders html'); out[name] = r.html; return r.html; };

const { toApi } = await import('../public/ui/js/views/movies.js');
assert.deepEqual(toApi({ q: 'ab', year: '2025', rmin: '90', genre: 'Drama' }), { q: 'ab', genre: 'Drama', released_from: '2025-01-01', released_to: '2025-12-31', runtime_min: '90' });
assert.deepEqual(toApi({ from: '2020', to: '2022' }), { released_from: '2020-01-01', released_to: '2022-12-31' });

const home = await run('home', await load('views/home.js'), { params: {}, query: {} });
assert.match(home, /phim trong DB/); assert.match(home, /data-to="4469"/); assert.match(home, /Hỏng|Tắt|Ổn/);
await run('movies', await load('views/movies.js'), { params: {}, query: { genre: 'Drama' } });
assert.match(out.movies, /Bộ lọc/); assert.match(out.movies, /genre: <b>Drama/);
const detail = await run('movie', await load('views/movie.js'), { params: { code: 'AAA-001' }, query: {} });
assert.match(detail, /Title of AAA-001/); assert.match(detail, /Eimi Fukada/); assert.match(detail, /#\/facet\/genre\/Drama/);
await run('performers', await load('views/performers.js'), { params: {}, query: { blood_type: 'O' } });
const pd = await run('performer', await load('views/performer.js'), { params: { id: '1' }, query: {} });
assert.match(pd, /Aaliyah Love/); assert.match(pd, /Tokyo/);
const crawl = await run('crawl', await load('views/crawl.js'), { params: {}, query: {} });
assert.match(crawl, /JavBus/); assert.match(crawl, /600s/);
const s = await run('search', await load('views/search.js'), { params: {}, query: { q: 'Drama' } });
assert.match(s, /Kết quả/); assert.match(s, /Danh mục khớp/); assert.match(s, /#\/facet\/maker\/Mankitsu/);
await run('facet-genre', await load('views/facet.js'), { params: { kind: 'genre', value: 'Drama' }, query: {} });
await run('facet-year', await load('views/facet.js'), { params: { kind: 'year', value: '2025' }, query: {} });
const cal = await run('calendar', await load('views/calendar.js'), { params: {}, query: { month: '2026-10' } });
assert.match(cal, /Lịch phát hành/); assert.match(cal, /AAA-001/);
const adm = await run('admin', await load('views/admin.js'), { params: {}, query: {} });
assert.match(adm, /Chất lượng dữ liệu/); assert.match(adm, /jvm_abc/); assert.ok(!adm.includes('<b>one</b>'), 'key labels are escaped');
await run('settings', await load('views/settings.js'), { params: {}, query: {} });
const cat = await run('catalog', await load('views/catalog.js'), { params: { kind: 'genres' }, query: {} });
assert.match(cat, /Thể loại/);
await run('catalog-years', await load('views/catalog.js'), { params: { kind: 'years' }, query: {} });
assert.match(await run('catalog-unknown', await load('views/catalog.js'), { params: { kind: 'nope' }, query: {} }), /không tồn tại/);

// a title with markup must never reach the page unescaped
const movieList = (await import('../public/ui/js/components.js')).movieCard(movie('XSS-1', { title_en: '<img src=x onerror=alert(1)>' }));
assert.ok(!movieList.includes('<img src=x'), 'titles are escaped');
console.log('UI render smoke: ' + Object.keys(out).length + ' views rendered, all assertions passed');
