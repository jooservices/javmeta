import { esc, fmt, ago } from '../util.js';
import { api } from '../api.js';
import { head, a, href, stat, errorBox, emptyBox, ic } from '../components.js';
import { t } from '../i18n.js';

/* the state of one source from its status row; `label` is an i18n key */
export function sourceState(s) {
  if (!s.enabled) return { key: 'off', label: 'crawl.state.off', dot: 'off', chip: '' };
  const age = s.last_success_at ? (Date.now() - new Date(s.last_success_at).getTime()) / 1000 : Infinity;
  const failing = s.has_recent_failure ?? (s.last_error_at && (!s.last_success_at || new Date(s.last_error_at) > new Date(s.last_success_at)));
  if (age > 3600 && failing) return { key: 'bad', label: 'crawl.state.bad', dot: 'bad', chip: 'bad' };
  if (failing || age > 1800) return { key: 'warn', label: 'crawl.state.warn', dot: 'warn', chip: 'warn' };
  return { key: 'ok', label: 'crawl.state.ok', dot: '', chip: 'ok' };
}

export default {
  async render() {
    const guestMode = document.body.dataset.guestMode === 'true';
    let summary, sources;
    try { [summary, sources] = await Promise.all([api('status/summary'), api('status/sources')]); } catch (e) { return { html: `<div class="wrap">${errorBox(e)}</div>` }; }
    const s = summary.data, rows = sources.data;
    const states = rows.map(r => sourceState(r));
    const count = k => states.filter(x => x.key === k).length;
    const html = `<div class="wrap">${head(t('crawl.title'), t('crawl.sub'), `<span class="chip">${ago(s.checked_at)}</span>`)}
      <div class="panel"><div class="grid g4" style="gap:0">
        ${stat(fmt(s.counts.movies), t('crawl.stat.movies'), t('crawl.per_hour', { n: fmt(s.ingest.movies_1h) }))}
        ${stat(fmt(s.counts.performers), t('crawl.stat.performers'), t('crawl.per_hour', { n: fmt(s.ingest.performers_1h) }))}
        ${stat(`${count('ok')} / ${rows.length}`, t('crawl.stat.sources_ok'), count('bad') ? t('crawl.broken', { n: count('bad') }) : '')}
        ${stat(fmt(s.queue.by_status.pending || 0), t('crawl.stat.queued'), t('crawl.failed', { n: fmt(s.queue.by_status.failed || 0) }))}</div></div>
      ${rows.length === 0 ? emptyBox(t('crawl.empty'), t('crawl.empty_hint'), a('#/settings', t('common.open_settings'), 'btn')) : `<div class="panel tbl-wrap"><table class="crawl-table"><thead><tr><th>${t('crawl.head.source')}</th><th>${t('crawl.head.status')}</th><th>${t('crawl.head.lane')}</th><th>${t('crawl.head.gap')}</th><th>${t('crawl.head.pending')}</th><th>${t('crawl.head.running')}</th><th>${t('crawl.head.done')}</th><th>${t('crawl.head.failed')}</th><th>${t('crawl.head.last_success')}</th><th>${t('crawl.head.last_error')}</th></tr></thead><tbody>
      ${rows.map((r, i) => { const st = states[i]; return `<tr><td><b>${esc(r.name)}</b><div class="dim mono">${esc(r.slug)}</div></td>
        <td><span class="chip ${st.chip}"><span class="dot ${st.dot}"></span> ${t(st.label)}</span></td>
        <td>${r.lane === 'browser' ? t('crawl.lane.browser') : r.lane === 'direct' ? t('crawl.lane.direct') : '—'}</td>
        <td class="mono">${fmt(r.gap_seconds_current)}s <span class="dim">(${fmt(r.gap_seconds_min)}–${fmt(r.gap_seconds_max)})</span></td>
        <td class="mono">${fmt(r.queue.pending)}</td><td class="mono">${fmt(r.queue.claimed)}</td><td class="mono">${fmt(r.queue.done)}</td><td class="mono">${fmt(r.queue.failed)}</td>
        <td class="muted">${r.last_success_at ? ago(r.last_success_at) : '—'}</td>
        <td class="muted"${!guestMode && r.last_error ? ` title="${esc(r.last_error)}"` : ''}>${guestMode ? (r.has_recent_failure ? t('crawl.recent_failure') : '—') : (r.last_error ? esc(r.last_error.slice(0, 70)) + (r.last_error.length > 70 ? '…' : '') + '<div class="dim">' + ago(r.last_error_at) + '</div>' : '—')}</td></tr>`; }).join('')}
      </tbody></table></div>
      <p class="dim">${t('crawl.note')}</p>`}</div>`;
    return { html, mount: root => { /* refreshed by the app timer */ } };
  },
};
