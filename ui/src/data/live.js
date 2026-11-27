// Live data layer: same exports the pages already use, but every value comes from the REST API (no static snapshot).
import { get, send, setCsrf, ApiError } from './api.js'; import { t, tn, locale } from '../i18n/index.js';
export { ApiError };
export const lag = async () => {};
const norm = s => (s || '').toString().toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '');
export const fmt = n => (n == null ? '—' : Number(n).toLocaleString(locale()));
export const fmtBytes = b => (b == null ? '' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : b >= 1e6 ? Math.round(b / 1e6) + ' MB' : Math.round(b / 1e3) + ' KB');
export const ago = iso => { if (!iso) return '—'; const s = (Date.now() - new Date(iso)) / 1000; if (s < 90) return tn('time.secondsAgo', Math.round(s)); if (s < 5400) return tn('time.minutesAgo', Math.round(s / 60)); if (s < 129600) return tn('time.hoursAgo', Math.round(s / 3600)); return tn('time.daysAgo', Math.round(s / 86400)); };
export const ageOf = b => { if (!b) return null; const d = new Date(b), n = new Date(); let a = n.getFullYear() - d.getFullYear(); if (n < new Date(n.getFullYear(), d.getMonth(), d.getDate())) a--; return a; };

// ---------- entities (one copy per movie / performer, shared by every list that shows it)
const M = new Map(); const P = new Map();
export function adaptMovie(a) {
  const prev = M.get(a.code);
  const m = Object.assign(prev || {}, {
    id: a.code, code: a.code, jp: a.title_jp, en: a.title_en || (a.title !== a.title_jp ? a.title : null), date: a.release_date, year: a.release_date ? +String(a.release_date).slice(0, 4) : null, runtime: a.runtime_minutes, censored: a.censored,
    maker: a.maker, label: a.label, series: a.series, score: a.score, tier: a.quality_tier, genres: a.genres || [], perfs: a.performer_ids || [], sources: a.sources || [], magnets: a.download_count ?? prev?.magnets ?? 0,
    hls: a.hls_count || 0, galleryCount: a.gallery_count || 0, cover: a.cover_thumb_url || a.cover_url, coverFull: a.cover_url, crawled: a.crawled_at, needsReview: a.needs_review, delisted: a.delisted, dl: prev?.dl || null, watch: prev?.watch || null, srcs: prev?.srcs || null, codes: prev?.codes || null, gallery: prev?.gallery || [], desc: a.description ?? prev?.desc ?? null,
  });
  if (a.performers) { m.perfList = a.performers.map(adaptPerformer); }
  M.set(m.code, m); return m;
}
export function adaptPerformer(a) {
  const prev = P.get(a.id); const pr = a.profile || prev?.pr || null;
  const p = Object.assign(prev || {}, {
    id: a.id, slug: a.slug || prev?.slug || String(a.id), name: a.name, kanji: a.name_kanji, kana: a.name_kana, img: a.photo_url, birth: a.birth_date, height: a.height_cm, bust: a.bust, waist: a.waist, hip: a.hip, cup: a.cup, blood: a.blood_type, rh: a.blood_rh,
    bio: a.bio_text ?? prev?.bio ?? null, debut: a.debut_date, retired: a.retired_date, location: a.location, count: a.movie_count, aliases: a.aliases || [], crawled: a.crawled_at, first: a.first_seen_at, together: a.together, photoCount: a.photo_count, sourceCount: a.source_count, pr,
  });
  p.status = p.retired ? 'retired' : 'active'; P.set(p.id, p); return p;
}
export const getMovie = code => M.get(code); export const getPerformer = id => P.get(+id);
export const performersOf = m => (m.perfList || m.perfs.map(i => P.get(i)).filter(Boolean));
export const cover = m => m.cover || null; export const coverFull = m => m.coverFull || m.cover || null; export const photo = p => p.img || null;
export const galleryFull = m => (m.gallery || []).map(g => g.full || g);
export const effOf = x => ({ birth: x.birth, height: x.height, bust: x.bust, waist: x.waist, hip: x.hip, cup: x.cup, blood: x.blood, location: x.location, debut: x.debut });
export const ageOfPerformer = p => ageOf(p.birth);
const nrm = s => (s || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
export const descIsTitle = m => { if (!m.desc) return true; const d = nrm(m.desc), codeN = nrm(m.code); const strip = s => nrm(s).replace(codeN, '').trim(); const t = [m.en, m.jp].filter(Boolean).map(strip).filter(Boolean); return t.some(x => d === x || d === nrm(m.code + ' ' + x) || (x.length > 12 && (d.includes(x) || x.includes(d)) && Math.abs(d.length - x.length) / Math.max(d.length, x.length) < 0.35)); };
const GENERIC = new Set(['hi-def', 'hd', '4k', 'ハイビジョン', 'digital mosaic', 'dmm exclusive', 'exclusive', 'featured actress', 'individual', 'solowork', 'fhd', 'フルハイビジョン(fhd)', '配信専用', 'sample video', 'standard quality']);
export const mainTags = (m, n = 3) => { const ok = m.genres.filter(g => !GENERIC.has(g.toLowerCase())); const pool = ok.length ? ok : m.genres; return { shown: pool.slice(0, n), rest: m.genres.filter(g => !pool.slice(0, n).includes(g)) }; };
export const isPlayableUrl = u => /\.m3u8|\.mp4/i.test(u || '');

// ---------- paging helper: API pagination meta -> the {total,page,per,pages,items} object the pages use
const pageOf = (res, items) => { const g = res.meta?.pagination || {}; return { total: g.total ?? items.length, page: g.page ?? 1, per: g.per_page ?? items.length, pages: g.pages ?? 1, items, all: items }; };
export async function loadMovie(code) {
  const { data } = await get(`/movies/${encodeURIComponent(code)}`); const m = adaptMovie(data); m.desc = data.description; m.directors = data.attrs?.directors || [];
  return m;
}
export async function loadMovieExtras(code) {
  const [dl, media, codes, srcs, rel] = await Promise.all([get(`/movies/${code}/downloads`), get(`/movies/${code}/media`), get(`/movies/${code}/codes`), get(`/movies/${code}/sources`), get(`/movies/${code}/related`, { limit: 12 })]);
  const m = M.get(code); const QR = q => (q ? ({ '1080p Full HD': 6, '1080p': 6, '720p HD': 5, '720p': 5, HD: 4, '480p': 3, '360p': 2, '240p': 1, preview: 0 }[q] ?? 3) : 3);
  m.dl = dl.data.map(x => ({ url: x.url, name: x.name, size: x.size, quality: x.quality, type: x.type, bytes: x.bytes })).sort((a, b) => QR(b.quality) - QR(a.quality) || (b.bytes || 0) - (a.bytes || 0)); m.magnets = m.dl.length;
  m.watch = media.data.hls.map(x => ({ url: x.url, quality: x.meta?.quality || null })); m.gallery = media.data.gallery.map(x => ({ src: x.url, full: x.url, kind: x.kind })); m.codesList = codes.data; m.markers = codes.meta.markers || []; m.srcs = srcs.data.map(s => ({ slug: s.source, url: s.url, code: s.code }));
  m.related = rel.data.map(adaptMovie); return m;
}
export async function loadMovieDownloads(code) { const m = M.get(code); if (m?.dl) return m.dl; const { data } = await get(`/movies/${code}/downloads`); const dl = data.map(x => ({ url: x.url, name: x.name, size: x.size, quality: x.quality, type: x.type, bytes: x.bytes })); if (m) m.dl = dl; return dl; }
export async function loadPerformer(idOrSlug) { const { data, meta } = await get(`/performers/${encodeURIComponent(idOrSlug)}`); const p = adaptPerformer(data); p.pr = data.profile; p.canonicalSlug = meta.canonical_slug; return p; }
export async function loadPerformerExtras(id) {
  const [src, co, st, ph] = await Promise.all([get(`/performers/${id}/sources`), get(`/performers/${id}/co-performers`, { limit: 12 }), get(`/performers/${id}/statistics`), get(`/performers/${id}/photos`)]);
  const p = P.get(+id); p.rows = src.data; p.srcs = src.data.filter(s => s.url).map(s => ({ slug: s.source, url: s.url })); p.co = co.data.map(adaptPerformer); p.stats = st.data; p.photos = ph.data; return p;
}
export async function loadPerformerMovies(id, params) { const res = await get(`/performers/${id}/movies`, params); return pageOf(res, res.data.map(adaptMovie)); }
export async function queryMovies(params = {}) { const res = await get('/movies', params); return pageOf(res, res.data.map(adaptMovie)); }
export async function queryPerformers(params = {}) { const res = await get('/performers', params); return pageOf(res, res.data.map(adaptPerformer)); }
export const related = m => m.related || [];

// ---------- bootstrap: totals and the live indicator
let _boot = null;
// While the installation requires sign-in and nobody is signed in, there is nothing to read yet: answer empty, call nothing.
const NOTHING_YET = { totals: { movies: 0, performers: 0 }, overview: {}, summary: {} };
// The statistics are for signed-in users: a guest gets the totals from the public status summary and no overview.
export const loadCatalog = () => (_boot ||= (async () => {
  await auth.ready();
  if (auth.loginRequired() && !auth.user()) return NOTHING_YET;
  const s = await get('/status/summary');
  if (!auth.user()) return { totals: { movies: s.data.movies, performers: s.data.performers }, overview: {}, summary: s.data };
  const o = await get('/statistics/overview');
  return { totals: { movies: o.data.movies, performers: o.data.performers }, overview: o.data, summary: s.data };
})());
export const refreshBoot = () => { _boot = null; return loadCatalog(); };

// ---------- search engine (same shapes the Browse page already renders)
export const MOVIE_FILTERS = ['genre', 'genre_not', 'genre_all', 'maker', 'series', 'label', 'actress_id', 'code', 'released_from', 'released_to', 'runtime_min', 'runtime_max', 'crawled_days', 'score_min', 'tier_min', 'censored', 'has_magnet', 'has_performers', 'has_watch', 'source'];
export const PERF_FILTERS = ['has_profile', 'bio_q', 'age_min', 'age_max', 'debut_from', 'debut_to', 'cup', 'blood_type', 'location', 'height_min', 'height_max', 'born_from', 'born_to', 'bust_min', 'bust_max', 'waist_min', 'waist_max', 'hip_min', 'hip_max', 'status', 'has_image', 'has_bio', 'movies_min'];
export const PERF_MOVIE_FILTERS = ['genre', 'genre_all', 'maker', 'series', 'label', 'released_from', 'released_to'];
const pick = (o, keys) => Object.fromEntries(keys.filter(k => o[k] !== '' && o[k] != null).map(k => [k, o[k]]));
const interpretLive = async (text, ignore, target) => {
  if (!text || norm(text).trim().length < 2) return { filters: {}, conditions: [], residual: '' };
  const { data } = await send('POST', '/search/interpretations', { q: text, target, ignore });
  return { filters: data.filters, conditions: data.conditions, residual: data.residual, ignored: data.ignored || [] };
};
export async function unifiedSearch(text, o = {}) {
  const ignore = o.ignore || []; const it = await interpretLive(text, ignore, 'movies'); const manual = o.manual || {}; const pf = pick(manual, PERF_FILTERS);
  const mp = { ...it.filters, ...pick(manual, MOVIE_FILTERS), ...pf }; const hasQuery = Object.keys(mp).length > 0;
  const per = o.per_page || 24; const sort = o.sort || (mp.q ? 'relevance' : 'release_date');
  const pq = mp.actress_id ? '' : (it.residual || (it.conditions.length ? '' : text || '')); const movieSide = Object.keys(mp).some(k => !PERF_FILTERS.includes(k)); const fromMovies = !pq && movieSide;
  const [movies, performers, photosRes, catalogRes] = await Promise.all([
    queryMovies({ ...mp, sort, page: o.page, per_page: per }),
    queryPerformers(fromMovies ? { ...pf, ...pick(mp, MOVIE_FILTERS), in_movies: '1', sort: o.psort || 'movies', page: o.ppage, per_page: per } : { ...pf, q: pq, sort: o.psort || 'featured', page: o.ppage, per_page: per }),
    get('/search/photos', { ...mp, page: 1, per_page: 48 }), text && norm(text).trim().length >= 2 ? get('/search/catalog', { q: text }) : { data: [] }]);
  const ph = photosRes.data; const photos = { total: ph.total, items: [...ph.people, ...ph.covers] };
  return { interp: it, params: mp, hasQuery, movies, performers, photos, catalog: catalogRes.data, fromMovies, perfBase: { q: pq, in_movies: fromMovies ? '1' : undefined, ...pick(mp, MOVIE_FILTERS) }, perfParams: pf };
}
export async function performerSearch(text, o = {}) {
  const ignore = o.ignore || []; const it = await interpretLive(text, ignore, 'performers'); const manual = o.manual || {}; const pf = { ...it.filters, ...pick(manual, PERF_FILTERS) }; const mf = pick(manual, PERF_MOVIE_FILTERS);
  const base = { q: it.residual, q_bio: '1' };
  const performers = await queryPerformers({ ...pf, ...mf, ...base, sort: o.sort === 'linked_title_count' ? 'movies' : (o.sort || 'featured'), page: o.page, per_page: o.per_page || 24 });
  return { interp: it, params: { ...pf, ...mf, q: it.residual }, perfParams: pf, movieParams: mf, perfBase: base, performers, movies: { total: 0 }, photos: { total: 0, items: [] }, catalog: [], hasQuery: Object.keys(pf).length + Object.keys(mf).length > 0 || !!it.residual };
}
export const facetCounts = async params => (await get('/movies/facets', params)).data;
export const perfFacetCounts = async (base, pf) => (await get('/performers/facets', { ...base, ...pf })).data;
export const perfMovieFacets = async res => (await get('/performers/movie-facets', { ...res.perfParams, ...res.movieParams, ...res.perfBase })).data;
export const relaxHints = async (params, chips) => {
  const out = await Promise.all(chips.map(async c => { const x = { ...params, per_page: 1 }; c.keys.forEach(k => { delete x[k]; }); const r = await get('/movies', x); return { chip: c, total: r.meta.pagination.total }; }));
  return out.filter(h => h.total > 0).sort((a, b) => b.total - a.total).slice(0, 3);
};
export const didYouMean = async text => { try { const { meta } = await get('/search', { q: text, types: 'movie', per_page: 1 }); return meta.did_you_mean ? [{ name: meta.did_you_mean, kind: t('search.suggestionKind') }] : []; } catch { return []; } };
export const suggest = async text => (await get('/suggestions', { q: text })).data;

// ---------- discovery
export const recentUpdates = async (type, params) => (await get('/updates', { type, ...params }));
export const updatesSummary = async () => (await get('/updates/summary')).data;
export const highlights = async () => { const d = (await get('/highlights')).data; return { picks: d.picks.map(adaptMovie), hot: d.hot_performers.map(adaptPerformer), newest: d.newest.map(adaptMovie) }; };
export const calendarMonth = async (y, mo) => { const key = `${y}-${String(mo + 1).padStart(2, '0')}`; const { data } = await get('/calendar', { month: key }); return { counts: Object.fromEntries(data.map(c => [c.day, c.count])) }; };
export const calendarDay = async day => (await get('/calendar', { day })).data.map(adaptMovie);
export const categoryCover = () => null;
export const profileFill = async () => { await auth.ready(); return auth.user() ? (await get('/statistics/data-quality')).data : { total: 0 }; };

// ---------- session + personal state (owner marks live on the server)
const subs = new Set(); let session = { scope: 'guest', user: null }; const emit = () => subs.forEach(f => f());
let firstLoaded; const sessionKnown = new Promise(resolve => { firstLoaded = resolve; });
export const auth = {
  ready: () => sessionKnown,
  user: () => session.user, scope: () => session.scope, loginRequired: () => !!session.login_required, key: () => (session.user ? String(session.user.id) : null),
  async load() { try { const { data } = await get('/sessions/current'); session = data; setCsrf(data.csrf || null); } catch { session = { scope: 'guest', user: null }; } firstLoaded(); _boot = null; emit(); if (session.user) await personal.load(); return auth.user(); },
  async login(email, password) { const { data } = await send('POST', '/sessions', { email, password }); setCsrf(data.csrf); await auth.load(); return data; },
  async logout() { await send('DELETE', '/sessions/current'); session = { scope: 'guest', user: null }; setCsrf(null); personal.reset(); emit(); },
  subscribe: f => (subs.add(f), () => subs.delete(f)),
};
const KIND = { fav: ['favorite', 'movie'], later: ['want', 'movie'], seen: ['watched', 'movie'], pfav: ['favorite', 'performer'] };
const base = () => ({ rq: [], fav: [], later: [], seen: [], pfav: [], follows: [], saved: [], recent: [], requests: [], downloads: [], activity: [] });
let state = base(); const psubs = new Set(); const pemit = () => psubs.forEach(f => f());
const RQ = 'xc.recent-queries'; try { state.rq = JSON.parse(localStorage.getItem(RQ) || '[]'); } catch { /* ignore */ }
export const personal = {
  get: () => state, subscribe: f => (psubs.add(f), () => psubs.delete(f)),
  reset() { state = { ...base(), rq: state.rq }; pemit(); },
  async load() {
    if (!auth.user()) return;
    const [marks, follows, saved, hist, reqs] = await Promise.all([get('/me/marks', { per_page: 100 }), get('/me/follows'), get('/me/saved-searches'), get('/me/history', { per_page: 100 }), get('/download-requests')]);
    const by = { fav: [], later: [], seen: [], pfav: [] };
    for (const m of marks.data) { const k = Object.entries(KIND).find(([, [kind, target]]) => kind === m.kind && target === m.target)?.[0]; if (k) by[k].push(m.target === 'performer' ? +m.ref : m.ref); }
    state = { ...state, ...by, follows: follows.data.map(f => ({ type: f.kind, name: f.kind === 'performer' ? (f.performer?.name || f.ref) : f.ref, ref: f.ref, at: Date.parse(f.created_at) })), saved: saved.data.map(s => ({ id: s.id, name: s.name, params: Object.fromEntries(new URLSearchParams(s.query)), at: Date.parse(s.created_at) })), activity: hist.data.map(h => ({ type: h.kind, key: h.ref, label: h.label || h.ref, at: Date.parse(h.viewed_at), n: 1 })), requests: reqs.data.map(r => ({ code: r.movie_code, state: r.state, id: r.id, at: Date.parse(r.created_at) })) };
    pemit();
  },
  toggle(kind, id) {
    const [mk, target] = KIND[kind]; const on = !state[kind].includes(id); state = { ...state, [kind]: on ? [...state[kind], id] : state[kind].filter(x => x !== id) }; pemit();
    send(on ? 'PUT' : 'DELETE', `/me/marks/${mk}/${target}/${encodeURIComponent(id)}`).catch(() => { state = { ...state, [kind]: on ? state[kind].filter(x => x !== id) : [...state[kind], id] }; pemit(); }); return on;
  },
  has: (kind, id) => state[kind].includes(id),
  follow(type, name, ref) { const key = ref ?? name; const on = !state.follows.some(f => f.type === type && String(f.ref ?? f.name) === String(key)); state = { ...state, follows: on ? [...state.follows, { type, name, ref: key, at: Date.now() }] : state.follows.filter(f => !(f.type === type && String(f.ref ?? f.name) === String(key))) }; pemit(); send(on ? 'PUT' : 'DELETE', `/me/follows/${type}/${encodeURIComponent(key)}`).catch(() => {}); },
  isFollowing: (type, name, ref) => state.follows.some(f => f.type === type && String(f.ref ?? f.name) === String(ref ?? name)),
  saveSearch(name, params) { const query = new URLSearchParams(params).toString(); send('POST', '/me/saved-searches', { name, query }).then(({ data }) => { state = { ...state, saved: [...state.saved, { id: data.id, name, params, at: Date.now() }] }; pemit(); }); },
  removeSearch(i) { const s = state.saved[i]; state = { ...state, saved: state.saved.filter((_, j) => j !== i) }; pemit(); if (s?.id) send('DELETE', `/me/saved-searches/${s.id}`).catch(() => {}); },
  logDownload(code, kind) { if (!auth.user()) return; send('POST', '/me/history', { kind: 'download', ref: code, label: kind }).catch(() => {}); state = { ...state, downloads: [{ code, kind, at: Date.now() }, ...state.downloads].slice(0, 500) }; pemit(); },
  noteView(type, key, label) { if (!auth.user() || !['movie', 'performer', 'photo'].includes(type)) return; send('POST', '/me/history', { kind: type, ref: String(key), label }).catch(() => {}); },
  async request(code) { const { data } = await send('POST', '/download-requests', { movie_code: code }); state = { ...state, requests: [...state.requests.filter(r => r.code !== code), { code, state: data.state, id: data.id, at: Date.now() }] }; pemit(); },
  async cancelRequest(code) { const r = state.requests.find(x => x.code === code); state = { ...state, requests: state.requests.filter(x => x.code !== code) }; pemit(); if (r?.id) await send('DELETE', `/download-requests/${r.id}`).catch(() => {}); },
  hasRequest: code => state.requests.some(r => r.code === code),
  async refreshRequests() { try { const { data } = await get('/download-requests'); state = { ...state, requests: data.map(r => ({ code: r.movie_code, state: r.state, id: r.id, found: r.found_count, at: Date.parse(r.created_at) })) }; pemit(); } catch { /* keep */ } },
  pushQuery(q) { const t = (q || '').trim(); if (t.length < 2) return; state = { ...state, rq: [t, ...(state.rq || []).filter(x => x !== t)].slice(0, 8) }; localStorage.setItem(RQ, JSON.stringify(state.rq)); pemit(); },
  clearQueries() { state = { ...state, rq: [] }; localStorage.setItem(RQ, '[]'); pemit(); },
  pushRecent(code) { state = { ...state, recent: [code, ...state.recent.filter(c => c !== code)].slice(0, 12) }; pemit(); },
};
export async function feed(params) { const res = await get('/me/feed', params); return { items: res.data.map(adaptMovie), meta: res.meta }; }
export const markFeedSeen = () => send('PATCH', '/me/feed', {});

// ---------- views (counter per movie; the server keeps it)
let _views = { counts: {}, mine: [] }; let _vv = 0; const vsubs = new Set();
export const viewsStore = { subscribe: f => (vsubs.add(f), () => vsubs.delete(f)), version: () => _vv };
export async function loadViews() { _vv++; vsubs.forEach(f => f()); }
export const viewCount = code => _views.counts[code] || 0; export const myViews = () => _views.mine;
export async function recordView(code) { const mk = 'xc.v.' + code; const last = +(sessionStorage.getItem(mk) || 0); if (Date.now() - last < 30 * 60 * 1000) return; sessionStorage.setItem(mk, String(Date.now())); try { const { data } = await send('POST', `/movies/${encodeURIComponent(code)}/views`); _views.counts[code] = data.count; _vv++; vsubs.forEach(f => f()); } catch { /* offline */ } }
export const loadViewCounts = async codes => { const need = codes.filter(c => !(c in _views.counts)); if (!need.length) return; try { const { data } = await get('/movies/views', { codes: need.join(',') }); Object.assign(_views.counts, data); _vv++; vsubs.forEach(f => f()); } catch { /* keep */ } };

// ---------- operations (Command Center)
// A degraded answer (no Prometheus) has empty deps and probes: fill what the Command Center pages read so they show "not connected" instead of failing.
const DEP_DEFAULTS = { postgres: { conn: 0, max: 0, connXcrawler: 0, sizeBytes: 0, series: [] }, mongo: { connections: 0, up: false }, redis: { clients: 0, memBytes: 0, opsPerSec: 0 }, elasticsearch: { color: '—', docs: 0, shards: 0 } };
const PROBES = ['sentry', 'openobserve', 'prometheus', 'postgres', 'redis', 'mongo', 'elasticsearch', 'flaresolverr', 'ollama', 'playwright'];
export const loadInfra = async () => {
  const { data, meta } = await get('/infrastructure');
  const deps = Object.fromEntries(Object.entries(DEP_DEFAULTS).map(([k, v]) => [k, { ...v, ...(data.deps?.[k] || {}) }]));
  const probes = Object.fromEntries(PROBES.map(k => [k, { ok: false, ms: 0, ...(data.probes?.[k] || {}) }]));
  return { ...data, vms: data.vms || [], services: data.services || {}, series: data.series || [], deps, probes, degraded: !!meta?.degraded, reason: meta?.reason || null };
};
// the Command Center data, in the shape its tabs draw
export const loadOps = async () => {
  const [ov, src, nodes, keys, tpl, st, alerts, errs, contrib, perf, ms, ps, me, runs, queue] = await Promise.all([get('/operations/overview'), get('/sources'), get('/nodes'), get('/api-keys', { per_page: 50 }), get('/notification-templates'), get('/settings'), get('/alerts', { per_page: 40 }), get('/crawl-errors'), get('/statistics/contributions'), get('/operations/performance'), get('/statistics/series', { metric: 'movies', days: 14 }), get('/statistics/series', { metric: 'performers', days: 14 }), get('/me'), get('/crawl-runs', { per_page: 40 }), get('/crawl-queue')]);
  const flat = nodes.data.flatMap(n => n.lanes.map(l => ({ node: n.node, lane: l.lane, attempts_1h: l.attempts, ok: l.ok, bad: l.attempts - l.ok, avg_ms: l.avg_ms, last_at: l.last_at, egress_ips: l.egress_ips })));
  return { sources: src.data, hourly: ov.data.hourly.map(h => ({ ...h, err: h.errors, skipped: 0 })), runs: runs.data, alerts: alerts.data, queue: queue.data, nodes: flat, nodes24: nodes.data.map(n => ({ node: n.node, attempts: n.attempts_24h, ok: n.ok_24h })), errors: errs.data, keys: keys.data, templates: tpl.data.map(t => ({ ...t, default_severity: t.severity })), settings: st.data, contrib24: contrib.data, trend: { movies: ms.data.map(x => ({ d: x.period, n: x.count })), performers: ps.data.map(x => ({ d: x.period, n: x.count })), magnets: [], observations: [], hourly: [], d24: { performers: 0, magnets: 0, observations: 0 } }, account: me.data.user, overview: ov.data, perf: perf.data };
};
export const loadOps2 = async () => { const [q, p, er, ru] = await Promise.all([get('/crawl-queue'), get('/operations/performance'), get('/crawl-errors'), get('/crawl-runs', { per_page: 40 })]); return { at: new Date().toISOString(), hourly: p.data.hourly, queue: q.data.map(x => ({ ...x, n: x.n, late_1h: 0 })), failed: er.data, lanes: p.data.lanes, runs: p.data.runs, caps: p.data.caps, runList: ru.data }; };
export const loadRecentMovies = async () => new Map(); export const extraMoviesOf = () => [];
export const moviesOfPerformer = () => [];

// ---------- performer profile as the detail page shows it (all bio fields, merged by the server from every source)
export function performerFacts(p) {
  const r = p.pr || {}; const age = ageOf(p.birth); const hair = [r.hair_color, r.hair_length].filter(Boolean).join(' · ') || null;
  const facts = [[t('facts.age'), age != null ? `${age}` : null], [t('facts.birthDate'), p.birth], [t('facts.residence'), p.location], [t('facts.height'), p.height ? `${p.height} cm` : null], [t('facts.measurements'), p.bust ? `${p.bust}-${p.waist ?? '?'}-${p.hip ?? '?'}` : null], [t('facts.cup'), p.cup], [t('facts.bloodType'), p.blood ? `${p.blood}${p.rh ? ` (${p.rh})` : ''}` : null], [t('facts.debut'), p.debut],
    [t('facts.zodiac'), r.zodiac], [t('facts.hairColor'), hair], [t('facts.nameKanji'), p.kanji], [t('facts.reading'), p.kana || r.name_reading], [t('facts.nickname'), r.nickname], [t('facts.retiredOn'), p.retired], [t('facts.activePeriod'), r.career_period], [t('facts.agency'), r.agency], [t('facts.hobby'), r.hobby], [t('facts.skill'), r.skill], [t('facts.sourceFavorites'), r.favorite_count != null ? fmt(r.favorite_count) : null]];
  const core = [p.birth, p.height, p.bust, p.cup, p.blood, p.location, p.debut, p.bio, p.img];
  return { facts, filled: core.filter(Boolean).length, total: core.length, bio: { other: r.note || null } };
}

// batch the view-counter reads of the cards on screen into one request
let _vq = new Set(), _vt = null;
export const ensureViews = code => { if (code in _views.counts) return; _vq.add(code); if (!_vt) _vt = setTimeout(() => { const c = [..._vq]; _vq = new Set(); _vt = null; loadViewCounts(c); }, 60); };

// ---------- the home page: everything it shows in one parallel load
export async function loadHome() {
  await auth.ready();
  // The system figures (statistics) are for signed-in users; a guest gets the lists only.
  const figures = auth.user() ? Promise.all([get('/statistics/overview'), get('/statistics/series', { metric: 'movies', days: 30 }), get('/statistics/series', { metric: 'performers', days: 30 }), get('/statistics/series', { metric: 'movies', interval: 'hour', days: 1 }), get('/statistics/crawl-health', { hours: 72 }), get('/statistics/contributions'), get('/statistics/sources-active')]) : Promise.resolve(null);
  const [fig, sm, upd, rel, genres, hl] = await Promise.all([figures, get('/updates/summary'), get('/movies', { sort: 'update_date', per_page: 16 }), get('/movies', { sort: 'release_date', per_page: 16 }), get('/catalogs/genres', { per_page: 24, sort: '-count', script: 'latin', min_count: 100 }), get('/highlights')]);
  const [ov, mseries, pseries, hseries, health, contrib, srcs] = fig || [];
  return { ov: ov?.data, sm: sm.data, mseries: mseries?.data, pseries: pseries?.data, hseries: hseries?.data, health: health?.data, contrib: contrib?.data, src: srcs?.data, upd: upd.data.map(adaptMovie), rel: rel.data.map(adaptMovie), genres: genres.data, pool: [...hl.data.picks, ...hl.data.newest].map(adaptMovie) };
}
