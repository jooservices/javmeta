import { fmt, toast } from '../util.js';
import { t } from '../i18n.js';
import { admin, send } from '../api.js';
import { head, movieCard, emptyBox, errorBox } from '../components.js';

/* new movies (last 30 days) of the performers, makers and series the owner follows */
export default {
  async render() {
    let body;
    try { body = await admin('/ui-me/feed'); } catch (e) { return { html: `<div class="wrap">${head(t('feed.title'), '')}${errorBox(e)}</div>` }; }
    const items = body.data || [], m = body.meta || {};
    const newCount = Number(m.new_since_last_visit) > 0 ? Number(m.new_since_last_visit) : 0;
    const sub = m.follows
      ? (newCount
        ? t('feed.sub_new', { follows: fmt(m.follows), count: fmt(items.length), fresh: fmt(newCount) })
        : t('feed.sub', { follows: fmt(m.follows), count: fmt(items.length) }))
      : t('feed.sub_none');
    const markSeen = items.length ? `<button type="button" class="btn sm" data-feed-seen>${t('feed.mark_seen')}</button>` : '';
    const html = `<div class="wrap">${head(t('feed.title'), sub, markSeen)}${items.length ? `<div class="mgrid">${items.map((x, i) => movieCard(x, i)).join('')}</div>` : emptyBox(t(m.follows ? 'feed.empty.no_new' : 'feed.empty.none'), t('feed.empty.hint'))}</div>`;
    return {
      html,
      mount: root => {
        root.querySelector('[data-feed-seen]')?.addEventListener('click', async e => {
          const btn = e.currentTarget;
          btn.disabled = true;
          try { await send('POST', '/ui-me/feed/seen'); toast(t('feed.marked_seen')); btn.remove(); }
          catch (err) { toast(err.message); btn.disabled = false; }
        });
      },
    };
  },
};
