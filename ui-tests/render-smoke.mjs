// Renders every UI view against sample API responses (no browser, no network): `node ui-tests/render-smoke.mjs`.
import assert from 'node:assert/strict';
import { readFile, readdir, stat } from 'node:fs/promises';

const store = {};
globalThis.localStorage = { getItem: k => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); } };
globalThis.location = { hash: '#/', href: '', reload() {} };
globalThis.innerWidth = 1280;
globalThis.document = { querySelector: () => null, body: { contains: () => true, dataset: { guestMode: 'false' } }, documentElement: { dataset: {} }, addEventListener() {}, createElement: () => ({ append() {}, remove() {} }) };
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
  { slug: 'javdb', name: 'JavDB', lane: 'direct', enabled: true, gap_seconds_current: 5, gap_seconds_min: 4, gap_seconds_max: 60, last_success_at: new Date().toISOString(), last_error_at: null, last_error: null, queue: { pending: 3, claimed: 1, done: 20, failed: 0 } },
  { slug: 'javbus', name: 'JavBus', lane: 'browser', enabled: true, gap_seconds_current: 600, gap_seconds_min: 4, gap_seconds_max: 60, last_success_at: null, last_error_at: new Date().toISOString(), last_error: 'All fetch methods exhausted', queue: { pending: 90, claimed: 0, done: 0, failed: 4 } },
  { slug: 'avfan', name: 'Avfan', enabled: false, gap_seconds_current: 5, gap_seconds_min: 2, gap_seconds_max: 30, last_success_at: null, last_error_at: null, last_error: null, queue: { pending: 0, claimed: 0, done: 0, failed: 0 } },
];

