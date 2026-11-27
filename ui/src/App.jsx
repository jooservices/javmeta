import { useState, useRef, useEffect, useMemo, useSyncExternalStore } from 'react';
import { Routes, Route, NavLink as RLink, useNavigate, useLocation, Link, Navigate, useParams } from 'react-router-dom';
import { AppShell, Burger, Group, NavLink, Collapse, Divider as Div, TextInput, Text, Badge, ScrollArea, Popover, Stack, Avatar, Kbd, ActionIcon, Modal, Table, Divider, Box, UnstyledButton, Tooltip, Loader } from '@mantine/core';
import { useDisclosure, useHotkeys, useDebouncedValue, useLocalStorage, useMediaQuery } from '@mantine/hooks';
import { IconSearch, IconCompass, IconMovie, IconUsers, IconCategory, IconCalendar, IconChartBar, IconHeart, IconClock, IconEyeCheck, IconBell, IconFolder, IconDownload, IconPhoto, IconActivity, IconServer2, IconKey, IconSettings, IconReportAnalytics, IconHelp, IconSparkles, IconUserCheck, IconAlertTriangle, IconBrandTelegram, IconLayoutSidebarLeftCollapse, IconLayoutSidebarLeftExpand, IconChevronDown } from '@tabler/icons-react';

import { installListTracker } from './lists.js'; import { Omnibox } from './omnibox.jsx'; import { PlayerHost, useGoKeys, PrivacyLayer, PrivacyToggle, ShortcutTable } from './features.jsx'; import { AvatarMenu, ProfilePage, ViewedPage, DownloadedPage, ActivityPage, LoginPage, NeedLogin } from './account.jsx'; import { auth, loadViews, loadCatalog, fmt } from './shim.js'; import { useScope, Empty } from './components.jsx'; import { Button } from '@mantine/core'; import Home from './pages/Home.jsx'; import Updates from './pages/Updates.jsx'; import Search from './pages/Search.jsx'; import Movies from './pages/Movies.jsx'; import Movie from './pages/Movie.jsx';
import Performers from './pages/Performers.jsx'; import Performer from './pages/Performer.jsx'; import Catalog from './pages/Catalog.jsx'; import Calendar from './pages/Calendar.jsx'; import Stats from './pages/Stats.jsx';
import { t, tk, locale } from './i18n/index.js'; import { LangSwitch } from './langswitch.jsx';
import Mine from './pages/Mine.jsx'; import Crawl from './pages/Crawl.jsx'; import Ops from './pages/Ops.jsx'; import Admin from './pages/Admin.jsx'; import Settings from './pages/Settings.jsx'; import Help from './pages/Help.jsx';

