import { esc, fold } from '../util.js';
import { head, ic } from '../components.js';
import { admin, send } from '../api.js';
import { askConfirm } from '../confirm.js';
import { applyTheme, applyDensity, currentTheme, currentDensity } from '../theme.js';
import { t, html as tHtml, currentLocale, setLocale, SUPPORTED } from '../i18n.js';
import { optionLabel } from './report.js';

const shortcutMod = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '') ? '⌘' : 'Ctrl';
const keys = () => [[t('settings.shortcut.search_keys', { mod: shortcutMod }), t('help.search')], ['t', t('help.theme')], ['Esc', t('help.close')], ['?', t('help.shortcuts')]];

/* Proposal: the long page becomes tabs; each tab holds the related groups as sections. */
const TABS = [
  ['personal', 'settings.tab.personal', 'user', null],
  ['crawl', 'settings.tab.crawl', 'spider', ['queue', 'worker', 'browser', 'retry', 'crawl', 'cleanup', 'timeouts']],
  ['notify', 'settings.tab.notify', 'bell', ['alerts', 'telegram', 'report', 'follow_notifications']],
  ['health', 'settings.tab.health', 'clock', ['readiness', 'observability', 'stats', 'startup_health', 'backup']],
  ['search', 'settings.tab.search', 'search', ['search', 'ai', 'normalization']],
  ['media', 'settings.tab.media', 'film', ['library', 'downloads', 'files', 'media', 'capture', 'dataset']],
  ['system', 'settings.tab.system', 'gear', ['domains', 'ui_security']],
];
const CRAWL_SUBS = [
  ['queue', 'settings.subtab.queue', ['queue', 'cleanup']],
  ['worker', 'settings.subtab.worker', ['worker', 'browser', 'retry']],
  ['loop', 'settings.subtab.loop', ['crawl', 'timeouts']],
];
const tabStore = { get: () => { try { return sessionStorage.getItem('settings-tab'); } catch { return null; } }, set: v => { try { sessionStorage.setItem('settings-tab', v); } catch { /* ignore */ } } };