const route = url => {
  const u = new URL(url, 'https://x.test');
  const p = u.pathname.replace('/ui-api/', '');
  if (p === 'movies/facets') return { data: { total: u.searchParams.get('q') === 'empty' ? 0 : 12, genres: [{ name: 'Drama', count: 12 }, { name: '<b>School</b>', count: 3 }], makers: [{ name: 'Maker One', count: 10 }], series: [{ name: 'Series One', count: 9 }], labels: [{ name: 'Label One', count: 8 }], years: [{ name: '2025', count: 7 }], sources: [{ name: 'javdb', count: 6 }], performers: [{ id: 92, name: 'Eimi <b>x</b>', count: 5 }] } };
  if (p === 'performers/facets') return { data: { total: 7, cups: [{ name: 'D', count: 5 }], blood_types: [{ name: 'O', count: 4 }], locations: [{ name: 'Tokyo', count: 3 }], sources: [{ name: 'javdb', count: 2 }], status: [{ name: 'active', count: 7 }], with_image: 6, with_bio: 4 } };
  if (p === 'scenes/search') return { data: [{ movie_code: 'AAA-001', library_file_id: 1, start_sec: 12, description: '<b>red room</b>', thumb_url: null }] };
  if (p === 'status/summary') return { data: summary };
  if (p === 'status/sources') return { data: sources };
  if (p === 'movies') return { data: [movie('AAA-001'), movie('BBB-002', { title_en: '<script>alert(1)</script>' })], ...pg(2) };
  if (p.startsWith('movies/') && !p.endsWith('/related') && p !== 'movies/calendar') return { data: { ...movie(decodeURIComponent(p.slice(7))), codes: [{ code: 'AAA-001', kind: 'display' }], directors: [], description: 'A story', attrs: { k: 'v' }, magnets: [{ url: 'magnet:?xt=1' }], hls_stream_urls: [], gallery: [], photos: {}, crawled_at: '2026-10-24T00:00:00Z', delisted_at: null } };
  if (['cups', 'blood_types', 'locations'].includes(p.replace('meta/catalog/', ''))) return { data: [{ name: 'D', performer_count: 30 }, { name: 'E', performer_count: 9 }], meta: { pagination: { total: 2, per_page: 24, page: 1, last_page: 1 } } };
  if (p.startsWith('meta/catalog/')) return { data: [{ name: '2025', movie_count: 40 }, { name: 'School', movie_count: 12 }], meta: { pagination: { total: 2, per_page: 60, page: 1, last_page: 1, sort: 'movie_count' } } };
  if (p === 'search') return { data: { movies: [movie('AAA-001'), movie('BBB-002')], performers: [performer(1)], interpretation: { keywords: 'night', filters: { maker: 'Mankitsu', released_from: '2024-01-01' }, by: 'rules' } } };
  if (p === 'suggest') return { data: { movies: [], performers: [{ id: 92, name: 'Eimi <b>x</b>', movie_count: 5 }], genres: [{ name: 'Drama', movie_count: 40 }], makers: [{ name: 'Mankitsu', movie_count: 9 }], series: [] } };
  if (p === 'movies/calendar') return { data: [{ date: '2026-10-05', count: 5, movies: [{ code: 'AAA-001', title: 'T', cover_url: null, maker: 'M' }] }], meta: { month: '2026-10', total: 5 } };
  if (p.endsWith('/related')) return { data: [movie('REL-001')] };
  if (p === 'performers') return { data: [performer(1), performer(2)], ...pg(2) };
  if (p.startsWith('performers/')) return { data: performer(1) };
  throw new Error('no sample for ' + p);
};
const settingsSample = {
  data: {
    groups: {
      queue: 'Hàng đợi', crawl: 'Vòng lặp crawl', worker: 'Worker', browser: 'Trình duyệt',
      cleanup: 'Dọn dẹp', timeouts: 'Thời gian chờ', telegram: 'Telegram', report: 'Báo cáo',
      follow_notifications: 'Thông báo theo dõi',
    },
    settings: [
      { key: 'queue.feed_pool_limit', group: 'queue', label: 'Việc nạp mỗi phút', description: 'd', type: 'int', min: 1, max: 5000, restart: null, apply_after_seconds: 30, source: 'database', value: 300, is_set: null },
      { key: 'queue.feed_interval_seconds', group: 'queue', label: 'Chu kỳ nạp hàng đợi (giây)', description: 'Chọn 5, 10, 15, 30 hoặc 60 giây.', type: 'int', min: 5, max: 60, options: [5, 10, 15, 30, 60], apply_after_seconds: 5, source: 'database', value: 10 },
      { key: 'crawl.source_gap_overrides', group: 'crawl', label: 'Khoảng request ghi đè theo nguồn (JSON)', description: 'Khoảng gap theo từng nguồn.', type: 'json', shape: 'source_gap_override_map', max_length: 10000, apply_after_seconds: 30, source: 'default', value: {} },
      { key: 'worker.node_overrides', group: 'worker', label: 'Ghi đè node', description: 'JSON worker layout', type: 'json', shape: 'node_worker_map', max_length: 500, apply_after_seconds: 900, source: 'environment', value: { main: { default: 2 } }, is_set: null },
      { key: 'telegram.bot_token', group: 'telegram', label: 'Token <b>x</b>', description: 'd', type: 'secret', min: null, max: null, restart: null, source: 'default', value: null, is_set: true },
      { key: 'report.enabled', group: 'report', label: 'Bật báo cáo định kỳ', description: 'Lịch tự động qua Telegram.', type: 'bool', value: true },
      { key: 'report.interval_minutes', group: 'report', label: 'Chu kỳ gửi báo cáo', description: 'Theo múi giờ đã chọn.', type: 'int', options: [15, 30, 60, 120, 180, 360, 720, 1440], value: 120 },
      { key: 'report.timezone', group: 'report', label: 'Múi giờ báo cáo', description: 'Tên múi giờ IANA.', type: 'string', value: 'America/Mexico_City' },
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
const adminSample = u => {
  if (u.includes('/ui-admin/settings')) return settingsSample;
  if (u.includes('/ui-admin/notifications')) return { data: [{ key: 'crawl.success_rate_low', name: 'Tỷ lệ crawl thành công thấp', usage: 'Gửi khi tỷ lệ thành công xuống dưới ngưỡng.', channel: 'telegram', enabled: true, last_sent_at: '2026-11-02 17:40:00', updated_at: '2026-11-02 17:00:00' }] };
  if (u.includes('/ui-admin/report/periodic')) return reportSample;
  if (u.includes('report/quality')) return { data: { movies: 100, performers: 50, movies_by_source: { javdb: 60 }, core_fields: { code: { filled: 100, percent: 100 }, maker: { filled: 80, percent: 80 } }, movies_with_8_of_11: { count: 70, percent: 70 }, completeness_tiers: { 1: 20, 2: 80 }, needs_review: 3 } };
  return { data: [{ id: 1, prefix: 'jvm_abc', label: 'Key <b>one</b>', status: 'active', abuse_rpm: 60, scopes: ['catalog'], created_at: '2026-10-24T00:00:00Z' }] };
};
const libSample = u => u.endsWith('/library') ? { data: { files: 3, matched: 2, unmatched: 1, missing: 0, bytes: 5012, by_folder: [{ folder: '/media/<b>a</b>', files: 3, bytes: 5012 }], last_scan_at: '2026-10-24 03:00:00', configured: true } } : u.includes('/codes') ? { data: ['AAA-001'] } : u.includes('/on-disk') ? { data: [{ code: 'AAA-001', files: 1, bytes: 5000 }] } : { data: [{ path: '/media/a/x.mp4', size: 7, folder: '/media/a' }], meta: { total: 1, page: 1, per_page: 100 } };
const dlSample = u => u.endsWith('/status') ? { data: { enabled: true, files_enabled: true } } : { data: { items: [{ hash: 'aa', name: 'Video <b>x</b>', movie_code: 'AAA-001', state: 'downloading', progress: 42.5, size: 1e9, speed: 2e6, eta: 600, completed: false }, { hash: 'bb', name: 'Done', movie_code: null, state: 'completed', progress: 100, size: 2e9, speed: 0, eta: null, completed: true }] } };
globalThis.fetch = async url => url.startsWith('/ui-me/media/files') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => ({ data: { data: [{ id: 1, code: 'AAA-001', path: '/generated/video.mp4' }] } }) } : url.startsWith('/ui-me/downloads') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => dlSample(url) } : url.startsWith('/ui-me/library') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => libSample(url) } : url.startsWith('/ui-me/') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => (url.includes('/marks') ? { data: [{ kind: 'favourite', subject: 'movie', key: 'AAA-001' }] } : { data: [movie('AAA-001')], meta: { follows: 2, new_since_last_visit: 1, total: 1 } }) } : url.startsWith('/ui-admin/') ? { ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => adminSample(url) } : ({ ok: true, status: 200, redirected: false, headers: { get: () => 'application/json' }, json: async () => route(url) });

