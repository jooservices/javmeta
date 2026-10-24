import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { crumbs, a, href, avatar, performerName, kv, notKnown, errorBox, emptyBox, facetLink } from '../components.js';
import { moviesBrowser } from './movies.js';

const age = d => {
  if (!d) return null;
  const b = new Date(d), n = new Date();
  let y = n.getFullYear() - b.getFullYear();
  if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) y--;
  return y;
};
const v = (x, fn = esc) => (x === null || x === undefined || x === '') ? notKnown() : fn(x);

export default {
  async render({ params }) {
    const body = await api('performers/' + encodeURIComponent(params.id)).catch(e => ({ error: e }));
    if (body.error) {
      if (body.error.status === 404) return { html: `<div class="wrap">${crumbs([['Diễn viên', '#/performers'], [params.id]])}${emptyBox('Không tìm thấy diễn viên', esc(params.id), a('#/performers', 'Về danh sách', 'btn'))}</div>` };
      return { html: `<div class="wrap">${errorBox(body.error)}</div>` };
    }
    const p = body.data;
    const name = performerName(p);
    const tab = new URLSearchParams(location.hash.split('?')[1] || '').get('tab') || 'movies';
    const fields = ['birth_date', 'height_cm', 'bust', 'waist', 'hip', 'cup', 'blood_type', 'bio_text', 'debut_date', 'location', 'image_url'];
    const missing = fields.filter(k => p[k] === null || p[k] === undefined || p[k] === '');
    const complete = Math.round(100 - missing.length / fields.length * 100);
    const tabs = [['movies', 'Phim'], ['sources', 'Nguồn'], ['bio', 'Hồ sơ chi tiết']];
    const tabsHtml = `<div class="tabs">${tabs.map(t => `<a class="tab ${tab === t[0] ? 'on' : ''}" href="#/performer/${encodeURIComponent(p.id)}?tab=${t[0]}">${t[1]}${t[0] === 'sources' ? `<i>${(p.sources || []).length}</i>` : ''}</a>`).join('')}</div>`;
    let bodyHtml = '';
    let mount = null;
    if (tab === 'movies') {
      const b = moviesBrowser({}, { fixed: { actress: name }, closed: true });
      bodyHtml = b.html; mount = b.mount;
    }
    if (tab === 'sources') {
      bodyHtml = `<div class="panel tbl-wrap"><table><thead><tr><th>Nguồn</th><th>Tên</th><th>Ngày sinh</th><th>Trạng thái</th><th></th></tr></thead><tbody>${(p.sources || []).map(s => `<tr><td><b>${esc(s.source_slug)}</b><div class="dim mono">${esc(s.external_id || '')}</div></td><td>${esc(s.name_romaji || s.name_kanji || '')}</td><td class="mono">${esc(s.birth_date || '—')}</td><td>${s.active ? '<span class="chip ok">còn</span>' : '<span class="chip warn">đã gỡ</span>'}</td><td>${s.source_url ? `<a class="btn sm" href="${esc(s.source_url)}" target="_blank" rel="noreferrer noopener">Mở trang</a>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">Chưa có.</td></tr>'}</tbody></table></div>`;
    }
    if (tab === 'bio') {
      bodyHtml = `<div class="panel"><div class="bd">${kv([
        ['Tên romaji', v(p.name_romaji)], ['Tên kanji', v(p.name_kanji)], ['Tên kana', v(p.name_kana)],
        ['Biệt danh', (p.aliases || []).length ? esc(p.aliases.join(', ')) : '<span class="dim">không có</span>'],
        ['Ngày sinh', v(p.birth_date, x => `<span class="mono">${esc(x)}</span>${age(x) !== null ? ` <span class="dim">· ${age(x)} tuổi</span>` : ''}`)],
        ['Chiều cao', v(p.height_cm, x => `<span class="mono">${x} cm</span>`)],
        ['Số đo', (p.bust || p.waist || p.hip) ? `<span class="mono">${fmt(p.bust)}-${fmt(p.waist)}-${fmt(p.hip)}</span>` : notKnown()],
        ['Cup', v(p.cup)], ['Nhóm máu', v(p.blood_type)], ['Nơi sống', v(p.location)],
        ['Debut', v(p.debut_date)], ['Nghỉ', p.retired_date ? esc(p.retired_date) : '<span class="dim">chưa ghi nhận</span>'],
        ['Tiểu sử', v(p.bio_text)],
        ['Thuộc tính thêm', `<span class="mono" style="font-size:12px;white-space:pre-wrap">${esc(JSON.stringify(p.attrs || {}))}</span>`],
      ])}</div></div>`;
    }
    const html = `<div class="wrap">${crumbs([['Diễn viên', '#/performers'], [name]])}
      <div class="cols" style="grid-template-columns:minmax(0,320px) minmax(0,1fr)"><div class="col">
        <div class="panel"><div class="bd" style="display:flex;flex-direction:column;gap:12px"><div style="display:flex;gap:14px;align-items:center">${avatar(p, 'lg')}<div><h1 style="font-size:20px">${esc(name)}</h1><div class="muted">${esc(p.name_kanji || '')}</div>${(p.aliases || []).length ? `<div class="dim">aka ${esc(p.aliases.slice(0, 3).join(', '))}</div>` : ''}</div></div>
          ${kv([
            ['Tuổi', p.birth_date ? `<span class="mono">${age(p.birth_date)}</span>` : notKnown()],
            ['Nhóm máu', v(p.blood_type)], ['Nơi sống', v(p.location)],
            ['Chiều cao', v(p.height_cm, x => `<span class="mono">${x} cm</span>`)],
            ['Số đo', (p.bust || p.waist || p.hip) ? `<span class="mono">${fmt(p.bust)}-${fmt(p.waist)}-${fmt(p.hip)}</span>` : notKnown()],
            ['Cup', v(p.cup)], ['Debut', v(p.debut_date)],
            ['Số phim', `<span class="mono">${fmt(p.linked_title_count)}</span>`],
          ])}
          <div><div class="muted" style="display:flex;justify-content:space-between"><span>Độ đầy đủ hồ sơ</span><span class="mono">${complete}%</span></div><div class="bar ${complete < 60 ? 'warn' : 'ok'}"><i style="width:${complete}%"></i></div>${missing.length ? `<div class="dim" style="margin-top:4px">Đang chờ crawl: ${esc(missing.join(', '))}</div>` : ''}</div></div></div></div>
      <div class="col">${tabsHtml}${bodyHtml}</div></div></div>`;
    return { html, mount };
  },
};
