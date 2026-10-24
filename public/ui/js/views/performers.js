import { esc, queryString, debounce } from '../util.js';
import { head, emptyBox, performerCard, performerRow, performerHead, infiniteList, performerPage, ic } from '../components.js';

const SORTS = [['name', 'Tên A–Z'], ['linked_title_count', 'Nhiều phim'], ['updated_at', 'Mới cập nhật']];
const field = (name, label, value, ph = '', type = 'text') => `<label class="f">${label}<input type="${type}" name="${name}" value="${esc(value || '')}" placeholder="${esc(ph)}"></label>`;
const BLOOD = ['A', 'B', 'O', 'AB'];
const prefs = () => { try { return JSON.parse(localStorage.getItem('xc-plist') || '{}'); } catch { return {}; } };

export default {
  async render({ query }) {
    const mode = prefs().mode || 'grid';
    const open = innerWidth > 900;
    const sort = query.sort || 'name';
    const side = `<form id="pf" autocomplete="off">
      <details open><summary>Từ khoá</summary><div class="fb">${field('q', 'Tên', query.q, 'ít nhất 2 ký tự')}</div></details>
      <details open><summary>Hồ sơ</summary><div class="fb">
        <label class="f">Nhóm máu<select name="blood_type"><option value="">Tất cả</option>${BLOOD.map(b => `<option ${query.blood_type === b ? 'selected' : ''}>${b}</option>`).join('')}</select></label>
        ${field('location', 'Nơi sống', query.location, 'ví dụ Tokyo')}
        ${field('cup', 'Cup', query.cup, 'ví dụ D')}
        <div class="two">${field('age_min', 'Tuổi từ', query.age_min, '', 'number')}${field('age_max', 'đến', query.age_max, '', 'number')}</div>
        <div class="two">${field('height_min', 'Cao ≥ (cm)', query.height_min, '', 'number')}${field('height_max', 'Cao ≤', query.height_max, '', 'number')}</div></div></details>
      <div class="fb"><button class="btn sm" type="reset" data-act="clearfilters">Xoá bộ lọc</button></div></form>`;
    const keys = ['q', 'blood_type', 'location', 'cup', 'age_min', 'age_max', 'height_min', 'height_max'];
    const chips = keys.filter(k => query[k]).map(k => `<button class="chip on" data-act="dropfilter" data-k="${k}">${esc(k)}: <b>${esc(query[k])}</b><span class="x">×</span></button>`).join('');
    const html = `<div class="wrap">${head('Diễn viên', 'hồ sơ gộp từ mọi nguồn')}<div class="br"><div class="brbar"><button class="btn ${open ? 'on' : ''}" data-act="toggleside">${ic('filter')} Bộ lọc</button><b class="mono" data-count></b> <span class="muted">diễn viên</span>
      <span class="sp"></span><label class="muted">Xếp theo <select id="sortsel">${SORTS.map(s => `<option value="${s[0]}" ${sort === s[0] ? 'selected' : ''}>${s[1]}</option>`).join('')}</select></label>
      <div class="seg"><button class="${mode === 'grid' ? 'on' : ''}" data-act="mode" data-v="grid" title="Lưới">${ic('grid')}</button><button class="${mode === 'table' ? 'on' : ''}" data-act="mode" data-v="table" title="Bảng">${ic('list')}</button></div></div>
      <div class="parsed">${chips ? '<span class="dim" style="font-size:11px">Đang lọc</span>' + chips : ''}</div>
      <div class="brmain ${open ? '' : 'nof'}"><div class="brscrim" data-act="toggleside"></div><aside class="brside panel"><button class="btn sm brclose" data-act="toggleside">Đóng bộ lọc</button>${side}</aside><div data-list></div></div></div></div>`;
    return { html, mount: root => mount(root, query, sort, mode, keys) };
  },
};

function mount(root, query, sort, mode, keys) {
  const base = { sort };
  keys.forEach(k => { if (query[k]) base[k] = query[k]; });
  const list = root.querySelector('[data-list]');
  const grid = mode === 'grid'
    ? '<div class="agrid" data-h></div><div data-e></div><div class="brfoot" data-f></div>'
    : `<div class="panel tbl-wrap"><table><thead>${performerHead}</thead><tbody data-h></tbody></table></div><div data-e></div><div class="brfoot" data-f></div>`;
  const ctl = infiniteList(list, {
    fetchPage: performerPage(base),
    renderItems: (items, off) => items.map((p, i) => mode === 'grid' ? performerCard(p, off + i) : performerRow(p)).join(''),
    wrapGrid: grid,
    emptyHtml: emptyBox('Không có diễn viên khớp', 'Nới điều kiện hoặc bỏ bớt bộ lọc.', '<button class="btn" data-act="clearfilters">Xoá bộ lọc</button>'),
    countEl: root.querySelector('[data-count]'),
  });
  const go = v => { location.hash = '#/performers' + queryString(v); };
  const read = () => {
    const f = Object.fromEntries(new FormData(root.querySelector('#pf')).entries());
    const out = { ...query, ...f };
    out.sort = root.querySelector('#sortsel').value;
    return out;
  };
  root.querySelector('#pf').addEventListener('input', debounce(() => go(read()), 600));
  root.querySelector('#pf').addEventListener('submit', e => { e.preventDefault(); go(read()); });
  root.querySelector('#sortsel').addEventListener('change', () => go(read()));
  root.addEventListener('click', e => {
    const t = e.target.closest('[data-act]'); if (!t) return;
    const act = t.dataset.act;
    if (act === 'toggleside') { root.querySelector('.brmain').classList.toggle('nof'); }
    if (act === 'mode') { try { localStorage.setItem('xc-plist', JSON.stringify({ mode: t.dataset.v })); } catch { /* ignore */ } location.reload(); }
    if (act === 'dropfilter') { const q = { ...query }; delete q[t.dataset.k]; go(q); }
    if (act === 'clearfilters') { e.preventDefault(); go({}); }
  });
  return () => ctl.destroy();
}
