import { esc, fmt, ago } from '../util.js';
import { api } from '../api.js';
import { head, a, href, stat, movieCard, performerCard, errorBox, emptyBox } from '../components.js';
import { sourceState } from './crawl.js';

const countUp = root => root.querySelectorAll('.cu').forEach(e => {
  const to = +e.dataset.to, t0 = performance.now();
  const f = t => { const k = Math.min(1, (t - t0) / 700); e.textContent = fmt(Math.round(to * (1 - Math.pow(1 - k, 3)))); if (k < 1 && document.body.contains(e)) requestAnimationFrame(f); };
  requestAnimationFrame(f);
});
const cu = n => `<span class="cu" data-to="${n}">0</span>`;

export default {
  async render() {
    let summary, sources, latest, people;
    try {
      [summary, sources, latest, people] = await Promise.all([
        api('status/summary'), api('status/sources'),
        api('movies', { sort: 'update_date', per_page: 14 }),
        api('performers', { sort: 'updated_at', per_page: 6 }),
      ]);
    } catch (e) { return { html: `<div class="wrap">${errorBox(e)}</div>` }; }
    const s = summary.data, src = sources.data;
    const queue = s.queue.by_status || {};
    const ranked = src.map(x => ({ x, st: sourceState(x) })).sort((p, q) => ({ bad: 0, warn: 1, off: 3, ok: 2 }[p.st.key] - { bad: 0, warn: 1, off: 3, ok: 2 }[q.st.key]));
    const html = `<div class="wrap">
      <div class="hero"><form class="bigq" id="hsearch"><input type="text" id="hsq" placeholder="Tìm phim, diễn viên, mã phim, thể loại…" autocomplete="off"><button class="btn pri" type="submit">Tìm</button></form>
        <div class="chips"><span class="dim" style="line-height:20px">Gợi ý</span>${['Drama', 'Mankitsu', 'blood type O Tokyo'].map(r => a(href.search(r), esc(r), 'chip')).join('')}</div></div>
      <div class="panel"><div class="grid g6" style="gap:0">
        <div class="stat"><b>${cu(s.counts.movies)}</b><span>phim trong DB</span><em>+${fmt(s.ingest.movies_24h)} trong 24 giờ</em></div>
        <div class="stat"><b>${cu(s.counts.performers)}</b><span>diễn viên</span><em>+${fmt(s.ingest.performers_24h)} trong 24 giờ</em></div>
        <div class="stat"><b>${cu(s.ingest.movies_1h)}</b><span>phim mới trong 1 giờ</span><em>${fmt(s.ingest.performers_1h)} diễn viên</em></div>
        <div class="stat"><b>${cu(queue.pending || 0)}</b><span>việc đang chờ</span><em>${fmt(queue.claimed || 0)} đang chạy</em></div>
        <div class="stat"><b>${cu(queue.done || 0)}</b><span>việc đã xong</span></div>
        <div class="stat"><b>${cu(queue.failed || 0)}</b><span>việc lỗi</span><em>${s.queue.stuck_claimed ? s.queue.stuck_claimed + ' bị kẹt' : 'không kẹt'}</em></div></div></div>
      <div class="cols"><div class="col">
        <div class="panel"><div class="hd"><h2>Mới cập nhật</h2><span class="sp"></span>${a(href.movies({ sort: 'update_date' }), 'Xem tất cả →', 'btn sm ghost')}</div><div class="bd"><div class="strip">${(latest.data || []).map((m, i) => movieCard(m, i)).join('')}</div></div></div>
        <div class="panel"><div class="hd"><h2>Diễn viên mới cập nhật</h2><span class="sp"></span>${a(href.performers({ sort: 'updated_at' }), 'Xem tất cả →', 'btn sm ghost')}</div><div class="bd"><div class="agrid S">${(people.data || []).map((p, i) => performerCard(p, i)).join('') || '<span class="muted">Chưa có.</span>'}</div></div></div>
      </div><div class="col">
        <div class="panel"><div class="hd"><h2>Sức khoẻ crawl</h2><span class="sp"></span>${a(href.crawl(), 'Chi tiết →', 'btn sm ghost')}</div>${ranked.slice(0, 10).map(({ x, st }) => `<div class="row-i"><span class="dot ${st.dot}"></span><div class="grow ell">${esc(x.name)}</div><span class="chip ${st.chip}">${st.label}</span><span class="muted mono">${x.last_success_at ? ago(x.last_success_at) : '—'}</span></div>`).join('')}</div>
        <div class="panel"><div class="hd"><h2>Worker</h2></div>${(s.workers || []).map(w => `<div class="row-i"><span class="dot ${w.stale ? 'bad' : ''}"></span><div class="grow ell mono">${esc(w.instance)}</div><span class="muted">${w.active_claims} việc</span></div>`).join('') || '<div class="row-i muted">Chưa có tín hiệu.</div>'}</div>
        <div class="panel"><div class="hd"><h2>24 giờ qua</h2></div><div class="bd">${[['Lượt chạy', s.runs_24h.runs], ['Trang đã tải', s.runs_24h.pages_fetched], ['Phim mới', s.runs_24h.movies_new], ['Phim cập nhật', s.runs_24h.movies_updated], ['Lỗi', s.runs_24h.failures]].map(([k, n]) => `<div class="row-i" style="padding-inline:0"><div class="grow">${k}</div><span class="mono">${fmt(n)}</span></div>`).join('')}</div></div>
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
