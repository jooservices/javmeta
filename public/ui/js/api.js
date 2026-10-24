import { queryString } from './util.js';

export class ApiError extends Error {
  constructor(message, status, body) { super(message); this.status = status; this.body = body; }
}

const cache = new Map();

/* GET through the owner's session: /ui-api/<path> runs /api/v1/<path> in the same process (no key in the browser). */
export async function api(path, params = {}, opt = {}) {
  const url = '/ui-api/' + path + queryString(params);
  if (opt.ttl) {
    const hit = cache.get(url);
    if (hit && hit.until > Date.now()) return hit.value;
  }
  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: opt.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError('Mất kết nối tới máy chủ. Thử lại sau ít giây.', 0, null);
  }
  const type = res.headers.get('content-type') || '';
  if (res.redirected || !type.includes('json')) {
    if (res.status === 401 || res.redirected) { location.href = '/login'; }
    throw new ApiError('Phiên đăng nhập đã hết hạn.', res.status, null);
  }
  const body = await res.json();
  if (!res.ok) {
    throw new ApiError(body.detail || body.message || body.title || ('Lỗi ' + res.status), res.status, body);
  }
  if (opt.ttl) cache.set(url, { value: body, until: Date.now() + opt.ttl });
  return body;
}

export const pagination = body => (body.meta && body.meta.pagination) || {};
