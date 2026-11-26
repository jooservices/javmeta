import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Popover, TextInput, Group, Stack, Text, Badge, Avatar, Button, UnstyledButton, SegmentedControl, Box, Loader, ScrollArea, Divider, Skeleton, ActionIcon, Tooltip, Modal } from '@mantine/core';
import { useHotkeys, useDebouncedValue, useMediaQuery } from '@mantine/hooks';
import { IconSearch, IconSparkles, IconMovie, IconUsers, IconPhoto, IconCategory, IconAdjustmentsHorizontal, IconListSearch } from '@tabler/icons-react';
import { loadCatalog, unifiedSearch, photo, fmt, personal } from './shim.js';
import { QuickView, QuickIcon } from './components.jsx'; import { Mark, Head, Row, PhotoGrid } from './results.jsx'; import { PhotoViewer } from './features.jsx';
import { PerformerQuick } from './detail.jsx';

const SCOPES = [['all', 'Tất cả'], ['movies', 'Phim'], ['performers', 'Diễn viên'], ['photos', 'Ảnh']];
export function Omnibox() {
  const nav = useNavigate(); const [v, setV] = useState(''); const [open, setOpen] = useState(false); const [hi, setHi] = useState(-1); const [res, setRes] = useState(null); const [scope, setScope] = useState('all'); const [quick, setQuick] = useState(null); const [dropW, setDropW] = useState(980); const ref = useRef(); const quickOn = useRef(false);
  const wide = useMediaQuery('(min-width: 900px)'); const [dv] = useDebouncedValue(v, 140); useEffect(() => { if (dv.trim().length < 2) { setRes(null); return; } let on = true; unifiedSearch(dv, { per_page: 4 }).then(r => on && setRes(r)).catch(() => on && setRes(null)); return () => { on = false; }; }, [dv]);
  useHotkeys([['mod+K', () => ref.current?.focus()], ['/', e => { e.preventDefault(); ref.current?.focus(); }]]);
  const shown = open && v.trim().length >= 2;
  useEffect(() => { if (!shown) return; const fit = () => { const l = ref.current?.getBoundingClientRect().left ?? 12; setDropW(Math.max(320, Math.min(760, window.innerWidth - l - 24))); }; fit(); window.addEventListener('resize', fit); return () => window.removeEventListener('resize', fit); }, [shown]);
  const go = to => { setOpen(false); ref.current?.blur(); nav(to); };
  const TAB = { movies: 'movies', performers: 'performers', photos: 'photos' }; const all = (extra = {}) => `/search?${new URLSearchParams({ q: v.trim(), ...(TAB[scope] && TAB[scope] !== 'movies' ? { tab: TAB[scope] } : {}), ...extra })}`;
  const openQuick = x => { quickOn.current = true; setQuick(x); if (x.type === 'photo') personal.noteView('photo', x.full || x.src, x.label); };
  const closeQuick = () => { quickOn.current = false; setQuick(null); setOpen(true); setTimeout(() => ref.current?.focus(), 60); };
  const items = []; const add = to => { items.push(to); return items.length - 1; };
  const q = dv; const mv = res?.movies, pr = res?.performers, ph = res?.photos, it = res?.interp;
  const showM = scope === 'all' || scope === 'movies', showP = scope === 'all' || scope === 'performers', showPh = scope === 'all' || scope === 'photos';
  const mRow = (m, k) => { const i = add(`/movies/${m.code}`); return <Row key={k + m.id} active={hi === i} onHover={() => setHi(i)} onClick={() => go(`/movies/${m.code}`)} onQuick={() => openQuick({ type: 'movie', code: m.code })} thumb={m.cover} title={m.code} sub={`${(m.en || m.jp || '').replace(m.code, '').trim()}${m.maker ? ' · ' + m.maker : ''}`} right={m.date?.slice(0, 4)} q={q} />; };
  const pRow = (p, k) => { const i = add(`/performers/${p.slug || p.id}`); return <Row key={k + p.id} active={hi === i} onHover={() => setHi(i)} onClick={() => go(`/performers/${p.slug || p.id}`)} onQuick={() => openQuick({ type: 'performer', id: p.id })} thumb={photo(p)} round title={p.name} sub={[p.kanji, p.location].filter(Boolean).join(' · ')} right={`${p.count} phim`} q={q} />; };
  const counts = { all: null, movies: mv?.total, performers: pr?.total, photos: ph?.total };
  const photoQuick = x => openQuick({ type: 'photo', ...x }); const photoOpen = x => go(x.to);
  const nRows = scope === 'all' ? 3 : 4; const Body = res && <Stack gap={0}>
    {it.conditions.length > 0 && <><Box px="md" py="sm"><Group gap="xs"><IconSparkles size={16} color="#4fd1c5" /><Text size="xs" c="dimmed">Tôi hiểu là</Text>{it.conditions.map(c => <Badge key={c.id} variant="outline" color="teal" size="lg" maw="100%" style={{ textTransform: 'none' }}>{c.k}: {c.v}</Badge>)}</Group></Box><Divider /></>}
    {showM && <><Head icon={IconMovie} label="Phim" count={mv.total} onAll={() => go(all({ tab: '' }))} />{mv.total ? mv.items.slice(0, nRows).map(m => mRow(m, 'm')) : <Text size="sm" c="dimmed" px="md" py="xs">Không có phim khớp.</Text>}</>}
    {showP && <><Head icon={IconUsers} label="Diễn viên" count={pr.total} onAll={() => go(all({ tab: 'performers' }))} />{pr.total ? pr.items.slice(0, nRows).map(p => pRow(p, 'p')) : <Text size="sm" c="dimmed" px="md" py="xs">Không có diễn viên khớp.</Text>}</>}
    {showPh && <><Head icon={IconPhoto} label="Ảnh" count={ph.total} onAll={() => go(all({ tab: 'photos' }))} />{ph.items.length ? <PhotoGrid items={ph.items.slice(0, scope === 'photos' ? 12 : 6)} onQuick={photoQuick} onOpen={photoOpen} /> : <Text size="sm" c="dimmed" px="md" py="xs">Không có ảnh khớp.</Text>}</>}
    {scope === 'all' && res.catalog.length > 0 && <><Head icon={IconCategory} label="Danh mục" /><Group gap="sm" px="md" py="xs" wrap="wrap" pb="md">{res.catalog.slice(0, 8).map(c => <Badge key={c.kind + c.name} component="a" href={`/search?${new URLSearchParams({ [{ genres: 'genre', makers: 'maker', series: 'series', labels: 'label' }[c.kind]]: c.name })}`} onClick={e => { e.preventDefault(); go(`/search?${new URLSearchParams({ [{ genres: 'genre', makers: 'maker', series: 'series', labels: 'label' }[c.kind]]: c.name })}`); }} variant="light" color="gray" size="lg" style={{ cursor: 'pointer', textTransform: 'none' }}>{{ genres: 'Thể loại', makers: 'Hãng', series: 'Series', labels: 'Nhãn' }[c.kind]}: <Mark text={c.name} q={q} /></Badge>)}</Group></>}
  </Stack>;
  const onKey = e => { if (e.key === 'ArrowDown') { e.preventDefault(); setHi(h => Math.min(h + 1, items.length - 1)); } else if (e.key === 'ArrowUp') { e.preventDefault(); setHi(h => Math.max(h - 1, -1)); } else if (e.key === 'Enter') { if (hi >= 0 && items[hi]) go(items[hi]); else if (v.trim()) go(all()); } else if (e.key === 'Escape') setOpen(false); };
  return <>
    <Popover opened={shown} position="bottom-start" offset={8} width={wide ? dropW : Math.max(320, (typeof window !== 'undefined' ? window.innerWidth : 800) - 24)} shadow="xl" withinPortal middlewares={{ shift: { padding: 12 }, flip: false }}>
      <Popover.Target><TextInput ref={ref} value={v} w="100%" maw={620} placeholder="Tìm phim, diễn viên, ảnh…" leftSection={<IconSearch size={16} />} rightSection={v ? <Loader size={14} type="dots" style={{ opacity: v !== dv ? 1 : 0 }} /> : <span className="kbd">⌘K</span>} onChange={e => { setV(e.target.value); setHi(-1); setOpen(true); }} onFocus={() => setOpen(true)} onBlur={e => { if (quickOn.current) return; if (e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.mantine-Popover-dropdown')) return; setTimeout(() => setOpen(false), 180); }} onKeyDown={onKey} aria-label="Tìm kiếm" /></Popover.Target>
      <Popover.Dropdown p={0} onPointerDown={e => e.preventDefault()} style={{ overflow: 'hidden' }}>
        <Group justify="space-between" px="md" py="sm" wrap="wrap" gap="sm" style={{ borderBottom: '1px solid var(--mantine-color-dark-5)' }}>
          <SegmentedControl size="sm" value={scope} onChange={x => { setScope(x); ref.current?.focus(); }} data={SCOPES.map(([k, l]) => ({ value: k, label: counts[k] != null && res ? `${l} ${fmt(counts[k])}` : l }))} />
</Group>
        <ScrollArea.Autosize mah="calc(100vh - 210px)" type="auto">
          {!res ? <Stack p="md"><Skeleton h={80} /><Skeleton h={80} /><Skeleton h={80} /></Stack> : <Box miw={0}>{Body}</Box>}
        </ScrollArea.Autosize>
        <Group justify="space-between" px="md" py="sm" wrap="wrap" gap="sm" style={{ borderTop: '1px solid var(--mantine-color-dark-5)' }}>
          <Group gap="md" visibleFrom="md"><Text size="xs" c="dimmed"><span className="kbd">↑↓</span> chọn</Text><Text size="xs" c="dimmed"><span className="kbd">⏎</span> mở</Text><Text size="xs" c="dimmed"><span className="kbd">Esc</span> đóng</Text></Group>
          <Group gap="sm" ml="auto"><Button leftSection={<IconListSearch size={16} />} onClick={() => go(all())}>Xem tất cả kết quả</Button></Group></Group>
      </Popover.Dropdown></Popover>
    <QuickView zIndex={400} code={quick?.type === 'movie' ? quick.code : null} onClose={closeQuick} />
    <PerformerQuick zIndex={400} id={quick?.type === 'performer' ? quick.id : null} onClose={closeQuick} />
    <Modal opened={quick?.type === 'photo'} onClose={closeQuick} zIndex={400} size="auto" centered title={<Text fw={600}>{quick?.label}</Text>} styles={{ body: { padding: 0 }, content: { maxWidth: 'min(96vw, 1200px)' } }}>
      {quick?.type === 'photo' && <><PhotoViewer index={0} setIndex={() => {}} items={[{ src: quick.full || quick.src, thumb: quick.src, label: quick.label }]} /><Group justify="flex-end" p="md"><Button onClick={() => { const t = quick.to; quickOn.current = false; setQuick(null); setOpen(false); nav(t); }}>{quick.to.startsWith('/movies') ? 'Mở trang phim' : 'Mở hồ sơ diễn viên'}</Button></Group></>}</Modal>
  </>;
}
