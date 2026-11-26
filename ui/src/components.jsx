import { useEffect, useState, useSyncExternalStore, useCallback } from 'react';
import { LazyImg } from './lazyimg.jsx';
import { Link, useSearchParams, useNavigate } from 'react-router-dom';
import { canPreview } from './playable.js'; import { Mark } from './mark.jsx'; import { Box, LoadingOverlay, Skeleton, Group, Text, Badge, ActionIcon, Tooltip, HoverCard, Stack, Pagination, Select, Button, Drawer, Center, Paper, Title, Alert, Avatar, Divider, SimpleGrid, Modal, Anchor, CopyButton } from '@mantine/core'; import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { IconEye as IconEyeV, IconDownload, IconPlayerPlay, IconArrowsMaximize, IconArrowRight, IconHeart, IconHeartFilled, IconClockPlus, IconClockFilled, IconEye, IconEyeCheck, IconMagnet, IconAlertTriangle, IconInbox, IconRefresh, IconArrowUpRight, IconPhotoOff } from '@tabler/icons-react';
import { loadCatalog, lag, personal, cover, photo, performersOf, getMovie, fmt, fmtBytes, related, descIsTitle, mainTags, auth, viewCount, viewsStore, loadViews, loadMovieDownloads, ensureViews } from '@shared/store.js';

export function useAsync(fn, deps) {
  const [s, set] = useState({ loading: true, data: null, error: null }); const [tick, setTick] = useState(0);
  useEffect(() => {
    let live = true; set(x => ({ ...x, loading: true, error: null }));
    (async () => { try { await Promise.all([lag(), loadCatalog()]); const d = await fn(); if (live) set({ loading: false, data: d, error: null }); } catch (e) { if (live) set({ loading: false, data: null, error: e }); } })();
    return () => { live = false; };
    // eslint-disable-next-line
  }, [...deps, tick]);
  return { ...s, reload: () => setTick(t => t + 1) };
}
export const useAuth = () => useSyncExternalStore(auth.subscribe, auth.user);
export const useScope = () => useSyncExternalStore(auth.subscribe, auth.scope);
export const useViews = () => { useSyncExternalStore(viewsStore.subscribe, viewsStore.version); return viewCount; };
export const usePersonal = () => useSyncExternalStore(personal.subscribe, personal.get);

// stale data stays visible under a loading layer; first load shows a skeleton; errors have a retry
export function Async({ state, skeleton, children, minH = 240 }) {
  if (state.error) return <Alert color="red" icon={<IconAlertTriangle />} title="Không tải được dữ liệu" mt="md"><Text size="sm" mb="sm">{String(state.error.message || state.error)}</Text><Button size="xs" variant="light" leftSection={<IconRefresh size={14} />} onClick={state.reload}>Thử lại</Button></Alert>;
  if (!state.data) return skeleton || <Stack gap="sm"><Skeleton h={28} w="40%" /><Skeleton h={minH} /></Stack>;
  return <Box pos="relative" mih={minH}><LoadingOverlay visible={state.loading} zIndex={4} overlayProps={{ blur: 2, radius: 'md', backgroundOpacity: 0.45 }} loaderProps={{ type: 'bars', size: 'md' }} /><Stack gap="lg">{children(state.data)}</Stack></Box>;
}
// the one "quick view" control used on every image of the app: same icon, same corner
export function QuickIcon({ onClick, label = 'Xem nhanh', offset = 8 }) {
  return <Tooltip label={label} withinPortal><ActionIcon className="qv-icon" variant="filled" color="dark" size="md" radius="xl" aria-label={label} onPointerDown={e => e.stopPropagation()} onClick={e => { e.preventDefault(); e.stopPropagation(); onClick(); }} style={{ position: 'absolute', top: offset, right: offset, zIndex: 2, background: 'rgba(11,14,17,.78)', border: '1px solid rgba(255,255,255,.18)' }}><IconArrowsMaximize size={14} /></ActionIcon></Tooltip>;
}
export const HeaderAction = ({ to, children }) => <Button component={Link} to={to} variant="default" rightSection={<IconArrowRight size={14} />}>{children}</Button>;
export const SectionLink = ({ to, children }) => <Button component={Link} to={to} variant="subtle" color="gray" size="compact-sm" rightSection={<IconArrowRight size={14} />}>{children}</Button>;
export const Empty = ({ title = 'Không có kết quả', hint, action }) => <Center py={48}><Stack align="center" gap={8} maw={420} ta="center"><IconInbox size={36} stroke={1.2} color="#5a6572" /><Text fw={600}>{title}</Text>{hint && <Text size="sm" c="dimmed">{hint}</Text>}{action}</Stack></Center>;
export const Disabled = ({ title, hint, setting }) => <Paper withBorder p="xl" radius="md"><Stack align="center" gap={8} ta="center"><Badge color="gray" variant="light">Đang tắt</Badge><Text fw={600}>{title}</Text><Text size="sm" c="dimmed" maw={520}>{hint}</Text>{setting && <Text size="xs" className="mono" c="dimmed">{setting}</Text>}</Stack></Paper>;

