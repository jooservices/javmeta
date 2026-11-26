import { useParams, useNavigate, Navigate } from 'react-router-dom';
import { useState } from 'react';
import { Stack, Group, Text, Title, Button, Skeleton } from '@mantine/core';
import { IconChevronLeft } from '@tabler/icons-react';
import { useAsync, Async, QuickView, Empty } from '../components.jsx';
import { t } from '../i18n/index.js'; import { PerformerBody, RecentFilms } from '../detail.jsx'; import { readContext } from '../lists.js'; import { ListNav, useListKeys } from '../listnav.jsx'; import { PerformerInsights } from '../features.jsx'; import { auth, loadPerformer, loadPerformerExtras, personal } from '../shim.js';
export default function Performer() {
  const { id: raw } = useParams(); const id = parseInt(raw, 10); const nav = useNavigate(); const [pv, setPv] = useState(null);
  const ctx = readContext('performers', id); const pos = ctx ? ctx.items.indexOf(id) : -1; const prev = pos > 0 ? ctx.items[pos - 1] : null, next = pos >= 0 && pos < (ctx?.items.length || 0) - 1 ? ctx.items[pos + 1] : null;
  const go = i => i != null && nav(`/performers/${i}`, { replace: true }); useListKeys(prev, next, go);
  const s = useAsync(async () => { try { const p = await loadPerformer(id); await loadPerformerExtras(p.id); auth.load().then(() => personal.noteView('performer', String(p.id), p.name)); return { p: { ...p } }; } catch (e) { if (e.status === 404) return { p: null }; throw e; } }, [id]);
  return <Async state={s} skeleton={<Group align="flex-start"><Skeleton h={360} w={280} /><Stack flex={1}><Skeleton h={30} /><Skeleton h={120} /></Stack></Group>}>{({ p }) => !p ? <Empty title={t('performer.notFound')} /> : (p.canonicalSlug && raw !== p.canonicalSlug ? <Navigate to={`/performers/${p.canonicalSlug}`} replace /> : <Stack gap="lg">
    <Group justify="space-between" wrap="wrap"><Button size="compact-sm" variant="subtle" color="gray" leftSection={<IconChevronLeft size={14} />} onClick={() => nav(-1)}>{t('auth.back')}</Button><ListNav pos={pos} total={ctx?.items.length} label={ctx?.label} prev={prev} next={next} onGo={go} noun={t('noun.performer')} /></Group>
    <div><Title order={2} fw={600}>{p.name}</Title><Text c="dimmed">{[p.kanji, p.kana].filter(Boolean).join(' · ')}</Text></div>
    <PerformerBody p={p} link={false} recent={false} />
    <PerformerInsights p={p} />
    <RecentFilms id={p.id} total={p.count} />
    <QuickView code={pv} onClose={() => setPv(null)} /></Stack>)}</Async>;
}
