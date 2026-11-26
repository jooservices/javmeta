import { useEffect } from 'react';
import { Group, Text, ActionIcon, Tooltip } from '@mantine/core';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-react';
import { t } from './i18n/index.js';

// ‹ 3 / 1397 · Results ›  with ← → keys; `blocked()` lets the page say that a window is open and the keys belong to it
export function ListNav({ pos, total, label, prev, next, onGo, noun }) {
  if (pos < 0) return null;
  return <Group gap="xs" wrap="nowrap" aria-label={t('listnav.pair', { noun })}>
    <Tooltip label={prev ? t('listnav.prevTip', { noun, name: prev }) : t('listnav.first')}><ActionIcon variant="default" size="lg" disabled={!prev} onClick={() => onGo(prev)} aria-label={t('listnav.prev', { noun })}><IconChevronLeft size={18} /></ActionIcon></Tooltip>
    <Text size="sm" c="dimmed" className="mono">{pos + 1} / {total}{label ? ` · ${label}` : ''}</Text>
    <Tooltip label={next ? t('listnav.nextTip', { noun, name: next }) : t('listnav.last')}><ActionIcon variant="default" size="lg" disabled={!next} onClick={() => onGo(next)} aria-label={t('listnav.next', { noun })}><IconChevronRight size={18} /></ActionIcon></Tooltip></Group>;
}
export function useListKeys(prev, next, onGo, blocked = () => false) {
  useEffect(() => {
    const k = e => { const t = e.target; if (e.metaKey || e.ctrlKey || e.altKey || blocked() || (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) || document.querySelector('.mantine-Modal-content, .mantine-Drawer-content')) return; if (e.key === 'ArrowLeft' && prev != null) { e.preventDefault(); onGo(prev); } else if (e.key === 'ArrowRight' && next != null) { e.preventDefault(); onGo(next); } };
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k);
  }, [prev, next, onGo, blocked]); // eslint-disable-line
}
