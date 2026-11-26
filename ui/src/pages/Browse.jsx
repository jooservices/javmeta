import { useEffect, useMemo, useRef, useState } from 'react';
import { LazyImg } from '../lazyimg.jsx';
import { useNavigate, useLocation, useNavigationType, Link } from 'react-router-dom';
import { notifications } from '@mantine/notifications';
import { Stack, Group, Text, TextInput, Select, Button, Badge, Tabs, Drawer, Modal, Menu, Paper, Loader, ActionIcon, Chip, Divider, Skeleton, Table, SegmentedControl, Avatar } from '@mantine/core';
import { useDebouncedValue, useDisclosure, useMediaQuery } from '@mantine/hooks';
import { IconSearch, IconFilter, IconX, IconBrain, IconBookmark, IconBookmarkPlus, IconTrash, IconRestore, IconLink, IconHistory, IconBulb, IconLayoutGrid, IconTable } from '@tabler/icons-react';
import { Async, useAsync, PageHead, useQuery, Pager, MovieCard, QuickView, Empty, MovieGridSkeleton, usePersonal, Cover, MarkButtons } from '../components.jsx'; import { Mark } from '../mark.jsx'; import { registerList, saveContext } from '../lists.js';
import { PerfCard } from '../perfcard.jsx';
import { SearchFilters } from '../searchfilters.jsx';
import { PhotoViewer } from '../features.jsx';
import { unifiedSearch, facetCounts, perfFacetCounts, relaxHints, didYouMean, loadCatalog, personal, getPerformer, auth, MOVIE_FILTERS, PERF_FILTERS, PERF_MOVIE_FILTERS, performerSearch, perfMovieFacets, ageOfPerformer, effOf, fmt } from '../shim.js';

const LABEL = { genre: 'Thể loại', genre_not: 'Loại trừ', maker: 'Hãng', series: 'Series', label: 'Nhãn', actress_id: 'Diễn viên', code: 'Mã', released_from: 'Từ năm', released_to: 'Đến năm', runtime_min: '≥ phút', runtime_max: '≤ phút', crawled_days: 'Thu thập trong (ngày)', score_min: 'Điểm ≥', tier_min: 'Tầng ≥', censored: 'Kiểm duyệt', has_magnet: 'Link tải', has_performers: 'Có diễn viên', has_watch: 'Xem thử', source: 'Nguồn', genre_all: 'Đủ thể loại', bio_q: 'Tiểu sử', age_min: 'Tuổi ≥', age_max: 'Tuổi ≤', debut_from: 'Ra mắt từ', debut_to: 'Ra mắt đến', cup: 'Cup', blood_type: 'Nhóm máu', location: 'Nơi ở', height_min: 'Cao ≥', height_max: 'Cao ≤', born_from: 'Sinh từ', born_to: 'Sinh đến', bust_min: 'Ngực ≥', bust_max: 'Ngực ≤', waist_min: 'Eo ≥', waist_max: 'Eo ≤', hip_min: 'Mông ≥', hip_max: 'Mông ≤', status: 'Hoạt động', has_image: 'Ảnh', has_bio: 'Tiểu sử', has_profile: 'Hồ sơ', movies_min: 'Số phim ≥' };
const VAL = { censored: { censored: 'Có che', uncensored: 'Không che' }, has_magnet: { 1: 'Có', 0: 'Không' }, has_performers: { 1: 'Có' }, has_watch: { 1: 'Có' }, has_image: { 1: 'Đã có', 0: 'Chưa có' }, has_bio: { 1: 'Đã có', 0: 'Chưa có' }, has_profile: { 1: 'Đã có', 0: 'Chưa có' }, genre_all: { 1: 'Bật' }, status: { active: 'Đang hoạt động', retired: 'Đã nghỉ' } };
const show = (k, v) => (k === 'actress_id' ? v.split(',').map(i => getPerformer(i)?.name || i).join(', ') : VAL[k]?.[v] || v);
const MOVIE_SORTS = [['relevance', 'Liên quan'], ['release_date', 'Ngày phát hành'], ['update_date', 'Lần cập nhật'], ['rating', 'Điểm'], ['runtime', 'Thời lượng'], ['code', 'Mã phim']];
const TITLE = { all: 'Tìm kiếm', movies: 'Phim', performers: 'Diễn viên' };
const PERF_SORT_ALL = [['featured', 'Có ảnh trước'], ['movies', 'Nhiều phim nhất'], ['name', 'Tên A–Z'], ['crawled', 'Mới thu thập'], ['age', 'Tuổi trẻ nhất']];
const PERF_SORTS = [['featured', 'Có ảnh trước'], ['movies', 'Nhiều phim nhất'], ['name', 'Tên A–Z']];
const CAT_KEY = { genres: ['genre', 'Thể loại'], makers: ['maker', 'Hãng'], series: ['series', 'Series'], labels: ['label', 'Nhãn'] };

