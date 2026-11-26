// "previous / next": a list page registers what it shows; when a link of that kind inside it is clicked the list is remembered
const reg = new Map();
export const registerList = (id, items) => reg.set(id, items);
const KEY = { movies: 'xc.ctx', performers: 'xc.pctx' };
export function saveContext(kind, items, label = '') { try { sessionStorage.setItem(KEY[kind], JSON.stringify({ items, label })); } catch { /* storage full */ } }
let installed = false;
export function installListTracker() {
  if (installed) return; installed = true;
  document.addEventListener('click', e => {
    const a = e.target.closest?.('a[href^="/movies/"], a[href^="/performers/"]'); if (!a) return; const box = a.closest('[data-list-id]'); if (!box) return;
    const kind = a.getAttribute('href').includes('/performers/') ? 'performers' : 'movies'; if ((box.dataset.listKind || 'movies') !== kind) return;
    const items = reg.get(box.dataset.listId); if (items) saveContext(kind, items, box.dataset.listLabel || '');
  }, true);
}
export const readContext = (kind, item) => { try { const c = JSON.parse(sessionStorage.getItem(KEY[kind])); if (c && c.items.includes(item)) return c; } catch { /* none */ } return null; };
