import { esc, fmt, queryString } from '../util.js';
import { api, pagination } from '../api.js';
import { head, a, href, movieCard, performerCard, emptyBox, errorBox } from '../components.js';

export default {
  async render({ query }) {
    const q = (query.q || '').trim();
    const semantic = query.mode === 'meaning';
    const modeLinks = `<div class="seg"><a class="${semantic ? '' : 'on'}" style="padding:3px 9px" href="${href.search(q)}">Từ khoá</a><a class="${semantic ? 'on' : ''}" style="padding:3px 9px" href="#/search${queryString({ q, mode: 'meaning' })}">Theo nghĩa</a></div>`;
    const form = `<form class="bigq" id="sform"><input type="text" id="sq" value="${esc(q)}" placeholder="Mô tả phim hoặc diễn viên, mã phim, thể loại…" autocomplete="off"><button class="btn pri" type="submit">Tìm</button></form>`;
    if (q.length < 2) {
      return { html: `<div class="wrap">${head('Tìm kiếm', 'từ khoá hoặc theo nghĩa')}<div class="hero">${form}${modeLinks}<div class="chips"><span class="dim">Ví dụ</span>${['Drama', 'Mankitsu', 'onsen', 'office'].map(r => a(href.search(r), esc(r), 'chip')).join('')}</div></div></div>`, mount: bind };
    }
    let movies, people, sg = {};
    try {
      [movies, people, sg] = await Promise.all([
        api('movies', { q, per_page: 18, ...(semantic ? { semantic: 1 } : {}) }),
        api('performers', { q, per_page: 8 }),
        api('suggest', { q, limit: 8 }).then(r => r.data || {}).catch(() => ({})),
      ]);
    } catch (e) { return { html: `<div class="wrap">${head('Tìm kiếm', esc(q))}${errorBox(e)}</div>` }; }
    const mt = pagination(movies).total ?? (movies.data || []).length;
    const pt = pagination(people).total ?? (people.data || []).length;
    const groups = [['Thể loại', 'genre', sg.genres], ['Maker', 'maker', sg.makers], ['Series', 'series', sg.series]].filter(g => (g[2] || []).length);
    const facets = groups.length ? `<div class="panel"><div class="hd"><h2>Danh mục khớp</h2></div><div class="bd">${groups.map(g => `<div class="chips" style="margin-bottom:6px"><span class="dim" style="width:70px">${g[0]}</span>${g[2].map(x => a(href.facet(g[1], x.name), `${esc(x.name)} <span class="mono dim">${fmt(x.movie_count)}</span>`, 'chip')).join('')}</div>`).join('')}</div></div>` : '';
    const html = `<div class="wrap">${head('Kết quả', `“${esc(q)}”`, modeLinks)}<div class="hero">${form}</div>${facets}
      <div class="panel"><div class="hd"><h2>Phim</h2><span class="chip mono">${fmt(mt)}</span><span class="sp"></span>${mt > 18 ? a(href.movies({ q }), 'Xem tất cả →', 'btn sm ghost') : ''}</div><div class="bd">${(movies.data || []).length ? `<div class="mgrid">${movies.data.map((m, i) => movieCard(m, i)).join('')}</div>` : emptyBox('Không có phim khớp', semantic ? 'Tìm theo nghĩa cần embedder; thử chế độ từ khoá.' : 'Thử từ khoá khác.')}</div></div>
      <div class="panel"><div class="hd"><h2>Diễn viên</h2><span class="chip mono">${fmt(pt)}</span><span class="sp"></span>${pt > 8 ? a(href.performers({ q }), 'Xem tất cả →', 'btn sm ghost') : ''}</div><div class="bd">${(people.data || []).length ? `<div class="agrid">${people.data.map((p, i) => performerCard(p, i)).join('')}</div>` : '<span class="muted">Không có diễn viên khớp.</span>'}</div></div></div>`;
    return { html, mount: bind };
  },
};

function bind(root) {
  root.querySelector('#sform').addEventListener('submit', e => { e.preventDefault(); const q = root.querySelector('#sq').value.trim(); if (q) location.hash = href.search(q); });
  const input = root.querySelector('#sq'); if (input && !input.value) input.focus();
}
