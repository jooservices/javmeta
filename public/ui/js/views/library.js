import { esc, fmt, queryString } from '../util.js';
import { t } from '../i18n.js';
import { admin } from '../api.js';
import { head, movieCard, emptyBox, errorBox } from '../components.js';

const TITLES = { favourite: 'library.favourite', watch_later: 'library.watch_later', seen: 'library.seen' };

/* the movies the owner marked */
export default {
  async render({ params, query }) {
    const titleKey = TITLES[params.kind];
    if (!titleKey) return { html: `<div class="wrap">${emptyBox(t('library.unknown'), esc(params.kind))}</div>` };
    const title = t(titleKey);
    const page = Math.max(1, Number.parseInt(query.page || '1', 10) || 1);
    let body;
    try { body = await admin('/ui-me/movies/' + params.kind + queryString({ page, per_page: 50 })); } catch (e) { return { html: `<div class="wrap">${head(title, '')}${errorBox(e)}</div>` }; }
    const items = body.data || [];
    const pagination = body.meta?.pagination || {};
    const total = body.meta?.total ?? items.length;
    const lastPage = Math.max(1, pagination.last_page || 1);
    const nav = lastPage > 1 ? `<nav class="brfoot" aria-label="${t('library.pagination')}"><span>${t('library.page', { page, pages: lastPage })}</span>${page > 1 ? `<a class="btn sm" href="#/me/${encodeURIComponent(params.kind)}${queryString({ page: page - 1, per_page: 50 })}">${t('library.previous')}</a>` : ''}${page < lastPage ? `<a class="btn sm" href="#/me/${encodeURIComponent(params.kind)}${queryString({ page: page + 1, per_page: 50 })}">${t('library.next')}</a>` : ''}</nav>` : '';
    return { html: `<div class="wrap">${head(title, t('library.count', { count: total, n: fmt(total) }))}${items.length ? `<div class="mgrid">${items.map((m, i) => movieCard(m, i)).join('')}</div>${nav}` : emptyBox(t('library.empty'), t('library.empty_hint'))}</div>` };
  },
};
