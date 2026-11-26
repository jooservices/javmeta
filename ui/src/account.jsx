import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Menu, Avatar, UnstyledButton, Group, Stack, Text, Badge, Paper, Button, TextInput, PasswordInput, Tabs, SegmentedControl, SimpleGrid, Table, Divider, Anchor } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { IconUser, IconEye, IconDownload, IconTimeline, IconHeart, IconClock, IconBell, IconFolder, IconPhoto, IconLogout, IconChevronDown, IconLogin } from '@tabler/icons-react';
import { auth, photo, personal, fmt, adaptMovie, adaptPerformer, ago } from './shim.js'; import { get, send, ApiError } from './data/api.js';
import { MovieCard, QuickView, PageHead, Empty, Async, useAsync, usePersonal, useAuth, Cover, MovieGridSkeleton, Pager } from './components.jsx'; import { t, tk, locale } from './i18n/index.js';

const initials = n => (n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
const ROLE = { super_administrator: tk('acct.role.super'), admin: tk('acct.role.admin'), user: tk('acct.role.user') };
const roleName = r => (ROLE[r] ? t(ROLE[r]) : r);
export const UserAvatar = ({ user, size = 36 }) => <Avatar size={size} radius="xl" color="teal" variant="filled">{initials(user.name)}</Avatar>;
const when = t => new Date(t).toLocaleString(locale(), { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

// the account menu for a signed-in user, and a sign-in button for a visitor
export function AvatarMenu() {
  const user = useAuth(); const nav = useNavigate();
  if (!user) return <Button component={Link} to="/login" size="compact-md" variant="light" leftSection={<IconLogin size={16} />}>{t('auth.login')}</Button>;
  const Item = ({ to, icon: Ic, children }) => <Menu.Item component={Link} to={to} leftSection={<Ic size={16} />} py={10}>{children}</Menu.Item>;
  return <Menu width={300} position="bottom-end" shadow="lg" withinPortal offset={8}>
    <Menu.Target><UnstyledButton aria-label={t('acct.account')} style={{ borderRadius: 999 }}><Group gap={6} wrap="nowrap"><UserAvatar user={user} size={36} /><IconChevronDown size={14} color="#8a96a4" /></Group></UnstyledButton></Menu.Target>
    <Menu.Dropdown>
      <Group gap="sm" p="sm" wrap="nowrap"><UserAvatar user={user} size={44} /><div style={{ minWidth: 0 }}><Text fw={600} truncate>{user.name}</Text><Text size="xs" c="dimmed" truncate>{user.email}</Text><Badge size="md" variant="light" mt={4}>{roleName(user.role)}</Badge></div></Group>
      <Divider />
      <Menu.Label>{t('acct.account')}</Menu.Label><Item to="/profile" icon={IconUser}>{t('acct.myProfile')}</Item>
      <Menu.Label>{t('acct.activity')}</Menu.Label><Item to="/history" icon={IconEye}>{t('acct.viewedMovies')}</Item><Item to="/downloaded" icon={IconDownload}>{t('acct.downloadedMovies')}</Item><Item to="/activity" icon={IconTimeline}>{t('acct.myActivity')}</Item>
      <Menu.Label>{t('acct.myLists')}</Menu.Label><Item to="/favorites" icon={IconHeart}>{t('nav.favorites')}</Item><Item to="/watch-later" icon={IconClock}>{t('nav.watchLater')}</Item><Item to="/following" icon={IconBell}>{t('nav.following')}</Item><Item to="/library" icon={IconFolder}>{t('nav.library')}</Item><Item to="/downloads" icon={IconDownload}>{t('nav.downloads')}</Item><Item to="/media" icon={IconPhoto}>{t('acct.mediaFiles')}</Item>
      <Divider my={4} /><Menu.Item color="red" leftSection={<IconLogout size={16} />} py={10} onClick={async () => { await auth.logout(); nav('/'); }}>{t('auth.logout')}</Menu.Item>
    </Menu.Dropdown></Menu>;
}
export const NeedLogin = () => <Empty title={t('auth.needLogin')} hint={t('auth.needLoginHint')} action={<Button component={Link} to="/login">{t('auth.login')}</Button>} />;

export function LoginPage() {
  const nav = useNavigate(); const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [err, setErr] = useState(null); const [busy, setBusy] = useState(false); const [reset, setReset] = useState(false);
  const submit = async e => { e.preventDefault(); setBusy(true); setErr(null); try { await auth.login(email, pw); nav('/'); } catch (x) { setErr(x instanceof ApiError && x.status === 429 ? t('auth.tooMany') : t('auth.badCredentials')); } setBusy(false); };
  const sendReset = async () => { await send('POST', '/password-resets', { email }); notifications.show({ message: t('auth.resetRecorded'), color: 'teal' }); setReset(false); };
  return <Stack align="center" mt={48}><Paper className="tile" w="100%" maw={400} p="lg"><form onSubmit={submit}><Stack gap="md"><Text fw={600} fz="lg">{t('auth.login')}</Text>
    <TextInput label="Email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="username" data-autofocus />
    {!reset && <PasswordInput label={t('auth.password')} value={pw} onChange={e => setPw(e.target.value)} autoComplete="current-password" error={err} />}
    {reset ? <Group justify="space-between"><Button variant="subtle" onClick={() => setReset(false)}>{t('auth.back')}</Button><Button disabled={!email} onClick={sendReset}>{t('auth.sendReset')}</Button></Group> : <><Button type="submit" loading={busy} disabled={!email || !pw}>{t('auth.login')}</Button><Anchor size="sm" component="button" type="button" onClick={() => setReset(true)}>{t('auth.forgot')}</Anchor></>}</Stack></form></Paper></Stack>;
}

export function ProfilePage() {
  const user = useAuth(); const st = usePersonal(); const [name, setName] = useState(''); const [cur, setCur] = useState(''); const [nw, setNw] = useState(''); useEffect(() => { if (user) setName(user.name); }, [user]);
  const counters = useAsync(async () => (user ? (await get('/me')).data.counters : null), [user?.id]);
  if (!user) return <NeedLogin />;
  const save = async () => { try { await send('PATCH', '/me', { name, ...(nw ? { current_password: cur, new_password: nw } : {}) }); notifications.show({ title: t('profile.saved'), message: t('profile.updated'), color: 'teal' }); setCur(''); setNw(''); await auth.load(); } catch (e) { notifications.show({ title: t('profile.saveFailed'), message: e.errors?.[0]?.field === 'current_password' ? t('profile.wrongCurrent') : e.message, color: 'red' }); } };
  const c = counters.data || {};
  return <Stack gap="lg"><PageHead title={t('acct.myProfile')} sub={t('profile.sub')} />
    <SimpleGrid cols={{ base: 1, md: 3 }} spacing="lg">
      <Paper className="tile"><Stack align="center" gap="sm"><UserAvatar user={{ ...user, name }} size={96} /><Text fw={600} fz="lg">{name}</Text><Badge variant="light" size="lg">{roleName(user.role)}</Badge><Text size="sm" c="dimmed">{user.email}</Text></Stack></Paper>
      <Paper className="tile" style={{ gridColumn: 'span 2' }}><Stack gap="md"><Text fw={600}>{t('profile.info')}</Text>
        <SimpleGrid cols={{ base: 1, sm: 2 }}><TextInput label={t('profile.displayName')} value={name} onChange={e => setName(e.target.value)} /><TextInput label="Email" value={user.email} readOnly /></SimpleGrid>
        <Divider /><Text fw={600}>{t('profile.changePassword')}</Text><SimpleGrid cols={{ base: 1, sm: 2 }}><PasswordInput label={t('profile.currentPassword')} value={cur} onChange={e => setCur(e.target.value)} /><PasswordInput label={t('profile.newPassword')} value={nw} onChange={e => setNw(e.target.value)} /></SimpleGrid>
        <Group justify="flex-end"><Button onClick={save}>{t('profile.saveChanges')}</Button></Group></Stack></Paper></SimpleGrid>
    <SimpleGrid cols={{ base: 2, md: 4 }}>{[[t('profile.marks'), c.marks ?? st.fav.length, '/favorites'], [t('profile.following'), c.follows ?? st.follows.length, '/following'], [t('profile.savedSearches'), c.saved_searches ?? st.saved.length, '/search'], [t('profile.downloadRequests'), c.download_requests ?? st.requests.length, '/downloads']].map(([k, v, to]) => <Paper className="tile" key={k} component={Link} to={to} style={{ textDecoration: 'none', color: 'inherit' }}><Text size="xs" c="dimmed" tt="uppercase" fw={600}>{k}</Text><Text fz={26} fw={600} className="mono">{fmt(v)}</Text></Paper>)}</SimpleGrid></Stack>;
}
// history rows -> the movies they name (one lookup call)
async function moviesOf(rows) { const codes = [...new Set(rows.map(r => r.ref))]; if (!codes.length) return new Map(); const { data } = await send('POST', '/movies/lookups', { codes }); return new Map(data.map(adaptMovie).map(m => [m.code, m])); }
export function ViewedPage() {
  const user = useAuth(); const [pv, setPv] = useState(null); const [page, setPage] = useState(1);
  const s = useAsync(async () => { if (!user) return null; const r = await get('/me/history', { kind: 'movie', page, per_page: 48 }); const by = await moviesOf(r.data); return { rows: r.data.filter(x => by.has(x.ref)).map(x => ({ m: by.get(x.ref), at: x.viewed_at })), pg: r.meta.pagination }; }, [user?.id, page]);
  if (!user) return <NeedLogin />;
  return <Stack gap="lg"><PageHead title={t('acct.viewedMovies')} sub={t('viewed.sub')} />
    <Async state={s} skeleton={<MovieGridSkeleton n={8} />}>{d => d.rows.length ? <><div className="pgrid fixed">{d.rows.map((r, i) => <div key={i}><MovieCard m={r.m} onPreview={setPv} /><Text size="xs" c="dimmed" mt={4}>{when(r.at)}</Text></div>)}</div><Pager res={{ page: d.pg.page, per: d.pg.per_page, total: d.pg.total, pages: d.pg.pages }} set={p => p.page && setPage(p.page)} sizes={[48]} /></> : <Empty title={t('viewed.none')} hint={t('viewed.noneHint')} action={<Button component={Link} to="/movies" variant="light">{t('viewed.browse')}</Button>} />}</Async><QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
export function DownloadedPage() {
  const user = useAuth();
  const s = useAsync(async () => { if (!user) return null; const r = await get('/me/history', { kind: 'download', per_page: 100 }); const by = await moviesOf(r.data); return r.data.filter(x => by.has(x.ref)).map(x => ({ ...x, m: by.get(x.ref) })); }, [user?.id]);
  if (!user) return <NeedLogin />;
  return <Stack gap="lg"><PageHead title={t('acct.downloadedMovies')} sub={t('downloaded.sub')} />
    <Async state={s} skeleton={<MovieGridSkeleton n={6} />}>{rows => rows.length ? <div className="scroll-x"><Table miw={640} highlightOnHover><Table.Thead><Table.Tr><Table.Th>{t('common.movie')}</Table.Th><Table.Th>{t('downloaded.type')}</Table.Th><Table.Th>{t('downloaded.time')}</Table.Th></Table.Tr></Table.Thead><Table.Tbody>{rows.map((d, i) => <Table.Tr key={i}><Table.Td><Group gap="md" wrap="nowrap"><div className="poster" style={{ width: 96, flexShrink: 0 }}><Cover m={d.m} /></div><div><Text component={Link} to={`/movies/${d.m.code}`} className="mono" size="sm" c="teal.3">{d.m.code}</Text><Text size="xs" c="dimmed" lineClamp={1}>{(d.m.en || '').replace(d.m.code, '').trim()}</Text></div></Group></Table.Td><Table.Td>{d.label}</Table.Td><Table.Td className="mono">{when(d.viewed_at)}</Table.Td></Table.Tr>)}</Table.Tbody></Table></div> : <Empty title={t('downloaded.none')} hint={t('downloaded.noneHint')} />}</Async></Stack>;
}
const KINDS = [['movie', tk('common.movies')], ['performer', tk('common.performers')], ['photo', tk('common.photos')]];
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
  return <Stack gap="lg"><PageHead title={t('acct.myActivity')} sub={t('activity.sub')} right={<SegmentedControl value={mode} onChange={setMode} data={[{ value: 'viewed', label: t('activity.viewed') }, { value: 'liked', label: t('activity.liked') }]} />} />
    <Tabs value={tab} onChange={setTab}><Tabs.List>{KINDS.map(([k, l]) => <Tabs.Tab key={k} value={k}>{t(l)}</Tabs.Tab>)}</Tabs.List></Tabs>
    <Async state={content} skeleton={<MovieGridSkeleton n={6} />}>{c => c.movies ? (c.movies.length ? <div className="pgrid fixed">{c.movies.map(m => <MovieCard key={m.id} m={m} onPreview={setPv} />)}</div> : <Empty title={mode === 'viewed' ? t('viewed.none') : t('activity.noLikedMovies')} />) : c.people ? (c.people.length ? <div className="pgrid">{c.people.map(p => <Paper key={p.id} className="tile" p="sm" component={Link} to={`/performers/${p.slug || p.id}`} style={{ textDecoration: 'none', color: 'inherit' }}><Group wrap="nowrap"><Avatar src={photo(p)} size={56} radius="md" imageProps={{ referrerPolicy: 'no-referrer' }} /><div style={{ minWidth: 0 }}><Text fw={500} truncate>{p.name}</Text><Text size="xs" c="dimmed">{t('common.moviesCount', { n: fmt(p.count) })}</Text></div></Group></Paper>)}</div> : <Empty title={t('activity.noPerformers')} />) : (c.photos.length ? <Group gap="sm">{c.photos.map((a, i) => <Link key={i} to={`/movies/${a.label}`}><img src={a.key} alt={a.label} referrerPolicy="no-referrer" style={{ width: 160, height: 106, objectFit: 'cover', borderRadius: 8, display: 'block' }} /></Link>)}</Group> : <Empty title={t('activity.noPhotos')} />)}</Async><QuickView code={pv} onClose={() => setPv(null)} /></Stack>;
}
