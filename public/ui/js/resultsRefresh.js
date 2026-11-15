import { esc, queryString } from './util.js';
import { t } from './i18n.js';

/* Reusable "change the filters, refresh only the results" helper for every list view.
   - softNavigate: put the new query in the address (pushState/replaceState, no hashchange, no route render) and tell the view.
   - bindQuerySoftUpdate: a list view registers one handler; app.js calls it on back/forward instead of rendering the route again.
   - refreshResultsRegion: abortable, debounced fetch + render of one region; dims it, keeps the last good result on error with a retry. */

let soft = null;
const nav = { path: null, hash: null };

export const splitHash = (hash = location.hash) => {
  const [path, qs = ''] = String(hash).replace(/^#\/?/, '').split('?');
  return { path, query: Object.fromEntries(new URLSearchParams(qs).entries()) };
};

/* app.js calls this after every full route render so soft updates know which route is on screen */
export const markRendered = (hash = location.hash) => { nav.hash = hash; nav.path = splitHash(hash).path; };

export const bindQuerySoftUpdate = (onQuery, keys = null) => {
  const handler = { onQuery, keys: keys ? new Set(keys) : null };
  soft = handler;
  return () => { if (soft === handler) soft = null; };
};

export const hasSoftUpdate = () => soft !== null;

/* true when the change was handled in place (or was already handled); false means the caller must render the route */
export function trySoftUpdate(hash = location.hash) {
  if (hash === nav.hash) return true;
  const { path, query } = splitHash(hash);
  if (!soft || path !== nav.path) return false;
  if (soft.keys) {
    const previous = splitHash(nav.hash).query;
    const changed = new Set([...Object.keys(previous), ...Object.keys(query)].filter(key => previous[key] !== query[key]));
    if ([...changed].some(key => !soft.keys.has(key))) return false;
  }
  nav.hash = hash;
  soft.onQuery(query, { path, history: true });
  return true;
}

export function softNavigate(path, query = {}, { replace = false } = {}) {
  const next = '#/' + path + queryString(query);
  if (next === location.hash) return false;
  if (!soft || path !== nav.path || typeof history?.pushState !== 'function') { location.hash = next; return false; }
  history[replace ? 'replaceState' : 'pushState'](history.state ?? null, '', (location.pathname || '') + (location.search || '') + next);
  nav.hash = next;
  soft.onQuery(query, { path, history: false });
  return true;
}

/* region: the element that holds the results. load(signal) fetches, render(data) draws; both are owned by the view.
   While a refresh is pending the region is dimmed (aria-busy) and keeps its height so nothing below or around it moves. */
export function refreshResultsRegion(region, { load, render, delay = 250, signal } = {}) {
  let timer, request, generation = 0;
  const slot = () => region.querySelector('[data-results-error]');
  const busy = on => { region.classList.toggle('is-refreshing', on); region.setAttribute('aria-busy', String(on)); };
  const release = () => { region.style.minHeight = ''; };
  const showError = e => {
    const el = slot(); if (!el) return;
    el.removeAttribute('hidden');
    el.hidden = false;
    el.innerHTML = `<span class="err">${t('results.failed')} ${esc(e.message || e)}</span> <button type="button" class="btn sm" data-results-retry>${t('results.retry')}</button>`;
  };
  const clearError = () => { const el = slot(); if (el) { el.hidden = true; el.setAttribute('hidden', ''); el.innerHTML = ''; } };

  async function exec() {
    request?.abort();
    const mine = request = new AbortController(), id = generation;
    const stop = () => mine.abort();
    signal?.addEventListener('abort', stop, { once: true });
    region.style.minHeight = (region.offsetHeight || 0) + 'px';
    try {
      const data = await load(mine.signal);
      if (mine.signal.aborted || id !== generation) return;
      render(data);
      if (id === generation) clearError();
    } catch (e) {
      if (e.name === 'AbortError' || mine.signal.aborted || id !== generation) return;
      showError(e);
    } finally {
      signal?.removeEventListener('abort', stop);
      if (id === generation) { busy(false); release(); }
    }
  }

  function run({ immediate = false } = {}) {
    clearTimeout(timer);
    request?.abort();
    generation++;
    busy(true);
    timer = setTimeout(exec, immediate ? 0 : delay);
  }

  region.addEventListener('click', e => { if (e.target.closest('[data-results-retry]')) run({ immediate: true }); }, signal ? { signal } : undefined);
  return { run, destroy() { clearTimeout(timer); request?.abort(); generation++; busy(false); release(); } };
}
