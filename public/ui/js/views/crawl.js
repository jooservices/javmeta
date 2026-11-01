import { esc, fmt, ago } from '../util.js';
import { api } from '../api.js';
import { head, a, href, stat, errorBox, ic } from '../components.js';

/* the state of one source from its status row */
export function sourceState(s) {
  if (!s.enabled) return { key: 'off', label: 'Tắt', dot: 'off', chip: '' };
  const age = s.last_success_at ? (Date.now() - new Date(s.last_success_at).getTime()) / 1000 : Infinity;
  const failing = s.last_error_at && (!s.last_success_at || new Date(s.last_error_at) > new Date(s.last_success_at));
  if (age > 3600 && failing) return { key: 'bad', label: 'Hỏng', dot: 'bad', chip: 'bad' };
  if (failing || age > 1800) return { key: 'warn', label: 'Lỗi một phần', dot: 'warn', chip: 'warn' };
  return { key: 'ok', label: 'Ổn', dot: '', chip: 'ok' };
}

export default {
  async render() {
    let summary, sources;
    try { [summary, sources] = await Promise.all([api('status/summary'), api('status/sources')]); } catch (e) { return { html: `<div class="wrap">${errorBox(e)}</div>` }; }
    const s = summary.data, rows = sources.data;
    const states = rows.map(r => sourceState(r));
    const count = k => states.filter(x => x.key === k).length;
    const html = `<div class="wrap">${head('Crawl theo nguồn', 'cập nhật mỗi 30 giây', `<span class="chip">${ago(s.checked_at)}</span>`)}
      <div class="panel"><div class="grid g4" style="gap:0">
        ${stat(fmt(s.counts.movies), 'phim', '+' + fmt(s.ingest.movies_1h) + ' / giờ')}
        ${stat(fmt(s.counts.performers), 'diễn viên', '+' + fmt(s.ingest.performers_1h) + ' / giờ')}
        ${stat(`${count('ok')} / ${rows.length}`, 'nguồn ổn', count('bad') ? count('bad') + ' hỏng' : '')}
        ${stat(fmt(s.queue.by_status.pending || 0), 'việc chờ', fmt(s.queue.by_status.failed || 0) + ' lỗi')}</div></div>
      <div class="panel tbl-wrap"><table><thead><tr><th>Nguồn</th><th>Trạng thái</th><th>Làn</th><th>Khoảng nghỉ</th><th>Chờ</th><th>Đang chạy</th><th>Xong</th><th>Lỗi</th><th>Thành công cuối</th><th>Lỗi cuối</th></tr></thead><tbody>
      ${rows.map((r, i) => { const st = states[i]; return `<tr><td><b>${esc(r.name)}</b><div class="dim mono">${esc(r.slug)}</div></td>
        <td><span class="chip ${st.chip}"><span class="dot ${st.dot}"></span> ${st.label}</span></td>
        <td>${r.lane === 'browser' ? 'Trình duyệt' : r.lane === 'direct' ? 'Trực tiếp' : '—'}</td>
        <td class="mono">${fmt(r.gap_seconds_current)}s <span class="dim">(${fmt(r.gap_seconds_min)}–${fmt(r.gap_seconds_max)})</span></td>
        <td class="mono">${fmt(r.queue.pending)}</td><td class="mono">${fmt(r.queue.claimed)}</td><td class="mono">${fmt(r.queue.done)}</td><td class="mono">${fmt(r.queue.failed)}</td>
        <td class="muted">${r.last_success_at ? ago(r.last_success_at) : '—'}</td>
        <td class="muted" title="${esc(r.last_error || '')}">${r.last_error ? esc(r.last_error.slice(0, 70)) + (r.last_error.length > 70 ? '…' : '') + '<div class="dim">' + ago(r.last_error_at) + '</div>' : '—'}</td></tr>`; }).join('')}
      </tbody></table></div>
      <p class="dim">Bật hay tắt một nguồn nằm trong cơ sở dữ liệu (<code>crawler:source-toggle</code>); màn hình này chỉ đọc. Khoảng nghỉ tự tăng khi site chặn hoặc giới hạn tốc độ.</p></div>`;
    return { html, mount: root => { /* refreshed by the app timer */ } };
  },
};
