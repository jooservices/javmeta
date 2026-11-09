import { esc } from './util.js';
import { t } from './i18n.js';

export const values = value => [...new Set(String(value || '').split(',').filter(Boolean))];
export const toggleValue = (value, item) => values(value).includes(String(item)) ? values(value).filter(v => v !== String(item)).join(',') : [...values(value), String(item)].join(',');
export const movieKeys = 'q code genre genre_all actress actress_id maker series label source released_from released_to runtime_min runtime_max score_min tier_min censored on_disk has_cover has_magnet has_performers sort'.split(' ');
export const performerKeys = 'q cup blood_type location age_min age_max height_min height_max bust_min bust_max waist_min waist_max hip_min hip_max debut_from debut_to status has_image has_bio movies_min source sort'.split(' ');
export const multiKeys = ['genre', 'actress_id', 'source', 'cup', 'blood_type'];
const labelKeys =new Set(['q', 'code', 'genre', 'genre_all', 'actress', 'actress_id', 'maker', 'series', 'label', 'source', 'released_from', 'released_to', 'runtime_min', 'runtime_max', 'score_min', 'tier_min', 'censored', 'on_disk', 'has_cover', 'has_magnet', 'has_performers', 'cup', 'blood_type', 'location', 'age_min', 'age_max', 'height_min', 'height_max', 'bust_min', 'bust_max', 'waist_min', 'waist_max', 'hip_min', 'hip_max', 'debut_from', 'debut_to', 'status', 'has_image', 'has_bio', 'movies_min']);
export const label = key => t('filter.label.' + key);
/* Compatibility for docs/smoke that read labels[key]: resolve through i18n. */
export const labels = new Proxy({}, { get: (_t, key) => (typeof key === 'string' ? label(key) : undefined) });
export function movieParams(query) {
  const p = Object.fromEntries(movieKeys.filter(k => query[k] !== undefined && query[k] !== '').map(k => [k, query[k]]));
  // Preserve links made by catalog and older UI versions.
  if (query.year) { p.released_from = query.year + '-01-01'; p.released_to = query.year + '-12-31'; }
  else { if (query.from) p.released_from = query.from; if (query.to) p.released_to = query.to; }
  if (query.rmin) p.runtime_min = query.rmin;
  if (query.rmax) p.runtime_max = query.rmax;
  for (const [k, suffix] of [['released_from', '-01-01'], ['released_to', '-12-31']]) if (/^\d{4}$/.test(p[k] || '')) p[k] += suffix;
  return p;
}
export function performerParams(query) {
  const p = Object.fromEntries(performerKeys.filter(k => query[k] !== undefined && query[k] !== '').map(k => [k, query[k]]));
  return p;
}
export function activeFilters(query, names = {}) {
  return Object.entries(query).filter(([k, v]) => labelKeys.has(k) && v !== '' && v !== undefined).flatMap(([k, v]) => (multiKeys.includes(k) ? values(v) : [String(v)]).map(value => ({ key: k, value, label: label(k) + ': ' + (k === 'actress_id' ? names[value] || '#' + value : value) })));
}
export function removeFilter(query, key, value) {
  const next = { ...query };
  if (multiKeys.includes(key) && value !== undefined) next[key] = values(next[key]).filter(v => v !== value).join(',');
  else delete next[key];
  if (key === 'genre' && !next.genre) delete next.genre_all;
  return next;
}
export const chipsHtml = (query, names) => activeFilters(query, names).map(f => `<button type="button" class="chip on" data-drop="${esc(f.key)}" data-value="${esc(f.value)}" aria-label="${t('filter.chip.remove', { label: f.label })}">${esc(f.label)} <span aria-hidden="true">×</span></button>`).join('');
