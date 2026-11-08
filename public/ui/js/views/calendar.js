import { esc, fmt, codeLabel } from '../util.js';
import { api } from '../api.js';
import { head, a, href, errorBox } from '../components.js';

const pad = n => String(n).padStart(2, '0');
const shift = (month, d) => { const [y, m] = month.split('-').map(Number); const t = new Date(y, m - 1 + d, 1); return `${t.getFullYear()}-${pad(t.getMonth() + 1)}`; };
const nowMonth = () => { const n = new Date(); return `${n.getFullYear()}-${pad(n.getMonth() + 1)}`; };

/* movies by release day of one month */
export default {
  async render({ query }) {
    const requested = /^\d{4}-(0[1-9]|1[0-2])$/.test(query.month || '') ? query.month : '';
    let body;
    try { body = await api('movies/calendar', requested ? { month: requested } : {}); } catch (e) { return { html: `<div class="wrap">${head('Lịch phát hành', esc(requested || ''))}${errorBox(e)}</div>` }; }
    const month = body.meta?.month || requested || nowMonth();
    const latest = body.meta?.latest_month;
    const total = body.meta?.total ?? 0;
    const byDay = Object.fromEntries((body.data || []).map(d => [d.date, d]));
    const [y, m] = month.split('-').map(Number);
    const lead = (new Date(y, m - 1, 1).getDay() + 6) % 7; // Monday first
    const days = new Date(y, m, 0).getDate();
    const cells = [];
    for (let i = 0; i < lead; i++) cells.push('<div class="calcell off"></div>');
    for (let d = 1; d <= days; d++) {
      const key = `${month}-${pad(d)}`, day = byDay[key];
      const items = day ? day.movies.slice(0, 3).map(x => `<div class="ell">${a(href.movie(x.code), esc(codeLabel(x.code)), 'lnk')}</div>`).join('') + (day.count > 3 ? `<div class="dim">+${fmt(day.count - 3)} nữa</div>` : '') : '';
      cells.push(`<div class="calcell ${day ? 'has' : ''}"><div class="calhead"><b>${d}</b>${day ? `<a class="chip mono" href="${href.movies({ released_from: key, released_to: key })}">${fmt(day.count)}</a>` : ''}</div>${items}</div>`);
    }
    const jump = total === 0 && latest && latest !== month
      ? a(`#/calendar?month=${latest}`, `Tới tháng có phim (${Number(latest.slice(5))}/${latest.slice(0, 4)})`, 'btn sm')
      : '';
    const nav = `<div class="seg"><a style="padding:3px 9px" href="#/calendar?month=${shift(month, -1)}">‹</a><a class="${month === nowMonth() ? 'on' : ''}" style="padding:3px 9px" href="#/calendar?month=${nowMonth()}">Hôm nay</a><a style="padding:3px 9px" href="#/calendar?month=${shift(month, 1)}">›</a></div>${jump}`;
    const html = `<div class="wrap">${head('Lịch phát hành', `${m}/${y} · ${fmt(total)} phim`, nav)}
      <div class="panel"><div class="bd">${total === 0 ? `<p class="muted" data-calendar-empty>Tháng này chưa có phim phát hành.${latest && latest !== month ? ' Dùng nút phía trên để tới tháng gần nhất có phim.' : ''}</p>` : ''}<div class="calgrid">${['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].map(w => `<div class="dim calw">${w}</div>`).join('')}${cells.join('')}</div></div></div></div>`;
    return { html };
  },
};
