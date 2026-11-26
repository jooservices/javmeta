import { Group, Text, Badge, UnstyledButton } from '@mantine/core';
import { LazyImg } from './lazyimg.jsx';
import { fmt } from './shim.js';
import { QuickIcon } from './components.jsx';

import { Mark } from './mark.jsx';
export { Mark };
export const Head = ({ icon: Ic, label, count, onAll }) => <Group justify="space-between" px="md" pt="md" pb="xs" wrap="nowrap"><Group gap="xs" wrap="nowrap"><Ic size={16} color="#8a96a4" /><Text size="xs" fw={600} tt="uppercase" c="dimmed">{label}</Text>{count != null && <Badge size="md" variant="light" color="gray">{fmt(count)}</Badge>}</Group>{onAll && <UnstyledButton onClick={onAll} mih={32} px="xs"><Text size="sm" c="teal.3">Xem tất cả →</Text></UnstyledButton>}</Group>;
export function Row({ active, onClick, onHover, onQuick, thumb, round, title, sub, right, q }) {
  const w = round ? 64 : 120, h = round ? 64 : 80;
  return <div role="link" tabIndex={0} onClick={onClick} onKeyDown={e => e.key === 'Enter' && onClick()} onMouseEnter={onHover} style={{ cursor: 'pointer', background: active ? 'var(--mantine-color-dark-6)' : 'transparent', borderLeft: `2px solid ${active ? 'var(--mantine-color-teal-6)' : 'transparent'}`, padding: '8px 16px' }}>
    <Group gap="md" wrap="nowrap"><div style={{ position: 'relative', width: w, height: h, flexShrink: 0 }}><div style={{ width: '100%', height: '100%', borderRadius: round ? '50%' : 8, overflow: 'hidden', background: 'var(--mantine-color-dark-7)' }}>{thumb ? <LazyImg src={thumb} alt=""  style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} /> : <div style={{ width: '100%', height: '100%', display: 'grid', placeItems: 'center', fontSize: 22, fontWeight: 600, color: 'var(--mantine-color-dimmed)' }}>{((title || '?')[0] || '?').toUpperCase()}</div>}</div>{onQuick && <QuickIcon onClick={onQuick} offset={round ? -4 : 8} />}</div>
      <div style={{ minWidth: 0, flex: 1 }}><Text size="sm" fw={500} truncate><Mark text={title} q={q} /></Text>{sub && <Text size="xs" c="dimmed" lineClamp={2}>{sub}</Text>}</div>{right && <Text size="xs" c="dimmed" className="mono" style={{ flexShrink: 0 }}>{right}</Text>}</Group></div>;
}
export function PhotoGrid({ items, onQuick, onOpen }) {
  return <Group gap="sm" px="md" py="xs" wrap="wrap">{items.map((x, i) => <div key={i} role="link" tabIndex={0} onClick={() => onOpen(x)} onKeyDown={e => e.key === 'Enter' && onOpen(x)} style={{ position: 'relative', width: 112, height: 112, borderRadius: 8, overflow: 'hidden', cursor: 'pointer', background: 'var(--mantine-color-dark-7)' }} aria-label={`${x.kind} ${x.label}`}><LazyImg src={x.src} alt={x.label}  style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} /><QuickIcon onClick={() => onQuick(x)} /></div>)}</Group>;
}
