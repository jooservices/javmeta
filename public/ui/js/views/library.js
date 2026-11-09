import { esc, fmt } from '../util.js';
import { t } from '../i18n.js';
import { admin } from '../api.js';
import { head, movieCard, emptyBox, errorBox } from '../components.js';

const TITLES = { favourite: 'library.favourite', watch_later: 'library.watch_later', seen: 'library.seen' };

/* the movies the owner marked */
export default {
  async render({ params }) {
    const titleKey = TITLES[params.kind];
    if (!titleKey) return { html: `<div class="wrap">${emptyBox(t('library.unknown'), esc(params.kind))}</div>` };
    const title = t(titleKey);
    let body;
    try { body = await admin('/ui-me/movies/' + params.kind); } catch (e) { return { html: `<div class="wrap">${head(title, '')}${errorBox(e)}</div>` }; }
    const items = body.data || [];
    return { html: `<div class="wrap">${head(title, t('library.count', { count: items.length, n: fmt(items.length) }))}${items.length ? `<div class="mgrid">${items.map((m, i) => movieCard(m, i)).join('')}</div>` : emptyBox(t('library.empty'), t('library.empty_hint'))}</div>` };
  },
};
