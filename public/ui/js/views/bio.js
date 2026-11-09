import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { head, a, href, errorBox } from '../components.js';
import { t } from '../i18n.js';

const GROUPS = [['cups', 'cup', 'bio.group.cups'], ['blood_types', 'blood_type', 'bio.group.blood_types'], ['locations', 'location', 'bio.group.locations']];

/* performer profiles by what the sources know: cup, blood type, location; every value opens the filtered performer list */
export default {
  async render() {
    let rows;
    try { rows = await Promise.all(GROUPS.map(g => api('meta/catalog/' + g[0], { per_page: 24 }, { ttl: 120000 }))); } catch (e) { return { html: `<div class="wrap">${head(t('bio.title'), '')}${errorBox(e)}</div>` }; }
    const panel = (g, body) => {
      const items = body.data || [], max = Math.max(1, ...items.map(x => x.performer_count));
      const total = body.meta?.pagination?.total ?? items.length;
      return `<div class="panel"><div class="hd"><h2>${t(g[2])}</h2><span class="chip mono">${t('bio.values', { count: total, n: fmt(total) })}</span><span class="sp"></span>${a('#/catalog/' + g[0], t('common.view_all'), 'btn sm ghost')}</div>${items.map(x => `<div class="row-i"><div class="grow ell">${a(href.performers({ [g[1]]: x.name }), esc(x.name))}</div><div class="bar" style="width:140px"><i style="width:${Math.max(2, x.performer_count / max * 100)}%"></i></div><span class="mono muted" style="width:70px;text-align:right">${fmt(x.performer_count)}</span></div>`).join('') || `<div class="row-i muted">${t('common.no_data')}</div>`}</div>`;
    };
    return { html: `<div class="wrap">${head(t('bio.title'), t('bio.sub'))}<div class="grid g2">${GROUPS.map((g, i) => panel(g, rows[i])).join('')}</div></div>` };
  },
};
