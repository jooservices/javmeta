import { canPreview, playableList } from './playable.js'; import { useEffect, useRef, useState, useCallback } from 'react';
import { LazyImg } from './lazyimg.jsx';
import { useNavigate } from 'react-router-dom'; import { loadMovieExtras } from './shim.js';
import { Stack, Group, Text, Button, Paper, Title, SegmentedControl, ActionIcon, Tooltip, Alert, Badge, Loader, Table } from '@mantine/core';
import { useLocalStorage } from '@mantine/hooks';
import { IconEyeOff, IconEye, IconPlayerPlay, IconExternalLink, IconAlertCircle } from '@tabler/icons-react'; import { t, tk } from './i18n/index.js';

// ---- A9: keyboard shortcuts (one list feeds the handler, the help dialog and the help page)
export const GO = [['h', '/', tk('nav.explore')], ['s', '/search', tk('nav.search')], ['m', '/movies', tk('nav.movies')], ['p', '/performers', tk('nav.performers')], ['c', '/calendar', tk('nav.calendar')], ['t', '/stats', tk('nav.stats')], ['f', '/mine/fav', tk('nav.favorites')], ['l', '/mine/later', tk('nav.watchLater')], ['o', '/ops', tk('nav.commandCenter')], ['a', '/activity', tk('nav.myActivity')]];
export const shortcuts = () => [[t('shortcuts.slashOrK'), t('shortcuts.quickSearch')], ['⌘B', t('shortcuts.toggleSidebar')], ['?', t('shortcuts.openHelp')], ['↑ ↓ Enter', t('shortcuts.pickSuggestion')], ['Esc', t('shortcuts.close')], ['B', t('shortcuts.privacy')], ['`', t('shortcuts.panic')], ...GO.map(([k, , d]) => [`g ${k}`, t('shortcuts.goTo', { name: t(d) })])];
export const ShortcutTable = () => <Table withRowBorders={false} verticalSpacing={6}><Table.Tbody>{shortcuts().map(([k, d]) => <Table.Tr key={k}><Table.Td w={120}><span className="kbd">{k}</span></Table.Td><Table.Td>{d}</Table.Td></Table.Tr>)}</Table.Tbody></Table>;
const typing = e => { const t = e.target; return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); };
export function useGoKeys() {
  const nav = useNavigate(); const armed = useRef(0);
  useEffect(() => {
    const on = e => {
      if (e.metaKey || e.ctrlKey || e.altKey || typing(e)) return; const k = e.key.toLowerCase();
      if (armed.current && Date.now() - armed.current < 1200) { armed.current = 0; const hit = GO.find(x => x[0] === k); if (hit) { e.preventDefault(); nav(hit[1]); } return; }
      if (k === 'g' && !e.shiftKey) armed.current = Date.now();
    };
    window.addEventListener('keydown', on); return () => window.removeEventListener('keydown', on);
  }, [nav]);
}

// ---- A3: privacy mode (blur every picture, quick hide)
export function usePrivacy() { const [on, setOn] = useLocalStorage({ key: 'xc.privacy', defaultValue: false }); useEffect(() => { document.documentElement.dataset.privacy = on ? 'on' : 'off'; }, [on]); return [on, setOn]; }
export function PrivacyLayer() {
  const [on, setOn] = usePrivacy(); const [hide, setHide] = useState(false);
  useEffect(() => { const k = e => { if (typing(e) || e.metaKey || e.ctrlKey || e.altKey) return; if (e.key === '`') { e.preventDefault(); setHide(h => !h); } else if (e.key.toLowerCase() === 'b') setOn(v => !v); }; window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k); }, []);
  useEffect(() => { document.title = hide ? t('privacy.hiddenTitle') : 'XCrawler'; }, [hide]);
  return hide ? <div role="presentation" onClick={() => setHide(false)} style={{ position: 'fixed', inset: 0, zIndex: 9999, background: '#0b0d10', cursor: 'default' }} /> : null;
}
export function PrivacyToggle() {
  const [on, setOn] = usePrivacy();
  return <Tooltip label={on ? t('privacy.turnOff') : t('privacy.turnOn')}><ActionIcon variant={on ? 'filled' : 'default'} color={on ? 'teal' : undefined} onClick={() => setOn(v => !v)} aria-label={t('privacy.mode')} aria-pressed={on}>{on ? <IconEyeOff size={18} /> : <IconEye size={18} />}</ActionIcon></Tooltip>;
}

