import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const UI_ROOT = fileURLToPath(new URL('../../', import.meta.url));
export const SRC = join(UI_ROOT, 'src');
export const I18N_DIR = join(SRC, 'i18n');

export const readJson = file => JSON.parse(readFileSync(join(I18N_DIR, file), 'utf8'));

// every file under src (posix-style relative paths)
export function walk(dir = SRC, out = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
}
export const rel = file => relative(UI_ROOT, file).split(sep).join('/');

// the source files that can hold translation calls
export const sourceFiles = () => walk().filter(f => /\.(jsx?|mjs)$/.test(f));

// {name} placeholders of a text, sorted
export const variablesOf = text => [...text.matchAll(/\{(\w+)\}/g)].map(m => m[1]).sort();