const NAV = [
  [tk('nav.group.explore'), [['/', tk('nav.explore'), IconCompass], ['/search', tk('nav.search'), IconSearch], ['/updates', tk('nav.updates'), IconSparkles]]],
  [tk('nav.group.data'), [['/movies', tk('nav.movies'), IconMovie], ['/performers', tk('nav.performers'), IconUsers], ['/genres', tk('nav.categories'), IconCategory], ['/calendar', tk('nav.calendar'), IconCalendar], ['/stats', tk('nav.stats'), IconChartBar]]],
  [tk('nav.group.mine'), [['/favorites', tk('nav.favorites'), IconHeart], ['/following', tk('nav.following'), IconBell], ['/history', tk('nav.history'), IconEyeCheck], ['/library', tk('nav.library'), IconFolder], ['/downloads', tk('nav.downloads'), IconDownload]], 'owner'],
  [tk('nav.group.ops'), [['/admin', tk('nav.commandCenter'), IconServer2], ['/admin/sources', tk('nav.crawl'), IconActivity]], 'admin'],
  [tk('nav.group.admin'), [['/admin/keys', tk('nav.keys'), IconKey], ['/admin/settings', tk('nav.settings'), IconSettings], ['/admin/notifications', tk('nav.notifications'), IconBrandTelegram], ['/admin/reports', tk('nav.reports'), IconReportAnalytics]], 'admin'],
  [tk('nav.group.help'), [['/help', tk('nav.help'), IconHelp]]],
];
const CATALOGS = ['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood-types', 'locations'];
const RANK = { guest: 0, owner: 1, admin: 2 };
function Guard({ min, children }) { const scope = useScope(); if (RANK[scope] >= RANK[min]) return children; return <NeedLogin />; }
function WatchRedirect() { const { code } = useParams(); return <Navigate to={`/movies/${code}?play=1`} replace />; }
export default function App() {
  const [open, { toggle, close }] = useDisclosure(); const loc = useLocation(); const [help, hs] = useDisclosure(false);
  const [rail, setRail] = useLocalStorage({ key: 'xc.nav.rail', defaultValue: false }); const [shut, setShut] = useLocalStorage({ key: 'xc.nav.shut', defaultValue: {} });
  const scope = useScope(); const loginRequired = useSyncExternalStore(auth.subscribe, auth.loginRequired); const desktop = useMediaQuery('(min-width: 62em)'); const slim = desktop && rail;
  useEffect(close, [loc.pathname]);
  useEffect(() => { auth.load().then(() => loadViews()); return auth.subscribe(() => loadViews()); }, []);
  useLegacyHash();
  useEffect(() => { installListTracker(); }, []);
  useGoKeys();
  useHotkeys([['?', hs.open], ['mod+B', () => setRail(r => !r)]]);
  // While the installation requires sign-in (the default), a visitor sees only the sign-in page.
  const canRead = !(loginRequired && scope === 'guest');
  if (!canRead && loc.pathname !== '/login') return <Navigate to="/login" replace />;
  const here = loc.pathname + loc.search;
  const active = to => { const p = to; if (p === '/') return loc.pathname === '/'; if (p === '/movies') return loc.pathname === '/movies' || loc.pathname.startsWith('/movies/'); if (p === '/genres') return CATALOGS.some(k => loc.pathname === `/${k}` || loc.pathname.startsWith(`/${k}/`)); if (p === '/performers') return loc.pathname.startsWith('/performers'); if (p === '/admin') return loc.pathname === '/admin'; return loc.pathname === p; };
  return <AppShell header={{ height: 56 }} navbar={{ width: slim ? 64 : 232, breakpoint: 'md', collapsed: { mobile: !open } }} padding={{ base: 'sm', sm: 'md', md: 'lg' }} styles={{ navbar: { transition: 'width .18s ease' } }}>
    <PrivacyLayer /><PlayerHost /><AppShell.Header><Group h="100%" px="md" gap="md" wrap="nowrap"><Group gap="md" wrap="nowrap" style={{ flexShrink: 0, width: desktop && !slim ? 224 : undefined }}><Burger opened={open} onClick={toggle} hiddenFrom="md" size="sm" aria-label={t('app.openMenu')} />
      <Tooltip label={rail ? t('app.expandSidebarTip') : t('app.collapseSidebarTip')}><ActionIcon variant="default" visibleFrom="md" onClick={() => setRail(r => !r)} aria-label={rail ? t('app.expandSidebar') : t('app.collapseSidebar')}>{rail ? <IconLayoutSidebarLeftExpand size={18} /> : <IconLayoutSidebarLeftCollapse size={18} />}</ActionIcon></Tooltip>
      <Group gap="xs" component={Link} to="/" style={{ textDecoration: 'none', color: 'inherit' }} wrap="nowrap"><Box w={28} h={28} bg="teal.6" style={{ borderRadius: 8, display: 'grid', placeItems: 'center', color: '#04201e', fontWeight: 700 }}>X</Box><Text fw={600} visibleFrom="xs">XCrawler</Text></Group></Group>
      <Box flex={1} miw={0} style={{ display: 'flex', justifyContent: 'flex-start' }}><Omnibox /></Box>
      {canRead && <LiveIndicator />}<LangSwitch /><PrivacyToggle /><ActionIcon variant="default" onClick={hs.open} aria-label={t('app.shortcutsHelp')} visibleFrom="sm"><IconHelp size={18} /></ActionIcon><AvatarMenu /></Group></AppShell.Header>
    <AppShell.Navbar p="xs"><ScrollArea><Stack gap={4}>{NAV.filter(([, , min]) => !min || RANK[scope] >= RANK[min]).map(([g, items]) => { const closed = !!shut[g] && !slim;
      return <div key={g}>
        {slim ? <Div my="xs" /> : <UnstyledButton className="nav-group-toggle" w="100%" px="sm" py={4} onClick={() => setShut(x => ({ ...x, [g]: !x[g] }))} aria-expanded={!closed} aria-label={closed ? t('nav.openGroup', { name: t(g) }) : t('nav.closeGroup', { name: t(g) })}><Group justify="space-between" wrap="nowrap"><Text size="xs" tt="uppercase" c="dimmed" fw={600}>{t(g)}</Text><IconChevronDown size={12} color="#5a6572" style={{ transform: closed ? 'rotate(-90deg)' : 'none', transition: 'transform .15s' }} /></Group></UnstyledButton>}
        <Collapse expanded={!closed}><Stack gap={4}>{items.map(([to, labelKey, Ic]) => { const label = t(labelKey); return slim
          ? <Tooltip key={to} label={label} position="right" withArrow><NavLink component={Link} to={to} aria-label={label} leftSection={<Ic size={18} stroke={1.6} />} active={active(to)} style={{ borderRadius: 8, justifyContent: 'center' }} py={8} px={0} /></Tooltip>
          : <NavLink key={to} component={Link} to={to} label={label} leftSection={<Ic size={16} stroke={1.6} />} active={active(to)} style={{ borderRadius: 8 }} py={8} />; })}</Stack></Collapse></div>; })}
      {!slim && <Text size="xs" c="dimmed" px="sm" py="md">{t('app.liveFromProduction')}</Text>}</Stack></ScrollArea></AppShell.Navbar>
    <AppShell.Main style={{ display: 'flex', flexDirection: 'column', minHeight: 'calc(100vh - 56px)' }}><Box flex={1}><Routes><Route path="/" element={<Home />} /><Route path="/search" element={<Search />} /><Route path="/updates" element={<Updates />} /><Route path="/movies" element={<Movies />} /><Route path="/movies/:code" element={<Movie />} /><Route path="/watch/:code" element={<WatchRedirect />} />
      <Route path="/performers" element={<Performers />} /><Route path="/performers/:id" element={<Performer />} />{CATALOGS.map(k => <Route key={k} path={`/${k}`} element={<Catalog kind={k} />} />)}<Route path="/calendar" element={<Calendar />} /><Route path="/stats" element={<Stats />} />
      <Route path="/login" element={<LoginPage />} /><Route path="/profile" element={<ProfilePage />} /><Route path="/activity" element={<ActivityPage />} /><Route path="/history" element={<ViewedPage />} /><Route path="/downloaded" element={<DownloadedPage />} />
      <Route path="/favorites" element={<Mine kind="fav" />} /><Route path="/watch-later" element={<Mine kind="later" />} /><Route path="/watched" element={<Mine kind="seen" />} /><Route path="/following" element={<Mine kind="follows" />} /><Route path="/library" element={<Mine kind="library" />} /><Route path="/downloads" element={<Mine kind="downloads" />} /><Route path="/requests" element={<Navigate to="/downloads" replace />} /><Route path="/media" element={<Mine kind="media" />} />
      <Route path="/admin" element={<Guard min="admin"><Ops tab="overview" /></Guard>} />{['infrastructure', 'queue', 'nodes', 'alerts', 'errors', 'runs', 'events'].map(t => <Route key={t} path={`/admin/${t}`} element={<Guard min="admin"><Ops tab={t === 'infrastructure' ? 'infra' : t} /></Guard>} />)}
      <Route path="/admin/sources" element={<Guard min="admin"><Ops tab="sources" /></Guard>} /><Route path="/admin/keys" element={<Guard min="admin"><Admin /></Guard>} /><Route path="/admin/users" element={<Navigate to="/admin/keys" replace />} />
      <Route path="/admin/settings" element={<Guard min="admin"><Settings page="settings" /></Guard>} /><Route path="/admin/notifications" element={<Guard min="admin"><Settings page="notifications" /></Guard>} /><Route path="/admin/reports" element={<Guard min="admin"><Settings page="reports" /></Guard>} /><Route path="/help" element={<Help />} /><Route path="*" element={<Empty title={t('app.notFound')} hint={t('app.notFoundHint')} action={<Button component={Link} to="/" variant="light">{t('app.goHome')}</Button>} />} /></Routes></Box>
      <Group justify="space-between" wrap="wrap" component="footer" mt="xl" pt="md" style={{ borderTop: '1px solid var(--mantine-color-dark-5)' }}><Text size="xs" c="dimmed">© 2026 JOOservices. All rights reserved.</Text><Group gap="md"><Text size="xs" c="dimmed">XCrawler Console</Text>{canRead && <LiveDataNote />}</Group></Group></AppShell.Main>
    <Modal opened={help} onClose={hs.close} title={t('app.shortcuts')} centered><ShortcutTable /></Modal>
  </AppShell>;
}

