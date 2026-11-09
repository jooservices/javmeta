/**
 * Counting of hardcoded Vietnamese UI strings for the ratchet (tools/quality/i18n-hardcoded-ratchet.mjs).
 *
 * JavaScript is read the way the language reads it: comments are skipped, an apostrophe inside a comment or another
 * literal never opens a new one, regular expression literals are skipped (a quote inside one is not a string),
 * template literals contribute their text parts but not the code inside ${} (which is scanned on its own), and a
 * template that starts like a URL or path is not UI text as a whole. A quote string that is not closed on its line
 * ends there, so one odd line cannot shift the pairing of the lines after it.
 */
// Vietnamese letters: the base letters with their own code points plus the whole composed range (ồ, ế, ữ, ...).
const viChars = /[ĂÂÊÔƠƯĐăâêôơưđ\u00C0-\u00D6\u00D8-\u00F6\u00F8-\u00FF\u0128\u0129\u0168\u0169\u1EA0-\u1EF9]/;
const pathLike = /^['"`](?:\.\.?\/|https?:|#\/)/;
const regexMayStartAfter = /(?:[(,=:\[!&|?{};+\-*%<>~^]|\b(?:return|typeof|case|in|of|void|delete|throw|else|do))$/;

/** A slash starts a regular expression after an operator or keyword, but is a division after `x++`, `x--` or a closing bracket. */
function regexMayStartHere(text, i) {
  const before = text.slice(Math.max(0, i - 12), i).trimEnd();
  if (before.endsWith('++') || before.endsWith('--')) return false;
  return regexMayStartAfter.test(before);
}

export function jsLiterals(text) {
  const items = [];
  const scan = (start, insideExpression) => {
    let i = start;
    let depth = 0;
    while (i < text.length) {
      const c = text[i];
      const next = text[i + 1];
      if (c === '/' && next === '/') { while (i < text.length && text[i] !== '\n') i += 1; continue; }
      if (c === '/' && next === '*') { const end = text.indexOf('*/', i + 2); i = end === -1 ? text.length : end + 2; continue; }
      if (c === '/' && (i === 0 || regexMayStartHere(text, i))) {
        let j = i + 1;
        let inClass = false;
        while (j < text.length && text[j] !== '\n') {
          if (text[j] === '\\') { j += 2; continue; }
          if (text[j] === '[') inClass = true;
          else if (text[j] === ']') inClass = false;
          else if (text[j] === '/' && !inClass) break;
          j += 1;
        }
        i = j + 1;
        while (/[a-z]/.test(text[i] ?? '')) i += 1;
        continue;
      }
      if (c === '\'' || c === '"') {
        let j = i + 1;
        while (j < text.length && text[j] !== c && text[j] !== '\n') j += text[j] === '\\' ? 2 : 1;
        items.push(text.slice(i, Math.min(j + 1, text.length)));
        i = j + 1;
        continue;
      }
      if (c === '`') {
        const parts = [];
        let part = '`';
        i += 1;
        while (i < text.length && text[i] !== '`') {
          if (text[i] === '\\') { part += text.slice(i, i + 2); i += 2; continue; }
          if (text[i] === '$' && text[i + 1] === '{') {
            parts.push(part);
            part = '';
            i = scan(i + 2, true);
            continue;
          }
          part += text[i];
          i += 1;
        }
        parts.push(part + '`');
        if (!pathLike.test(parts[0])) items.push(...parts);
        i += 1;
        continue;
      }
      if (insideExpression) {
        if (c === '{') depth += 1;
        if (c === '}') { if (depth === 0) return i + 1; depth -= 1; }
      }
      i += 1;
    }
    return i;
  };
  scan(0, false);
  return items;
}

/**
 * The visible text of an HTML or Blade file: comments, scripts, styles, Blade echoes and directives are dropped and what
 * stands between tags is read as text. An element that carries data-i18n gets its text from the catalog at run time, so
 * the Vietnamese fallback written inside it is not counted.
 */
export function markupTextNodes(text) {
  const cleaned = text
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/\{\{--[\s\S]*?--\}\}/g, ' ')
    .replace(/<script\b[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[\s\S]*?<\/style>/gi, ' ')
    .replace(/\{!!?[\s\S]*?!!?\}|\{\{[\s\S]*?\}\}/g, ' ')
    .replace(/^\s*@\w+.*$/gm, ' ');
  const nodes = [];
  for (const m of cleaned.matchAll(/<([a-zA-Z][\w-]*)([^<>]*)>([^<>]+)(?=<)/g)) {
    if (/\bdata-i18n\b/.test(m[2])) continue;
    const value = m[3].replace(/&[a-z#0-9]+;/gi, ' ').trim();
    if (value.length >= 2) nodes.push(value);
  }
  return nodes;
}

export function countIn(text, file = '') {
  let count = 0;
  const offenders = [];
  const markup = /\.(?:html|blade\.php)$/.test(file);
  const literals = /\.js$/.test(file)
    ? jsLiterals(text)
    : [...text.matchAll(/(['"`])(?:(?!\1|\\).|\\.)*\1/gs)].map(m => m[0]);
  for (const s of literals) {
    if (s.length < 4) continue;
    if (!viChars.test(s)) continue;
    if (pathLike.test(s)) continue; // import paths, URLs and routes are not UI text
    count += 1;
    offenders.push(s.slice(0, 80));
  }
  if (markup) {
    for (const node of markupTextNodes(text)) {
      if (!viChars.test(node)) continue;
      count += 1;
      offenders.push(node.slice(0, 80));
    }
  }
  return { count, offenders };
}
