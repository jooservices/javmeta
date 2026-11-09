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
      if (c === '/' && (i === 0 || regexMayStartAfter.test(text.slice(Math.max(0, i - 12), i).trimEnd()))) {
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

export function countIn(text, file = '') {
  let count = 0;
  const offenders = [];
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
  return { count, offenders };
}
