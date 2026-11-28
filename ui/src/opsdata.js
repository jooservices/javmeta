// derived numbers for the Command Center
import { tk } from './i18n/index.js';
const sum = (a, k) => a.reduce((s, x) => s + (+x[k] || 0), 0);
const avg = (a, k) => { const v = a.map(x => x[k]).filter(x => x != null); return v.length ? v.reduce((s, x) => s + +x, 0) / v.length : null; };
export const RANGES = [['6h', 6], ['24h', 24], ['72h', 72], ['7d', 168]];
// translation keys, resolved with t() where they are shown
export const RANGE_NAME = { 6: tk('ops.range.6'), 24: tk('ops.range.24'), 72: tk('ops.range.72'), 168: tk('ops.range.168') };
// the newest hour is still being collected, so it is left out; the previous period is the same number of hours right before
export function windows(hourly, hrs) { const rows = hourly.slice(0, -1); return { cur: rows.slice(-hrs), prev: rows.slice(-2 * hrs, -hrs) }; }
export function summary(w) {
  const att = sum(w, 'attempts'), ok = sum(w, 'ok'), bad = sum(w, 'blocked') + sum(w, 'timeout') + sum(w, 'err');
  return { attempts: att, ok, bad, success: att ? (ok / att) * 100 : null, errRate: att ? (bad / att) * 100 : null, p90: avg(w, 'p90'), queue: avg(w, 'queue_ready'), pg: avg(w, 'pg') };
}
export const change = (c, p) => (c == null || p == null || p === 0 ? null : (c / p - 1) * 100);
const lbl = h => { const d = new Date(h); return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')} ${String(d.getUTCHours()).padStart(2, '0')}h`; };
// one chart row per hour of the current period, with the value of the same hour of the previous period beside it
export function compareSeries(cur, prev, fn) { return cur.map((h, i) => ({ hour: lbl(h.hour_at), current: fn(h), previous: prev[i] ? fn(prev[i]) : null })); }
export const pctColor = v => (v == null ? 'gray' : v >= 85 ? 'red' : v >= 70 ? 'yellow' : 'teal');
export const fmtBytes = b => (b == null ? '—' : b >= 1e9 ? `${(b / 1e9).toFixed(1)} GB` : `${(b / 1e6).toFixed(0)} MB`);
// translation keys, resolved with t() where they are shown
export const HINTS = { ssrf_blocked: tk('ops.hint.ssrf_blocked'), network: tk('ops.hint.network'), fetch_error: tk('ops.hint.fetch_error'), parse_failed: tk('ops.hint.parse_failed'), normalization_failed: tk('ops.hint.normalization_failed'), not_found: tk('ops.hint.not_found'), challenge: tk('ops.hint.challenge') };
