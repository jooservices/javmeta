import { esc, fmt, titleOf, codeLabel, isDerivedCode, toast } from '../util.js';
import { api, send } from '../api.js';
import { myMarks, markBtn, downloadsStatus } from '../marks.js';
import { crumbs, cover, a, href, disk, facetLink, facetChip, performerCard, movieCard, kv, notKnown, errorBox, emptyBox, ic, performerName } from '../components.js';
import { t, formatDate } from '../i18n.js';

export default {
  async render({ params }) {
    const body = await api('movies/' + encodeURIComponent(params.code)).catch(e => ({ error: e }));
    if (body.error) {
      if (body.error.status === 404) return { html: `<div class="wrap" style="overflow-wrap:anywhere">${crumbs([[t('movies.title'), '#/movies'], [params.code]])}${emptyBox(t('movie.not_found'), esc(params.code), a('#/movies', t('common.back_to_movies'), 'btn'))}</div>` };
      return { html: `<div class="wrap" style="overflow-wrap:anywhere">${errorBox(body.error)}</div>` };
    }
    const m = body.data;
    const guestMode = document.body.dataset.guestMode === 'true';
    const marks = await myMarks();
    const dl = await downloadsStatus();
    const performers = m.performers || [];
    const requestedTab = new URLSearchParams(location.hash.split('?')[1] || '').get('tab') || 'meta';
    const tab = requestedTab;
    const tabs = guestMode
      ? [['meta', t('movie.tab.meta')], ['codes', t('movie.tab.codes')]]
      : [['meta', t('movie.tab.meta')], ['media', t('movie.tab.media')], ['codes', t('movie.tab.codes')]];
    const tabsHtml = `<div class="tabs">${tabs.map(tb => `<a class="tab ${tab === tb[0] ? 'on' : ''}" href="#/movie/${encodeURIComponent(m.code)}?tab=${tb[0]}">${tb[1]}</a>`).join('')}</div>`;
    const downloadsPanel = renderDownloadsPanel(m, { guestMode, qbEnabled: Boolean(dl.enabled) });
    let content = '';
    if (tab === 'meta') {
      content = kv([
        [t('movie.field.code'), isDerivedCode(m.code) ? `<span class="mono" title="${esc(m.code)}">${esc(codeLabel(m.code))}</span> <span class="chip warn" title="${t('movie.derived_code_hint')}">${t('movie.derived_code')}</span>` : `<span class="mono">${esc(m.code)}</span>`],
        [t('movie.field.title_en'), m.title_en ? esc(m.title_en) : notKnown()],
        [t('movie.field.title_jp'), m.title_jp ? esc(m.title_jp) : notKnown()],
        [t('movie.field.released'), m.release_date ? `<span class="mono">${esc(m.release_date)}</span> ${a(href.facet('year', m.release_date.slice(0, 4)), esc(m.release_date.slice(0, 4)), 'chip')}` : notKnown()],
        [t('movie.field.runtime'), m.runtime_minutes ? `<span class="mono">${t('movie.minutes', { count: m.runtime_minutes, n: m.runtime_minutes })}</span>` : notKnown()],
        [t('movie.field.maker'), m.maker ? facetLink('maker', m.maker) : notKnown()],
        [t('movie.field.label'), m.label ? facetLink('label', m.label) : notKnown()],
        [t('movie.field.series'), m.series ? facetLink('series', m.series) : `<span class="dim">${t('movie.no_series')}</span>`],
        [t('movie.field.censored'), m.censored === null ? notKnown() : t(m.censored ? 'filter.censored.yes' : 'filter.censored.no')],
        [t('movie.field.score'), m.community_score !== null ? `<span class="mono">★ ${esc(m.community_score)}</span>` : notKnown()],
        [t('movie.field.completeness'), `<span class="mono">${t('movie.tier', { n: m.completeness_tier ?? '—' })}</span>`],
        [t('movie.field.directors'), (m.directors || []).length ? esc(m.directors.join(', ')) : notKnown()],
        [t('movie.field.crawled_at'), `<span class="mono">${esc(m.crawled_at || '—')}</span>`],
        [t('movie.field.description'), m.description ? esc(m.description) : notKnown()],
      ]);
    }
    if (tab === 'media' && guestMode) {
      content = `<p class="guest-login-hint"><a href="/login">${t('movie.media_login')}</a></p>`;
    } else if (tab === 'media') {
      const magnets = m.magnets || [], hls = m.hls_stream_urls || [], gallery = m.gallery || [];
      const row = (label, u) => `<div class="row-i"><div class="grow ell mono" title="${esc(u)}">${esc(u)}</div><button class="btn sm" data-act="copy" data-v="${esc(u)}">${ic('copy')} ${label}</button></div>`;
      content = `<div class="panel"><div class="hd"><h2>${t('movie.magnets')}</h2><span class="chip mono">${magnets.length}</span></div>${magnets.map(x => row(t('common.copy'), typeof x === 'string' ? x : (x.url || ''))).join('') || `<div class="row-i muted">${t('common.none_yet_cap')}</div>`}</div>
        <div class="panel"><div class="hd"><h2>${t('movie.hls')}</h2><span class="chip mono">${hls.length}</span></div>${hls.map(x => row(t('common.copy'), typeof x === 'string' ? x : (x.url || ''))).join('') || `<div class="row-i muted">${t('common.none_yet_cap')}</div>`}</div>
        <div class="panel"><div class="hd"><h2>${t('movie.gallery')}</h2><span class="chip mono">${gallery.length}</span></div><div class="bd"><div class="mgrid S">${gallery.slice(0, 24).map(g => { const u = typeof g === 'string' ? g : (g.url || ''); return u ? `<a href="${esc(u)}" target="_blank" rel="noreferrer noopener" class="th" style="--h:200"><img loading="lazy" referrerpolicy="no-referrer" src="${esc(u)}" alt="" onerror="this.remove()"></a>` : ''; }).join('') || `<span class="muted">${t('common.none_yet_cap')}</span>`}</div></div></div>`;
    }
    if (tab === 'codes') {
      content = `<div class="panel tbl-wrap"><table><thead><tr><th>${t('movie.field.code')}</th><th>${t('movie.codes.kind')}</th></tr></thead><tbody>${(m.codes || []).map(c => `<tr><td class="mono">${esc(c.code || c.value || JSON.stringify(c))}</td><td>${esc(c.kind || '')}</td></tr>`).join('') || `<tr><td colspan="2" class="muted">${t('common.none_yet_cap')}</td></tr>`}</tbody></table></div>
        <div class="panel"><div class="hd"><h2>${t('movie.performer_sources')}</h2></div>${performers.flatMap(p => (p.sources || []).map(s => `<div class="row-i"><span class="dot"></span><div class="grow">${esc(performerName(p))} <span class="muted">· ${esc(s.source_slug)}</span></div><a class="btn sm" href="${esc(s.source_url)}" target="_blank" rel="noreferrer noopener">${ic('link')} ${t('common.open_page')}</a></div>`)).join('') || `<div class="row-i muted">${t('common.none_yet_cap')}</div>`}</div>
        <div class="panel"><div class="hd"><h2>${t('movie.attrs')}</h2></div><div class="bd mono" style="font-size:12px;white-space:pre-wrap;word-break:break-word">${esc(JSON.stringify(m.attrs || {}, null, 2))}</div></div>`;
    }
    const html = `<div class="wrap" style="overflow-wrap:anywhere">${crumbs([[t('movies.title'), '#/movies'], ...(m.maker ? [[m.maker, href.facet('maker', m.maker)]] : []), [codeLabel(m.code)]])}
      <div class="cols detail"><div class="col">${cover(m, { dur: true, zoom: true })}</div>
      <div class="col"><div><div class="mono muted">${esc(codeLabel(m.code))}${m.release_date ? ' · ' + esc(m.release_date) : ''}${m.needs_review ? ` · <span class="chip warn">${t('movie.needs_review')}</span>` : ''}${m.delisted_at ? ` · <span class="chip bad">${t('movie.delisted')}</span>` : ''}</div><h1 style="font-size:22px">${esc(titleOf(m))}</h1></div>
        <div class="chips">${(m.genres || []).map(g => facetChip('genre', g)).join('')}${m.series ? facetChip('series', m.series) : ''}${m.maker ? facetChip('maker', m.maker) : ''}${m.runtime_minutes ? `<span class="chip mono">${m.runtime_minutes}′</span>` : ''}${m.community_score !== null ? `<span class="chip mono">★ ${esc(m.community_score)}</span>` : ''}</div>
        <div class="agrid S">${performers.map((p, i) => performerCard(p, i)).join('') || `<span class="muted">${t('movie.no_performers_yet')}</span>`}</div>
        <div class="ibtns">${disk.codes.has(m.code) ? `<a class="btn pri" href="#/player/${encodeURIComponent(m.code)}">${t('movie.play')}</a>` : ''}<button class="btn" data-act="copy" data-v="${esc(m.code)}">${ic('copy')} ${t(isDerivedCode(m.code) ? 'movie.copy_internal_code' : 'movie.copy_code')}</button>${markBtn(marks, 'favourite', 'movie', m.code, t('movie.mark.favourite_on'), t('movie.mark.favourite_off'))}${markBtn(marks, 'watch_later', 'movie', m.code, t('movie.mark.watch_later_on'), t('movie.mark.watch_later_off'))}${markBtn(marks, 'seen', 'movie', m.code, t('movie.mark.seen_on'), t('movie.mark.seen_off'))}${!guestMode && dl.enabled && (m.magnets || []).length ? `<button class="btn sm" data-dl="${esc(m.code)}" aria-label="${esc(t('movie.downloads.send_qb'))}">${t('movie.download')}</button>` : ''}</div>
        ${downloadsPanel}
        <div class="panel">${tabsHtml}<div class="bd">${content}</div></div></div></div>
      <div class="panel" id="related"><div class="hd"><h2>${t('movie.related')}</h2><span class="dim">${t('movie.related_sub')}</span></div><div class="bd"><div class="strip" id="relstrip"><span class="loader"><i></i><i></i><i></i></span></div></div></div></div>`;
    return {
      html,
      mount: root => {
        loadRelated(m, root);
        wireDownloadsPanel(root, m);
      },
    };
  },
};

