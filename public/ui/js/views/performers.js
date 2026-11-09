import { head } from '../components.js';
import { t } from '../i18n.js';
import { searchBrowser } from '../searchBrowser.js';

export default {
  async render({ query }) {
    const browser = searchBrowser('performers', query);
    return { html: `<div class="wrap">${head(t('performers.title'), t('performers.sub'))}${browser.html}</div>`, mount: browser.mount };
  },
};