export default {
  async render() {
    const theme = currentTheme(), dens = currentDensity();
    let sys = null;
    try { sys = (await admin('/ui-admin/settings')).data; } catch { /* the personal settings still work */ }
    const row = (title, d, c) => `<div class="row-i"><div class="grow"><b>${title}</b><div class="muted">${d}</div></div>${c}</div>`;
    const active = TABS.some(tab => tab[0] === tabStore.get()) ? tabStore.get() : 'personal';
    const known = new Set(TABS.flatMap(tab => tab[3] || []));
    const pane = (id, inner) => `<section class="pane" data-pane="${id}" ${id === active ? '' : 'hidden'}>${inner}</section>`;
    const tabs = `<div class="tabs" role="tablist" style="margin-bottom:12px">${TABS.map(tab => `<button type="button" class="tab ${tab[0] === active ? 'on' : ''}" role="tab" data-tab="${tab[0]}">${ic(tab[2])}${t(tab[1])}</button>`).join('')}</div>`;
    const html = `<div class="wrap">${head(t('nav.settings'), t('settings.sub'))}<label class="f" style="max-width:360px"><span>${t('settings.filter')}</span><input type="search" data-settings-filter placeholder="${t('settings.filter_placeholder')}" aria-label="${t('settings.filter')}" autocomplete="off"></label>${tabs}
      ${pane('personal', `<div class="grid g2">
      <div class="panel"><div class="hd"><h2>${t('settings.appearance')}</h2></div>
        ${row(t('settings.theme'), t('settings.theme_hint'), `<div class="seg">${['dark', 'light', 'system'].map(mode => `<button class="${theme === mode ? 'on' : ''}" data-act="settheme" data-v="${mode}">${t('settings.theme.' + mode)}</button>`).join('')}</div>`)}
        ${row(t('settings.density'), t('settings.density_hint'), `<div class="seg">${['normal', 'compact'].map(mode => `<button class="${dens === mode ? 'on' : ''}" data-act="setdens" data-v="${mode}">${t('settings.density.' + mode)}</button>`).join('')}</div>`)}</div>
      <div class="panel"><div class="hd"><h2>${t('settings.system')}</h2></div>
        ${row(t('settings.notifications'), t('settings.notifications_hint'), `<a class="btn sm" href="#/settings/notifications">${ic('edit')}${t('settings.notifications_open')}</a>`)}
        ${row(t('settings.report'), t('settings.report_hint'), `<a class="btn sm" href="#/settings/report">${ic('send')}${t('settings.report_open')}</a>`)}
        ${row(t('settings.ops'), t('settings.ops_hint'), `<a class="btn sm" href="${esc(document.querySelector('meta[name=command-center-url]')?.content || '/ops')}">${ic('ext')}${t('settings.ops_open')}</a>`)}
        ${row(t('settings.logout'), t('settings.logout_hint'), `<form method="post" action="/logout" class="logout-form"><input type="hidden" name="_token" value="${esc(document.querySelector('meta[name=csrf-token]')?.content || '')}"><button class="btn sm" type="submit">${ic('logout')}${t('settings.logout')}</button></form>`)}
        ${row(t('settings.api'), t('settings.api_hint'), '<span class="chip ok">/api/v1</span>')}</div>
      <div class="panel" style="grid-column:1/-1"><div class="hd"><h2>${t('help.title')}</h2></div><div class="bd"><dl class="kv" style="grid-template-columns:130px 1fr">${keys().map(k => `<dt><kbd>${k[0]}</kbd></dt><dd>${k[1]}</dd>`).join('')}</dl></div></div></div></div>`)}
      ${sys ? TABS.filter(tab => tab[3]).map(tab => pane(tab[0], tab[0] === 'crawl' ? crawlPanels(sys, tab[3]) : systemPanels(sys, tab[0] === 'system' ? [...tab[3], ...Object.keys(sys.groups).filter(g => !known.has(g))] : tab[3]))).join('') : ''}</div>`;
    return {
      html,
      mount: root => { mountSystem(root); mountSettingsFilter(root); root.querySelector('.logout-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        if (await askConfirm({ title: t('settings.logout'), body: t('settings.logout_confirm'), confirmLabel: t('settings.logout'), danger: true })) e.target.submit();
      }); root.addEventListener('click', e => {
        const sub = e.target.closest('[data-subtab]');
        if (sub) {
          const host = sub.closest('[data-pane]');
          host.querySelectorAll('[data-subtab]').forEach(b => b.classList.toggle('on', b === sub));
          host.querySelectorAll('[data-subpane]').forEach(p => { p.hidden = p.dataset.subpane !== sub.dataset.subtab; });
          return;
        }
        const tab = e.target.closest('[data-tab]');
        if (tab) { tabStore.set(tab.dataset.tab); root.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('on', b === tab)); root.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== tab.dataset.tab; }); return; }
        const btn = e.target.closest('[data-act]'); if (!btn) return;
        if (btn.dataset.act === 'settheme') { applyTheme(btn.dataset.v); location.reload(); }
        if (btn.dataset.act === 'setdens') { applyDensity(btn.dataset.v); location.reload(); }
      }); },
    };
  },
};

