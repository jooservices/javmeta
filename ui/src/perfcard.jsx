import { Link } from 'react-router-dom';
import { LazyImg } from './lazyimg.jsx';
import { Paper, Group, Avatar, Box, Text, Badge } from '@mantine/core';
import { photo } from './shim.js';
import { Mark } from './mark.jsx'; import { t } from './i18n/index.js';

export function PerfCard({ x, hl }) {
  return <Paper className="tile" p={0} component={Link} to={`/performers/${x.slug || x.id}`} style={{ textDecoration: 'none', color: 'inherit', overflow: 'hidden' }}>
    <div className="poster cover-portrait" style={{ borderRadius: 0 }}>{x.img ? <LazyImg src={photo(x)} alt={x.name}  /> : <Group h="100%" justify="center"><Avatar size={64} color="teal" variant="light">{(x.name || '?')[0]}</Avatar></Group>}</div>
    <Box p="xs"><Text size="sm" fw={500} truncate><Mark text={x.name} q={hl} /></Text><Group justify="space-between"><Text size="xs" c="dimmed" truncate>{x.kanji ? <Mark text={x.kanji} q={hl} /> : ' '}</Text><Badge size="md" variant="light">{t('common.moviesCount', { n: x.count })}</Badge></Group></Box></Paper>;
}
