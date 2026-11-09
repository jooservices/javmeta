import assert from 'node:assert/strict';
import { countIn } from './i18n-literals.mjs';

const count = source => countIn(source, 'x.js').count;

// An apostrophe inside a comment or a plain literal does not shift the quote pairing.
assert.equal(count("// it's a comment\ntoast('Đã lưu');"), 1);
assert.equal(count("const a = \"don't\"; toast('Đã xoá');"), 1);
// A regular expression with a quote in it is not a string, and a toast after it on the same line is still seen.
assert.equal(count("const bad = /[\"']/g; toast('Đã cập nhật');"), 1);
assert.equal(count("x.replace(/'/g, ''); toast('Không tìm thấy');"), 1);
// Division is not a regular expression.
assert.equal(count("const half = total / 2; toast('Xong rồi');"), 1);
// Text parts of a template count, code inside ${} is scanned on its own, a URL-like template is not UI text.
assert.equal(count("const m = `Đã xoá ${name} khỏi danh sách`;"), 2);
assert.equal(count("const u = `https://example.test/${id}/việt-nam`;"), 0);
assert.equal(count("const u = `#/phim/${code}/diễn-viên`;"), 0);
assert.equal(count("const u = `${base}/${t('x')} ${'Đã thêm'}`;"), 1);
// The multiplication sign is not a Vietnamese letter.
assert.equal(count("btn.innerHTML = '×';"), 0);
// Translated code has nothing to count.
assert.equal(count("toast(t('mark.toast.seen_on'));"), 0);
// Import paths and comments are ignored.
assert.equal(count("import x from '../views/phim-ảnh.js'; // 'Đã xoá'"), 0);

console.log('i18n literal counter: all assertions passed');
