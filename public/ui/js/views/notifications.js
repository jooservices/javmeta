import { esc } from '../util.js';
import { head, ic } from '../components.js';
import { admin, send } from '../api.js';
import { askConfirm } from '../confirm.js';
import { t, html as tHtml } from '../i18n.js';

const endpoint = '/ui-admin/notifications';
const TIER_LABEL = { critical: 'notifications.level.critical', warning: 'notifications.level.warning', notice: 'notifications.level.notice', info: 'notifications.level.info' };
const tierLabel = value => (TIER_LABEL[value] ? t(TIER_LABEL[value]) : value);

const rowHtml = row => `<tr><td>${esc(row.name)}</td><td>${esc(row.usage)}</td><td>${esc(row.channel)}</td><td>${row.enabled ? t('common.on') : t('common.off')}</td><td>${esc(tierLabel(row.tier || row.default_severity))}</td><td>${esc(row.last_sent_at || t('notifications.never_sent'))}</td><td>${esc(row.updated_at || t('notifications.default'))}</td><td><button class="btn sm" data-action="edit" data-key="${esc(row.key)}">${t('common.edit')}</button></td></tr>`;

export default {
  async render() {
    let rows = [];
    let error = '';
    try { rows = (await admin(endpoint)).data || []; }
    catch (e) { error = e.message; }
    let quietHtml = `<div class="err" role="alert">${t('notifications.quiet.load_failed')}</div>`;
    let dailyScheduleHtml = `<div class="err" role="alert">${t('notifications.daily.load_failed')}</div>`;
    try {
      const settings = (await admin('/ui-admin/settings')).data.settings || [];
      const quiet = settings.find(row => row.key === 'notifications.quiet_mode');
      const dailyTime = settings.find(row => row.key === 'report.daily_time');
      const dailyTimezone = settings.find(row => row.key === 'report.daily_timezone');
      quietHtml = quiet
        ? `<label class="switch"><input type="checkbox" data-quiet-mode ${quiet.value ? 'checked' : ''}><span class="track"></span><span class="txt">${t('notifications.quiet.label')}</span></label><span class="muted" data-quiet-status aria-live="polite"></span>`
        : `<div class="err" role="alert">${t('notifications.quiet.missing')}</div>`;
      dailyScheduleHtml = dailyTime && dailyTimezone
        ? `<div class="bd"><label>${t('notifications.daily.time')}<input type="time" data-daily-time value="${esc(dailyTime.value || '08:00')}"></label><label>${t('notifications.daily.timezone')}<input type="text" data-daily-timezone maxlength="64" value="${esc(dailyTimezone.value || 'America/Mexico_City')}"></label><button class="btn pri" data-action="save-daily-schedule">${ic('save')}${t('notifications.daily.save')}</button><span class="muted" data-daily-schedule-status aria-live="polite"></span></div>`
        : `<div class="err" role="alert">${t('notifications.daily.missing')}</div>`;
    } catch (e) { quietHtml = `<div class="err" role="alert">${esc(e.message)}</div>`; }
    const previews = await Promise.all([
      reportPreview('/ui-admin/report/periodic', t('notifications.report.periodic')),
      reportPreview('/ui-admin/report/daily', t('notifications.report.daily')),
    ]);
    const content = error
      ? `<div class="panel"><div class="bd"><div class="err" role="alert">${esc(error)}</div><button class="btn" data-action="reload">${t('common.retry')}</button></div></div>`
      : rows.length === 0
        ? `<div class="panel"><div class="bd empty">${t('notifications.empty')}</div></div>`
        : `<section class="panel"><div class="hd"><h2>${t('notifications.quiet.title')}</h2></div><div class="bd">${quietHtml}<div class="muted" style="margin-top:8px">${t('notifications.quiet.note')}</div></div></section><section class="panel notification-daily-schedule"><div class="hd"><h2>${t('notifications.daily.title')}</h2></div>${dailyScheduleHtml}</section><section class="panel"><div class="hd"><h2>${t('notifications.next_reports')}</h2></div><div class="bd notification-report-previews">${previews.join('')}</div></section><div class="notifications-layout"><section class="panel notifications-list"><div class="hd"><h2>${t('notifications.list')}</h2><span class="chip">${rows.length}</span></div><div class="notifications-scroll"><table><thead><tr><th>${t('notifications.head.name')}</th><th>${t('notifications.head.usage')}</th><th>${t('notifications.head.channel')}</th><th>${t('notifications.head.enabled')}</th><th>${t('notifications.head.tier')}</th><th>${t('notifications.head.last_sent')}</th><th>${t('notifications.head.updated')}</th><th></th></tr></thead><tbody>${rows.map(rowHtml).join('')}</tbody></table></div></section><section class="panel notifications-editor"><div class="hd"><h2>${t('notifications.editor')}</h2><span class="sp"></span><span class="muted" data-status></span></div><div class="bd" data-editor><div class="muted">${t('notifications.pick')}</div></div></section></div>`;
    return {
      html: `<div class="wrap">${head(t('notifications.title'), t('notifications.sub'))}${content}</div>`,
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
      if (out) out.textContent = tHtml('notifications.preview_failed', { message: error.message });
    }
  };

  const loadRevisions = async () => {
    const target = root.querySelector('[data-revisions]');
    if (!target) return;
    try {
      const revisions = (await admin(`${endpoint}/${encodeURIComponent(selected)}/revisions`)).data || [];
      target.innerHTML = revisions.length === 0
        ? `<p class="muted">${t('notifications.revisions.none')}</p>`
        : `<ul class="notification-revisions">${revisions.map(revision => `<li><span>${t('notifications.revisions.item', { version: revision.version, date: revision.created_at || '', by: revision.updated_by ? t('notifications.revisions.user', { id: revision.updated_by }) : t('notifications.revisions.unknown') })}</span><button class="btn sm" data-action="restore" data-revision="${esc(revision.id)}">${t('notifications.revisions.restore')}</button></li>`).join('')}</ul>`;
    } catch (error) {
      target.innerHTML = `<div class="err" role="alert">${esc(error.message)}</div>`;
    }
  };

  const loadTemplate = async key => {
    selected = key;
    if (status()) status().textContent = t('common.loading');
    editor().innerHTML = `<div class="loading">${t('notifications.loading_template')}</div>`;
    try {
      template = (await admin(`${endpoint}/${encodeURIComponent(key)}`)).data;
      editor().innerHTML = editorHtml(template);
      if (status()) status().textContent = t('notifications.version', { version: template.version });
      loadRevisions();
      refreshPreview();
    } catch (error) {
      template = null;
      editor().innerHTML = `<div class="err" role="alert">${esc(error.message)} <button class="btn sm" data-action="reload-template">${t('common.retry')}</button></div>`;
      if (status()) status().textContent = t('notifications.load_failed');
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
    if (status()) status().textContent = t('common.saving');
    try {
      template = (await send('PUT', `${endpoint}/${encodeURIComponent(selected)}`, data)).data;
      if (status()) status().textContent = t('common.saved');
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
      if (tbody) tbody.innerHTML = rows.map(rowHtml).join('');
    } catch { /* the current editor remains usable if the list refresh fails */ }
  };

  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const action = button.dataset.action;
    if (action === 'save-daily-schedule') {
      const message = root.querySelector('[data-daily-schedule-status]');
      button.disabled = true;
      if (message) message.textContent = t('common.saving');
      try {
        const settings = {
          'report.daily_time': root.querySelector('[data-daily-time]').value,
          'report.daily_timezone': root.querySelector('[data-daily-timezone]').value.trim(),
        };
        await send('PUT', '/ui-admin/settings', { settings });
        if (message) message.textContent = t('notifications.daily.saved');
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
      if (!await askConfirm({ title: t('notifications.reset.title'), body: t('notifications.reset.body'), confirmLabel: t('notifications.reset.ok'), danger: true })) return;
      button.disabled = true;
      try { template = (await send('POST', `${endpoint}/${encodeURIComponent(selected)}/reset`)).data; await loadTemplate(selected); await refreshList(); }
      catch (error) { if (status()) status().innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`; }
      finally { button.disabled = false; }
      return;
    }
    if (action === 'test') {
      if (!await askConfirm({ title: t('notifications.test.title'), body: t('notifications.test.body'), confirmLabel: t('notifications.test.ok') })) return;
      button.disabled = true;
      if (status()) status().textContent = t('notifications.test.sending');
      const form = editor().querySelector('form');
      try {
        const result = await send('POST', `${endpoint}/${encodeURIComponent(selected)}/test`, { title_template: form.elements.title_template.value, body_template: form.elements.body_template.value, default_severity: form.elements.default_severity.value });
        if (status()) status().textContent = t(result.data.sent ? 'notifications.test.sent' : 'notifications.test.not_sent');
        await refreshList();
      } catch (error) { if (status()) status().innerHTML = `<span class="err" role="alert">${esc(error.message)}</span>`; }
      finally { button.disabled = false; }
      return;
    }
    if (action === 'preview') { await refreshPreview(); return; }
    if (action === 'restore') {
      if (!await askConfirm({ title: t('notifications.restore.title'), body: t('notifications.restore.body'), confirmLabel: t('notifications.reset.ok'), danger: true })) return;
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

  root.addEventListener('change', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.name !== 'enabled') return;
    const label = input.parentElement?.querySelector('.txt');
    if (label) label.textContent = t(input.checked ? 'notifications.enabled_on' : 'notifications.enabled_off');
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
    if (message) message.textContent = t('common.saving');
    try {
      await send('PUT', '/ui-admin/settings', { settings: { 'notifications.quiet_mode': input.checked } });
      if (message) message.textContent = t(input.checked ? 'notifications.quiet.on' : 'notifications.quiet.off');
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
    if (out) out.textContent = t('notifications.preview_generating');
    try {
      const result = await admin(button.dataset.previewReport);
      if (out) out.textContent = result.data.text;
    } catch (error) {
      if (out) out.textContent = tHtml('notifications.preview_generate_failed', { message: error.message });
    } finally { button.disabled = false; }
  });
}

async function reportPreview(url, title) {
  try {
    const result = await admin(url);
    return `<details class="notification-report-preview"><summary>${esc(title)}</summary><button class="btn sm" data-preview-report="${esc(url)}">${t('common.refresh')}</button><pre data-report-preview="${esc(url)}">${esc(result.data.text)}</pre></details>`;
  } catch (error) {
    return `<details class="notification-report-preview"><summary>${esc(title)}</summary><pre class="err" role="alert" data-report-preview="${esc(url)}">${esc(error.message)}</pre><button class="btn sm" data-preview-report="${esc(url)}">${t('common.retry')}</button></details>`;
  }
}

function editorHtml(row) {
  const variables = (row.variables || []).map(variable => `<button type="button" class="btn sm notification-variable" data-action="insert" data-target="body_template" data-variable="${esc(variable.name)}">{${esc(variable.name)}} <small>${esc(String(variable.sample))}</small></button>`).join('');
  return `<form class="notification-form"><div class="notification-meta"><b>${esc(row.name)}</b><span>${esc(row.usage)}</span><span>${t('notifications.channel', { channel: row.channel })}</span></div>
    <label class="notification-label">${t('notifications.field.title')}<textarea name="title_template" rows="2" maxlength="10000">${esc(row.title_template)}</textarea></label>
    <label class="notification-label">${t('notifications.field.body')}<textarea name="body_template" rows="7" maxlength="30000">${esc(row.body_template)}</textarea></label>
    <fieldset class="notification-fields"><legend>${t('notifications.policy')}</legend><label>${t('notifications.severity')}<select name="default_severity">${['critical', 'warning', 'notice', 'info'].map(value => `<option value="${value}"${row.default_severity === value ? ' selected' : ''}>${esc(tierLabel(value))}</option>`).join('')}</select></label><label>${t('notifications.tier')}<select name="tier">${['critical', 'warning', 'notice', 'info'].map(value => `<option value="${value}"${row.tier === value ? ' selected' : ''}>${esc(tierLabel(value))}</option>`).join('')}</select></label><label>${t('notifications.cooldown')}<input name="cooldown_minutes" type="number" min="0" max="10080" value="${Number(row.cooldown_minutes || 0)}"></label><label>${t('notifications.escalation')}<input name="escalation_minutes" type="number" min="0" max="10080" value="${Number(row.escalation_minutes || 0)}"></label><label class="switch"><input name="enabled" type="checkbox" ${row.enabled ? 'checked' : ''}><span class="track"></span><span class="txt">${t(row.enabled ? 'notifications.enabled_on' : 'notifications.enabled_off')}</span></label><label class="switch"><input name="include_in_periodic_report" type="checkbox" ${row.include_in_periodic_report ? 'checked' : ''}><span class="track"></span><span class="txt">${t('notifications.in_periodic')}</span></label><label class="switch"><input name="include_in_daily_report" type="checkbox" ${row.include_in_daily_report ? 'checked' : ''}><span class="track"></span><span class="txt">${t('notifications.in_daily')}</span></label><label class="switch"><input name="send_resolved" type="checkbox" ${row.send_resolved ? 'checked' : ''}><span class="track"></span><span class="txt">${t('notifications.send_resolved')}</span></label></fieldset>
    <div class="notification-variables"><b>${t('notifications.variables')}</b><div>${variables || `<span class="muted">${t('notifications.variables_none')}</span>`}</div></div>
    <div class="notification-preview"><b>${t('report.preview')}</b><pre data-preview>${t('notifications.preview_generating')}</pre></div>
    <div class="notification-actions"><button type="button" class="btn pri" data-action="save">${ic('save')}${t('notifications.save')}</button><button type="button" class="btn" data-action="test">${ic('send')}${t('notifications.test.ok')}</button><button type="button" class="btn" data-action="reset">${ic('refresh')}${t('notifications.reset.title')}</button></div>
    <div class="notification-history"><b>${t('notifications.revisions.title')}</b><div data-revisions><span class="muted">${t('notifications.revisions.loading')}</span></div></div></form>`;
}
