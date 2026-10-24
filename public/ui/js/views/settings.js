import { esc } from '../util.js';
import { head, ic } from '../components.js';
import { applyTheme, applyDensity, currentTheme, currentDensity } from '../theme.js';

const KEYS = [['⌘K hoặc /', 'Tìm kiếm'], ['g h', 'Discover'], ['g m', 'Phim'], ['g p', 'Diễn viên'], ['g c', 'Crawl'], ['g s', 'Tìm kiếm'], ['t', 'Đổi sáng/tối'], ['Esc', 'Đóng'], ['?', 'Bảng phím tắt']];

export default {
  async render() {
    const theme = currentTheme(), dens = currentDensity();
    const row = (t, d, c) => `<div class="row-i"><div class="grow"><b>${t}</b><div class="muted">${d}</div></div>${c}</div>`;
    const html = `<div class="wrap">${head('Cài đặt', 'lưu trên trình duyệt này')}<div class="grid g2">
      <div class="panel"><div class="hd"><h2>Giao diện</h2></div>
        ${row('Chủ đề', 'Tối là mặc định', `<div class="seg">${['dark', 'light', 'system'].map(t => `<button class="${theme === t ? 'on' : ''}" data-act="settheme" data-v="${t}">${{ dark: 'Tối', light: 'Sáng', system: 'Hệ thống' }[t]}</button>`).join('')}</div>`)}
        ${row('Mật độ', 'Gọn hiển thị nhiều thông tin hơn', `<div class="seg">${['normal', 'compact'].map(t => `<button class="${dens === t ? 'on' : ''}" data-act="setdens" data-v="${t}">${{ normal: 'Vừa', compact: 'Gọn' }[t]}</button>`).join('')}</div>`)}</div>
      <div class="panel"><div class="hd"><h2>Hệ thống</h2></div>
        ${row('Bảng điều khiển vận hành', 'Số liệu crawl và hàng đợi', '<a class="btn sm" href="/ops">Mở /ops</a>')}
        ${row('Đăng xuất', 'Kết thúc phiên của chủ sở hữu', `<form method="post" action="/logout" class="logout-form"><input type="hidden" name="_token" value="${esc(document.querySelector('meta[name=csrf-token]')?.content || '')}"><button class="btn sm" type="submit">Đăng xuất</button></form>`)}
        ${row('API và MCP', 'Cùng dữ liệu cho các công cụ AI', '<span class="chip ok">/api/v1</span>')}</div>
      <div class="panel" style="grid-column:1/-1"><div class="hd"><h2>Phím tắt</h2></div><div class="bd"><dl class="kv" style="grid-template-columns:130px 1fr">${KEYS.map(k => `<dt><kbd>${k[0]}</kbd></dt><dd>${k[1]}</dd>`).join('')}</dl></div></div></div></div>`;
    return {
      html,
      mount: root => root.addEventListener('click', e => {
        const t = e.target.closest('[data-act]'); if (!t) return;
        if (t.dataset.act === 'settheme') { applyTheme(t.dataset.v); location.reload(); }
        if (t.dataset.act === 'setdens') { applyDensity(t.dataset.v); location.reload(); }
      }),
    };
  },
};
