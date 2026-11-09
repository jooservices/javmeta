#!/usr/bin/env node
/**
 * Ratchet: count of hardcoded user-visible Vietnamese/Latin UI string literals
 * in public/ui/js and Blade views may only go down (or stay). Baseline is committed
 * beside this script. Fail when the current count exceeds the baseline.
 *
 * Usage: node tools/quality/i18n-hardcoded-ratchet.mjs [--write-baseline]
 */
import assert from 'node:assert/strict';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const baselinePath = path.join(root, 'tools/quality/i18n-hardcoded-baseline.json');
const writeBaseline = process.argv.includes('--write-baseline');

const viChars = /[ăâêôơưđĂÂÊÔƠƯĐáàảãạéèẻẽẹíìỉĩịóòỏõọúùủũụýỳỷỹỵÁÀẢÃẠÉÈẺẼẸÍÌỈĨỊÓÒỎÕỌÚÙỦŨỤÝỲỶỸỴ]/;
const skipDirs = new Set(['node_modules', 'vendor', '.git']);

async function* walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (skipDirs.has(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else yield full;
  }
}

function countIn(text) {
  let count = 0;
  const offenders = [];
  const re = /(['"`])(?:(?!\1|\\).|\\.)*\1/gs;
  let m;
  while ((m = re.exec(text))) {
    const s = m[0];
    if (s.length < 4) continue;
    if (!viChars.test(s)) continue;
    // Ignore import paths and obvious non-UI noise.
    if (/^['"`]\.\.?\/|^['"`]https?:|^['"`]#\//.test(s)) continue;
    count += 1;
    offenders.push(s.slice(0, 80));
  }
  return { count, offenders };
}

const targets = [
  path.join(root, 'public/ui/js'),
  path.join(root, 'public/ui/index.html'),
  path.join(root, 'resources/views'),
];

let total = 0;
const byFile = {};
for (const target of targets) {
  const st = await readFile(target, 'utf8').then(() => 'file').catch(() => 'dir');
  const files = st === 'file' ? [target] : [];
  if (st === 'dir') {
    for await (const file of walk(target)) {
      if (!/\.(js|blade\.php|html)$/.test(file)) continue;
      files.push(file);
    }
  }
  for (const file of files) {
    const text = await readFile(file, 'utf8');
    const { count } = countIn(text);
    if (count === 0) continue;
    const rel = path.relative(root, file);
    byFile[rel] = count;
    total += count;
  }
}

const snapshot = { total, byFile, updated_at: new Date().toISOString() };

if (writeBaseline) {
  await writeFile(baselinePath, JSON.stringify(snapshot, null, 2) + '\n');
  console.log(`Wrote baseline total=${total} files=${Object.keys(byFile).length}`);
  process.exit(0);
}

const baseline = JSON.parse(await readFile(baselinePath, 'utf8'));
assert.ok(typeof baseline.total === 'number', 'baseline.total missing');
if (total > baseline.total) {
  console.error(`i18n hardcoded ratchet failed: ${total} > baseline ${baseline.total}`);
  const rises = Object.entries(byFile).filter(([f, n]) => n > (baseline.byFile?.[f] || 0));
  for (const [f, n] of rises.slice(0, 20)) console.error(`  + ${f}: ${n} (was ${baseline.byFile?.[f] || 0})`);
  process.exit(1);
}
console.log(`i18n hardcoded ratchet OK: ${total} <= baseline ${baseline.total}`);
