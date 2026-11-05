import { esc } from '../util.js';
import { head, ic } from '../components.js';
import { admin, send } from '../api.js';
import { applyTheme, applyDensity, currentTheme, currentDensity } from '../theme.js';

const shortcutMod = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '') ? '⌘' : 'Ctrl';
const KEYS = [[`${shortcutMod}+K hoặc /`, 'Tìm kiếm'], ['t', 'Đổi sáng/tối'], ['Esc', 'Đóng'], ['?', 'Bảng phím tắt']];

/* Proposal: the long page becomes tabs; each tab holds the related groups as sections. */
const TABS = [
  ['personal', 'Cá nhân', 'user', null],
  ['crawl', 'Crawl & hàng đợi', 'spider', ['queue', 'worker', 'browser', 'retry', 'crawl', 'cleanup', 'timeouts']],
  ['notify', 'Thông báo & báo cáo', 'bell', ['alerts', 'telegram', 'report', 'follow_notifications']],
  ['health', 'Giám sát & sao lưu', 'clock', ['readiness', 'observability', 'stats', 'startup_health', 'backup']],
  ['search', 'Tìm kiếm & AI', 'search', ['search', 'ai', 'normalization']],
  ['media', 'Thư viện & media', 'film', ['library', 'downloads', 'files', 'media', 'capture', 'dataset']],
  ['system', 'Hệ thống & bảo mật', 'gear', ['domains', 'ui_security']],
];
const tabStore = { get: () => { try { return sessionStorage.getItem('settings-tab'); } catch { return null; } }, set: v => { try { sessionStorage.setItem('settings-tab', v); } catch { /* ignore */ } } };

export default {
  async render() {
    const theme = currentTheme(), dens = currentDensity();
    let sys = null;
    try { sys = (await admin('/ui-admin/settings')).data; } catch { /* the personal settings still work */ }
    const row = (t, d, c) => `<div class="row-i"><div class="grow"><b>${t}</b><div class="muted">${d}</div></div>${c}</div>`;
    const active = TABS.some(t => t[0] === tabStore.get()) ? tabStore.get() : 'personal';
    const known = new Set(TABS.flatMap(t => t[3] || []));
    const pane = (id, inner) => `<section class="pane" data-pane="${id}" ${id === active ? '' : 'hidden'}>${inner}</section>`;
    const tabs = `<div class="tabs" role="tablist" style="margin-bottom:12px">${TABS.map(t => `<button type="button" class="tab ${t[0] === active ? 'on' : ''}" role="tab" data-tab="${t[0]}">${ic(t[2])}${t[1]}</button>`).join('')}</div>`;
    const html = `<div class="wrap">${head('Cài đặt', 'chia theo nhóm; lưu từng mục')}${tabs}
      ${pane('personal', `<div class="grid g2">
      <div class="panel"><div class="hd"><h2>Giao diện</h2></div>
        ${row('Chủ đề', 'Tối là mặc định', `<div class="seg">${['dark', 'light', 'system'].map(t => `<button class="${theme === t ? 'on' : ''}" data-act="settheme" data-v="${t}">${{ dark: 'Tối', light: 'Sáng', system: 'Hệ thống' }[t]}</button>`).join('')}</div>`)}
        ${row('Mật độ', 'Gọn hiển thị nhiều thông tin hơn', `<div class="seg">${['normal', 'compact'].map(t => `<button class="${dens === t ? 'on' : ''}" data-act="setdens" data-v="${t}">${{ normal: 'Vừa', compact: 'Gọn' }[t]}</button>`).join('')}</div>`)}</div>
      <div class="panel"><div class="hd"><h2>Hệ thống</h2></div>
        ${row('Thông báo', 'Sửa mẫu, xem trước và phục hồi lịch sử thông báo', `<a class="btn sm" href="#/settings/notifications">${ic('edit')}Quản lý mẫu</a>`)}
        ${row('Báo cáo định kỳ', 'Xem trước, chỉnh lịch và gửi báo cáo Telegram.', `<a class="btn sm" href="#/settings/report">${ic('send')}Mở Báo cáo</a>`)}
        ${row('Bảng điều khiển vận hành', 'Số liệu crawl và hàng đợi', `<a class="btn sm" href="${esc(document.querySelector('meta[name=command-center-url]')?.content || '/ops')}">${ic('ext')}Mở Command Center</a>`)}
        ${row('Đăng xuất', 'Kết thúc phiên của chủ sở hữu', `<form method="post" action="/logout" class="logout-form"><input type="hidden" name="_token" value="${esc(document.querySelector('meta[name=csrf-token]')?.content || '')}"><button class="btn sm" type="submit">${ic('logout')}Đăng xuất</button></form>`)}
        ${row('API và MCP', 'Cùng dữ liệu cho các công cụ AI', '<span class="chip ok">/api/v1</span>')}</div>
      <div class="panel" style="grid-column:1/-1"><div class="hd"><h2>Phím tắt</h2></div><div class="bd"><dl class="kv" style="grid-template-columns:130px 1fr">${KEYS.map(k => `<dt><kbd>${k[0]}</kbd></dt><dd>${k[1]}</dd>`).join('')}</dl></div></div></div></div>`)}
      ${sys ? TABS.filter(t => t[3]).map(t => pane(t[0], systemPanels(sys, t[0] === 'system' ? [...t[3], ...Object.keys(sys.groups).filter(g => !known.has(g))] : t[3]))).join('') : ''}</div>`;
    return {
      html,
      mount: root => { mountSystem(root); root.addEventListener('click', e => {
        const tab = e.target.closest('[data-tab]');
        if (tab) { tabStore.set(tab.dataset.tab); root.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('on', b === tab)); root.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== tab.dataset.tab; }); return; }
        const t = e.target.closest('[data-act]'); if (!t) return;
        if (t.dataset.act === 'settheme') { applyTheme(t.dataset.v); location.reload(); }
        if (t.dataset.act === 'setdens') { applyDensity(t.dataset.v); location.reload(); }
      }); },
    };
  },
};

