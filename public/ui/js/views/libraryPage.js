import { mediaButtons, mountMedia } from './media.js';
import { esc, fmt } from '../util.js';
import { admin } from '../api.js';
import { head, a, href, errorBox, emptyBox } from '../components.js';

const size = b => b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : b >= 1e6 ? (b / 1e6).toFixed(0) + ' MB' : fmt(b) + ' B';

/* what is on the disk: totals, use by folder, the movies that have a file, the files that matched no movie */
export default {
  async render({ query = {} } = {}) {
    let sum, on, un, files;
    try { [sum, on, un, files] = (await Promise.all([admin('/ui-me/library'), admin('/ui-me/library/on-disk'), admin('/ui-me/library/unmatched'), admin('/ui-me/media/files?page=' + Math.max(1, Number(query.media_page) || 1))])).map(r => r); } catch (e) { return { html: `<div class="wrap">${head('Thư viện', '')}${errorBox(e)}</div>` }; }
    const s = sum.data;
    if (!s.files && !s.missing) {
      return { html: `<div class="wrap">${head('Thư viện', 'tệp trên đĩa')}${emptyBox(s.configured ? 'Chưa quét xong lần nào' : 'Chưa cấu hình thư mục', 'Vào Cài đặt → Thư viện trên đĩa để chọn thư mục và bật quét. Quét ngay: php artisan library:scan --force.', a('#/settings', 'Mở Cài đặt', 'btn'))}</div>` };
    }
    const max = Math.max(1, ...s.by_folder.map(f => f.bytes));
    const tile = (label, v, sub = '') => `<div class="panel"><div class="bd"><div class="muted">${esc(label)}</div><div style="font-size:22px;font-weight:600" class="mono">${v}</div><div class="dim">${esc(sub)}</div></div></div>`;
    const html = `<div class="wrap">${head('Thư viện', `quét lần cuối ${esc((s.last_scan_at || '—').slice(0, 16))}`, a('#/movies?on_disk=1', 'Phim đã có →', 'btn sm ghost'))}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:10px">${tile('Tệp video', fmt(s.files), `${fmt(s.missing)} tệp đã biến mất`)}${tile('Đã ghép phim', fmt(s.matched), 'nhận ra mã phim')}${tile('Chưa ghép', fmt(s.unmatched), 'cần xem lại')}${tile('Dung lượng', size(s.bytes), 'tổng các tệp còn')}</div>
      <div class="panel"><div class="hd"><h2>Dung lượng theo thư mục</h2></div>${s.by_folder.map(f => `<div class="row-i"><div class="grow ell mono" title="${esc(f.folder)}">${esc(f.folder)}</div><div class="bar" style="width:160px"><i style="width:${Math.max(2, f.bytes / max * 100)}%"></i></div><span class="mono muted" style="width:90px;text-align:right">${size(f.bytes)}</span><span class="mono muted" style="width:70px;text-align:right">${fmt(f.files)} tệp</span></div>`).join('')}</div>
      <div class="grid g2"><div class="panel"><div class="hd"><h2>Phim có trên máy</h2><span class="chip mono">${fmt(on.data.length)}</span></div>${on.data.map(x => `<div class="row-i"><div class="grow">${a(href.movie(x.code), esc(x.code))}</div><span class="mono muted">${x.files > 1 ? fmt(x.files) + ' tệp · ' : ''}${size(x.bytes)}</span></div>`).join('') || '<div class="row-i muted">Chưa có.</div>'}</div>
        <div class="panel"><div class="hd"><h2>Tệp chưa ghép được</h2><span class="chip mono">${fmt(un.meta.total)}</span></div>${un.data.map(f => `<div class="row-i"><div class="grow ell mono" title="${esc(f.path)}">${esc(f.path)}</div><span class="mono muted">${size(f.size)}</span></div>`).join('') || '<div class="row-i muted">Mọi tệp đã ghép.</div>'}</div></div></div>`;
    const processing = `<div class="panel"><div class="hd"><h2>Xử lý media</h2><a class="btn" href="#/media">Hàng đợi</a></div><p role="status" data-media-message></p>${files.data.data.map(f => `<div class="bd" style="overflow-wrap:anywhere"><p>${esc(f.code || f.path)}</p><div style="display:flex;flex-wrap:wrap;gap:6px">${mediaButtons(f.id)}</div></div>`).join('') || '<p>Chưa có tệp.</p>'}<nav>${files.data.prev_page_url ? `<a class="btn" href="#/library?media_page=${files.data.current_page - 1}">Trước</a>` : ''}${files.data.next_page_url ? `<a class="btn" href="#/library?media_page=${files.data.current_page + 1}">Sau</a>` : ''}</nav></div>`;
    return { html: html.replace(/<\/div>$/, () => processing + '</div>'), mount: mountMedia };
  },
};
