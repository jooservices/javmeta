import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Stack, Group, Text, Paper, SimpleGrid, Table, Anchor, Title, Progress, Skeleton, SegmentedControl, Select, MultiSelect, Badge } from '@mantine/core';
import { BarChart, DonutChart, LineChart, RadarChart } from '@mantine/charts';
import { IconArrowUpRight, IconArrowDownRight } from '@tabler/icons-react';
import { useAsync, Async, PageHead } from '../components.jsx';
import { Kpi } from '../kpi.jsx';
import { fmt } from '../shim.js'; import { get } from '../data/api.js';

const pct = (a, b) => (b ? (a / b) * 100 : null);
const Card = ({ title, sub, right, children }) => <Paper className="tile"><Group justify="space-between" align="flex-start" mb="sm" wrap="wrap" gap="sm"><div><Text fw={600}>{title}</Text>{sub && <Text size="xs" c="dimmed">{sub}</Text>}</div>{right}</Group>{children}</Paper>;
const Delta = ({ v }) => v == null ? <Badge variant="light" color="gray">—</Badge> : <Badge variant="light" color={v >= 0 ? 'teal' : 'red'} leftSection={v >= 0 ? <IconArrowUpRight size={12} /> : <IconArrowDownRight size={12} />}>{`${v >= 0 ? '+' : '−'}${Math.abs(v).toFixed(Math.abs(v) < 10 ? 1 : 0)}%`}</Badge>;
const COLORS = ['teal.6', 'blue.5', 'orange.5'];
const METRICS = [['movies', 'Phim'], ['performers', 'Diễn viên']];
const KINDS = { makers: ['Hãng', 'maker'], genres: ['Thể loại', 'genre'], series: ['Loạt phim', 'series'], labels: ['Nhãn', 'label'] };
const AXES = [['scale', 'Quy mô'], ['tier3', 'Đầy đủ dữ liệu'], ['score', 'Điểm cộng đồng'], ['links', 'Có link tải'], ['watch', 'Có xem thử'], ['cast', 'Có diễn viên'], ['recent', 'Phát hành 12 tháng']];
const FIELDS = [['Tiêu đề EN', 'title_en'], ['Ảnh bìa', 'cover'], ['Ngày phát hành', 'release_date'], ['Thời lượng', 'runtime'], ['Hãng', 'maker'], ['Nhãn', 'label'], ['Series', 'series'], ['Điểm', 'score'], ['Thể loại', 'genres'], ['Diễn viên', 'performers'], ['Có link tải', 'magnets']];
const WD = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
// last 14 daily counts -> this week vs the week before, same weekday order
function weekCompare(rows) { const by = new Map(rows.map(r => [r.d, r.n])); const days = Array.from({ length: 14 }, (_, i) => { const d = new Date(Date.now() - (13 - i) * 864e5); return d.toISOString().slice(0, 10); }); const v = days.map(d => by.get(d) || 0); const p = v.slice(0, 7), c = v.slice(7); const sp = p.reduce((a, b) => a + b, 0), sc = c.reduce((a, b) => a + b, 0); return { c: sc, p: sp, change: sp ? ((sc - sp) / sp) * 100 : null, chart: c.map((x, i) => ({ ngày: WD[(new Date(days[7 + i]).getUTCDay() + 6) % 7], 'Tuần này': x, 'Tuần trước': p[i] })) }; }
function yoyRows(years) { const ys = years.filter(y => y.y >= 2000); return ys.map((r, i) => ({ ...r, change: i && ys[i - 1].n ? ((r.n - ys[i - 1].n) / ys[i - 1].n) * 100 : null })); }

