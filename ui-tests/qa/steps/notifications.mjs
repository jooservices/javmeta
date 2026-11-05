import { defineStep } from '../lib/step.mjs';

const editor = page => page.locator('[data-editor]');
const version = async ctx => Number(((await ctx.page.$eval('[data-status]', e => e.textContent).catch(() => '')).match(/Phiên bản (\d+)/) || [])[1] || 0);

export const openNotificationEditor = defineStep('openNotificationEditor', async (ctx, rowIndex = 0) => {
  await ctx.page.locator('[data-action=edit]').nth(rowIndex).click();
  await ctx.page.waitForSelector('[data-editor] textarea[name=title_template]', { timeout: 8000 })
    .catch(() => { throw new Error('Clicking "Sửa" did not open the editor.'); });
  ctx.state.titleBefore = await ctx.page.inputValue('[data-editor] textarea[name=title_template]');
  ctx.state.versionBefore = await version(ctx);
});

export const typeInTitle = defineStep('typeInTitle', async (ctx, text) => {
  await ctx.page.fill('[data-editor] textarea[name=title_template]', text);
  ctx.state.typedTitle = text;
});

export const expectLivePreviewShows = defineStep('expectLivePreviewShows', async (ctx, text) => {
  await ctx.page.waitForFunction(t => (document.querySelector('[data-editor]')?.innerText || '').includes(t), text, { timeout: 6000 })
    .catch(() => { throw new Error(`The live preview does not show "${text}".`); });
});

export const insertVariable = defineStep('insertVariable', async ctx => {
  const before = await ctx.page.inputValue('[data-editor] textarea[name=body_template]');
  await ctx.page.locator('[data-editor] [data-action=insert]').first().click();
  const after = await ctx.page.inputValue('[data-editor] textarea[name=body_template]');
  if (after.length <= before.length || !/\{[a-z_]+\}/.test(after.slice(before.length) + after)) throw new Error('A variable button did not insert its placeholder into the body.');
});

export const saveTemplate = defineStep('saveTemplate', async ctx => {
  await ctx.page.click('[data-editor] [data-action=save]');
  await ctx.page.waitForFunction(v => Number(((document.querySelector('[data-status]')?.textContent || '').match(/Phiên bản (\d+)/) || [])[1] || 0) > v, ctx.state.versionBefore, { timeout: 8000 })
    .catch(() => { throw new Error('Saving the template did not create a new version.'); });
  ctx.state.versionAfterSave = await version(ctx);
});

export const expectTitlePersisted = defineStep('expectTitlePersisted', async ctx => {
  await ctx.page.reload({ waitUntil: 'load' });
  await ctx.page.waitForSelector('[data-action=edit]');
  await ctx.page.locator('[data-action=edit]').first().click();
  await ctx.page.waitForSelector('[data-editor] textarea[name=title_template]');
  const now = await ctx.page.inputValue('[data-editor] textarea[name=title_template]');
  if (now !== ctx.state.typedTitle) throw new Error(`After a reload the title is "${now}", expected "${ctx.state.typedTitle}".`);
});

/** Guards the reset check below: the title must really differ from the original before it is reset. */
export const expectTitleDiffersFromOriginal = defineStep('expectTitleDiffersFromOriginal', async ctx => {
  const now = await ctx.page.inputValue('[data-editor] textarea[name=title_template]');
  if (now === ctx.state.titleBefore) throw new Error('The title still equals the original, so a reset would prove nothing.');
});

export const resetTemplate = defineStep('resetTemplate', async ctx => {
  await ctx.page.click('[data-editor] [data-action=reset]');
  await ctx.page.waitForSelector('[data-confirm-yes]', { timeout: 4000 }).catch(() => { throw new Error('Reset did not ask for confirmation.'); });
  await ctx.page.click('[data-confirm-yes]');
  await ctx.page.waitForFunction(t => document.querySelector('[data-editor] textarea[name=title_template]')?.value === t, ctx.state.titleBefore, { timeout: 8000 })
    .catch(() => { throw new Error('Resetting did not bring back the original title.'); });
});

export const expectReportSendAsksToConfirm = defineStep('expectReportSendAsksToConfirm', async ctx => {
  await ctx.page.click('[data-report-send]');
  await ctx.page.waitForSelector('[data-confirm-yes]', { timeout: 4000 }).catch(() => { throw new Error('Telegram send did not ask for confirmation.'); });
  await ctx.page.click('[data-confirm-no]');
  await ctx.page.waitForTimeout(300);
  if (await ctx.page.$('[data-confirm-yes]')) throw new Error('Cancelling the send confirm left the dialog open.');
});

export const expectTestSendAsksToConfirm = defineStep('expectTestSendAsksToConfirm', async ctx => {
  await ctx.page.click('[data-editor] [data-action=test]');
  await ctx.page.waitForSelector('[data-confirm-yes]', { timeout: 4000 }).catch(() => { throw new Error('Test send did not ask for confirmation.'); });
  const title = await ctx.page.$eval('#confirm-title', e => e.innerText);
  if (!/gửi thử/i.test(title)) throw new Error(`Confirm title is "${title}".`);
  await ctx.page.click('[data-confirm-no]');
  await ctx.page.waitForTimeout(300);
  if (await ctx.page.$('[data-confirm-yes]')) throw new Error('Cancelling the test-send confirm left the dialog open.');
});