const input = r => {
  const id = 'st-' + r.key;
  if (r.type === 'bool') return `<label class="switch"><input type="checkbox" id="${id}" data-key="${esc(r.key)}" data-type="bool" ${r.value ? 'checked' : ''}><span class="track"></span><span class="txt">${t(r.value ? 'common.on' : 'common.off')}</span></label>`;
  if (r.type === 'secret') return `<input type="password" id="${id}" data-key="${esc(r.key)}" data-type="secret" autocomplete="new-password" placeholder="${t(r.is_set ? 'settings.secret.set' : 'settings.secret.unset')}" style="min-width:240px">`;
  if (r.multiline) return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="string" rows="6" maxlength="${r.max_length || 500}" style="min-width:min(100%,420px)">${esc(r.value || '')}</textarea>`;
  if (r.type === 'string_list' && Array.isArray(r.item_options) && r.item_options.length) return `<div role="group" aria-label="${esc(r.label)}">${r.item_options.map(option => `<label class="switch"><input type="checkbox" data-key="${esc(r.key)}" data-type="string_list" value="${esc(option)}" ${(r.value || []).includes(option) ? 'checked' : ''}><span class="track"></span> <span>${esc(settingOptionLabel(r.group, option))}</span></label>`).join('')}</div>`;
  if (r.type === 'string_list') return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="string_list" rows="3" style="min-width:300px">${esc((r.value || []).join('\n'))}</textarea>`;
  if (Array.isArray(r.options) && r.options.length > 0) return `<select id="${id}" data-key="${esc(r.key)}" data-type="${esc(r.type)}" style="min-width:140px">${r.options.map(option => `<option value="${esc(option)}"${String(option) === String(r.value) ? ' selected' : ''}>${esc(option)}</option>`).join('')}</select>`;
  if (r.type === 'json') {
    const value = r.warning && typeof r.value === 'string' ? r.value : JSON.stringify(r.value || {}, null, 2);
    return `<textarea id="${id}" data-key="${esc(r.key)}" data-type="json" rows="5" maxlength="${r.max_length || 500}" spellcheck="false" aria-label="${esc(r.label)}" style="min-width:min(100%,420px);font-family:monospace">${esc(value)}</textarea>`;
  }
  const num = r.type === 'int' || r.type === 'float';
  return `<input ${num ? `type="number" step="${r.type === 'float' ? '0.05' : '1'}"` : 'type="text"'} id="${id}" data-key="${esc(r.key)}" data-type="${r.type}" value="${esc(Array.isArray(r.value) ? r.value.join(',') : (r.value ?? ''))}" ${r.min != null ? `min="${r.min}"` : ''} ${r.max != null ? `max="${r.max}"` : ''} style="min-width:140px">`;
};

function crawlPanels(sys, groups) {
  const used = new Set(CRAWL_SUBS.flatMap(s => s[2]));
  const extra = groups.filter(g => !used.has(g));
  const subs = extra.length ? [...CRAWL_SUBS, ['more', 'settings.subtab.more', extra]] : CRAWL_SUBS;
  return `<div class="tabs" role="tablist" data-subtabs="crawl" style="margin-bottom:12px">${subs.map((s, i) => `<button type="button" class="tab ${i === 0 ? 'on' : ''}" data-subtab="${s[0]}">${t(s[1])}</button>`).join('')}</div>`
    + subs.map((s, i) => `<div data-subpane="${s[0]}" ${i === 0 ? '' : 'hidden'}>${systemPanels(sys, s[2])}</div>`).join('');
}

function mountSettingsFilter(root) {
  const input = root.querySelector('[data-settings-filter]');
  if (!input) return;
  input.addEventListener('input', () => {
    const q = fold(input.value.trim());
    root.querySelectorAll('[data-settings-row]').forEach(row => {
      const hay = fold((row.textContent || '') + ' ' + (row.dataset.settingsRow || ''));
      row.hidden = !!(q && !hay.includes(q));
    });
    root.querySelectorAll('[data-group]').forEach(sec => {
      const any = [...sec.querySelectorAll('[data-settings-row]')].some(r => !r.hidden);
      sec.hidden = !!(q && !any);
    });
    if (!q) {
      root.querySelectorAll('[data-subpane]').forEach((p, i) => { p.hidden = i !== 0; });
      return;
    }
    root.querySelectorAll('[data-pane]').forEach(pane => {
      pane.querySelectorAll('[data-subpane]').forEach(p => {
        p.hidden = ![...p.querySelectorAll('[data-settings-row]')].some(r => !r.hidden);
      });
    });
  });
}

