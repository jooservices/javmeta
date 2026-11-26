import { useEffect, useState } from 'react';
import { LazyImg } from '../lazyimg.jsx';
import { useNavigate } from 'react-router-dom';
import { Stack, Group, Text, Title, Button, Badge, Select, Modal, SegmentedControl, Skeleton, UnstyledButton } from '@mantine/core';
import { Async, useAsync, PageHead, MovieCard, QuickView, MovieGridSkeleton } from '../components.jsx';
import { t, tk } from '../i18n/index.js'; import { PerfCard } from '../perfcard.jsx';
import { PhotoViewer } from '../features.jsx'; import { Kpi } from '../kpi.jsx';
import { recentUpdates, updatesSummary, fmt, adaptMovie, adaptPerformer } from '../shim.js'; import { get } from '../data/api.js'; import { registerList } from '../lists.js';

// the feed is grouped by (UTC) day by the server; the page names the days relative to the newest one
const DAY = 864e5; const dayNo = d => Math.floor(Date.parse(d) / DAY);
const BUCKETS = [['today', tk('updates.today')], ['yesterday', tk('updates.yesterday')], ['week', tk('updates.thisWeek')], ['lastweek', tk('updates.lastWeek')], ['older', tk('updates.older')]];
const bucketOf = (d, ref) => { const x = dayNo(ref) - dayNo(d); return x <= 0 ? 'today' : x === 1 ? 'yesterday' : x < 7 ? 'week' : x < 14 ? 'lastweek' : 'older'; };
const Heading = ({ children, count }) => <Group gap="sm" mb="sm" align="baseline" data-group={children}><Text fw={600}>{children}</Text><Badge size="md" variant="light" color="gray">{fmt(count)}</Badge></Group>;
const More = ({ feed }) => feed.hasMore ? <Group justify="center" mt="md"><Button variant="light" loading={feed.loading} onClick={feed.more}>{t('updates.loadMore', { n: fmt(feed.total - feed.count) })}</Button></Group> : null;

// one feed: pages of day groups, merged, with "load more"
function useFeed(type, params, adapt) {
  const key = JSON.stringify([type, params]); const [pages, setPages] = useState([]); const [meta, setMeta] = useState(null); const [page, setPage] = useState(1); const [loading, setLoading] = useState(true);
  useEffect(() => { setPages([]); setPage(1); }, [key]);
  useEffect(() => { let on = true; setLoading(true); recentUpdates(type, { ...params, page, per_page: 48 }).then(r => { if (!on) return; const g = r.data.map(d => ({ day: d.day, items: d.items.map(adapt) })); setPages(p => (page === 1 ? [g] : [...p, g])); setMeta(r.meta.pagination); setLoading(false); }); return () => { on = false; }; }, [key, page]); // eslint-disable-line
  const days = new Map(); pages.flat().forEach(g => { (days.get(g.day) || days.set(g.day, []).get(g.day)).push(...g.items); });
  const groups = [...days].map(([day, items]) => ({ day, items })); const ref = groups[0]?.day; const count = groups.reduce((a, g) => a + g.items.length, 0);
  const byBucket = BUCKETS.map(([k, label]) => { const items = groups.filter(g => bucketOf(g.day, ref) === k).flatMap(g => g.items); return { k, label: t(label), items, total: items.length }; }).filter(g => g.items.length);
  return { groups: byBucket, ref, count, total: meta?.total ?? 0, hasMore: meta ? page < meta.pages : false, more: () => setPage(p => p + 1), loading };
}
const spark = rows => Array.from({ length: 14 }, (_, i) => rows[rows.length - 14 + i]?.count || 0);

