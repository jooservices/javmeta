import { esc, fmt } from '../util.js';
import { admin, send } from '../api.js';
import { head, a, href, errorBox, emptyBox } from '../components.js';
import { t } from '../i18n.js';

const size = b => b >= 1e9 ? (b / 1e9).toFixed(2) + ' GB' : b >= 1e6 ? (b / 1e6).toFixed(0) + ' MB' : fmt(b) + ' B';
const eta = s => s == null ? '—' : s >= 3600 ? Math.floor(s / 3600) + 'h ' + Math.floor(s % 3600 / 60) + 'm' : s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's';
const STATE = { downloading: ['downloads.state.downloading', 'ok'], paused: ['downloads.state.paused', 'warn'], completed: ['downloads.state.completed', 'ok'], error: ['downloads.state.error', 'bad'], waiting: ['downloads.state.waiting', ''] };

const row = tor => {
  const [labelKey, cls] = STATE[tor.state] || [null, ''];
  const label = labelKey ? t(labelKey) : tor.state;
  return `<div class="row-i" style="flex-wrap:wrap" data-hash="${esc(tor.hash)}"><div class="grow" style="min-width:240px"><div class="ell">${tor.movie_code ? a(href.movie(tor.movie_code), esc(tor.movie_code)) + ' · ' : ''}<span title="${esc(tor.name)}">${esc(tor.name)}</span></div>
    <div class="bar" style="margin-top:4px"><i style="width:${Math.max(1, tor.progress)}%"></i></div></div>
    <span class="chip ${cls}">${esc(label)}</span><span class="mono" style="width:60px;text-align:right">${tor.progress}%</span><span class="mono muted" style="width:110px;text-align:right">${tor.completed ? size(tor.size) : size(tor.speed) + '/s'}</span><span class="mono muted" style="width:70px;text-align:right">${tor.completed ? '' : eta(tor.eta)}</span>
    <span>${tor.completed ? `<button class="btn sm" data-links>${t('downloads.links')}</button>` : (tor.state === 'paused' ? `<button class="btn sm" data-resume>${t('downloads.resume')}</button>` : `<button class="btn sm" data-pause>${t('downloads.pause')}</button>`)} <button class="btn sm" data-remove>${t('common.delete')}</button></span><div class="muted" data-out style="width:100%"></div></div>`;
};

/* downloads started from the movie pages: progress, speed, ETA, pause, resume, remove, links to finished files */
export default {
  async render() {
    let st;
    try { st = (await admin('/ui-me/downloads/status')).data; } catch (e) { return { html: `<div class="wrap">${head(t('downloads.title'), '')}${errorBox(e)}</div>` }; }
    if (!st.enabled) return { html: `<div class="wrap">${head(t('downloads.title'), 'qBittorrent')}${emptyBox(t('downloads.disabled'), t('downloads.disabled_hint'), a('#/settings', t('common.open_settings'), 'btn'))}</div>` };
    let items;
    try { items = (await admin('/ui-me/downloads')).data.items; } catch (e) { return { html: `<div class="wrap">${head(t('downloads.title'), 'qBittorrent')}${errorBox(e)}</div>` }; }
    const html = `<div class="wrap">${head(t('downloads.title'), t('downloads.count', { count: items.length, n: fmt(items.length) }))}<div class="panel" id="dlist">${items.map(row).join('') || `<div class="row-i muted">${t('downloads.empty_hint')}</div>`}</div></div>`;
    return { html, mount };
  },
};

function mount(root) {
  const list = root.querySelector('#dlist');
  const refresh = async () => { try { list.innerHTML = (await admin('/ui-me/downloads')).data.items.map(row).join('') || `<div class="row-i muted">${t('downloads.empty')}</div>`; } catch { /* keep the last view */ } };
  const timer = setInterval(refresh, 5000);
  list.addEventListener('click', async e => {
    const r = e.target.closest('[data-hash]'); if (!r) return;
    const h = r.dataset.hash, out = r.querySelector('[data-out]');
    try {
      if (e.target.closest('[data-pause]')) { await send('POST', `/ui-me/downloads/${h}/pause`); refresh(); }
      if (e.target.closest('[data-resume]')) { await send('POST', `/ui-me/downloads/${h}/resume`); refresh(); }
      if (e.target.closest('[data-remove]') && confirm(t('downloads.remove_confirm'))) { const files = confirm(t('downloads.remove_files_confirm')); await send('DELETE', `/ui-me/downloads/${h}${files ? '?files=1' : ''}`); refresh(); }
      if (e.target.closest('[data-links]')) {
        const d = (await admin(`/ui-me/downloads/${h}/links`)).data;
        out.innerHTML = d.links.length ? d.links.map(l => `<a class="lnk" href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.name)}</a> <button class="btn sm" data-act="copy" data-v="${esc(l.url)}">${t('downloads.copy_link')}</button>`).join('<br>') + ` <span class="dim">${t('downloads.links_expire')}</span>` : t('downloads.no_files');
      }
    } catch (err) { out.innerHTML = `<span class="err">${esc(err.message)}</span>`; }
  });
  return () => clearInterval(timer);
}