function magnetUrl(item) {
  return typeof item === 'string' ? item : (item?.url || '');
}

function downloadsStatusLine(movie) {
  const summary = movie.downloads || {};
  const magnets = movie.magnets || [];
  const count = summary.count ?? magnets.length;
  const state = summary.state || (count > 0 ? 'found' : 'idle');
  if (state === 'searching') return t('movie.downloads.searching');
  if (state === 'failed') return t('movie.downloads.failed');
  if (state === 'not_found') {
    return t('movie.downloads.not_found', {
      checked: formatDate(summary.last_checked_at) || '—',
      retry: formatDate(summary.cooldown_until) || '—',
    });
  }
  if (count > 0) return t('movie.downloads.available_count', { count });
  return t('movie.downloads.empty');
}

function renderDownloadsPanel(movie, { guestMode, qbEnabled }) {
  const magnets = (movie.magnets || []).map(magnetUrl).filter(Boolean);
  const summary = movie.downloads || {};
  const searchEnabled = summary.search_enabled !== false;
  const state = summary.state || (magnets.length ? 'found' : 'idle');
  const rows = magnets.map(url => `<div class="row-i"><div class="grow ell mono" title="${esc(url)}">${esc(url)}</div>
    <button class="btn sm" data-act="copy" data-v="${esc(url)}" aria-label="${esc(t('movie.downloads.copy'))}">${ic('copy')} ${esc(t('movie.downloads.copy'))}</button>
    <a class="btn sm" href="${esc(url)}">${esc(t('movie.downloads.open'))}</a>
    ${!guestMode && qbEnabled ? `<button class="btn sm" data-dl="${esc(movie.code)}" data-magnet="${esc(url)}" aria-label="${esc(t('movie.downloads.send_qb'))}">${esc(t('movie.downloads.send_qb'))}</button>` : ''}
  </div>`).join('');
  const findButton = searchEnabled && state !== 'searching'
    ? `<button class="btn sm" data-download-search="${esc(movie.code)}" aria-label="${esc(t('movie.downloads.find'))}">${esc(t('movie.downloads.find'))}</button>`
    : '';
  const guestHint = guestMode ? `<p class="muted">${esc(t('movie.downloads.login_hint'))}</p>` : '';

  return `<div class="panel" id="movie-downloads" role="region" aria-label="${esc(t('movie.downloads.panel_aria'))}">
    <div class="hd"><h2>${esc(t('movie.downloads.title'))}</h2><span class="chip mono" data-download-count>${magnets.length}</span></div>
    <div class="bd">
      <p data-download-status>${esc(downloadsStatusLine(movie))}</p>
      ${rows || `<div class="row-i muted">${esc(t('movie.downloads.empty'))}</div>`}
      <div class="ibtns">${findButton}</div>
      ${guestHint}
    </div>
  </div>`;
}