export default function Updates() {
  const [pv, setPv] = useState(null); const [sort, setSort] = useState('crawled'); const [open, setOpen] = useState(null); const nav = useNavigate();
  const head = useAsync(async () => { const [sum, m, p] = await Promise.all([updatesSummary(), get('/statistics/series', { metric: 'movies', days: 14 }), get('/statistics/series', { metric: 'performers', days: 14 })]); return { sum, m: m.data, p: p.data }; }, []);
  const fm = useFeed('movies', {}, adaptMovie); const fp = useFeed('performers', { sort: sort === 'debut' ? 'debut' : 'crawled', has_photo: sort === 'crawled' ? '' : '' }, x => ({ ...adaptPerformer(x), slug: x.slug })); const fph = useFeed('photos', {}, x => x);
  const go = id => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  registerList('updates', fm.groups.flatMap(g => g.items).map(m => m.code)); registerList('updates-p', fp.groups.flatMap(g => g.items).map(x => x.id));
  const photoAll = fph.groups.flatMap(g => g.items); const cur = open != null ? photoAll[open] : null;
  return <Stack gap="lg"><Async state={head} skeleton={<Stack><PageHead title={t('nav.updates')} /><Skeleton h={40} /><MovieGridSkeleton n={8} /></Stack>}>{({ sum, m, p }) => <Stack gap="xl">
      <PageHead title={t('nav.updates')} sub={fm.ref ? t('updates.collectedUntil', { date: fm.ref.split('-').reverse().join('/') }) : ''} />
      <div className="kpi3">{[['u-movies', t('updates.newMovies'), fm.total, spark(m), t('updates.in24h', { n: fmt(sum.movies_24h) })], ['u-perfs', t('updates.newPerformers'), fp.total, spark(p), t('updates.in24h', { n: fmt(sum.performers_24h) })], ['u-photos', t('updates.newPhotos'), fph.total, null, t('updates.in24h', { n: fmt(sum.photos_24h) })]].map(([id, label, n, sp, recent]) => <UnstyledButton key={id} onClick={() => go(id)} aria-label={t('shortcuts.goTo', { name: label })} style={{ display: 'block' }}><Kpi label={label} value={fmt(n)} delta={recent} data={sp} /></UnstyledButton>)}</div>
      <section id="u-movies" data-list-id="updates" data-list-label={t('nav.updates')} style={{ scrollMarginTop: 72 }}><Title order={3} fw={600} mb="md">{t('updates.newMovies')}</Title>
        <Stack gap="lg">{fm.groups.map(g => <div key={g.k}><Heading count={g.total}>{g.label}</Heading><div className="pgrid fixed">{g.items.map(x => <MovieCard key={x.id} m={x} onPreview={setPv} />)}</div></div>)}</Stack><More feed={fm} /></section>
      <section id="u-perfs" data-list-id="updates-p" data-list-kind="performers" data-list-label={t('nav.updates')} style={{ scrollMarginTop: 72 }}><Group justify="space-between" mb="md" wrap="wrap"><div><Title order={3} fw={600}>{t('updates.newPerformers')}</Title><Text size="xs" c="dimmed">{t('updates.performersHint')}</Text></div>
        <Select size="xs" w={210} value={sort} onChange={setSort} data={[{ value: 'crawled', label: t('updates.sortCrawled') }, { value: 'debut', label: t('updates.sortAppeared') }]} allowDeselect={false} aria-label={t('updates.sortPerformers')} /></Group>
        <Stack gap="lg">{fp.groups.map(g => <div key={g.k}><Heading count={g.total}>{g.label}</Heading><div className="pgrid fixed">{g.items.map(x => <div key={x.id}><PerfCard x={x} /><Text size="xs" c="dimmed" mt={4} ta="center" className="mono">{t('updates.crawledAt', { at: (x.crawled || '').slice(0, 16).replace('T', ' ') })}</Text></div>)}</div></div>)}</Stack><More feed={fp} /></section>
      <section id="u-photos" style={{ scrollMarginTop: 72 }}><Title order={3} fw={600} mb="md">{t('updates.newPhotos')}</Title>
        <Stack gap="lg">{(() => { let i = 0; return fph.groups.map(g => <div key={g.k}><Heading count={g.total}>{g.label}</Heading><div className="pgrid fixed">{g.items.map(x => { const idx = i++; return <div key={idx} className="poster cover-portrait" role="link" tabIndex={0} aria-label={`${x.kind} ${x.label}`} onClick={() => setOpen(idx)} onKeyDown={e => e.key === 'Enter' && setOpen(idx)}><LazyImg src={x.thumb_url} alt={x.label}  /><div className="poster-bar"><span className="code">{x.label}</span></div></div>; })}</div></div>); })()}</Stack><More feed={fph} /></section>
      <Modal opened={cur != null} onClose={() => setOpen(null)} size="auto" centered zIndex={400} styles={{ body: { padding: 0 }, content: { maxWidth: 'min(96vw, 1200px)' } }} title={<Group gap="sm"><Badge variant="filled" className="mono">{cur?.label}</Badge><Text size="sm" c="dimmed">{cur?.kind === 'avatar' ? t('updates.performerPhoto') : cur?.kind}</Text></Group>}>
        {cur && <><PhotoViewer index={open} setIndex={setOpen} items={photoAll.map(x => ({ src: x.full_url || x.thumb_url, thumb: x.thumb_url, label: x.label }))} /><Group justify="flex-end" p="md"><Button onClick={() => { const t = `/performers/${cur.performer_id}`; setOpen(null); nav(t); }}>{t('omnibox.openPerformer')}</Button></Group></>}</Modal>
      <QuickView code={pv} onClose={() => setPv(null)} />
    </Stack>}</Async></Stack>;
}
