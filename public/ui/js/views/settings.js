import { esc } from '../util.js';
import { head, ic } from '../components.js';
import { admin, send } from '../api.js';
import { applyTheme, applyDensity, currentTheme, currentDensity } from '../theme.js';

const KEYS = [['⌘K hoặc /', 'Tìm kiếm'], ['g h', 'Discover'], ['g m', 'Phim'], ['g p', 'Diễn viên'], ['g c', 'Crawl'], ['g s', 'Tìm kiếm'], ['t', 'Đổi sáng/tối'], ['Esc', 'Đóng'], ['?', 'Bảng phím tắt']];

export default {
  async render() {
    const theme = currentTheme(), dens = currentDensity();
    let sys = null;
    try { sys = (await admin('/ui-admin/settings')).data; } catch { /* the personal settings still work */ }
    const row = (t, d, c) => `<div class="row-i"><div class="grow"><b>${t}</b><div class="muted">${d}</div></div>${c}</div>`;
    const html = `<div class="wrap">${head('Cài đặt', 'lưu trên trình duyệt này')}<div class="grid g2">
      <div class="panel"><div class="hd"><h2>Giao diện</h2></div>
        ${row('Chủ đề', 'Tối là mặc định', `<div class="seg">${['dark', 'light', 'system'].map(t => `<button class="${theme === t ? 'on' : ''}" data-act="settheme" data-v="${t}">${{ dark: 'Tối', light: 'Sáng', system: 'Hệ thống' }[t]}</button>`).join('')}</div>`)}
        ${row('Mật độ', 'Gọn hiển thị nhiều thông tin hơn', `<div class="seg">${['normal', 'compact'].map(t => `<button class="${dens === t ? 'on' : ''}" data-act="setdens" data-v="${t}">${{ normal: 'Vừa', compact: 'Gọn' }[t]}</button>`).join('')}</div>`)}</div>
      <div class="panel"><div class="hd"><h2>Hệ thống</h2></div>
        ${row('Bảng điều khiển vận hành', 'Số liệu crawl và hàng đợi', `<a class="btn sm" href="${esc(document.querySelector('meta[name=command-center-url]')?.content || '/ops')}">Mở Command Center</a>`)}
        ${row('Đăng xuất', 'Kết thúc phiên của chủ sở hữu', `<form method="post" action="/logout" class="logout-form"><input type="hidden" name="_token" value="${esc(document.querySelector('meta[name=csrf-token]')?.content || '')}"><button class="btn sm" type="submit">Đăng xuất</button></form>`)}
        ${row('API và MCP', 'Cùng dữ liệu cho các công cụ AI', '<span class="chip ok">/api/v1</span>')}</div>
      ${sys ? systemPanels(sys) : ''}
      <div class="panel" style="grid-column:1/-1"><div class="hd"><h2>Phím tắt</h2></div><div class="bd"><dl class="kv" style="grid-template-columns:130px 1fr">${KEYS.map(k => `<dt><kbd>${k[0]}</kbd></dt><dd>${k[1]}</dd>`).join('')}</dl></div></div></div></div>`;
    return {
      html,
      mount: root => { mountSystem(root); root.addEventListener('click', e => {
        const t = e.target.closest('[data-act]'); if (!t) return;
        if (t.dataset.act === 'settheme') { applyTheme(t.dataset.v); location.reload(); }
        if (t.dataset.act === 'setdens') { applyDensity(t.dataset.v); location.reload(); }
      }); },
    };
  },
};

const input = r => {
  const id = 'st-' + r.key;
  if (r.type === 'bool') return `<label class="sw"><input type="checkbox" id="${id}" data-key="${esc(r.key)}" data-type="bool" ${r.value ? 'checked' : ''}> <span>${r.value ? 'Bật' : 'Tắt'}</span></label>`;
  if (r.type === 'secret') return `<input type="password" id="${id}" data-key="${esc(r.key)}" data-type="secret" autocomplete="new-password" placeholder="${r.is_set ? 'đã đặt, nhập để thay (để trống = giữ nguyên)' : 'chưa đặt'}" style="min-width:240px">`;
  if (r.type === 'string_list') return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="string_list" rows="3" style="min-width:300px">${esc((r.value || []).join('\n'))}</textarea>`;
  const num = r.type === 'int' || r.type === 'float';
  return `<input ${num ? `type="number" step="${r.type === 'float' ? '0.05' : '1'}"` : 'type="text"'} id="${id}" data-key="${esc(r.key)}" data-type="${r.type}" value="${esc(Array.isArray(r.value) ? r.value.join(',') : (r.value ?? ''))}" ${r.min != null ? `min="${r.min}"` : ''} ${r.max != null ? `max="${r.max}"` : ''} style="min-width:140px">`;
};

/* every feature switch and operational value, stored in the database; grouped, saved per group */
function systemPanels(sys) {
  const byGroup = {};
  sys.settings.forEach(r => { (byGroup[r.group] ||= []).push(r); });
  return `<div class="panel" style="grid-column:1/-1"><div class="hd"><h2>Cấu hình hệ thống</h2><span class="chip ok">lưu trong cơ sở dữ liệu</span></div><div class="bd muted">Mọi tính năng và cấu hình nằm ở đây, không đặt trong tệp env. Thay đổi có hiệu lực ngay, trừ mục có nhãn “khởi động lại”.</div></div>` +
    Object.entries(sys.groups).filter(([g]) => byGroup[g]).map(([g, title]) => `<div class="panel" style="grid-column:1/-1" data-group="${esc(g)}"><div class="hd"><h2>${esc(title)}</h2><span class="sp"></span><span class="muted" data-msg></span><button class="btn sm pri" data-save="${esc(g)}">Lưu</button></div>` +
      byGroup[g].map(r => `<div class="row-i" style="align-items:flex-start;flex-wrap:wrap"><div class="grow" style="min-width:220px"><b>${esc(r.label)}</b> ${r.source === 'database' ? '<span class="chip ok">DB</span>' : '<span class="chip warn">mặc định</span>'}${r.restart ? ` <span class="chip">khởi động lại ${esc(r.restart)}</span>` : ''}<div class="muted">${esc(r.description)}</div></div><div>${input(r)}</div></div>`).join('') + '</div>').join('');
}

function mountSystem(root) {
  root.addEventListener('click', async e => {
    const b = e.target.closest('[data-save]'); if (!b) return;
    const panel = b.closest('[data-group]'), msg = panel.querySelector('[data-msg]');
    const settings = {};
    panel.querySelectorAll('[data-key]').forEach(el => {
      const t = el.dataset.type;
      if (t === 'bool') settings[el.dataset.key] = el.checked;
      else if (t === 'secret') { if (el.value !== '') settings[el.dataset.key] = el.value; }
      else settings[el.dataset.key] = el.value;
    });
    b.disabled = true; msg.textContent = 'Đang lưu…';
    try {
      await send('PUT', '/ui-admin/settings', { settings });
      msg.textContent = 'Đã lưu'; setTimeout(() => location.reload(), 600);
    } catch (err) {
      const errs = err.body?.data?.errors; msg.innerHTML = `<span class="err">${esc(errs ? Object.values(errs).join(' · ') : err.message)}</span>`;
    } finally { b.disabled = false; }
  });
}
