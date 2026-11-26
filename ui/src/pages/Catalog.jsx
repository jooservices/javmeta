import { Link, useNavigate } from 'react-router-dom';
import { LazyImg } from '../lazyimg.jsx';
import { useState, useEffect } from 'react';
import { Stack, Group, Text, Tabs, TextInput, Table, Badge, Progress, SegmentedControl, Switch, Chip, Skeleton } from '@mantine/core';
import { IconSearch, IconList, IconPhoto } from '@tabler/icons-react';
import { useAsync, Async, PageHead, TableSkeleton, Empty, Pager } from '../components.jsx';
import { get } from '../data/api.js'; import { fmt } from '../shim.js';
import { useDebouncedValue } from '@mantine/hooks';

// the eight catalogues: five of movies, three of performers (URL = /genres, /makers, /cups ...)
export const KINDS = { genres: ['Thể loại', 'genre'], makers: ['Hãng', 'maker'], series: ['Loạt phim', 'series'], labels: ['Nhãn', 'label'], years: ['Năm', 'year'], cups: ['Cup', 'cup'], 'blood-types': ['Nhóm máu', 'blood_type'], locations: ['Nơi sống', 'location'] };
const PERF = new Set(['cups', 'blood-types', 'locations']);
const linkOf = (k, nm) => (PERF.has(k) ? `/performers?${KINDS[k][1]}=${encodeURIComponent(nm)}` : k === 'years' ? `/movies?released_from=${nm}&released_to=${nm}` : `/movies?${KINDS[k][1]}=${encodeURIComponent(nm)}`);
const SCRIPTS = [['all', 'Tất cả'], ['latin', 'Latin'], ['ja', '日本語'], ['zh', '中文'], ['other', 'Khác']];

export default function Catalog({ kind = 'genres' }) {
  const nav = useNavigate(); const k = KINDS[kind] ? kind : 'genres'; const [q, setQ] = useState(''); const [dq] = useDebouncedValue(q, 250); const [sort, setSort] = useState('-count'); const [view, setView] = useState(localStorage.getItem('xc.catview') || 'text');
  const [script, setScript] = useState('all'); const [letter, setLetter] = useState(''); const [hide, setHide] = useState(false); const [page, setPage] = useState(1); const [per, setPer] = useState(60);
  useEffect(() => { setPage(1); setLetter(''); setScript('all'); }, [k]); useEffect(() => setPage(1), [dq, sort, script, letter, hide, per]);
  const idx = useAsync(() => get(`/catalogs/${k}/index`, { min_count: hide ? 3 : '' }).then(r => r.data), [k, hide]);
  const s = useAsync(() => get(`/catalogs/${k}`, { q: dq, sort, script, letter, min_count: hide ? 3 : '', page, per_page: per }), [k, dq, sort, script, letter, hide, page, per]);
  const letters = script !== 'all' ? (idx.data?.find(x => x.script === script)?.letters || []) : [];
  return <Stack gap="lg"><PageHead title="Danh mục" sub="Số phim tính trên toàn kho. Bấm một mục để lọc danh sách." />
    <Tabs value={k} onChange={v => nav(`/${v}`)}><Tabs.List>{Object.entries(KINDS).map(([key, [lab]]) => <Tabs.Tab key={key} value={key}>{lab}</Tabs.Tab>)}</Tabs.List></Tabs>
    <Group wrap="wrap"><TextInput leftSection={<IconSearch size={14} />} placeholder="Lọc trong danh mục" value={q} onChange={e => setQ(e.target.value)} w={260} /><SegmentedControl size="sm" value={sort} onChange={setSort} data={[{ value: '-count', label: 'Nhiều phim' }, { value: 'name', label: 'Tên A–Z' }]} />
      <SegmentedControl size="sm" value={view} onChange={v => { setView(v); localStorage.setItem('xc.catview', v); }} data={[{ value: 'text', label: <Group gap={6} wrap="nowrap"><IconList size={16} /><span>Chữ</span></Group> }, { value: 'thumb', label: <Group gap={6} wrap="nowrap"><IconPhoto size={16} /><span>Ảnh</span></Group> }]} aria-label="Kiểu hiển thị" />
      <Switch label="Ẩn mục dưới 3" checked={hide} onChange={e => setHide(e.currentTarget.checked)} /></Group>
    {!PERF.has(k) && k !== 'years' && <Stack gap={8}><Group gap={8}>{SCRIPTS.map(([v, lab]) => <Chip key={v} checked={script === v} onChange={() => { setScript(v); setLetter(''); }} variant="light" size="sm">{lab}{v !== 'all' && idx.data ? ` · ${fmt(idx.data.find(x => x.script === v)?.count || 0)}` : ''}</Chip>)}</Group>
      {letters.length > 0 && <Group gap={6}><Chip checked={!letter} onChange={() => setLetter('')} size="xs" variant="outline">Tất cả</Chip>{letters.slice(0, 80).map(l => <Chip key={l.letter} checked={letter === l.letter} onChange={() => setLetter(letter === l.letter ? '' : l.letter)} size="xs" variant="outline">{l.letter} · {l.count}</Chip>)}</Group>}</Stack>}
    <Async state={s} skeleton={<TableSkeleton rows={12} />}>{res => { const rows = res.data; const max = Math.max(...rows.map(r => r.count), 1);
      if (!rows.length) return <Empty title="Không có mục nào khớp" hint="Bỏ bớt bộ lọc hoặc đổi từ khoá." />;
      return <Stack gap="md">{view === 'thumb' ? <div className="pgrid fixed">{rows.map(r => <Link key={r.slug} to={linkOf(k, r.name)} aria-label={`${KINDS[k][0]} ${r.name}`} style={{ textDecoration: 'none', color: 'inherit' }}>
        <div className="poster">{r.cover_url ? <LazyImg src={r.cover_url} alt={r.name}  /> : <Group h="100%" justify="center" gap={4} style={{ flexDirection: 'column' }}><Text fz={32} fw={600} c="dimmed">{String(r.name).slice(0, 2).toUpperCase()}</Text></Group>}</div>
        <Group justify="space-between" mt={8} wrap="nowrap" gap="xs"><Text size="sm" fw={500} truncate>{r.name}</Text><Badge size="md" variant="light" style={{ flexShrink: 0 }}>{fmt(r.count)} {PERF.has(k) ? 'người' : 'phim'}</Badge></Group></Link>)}</div>
        : <Table highlightOnHover><Table.Thead><Table.Tr><Table.Th>{KINDS[k][0]}</Table.Th><Table.Th w="40%"></Table.Th><Table.Th ta="right">{PERF.has(k) ? 'Số diễn viên' : 'Số phim'}</Table.Th></Table.Tr></Table.Thead><Table.Tbody>{rows.map(r => <Table.Tr key={r.slug}><Table.Td><Text component={Link} to={linkOf(k, r.name)} c="teal.3" size="sm">{r.name}</Text></Table.Td><Table.Td><Progress value={(r.count / max) * 100} size="sm" color="teal" /></Table.Td><Table.Td ta="right" className="mono">{fmt(r.count)}</Table.Td></Table.Tr>)}</Table.Tbody></Table>}
        <Pager res={{ page: res.meta.pagination.page, per: res.meta.pagination.per_page, total: res.meta.pagination.total, pages: res.meta.pagination.pages }} set={patch => { if (patch.page) setPage(patch.page); if (patch.per_page) setPer(+patch.per_page); }} sizes={[60, 120, 200]} /></Stack>; }}</Async></Stack>;
}
