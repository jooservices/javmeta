<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>JavMeta · Operations</title>
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
    .row { display: grid; grid-template-columns: 104px 110px minmax(0, 1fr); gap: 10px; align-items: start; padding: 10px 0; border-top: 1px solid #2e424a; font-size: 12px; }
    .row b { color: #f3d1a5; }
    .row span:last-child { color: var(--muted); overflow-wrap: anywhere; }
    .empty { color: var(--muted); padding: 15px 0; font-size: 13px; }
    .error-box { border: 1px solid #a75658; border-radius: 10px; background: #4a242b; color: #ffd8d9; padding: 15px; margin-bottom: 18px; }
    a { color: var(--accent); }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    @media (max-width: 550px) { header, main { padding-left: 15px; padding-right: 15px; } .metrics { grid-template-columns: repeat(2, 1fr); } .row { grid-template-columns: 82px 78px minmax(0, 1fr); } }
  </style>
</head>
<body>
<header>
  <div class="brand"><span class="brand-mark">J</span><strong>JavMeta</strong></div>
  <span class="eyebrow">Operations</span>
  <span class="spacer"></span>
  <span id="live" class="live warn" role="status">Connecting</span>
  <form method="post" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Sign out</button></form>
</header>
<main>
  <div class="title"><div><div class="eyebrow">Command Center</div><h1>Crawl operations</h1><p>Live progress, source health, performance and issues.</p></div><div id="asof" class="asof">Waiting for data</div></div>
  <div id="error" class="error-box" role="alert" hidden></div>
  <section class="kpis" aria-label="Catalogue and queue totals">
    <div class="kpi"><span class="label">Videos</span><strong id="movies">—</strong><small id="movies-hour">— added in 1 hour</small></div>
    <div class="kpi"><span class="label">Performers</span><strong id="performers">—</strong><small>Unique records</small></div>
    <div class="kpi"><span class="label">Queue complete</span><strong id="done">—</strong><small>All time</small></div>
    <div class="kpi"><span class="label">Queue pending</span><strong id="pending">—</strong><small id="claimed">— in progress</small></div>
    <div class="kpi problem"><span class="label">Failed items</span><strong id="failed">—</strong><small id="failed-jobs">— failed jobs</small></div>
  </section>
  <div class="grid">
    <div>
      <section class="panel"><h2>Sources</h2><p class="hint">Successful crawl age, circuit state and backlog for enabled sources</p><div id="sources" class="source-grid"><div class="empty">Loading sources…</div></div></section>
      <section class="panel"><h2>Recent issues</h2><p class="hint">Latest failed crawl items</p><div id="issues" class="rows"><div class="empty">Loading issues…</div></div></section>
    </div>
    <div>
      <section class="panel"><h2>Performance · last hour</h2><p class="hint">Crawl work recorded by the application</p><div id="performance" class="metrics"></div></section>
      <section class="panel"><h2>Activity</h2><p class="hint">Recent crawl events</p><div id="events" class="rows"></div></section>
    </div>
  </div>
</main>
<script>
(() => {
  const endpoint = @json(route('operations.data'));
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(value || 0).toLocaleString();
  const age = seconds => seconds == null ? 'Never' : seconds < 60 ? `${seconds}s ago` : seconds < 3600 ? `${Math.floor(seconds / 60)}m ago` : `${Math.floor(seconds / 3600)}h ago`;
  const time = value => value ? new Date(value).toLocaleString() : '—';
  let previous = null;
  async function refresh() {
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store'});
      if (response.status === 401 || response.redirected) { window.location.href = @json(route('login')); return; }
      if (!response.ok) throw new Error(`Data request returned ${response.status}`);
      const data = await response.json();
      $('error').hidden = true;
      $('live').className = 'live'; $('live').textContent = 'Live';
      $('asof').textContent = `Updated ${time(data.as_of)} · refreshes every 15 seconds`;
      ['movies','performers'].forEach(key => { $(key).textContent = number(data.totals[key]); });
      $('movies-hour').textContent = `${number(data.totals.movies_first_seen_1h)} added in 1 hour`;
      ['done','pending','failed'].forEach(key => { $(key).textContent = number(data.queue[key]); });
      $('claimed').textContent = `${number(data.queue.claimed)} in progress`;
      $('failed-jobs').textContent = `${number(data.totals.failed_jobs)} failed jobs`;
      $('sources').innerHTML = data.sources.map(source => `<article class="source"><div class="source-head"><strong>${esc(source.name || source.slug)}</strong><span class="pill ${source.status === 'healthy' ? '' : 'attention'}">${esc(source.status)}</span></div><p>Last success: ${esc(age(source.last_success_age_seconds))}<br>Circuit: ${esc(source.circuit_state)} · ${number(source.consecutive_failures)} consecutive failures</p><div class="counts">${number(source.queue.done)} done · ${number(source.queue.pending)} pending · ${number(source.queue.claimed)} active · ${number(source.queue.failed)} failed</div>${source.last_error ? `<p title="${esc(source.last_error)}">Latest error: ${esc(source.last_error)}</p>` : ''}</article>`).join('') || '<div class="empty">No source is enabled.</div>';
      const metric = (label, value) => `<div class="metric"><span>${label}</span><b>${number(value)}</b></div>`;
      $('performance').innerHTML = metric('Runs', data.hour.runs) + metric('Pages fetched', data.hour.pages) + metric('New videos', data.hour.movies_new) + metric('Updated videos', data.hour.movies_updated) + metric('Fetch failures', data.hour.failures) + metric('Failed jobs', data.totals.failed_jobs);
      $('issues').innerHTML = data.issues.map(item => `<div class="row"><b>${esc(item.source)}</b><span>${esc(item.code)}</span><span>${esc(item.kind)} · ${number(item.attempts)} attempts${item.detail ? ' · ' + esc(item.detail) : ''}</span></div>`).join('') || '<div class="empty">No failed crawl items.</div>';
      $('events').innerHTML = data.events.map(item => `<div class="row"><b>${esc(item.source)}</b><span>${esc(item.kind)}</span><span>${esc(time(item.at))}</span></div>`).join('') || '<div class="empty">No crawl events yet.</div>';
      previous = data.as_of;
    } catch (error) {
      $('live').className = 'live bad'; $('live').textContent = 'Data unavailable';
      $('error').textContent = `Could not refresh crawl status: ${error.message}`;
      $('error').hidden = false;
      if (previous) $('asof').textContent = `Last good update ${time(previous)}`;
    }
  }
  refresh(); setInterval(refresh, 15000);
})();
</script>
</body>
</html>
