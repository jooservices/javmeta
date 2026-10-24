import { fmt } from '../util.js';
import { admin, send } from '../api.js';
import { head, movieCard, emptyBox, errorBox } from '../components.js';

/* new movies (last 30 days) of the performers, makers and series the owner follows */
export default {
  async render() {
    let body;
    try { body = await admin('/ui-me/feed'); } catch (e) { return { html: `<div class="wrap">${head('Theo dõi', '')}${errorBox(e)}</div>` }; }
    const items = body.data || [], m = body.meta || {};
    const sub = m.follows ? `${fmt(m.follows)} mục đang theo dõi · ${fmt(m.new_since_last_visit)} phim mới từ lần xem trước` : 'chưa theo dõi gì';
    const html = `<div class="wrap">${head('Theo dõi', sub)}${items.length ? `<div class="mgrid">${items.map((x, i) => movieCard(x, i)).join('')}</div>` : emptyBox(m.follows ? 'Chưa có phim mới trong 30 ngày' : 'Chưa theo dõi gì', 'Bấm “Theo dõi” ở trang diễn viên, maker hoặc series.')}</div>`;
    return { html, mount: () => { send('POST', '/ui-me/feed/seen').catch(() => {}); } };
  },
};
