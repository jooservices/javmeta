import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Stack, Group, Text, Paper, SimpleGrid, Table, Anchor, Title, Progress, Skeleton, SegmentedControl, Select, MultiSelect, Badge } from '@mantine/core';
import { BarChart, DonutChart, LineChart, RadarChart } from '@mantine/charts';
import { IconArrowUpRight, IconArrowDownRight } from '@tabler/icons-react';
import { useAsync, Async, PageHead } from '../components.jsx';
import { Kpi } from '../kpi.jsx';
import { fmt } from '../shim.js'; import { get } from '../data/api.js'; import { t, tk, locale } from '../i18n/index.js';

const pct = (a, b) => (b ? (a / b) * 100 : null);
const Card = ({ title, sub, right, children }) => <Paper className="tile"><Group justify="space-between" align="flex-start" mb="sm" wrap="wrap" gap="sm"><div><Text fw={600}>{title}</Text>{sub && <Text size="xs" c="dimmed">{sub}</Text>}</div>{right}</Group>{children}</Paper>;
const Delta = ({ v }) => v == null ? <Badge variant="light" color="gray">—</Badge> : <Badge variant="light" color={v >= 0 ? 'teal' : 'red'} leftSection={v >= 0 ? <IconArrowUpRight size={12} /> : <IconArrowDownRight size={12} />}>{`${v >= 0 ? '+' : '−'}${Math.abs(v).toFixed(Math.abs(v) < 10 ? 1 : 0)}%`}</Badge>;
const COLORS = ['teal.6', 'blue.5', 'orange.5'];
const METRICS = [['movies', tk('common.movies')], ['performers', tk('common.performers')]];
const KINDS = { makers: [tk('catalog.kind.makers'), 'maker'], genres: [tk('catalog.kind.genres'), 'genre'], series: [tk('catalog.kind.series'), 'series'], labels: [tk('catalog.kind.labels'), 'label'] };
const AXES = [['scale', tk('stats.axis.scale')], ['tier3', tk('movie.tierFull')], ['score', tk('movie.communityScore')], ['links', tk('stats.hasLinks')], ['watch', tk('stats.hasPreview')], ['cast', tk('stats.hasCast')], ['recent', tk('stats.axis.recent')]];
const FIELDS = [[tk('admin.titleEn'), 'title_en'], [tk('movie.coverImage2'), 'cover'], [tk('admin.releaseDate'), 'release_date'], [tk('movie.runtime'), 'runtime'], [tk('movie.maker'), 'maker'], [tk('movie.label'), 'label'], ['Series', 'series'], [tk('movie.score'), 'score'], [tk('omnibox.kind.genre'), 'genres'], [tk('common.performers'), 'performers'], [tk('stats.hasLinks'), 'magnets']];
const WD = [tk('cal.mon'), tk('cal.tue'), tk('cal.wed'), tk('cal.thu'), tk('cal.fri'), tk('cal.sat'), tk('cal.sun')];
// last 14 daily counts -> this week vs the week before, same weekday order
function weekCompare(rows) { const by = new Map(rows.map(r => [r.d, r.n])); const days = Array.from({ length: 14 }, (_, i) => { const d = new Date(Date.now() - (13 - i) * 864e5); return d.toISOString().slice(0, 10); }); const v = days.map(d => by.get(d) || 0); const p = v.slice(0, 7), c = v.slice(7); const sp = p.reduce((a, b) => a + b, 0), sc = c.reduce((a, b) => a + b, 0); return { c: sc, p: sp, change: sp ? ((sc - sp) / sp) * 100 : null, chart: c.map((x, i) => ({ day: t(WD[(new Date(days[7 + i]).getUTCDay() + 6) % 7]), thisWeek: x, lastWeek: p[i] })) }; }
function yoyRows(years) { const ys = years.filter(y => y.y >= 2000); return ys.map((r, i) => ({ ...r, change: i && ys[i - 1].n ? ((r.n - ys[i - 1].n) / ys[i - 1].n) * 100 : null })); }

