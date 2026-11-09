<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ __('operations.title') }}</title>
  <style>
    :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #0d141b; color: #edf4f2; --bg:#0d141b; --panel:#17242b; --line:#315059; --muted:#9eb2b7; --accent:#67cfba; --warn:#f4bd67; --bad:#f48282; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; }
    button { font: inherit; cursor: pointer; }
    header { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; padding: 15px 28px; border-bottom: 1px solid var(--line); background: #132028; }
    header strong { font-size: 20px; letter-spacing: -.04em; }
    .brand-mark { display: inline-grid; place-items: center; width: 33px; height: 33px; border-radius: 9px; background: var(--accent); color: #09241f; font-weight: 900; }
    .brand { display: flex; gap: 10px; align-items: center; }
    .spacer { flex: 1; }
    .live { color: var(--accent); font-size: 13px; font-weight: 700; }
    .live.warn { color: var(--warn); }
    .live.bad { color: var(--bad); }
    .live::before { content: ''; display: inline-block; width: 7px; height: 7px; margin-right: 7px; border-radius: 50%; background: currentColor; }
    .logout { border: 1px solid var(--line); background: transparent; color: #edf4f2; border-radius: 8px; padding: 8px 12px; }
    .locale-switch { display: flex; overflow: hidden; border: 1px solid var(--line); border-radius: 8px; }
    .locale-switch a { padding: 8px 10px; color: var(--muted); text-decoration: none; font-size: 12px; font-weight: 800; }
    .locale-switch a[aria-current="true"] { color: #09241f; background: var(--accent); }
    .locale-switch a:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
    main { max-width: 1480px; margin: auto; padding: 30px 28px 60px; }
    .title { display: flex; justify-content: space-between; flex-wrap: wrap; align-items: end; gap: 12px; margin-bottom: 22px; }
    .eyebrow { color: var(--accent); text-transform: uppercase; letter-spacing: .15em; font-size: 11px; font-weight: 800; }
    h1 { margin: 7px 0 4px; font-size: clamp(25px, 4vw, 36px); letter-spacing: -.05em; }
    p { margin: 0; color: var(--muted); }
    .asof { color: var(--muted); font-size: 12px; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; margin-bottom: 21px; }
    .kpi, .panel { border: 1px solid var(--line); background: var(--panel); border-radius: 13px; }
    .kpi { padding: 18px; min-height: 113px; }
    .kpi .label { color: var(--muted); text-transform: uppercase; font-size: 11px; font-weight: 800; letter-spacing: .08em; }
    .kpi strong { display: block; margin: 7px 0 4px; font-size: 32px; line-height: 1; font-variant-numeric: tabular-nums; letter-spacing: -.05em; }
    .kpi small { color: var(--muted); }
    .kpi.problem strong { color: var(--warn); }
    .grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(300px, 1fr); gap: 18px; }
    .panel { padding: 19px; min-width: 0; margin-bottom: 18px; }
    .panel h2 { font-size: 17px; margin: 0 0 5px; letter-spacing: -.02em; }
    .panel .hint { font-size: 12px; margin-bottom: 14px; }
    .source-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(225px, 1fr)); gap: 10px; }
    .source { border: 1px solid #3c535a; background: #102029; border-radius: 10px; padding: 14px; }
    .source-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    .source strong { font-size: 16px; }
    .pill { border-radius: 20px; padding: 4px 8px; color: var(--accent); background: #164236; font-size: 11px; font-weight: 800; }
    .pill.attention { background: #493820; color: var(--warn); }
    .source p { margin-top: 9px; font-size: 12px; }
    .source .counts { color: #dce7e6; margin-top: 13px; font-size: 12px; font-variant-numeric: tabular-nums; }
    .metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 9px; }
    .metric { background: #102029; border: 1px solid #3c535a; border-radius: 9px; padding: 11px; }
    .metric b { display: block; margin-top: 5px; font-size: 21px; font-variant-numeric: tabular-nums; }
    .metric span { color: var(--muted); font-size: 11px; }
    .rows { display: grid; gap: 0; }
    .row { display: grid; grid-template-columns: 104px minmax(110px, 1.3fr) minmax(0, 1fr); gap: 10px; align-items: start; padding: 10px 0; border-top: 1px solid #2e424a; font-size: 12px; }
    .row b { color: #f3d1a5; }
    .row span { min-width: 0; overflow-wrap: anywhere; }
    .row span:last-child { color: var(--muted); }
    .empty { color: var(--muted); padding: 15px 0; font-size: 13px; }
    .error-box { border: 1px solid #a75658; border-radius: 10px; background: #4a242b; color: #ffd8d9; padding: 15px; margin-bottom: 18px; }
    .nodes-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; margin-bottom: 14px; }
    .nodes-form label { display: grid; gap: 5px; color: var(--muted); font-size: 12px; }
    .nodes-form input, .nodes-form select { background: #0d141b; color: #edf4f2; border: 1px solid var(--line); border-radius: 8px; padding: 9px 11px; font: inherit; min-width: 200px; }
    .nodes-form input:focus, .nodes-form select:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(103,207,186,.18); }
    .action { border: 1px solid var(--accent); background: var(--accent); color: #09241f; border-radius: 8px; padding: 9px 14px; font-weight: 700; }
    .action:disabled { opacity: .5; cursor: default; }
    .action.ghost { background: transparent; color: var(--accent); }
    .join-box { border: 1px dashed var(--accent); border-radius: 10px; padding: 14px; margin-bottom: 14px; background: #102029; }
    .join-box code { display: block; overflow-wrap: anywhere; white-space: pre-wrap; font-size: 12.5px; margin: 8px 0 10px; color: #dff7f1; }
    .join-box .steps { margin: 0 0 10px 18px; padding: 0; color: var(--muted); font-size: 13px; }
    .node-row { display: grid; grid-template-columns: minmax(0, 1.2fr) 90px 100px minmax(0, 1fr); gap: 10px; padding: 10px 0; border-top: 1px solid #2e424a; font-size: 12px; align-items: center; }
    .badge-used { color: var(--accent); } .badge-expired { color: var(--muted); } .badge-pending { color: var(--warn); }
    a { color: var(--accent); }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    @media (max-width: 550px) { header, main { padding-left: 15px; padding-right: 15px; } .metrics { grid-template-columns: repeat(2, 1fr); } .row { grid-template-columns: 82px 78px minmax(0, 1fr); } }
  </style>
</head>
<body>
<header>
  <div class="brand"><span class="brand-mark">J</span><strong>JavMeta</strong></div>
  <span class="eyebrow">{{ __('operations.operations') }}</span>
  <span class="spacer"></span>
  <span id="live" class="live warn" role="status">{{ __('operations.live.connecting') }}</span>
  <div class="locale-switch" role="group" aria-label="{{ __('operations.language.switcher') }}">
    @foreach (['vi', 'en'] as $language)
      <a href="{{ request()->fullUrlWithQuery(['lang' => $language]) }}" lang="{{ $language }}" title="{{ __('operations.language.' . $language) }}" @if (app()->getLocale() === $language) aria-current="true" @endif>{{ strtoupper($language) }}</a>
    @endforeach
  </div>
  <form method="post" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">{{ __('operations.sign_out') }}</button></form>
</header>
<main>
  <div class="title"><div><div class="eyebrow">{{ __('operations.command_center') }}</div><h1>{{ __('operations.crawl_operations') }}</h1><p>{{ __('operations.intro') }}</p></div><time id="asof" class="asof">{{ __('operations.live.waiting') }}</time></div>
  <div id="error" class="error-box" role="alert" hidden></div>
  <section class="kpis" aria-label="{{ __('operations.totals.aria_label') }}">
    <div class="kpi"><span class="label">{{ __('operations.totals.videos') }}</span><strong id="movies">—</strong><small id="movies-hour">—</small></div>
    <div class="kpi"><span class="label">{{ __('operations.totals.performers') }}</span><strong id="performers">—</strong><small>{{ __('operations.totals.unique_records') }}</small></div>
    <div class="kpi"><span class="label">{{ __('operations.totals.queue_complete') }}</span><strong id="done">—</strong><small>{{ __('operations.totals.all_time') }}</small></div>
    <div class="kpi"><span class="label">{{ __('operations.totals.interface_access') }}</span><strong id="ui-login-mode">—</strong><small id="ui-login-detail">—</small></div>
    <div class="kpi"><span class="label">{{ __('operations.totals.queue_pending') }}</span><strong id="pending">—</strong><small id="claimed">—</small></div>
    <div class="kpi problem"><span class="label">{{ __('operations.totals.failed_items') }}</span><strong id="failed">—</strong><small id="failed-jobs">—</small></div>
  </section>
  <div class="grid">
    <div>
      <section class="panel"><h2>{{ __('operations.source.title') }}</h2><p class="hint">{{ __('operations.source.hint') }}</p><div id="sources" class="source-grid"><div class="empty">{{ __('operations.source.loading') }}</div></div></section>
      <section class="panel"><h2>{{ __('operations.issues.title') }}</h2><p class="hint">{{ __('operations.issues.hint') }}</p><div id="issues" class="rows"><div class="empty">{{ __('operations.issues.loading') }}</div></div></section>
    </div>
    <div>
      <section class="panel"><h2>{{ __('operations.performance.title') }}</h2><p class="hint">{{ __('operations.performance.hint') }}</p><div id="performance" class="metrics"></div></section>
      <section class="panel"><h2>{{ __('operations.activity.title') }}</h2><p class="hint">{{ __('operations.activity.hint') }}</p><div id="events" class="rows"></div></section>
    </div>
  </div>
  <section class="panel" id="nodes" aria-labelledby="nodes-title">
    <h2 id="nodes-title">{{ __('operations.nodes.title') }}</h2>
    <p class="hint">{{ __('operations.nodes.hint') }}</p>
    <form class="nodes-form" id="node-form">
      <label>{{ __('operations.nodes.name') }}<input id="node-name" name="node_name" required minlength="3" maxlength="64" pattern="[a-z][a-z0-9\-]*" placeholder="{{ __('operations.nodes.name_placeholder') }}" autocomplete="off"></label>
      <label>{{ __('operations.nodes.role') }}<select id="node-role" name="role"><option value="worker">{{ __('operations.nodes.role_worker') }}</option></select></label>
      <button class="action" id="node-create" type="submit">{{ __('operations.nodes.create_join_command') }}</button>
    </form>
    <div id="node-error" class="error-box" role="alert" hidden></div>
    <div id="join-box" class="join-box" hidden aria-live="polite">
      <strong>{{ __('operations.nodes.join_as_sudo') }}</strong>
      <ol class="steps"><li>{{ __('operations.nodes.step_single_use', ['when' => '']) }}<span id="join-expiry">{{ __('operations.nodes.expiry_soon') }}</span>.</li><li>{{ __('operations.nodes.step_installs') }}</li><li>{{ __('operations.nodes.step_appears', ['status' => __('operations.nodes.status.used')]) }}</li></ol>
      <code id="join-command"></code>
      <button class="action ghost" id="join-copy" type="button">{{ __('operations.copy_command') }}</button> <span id="join-copied" class="hint" role="status"></span>
    </div>
    <div id="node-list" class="rows"><div class="empty">{{ __('operations.nodes.loading') }}</div></div>
  </section>
</main>
<script>
(() => {
  const endpoint = @json(route('operations.data'));
  const loginUrl = @json(route('login', ['lang' => app()->getLocale()]));
  const messages = @json(__('operations'));
  const locale = @json(app()->getLocale());
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const t = (key, values = {}) => {
    const message = key.split('.').reduce((value, part) => value?.[part], messages);
    if (typeof message !== 'string') return key;
    return Object.entries(values).reduce((result, [name, value]) => result.replaceAll(`:${name}`, String(value)), message);
  };
  const numberFormat = new Intl.NumberFormat(locale);
  const dateTimeFormat = new Intl.DateTimeFormat(locale, {dateStyle: 'medium', timeStyle: 'short'});
  const number = value => numberFormat.format(Number(value || 0));
  const age = seconds => {
    if (seconds == null) return t('time.never');
    if (seconds < 60) return t('time.seconds_ago', {count: number(Math.floor(seconds))});
    if (seconds < 3600) return t('time.minutes_ago', {count: number(Math.floor(seconds / 60))});
    return t('time.hours_ago', {count: number(Math.floor(seconds / 3600))});
  };
  const time = value => value ? dateTimeFormat.format(new Date(value)) : '—';
  const translated = (key, fallback) => {
    const value = t(key);
    return value === key ? fallback : value;
  };
  let previous = null;
  async function refresh() {
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store'});
      if (response.status === 401 || response.redirected) { window.location.href = loginUrl; return; }
      if (!response.ok) throw new Error(t('errors.data_request', {status: response.status}));
      const data = await response.json();
      $('error').hidden = true;
      $('live').className = 'live'; $('live').textContent = t('live.live');
      $('asof').dateTime = data.as_of;
      $('asof').textContent = t('live.updated', {time: time(data.as_of), seconds: number(15)});
      ['movies','performers'].forEach(key => { $(key).textContent = number(data.totals[key]); });
      $('movies-hour').textContent = t('totals.added_one_hour', {count: number(data.totals.movies_first_seen_1h)});
      $('ui-login-mode').textContent = data.ui_login_required ? t('login.required') : t('login.guest');
      $('ui-login-detail').textContent = data.ui_login_required ? t('login.owner_required') : t('login.public_read_only');
      ['done','pending','failed'].forEach(key => { $(key).textContent = number(data.queue[key]); });
      $('claimed').textContent = t('totals.in_progress', {count: number(data.queue.claimed)});
      $('failed-jobs').textContent = t('totals.failed_jobs', {count: number(data.totals.failed_jobs)});
      $('sources').innerHTML = data.sources.map(source => {
        const statusKey = `source.status.${source.status}`;
        const status = translated(statusKey, source.status);
        const queueCounts = t('source.queue_counts', {
          done: number(source.queue.done), pending: number(source.queue.pending),
          active: number(source.queue.claimed), failed: number(source.queue.failed),
        });
        const latestError = source.last_error ? t('source.latest_error', {error: source.last_error}) : '';
        return `<article class="source"><div class="source-head"><strong>${esc(source.name || source.slug)}</strong><span class="pill ${source.status === 'healthy' ? '' : 'attention'}">${esc(status)}</span></div><p>${esc(t('source.last_success', {age: age(source.last_success_age_seconds)}))}</p><div class="counts">${esc(queueCounts)}</div>${source.last_error ? `<p title="${esc(latestError)}">${esc(latestError)}</p>` : ''}</article>`;
      }).join('') || `<div class="empty">${t('source.empty')}</div>`;
      const metric = (label, value) => `<div class="metric"><span>${label}</span><b>${number(value)}</b></div>`;
      $('performance').innerHTML = metric(t('performance.runs'), data.hour.runs) + metric(t('performance.pages_fetched'), data.hour.pages) + metric(t('performance.new_videos'), data.hour.movies_new) + metric(t('performance.updated_videos'), data.hour.movies_updated) + metric(t('performance.fetch_failures'), data.hour.failures) + metric(t('performance.failed_jobs'), data.totals.failed_jobs);
      $('issues').innerHTML = data.issues.map(item => `<div class="row"><b>${esc(item.source)}</b><span>${esc(item.code)}</span><span>${esc(item.kind)} · ${esc(t('issues.attempts', {count: number(item.attempts)}))}${item.detail ? ' · ' + esc(item.detail) : ''}</span></div>`).join('') || `<div class="empty">${t('issues.empty')}</div>`;
      $('events').innerHTML = data.events.map(item => `<div class="row"><b>${esc(item.source)}</b><span>${esc(item.kind)}</span><span>${esc(time(item.at))}</span></div>`).join('') || `<div class="empty">${t('activity.empty')}</div>`;
      previous = data.as_of;
    } catch (error) {
      $('live').className = 'live bad'; $('live').textContent = t('live.unavailable');
      $('error').textContent = t('errors.refresh_failed', {error: error.message});
      $('error').hidden = false;
      if (previous) { $('asof').dateTime = previous; $('asof').textContent = t('live.last_good_update', {time: time(previous)}); }
    }
  }
  refresh(); setInterval(refresh, 15000);

  const csrf = @json(csrf_token());
  const nodesEndpoint = @json(route('operations.nodes.index'));
  async function loadNodes() {
    try {
      const response = await fetch(nodesEndpoint, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
      if (!response.ok) throw new Error(t('nodes.request_failed', {status: response.status}));
      const rows = (await response.json()).data;
      $('node-list').innerHTML = rows.map(row => {
        const status = translated(`nodes.status.${row.status}`, row.status);
        const role = row.role === 'worker' ? t('nodes.role_worker') : row.role;
        const timing = row.status === 'used'
          ? t('nodes.used_at', {time: time(row.used_at)})
          : t('nodes.expires_at', {time: time(row.expires_at)});
        return `<div class="node-row"><b>${esc(row.node_name)}</b><span>${esc(role)}</span><span class="badge-${esc(row.status)}">${esc(status)}</span><span>${esc(timing)}</span></div>`;
      }).join('') || `<div class="empty">${t('nodes.empty')}</div>`;
    } catch (error) {
      $('node-list').innerHTML = `<div class="empty">${esc(t('nodes.load_failed', {error: error.message}))}</div>`;
    }
  }
  $('node-form').addEventListener('submit', async event => {
    event.preventDefault();
    const button = $('node-create'); button.disabled = true; $('node-error').hidden = true;
    try {
      const response = await fetch(nodesEndpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({node_name: $('node-name').value.trim(), role: $('node-role').value})});
      const body = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(Object.values(body.errors || {}).flat().join(' ') || body.message || t('errors.request', {status: response.status}));
      $('join-command').textContent = body.data.command;
      $('join-expiry').textContent = t('time.at', {time: time(body.data.expires_at)});
      $('join-copied').textContent = '';
      $('join-box').hidden = false;
      loadNodes();
    } catch (error) {
      $('node-error').textContent = error.message; $('node-error').hidden = false;
    } finally { button.disabled = false; }
  });
  $('join-copy').addEventListener('click', async () => {
    const text = $('join-command').textContent;
    try { await navigator.clipboard.writeText(text); $('join-copied').textContent = t('copied'); }
    catch { const range = document.createRange(); range.selectNodeContents($('join-command')); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); $('join-copied').textContent = t('copy_shortcut'); }
  });
  loadNodes(); setInterval(loadNodes, 15000);
})();
</script>
</body>
</html>