// ---- A2: in-page preview player (mp4 direct; HLS natively where the browser can, hls.js elsewhere)
const label = (s, i) => { const q = s.quality || (s.url.match(/\/(\d{3,4}p)\//) || [])[1]; return `${q || t('player.sourceN', { n: i + 1 })} · ${/\.m3u8/i.test(s.url) ? 'HLS' : 'MP4'}`; };
const host = u => { try { return new URL(u).host.replace(/^www\./, ''); } catch { return ''; } };
export function PreviewPlayer({ m, bare }) {
  const [, force] = useState(0); useEffect(() => { if (m.watch == null && m.hls) loadMovieExtras(m.code).then(() => force(x => x + 1)); }, [m.code]); const all = m.watch || []; const good = playableList(m); const list = bare ? good : [...good, ...all.filter(x => !good.includes(x))]; const [i, setI] = useState(0); const [err, setErr] = useState(null); const [busy, setBusy] = useState(true); const ref = useRef(null);
  useEffect(() => { setI(0); setErr(null); }, [m.code]);
  const s = list[i];
  useEffect(() => {
    const v = ref.current; if (!v || !s) return; setErr(null); setBusy(true); let hls;
    const fail = () => { setErr(t('player.blocked')); setBusy(false); };
    v.onerror = fail; v.onloadeddata = () => setBusy(false);
    if (/\.m3u8/i.test(s.url)) {
      if (v.canPlayType('application/vnd.apple.mpegurl')) v.src = s.url;
      else import('hls.js').then(({ default: Hls }) => { if (!Hls.isSupported()) return fail(); hls = new Hls(); hls.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) fail(); }); hls.loadSource(s.url); hls.attachMedia(v); }).catch(fail);
    } else v.src = s.url;
    return () => { if (hls) hls.destroy(); v.removeAttribute('src'); v.load(); };
  }, [s?.url]);
  if (!s) return null;
  const wrap = ch => (bare ? <div>{ch}</div> : <Paper className="tile" id="watch">{ch}</Paper>);
  return wrap(<Stack gap="sm">
    <Group justify="space-between" wrap="wrap">{bare ? <Text size="sm" c="dimmed">{t('player.livePreview')}</Text> : <Title order={4} fw={600}>{t('player.preview')}</Title>}<Group gap="xs"><Badge variant="light" color="gray">{host(s.url)}</Badge></Group></Group>
    {list.length > 1 && <SegmentedControl size="xs" value={String(i)} onChange={v => setI(+v)} data={list.map((x, k) => ({ value: String(k), label: label(x, k) }))} styles={{ root: { maxWidth: '100%', overflowX: 'auto' } }} />}
    <div className={bare ? 'player live' : 'player'}><video ref={ref} controls playsInline preload="metadata" poster={m.cover || undefined} />{busy && !err && <div className="player-busy"><Loader size="sm" /></div>}</div>
    {err && <Alert color="yellow" variant="light" icon={<IconAlertCircle size={16} />} title={err}><Group gap="sm" wrap="wrap"><Text size="sm" c="dimmed">{t('player.tryOther')}</Text><Button size="compact-sm" variant="light" component="a" href={s.url} target="_blank" rel="noreferrer" leftSection={<IconExternalLink size={14} />}>{t('player.openSource')}</Button></Group></Alert>}
  </Stack>);
}

// ---- A7: photo viewer (swipe, arrow keys, thumbnail strip, counter)
export function PhotoViewer({ items, index, setIndex }) {
  const n = items.length; const go = useCallback(d => setIndex(i => (i + d + n) % n), [n, setIndex]); const x0 = useRef(null); const strip = useRef(null);
  useEffect(() => { const k = e => { if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); } else if (e.key === 'ArrowRight') { e.preventDefault(); go(1); } }; window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k); }, [go]);
  useEffect(() => { strip.current?.querySelector('.on')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }); }, [index]);
  const cur = items[index]; if (!cur) return null;
  const box = useRef(null);
  const down = e => { x0.current = e.clientX; }; const up = e => { if (x0.current == null) return; const dx = e.clientX - x0.current; x0.current = null; const el = box.current; if (el && el.scrollWidth > el.clientWidth + 1) return; if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1); };
  return <div>
    <div className="viewer-wrap">
      <div className="viewer" ref={box} onPointerDown={down} onPointerUp={up} onPointerCancel={() => { x0.current = null; }}><img src={cur.src} alt={cur.label} draggable={false} /></div>
      {n > 1 && <><ActionIcon variant="filled" color="dark" size="xl" pos="absolute" left={12} top="45%" onClick={() => go(-1)} aria-label={t('viewer.prev')} visibleFrom="sm"><span aria-hidden>‹</span></ActionIcon><ActionIcon variant="filled" color="dark" size="xl" pos="absolute" right={12} top="45%" onClick={() => go(1)} aria-label={t('viewer.next')} visibleFrom="sm"><span aria-hidden>›</span></ActionIcon></>}
      <Badge pos="absolute" bottom={10} right={12} variant="filled" color="dark" size="lg">{index + 1} / {n}</Badge></div>
    {n > 1 && <div className="vstrip" ref={strip}>{items.map((it, k) => <div key={k} className={`vt${k === index ? ' on' : ''}`} onClick={() => setIndex(k)}><LazyImg src={it.thumb || it.src} alt={`${k + 1}`}  /></div>)}</div>}
  </div>;
}

