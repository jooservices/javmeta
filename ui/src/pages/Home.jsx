import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react'; import { useLocalStorage } from '@mantine/hooks';
import { Collapse, ActionIcon, Tooltip, Box, Progress, Group, Text, SimpleGrid, Stack, TextInput, Badge, Button, Skeleton, ScrollArea, Paper, Title, Anchor, SegmentedControl } from '@mantine/core';
import { AreaChart, Sparkline } from '@mantine/charts';
import { IconChevronUp, IconChevronDown, IconSearch, IconSparkles } from '@tabler/icons-react';
import { SectionLink, useAsync, Async, MovieCard, QuickView, MovieGridSkeleton, usePersonal } from '../components.jsx';
import { loadHome, fmt, getMovie } from '../shim.js';
import { t, locale } from '../i18n/index.js'; import { Kpi } from '../kpi.jsx'; import { registerList } from '../lists.js'; import { TodayPicks, WatchableRow } from '../features.jsx';

export default function Home() {
  const [pv, setPv] = useState(null); const st = usePersonal(); const [stats, setStats] = useLocalStorage({ key: 'xc.home.stats', defaultValue: true });
  const s = useAsync(loadHome, []);
  return <Stack gap="lg">
    <Async state={s} skeleton={<Stack><SimpleGrid cols={{ base: 2, md: 4 }}>{[1, 2, 3, 4].map(i => <Skeleton key={i} h={86} />)}</SimpleGrid><MovieGridSkeleton /></Stack>}>{(d) => { const { ov, sm, src } = d; const hours = (d.health || []).map(h => ({ h: new Date(h.hour_at).toLocaleString(locale(), { hour: '2-digit', day: '2-digit', month: '2-digit' }), ok: h.ok, err: h.blocked + h.timeout + h.errors })); return <Stack gap="lg">
      {ov && <>
      <Group justify="space-between" wrap="nowrap"><div><Text fw={600}>{t('home.systemFigures')}</Text>{!stats && <Text size="xs" c="dimmed">{t('home.figuresHidden')}</Text>}</div><Tooltip label={stats ? t('home.hideFigures') : t('home.showFigures')}><ActionIcon variant="default" onClick={() => setStats(v => !v)} aria-expanded={stats} aria-label={stats ? t('home.hideFigures') : t('home.showFigures')}>{stats ? <IconChevronUp size={16} /> : <IconChevronDown size={16} />}</ActionIcon></Tooltip></Group>
      <Collapse expanded={stats}><Stack gap="lg">
      <div className="split23 kpi-split"><div className="kpi-main">
        <Kpi label={t('common.movies')} value={fmt(ov.movies)} delta={t('home.per24h', { n: fmt(ov.movies_24h) })} data={d.mseries.map(x => x.count)} />
        <Kpi label={t('common.performers')} value={fmt(ov.performers)} delta={t('home.per24h', { n: fmt(ov.performers_24h) })} data={d.pseries.map(x => x.count)} />
        <Kpi label={t('home.magnetsStored')} value={fmt(ov.magnets)} delta={t('home.moviesWithLinks', { n: fmt(ov.movies_with_download) })} />
        <Kpi label={t('home.photosInLibrary')} value={fmt(ov.gallery_photos)} delta={t('home.performersWithPhotos', { n: fmt(ov.performers_with_photo) })} />
      </div><div className="kpi-side">
        <Kpi wide label={t('home.newMovies24h')} value={fmt(ov.movies_24h)} delta={t('home.inLastHour', { n: fmt(ov.movies_1h) })} data={d.hseries.map(x => x.count)} color="blue.5" />
        <Kpi wide label={t('home.activeSources')} value={`${src.active}/${src.total}`} delta={t('home.sourcesOff', { n: src.total - src.active })} bar={(src.active / src.total) * 100} />
      </div></div>
      <div className="split23">
      <Paper className="tile"><Group justify="space-between" mb="xs"><div><Text fw={600}>{t('home.crawlHealth')}</Text><Text size="xs" c="dimmed">{t('home.crawlHealthSub')}</Text></div><SectionLink to="/admin/sources">{t('common.details')}</SectionLink></Group>
        <AreaChart h={210} data={hours} dataKey="h" series={[{ name: 'ok', color: 'teal.6', label: t('common.success') }, { name: 'err', color: 'red.6', label: t('common.error') }]} withDots={false} xAxisProps={{ interval: 11, tickMargin: 8 }} gridAxis="y" curveType="monotone" tickLine="none" /></Paper>
        <Paper className="tile"><Group justify="space-between" mb="md" wrap="nowrap"><div><Text fw={600}>{t('home.contributors')}</Text><Text size="xs" c="dimmed">{t('home.contributorsSub')}</Text></div><SectionLink to="/admin/sources">{t('common.details')}</SectionLink></Group>
          <Stack gap="sm">{d.contrib.slice(0, 6).map(x => <div key={x.slug}><Group justify="space-between" mb={4} wrap="nowrap"><Text size="sm" truncate>{x.name}</Text><Text size="sm" className="mono">{fmt(x.n)}</Text></Group><Progress value={(x.n / Math.max(...d.contrib.map(y => y.n))) * 100} size="sm" color="teal" /></div>)}</Stack></Paper>
      </div>
      </Stack></Collapse>
      </>}
      <TodayPicks st={st} onPreview={setPv} pool={d.pool} />
      <WatchableRow onPreview={setPv} />
      <div><Group justify="space-between" mb="md"><Title order={4} fw={600}>{t('nav.updates')}</Title><SectionLink to="/updates">{t('common.viewAllShort')}</SectionLink></Group><div className="pgrid home" data-list-id="home-upd" data-list-label={t('nav.updates')}>{(registerList('home-upd', d.upd.map(m => m.code)), d.upd).map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div></div>
      <div><Group justify="space-between" mb="md"><Title order={4} fw={600}>{t('home.recentReleases')}</Title><SectionLink to="/calendar">{t('home.calendarShort')}</SectionLink></Group><div className="pgrid home" data-list-id="home-rel" data-list-label={t('home.recentReleases')}>{(registerList('home-rel', d.rel.map(m => m.code)), d.rel).map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div></div>
      {st.recent.length > 0 && <div><Title order={4} fw={600} mb="md">{t('home.recentlyViewed')}</Title><div className="pgrid home">{st.recent.map(getMovie).filter(Boolean).slice(0, 8).map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div></div>}
      <div><Title order={4} fw={600} mb="md">{t('home.popularGenres')}</Title><Group gap={8}>{d.genres.map(g => <Badge key={g.slug} component={Link} to={`/movies?genre=${encodeURIComponent(g.name)}`} variant="light" color="gray" size="lg" style={{ cursor: 'pointer' }}>{g.name} <span className="dim">{fmt(g.count)}</span></Badge>)}</Group></div>
    </Stack>; }}</Async><QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
