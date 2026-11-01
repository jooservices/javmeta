import { esc, fmt } from '../util.js';
import { admin, send } from '../api.js';
import { head, errorBox, ic } from '../components.js';

const SCOPES = [['movies', 'Phim'], ['bulk', 'Tra hàng loạt'], ['performers', 'Diễn viên'], ['catalog', 'Danh mục & gợi ý'], ['status', 'Trạng thái'], ['config', 'Cấu hình dịch vụ'], ['mcp', 'MCP'], ['media', 'Worker media']];
const FIELD_NAMES = { code: 'Mã', title_jp: 'Tên JP', title_en: 'Tên EN', actresses: 'Diễn viên', cover_url: 'Ảnh bìa', release_date: 'Ngày phát hành', runtime: 'Thời lượng', maker: 'Maker', label: 'Label', series: 'Series', genres: 'Thể loại' };

/* owner administration: data quality report and API keys */
export default {
  async render() {
    let keys, quality;
    try { [keys, quality] = await Promise.all([admin('keys'), admin('report/quality')]); } catch (e) { return { html: `<div class="wrap">${head('Quản trị', '')}${errorBox(e)}</div>` }; }
    const q = quality.data;
    const bar = p => `<div class="bar barfield"><i style="width:${Math.max(1, p)}%"></i></div>`;
    const fields = Object.entries(q.core_fields).map(([k, v]) => `<div class="row-i"><div class="grow ell" style="min-width:72px">${esc(FIELD_NAMES[k] || k)}</div>${bar(v.percent)}<span class="mono" style="width:60px;text-align:right">${esc(v.percent)}%</span><span class="mono muted" style="width:80px;text-align:right">${fmt(v.filled)}</span></div>`).join('');
    const sources = Object.entries(q.movies_by_source || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => `<div class="row-i"><div class="grow">${esc(k)}</div><span class="mono">${fmt(v)}</span></div>`).join('');
    const tiers = Object.entries(q.completeness_tiers || {}).map(([k, v]) => `<span class="chip mono">tầng ${esc(k)}: ${fmt(v)}</span>`).join(' ');
    const html = `<div class="wrap">${head('Quản trị', 'chất lượng dữ liệu và khoá API')}
      <div class="grid g2">
        <div class="panel"><div class="hd"><h2>Chất lượng dữ liệu</h2><span class="chip mono">${fmt(q.movies)} phim</span></div>
          <div class="bd"><div class="chips" style="margin-bottom:8px"><span class="chip ok mono">≥ 8/11 trường: ${esc(q.movies_with_8_of_11.percent)}%</span><span class="chip ${q.needs_review ? 'warn' : ''} mono">cần xem lại: ${fmt(q.needs_review)}</span>${tiers}</div></div>${fields}</div>
        <div class="panel"><div class="hd"><h2>Phim theo nguồn</h2></div>${sources || '<div class="row-i muted">Chưa có.</div>'}</div>
      </div>
      <div class="panel"><div class="hd"><h2>Khoá API</h2><span class="chip mono">${fmt(keys.data.length)}</span></div>
        <div class="bd"><form id="kf" class="brbar" style="flex-wrap:wrap;gap:8px"><input type="text" name="label" placeholder="Tên khoá" required maxlength="100" style="max-width:200px"><input type="number" name="abuse_rpm" value="60" min="1" max="10000" style="width:90px" title="Số lệnh gọi mỗi phút"><span class="muted">/phút</span>
          ${SCOPES.map(s => `<label class="muted"><input type="checkbox" name="scopes" value="${s[0]}"> ${s[1]}</label>`).join(' ')}<button class="btn pri" type="submit">Tạo khoá</button></form>
          <div id="newkey" class="muted" style="margin-top:6px">Không chọn quyền nào = khoá dùng được mọi thứ.</div></div>
        <div class="tbl-wrap"><table><thead><tr><th>Tên</th><th>Tiền tố</th><th>Quyền</th><th>/phút</th><th>Trạng thái</th><th>Tạo lúc</th><th></th></tr></thead><tbody id="keyrows">${keys.data.map(k => row(k)).join('')}</tbody></table></div>
        <div id="usage" class="bd"></div></div></div>`;
    return { html, mount };
  },
};

function row(k) {
  const scopes = k.scopes ? k.scopes.map(s => `<span class="chip">${esc(s)}</span>`).join(' ') : '<span class="chip ok">tất cả</span>';
  const active = k.status === 'active';
  return `<tr><td>${esc(k.label)}</td><td class="mono">${esc(k.prefix)}</td><td>${scopes}</td><td class="mono">${esc(k.abuse_rpm)}</td><td>${active ? '<span class="chip ok">đang dùng</span>' : '<span class="chip bad">đã thu hồi</span>'}</td><td class="mono">${esc((k.created_at || '').slice(0, 16).replace('T', ' '))}</td><td><button class="btn sm" data-usage="${k.id}">Mức dùng</button> ${active ? `<button class="btn sm" data-revoke="${k.id}">Thu hồi</button>` : ''}</td></tr>`;
}

function mount(root) {
  const form = root.querySelector('#kf'), out = root.querySelector('#newkey'), rows = root.querySelector('#keyrows'), usage = root.querySelector('#usage');
  const reload = async () => { rows.innerHTML = (await admin('keys')).data.map(row).join(''); };
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const f = new FormData(form), scopes = f.getAll('scopes');
    try {
      const r = await send('POST', 'keys', { label: f.get('label'), abuse_rpm: Number(f.get('abuse_rpm')), ...(scopes.length ? { scopes } : {}) });
      out.innerHTML = `Khoá mới (chỉ hiện một lần): <code class="mono" style="user-select:all">${esc(r.data.plaintext)}</code> <button class="btn sm" data-act="copy" data-v="${esc(r.data.plaintext)}">${ic('copy')} Sao chép</button>`;
      form.reset(); await reload();
    } catch (err) { out.innerHTML = `<span class="err">${esc(err.message)}</span>`; }
  });
  root.addEventListener('click', async e => {
    const r = e.target.closest('[data-revoke]'), u = e.target.closest('[data-usage]');
    if (r && confirm('Thu hồi khoá này? Ứng dụng đang dùng nó sẽ bị từ chối.')) { await send('DELETE', 'keys/' + r.dataset.revoke); await reload(); }
    if (u) {
      try {
        const d = (await admin('keys/' + u.dataset.usage + '/usage')).data;
        usage.innerHTML = `<b>24 giờ:</b> <span class="mono">${fmt(d.last_24h.calls)}</span> lệnh, lỗi <span class="mono">${fmt(d.last_24h.errors)}</span>, trung bình <span class="mono">${fmt(d.last_24h.avg_ms)} ms</span> · <b>7 ngày:</b> <span class="mono">${fmt(d.last_7d.calls)}</span> lệnh, lỗi <span class="mono">${fmt(d.last_7d.errors)}</span><div class="muted" style="margin-top:4px">${d.top_endpoints.map(x => `<span class="mono">${esc(x.endpoint)}</span> ×${fmt(x.calls)}`).join(' · ') || 'Chưa có lệnh gọi.'}</div>`;
      } catch (err) { usage.innerHTML = `<span class="err">${esc(err.message)}</span>`; }
    }
  });
}