// ---- A6: performer insights computed from the performer's movies (timeline, makers, genres, co-stars)
import { Link } from 'react-router-dom'; import { SimpleGrid, Avatar, Anchor } from '@mantine/core'; import { BarChart } from '@mantine/charts';
import { photo } from './shim.js';
const top = (arr, n) => { const c = new Map(); arr.filter(Boolean).forEach(k => c.set(k, (c.get(k) || 0) + 1)); return [...c].sort((a, b) => b[1] - a[1]).slice(0, n); };
export function PerformerInsights({ p }) {
  const st = p.stats; if (!st || !st.span?.total) return null;
  const by = Object.fromEntries(st.by_year.map(y => [y.year, y.count])); const years = st.by_year.map(y => y.year); const span = years.length ? Array.from({ length: years.at(-1) - years[0] + 1 }, (_, i) => years[0] + i) : [];
  const timeline = span.map(y => ({ year: String(y), movies: by[y] || 0 }));
  return <Paper className="tile"><Stack gap="md">
    <Group justify="space-between" wrap="wrap"><div><Text fw={600}>{t('insights.title')}</Text><Text size="xs" c="dimmed">{t('insights.span', { n: st.span.total, first: String(st.span.first || '').slice(0, 10), last: String(st.span.last || '').slice(0, 10) })}</Text></div></Group>
    {timeline.length > 1 && <BarChart h={150} data={timeline} dataKey="year" series={[{ name: 'movies', color: 'teal.6', label: t('insights.movieCount') }]} gridAxis="y" tickLine="none" withTooltip yAxisProps={{ allowDecimals: false }} barProps={{ maxBarSize: 40 }} />}
    <SimpleGrid cols={{ base: 1, md: 3 }} spacing="lg">
      <div><Text size="xs" c="dimmed" mb={8}>{t('insights.topMakers')}</Text><Group gap={8}>{st.top_makers.map(({ name, count }) => <Badge key={name} variant="light" component={Link} to={`/movies?maker=${encodeURIComponent(name)}`} style={{ cursor: 'pointer' }}>{name} · {count}</Badge>)}</Group></div>
      <div><Text size="xs" c="dimmed" mb={8}>{t('insights.topGenres')}</Text><Group gap={8}>{st.top_genres.map(({ name, count }) => <Badge key={name} variant="outline" color="gray" component={Link} to={`/movies?genre=${encodeURIComponent(name)}`} style={{ cursor: 'pointer' }}>{name} · {count}</Badge>)}</Group></div>
      <div><Text size="xs" c="dimmed" mb={8}>{t('insights.coStars')}</Text>{p.co?.length ? <Stack gap={8}>{p.co.slice(0, 8).map(q => <Group key={q.id} gap="sm" wrap="nowrap"><Avatar src={photo(q)} size={32} radius="xl" imageProps={{ referrerPolicy: 'no-referrer' }} /><Anchor component={Link} to={`/performers/${q.slug || q.id}`} size="sm" truncate flex={1}>{q.name}</Anchor><Text size="xs" c="dimmed" className="mono">{t('common.moviesCount', { n: q.together })}</Text></Group>)}</Stack> : <Text size="sm" c="dimmed">{t('common.noData')}</Text>}</div>
    </SimpleGrid></Stack></Paper>;
}

