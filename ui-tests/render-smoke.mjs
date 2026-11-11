// Renders every UI view against sample API responses (no browser, no network): `node ui-tests/render-smoke.mjs`.
import assert from 'node:assert/strict';
import { readFile, readdir, stat } from 'node:fs/promises';

const store = {};
globalThis.localStorage = { getItem: k => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); } };
globalThis.location = { hash: '#/', href: '', reload() {} };
globalThis.innerWidth = 1280;
globalThis.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
const fakeEl = () => {
  const attrs = {};
  const kids = [];
  const el = {
    className: '', hidden: false, style: {}, dataset: {}, innerHTML: '', textContent: '',
    append() {}, remove() {}, focus() {}, blur() {},
    setAttribute(k, v) { attrs[k] = String(v); }, getAttribute(k) { return attrs[k] ?? null; },
    addEventListener() {}, querySelector() { return fakeEl(); }, querySelectorAll() { return []; },
    closest() { return null; }, contains() { return false; },
  };
  kids.push(el);
  return el;
};
globalThis.document = {
  querySelector: () => null,
  getElementById: () => null,
  body:{ contains: () => true, dataset: { guestMode: 'false' }, appendChild() {}, style: {} },
  documentElement: { dataset: {}, lang: 'vi', dir: 'ltr' },
  addEventListener() {},
  createElement: () => fakeEl(),
  activeElement: null,
};
globalThis.requestAnimationFrame = () => 0;
globalThis.IntersectionObserver = class { observe() {} disconnect() {} };

// The shell loads the catalog before its first render; the smoke test does the same.
await (await import('../public/ui/js/i18n.js')).initI18n('vi');

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
  { slug: 'javdb', name: 'JavDB', lane: 'direct', enabled: true, gap_seconds_current: 5, gap_seconds_min: 4, gap_seconds_max: 60, last_success_at: new Date().toISOString(), last_error_at: null, last_error: null, queue: { pending: 3, claimed: 1, done: 20, failed: 0 } },
  { slug: 'javbus', name: 'JavBus', lane: 'browser', enabled: true, gap_seconds_current: 600, gap_seconds_min: 4, gap_seconds_max: 60, last_success_at: null, last_error_at: new Date().toISOString(), last_error: 'All fetch methods exhausted', queue: { pending: 90, claimed: 0, done: 0, failed: 4 } },
  { slug: 'avfan', name: 'Avfan', enabled: false, gap_seconds_current: 5, gap_seconds_min: 2, gap_seconds_max: 30, last_success_at: null, last_error_at: null, last_error: null, queue: { pending: 0, claimed: 0, done: 0, failed: 0 } },
];

