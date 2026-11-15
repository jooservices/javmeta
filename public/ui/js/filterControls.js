import { esc } from './util.js';
import { label } from './filterState.js';
import { t } from './i18n.js';
import { pickerHtml } from './pickers.js';
import { facetListHtml } from './facetList.js';

const field = (key, q, type = 'text') => `<label class="f">${label(key)}<input name="${key}" type="${type}" value="${esc(q[key] || '')}"${type === 'number' ? ' min="0" step="1"' : ''}></label>`;
const select = (key, q, options) => `<label class="f">${label(key)}<select name="${key}">${[['', t('filter.all')], ...options].map(([v, text]) => `<option value="${v}" ${String(q[key] ?? '') === v ? 'selected' : ''}>${text}</option>`).join('')}</select></label>`;
const tri = (key, q) => select(key, q, [['1', t('common.yes')], ['0', t('common.no')]]);
const range = (min, max, q) => `<div class="two">${field(min, q, 'number')}${field(max, q, 'number')}</div>`;
const details = (title, html, open = false) => `<details ${open ? 'open' : ''}><summary>${title}</summary><div class="fb">${html}</div></details>`;
export function filterControls(kind, query, fixed = {}) {
  const q = { ...query };
  /* every facet is one FacetList: [facet group, filter key]; the page mounts them after it draws */
  const facet = (group, key, extra = {}) => (fixed[key] ? '' : facetListHtml({ kind, facetGroup: group, filterKey: key, query: q, ...extra }));
  const rule = `<p class="fl-rule" data-facet-rule>${t('facetlist.rule')}</p>`;
  if (kind === 'movies') {
    const main = details(t('filter.section.find_movies'), field('q', q) + field('code', q) + (!fixed.actress_id ? pickerHtml() : '') + (!fixed.actress ? field('actress', q) : ''), true);
    const classification = details(t('filter.section.classification'), facet('genres', 'genre', { genreAllToggle: true }) + facet('makers', 'maker') + facet('series', 'series') + facet('labels', 'label'), true);
    const other = details(t('filter.section.time_quality'), (!fixed.year ? facet('years', 'year') + range('released_from', 'released_to', q) : '') + range('runtime_min', 'runtime_max', q) + `<label class="f">${label('score_min')} <output data-score>${esc(q.score_min || 0)}</output><input name="score_min" type="range" min="0" max="10" step="0.5" value="${esc(q.score_min || 0)}"></label>` + select('tier_min', q, [['0', t('filter.tier_any')], ['1', '1'], ['2', '2'], ['3', '3']]) + select('censored', q, [['censored', t('filter.censored.yes')], ['uncensored', t('filter.censored.no')]]) + ['on_disk', 'has_cover', 'has_magnet', 'has_performers'].map(k => tri(k, q)).join('') + facet('sources', 'source'));
    return main + classification + other + rule;
  }
  return details(t('filter.section.profile'), field('q', q) + facet('cups', 'cup') + facet('blood_types', 'blood_type') + facet('locations', 'location') + range('age_min', 'age_max', q) + range('height_min', 'height_max', q), true)
    + details(t('filter.section.measurements'), range('bust_min', 'bust_max', q) + range('waist_min', 'waist_max', q) + range('hip_min', 'hip_max', q))
    + details(t('filter.section.activity'), range('debut_from', 'debut_to', q) + facet('status', 'status') + tri('has_image', q) + tri('has_bio', q) + field('movies_min', q, 'number') + facet('sources', 'source')) + rule;
}
