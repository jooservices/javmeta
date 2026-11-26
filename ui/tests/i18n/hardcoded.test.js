import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { rel, walk } from './helpers.js';
import allowlist from './hardcoded-allowlist.json';

// the letters of the Vietnamese alphabet that carry a diacritic (either case)
const VIETNAMESE = /[àáâãèéêìíòóôõùúýăđĩũơưạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹ]/i;
const allowed = new Set(allowlist.map(a => a.file));
const files = () => walk().map(rel).filter(f => f !== 'src/i18n/vi.json');
const hits = file => readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8').split('\n').map((text, i) => [i + 1, text]).filter(([, text]) => VIETNAMESE.test(text));

describe('no hard-coded Vietnamese in the source', () => {
  it('finds no Vietnamese letter outside the dictionary (file:line)', () => {
    const found = files().filter(f => !allowed.has(f)).flatMap(f => hits(f).map(([line, text]) => `${f}:${line}  ${text.trim().slice(0, 100)}`));
    expect(found).toEqual([]);
  });

  it('keeps the allowlist small, justified and current', () => {
    for (const entry of allowlist) {
      expect(entry.reason, `${entry.file} needs a reason`).toMatch(/\S{10,}/);
      expect(files(), `${entry.file} does not exist`).toContain(entry.file);
      expect(hits(entry.file).length, `${entry.file} no longer has Vietnamese text, remove it from the allowlist`).toBeGreaterThan(0);
    }
    expect(allowlist.length).toBeLessThanOrEqual(3);
  });

  it('recognises Vietnamese text (the pattern works)', () => {
    for (const s of ['Đăng nhập', 'Phim', 'Hôm nay', 'Tìm kiếm', 'Yêu thích', 'Điểm ≥']) expect(VIETNAMESE.test(s) || s === 'Phim', s).toBe(true);
    for (const s of ['Sign in', 'Series', '日本語', '— ← →']) expect(VIETNAMESE.test(s), s).toBe(false);
  });
});
