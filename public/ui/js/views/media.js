import { admin, send } from '../api.js';
import { esc } from '../util.js';
import { head, errorBox } from '../components.js';
import { t } from '../i18n.js';

/* [kind, i18n key] */
export const kinds = [['thumbnails', 'media.kind.thumbnails'], ['scenes', 'media.kind.scenes'], ['transcribe', 'media.kind.transcribe'], ['translate', 'media.kind.translate'], ['describe', 'media.kind.describe']];
export const mediaButtons = id => kinds.map(([kind, label]) => `<button class="btn sm ghost" data-media-kind="${kind}" data-file="${Number(id)}">${t(label)}</button>`).join('');
export function mountMedia(root) {
  const handle = async e => {
    const b = e.target.closest('[data-media-kind], [data-media-retry]');
    if (!b || b.disabled) return;
    b.disabled = true;
    const message = root.querySelector('[data-media-message]');
    if (message) message.textContent = t('media.sending');
    try {
      if (b.dataset.mediaRetry) await send('POST', `/ui-me/media/${b.dataset.mediaRetry}/retry`);
      else await send('POST', '/ui-me/media', { kind: b.dataset.mediaKind, library_file_id: Number(b.dataset.file) });
      if (message) message.textContent = t('media.queued');
    } catch (error) { if (message) message.textContent = error.message; }
    finally { b.disabled = false; }
  };
  root.addEventListener('click', handle);
  return () => root.removeEventListener('click', handle);
}
const statuses = { pending: 'media.status.pending', running: 'media.status.running', done: 'media.status.done', failed: 'media.status.failed' };
export default {
  async render({ query = {} } = {}) {
    try {
      const page = Math.max(1, Number(query.page) || 1);
      const { data: jobs, capture_enabled: captureEnabled } = await admin(`/ui-me/media?page=${page}`);
      return { html: `<div class="wrap">${head(t('media.title'), t('media.sub'), `<a class="btn" href="#/library">${t('media.choose_file')}</a>`)}<form data-capture style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0"><label style="flex:1;min-width:0">${t('media.capture.url')}<input name="url" type="url" required style="width:100%;box-sizing:border-box" placeholder="https://…" ${captureEnabled ? '' : 'disabled'}></label><button class="btn" ${captureEnabled ? '' : 'disabled'}>${t('media.capture')}</button></form>${`<p class="muted">${t(captureEnabled ? 'media.capture.note' : 'media.capture.disabled')}</p>`}<p role="status" data-media-message></p><div class="panel">${jobs.data.map(j => `<div class="bd" style="overflow-wrap:anywhere"><strong>#${j.id} · ${esc(j.kind === 'capture' ? t('media.capture') : (kinds.find(k => k[0] === j.kind) ? t(kinds.find(k => k[0] === j.kind)[1]) : j.kind))}</strong><p>${esc(statuses[j.status] ? t(statuses[j.status]) : j.status)}${j.library_file_id ? ' · ' + t('media.file_n', { id: Number(j.library_file_id) }) : ''} · ${t('media.attempt', { n: j.attempts })}</p>${j.output_url ? `<a class="btn" href="${esc(j.output_url)}">${t('media.open_output')}</a>` : ''}${j.status === 'running' ? `<progress aria-label="${t('media.status.running')}"></progress>` : ''}${j.status === 'done' ? `<progress value="1" max="1" aria-label="${t('media.status.done')}"></progress>` : ''}${j.error ? `<p class="err">${esc(j.error)}</p>` : ''}${j.status === 'failed' ? `<button class="btn" data-media-retry="${j.id}">${t('common.retry')}</button>` : ''}</div>`).join('') || `<div class="bd">${t('media.empty')}</div>`}</div><nav>${page > 1 ? `<a class="btn" href="#/media?page=${page - 1}">${t('common.previous')}</a>` : ''}${jobs.next_page_url ? `<a class="btn" href="#/media?page=${page + 1}">${t('common.next')}</a>` : ''}<button class="btn" data-refresh>${t('common.refresh')}</button></nav></div>`, mount(root) {
        root.querySelector('[data-refresh]').onclick = () => location.reload();
        const form = root.querySelector('[data-capture]');
        form.onsubmit = async e => {
          e.preventDefault();
          if (!captureEnabled) return;
          const button = form.querySelector('button');
          button.disabled = true;
          const status = root.querySelector('[data-media-message]');
          status.textContent = t('media.capture.queuing');
          try { await send('POST', '/ui-me/media/capture', { url: form.elements.url.value }); status.textContent = t('media.capture.queued'); }
          catch (error) { status.textContent = error.message; }
          finally { button.disabled = false; }
        };
        return mountMedia(root);
      } };
    } catch (e) { return { html: `<div class="wrap">${head(t('media.title'), '')}${errorBox(e)}</div>` }; }
  },
};