export function PageHead({ title, sub, right, back }) {
  return <Group justify="space-between" align="flex-end" wrap="wrap"><div><Title order={2} fw={600}>{title}</Title>{sub && <Text size="sm" c="dimmed" mt={4}>{sub}</Text>}</div>{right}</Group>;
}
export function useQuery() {
  const [sp, setSp] = useSearchParams();
  const params = Object.fromEntries(sp.entries());
  const set = useCallback((patch, keepPage = false, replace = false) => setSp(prev => { const n = new URLSearchParams(prev); Object.entries(patch).forEach(([k, v]) => (v === '' || v == null || v === false ? n.delete(k) : n.set(k, v === true ? '1' : String(v)))); if (!keepPage && !('page' in patch)) n.delete('page'); return n; }, { replace }), [setSp]);
  return [params, set, () => setSp({})];
}
export function Pager({ res, set, sizes = [24, 48, 96], scroll = true }) {
  if (!res) return null; const from = res.total ? (res.page - 1) * res.per + 1 : 0, to = Math.min(res.total, res.page * res.per);
  return <Group justify="space-between" wrap="wrap" gap="sm"><Text size="sm" c="dimmed">{from.toLocaleString('vi')}–{to.toLocaleString('vi')} / {fmt(res.total)} kết quả</Text><Pagination value={res.page} total={res.pages} onChange={p => { set({ page: p }, true); if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' }); }} siblings={1} boundaries={1} withEdges size="sm" />
    <Select size="xs" w={118} value={String(res.per)} data={sizes.map(s => ({ value: String(s), label: `${s} / trang` }))} onChange={v => set({ per_page: v })} allowDeselect={false} aria-label="Số dòng mỗi trang" /></Group>;
}
export function Cover({ m, ratio, ...rest }) {
  const src = cover(m); return src ? <LazyImg src={src} alt={m.code}  {...rest} /> : <Center h="100%"><IconPhotoOff color="#5a6572" /></Center>;
}
export function MarkButtons({ code, size = 'sm', stack }) {
  const st = usePersonal(); const m = getMovie(code); if (!m) return null; const id = m.id;
  const mk = (kind, on, off, label, msg) => <Tooltip label={label} key={kind}><ActionIcon size={size} variant={st[kind].includes(id) ? 'filled' : 'default'} color={st[kind].includes(id) ? 'teal' : 'gray'} aria-label={label} onClick={e => { e.preventDefault(); e.stopPropagation(); const now = personal.toggle(kind, id); notifications.show({ message: `${code}: ${now ? msg : 'đã bỏ ' + label.toLowerCase()}`, color: 'teal', autoClose: 1800 }); }}>{st[kind].includes(id) ? on : off}</ActionIcon></Tooltip>;
  const items = [mk('fav', <IconHeartFilled size={15} />, <IconHeart size={15} />, 'Yêu thích', 'đã thêm vào Yêu thích'), mk('later', <IconClockFilled size={15} />, <IconClockPlus size={15} />, 'Xem sau', 'đã thêm vào Xem sau'), mk('seen', <IconEyeCheck size={15} />, <IconEye size={15} />, 'Đã xem', 'đã đánh dấu Đã xem')];
  return stack ? <Stack gap={4}>{items}</Stack> : <Group gap={4}>{items}</Group>;
}
export function MoviePreview({ m }) {
  const ps = performersOf(m); const names = [...new Map(ps.map(p => [p.id, p])).values()];
  return <Stack gap={8} w="100%"><div className="poster" style={{ aspectRatio: '16/9', cursor: 'default' }}><Cover m={m} /></div>
    <div><Text fw={600} size="sm">{m.en || m.jp}</Text>{m.jp && m.jp !== m.en && <Text size="xs" c="dimmed">{m.jp}</Text>}</div>
    <Group gap={8}>{m.date && <Badge variant="light" color="gray" size="md">{m.date}</Badge>}{m.runtime && <Badge variant="light" color="gray" size="md">{m.runtime} phút</Badge>}{m.score != null && <Badge variant="light" color="yellow" size="md">★ {m.score}</Badge>}{m.maker && <Badge variant="light" size="md">{m.maker}</Badge>}{m.series && <Badge variant="outline" size="md">{m.series}</Badge>}{m.label && <Badge variant="outline" color="gray" size="md">{m.label}</Badge>}</Group>
    {m.directors?.length > 0 && <Text size="xs" c="dimmed">Đạo diễn: {m.directors.join(', ')}</Text>}
    {names.length > 0 && <div><Text size="xs" c="dimmed" mb={4}>Diễn viên ({names.length})</Text><Group gap={8}>{names.map(p => <Group gap={4} key={p.id} wrap="nowrap"><Avatar size={20} src={photo(p)} imageProps={{ referrerPolicy: 'no-referrer' }} /><Text size="xs">{p.name}</Text></Group>)}</Group></div>}
    {!descIsTitle(m) && <Text size="xs" c="dimmed">{m.desc}</Text>}
    <div><Text size="xs" c="dimmed" mb={4}>Thể loại ({m.genres.length})</Text><Group gap={4}>{m.genres.map(g => <Badge key={g} size="md" variant="outline" color="gray" style={{ textTransform: 'none' }}>{g}</Badge>)}</Group></div>
    <Group gap={8}><Badge variant="light" color="teal" size="md" leftSection={<IconDownload size={11} />}>{m.magnets ? `${m.magnets} link tải` : 'Chưa có link tải'}</Badge>{m.sources?.length > 0 && <Badge variant="light" color="gray" size="md">{m.sources.length} nguồn</Badge>}</Group></Stack>;
}
const chip = { background: 'rgba(11,14,17,.78)', border: '1px solid rgba(255,255,255,.18)', color: '#fff' };
const host = u => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return u.slice(0, 30); } };
// bottom-right of every cover: watch (preview/stream links) and download (magnet/torrent links); more than one link opens a chooser
function CardActions({ m }) {
  const [dl, dlh] = useDisclosure(false); const [links, setLinks] = useState(null); const n = m.magnets; const views = useViews()(m.code); useEffect(() => ensureViews(m.code), [m.code]);
  const stop = e => e.stopPropagation();
  const open = async e => { e.preventDefault(); stop(e); const l = await loadMovieDownloads(m.code); if (l.length === 1) { personal.logDownload(m.code, l[0].type); window.open(l[0].url, '_blank', 'noreferrer'); return; } setLinks(l); dlh.open(); };
  return <Group gap={8} wrap="nowrap" style={{ marginLeft: 'auto', flex: 'none', pointerEvents: 'auto' }} onClick={stop}>
    <Tooltip label={`Lượt xem · ${fmt(views)}`}><Badge className="qv-icon" size="lg" radius="xl" variant="filled" color="dark" leftSection={<IconEyeV size={14} />} style={{ ...chip, textTransform: 'none', height: 26 }}>{fmt(views)}</Badge></Tooltip>
    <Tooltip label={n ? (n === 1 ? 'Tải về' : `Tải về · ${n} liên kết`) : 'Chưa có liên kết tải'}><Button className="qv-icon" size="compact-sm" radius="xl" variant="filled" color="teal" disabled={!n} aria-label="Tải về" leftSection={<IconDownload size={14} />} styles={{ root: n ? { border: '1px solid rgba(255,255,255,.18)' } : chip }} onClick={open}>{n > 1 ? n : null}</Button></Tooltip>
    <Modal opened={dl} onClose={dlh.close} title={<Text fw={600}>Tải về · {m.code}</Text>} centered size="lg" zIndex={400}>
      <Stack gap="sm"><Text size="sm" c="dimmed">{links?.length || 0} liên kết. Chọn nguồn để tải.</Text>{(links || []).map((d, i) => <Group key={i} justify="space-between" wrap="nowrap" gap="sm" p="xs" style={{ border: '1px solid var(--mantine-color-dark-5)', borderRadius: 8 }}>
        <Group gap="xs" wrap="nowrap" style={{ minWidth: 0 }}><Badge variant="light" color={d.type === 'magnet' ? 'grape' : 'blue'}>{d.type === 'magnet' ? 'Magnet' : '.torrent'}</Badge>{d.quality && <Badge variant="outline" color="teal">{d.quality}</Badge>}<Text size="sm" truncate>{d.name || host(d.url)}</Text></Group>
        <Group gap="xs" wrap="nowrap"><Text size="xs" c="dimmed" className="mono">{d.size || fmtBytes(d.bytes) || ''}</Text><CopyButton value={d.url}>{({ copied, copy }) => <Button size="compact-sm" variant="default" onClick={() => { copy(); notifications.show({ message: 'Đã sao chép liên kết', color: 'teal', autoClose: 1500 }); }}>{copied ? 'Đã chép' : 'Sao chép'}</Button>}</CopyButton><Button size="compact-sm" component="a" href={d.url} target="_blank" rel="noreferrer" onClick={() => personal.logDownload(m.code, d.type)} leftSection={<IconDownload size={14} />}>{d.type === 'magnet' ? 'Mở magnet' : 'Tải .torrent'}</Button></Group></Group>)}</Stack></Modal>
  </Group>;
}
export function MovieCard({ m, onPreview, hl }) {
  const ps = performersOf(m); const names = [...new Map(ps.map(p => [p.name, p])).values()]; const { shown, rest } = mainTags(m, 3);
  return <HoverCard width={400} shadow="lg" openDelay={450} closeDelay={80} position="right-start" withinPortal withArrow>
    <HoverCard.Target><div>
      <div className="poster"><Link to={`/movies/${m.code}`} aria-label={m.code} style={{ position: 'absolute', inset: 0 }}><Cover m={m} /></Link>
        {canPreview(m) && <Tooltip label="Có thể xem thử"><button type="button" className="poster-play" aria-label={`Xem thử ${m.code}`} onPointerDown={e => e.stopPropagation()} onClick={e => { e.preventDefault(); e.stopPropagation(); window.dispatchEvent(new CustomEvent('xc:play', { detail: m.code })); }}><IconPlayerPlay size={14} /></button></Tooltip>}
        <div className="poster-bar"><span className="code"><Mark text={m.code} q={hl} /></span><CardActions m={m} /></div>
        {onPreview && <QuickIcon onClick={() => onPreview(m.code)} />}</div>
      <Text component={Link} to={`/movies/${m.code}`} size="sm" mt={8} lh={1.3} className="clamp2" fw={500} style={{ display: '-webkit-box', textDecoration: 'none', color: 'inherit' }}><Mark text={(m.en || m.jp || '').replace(m.code, '').trim() || m.jp} q={hl} /></Text>
      <Group justify="space-between" mt={4} wrap="nowrap"><Text size="xs" c="dimmed" truncate>{m.maker ? <Mark text={m.maker} q={hl} /> : '—'}</Text><Text size="xs" c="dimmed" className="mono">{m.date?.slice(0, 7) || ''}</Text></Group>
      {names.length > 0 && <Text size="xs" mt={4} lh={1.5} c="dimmed">{names.map((p, i) => <span key={p.id}><Anchor component={Link} to={`/performers/${p.slug || p.id}`} size="xs" c="teal.3"><Mark text={p.name} q={hl} /></Anchor>{i < names.length - 1 ? ', ' : ''}</span>)}</Text>}
      {(shown.length > 0 || rest.length > 0) && <Group gap={8} mt={8} wrap="wrap">{shown.map(g => <Badge key={g} component={Link} to={`/movies?genre=${encodeURIComponent(g)}`} variant="outline" color="gray" size="md" style={{ textTransform: 'none', cursor: 'pointer' }}>{g}</Badge>)}{rest.length > 0 && <Tooltip label={rest.join(', ')} multiline w={260}><Badge variant="light" color="gray" size="md">+{rest.length}</Badge></Tooltip>}</Group>}
    </div></HoverCard.Target>
    <HoverCard.Dropdown style={{ maxHeight: 'calc(100vh - 24px)', overflowY: 'auto' }}><MoviePreview m={m} /><Divider my={8} /><MarkButtons code={m.code} /></HoverCard.Dropdown></HoverCard>;
}
export function QuickView({ code, onClose, zIndex }) {
  const nav = useNavigate(); const m = code ? getMovie(code) : null; const ps = m ? performersOf(m) : [];
  return <Drawer opened={!!code} onClose={onClose} zIndex={zIndex} position="right" size="md" title={<Text fw={600}>{code}</Text>} padding="md" overlayProps={{ backgroundOpacity: 0.5, blur: 2 }}>
    {m && <Stack gap="md"><div className="poster" style={{ aspectRatio: '16/10', cursor: 'default' }}><Cover m={m} /></div>
      <div><Text fw={600}>{m.en || m.jp}</Text><Text size="sm" c="dimmed">{m.jp}</Text></div>
      <SimpleGrid cols={2} spacing={8}>{[['Phát hành', m.date], ['Thời lượng', m.runtime && `${m.runtime} phút`], ['Hãng', m.maker], ['Series', m.series], ['Nhãn', m.label], ['Điểm', m.score], ['Nguồn', (m.sources || []).join(', ')], ['Magnet', m.magnets]].map(([k, v]) => <div key={k}><Text size="xs" c="dimmed">{k}</Text><Text size="sm">{v ?? '—'}</Text></div>)}</SimpleGrid>
      {!descIsTitle(m) && <Text size="sm" c="dimmed">{m.desc}</Text>}
      <Group gap={8}>{m.genres.map(g => <Badge key={g} variant="outline" color="gray" size="md" component={Link} to={`/movies?genre=${encodeURIComponent(g)}`} onClick={onClose} style={{ cursor: 'pointer' }}>{g}</Badge>)}</Group>
      {ps.length > 0 && <Group>{ps.map(p => <Group key={p.id} gap={8} component={Link} to={`/performers/${p.slug || p.id}`} onClick={onClose} style={{ textDecoration: 'none', color: 'inherit' }}><Avatar size={28} src={photo(p)} imageProps={{ referrerPolicy: 'no-referrer' }} /><Text size="sm">{p.name}</Text></Group>)}</Group>}
      <Group><MarkButtons code={m.code} size="md" /><Button ml="auto" onClick={() => { onClose(); nav(`/movies/${m.code}`); }}>Mở trang đầy đủ</Button></Group></Stack>}
  </Drawer>;
}
export const MovieGridSkeleton = ({ n = 12 }) => <div className="pgrid">{Array.from({ length: n }, (_, i) => <div key={i}><Skeleton style={{ aspectRatio: '3/2' }} /><Skeleton h={12} mt={8} w="85%" /><Skeleton h={10} mt={8} w="45%" /></div>)}</div>;
export const TableSkeleton = ({ rows = 10 }) => <Stack gap={8}>{Array.from({ length: rows }, (_, i) => <Skeleton key={i} h={34} />)}</Stack>;
export { related };