const load = async f => (await import('../public/ui/js/' + f)).default;
const out = {};
const run = async (name, view, ctx) => { const r = await view.render(ctx); assert.ok(r.html && r.html.length > 100, name + ' renders html'); out[name] = r.html; return r.html; };

const { toApi } = await import('../public/ui/js/views/movies.js');
assert.deepEqual(toApi({ q: 'ab', year: '2025', rmin: '90', genre: 'Drama' }), { q: 'ab', genre: 'Drama', released_from: '2025-01-01', released_to: '2025-12-31', runtime_min: '90' });
assert.deepEqual(toApi({ from: '2020', to: '2022' }), { released_from: '2020-01-01', released_to: '2022-12-31' });

const home = await run('home', await load('views/home.js'), { params: {}, query: {} });
assert.match(home, /phim trong DB/); assert.match(home, /data-to="4469"/); assert.match(home, /Hỏng|Tắt|Ổn/);
await run('movies', await load('views/movies.js'), { params: {}, query: { genre: 'Drama' } });
assert.match(out.movies, /Bộ lọc/); assert.match(out.movies, /data-drop="genre" data-value="Drama"/); assert.match(out.movies, /Thể loại: Drama/);
const detail = await run('movie', await load('views/movie.js'), { params: { code: 'AAA-001' }, query: {} });
assert.match(detail, /Title of AAA-001/); assert.match(detail, /class="cols detail"/, 'detail uses the fluid cover column'); assert.match(detail, /data-zoom="https:\/\/img\.test\/AAA-001\.jpg"/, 'cover opens the zoom viewer'); assert.match(detail, /role="button"[^>]*aria-label="Phóng to ảnh bìa AAA-001"/); assert.match(detail, /Eimi Fukada/); assert.match(detail, /#\/facet\/genre\/Drama/);
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
const crawl = await run('crawl', await load('views/crawl.js'), { params: {}, query: {} });
assert.match(crawl, /JavBus/); assert.match(crawl, /600s/); assert.match(crawl, /Trình duyệt/); assert.match(crawl, /Trực tiếp/);
assert.match(crawl, /Avfan[\s\S]*?<td>—<\/td>/);
sources[2].lane = 'future-lane';
assert.match(await run('crawl-unknown-lane', await load('views/crawl.js'), { params: {}, query: {} }), /Avfan[\s\S]*?<td>—<\/td>/);
delete sources[2].lane;
const s = await run('search', await load('views/search.js'), { params: {}, query: { q: 'Drama' } });
assert.match(s, /Kết quả/);
const sm = await run('search-meaning', await load('views/search.js'), { params: {}, query: { q: 'Mankitsu night movies from 2024', mode: 'meaning' } });
assert.match(sm, /Tôi hiểu là/); assert.match(sm, /Mankitsu/); assert.match(s, /Danh mục khớp/); assert.match(s, /#\/facet\/maker\/Mankitsu/);
await run('facet-genre', await load('views/facet.js'), { params: { kind: 'genre', value: 'Drama' }, query: {} });
await run('facet-year', await load('views/facet.js'), { params: { kind: 'year', value: '2025' }, query: {} });
const cal = await run('calendar', await load('views/calendar.js'), { params: {}, query: { month: '2026-10' } });
assert.match(cal, /Lịch phát hành/); assert.match(cal, /AAA-001/);
const adm = await run('admin', await load('views/admin.js'), { params: {}, query: {} });
assert.match(adm, /Chất lượng dữ liệu/); assert.match(adm, /Cấu hình dịch vụ/); assert.match(adm, /jvm_abc/); assert.ok(!adm.includes('<b>one</b>'), 'key labels are escaped');
const lib = await run('library', await load('views/library.js'), { params: { kind: 'favourite' }, query: {} });
assert.match(lib, /Yêu thích/); assert.match(lib, /AAA-001/);
assert.match(await run('feed', await load('views/feed.js'), { params: {}, query: {} }), /Theo dõi/);
assert.match(out.movie, /data-mark/); assert.match(out.movie, /aria-pressed="true"/);
const bioHtml = await run('bio', await load('views/bio.js'), { params: {}, query: {} });
assert.match(bioHtml, /Hồ sơ diễn viên/); assert.match(bioHtml, /#\/performers\?cup=/);
const statsHtml = await run('stats', await load('views/stats.js'), { params: {}, query: {} });
assert.match(statsHtml, /Thống kê/); assert.match(statsHtml, /Phim theo năm/);
const libHtml = await run('library-page', await load('views/libraryPage.js'), { params: {}, query: {} });
assert.match(libHtml, /Thư viện/); assert.match(libHtml, /AAA-001/); assert.ok(!libHtml.includes('<b>a</b>'), 'folders are escaped');
const dlHtml = await run('downloads', await load('views/downloads.js'), { params: {}, query: {} });
assert.match(dlHtml, /Tải về/); assert.match(dlHtml, /42\.5%/); assert.match(dlHtml, /data-links/); assert.ok(!dlHtml.includes('<b>x</b>'), 'names are escaped');
const st = await run('settings', await load('views/settings.js'), { params: {}, query: {} });
assert.match(st, /Cấu hình hệ thống/); assert.match(st, /queue\.feed_pool_limit/); assert.ok(!st.includes('<b>x</b>'), 'labels are escaped'); assert.match(st, /đã đặt, nhập để thay/);
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
assert.match(reportHtml, /data-key="report\.interval_minutes"/);
assert.match(reportHtml, /data-key="report\.template"/);
assert.match(reportHtml, /max-width:100%/);
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
assert.match(notificationPage, /Thông báo/); assert.match(notificationPage, /Danh sách thông báo/);
assert.match(notificationPage, /Tỷ lệ crawl thành công thấp/); assert.match(notificationPage, /Gửi gần nhất/);
assert.ok(!notificationPage.includes('<script>'), 'notification labels are escaped');
const notificationCss = await readFile(new URL('../public/ui/app.css', import.meta.url), 'utf8');
assert.match(notificationCss, /@media\(max-width:480px\)/, 'notification editor has a narrow-screen layout');
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
const movieList = (await import('../public/ui/js/components.js')).movieCard(movie('XSS-1', { title_en: '<img src=x onerror=alert(1)>' }));
assert.ok(!movieList.includes('<img src=x'), 'titles are escaped');
await import('./search-filters.mjs');

// Keep user instructions tied to the current menus and filter labels.
const docsRoot = new URL('../docs/', import.meta.url);
const guideIndex = await readFile(new URL('user-guide/README.md', docsRoot), 'utf8');
const shellSource = await readFile(new URL('../public/ui/js/app.js', import.meta.url), 'utf8');
const navSource = shellSource.split('const NAV = [')[1].split('let cleanup')[0];
const menuLabels = [...navSource.matchAll(/\['[^']+', '([^']+)', '[^']+',/g)].map(m => m[1]);
assert.ok(menuLabels.length >= 20, 'read the full current NAV');
for (const label of menuLabels) assert.ok(guideIndex.includes(label), 'user index covers menu: ' + label);
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
    if (url.startsWith('/ui-api/movies/')) return jsonResponse({ data: { ...movie('AAA-001'), magnets: [{ url: 'magnet:?private' }], hls_stream_urls: ['https://cdn.example.com/video?signature=private'], gallery: ['https://cdn.example.com/image?signature=private'] } });
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
  assert.equal(calls.length, callCount, 'guest requests do not call personal reads or write endpoints');

  globalThis.location.hash = '#/movie/AAA-001?tab=media';
  const mediaHtml = (await guestMovie.render({ params: { code: 'AAA-001' }, query: { tab: 'media' } })).html;
  assert.match(mediaHtml, /Đăng nhập để dùng liên kết và nội dung media/);
  assert.ok(!mediaHtml.includes('magnet:?private'));
  assert.ok(!mediaHtml.includes('signature=private'));
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
      hidden: false, className: '', style: {}, attrs: {}, children: {}, focused: false, innerHTML: '', textContent: '', href: '', alt: '', src: 'x',
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
