import { esc, fmt } from '../util.js';
import { admin, send } from '../api.js';
import { head, a, href, errorBox, emptyBox } from '../components.js';

const size = b => b >= 1e9 ? (b / 1e9).toFixed(2) + ' GB' : b >= 1e6 ? (b / 1e6).toFixed(0) + ' MB' : fmt(b) + ' B';
const eta = s => s == null ? '—' : s >= 3600 ? Math.floor(s / 3600) + 'h ' + Math.floor(s % 3600 / 60) + 'm' : s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's';
const STATE = { downloading: ['đang tải', 'ok'], paused: ['tạm dừng', 'warn'], completed: ['xong', 'ok'], error: ['lỗi', 'bad'], waiting: ['chờ', ''] };

const row = t => {
  const [label, cls] = STATE[t.state] || [t.state, ''];
  return `<div class="row-i" style="flex-wrap:wrap" data-hash="${esc(t.hash)}"><div class="grow" style="min-width:240px"><div class="ell">${t.movie_code ? a(href.movie(t.movie_code), esc(t.movie_code)) + ' · ' : ''}<span title="${esc(t.name)}">${esc(t.name)}</span></div>
    <div class="bar" style="margin-top:4px"><i style="width:${Math.max(1, t.progress)}%"></i></div></div>
    <span class="chip ${cls}">${esc(label)}</span><span class="mono" style="width:60px;text-align:right">${t.progress}%</span><span class="mono muted" style="width:110px;text-align:right">${t.completed ? size(t.size) : size(t.speed) + '/s'}</span><span class="mono muted" style="width:70px;text-align:right">${t.completed ? '' : eta(t.eta)}</span>
    <span>${t.completed ? '<button class="btn sm" data-links>Liên kết</button>' : (t.state === 'paused' ? '<button class="btn sm" data-resume>Tiếp tục</button>' : '<button class="btn sm" data-pause>Tạm dừng</button>')} <button class="btn sm" data-remove>Xoá</button></span><div class="muted" data-out style="width:100%"></div></div>`;
};

/* downloads started from the movie pages: progress, speed, ETA, pause, resume, remove, links to finished files */
export default {
  async render() {
    let st;
    try { st = (await admin('/ui-me/downloads/status')).data; } catch (e) { return { html: `<div class="wrap">${head('Tải về', '')}${errorBox(e)}</div>` }; }
    if (!st.enabled) return { html: `<div class="wrap">${head('Tải về', 'qBittorrent')}${emptyBox('Tải về đang tắt', 'Bật trong Cài đặt → Tải về sau khi đã duyệt văn bản quyết định (JOOXCR-223).', a('#/settings', 'Mở Cài đặt', 'btn'))}</div>` };
    let items;
    try { items = (await admin('/ui-me/downloads')).data.items; } catch (e) { return { html: `<div class="wrap">${head('Tải về', 'qBittorrent')}${errorBox(e)}</div>` }; }
    const html = `<div class="wrap">${head('Tải về', `${fmt(items.length)} mục`)}<div class="panel" id="dlist">${items.map(row).join('') || '<div class="row-i muted">Chưa có mục nào. Mở một phim và bấm “Tải về”.</div>'}</div></div>`;
    return { html, mount };
  },
};

function mount(root) {
  const list = root.querySelector('#dlist');
  const refresh = async () => { try { list.innerHTML = (await admin('/ui-me/downloads')).data.items.map(row).join('') || '<div class="row-i muted">Chưa có mục nào.</div>'; } catch { /* keep the last view */ } };
  const timer = setInterval(refresh, 5000);
  list.addEventListener('click', async e => {
    const r = e.target.closest('[data-hash]'); if (!r) return;
    const h = r.dataset.hash, out = r.querySelector('[data-out]');
    try {
      if (e.target.closest('[data-pause]')) { await send('POST', `/ui-me/downloads/${h}/pause`); refresh(); }
      if (e.target.closest('[data-resume]')) { await send('POST', `/ui-me/downloads/${h}/resume`); refresh(); }
      if (e.target.closest('[data-remove]') && confirm('Xoá mục này? Chọn OK để giữ tệp, Hủy để dừng.')) { const files = confirm('Xoá luôn cả tệp đã tải?'); await send('DELETE', `/ui-me/downloads/${h}${files ? '?files=1' : ''}`); refresh(); }
      if (e.target.closest('[data-links]')) {
        const d = (await admin(`/ui-me/downloads/${h}/links`)).data;
        out.innerHTML = d.links.length ? d.links.map(l => `<a class="lnk" href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.name)}</a> <button class="btn sm" data-act="copy" data-v="${esc(l.url)}">Sao chép liên kết</button>`).join('<br>') + ' <span class="dim">(hết hạn sau ít phút)</span>' : 'Không có tệp.';
      }
    } catch (err) { out.innerHTML = `<span class="err">${esc(err.message)}</span>`; }
  });
  return () => clearInterval(timer);
}
