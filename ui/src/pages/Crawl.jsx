import { Link } from 'react-router-dom';
import { useState } from 'react';
import { Stack, Group, Text, Badge, Table, TextInput, SegmentedControl, Paper, SimpleGrid, Tooltip } from '@mantine/core';
import { IconSearch } from '@tabler/icons-react';
import { useAsync, Async, PageHead, TableSkeleton, Empty, HeaderAction } from '../components.jsx';
import { loadOps, loadCatalog, fmt } from '../shim.js';
export const SNAP = new Date();
export function srcStatus(s) {
  if (!s.enabled) return ['off', 'Tắt', 'gray']; const ok = s.last_success_at && new Date(s.last_success_at), er = s.last_error_at && new Date(s.last_error_at); const age = ok ? (SNAP - ok) / 60000 : Infinity;
  if (age > 60 && er && (!ok || er > ok)) return ['bad', 'Hỏng', 'red']; if ((er && ok && er > ok) || age > 30) return ['warn', 'Lỗi một phần', 'yellow']; return ['ok', 'Ổn', 'teal'];
}
export const ago2 = iso => { if (!iso) return '—'; const m = (SNAP - new Date(iso)) / 60000; return m < 60 ? `${Math.round(m)} phút trước` : m < 1440 ? `${Math.round(m / 60)} giờ trước` : `${Math.round(m / 1440)} ngày trước`; };
export default function Crawl() {
  const [q, setQ] = useState(''); const [f, setF] = useState('all'); const s = useAsync(async () => { const [o, c] = await Promise.all([loadOps(), loadCatalog()]); return o.sources; }, []);
  return <Stack gap="lg"><PageHead title="Thu thập & nguồn" sub="Chỉ đọc. Quản lý chi tiết nằm ở Command Center." right={<HeaderAction to="/admin">Mở Command Center</HeaderAction>} />
    <Async state={s} skeleton={<TableSkeleton rows={14} />}>{rows => { const st = rows.map(r => ({ ...r, st: srcStatus(r) })); const cnt = k => st.filter(r => r.st[0] === k).length; const vis = st.filter(r => (f === 'all' || r.st[0] === f) && (r.slug + r.name).toLowerCase().includes(q.toLowerCase()));
      return <><SimpleGrid cols={{ base: 2, md: 4 }}>{[['Ổn', 'ok', 'teal'], ['Lỗi một phần', 'warn', 'yellow'], ['Hỏng', 'bad', 'red'], ['Tắt', 'off', 'gray']].map(([l, k, c]) => <Paper className="tile" key={k} onClick={() => setF(f === k ? 'all' : k)} style={{ cursor: 'pointer', borderColor: f === k ? 'var(--mantine-color-teal-6)' : undefined }}><Text size="xs" c="dimmed">{l}</Text><Text fz={26} fw={600} c={c} className="mono">{cnt(k)}</Text></Paper>)}</SimpleGrid>
        <Stack gap="md"><Group><TextInput leftSection={<IconSearch size={14} />} placeholder="Lọc nguồn" value={q} onChange={e => setQ(e.target.value)} w={240} /><SegmentedControl size="sm" value={f} onChange={setF} data={[{ value: 'all', label: 'Tất cả' }, { value: 'ok', label: 'Ổn' }, { value: 'warn', label: 'Lỗi một phần' }, { value: 'bad', label: 'Hỏng' }, { value: 'off', label: 'Tắt' }]} /></Group>
        {vis.length ? <div className="scroll-x"><Table miw={980} highlightOnHover verticalSpacing={8}><Table.Thead><Table.Tr><Table.Th>Nguồn</Table.Th><Table.Th>Trạng thái</Table.Th><Table.Th ta="right">Nghỉ (s)</Table.Th><Table.Th ta="right">Chờ</Table.Th><Table.Th ta="right">Xong</Table.Th><Table.Th ta="right">Lỗi</Table.Th><Table.Th>Thành công cuối</Table.Th><Table.Th>Lỗi cuối</Table.Th></Table.Tr></Table.Thead>
          <Table.Tbody>{vis.map(r => <Table.Tr key={r.slug}><Table.Td><Text size="sm" fw={500}>{r.name}</Text><Text size="xs" c="dimmed" className="mono">{r.slug}</Text></Table.Td><Table.Td><Badge variant="light" color={r.st[2]}>{r.st[1]}</Badge>{r.circuit_state && r.circuit_state !== 'closed' && <Badge ml={4} variant="outline" color="orange">circuit {r.circuit_state}</Badge>}</Table.Td><Table.Td ta="right" className="mono">{(+r.gap_seconds_current).toFixed(1)}</Table.Td><Table.Td ta="right" className="mono">{fmt(r.pending)}</Table.Td><Table.Td ta="right" className="mono">{fmt(r.done)}</Table.Td><Table.Td ta="right" className="mono" c={r.failed > 500 ? 'red' : undefined}>{fmt(r.failed)}</Table.Td><Table.Td>{ago2(r.last_success_at)}</Table.Td><Table.Td maw={260}><Tooltip label={r.last_error} disabled={!r.last_error} multiline w={340}><Text size="xs" c="dimmed" truncate>{r.last_error ? `${ago2(r.last_error_at)} · ${r.last_error}` : '—'}</Text></Tooltip></Table.Td></Table.Tr>)}</Table.Tbody></Table></div> : <Empty title="Không có nguồn khớp" />}</Stack></>; }}</Async></Stack>;
}
