import { esc } from '../util.js';
import { head, loadingBox } from '../components.js';
import { admin, send } from '../api.js';
import { askConfirm } from '../confirm.js';
import { t } from '../i18n.js';

export default {
  async render() {
    let settings, preview;
    try {
      settings = await admin('/ui-admin/settings');
    } catch (error) {
      return { html: `<div class="wrap">${head(t('report.title'), t('report.breadcrumb'))}<div class="empty"><div class="err" role="alert">${esc(error.message)}</div><button class="btn" data-act="reload">${t('common.retry')}</button></div></div>` };
    }
    try {
      preview = await admin('/ui-admin/report/periodic');
    } catch (error) {
      preview = { data: { text: String(error.message || t('report.preview_error')), last: null, history: [] } };
    }
    const reportSettings = settings.data.settings.filter(row => row.group === 'report');
    const rows = reportSettings.map(row => `<div class="row-i" style="align-items:flex-start"><div class="grow"><label for="report-${esc(row.key)}"><b>${esc(row.label)}</b></label><div class="muted">${esc(row.description)}</div></div><div class="ctrl">${control(row)}</div></div>`).join('');
    return {
      html: `<div class="wrap">${head(t('report.title'), t('report.breadcrumb'))}<div class="grid g2">
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>${t('report.schedule')}</h2><span class="sp"></span><span class="muted" data-report-status aria-live="polite"></span><button class="btn sm pri" data-report-save>${t('report.save')}</button></div><div class="bd muted">${t('report.note')}</div>${rows}</section>
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>${t('report.preview')}</h2><span class="sp"></span><button class="btn sm" data-report-refresh>${t('common.refresh')}</button><button class="btn sm" data-report-send>${t('report.send_now')}</button></div><div class="bd"><pre data-report-preview style="white-space:pre-wrap;overflow-wrap:anywhere;margin:0">${esc(preview.data.text)}</pre></div></section>
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>${t('report.last')}</h2></div><div class="bd" data-report-last>${lastReport(preview.data.last)}</div></section>
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>${t('report.history')}</h2></div><div class="bd" data-report-history>${history(preview.data.history)}</div></section>
      </div></div>`,
      mount: root => mount(root),
    };
  },
};

function control(row) {
  const id = `report-${row.key}`;
  if (row.type === 'bool') return `<label class="switch"><input id="${id}" data-key="${esc(row.key)}" type="checkbox" ${row.value ? 'checked' : ''}><span class="track"></span><span class="txt">${t(row.value ? 'common.on' : 'common.off')}</span></label>`;
  if (row.type === 'int' && Array.isArray(row.options)) return `<select id="${id}" data-key="${esc(row.key)}">${row.options.map(value => `<option value="${value}" ${Number(value) === Number(row.value) ? 'selected' : ''}>${value === 1440 ? t('report.interval.day') : t('report.interval.minutes', { count: value, n: value })}</option>`).join('')}</select>`;
  if (row.type === 'string_list' && Array.isArray(row.item_options)) return `<div role="group" aria-label="${esc(row.label)}">${row.item_options.map(value => `<label class="switch"><input type="checkbox" data-list="${esc(row.key)}" value="${esc(value)}" ${(row.value || []).includes(value) ? 'checked' : ''}><span class="track"></span><span class="txt">${esc(optionLabel(value))}</span></label>`).join('')}</div>`;
  if (row.type === 'string_list') return `<textarea id="${id}" data-list-text="${esc(row.key)}" rows="3">${esc((row.value || []).join('\n'))}</textarea>`;
  if (row.multiline) return `<textarea id="${id}" data-key="${esc(row.key)}" rows="6" maxlength="${row.max_length || 1000}">${esc(row.value || '')}</textarea>`;
  if (row.format === 'time') return `<input id="${id}" data-key="${esc(row.key)}" type="time" value="${esc(row.value || '')}">`;
  if (row.type === 'int') return `<input id="${id}" data-key="${esc(row.key)}" type="number" step="1" min="${row.min ?? 0}" max="${row.max ?? 100000}" value="${esc(row.value ?? '')}">`;
  return `<input id="${id}" data-key="${esc(row.key)}" type="text" value="${esc(row.value ?? '')}">`;
}