function PhotoTab({ photos }) {
  const [n, setN] = useState(48); const [open, setOpen] = useState(null); const nav = useNavigate();
  useEffect(() => setN(48), [photos]);
  const people = photos.items.filter(x => x.kind === 'Diễn viên'), rest = photos.items.filter(x => x.kind !== 'Diễn viên'); const all = [...people, ...rest]; const cur = open != null ? all[open] : null;
  if (!all.length) return <Empty title="Không có ảnh khớp" hint="Ảnh lấy từ ảnh bìa, gallery của các phim khớp và ảnh diễn viên." />;
  const tile = (x, i, portrait) => <div key={i} className={portrait ? 'poster cover-portrait' : 'poster'} role="link" tabIndex={0} aria-label={`${x.kind} ${x.label}`} onClick={() => setOpen(i)} onKeyDown={e => e.key === 'Enter' && setOpen(i)}><LazyImg src={x.src} alt={x.label}  /><div className="poster-bar"><span className="code">{x.label}</span></div></div>;
  return <Stack gap="lg">
    {people.length > 0 && <div><Text fw={600} mb="sm">Ảnh diễn viên</Text><div className="pgrid">{people.map((x, i) => tile(x, i, true))}</div></div>}
    {rest.length > 0 && <div><Text fw={600} mb="sm">Ảnh bìa và ảnh phim</Text><div className="pgrid">{rest.slice(0, n).map((x, i) => tile(x, people.length + i, false))}</div>{rest.length > n && <Group justify="center" mt="md"><Button variant="light" onClick={() => setN(v => v + 48)}>Tải thêm ({fmt(rest.length - n)} ảnh)</Button></Group>}</div>}
    <Modal opened={cur != null} onClose={() => setOpen(null)} size="auto" centered zIndex={400} styles={{ body: { padding: 0 }, content: { maxWidth: 'min(96vw, 1200px)' } }} title={<Group gap="sm"><Badge variant="filled" className="mono">{cur?.label}</Badge><Text size="sm" c="dimmed">{cur?.kind}</Text></Group>}>
      {cur && <><PhotoViewer index={open} setIndex={setOpen} items={all.map(x => ({ src: x.full || x.src, thumb: x.src, label: x.label }))} /><Group justify="flex-end" p="md"><Button onClick={() => { const t = cur.to; setOpen(null); nav(t); }}>{cur.to.startsWith('/movies') ? 'Mở trang phim' : 'Mở hồ sơ diễn viên'}</Button></Group></>}</Modal>
  </Stack>;
}

