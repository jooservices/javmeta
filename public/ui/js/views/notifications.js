import { esc } from '../util.js';
import { head } from '../components.js';
import { admin, send } from '../api.js';

const endpoint = '/ui-admin/notifications';

export default {
  async render() {
    let rows = [];
    let error = '';
    try { rows = (await admin(endpoint)).data || []; }
    catch (e) { error = e.message; }
    const content = error
      ? `<div class="panel"><div class="bd"><div class="err" role="alert">${esc(error)}</div><button class="btn" data-action="reload">Thử lại</button></div></div>`
      : rows.length === 0
        ? '<div class="panel"><div class="bd empty">Chưa có mẫu thông báo.</div></div>'
        : `<div class="notifications-layout"><section class="panel notifications-list"><div class="hd"><h2>Danh sách thông báo</h2><span class="chip">${rows.length}</span></div><div class="notifications-scroll"><table><thead><tr><th>Tên</th><th>Mục đích</th><th>Kênh</th><th>Bật</th><th>Gửi gần nhất</th><th>Sửa gần nhất</th><th></th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name)}</td><td>${esc(row.usage)}</td><td>${esc(row.channel)}</td><td>${row.enabled ? 'Bật' : 'Tắt'}</td><td>${esc(row.last_sent_at || 'Chưa gửi')}</td><td>${esc(row.updated_at || 'Mặc định')}</td><td><button class="btn sm" data-action="edit" data-key="${esc(row.key)}">Sửa</button></td></tr>`).join('')}</tbody></table></div></section><section class="panel notifications-editor"><div class="hd"><h2>Chỉnh sửa mẫu</h2><span class="sp"></span><span class="muted" data-status></span></div><div class="bd" data-editor><div class="muted">Chọn một loại thông báo để chỉnh sửa.</div></div></section></div>`;
    return {
      html: `<div class="wrap">${head('Thông báo', 'Mẫu nội dung được lưu trong cơ sở dữ liệu')}${content}</div>`,
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
      if (tbody) tbody.innerHTML = rows.map(row => `<tr><td>${esc(row.name)}</td><td>${esc(row.usage)}</td><td>${esc(row.channel)}</td><td>${row.enabled ? 'Bật' : 'Tắt'}</td><td>${esc(row.last_sent_at || 'Chưa gửi')}</td><td>${esc(row.updated_at || 'Mặc định')}</td><td><button class="btn sm" data-action="edit" data-key="${esc(row.key)}">Sửa</button></td></tr>`).join('');
    } catch { /* the current editor remains usable if the list refresh fails */ }
  };

  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const action = button.dataset.action;
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
}

function editorHtml(row) {
  const variables = (row.variables || []).map(variable => `<button type="button" class="btn sm notification-variable" data-action="insert" data-target="body_template" data-variable="${esc(variable.name)}">{${esc(variable.name)}} <small>${esc(String(variable.sample))}</small></button>`).join('');
  return `<form class="notification-form"><div class="notification-meta"><b>${esc(row.name)}</b><span>${esc(row.usage)}</span><span>Kênh: ${esc(row.channel)}</span></div>
    <label class="notification-label">Tiêu đề<textarea name="title_template" rows="2" maxlength="10000">${esc(row.title_template)}</textarea></label>
    <label class="notification-label">Nội dung<textarea name="body_template" rows="7" maxlength="30000">${esc(row.body_template)}</textarea></label>
    <div class="notification-fields"><label>Mức mặc định<select name="default_severity">${['critical', 'warning', 'notice', 'info'].map(value => `<option value="${value}"${row.default_severity === value ? ' selected' : ''}>${value}</option>`).join('')}</select></label><label class="sw"><input name="enabled" type="checkbox" ${row.enabled ? 'checked' : ''}><span>${row.enabled ? 'Đang bật' : 'Đang tắt'}</span></label></div>
    <div class="notification-variables"><b>Biến được phép</b><div>${variables || '<span class="muted">Mẫu này không có biến.</span>'}</div></div>
    <div class="notification-preview"><b>Xem trước trực tiếp</b><pre data-preview>Đang tạo bản xem trước…</pre></div>
    <div class="notification-actions"><button type="button" class="btn pri" data-action="save">Lưu mẫu</button><button type="button" class="btn" data-action="test">Gửi thử</button><button type="button" class="btn" data-action="reset">Khôi phục mặc định</button></div>
    <div class="notification-history"><b>Lịch sử phiên bản</b><div data-revisions><span class="muted">Đang tải lịch sử…</span></div></div></form>`;
}
