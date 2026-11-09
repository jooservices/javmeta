import { esc } from './util.js';
import { label } from './filterState.js';
import { t } from './i18n.js';
import { pickerHtml } from './pickers.js';
import { facetShell } from './facets.js';

const field = (key, q, type = 'text', catalog = '') => `<label class="f">${label(key)}<input name="${key}" type="${type}" value="${esc(q[key] || '')}"${type === 'number' ? ' min="0" step="1"' : ''}${catalog ? ` list="catalog-${key}" data-catalog="${catalog}"` : ''}></label>${catalog ? `<datalist id="catalog-${key}"></datalist><div data-top="${catalog}" data-key="${key}" class="facet-values"></div>` : ''}`;
const select = (key, q, options) => `<label class="f">${label(key)}<select name="${key}">${[['', key === 'genre_all' ? t('filter.any_genre') : t('filter.all')], ...options].map(([v, text]) => `<option value="${v}" ${String(q[key] ?? '') === v ? 'selected' : ''}>${text}</option>`).join('')}</select></label>`;
const tri = (key, q) => select(key, q, [['1', t('common.yes')], ['0', t('common.no')]]);
const range = (min, max, q) => `<div class="two">${field(min, q, 'number')}${field(max, q, 'number')}</div>`;
const choices = (group, key, q, search = false) => `<fieldset class="filter-set"><legend>${label(key)}</legend>${search ? `<label class="f">${t('filter.genre_search')}<input type="search" data-choice-search="${key}"></label>` : ''}<div class="filter-choices" data-choices="${group}" data-key="${key}"></div></fieldset>`;
const details = (title, html, open = false) => `<details ${open ? 'open' : ''}><summary>${title}</summary><div class="fb">${html}</div></details>`;
export function filterControls(kind, query, fixed = {}) {
  const q = { ...query };
  if (kind === 'movies') {
    const main = details(t('filter.section.find_movies'), field('q', q) + field('code', q) + (!fixed.actress_id ? pickerHtml() : '') + (!fixed.actress ? field('actress', q) : ''), true);
    const classification = details(t('filter.section.classification'), (!fixed.genre ? choices('genres', 'genre', q, true) + select('genre_all', q, [['1', label('genre_all')]]) : '') + [['maker', 'makers'], ['series', 'series'], ['label', 'labels']].filter(([k]) => !fixed[k]).map(([k, c]) => field(k, q, 'text', c)).join(''), true);
    const other = details(t('filter.section.time_quality'), (!fixed.year ? range('released_from', 'released_to', q) : '') + range('runtime_min', 'runtime_max', q) + `<label class="f">${label('score_min')} <output data-score>${esc(q.score_min || 0)}</output><input name="score_min" type="range" min="0" max="10" step="0.5" value="${esc(q.score_min || 0)}"></label>` + select('tier_min', q, [['0', t('filter.tier_any')], ['1', '1'], ['2', '2'], ['3', '3']]) + select('censored', q, [['censored', t('filter.censored.yes')], ['uncensored', t('filter.censored.no')]]) + ['on_disk', 'has_cover', 'has_magnet', 'has_performers'].map(k => tri(k, q)).join('') + choices('sources', 'source', q));
    return main + classification + other + facetShell(kind);
  }
  return details(t('filter.section.profile'), field('q', q) + choices('cups', 'cup', q) + choices('blood_types', 'blood_type', q) + field('location', q, 'text', 'locations') + range('age_min', 'age_max', q) + range('height_min', 'height_max', q), true)
    + details(t('filter.section.measurements'), range('bust_min', 'bust_max', q) + range('waist_min', 'waist_max', q) + range('hip_min', 'hip_max', q))
    + details(t('filter.section.activity'), range('debut_from', 'debut_to', q) + select('status', q, [['active', t('filter.status.active')], ['retired', t('filter.status.retired')]]) + tri('has_image', q) + tri('has_bio', q) + field('movies_min', q, 'number') + choices('sources', 'source', q)) + facetShell(kind);
}