/* every feature switch and operational value, stored in the database; grouped, saved per group */
function systemPanels(sys, only) {
  const byGroup = {};
  sys.settings.forEach(r => { (byGroup[r.group] ||= []).push(r); });
  return `<div class="muted" style="margin-bottom:10px"><span class="chip ok">${t('settings.stored_in_db')}</span> ${t('settings.apply_note')}</div>` + '<div class="section-stack">' +
    Object.entries(sys.groups).filter(([g]) => byGroup[g] && (!only || only.includes(g))).map(([g, title]) => `<div class="section" data-group="${esc(g)}"><div class="hd"><h2>${esc(title)}</h2><span class="sp"></span><span class="muted" data-msg></span><button class="btn sm pri" data-save="${esc(g)}">${ic('save')}${t('common.save')}</button></div>` +
      byGroup[g].map(r => `<div class="row-i" data-settings-row="${esc(r.key)}" style="align-items:flex-start"><div class="grow"><b>${esc(r.label)}</b> ${r.source === 'database' ? '<span class="chip ok">DB</span>' : r.source === 'environment' ? `<span class="chip warn">${t('settings.source.env')}</span>` : `<span class="chip warn">${t('settings.source.default')}</span>`}${r.apply_after_seconds > 0 ? ` <span class="chip">${t('settings.apply_after', { count: r.apply_after_seconds, n: r.apply_after_seconds })}</span>` : ''}${r.restart ? ` <span class="chip">${t('settings.restart', { service: r.restart })}</span>` : ''}<div class="muted">${esc(r.description)}</div>${r.warning ? `<div class="err" role="alert">${esc(r.warning)}</div>` : ''}</div><div>${input(r)}</div></div>`).join('') + '</div>').join('') + '</div>';
}

function mountSystem(root) {
  root.addEventListener('change', e => { const el = e.target; if (el.matches?.('.switch input[data-type="bool"]')) el.closest('.switch').querySelector('.txt').textContent = t(el.checked ? 'common.on' : 'common.off'); });
  root.addEventListener('click', async e => {
    const b = e.target.closest('[data-save]'); if (!b) return;
    const panel = b.closest('[data-group]'), msg = panel.querySelector('[data-msg]');
    const settings = {};
    let invalid = '';
    panel.querySelectorAll('[data-key]').forEach(el => {
      const type = el.dataset.type;
      if (type === 'bool') settings[el.dataset.key] = el.checked;
      else if (type === 'string_list' && el.type === 'checkbox') settings[el.dataset.key] = Array.from(panel.querySelectorAll('[data-type="string_list"][type="checkbox"]')).filter(input => input.dataset.key === el.dataset.key && input.checked).map(input => input.value);
      else if (type === 'secret') { if (el.value !== '') settings[el.dataset.key] = el.value; }
      else if (type === 'json') {
        try { settings[el.dataset.key] = JSON.parse(el.value); }
        catch { invalid = tHtml('settings.invalid_json', { label: el.getAttribute('aria-label') }); }
      }
      else settings[el.dataset.key] = el.value;
    });
    if (invalid) { msg.innerHTML = `<span class="err" role="alert">${esc(invalid)}</span>`; return; }
    b.disabled = true; msg.textContent = t('common.saving');
    try {
      const result = await send('PUT', '/ui-admin/settings', { settings });
      // The saved owner language also becomes this browser's choice, so the reload below shows it.
      if (SUPPORTED.includes(settings['ui.locale'])) await setLocale(settings['ui.locale'], { notify: false });
      const delay = Number(result.data?.apply_after_seconds || 30);
      msg.textContent = t('settings.saved_apply', { count: delay, n: delay });
      setTimeout(() => location.reload(), 1500);
    } catch (err) {
      const errs = err.body?.data?.errors; msg.innerHTML = `<span class="err">${esc(errs ? Object.values(errs).join(' · ') : err.message)}</span>`;
    } finally { b.disabled = false; }
  });
}

function settingOptionLabel(group, option) {
  return group === 'report' ? optionLabel(option) : option;
}
