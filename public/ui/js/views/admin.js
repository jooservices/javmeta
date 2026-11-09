import { esc, fmt } from '../util.js';
import { admin, send } from '../api.js';
import { head, errorBox, ic } from '../components.js';
import { askConfirm } from '../confirm.js';
import { t, html as tHtml } from '../i18n.js';

const SCOPES = [['movies', 'admin.scope.movies'], ['bulk', 'admin.scope.bulk'], ['performers', 'admin.scope.performers'], ['catalog', 'admin.scope.catalog'], ['status', 'admin.scope.status'], ['config', 'admin.scope.config'], ['mcp', 'admin.scope.mcp'], ['media', 'admin.scope.media']];
const FIELD_NAMES = ['code', 'title_jp', 'title_en', 'actresses', 'cover_url', 'release_date', 'runtime', 'maker', 'label', 'series', 'genres'];

/* owner administration: data quality report and API keys */
export default {
  async render() {
    let keys, quality;
    try { [keys, quality] = await Promise.all([admin('keys'), admin('report/quality')]); } catch (e) { return { html: `<div class="wrap">${head(t('admin.title'), '')}${errorBox(e)}</div>` }; }
    const q = quality.data;
    const bar = p => `<div class="bar barfield"><i style="width:${Math.max(1, p)}%"></i></div>`;
    const fields = Object.entries(q.core_fields).map(([k, v]) => `<div class="row-i"><div class="grow ell" style="min-width:72px">${esc(FIELD_NAMES.includes(k) ? t('admin.field.' + k) : k)}</div>${bar(v.percent)}<span class="mono" style="width:60px;text-align:right">${esc(v.percent)}%</span><span class="mono muted" style="width:80px;text-align:right">${fmt(v.filled)}</span></div>`).join('');
    const sources = Object.entries(q.movies_by_source || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => `<div class="row-i"><div class="grow">${esc(k)}</div><span class="mono">${fmt(v)}</span></div>`).join('');
    const tiers = Object.entries(q.completeness_tiers || {}).map(([k, v]) => `<span class="chip mono">${t('admin.tier_chip', { tier: k, n: fmt(v) })}</span>`).join(' ');
    const html = `<div class="wrap">${head(t('admin.title'), t('admin.sub'))}
      <div class="grid g2">
        <div class="panel"><div class="hd"><h2>${t('admin.quality')}</h2><span class="chip mono">${t('admin.movies_chip', { count: q.movies, n: fmt(q.movies) })}</span></div>
          <div class="bd"><div class="chips" style="margin-bottom:8px"><span class="chip ok mono">${t('admin.fields_8_of_11', { percent: q.movies_with_8_of_11.percent })}</span><span class="chip ${q.needs_review ? 'warn' : ''} mono">${t('admin.needs_review', { n: fmt(q.needs_review) })}</span>${tiers}</div></div>${fields}</div>
        <div class="panel"><div class="hd"><h2>${t('admin.by_source')}</h2></div>${sources || `<div class="row-i muted">${t('common.none_yet_cap')}</div>`}</div>
      </div>
      <div class="panel"><div class="hd"><h2>${t('admin.keys')}</h2><span class="chip mono">${fmt(keys.data.length)}</span></div>
        <div class="bd"><form id="kf" class="brbar" style="flex-wrap:wrap;gap:8px"><input type="text" name="label" placeholder="${t('admin.key_name')}" required maxlength="100" style="max-width:200px"><input type="number" name="abuse_rpm" value="60" min="1" max="10000" style="width:90px" title="${t('admin.rpm_title')}"><span class="muted">${t('admin.per_minute')}</span>
          ${SCOPES.map(s => `<label class="muted"><input type="checkbox" name="scopes" value="${s[0]}" ${s[0] === 'catalog' ? 'checked' : ''}> ${t(s[1])}</label>`).join(' ')}<button class="btn pri" type="submit">${t('admin.create_key')}</button></form>
          <div id="newkey" class="muted" style="margin-top:6px">${t('admin.scopes_hint')}</div></div>
        <div class="tbl-wrap"><table><thead><tr><th>${t('admin.head.name')}</th><th>${t('admin.head.prefix')}</th><th>${t('admin.head.scopes')}</th><th>${t('admin.per_minute')}</th><th>${t('admin.head.status')}</th><th>${t('admin.head.created')}</th><th></th></tr></thead><tbody id="keyrows">${keys.data.map(k => row(k)).join('')}</tbody></table></div>
        <div id="usage" class="bd"></div></div></div>`;
    return { html, mount };
  },
};

