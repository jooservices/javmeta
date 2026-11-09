import { admin } from '../api.js';
import { esc } from '../util.js';
import { head, errorBox, emptyBox } from '../components.js';
import { t } from '../i18n.js';

/* language names are shown in their own language on purpose */
const languageLabel = s => ({ en: 'English', vi: 'Tiếng Việt', ja: '日本語' }[s.language] || s.language) + (s.source === 'whisper' ? ' · ' + t('player.sub.original') : ' · ' + t('player.sub.translated'));
const stamp = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
export function seek(video, seconds) {
  const value = Number(seconds);
  if (!Number.isFinite(value) || value < 0) return;
  video.currentTime = Math.min(value, Number.isFinite(video.duration) ? video.duration : value);
}
export function selectTrack(video, id) {
  for (const track of video.textTracks) track.mode = track.id === id ? 'showing' : 'disabled';
}
export default {
  async render({ params, query = {} }) {
    let data;
    try { ({ data } = await admin(`/ui-me/player/${encodeURIComponent(params.code)}${query.file ? '?file=' + encodeURIComponent(query.file) : ''}`)); }
    catch (e) {
      if (e.status === 404) {
        return { html: `<div class="wrap">${head(t('player.title'), params.code)}${emptyBox(t('player.no_file'), t('player.no_file_hint'), `<a class="btn" href="#/settings">${t('nav.settings')}</a>`)}</div>` };
      }
      return { html: `<div class="wrap">${head(t('player.title'), '')}${errorBox(e)}</div>` };
    }
    if (!data.enabled) return { html: `<div class="wrap">${head(t('player.title'), params.code)}${emptyBox(t('player.disabled'), t('player.disabled_hint'), `<a class="btn" href="#/settings">${t('nav.settings')}</a>`)}</div>` };
    if (!data.files?.length) return { html: `<div class="wrap">${head(t('player.title'), params.code)}${emptyBox(t('player.no_file'), t('player.no_file_hint'), `<a class="btn" href="#/settings">${t('nav.settings')}</a>`)}</div>` };
    if (!data.url) return { html: `<div class="wrap">${head(t('player.title'), params.code)}${emptyBox(t('player.cannot_open'), data.error || t('player.cannot_open_hint'), `<a class="btn" href="#/settings">${t('nav.settings')}</a>`)}</div>` };
    const subs = data.subtitles || [];
    const scenes = data.scenes || [];
    return { html: `<div class="wrap">${head(t('player.title'), params.code)}<label>${t('player.file')} <select data-file>${data.files.map(f => `<option value="${f.id}" ${f.id === data.library_file_id ? 'selected' : ''}>${esc(f.name)}</option>`).join('')}</select></label><video controls preload="metadata" style="display:block;width:100%;max-height:70vh;background:#000" src="${esc(data.url)}">${subs.map((s, i) => `<track id="subtitle-${s.id}" kind="subtitles" srclang="${esc(s.language)}" label="${esc(languageLabel(s))}" src="${esc(s.url)}" ${i === 0 ? 'default' : ''}>`).join('')}</video><p role="status" data-player-status>${t('player.loading')}</p>${data.error ? `<p class="err">${esc(data.error)}</p>` : ''}<label>${t('player.subtitles')} <select data-subtitles><option value="">${t('common.off')}</option>${subs.map((s, i) => `<option value="subtitle-${s.id}" ${i === 0 ? 'selected' : ''}>${esc(languageLabel(s))}</option>`).join('')}</select></label>${subs.length ? '' : `<p class="muted">${t('player.no_subtitles')}</p>`}<section class="panel"><div class="hd"><h2>${t('player.scenes')}</h2></div><div class="bd" style="display:flex;flex-wrap:wrap;gap:6px">${scenes.map(s => `<button class="btn sm" data-seek="${Number(s.start_sec)}" title="${esc(s.description || '')}">${stamp(Number(s.start_sec))} ${esc(s.description || t('player.scene'))}</button>`).join('') || t('player.no_scenes')}</div></section></div>`, mount(root) {
      const video = root.querySelector('video');
      const status = root.querySelector('[data-player-status]');
      video.onloadedmetadata = () => { status.textContent = ''; seek(video, query.t || 0); };
      video.onerror = () => { status.textContent = t('player.error'); };
      video.onwaiting = () => { status.textContent = t('player.loading'); };
      video.onplaying = () => { status.textContent = ''; };
      root.querySelector('[data-subtitles]').onchange = e => selectTrack(video, e.target.value);
      root.querySelector('[data-file]').onchange = e => { location.hash = `#/player/${encodeURIComponent(params.code)}?file=${e.target.value}`; };
      const click = e => { const b = e.target.closest('[data-seek]'); if (b) seek(video, b.dataset.seek); };
      root.addEventListener('click', click);
      return () => { root.removeEventListener('click', click); video.pause(); video.removeAttribute('src'); video.load(); };
    } };
  },
};
