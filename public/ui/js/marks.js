import { esc } from './util.js';
import { admin, send } from './api.js';

/* the owner's favourites, watch-later, seen and follows, kept on the server */
let cache = null;

export async function myMarks(force = false) {
  if (!cache || force) {
    try { cache = new Set(((await admin('/ui-me/marks')).data || []).map(m => [m.kind, m.subject, m.key].join('\u0001'))); } catch { cache = new Set(); }
  }
  return cache;
}

export const markBtn = (set, kind, subject, key, on, off, extra = '') => {
  const is = set.has([kind, subject, key].join('\u0001'));
  return `<button class="btn sm ${is ? 'pri' : ''} ${extra}" data-mark data-kind="${esc(kind)}" data-subject="${esc(subject)}" data-key="${esc(key)}" data-on-label="${esc(on)}" data-off-label="${esc(off)}" aria-pressed="${is}">${esc(is ? on : off)}</button>`;
};

export async function toggleMark(btn) {
  const was = btn.getAttribute('aria-pressed') === 'true';
  btn.disabled = true;
  try {
    await send('POST', '/ui-me/marks', { kind: btn.dataset.kind, subject: btn.dataset.subject, key: btn.dataset.key, on: !was });
    const now = !was;
    btn.setAttribute('aria-pressed', String(now));
    btn.classList.toggle('pri', now);
    btn.textContent = now ? btn.dataset.onLabel : btn.dataset.offLabel;
    await myMarks(true);
  } catch (e) { btn.title = e.message; } finally { btn.disabled = false; }
}
