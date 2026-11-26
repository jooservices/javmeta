import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react'; import { useLocalStorage } from '@mantine/hooks';
import { Collapse, ActionIcon, Tooltip, Box, Progress, Group, Text, SimpleGrid, Stack, TextInput, Badge, Button, Skeleton, ScrollArea, Paper, Title, Anchor, SegmentedControl } from '@mantine/core';
import { AreaChart, Sparkline } from '@mantine/charts';
import { IconChevronUp, IconChevronDown, IconSearch, IconSparkles } from '@tabler/icons-react';
import { SectionLink, useAsync, Async, MovieCard, QuickView, MovieGridSkeleton, usePersonal } from '../components.jsx';
import { loadHome, fmt, getMovie } from '../shim.js';
import { Kpi } from '../kpi.jsx'; import { registerList } from '../lists.js'; import { TodayPicks, WatchableRow } from '../features.jsx';

export default function Home() {
  const [pv, setPv] = useState(null); const st = usePersonal(); const [stats, setStats] = useLocalStorage({ key: 'xc.home.stats', defaultValue: true });
  const s = useAsync(loadHome, []);
  return <Stack gap="lg">
    <Async state={s} skeleton={<Stack><SimpleGrid cols={{ base: 2, md: 4 }}>{[1, 2, 3, 4].map(i => <Skeleton key={i} h={86} />)}</SimpleGrid><MovieGridSkeleton /></Stack>}>{(d) => { const { ov, sm, src } = d; const hours = d.health.map(h => ({ h: new Date(h.hour_at).toLocaleString('vi', { hour: '2-digit', day: '2-digit', month: '2-digit' }), ok: h.ok, lỗi: h.blocked + h.timeout + h.errors })); return <Stack gap="lg">
      <Group justify="space-between" wrap="nowrap"><div><Text fw={600}>Số liệu hệ thống</Text>{!stats && <Text size="xs" c="dimmed">Đang ẩn thẻ số liệu và biểu đồ thu thập</Text>}</div><Tooltip label={stats ? 'Ẩn số liệu' : 'Hiện số liệu'}><ActionIcon variant="default" onClick={() => setStats(v => !v)} aria-expanded={stats} aria-label={stats ? 'Ẩn số liệu' : 'Hiện số liệu'}>{stats ? <IconChevronUp size={16} /> : <IconChevronDown size={16} />}</ActionIcon></Tooltip></Group>
      <Collapse expanded={stats}><Stack gap="lg">
      <div className="split23 kpi-split"><div className="kpi-main">
        <Kpi label="Phim" value={fmt(ov.movies)} delta={`+${fmt(ov.movies_24h)} / 24 giờ`} data={d.mseries.map(x => x.count)} />
        <Kpi label="Diễn viên" value={fmt(ov.performers)} delta={`+${fmt(ov.performers_24h)} / 24 giờ`} data={d.pseries.map(x => x.count)} />
        <Kpi label="Magnet đã lưu" value={fmt(ov.magnets)} delta={`${fmt(ov.movies_with_download)} phim có link`} />
        <Kpi label="Ảnh trong thư viện" value={fmt(ov.gallery_photos)} delta={`${fmt(ov.performers_with_photo)} diễn viên có ảnh`} />
      </div><div className="kpi-side">
        <Kpi wide label="Phim mới 24 giờ" value={fmt(ov.movies_24h)} delta={`${fmt(ov.movies_1h)} trong 1 giờ`} data={d.hseries.map(x => x.count)} color="blue.5" />
        <Kpi wide label="Nguồn đang hoạt động" value={`${src.active}/${src.total}`} delta={`${src.total - src.active} nguồn đang tắt`} bar={(src.active / src.total) * 100} />
      </div></div>
      <div className="split23">
      <Paper className="tile"><Group justify="space-between" mb="xs"><div><Text fw={600}>Sức khỏe thu thập</Text><Text size="xs" c="dimmed">72 giờ gần nhất · lượt tải thành công và lỗi theo giờ</Text></div><SectionLink to="/admin/sources">Chi tiết</SectionLink></Group>
        <AreaChart h={210} data={hours} dataKey="h" series={[{ name: 'ok', color: 'teal.6', label: 'Thành công' }, { name: 'lỗi', color: 'red.6', label: 'Lỗi' }]} withDots={false} xAxisProps={{ interval: 11, tickMargin: 8 }} gridAxis="y" curveType="monotone" tickLine="none" /></Paper>
        <Paper className="tile"><Group justify="space-between" mb="md" wrap="nowrap"><div><Text fw={600}>Nguồn đóng góp</Text><Text size="xs" c="dimmed">Phim mới ghi nhận trong 24 giờ</Text></div><SectionLink to="/admin/sources">Chi tiết</SectionLink></Group>
          <Stack gap="sm">{d.contrib.slice(0, 6).map(x => <div key={x.slug}><Group justify="space-between" mb={4} wrap="nowrap"><Text size="sm" truncate>{x.name}</Text><Text size="sm" className="mono">{fmt(x.n)}</Text></Group><Progress value={(x.n / Math.max(...d.contrib.map(y => y.n))) * 100} size="sm" color="teal" /></div>)}</Stack></Paper>
      </div>
      </Stack></Collapse>
      <TodayPicks st={st} onPreview={setPv} pool={d.pool} />
      <WatchableRow onPreview={setPv} />
      <div><Group justify="space-between" mb="md"><Title order={4} fw={600}>Mới cập nhật</Title><SectionLink to="/updates">Xem tất cả</SectionLink></Group><div className="pgrid home" data-list-id="home-upd" data-list-label="Mới cập nhật">{(registerList('home-upd', d.upd.map(m => m.code)), d.upd).map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div></div>
      <div><Group justify="space-between" mb="md"><Title order={4} fw={600}>Phát hành gần đây</Title><SectionLink to="/calendar">Lịch</SectionLink></Group><div className="pgrid home" data-list-id="home-rel" data-list-label="Phát hành gần đây">{(registerList('home-rel', d.rel.map(m => m.code)), d.rel).map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div></div>
      {st.recent.length > 0 && <div><Title order={4} fw={600} mb="md">Xem gần đây</Title><div className="pgrid home">{st.recent.map(getMovie).filter(Boolean).slice(0, 8).map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div></div>}
      <div><Title order={4} fw={600} mb="md">Thể loại phổ biến</Title><Group gap={8}>{d.genres.map(g => <Badge key={g.slug} component={Link} to={`/movies?genre=${encodeURIComponent(g.name)}`} variant="light" color="gray" size="lg" style={{ cursor: 'pointer' }}>{g.name} <span className="dim">{fmt(g.count)}</span></Badge>)}</Group></div>
    </Stack>; }}</Async><QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