// one engine for two pages: Tìm kiếm (movies, performers, photos, categories in tabs) and Phim (movies only, no tabs)
export default function Browse({ kind = 'all' }) {
  const moviesOnly = kind === 'movies', perfOnly = kind === 'performers';
  const nav = useNavigate(); const [p, set, clear] = useQuery(); const wide = useMediaQuery('(min-width: 1100px)'); const [fo, fh] = useDisclosure(false); const [pv, setPv] = useState(null); const st = usePersonal();
  const [saveOpen, sh] = useDisclosure(false); const [sname, setSname] = useState('');
  const [qv, setQv] = useState(p.q || ''); const [dq] = useDebouncedValue(qv, 250); const sent = useRef(p.q || '');
  useEffect(() => { if ((p.q || '') !== sent.current) { sent.current = p.q || ''; setQv(p.q || ''); } }, [p.q]);
  useEffect(() => { if (dq !== (p.q || '')) { sent.current = dq; set({ q: dq }, false, true); } }, [dq]); // eslint-disable-line
  const boot = useAsync(async () => loadCatalog(), []);
  const [view, setView] = useState(localStorage.getItem('xc.view') || 'grid'); const pathname = useLocation().pathname;
  useEffect(() => { auth.load().then(() => ['genre', 'maker', 'series', 'label'].forEach(k => p[k] && !String(p[k]).includes(',') && personal.noteView(k, p[k], p[k]))); }, [p.genre, p.maker, p.series, p.label]); // eslint-disable-line
  const tab = p.tab || 'movies'; const ignore = p.ig ? p.ig.split('|') : [];
  const manual = useMemo(() => Object.fromEntries((perfOnly ? [...PERF_MOVIE_FILTERS, ...PERF_FILTERS] : [...MOVIE_FILTERS, ...PERF_FILTERS]).filter(k => p[k]).map(k => [k, p[k]])), [JSON.stringify(p)]); // eslint-disable-line
  const data = useAsync(async () => {
    const r = perfOnly ? await performerSearch(p.q || '', { ignore, manual, sort: p.sort, page: p.page, per_page: p.per_page }) : await unifiedSearch(p.q || '', { ignore, manual, sort: p.sort, psort: p.psort, page: p.page, ppage: p.ppage, per_page: p.per_page });
    const [fc, pfc] = await Promise.all([perfOnly ? perfMovieFacets(r) : facetCounts(r.params), perfFacetCounts(r.perfBase, r.perfParams)]);
    const empty = !r.movies.total && !r.performers.total; const dym = p.q && empty ? await didYouMean(p.q) : [];
    const chips0 = [...r.interp.conditions.map(c => ({ key: c.id, auto: true, label: c.k, value: c.v, keys: c.keys })), ...Object.keys(manual).filter(k => LABEL[k]).map(k => ({ key: k, auto: false, label: LABEL[k], value: manual[k], keys: [k] }))];
    const hints = perfOnly ? (r.performers.total === 0 ? (await Promise.all(chips0.map(async c => ({ chip: c, total: (await performerSearch(p.q || '', c.auto ? { ignore: [...ignore, c.key], manual } : { ignore, manual: Object.fromEntries(Object.entries(manual).filter(([k]) => k !== c.key)) })).performers.total })))).filter(h => h.total > 0).sort((a, b) => b.total - a.total).slice(0, 3) : []) : (r.movies.total === 0 ? await relaxHints(r.params, chips0) : []);
    return { r, fc, pfc, dym, hints };
  }, [JSON.stringify(p)]); // eslint-disable-line
  const { r, fc, pfc, dym: dymList, hints: hintList } = data.data || {};
  const loc = useLocation(); const navType = useNavigationType(); const skey = `xc.scroll:${loc.pathname}${loc.search}`; const settled = !!(r && fc && pfc);
  useEffect(() => { // coming back (Back) puts the page where it was
    if (!settled || navType !== 'POP') return; const y = +sessionStorage.getItem(skey); if (!y) return; const id = requestAnimationFrame(() => window.scrollTo(0, y)); return () => cancelAnimationFrame(id);
  }, [settled, skey]); // eslint-disable-line
  useEffect(() => { let t; const on = () => { clearTimeout(t); t = setTimeout(() => sessionStorage.setItem(skey, String(Math.round(window.scrollY))), 120); }; window.addEventListener('scroll', on, { passive: true }); return () => { clearTimeout(t); window.removeEventListener('scroll', on); }; }, [skey]);
  const found = !!(r && (r.movies.total || r.performers.total));
  useEffect(() => { if (!p.q || !found) return; const t = setTimeout(() => personal.pushQuery(p.q), 1500); return () => clearTimeout(t); }, [p.q, found]);
  if (!r || !fc || !pfc) return <Stack gap="lg"><PageHead title={TITLE[kind]} /><Skeleton h={44} /><MovieGridSkeleton n={8} /></Stack>;
  const loading = data.loading;

  const labelOf = k => (perfOnly && PERF_MOVIE_FILTERS.includes(k) ? `Phim ${LABEL[k].toLowerCase()}` : LABEL[k]);
  const chips = [...r.interp.conditions.map(c => ({ key: c.id, auto: true, label: c.k, value: c.v, keys: c.keys, remove: () => set({ ig: [...ignore, c.id].join('|') }, true) })),
    ...Object.keys(manual).filter(k => LABEL[k]).map(k => ({ key: k, label: labelOf(k), value: show(k, manual[k]), keys: [k], remove: () => set({ [k]: '' }) }))];
  const hl = r.params.q || (r.interp.conditions.length ? '' : p.q || ''); const dym = dymList || [];
  const idle = !p.q && !chips.length; const nManual = Object.keys(manual).length; const hints = hintList || [];
  const setPerf = (patch, kp) => set('page' in patch ? { ...patch, ppage: patch.page, page: undefined } : patch, true);
  const copyLink = async () => { const url = window.location.href; try { await navigator.clipboard.writeText(url); } catch { const t = document.createElement('textarea'); t.value = url; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); } notifications.show({ message: 'Đã sao chép liên kết tìm kiếm', color: 'teal', autoClose: 1800 }); };
  const resetAll = () => { setQv(''); sent.current = ''; clear(); };
  const apply = pr => nav(`${pathname}?${new URLSearchParams(pr)}`);
  const filters = <SearchFilters p={p} set={set} fc={fc} pfc={pfc} tab={moviesOnly ? 'movies' : tab} kind={kind} />;
  const addCat = c => { const [k] = CAT_KEY[c.kind]; set({ [k]: k === 'genre' ? [...new Set([...(p.genre ? p.genre.split(',') : []), c.name])].join(',') : c.name }); };
  const DYM = dym.length > 0 && <Stack align="center" gap="xs"><Group gap={8}><IconBulb size={16} color="#fab005" /><Text size="sm">Ý anh là:</Text>{dym.map(d => <Button key={d.name} size="compact-sm" variant="light" color="yellow" onClick={() => setQv(d.name)}>{d.name} <Text span size="xs" c="dimmed" ml={6}>{d.kind}</Text></Button>)}</Group></Stack>;
  const none = (title, hint) => <Empty title={title} hint={hint} action={<Stack align="center" gap="sm">{DYM}<Button variant="light" leftSection={<IconRestore size={16} />} onClick={resetAll}>Xóa hết điều kiện</Button></Stack>} />;

  registerList('browse', r.movies.all ? r.movies.all.map(m => m.code) : []);
  const moviesPanel = <Stack gap="md" data-list-id="browse" data-list-label="Kết quả">
          <Group justify="space-between"><Text size="sm" c="dimmed">Sắp xếp</Text><Group gap="xs"><Select size="xs" w={190} value={p.sort || (r.params.q ? 'relevance' : 'release_date')} onChange={v => set({ sort: v })} data={MOVIE_SORTS.map(([value, label]) => ({ value, label }))} allowDeselect={false} aria-label="Sắp xếp phim" /><SegmentedControl size="xs" value={view} onChange={x => { setView(x); localStorage.setItem('xc.view', x); }} data={[{ value: 'grid', label: <IconLayoutGrid size={16} /> }, { value: 'table', label: <IconTable size={16} /> }]} aria-label="Kiểu hiển thị" /></Group></Group>
          {r.movies.total ? <>{view === 'grid' ? <div className="pgrid">{r.movies.items.map(m => <MovieCard key={m.id} m={m} onPreview={setPv} hl={hl} />)}</div> : <div className="scroll-x"><Table miw={980} highlightOnHover verticalSpacing={8} stickyHeader><Table.Thead><Table.Tr><Table.Th w={92}></Table.Th><Table.Th>Mã</Table.Th><Table.Th>Tiêu đề</Table.Th><Table.Th>Hãng</Table.Th><Table.Th>Phát hành</Table.Th><Table.Th ta="right">Phút</Table.Th><Table.Th ta="right">Điểm</Table.Th><Table.Th>Đánh dấu</Table.Th></Table.Tr></Table.Thead><Table.Tbody>{r.movies.items.map(m => <Table.Tr key={m.id} style={{ cursor: 'pointer' }} onClick={() => setPv(m.code)}><Table.Td><div className="poster" style={{ width: 80 }}><Cover m={m} /></div></Table.Td><Table.Td className="mono"><Link to={`/movies/${m.code}`} onClick={e => e.stopPropagation()}><Mark text={m.code} q={hl} /></Link></Table.Td><Table.Td maw={380}><Text size="sm" className="clamp2"><Mark text={(m.en || m.jp || '').replace(m.code, '').trim()} q={hl} /></Text></Table.Td><Table.Td>{m.maker ? <Mark text={m.maker} q={hl} /> : null}</Table.Td><Table.Td className="mono">{m.date}</Table.Td><Table.Td ta="right" className="mono">{m.runtime ?? '—'}</Table.Td><Table.Td ta="right" className="mono">{m.score ?? '—'}</Table.Td><Table.Td onClick={e => e.stopPropagation()}><MarkButtons code={m.code} size="sm" /></Table.Td></Table.Tr>)}</Table.Tbody></Table></div>}<Pager res={r.movies} set={set} /></> :
            <Empty title="Không có phim khớp" hint="Danh sách rỗng không chứng minh phim không tồn tại ở nguồn." action={<Stack align="center" gap="xs">{DYM}{hints.map(h => <Button key={h.chip.key} size="xs" variant="light" onClick={h.chip.remove}>Bỏ “{h.chip.label}: {h.chip.value}” để có {fmt(h.total)} phim</Button>)}<Button variant="default" size="xs" leftSection={<IconRestore size={14} />} onClick={resetAll}>Xóa hết điều kiện</Button></Stack>} />}</Stack>;
  const ROWS_PERF = [['Tên', x => x.name], ['Kanji', x => x.kanji], ['Tuổi', x => ageOfPerformer(x)], ['Nhóm máu', x => effOf(x).blood], ['Cup', x => effOf(x).cup], ['Cao', x => (effOf(x).height ? `${effOf(x).height} cm` : null)], ['Số đo', x => (effOf(x).bust ? `${effOf(x).bust}-${effOf(x).waist ?? '?'}-${effOf(x).hip ?? '?'}` : null)], ['Nơi ở', x => x.location], ['Số phim', x => x.count]];
  registerList('browse-p', r.performers.all ? r.performers.all.map(x => x.id) : []);
  const performersPanel = <Stack gap="md" data-list-id="browse-p" data-list-kind="performers" data-list-label="Kết quả">
    <Group justify="space-between"><Text size="sm" c="dimmed">Sắp xếp</Text><Group gap="xs"><Select size="xs" w={200} value={p.sort === 'linked_title_count' ? 'movies' : (p.sort || 'featured')} onChange={v => set({ sort: v })} data={PERF_SORT_ALL.map(([value, label]) => ({ value, label }))} allowDeselect={false} aria-label="Sắp xếp diễn viên" /><SegmentedControl size="xs" value={view} onChange={x => { setView(x); localStorage.setItem('xc.view', x); }} data={[{ value: 'grid', label: <IconLayoutGrid size={16} /> }, { value: 'table', label: <IconTable size={16} /> }]} aria-label="Kiểu hiển thị" /></Group></Group>
    {r.performers.total ? <>{view === 'grid' ? <div className="pgrid">{r.performers.items.map(x => <PerfCard key={x.id} x={x} hl={hl} />)}</div> : <div className="scroll-x"><Table miw={900} highlightOnHover verticalSpacing={8} stickyHeader><Table.Thead><Table.Tr><Table.Th w={72}></Table.Th>{ROWS_PERF.map(([h]) => <Table.Th key={h} ta={['Tuổi', 'Số phim'].includes(h) ? 'right' : undefined}>{h}</Table.Th>)}</Table.Tr></Table.Thead><Table.Tbody>{r.performers.items.map(x => <Table.Tr key={x.id} style={{ cursor: 'pointer' }} onClick={() => { saveContext('performers', r.performers.all.map(y => y.id), 'Kết quả'); nav(`/performers/${x.slug || x.id}`); }}><Table.Td><div className="poster cover-portrait" style={{ width: 56, borderRadius: 8 }}>{x.img ? <LazyImg src={x.img} alt={x.name}  /> : <Group h="100%" justify="center"><Avatar size={32} color="teal" variant="light">{(x.name || '?')[0]}</Avatar></Group>}</div></Table.Td>{ROWS_PERF.map(([h, f]) => <Table.Td key={h} ta={['Tuổi', 'Số phim'].includes(h) ? 'right' : undefined} className={['Tuổi', 'Số phim', 'Cup', 'Nhóm máu', 'Số đo', 'Cao'].includes(h) ? 'mono' : undefined}>{h === 'Tên' ? <Link to={`/performers/${x.slug || x.id}`} onClick={e => e.stopPropagation()}><Mark text={x.name} q={hl} /></Link> : h === 'Kanji' ? (x.kanji ? <Mark text={x.kanji} q={hl} /> : '—') : (f(x) ?? '—')}</Table.Td>)}</Table.Tr>)}</Table.Tbody></Table></div>}<Pager res={r.performers} set={set} /></> :
      <Empty title="Không có diễn viên khớp" hint="Danh sách rỗng không chứng minh diễn viên không tồn tại ở nguồn." action={<Stack align="center" gap="xs">{DYM}{hints.map(h => <Button key={h.chip.key} size="xs" variant="light" onClick={h.chip.remove}>Bỏ “{h.chip.label}: {h.chip.value}” để có {fmt(h.total)} diễn viên</Button>)}<Button variant="default" size="xs" leftSection={<IconRestore size={14} />} onClick={resetAll}>Xóa hết điều kiện</Button></Stack>} />}</Stack>;
  return <Stack gap="lg">
    <PageHead title={TITLE[kind]} sub={perfOnly ? `${fmt(r.performers.total)} diễn viên khớp trong kho ${fmt(boot.data?.totals.performers)}` : moviesOnly ? `${fmt(r.movies.total)} phim khớp trong kho ${fmt(boot.data?.totals.movies)}` : `${fmt(r.movies.total)} phim · ${fmt(r.performers.total)} diễn viên · ${fmt(r.photos.total)} ảnh khớp`} right={<Group gap="xs">
      <Menu position="bottom-end" withinPortal><Menu.Target><Button size="xs" variant="default" leftSection={<IconBookmark size={14} />}>Đã lưu ({st.saved.length})</Button></Menu.Target><Menu.Dropdown><Menu.Item leftSection={<IconBookmarkPlus size={14} />} onClick={sh.open}>Lưu tìm kiếm hiện tại…</Menu.Item>{st.saved.length > 0 && <Menu.Divider />}{st.saved.map((x, i) => <Menu.Item key={i} onClick={() => apply(x.params)} rightSection={<ActionIcon size="sm" variant="subtle" color="red" aria-label="Xóa" onClick={e => { e.stopPropagation(); personal.removeSearch(i); }}><IconTrash size={14} /></ActionIcon>}>{x.name}</Menu.Item>)}</Menu.Dropdown></Menu>
      <Button size="xs" variant="default" leftSection={<IconLink size={14} />} onClick={copyLink}>Sao chép liên kết</Button>
      {!wide && <Button size="xs" leftSection={<IconFilter size={14} />} onClick={fh.open}>Bộ lọc{nManual ? ` (${nManual})` : ''}</Button>}</Group>} />
    <TextInput size="md" value={qv} onChange={e => setQv(e.target.value)} leftSection={<IconSearch size={18} />} placeholder={perfOnly ? 'Gõ để tìm diễn viên: tên, kanji, tiểu sử, hoặc cả câu như “tuổi 25-30 nhóm máu A”' : moviesOnly ? 'Gõ để tìm phim: mã, tên, hãng, diễn viên, hoặc cả câu như “amateur 2026 dưới 120 phút”' : 'Gõ để tìm: tên, mã, hoặc cả câu như “amateur 2026 dưới 120 phút”'} aria-label="Tìm kiếm"
      rightSection={qv !== dq ? <Loader size={16} type="dots" /> : qv ? <ActionIcon variant="subtle" color="gray" onClick={() => setQv('')} aria-label="Xóa từ khoá"><IconX size={16} /></ActionIcon> : null} data-autofocus />
    {chips.length > 0 && <Group gap={8} align="center">
      {r.interp.conditions.length > 0 && <Group gap={8}><IconBrain size={16} color="#4fd1c5" /><Text size="xs" c="dimmed">Tôi hiểu là</Text></Group>}
      {chips.map(c => <Chip key={c.key} checked variant={c.auto ? 'outline' : 'light'} color={c.auto ? 'teal' : undefined} icon={<IconX size={12} />} onClick={c.remove}>{c.label}: {c.value}</Chip>)}
      <Button size="compact-xs" variant="subtle" color="gray" onClick={resetAll}>Xóa hết</Button></Group>}
    {idle && kind === 'all' && <Paper className="tile"><Stack gap="md">
      {(st.rq || []).length > 0 && <div><Group justify="space-between" mb={8}><Group gap={8}><IconHistory size={16} color="#8a96a4" /><Text size="sm" fw={600}>Tìm gần đây</Text></Group><Button size="compact-xs" variant="subtle" color="gray" onClick={() => personal.clearQueries()}>Xóa lịch sử</Button></Group><Group gap={8}>{st.rq.map(q => <Badge key={q} size="lg" variant="light" color="gray" style={{ cursor: 'pointer', textTransform: 'none' }} onClick={() => setQv(q)}>{q}</Badge>)}</Group></div>}
      {st.saved.length > 0 && <div><Group gap={8} mb={8}><IconBookmark size={16} color="#8a96a4" /><Text size="sm" fw={600}>Đã lưu</Text></Group><Group gap={8}>{st.saved.map((x, i) => <Badge key={i} size="lg" variant="light" style={{ cursor: 'pointer', textTransform: 'none' }} onClick={() => apply(x.params)}>{x.name}</Badge>)}</Group></div>}
      <div><Text size="sm" fw={600} mb={8}>Thể loại phổ biến</Text><Group gap={8}>{fc.genres.slice(0, 14).map(g => <Badge key={g.name} size="lg" variant="outline" color="gray" style={{ cursor: 'pointer', textTransform: 'none' }} onClick={() => set({ genre: g.name })}>{g.name} · {fmt(g.n)}</Badge>)}</Group></div>
      <div><Text size="sm" fw={600} mb={8}>Thử gõ</Text><Group gap={8}>{['amateur 2026 dưới 120 phút', 'drama trên 90 phút', 'SOD Create 2026'].map(q => <Badge key={q} size="lg" variant="light" color="teal" style={{ cursor: 'pointer', textTransform: 'none' }} onClick={() => setQv(q)}>{q}</Badge>)}</Group></div>
    </Stack></Paper>}
    <div className="sgrid">
      {wide && <aside className="sfilters" aria-label="Bộ lọc">{filters}</aside>}
      <div style={{ minWidth: 0 }}>{moviesOnly ? moviesPanel : perfOnly ? performersPanel : <Tabs keepMounted={false} value={tab} onChange={v => set({ tab: v === 'movies' ? '' : v }, true)}>
        <Tabs.List><Tabs.Tab value="movies">Phim <Badge ml={8} size="md" variant="light">{fmt(r.movies.total)}</Badge></Tabs.Tab><Tabs.Tab value="performers">Diễn viên <Badge ml={8} size="md" variant="light">{fmt(r.performers.total)}</Badge></Tabs.Tab><Tabs.Tab value="photos">Ảnh <Badge ml={8} size="md" variant="light">{fmt(r.photos.total)}</Badge></Tabs.Tab><Tabs.Tab value="catalog">Danh mục <Badge ml={8} size="md" variant="light">{r.catalog.length}</Badge></Tabs.Tab></Tabs.List>
        <Tabs.Panel value="movies" pt="md">{moviesPanel}</Tabs.Panel>
        <Tabs.Panel value="performers" pt="md"><Stack gap="md" data-list-id="browse-p" data-list-kind="performers" data-list-label="Kết quả">
          <Group justify="space-between"><Text size="sm" c="dimmed">{r.fromMovies ? 'Diễn viên trong các phim khớp' : 'Diễn viên khớp'}</Text><Select size="xs" w={190} value={p.psort || (r.fromMovies ? 'movies' : 'featured')} onChange={v => set({ psort: v })} data={PERF_SORTS.map(([value, label]) => ({ value, label }))} allowDeselect={false} aria-label="Sắp xếp diễn viên" /></Group>
          {r.performers.total ? <><div className="pgrid">{r.performers.items.map(x => <PerfCard key={x.id} x={x} hl={hl} />)}</div><Pager res={r.performers} set={setPerf} /></> : none('Không có diễn viên khớp', 'Thử bỏ bớt điều kiện ở mục Diễn viên của bộ lọc.')}</Stack></Tabs.Panel>
        <Tabs.Panel value="photos" pt="md"><PhotoTab photos={r.photos} /></Tabs.Panel>
        <Tabs.Panel value="catalog" pt="md"><Stack gap="lg">
          <div><Text fw={600} mb="sm">Danh mục khớp từ khoá</Text>{r.catalog.length ? <Group gap={8}>{r.catalog.map(c => <Badge key={c.kind + c.name} size="lg" variant="light" style={{ cursor: 'pointer', textTransform: 'none' }} onClick={() => addCat(c)}>{CAT_KEY[c.kind][1]}: {c.name} · {fmt(c.n)}</Badge>)}</Group> : <Text size="sm" c="dimmed">Không có danh mục khớp từ khoá.</Text>}</div>
          <div><Text fw={600} mb="sm">Thể loại trong kết quả</Text><Group gap={8}>{fc.genres.slice(0, 24).map(g => <Badge key={g.name} size="lg" variant="outline" color="gray" style={{ cursor: 'pointer', textTransform: 'none' }} onClick={() => addCat({ kind: 'genres', name: g.name })}>{g.name} · {fmt(g.n)}</Badge>)}</Group></div></Stack></Tabs.Panel>
      </Tabs>}</div></div>
    <Drawer opened={fo && !wide} onClose={fh.close} position="left" size={340} title="Bộ lọc" zIndex={300}><Stack>{filters}<Button variant="default" onClick={resetAll} leftSection={<IconRestore size={16} />}>Xóa hết điều kiện</Button></Stack></Drawer>
    <Modal opened={saveOpen} onClose={sh.close} title="Lưu tìm kiếm hiện tại" centered zIndex={400}><TextInput data-autofocus value={sname} onChange={e => setSname(e.target.value)} placeholder="Tên, ví dụ: Amateur 2025" /><Group justify="flex-end" mt="md"><Button variant="default" onClick={sh.close}>Hủy</Button><Button disabled={!sname.trim()} onClick={() => { personal.saveSearch(sname.trim(), Object.fromEntries(Object.entries({ q: p.q, ig: p.ig, ...manual }).filter(([, v]) => v))); setSname(''); sh.close(); }}>Lưu</Button></Group></Modal>
    <QuickView code={pv} onClose={() => setPv(null)} />
  </Stack>;
}
