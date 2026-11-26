import { Link } from 'react-router-dom';
import { useState, useEffect } from 'react';
import { Stack, Group, Text, Button, ActionIcon, Paper, SimpleGrid, Tooltip, Badge, Modal } from '@mantine/core';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-react';
import { useAsync, Async, PageHead, MovieCard, QuickView } from '../components.jsx';
import { loadCatalog, calendarMonth, calendarDay, fmt } from '../shim.js';
export default function Calendar() {
  const now = new Date(); const [ym, setYm] = useState([now.getFullYear(), now.getMonth()]); const [day, setDay] = useState(null); const [pv, setPv] = useState(null); const [dayMovies, setDayMovies] = useState(null); useEffect(() => { setDayMovies(null); if (day) calendarDay(day).then(setDayMovies); }, [day]); 
  const s = useAsync(async () => { await loadCatalog(); return calendarMonth(ym[0], ym[1]); }, [ym.join()]);
  const first = new Date(ym[0], ym[1], 1); const lead = (first.getDay() + 6) % 7; const days = new Date(ym[0], ym[1] + 1, 0).getDate(); const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  const mv = d => setYm(([y, m]) => { const n = new Date(y, m + d, 1); return [n.getFullYear(), n.getMonth()]; });
  const label0 = first.toLocaleDateString('vi', { month: 'long', year: 'numeric' }); const label = label0.charAt(0).toUpperCase() + label0.slice(1);
  return <Stack gap="lg"><PageHead title="Lịch phát hành" sub="Số phim phát hành mỗi ngày trong kho. Bấm vào một ngày để xem phim." right={<Group gap="xs"><ActionIcon variant="default" onClick={() => mv(-1)} aria-label="Tháng trước"><IconChevronLeft size={16} /></ActionIcon><Text fw={600} w={190} ta="center" style={{ whiteSpace: 'nowrap' }}>{label}</Text><ActionIcon variant="default" onClick={() => mv(1)} aria-label="Tháng sau"><IconChevronRight size={16} /></ActionIcon><Button size="xs" variant="light" onClick={() => setYm([now.getFullYear(), now.getMonth()])}>Hôm nay</Button></Group>} />
    <Async state={s} skeleton={<SimpleGrid cols={7} spacing={4}>{Array.from({ length: 35 }, (_, i) => <Paper key={i} className="tile" h={92} />)}</SimpleGrid>}>{({ counts }) => { const total = Object.values(counts).reduce((a, b) => a + b, 0); return <>
      {total === 0 && <Paper className="tile" ta="center" mb="sm"><Text size="sm">Tháng này không có phim phát hành trong dữ liệu.</Text></Paper>}
      <SimpleGrid cols={7} spacing={4} className="scroll-x" style={{ minWidth: 640 }}>{['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].map(d => <Text key={d} size="xs" c="dimmed" ta="center" fw={600}>{d}</Text>)}
        {Array.from({ length: lead }, (_, i) => <div key={'l' + i} />)}
        {Array.from({ length: days }, (_, i) => { const d = i + 1; const key = `${ym[0]}-${String(ym[1] + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`; const n = counts[key] || 0;
          return <Paper key={d} className="tile" p={8} mih={96} onClick={() => n && setDay(key)} style={{ cursor: n ? 'pointer' : 'default', borderColor: key === todayKey ? '#4fd1c5' : undefined, opacity: n ? 1 : 0.55 }}>
            <Group justify="space-between" mb={4}><Text size="xs" fw={key === todayKey ? 700 : 400}>{d}</Text>{n > 0 && <Badge size="md" variant="light">{fmt(n)}</Badge>}</Group>
            </Paper>; })}</SimpleGrid>
      <Modal opened={!!day} onClose={() => setDay(null)} title={`Phát hành ngày ${day}`} size="xl"><Text size="sm" c="dimmed" mb="sm">{fmt(counts[day])} phim phát hành ngày này{dayMovies && dayMovies.length < counts[day] ? ` · hiện ${dayMovies.length} phim điểm cao nhất` : ''}</Text><div className="pgrid">{(dayMovies || []).map(m => <MovieCard key={m.id} m={m} onPreview={c => { setDay(null); setPv(c); }} />)}</div><Button mt="md" component={Link} to={`/movies?sort=release_date`} variant="light" onClick={() => setDay(null)}>Mở danh sách phim</Button></Modal></>; }}</Async>
    <QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