function wireDownloadsPanel(root, movie) {
  const panel = root.querySelector('#movie-downloads');
  if (!panel) return;
  panel.addEventListener('click', async event => {
    const button = event.target.closest('[data-download-search]');
    if (!button) return;
    button.disabled = true;
    const status = panel.querySelector('[data-download-status]');
    if (status) status.textContent = t('movie.downloads.searching');
    try {
      const result = await send('POST', '/ui-api/movies/' + encodeURIComponent(movie.code) + '/downloads/search');
      const summary = result?.data?.downloads || {};
      if (status) {
        if (summary.state === 'searching') status.textContent = t('movie.downloads.searching');
        else if (summary.state === 'not_found') status.textContent = t('movie.downloads.not_found', {
          checked: formatDate(summary.last_checked_at) || '—',
          retry: formatDate(summary.cooldown_until) || '—',
        });
        else if (result?.data?.reason === 'budget' || result?.data?.reason === 'cooldown') status.textContent = t('movie.downloads.budget');
        else if (summary.count > 0) status.textContent = t('movie.downloads.available_count', { count: summary.count });
        else status.textContent = result?.message || t('movie.downloads.empty');
      }
      if (summary.state === 'searching') {
        // Soft refresh so found magnets appear once the job finishes.
        setTimeout(() => { location.hash = location.hash; }, 2500);
      } else if ((summary.count || 0) > 0) {
        location.hash = location.hash;
      }
    } catch (error) {
      if (status) status.textContent = error.status === 429 ? t('movie.downloads.budget') : (error.message || t('movie.downloads.failed'));
      toast(error.message || t('movie.downloads.failed'));
    } finally {
      button.disabled = false;
    }
  });
}

async function loadRelated(m, root) {
  const strip = root.querySelector('#relstrip');
  try {
    const r = await api('movies/' + encodeURIComponent(m.code) + '/related');
    strip.innerHTML = (r.data || []).map((x, i) => movieCard(x, i)).join('') || `<span class="muted">${t('movie.no_related')}</span>`;
  } catch (e) {
    strip.innerHTML = `<span class="err">${esc(e.message)}</span>`;
  }
}
