export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => [...r.querySelectorAll(s)];
export const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const fold = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').toLowerCase();
export const fmt = n => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('vi-VN');
export const hueOf = s => { let h = 7; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
export const initials = n => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(s => s[0]).join('').toUpperCase();
export const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

export function ago(iso) {
  if (!iso) return '—';
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 90) return Math.round(s) + ' giây trước';
  if (s < 5400) return Math.round(s / 60) + ' phút trước';
  if (s < 129600) return Math.round(s / 3600) + ' giờ trước';
  return Math.round(s / 86400) + ' ngày trước';
}

export function toast(text) {
  const e = document.createElement('div');
  e.className = 'toast';
  e.textContent = text;
  $('#toasts').append(e);
  setTimeout(() => e.remove(), 2600);
}

export async function copyText(text, ok = 'Đã sao chép') {
  try { await navigator.clipboard.writeText(text); toast(ok); } catch { toast(text); }
}

export const queryString = o => {
  const p = new URLSearchParams();
  Object.entries(o || {}).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined && v !== false) p.set(k, v === true ? '1' : v); });
  const s = p.toString();
  return s ? '?' + s : '';
};

/* the stable title of a movie: English, else Japanese, else its code */
export const titleOf = m => m.title_en || m.title_jp || m.code;
