import { fmt, toast } from '../util.js';
import { admin, send } from '../api.js';
import { head, movieCard, emptyBox, errorBox } from '../components.js';

/* new movies (last 30 days) of the performers, makers and series the owner follows */
export default {
  async render() {
    let body;
    try { body = await admin('/ui-me/feed'); } catch (e) { return { html: `<div class="wrap">${head('Theo dõi', '')}${errorBox(e)}</div>` }; }
    const items = body.data || [], m = body.meta || {};
    const newCount = Number(m.new_since_last_visit) > 0 ? Number(m.new_since_last_visit) : 0;
    const sub = m.follows
      ? `${fmt(m.follows)} mục đang theo dõi · ${fmt(items.length)} phim${newCount ? ` · ${fmt(newCount)} mới` : ''}`
      : 'chưa theo dõi gì';
    const markSeen = items.length ? `<button type="button" class="btn sm" data-feed-seen>Đánh dấu đã xem</button>` : '';
    const html = `<div class="wrap">${head('Theo dõi', sub, markSeen)}${items.length ? `<div class="mgrid">${items.map((x, i) => movieCard(x, i)).join('')}</div>` : emptyBox(m.follows ? 'Chưa có phim mới trong 30 ngày' : 'Chưa theo dõi gì', 'Bấm “Theo dõi” ở trang diễn viên, hãng hoặc loạt phim.')}</div>`;
    return {
      html,
      mount: root => {
        root.querySelector('[data-feed-seen]')?.addEventListener('click', async e => {
          const btn = e.currentTarget;
          btn.disabled = true;
          try { await send('POST', '/ui-me/feed/seen'); toast('Đã đánh dấu đã xem'); btn.remove(); }
          catch (err) { toast(err.message); btn.disabled = false; }
        });
      },
    };
  },
};
