import { admin, send } from '../api.js';
import { esc } from '../util.js';
import { head, errorBox } from '../components.js';

export const kinds = [['thumbnails', 'Ảnh preview'], ['scenes', 'Tách cảnh']];
export const mediaButtons = id => kinds.map(([kind, label]) => `<button class="btn sm ghost" data-media-kind="${kind}" data-file="${Number(id)}">${label}</button>`).join('');
export function mountMedia(root) {
  const handle = async e => {
    const b = e.target.closest('[data-media-kind], [data-media-retry]');
    if (!b || b.disabled) return;
    b.disabled = true;
    const message = root.querySelector('[data-media-message]');
    if (message) message.textContent = 'Đang gửi…';
    try {
      if (b.dataset.mediaRetry) await send('POST', `/ui-me/media/${b.dataset.mediaRetry}/retry`);
      else await send('POST', '/ui-me/media', { kind: b.dataset.mediaKind, library_file_id: Number(b.dataset.file) });
      if (message) message.textContent = 'Đã xếp hàng. Mở Media để xem tiến độ.';
    } catch (error) { if (message) message.textContent = error.message; }
    finally { b.disabled = false; }
  };
  root.addEventListener('click', handle);
  return () => root.removeEventListener('click', handle);
}
const statuses = { pending: 'Đang chờ', running: 'Đang xử lý', done: 'Hoàn tất', failed: 'Thất bại' };
export default {
  async render({ query = {} } = {}) {
    try {
      const page = Math.max(1, Number(query.page) || 1);
      const { data: jobs } = await admin(`/ui-me/media?page=${page}`);
      return { html: `<div class="wrap">${head('Xử lý media', 'Hàng đợi trên máy nội bộ', '<a class="btn" href="#/library">Chọn tệp</a>')}<p role="status" data-media-message></p><div class="panel">${jobs.data.map(j => `<div class="bd" style="overflow-wrap:anywhere"><strong>#${j.id} · ${esc(kinds.find(k => k[0] === j.kind)?.[1] || j.kind)}</strong><p>${esc(statuses[j.status])} · Tệp #${j.library_file_id} · Lần ${j.attempts}</p>${j.status === 'running' ? '<progress aria-label="Đang xử lý"></progress>' : ''}${j.status === 'done' ? '<progress value="1" max="1" aria-label="Hoàn tất"></progress>' : ''}${j.error ? `<p class="err">${esc(j.error)}</p>` : ''}${j.status === 'failed' ? `<button class="btn" data-media-retry="${j.id}">Thử lại</button>` : ''}</div>`).join('') || '<div class="bd">Chưa có việc xử lý media.</div>'}</div><nav>${page > 1 ? `<a class="btn" href="#/media?page=${page - 1}">Trước</a>` : ''}${jobs.next_page_url ? `<a class="btn" href="#/media?page=${page + 1}">Sau</a>` : ''}<button class="btn" data-refresh>Làm mới</button></nav></div>`, mount(root) { root.querySelector('[data-refresh]').onclick = () => location.reload(); return mountMedia(root); } };
    } catch (e) { return { html: `<div class="wrap">${head('Xử lý media', '')}${errorBox(e)}</div>` }; }
  },
};