const facetRows = {
  genres: [{ name: 'Drama', count: 12 }, { name: '<b>School</b>', count: 3 }, { name: 'Rare', count: 1 }], makers: [{ name: 'Maker One', count: 10 }], series: [{ name: 'Series One', count: 9 }], labels: [{ name: 'Label One', count: 8 }],
  years: [{ name: '2025', count: 7 }], sources: [{ name: 'javdb', count: 6 }, { name: 'javbus', count: 4 }], performers: [{ id: 92, name: 'Eimi <b>x</b>', count: 5 }],
  cups: [{ name: 'D', count: 5 }], blood_types: [{ name: 'O', count: 4 }], locations: [{ name: 'Tokyo', count: 3 }], status: [{ name: 'active', count: 7 }, { name: 'retired', count: 3 }],
};
const route = url => {
  const u = new URL(url, 'https://x.test');
  const p = u.pathname.replace('/ui-api/', '');
  if (p.endsWith('/facets/values')) {
    const group = u.searchParams.get('facet'), needle = (u.searchParams.get('q') || '').toLowerCase(), min = Number(u.searchParams.get('min_count') || 0);
    const per = Number(u.searchParams.get('per_page') || 20), page = Number(u.searchParams.get('page') || 1);
    const all = (facetRows[group] || []).filter(r => r.name.toLowerCase().includes(needle)), items = all.filter(r => r.count >= min);
    return { data: { items: items.slice((page - 1) * per, page * per), total: items.length, hidden_below_min: all.length - items.length, facet: group }, meta: { pagination: { total: items.length, per_page: per, page, last_page: Math.max(1, Math.ceil(items.length / per)), min_count: min } } };
  }
  if (p === 'movies/facets') return { data: { total: u.searchParams.get('q') === 'empty' ? 0 : 12, genres: [{ name: 'Drama', count: 12 }, { name: '<b>School</b>', count: 3 }], makers: [{ name: 'Maker One', count: 10 }], series: [{ name: 'Series One', count: 9 }], labels: [{ name: 'Label One', count: 8 }], years: [{ name: '2025', count: 7 }], sources: [{ name: 'javdb', count: 6 }], performers: [{ id: 92, name: 'Eimi <b>x</b>', count: 5 }] } };
  if (p === 'performers/facets') return { data: { total: 7, cups: [{ name: 'D', count: 5 }], blood_types: [{ name: 'O', count: 4 }], locations: [{ name: 'Tokyo', count: 3 }], sources: [{ name: 'javdb', count: 2 }], status: [{ name: 'active', count: 7 }], with_image: 6, with_bio: 4 } };
  if (p === 'scenes/search') return { data: [{ movie_code: 'AAA-001', library_file_id: 1, start_sec: 12, description: '<b>red room</b>', thumb_url: null }] };
  if (p === 'status/summary') return { data: summary };
  if (p === 'status/sources') return { data: sources };
  if (p === 'movies') return { data: [movie('AAA-001'), movie('BBB-002', { title_en: '<script>alert(1)</script>' })], ...pg(2) };
  if (p.startsWith('movies/') && !p.endsWith('/related') && p !== 'movies/calendar') return { data: { ...movie(decodeURIComponent(p.slice(7))), codes: [{ code: 'AAA-001', kind: 'display' }], directors: [], description: 'A story', attrs: { k: 'v' }, magnets: [{ url: 'magnet:?xt=1' }], downloads: { available: true, count: 1, last_checked_at: null, state: 'found', search_enabled: true }, hls_stream_urls: [], gallery: [], photos: {}, crawled_at: '2026-10-24T00:00:00Z', delisted_at: null } };
  if (['cups', 'blood_types', 'locations'].includes(p.replace('meta/catalog/', ''))) return { data: [{ name: 'D', performer_count: 30 }, { name: 'E', performer_count: 9 }], meta: { pagination: { total: 2, per_page: 24, page: 1, last_page: 1 } } };
  if (p.startsWith('meta/catalog/')) return { data: [{ name: '2025', movie_count: 40 }, { name: 'School', movie_count: 12 }], meta: { pagination: { total: 2, per_page: 60, page: 1, last_page: 1, sort: 'movie_count' } } };
  if (p === 'search') return { data: { movies: [movie('AAA-001'), movie('BBB-002')], performers: [performer(1)], interpretation: { keywords: 'night', filters: { maker: 'Mankitsu', released_from: '2024-01-01' }, by: 'rules', used_vectors: false }, totals: { movies: 480, performers: 12 } } };
  if (p === 'suggest') return { data: { movies: [], performers: [{ id: 92, name: 'Eimi <b>x</b>', movie_count: 5 }], genres: [{ name: 'Drama', movie_count: 40 }], makers: [{ name: 'Mankitsu', movie_count: 9 }], series: [] } };
  if (p === 'movies/calendar') {
    const month = u.searchParams.get('month') || '2026-10';
    if (month === '2026-10') return { data: [{ date: '2026-10-05', count: 5, movies: [{ code: 'AAA-001', title: 'T', cover_url: null, maker: 'M' }] }], meta: { month, total: 5, latest_month: '2026-10' } };
    return { data: [], meta: { month, total: 0, latest_month: '2026-10' } };
  }
  if (p.endsWith('/related')) return { data: [movie('REL-001')] };
  if (p === 'performers') return { data: [performer(1), performer(2)], ...pg(2) };
  if (p === 'performers/9') return { data: { ...performer(9), name_kanji: null, aliases: [], birth_date: null, height_cm: 158, bust: 84, waist: null, hip: null, cup: null, blood_type: null, location: null, debut_date: null, retired_date: null, bio_text: null, attrs: { weird_key: 1 } } };
  if (p === 'performers/10') return { data: { ...performer(10), name_romaji: 'Nobody Known', name_kanji: null, aliases: [], birth_date: null, height_cm: null, bust: null, waist: null, hip: null, cup: null, blood_type: null, location: null, debut_date: null, retired_date: null, bio_text: null, attrs: {}, linked_title_count: 0 } };
  if (p.startsWith('performers/')) return { data: performer(1) };
  throw new Error('no sample for ' + p);
};
const settingsSample = {
  data: {
    groups: {
      queue: 'Hàng đợi', crawl: 'Vòng lặp crawl', worker: 'Worker', browser: 'Trình duyệt',
      cleanup: 'Dọn dẹp', timeouts: 'Thời gian chờ', telegram: 'Telegram', report: 'Báo cáo',
      follow_notifications: 'Thông báo theo dõi',
      notifications: 'Chính sách thông báo',
    },
    settings: [
      { key: 'queue.feed_pool_limit', group: 'queue', label: 'Việc nạp mỗi phút', description: 'd', type: 'int', min: 1, max: 5000, restart: null, apply_after_seconds: 30, source: 'database', value: 300, is_set: null },
      { key: 'queue.feed_interval_seconds', group: 'queue', label: 'Chu kỳ nạp hàng đợi (giây)', description: 'Chọn 5, 10, 15, 30 hoặc 60 giây.', type: 'int', min: 5, max: 60, options: [5, 10, 15, 30, 60], apply_after_seconds: 5, source: 'database', value: 10 },
      { key: 'crawl.source_gap_overrides', group: 'crawl', label: 'Khoảng request ghi đè theo nguồn (JSON)', description: 'Khoảng gap theo từng nguồn.', type: 'json', shape: 'source_gap_override_map', max_length: 10000, apply_after_seconds: 30, source: 'default', value: {} },
      { key: 'worker.node_overrides', group: 'worker', label: 'Ghi đè node', description: 'JSON worker layout', type: 'json', shape: 'node_worker_map', max_length: 500, apply_after_seconds: 900, source: 'environment', value: { main: { default: 2 } }, is_set: null },
      { key: 'telegram.bot_token', group: 'telegram', label: 'Token <b>x</b>', description: 'd', type: 'secret', min: null, max: null, restart: null, source: 'default', value: null, is_set: true },
      { key: 'notifications.quiet_mode', group: 'notifications', label: 'Chế độ yên lặng', description: 'Chỉ gửi CRITICAL và sự kiện bảo mật.', type: 'bool', value: false },
      { key: 'report.enabled', group: 'report', label: 'Bật báo cáo định kỳ', description: 'Lịch tự động qua Telegram.', type: 'bool', value: true },
      { key: 'report.interval_minutes', group: 'report', label: 'Chu kỳ gửi báo cáo', description: 'Theo múi giờ đã chọn.', type: 'int', options: [15, 30, 60, 120, 180, 360, 720, 1440], value: 120 },
      { key: 'report.timezone', group: 'report', label: 'Múi giờ báo cáo', description: 'Tên múi giờ IANA.', type: 'string', value: 'America/Mexico_City' },
      { key: 'report.daily_time', group: 'report', label: 'Giờ gửi báo cáo hằng ngày', description: 'Giờ địa phương.', type: 'string', format: 'time', value: '08:00' },
      { key: 'report.daily_timezone', group: 'report', label: 'Múi giờ báo cáo hằng ngày', description: 'Tên múi giờ IANA.', type: 'string', format: 'timezone', value: 'America/Mexico_City' },
      { key: 'report.sections', group: 'report', label: 'Các phần trong báo cáo', description: 'Chọn mục hiển thị.', type: 'string_list', item_options: ['movies', 'performers', 'crawl', 'sources', 'fleet', 'workers', 'servers', 'services'], value: ['movies', 'crawl'] },
      { key: 'report.template', group: 'report', label: 'Mẫu văn bản báo cáo', description: 'Mẫu tiếng Anh.', type: 'string', multiline: true, max_length: 1000, value: '{header}\n\n{movies}\n\n{crawl}' },
    ],
  },
};
const reportSample = {
  data: {
    data: { generated_at: '2026-11-02T18:00:00-06:00', window_minutes: 120, movies: { total: 10, since_last: 1, last_24h: 4, avg_per_hour: 1, peak_per_hour: 1 }, sections: ['movies'] },
    text: '📊 [XCrawler] Report · Nov 2, 18:00 (Mexico) · last 2 h\n\nMovies 10 (+1 since last report · +4 in 24 h)',
    last: null,
    history: [],
  },
};
let emptyAdminKeys = false;
let interactiveAdminKeys = null;
let failedAdminCursor = null;
let interactiveAdminPageRequests = [];
const adminSample = u => {
  if (u.includes('/ui-admin/settings')) return settingsSample;
  if (u === '/ui-admin/notifications') return { data: [{ key: 'crawl.success_rate_low', name: 'Tỷ lệ crawl thành công thấp', usage: 'Gửi khi tỷ lệ thành công xuống dưới ngưỡng.', channel: 'telegram', enabled: true, tier: 'warning', cooldown_minutes: 120, escalation_minutes: 60, include_in_periodic_report: true, include_in_daily_report: true, send_resolved: false, last_sent_at: '2026-11-02 17:40:00', updated_at: '2026-11-02 17:00:00' }] };
  if (u === '/ui-admin/report/daily') return { ...reportSample, data: { ...reportSample.data, text: 'Daily report preview · last 24 h' } };
  if (u === '/ui-admin/report/periodic') return reportSample;
  if (u.includes('report/quality')) return { data: { movies: 100, performers: 50, movies_by_source: { javdb: 60 }, core_fields: { code: { filled: 100, percent: 100 }, maker: { filled: 80, percent: 80 } }, movies_with_8_of_11: { count: 70, percent: 70 }, completeness_tiers: { 1: 20, 2: 80 }, needs_review: 3 } };
  if (u.startsWith('/ui-admin/keys')) {
    const url = new URL(u, 'https://x.test'), cursor = Number(url.searchParams.get('cursor') || Number.MAX_SAFE_INTEGER);
    const perPage = Math.max(1, Number(url.searchParams.get('per_page') || 25));
    const all = emptyAdminKeys ? [] : Array.from({ length: 26 }, (_, index) => ({
      id: index + 1, prefix: index === 25 ? 'jvm_abc' : `jvm_${String(index).padStart(8, '0')}`,
      label: index === 25 ? 'Key <b>one</b>' : `Key ${index + 1}`, status: 'active', abuse_rpm: 60,
      scopes: ['catalog'], created_at: '2026-10-24T00:00:00Z',
    }));
    const rows = [...(interactiveAdminKeys ?? all)].filter(key => key.id < cursor).sort((a, b) => b.id - a.id);
    const selected = rows.slice(0, perPage + 1), hasMore = selected.length > perPage;
    if (hasMore) selected.pop();
    return { data: selected, meta: { pagination: { per_page: perPage, has_more: hasMore, next_cursor: hasMore ? String(selected.at(-1).id) : null } } };
  }
  return { data: [] };
};
const libSample = u => u.endsWith('/library') ? { data: { files: 3, matched: 2, unmatched: 1, missing: 0, bytes: 5012, by_folder: [{ folder: '/media/<b>a</b>', files: 3, bytes: 5012 }], last_scan_at: '2026-10-24 03:00:00', configured: true } } : u.includes('/codes') ? { data: ['AAA-001'] } : u.includes('/on-disk') ? { data: [{ code: 'AAA-001', files: 1, bytes: 5000 }] } : { data: [{ path: '/media/a/x.mp4', size: 7, folder: '/media/a' }], meta: { total: 1, page: 1, per_page: 100 } };
const dlSample = u => u.endsWith('/status') ? { data: { enabled: true, files_enabled: true } } : { data: { items: [{ hash: 'aa', name: 'Video <b>x</b>', movie_code: 'AAA-001', state: 'downloading', progress: 42.5, size: 1e9, speed: 2e6, eta: 600, completed: false }, { hash: 'bb', name: 'Done', movie_code: null, state: 'completed', progress: 100, size: 2e9, speed: 0, eta: null, completed: true }] } };
const mockJsonResponse = (body, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  redirected: false,
  headers: { get: () => 'application/json' },
  json: async () => body,
});
globalThis.fetch = async (url, options = {}) => {
  if (url.startsWith('/ui-admin/')) {
    const revokedKey = url.match(/^\/ui-admin\/keys\/(\d+)$/);
    if (options.method === 'DELETE' && revokedKey && interactiveAdminKeys !== null) {
      interactiveAdminKeys = interactiveAdminKeys.filter(key => key.id !== Number(revokedKey[1]));
      return mockJsonResponse(null, 204);
    }

    const requestUrl = new URL(url, 'https://x.test');
    const cursor = requestUrl.searchParams.get('cursor');
    if (requestUrl.pathname === '/ui-admin/keys' && interactiveAdminKeys !== null) interactiveAdminPageRequests.push(cursor);
    if (requestUrl.pathname === '/ui-admin/keys' && failedAdminCursor !== null && failedAdminCursor === cursor) {
      failedAdminCursor = null;
      return mockJsonResponse({ message: 'API key page unavailable' }, 503);
    }

    return mockJsonResponse(adminSample(url));
  }

  if (url.startsWith('/ui-me/media/files')) return mockJsonResponse({ data: { data: [{ id: 1, code: 'AAA-001', path: '/generated/video.mp4' }] } });
  if (url.startsWith('/ui-me/downloads')) return mockJsonResponse(dlSample(url));
  if (url.startsWith('/ui-me/library')) return mockJsonResponse(libSample(url));
  if (url.startsWith('/ui-me/')) return mockJsonResponse(url.includes('/marks') ? { data: [{ kind: 'favourite', subject: 'movie', key: 'AAA-001' }] } : { data: [movie('AAA-001')], meta: { follows: 2, new_since_last_visit: 1, total: 1 } });

  return mockJsonResponse(route(url));
};

const load = async f => (await import('../public/ui/js/' + f)).default;
const out = {};
const run = async (name, view, ctx) => { const r = await view.render(ctx); assert.ok(r.html && r.html.length > 100, name + ' renders html'); out[name] = r.html; return r.html; };

const { toApi } = await import('../public/ui/js/views/movies.js');
assert.deepEqual(toApi({ q: 'ab', year: '2025', rmin: '90', genre: 'Drama' }), { q: 'ab', genre: 'Drama', released_from: '2025-01-01', released_to: '2025-12-31', runtime_min: '90' });
assert.deepEqual(toApi({ from: '2020', to: '2022' }), { released_from: '2020-01-01', released_to: '2022-12-31' });