// old links /app#/movies/ABC-123 keep working: the hash is mapped to the new clean path
function useLegacyHash() { const nav = useNavigate(); useEffect(() => { const h = window.location.hash; if (!h.startsWith('#/')) return; const map = [[/^#\/catalog\/(.+)$/, (m) => `/${m[1]}`], [/^#\/mine\/fav/, () => '/favorites'], [/^#\/mine\/later/, () => '/watch-later'], [/^#\/mine\/seen/, () => '/watched'], [/^#\/mine\/follows/, () => '/following'], [/^#\/mine\/viewed/, () => '/history'], [/^#\/ops/, () => '/admin'], [/^#\/crawl/, () => '/admin/sources'], [/^#\/settings\/(.+)$/, (m) => `/admin/settings/${m[1]}`.replace('/settings/notifications', '/notifications').replace('/settings/reports', '/reports')], [/^#(\/.*)$/, (m) => m[1]]]; for (const [re, to] of map) { const m = h.match(re); if (m) { nav(to(m), { replace: true }); return; } } }, []); }
// the top-bar pill: counts of the live warehouse
function LiveIndicator() { const [d, setD] = useState(null); useEffect(() => { loadCatalog().then(c => setD(c.summary)); }, []); if (!d) return null; return <Group gap={6} visibleFrom="lg" style={{ whiteSpace: 'nowrap' }}><span className="dot" style={{ background: d.sources_ok === d.sources_enabled ? '#5fd38d' : '#f5c542' }} /><Text size="xs" c="dimmed">{t('app.live', { movies: fmt(d.movies), performers: fmt(d.performers), ok: d.sources_ok, total: d.sources_enabled })}</Text></Group>; }
function LiveDataNote() { const [d, setD] = useState(null); useEffect(() => { loadCatalog().then(c => setD(c.overview)); }, []); return <Text size="xs" c="dimmed">{d ? t('app.dataUpdated', { at: new Date(d.data_as_of).toLocaleString(locale()) }) : ''}</Text>; }
