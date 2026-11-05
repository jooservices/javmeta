import { defineStep } from '../lib/step.mjs';

/** Creates an API key from the admin page, checks that its secret is shown once with the usual prefix, and that it is listed as in use. */
export const createApiKey = defineStep('createApiKey', async (ctx, label) => {
  await ctx.page.fill('#view input[name=label]', label);
  const catalog = ctx.page.locator('#view input[name=scopes][value=catalog]');
  if (!(await catalog.isChecked())) await catalog.check();
  await ctx.page.click('#view button:has-text("Tạo khoá")');
  await ctx.page.waitForSelector('[data-confirm-yes]', { timeout: 4000 }).catch(() => { throw new Error('Creating a key did not ask for confirmation.'); });
  await ctx.page.click('[data-confirm-yes]');
  await ctx.page.waitForFunction(() => /Khoá mới \(chỉ hiện một lần\)/.test(document.querySelector('#view')?.innerText || ''), null, { timeout: 8000 })
    .catch(() => { throw new Error('The page did not show the new key after creating it.'); });
  const text = await ctx.page.$eval('#view', e => e.innerText);
  if (!/Khoá mới \(chỉ hiện một lần\): jvm_[A-Za-z0-9]{20,}/.test(text)) throw new Error('The new key secret is not shown with its jvm_ prefix.');
  ctx.state.apiKeyLabel = label;
});

export const expectApiKeyRequiresAScope = defineStep('expectApiKeyRequiresAScope', async ctx => {
  await ctx.page.fill('#view input[name=label]', 'no-scope-' + Date.now().toString(36));
  await ctx.page.locator('#view input[name=scopes]').evaluateAll(els => els.forEach(el => { el.checked = false; }));
  await ctx.page.click('#view button:has-text("Tạo khoá")');
  await ctx.page.waitForTimeout(400);
  const text = await ctx.page.$eval('#newkey', e => e.innerText);
  if (!/ít nhất một quyền/.test(text)) throw new Error(`Creating a key with no scope did not fail: ${text}`);
  if (await ctx.page.$('[data-confirm-yes]')) throw new Error('A no-scope submit still opened the confirm dialog.');
});

/** The row of the key created by this scenario (older keys with a similar name may exist). */
const rowOf = ctx => ctx.page.locator('#view tr', { hasText: ctx.state.apiKeyLabel }).first();

export const expectApiKeyStatus = defineStep('expectApiKeyStatus', async (ctx, status) => {
  const label = ctx.state.apiKeyLabel;
  // A real reload: opening the same hash route again would keep the one-time banner on screen.
  await ctx.page.reload({ waitUntil: 'load' });
  await ctx.page.waitForTimeout(1800);
  const row = rowOf(ctx);
  if (!(await row.count())) throw new Error(`The key "${label}" is missing from the list.`);
  const text = await row.innerText();
  if (!text.includes(status)) throw new Error(`The key "${label}" is not "${status}": ${text.replace(/\s+/g, ' ')}`);
  if (/jvm_[A-Za-z0-9]{30,}/.test(await ctx.page.$eval('#view', e => e.innerText))) throw new Error('A full key secret is visible again after a reload; it must be shown only once.');
});

export const revokeApiKey = defineStep('revokeApiKey', async ctx => {
  // The page asks for confirmation with the browser's own dialog; a headless browser dismisses it unless it is accepted.
  ctx.page.once('dialog', dialog => dialog.accept());
  await rowOf(ctx).locator('button', { hasText: 'Thu hồi' }).click();
  await ctx.page.waitForTimeout(1500);
});
