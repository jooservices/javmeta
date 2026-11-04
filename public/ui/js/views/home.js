import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { a, href, stat, movieCard, performerCard, errorBox } from '../components.js';

/* Discover for people who browse the catalog: collection numbers, what is new, who is new, what is popular, what releases today.
   Queue, worker and source-health panels live in the Command Center, not here. */
const countUp = root => root.querySelectorAll('.cu').forEach(e => {
  const to = +e.dataset.to, t0 = performance.now();
  const f = t => { const k = Math.min(1, (t - t0) / 700); e.textContent = fmt(Math.round(to * (1 - Math.pow(1 - k, 3)))); if (k < 1 && document.body.contains(e)) requestAnimationFrame(f); };
  requestAnimationFrame(f);
});
const cu = n => `<span class="cu" data-to="${n}">0</span>`;

export default {
  async render() {
    let summary, latest, people, genres, today;
    try {
      [summary, latest, people, genres, today] = await Promise.all([
        api('status/summary'),
        api('movies', { sort: 'update_date', per_page: 12 }),
        api('performers', { sort: 'updated_at', per_page: 6 }),
        api('meta/catalog/genres', { per_page: 12 }, { ttl: 120000 }),
        api('movies', { sort: 'release_date', per_page: 5, released_to: new Date().toISOString().slice(0, 10) }),
      ]);
    } catch (e) { return { html: `<div class="wrap">${errorBox(e)}</div>` }; }
    const s = summary.data;
    const html = `<div class="wrap">
      <div class="hero"><form class="bigq" id="hsearch"><input type="text" id="hsq" placeholder="Tìm phim, diễn viên, mã phim, thể loại…" autocomplete="off"><button class="btn pri" type="submit">Tìm</button></form>
        <div class="chips"><span class="dim" style="line-height:20px">Gợi ý</span>${['Drama', 'Văn phòng', 'Du lịch', 'Học đường'].map(r => a(href.search(r), esc(r), 'chip')).join('')}</div></div>
      <div class="panel"><div class="grid g6" style="gap:0">
        <div class="stat"><b>${cu(s.counts.movies)}</b><span>phim trong kho</span><em>+${fmt(s.ingest.movies_24h)} trong 24 giờ</em></div>
        <div class="stat"><b>${cu(s.counts.performers)}</b><span>diễn viên</span><em>+${fmt(s.ingest.performers_24h)} trong 24 giờ</em></div>
        <div class="stat"><b>${cu(s.ingest.movies_1h)}</b><span>phim mới trong 1 giờ</span><em>cập nhật liên tục</em></div></div></div>
      <div class="cols"><div class="col">
        <div class="panel"><div class="hd"><h2>Mới cập nhật</h2><span class="sp"></span>${a(href.movies({ sort: 'update_date' }), 'Xem tất cả →', 'btn sm ghost')}</div><div class="bd"><div class="strip">${(latest.data || []).map((m, i) => movieCard(m, i)).join('')}</div></div></div>
        <div class="panel"><div class="hd"><h2>Diễn viên mới cập nhật</h2><span class="sp"></span>${a(href.performers({ sort: 'updated_at' }), 'Xem tất cả →', 'btn sm ghost')}</div><div class="bd"><div class="agrid S">${(people.data || []).map((p, i) => performerCard(p, i)).join('')}</div></div></div>
      </div><div class="col">
        <div class="panel"><div class="hd"><h2>Phát hành gần đây</h2><span class="sp"></span>${a('#/calendar', 'Lịch →', 'btn sm ghost')}</div>${(today.data || []).map(m => `<a class="row-i" href="${href.movie(m.code)}" style="text-decoration:none;color:inherit"><div class="grow ell mono">${esc(m.code)}</div><span class="muted">${esc(m.release_date || '')}</span></a>`).join('')}</div>
        <div class="panel"><div class="hd"><h2>Thể loại phổ biến</h2><span class="sp"></span>${a('#/catalog/genres', 'Tất cả →', 'btn sm ghost')}</div><div class="bd chips">${(genres.data || []).map(g => a(href.facet('genre', g.name), esc(g.name) + ' <small class="dim">' + fmt(g.movie_count) + '</small>', 'chip')).join('')}</div></div>
      </div></div></div>`;
    return {
      html,
      mount: root => {
        countUp(root);
        root.querySelector('#hsearch').addEventListener('submit', e => { e.preventDefault(); const q = root.querySelector('#hsq').value.trim(); if (q) location.hash = href.search(q); });
      },
    };
  },
};
