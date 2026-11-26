import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Menu, Avatar, UnstyledButton, Group, Stack, Text, Badge, Paper, Button, TextInput, PasswordInput, Tabs, SegmentedControl, SimpleGrid, Table, Divider, Anchor } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { IconUser, IconEye, IconDownload, IconTimeline, IconHeart, IconClock, IconBell, IconFolder, IconPhoto, IconLogout, IconChevronDown, IconLogin } from '@tabler/icons-react';
import { auth, photo, personal, fmt, adaptMovie, adaptPerformer, ago } from './shim.js'; import { get, send, ApiError } from './data/api.js';
import { MovieCard, QuickView, PageHead, Empty, Async, useAsync, usePersonal, useAuth, Cover, MovieGridSkeleton, Pager } from './components.jsx';

const initials = n => (n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
const ROLE = { super_administrator: 'Quản trị viên cấp cao', admin: 'Quản trị viên', user: 'Thành viên' };
export const UserAvatar = ({ user, size = 36 }) => <Avatar size={size} radius="xl" color="teal" variant="filled">{initials(user.name)}</Avatar>;
const when = t => new Date(t).toLocaleString('vi', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

// the account menu for a signed-in user, and a sign-in button for a visitor
export function AvatarMenu() {
  const user = useAuth(); const nav = useNavigate();
  if (!user) return <Button component={Link} to="/login" size="compact-md" variant="light" leftSection={<IconLogin size={16} />}>Đăng nhập</Button>;
  const Item = ({ to, icon: Ic, children }) => <Menu.Item component={Link} to={to} leftSection={<Ic size={16} />} py={10}>{children}</Menu.Item>;
  return <Menu width={300} position="bottom-end" shadow="lg" withinPortal offset={8}>
    <Menu.Target><UnstyledButton aria-label="Tài khoản" style={{ borderRadius: 999 }}><Group gap={6} wrap="nowrap"><UserAvatar user={user} size={36} /><IconChevronDown size={14} color="#8a96a4" /></Group></UnstyledButton></Menu.Target>
    <Menu.Dropdown>
      <Group gap="sm" p="sm" wrap="nowrap"><UserAvatar user={user} size={44} /><div style={{ minWidth: 0 }}><Text fw={600} truncate>{user.name}</Text><Text size="xs" c="dimmed" truncate>{user.email}</Text><Badge size="md" variant="light" mt={4}>{ROLE[user.role] || user.role}</Badge></div></Group>
      <Divider />
      <Menu.Label>Tài khoản</Menu.Label><Item to="/profile" icon={IconUser}>Hồ sơ của tôi</Item>
      <Menu.Label>Hoạt động</Menu.Label><Item to="/history" icon={IconEye}>Phim đã xem</Item><Item to="/downloaded" icon={IconDownload}>Phim đã tải</Item><Item to="/activity" icon={IconTimeline}>Hoạt động của tôi</Item>
      <Menu.Label>Danh sách của tôi</Menu.Label><Item to="/favorites" icon={IconHeart}>Yêu thích</Item><Item to="/watch-later" icon={IconClock}>Xem sau</Item><Item to="/following" icon={IconBell}>Theo dõi</Item><Item to="/library" icon={IconFolder}>Thư viện</Item><Item to="/downloads" icon={IconDownload}>Tải về</Item><Item to="/media" icon={IconPhoto}>Tệp media</Item>
      <Divider my={4} /><Menu.Item color="red" leftSection={<IconLogout size={16} />} py={10} onClick={async () => { await auth.logout(); nav('/'); }}>Đăng xuất</Menu.Item>
    </Menu.Dropdown></Menu>;
}
export const NeedLogin = () => <Empty title="Bạn cần đăng nhập" hint="Nội dung này chỉ dành cho tài khoản đã đăng nhập." action={<Button component={Link} to="/login">Đăng nhập</Button>} />;

export function LoginPage() {
  const nav = useNavigate(); const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [err, setErr] = useState(null); const [busy, setBusy] = useState(false); const [reset, setReset] = useState(false);
  const submit = async e => { e.preventDefault(); setBusy(true); setErr(null); try { await auth.login(email, pw); nav('/'); } catch (x) { setErr(x instanceof ApiError && x.status === 429 ? 'Thử quá nhiều lần, hãy đợi một phút.' : 'Sai email hoặc mật khẩu.'); } setBusy(false); };
  const sendReset = async () => { await send('POST', '/password-resets', { email }); notifications.show({ message: 'Nếu email tồn tại, hướng dẫn đặt lại mật khẩu đã được ghi nhận.', color: 'teal' }); setReset(false); };
  return <Stack align="center" mt={48}><Paper className="tile" w="100%" maw={400} p="lg"><form onSubmit={submit}><Stack gap="md"><Text fw={600} fz="lg">Đăng nhập</Text>
    <TextInput label="Email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="username" data-autofocus />
    {!reset && <PasswordInput label="Mật khẩu" value={pw} onChange={e => setPw(e.target.value)} autoComplete="current-password" error={err} />}
    {reset ? <Group justify="space-between"><Button variant="subtle" onClick={() => setReset(false)}>Quay lại</Button><Button disabled={!email} onClick={sendReset}>Gửi yêu cầu đặt lại</Button></Group> : <><Button type="submit" loading={busy} disabled={!email || !pw}>Đăng nhập</Button><Anchor size="sm" component="button" type="button" onClick={() => setReset(true)}>Quên mật khẩu?</Anchor></>}</Stack></form></Paper></Stack>;
}

export function ProfilePage() {
  const user = useAuth(); const st = usePersonal(); const [name, setName] = useState(''); const [cur, setCur] = useState(''); const [nw, setNw] = useState(''); useEffect(() => { if (user) setName(user.name); }, [user]);
  const counters = useAsync(async () => (user ? (await get('/me')).data.counters : null), [user?.id]);
  if (!user) return <NeedLogin />;
  const save = async () => { try { await send('PATCH', '/me', { name, ...(nw ? { current_password: cur, new_password: nw } : {}) }); notifications.show({ title: 'Đã lưu', message: 'Hồ sơ của bạn đã được cập nhật.', color: 'teal' }); setCur(''); setNw(''); await auth.load(); } catch (e) { notifications.show({ title: 'Không lưu được', message: e.errors?.[0]?.field === 'current_password' ? 'Mật khẩu hiện tại chưa đúng.' : e.message, color: 'red' }); } };
  const c = counters.data || {};
  return <Stack gap="lg"><PageHead title="Hồ sơ của tôi" sub="Thông tin tài khoản và hoạt động của bạn." />
    <SimpleGrid cols={{ base: 1, md: 3 }} spacing="lg">
      <Paper className="tile"><Stack align="center" gap="sm"><UserAvatar user={{ ...user, name }} size={96} /><Text fw={600} fz="lg">{name}</Text><Badge variant="light" size="lg">{ROLE[user.role] || user.role}</Badge><Text size="sm" c="dimmed">{user.email}</Text></Stack></Paper>
      <Paper className="tile" style={{ gridColumn: 'span 2' }}><Stack gap="md"><Text fw={600}>Thông tin</Text>
        <SimpleGrid cols={{ base: 1, sm: 2 }}><TextInput label="Tên hiển thị" value={name} onChange={e => setName(e.target.value)} /><TextInput label="Email" value={user.email} readOnly /></SimpleGrid>
        <Divider /><Text fw={600}>Đổi mật khẩu</Text><SimpleGrid cols={{ base: 1, sm: 2 }}><PasswordInput label="Mật khẩu hiện tại" value={cur} onChange={e => setCur(e.target.value)} /><PasswordInput label="Mật khẩu mới (ít nhất 8 ký tự)" value={nw} onChange={e => setNw(e.target.value)} /></SimpleGrid>
        <Group justify="flex-end"><Button onClick={save}>Lưu thay đổi</Button></Group></Stack></Paper></SimpleGrid>
    <SimpleGrid cols={{ base: 2, md: 4 }}>{[['Đánh dấu', c.marks ?? st.fav.length, '/favorites'], ['Đang theo dõi', c.follows ?? st.follows.length, '/following'], ['Tìm kiếm đã lưu', c.saved_searches ?? st.saved.length, '/search'], ['Yêu cầu tải', c.download_requests ?? st.requests.length, '/downloads']].map(([k, v, to]) => <Paper className="tile" key={k} component={Link} to={to} style={{ textDecoration: 'none', color: 'inherit' }}><Text size="xs" c="dimmed" tt="uppercase" fw={600}>{k}</Text><Text fz={26} fw={600} className="mono">{fmt(v)}</Text></Paper>)}</SimpleGrid></Stack>;
}
// history rows -> the movies they name (one lookup call)
async function moviesOf(rows) { const codes = [...new Set(rows.map(r => r.ref))]; if (!codes.length) return new Map(); const { data } = await send('POST', '/movies/lookups', { codes }); return new Map(data.map(adaptMovie).map(m => [m.code, m])); }
export function ViewedPage() {
  const user = useAuth(); const [pv, setPv] = useState(null); const [page, setPage] = useState(1);
  const s = useAsync(async () => { if (!user) return null; const r = await get('/me/history', { kind: 'movie', page, per_page: 48 }); const by = await moviesOf(r.data); return { rows: r.data.filter(x => by.has(x.ref)).map(x => ({ m: by.get(x.ref), at: x.viewed_at })), pg: r.meta.pagination }; }, [user?.id, page]);
  if (!user) return <NeedLogin />;
  return <Stack gap="lg"><PageHead title="Phim đã xem" sub="Các phim bạn đã mở xem, mới nhất ở trên." />
    <Async state={s} skeleton={<MovieGridSkeleton n={8} />}>{d => d.rows.length ? <><div className="pgrid fixed">{d.rows.map((r, i) => <div key={i}><MovieCard m={r.m} onPreview={setPv} /><Text size="xs" c="dimmed" mt={4}>{when(r.at)}</Text></div>)}</div><Pager res={{ page: d.pg.page, per: d.pg.per_page, total: d.pg.total, pages: d.pg.pages }} set={p => p.page && setPage(p.page)} sizes={[48]} /></> : <Empty title="Chưa xem phim nào" hint="Mở một phim để nó xuất hiện ở đây." action={<Button component={Link} to="/movies" variant="light">Duyệt phim</Button>} />}</Async><QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
export function DownloadedPage() {
  const user = useAuth();
  const s = useAsync(async () => { if (!user) return null; const r = await get('/me/history', { kind: 'download', per_page: 100 }); const by = await moviesOf(r.data); return r.data.filter(x => by.has(x.ref)).map(x => ({ ...x, m: by.get(x.ref) })); }, [user?.id]);
  if (!user) return <NeedLogin />;
  return <Stack gap="lg"><PageHead title="Phim đã tải" sub="Các phim bạn đã bấm tải về." />
    <Async state={s} skeleton={<MovieGridSkeleton n={6} />}>{rows => rows.length ? <div className="scroll-x"><Table miw={640} highlightOnHover><Table.Thead><Table.Tr><Table.Th>Phim</Table.Th><Table.Th>Loại</Table.Th><Table.Th>Thời điểm</Table.Th></Table.Tr></Table.Thead><Table.Tbody>{rows.map((d, i) => <Table.Tr key={i}><Table.Td><Group gap="md" wrap="nowrap"><div className="poster" style={{ width: 96, flexShrink: 0 }}><Cover m={d.m} /></div><div><Text component={Link} to={`/movies/${d.m.code}`} className="mono" size="sm" c="teal.3">{d.m.code}</Text><Text size="xs" c="dimmed" lineClamp={1}>{(d.m.en || '').replace(d.m.code, '').trim()}</Text></div></Group></Table.Td><Table.Td>{d.label}</Table.Td><Table.Td className="mono">{when(d.viewed_at)}</Table.Td></Table.Tr>)}</Table.Tbody></Table></div> : <Empty title="Chưa tải phim nào" hint="Các phim bạn bấm tải sẽ hiện ở đây." />}</Async></Stack>;
}
const KINDS = [['movie', 'Phim'], ['performer', 'Diễn viên'], ['photo', 'Ảnh']];
export function ActivityPage() {
  const user = useAuth(); const [tab, setTab] = useState('movie'); const [mode, setMode] = useState('viewed'); const [pv, setPv] = useState(null);
  const s = useAsync(async () => {
    if (!user) return null;
    if (mode === 'liked') { const r = await get('/me/marks', { kind: 'favorite', target: tab === 'performer' ? 'performer' : 'movie', per_page: 100 }); return { liked: r.data }; }
    const r = await get('/me/history', { kind: tab, per_page: 100 }); return { hist: r.data };
  }, [user?.id, tab, mode]);
  const content = useAsync(async () => {
    const d = s.data; if (!d) return null; const refs = (d.liked || d.hist || []).map(x => x.ref);
    if (tab === 'movie') { const by = await moviesOf(refs.map(ref => ({ ref }))); return { movies: [...new Set(refs)].map(r => by.get(r)).filter(Boolean) }; }
    if (tab === 'performer') { const ids = [...new Set(refs)].map(Number).filter(Boolean); if (!ids.length) return { people: [] }; return { people: (await send('POST', '/performers/lookups', { ids })).data.map(adaptPerformer) }; }
    return { photos: (d.hist || []).map(h => ({ key: h.ref, label: h.label })) };
  }, [JSON.stringify(s.data)]);
  if (!user) return <NeedLogin />;
  return <Stack gap="lg"><PageHead title="Hoạt động của tôi" sub="Những gì bạn đã xem và đã thích, theo từng loại." right={<SegmentedControl value={mode} onChange={setMode} data={[{ value: 'viewed', label: 'Đã xem' }, { value: 'liked', label: 'Đã thích' }]} />} />
    <Tabs value={tab} onChange={setTab}><Tabs.List>{KINDS.map(([k, l]) => <Tabs.Tab key={k} value={k}>{l}</Tabs.Tab>)}</Tabs.List></Tabs>
    <Async state={content} skeleton={<MovieGridSkeleton n={6} />}>{c => c.movies ? (c.movies.length ? <div className="pgrid fixed">{c.movies.map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div> : <Empty title={mode === 'viewed' ? 'Chưa xem phim nào' : 'Chưa thích phim nào'} />) : c.people ? (c.people.length ? <div className="pgrid">{c.people.map(p => <Paper key={p.id} className="tile" p="sm" component={Link} to={`/performers/${p.slug || p.id}`} style={{ textDecoration: 'none', color: 'inherit' }}><Group wrap="nowrap"><Avatar src={photo(p)} size={56} radius="md" imageProps={{ referrerPolicy: 'no-referrer' }} /><div style={{ minWidth: 0 }}><Text fw={500} truncate>{p.name}</Text><Text size="xs" c="dimmed">{fmt(p.count)} phim</Text></div></Group></Paper>)}</div> : <Empty title="Chưa có diễn viên" />) : (c.photos.length ? <Group gap="sm">{c.photos.map((a, i) => <Link key={i} to={`/movies/${a.label}`}><img src={a.key} alt={a.label} referrerPolicy="no-referrer" style={{ width: 160, height: 106, objectFit: 'cover', borderRadius: 8, display: 'block' }} /></Link>)}</Group> : <Empty title="Chưa xem ảnh nào" />)}</Async><QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
