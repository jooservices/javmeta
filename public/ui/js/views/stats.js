import { esc, fmt } from '../util.js';
import { api } from '../api.js';
import { head, a, href, errorBox } from '../components.js';

const bars = (items, link, key = 'movie_count') => {
  const max = Math.max(1, ...items.map(x => x[key]));
  return items.map(x => `<div class="row-i"><div class="grow ell">${link(x)}</div><div class="bar" style="width:160px"><i style="width:${Math.max(2, x[key] / max * 100)}%"></i></div><span class="mono muted" style="width:70px;text-align:right">${fmt(x[key])}</span></div>`).join('') || '<div class="row-i muted">Chưa có dữ liệu.</div>';
};

/* the shape of the collection: size, growth, movies per year, biggest makers and genres */
export default {
  async render() {
    let sum, years, makers, genres, series, src;
    try {
      [sum, years, makers, genres, series, src] = await Promise.all([
        api('status/summary'),
        api('meta/catalog/years', { per_page: 200, sort: 'name' }, { ttl: 120000 }),
        api('meta/catalog/makers', { per_page: 12 }, { ttl: 120000 }),
        api('meta/catalog/genres', { per_page: 12 }, { ttl: 120000 }),
        api('meta/catalog/series', { per_page: 12 }, { ttl: 120000 }),
        api('status/sources'),
      ]);
    } catch (e) { return { html: `<div class="wrap">${head('Thống kê', '')}${errorBox(e)}</div>` }; }
    const s = sum.data, ing = s.ingest;
    const tile = (label, value, sub = '') => `<div class="panel"><div class="bd"><div class="muted">${esc(label)}</div><div style="font-size:24px;font-weight:600" class="mono">${fmt(value)}</div><div class="dim">${esc(sub)}</div></div></div>`;
    const ys = (years.data || []).filter(y => Number(y.name) > 1950);
    const enabled = src.data.filter(x => x.enabled), ok = enabled.filter(x => x.last_success_at && Date.now() - new Date(x.last_success_at) < 86400000).length;
    const html = `<div class="wrap">${head('Thống kê', 'tổng quan bộ sưu tập')}
      <div class="grid g4" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));display:grid;gap:10px;margin-bottom:10px">${tile('Phim', s.counts.movies, `+${fmt(ing.movies_24h)} trong 24 giờ`)}${tile('Diễn viên', s.counts.performers, `+${fmt(ing.performers_24h)} trong 24 giờ`)}${tile('Phim mới / giờ', ing.movies_1h, 'giờ vừa qua')}${tile('Nguồn đang ổn', ok, `trên ${enabled.length} nguồn bật`)}</div>
      <div class="panel"><div class="hd"><h2>Phim theo năm phát hành</h2></div><div class="bd">${bars(ys, y => a(href.facet('year', y.name), esc(y.name)))}</div></div>
      <div class="grid g2"><div class="panel"><div class="hd"><h2>Maker nhiều phim nhất</h2>${a('#/catalog/makers', 'Tất cả →', 'btn sm ghost')}</div>${bars(makers.data || [], x => a(href.facet('maker', x.name), esc(x.name)))}</div>
        <div class="panel"><div class="hd"><h2>Thể loại phổ biến</h2>${a('#/catalog/genres', 'Tất cả →', 'btn sm ghost')}</div>${bars(genres.data || [], x => a(href.facet('genre', x.name), esc(x.name)))}</div></div>
      <div class="panel"><div class="hd"><h2>Series lớn nhất</h2>${a('#/catalog/series', 'Tất cả →', 'btn sm ghost')}</div>${bars(series.data || [], x => a(href.facet('series', x.name), esc(x.name)))}</div></div>`;
    return { html };
  },
};
