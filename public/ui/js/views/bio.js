import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { head, a, href, errorBox } from '../components.js';

const GROUPS = [['cups', 'cup', 'Cup'], ['blood_types', 'blood_type', 'Nhóm máu'], ['locations', 'location', 'Nơi sống']];

/* performer profiles by what the sources know: cup, blood type, location; every value opens the filtered performer list */
export default {
  async render() {
    let rows;
    try { rows = await Promise.all(GROUPS.map(g => api('meta/catalog/' + g[0], { per_page: 24 }, { ttl: 120000 }))); } catch (e) { return { html: `<div class="wrap">${head('Hồ sơ diễn viên', '')}${errorBox(e)}</div>` }; }
    const panel = (g, body) => {
      const items = body.data || [], max = Math.max(1, ...items.map(x => x.performer_count));
      const total = body.meta?.pagination?.total ?? items.length;
      return `<div class="panel"><div class="hd"><h2>${esc(g[2])}</h2><span class="chip mono">${fmt(total)} giá trị</span><span class="sp"></span>${a('#/catalog/' + g[0], 'Xem tất cả →', 'btn sm ghost')}</div>${items.map(x => `<div class="row-i"><div class="grow ell">${a(href.performers({ [g[1]]: x.name }), esc(x.name))}</div><div class="bar" style="width:140px"><i style="width:${Math.max(2, x.performer_count / max * 100)}%"></i></div><span class="mono muted" style="width:70px;text-align:right">${fmt(x.performer_count)}</span></div>`).join('') || '<div class="row-i muted">Chưa có dữ liệu.</div>'}</div>`;
    };
    return { html: `<div class="wrap">${head('Hồ sơ diễn viên', 'theo thông tin các nguồn cung cấp')}<div class="grid g2">${GROUPS.map((g, i) => panel(g, rows[i])).join('')}</div></div>` };
  },
};
