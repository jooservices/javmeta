import { esc, fmt, queryString } from '../util.js';
import { api, pagination } from '../api.js';
import { head, a, href, movieCard, performerCard, emptyBox, errorBox } from '../components.js';

export default {
  async render({ query }) {
    const q = (query.q || '').trim();
    const guestMode = document.body.dataset.guestMode === 'true';
    const semantic = query.mode === 'meaning';
    const modeLinks = `<div class="seg"><a class="${semantic ? '' : 'on'}" style="padding:3px 9px" href="${href.search(q)}">Từ khoá</a><a class="${semantic ? 'on' : ''}" style="padding:3px 9px" href="#/search${queryString({ q, mode: 'meaning' })}">Theo nghĩa</a></div>`;
    const form = `<form class="bigq" id="sform"><input type="text" id="sq" value="${esc(q)}" placeholder="Mô tả phim hoặc diễn viên, mã phim, thể loại…" autocomplete="off"><button class="btn pri" type="submit">Tìm</button></form>`;
    if (q.length < 2) {
      return { html: `<div class="wrap">${head('Tìm kiếm', 'từ khoá hoặc theo nghĩa')}<div class="hero">${form}${modeLinks}<div class="chips"><span class="dim">Ví dụ</span>${['Drama', 'Mankitsu', 'onsen', 'office'].map(r => a(href.search(r), esc(r), 'chip')).join('')}</div></div></div>`, mount: bind };
    }
    let movies, people, sg = {}, hy, scenes = [], sceneError = null;
    try {
      [hy, sg, scenes] = await Promise.all([
        api('search', { q, per_page: 18, ...(semantic ? {} : { understand: 0 }) }),
        api('suggest', { q, limit: 8 }).then(r => r.data || {}).catch(() => ({})),
        !guestMode && q.length >= 3 ? api('scenes/search', { q, per_page: 12 }).then(r => r.data || []).catch(e => { sceneError = e; return []; }) : Promise.resolve([]),
      ]);
      movies = { data: hy.data.movies, meta: { pagination: { total: hy.data.movies.length } } };
      people = { data: hy.data.performers, meta: { pagination: { total: hy.data.performers.length } } };
    } catch (e) { return { html: `<div class="wrap">${head('Tìm kiếm', esc(q))}${errorBox(e)}</div>` }; }
    const it = hy.data.interpretation || { filters: {}, keywords: '', by: 'none' };
    const FILTER_LABEL = { maker: 'maker', series: 'series', genre: 'thể loại', actress: 'diễn viên', code: 'mã', label: 'label', released_from: 'từ ngày', released_to: 'đến ngày', runtime_min: 'dài hơn (phút)', runtime_max: 'ngắn hơn (phút)' };
    const understood = semantic && (Object.keys(it.filters).length || it.keywords !== q) ? `<div class="chips" style="margin:6px 0"><span class="dim">Tôi hiểu là${it.by === 'ollama' ? ' (Ollama)' : ''}:</span>${Object.entries(it.filters).map(([k, v]) => `<span class="chip on">${esc(FILTER_LABEL[k] || k)}: <b>${esc(v)}</b></span>`).join('')}${it.keywords ? `<span class="chip">từ khoá: <b>${esc(it.keywords)}</b></span>` : ''}</div>` : '';
    const mt = pagination(movies).total ?? (movies.data || []).length;
    const pt = pagination(people).total ?? (people.data || []).length;
    const groups = [['Thể loại', 'genre', sg.genres], ['Maker', 'maker', sg.makers], ['Series', 'series', sg.series]].filter(g => (g[2] || []).length);
    const facets = groups.length ? `<div class="panel"><div class="hd"><h2>Danh mục khớp</h2></div><div class="bd">${groups.map(g => `<div class="chips" style="margin-bottom:6px"><span class="dim" style="width:70px">${g[0]}</span>${g[2].map(x => a(href.facet(g[1], x.name), `${esc(x.name)} <span class="mono dim">${fmt(x.movie_count)}</span>`, 'chip')).join('')}</div>`).join('')}</div></div>` : '';
    const html = `<div class="wrap">${head('Kết quả', `“${esc(q)}”`, modeLinks)}<div class="hero">${form}${understood}</div>${facets}
      <div class="panel"><div class="hd"><h2>Phim</h2><span class="chip mono">${fmt(mt)}</span><span class="sp"></span>${mt > 18 ? a(href.movies({ q }), 'Xem tất cả →', 'btn sm ghost') : ''}</div><div class="bd">${(movies.data || []).length ? `<div class="mgrid">${movies.data.map((m, i) => movieCard(m, i)).join('')}</div>` : emptyBox('Không có phim khớp', semantic ? 'Tìm theo nghĩa cần embedder; thử chế độ từ khoá.' : 'Thử từ khoá khác.')}</div></div>
      <div class="panel"><div class="hd"><h2>Diễn viên</h2><span class="chip mono">${fmt(pt)}</span><span class="sp"></span>${pt > 8 ? a(href.performers({ q }), 'Xem tất cả →', 'btn sm ghost') : ''}</div><div class="bd">${(people.data || []).length ? `<div class="agrid">${people.data.map((p, i) => performerCard(p, i)).join('')}</div>` : '<span class="muted">Không có diễn viên khớp.</span>'}</div></div></div>`;
    const sceneHtml = guestMode ? '' : `<section class="panel"><div class="hd"><h2>Cảnh</h2><span class="chip">${scenes.length}</span></div><div class="bd">${sceneError ? errorBox(sceneError) : scenes.map(s => `<a class="row-i" href="#/player/${encodeURIComponent(s.movie_code || 'file-' + s.library_file_id)}?file=${s.library_file_id}&t=${Number(s.start_sec)}" style="overflow-wrap:anywhere">${s.thumb_url ? `<img src="${esc(s.thumb_url)}" alt="" loading="lazy" width="96">` : ''}<span>${esc(s.movie_code || 'Tệp #' + s.library_file_id)} · ${Math.floor(s.start_sec / 60)}:${String(Math.floor(s.start_sec % 60)).padStart(2, '0')}<br>${esc(s.description)}</span></a>`).join('') || 'Chưa có cảnh khớp. Hãy tách và mô tả cảnh trong Thư viện.'}</div></section>`;
    return { html: html.replace(/<\/div>$/, () => sceneHtml + '</div>'), mount: bind };
  },
};

function bind(root) {
  root.querySelector('#sform').addEventListener('submit', e => { e.preventDefault(); const q = root.querySelector('#sq').value.trim(); if (q) location.hash = href.search(q); });
  const input = root.querySelector('#sq'); if (input && !input.value) input.focus();
}
