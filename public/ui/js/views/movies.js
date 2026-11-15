import { head } from '../components.js';
import { t } from '../i18n.js';
import { movieParams } from '../filterState.js';
import { searchBrowser, movieSorts } from '../searchBrowser.js';

export const SORTS = movieSorts;
export const toApi = movieParams;
export const moviesBrowser = (query, options) => searchBrowser('movies', query, options);
export default {
  async render({ query }) {
    const browser = moviesBrowser(query);
    return { html: `<div class="wrap">${head(t('movies.title'), t('movies.sub'))}${browser.html}</div>`, mount: browser.mount };
  },
};