const OPTIONS = ['movies', 'performers', 'crawl', 'sources', 'fleet', 'workers', 'servers', 'services', 'telegram'];
export function optionLabel(value) {
  return OPTIONS.includes(value) ? t('report.option.' + value) : value;
}

function lastReport(row) {
  if (!row) return `<p class="muted">${t('report.last_none')}</p>`;
  return `<p><b>${t(row.result === 'sent' ? 'report.sent' : 'report.send_failed')}</b> · ${esc(row.channel)} · ${esc(row.created_at || '—')}</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(row.rendered_text || '')}</pre>`;
}

function history(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return `<p class="muted">${t('report.history_none')}</p>`;
  return `<div style="overflow-x:auto"><table><thead><tr><th>${t('report.head.time')}</th><th>${t('report.head.result')}</th><th>${t('report.head.channel')}</th><th>${t('report.head.content')}</th></tr></thead><tbody>${rows.slice(0, 20).map(row => `<tr><td>${esc(row.created_at || '—')}</td><td>${t(row.result === 'sent' ? 'report.sent' : 'report.failed')}</td><td>${esc(row.channel || '')}</td><td><details><summary>${t('report.view')}</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(row.rendered_text || '')}</pre></details></td></tr>`).join('')}</tbody></table></div>`;
}

function mount(root) {
  const status = root.querySelector('[data-report-status]');
  root.addEventListener('change', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.dataset.key !== 'report.enabled') return;
    const label = input.parentElement?.querySelector('.txt');
    if (label) label.textContent = t(input.checked ? 'common.on' : 'common.off');
  });
  const refresh = async () => {
    const result = await admin('/ui-admin/report/periodic');
    root.querySelector('[data-report-preview]').textContent = result.data.text;
    root.querySelector('[data-report-last]').innerHTML = lastReport(result.data.last);
    root.querySelector('[data-report-history]').innerHTML = history(result.data.history);
  };
  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-report-save], [data-report-refresh], [data-report-send]');
    if (!button) return;
    button.disabled = true;
    try {
      if (button.hasAttribute('data-report-save')) {
        const values = {};
        root.querySelectorAll('[data-key]').forEach(field => {
          if (field.type === 'checkbox') values[field.dataset.key] = field.checked;
          else if (field.type === 'number') values[field.dataset.key] = Number(field.value);
          else if (field.value !== '') values[field.dataset.key] = field.value;
        });
        root.querySelectorAll('[data-list]').forEach(field => {
          const key = field.dataset.list;
          values[key] = Array.from(root.querySelectorAll('[data-list]')).filter(item => item.dataset.list === key && item.checked).map(item => item.value);
        });
        root.querySelectorAll('[data-list-text]').forEach(field => { values[field.dataset.listText] = field.value.split(/[\r\n,]+/).map(value => value.trim()).filter(Boolean); });
        status.textContent = t('common.saving');
        await send('PUT', '/ui-admin/settings', { settings: values });
        status.textContent = t('report.saved');
        await refresh();
      } else if (button.hasAttribute('data-report-send')) {
        if (!await askConfirm({ title: t('report.confirm.title'), body: t('report.confirm.body'), confirmLabel: t('report.confirm.ok') })) { button.disabled = false; return; }
        status.textContent = t('report.sending');
        await send('POST', '/ui-admin/report/periodic/send-now', {});
        status.textContent = t('report.sent_ok');
        await refresh();
      } else {
        status.textContent = t('report.refreshing');
        await refresh();
        status.textContent = t('report.refreshed');
      }
    } catch (error) {
      const errors = error.body?.data?.errors;
      status.innerHTML = `<span class="err" role="alert">${esc(errors ? Object.values(errors).join(' · ') : error.message)}</span>`;
    } finally {
      button.disabled = false;
    }
  });
}
