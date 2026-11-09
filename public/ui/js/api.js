import { queryString } from './util.js';
import { t, acceptLanguageHeader } from './i18n.js';

export class ApiError extends Error {
  constructor(message, status, body) { super(message); this.status = status; this.body = body; }
}

const cache = new Map();
const guestMode = () => document.body?.dataset.guestMode === 'true';
const lang = () => ({ 'Accept-Language': acceptLanguageHeader() });
const httpError = status => t('api.error.http', { status });

/* GET through the owner's session: /ui-api/<path> runs /api/v1/<path> in the same process (no key in the browser). */
export async function api(path, params = {}, opt = {}) {
  const url = '/ui-api/' + path + queryString(params);
  const cacheKey = acceptLanguageHeader() + ' ' + url;
  if (opt.ttl) {
    const hit = cache.get(cacheKey);
    if (hit && hit.until > Date.now()) return hit.value;
  }
  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...lang() }, signal: opt.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError(t('api.error.network'), 0, null);
  }
  const type = res.headers.get('content-type') || '';
  if (res.redirected || !type.includes('json')) {
    if ((res.status === 401 || res.redirected) && !guestMode()) { location.href = '/login'; }
    throw new ApiError(t('api.error.session'), res.status, null);
  }
  const body = await res.json();
  if (!res.ok) {
    throw new ApiError(body.detail || body.message || body.title || httpError(res.status), res.status, body);
  }
  if (opt.ttl) cache.set(cacheKey, { value: body, until: Date.now() + opt.ttl });
  return body;
}

export const pagination = body => (body.meta && body.meta.pagination) || {};

/* Changes made by the owner (admin page): POST/DELETE to /ui-admin with the page's CSRF token. */
export async function send(method, path, body) {
  if (guestMode()) throw new ApiError(t('api.error.login_required'), 401, null);
  const token = document.querySelector('meta[name=csrf-token]')?.content || '';
  let res;
  try {
    res = await fetch(path.startsWith('/') ? path : '/ui-admin/' + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, ...lang() }, body: body ? JSON.stringify(body) : undefined });
  } catch { throw new ApiError(t('api.error.network'), 0, null); }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(data.detail || data.message || data.title || httpError(res.status), res.status, data);
  return data;
}

export async function admin(path) {
  if (guestMode()) throw new ApiError(t('api.error.login_required'), 401, null);
  const res = await fetch(path.startsWith('/') ? path : '/ui-admin/' + path, { credentials: 'same-origin', headers: { Accept: 'application/json', ...lang() } });
  const type = res.headers.get('content-type') || '';
  if (res.redirected || !type.includes('json')) { if (res.status === 401 || res.redirected) location.href = '/login'; throw new ApiError(t('api.error.session'), res.status, null); }
  const data = await res.json();
  if (!res.ok) throw new ApiError(data.detail || data.message || httpError(res.status), res.status, data);
  return data;
}
