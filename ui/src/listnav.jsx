import { useEffect } from 'react';
import { Group, Text, ActionIcon, Tooltip } from '@mantine/core';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-react';

// ‹ 3 / 1397 · Kết quả ›  with ← → keys; `blocked()` lets the page say that a window is open and the keys belong to it
export function ListNav({ pos, total, label, prev, next, onGo, noun }) {
  if (pos < 0) return null;
  return <Group gap="xs" wrap="nowrap" aria-label={`${noun} trước và sau`}>
    <Tooltip label={prev ? `${noun} trước: ${prev} (←)` : 'Đây là mục đầu danh sách'}><ActionIcon variant="default" size="lg" disabled={!prev} onClick={() => onGo(prev)} aria-label={`${noun} trước`}><IconChevronLeft size={18} /></ActionIcon></Tooltip>
    <Text size="sm" c="dimmed" className="mono">{pos + 1} / {total}{label ? ` · ${label}` : ''}</Text>
    <Tooltip label={next ? `${noun} sau: ${next} (→)` : 'Đây là mục cuối danh sách'}><ActionIcon variant="default" size="lg" disabled={!next} onClick={() => onGo(next)} aria-label={`${noun} sau`}><IconChevronRight size={18} /></ActionIcon></Tooltip></Group>;
}
export function useListKeys(prev, next, onGo, blocked = () => false) {
  useEffect(() => {
    const k = e => { const t = e.target; if (e.metaKey || e.ctrlKey || e.altKey || blocked() || (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) || document.querySelector('.mantine-Modal-content, .mantine-Drawer-content')) return; if (e.key === 'ArrowLeft' && prev != null) { e.preventDefault(); onGo(prev); } else if (e.key === 'ArrowRight' && next != null) { e.preventDefault(); onGo(next); } };
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k);
  }, [prev, next, onGo, blocked]); // eslint-disable-line
}
