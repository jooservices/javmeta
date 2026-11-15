import { mediaButtons, mountMedia } from './media.js';
import { esc, fmt } from '../util.js';
import { t } from '../i18n.js';
import { admin } from '../api.js';
import { head, a, href, errorBox, emptyBox } from '../components.js';

const size = b => b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : b >= 1e6 ? (b / 1e6).toFixed(0) + ' MB' : fmt(b) + ' B';

/* what is on the disk: totals, use by folder, the movies that have a file, the files that matched no movie */
export default {
  async render({ query = {} } = {}) {
    let sum;
    try { sum = await admin('/ui-me/library'); } catch (e) { return { html: `<div class="wrap">${head(t('libraryPage.title'), '')}${errorBox(e)}</div>` }; }
    const s = sum.data;
    if (s.enabled === false) {
      return { html: `<div class="wrap">${head(t('libraryPage.title'), '')}${emptyBox(t('libraryPage.disabled'), t('libraryPage.disabled_hint'), a('#/settings', t('common.open_settings'), 'btn'))}</div>` };
    }
    let on, un, files;
    try { [on, un, files] = await Promise.all([admin('/ui-me/library/on-disk'), admin('/ui-me/library/unmatched'), admin('/ui-me/media/files?page=' + Math.max(1, Number(query.media_page) || 1))]); } catch (e) { return { html: `<div class="wrap">${head(t('libraryPage.title'), '')}${errorBox(e)}</div>` }; }
    if (!s.files && !s.missing) {
      return { html: `<div class="wrap">${head(t('libraryPage.title'), t('libraryPage.sub'))}${emptyBox(t(s.configured ? 'libraryPage.empty.not_scanned' : 'libraryPage.empty.not_configured'), t('libraryPage.empty.hint'), a('#/settings', t('common.open_settings'), 'btn'))}</div>` };
    }
    const max = Math.max(1, ...s.by_folder.map(f => f.bytes));
    const tile = (label, v, sub = '') => `<div class="panel"><div class="bd"><div class="muted">${esc(label)}</div><div style="font-size:22px;font-weight:600" class="mono">${v}</div><div class="dim">${esc(sub)}</div></div></div>`;
    const html = `<div class="wrap">${head(t('libraryPage.title'), t('libraryPage.last_scan', { date: (s.last_scan_at || '—').slice(0, 16) }), a('#/movies?on_disk=1', t('libraryPage.movies_on_disk'), 'btn sm ghost'))}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:10px">${tile(t('libraryPage.tile.files'), fmt(s.files), t('libraryPage.tile.files_missing', { count: s.missing, n: fmt(s.missing) }))}${tile(t('libraryPage.tile.matched'), fmt(s.matched), t('libraryPage.tile.matched_sub'))}${tile(t('libraryPage.tile.unmatched'), fmt(s.unmatched), t('libraryPage.tile.unmatched_sub'))}${tile(t('libraryPage.tile.size'), size(s.bytes), t('libraryPage.tile.size_sub'))}</div>
      <div class="panel"><div class="hd"><h2>${t('libraryPage.by_folder')}</h2></div>${s.by_folder.map(f => `<div class="row-i"><div class="grow ell mono" title="${esc(f.folder)}">${esc(f.folder)}</div><div class="bar" style="width:160px"><i style="width:${Math.max(2, f.bytes / max * 100)}%"></i></div><span class="mono muted" style="width:90px;text-align:right">${size(f.bytes)}</span><span class="mono muted" style="width:70px;text-align:right">${t('libraryPage.files', { count: f.files, n: fmt(f.files) })}</span></div>`).join('')}</div>
      <div class="grid g2"><div class="panel"><div class="hd"><h2>${t('libraryPage.on_disk')}</h2><span class="chip mono">${fmt(on.data.length)}</span></div>${on.data.map(x => `<div class="row-i"><div class="grow">${a(href.movie(x.code), esc(x.code))}</div><span class="mono muted">${x.files > 1 ? t('libraryPage.files', { count: x.files, n: fmt(x.files) }) + ' · ' : ''}${size(x.bytes)}</span></div>`).join('') || `<div class="row-i muted">${t('common.none_yet_cap')}</div>`}</div>
        <div class="panel"><div class="hd"><h2>${t('libraryPage.unmatched')}</h2><span class="chip mono">${fmt(un.meta.total)}</span></div>${un.data.map(f => `<div class="row-i"><div class="grow ell mono" title="${esc(f.path)}">${esc(f.path)}</div><span class="mono muted">${size(f.size)}</span></div>`).join('') || `<div class="row-i muted">${t('libraryPage.all_matched')}</div>`}</div></div></div>`;
    const processing = `<div class="panel"><div class="hd"><h2>${t('media.title')}</h2><a class="btn" href="#/media">${t('libraryPage.queue')}</a></div><p role="status" data-media-message></p>${files.data.data.map(f => `<div class="bd" style="overflow-wrap:anywhere"><p>${esc(f.code || f.path)}</p><div style="display:flex;flex-wrap:wrap;gap:6px">${mediaButtons(f.id)}</div></div>`).join('') || `<p>${t('libraryPage.no_files')}</p>`}<nav>${files.data.prev_page_url ? `<a class="btn" href="#/library?media_page=${files.data.current_page - 1}">${t('common.previous')}</a>` : ''}${files.data.next_page_url ? `<a class="btn" href="#/library?media_page=${files.data.current_page + 1}">${t('common.next')}</a>` : ''}</nav></div>`;
    return { html: html.replace(/<\/div>$/, () => processing + '</div>'), mount: mountMedia };
  },
};
