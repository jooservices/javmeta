import { esc } from '../util.js';
import { head, crumbs, emptyBox, a } from '../components.js';
import { moviesBrowser } from './movies.js';
import { myMarks, markBtn } from '../marks.js';

const LABEL = { genre: 'Thể loại', maker: 'Maker', series: 'Series', label: 'Label', year: 'Năm phát hành' };

/* every genre, maker, series, label and year is a page: the movies of that value */
export default {
  async render({ params, query }) {
    const kind = params.kind, value = decodeURIComponent(params.value);
    if (!LABEL[kind]) return { html: `<div class="wrap">${emptyBox('Loại không có trang riêng', esc(kind), a('#/movies', 'Về danh sách phim', 'btn'))}</div>` };
    const b = kind === 'year'
      ? moviesBrowser(query, { fixed: { year: true }, apiExtra: { released_from: value + '-01-01', released_to: value + '-12-31' }, closed: true })
      : moviesBrowser(query, { fixed: { [kind]: value }, closed: true });
    const follow = (kind === 'maker' || kind === 'series') ? markBtn(await myMarks(), 'follow', kind, value, '🔔 Đang theo dõi', '🔔 Theo dõi') : '';
    return { html: `<div class="wrap">${crumbs([['Phim', '#/movies'], [LABEL[kind]], [value]])}${head(esc(value), LABEL[kind], follow)}${b.html}</div>`, mount: b.mount };
  },
};
