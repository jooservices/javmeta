import { esc } from '../util.js';
import { head, loadingBox } from '../components.js';
import { admin, send } from '../api.js';

export default {
  async render() {
    let settings, preview;
    try {
      [settings, preview] = await Promise.all([admin('/ui-admin/settings'), admin('/ui-admin/report/periodic')]);
    } catch (error) {
      return { html: `<div class="wrap">${head('Báo cáo', 'Cài đặt → Báo cáo')}<div class="empty"><div class="err" role="alert">${esc(error.message)}</div><button class="btn" data-act="reload">Thử lại</button></div></div>` };
    }
    const reportSettings = settings.data.settings.filter(row => row.group === 'report');
    const rows = reportSettings.map(row => `<div class="row-i" style="align-items:flex-start;flex-wrap:wrap"><div class="grow" style="min-width:220px"><label for="report-${esc(row.key)}"><b>${esc(row.label)}</b></label><div class="muted">${esc(row.description)}</div></div><div>${control(row)}</div></div>`).join('');
    return {
      html: `<div class="wrap">${head('Báo cáo', 'Cài đặt → Báo cáo')}<div class="grid g2">
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>Lịch và nội dung</h2><span class="sp"></span><span class="muted" data-report-status aria-live="polite"></span><button class="btn sm pri" data-report-save>Lưu cài đặt</button></div><div class="bd muted">Báo cáo gửi bằng tiếng Anh theo mẫu XCrawler; thay đổi được áp dụng từ cơ sở dữ liệu.</div>${rows}</section>
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>Xem trước trực tiếp</h2><span class="sp"></span><button class="btn sm" data-report-refresh>Làm mới</button><button class="btn sm pri" data-report-send>Gửi ngay qua Telegram</button></div><div class="bd"><pre data-report-preview style="white-space:pre-wrap;overflow-wrap:anywhere;margin:0">${esc(preview.data.text)}</pre></div></section>
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>Lần gửi gần nhất</h2></div><div class="bd" data-report-last>${lastReport(preview.data.last)}</div></section>
        <section class="panel" style="grid-column:1/-1"><div class="hd"><h2>Lịch sử gửi gần đây</h2></div><div class="bd" data-report-history>${history(preview.data.history)}</div></section>
      </div></div>`,
      mount: root => mount(root),
    };
  },
};

function control(row) {
  const id = `report-${row.key}`;
  if (row.type === 'bool') return `<label class="sw"><input id="${id}" data-key="${esc(row.key)}" type="checkbox" ${row.value ? 'checked' : ''}><span>${row.value ? 'Bật' : 'Tắt'}</span></label>`;
  if (row.type === 'int' && Array.isArray(row.options)) return `<select id="${id}" data-key="${esc(row.key)}">${row.options.map(value => `<option value="${value}" ${Number(value) === Number(row.value) ? 'selected' : ''}>${value === 1440 ? '24 giờ' : `${value} phút`}</option>`).join('')}</select>`;
  if (row.type === 'string_list' && Array.isArray(row.item_options)) return `<div role="group" aria-label="${esc(row.label)}">${row.item_options.map(value => `<label class="sw"><input type="checkbox" data-list="${esc(row.key)}" value="${esc(value)}" ${(row.value || []).includes(value) ? 'checked' : ''}><span>${esc(optionLabel(value))}</span></label>`).join('')}</div>`;
  if (row.type === 'string_list') return `<textarea id="${id}" data-list-text="${esc(row.key)}" rows="3">${esc((row.value || []).join('\n'))}</textarea>`;
  if (row.multiline) return `<textarea id="${id}" data-key="${esc(row.key)}" rows="6" maxlength="${row.max_length || 1000}" style="width:min(100%,520px);max-width:100%;box-sizing:border-box">${esc(row.value || '')}</textarea>`;
  if (row.format === 'time') return `<input id="${id}" data-key="${esc(row.key)}" type="time" value="${esc(row.value || '')}">`;
  if (row.type === 'int') return `<input id="${id}" data-key="${esc(row.key)}" type="number" step="1" min="${row.min ?? 0}" max="${row.max ?? 100000}" value="${esc(row.value ?? '')}">`;
  return `<input id="${id}" data-key="${esc(row.key)}" type="text" value="${esc(row.value ?? '')}" style="min-width:min(100%,320px)">`;
}

function optionLabel(value) {
  return ({ movies: 'Phim', performers: 'Diễn viên', crawl: 'Thu thập dữ liệu', sources: 'Nguồn', fleet: 'Cụm nút', workers: 'Worker', servers: 'Máy chủ', services: 'Dịch vụ', telegram: 'Telegram' })[value] || value;
}

function lastReport(row) {
  if (!row) return '<p class="muted">Chưa có báo cáo nào được gửi.</p>';
  return `<p><b>${row.result === 'sent' ? 'Đã gửi' : 'Gửi lỗi'}</b> · ${esc(row.channel)} · ${esc(row.created_at || '—')}</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(row.rendered_text || '')}</pre>`;
}

function history(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return '<p class="muted">Chưa có lịch sử gửi.</p>';
  return `<div style="overflow-x:auto"><table><thead><tr><th>Thời gian</th><th>Kết quả</th><th>Kênh</th><th>Nội dung</th></tr></thead><tbody>${rows.slice(0, 20).map(row => `<tr><td>${esc(row.created_at || '—')}</td><td>${row.result === 'sent' ? 'Đã gửi' : 'Lỗi'}</td><td>${esc(row.channel || '')}</td><td><details><summary>Xem báo cáo</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(row.rendered_text || '')}</pre></details></td></tr>`).join('')}</tbody></table></div>`;
}

function mount(root) {
  const status = root.querySelector('[data-report-status]');
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
          else values[field.dataset.key] = field.value;
        });
        root.querySelectorAll('[data-list]').forEach(field => {
          const key = field.dataset.list;
          values[key] = Array.from(root.querySelectorAll('[data-list]')).filter(item => item.dataset.list === key && item.checked).map(item => item.value);
        });
        root.querySelectorAll('[data-list-text]').forEach(field => { values[field.dataset.listText] = field.value.split(/[\r\n,]+/).map(value => value.trim()).filter(Boolean); });
        status.textContent = 'Đang lưu…';
        await send('PUT', '/ui-admin/settings', { settings: values });
        status.textContent = 'Đã lưu cài đặt.';
        await refresh();
      } else if (button.hasAttribute('data-report-send')) {
        status.textContent = 'Đang gửi…';
        await send('POST', '/ui-admin/report/periodic/send-now', {});
        status.textContent = 'Đã gửi báo cáo qua Telegram.';
        await refresh();
      } else {
        status.textContent = 'Đang làm mới…';
        await refresh();
        status.textContent = 'Đã cập nhật bản xem trước.';
      }
    } catch (error) {
      const errors = error.body?.data?.errors;
      status.innerHTML = `<span class="err" role="alert">${esc(errors ? Object.values(errors).join(' · ') : error.message)}</span>`;
    } finally {
      button.disabled = false;
    }
  });
}
