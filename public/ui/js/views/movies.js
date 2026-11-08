import { head } from '../components.js';
import { movieParams } from '../filterState.js';
import { searchBrowser, movieSorts } from '../searchBrowser.js';

export const SORTS = movieSorts;
export const toApi = movieParams;
export const moviesBrowser = (query, options) => searchBrowser('movies', query, options);
export default {
  async render({ query }) {
    const browser = moviesBrowser(query);
    return { html: `<div class="wrap">${head('Phim', 'toàn bộ phim đã crawl, cuộn để tải thêm')}${browser.html}</div>`, mount: browser.mount };
  },
};
