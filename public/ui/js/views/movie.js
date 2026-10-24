import { esc, fmt, titleOf } from '../util.js';
import { api } from '../api.js';
import { myMarks, markBtn } from '../marks.js';
import { crumbs, cover, a, href, facetLink, facetChip, performerCard, movieCard, kv, notKnown, errorBox, emptyBox, ic, performerName } from '../components.js';

export default {
  async render({ params }) {
    const body = await api('movies/' + encodeURIComponent(params.code)).catch(e => ({ error: e }));
    if (body.error) {
      if (body.error.status === 404) return { html: `<div class="wrap">${crumbs([['Phim', '#/movies'], [params.code]])}${emptyBox('Không tìm thấy phim', esc(params.code), a('#/movies', 'Về danh sách phim', 'btn'))}</div>` };
      return { html: `<div class="wrap">${errorBox(body.error)}</div>` };
    }
    const m = body.data;
    const marks = await myMarks();
    const performers = m.performers || [];
    const tab = new URLSearchParams(location.hash.split('?')[1] || '').get('tab') || 'meta';
    const tabs = [['meta', 'Metadata'], ['media', 'Liên kết & media'], ['codes', 'Mã & nguồn']];
    const tabsHtml = `<div class="tabs">${tabs.map(t => `<a class="tab ${tab === t[0] ? 'on' : ''}" href="#/movie/${encodeURIComponent(m.code)}?tab=${t[0]}">${t[1]}</a>`).join('')}</div>`;
    let content = '';
    if (tab === 'meta') {
      content = kv([
        ['Mã', `<span class="mono">${esc(m.code)}</span>`],
        ['Tên (EN)', m.title_en ? esc(m.title_en) : notKnown()],
        ['Tên (JP)', m.title_jp ? esc(m.title_jp) : notKnown()],
        ['Phát hành', m.release_date ? `<span class="mono">${esc(m.release_date)}</span> ${a(href.facet('year', m.release_date.slice(0, 4)), esc(m.release_date.slice(0, 4)), 'chip')}` : notKnown()],
        ['Thời lượng', m.runtime_minutes ? `<span class="mono">${m.runtime_minutes} phút</span>` : notKnown()],
        ['Maker', m.maker ? facetLink('maker', m.maker) : notKnown()],
        ['Label', m.label ? facetLink('label', m.label) : notKnown()],
        ['Series', m.series ? facetLink('series', m.series) : '<span class="dim">không thuộc series</span>'],
        ['Kiểm duyệt', m.censored === null ? notKnown() : (m.censored ? 'Có che' : 'Không che')],
        ['Điểm cộng đồng', m.community_score !== null ? `<span class="mono">★ ${esc(m.community_score)}</span>` : notKnown()],
        ['Độ đầy đủ', `<span class="mono">tầng ${esc(m.completeness_tier ?? '—')}</span>`],
        ['Đạo diễn', (m.directors || []).length ? esc(m.directors.join(', ')) : notKnown()],
        ['Crawl lần cuối', `<span class="mono">${esc(m.crawled_at || '—')}</span>`],
        ['Mô tả', m.description ? esc(m.description) : notKnown()],
      ]);
    }
    if (tab === 'media') {
      const magnets = m.magnets || [], hls = m.hls_stream_urls || [], gallery = m.gallery || [];
      const row = (label, u) => `<div class="row-i"><div class="grow ell mono" title="${esc(u)}">${esc(u)}</div><button class="btn sm" data-act="copy" data-v="${esc(u)}">${ic('copy')} ${label}</button></div>`;
      content = `<div class="panel"><div class="hd"><h2>Magnet / tải</h2><span class="chip mono">${magnets.length}</span></div>${magnets.map(x => row('Sao chép', typeof x === 'string' ? x : (x.url || ''))).join('') || '<div class="row-i muted">Chưa có.</div>'}</div>
        <div class="panel"><div class="hd"><h2>Luồng HLS</h2><span class="chip mono">${hls.length}</span></div>${hls.map(x => row('Sao chép', typeof x === 'string' ? x : (x.url || ''))).join('') || '<div class="row-i muted">Chưa có.</div>'}</div>
        <div class="panel"><div class="hd"><h2>Ảnh mẫu</h2><span class="chip mono">${gallery.length}</span></div><div class="bd"><div class="mgrid S">${gallery.slice(0, 24).map(g => { const u = typeof g === 'string' ? g : (g.url || ''); return u ? `<a href="${esc(u)}" target="_blank" rel="noreferrer noopener" class="th" style="--h:200"><img loading="lazy" referrerpolicy="no-referrer" src="${esc(u)}" alt="" onerror="this.remove()"></a>` : ''; }).join('') || '<span class="muted">Chưa có.</span>'}</div></div></div>`;
    }
    if (tab === 'codes') {
      content = `<div class="panel tbl-wrap"><table><thead><tr><th>Mã</th><th>Loại</th></tr></thead><tbody>${(m.codes || []).map(c => `<tr><td class="mono">${esc(c.code || c.value || JSON.stringify(c))}</td><td>${esc(c.kind || '')}</td></tr>`).join('') || '<tr><td colspan="2" class="muted">Chưa có.</td></tr>'}</tbody></table></div>
        <div class="panel"><div class="hd"><h2>Nguồn của diễn viên</h2></div>${performers.flatMap(p => (p.sources || []).map(s => `<div class="row-i"><span class="dot"></span><div class="grow">${esc(performerName(p))} <span class="muted">· ${esc(s.source_slug)}</span></div><a class="btn sm" href="${esc(s.source_url)}" target="_blank" rel="noreferrer noopener">${ic('link')} Mở trang</a></div>`)).join('') || '<div class="row-i muted">Chưa có.</div>'}</div>
        <div class="panel"><div class="hd"><h2>Thuộc tính thêm (attrs)</h2></div><div class="bd mono" style="font-size:12px;white-space:pre-wrap;word-break:break-word">${esc(JSON.stringify(m.attrs || {}, null, 2))}</div></div>`;
    }
    const html = `<div class="wrap">${crumbs([['Phim', '#/movies'], ...(m.maker ? [[m.maker, href.facet('maker', m.maker)]] : []), [m.code]])}
      <div class="cols" style="grid-template-columns:minmax(0,340px) minmax(0,1fr)"><div class="col">${cover(m, { dur: true })}</div>
      <div class="col"><div><div class="mono muted">${esc(m.code)}${m.release_date ? ' · ' + esc(m.release_date) : ''}${m.needs_review ? ' · <span class="chip warn">cần xem lại</span>' : ''}${m.delisted_at ? ' · <span class="chip bad">đã gỡ</span>' : ''}</div><h1 style="font-size:22px">${esc(titleOf(m))}</h1></div>
        <div class="chips">${(m.genres || []).map(g => facetChip('genre', g)).join('')}${m.series ? facetChip('series', m.series) : ''}${m.maker ? facetChip('maker', m.maker) : ''}${m.runtime_minutes ? `<span class="chip mono">${m.runtime_minutes}′</span>` : ''}${m.community_score !== null ? `<span class="chip mono">★ ${esc(m.community_score)}</span>` : ''}</div>
        <div class="agrid S">${performers.map((p, i) => performerCard(p, i)).join('') || '<span class="muted">Chưa có diễn viên.</span>'}</div>
        <div class="ibtns"><button class="btn" data-act="copy" data-v="${esc(m.code)}">${ic('copy')} Sao chép mã</button>${markBtn(marks, 'favourite', 'movie', m.code, '★ Đã yêu thích', '☆ Yêu thích')}${markBtn(marks, 'watch_later', 'movie', m.code, '⏱ Trong danh sách xem sau', '⏱ Xem sau')}${markBtn(marks, 'seen', 'movie', m.code, '✓ Đã xem', '✓ Đánh dấu đã xem')}</div>
        <div class="panel">${tabsHtml}<div class="bd">${content}</div></div></div></div>
      <div class="panel" id="related"><div class="hd"><h2>Phim liên quan</h2><span class="dim">cùng diễn viên, series, maker</span></div><div class="bd"><div class="strip" id="relstrip"><span class="loader"><i></i><i></i><i></i></span></div></div></div></div>`;
    return {
      html,
      mount: root => {
        loadRelated(m, root);
      },
    };
  },
};

async function loadRelated(m, root) {
  const strip = root.querySelector('#relstrip');
  try {
    const r = await api('movies/' + encodeURIComponent(m.code) + '/related');
    strip.innerHTML = (r.data || []).map((x, i) => movieCard(x, i)).join('') || '<span class="muted">Chưa có phim liên quan.</span>';
  } catch (e) {
    strip.innerHTML = `<span class="err">${esc(e.message)}</span>`;
  }
}