export default function Stats() {
  const s = useAsync(async () => { const [d, h] = await Promise.all([get('/statistics/dashboard'), get('/statistics/crawl-health', { hours: 72 })]); return { d: d.data, h: h.data }; }, []);
  const [metric, setMetric] = useState('movies'); const [hm, setHm] = useState('ok'); const [kind, setKind] = useState('makers'); const [picked, setPicked] = useState(null);
  const cmp = useAsync(async () => { const names = picked?.kind === kind ? picked.names : (s.data?.d[kind].slice(0, 2).map(x => x.name) || []); if (names.length < 2) return []; return (await get('/statistics/compare', { kind, names: names.join(',') })).data; }, [kind, JSON.stringify(picked), !!s.data]);
  return <Stack gap="lg"><PageHead title="Thống kê" sub="Số liệu trên toàn kho, tính trực tiếp từ dữ liệu đang có." />
    <Async state={s} skeleton={<SimpleGrid cols={{ base: 1, md: 2 }}><Skeleton h={300} /><Skeleton h={300} /></SimpleGrid>}>{({ d, h }) => {
      const T = d.totals; const total = d.coverage.total; const wk = { movies: weekCompare(d.movies_series), performers: weekCompare(d.performers_series) }; const w = wk[metric];
      const yoy = yoyRows(d.years); const last = yoy.at(-1); const tiers = d.tiers.map(t => ({ name: `Tầng ${t.tier}`, value: t.n, color: ['red.6', 'yellow.6', 'teal.6'][t.tier - 1] || 'gray' }));
      const maker10 = d.makers.slice(0, 10), maker10n = maker10.reduce((a, x) => a + x.n, 0); const donut = [...d.makers.slice(0, 8).map((x, i) => ({ name: x.name, value: x.n, color: ['teal.6', 'blue.5', 'orange.5', 'grape.5', 'cyan.5', 'lime.5', 'pink.5', 'yellow.5'][i] })), { name: 'Khác', value: Math.max(0, d.with_maker - d.makers.slice(0, 8).reduce((a, x) => a + x.n, 0)), color: 'gray.7' }];
      const genre10 = d.genres.slice(0, 10).map(g => ({ thể_loại: g.name, 'tỉ lệ phim': +pct(g.n, total).toFixed(1) }));
      const hours = h.map(x => ({ giờ: new Date(x.hour_at).toLocaleString('vi', { day: '2-digit', hour: '2-digit' }), 'Thành công': x.ok, Lỗi: x.blocked + x.timeout + x.errors })); const list = d[kind]; const sel = (picked && picked.kind === kind ? picked.names : list.slice(0, 2).map(x => x.name)).slice(0, 3); const items = cmp.data || [];
      const radar = AXES.map(([k, label]) => Object.fromEntries([['trục', label], ...items.map(m => [m.name, Math.min(100, m.axes[k])])]));
      const top = (arr, to, lab) => <Card title={lab}><Table verticalSpacing={8} withRowBorders={false}><Table.Tbody>{arr.slice(0, 10).map(x => <Table.Tr key={x.name}><Table.Td><Anchor component={Link} to={`${to}=${encodeURIComponent(x.name)}`} size="sm">{x.name}</Anchor></Table.Td><Table.Td ta="right" className="mono">{fmt(x.n)}</Table.Td></Table.Tr>)}</Table.Tbody></Table></Card>;
      return <Stack gap="lg">
        <div className="kpi-main kpi4">{[['Phim', T.movies, d.movies_series.map(x => x.n), `+${fmt(T.movies_24h)} / 24 giờ`], ['Diễn viên', T.performers, d.performers_series.map(x => x.n), `+${fmt(T.performers_24h)} / 24 giờ`], ['Magnet', T.magnets, null, `${fmt(d.coverage.magnets)} phim có link`], ['Phim cần xem lại', d.coverage.needs_review, null, 'đang chờ duyệt']].map(([l, v, dd, dl]) => <Kpi key={l} label={l} value={fmt(v)} delta={dl} data={dd} />)}</div>
        <Title order={4} fw={600}>So sánh theo thời gian</Title>
        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
          <Card title="7 ngày gần nhất so với 7 ngày trước" sub="Mỗi đường là một tuần, cùng thứ tự ngày" right={<SegmentedControl size="xs" value={metric} onChange={setMetric} data={METRICS.map(([value, label]) => ({ value, label }))} aria-label="Chỉ số so sánh tuần" />}>
            <Group gap="xl" mb="sm" wrap="wrap"><div><Text size="xs" c="dimmed">Tuần này</Text><Text fz={24} fw={600} className="mono">{fmt(w.c)}</Text></div><div><Text size="xs" c="dimmed">Tuần trước</Text><Text fz={24} fw={600} className="mono">{fmt(w.p)}</Text></div><div><Text size="xs" c="dimmed">Thay đổi</Text><Delta v={w.change} /></div></Group>
            <LineChart h={230} data={w.chart} dataKey="ngày" series={[{ name: 'Tuần này', color: 'teal.6' }, { name: 'Tuần trước', color: 'gray.5', strokeDasharray: '5 5' }]} curveType="monotone" withLegend tickLine="none" gridAxis="y" withDots={false} />
            <SimpleGrid cols={2} mt="md" spacing="xs">{METRICS.map(([k, l]) => <div key={k}><Text size="xs" c="dimmed">{l}</Text><Delta v={wk[k].change} /></div>)}</SimpleGrid></Card>
          <Card title="Thu thập theo giờ: 3 ngày gần nhất" sub="Số lượt trong mỗi giờ (UTC)" right={<SegmentedControl size="xs" value={hm} onChange={setHm} data={[{ value: 'ok', label: 'Thành công' }, { value: 'err', label: 'Lỗi' }]} aria-label="Loại lượt thu thập" />}>
            <LineChart h={290} data={hours} dataKey="giờ" series={[hm === 'ok' ? { name: 'Thành công', color: 'teal.6' } : { name: 'Lỗi', color: 'red.6' }]} curveType="monotone" withLegend tickLine="none" gridAxis="y" withDots={false} xAxisProps={{ interval: 5 }} /></Card>
        </SimpleGrid>
        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
          <Card title="Phim theo năm phát hành"><BarChart h={260} data={d.years.filter(y => y.y >= 2000).map(y => ({ năm: String(y.y), phim: y.n }))} dataKey="năm" series={[{ name: 'phim', color: 'teal.6' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title="So với năm trước" sub={`${last.y} chưa đủ năm nên số tăng thấp hơn thực tế`}>
            <BarChart h={150} data={yoy.slice(-9).filter(r => r.change != null).map(r => ({ năm: String(r.y), 'tăng (%)': Math.round(r.change) }))} dataKey="năm" series={[{ name: 'tăng (%)', color: 'blue.5' }]} tickLine="none" gridAxis="y" />
            <Table verticalSpacing={6} withRowBorders={false} mt="sm"><Table.Thead><Table.Tr><Table.Th>Năm</Table.Th><Table.Th ta="right">Phim</Table.Th><Table.Th ta="right">So với năm trước</Table.Th></Table.Tr></Table.Thead><Table.Tbody>{[...yoy].reverse().slice(0, 5).map(r => <Table.Tr key={r.y}><Table.Td className="mono">{r.y}{r.y === last.y ? ' *' : ''}</Table.Td><Table.Td ta="right" className="mono">{fmt(r.n)}</Table.Td><Table.Td ta="right"><Delta v={r.change} /></Table.Td></Table.Tr>)}</Table.Tbody></Table><Text size="xs" c="dimmed" mt={4}>* năm chưa đủ</Text></Card>
        </SimpleGrid>
        <Title order={4} fw={600}>Cơ cấu</Title>
        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
          <Card title="10 hãng đầu chiếm" sub={`Trong ${fmt(d.with_maker)} phim có ghi hãng`}><Group justify="center"><DonutChart data={donut} size={190} thickness={26} withTooltip chartLabel={`${pct(maker10n, d.with_maker).toFixed(0)}%`} /></Group>
            <Text size="sm" ta="center" mt="xs">10 hãng đầu có {fmt(maker10n)} phim, bằng <b>{pct(maker10n, d.with_maker).toFixed(1)}%</b> số phim có hãng.</Text></Card>
          <Card title="Thể loại phổ biến" sub="Tỉ lệ phim gắn thẻ đó (một phim có nhiều thẻ nên cộng lại vượt 100%)"><BarChart h={300} data={genre10} dataKey="thể_loại" orientation="vertical" series={[{ name: 'tỉ lệ phim', color: 'teal.6' }]} tickLine="none" gridAxis="x" valueFormatter={v => `${v}%`} yAxisProps={{ width: 110 }} /></Card>
        </SimpleGrid>
        <Title order={4} fw={600}>Phân bố trên toàn kho</Title>
        <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
          <Card title="Thời lượng" sub={`${fmt(d.coverage.runtime)} phim có thời lượng`}><BarChart h={200} data={['<60', '60-89', '90-119', '120-179', '180+'].map(k => ({ khoảng: k, phim: d.runtime.find(x => x.k === k)?.n || 0 }))} dataKey="khoảng" series={[{ name: 'phim', color: 'teal.6' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title="Điểm cộng đồng (thang 0–10)" sub={`${fmt(d.coverage.score)} phim có điểm`}><BarChart h={200} data={['0-2', '2-4', '4-6', '6-8', '8-10'].map(k => ({ khoảng: k, phim: d.score.find(x => x.k === k)?.n || 0 }))} dataKey="khoảng" series={[{ name: 'phim', color: 'yellow.5' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title="Phim theo tháng phát hành" sub="12 tháng gần nhất"><BarChart h={200} data={d.months.map(x => ({ tháng: x.k, phim: x.n }))} dataKey="tháng" series={[{ name: 'phim', color: 'blue.5' }]} tickLine="none" gridAxis="y" /></Card>
          <Card title="Số diễn viên mỗi phim"><BarChart h={200} data={['0', '1', '2', '3-4', '5+'].map(k => ({ khoảng: k, phim: d.cast.find(x => x.k === k)?.n || 0 }))} dataKey="khoảng" series={[{ name: 'phim', color: 'violet.5' }]} tickLine="none" gridAxis="y" /></Card>
        </SimpleGrid>
        <Title order={4} fw={600}>So sánh các mục</Title>
        <Card title="Biểu đồ tương quan" sub="Chọn tối đa 3 mục cùng loại. Mỗi trục từ 0 đến 100." right={<Select size="xs" w={150} value={kind} onChange={v => { setKind(v); setPicked(null); }} data={Object.entries(KINDS).map(([value, [label]]) => ({ value, label }))} allowDeselect={false} aria-label="Loại mục so sánh" />}>
          <MultiSelect size="xs" mb="md" searchable value={sel} onChange={v => setPicked({ kind, names: v.slice(0, 3) })} data={list.map(x => ({ value: x.name, label: `${x.name} (${fmt(x.n)})` }))} maxValues={3} placeholder="Chọn 2–3 mục" aria-label="Các mục cần so sánh" />
          {items.length >= 2 ? <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg" style={{ alignItems: 'center' }}>
            <RadarChart h={340} data={radar} dataKey="trục" withPolarGrid withPolarAngleAxis withLegend series={items.map((m, i) => ({ name: m.name, color: COLORS[i], opacity: 0.25 }))} />
            <Table verticalSpacing={8} withRowBorders><Table.Thead><Table.Tr><Table.Th></Table.Th>{items.map((m, i) => <Table.Th key={m.name} ta="right"><Anchor component={Link} to={`/movies?${KINDS[kind][1]}=${encodeURIComponent(m.name)}`} size="sm" c={COLORS[i]}>{m.name}</Anchor></Table.Th>)}</Table.Tr></Table.Thead>
              <Table.Tbody><Table.Tr><Table.Td fw={700}>Số phim (kho)</Table.Td>{items.map(m => <Table.Td key={m.name} ta="right" className="mono">{fmt(m.n)}</Table.Td>)}</Table.Tr><Table.Tr><Table.Td fw={700}>Tỉ lệ trên kho</Table.Td>{items.map(m => <Table.Td key={m.name} ta="right" className="mono">{(m.share * 100).toFixed(1)}%</Table.Td>)}</Table.Tr>
                {AXES.map(([k, label]) => <Table.Tr key={k}><Table.Td fw={700}>{label}</Table.Td>{items.map(m => <Table.Td key={m.name} ta="right" className="mono">{Math.min(100, m.axes[k])}</Table.Td>)}</Table.Tr>)}</Table.Tbody></Table></SimpleGrid> : <Text size="sm" c="dimmed">Chọn ít nhất 2 mục để so sánh.</Text>}
          <Text size="xs" c="dimmed" mt="sm">Quy mô tính theo căn bậc hai của số phim so với mục lớn nhất cùng loại. Các trục còn lại là tỉ lệ phim của mục đó, tính trên toàn kho.</Text></Card>
        <Title order={4} fw={600}>Chất lượng dữ liệu</Title>
        <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
          <Card title="Độ đầy đủ dữ liệu"><Group justify="center"><DonutChart data={tiers} size={200} thickness={28} withTooltip chartLabel={fmt(total)} /></Group><Group justify="center" gap="md">{tiers.map(t => <Group key={t.name} gap={8}><span className="dot" style={{ background: `var(--mantine-color-${t.color.replace('.', '-')})` }} /><Text size="xs">{t.name}: {fmt(t.value)}</Text></Group>)}</Group></Card>
          <Card title={`Độ phủ từng trường (${fmt(total)} phim)`}><Stack gap="sm">{FIELDS.map(([lab, k]) => { const p = Math.round(pct(d.coverage[k], total)); return <div key={k}><Group justify="space-between"><Text size="sm">{lab}</Text><Text size="sm" className="mono">{p}%</Text></Group><Progress value={p} color={p > 80 ? 'teal' : p > 40 ? 'yellow' : 'red'} size="sm" /></div>; })}</Stack><Text size="xs" c="dimmed" mt="sm">{fmt(d.coverage.needs_review)} phim đang chờ xem lại.</Text></Card>
        </SimpleGrid>
        <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }} spacing="lg">{top(d.makers, '/movies?maker', 'Hãng nhiều phim nhất')}{top(d.genres, '/movies?genre', 'Thể loại phổ biến')}{top(d.series, '/movies?series', 'Loạt phim lớn nhất')}</SimpleGrid>
      </Stack>; }}</Async></Stack>;
}