const input = r => {
  const id = 'st-' + r.key;
  if (r.type === 'bool') return `<label class="switch"><input type="checkbox" id="${id}" data-key="${esc(r.key)}" data-type="bool" ${r.value ? 'checked' : ''}><span class="track"></span><span class="txt">${r.value ? 'Bật' : 'Tắt'}</span></label>`;
  if (r.type === 'secret') return `<input type="password" id="${id}" data-key="${esc(r.key)}" data-type="secret" autocomplete="new-password" placeholder="${r.is_set ? 'đã đặt, nhập để thay (để trống = giữ nguyên)' : 'chưa đặt'}" style="min-width:240px">`;
  if (r.multiline) return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="string" rows="6" maxlength="${r.max_length || 500}" style="min-width:min(100%,420px)">${esc(r.value || '')}</textarea>`;
  if (r.type === 'string_list' && Array.isArray(r.item_options) && r.item_options.length) return `<div role="group" aria-label="${esc(r.label)}">${r.item_options.map(option => `<label class="switch"><input type="checkbox" data-key="${esc(r.key)}" data-type="string_list" value="${esc(option)}" ${(r.value || []).includes(option) ? 'checked' : ''}><span class="track"></span> <span>${esc(settingOptionLabel(r.group, option))}</span></label>`).join('')}</div>`;
  if (r.type === 'string_list') return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="string_list" rows="3" style="min-width:300px">${esc((r.value || []).join('\n'))}</textarea>`;
  if (Array.isArray(r.options) && r.options.length > 0) return `<select id="${id}" data-key="${esc(r.key)}" data-type="${esc(r.type)}" style="min-width:140px">${r.options.map(option => `<option value="${esc(option)}"${String(option) === String(r.value) ? ' selected' : ''}>${esc(option)}</option>`).join('')}</select>`;
  if (r.type === 'json') {
    const value = r.warning && typeof r.value === 'string' ? r.value : JSON.stringify(r.value || {}, null, 2);
    return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="json" rows="5" maxlength="${r.max_length || 500}" spellcheck="false" aria-label="${esc(r.label)}" style="min-width:min(100%,420px);font-family:monospace">${esc(value)}</textarea>`;
  }
  const num = r.type === 'int' || r.type === 'float';
  return `<input ${num ? `type="number" step="${r.type === 'float' ? '0.05' : '1'}"` : 'type="text"'} id="${id}" data-key="${esc(r.key)}" data-type="${r.type}" value="${esc(Array.isArray(r.value) ? r.value.join(',') : (r.value ?? ''))}" ${r.min != null ? `min="${r.min}"` : ''} ${r.max != null ? `max="${r.max}"` : ''} style="min-width:140px">`;
};

/* every feature switch and operational value, stored in the database; grouped, saved per group */
function systemPanels(sys, only) {
  const byGroup = {};
  sys.settings.forEach(r => { (byGroup[r.group] ||= []).push(r); });
  return '<div class="muted" style="margin-bottom:10px"><span class="chip ok">lưu trong cơ sở dữ liệu</span> Sau khi lưu, mỗi mục hiển thị thời gian áp dụng dự kiến.</div>' + '<div class="section-stack">' +
    Object.entries(sys.groups).filter(([g]) => byGroup[g] && (!only || only.includes(g))).map(([g, title]) => `<div class="section" data-group="${esc(g)}"><div class="hd"><h2>${esc(title)}</h2><span class="sp"></span><span class="muted" data-msg></span><button class="btn sm pri" data-save="${esc(g)}">${ic('save')}Lưu</button></div>` +
      byGroup[g].map(r => `<div class="row-i" style="align-items:flex-start;flex-wrap:wrap"><div class="grow" style="min-width:220px"><b>${esc(r.label)}</b> ${r.source === 'database' ? '<span class="chip ok">DB</span>' : r.source === 'environment' ? '<span class="chip warn">env chuyển đổi</span>' : '<span class="chip warn">mặc định</span>'}${r.apply_after_seconds > 0 ? ` <span class="chip">áp dụng sau tối đa ${r.apply_after_seconds} giây</span>` : ''}${r.restart ? ` <span class="chip">khởi động lại ${esc(r.restart)}</span>` : ''}<div class="muted">${esc(r.description)}</div>${r.warning ? `<div class="err" role="alert">${esc(r.warning)}</div>` : ''}</div><div>${input(r)}</div></div>`).join('') + '</div>').join('') + '</div>';
}

function mountSystem(root) {
  root.addEventListener('change', e => { const el = e.target; if (el.matches?.('.switch input[data-type="bool"]')) el.closest('.switch').querySelector('.txt').textContent = el.checked ? 'Bật' : 'Tắt'; });
  root.addEventListener('click', async e => {
    const b = e.target.closest('[data-save]'); if (!b) return;
    const panel = b.closest('[data-group]'), msg = panel.querySelector('[data-msg]');
    const settings = {};
    let invalid = '';
    panel.querySelectorAll('[data-key]').forEach(el => {
      const t = el.dataset.type;
      if (t === 'bool') settings[el.dataset.key] = el.checked;
      else if (t === 'string_list' && el.type === 'checkbox') settings[el.dataset.key] = Array.from(panel.querySelectorAll('[data-type="string_list"][type="checkbox"]')).filter(input => input.dataset.key === el.dataset.key && input.checked).map(input => input.value);
      else if (t === 'secret') { if (el.value !== '') settings[el.dataset.key] = el.value; }
      else if (t === 'json') {
        try { settings[el.dataset.key] = JSON.parse(el.value); }
        catch { invalid = `${el.getAttribute('aria-label')}: JSON không hợp lệ.`; }
      }
      else settings[el.dataset.key] = el.value;
    });
    if (invalid) { msg.innerHTML = `<span class="err" role="alert">${esc(invalid)}</span>`; return; }
    b.disabled = true; msg.textContent = 'Đang lưu…';
    try {
      const result = await send('PUT', '/ui-admin/settings', { settings });
      const delay = Number(result.data?.apply_after_seconds || 30);
      msg.textContent = `Đã lưu; áp dụng sau tối đa ${delay} giây`;
      setTimeout(() => location.reload(), 1500);
    } catch (err) {
      const errs = err.body?.data?.errors; msg.innerHTML = `<span class="err">${esc(errs ? Object.values(errs).join(' · ') : err.message)}</span>`;
    } finally { b.disabled = false; }
  });
}

function settingOptionLabel(group, option) {
  if (group !== 'report') return option;

  return ({ movies: 'Phim', performers: 'Diễn viên', crawl: 'Thu thập dữ liệu', sources: 'Nguồn', fleet: 'Cụm nút', workers: 'Worker', servers: 'Máy chủ', services: 'Dịch vụ', telegram: 'Telegram' })[option] || option;
}
