import { head } from '../components.js';
import { searchBrowser } from '../searchBrowser.js';

export default {
  async render({ query }) {
    const browser = searchBrowser('performers', query);
    return { html: `<div class="wrap">${head('Diễn viên', 'hồ sơ gộp từ mọi nguồn')}${browser.html}</div>`, mount: browser.mount };
  },
};
