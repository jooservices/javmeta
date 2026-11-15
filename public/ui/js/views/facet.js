import { esc } from '../util.js';
import { t } from '../i18n.js';
import { head, crumbs, emptyBox, a } from '../components.js';
import { moviesBrowser } from './movies.js';
import { myMarks, markBtn } from '../marks.js';

const LABEL = { genre: 'facet.kind.genre', maker: 'facet.kind.maker', series: 'facet.kind.series', label: 'facet.kind.label', year: 'facet.kind.year' };

/* every genre, maker, series, label and year is a page: the movies of that value */
export default {
  async render({ params, query }) {
    const kind = params.kind, value = decodeURIComponent(params.value);
    if (!LABEL[kind]) return { html: `<div class="wrap">${emptyBox(t('facet.unknown_kind'), esc(kind), a('#/movies', t('common.back_to_movies'), 'btn'))}</div>` };
    const b = kind === 'year'
      ? moviesBrowser(query, { fixed: { year: true }, apiExtra: { released_from: value + '-01-01', released_to: value + '-12-31' }, closed: true })
      : moviesBrowser(query, { fixed: { [kind]: value }, closed: true });
    const follow = (kind === 'maker' || kind === 'series') ? markBtn(await myMarks(), 'follow', kind, value, t('common.following'), t('common.follow')) : '';
    return { html: `<div class="wrap">${crumbs([[t('movies.title'), '#/movies'], [t(LABEL[kind])], [value]])}${head(esc(value), t(LABEL[kind]), follow)}${b.html}</div>`, mount: b.mount };
  },
};
