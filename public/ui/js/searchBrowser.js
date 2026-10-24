import { esc, queryString } from './util.js';
import { emptyBox, movieCard, movieRow, movieHead, performerCard, performerRow, performerHead, infiniteList, ic } from './components.js';
import { api, pagination } from './api.js';
import { movieParams, performerParams, chipsHtml, removeFilter, toggleValue, multiKeys } from './filterState.js';
import { filterControls } from './filterControls.js';
import { mountPicker, performerNames, fillCatalogs } from './pickers.js';
import { mountFacets, relaxationSuggestions, suggestionsHtml } from './facets.js';
import { savedSearchesHtml, mountSavedSearches } from './savedSearches.js';

export const movieSorts = [['release_date', 'Mới phát hành'], ['update_date', 'Mới cập nhật'], ['rating', 'Điểm cao'], ['relevance', 'Liên quan (cần từ khoá)']];
const performerSorts = [['name', 'Tên A–Z'], ['updated_at', 'Mới cập nhật'], ['linked_title_count', 'Nhiều phim nhất']];
const prefs = key => { try { return JSON.parse(localStorage.getItem(key) || '{}'); } catch { return {}; } };
const writePrefs = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* Private mode may disable storage. */ } };
let focusPicker = false;
const drawerState = new Map();
export function searchBrowser(kind, inputQuery, { fixed = {}, apiExtra = {}, closed = false } = {}) {
  const movies = kind === 'movies', convert = movies ? movieParams : performerParams;
  const canonical = convert(inputQuery);
  const query = { ...canonical };
  for (const key of ['released_from', 'released_to', 'debut_from', 'debut_to']) if (query[key]) query[key] = query[key].slice(0, 4);
  const prefKey = movies ? 'xc-list' : 'xc-plist', pr = prefs(prefKey);
  const mode = pr.mode || 'grid', size = pr.size || 'M', perPage = [24, 48, 96].includes(Number(pr.perPage)) ? Number(pr.perPage) : 24;
  const sort = query.sort || (movies ? (query.q ? 'relevance' : 'release_date') : 'name');
  const baseParams = q => ({ ...convert(q), ...apiExtra, ...Object.fromEntries(Object.entries(fixed).filter(([k]) => k !== 'year')) });
  const open = drawerState.get(kind) ?? (!closed && innerWidth > 900);
  const html = `<div class="br search-browser" data-key="${kind}"><div class="brbar"><button class="btn ${open ? 'on' : ''}" data-act="toggleside" aria-expanded="${open}">${ic('filter')} Bộ lọc</button><b class="mono" data-count></b><span class="muted">${movies ? 'phim' : 'diễn viên'}</span><span class="sp"></span><label>Xếp theo <select data-sort>${(movies ? movieSorts : performerSorts).map(([v, l]) => `<option value="${v}" ${sort === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label><label>Mỗi trang <select data-page-size>${[24, 48, 96].map(n => `<option ${perPage === n ? 'selected' : ''}>${n}</option>`).join('')}</select></label><div class="seg">${movies && mode === 'grid' ? ['S', 'M', 'L'].map(s => `<button class="${size === s ? 'on' : ''}" data-act="size" data-v="${s}" aria-label="Cỡ ảnh ${s}">${s}</button>`).join('') : ''}${['grid', 'table'].map(m => `<button class="${mode === m ? 'on' : ''}" data-act="mode" data-v="${m}" aria-label="${m === 'grid' ? 'Lưới' : 'Bảng'}">${ic(m === 'grid' ? 'grid' : 'list')}</button>`).join('')}</div></div><div class="parsed" aria-label="Bộ lọc đang dùng"><div data-active>${chipsHtml(query)}</div><button class="btn sm" data-act="clearfilters">Xoá tất cả</button></div>${savedSearchesHtml()}<div class="brmain ${open ? '' : 'nof'}"><div class="brscrim" data-act="toggleside"></div><aside class="brside panel"><button class="btn sm brclose" data-act="toggleside">Đóng bộ lọc</button><form data-filter-form autocomplete="off">${filterControls(kind, query, fixed)}<button type="submit" class="btn sm">Áp dụng</button></form></aside><div class="search-results"><div data-list></div><div data-relax role="status"></div></div></div></div>`;
  return { html, mount(root) {
    const lifetime = new AbortController(), signal = lifetime.signal;
    const list = root.querySelector('[data-list]');
    let timer, names = {}, suggestions = [];
    const path = location.hash.slice(2).split('?')[0] || kind;
    const go = q => { clearTimeout(timer); drawerState.set(kind, !root.querySelector('.brmain').classList.contains('nof')); location.hash = '#/' + path + queryString(q); };
    const grid = mode === 'grid' ? `<div class="${movies ? 'mgrid ' + size : 'agrid'}" data-h></div><div data-e></div><div class="brfoot" data-f></div>` : `<div class="panel tbl-wrap"><table><thead>${movies ? movieHead : performerHead}</thead><tbody data-h></tbody></table></div><div data-e></div><div class="brfoot" data-f></div>`;
    const ctl = infiniteList(list, {
      fetchPage: async state => {
        const body = await api(kind, { ...baseParams(query), sort, per_page: perPage, ...(movies ? (state.cursor ? { cursor: state.cursor } : {}) : { page: state.page }) }, { signal });
        const pg = pagination(body), items = body.data || [];
        return { items, total: pg.total, cursor: pg.next_cursor, page: state.page + 1, hasMore: items.length > 0 && (movies ? !!pg.has_more : state.page < (pg.last_page || 1)) };
      },
      renderItems: (items, off) => items.map((item, i) => movies ? (mode === 'grid' ? movieCard(item, off + i) : movieRow(item)) : (mode === 'grid' ? performerCard(item, off + i) : performerRow(item))).join(''),
      wrapGrid: grid, countEl: root.querySelector('[data-count]'),
      emptyHtml: emptyBox(movies ? 'Không có phim khớp' : 'Không có diễn viên khớp', 'Nới điều kiện hoặc bỏ bớt bộ lọc.', '<button class="btn" data-act="clearfilters">Xoá bộ lọc</button>'),
    });
    const form = root.querySelector('[data-filter-form]');
    const read = () => {
      const next = { ...query };
      for (const [k, v] of new FormData(form)) next[k] = v;
      if (next.score_min === '0' && !query.score_min) delete next.score_min;
      next.sort = root.querySelector('[data-sort]').value;
      return next;
    };
    const applyValue = (key, value) => {
      const next = read();
      if (key === 'year') { const same = next.released_from === value && next.released_to === value; next.released_from = same ? '' : value; next.released_to = same ? '' : value; }
      else next[key] = multiKeys.includes(key) ? toggleValue(next[key], value) : (next[key] === value ? '' : value);
      go(next);
    };
    form.addEventListener('input', e => {
      if (!e.target.name) return;
      if (e.target.name === 'score_min') root.querySelector('[data-score]').textContent = e.target.value;
      clearTimeout(timer); timer = setTimeout(() => { if (!signal.aborted) go(read()); }, 600);
    }, { signal });
    form.addEventListener('change', e => { if (e.target.matches('select')) go(read()); if (e.target.dataset.choice) applyValue(e.target.dataset.choice, e.target.value); }, { signal });
    form.addEventListener('submit', e => { e.preventDefault(); go(read()); }, { signal });
    root.querySelector('[data-sort]').addEventListener('change', () => go(read()), { signal });
    root.querySelector('[data-page-size]').addEventListener('change', e => { writePrefs(prefKey, { ...pr, perPage: Number(e.target.value) }); location.reload(); }, { signal });
    root.addEventListener('click', e => {
      const button = e.target.closest('button, [data-act]'); if (!button) return;
      if (button.dataset.facet) applyValue(button.dataset.facet, button.dataset.value);
      if (button.dataset.drop) go(removeFilter(read(), button.dataset.drop, button.dataset.value));
      if (button.dataset.relax !== undefined) go(suggestions[Number(button.dataset.relax)].query);
      const act = button.dataset.act;
      if (act === 'clearfilters') { e.preventDefault(); go({}); }
      if (act === 'toggleside') {
        const main = root.querySelector('.brmain'); main.classList.toggle('nof');
        const toggle = root.querySelector('.brbar [data-act=toggleside]'); toggle.classList.toggle('on'); toggle.setAttribute('aria-expanded', String(!main.classList.contains('nof')));
      }
      if (act === 'mode' || act === 'size') { writePrefs(prefKey, { ...pr, [act]: button.dataset.v }); location.reload(); }
    }, { signal });
    const stopPicker = mountPicker(root, query.actress_id, id => { focusPicker = true; applyValue('actress_id', id); }, signal);
    if (movies) {
      if (focusPicker) { root.querySelector('[data-performer-input]')?.focus(); focusPicker = false; }
      performerNames(query.actress_id, signal).then(result => {
        if (signal.aborted) return; names = result;
        root.querySelector('[data-active]').innerHTML = chipsHtml(query, names);
        const chips = root.querySelector('[data-performer-chips]'); if (chips) chips.innerHTML = chipsHtml({ actress_id: query.actress_id }, names);
      }).catch(() => {});
    }
    fillCatalogs(root, signal);
    mountSavedSearches(root, kind, baseParams(query), signal);
    const stopFacets = mountFacets(root, kind, query, baseParams, async data => {
      if (data.total !== 0) return;
      const host = root.querySelector('[data-relax]'); host.textContent = 'Đang tìm cách nới bộ lọc…';
      suggestions = await relaxationSuggestions(kind, query, baseParams, signal, names);
      if (!signal.aborted) host.innerHTML = suggestionsHtml(suggestions, kind) || 'Thử xoá tất cả hoặc đổi từ khoá.';
    }, signal);
    return () => { clearTimeout(timer); ctl.destroy(); lifetime.abort(); stopPicker(); stopFacets(); };
  } };
}
