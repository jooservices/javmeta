// A tiny translation layer: t('key', { name }) with {name} interpolation, a plural helper, a React provider and a persisted language.
import { createElement, Fragment, useEffect, useSyncExternalStore } from 'react';
import vi from './vi.json';
import en from './en.json';

export const LANGS = ['vi', 'en'];
export const DEFAULT_LANG = 'vi';
export const STORAGE_KEY = 'xc.lang';
const DICTS = { vi, en };

const readStored = () => { try { const v = localStorage.getItem(STORAGE_KEY); return LANGS.includes(v) ? v : DEFAULT_LANG; } catch { return DEFAULT_LANG; } };
let lang = readStored();
const subs = new Set();
const apply = () => { if (typeof document !== 'undefined') document.documentElement.lang = lang; };

export const getLang = () => lang;
export const subscribe = f => { subs.add(f); return () => subs.delete(f); };
export function setLang(next) {
  if (!LANGS.includes(next) || next === lang) return;
  lang = next; try { localStorage.setItem(STORAGE_KEY, next); } catch { /* storage unavailable */ }
  apply(); subs.forEach(f => f());
}
// the locale tag for toLocaleString / Intl
export const locale = () => lang;

export const interpolate = (text, vars) => text.replace(/\{(\w+)\}/g, (whole, k) => (vars && k in vars ? String(vars[k]) : whole));
// the active language, then Vietnamese, then the key itself
export function t(key, vars) {
  const text = DICTS[lang][key] ?? DICTS[DEFAULT_LANG][key] ?? key;
  return vars ? interpolate(text, vars) : text;
}
// marks a key that is translated later (module-level tables): returns the key unchanged, the scanner still sees it
export const tk = key => key;
// plural helper: keys `<key>.one` and `<key>.other`; {n} is available in the text
export const tn = (key, n, vars) => t(`${key}.${n === 1 ? 'one' : 'other'}`, { n, ...vars });

export const useLang = () => useSyncExternalStore(subscribe, getLang, getLang);
// a language switch remounts the tree below it, so every t() call is evaluated again
export function I18nProvider({ children }) {
  const l = useLang(); useEffect(apply, [l]);
  return createElement(Fragment, { key: l }, children);
}
apply();
