import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { readJson, rel, sourceFiles, variablesOf } from './helpers.js';

const vi = readJson('vi.json');
const en = readJson('en.json');

// t('key'), tk('key') and tn('key', n): the key is a string literal (a table of keys uses tk so this scan sees it)
const CALL = /\b(t|tk|tn)\(\s*(['"])([^'"\n]+)\2/g;
// keys that are built from parts at run time (src/opstabs.jsx: tn(`time.${unit}${form}`, n))
const DYNAMIC_PLURALS = ['seconds', 'minutes', 'hours', 'days'].flatMap(u => ['Ago', 'Span'].map(f => `time.${u}${f}`));

function usedKeys() {
  const keys = new Map();
  const add = (key, where) => keys.set(key, [...(keys.get(key) || []), where]);
  for (const file of sourceFiles()) {
    if (rel(file).startsWith('src/i18n/')) continue;
    const lines = readFileSync(file, 'utf8').split('\n');
    lines.forEach((line, i) => {
      for (const [, fn, , key] of line.matchAll(CALL)) {
        if (fn === 'tn') { add(`${key}.one`, `${rel(file)}:${i + 1}`); add(`${key}.other`, `${rel(file)}:${i + 1}`); } else add(key, `${rel(file)}:${i + 1}`);
      }
    });
  }
  for (const base of DYNAMIC_PLURALS) { add(`${base}.one`, 'dynamic'); add(`${base}.other`, 'dynamic'); }
  return keys;
}

describe('translation dictionaries', () => {
  it('have exactly the same keys', () => {
    const a = Object.keys(vi).sort(); const b = Object.keys(en).sort();
    expect(a.filter(k => !(k in en)), 'keys only in vi.json').toEqual([]);
    expect(b.filter(k => !(k in vi)), 'keys only in en.json').toEqual([]);
  });

  it('have no empty value', () => {
    for (const [name, dict] of [['vi.json', vi], ['en.json', en]]) {
      const empty = Object.entries(dict).filter(([, v]) => typeof v !== 'string' || v.trim() === '').map(([k]) => k);
      expect(empty, `empty values in ${name}`).toEqual([]);
    }
  });

  it('use the same {variables} in both languages for every key', () => {
    const bad = Object.keys(vi).filter(k => k in en && JSON.stringify(variablesOf(vi[k])) !== JSON.stringify(variablesOf(en[k])));
    expect(bad.map(k => `${k}: vi ${JSON.stringify(variablesOf(vi[k]))} / en ${JSON.stringify(variablesOf(en[k]))}`)).toEqual([]);
  });

  it('have a .one and a .other text for every plural', () => {
    const bases = new Set(Object.keys(vi).filter(k => /\.(one|other)$/.test(k)).map(k => k.replace(/\.(one|other)$/, '')));
    const missing = [...bases].flatMap(b => ['one', 'other'].map(f => `${b}.${f}`)).filter(k => !(k in vi));
    expect(missing).toEqual([]);
  });
});

describe('keys used in the source', () => {
  const used = usedKeys();

  it('finds the translation calls (the scan is not empty)', () => {
    expect(used.size).toBeGreaterThan(500);
  });

  it('exist in vi.json', () => {
    const missing = [...used].filter(([k]) => !(k in vi)).map(([k, where]) => `${k} (${where[0]})`);
    expect(missing).toEqual([]);
  });

  it('exist in en.json', () => {
    const missing = [...used].filter(([k]) => !(k in en)).map(([k, where]) => `${k} (${where[0]})`);
    expect(missing).toEqual([]);
  });

  it('leave no dictionary key unused', () => {
    const unused = Object.keys(vi).filter(k => !used.has(k));
    expect(unused).toEqual([]);
  });
});
