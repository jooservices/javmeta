import { esc } from '../util.js';
import { head, ic } from '../components.js';
import { admin, send } from '../api.js';

const endpoint = '/ui-admin/notifications';

export default {
  async render() {
    let rows = [];
    let error = '';
    try { rows = (await admin(endpoint)).data || []; }
    catch (e) { error = e.message; }
    let quietHtml = '<div class="err" role="alert">Không tải được chế độ yên lặng.</div>';
    let dailyScheduleHtml = '<div class="err" role="alert">Không tải được lịch báo cáo hằng ngày.</div>';
    try {
      const settings = (await admin('/ui-admin/settings')).data.settings || [];
      const quiet = settings.find(row => row.key === 'notifications.quiet_mode');
      const dailyTime = settings.find(row => row.key === 'report.daily_time');
      const dailyTimezone = settings.find(row => row.key === 'report.daily_timezone');
      quietHtml = quiet
        ? `<label class="switch"><input type="checkbox" data-quiet-mode ${quiet.value ? 'checked' : ''}><span class="track"></span><span class="txt">Chỉ gửi CRITICAL và sự kiện bảo mật</span></label><span class="muted" data-quiet-status aria-live="polite"></span>`
        : '<div class="err" role="alert">Chưa có cấu hình chế độ yên lặng.</div>';
      dailyScheduleHtml = dailyTime && dailyTimezone
        ? `<div class="bd"><label>Giờ gửi cục bộ<input type="time" data-daily-time value="${esc(dailyTime.value || '08:00')}"></label><label>Múi giờ IANA<input type="text" data-daily-timezone maxlength="64" value="${esc(dailyTimezone.value || 'America/Mexico_City')}"></label><button class="btn pri" data-action="save-daily-schedule">${ic('save')}Lưu lịch</button><span class="muted" data-daily-schedule-status aria-live="polite"></span></div>`
        : '<div class="err" role="alert">Chưa có cấu hình lịch báo cáo hằng ngày.</div>';
    } catch (e) { quietHtml = `<div class="err" role="alert">${esc(e.message)}</div>`; }
    const previews = await Promise.all([
      reportPreview('/ui-admin/report/periodic', 'Báo cáo định kỳ'),
      reportPreview('/ui-admin/report/daily', 'Báo cáo hằng ngày'),
    ]);
    const content = error
      ? `<div class="panel"><div class="bd"><div class="err" role="alert">${esc(error)}</div><button class="btn" data-action="reload">Thử lại</button></div></div>`
      : rows.length === 0
        ? '<div class="panel"><div class="bd empty">Chưa có mẫu thông báo.</div></div>'
        : `<section class="panel"><div class="hd"><h2>Chế độ yên lặng</h2></div><div class="bd">${quietHtml}<div class="muted" style="margin-top:8px">WARNING chỉ nằm trong báo cáo; NOTICE được gửi theo từng sự kiện và cooldown.</div></div></section><section class="panel notification-daily-schedule"><div class="hd"><h2>Lịch báo cáo hằng ngày</h2></div>${dailyScheduleHtml}</section><section class="panel"><div class="hd"><h2>Xem trước báo cáo kế tiếp</h2></div><div class="bd notification-report-previews">${previews.join('')}</div></section><div class="notifications-layout"><section class="panel notifications-list"><div class="hd"><h2>Danh sách thông báo</h2><span class="chip">${rows.length}</span></div><div class="notifications-scroll"><table><thead><tr><th>Tên</th><th>Mục đích</th><th>Kênh</th><th>Bật</th><th>Tầng</th><th>Gửi gần nhất</th><th>Sửa gần nhất</th><th></th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name)}</td><td>${esc(row.usage)}</td><td>${esc(row.channel)}</td><td>${row.enabled ? 'Bật' : 'Tắt'}</td><td>${esc(row.tier || row.default_severity)}</td><td>${esc(row.last_sent_at || 'Chưa gửi')}</td><td>${esc(row.updated_at || 'Mặc định')}</td><td><button class="btn sm" data-action="edit" data-key="${esc(row.key)}">Sửa</button></td></tr>`).join('')}</tbody></table></div></section><section class="panel notifications-editor"><div class="hd"><h2>Chỉnh sửa mẫu và chính sách</h2><span class="sp"></span><span class="muted" data-status></span></div><div class="bd" data-editor><div class="muted">Chọn một loại thông báo để chỉnh sửa.</div></div></section></div>`;
    return {
      html: `<div class="wrap">${head('Chính sách thông báo', 'Mẫu tiếng Anh và chính sách gửi được lưu trong cơ sở dữ liệu')}${content}</div>`,
      mount: root => mount(root),
    };
  },
};

function mount(root) {
  let selected = '';
  let timer = 0;
  let template = null;
  const status = () => root.querySelector('[data-status]');
  const editor = () => root.querySelector('[data-editor]');
  const preview = root.querySelector('[data-preview]');

  const refreshPreview = async () => {
    if (!template) return;
    const out = root.querySelector('[data-preview]');
    const title = root.querySelector('[name=title_template]')?.value || '';
    const body = root.querySelector('[name=body_template]')?.value || '';
    try {
      const result = await send('POST', `${endpoint}/${encodeURIComponent(selected)}/preview`, { title_template: title, body_template: body, default_severity: root.querySelector('[name=default_severity]')?.value });
      if (out) out.textContent = `${result.data.title}\n\n${result.data.body}`;
    } catch (error) {
      if (out) out.textContent = `Không thể xem trước: ${error.message}`;
    }
  };

  const loadRevisions = async () => {
    const target = root.querySelector('[data-revisions]');
    if (!target) return;
    try {
      const revisions = (await admin(`${endpoint}/${encodeURIComponent(selected)}/revisions`)).data || [];
      target.innerHTML = revisions.length === 0
        ? '<p class="muted">Chưa có bản sửa trước.</p>'
        : `<ul class="notification-revisions">${revisions.map(revision => `<li><span>Phiên bản ${esc(revision.version)} · ${esc(revision.created_at || '')} · ${revision.updated_by ? `người dùng #${esc(revision.updated_by)}` : 'không xác định'}</span><button class="btn sm" data-action="restore" data-revision="${esc(revision.id)}">Khôi phục bản này</button></li>`).join('')}</ul>`;
    } catch (error) {
      target.innerHTML = `<div class="err" role="alert">${esc(error.message)}</div>`;
    }
  };

  const loadTemplate = async key => {
    selected = key;
    if (status()) status().textContent = 'Đang tải…';
    editor().innerHTML = '<div class="loading">Đang tải mẫu thông báo…</div>';
    try {
      template = (await admin(`${endpoint}/${encodeURIComponent(key)}`)).data;
      editor().innerHTML = editorHtml(template);
      if (status()) status().textContent = `Phiên bản ${template.version}`;
      loadRevisions();
      refreshPreview();
    } catch (error) {
      template = null;
      editor().innerHTML = `<div class="err" role="alert">${esc(error.message)} <button class="btn sm" data-action="reload-template">Thử lại</button></div>`;
      if (status()) status().textContent = 'Không tải được';
    }
  };

  const save = async () => {
    const form = editor().querySelector('form');
    const data = {
      title_template: form.elements.title_template.value,
      body_template: form.elements.body_template.value,
      enabled: form.elements.enabled.checked,
      default_severity: form.elements.default_severity.value,
      tier: form.elements.tier.value,
      cooldown_minutes: Number(form.elements.cooldown_minutes.value),
      escalation_minutes: Number(form.elements.escalation_minutes.value),
      include_in_periodic_report: form.elements.include_in_periodic_report.checked,
      include_in_daily_report: form.elements.include_in_daily_report.checked,
      send_resolved: form.elements.send_resolved.checked,
    };
    const button = form.querySelector('[data-action=save]');
    button.disabled = true;
    if (status()) status().textContent = 'Đang lưu…';
    try {
      template = (await send('PUT', `${endpoint}/${encodeURIComponent(selected)}`, data)).data;
      if (status()) status().textContent = 'Đã lưu';
      await loadTemplate(selected);
      await refreshList();
    } catch (error) {
      if (status()) status().innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`;
    } finally { button.disabled = false; }
  };

  const refreshList = async () => {
    try {
      const rows = (await admin(endpoint)).data || [];
      const tbody = root.querySelector('.notifications-list tbody');
      if (tbody) tbody.innerHTML = rows.map(row => `<tr><td>${esc(row.name)}</td><td>${esc(row.usage)}</td><td>${esc(row.channel)}</td><td>${row.enabled ? 'Bật' : 'Tắt'}</td><td>${esc(row.tier || row.default_severity)}</td><td>${esc(row.last_sent_at || 'Chưa gửi')}</td><td>${esc(row.updated_at || 'Mặc định')}</td><td><button class="btn sm" data-action="edit" data-key="${esc(row.key)}">Sửa</button></td></tr>`).join('');
    } catch { /* the current editor remains usable if the list refresh fails */ }
  };

  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const action = button.dataset.action;
    if (action === 'save-daily-schedule') {
      const message = root.querySelector('[data-daily-schedule-status]');
      button.disabled = true;
      if (message) message.textContent = 'Đang lưu…';
      try {
        const settings = {
          'report.daily_time': root.querySelector('[data-daily-time]').value,
          'report.daily_timezone': root.querySelector('[data-daily-timezone]').value.trim(),
        };
        await send('PUT', '/ui-admin/settings', { settings });
        if (message) message.textContent = 'Đã lưu; lịch mới sẽ áp dụng mà không cần khởi động lại.';
      } catch (error) {
        const errors = error.body?.data?.errors;
        const details = errors ? Object.values(errors).join(' · ') : error.message;
        if (message) message.innerHTML = `<span class="err" role="alert">${esc(details)}</span>`;
      } finally { button.disabled = false; }
      return;
    }
    if (action === 'edit') { await loadTemplate(button.dataset.key); return; }
    if (action === 'reload') { location.reload(); return; }
    if (action === 'reload-template') { await loadTemplate(selected); return; }
    if (action === 'save') { event.preventDefault(); await save(); return; }
    if (action === 'reset') {
      button.disabled = true;
      try { template = (await send('POST', `${endpoint}/${encodeURIComponent(selected)}/reset`)).data; await loadTemplate(selected); await refreshList(); }
      catch (error) { if (status()) status().innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`; }
      finally { button.disabled = false; }
      return;
    }
    if (action === 'test') {
      button.disabled = true;
      if (status()) status().textContent = 'Đang gửi thử…';
      const form = editor().querySelector('form');
      try {
        const result = await send('POST', `${endpoint}/${encodeURIComponent(selected)}/test`, { title_template: form.elements.title_template.value, body_template: form.elements.body_template.value, default_severity: form.elements.default_severity.value });
        if (status()) status().textContent = result.data.sent ? 'Đã gửi thử tới Telegram.' : 'Telegram chưa gửi được mẫu thử.';
        await refreshList();
      } catch (error) { if (status()) status().innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`; }
      finally { button.disabled = false; }
      return;
    }
    if (action === 'preview') { await refreshPreview(); return; }
    if (action === 'restore') {
      button.disabled = true;
      try { template = (await send('POST', `${endpoint}/${encodeURIComponent(selected)}/revisions/${encodeURIComponent(button.dataset.revision)}/restore`)).data; await loadTemplate(selected); await refreshList(); }
      catch (error) { if (status()) status().innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`; }
      finally { button.disabled = false; }
      return;
    }
    if (action === 'insert') {
      const textarea = editor().querySelector(`[name="${button.dataset.target}"]`);
      if (!textarea) return;
      const start = textarea.selectionStart, end = textarea.selectionEnd;
      const value = `{${button.dataset.variable}}`;
      textarea.setRangeText(value, start, end, 'end');
      textarea.focus();
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  root.addEventListener('input', event => {
    if (!event.target.matches('[name=title_template], [name=body_template]')) return;
    clearTimeout(timer);
    timer = setTimeout(refreshPreview, 250);
  });
  root.addEventListener('change', async event => {
    const input = event.target.closest('[data-quiet-mode]');
    if (!input) return;
    const message = root.querySelector('[data-quiet-status]');
    input.disabled = true;
    if (message) message.textContent = 'Đang lưu…';
    try {
      await send('PUT', '/ui-admin/settings', { settings: { 'notifications.quiet_mode': input.checked } });
      if (message) message.textContent = input.checked ? 'Đã bật chế độ yên lặng.' : 'Đã tắt chế độ yên lặng.';
    } catch (error) {
      input.checked = !input.checked;
      if (message) message.innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`;
    } finally { input.disabled = false; }
  });
  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-preview-report]');
    if (!button) return;
    const out = root.querySelector(`[data-report-preview="${button.dataset.previewReport}"]`);
    button.disabled = true;
    if (out) out.textContent = 'Đang tạo bản xem trước…';
    try {
      const result = await admin(button.dataset.previewReport);
      if (out) out.textContent = result.data.text;
    } catch (error) {
      if (out) out.textContent = `Không thể tạo bản xem trước: ${error.message}`;
    } finally { button.disabled = false; }
  });
}

async function reportPreview(url, title) {
  try {
    const result = await admin(url);
    return `<details class="notification-report-preview"><summary>${esc(title)}</summary><button class="btn sm" data-preview-report="${esc(url)}">Làm mới</button><pre data-report-preview="${esc(url)}">${esc(result.data.text)}</pre></details>`;
  } catch (error) {
    return `<details class="notification-report-preview"><summary>${esc(title)}</summary><pre class="err" role="alert" data-report-preview="${esc(url)}">${esc(error.message)}</pre><button class="btn sm" data-preview-report="${esc(url)}">Thử lại</button></details>`;
  }
}

function editorHtml(row) {
  const variables = (row.variables || []).map(variable => `<button type="button" class="btn sm notification-variable" data-action="insert" data-target="body_template" data-variable="${esc(variable.name)}">{${esc(variable.name)}} <small>${esc(String(variable.sample))}</small></button>`).join('');
  return `<form class="notification-form"><div class="notification-meta"><b>${esc(row.name)}</b><span>${esc(row.usage)}</span><span>Kênh: ${esc(row.channel)}</span></div>
    <label class="notification-label">Tiêu đề<textarea name="title_template" rows="2" maxlength="10000">${esc(row.title_template)}</textarea></label>
    <label class="notification-label">Nội dung<textarea name="body_template" rows="7" maxlength="30000">${esc(row.body_template)}</textarea></label>
    <fieldset class="notification-fields"><legend>Chính sách gửi</legend><label>Mức nội dung<select name="default_severity">${['critical', 'warning', 'notice', 'info'].map(value => `<option value="${value}"${row.default_severity === value ? ' selected' : ''}>${value}</option>`).join('')}</select></label><label>Tầng gửi<select name="tier">${['critical', 'warning', 'notice', 'info'].map(value => `<option value="${value}"${row.tier === value ? ' selected' : ''}>${value}</option>`).join('')}</select></label><label>Cooldown (phút)<input name="cooldown_minutes" type="number" min="0" max="10080" value="${Number(row.cooldown_minutes || 0)}"></label><label>Nâng WARNING (phút)<input name="escalation_minutes" type="number" min="0" max="10080" value="${Number(row.escalation_minutes || 0)}"></label><label class="switch"><input name="enabled" type="checkbox" ${row.enabled ? 'checked' : ''}><span class="track"></span><span class="txt">${row.enabled ? 'Đang bật' : 'Đang tắt'}</span></label><label class="switch"><input name="include_in_periodic_report" type="checkbox" ${row.include_in_periodic_report ? 'checked' : ''}><span class="track"></span><span class="txt">Trong báo cáo định kỳ</span></label><label class="switch"><input name="include_in_daily_report" type="checkbox" ${row.include_in_daily_report ? 'checked' : ''}><span class="track"></span><span class="txt">Trong báo cáo hằng ngày</span></label><label class="switch"><input name="send_resolved" type="checkbox" ${row.send_resolved ? 'checked' : ''}><span class="track"></span><span class="txt">Gửi khi đã khôi phục</span></label></fieldset>
    <div class="notification-variables"><b>Biến được phép</b><div>${variables || '<span class="muted">Mẫu này không có biến.</span>'}</div></div>
    <div class="notification-preview"><b>Xem trước trực tiếp</b><pre data-preview>Đang tạo bản xem trước…</pre></div>
    <div class="notification-actions"><button type="button" class="btn pri" data-action="save">${ic('save')}Lưu mẫu</button><button type="button" class="btn" data-action="test">${ic('send')}Gửi thử</button><button type="button" class="btn" data-action="reset">${ic('refresh')}Khôi phục mặc định</button></div>
    <div class="notification-history"><b>Lịch sử phiên bản</b><div data-revisions><span class="muted">Đang tải lịch sử…</span></div></div></form>`;
}
