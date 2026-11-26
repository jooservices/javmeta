import { renderToStaticMarkup } from 'react-dom/server';
import { createElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import viDict from '../../src/i18n/vi.json';
import enDict from '../../src/i18n/en.json';

// the module reads localStorage when it loads, so every test loads a fresh copy with its own storage
async function load({ stored, navigatorLanguage } = {}) {
  vi.resetModules();
  const store = new Map(stored ? [['xc.lang', stored]] : []);
  vi.stubGlobal('localStorage', { getItem: k => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)) });
  const doc = { documentElement: { lang: '' } };
  vi.stubGlobal('document', doc);
  if (navigatorLanguage) vi.stubGlobal('navigator', { language: navigatorLanguage, languages: [navigatorLanguage] });
  const mod = await import('../../src/i18n/index.js');
  return { ...mod, store, doc };
}

afterEach(() => { vi.unstubAllGlobals(); vi.doUnmock('../../src/i18n/en.json'); vi.resetModules(); });

describe('language choice', () => {
  it('defaults to Vietnamese and sets <html lang>', async () => {
    const i = await load();
    expect(i.getLang()).toBe('vi'); expect(i.locale()).toBe('vi'); expect(i.doc.documentElement.lang).toBe('vi');
  });

  it('does not use the browser language for the default', async () => {
    const i = await load({ navigatorLanguage: 'en-US' });
    expect(i.getLang()).toBe('vi');
  });

  it('reads the saved language from xc.lang', async () => {
    const i = await load({ stored: 'en' });
    expect(i.getLang()).toBe('en'); expect(i.doc.documentElement.lang).toBe('en');
  });

  it('ignores a saved value it does not know', async () => {
    const i = await load({ stored: 'fr' });
    expect(i.getLang()).toBe('vi');
  });

  it('falls back to Vietnamese when storage is not readable', async () => {
    vi.resetModules();
    vi.stubGlobal('localStorage', { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } });
    vi.stubGlobal('document', { documentElement: { lang: '' } });
    const i = await import('../../src/i18n/index.js');
    expect(i.getLang()).toBe('vi');
    expect(() => i.setLang('en')).not.toThrow(); expect(i.getLang()).toBe('en');
  });

  it('setLang saves it, updates <html lang> and tells the subscribers', async () => {
    const i = await load(); const seen = []; const off = i.subscribe(() => seen.push(i.getLang()));
    i.setLang('en');
    expect(i.store.get('xc.lang')).toBe('en'); expect(i.doc.documentElement.lang).toBe('en'); expect(seen).toEqual(['en']);
    i.setLang('en'); expect(seen).toEqual(['en']);
    off(); i.setLang('vi'); expect(seen).toEqual(['en']); expect(i.store.get('xc.lang')).toBe('vi');
  });

  it('setLang ignores an unknown language', async () => {
    const i = await load(); i.setLang('xx');
    expect(i.getLang()).toBe('vi'); expect(i.store.has('xc.lang')).toBe(false);
  });
});

describe('t()', () => {
  it('returns the text of the active language', async () => {
    const i = await load();
    expect(i.t('auth.login')).toBe(viDict['auth.login']); expect(i.t('auth.login')).toBe('Đăng nhập');
    i.setLang('en'); expect(i.t('auth.login')).toBe(enDict['auth.login']); expect(i.t('auth.login')).toBe('Sign in');
  });

  it('fills {name} placeholders, also when the same one appears twice', async () => {
    const i = await load();
    expect(i.t('pager.range', { from: 1, to: 24, total: '1.397' })).toBe('1–24 / 1.397 kết quả');
    i.setLang('en'); expect(i.t('pager.range', { from: 1, to: 24, total: '1,397' })).toBe('1–24 of 1,397 results');
    expect(i.interpolate('{a}-{a}', { a: 'x' })).toBe('x-x');
  });

  it('keeps a placeholder that has no value and accepts 0', async () => {
    const i = await load();
    expect(i.t('common.minutes')).toBe('{n} phút');
    expect(i.t('common.minutes', { other: 1 })).toBe('{n} phút');
    expect(i.t('common.minutes', { n: 0 })).toBe('0 phút');
  });

  it('returns the key when no language has it', async () => {
    const i = await load();
    expect(i.t('no.such.key')).toBe('no.such.key'); expect(i.t('no.such.key', { n: 1 })).toBe('no.such.key');
  });

  it('falls back to Vietnamese when the active language lacks the key', async () => {
    vi.doMock('../../src/i18n/en.json', () => ({ default: {} }));
    const i = await load({ stored: 'en' });
    expect(i.t('common.copy')).toBe('Sao chép');
  });

  it('tk() returns the key unchanged', async () => {
    const i = await load(); expect(i.tk('nav.explore')).toBe('nav.explore');
  });
});

describe('tn()', () => {
  it('picks the singular for 1 and the plural otherwise, with {n}', async () => {
    const i = await load({ stored: 'en' });
    expect(i.tn('time.minutesAgo', 1)).toBe('1 minute ago');
    expect(i.tn('time.minutesAgo', 5)).toBe('5 minutes ago');
    expect(i.tn('time.minutesAgo', 0)).toBe('0 minutes ago');
    i.setLang('vi'); expect(i.tn('time.minutesAgo', 1)).toBe('1 phút trước'); expect(i.tn('time.minutesAgo', 5)).toBe('5 phút trước');
  });
});

describe('I18nProvider', () => {
  it('renders its children', async () => {
    const i = await load();
    expect(renderToStaticMarkup(createElement(i.I18nProvider, null, createElement('b', null, i.t('auth.login'))))).toBe('<b>Đăng nhập</b>');
  });

  it('renders the text of the language that is active', async () => {
    const i = await load({ stored: 'en' }); const Login = () => createElement('b', null, i.t('auth.login'));
    expect(renderToStaticMarkup(createElement(i.I18nProvider, null, createElement(Login)))).toBe('<b>Sign in</b>');
    i.setLang('vi');
    expect(renderToStaticMarkup(createElement(i.I18nProvider, null, createElement(Login)))).toBe('<b>Đăng nhập</b>');
  });
});