export default function Stats() {
  const s = useAsync(async () => { const [d, h] = await Promise.all([get('/statistics/dashboard'), get('/statistics/crawl-health', { hours: 72 })]); return { d: d.data, h: h.data }; }, []);
  const [metric, setMetric] = useState('movies'); const [hm, setHm] = useState('ok'); const [kind, setKind] = useState('makers'); const [picked, setPicked] = useState(null);
  const cmp = useAsync(async () => { const names = picked?.kind === kind ? picked.names : (s.data?.d[kind].slice(0, 2).map(x => x.name) || []); if (names.length < 2) return []; return (await get('/statistics/compare', { kind, names: names.join(',') })).data; }, [kind, JSON.stringify(picked), !!s.data]);
  return <Stack gap="lg"><PageHead title={t('nav.stats')} sub={t('stats.sub')} />
    <Async state={s} skeleton={<SimpleGrid cols={{ base: 1, md: 2 }}><Skeleton h={300} /><Skeleton h={300} /></SimpleGrid>}>{({ d, h }) => {
      const T = d.totals; const total = d.coverage.total; const wk = { movies: weekCompare(d.movies_series), performers: weekCompare(d.performers_series) }; const w = wk[metric];
      const yoy = yoyRows(d.years); const last = yoy.at(-1); const tiers = d.tiers.map(x => ({ name: t('stats.tier', { n: x.tier }), value: x.n, color: ['red.6', 'yellow.6', 'teal.6'][x.tier - 1] || 'gray' }));
      const maker10 = d.makers.slice(0, 10), maker10n = maker10.reduce((a, x) => a + x.n, 0); const donut = [...d.makers.slice(0, 8).map((x, i) => ({ name: x.name, value: x.n, color: ['teal.6', 'blue.5', 'orange.5', 'grape.5', 'cyan.5', 'lime.5', 'pink.5', 'yellow.5'][i] })), { name: t('stats.rest'), value: Math.max(0, d.with_maker - d.makers.slice(0, 8).reduce((a, x) => a + x.n, 0)), color: 'gray.7' }];
      const genre10 = d.genres.slice(0, 10).map(g => ({ genre: g.name, share: +pct(g.n, total).toFixed(1) }));
      const hours = h.map(x => ({ hour: new Date(x.hour_at).toLocaleString(locale(), { day: '2-digit', hour: '2-digit' }), ok: x.ok, err: x.blocked + x.timeout + x.errors })); const list = d[kind]; const sel = (picked && picked.kind === kind ? picked.names : list.slice(0, 2).map(x => x.name)).slice(0, 3); const items = cmp.data || [];
      const radar = AXES.map(([k, label]) => Object.fromEntries([['axis', t(label)], ...items.map(m => [m.name, Math.min(100, m.axes[k])])]));
      const top = (arr, to, lab) => <Card title={lab}><Table verticalSpacing={8} withRowBorders={false}><Table.Tbody>{arr.slice(0, 10).map(x => <Table.Tr key={x.name}><Table.Td><Anchor component={Link} to={`${to}=${encodeURIComponent(x.name)}`} size="sm">{x.name}</Anchor></Table.Td><Table.Td ta="right" className="mono">{fmt(x.n)}</Table.Td></Table.Tr>)}</Table.Tbody></Table></Card>;
      return <Stack gap="lg">
        <div className="kpi-main kpi4">{[[t('common.movies'), T.movies, d.movies_series.map(x => x.n), t('home.per24h', { n: fmt(T.movies_24h) })], [t('common.performers'), T.performers, d.performers_series.map(x => x.n), t('home.per24h', { n: fmt(T.performers_24h) })], ['Magnet', T.magnets, null, t('home.moviesWithLinks', { n: fmt(d.coverage.magnets) })], [t('stats.needReview'), d.coverage.needs_review, null, t('stats.awaitingReview')]].map(([l, v, dd, dl]) => <Kpi key={l} label={l} value={fmt(v)} delta={dl} data={dd} />)}</div>
        <Title order={4} fw={600}>{t('stats.overTime')}</Title>
        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
          <Card title={t('stats.week7')} sub={t('stats.week7Sub')} right={<SegmentedControl size="xs" value={metric} onChange={setMetric} data={METRICS.map(([value, label]) => ({ value, label: t(label) }))} aria-label={t('stats.weekMetric')} />}>
            <Group gap="xl" mb="sm" wrap="wrap"><div><Text size="xs" c="dimmed">{t('stats.thisWeek')}</Text><Text fz={24} fw={600} className="mono">{fmt(w.c)}</Text></div><div><Text size="xs" c="dimmed">{t('stats.lastWeek')}</Text><Text fz={24} fw={600} className="mono">{fmt(w.p)}</Text></div><div><Text size="xs" c="dimmed">{t('stats.change')}</Text><Delta v={w.change} /></div></Group>
            <LineChart h={230} data={w.chart} dataKey="day" series={[{ name: 'thisWeek', label: t('stats.thisWeek'), color: 'teal.6' }, { name: 'lastWeek', label: t('stats.lastWeek'), color: 'gray.5', strokeDasharray: '5 5' }]} curveType="monotone" withLegend tickLine="none" gridAxis="y" withDots={false} />
            <SimpleGrid cols={2} mt="md" spacing="xs">{METRICS.map(([k, l]) => <div key={k}><Text size="xs" c="dimmed">{t(l)}</Text><Delta v={wk[k].change} /></div>)}</SimpleGrid></Card>
          <Card title={t('stats.hourly3d')} sub={t('stats.hourlySub')} right={<SegmentedControl size="xs" value={hm} onChange={setHm} data={[{ value: 'ok', label: t('common.success') }, { value: 'err', label: t('common.error') }]} aria-label={t('stats.fetchKind')} />}>
            <LineChart h={290} data={hours} dataKey="hour" series={[hm === 'ok' ? { name: 'ok', label: t('common.success'), color: 'teal.6' } : { name: 'err', label: t('common.error'), color: 'red.6' }]} curveType="monotone" withLegend tickLine="none" gridAxis="y" withDots={false} xAxisProps={{ interval: 5 }} /></Card>
        </SimpleGrid>
        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
          <Card title={t('stats.byYear')}><BarChart h={260} data={d.years.filter(y => y.y >= 2000).map(y => ({ year: String(y.y), movies: y.n }))} dataKey="year" series={[{ name: 'movies', label: t('common.movies'), color: 'teal.6' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title={t('stats.vsLastYear')} sub={t('stats.partialYear', { year: last.y })}>
            <BarChart h={150} data={yoy.slice(-9).filter(r => r.change != null).map(r => ({ year: String(r.y), growth: Math.round(r.change) }))} dataKey="year" series={[{ name: 'growth', label: t('stats.growthPct'), color: 'blue.5' }]} tickLine="none" gridAxis="y" />
            <Table verticalSpacing={6} withRowBorders={false} mt="sm"><Table.Thead><Table.Tr><Table.Th>{t('stats.year')}</Table.Th><Table.Th ta="right">{t('common.movies')}</Table.Th><Table.Th ta="right">{t('stats.vsLastYear')}</Table.Th></Table.Tr></Table.Thead><Table.Tbody>{[...yoy].reverse().slice(0, 5).map(r => <Table.Tr key={r.y}><Table.Td className="mono">{r.y}{r.y === last.y ? ' *' : ''}</Table.Td><Table.Td ta="right" className="mono">{fmt(r.n)}</Table.Td><Table.Td ta="right"><Delta v={r.change} /></Table.Td></Table.Tr>)}</Table.Tbody></Table><Text size="xs" c="dimmed" mt={4}>{t('stats.partialYearNote')}</Text></Card>
        </SimpleGrid>
        <Title order={4} fw={600}>{t('stats.structure')}</Title>
        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
          <Card title={t('stats.top10Share')} sub={t('stats.top10Sub', { n: fmt(d.with_maker) })}><Group justify="center"><DonutChart data={donut} size={190} thickness={26} withTooltip chartLabel={`${pct(maker10n, d.with_maker).toFixed(0)}%`} /></Group>
            <Text size="sm" ta="center" mt="xs">{t('stats.top10Before', { n: fmt(maker10n) })}<b>{pct(maker10n, d.with_maker).toFixed(1)}%</b>{t('stats.top10After')}</Text></Card>
          <Card title={t('home.popularGenres')} sub={t('stats.genreSub')}><BarChart h={300} data={genre10} dataKey="genre" orientation="vertical" series={[{ name: 'share', label: t('stats.movieShare'), color: 'teal.6' }]} tickLine="none" gridAxis="x" valueFormatter={v => `${v}%`} yAxisProps={{ width: 110 }} /></Card>
        </SimpleGrid>
        <Title order={4} fw={600}>{t('stats.distribution')}</Title>
        <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
          <Card title={t('movie.runtime')} sub={t('stats.withRuntime', { n: fmt(d.coverage.runtime) })}><BarChart h={200} data={['<60', '60-89', '90-119', '120-179', '180+'].map(k => ({ range: k, movies: d.runtime.find(x => x.k === k)?.n || 0 }))} dataKey="range" series={[{ name: 'movies', label: t('common.movies'), color: 'teal.6' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title={t('stats.communityScoreScale')} sub={t('stats.withScore', { n: fmt(d.coverage.score) })}><BarChart h={200} data={['0-2', '2-4', '4-6', '6-8', '8-10'].map(k => ({ range: k, movies: d.score.find(x => x.k === k)?.n || 0 }))} dataKey="range" series={[{ name: 'movies', label: t('common.movies'), color: 'yellow.5' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title={t('stats.byMonth')} sub={t('stats.last12')}><BarChart h={200} data={d.months.map(x => ({ month: x.k, movies: x.n }))} dataKey="month" series={[{ name: 'movies', label: t('common.movies'), color: 'blue.5' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title={t('stats.castPerMovie')}><BarChart h={200} data={['0', '1', '2', '3-4', '5+'].map(k => ({ range: k, movies: d.cast.find(x => x.k === k)?.n || 0 }))} dataKey="range" series={[{ name: 'movies', label: t('common.movies'), color: 'violet.5' }]} tickLine="none" gridAxis="y" /></Card>
        </SimpleGrid>
        <Title order={4} fw={600}>{t('stats.compareItems')}</Title>
        <Card title={t('stats.radarTitle')} sub={t('stats.radarSub')} right={<Select size="xs" w={150} value={kind} onChange={v => { setKind(v); setPicked(null); }} data={Object.entries(KINDS).map(([value, [label]]) => ({ value, label: t(label) }))} allowDeselect={false} aria-label={t('stats.compareKind')} />}>
          <MultiSelect size="xs" mb="md" searchable value={sel} onChange={v => setPicked({ kind, names: v.slice(0, 3) })} data={list.map(x => ({ value: x.name, label: `${x.name} (${fmt(x.n)})` }))} maxValues={3} placeholder={t('stats.pick23')} aria-label={t('stats.itemsToCompare')} />
          {items.length >= 2 ? <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg" style={{ alignItems: 'center' }}>
            <RadarChart h={340} data={radar} dataKey="axis" withPolarGrid withPolarAngleAxis withLegend series={items.map((m, i) => ({ name: m.name, color: COLORS[i], opacity: 0.25 }))} />
            <Table verticalSpacing={8} withRowBorders><Table.Thead><Table.Tr><Table.Th></Table.Th>{items.map((m, i) => <Table.Th key={m.name} ta="right"><Anchor component={Link} to={`/movies?${KINDS[kind][1]}=${encodeURIComponent(m.name)}`} size="sm" c={COLORS[i]}>{m.name}</Anchor></Table.Th>)}</Table.Tr></Table.Thead>
              <Table.Tbody><Table.Tr><Table.Td fw={700}>{t('stats.movieCountLib')}</Table.Td>{items.map(m => <Table.Td key={m.name} ta="right" className="mono">{fmt(m.n)}</Table.Td>)}</Table.Tr><Table.Tr><Table.Td fw={700}>{t('stats.shareOfLib')}</Table.Td>{items.map(m => <Table.Td key={m.name} ta="right" className="mono">{(m.share * 100).toFixed(1)}%</Table.Td>)}</Table.Tr>
                {AXES.map(([k, label]) => <Table.Tr key={k}><Table.Td fw={700}>{t(label)}</Table.Td>{items.map(m => <Table.Td key={m.name} ta="right" className="mono">{Math.min(100, m.axes[k])}</Table.Td>)}</Table.Tr>)}</Table.Tbody></Table></SimpleGrid> : <Text size="sm" c="dimmed">{t('stats.pickAtLeast2')}</Text>}
          <Text size="xs" c="dimmed" mt="sm">{t('stats.radarNote')}</Text></Card>
        <Title order={4} fw={600}>{t('admin.tabQuality')}</Title>
        <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
          <Card title={t('stats.completeness')}><Group justify="center"><DonutChart data={tiers} size={200} thickness={28} withTooltip chartLabel={fmt(total)} /></Group><Group justify="center" gap="md">{tiers.map(t => <Group key={t.name} gap={8}><span className="dot" style={{ background: `var(--mantine-color-${t.color.replace('.', '-')})` }} /><Text size="xs">{t.name}: {fmt(t.value)}</Text></Group>)}</Group></Card>
          <Card title={t('stats.fieldCoverage', { n: fmt(total) })}><Stack gap="sm">{FIELDS.map(([lab, k]) => { const p = Math.round(pct(d.coverage[k], total)); return <div key={k}><Group justify="space-between"><Text size="sm">{t(lab)}</Text><Text size="sm" className="mono">{p}%</Text></Group><Progress value={p} color={p > 80 ? 'teal' : p > 40 ? 'yellow' : 'red'} size="sm" /></div>; })}</Stack><Text size="xs" c="dimmed" mt="sm">{t('stats.moviesAwaiting', { n: fmt(d.coverage.needs_review) })}</Text></Card>
        </SimpleGrid>
        <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }} spacing="lg">{top(d.makers, '/movies?maker', t('stats.topMakers'))}{top(d.genres, '/movies?genre', t('home.popularGenres'))}{top(d.series, '/movies?series', t('stats.topSeries'))}</SimpleGrid>
      </Stack>; }}</Async></Stack>;
}
