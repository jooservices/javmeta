const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
export function Mark({ text, q }) {
  const ws = (q || '').trim().split(/\s+/).filter(w => w.length > 1); if (!text || !ws.length) return text;
  const parts = String(text).split(new RegExp(`(${ws.map(esc).join('|')})`, 'ig'));
  return parts.map((p, i) => (ws.some(w => w.toLowerCase() === p.toLowerCase()) ? <mark key={i} style={{ background: 'none', color: 'var(--mantine-color-teal-3)', fontWeight: 600 }}>{p}</mark> : p));
}
