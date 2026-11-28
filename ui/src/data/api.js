// The one HTTP client of the app: JSON in/out, session cookie, CSRF header on unsafe methods, problem+json errors.
let csrf = null;
export const setCsrf = t => { csrf = t; };
export class ApiError extends Error {
  constructor(status, problem) { super(problem?.detail || problem?.title || `HTTP ${status}`); this.status = status; this.code = problem?.code; this.errors = problem?.errors || []; this.problem = problem; }
}
const qs = p => { const e = Object.entries(p || {}).filter(([, v]) => v !== undefined && v !== null && v !== ''); return e.length ? '?' + new URLSearchParams(e.map(([k, v]) => [k, String(v)])) : ''; };
export async function api(path, { params, method = 'GET', body, headers = {}, signal } = {}) {
  const h = { Accept: 'application/json', ...headers }; if (body !== undefined) h['Content-Type'] = 'application/json'; if (csrf && method !== 'GET') h['X-CSRF-Token'] = csrf;
  const res = await fetch(`/api/v2${path}${qs(params)}`, { method, headers: h, credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body), signal });
  if (res.status === 204) return { data: null, meta: {}, etag: null };
  const text = await res.text(); let json = null; try { json = text ? JSON.parse(text) : null; } catch { /* not json */ }
  if (!res.ok) throw new ApiError(res.status, json);
  return { ...json, etag: res.headers.get('ETag') };
}
export const get = (path, params, opt) => api(path, { params, ...opt });
export const send = (method, path, body, opt) => api(path, { method, body, ...opt });