// ---- A8: "What to watch today" - picks from what the user liked, followed and watched; falls back to the best new releases
import { IconX, IconSparkles, IconCheck, IconArrowBackUp } from '@tabler/icons-react'; import { modals } from '@mantine/modals';
import { getMovie, queryMovies, personal as store } from './shim.js'; import { MovieCard } from './components.jsx';
const daySeed = () => { const d = new Date(); return d.getFullYear() * 400 + d.getMonth() * 31 + d.getDate(); };
const rnd = (s, id) => { const x = Math.sin(s * 9301 + id * 49297) * 233280; return x - Math.floor(x); };
export function picksFor(st, dismissed, pool, n = 8) {
  const liked = [...st.fav, ...st.later, ...st.seen].map(id => getMovie(id)).filter(Boolean); const seen = new Set([...st.fav, ...st.seen, ...dismissed]);
  const w = new Map(); const add = (k, v) => k && w.set(k, (w.get(k) || 0) + v);
  liked.forEach(m => { m.genres.forEach(g => add(`g:${g}`, 1)); add(`m:${m.maker}`, 2); (m.perfs || []).forEach(p => add(`p:${p}`, 3)); });
  st.follows.forEach(f => add(f.type === 'performer' ? `p:${f.ref}` : f.type === 'maker' ? `m:${f.name}` : `s:${f.name}`, 5));
  st.pfav.forEach(id => add(`p:${id}`, 4));
  const s = daySeed(); const has = w.size > 0; const cand = pool.filter(m => !seen.has(m.id));
  const score = m => (has ? m.genres.reduce((a, g) => a + (w.get(`g:${g}`) || 0), 0) + (w.get(`m:${m.maker}`) || 0) + (m.perfs || []).reduce((a, p) => a + (w.get(`p:${p}`) || 0), 0) + (m.series ? w.get(`s:${m.series}`) || 0 : 0) : 0) + (m.tier === 3 ? 2 : 0) + (m.score || 0) / 20 + rnd(s, [...m.code].reduce((a, c) => a + c.charCodeAt(0), 0)) * 3;
  return { has, items: cand.map(m => [m, score(m)]).sort((a, b) => b[1] - a[1]).slice(0, n).map(x => x[0]) };
}
export function TodayPicks({ st, onPreview, pool }) {
  const [gone, setGone] = useLocalStorage({ key: 'xc.dismissed', defaultValue: [] }); const { has, items } = picksFor(st, gone, pool || []);
  if (!items.length) return null;
  return <div><Group justify="space-between" mb="md" wrap="nowrap"><div><Title order={4} fw={600}>{t('picks.title')}</Title><Text size="xs" c="dimmed">{has ? t('picks.subPersonal') : t('picks.subDefault')}</Text></div>{gone.length > 0 && <Button size="compact-sm" variant="subtle" color="gray" onClick={() => setGone([])}>{t('picks.unhide', { n: gone.length })}</Button>}</Group>
    <div className="pgrid home">{items.map(m => <div key={m.id} className="pick"><MovieCard m={m} onPreview={onPreview} /><Tooltip label={t('picks.notInterested')}><ActionIcon className="pick-x" variant="filled" color="dark" size="md" aria-label={t('picks.notInterestedCode', { code: m.code })} onClick={() => modals.openConfirmModal({ title: t('picks.confirmTitle'), centered: true, zIndex: 400, children: <Text size="sm">{t('picks.confirmBefore')}<b>{m.code}</b>{t('picks.confirmAfter')}</Text>, labels: { confirm: t('picks.confirmYes'), cancel: t('picks.confirmNo') }, confirmProps: { color: 'red', leftSection: <IconCheck size={16} /> }, cancelProps: { variant: 'default', leftSection: <IconArrowBackUp size={16} /> }, onConfirm: () => setGone(g => [...g, m.id]) })}><IconX size={14} /></ActionIcon></Tooltip></div>)}</div></div>;
}

// movies that can be previewed in the page: direct video files first (they play everywhere), then HLS
export function WatchableRow({ onPreview }) {
  const [items, setItems] = useState([]); useEffect(() => { queryMovies({ has_watch: '1', sort: 'update_date', per_page: 8 }).then(r => setItems(r.items)); }, []);
  if (!items.length) return null;
  return <div><Group justify="space-between" mb="md"><div><Title order={4} fw={600}>{t('watchable.title')}</Title><Text size="xs" c="dimmed">{t('watchable.sub')}</Text></div></Group><div className="pgrid home">{items.map(m => <MovieCard key={m.id} m={m} onPreview={onPreview} />)}</div></div>;
}

// ---- A2: preview opens in a modal on the current page (any card's play icon fires xc:play)
import { Modal } from '@mantine/core'; import { getMovie as _gm } from './shim.js';
export function PlayerHost() {
  const [code, setCode] = useState(null); const m = code ? _gm(code) : null;
  useEffect(() => { const on = e => setCode(e.detail); window.addEventListener('xc:play', on); return () => window.removeEventListener('xc:play', on); }, []);
  return <Modal opened={!!m} onClose={() => setCode(null)} size="xl" centered zIndex={400} title={<Group gap="sm"><Badge variant="filled" className="mono">{m?.code}</Badge><Text fw={600} lineClamp={1}>{(m?.en || m?.jp || '').replace(m?.code || '', '').trim()}</Text></Group>} overlayProps={{ backgroundOpacity: 0.6, blur: 6 }} styles={{ body: { padding: 0 } }}>
    {m && <div style={{ padding: 16 }}><PreviewPlayer m={m} bare /></div>}</Modal>;
}
