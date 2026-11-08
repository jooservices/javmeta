import { $ } from './util.js';
import { ic } from './icons.js';

const get = (k, d) => { try { return localStorage.getItem(k) || d; } catch { return d; } };
const put = (k, v) => { try { localStorage.setItem(k, v); } catch { /* ignore */ } };

export const currentTheme = () => get('xc-theme', 'dark');
export const currentDensity = () => get('xc-density', 'normal');

export function applyTheme(t) {
  put('xc-theme', t);
  const eff = t === 'system' ? (matchMedia('(prefers-color-scheme:light)').matches ? 'light' : 'dark') : t;
  document.documentElement.dataset.theme = eff;
  const b = $('#themebtn'); if (b) b.innerHTML = ic(eff === 'light' ? 'moon' : 'sun');
}
export function applyDensity(d) {
  put('xc-density', d);
  document.body.dataset.density = d;
}
export function toggleTheme() {
  applyTheme(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light');
}
export function initTheme() {
  applyTheme(currentTheme());
  applyDensity(currentDensity());
}