const home = await run('home', await load('views/home.js'), { params: {}, query: {} });
assert.match(home, /phim trong kho/); assert.match(home, /data-to="4469"/); assert.match(home, /Mới cập nhật/); assert.match(home, /Thể loại phổ biến/);
assert.doesNotMatch(home, /Chưa có tín hiệu|WORKER|Sức khoẻ crawl|việc đang chờ/i, 'Discover is for people who browse: queue, worker and source health live in the Command Center');
assert.doesNotMatch(home, /#\/search\?q=Drama/, 'U-03: Discover chips are catalog genres, not hardcoded Drama');
assert.match(home, /Gợi ý[\s\S]*School/, 'U-03: Discover chips use a genre that exists');
assert.match(home, /<h1>Khám phá<\/h1>/, 'U-17: Discover has an h1');
assert.match(home, /id="hsq"[^>]*aria-label="/, 'U-17: the Discover search box has an accessible name');
assert.doesNotMatch(home, /class="cu" data-to="4469">0</, 'U-21: counters start at the real total');
assert.match(home, /href="#\/catalog\/genres" class="chip more">Mọi thể loại/, 'JOOXCR-407: Discover suggestions link to the genre catalog');
await run('movies', await load('views/movies.js'), { params: {}, query: { genre: 'Drama' } });
assert.match(out.movies, /Bộ lọc/); assert.match(out.movies, /data-drop="genre" data-value="Drama"/); assert.match(out.movies, /Thể loại: Drama/);
// JOOXCR-407: every facet is one FacetList; filters refresh only the results region without a route render
for (const group of ['genres', 'makers', 'series', 'labels', 'years', 'sources']) assert.match(out.movies, new RegExp(`<fieldset[^>]*data-facet-list="${group}"`), group + ' is a FacetList fieldset');
assert.match(out.movies, /data-fl-all/); assert.doesNotMatch(out.movies, /data-top=|name="maker"|data-choices=/, 'no tag rows or typed facet fields');
assert.match(out.movies, /data-results[^>]*aria-busy="false"/); assert.match(out.movies, /data-results-error[^>]*hidden/); assert.match(out.movies, /Đang lọc/);
const uiSources = { search: await readFile(new URL('../public/ui/js/searchBrowser.js', import.meta.url), 'utf8'), app: await readFile(new URL('../public/ui/js/app.js', import.meta.url), 'utf8') };
assert.match(uiSources.search, /softNavigate\(path, q/); assert.doesNotMatch(uiSources.search, /location\.hash = /, 'a filter change never remounts the view through the hash');
assert.match(uiSources.app, /trySoftUpdate/); assert.match(uiSources.app, /addEventListener\('popstate', navigate\)/); assert.match(uiSources.app, /addEventListener\('hashchange', navigate\)/);
const resultsRefresh = await import('../public/ui/js/resultsRefresh.js');
for (const name of ['softNavigate', 'bindQuerySoftUpdate', 'refreshResultsRegion', 'trySoftUpdate', 'markRendered']) assert.equal(typeof resultsRefresh[name], 'function', name);
const facetListModule = await import('../public/ui/js/facetList.js');
assert.equal(facetListModule.PER_PAGE, 20, 'the product page size is 20'); assert.equal(typeof facetListModule.mountFacetList, 'function');
const detail = await run('movie', await load('views/movie.js'), { params: { code: 'AAA-001' }, query: {} });
assert.match(detail, /Title of AAA-001/); assert.match(detail, /class="cols detail"/, 'detail uses the fluid cover column'); assert.match(detail, /data-zoom="https:\/\/img\.test\/AAA-001\.jpg"/, 'cover opens the zoom viewer'); assert.match(detail, /role="button"[^>]*aria-label="Phóng to ảnh bìa AAA-001"/); assert.match(detail, /Eimi Fukada/); assert.match(detail, /#\/facet\/genre\/Drama/);
assert.doesNotMatch(detail, /chờ crawl/, 'U-20: missing fields do not promise a crawl');
assert.match(detail, />Thông tin</, 'U-15: movie tabs are Vietnamese');
assert.match(detail, /id="movie-downloads"/, 'movie detail exposes the downloads panel');
assert.match(detail, /magnet:\?xt=1/, 'downloads panel lists magnets');
assert.match(detail, /data-download-search|data-act="copy"/, 'downloads panel exposes magnet actions or search');
globalThis.innerWidth = 375;
for (const code of ['JAVBTC-DERIVED-' + 'A'.repeat(64), 'TOKYOHOT-SLUG-JAPANESE_INVASION_2_AMERICA_SC4']) {
  const longDetail = await (await load('views/movie.js')).render({ params: { code }, query: {} });
  assert.match(longDetail.html, /overflow-wrap:anywhere/, 'long source codes must wrap within the movie detail');
  assert.ok(longDetail.html.includes(`data-v="${code}"`), 'copy keeps the full source identity');
  if (code.includes('-DERIVED-')) {
    assert.ok(!longDetail.html.includes('>' + code + '<'), 'a derived hash is never shown as the visible code');
    assert.match(longDetail.html, /JAVBTC · #AAAAAA/, 'derived codes show a short internal label');
    assert.match(longDetail.html, /chưa có mã chuẩn/);
    assert.match(longDetail.html, /Sao chép mã nội bộ/);
  }
  assert.ok(longDetail.html.includes(`#/movie/${encodeURIComponent(code)}?tab=codes`), 'source-code navigation keeps the full identity');
}
globalThis.innerWidth = 1280;
await run('performers', await load('views/performers.js'), { params: {}, query: { blood_type: 'O' } });
const pd = await run('performer', await load('views/performer.js'), { params: { id: '1' }, query: {} });
assert.match(pd, /Aaliyah Love/); assert.match(pd, /Tokyo/);
assert.doesNotMatch(pd, /Độ đầy đủ hồ sơ|Profile completeness|performer\.completeness|performer\.awaiting_crawl|\bheight_cm\b|\bbio_text\b|\bimage_url\b/, 'JOOXCR-339: no completeness bar, crawl status or raw field key on a profile');
const partial = await run('performer-partial', await load('views/performer.js'), { params: { id: '9' }, query: {} });
assert.match(partial, /158 cm/); assert.match(partial, /B84/, 'a partial measurement shows only the known part');
assert.doesNotMatch(partial, /none yet|chưa có|null|undefined|NaN|weird_key|birth_date|height_cm|image_url|Độ đầy đủ hồ sơ/i, 'JOOXCR-339: unknown values are omitted, never shown as a placeholder or a raw key');
const emptyProfile = await run('performer-empty', await load('views/performer.js'), { params: { id: '10' }, query: {} });
assert.equal((emptyProfile.match(/Chưa có thông tin hồ sơ\./g) || []).length, 1, 'JOOXCR-339: an empty profile shows one neutral message');
assert.doesNotMatch(emptyProfile, /chưa có tín hiệu|null|undefined|NaN|birth_date|height_cm|image_url|Độ đầy đủ hồ sơ/i);
const emptyBio = await run('performer-empty-bio', await load('views/performer.js'), { params: { id: '10' }, query: { tab: 'bio' } });
assert.doesNotMatch(emptyBio, /Extra attributes|Thuộc tính|attrs|\{\}|null|undefined|NaN/, 'JOOXCR-339: the bio tab does not dump raw attributes');
const crawl = await run('crawl', await load('views/crawl.js'), { params: {}, query: {} });
assert.match(crawl, /JavBus/); assert.match(crawl, /600s/); assert.match(crawl, /Trình duyệt/); assert.match(crawl, /Trực tiếp/);
assert.match(crawl, /Avfan[\s\S]*?<td>—<\/td>/);
assert.doesNotMatch(crawl, /crawler:source-toggle|php artisan/, 'U-08: crawl copy does not tell a UI user to run artisan');
const keptSources = sources.splice(0, sources.length);
const crawlEmpty = await run('crawl-empty', await load('views/crawl.js'), { params: {}, query: {} });
assert.match(crawlEmpty, /Chưa có nguồn/, 'U-08: no sources is a real empty state');
assert.match(crawlEmpty, /#\/settings/, 'U-08: the empty crawl state links to Settings');
assert.doesNotMatch(crawlEmpty, /<table/, 'U-08: an empty crawl page does not render a header-only table');
sources.push(...keptSources);
sources[2].lane = 'future-lane';
assert.match(await run('crawl-unknown-lane', await load('views/crawl.js'), { params: {}, query: {} }), /Avfan[\s\S]*?<td>—<\/td>/);
delete sources[2].lane;
const s = await run('search', await load('views/search.js'), { params: {}, query: { q: 'Drama' } });
assert.match(s, /Kết quả/);
assert.match(s, /data-search-movie-total/);
assert.match(s, />480</, 'U-04: search shows the catalog total, not the page length');
const sm = await run('search-meaning', await load('views/search.js'), { params: {}, query: { q: 'Mankitsu night movies from 2024', mode: 'meaning' } });
assert.match(sm, /Tôi hiểu là/); assert.match(sm, /Mankitsu/); assert.match(s, /Danh mục khớp/); assert.match(s, /#\/facet\/maker\/Mankitsu/);
assert.match(sm, /data-search-fallback/, 'U-04: meaning mode says when it fell back to keywords');
assert.match(sm, /Tìm theo nghĩa chưa sẵn sàng/);
const searchLanding = await run('search-landing', await load('views/search.js'), { params: {}, query: {} });
assert.doesNotMatch(searchLanding, /Mankitsu|onsen|>office</, 'U-03: search examples are not dead chips');
assert.match(searchLanding, /School/, 'U-03: search examples use a genre that exists');
assert.match(searchLanding, /id="sq"[^>]*aria-label="/, 'U-17: the search-page box has an accessible name');
const searchShort = await run('search-short', await load('views/search.js'), { params: {}, query: { q: 'a' } });
assert.match(searchShort, /Nhập ít nhất 2 ký tự/, 'U-04: a one-character query is explained');
await run('facet-genre', await load('views/facet.js'), { params: { kind: 'genre', value: 'Drama' }, query: {} });
await run('facet-year', await load('views/facet.js'), { params: { kind: 'year', value: '2025' }, query: {} });
const cal = await run('calendar', await load('views/calendar.js'), { params: {}, query: { month: '2026-10' } });
assert.match(cal, /Lịch phát hành/); assert.match(cal, /AAA-001/);
const calDefault = await run('calendar-default', await load('views/calendar.js'), { params: {}, query: {} });
assert.match(calDefault, /AAA-001/, 'U-11: the calendar opens on a month that has releases');
const calEmpty = await run('calendar-empty', await load('views/calendar.js'), { params: {}, query: { month: '2026-11' } });
assert.match(calEmpty, /Tháng này chưa có phim/, 'U-11: an empty month is named as empty');
assert.match(calEmpty, /Tới tháng có phim/, 'U-11: an empty month offers a jump to a month with releases');
const adm = await run('admin', await load('views/admin.js'), { params: {}, query: {} });
assert.match(adm, /Chất lượng dữ liệu/); assert.match(adm, /Cấu hình dịch vụ/); assert.match(adm, /jvm_abc/); assert.ok(!adm.includes('<b>one</b>'), 'key labels are escaped');
assert.match(adm, /data-key-direction="next"/, 'the admin key list exposes more results');
assert.match(adm, /data-key-direction="previous" disabled/, 'the first key page cannot navigate backward');
assert.match(adm, /Chọn ít nhất một quyền/, 'U-07: the API key form requires a scope');
assert.doesNotMatch(adm, /khoá dùng được mọi thứ/, 'U-07: empty scopes are not advertised as full access');
assert.match(adm, /value="catalog" checked/, 'U-07: catalog is the default explicit scope');
emptyAdminKeys = true;
const admEmpty = await run('admin-empty-keys', await load('views/admin.js'), { params: {}, query: {} });
assert.match(admEmpty, /Chưa có\./, 'an empty active-key page shows the empty state');
emptyAdminKeys = false;
interactiveAdminKeys = Array.from({ length: 26 }, (_, index) => ({
  id: index + 1, prefix: `fixture-${index + 1}`, label: `Fixture key ${index + 1}`, status: 'active', abuse_rpm: 60,
  scopes: ['catalog'], created_at: '2026-10-24T00:00:00Z',
})).reverse();
interactiveAdminPageRequests = [];
const adminView = await load('views/admin.js');
const interactiveAdmin = await adminView.render({ params: {}, query: {} });
const interactiveNode = () => {
  const listeners = new Map();

  return {
    innerHTML: '', textContent: '', dataset: {}, disabled: false, listeners,
    addEventListener: (name, listener) => listeners.set(name, listener),
    querySelector: () => null,
  };
};
const adminNodes = new Map(['#kf', '#newkey', '#keyrows', '#usage', '#keyTotal', '#keyPagination', '#keyListError'].map(selector => [selector, interactiveNode()]));
const adminRootListeners = new Map();
const adminRoot = {
  querySelector: selector => adminNodes.get(selector) ?? null,
  addEventListener: (name, listener) => adminRootListeners.set(name, listener),
};
adminNodes.get('#keyrows').innerHTML = interactiveAdmin.html.match(/<tbody id="keyrows">([\s\S]*?)<\/tbody>/)?.[1] ?? '';
adminNodes.get('#keyTotal').textContent = interactiveAdmin.html.match(/<span id="keyTotal"[^>]*>([\s\S]*?)<\/span>/)?.[1] ?? '';
adminNodes.get('#keyPagination').innerHTML = interactiveAdmin.html.match(/<nav id="keyPagination"[^>]*>([\s\S]*?)<\/nav>/)?.[1] ?? '';
interactiveAdmin.mount(adminRoot);
const clickAdminPage = async (direction, disabled = false) => {
  const button = { dataset: { keyDirection: direction }, disabled };
  await adminNodes.get('#keyPagination').listeners.get('click')({
    target: { closest: selector => selector === '[data-key-direction]' ? button : null },
  });
};
const keyRows = adminNodes.get('#keyrows');
const keyTotal = adminNodes.get('#keyTotal');
const keyPagination = adminNodes.get('#keyPagination');
const keyListError = adminNodes.get('#keyListError');
assert.match(keyRows.innerHTML, /Fixture key 26/);
assert.match(keyPagination.innerHTML, /1\+/);
assert.match(keyPagination.innerHTML, /data-key-direction="previous" disabled/, 'the first page disables Previous');
failedAdminCursor = '2';
await clickAdminPage('next');
assert.deepEqual(interactiveAdminPageRequests.slice(-1), ['2'], 'Next sends the next keyset cursor');
assert.equal(keyListError.textContent, 'API key page unavailable', 'a failed page request is announced');
assert.match(keyRows.innerHTML, /Fixture key 26/, 'a failed request leaves the current rows visible');
assert.match(keyPagination.innerHTML, /1\+/, 'a failed request leaves the current page visible');
await clickAdminPage('next');
assert.match(keyRows.innerHTML, /Fixture key 1/, 'Next loads the second page');
assert.equal(keyListError.textContent, '', 'a successful retry clears the page error');
assert.match(keyPagination.innerHTML, /mono">2<\/span>/, 'the second page is marked as the last page');
assert.match(keyPagination.innerHTML, /data-key-direction="next" disabled/, 'the last page disables Next');
const requestsBeforeDisabledNext = interactiveAdminPageRequests.length;
await clickAdminPage('next', true);
assert.equal(interactiveAdminPageRequests.length, requestsBeforeDisabledNext, 'a disabled Next control does not request another page');
await clickAdminPage('previous');
assert.match(keyRows.innerHTML, /Fixture key 26/, 'Previous returns to the first page');
await clickAdminPage('next');
globalThis.confirm = () => true;
await adminRootListeners.get('click')({ target: { closest: selector => selector === '[data-revoke]' ? { dataset: { revoke: '1' } } : null } });
assert.doesNotMatch(keyRows.innerHTML, /data-revoke="1"/, 'revoking the last row refreshes the visible list');
assert.match(keyRows.innerHTML, /Fixture key 26/, 'revocation clamps the now-invalid last page to the first page');
assert.equal(keyTotal.textContent, '25', 'the list shows the bounded visible row count');
assert.match(keyPagination.innerHTML, /mono">1<\/span>/, 'revocation refreshes and clamps the current page');
interactiveAdminKeys = [];
const interactiveEmptyAdmin = await adminView.render({ params: {}, query: {} });
assert.match(interactiveEmptyAdmin.html, /Chưa có\./, 'an empty active-key page renders its empty state');
assert.match(interactiveEmptyAdmin.html, /<span id="keyTotal" class="chip mono">0<\/span>/, 'an empty active-key page shows no rows');
interactiveAdminKeys = null;
interactiveAdminPageRequests = [];
const lib = await run('library', await load('views/library.js'), { params: { kind: 'favourite' }, query: {} });
assert.match(lib, /Yêu thích/); assert.match(lib, /AAA-001/);
const feedHtml = await run('feed', await load('views/feed.js'), { params: {}, query: {} });
assert.match(feedHtml, /Theo dõi/);
assert.match(feedHtml, /data-feed-seen/, 'U-14: feed marks seen from an explicit control');
assert.match(feedHtml, /1 phim/, 'U-14: the feed subtitle counts the movies in the grid');
assert.match(out.movie, /data-mark/); assert.match(out.movie, /aria-pressed="true"/);
const bioHtml = await run('bio', await load('views/bio.js'), { params: {}, query: {} });
assert.match(bioHtml, /Hồ sơ diễn viên/); assert.match(bioHtml, /#\/performers\?cup=/);
const statsHtml = await run('stats', await load('views/stats.js'), { params: {}, query: {} });
assert.match(statsHtml, /Thống kê/); assert.match(statsHtml, /Phim theo năm/);
const libHtml = await run('library-page', await load('views/libraryPage.js'), { params: {}, query: {} });
assert.match(libHtml, /Thư viện/); assert.match(libHtml, /AAA-001/); assert.ok(!libHtml.includes('<b>a</b>'), 'folders are escaped');
assert.doesNotMatch(libHtml, /php artisan|JOOXCR-223/, 'U-09: library copy has no artisan or ticket jargon');
const dlHtml = await run('downloads', await load('views/downloads.js'), { params: {}, query: {} });
assert.match(dlHtml, /Tải về/); assert.match(dlHtml, /42\.5%/); assert.match(dlHtml, /data-links/); assert.ok(!dlHtml.includes('<b>x</b>'), 'names are escaped');
const st = await run('settings', await load('views/settings.js'), { params: {}, query: {} });
assert.doesNotMatch(st, /<kbd>g [hmpsc]<\/kbd>/, 'U-13: Settings lists only shortcuts that exist');
assert.match(st, /Ctrl\+K hoặc \//, 'U-13: the search chord is labelled Ctrl+K on this platform');
assert.match(st, /data-tab="crawl"/); assert.match(st, /data-pane="crawl"/); assert.match(st, /lưu trong cơ sở dữ liệu/); assert.match(st, /class="switch"/); assert.match(st, /queue\.feed_pool_limit/); assert.ok(!st.includes('<b>x</b>'), 'labels are escaped'); assert.match(st, /đã đặt, nhập để thay/);
assert.match(st, /data-settings-filter/, 'U-19: Settings has a name filter');
assert.match(st, /data-subtab="queue"/, 'U-19: the crawl tab is split into smaller panes');
assert.match(st, /logout-form/, 'U-21: sign-out is a form that can ask to confirm');
assert.match(st, /worker\.node_overrides/); assert.match(st, /textarea[^>]+data-type="json"/); assert.match(st, /áp dụng sau tối đa 900 giây/); assert.match(st, /env chuyển đổi/);
assert.match(st, /textarea id="st-crawl\.source_gap_overrides"[^>]+maxlength="10000"/);
assert.match(st, /<select id="st-queue\.feed_interval_seconds" data-key="queue\.feed_interval_seconds" data-type="int"/);
for (const value of [5, 10, 15, 30, 60]) assert.match(st, new RegExp(`<option value="${value}"${value === 10 ? ' selected' : ''}>${value}</option>`));
assert.match(st, /Báo cáo/);
assert.match(st, /report\.interval_minutes/);
settingsSample.data.groups.normalization = 'Performer normalization';
settingsSample.data.settings.push(
  { key: 'normalization.blood_types', group: 'normalization', label: 'Allowed blood types', description: 'A, B, O, AB', type: 'string_list', item_options: ['A', 'B', 'O', 'AB'], source: 'default', value: ['A', 'B', 'O', 'AB'] },
  { key: 'normalization.cups', group: 'normalization', label: 'Allowed cup sizes', description: 'A through K', type: 'string_list', item_options: ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'], source: 'default', value: ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] },
  { key: 'normalization.field_ranges', group: 'normalization', label: 'Performer profile field ranges', description: 'Measurement and date ranges.', type: 'json', max_length: 2000, value: { height_cm: { min: 100, max: 250 } } },
  { key: 'normalization.location_aliases', group: 'normalization', label: 'Japanese prefecture and city aliases', description: 'Canonical names and aliases.', type: 'json', max_length: 500000, value: { Tokyo: '東京都', '東京都': '東京都' } },
);
globalThis.innerWidth = 375;
const normalizationSettings = await run('normalization-settings', await load('views/settings.js'), { params: {}, query: {} });
assert.match(normalizationSettings, /Performer normalization/);
assert.match(normalizationSettings, /normalization\.blood_types/);
assert.match(normalizationSettings, /normalization\.cups/);
assert.match(normalizationSettings, /data-type="string_list" value="K" checked/);
assert.match(normalizationSettings, /textarea id="st-normalization\.location_aliases"[^>]+maxlength="500000"/);
assert.match(normalizationSettings, /style="min-width:min\(100%,420px\);font-family:monospace"/);
globalThis.innerWidth = 1280;
globalThis.innerWidth = 375;
const reportView = await load('views/report.js');
const reportHtml = await run('report', reportView, { params: {}, query: {} });
assert.match(reportHtml, /Xem trước trực tiếp/);
assert.match(reportHtml, /Nov 2, 18:00 \(Mexico\)/);
assert.match(reportHtml, /Chưa có báo cáo nào được gửi/);
assert.match(reportHtml, /Chưa có lịch sử gửi/);
assert.match(reportHtml, /data-report-send/);
assert.match(reportHtml, /<button class="btn sm" data-report-send>/, 'U-06: Telegram send is a secondary action');
assert.doesNotMatch(reportHtml, /data-report-send[^>]*pri/, 'U-06: Telegram send is not the primary button');
assert.match(reportHtml, /data-key="report\.interval_minutes"/);
assert.match(reportHtml, /data-key="report\.template"/);
assert.doesNotMatch(reportHtml, /min-width:220px|min-width:min\(100%,320px\)/, 'U-16: report inputs do not force a wider row');
assert.match(reportHtml, /class="ctrl"/, 'U-16: report controls sit in a shrinking slot');
globalThis.innerWidth = 1280;
const reportFetch = globalThis.fetch;
globalThis.fetch = async url => url.startsWith('/ui-admin/') ? Promise.reject(new Error('Offline')) : reportFetch(url);
assert.match((await reportView.render({ params: {}, query: {} })).html, /Offline/);
globalThis.fetch = reportFetch;
settingsSample.data.settings.push({ key: 'telegram.owner_user_id', group: 'telegram', label: 'User ID chủ sở hữu Telegram', description: 'Để trống cho chat riêng.', type: 'int', min: 1, source: 'default', value: null });
settingsSample.data.settings.push({ key: 'telegram.api_base', group: 'telegram', label: 'Địa chỉ API Telegram', description: 'HTTPS', type: 'string', source: 'default', value: 'https://api.telegram.org', warning: 'URL đã lưu không hợp lệ và đang được bỏ qua. <b>unsafe</b>' });
settingsSample.data.settings.push({ key: 'worker.queue_overrides', group: 'worker', label: 'Worker override', description: 'JSON worker layout', type: 'json', source: 'database', value: '{"listing":oops', warning: 'Giá trị đã lưu không hợp lệ.' });
const secureSettings = await run('secure-settings', await load('views/settings.js'), { params: {}, query: {} });
assert.match(secureSettings, /telegram\.owner_user_id/);
assert.match(secureSettings, /URL đã lưu không hợp lệ/);
assert.match(secureSettings, /role="alert"/);
assert.ok(secureSettings.includes('{&quot;listing&quot;:oops'), 'invalid JSON remains editable as its stored raw text');
assert.match(secureSettings, /Giá trị đã lưu không hợp lệ/);
assert.ok(!secureSettings.includes('<b>unsafe</b>'), 'URL warnings are escaped');
assert.match(secureSettings, /value="https:\/\/api.telegram.org"/);
settingsSample.data.settings.find(r => r.key === 'telegram.api_base').warning = null;
const repairedSettings = await run('repaired-settings', await load('views/settings.js'), { params: {}, query: {} });
assert.ok(!repairedSettings.includes('URL đã lưu không hợp lệ'), 'repaired URLs clear the warning');

settingsSample.data.settings.push({ key: 'perf.warm_enabled', group: 'queue', label: 'Làm ấm bộ nhớ đệm', description: 'Tự làm mới trạng thái và bộ lọc.', type: 'bool', source: 'default', value: true });
const perfSettings = await run('performance-settings', await load('views/settings.js'), { params: {}, query: {} });
assert.match(perfSettings, /perf\.warm_enabled/); assert.match(perfSettings, /Làm ấm bộ nhớ đệm/);

globalThis.innerWidth = 375;
const notificationPage = await run('notifications', await load('views/notifications.js'), { params: {}, query: {} });
assert.match(notificationPage, /Chính sách thông báo/); assert.match(notificationPage, /Danh sách thông báo/);
assert.match(notificationPage, /Tỷ lệ crawl thành công thấp/); assert.match(notificationPage, /Gửi gần nhất/);
assert.match(notificationPage, /Chế độ yên lặng/); assert.match(notificationPage, /Báo cáo định kỳ/); assert.match(notificationPage, /Báo cáo hằng ngày/);
assert.match(notificationPage, /Cảnh báo|Nghiêm trọng/, 'U-15: notification severity names are Vietnamese');
assert.doesNotMatch(notificationPage, />CRITICAL<|>WARNING<|>NOTICE</, 'U-15: English severity tokens stay off the list');
assert.match(notificationPage, /Lịch báo cáo hằng ngày/); assert.match(notificationPage, /Giờ gửi cục bộ/); assert.match(notificationPage, /Múi giờ IANA/);
assert.match(notificationPage, /data-daily-time/); assert.match(notificationPage, /data-daily-timezone/); assert.match(notificationPage, /save-daily-schedule/);
assert.ok(!notificationPage.includes('<script>'), 'notification labels are escaped');
const notificationFetch = globalThis.fetch;
globalThis.fetch = async url => url === '/ui-admin/report/daily' ? Promise.reject(new Error('Offline')) : notificationFetch(url);
const failedReportPreview = await run('notifications-preview-error', await load('views/notifications.js'), { params: {}, query: {} });
assert.match(failedReportPreview, /<pre class="err" role="alert" data-report-preview="\/ui-admin\/report\/daily">Offline<\/pre>/);
assert.match(failedReportPreview, /data-preview-report="\/ui-admin\/report\/daily">Thử lại/);
globalThis.fetch = notificationFetch;
const notificationCss = await readFile(new URL('../public/ui/app.css', import.meta.url), 'utf8');
assert.match(notificationCss, /@media\(max-width:480px\)/, 'notification editor has a narrow-screen layout');
assert.match(notificationCss, /\.notification-daily-schedule \.bd\{display:grid/, 'daily report settings fit narrow screens');
globalThis.innerWidth = 1280;

settingsSample.data.groups.search = 'Tìm kiếm';
settingsSample.data.settings.push({ key: 'search.budget_ms', group: 'search', label: 'Ngân sách tìm kiếm (mili giây)', description: 'Giới hạn HTTP tùy chọn.', type: 'int', min: 25, max: 1500, source: 'default', value: 300 });
const searchSettings = await run('search-settings', await load('views/settings.js'), { params: {}, query: {} });
assert.match(searchSettings, /search\.budget_ms/); assert.match(searchSettings, /Ngân sách tìm kiếm/);
settingsSample.data.groups.observability = 'Giám sát';
settingsSample.data.settings.push({ key: 'observability.fetch_attempts_retention_days', group: 'observability', label: 'Số ngày giữ lịch sử thử tải', description: 'Tự động xóa các lần thử tải cũ.', type: 'int', min: 1, source: 'default', value: 14 });
const attemptSettings = await run('fetch-attempt-settings', await load('views/settings.js'), { params: {}, query: {} });
assert.match(attemptSettings, /observability\.fetch_attempts_retention_days/); assert.match(attemptSettings, /Số ngày giữ lịch sử thử tải/); assert.match(attemptSettings, /value="14"/);
const cat = await run('catalog', await load('views/catalog.js'), { params: { kind: 'genres' }, query: {} });
assert.match(cat, /Thể loại/);
await run('catalog-years', await load('views/catalog.js'), { params: { kind: 'years' }, query: {} });
assert.match(await run('catalog-unknown', await load('views/catalog.js'), { params: { kind: 'nope' }, query: {} }), /không tồn tại/);

// a title with markup must never reach the page unescaped
const components = await import('../public/ui/js/components.js');
const movieList = components.movieCard(movie('XSS-1', { title_en: '<img src=x onerror=alert(1)>' }));
assert.ok(!movieList.includes('<img src=x'), 'titles are escaped');
const oneLinkCard = components.movieCard(movie('AAA-001'));
assert.equal([...oneLinkCard.matchAll(/href="#\/movie\/AAA-001"/g)].length, 1, 'U-17: a movie card has one link to the film');
assert.match(oneLinkCard, /alt="Title of AAA-001"/, 'U-17: the cover uses the title as its name');
assert.equal(components.notKnown(), '<span class="dim">chưa có</span>', 'U-20: missing data is only "chưa có"');
assert.doesNotMatch(components.notKnown(), /chờ crawl/);
await import('./search-filters.mjs');

// Keep user instructions tied to the current menus and filter labels (resolved via i18n catalogs).
const docsRoot = new URL('../docs/', import.meta.url);
const guideIndex = await readFile(new URL('user-guide/README.md', docsRoot), 'utf8');
const shellSource = await readFile(new URL('../public/ui/js/app.js', import.meta.url), 'utf8');
const navSource = shellSource.split('const NAV = [')[1].split('let cleanup')[0];
const menuKeys = [...navSource.matchAll(/\['[^']+', '([^']+)', '[^']+',/g)].map(m => m[1]);
assert.ok(menuKeys.length >= 20, 'read the full current NAV');
const { initI18n, setLocale, t } = await import('../public/ui/js/i18n.js');
await initI18n('vi');
await setLocale('vi'); // earlier suites may have left another locale active
const menuLabels = menuKeys.map(key => t(key));
for (const text of menuLabels) assert.ok(guideIndex.includes(text), 'user index covers menu: ' + text);
const { movieKeys, performerKeys, labels } = await import('../public/ui/js/filterState.js');
for (const [file, keys] of [['movies.md', movieKeys], ['performers.md', performerKeys]]) {
  const guide = await readFile(new URL('user-guide/' + file, docsRoot), 'utf8');
  for (const key of keys) assert.ok(guide.includes(key === 'sort' ? 'Xếp theo' : labels[key]), file + ' covers current filter: ' + labels[key]);
}
let guidePages = 0, checkedLinks = 0;
for (const folder of ['user-guide', 'developer-guide']) {
  for (const file of await readdir(new URL(folder + '/', docsRoot))) {
    if (!file.endsWith('.md')) continue;
    const url = new URL(folder + '/' + file, docsRoot);
    const text = await readFile(url, 'utf8');
    if (folder === 'user-guide' && file !== 'settings-reference.md') {
      assert.match(text, /## Khi nào dùng/, file + ' explains when to use it');
      assert.match(text, /## Nếu có vẻ sai/, file + ' explains recovery');
    }
    if (folder === 'user-guide' && file !== 'README.md') assert.ok(guideIndex.includes('](' + file + ')'), file + ' reachable from index');
    for (const link of text.matchAll(/\]\(([^)]+)\)/g)) {
      if (/^(https?:|#)/.test(link[1])) continue;
      await stat(new URL(link[1].split('#')[0], url)); checkedLinks++;
    }
    guidePages++;
  }
}
console.log(`Documentation: ${guidePages} pages, ${menuLabels.length} menu labels and ${checkedLinks} links verified.`);
console.log('UI render smoke: ' + Object.keys(out).length + ' views rendered, all assertions passed');

// Media queue: escaped errors, empty and failure states, retry action, narrow viewport.
const normalFetch = globalThis.fetch;
const jsonResponse = data => ({ ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => data });
{
  const previousFetch = globalThis.fetch;
  globalThis.fetch = async url => url.endsWith('/library') ? jsonResponse({ data: { files: 0, matched: 0, unmatched: 0, missing: 0, bytes: 0, by_folder: [], last_scan_at: null, configured: false } }) : url.startsWith('/ui-me/media/files') ? jsonResponse({ data: { data: [] } }) : previousFetch(url);
  const emptyLib = await run('library-empty', await load('views/libraryPage.js'), { params: {}, query: {} });
  assert.match(emptyLib, /Chưa cấu hình thư mục/);
  assert.doesNotMatch(emptyLib, /php artisan/, 'U-09: the empty library does not ask the owner to run artisan');
  globalThis.fetch = async url => url.endsWith('/status') ? jsonResponse({ data: { enabled: false, files_enabled: false } }) : previousFetch(url);
  const emptyDl = await run('downloads-empty', await load('views/downloads.js'), { params: {}, query: {} });
  assert.match(emptyDl, /Tải về đang tắt/);
  assert.doesNotMatch(emptyDl, /JOOXCR-223|php artisan/, 'U-09: downloads empty state has no ticket id');
  assert.match(emptyDl, /Cài đặt/, 'U-09: downloads empty state points at Settings');
  globalThis.fetch = previousFetch;
}
const mediaView = await load('views/media.js');
globalThis.innerWidth = 375;
globalThis.fetch = async url => url.startsWith('/ui-me/media/files') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => ({ data: { data: [{ id: 1, code: 'AAA-001', path: '/generated/video.mp4' }] } }) } : url.startsWith('/ui-me/media') ? jsonResponse({ data: { data: [{ id: 1, kind: 'scenes', library_file_id: 7, status: 'failed', attempts: 3, error: '<script>bad</script>' }], next_page_url: null } }) : normalFetch(url);
const mediaHtml = (await mediaView.render({ query: {} })).html;
assert.match(mediaHtml, /data-media-retry="1"/);
assert.match(mediaHtml, /&lt;script&gt;/);
assert.ok(!mediaHtml.includes('<script>'));
globalThis.fetch = async () => jsonResponse({ data: { data: [], next_page_url: null } });
assert.match((await mediaView.render({ query: {} })).html, /Chưa có việc/);
globalThis.fetch = async () => { throw new Error('Offline'); };
assert.match((await mediaView.render({ query: {} })).html, /Offline/);
globalThis.fetch = normalFetch;
globalThis.innerWidth = 1280;

assert.match(out.search, /<h2>Cảnh<\/h2>/);
assert.match(out.search, /&lt;b&gt;red room&lt;\/b&gt;/);

// Player behavior and rendered states use generated-media API fixtures.
const playerModule = await import('../public/ui/js/views/player.js');
const playerView = playerModule.default;
const playerData = { enabled: true, url: '/signed/video', library_file_id: 1, files: [{ id: 1, name: '<b>video</b>.mp4' }], subtitles: [{ id: 1, language: 'en', source: 'whisper', url: '/signed/en.vtt' }, { id: 2, language: 'vi', source: 'translation', url: '/signed/vi.vtt' }], scenes: [{ start_sec: 12, description: '<b>scene</b>' }], error: null };
globalThis.fetch = async () => jsonResponse({ data: playerData });
globalThis.innerWidth = 375;
const playerHtml = (await playerView.render({ params: { code: 'GEN-001' }, query: {} })).html;
assert.match(playerHtml, /<video controls/); assert.match(playerHtml, /kind="subtitles"/);
assert.match(playerHtml, /Tiếng Việt/); assert.match(playerHtml, /data-seek="12"/);
assert.ok(!playerHtml.includes('<b>scene</b>'));
const videoFake = { duration: 20, currentTime: 0, textTracks: [{ id: 'subtitle-1', mode: 'disabled' }, { id: 'subtitle-2', mode: 'disabled' }] };
playerModule.seek(videoFake, 12); assert.equal(videoFake.currentTime, 12);
playerModule.seek(videoFake, 99); assert.equal(videoFake.currentTime, 20);
playerModule.selectTrack(videoFake, 'subtitle-2'); assert.equal(videoFake.textTracks[1].mode, 'showing'); assert.equal(videoFake.textTracks[0].mode, 'disabled');
playerModule.selectTrack(videoFake, ''); assert.ok(videoFake.textTracks.every(t => t.mode === 'disabled'));
globalThis.fetch = async () => jsonResponse({ data: { ...playerData, enabled: false } });
assert.match((await playerView.render({ params: { code: 'GEN-001' } })).html, /Máy chủ tệp đang tắt/);
globalThis.fetch = async () => jsonResponse({ data: { enabled: true, url: null, files: [], error: 'Không có tệp trên đĩa.', library_file_id: null, subtitles: [], scenes: [] } });
const playerEmpty = (await playerView.render({ params: { code: 'SMP-0520' } })).html;
assert.match(playerEmpty, /Không có tệp trên đĩa/, 'U-18: a movie with no file is an empty state');
assert.match(playerEmpty, /#\/settings/, 'U-18: the empty player state links to Settings');
assert.doesNotMatch(playerEmpty, /Thử lại/, 'U-18: the empty player state does not offer a retry that will 404');
globalThis.fetch = async () => jsonResponse({ data: { ...playerData, url: null, error: 'Missing' } });
assert.match((await playerView.render({ params: { code: 'GEN-001' } })).html, /Không thể mở video/);
globalThis.fetch = async () => { throw new Error('Offline'); };
assert.match((await playerView.render({ params: { code: 'GEN-001' } })).html, /Offline/);
globalThis.fetch = normalFetch;
globalThis.innerWidth = 1280;

// Capture is visibly disabled until enabled in Settings, then exposes one explicit owner form.
globalThis.fetch = async () => jsonResponse({ data: { data: [], next_page_url: null }, capture_enabled: false });
assert.match((await mediaView.render({ query: {} })).html, /Ghi luồng đang tắt/);
globalThis.fetch = async () => jsonResponse({ data: { data: [{ id: 8, kind: 'capture', status: 'done', attempts: 1, output_url: '/signed/capture' }], next_page_url: null }, capture_enabled: true });
const captureHtml = (await mediaView.render({ query: {} })).html;
assert.match(captureHtml, /data-capture/); assert.match(captureHtml, /Mở tệp đã ghi/);
assert.ok(!captureHtml.includes('Tệp #undefined'));
globalThis.fetch = normalFetch;

// Replacement metacharacters in API data must remain literal when appending panels.
{
  const previousFetch = globalThis.fetch;
  const literal = '$& $$ $1';
  globalThis.fetch = async url => {
    if (url.startsWith('/ui-me/media/files')) return jsonResponse({ data: { data: [{ id: 1, code: null, path: '/generated/' + literal + '.mp4' }] } });
    if (url.startsWith('/ui-api/scenes/search')) return jsonResponse({ data: [{ movie_code: 'AAA-001', library_file_id: 1, start_sec: 12, description: literal }] });
    return previousFetch(url);
  };
  try {
    const library = await (await load('views/libraryPage.js')).render({ params: {}, query: {} });
    assert.ok(library.html.includes('/generated/$&amp; $$ $1.mp4'));
    const search = await (await load('views/search.js')).render({ params: {}, query: { q: 'literal' } });
    assert.ok(search.html.includes('$&amp; $$ $1'));
  } finally { globalThis.fetch = previousFetch; }
}

// Guest mode keeps public search readable and prevents personal reads, writes and media links.
{
  const guestApi = await import('../public/ui/js/api.js');
  const savedSearches = await import('../public/ui/js/savedSearches.js');
  const guestSearch = await load('views/search.js');
  const guestMovie = await load('views/movie.js');
  const calls = [];
  document.body.dataset.guestMode = 'true';
  globalThis.innerWidth = 375;
  globalThis.fetch = async url => {
    calls.push(url);
    if (url.startsWith('/ui-api/search')) return jsonResponse({ data: { movies: [movie('AAA-001')], performers: [], interpretation: { filters: {}, keywords: 'public', by: 'none' } } });
    if (url.startsWith('/ui-api/suggest')) return jsonResponse({ data: { genres: [], makers: [], series: [] } });
    if (String(url).includes('/downloads/search')) return { ok: true, status: 202, redirected: false, headers: { get: () => 'application/json' }, json: async () => ({ message: 'ok', data: { queued: true, downloads: { state: 'searching', available: false, count: 0 } } }) };
    if (url.startsWith('/ui-api/movies/')) return jsonResponse({ data: { ...movie('AAA-001'), magnets: [{ url: 'magnet:?xt=urn:btih:publicguestmagnet' }], downloads: { available: true, count: 1, state: 'found', search_enabled: true }, hls_stream_urls: ['https://cdn.example.com/video?signature=private'], gallery: ['https://cdn.example.com/image?signature=private'] } });
    throw new Error('unexpected guest request: ' + url);
  };

  const searchHtml = (await guestSearch.render({ query: { q: 'public' } })).html;
  assert.ok(!searchHtml.includes('<h2>Cảnh</h2>'));
  assert.ok(!calls.some(url => url.includes('/scenes/search')));
  assert.match(savedSearches.savedSearchesHtml(), /Đăng nhập để dùng tìm kiếm đã lưu/);
  assert.ok(!savedSearches.savedSearchesHtml().includes('data-save-search'));
  const callCount = calls.length;
  await assert.rejects(guestApi.admin('/ui-me/marks'), /Đăng nhập để dùng tính năng này/);
  await assert.rejects(guestApi.send('POST', '/ui-me/marks'), /Đăng nhập để dùng tính năng này/);
  await guestApi.send('POST', '/ui-api/movies/AAA-001/downloads/search', {});
  assert.ok(calls.some(url => String(url).includes('/downloads/search')), 'guests may start on-demand download search');
  assert.equal(calls.length, callCount + 1, 'guest personal writes stay blocked while download search is allowed');

  globalThis.location.hash = '#/movie/AAA-001?tab=media';
  const mediaHtml = (await guestMovie.render({ params: { code: 'AAA-001' }, query: { tab: 'media' } })).html;
  assert.match(mediaHtml, /Đăng nhập để dùng liên kết và nội dung media/);
  assert.ok(!mediaHtml.includes('signature=private'));
  globalThis.location.hash = '#/movie/AAA-001';
  const guestDetail = (await guestMovie.render({ params: { code: 'AAA-001' }, query: {} })).html;
  assert.match(guestDetail, /magnet:\?xt=urn:btih:publicguestmagnet/, 'guests see magnet links on the downloads panel');
  assert.match(guestDetail, /id="movie-downloads"/);
  assert.doesNotMatch(guestDetail, /data-magnet=|Gửi tới qBittorrent|Send to qBittorrent/, 'guest downloads panel has no send-to-client control');
  document.body.dataset.guestMode = 'false';
  globalThis.location.hash = '#/';
  globalThis.innerWidth = 1280;
  globalThis.fetch = normalFetch;
}

const guestStyles = await readFile(new URL('../public/ui/app.css', import.meta.url), 'utf8');
assert.match(guestStyles, /flex-wrap:wrap/);
assert.match(guestStyles, /body\[data-guest-mode="true"\].*\[data-mark\]/);

/* zoom viewer: opens on [data-zoom], rejects non-http sources, closes on Escape and restores focus */
{
  const listeners = {};
  const mk = () => {
    const nodes = {};
    const el = {
      hidden: false, className: '', style: {}, dataset: {}, attrs: {}, children: {}, focused: false, innerHTML: '', textContent: '', href: '', alt: '', src: 'x',
      setAttribute(k, v) { this.attrs[k] = v; }, addEventListener() {}, focus() { this.focused = true; },
      removeAttribute(k) { if (k === 'src') this.src = ''; },
      querySelector(sel) { return nodes[sel] ??= { ...mk.leaf(), src: 'x' }; },
      querySelectorAll(sel) { return sel.split(',').map(x => this.querySelector(x.trim())); },
    };
    return el;
  };
  mk.leaf = () => ({ focused: false, textContent: '', href: '', alt: '', focus() { this.focused = true; }, removeAttribute(k) { if (k === 'src') this.src = ''; } });
  const appended = [];
  const returnFocus = { focused: false, focus() { this.focused = true; } };
  const fakeBody = { style: {}, appendChild: n => appended.push(n) };
  const previous = globalThis.document;
  globalThis.document = { body: fakeBody, activeElement: returnFocus, createElement: () => mk(), addEventListener: (type, fn) => { (listeners[type] ??= []).push(fn); } };
  const lb = await import('../public/ui/js/lightbox.js');
  lb.initLightbox();
  lb.openLightbox('javascript:alert(1)', 'BAD');
  assert.equal(lb.isLightboxOpen(), false, 'non-http sources never open the viewer');
  lb.openLightbox('https://img.test/AAA-001.jpg', 'AAA-001');
  assert.equal(lb.isLightboxOpen(), true);
  assert.equal(fakeBody.style.overflow, 'hidden', 'page scroll is locked while open');
  const dialog = appended[0];
  const closeBtn = dialog.querySelector('[data-lb-close]'), openLink = dialog.querySelector('[data-lb-open]');
  globalThis.document.activeElement = closeBtn;
  for (const fn of listeners.keydown) fn({ key: 'Tab', shiftKey: false, preventDefault() {} });
  assert.equal(openLink.focused, true, 'Tab moves focus to the original-image link');
  globalThis.document.activeElement = openLink; closeBtn.focused = false;
  for (const fn of listeners.keydown) fn({ key: 'Tab', shiftKey: false, preventDefault() {} });
  assert.equal(closeBtn.focused, true, 'Tab wraps back to the close button');
  for (const fn of listeners.keydown) fn({ key: 'Escape', preventDefault() {} });
  assert.equal(lb.isLightboxOpen(), false, 'Escape closes the viewer');
  assert.equal(fakeBody.style.overflow, '', 'page scroll is restored');
  assert.equal(returnFocus.focused, true, 'focus returns to the cover');
  globalThis.document = previous;
}
const cssText = await readFile(new URL('../public/ui/app.css', import.meta.url), 'utf8');
assert.doesNotMatch(cssText, /\.wrap\{max-width:1400px/, 'content width is fluid, not capped at 1400px');
assert.match(cssText, /\.cols\.detail\{grid-template-columns:minmax\(0,clamp\(340px,32vw,620px\)\)/);
assert.match(cssText, /Noto Sans CJK/, 'U-05: the font stack names a CJK face');
assert.match(cssText, /Meiryo/, 'U-05: the font stack includes Meiryo');
assert.match(cssText, /Yu Gothic/, 'U-05: the font stack includes Yu Gothic');
assert.doesNotMatch(cssText, /fonts\.googleapis/, 'U-05: CSS does not load an external font CDN');
assert.match(cssText, /--dim:#74808c/, 'U-12: dark dim text meets AA');
assert.match(cssText, /--dim:#616b76/, 'U-12: light dim text meets AA');
assert.match(cssText, /--accent:#0a736b/, 'U-12: light primary contrast meets AA');
assert.match(cssText, /\.bnav a\.on/, 'U-10: the active bottom-nav link is styled');
assert.match(cssText, /\.omni input\[type=text\]\{padding-right:12px\}/, 'U-10: phone search uses the leftover width');
assert.match(cssText, /\.pill-live,\.omni kbd,\.top \.sp\{display:none\}/, 'U-10: the top spacer does not steal phone search width');
const indexHtml = await readFile(new URL('../public/ui/index.html', import.meta.url), 'utf8');
assert.doesNotMatch(indexHtml, /fonts\.googleapis|fonts\.gstatic/, 'U-05: the shell does not load an external font CDN');
const confirmSource = await readFile(new URL('../public/ui/js/confirm.js', import.meta.url), 'utf8');
assert.match(confirmSource, /setAttribute\('role', 'dialog'\)/, 'U-06: confirm is an accessible dialog');
assert.match(confirmSource, /aria-modal/, 'U-06: confirm is modal');
const reportSource = await readFile(new URL('../public/ui/js/views/report.js', import.meta.url), 'utf8');
const notifySource = await readFile(new URL('../public/ui/js/views/notifications.js', import.meta.url), 'utf8');
assert.match(reportSource, /askConfirm/, 'U-06: report send asks for confirmation');
assert.match(notifySource, /askConfirm/, 'U-06: notification test/reset ask for confirmation');
const appSource = await readFile(new URL('../public/ui/js/app.js', import.meta.url), 'utf8');
assert.match(appSource, /data-dlg-close/, 'U-13: the help dialog has a close button');
assert.match(appSource, /me-favourite/, 'U-10: Yêu thích is on the phone bar');
const viCatalog = JSON.parse(await readFile(new URL('../public/ui/i18n/vi.json', import.meta.url), 'utf8'));
assert.equal(viCatalog['nav.discover'], 'Khám phá', 'U-15: Discover is labelled Khám phá');
assert.match(appSource, /\['home', 'nav.discover'/, 'U-15: Discover is labelled Khám phá'.replace(/:.*/, ': the menu uses its translated label'));
assert.equal(viCatalog['nav.makers'], 'Hãng', 'U-15: Maker is labelled Hãng');
assert.match(appSource, /\['catalog-makers', 'nav.makers'/, 'U-15: Maker is labelled Hãng'.replace(/:.*/, ': the menu uses its translated label'));
assert.equal(viCatalog['nav.series'], 'Loạt phim', 'U-15: Series is labelled Loạt phim');
assert.match(appSource, /\['catalog-series', 'nav.series'/, 'U-15: Series is labelled Loạt phim'.replace(/:.*/, ': the menu uses its translated label'));
assert.equal(viCatalog['nav.labels'], 'Nhãn', 'U-15: Label is labelled Nhãn');
assert.match(appSource, /\['catalog-labels', 'nav.labels'/, 'U-15: Label is labelled Nhãn'.replace(/:.*/, ': the menu uses its translated label'));
assert.equal(viCatalog['nav.crawl'], 'Thu thập & nguồn', 'U-15: crawl nav is Vietnamese');
assert.match(appSource, /\['crawl', 'nav.crawl'/, 'U-15: crawl nav is Vietnamese'.replace(/:.*/, ': the menu uses its translated label'));
assert.equal(viCatalog['nav.ops'], 'Vận hành', 'U-15: operations nav drops Command Center jargon');
assert.match(appSource, /\['ops', 'nav.ops'/, 'U-15: operations nav drops Command Center jargon'.replace(/:.*/, ': the menu uses its translated label'));
const marksSource = await readFile(new URL('../public/ui/js/marks.js', import.meta.url), 'utf8');
assert.match(marksSource, /toast\(/, 'U-14: toggleMark toasts the result');
const savedSource = await readFile(new URL('../public/ui/js/savedSearches.js', import.meta.url), 'utf8');
assert.match(savedSource, /details\.open = true/, 'U-14: saving a filter opens the saved list');
assert.match(savedSource, /askConfirm/, 'U-14: deleting a saved filter asks to confirm');
const feedSource = await readFile(new URL('../public/ui/js/views/feed.js', import.meta.url), 'utf8');
assert.doesNotMatch(feedSource, /mount: \(\) => \{ send\('POST', '\/ui-me\/feed\/seen'/, 'U-14: opening the feed does not silently mark it seen');
const homeSource = await readFile(new URL('../public/ui/js/views/home.js', import.meta.url), 'utf8');
assert.match(homeSource, /prefers-reduced-motion/, 'U-21: countUp respects reduced motion');
const settingsSource = await readFile(new URL('../public/ui/js/views/settings.js', import.meta.url), 'utf8');
assert.match(settingsSource, /askConfirm/, 'U-21: sign-out asks for confirmation');
assert.match(settingsSource, /CRAWL_SUBS/, 'U-19: the crawl tab is split');
assert.match(cssText, /\.mc-main/, 'U-17: the movie card uses one main link');
assert.match(cssText, /\.row-i \.ctrl/, 'U-16: report/settings controls shrink with the row');

const loginController = await readFile(new URL('../app/Http/Controllers/Operations/LoginController.php', import.meta.url), 'utf8');
const loginBlade = await readFile(new URL('../resources/views/operations/login.blade.php', import.meta.url), 'utf8');
assert.doesNotMatch(loginController, /soulevilx@gmail\.com/, 'U-01: the sign-in controller must not hardcode a real email');
assert.doesNotMatch(loginController, /->with\('email'/, 'U-01: the sign-in view is not given a default email');
assert.doesNotMatch(loginBlade, /old\('email',\s*\$email\)/, 'U-01: the email field is not prefilled from a controller default');
assert.match(loginBlade, /value="\{\{\s*old\('email'\)\s*\}\}"/, 'U-01: the email field uses only flashed old input');
assert.match(loginBlade, /lang="\{\{\s*str_replace\('_', '-', app\(\)->getLocale\(\)\)\s*\}\}"/, 'U-02: the sign-in page follows the active locale');
assert.match(loginBlade, /__\('auth\.title'\)/, 'U-02: the sign-in tab title is translated');
assert.match(loginBlade, /__\('auth\.heading'\)/, 'U-02: the sign-in heading is translated');
assert.doesNotMatch(loginBlade, /Operations sign in|JavMeta Operations|Command Center/, 'U-02: operations jargon stays off the front door');
assert.match(loginBlade, /__\('auth\.intro'\)/, 'U-02: the front door explains that the catalog is private');
assert.match(loginBlade, /id="pw-toggle"/, 'U-02: the password can be revealed');
assert.match(loginController, /__\('auth\.failed'\)/, 'U-02: a wrong password uses a translated message');
const authVi = await readFile(new URL('../lang/vi/auth.php', import.meta.url), 'utf8');
assert.match(authVi, /riêng tư/, 'U-02: Vietnamese auth copy still explains privacy');
assert.match(authVi, /Đăng nhập · XCrawler/, 'U-02: Vietnamese auth title names XCrawler');

// Bilingual render smoke (JOOXCR-410)
{
  const { initI18n, setLocale, t, currentLocale } = await import('../public/ui/js/i18n.js');
  await initI18n('vi');
  assert.equal(currentLocale(), 'vi');
  assert.match(t('nav.settings'), /Cài đặt/);
  const homeVi = await (await load('views/home.js')).render({ params: {}, query: {} });
  assert.match(homeVi.html, /phim trong kho/);
  await setLocale('en');
  assert.equal(document.documentElement.lang, 'en');
  assert.match(t('nav.settings'), /Settings/);
  const homeEn = await (await load('views/home.js')).render({ params: {}, query: {} });
  assert.match(homeEn.html, /movies in the collection/);
  console.log('Bilingual render smoke: vi and en home views OK');
}