function row(k) {
  const scopes = k.scopes ? k.scopes.map(s => `<span class="chip">${esc(s)}</span>`).join(' ') : `<span class="chip ok">${t('admin.scope_all')}</span>`;
  const active = k.status === 'active';
  return `<tr><td>${esc(k.label)}</td><td class="mono">${esc(k.prefix)}</td><td>${scopes}</td><td class="mono">${esc(k.abuse_rpm)}</td><td>${active ? `<span class="chip ok">${t('admin.status.active')}</span>` : `<span class="chip bad">${t('admin.status.revoked')}</span>`}</td><td class="mono">${esc((k.created_at || '').slice(0, 16).replace('T', ' '))}</td><td><button class="btn sm" data-usage="${k.id}">${t('admin.usage')}</button> ${active ? `<button class="btn sm" data-revoke="${k.id}">${t('admin.revoke')}</button>` : ''}</td></tr>`;
}

function mount(root) {
  const form = root.querySelector('#kf'), out = root.querySelector('#newkey'), rows = root.querySelector('#keyrows'), usage = root.querySelector('#usage');
  const reload = async () => { rows.innerHTML = (await admin('keys')).data.map(row).join(''); };
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const f = new FormData(form), scopes = f.getAll('scopes');
    if (!scopes.length) { out.innerHTML = `<span class="err" role="alert">${t('admin.scopes_required')}</span>`; return; }
    if (!await askConfirm({ title: t('admin.confirm.title'), body: tHtml('admin.confirm.body', { label: f.get('label'), scopes: scopes.join(', ') }), confirmLabel: t('admin.create_key') })) return;
    try {
      const r = await send('POST', 'keys', { label: f.get('label'), abuse_rpm: Number(f.get('abuse_rpm')), scopes });
      out.innerHTML = `${t('admin.new_key')} <code class="mono" style="user-select:all">${esc(r.data.plaintext)}</code> <button class="btn sm" data-act="copy" data-v="${esc(r.data.plaintext)}">${ic('copy')} ${t('common.copy')}</button>`;
      form.reset();
      const catalog = form.querySelector('input[name=scopes][value=catalog]');
      if (catalog) catalog.checked = true;
      await reload();
    } catch (err) { out.innerHTML = `<span class="err" role="alert">${esc(err.message)}</span>`; }
  });
  root.addEventListener('click', async e => {
    const r = e.target.closest('[data-revoke]'), u = e.target.closest('[data-usage]');
    if (r && confirm(t('admin.revoke_confirm'))) { await send('DELETE', 'keys/' + r.dataset.revoke); await reload(); }
    if (u) {
      try {
        const d = (await admin('keys/' + u.dataset.usage + '/usage')).data;
        usage.innerHTML = tHtml('admin.usage_line', { calls24: `<span class="mono">${fmt(d.last_24h.calls)}</span>`, errors24: `<span class="mono">${fmt(d.last_24h.errors)}</span>`, avg: `<span class="mono">${fmt(d.last_24h.avg_ms)} ms</span>`, calls7: `<span class="mono">${fmt(d.last_7d.calls)}</span>`, errors7: `<span class="mono">${fmt(d.last_7d.errors)}</span>`, endpoints: d.top_endpoints.map(x => `<span class="mono">${esc(x.endpoint)}</span> ×${fmt(x.calls)}`).join(' · ') || t('admin.no_calls') });
      } catch (err) { usage.innerHTML = `<span class="err">${esc(err.message)}</span>`; }
    }
  });
}
