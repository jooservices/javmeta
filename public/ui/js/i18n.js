/**
 * Client i18n for the plain ES-module UI (no build step).
 * Catalogs: /ui/i18n/{locale}.json — loaded before first render.
 * Keys are namespaced `view.section.item`. Plural forms use `{one,other}` objects (vi uses `other`).
 */
export const SUPPORTED = Object.freeze(['vi', 'en']);
export const DEFAULT_LOCALE = 'vi';
export const STORAGE_KEY = 'xc-locale';

/* Catalogs are fetched as plain JSON: JSON import attributes are not supported by every browser (Firefox before 138). */
const catalogs = { en: null, vi: null };
const loading = {};

async function readCatalog(locale) {
  const url = new URL(`../i18n/${locale}.json`, import.meta.url);
  if (url.protocol === 'file:') {
    const { readFile } = await import('node:fs/promises');
    return JSON.parse(await readFile(url, 'utf8'));
  }
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Could not load the ${locale} catalog (${response.status}).`);
  return response.json();
}

/** Loads one catalog once; a failed load is retried on the next call and never blocks the interface. */
export function loadCatalog(locale) {
  if (!SUPPORTED.includes(locale)) return Promise.resolve({});
  if (catalogs[locale]) return Promise.resolve(catalogs[locale]);
  loading[locale] ??= readCatalog(locale)
    .then(data => { catalogs[locale] = data; return data; })
    .catch(error => { warn(String(error.message || error)); return {}; })
    .finally(() => { delete loading[locale]; });
  return loading[locale];
}
const listeners = new Set();
let active = DEFAULT_LOCALE;
let ready = false;

const isDev = () => {
  try {
    return location.hostname === 'localhost' || location.hostname.endsWith('.localhost') || location.hostname === '127.0.0.1';
  } catch {
    return false;
  }
};

const warn = (msg) => {
  if (isDev()) console.warn('[i18n]', msg);
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

const readStored = () => {
  try {
    const v = localStorage.getItem(STORAGE_KEY);
    return SUPPORTED.includes(v) ? v : null;
  } catch {
    return null;
  }
};

const writeStored = (locale) => {
  try { localStorage.setItem(STORAGE_KEY, locale); } catch { /* ignore */ }
};

/** Prefer a stored choice, else the first browser language in vi|en, else vi. */
export function detectLocale() {
  const stored = readStored();
  if (stored) return stored;
  const langs = [];
  try {
    if (Array.isArray(navigator.languages)) langs.push(...navigator.languages);
    if (navigator.language) langs.push(navigator.language);
  } catch { /* ignore */ }
  for (const raw of langs) {
    const base = String(raw || '').toLowerCase().split('-')[0];
    if (SUPPORTED.includes(base)) return base;
  }
  return DEFAULT_LOCALE;
}

export function currentLocale() {
  return active;
}

export function isReady() {
  return ready;
}

function resolveMessage(locale, key) {
  const catalog = catalogs[locale] || {};
  if (Object.prototype.hasOwnProperty.call(catalog, key)) return catalog[key];
  return undefined;
}

function pickPlural(forms, count, locale) {
  if (forms == null || typeof forms !== 'object' || Array.isArray(forms)) return forms;
  const n = Number(count);
  try {
    const rule = new Intl.PluralRules(locale).select(Number.isFinite(n) ? n : 0);
    if (Object.prototype.hasOwnProperty.call(forms, rule)) return forms[rule];
  } catch { /* ignore */ }
  if (Object.prototype.hasOwnProperty.call(forms, 'other')) return forms.other;
  if (Object.prototype.hasOwnProperty.call(forms, 'one')) return forms.one;
  return undefined;
}

function interpolate(template, params, { html = false } = {}) {
  if (typeof template !== 'string') return template == null ? '' : String(template);
  return template.replace(/\{(\w+)\}/g, (_, name) => {
    if (!params || !Object.prototype.hasOwnProperty.call(params, name)) return `{${name}}`;
    const raw = params[name];
    return html ? String(raw ?? '') : escapeHtml(raw);
  });
}

function lookup(key, params = {}, { html = false } = {}) {
  let message = resolveMessage(active, key);
  if (message === undefined && active !== 'en') {
    message = resolveMessage('en', key);
    if (message !== undefined) warn(`missing ${active} key "${key}", falling back to en`);
  }
  if (message === undefined) {
    warn(`missing key "${key}"`);
    return key;
  }
  if (params && Object.prototype.hasOwnProperty.call(params, 'count') && message && typeof message === 'object') {
    message = pickPlural(message, params.count, active);
  }
  return interpolate(message, params, { html });
}

/** Translate a key. Interpolation escapes HTML by default. */
export function t(key, params) {
  return lookup(key, params, { html: false });
}

/** Translate with trusted markup (caller must pass already-safe values). */
export function html(key, params) {
  return lookup(key, params, { html: true });
}

export function formatNumber(value, options) {
  if (value === null || value === undefined || value === '') return '—';
  try {
    return new Intl.NumberFormat(active === 'vi' ? 'vi-VN' : 'en-US', options).format(Number(value));
  } catch {
    return String(value);
  }
}

export function formatDate(value, options) {
  if (!value) return '—';
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  try {
    return new Intl.DateTimeFormat(active === 'vi' ? 'vi-VN' : 'en-US', options).format(date);
  } catch {
    return String(value);
  }
}

export function formatRelativeTime(value) {
  if (!value) return '—';
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  const deltaSec = Math.round((date.getTime() - Date.now()) / 1000);
  const abs = Math.abs(deltaSec);
  const divisions = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 7],
    ['week', 4.34524],
    ['month', 12],
    ['year', Number.POSITIVE_INFINITY],
  ];
  let unit = 'second';
  let amount = deltaSec;
  let cursor = abs;
  let signed = deltaSec;
  for (const [name, step] of divisions) {
    if (cursor < step) {
      unit = name;
      amount = signed < 0 ? -Math.round(cursor) : Math.round(cursor);
      break;
    }
    cursor /= step;
    signed = signed < 0 ? -cursor : cursor;
  }
  try {
    return new Intl.RelativeTimeFormat(active === 'vi' ? 'vi' : 'en', { numeric: 'auto' }).format(amount, unit);
  } catch {
    return formatDate(date, { dateStyle: 'medium', timeStyle: 'short' });
  }
}

function syncDocument() {
  try {
    document.documentElement.lang = active;
    document.documentElement.dir = 'ltr';
  } catch { /* ignore */ }
}

export function onLocaleChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

let selection = 0;
let retryTimer = null;

/** A failed catalog is fetched again a few times; when it arrives the interface is redrawn. */
function scheduleCatalogRetry(attempt = 1) {
  if (retryTimer !== null || attempt > 3) return;
  retryTimer = setTimeout(async () => {
    retryTimer = null;
    await Promise.all([loadCatalog(active), loadCatalog('en')]);
    if (catalogs[active] && catalogs.en) {
      for (const fn of [...listeners]) {
        try { await fn(active); } catch { /* ignore */ }
      }
    } else {
      scheduleCatalogRetry(attempt + 1);
    }
  }, 2000 * attempt);
}

export async function setLocale(locale, { persist = true, notify = true } = {}) {
  if (!SUPPORTED.includes(locale)) return active;
  // Every selection, even of the language already shown, supersedes a switch that is still loading.
  const ticket = ++selection;
  if (locale === active && ready) return active;
  await Promise.all([loadCatalog(locale), loadCatalog('en')]); // en backs up any key a catalog lacks
  if (ticket !== selection) return active;
  active = locale;
  if (persist) writeStored(locale);
  syncDocument();
  ready = true;
  if (!catalogs[locale] || !catalogs.en) scheduleCatalogRetry();
  if (notify) {
    for (const fn of [...listeners]) {
      try { await fn(active); } catch { /* ignore */ }
    }
  }
  return active;
}

/** Load the catalog of the resolved locale and apply it before first paint. */
export async function initI18n(preferred) {
  const locale = SUPPORTED.includes(preferred) ? preferred : detectLocale();
  await setLocale(locale, { persist: Boolean(readStored()) || preferred != null, notify: false });
  ready = true;
  return active;
}

export function acceptLanguageHeader() {
  return active === 'en' ? 'en' : 'vi';
}
