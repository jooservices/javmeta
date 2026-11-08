import { esc, fmt } from '../util.js';
import { admin } from '../api.js';
import { head, movieCard, emptyBox, errorBox } from '../components.js';

const TITLES = { favourite: 'Yêu thích', watch_later: 'Xem sau', seen: 'Đã xem' };

/* the movies the owner marked */
export default {
  async render({ params }) {
    const title = TITLES[params.kind];
    if (!title) return { html: `<div class="wrap">${emptyBox('Danh sách không tồn tại', esc(params.kind))}</div>` };
    let body;
    try { body = await admin('/ui-me/movies/' + params.kind); } catch (e) { return { html: `<div class="wrap">${head(title, '')}${errorBox(e)}</div>` }; }
    const items = body.data || [];
    return { html: `<div class="wrap">${head(title, `${fmt(items.length)} phim`)}${items.length ? `<div class="mgrid">${items.map((m, i) => movieCard(m, i)).join('')}</div>` : emptyBox('Chưa có phim nào', 'Mở một phim và bấm nút đánh dấu.')}</div>` };
  },
};
